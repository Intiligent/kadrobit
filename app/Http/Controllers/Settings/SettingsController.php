<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Settings\ExtensionConnectionResource;
use App\Http\Resources\Settings\MarketplaceCatalogResource;
use App\Http\Resources\Settings\MarketplaceConnectionResource;
use App\Models\Marketplace;
use App\Services\Extension\ExtensionTokens;
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
        return redirect()->route('settings.general');
    }

    /**
     * General: the interface language. The chosen one comes from the shared `settings` prop.
     */
    public function general(): Response
    {
        return Inertia::render('settings/general/index', [
            'locales' => Locale::values(),
        ]);
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
     * Extension: download, installation steps and connected extensions. The release is what `npm run build` packed
     * into public/extension/ (config/extension.php); null when it has not been built.
     */
    public function extension(Request $request, ExtensionTokens $tokens): Response
    {
        $path = config('extension.release_path');
        $release = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        return Inertia::render('settings/extension/index', [
            'release' => $release ? [
                'version' => $release['version'],
                'built_at' => $release['built_at'],
                'url' => asset('extension/'.$release['file']),
            ] : null,
            'extensionId' => config('extension.id'),
            'connections' => ExtensionConnectionResource::collection($tokens->connections($request->user()))->resolve(),
        ]);
    }

    /**
     * Notifications: browser notifications per event. Whether this browser has them on is known only in the browser
     * (its push subscription); the event switches come from the shared `settings` prop.
     */
    public function notifications(): Response
    {
        return Inertia::render('settings/notifications/index', [
            'vapidPublicKey' => config('webpush.vapid.public_key'),
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
