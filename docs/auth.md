# Авторизация

Эпик: [KB-1](https://github.com/Intiligent/kadrobit/issues/1). Документ дополняется по мере выполнения этапов.

## Схема

- Вход в приложение — обычная сессия Laravel (guard `web`).
- Для `/api/*` — guard `api` (драйвер `passport`). Токен — personal access token Passport, живёт столько же, сколько сессия.
- Токен для API выдаётся при входе и передаётся в HttpOnly cookie `api_token` (подробнее — «Вход, регистрация, токен в cookie»).

## Вход, регистрация, токен в cookie

Задача [KB-4](https://github.com/Intiligent/kadrobit/issues/4).

### Маршруты

| Метод | Путь | Middleware | Что делает |
|---|---|---|---|
| GET | `/login` | guest | Страница входа. `?redirect=/путь` — куда вернуть после входа (только пути этого сайта) |
| POST | `/login` | guest | Вход. После 5 неудачных попыток для пары email + IP вход блокируется на минуту, ошибка показывается в поле email |
| GET | `/register` | guest | Страница регистрации |
| POST | `/register` | guest, `throttle:6,1` | Регистрация, сразу вход |
| POST | `/logout` | auth | Выход. Только POST |
| POST | `/auth/token` | auth, `throttle:30,1` | Новый токен в cookie для текущей сессии (вызывает API-клиент после 401) |

- Гостя со страниц под `auth` отправляет на `/login`, вошедшего со страниц под `guest` — на `/`.
- Email и username хранятся в нижнем регистре (мутаторы модели `User`, при любом способе создания), вход принимает email в любом регистре.
- Username: латиница, цифры, `.`, `_`, `-`, от 3 до 20 символов. Пароль — от 8 символов.
- Inertia-форма (POST/PUT/PATCH/DELETE), упёршаяся в лимит запросов (429), возвращает назад с flash-сообщением вместо страницы 429. GET-переходы так не обрабатываются: возврат назад мог бы попасть на ту же страницу и зациклиться.

### Токен в cookie

- На каждый вход (событие `Login` guard `web`, в том числе «запомнить меня») `LoginListener` создаёт personal access token и ставит cookie `api_token`: HttpOnly, SameSite не слабее `lax`. Вход без HTTP-сессии (artisan, очередь, сидер) токен не выдаёт.
- **Токен живёт столько же, сколько сессия** (`SESSION_LIFETIME`, по умолчанию 120 минут), cookie — так же. Пока пользователь активен, токен продлевается; если вкладку просто закрыли, API-доступ пропадает вместе с сессией.
- Cookie не шифруется (`encryptCookies except`): токен — подписанный JWT. `Passport::cookie()` не используется: это имя механизма Passport с зашифрованной cookie, с нашей оно бы конфликтовало.
- В сессии хранятся id токена, его хеш и время истечения, поэтому проверка «в cookie токен этой сессии и он не истекает» не обращается к базе.
- `PassportCookieAuthentication` (группа `api`) превращает cookie в `Authorization: Bearer`, если заголовка нет. Для POST/PUT/PATCH/DELETE — только если есть `X-Requested-With: XMLHttpRequest` (`api.js` отправляет его всегда). SameSite разделяет сайты, но не поддомены одного домена: обычная форма с чужого поддомена такой заголовок поставить не может, а fetch с другого источника остановит CORS.
- `RenewApiToken` (группа `web`) выдаёт новый токен, если у вошедшего пользователя нет cookie, в cookie чужой или устаревший токен, или до истечения меньше 5 минут. Старый токен сессии отзывается.
  - Продление сессии занимает её на 10 секунд (`Cache::add`), параллельные запросы токен не выпускают: иначе каждый выпустил бы свой, и при выходе отозвался бы только один.
  - Не срабатывает на `/logout` и `/auth/token` и если токен в этом запросе уже выдан (например, при входе через «запомнить меня»).
- Если API ответил 401, а сессия жива (токен отозван или истёк), `api.js` вызывает `POST /auth/token` и повторяет запрос. Если сессии нет — показывает диалог входа.
- `LogoutListener` отзывает только токен текущей сессии и удаляет cookie: на других устройствах вход сохраняется.
- Отозванные и истёкшие токены удаляет `passport:purge` раз в сутки (`routes/console.php`); на сервере должен работать `php artisan schedule:run`.

## Сброс пароля

Задача [KB-5](https://github.com/Intiligent/kadrobit/issues/5).

| Метод | Путь | Middleware | Что делает |
|---|---|---|---|
| GET | `/forgot-password` | guest | Форма запроса ссылки |
| POST | `/forgot-password` | guest, `throttle:6,1` | Отправляет письмо со ссылкой |
| GET | `/reset-password/{token}?email=…` | guest | Форма нового пароля (ссылка из письма) |
| POST | `/reset-password` | guest, `throttle:6,1` | Меняет пароль и ведёт на `/login` с сообщением |

- **Ответ не раскрывает, есть ли аккаунт:** на любой email форма отвечает одинаково («если аккаунт есть, письмо отправлено»), в том числе при повторном запросе в течение минуты (брокер отправляет не чаще раза в минуту). Неверный токен, истёкший токен и неизвестный email на странице сброса дают одну и ту же ошибку «ссылка недействительна».
- **Ссылка живёт 60 минут** и одноразовая (`config/auth.php` → `passwords.users.expire`).
- **Письмо** — `ResetPasswordNotification`, через очередь: форма отвечает одинаково быстро, есть аккаунт или нет. Шаблон — `resources/views/emails/reset-password.blade.php` на общем `emails/layout.blade.php`.
- **После сброса** (`PasswordResetListener`): новый `remember_token` завершает входы «запомнить меня», API-токены пользователя отзываются, его сессии в базе (`SESSION_DRIVER=database`) удаляются. Тот, кто знал старый пароль, теряет доступ на всех устройствах.
- Пароль — не короче 8 символов, с подтверждением.

### Пока не сделано

- Письмо для подтверждения email не отправляется: стандартное письмо Laravel ссылается на ещё не существующий маршрут. Подтверждение кодом — [KB-6](https://github.com/Intiligent/kadrobit/issues/6).
- Вход через Google — [KB-7](https://github.com/Intiligent/kadrobit/issues/7).

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
