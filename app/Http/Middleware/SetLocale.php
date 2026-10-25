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
 * The setting is read from the `settings` relation that the shared Inertia prop loads anyway, so a page
 * visit costs no extra query; reading it rather than a session copy lets a change made through the
 * stateless API apply on the next request. The session keeps the user's language for the pages shown
 * after logout (login, password reset).
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->locale($request)->value);

        return $next($request);
    }

    protected function locale(Request $request): Locale
    {
        $session = $request->hasSession() ? $request->session() : null;
        $user = $request->user();

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
