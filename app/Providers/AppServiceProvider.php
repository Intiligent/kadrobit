<?php

namespace App\Providers;

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
