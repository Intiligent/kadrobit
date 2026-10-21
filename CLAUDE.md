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

## Code style

- Eloquent models declare their settings as explicit properties (`$fillable`, `$hidden`, `$guarded`, `$table`,
  `$appends`, etc.) with docblocks, not with PHP attributes such as `#[Fillable]` or `#[Hidden]`.

## Branches

- One branch per issue, created from an up-to-date `main` and named exactly `KB-<issue number>`, e.g. `KB-2`.
- Switch to the branch before starting the task; all commits for the issue go there.

## Tests

- Tests run on PostgreSQL database `kadrobit_testing` (see `phpunit.xml`), never on SQLite: migrations use jsonb, GIN and tsvector.
- Before every run: `php artisan config:clear` and make sure `bootstrap/cache/config.php` does not exist.
  `Tests\TestCase` refuses to boot unless the database name ends with `_testing`.

## Commits

- Format: `KB-<issue number> - <title>`, e.g. `KB-3 - Add Passport personal access token cookie`.
- Title: English, imperative mood, capitalized, no trailing period, up to 72 characters.
- Use the number of the sub-issue for the stage being done; the epic number only for changes that span stages.
- Optional body after a blank line: short bullet list of what changed.
- No `Co-Authored-By` trailers.
- Work stage by stage. When a logical block is done, end the reply with the proposed commit message
  in this format.
