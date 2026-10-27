<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Enums\UserSettingKey;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The language switcher of the public pages. A guest's language is kept in the session (read by SetLocale);
 * a logged-in user's is their `general.locale` setting, which SetLocale prefers over the session.
 * The page reloads afterwards: translations are loaded once per page.
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ])['locale'];

        $request->session()->put(SetLocale::SESSION_KEY, $locale);
        $request->user()?->setSetting(UserSettingKey::GeneralLocale, $locale);

        return response()->noContent();
    }
}
