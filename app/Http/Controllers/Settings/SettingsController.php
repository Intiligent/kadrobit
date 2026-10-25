<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settings\MarketplaceCatalogResource;
use App\Http\Resources\Settings\MarketplaceConnectionResource;
use App\Models\Marketplace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings pages. Sections without a page yet are shown disabled in the nav.
 */
class SettingsController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('settings.marketplaces');
    }

    public function marketplaces(Request $request): Response
    {
        $accounts = $request->user()->marketplaceAccounts()->with('marketplace')->oldest()->get();

        return Inertia::render('settings/marketplaces/index', [
            'accounts' => MarketplaceConnectionResource::collection($accounts)->resolve(),
            'catalog' => MarketplaceCatalogResource::collection(Marketplace::query()->orderBy('name')->get())->resolve(),
        ]);
    }

    /**
     * Metadata defaults. The values come from the shared `settings` prop.
     */
    public function metadata(): Response
    {
        return Inertia::render('settings/metadata/index');
    }
}
