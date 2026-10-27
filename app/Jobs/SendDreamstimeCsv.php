<?php

namespace App\Jobs;

use App\Enums\MarketplaceAccountStatus;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\DeliveryFailed;
use App\Services\Marketplaces\Delivery\DreamstimeDelivery;
use App\Services\Marketplaces\Delivery\FtpSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Uploads the metadata CSV of works sent to Dreamstime once more, later (docs/marketplaces/dreamstime/README.md).
 * The first upload goes at the end of the publishing session itself (DreamstimeDelivery::finished()); Dreamstime fills
 * in only files it has already processed, so a file it had not got to by then gets its metadata from this pass.
 * The same name each time (publishing.dreamstime.csv_delays): a later upload replaces the earlier one.
 *
 * Runs under the account's publishing lock, like PublishListings: the marketplace sees one connection at a time.
 */
class SendDreamstimeCsv implements ShouldQueue
{
    use Queueable;

    /** The account was deleted: its listings went with it. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    /**
     * @param  list<int>  $listingIds  Listings the session uploaded
     * @param  string  $name  Base name of the CSV files on the server, the same on every pass
     * @param  int  $pass  Index into publishing.dreamstime.csv_delays
     */
    public function __construct(public MarketplaceAccount $account, public array $listingIds, public string $name, public int $pass = 0)
    {
        $this->onConnection(config('publishing.connection'));
        $this->onQueue('publishing');
    }

    /**
     * The pass after the first delay, if any. Not queue(): the dispatcher calls a job's queue() method to push it.
     *
     * @param  list<int>  $listingIds
     */
    public static function afterSession(MarketplaceAccount $account, array $listingIds, string $name): void
    {
        $delays = config('publishing.dreamstime.csv_delays');

        if ($delays) {
            dispatch(new self($account, $listingIds, $name))->delay($delays[0]);
        }
    }

    public function handle(DreamstimeDelivery $delivery): void
    {
        $account = $this->account->refresh()->load('marketplace');

        if ($account->status !== MarketplaceAccountStatus::Active) {
            return;
        }

        $listings = $delivery->sentListings($account, $this->listingIds);

        if ($listings->isEmpty()) {
            return;
        }

        $lock = Cache::lock("marketplace-account:{$account->id}", config('publishing.timeout') + 60);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        try {
            $uploaded = $this->upload($account, $delivery, $listings);
        } finally {
            $lock->release();
        }

        $next = $this->pass + 1;
        $delays = config('publishing.dreamstime.csv_delays');

        if ($uploaded && isset($delays[$next])) {
            dispatch(new self($account, $this->listingIds, $this->name, $next))->delay($delays[$next] - $delays[$this->pass]);
        }
    }

    /**
     * @param  Collection<int, AssetListing>  $listings
     * @return bool false when the job is released to wait for the sign-in limit, or the login was rejected
     */
    protected function upload(MarketplaceAccount $account, DreamstimeDelivery $delivery, Collection $listings): bool
    {
        $key = "marketplace-publish-sign-in:{$account->id}";

        if (RateLimiter::tooManyAttempts($key, config('publishing.sign_ins'))) {
            $this->release(RateLimiter::availableIn($key));

            return false;
        }

        RateLimiter::hit($key, config('publishing.sign_ins_decay_seconds'));

        try {
            /** @var FtpSession $session */
            $session = $delivery->open($account);

            try {
                $delivery->uploadCsv($session, $listings, $this->name);
            } finally {
                // Nothing was sent in this session: closing it queues no CSV of its own.
                $session->close();
            }

            return true;
        } catch (DeliveryFailed $e) {
            Log::info('Dreamstime CSV upload failed', ['account' => $account->id, 'reason' => $e->reason]);

            // A rejected login is not tried again: PublishListings marks the account on its next session.
            if ($e->reason !== ConnectionResult::AUTH) {
                throw $e;
            }

            return false;
        }
    }
}
