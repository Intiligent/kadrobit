# Настройки и подключение маркетплейсов

Задача: [KB-27](https://github.com/Intiligent/kadrobit/issues/27).

## Страницы

| URL | Что | Контроллер |
|---|---|---|
| `/settings` | Перенаправляет на первый готовый раздел | `Settings\SettingsController@index` |
| `/settings/marketplaces` | Подключённые маркетплейсы и каталог | `Settings\SettingsController@marketplaces` |

Страница настроек использует вложенные layout'ы: `defineOptions({ layout: [AppLayout, SettingsLayout] })`. `SettingsLayout` — навигация по разделам (`components/settings/SettingsNav.vue`) и область контента. Разделы без страницы показаны неактивными с подсказкой «Coming soon».

Разделы:

- **Account:** Profile, Security, Notifications, Data & privacy;
- **Workspace:** Marketplaces, Metadata defaults, Publishing;
- **Billing:** Plan & billing.

## Компоненты

| Файл | Что делает |
|---|---|
| `pages/settings/marketplaces/index.vue` | Страница: список, каталог, проверка, вкл/выкл, удаление |
| `components/settings/CredentialsNotice.vue` | Плашка о хранении паролей с раскрывающимися подробностями |
| `components/settings/MarketplaceConnectionList.vue` | Подключённые аккаунты: статус, «Check now», переключатель, «Edit», удаление |
| `components/settings/MarketplaceCatalog.vue` | Маркетплейсы, которые можно подключить |
| `components/settings/MarketplaceConnectDrawer.vue` | Панель подключения и редактирования: выбор маркетплейса → форма |
| `components/settings/MarketplaceLogo.vue` | Логотип или инициалы маркетплейса |

## API

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
