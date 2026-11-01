<?php

namespace App\Http\Controllers\Extension;

use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\ReportListingsRequest;
use App\Http\Resources\Extension\ExtensionListingResource;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Services\Extension\ExtensionStats;
use App\Services\Extension\ExtensionTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Laravel\Passport\Passport;

/**
 * The API of the browser extension (docs/browser-extension.md): only tokens with the extension scope get here.
 * The extension reads the works sent to the marketplace whose site is open and reports back what it found there.
 */
class ExtensionController extends Controller
{
    public const LISTINGS_PER_PAGE = 200;

    public function __construct(protected ExtensionStats $stats) {}

    /**
     * The popup: the Kadrobit account, the accounts on marketplaces the extension supports and their counts.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $user->marketplaceAccounts()->with('marketplace')->oldest()->orderBy('id')->get()
            ->filter(fn (MarketplaceAccount $account) => $account->marketplace->supportsExtension())
            ->values();

        // Counted over the listed accounts only, so the total is their sum.
        $stats = $this->stats->byAccount($accounts->modelKeys());

        $accounts = $accounts->map(fn (MarketplaceAccount $account) => [
            'id' => $account->id,
            'label' => $account->label,
            'status' => $account->status->value,
            'auto_submit' => $account->autoSubmits(),
            'marketplace' => [
                'code' => $account->marketplace->code,
                'name' => $account->marketplace->name,
            ],
            'stats' => $stats[$account->id] ?? ExtensionStats::empty(),
        ]);

        return response()->json(['data' => [
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ],
            'stats' => $this->stats->total($stats),
            'accounts' => $accounts,
        ]]);
    }

    /**
     * Works on the servers of one marketplace (?marketplace=<code>), across the user's accounts there that are not
     * turned off: the extension cannot tell which account the open site belongs to, the file names can. Page by page,
     * oldest first.
     */
    public function listings(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'marketplace' => ['required', 'string', Rule::in(config('extension.marketplaces'))],
        ]);

        $listings = AssetListing::query()
            ->with(['asset', 'account.marketplace'])
            ->whereHas('account', fn ($query) => $query
                ->where('user_id', $request->user()->getKey())
                ->where('status', '!=', MarketplaceAccountStatus::Disabled)
                ->whereHas('marketplace', fn ($marketplace) => $marketplace->where('code', $request->input('marketplace'))))
            ->where('status', ListingStatus::Sent)
            ->orderBy('id')
            ->cursorPaginate(self::LISTINGS_PER_PAGE)
            ->withQueryString();

        $names = $this->categoryNames($listings->getCollection());
        $resources = ExtensionListingResource::collection($listings);

        $resources->collection->each(function (ExtensionListingResource $resource) use ($names) {
            $resource->categoryNames = $names[$resource->account->marketplace_id] ?? [];
        });

        return $resources;
    }

    /**
     * Saves what the extension found: the work's ID and page on the marketplace, its review status, and that the
     * metadata on the marketplace is now the work's (metadata_hash from listings(); a work edited meanwhile stays
     * outdated). Works of turned-off accounts are skipped. Answers how many works were updated.
     */
    public function report(ReportListingsRequest $request): JsonResponse
    {
        $reports = collect($request->validated('listings'))->keyBy('id');

        $listings = AssetListing::query()
            ->with(['asset', 'account.marketplace'])
            ->whereKey($reports->keys())
            ->whereHas('account', fn ($query) => $query
                ->where('user_id', $request->user()->getKey())
                ->where('status', '!=', MarketplaceAccountStatus::Disabled))
            ->where('status', ListingStatus::Sent)
            ->get()
            ->filter(fn (AssetListing $listing) => $listing->account->marketplace->supportsExtension());

        foreach ($listings as $listing) {
            $report = $reports[$listing->id];
            $listing->fill(collect($report)->only(['external_id', 'external_url'])->all());

            if (array_key_exists('moderation_status', $report)) {
                $listing->moderation_status = $report['moderation_status'];
                $listing->moderation_checked_at = now();
            }

            $metadata = AssetListing::snapshot($listing->asset, $listing->account->marketplace);

            if (isset($report['metadata_hash']) && hash_equals(ExtensionListingResource::hash($metadata), $report['metadata_hash'])) {
                $listing->metadata = $metadata;
            }

            $listing->save();
        }

        return response()->json(['data' => ['updated' => $listings->count()]]);
    }

    /**
     * "Sign out" in the extension: its token and refresh token stop working.
     */
    public function logout(Request $request, ExtensionTokens $tokens): Response
    {
        $token = Passport::token()->newQuery()->find($request->user()->token()->oauth_access_token_id);

        if ($token) {
            $tokens->revoke($token);
        }

        return response()->noContent();
    }

    /**
     * Category names of the listed works, by marketplace id and the category's external_id.
     *
     * @param  iterable<AssetListing>  $listings
     * @return array<int, array<string, string>>
     */
    protected function categoryNames(iterable $listings): array
    {
        $wanted = collect($listings)
            ->groupBy(fn (AssetListing $listing) => $listing->account->marketplace_id)
            ->map(fn ($group, $marketplaceId) => $group
                ->flatMap(fn (AssetListing $listing) => $listing->asset->categories[$listing->account->marketplace->code] ?? [])
                ->unique()
                ->values());

        return $wanted->map(fn ($ids, $marketplaceId) => MarketplaceCategory::query()
            ->where('marketplace_id', $marketplaceId)
            ->whereIn('external_id', $ids)
            ->pluck('name', 'external_id')
            ->all())
            ->all();
    }
}
