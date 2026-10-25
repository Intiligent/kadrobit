<?php

namespace App\Jobs;

use App\Enums\MarketplaceAccountStatus;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\DeliveryFactory;
use App\Services\Marketplaces\Delivery\DeliveryFailed;
use App\Services\Marketplaces\Delivery\DeliverySession;
use App\Services\Marketplaces\Publishing;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One publishing session of a marketplace account: signs in once and uploads its queued listings one by one
 * (docs/publishing.md). Only one session runs per account, so the marketplace sees a single connection.
 *
 * - A rejected sign-in fails the queued listings and marks the account, with no retries: retrying a wrong
 *   password could get the account locked.
 * - An unreachable server or a lost connection is tried again by a new job after a pause, up to
 *   publishing.connect_tries; then the listings fail with "connect".
 * - Any other failure of one upload fails that listing only, and the session goes on.
 */
class PublishListings implements ShouldQueue
{
    use Queueable;

    /** The account was deleted: its listings went with it. */
    public bool $deleteWhenMissingModels = true;

    public int $timeout;

    /**
     * @param  int  $failures  Sessions in a row that could not reach the server
     */
    public function __construct(public MarketplaceAccount $account, public int $failures = 0)
    {
        $this->timeout = config('publishing.timeout');

        $this->onConnection(config('publishing.connection'));
        $this->onQueue('publishing');
    }

    /**
     * Limited by time, not tries: a job waiting for the running session of its account is released, and that
     * must not use up its tries.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(12);
    }

    /**
     * Holds the account's lock for the session. A follow-up session (a pause after a lost connection, or the rest
     * of a long batch) is queued only after the lock is let go, so it never finds its own account still locked.
     */
    public function handle(Publishing $publishing, DeliveryFactory $deliveries): void
    {
        // A second job for the account waits for the running session: the listings it was queued for are either
        // taken by that session or still queued when it ends.
        $lock = Cache::lock("marketplace-account:{$this->account->id}", $this->timeout + 60);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            $next = $this->session($publishing, $deliveries);
        } finally {
            $lock->release();
        }

        if ($next) {
            dispatch($next);
        }
    }

    /**
     * @return self|null The session to queue next
     */
    protected function session(Publishing $publishing, DeliveryFactory $deliveries): ?self
    {
        $account = $this->account->refresh()->load('marketplace');
        $publishing->recover($account);

        if (! $publishing->hasQueued($account)) {
            return null;
        }

        if ($account->status !== MarketplaceAccountStatus::Active) {
            $publishing->failQueued($account, AssetListing::ERROR_ACCOUNT);

            return null;
        }

        $delivery = $deliveries->for($account->marketplace);

        if (! $delivery) {
            $publishing->failQueued($account, AssetListing::ERROR_UNSUPPORTED);

            return null;
        }

        $key = "marketplace-publish-sign-in:{$account->id}";

        if (RateLimiter::tooManyAttempts($key, config('publishing.sign_ins'))) {
            $this->release(RateLimiter::availableIn($key));

            return null;
        }

        RateLimiter::hit($key, config('publishing.sign_ins_decay_seconds'));

        try {
            $session = $delivery->open($account);
        } catch (DeliveryFailed $e) {
            return $this->stopped($publishing, $account, $e->reason);
        }

        try {
            return $this->upload($publishing, $account, $session);
        } finally {
            $session->close();
        }
    }

    /**
     * Takes the queued listings until there are none left or the session has run its time; the rest goes on in
     * a new session, so a large batch never runs into the job timeout.
     */
    protected function upload(Publishing $publishing, MarketplaceAccount $account, DeliverySession $session): ?self
    {
        $until = now()->addSeconds(config('publishing.session_seconds'));

        while ($listing = $publishing->claim($account)) {
            try {
                $publishing->sent($listing, $session->send($listing));
            } catch (DeliveryFailed $e) {
                // The connection is lost or the server stopped taking our login: the session ends here.
                if ($e->reason === ConnectionResult::AUTH || $e->reason === ConnectionResult::CONNECT) {
                    $publishing->interrupted($listing);

                    return $this->stopped($publishing, $account, $e->reason);
                }

                $this->log($account, $e->reason);
                $publishing->failed($listing, $e->reason);
            }

            if (now()->gte($until)) {
                return new self($account);
            }
        }

        return null;
    }

    /**
     * The server rejected the login or could not be reached, at sign-in or in the middle of the session.
     * Unreachable: a new session after a pause, until the queued listings fail after the last try.
     */
    protected function stopped(Publishing $publishing, MarketplaceAccount $account, string $reason): ?self
    {
        $this->log($account, $reason);
        $failures = $this->failures + 1;

        if ($reason === ConnectionResult::CONNECT && $failures < config('publishing.connect_tries')) {
            $backoff = config('publishing.connect_backoff');

            return (new self($account, $failures))->delay($backoff[$failures - 1] ?? end($backoff));
        }

        if ($reason === ConnectionResult::AUTH) {
            // As "Check now" in settings would: the account is off until a sign-in succeeds.
            $account->update([
                'status' => $account->status === MarketplaceAccountStatus::Active ? MarketplaceAccountStatus::InvalidCredentials : $account->status,
                'last_error' => ConnectionResult::AUTH,
                'last_verified_at' => now(),
            ]);
        }

        $publishing->failQueued($account, $reason);

        return null;
    }

    /** Only the reason: the server's message may contain the login. */
    protected function log(MarketplaceAccount $account, string $reason): void
    {
        Log::info('Marketplace upload failed', [
            'marketplace' => $account->marketplace->code,
            'account' => $account->id,
            'reason' => $reason,
        ]);
    }
}
