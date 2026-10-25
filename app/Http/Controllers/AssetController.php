<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\MediaType;
use App\Enums\MetadataSource;
use App\Exceptions\ExceptionWithMessage;
use App\Http\Requests\Assets\MoveAssetsRequest;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetUploadResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\MarketplaceAccountResource;
use App\Jobs\DeleteAssetStorage;
use App\Models\MarketplaceCategory;
use App\Services\Assets\UploadBatches;
use App\Services\Metadata\AssetMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    /** Works per GET /api/assets request. */
    public const LIST_MAX = 100;

    /**
     * The library page: explorer, asset grid and info panel.
     */
    public function index(Request $request, UploadBatches $batches): Response
    {
        $user = $request->user();

        // TODO: paginate (Inertia infinite scroll) once libraries grow past a few hundred works.
        $assets = $user->assets()->with(AssetResource::RELATIONS)->latest()->get();

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
        $attributes = $request->assetAttributes();

        $asset->update($attributes);

        // A field the user edits is theirs from now on: generation no longer fills it on its own. Merged in the
        // database, not from this copy of the work: a generation applied meanwhile keeps its marks for other fields.
        // Status, folder and flags alone leave the sources as they are.
        $edited = array_values(array_intersect(AssetMetadataService::FIELDS, array_keys($attributes)));

        if ($edited) {
            DB::update('update assets set metadata_sources = metadata_sources || ?::jsonb where id = ?', [
                json_encode(array_fill_keys($edited, MetadataSource::User->value)),
                $asset->id,
            ]);
            $asset->refresh();
        }

        return AssetResource::make($asset->load(AssetResource::RELATIONS));
    }

    /**
     * Deletes an archived work for good: its rows now, its files in R2 right after (DeleteAssetStorage).
     * Only from the archive, so a work is never lost in one click from the library.
     */
    public function destroy(Request $request, string $asset): HttpResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($asset, $user) {
            // Locked and checked in the transaction: a restore from another tab cannot slip in between.
            $work = $user->ownAsset($asset, lock: true);

            if ($work->status !== AssetStatus::Archived) {
                throw new ExceptionWithMessage(__('library.delete.not_archived'), 409);
            }

            // Its upload may be running: the file must stay until it is over. A sent work can go, the marketplace
            // keeps its copy (docs/publishing.md).
            if ($work->listings()->pending()->exists()) {
                throw new ExceptionWithMessage(__('library.delete.publishing'), 409);
            }

            // Not a soft delete: the work and its file rows (cascade) are gone, and the same file can be uploaded again.
            $work->forceDelete();

            DeleteAssetStorage::dispatch($user->ulid, $work->ulid)->afterCommit();
        });

        return response()->noContent();
    }

    /**
     * Fresh versions of some of the user's works, e.g. after a real-time notice that they changed.
     * Works of other users and deleted ones are simply not in the answer.
     */
    public function list(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::LIST_MAX],
            'ids.*' => ['string'],
        ])['ids'];

        $works = $request->user()->assets()->whereIn('ulid', $ids)->with(AssetResource::RELATIONS)->get();

        return response()->json(['data' => AssetResource::collection($works)->resolve($request)]);
    }

    /**
     * Puts several works into one folder (or Unsorted) at once, e.g. dragged onto the explorer.
     */
    public function move(MoveAssetsRequest $request): JsonResponse
    {
        $user = $request->user();
        $folderId = $request->ownFolderId($request->input('folder_id'));

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
