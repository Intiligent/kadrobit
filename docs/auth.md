# Авторизация

Эпик: [KB-1](https://github.com/Intiligent/kadrobit/issues/1). Документ дополняется по мере выполнения этапов.

## Схема

- Вход в приложение — обычная сессия Laravel (guard `web`).
- Для `/api/*` — guard `api` (драйвер `passport`). Токен — personal access token Passport, живёт сутки.
- Выдача токена при входе, передача через HttpOnly cookie `api_token` и перевыдача истёкшего токена — этап [KB-4](https://github.com/Intiligent/kadrobit/issues/4).

## Модель пользователя

| Поле | Тип | Примечание |
|---|---|---|
| `first_name` | string | обязательное |
| `last_name` | string, nullable | |
| `username` | string(32), unique | хранится в нижнем регистре |
| `avatar` | string, nullable | URL |
| `email` | string, unique | |
| `email_verified_at` | timestamp, nullable | `User` реализует `MustVerifyEmail` |

- `User::generateUsername($seed)` — уникальный username из произвольной строки (для регистрации через Google): только `a-z0-9_.-`, не короче 3 и не длиннее 20 символов, при совпадении добавляется числовой суффикс.
- Миграция `split_name_on_users_table` переносит старое `name` целиком в `first_name` и выдаёт username вида `user<id>`.

## Ключи Passport

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `PASSPORT_KEY_PATH` | `storage` | Каталог с `oauth-private.key` и `oauth-public.key`. Относительный путь считается от корня проекта, можно указать абсолютный. |
| `PASSPORT_PRIVATE_KEY` | — | Содержимое приватного ключа. Если задано, файл не читается. |
| `PASSPORT_PUBLIC_KEY` | — | Содержимое публичного ключа. Если задано, файл не читается. |

`PASSPORT_KEY_PATH` задаётся в `config/passport.php` → `key_path` и применяется в `AppServiceProvider` через `Passport::loadKeysFrom()`, поэтому его используют и guard, и `php artisan passport:keys`.

Сгенерировать ключи: `php artisan passport:keys` (с `--force` — перезаписать; все выданные токены станут недействительными).

## Первичная настройка

```sh
php artisan migrate
php artisan db:seed --class=OauthSeeder   # или php artisan db:seed — вызывает его сам
```

`OauthSeeder` можно запускать повторно:

- создаёт ключи `oauth-private.key` и `oauth-public.key` в каталоге `PASSPORT_KEY_PATH`, только если их нет и не заданы `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`;
- создаёт personal access client для провайдера `users`, только если активного клиента ещё нет.

Без ключей и клиента guard `api` отвечает 500, а `createToken()` падает с ошибкой «Personal access client not found».

## Прод

- Ключи генерируются один раз и хранятся вне репозитория (`/storage/*.key` в `.gitignore`), права `600`. Если ключи лежат вне `storage`, укажите каталог в `PASSPORT_KEY_PATH`.
- При деплое ключи не перегенерировать: все выданные токены станут недействительными.
- Вместо файлов можно передать ключи через `PASSPORT_PRIVATE_KEY` и `PASSPORT_PUBLIC_KEY`.

## API

| Метод | Путь | Guard | Ответ |
|---|---|---|---|
| GET | `/api/user` | `auth:api` | `UserResource`: id, first_name, last_name, username, email, avatar, email_verified |

## Тесты

- Тесты идут на отдельной базе PostgreSQL `kadrobit_testing` (задано в `phpunit.xml`): миграции используют jsonb, GIN-индексы и tsvector, SQLite не подходит.
- Создать базу один раз: `createdb kadrobit_testing`.
- `Tests\TestCase` не запускается, если имя базы не заканчивается на `_testing`: так закешированный конфиг не даст `RefreshDatabase` снести рабочую базу. Перед прогоном — `php artisan config:clear`.
- Для запросов к `/api/*` в тестах нужен `$this->seed(OauthSeeder::class)` или `Passport::actingAs($user)`.
