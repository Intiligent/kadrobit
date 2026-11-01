<?php

namespace App\Services\Extension;

use App\Enums\ListingStatus;
use App\Enums\ModerationStatus;
use App\Models\AssetListing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Counts for the extension's popup: works on the marketplaces' servers and their review, as the extension last
 * read it there. Review counts are only as fresh as checked_at: the extension reads them on the marketplace's site.
 */
class ExtensionStats
{
    /**
     * By account id.
     *
     * @return Collection<int, array{sent: int, pending: int, accepted: int, rejected: int, checked_at: string|null}>
     */
    public function byAccount(User $user): Collection
    {
        return AssetListing::query()
            ->whereIn('marketplace_account_id', $user->marketplaceAccounts()->select('id'))
            ->groupBy('marketplace_account_id')
            ->selectRaw('marketplace_account_id')
            ->selectRaw('count(*) filter (where status = ?) as sent', [ListingStatus::Sent->value])
            ->selectRaw('count(*) filter (where moderation_status = ?) as pending', [ModerationStatus::Pending->value])
            ->selectRaw('count(*) filter (where moderation_status = ?) as accepted', [ModerationStatus::Accepted->value])
            ->selectRaw('count(*) filter (where moderation_status = ?) as rejected', [ModerationStatus::Rejected->value])
            ->selectRaw('max(moderation_checked_at) as checked_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->marketplace_account_id => [
                'sent' => (int) $row->sent,
                'pending' => (int) $row->pending,
                'accepted' => (int) $row->accepted,
                'rejected' => (int) $row->rejected,
                'checked_at' => $row->checked_at ? Carbon::parse($row->checked_at)->toIso8601String() : null,
            ]]);
    }

    /**
     * Sum of the accounts' counts; checked_at is the latest.
     *
     * @param  Collection<int, array{sent: int, pending: int, accepted: int, rejected: int, checked_at: string|null}>  $accounts
     * @return array{sent: int, pending: int, accepted: int, rejected: int, checked_at: string|null}
     */
    public function total(Collection $accounts): array
    {
        return [
            'sent' => $accounts->sum('sent'),
            'pending' => $accounts->sum('pending'),
            'accepted' => $accounts->sum('accepted'),
            'rejected' => $accounts->sum('rejected'),
            'checked_at' => $accounts->pluck('checked_at')->filter()->sortDesc()->first(),
        ];
    }

    /**
     * Counts of an account without listings.
     *
     * @return array{sent: int, pending: int, accepted: int, rejected: int, checked_at: null}
     */
    public static function empty(): array
    {
        return ['sent' => 0, 'pending' => 0, 'accepted' => 0, 'rejected' => 0, 'checked_at' => null];
    }
}
