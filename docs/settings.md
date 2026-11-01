# Настройки и подключение маркетплейсов

Задачи: [KB-27](https://github.com/Intiligent/kadrobit/issues/27) (маркетплейсы), [KB-36](https://github.com/Intiligent/kadrobit/issues/36) (пользовательские настройки).

## Страницы

| URL | Что | Контроллер |
|---|---|---|
| `/settings` | Перенаправляет на первый готовый раздел (General) | `Settings\SettingsController@index` |
| `/settings/general` | General: язык интерфейса | `Settings\SettingsController@general` |
| `/settings/marketplaces` | Подключённые маркетплейсы и каталог | `Settings\SettingsController@marketplaces` |
| `/settings/metadata` | Metadata defaults: автозаполнение метаданных при загрузке | `Settings\SettingsController@metadata` |
| `/settings/extension` | Browser extension: скачать, установка, подключённые расширения, персональный токен ([browser-extension.md](browser-extension.md#settings--extension)) | `Settings\SettingsController@extension` |

Страница настроек использует вложенные layout'ы: `defineOptions({ layout: [AppLayout, SettingsLayout] })`. `SettingsLayout` — навигация по разделам (`components/settings/SettingsNav.vue`) и область контента. Разделы без страницы показаны неактивными с подсказкой «Coming soon».

Разделы:

- **Account:** Profile, General, Security, Notifications, Data & privacy;
- **Workspace:** Marketplaces, Metadata defaults, Browser extension, Publishing;
- **Billing:** Plan & billing.

## Компоненты

| Файл | Что делает |
|---|---|
| `pages/settings/general/index.vue` | Выбор языка; после сохранения страница перезагружается с новыми переводами |
| `pages/settings/marketplaces/index.vue` | Страница: список, каталог, проверка, вкл/выкл, удаление |
| `pages/settings/metadata/index.vue` | Переключатель «Fill in metadata on upload», сохраняется сразу |
| `components/settings/CredentialsNotice.vue` | Плашка о хранении паролей с раскрывающимися подробностями |
| `components/settings/MarketplaceConnectionList.vue` | Подключённые аккаунты: статус, «Check now», переключатель, «Edit», удаление |
| `components/settings/MarketplaceCatalog.vue` | Маркетплейсы, которые можно подключить |
| `components/settings/MarketplaceConnectDrawer.vue` | Панель подключения и редактирования: выбор маркетплейса → форма |
| `components/settings/MarketplaceLogo.vue` | Логотип или инициалы маркетплейса |

## Пользовательские настройки

Настройки пользователя (не маркетплейсов) — по строке на ключ в `user_settings` (`user_id`, `key`, `value` — jsonb). Строка есть только у настройки, изменённой относительно значения по умолчанию: `null` или значение по умолчанию строку удаляет, поэтому новое значение по умолчанию доходит до всех, кто настройку не трогал.

- **Реестр** — `App\Enums\UserSettingKey`: ключ, значение по умолчанию (`default()`), правила (`rules()`), приведение к типу настройки (`cast()`), можно ли менять на странице настроек (`editable()`). Значения по умолчанию задаются только здесь.
- **Чтение и запись** — трейт `App\Models\Concerns\HasSettings` у `User`: `$user->setting(UserSettingKey::MetadataAutofillOnUpload)`, `$user->setSetting($key, $value)` (приводит значение к типу настройки через `cast()`: `"no"` для переключателя — `false`), `$user->settingValues()` — все изменяемые настройки со значениями по умолчанию. Чтение идёт из связи `settings`, она загружается один раз: все чтения за запрос — один SQL-запрос.
- **Ключи с точкой** (`metadata.autofill_on_upload`) — в API и на фронте вложенные объекты: `{"metadata": {"autofill_on_upload": true}}`.
- **Фронт** — общий Inertia prop `settings` (у гостя `null`). После изменения страница подменяет его ответом сервера: `router.replaceProp('settings', response.data)`.

| Метод | URL | Что делает |
|---|---|---|
| `GET` | `/api/settings` | Все изменяемые настройки со значениями по умолчанию |
| `PATCH` | `/api/settings` | Частичное изменение (`UpdateUserSettingsRequest`): только присланные ключи; `null` — сброс к значению по умолчанию; неизвестный или неизменяемый ключ в теле — 422, как и ключ с точкой вместо вложенности (`{"metadata.autofill_on_upload": …}`); строка запроса не проверяется, пустой объект ничего не меняет. Ответ — все настройки |

Маршруты в группе `auth:api`, подтверждённый email не нужен.

Новая настройка — один `case` в `UserSettingKey` с ветками в `default()`, `rules()` и `cast()`; в prop, API и валидацию она попадает сама.

| Ключ | По умолчанию | Что делает |
|---|---|---|
| `general.locale` | `en` | Язык интерфейса и писем: `en`, `ru` ([KB-48](https://github.com/Intiligent/kadrobit/issues/48)) |
| `metadata.autofill_on_upload` | `true` | Генерировать метаданные работы после обработки загрузки ([KB-38](https://github.com/Intiligent/kadrobit/issues/38)) |

## Язык интерфейса

Задача: [KB-48](https://github.com/Intiligent/kadrobit/issues/48). Как добавлять переводы — в [frontend.md](frontend.md#переводы).

- **Выбор.** Settings → General (список языков приходит prop `locales` из `App\Enums\Locale`), `PATCH /api/settings` с `{"general": {"locale": "ru"}}`, затем полная перезагрузка страницы: переводы загружаются один раз на страницу. Допустимые значения — `App\Enums\Locale`, другие — 422.
- **Определение языка** (`App\Http\Middleware\SetLocale`, группы `web` и `api`):
  - пользователь — его настройка `general.locale`. Она читается из связи `settings`, которую общий prop `settings` загружает всё равно, поэтому переход по страницам лишнего запроса не делает. Остальные запросы, в том числе API, делают один запрос к `user_settings`;
  - гость — язык из сессии, иначе `en`.
- **Порядок.** В `web` язык выбирается сразу после `StartSession` (список приоритетов в `MiddlewareHandler`), поэтому ошибки следующих middleware — устаревший CSRF-токен, лимит запросов — уже на языке пользователя. В `api` сессии нет: `SetLocale:api` берёт пользователя из guard `api` до `auth:api`.
- **Несуществующая страница.** `Route::fallback` (`FallbackController`) пропускает её через группу `web`, чтобы 404 был на языке пользователя; без него 404 рендерится до middleware, без сессии. Адрес, который есть с другим методом (`GET /logout`), по-прежнему получает 405. Неизвестные адреса `api/*` в fallback не попадают. Заголовки страниц ошибок фреймворка (`__('Not Found')`) — в `lang/{locale}.json`.
- **Регистрация.** Новый аккаунт (форма и Google) получает язык страницы, с которой регистрировались: настройка записывается до события `Registered`, поэтому письмо с кодом уже на этом языке. Английский строки не создаёт.
- **Сессия.** Middleware записывает в сессию язык пользователя, а выход (`LoginController@destroy`) переносит его в новую сессию: вход и сброс пароля после выхода остаются на языке пользователя. Для вошедшего пользователя сессия не используется, поэтому смена языка через API и на другом устройстве действует со следующего запроса.
- **Письма.** `User` реализует `HasLocalePreference`: уведомления из очереди уходят на языке пользователя, каким бы ни был язык запроса (например, сброс пароля с английской страницы входа). Предпросмотр: `/dev/emails/<письмо>?locale=ru`.
- **Генерация метаданных** остаётся на английском: этого требуют маркетплейсы, язык интерфейса на неё не влияет.

## API маркетплейсов

Все маршруты — в группе `auth:api` + `verified`. Каждый вход на сервер маркетплейса — при добавлении, проверке и изменении сервера, логина или пароля — идёт в общий лимит: 10 в минуту на пользователя (`MarketplaceAccountController::test()`, ответ 429 с `Retry-After`). Лимит считается в контроллере, а не в middleware маршрута: изменение входит на сервер, только когда меняет данные для входа. Переименование и вкл/выкл в лимит не входят.

| Метод | URL | Что делает |
|---|---|---|
| `POST` | `/api/marketplace-accounts` | Входит на сервер маркетплейса и сохраняет аккаунт при любом исходе (201). Неудачный вход: статус `invalid_credentials`, причина в `last_error`, текст ошибки в `message`; аккаунт не участвует в публикации, пока «Check now» или новый пароль не дадут успешный вход |
| `PATCH` | `/api/marketplace-accounts/{id}` | Частичное изменение. Смена `host`, `login` или `password` — сначала вход, потом сохранение. `label`, `contributor_id`, `status` (`active`/`disabled`) — без проверки. Включение аккаунта в `invalid_credentials` или с `last_error = auth` оставляет его в `invalid_credentials` |
| `POST` | `/api/marketplace-accounts/{id}/check` | Вход с сохранёнными данными. Неверный пароль → `invalid_credentials` (выключенный аккаунт остаётся выключенным, записывается только `last_error`); недоступный сервер → только `last_error = connect`. Причина `auth` сохраняется до первого успешного входа: недоступный сервер её не затирает |
| `DELETE` | `/api/marketplace-accounts/{id}` | Удаляет аккаунт вместе с паролем (без мягкого удаления) |

Чужой аккаунт — 404.

**Логин из номера контрибьютора.** У маркетплейсов с `upload_defaults.login_is_contributor_id` (Adobe Stock: логин SFTP — номер контрибьютора) отдельного поля логина нет. `contributor_id` обязателен и служит логином: при добавлении `login` игнорируется, в изменении запрещён, а смена `contributor_id` заново входит на сервер и обновляет `login`.

## Хранение паролей

- **Отдельный ключ.** `credentials` шифруется ключом `CREDENTIALS_KEY` (AES-256-GCM, `App\Casts\EncryptedCredentials`), не `APP_KEY`. Ключ создаётся командой `php artisan credentials:key` и хранится в секретах окружения, а не в репозитории.
- **Ротация.** Старый ключ — в `CREDENTIALS_PREVIOUS_KEYS` (через запятую), новый — в `CREDENTIALS_KEY`, затем `php artisan credentials:rotate`. После этого старый ключ можно убрать.
- **Только секреты в шифре.** В `credentials` лежит только пароль (позже — ключ SFTP). Сервер, порт и логин — обычные колонки, поэтому страница настроек ничего не расшифровывает. Пароль расшифровывается только для входа на сервер маркетплейса: при проверке и при загрузке.
- **Ответы API.** Пароль не возвращается никогда; `has_password` показывает, сохранён ли он.
- **Логи.** Параметры с паролем помечены `#[\SensitiveParameter]`. При ошибке входа в лог пишется только класс исключения и причина (`auth`, `connect`, `other`), без текста ответа сервера.
- **Отключение при сбое.** Неверный пароль переводит аккаунт в `invalid_credentials`; автоматических повторов нет, чтобы маркетплейс не заблокировал аккаунт.

## Проверка соединения

`App\Services\Marketplaces\ConnectionTester` подключается к серверу и входит с логином и паролем. Тайм-аут — `CREDENTIALS_CONNECT_TIMEOUT` (10 секунд).

- **Адрес.** Имя сервера резолвится (`App\Support\HostResolver`, IPv4 и IPv6), и подключение идёт к полученному адресу. Все адреса имени должны быть публичными, иначе сервер не трогаем (`connect`, в лог — предупреждение). Проверка повторяется при каждом входе, поэтому имя, которое позже перенаправили на внутренний адрес, тоже не пройдёт. Форма проверяет то же самое заранее (`App\Rules\PublicHostRule`), чтобы показать ошибку у поля.
- **FTP и FTPS** — напрямую расширение PHP `ftp` (`ext-ftp` в `composer.json`): подключение, вход, пассивный режим, адрес из ответа `PASV` игнорируется. В FTPS TLS-рукопожатие происходит внутри `ftp_login()`, поэтому неудачный вход разбирается по предупреждению PHP: `SSL/TLS handshake failed` или `Server doesn't support FTPS.` — это `other`, а не неверный пароль.
- **SFTP** — напрямую `phpseclib4\Net\SFTP`. `league/flysystem-sftp-v3` требует phpseclib 3, а Passport и Socialite — phpseclib 4, поэтому адаптер Flysystem поставить нельзя. Требование сервера сменить истёкший пароль — `auth`. Ключ сервера пока не проверяется.
- **Причина ошибки** (`ConnectionResult`): `auth` — сервер отклонил логин или пароль, `connect` — сервер недоступен (отказ, тайм-аут, обрыв, адрес не публичный), `other` — всё остальное. В списке активный аккаунт с `connect` показан как «Server unreachable», с `other` — «Check failed».

## Что дальше

- Повторное подтверждение (пароль Kadrobit или второй фактор) перед добавлением и изменением данных.
- Журнал действий с данными для входа и письмо при каждом изменении.
- Ключи во внешнем сервисе (KMS / Vault Transit).
- Вход по SSH-ключу для SFTP и проверка ключа сервера (запоминать отпечаток при подключении).
- SFTP-диск для сервиса загрузки: адаптер Flysystem под phpseclib 4 или свой.
- Остальные разделы настроек.
