<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAccountResolver;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * "Continue with Google" on the login and register pages. Stateful: Socialite keeps the OAuth state
 * in the session, and the intended URL survives the round trip to Google.
 */
class OAuthController extends Controller
{
    public function __construct(protected GoogleAccountResolver $accounts) {}

    public function redirectToGoogle(): SymfonyRedirectResponse
    {
        abort_unless(static::googleEnabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        abort_unless(static::googleEnabled(), 404);

        // The user pressed "Cancel" on the Google consent screen.
        if ($request->filled('error')) {
            return $this->fail('auth.google.cancelled');
        }

        try {
            /** @var User $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|GuzzleException $e) {
            // A stale or replayed callback, or Google not answering.
            Log::warning('Google login failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->fail('auth.google.failed');
        }

        if (! filled($googleUser->getEmail())) {
            return $this->fail('auth.google.failed');
        }

        $user = $this->accounts->resolve($googleUser);

        if (! $user) {
            return $this->fail('auth.google.unverified_email');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('home'));
    }

    /**
     * The button is shown and the routes answer only once the Google client is configured.
     */
    public static function googleEnabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    protected function fail(string $message): RedirectResponse
    {
        Inertia::flash('error', __($message));

        return redirect()->route('login');
    }
}
