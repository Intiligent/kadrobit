<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MarketplaceAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreMarketplaceAccountRequest;
use App\Http\Requests\Settings\UpdateMarketplaceAccountRequest;
use App\Http\Resources\Settings\MarketplaceConnectionResource;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\ConnectionTester;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Marketplace connections. New and changed sign-in details are saved only after a successful sign-in,
 * so an account in the list always had working credentials at some point.
 */
class MarketplaceAccountController extends Controller
{
    /** Sign-ins to marketplace servers per user and minute, see test(). */
    public const SIGN_INS_PER_MINUTE = 10;

    public function __construct(protected ConnectionTester $tester) {}

    public function store(StoreMarketplaceAccountRequest $request): JsonResponse
    {
        $marketplace = $request->marketplace();
        $connection = $request->connection();

        $this->signIn($request, $marketplace, $connection);

        $account = $request->user()->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => $request->input('label'),
            'contributor_id' => $request->input('contributor_id'),
            'host' => $connection['host'],
            'port' => $connection['port'],
            'login' => $connection['login'],
            'credentials' => ['password' => $connection['password']],
            'status' => MarketplaceAccountStatus::Active,
            'last_verified_at' => now(),
            'credentials_changed_at' => now(),
        ]);

        return MarketplaceConnectionResource::make($account->load('marketplace'))->response()->setStatusCode(201);
    }

    public function update(UpdateMarketplaceAccountRequest $request): MarketplaceConnectionResource
    {
        $account = $request->account();
        $rejected = $account->status === MarketplaceAccountStatus::InvalidCredentials || $account->last_error === ConnectionResult::AUTH;
        $account->fill($request->safe()->only(['label', 'contributor_id', 'status']));

        // Turning an account on does not lift a rejected sign-in: only working credentials do.
        if ($account->status === MarketplaceAccountStatus::Active && $rejected) {
            $account->status = MarketplaceAccountStatus::InvalidCredentials;
        }

        if ($request->changesConnection()) {
            $connection = $request->connection();
            $this->signIn($request, $account->marketplace, $connection);

            $account->fill([
                'host' => $connection['host'],
                'login' => $connection['login'],
                'credentials' => ['password' => $connection['password']],
                'last_verified_at' => now(),
                'last_error' => null,
                'credentials_changed_at' => now(),
            ]);

            // Working credentials lift a failed state, but do not turn on an account the user turned off.
            if ($account->status === MarketplaceAccountStatus::InvalidCredentials) {
                $account->status = MarketplaceAccountStatus::Active;
            }
        }

        $account->save();

        return MarketplaceConnectionResource::make($account);
    }

    /**
     * "Check now" with the saved credentials. A wrong password marks the account; an unreachable
     * server only records the reason, since the password may still be right. A rejected password stays
     * the recorded reason until a sign-in succeeds: a failure to reach the server says nothing about it.
     * A turned-off account stays off either way.
     */
    public function check(Request $request, string $account): MarketplaceConnectionResource
    {
        $account = $this->find($request, $account);

        $result = $this->test($request, $account->marketplace, [
            'host' => $account->host,
            'port' => $account->port,
            'login' => $account->login,
            'password' => $account->credentials['password'] ?? '',
        ]);

        $account->last_verified_at = now();

        if ($result->ok || $result->isAuthError() || $account->last_error !== ConnectionResult::AUTH) {
            $account->last_error = $result->reason;
        }

        if ($result->isAuthError() && $account->status === MarketplaceAccountStatus::Active) {
            $account->status = MarketplaceAccountStatus::InvalidCredentials;
        } elseif ($result->ok && $account->status === MarketplaceAccountStatus::InvalidCredentials) {
            $account->status = MarketplaceAccountStatus::Active;
        }

        $account->save();

        return MarketplaceConnectionResource::make($account);
    }

    /**
     * A hard delete: the credentials must not outlive the account.
     */
    public function destroy(Request $request, string $account): Response
    {
        $this->find($request, $account)->delete();

        return response()->noContent();
    }

    /**
     * @param  array{host: string, port: int|null, login: string, password: string}  $connection
     */
    protected function signIn(Request $request, Marketplace $marketplace, #[\SensitiveParameter] array $connection): void
    {
        $result = $this->test($request, $marketplace, $connection);

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'connection' => __("settings.marketplaces.check.{$result->reason}", [
                    'name' => $marketplace->name,
                    'host' => $connection['host'],
                ]),
            ]);
        }
    }

    /**
     * Every sign-in to a marketplace server counts against one limit per user, whichever action makes it.
     * Limits password guessing through Kadrobit and keeps marketplaces from locking the account after
     * repeated failures. Counted here rather than in route middleware: an update signs in only when it
     * changes the server, login or password.
     *
     * @param  array{host: string, port: int|null, login: string, password: string}  $connection
     */
    protected function test(Request $request, Marketplace $marketplace, #[\SensitiveParameter] array $connection): ConnectionResult
    {
        $key = 'marketplace-sign-in:'.$request->user()->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, self::SIGN_INS_PER_MINUTE)) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key);

        return $this->tester->test($marketplace, $connection);
    }

    protected function find(Request $request, string $id): MarketplaceAccount
    {
        return $request->user()->marketplaceAccounts()->with('marketplace')->findOrFail($id);
    }
}
