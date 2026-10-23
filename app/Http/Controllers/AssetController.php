<?php

namespace App\Http\Controllers;

use App\Enums\MediaType;
use App\Http\Requests\Assets\MoveAssetsRequest;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetUploadResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\MarketplaceAccountResource;
use App\Models\MarketplaceCategory;
use App\Services\Assets\UploadBatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    /**
     * The library page: explorer, asset grid and info panel.
     */
    public function index(Request $request, UploadBatches $batches): Response
    {
        $user = $request->user();

        // TODO: paginate (Inertia infinite scroll) once libraries grow past a few hundred works.
        $assets = $user->assets()->with(['files', 'folder:id,ulid'])->latest()->get();

        // TODO: это здесь не нужно с большой вероятностью, а то что это выводится в интерфейсе, то этого не будет. это переед в отдельный раздел настроек
        $accounts = $user->marketplaceAccounts()
            ->with('marketplace')
            ->get()
            ->sortBy(fn ($account) => $account->marketplace->name)
            ->values();

        // Flat list in tree order; the explorer builds the tree.
        $folders = $user->folders()->with('parent:id,ulid')->ordered()->get();

        return Inertia::render('library/index', [
            'assets' => AssetResource::collection($assets)->resolve(),
            'folders' => FolderResource::collection($folders)->resolve(),
            'accounts' => MarketplaceAccountResource::collection($accounts)->resolve(),
            'categories' => $this->categories($accounts->pluck('marketplace_id')->unique()->all()),
            'upload' => [
                'accept' => StoreAssetUploadRequest::ACCEPT,
                'max_size_mb' => StoreAssetUploadRequest::maxSizeMb(),
                'max_batch_files' => config('assets.upload_batch_max_files'),
                // Batches still being processed: the upload list picks them up again after a reload.
                'batches' => collect($batches->active($user))->map(fn (array $batch) => [
                    'id' => $batch['id'],
                    'missing' => $batch['missing'],
                    'uploads' => AssetUploadResource::collection($batch['uploads'])->resolve(),
                ])->all(),
            ],
        ]);
    }

    /**
     * Partial update of the base metadata and status; the info panel autosaves through it.
     */
    public function update(UpdateAssetRequest $request): AssetResource
    {
        $asset = $request->asset();
        $asset->update($request->assetAttributes());

        return AssetResource::make($asset->load(['files', 'folder:id,ulid']));
    }

    /**
     * Puts several works into one folder (or Unsorted) at once, e.g. dragged onto the explorer.
     */
    public function move(MoveAssetsRequest $request): JsonResponse
    {
        $user = $request->user();
        $folderId = $request->input('folder_id') ? $user->folders()->where('ulid', $request->input('folder_id'))->value('id') : null;

        $moved = $user->assets()->whereIn('ulid', $request->input('assets'))->update(['folder_id' => $folderId]);

        return response()->json(['data' => ['moved' => $moved]]);
    }

    /**
     * Category lists by marketplace code, limited to the marketplaces the user is connected to.
     *
     * @param  array<int, int>  $marketplaceIds
     * @return array<string, array<int, array{id: string, name: string, media_types: array<int, string>}>>
     */
    protected function categories(array $marketplaceIds): array
    {
        if (! $marketplaceIds) {
            return [];
        }

        return MarketplaceCategory::query()
            ->whereIn('marketplace_id', $marketplaceIds)
            ->with('marketplace:id,code')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (MarketplaceCategory $category) => $category->marketplace->code)
            ->map(fn ($group) => $group->map(fn (MarketplaceCategory $category) => [
                'id' => $category->external_id,
                'name' => $category->name,
                'media_types' => $category->media_types->map(fn (MediaType $type) => $type->value)->values()->all(),
            ])->values()->all())
            ->all();
    }
}
