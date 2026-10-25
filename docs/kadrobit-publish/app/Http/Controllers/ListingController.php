<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Exceptions\ExceptionWithMessage;
use App\Http\Resources\AssetListingResource;
use App\Models\AssetListing;
use App\Services\Marketplaces\ListingChecks;
use App\Services\Marketplaces\Publishing;
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
     * Sends the batch: to the chosen accounts, or only the chosen work–account pairs (the publish popover).
     * Pairs already queued, on their way or sent are left as they are.
     */
    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accounts' => ['required_without:pairs', 'array', 'min:1'],
            'accounts.*' => ['integer'],
            'pairs' => ['required_without:accounts', 'array', 'min:1', 'max:10000'],
            'pairs.*.asset' => ['required', 'string', 'max:26'],
            'pairs.*.account' => ['required', 'integer'],
        ]);

        $queued = isset($data['pairs'])
            ? $this->publishing->queue($request->user(), [], array_map(
                fn (array $pair) => ['asset' => $pair['asset'], 'account' => (int) $pair['account']],
                $data['pairs'],
            ))
            : $this->publishing->queue($request->user(), array_map('intval', $data['accounts']));

        return response()->json(['data' => [
            // By account id; an object even when empty.
            'queued' => (object) $queued,
            'summary' => $this->publishing->summary($request->user()),
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
