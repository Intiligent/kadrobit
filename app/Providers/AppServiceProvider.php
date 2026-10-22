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
     */
    protected function configurePassport(): void
    {
        $keyPath = config('passport.key_path');

        Passport::loadKeysFrom(str_starts_with($keyPath, '/') ? $keyPath : base_path($keyPath));

        Passport::personalAccessTokensExpireIn(now()->addDay());
        Passport::tokensExpireIn(now()->addDay());
        Passport::refreshTokensExpireIn(now()->addDays(7));
        Passport::cookie('api_token');
    }
}
