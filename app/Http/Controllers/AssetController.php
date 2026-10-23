<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Http\Resources\AssetResource;
use App\Http\Resources\MarketplaceAccountResource;
use App\Models\Folder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    /**
     * The library page: explorer, asset grid and info panel.
     */
    public function index(Request $request): Response
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

        // Flat list; the explorer builds the tree. Parents are referenced by ulid, like the works' folder_id.
        $folders = $user->folders()
            ->with('parent:id,ulid')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (Folder $folder) => [
                'id' => $folder->ulid,
                'parent_id' => $folder->parent?->ulid,
                'name' => $folder->name,
            ]);

        return Inertia::render('library/index', [
            'assets' => AssetResource::collection($assets)->resolve(),
            'folders' => $folders,
            'accounts' => MarketplaceAccountResource::collection($accounts)->resolve(),
            'upload' => [
                'accept' => StoreAssetUploadRequest::ACCEPT,
                'max_size_mb' => StoreAssetUploadRequest::maxSizeMb(),
                'max_batch_files' => config('assets.upload_batch_max_files'),
            ],
        ]);
    }
}
