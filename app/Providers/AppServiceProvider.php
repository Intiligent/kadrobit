<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use App\Services\Marketplaces\Delivery\Ftp\NativeFtpClient;
use App\Services\Marketplaces\Delivery\Ftp\PhpseclibSftpClient;
use App\Services\Marketplaces\Delivery\Ftp\SftpClient;
use App\Services\Metadata\Contracts\MetadataGenerator;
use App\Services\Metadata\MetadataGeneratorFactory;
use App\Support\CredentialsEncrypter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Marketplace credentials have their own key (config/credentials.php), resolved on first use.
        $this->app->singleton('credentials.encrypter', fn () => CredentialsEncrypter::make(config('credentials')));

        // The provider configured in config/metadata.php (METADATA_DRIVER).
        $this->app->bind(FtpClient::class, NativeFtpClient::class);
        $this->app->bind(SftpClient::class, PhpseclibSftpClient::class);
        $this->app->bind(MetadataGenerator::class, fn ($app) => $app->make(MetadataGeneratorFactory::class)->make());
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

        // Email confirmation code attempts: per user.
        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(10)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // The link from the confirmation email: its own bucket, so wrong codes typed in the same
        // browser do not block it.
        RateLimiter::for('verify-email-link', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Anything that sends another confirmation email: resend and address change.
        RateLimiter::for('verify-email-send', fn (Request $request) => Limit::perMinute(5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Metadata generation on request: each one is a paid call that takes a few seconds.
        RateLimiter::for('metadata-generation', fn (Request $request) => Limit::perMinute(20)
            ->by((string) $request->user()?->getAuthIdentifier()));

        // API token re-issue: per user.
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * API tokens are personal access tokens issued on login and kept in an HttpOnly cookie.
     * They live as long as the web session: renewed while the user is active, expired when the
     * session would be, so a forgotten browser does not keep API access after logout by timeout.
     *
     * The browser extension signs in through OAuth (docs/browser-extension.md): its access tokens live
     * as long, and the refresh token keeps it connected for a month without use.
     */
    protected function configurePassport(): void
    {
        $keyPath = config('passport.key_path');

        Passport::loadKeysFrom(str_starts_with($keyPath, '/') ? $keyPath : base_path($keyPath));

        $lifetime = now()->addMinutes((int) config('session.lifetime'));

        Passport::personalAccessTokensExpireIn($lifetime);
        Passport::tokensExpireIn($lifetime);
        Passport::refreshTokensExpireIn(now()->addDays(30));

        Passport::tokensCan([
            config('extension.scope') => 'Kadrobit browser extension',
        ]);

        Passport::authorizationView(fn (array $parameters) => $this->authorizationPage($parameters));
    }

    /**
     * The consent page of GET /oauth/authorize. Approve and deny are plain form posts to Passport,
     * which answers with a redirect to the client.
     *
     * @param  array{client: Client, user: User, scopes: list<Scope>, request: Request, authToken: string}  $parameters
     */
    protected function authorizationPage(array $parameters): Response
    {
        $request = $parameters['request'];

        return Inertia::render('auth/authorize/index', [
            'client' => [
                'id' => $parameters['client']->getKey(),
                'name' => $parameters['client']->name,
            ],
            'email' => $parameters['user']->email,
            'scopes' => collect($parameters['scopes'])->map(fn (Scope $scope) => $scope->id)->values()->all(),
            'state' => $request->input('state'),
            'authToken' => $parameters['authToken'],
            'csrfToken' => csrf_token(),
        ]);
    }
}
