<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        // The API client sends users here as /login?redirect=/current/page after a 401.
        if ($redirect = $this->safeRedirect($request->query('redirect'))) {
            redirect()->setIntendedUrl(url($redirect));
        }

        return Inertia::render('auth/login/index', ['googleEnabled' => OAuthController::googleEnabled()]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * Only same-site paths: "/assets?page=2" is accepted, "https://evil.test" and "//evil.test" are not.
     */
    protected function safeRedirect(mixed $path): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return $path;
    }
}
