<?php

namespace App\Services\Marketplaces;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Jobs\PublishListings;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The publishing batch and the life of a listing (docs/publishing.md).
 *
 * A batch is every ready work of the user × the accounts they pick, except pairs already queued, on their way or
 * sent. The upload itself is PublishListings: one session per account that takes the queued listings one by one.
 * A work whose listings are all sent becomes "submitted"; one with a failed or cancelled listing stays ready for
 * the next batch.
 */
class Publishing
{
    public function __construct(protected ListingChecks $checks) {}

    /**
     * What the next batch would send: per connected account (the account list of the publish popover) and per ready
     * work, with the checks of every pair the batch can still send (its work list).
     *
     * @return array{works: int, accounts: list<array{account: int, blocked: ?string, works: int, unsupported: int, warnings: list<array{asset: string, warnings: list<array>}>}>, items: list<array<string, mixed>>}
     */
    public function summary(User $user): array
    {
        $works = $this->readyWorks($user);
        $plans = $this->accounts($user)->map(fn (MarketplaceAccount $account) => $this->plan($works, $account));

        return [
            'works' => $works->count(),
            'accounts' => $plans
                ->map(fn (array $plan) => [
                    'account' => $plan['account']->id,
                    'blocked' => $plan['blocked'],
                    'works' => count($plan['items']),
                    'unsupported' => $plan['unsupported'],
                    'warnings' => collect($plan['items'])
                        ->filter(fn (array $item) => $item['check']->warnings)
                        ->map(fn (array $item) => ['asset' => $item['asset']->ulid, 'warnings' => $item['check']->warnings])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'items' => $works->map(fn (Asset $asset) => $this->item($asset, $plans))->values()->all(),
        ];
    }

    /**
     * Queues the batch for the given accounts of the user and starts a session for each one that got work.
     * Accounts that are turned off or lost their sign-in are skipped.
     *
     * With `$pairs`, only those work–account pairs are queued: the ones left ticked in the publish popover. A pair the
     * batch cannot send (already queued or sent, rejected by the checks, someone else's) is skipped.
     *
     * @param  list<int>  $accountIds
     * @param  list<array{asset: string, account: int}>|null  $pairs  Asset ulid and account id
     * @return array<int, int> Listings queued, by account id: only those this request queued itself
     */
    public function queue(User $user, array $accountIds, ?array $pairs = null): array
    {
        $works = $this->readyWorks($user);
        $queued = [];
        $wanted = $pairs === null ? null : collect($pairs)->mapWithKeys(fn (array $pair) => ["{$pair['asset']}|{$pair['account']}" => true]);

        if ($pairs !== null) {
            $accountIds = array_values(array_unique(array_column($pairs, 'account')));
        }

        foreach ($this->accounts($user)->whereIn('id', $accountIds) as $account) {
            $plan = $this->plan($works, $account);
            $items = $wanted === null
                ? $plan['items']
                : array_values(array_filter($plan['items'], fn (array $item) => $wanted->has("{$item['asset']->ulid}|{$account->id}")));

            if ($plan['blocked'] || ! $items) {
                continue;
            }

            $count = DB::transaction(function () use ($account, $items) {
                $count = 0;

                foreach ($items as ['asset' => $asset, 'check' => $check, 'listing' => $listing]) {
                    $attributes = [
                        'status' => ListingStatus::Queued,
                        'warnings' => $check->warnings,
                        'error' => null,
                        'attempts' => 0,
                    ];

                    if ($listing) {
                        // Failed or cancelled: queued again only if nothing changed it meanwhile.
                        $count += AssetListing::query()->whereKey($listing->id)->retryable()->update([
                            ...$attributes,
                            'warnings' => json_encode($check->warnings),
                            'updated_at' => now(),
                        ]);
                    } else {
                        // Another request may have queued the pair a moment ago: the unique index keeps one.
                        $count += AssetListing::query()->insertOrIgnore([
                            ...$attributes,
                            'asset_id' => $asset->id,
                            'marketplace_account_id' => $account->id,
                            'warnings' => json_encode($check->warnings),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($count) {
                    PublishListings::dispatch($account)->afterCommit();
                }

                return $count;
            });

            if ($count) {
                $queued[$account->id] = $count;
            }
        }

        return $queued;
    }

    /**
     * Takes a listing out of the queue before its upload starts.
     */
    public function cancel(AssetListing $listing): bool
    {
        $cancelled = AssetListing::query()->whereKey($listing->id)->where('status', ListingStatus::Queued)
            ->update(['status' => ListingStatus::Cancelled, 'updated_at' => now()]) > 0;

        if ($cancelled) {
            $this->settle($listing->asset);
        }

        return $cancelled;
    }

    /**
     * Queues a failed or cancelled listing again with fresh checks (ListingChecks, sendable) and starts its
     * account's session. False when the listing was neither failed nor cancelled.
     */
    public function retry(AssetListing $listing, ListingCheck $check): bool
    {
        return DB::transaction(function () use ($listing, $check) {
            $queued = AssetListing::query()->whereKey($listing->id)->retryable()->update([
                'status' => ListingStatus::Queued,
                'warnings' => json_encode($check->warnings),
                'error' => null,
                'attempts' => 0,
                'updated_at' => now(),
            ]) > 0;

            if ($queued) {
                PublishListings::dispatch($listing->account)->afterCommit();
                $this->notify($listing->asset);
            }

            return $queued;
        });
    }

    /**
     * The next queued listing of the account, moved to "sending" with the metadata it is sent with. A listing whose
     * work left the batch (back to draft, archived, deleted) is cancelled on the way; the work keeps it for the next
     * batch once it is ready again.
     */
    public function claim(MarketplaceAccount $account): ?AssetListing
    {
        while (true) {
            $listing = DB::transaction(function () use ($account) {
                $listing = $account->listings()->where('status', ListingStatus::Queued)->orderBy('id')->lockForUpdate()->first();

                if (! $listing) {
                    return null;
                }

                $asset = Asset::withTrashed()->with('files')->find($listing->asset_id);

                if (! $asset || $asset->trashed() || $asset->status !== AssetStatus::Ready) {
                    $listing->update(['status' => ListingStatus::Cancelled]);

                    return $asset ? $listing->setRelation('asset', $asset) : $listing;
                }

                $listing->update([
                    'status' => ListingStatus::Sending,
                    'metadata' => AssetListing::snapshot($asset, $account->marketplace),
                    'attempts' => $listing->attempts + 1,
                ]);

                return $listing->setRelation('asset', $asset)->setRelation('account', $account);
            });

            if (! $listing || $listing->status === ListingStatus::Sending) {
                return $listing;
            }

            if ($listing->relationLoaded('asset')) {
                $this->notify($listing->asset);
            }
        }
    }

    /**
     * Uploads cut off by a killed worker: back in the queue, or failed once they have used up their tries.
     * Called when a session starts; only one session runs per account, so nothing else is sending.
     */
    public function recover(MarketplaceAccount $account): void
    {
        $account->listings()->where('status', ListingStatus::Sending)->where('attempts', '>=', config('publishing.connect_tries'))
            ->update(['status' => ListingStatus::Failed, 'error' => ConnectionResult::OTHER, 'updated_at' => now()]);

        $account->listings()->where('status', ListingStatus::Sending)
            ->update(['status' => ListingStatus::Queued, 'updated_at' => now()]);
    }

    public function hasQueued(MarketplaceAccount $account): bool
    {
        return $account->listings()->pending()->exists();
    }

    public function sent(AssetListing $listing, string $remoteFilename): void
    {
        $listing->update([
            'status' => ListingStatus::Sent,
            'remote_filename' => $remoteFilename,
            'error' => null,
            'sent_at' => now(),
        ]);

        $this->settle($listing->asset);
    }

    public function failed(AssetListing $listing, string $reason): void
    {
        $listing->update(['status' => ListingStatus::Failed, 'error' => $reason]);

        $this->notify($listing->asset);
    }

    /**
     * Lost connection during an upload: the listing waits for the next session, or fails after its last try.
     */
    public function interrupted(AssetListing $listing): void
    {
        if ($listing->attempts >= config('publishing.connect_tries')) {
            $this->failed($listing, ConnectionResult::CONNECT);

            return;
        }

        $listing->update(['status' => ListingStatus::Queued]);
    }

    /**
     * Fails every queued listing of the account, e.g. when it was turned off or the server rejected the sign-in.
     */
    public function failQueued(MarketplaceAccount $account, string $reason): void
    {
        $assets = $account->listings()->pending()->pluck('asset_id');

        $account->listings()->pending()->update(['status' => ListingStatus::Failed, 'error' => $reason, 'updated_at' => now()]);

        Asset::withTrashed()->whereKey($assets)->with('user')->get()->each(fn (Asset $asset) => $this->notify($asset));
    }

    /**
     * A ready work whose listings are all sent becomes submitted. One queued, on its way, failed or cancelled keeps
     * it ready: the next batch still has to deliver that pair.
     */
    public function settle(Asset $asset): void
    {
        Asset::query()
            ->whereKey($asset->id)
            ->where('status', AssetStatus::Ready)
            ->whereHas('listings', fn ($query) => $query->where('status', ListingStatus::Sent))
            ->whereDoesntHave('listings', fn ($query) => $query->where('status', '!=', ListingStatus::Sent))
            ->update(['status' => AssetStatus::Submitted, 'updated_at' => now()]);

        $this->notify($asset);
    }

    /** The user's browsers fetch the work again with its listings. */
    protected function notify(Asset $asset): void
    {
        Realtime::changed($asset->user, 'asset', $asset->ulid, 'listing');
    }

    /**
     * A ready work for the publish popover: what its row shows, and the checks on every account the batch can still
     * send it to. Blocked accounts and accounts that already have the work (queued, on its way, sent) are left out.
     *
     * @param  Collection<int, array>  $plans
     * @return array<string, mixed>
     */
    protected function item(Asset $asset, Collection $plans): array
    {
        $thumbnail = $asset->file(AssetFileRole::Thumbnail) ?? $asset->file(AssetFileRole::Preview);

        return [
            'asset' => $asset->ulid,
            'title' => $asset->title ?? '',
            'original_filename' => $asset->original_filename,
            'type' => $asset->type->value,
            'width' => $asset->width,
            'height' => $asset->height,
            'duration_ms' => $asset->duration_ms,
            'size_bytes' => $asset->file(AssetFileRole::Original)?->size_bytes,
            'keywords' => count($asset->keywords ?? []),
            'thumbnail_url' => $thumbnail?->url(),
            'accounts' => $plans
                ->filter(fn (array $plan) => isset($plan['checks'][$asset->id]))
                ->map(fn (array $plan) => [
                    'account' => $plan['account']->id,
                    'errors' => $plan['checks'][$asset->id]->rejections(),
                    'warnings' => $plan['checks'][$asset->id]->warnings,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Asset>
     */
    protected function readyWorks(User $user): Collection
    {
        return $user->assets()->where('status', AssetStatus::Ready)->with(['files', 'listings'])->orderBy('id')->get();
    }

    /**
     * @return Collection<int, MarketplaceAccount>
     */
    protected function accounts(User $user): Collection
    {
        return $user->marketplaceAccounts()->with('marketplace')->orderBy('id')->get();
    }

    /**
     * Works of the batch for one account with their checks, and why the account takes none. `unsupported` counts the
     * works it will not take (type, file limits); `checks` has every pair the batch could still send, rejected ones
     * included, by asset id.
     *
     * @param  Collection<int, Asset>  $works
     * @return array{account: MarketplaceAccount, blocked: ?string, unsupported: int, items: list<array{asset: Asset, check: ListingCheck, listing: ?AssetListing}>, checks: array<int, ListingCheck>}
     */
    protected function plan(Collection $works, MarketplaceAccount $account): array
    {
        $plan = ['account' => $account, 'blocked' => $this->checks->accountBlocker($account), 'unsupported' => 0, 'items' => [], 'checks' => []];

        if ($plan['blocked']) {
            return $plan;
        }

        foreach ($works as $asset) {
            $listing = $asset->listings->firstWhere('marketplace_account_id', $account->id);

            // Queued, on its way or sent: a new batch neither sends it again nor duplicates it.
            if ($listing && ! $listing->status->isRetryable()) {
                continue;
            }

            $check = $this->checks->check($asset, $account);
            $plan['checks'][$asset->id] = $check;

            if ($check->sendable()) {
                $plan['items'][] = ['asset' => $asset, 'check' => $check, 'listing' => $listing];
            } else {
                $plan['unsupported']++;
            }
        }

        return $plan;
    }
}
