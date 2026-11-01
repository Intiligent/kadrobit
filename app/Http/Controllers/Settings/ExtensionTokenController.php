<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settings\ExtensionConnectionResource;
use App\Services\Extension\ExtensionTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Settings → Extension: personal tokens for the browser extension and disconnecting it.
 */
class ExtensionTokenController extends Controller
{
    public function __construct(protected ExtensionTokens $tokens) {}

    /**
     * A personal token to paste into the extension when its sign-in window cannot open. Shown once: only its
     * signature is kept.
     */
    public function store(Request $request): JsonResponse
    {
        $result = $this->tokens->issue($request->user());

        return ExtensionConnectionResource::make($result->getToken()->load('client', 'refreshToken'))
            ->additional(['token' => $result->accessToken])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Disconnects an extension at once. Someone else's or a non-extension token gives 404.
     */
    public function destroy(Request $request, string $token): Response
    {
        $connection = $this->tokens->connections($request->user())->firstWhere('id', $token);
        abort_unless($connection, 404);

        $this->tokens->revoke($connection);

        return response()->noContent();
    }
}
