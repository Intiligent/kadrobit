<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Project conventions

## Language

- Russian only for documentation and specifications in `docs/` and for GitHub issues (title and body).
- Everything in the code is in English: identifiers, comments, docblocks, log and exception messages,
  test names, migration and seeder content, config comments, commit messages.
- User-facing strings go through translation files, not hardcoded in Russian.

## GitHub issues

- Repository: `Intiligent/kadrobit`. The issue number `N` is the task key `KB-N`.
- Title format: `KB-<issue number> - <Area>: <what to do>`, the description in Russian, short, no trailing period.
  - Area is one word in English, capitalized: `Auth`, `Assets`, `Marketplaces`, `Storage`, `UI`, `Infra`, `Docs`.
  - Example: `KB-2 - Auth: Passport и модель пользователя`.
  - The number is known only after creation: create the issue, then rename it with the `KB-<number>` prefix.
- Large tasks are an epic issue with sub-issues, one per stage. The epic title starts with the area too
  and its body links the stages in order.
- Body: goal, scope as checkboxes, acceptance criteria, and a separate checklist of manual steps
  for the owner (accounts, keys, console settings) when there are any.

## Marketplaces

- `docs/marketplaces/README.md` is the status of every stock marketplace (coverage, delivery, metadata, categories,
  checks on a real account). Update it in the same change whenever a task adds or changes a marketplace's support.

## Code style

- Middleware configuration lives in `App\Http\Middleware\MiddlewareHandler`, exception handling in
  `App\Exceptions\ExceptionHandler`; `bootstrap/app.php` only wires them.
- Routes point to controller actions, not closures returning pages.
- Emails are sent through the queue: notifications implement `ShouldQueue`, mailables are queued, never sent inline.
- Every email gets a browser preview: a method in `App\Http\Controllers\Dev\EmailPreviewController` rendering the real
  notification's `toMail()`, and a route in the `dev/emails` group of `routes/web.php` (see `docs/development.md`).
- Table columns follow a logical order: keys and identifiers first, related fields grouped together,
  `created_at`/`updated_at` (and `deleted_at`) always last. A new column goes next to the fields it belongs with,
  not at the end. PostgreSQL ignores `->after()` and always appends: before the table has production data, put the
  column into the table's create migration instead of adding an alter migration; once it has, discuss
  rebuilding the table.
- Eloquent models declare their settings as explicit properties (`$fillable`, `$hidden`, `$guarded`, `$table`,
  `$appends`, etc.) with docblocks, not with PHP attributes such as `#[Fillable]` or `#[Hidden]`.

## Branches

- One branch per issue, created from an up-to-date `main` and named exactly `KB-<issue number>`, e.g. `KB-2`.
- Switch to the branch before starting the task; all commits for the issue go there.

## Frontend

- Inertia 3 + Vue 3 + Element Plus; see `docs/frontend.md`.
- Element Plus styles are compiled from its SCSS sources with the theme in `resources/style/theme/variables.scss`;
  never import its prebuilt CSS (`element-plus/dist/index.css`, `.../style/css`).
- UI controls are Element Plus components (`el-checkbox`, `el-radio-group`, `el-select`, `el-button`…), adjusted with
  styles where needed; never hand-made look-alikes (a `<button>` drawn as a checkbox, a custom switch).
- HTTP requests outside Inertia visits go through `resources/js/core/api.js`, never a bare axios/fetch call.
- User-facing texts on the frontend come from `lang/{locale}/*.php` via `laravel-vue-i18n` (`trans()`, `$t()`).
- Every new or changed string goes into all languages at once (`lang/en`, `lang/ru`): the languages never differ
  in keys or `:placeholders` (`tests/Unit/TranslationsTest.php`). Russian plurals have three forms, see `docs/frontend.md`.
- Error codes live in `App\Enums\ErrorCode` and `ERROR_CODE` in `resources/js/config/constant.js`; change both together.

## Local processes

- Queues, scheduler, logs and Vite run through Overmind: `overmind start` (`Procfile.dev`, see `docs/development.md`).
  New long-running processes (queues, frontend bundles) are added to `Procfile.dev`.

## Tests

- Tests run on PostgreSQL database `kadrobit_testing` (see `phpunit.xml`), never on SQLite: migrations use jsonb, GIN and tsvector.
- Before every run: `php artisan config:clear` and make sure `bootstrap/cache/config.php` does not exist.
  `Tests\TestCase` refuses to boot unless the database name ends with `_testing`.
- Frontend tests: `npm test` (Vitest, `tests/js`).

## Commits

- Format: `KB-<issue number> - <title>`, e.g. `KB-3 - Add Passport personal access token cookie`.
- Title: English, imperative mood, capitalized, no trailing period, up to 72 characters.
- Use the number of the sub-issue for the stage being done; the epic number only for changes that span stages.
- Optional body after a blank line: short bullet list of what changed.
- No `Co-Authored-By` trailers.
- Work stage by stage. When a logical block is done, end the reply with the proposed commit message
  in this format.
