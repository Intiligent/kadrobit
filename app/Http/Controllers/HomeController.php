<?php

namespace App\Http\Controllers;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The public home page: what Kadrobit does. Logged-in users land on their library instead, so every
     * redirect to "home" (after login, OAuth, email verification) ends there.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('library');
        }

        $marketplaces = Marketplace::query()->orderBy('name')->get(['code', 'name', 'is_active', 'upload_protocol']);
        $connectable = $marketplaces->filter(fn (Marketplace $marketplace) => $marketplace->is_active
            && in_array($marketplace->upload_protocol, UploadProtocol::fileTransfer(), true));
        // Listed but switched off in the catalogue (is_active = false), so they are not filtered by it.
        $webOnly = $marketplaces->filter(fn (Marketplace $marketplace) => $marketplace->upload_protocol === UploadProtocol::Web);

        return Inertia::render('home/index', [
            // The stock sites ribbon: those Kadrobit uploads to itself, with their protocol.
            'marketplaces' => $connectable
                ->map(fn (Marketplace $marketplace) => [
                    'code' => $marketplace->code,
                    'name' => $marketplace->name,
                    'protocol' => $marketplace->upload_protocol->value,
                ])
                ->values()
                ->all(),
            // Named under the ribbon: they take uploads only through their own websites.
            'unsupported' => $webOnly->pluck('name')->values()->all(),
        ]);
    }
}
