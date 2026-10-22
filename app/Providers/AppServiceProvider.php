<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePassport();
        $this->configureRateLimiting();
    }

    /**
     * The plain `throttle:6,1` keys guests by IP only, so every throttled route would share one counter.
     */
    protected function configureRateLimiting(): void
    {
        // Guest auth forms (register, password reset): per form and IP.
        RateLimiter::for('auth-forms', fn (Request $request) => Limit::perMinute(6)
            ->by(($request->route()?->getName() ?? $request->path()).'|'.$request->ip()));

        // Email confirmation: code attempts and link opens per user (or IP for the link without a login).
        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Anything that sends another confirmation email: resend and address change.
        RateLimiter::for('verify-email-send', fn (Request $request) => Limit::perMinute(5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // API token re-issue: per user.
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * API tokens are personal access tokens issued on login and kept in an HttpOnly cookie.
     * They live as long as the web session: renewed while the user is active, expired when the
     * session would be, so a forgotten browser does not keep API access after logout by timeout.
     */
    protected function configurePassport(): void
    {
        $keyPath = config('passport.key_path');

        Passport::loadKeysFrom(str_starts_with($keyPath, '/') ? $keyPath : base_path($keyPath));

        $lifetime = now()->addMinutes((int) config('session.lifetime'));

        Passport::personalAccessTokensExpireIn($lifetime);
        Passport::tokensExpireIn($lifetime);
        Passport::refreshTokensExpireIn(now()->addDays(7));
    }
}
