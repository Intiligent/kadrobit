<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Enums\UserSettingKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of the request: the user's `general.locale` setting, for a guest the language kept
 * in the session, otherwise English.
 *
 * The setting is read from the `settings` relation. On an Inertia page visit the shared `settings` prop
 * loads it anyway, so there is no extra query; other requests, the API included, make one query to
 * user_settings. Reading the setting rather than a session copy lets a change made through the stateless
 * API apply on the next request. The session keeps the user's language for the pages shown after logout
 * (login, password reset).
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    /**
     * @param  string|null  $guard  the guard to read the user from: `api` for the API, the default (web) otherwise
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        app()->setLocale($this->locale($request, $guard)->value);

        return $next($request);
    }

    protected function locale(Request $request, ?string $guard): Locale
    {
        $session = $request->hasSession() ? $request->session() : null;
        $user = $request->user($guard);

        if ($user instanceof User) {
            $locale = Locale::tryFrom((string) $user->setting(UserSettingKey::GeneralLocale)) ?? Locale::default();

            if ($session && $session->get(self::SESSION_KEY) !== $locale->value) {
                $session->put(self::SESSION_KEY, $locale->value);
            }

            return $locale;
        }

        return Locale::tryFrom((string) $session?->get(self::SESSION_KEY)) ?? Locale::default();
    }
}
