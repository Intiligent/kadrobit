<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extension ID
    |--------------------------------------------------------------------------
    |
    | Chrome derives the ID from the public key in the extension's manifest
    | (extension/manifest.json, "key"), so it is the same for every install and
    | the OAuth redirect URI never changes. See docs/browser-extension.md.
    |
    */

    'id' => env('EXTENSION_ID', 'fpdfkhaaacgkafhamlleoadkbkekeokp'),

    /*
    |--------------------------------------------------------------------------
    | OAuth client
    |--------------------------------------------------------------------------
    |
    | Public Passport client (Authorization Code with PKCE, no secret) the
    | extension signs in with. The ID is fixed, so the extension is built with
    | it; OauthSeeder creates the client.
    |
    */

    'client_id' => env('EXTENSION_CLIENT_ID', '01a1972a-6ffd-7284-9536-5f5987dec9bd'),

    /*
    | Token scope the extension's tokens carry: it opens the extension API only,
    | and the rest of the API turns such tokens away.
    */

    'scope' => 'extension',

    /*
    | Personal tokens issued in Settings → Extension, the fallback when the
    | OAuth window cannot open: they last this many days.
    */

    'token_days' => (int) env('EXTENSION_TOKEN_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Marketplaces
    |--------------------------------------------------------------------------
    |
    | Marketplaces the extension has an adapter for (marketplaces.code): only
    | their accounts get the auto submit switch and appear in the extension API.
    | A marketplace is added with its adapter: 123RF (KB-80), Adobe Stock (KB-81).
    |
    */

    'marketplaces' => ['shutterstock'],

    /*
    | A sent work waits for the extension to submit it to review (the library
    | shows it as being sent) at most this many hours: marketplaces show new
    | files within minutes, and a file never shown does not spin forever.
    */

    'await_review_hours' => (int) env('EXTENSION_AWAIT_REVIEW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Download
    |--------------------------------------------------------------------------
    |
    | `npm run build` packs the extension into public/extension/ with a
    | release.json (version, date, file) that Settings → Extension reads.
    |
    */

    'release_path' => public_path('extension/release.json'),

];
