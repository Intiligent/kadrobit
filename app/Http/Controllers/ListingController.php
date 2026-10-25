<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Exceptions\ExceptionWithMessage;
use App\Http\Resources\AssetListingResource;
use App\Models\AssetListing;
use App\Services\Marketplaces\ListingChecks;
use App\Services\Marketplaces\Publishing;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishing to marketplaces (KB-30, docs/publishing.md): the batch of ready works, sending it, and cancelling or
 * retrying one listing.
 */
class ListingController extends Controller
{
    public function __construct(protected Publishing $publishing) {}

    /**
     * What the next batch would send to each connected account, with the checks that did not pass.
     */
    public function batch(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->publishing->summary($request->user())]);
    }

    /**
     * Sends the chosen work–account pairs (the publish popover), at most publishing.batch_works works.
     * Pairs already queued, on their way or sent are left as they are.
     */
    public function publish(Request $request): JsonResponse
    {
        $limit = config('publishing.batch_works');

        $pairs = $request->validate([
            // "asset ulid|account id" strings checked in one pass, cheaper than per-item rules (pairs.*.asset).
            'pairs' => ['bail', 'required', 'array', 'min:1', 'max:'.$limit * max(1, $request->user()->marketplaceAccounts()->count()), function (string $attribute, array $pairs, Closure $fail) use ($limit) {
                foreach ($pairs as $pair) {
                    if (! is_string($pair) || ! preg_match(Publishing::PAIR_PATTERN, $pair)) {
                        $fail('validation.regex')->translate();

                        return;
                    }
                }

                if (count(array_unique(array_map(fn (string $pair) => strstr($pair, '|', true), $pairs))) > $limit) {
                    $fail(trans_choice('library.publishing.too_many', $limit, ['max' => $limit]));
                }
            }],
        ])['pairs'];

        return response()->json(['data' => [
            // By account id; an object even when empty.
            'queued' => (object) $this->publishing->queue($request->user(), array_values(array_unique($pairs))),
        ]], 202);
    }

    public function cancel(Request $request, string $listing): AssetListingResource
    {
        $listing = $this->find($request, $listing);

        if (! $this->publishing->cancel($listing)) {
            throw new ExceptionWithMessage(__('library.publishing.cancel_too_late'), 409);
        }

        return AssetListingResource::make($listing->refresh());
    }

    /**
     * Queues a failed or cancelled listing again. Only for a ready work (one moved out of the batch would be
     * cancelled at once), not for an account that is turned off or lost its sign-in, and not for a file the
     * marketplace rejects (resolution, size).
     */
    public function retry(Request $request, string $listing, ListingChecks $checks): AssetListingResource
    {
        $listing = $this->find($request, $listing);

        if ($listing->asset->status !== AssetStatus::Ready) {
            throw new ExceptionWithMessage(__('library.publishing.retry_not_ready'), 409);
        }

        $check = $checks->check($listing->asset->load('files'), $listing->account);

        if (! $check->sendable()) {
            $reason = $check->rejections()[0];

            throw new ExceptionWithMessage(__("library.checks.{$reason['key']}", $reason['params']), 409);
        }

        if (! $this->publishing->retry($listing, $check)) {
            throw new ExceptionWithMessage(__('library.publishing.retry_not_failed'), 409);
        }

        return AssetListingResource::make($listing->refresh());
    }

    /**
     * A listing of the user's own account; someone else's is not found rather than forbidden.
     */
    protected function find(Request $request, string $id): AssetListing
    {
        return AssetListing::query()
            ->whereHas('account', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with(['asset', 'account.marketplace'])
            ->findOrFail($id);
    }
}
