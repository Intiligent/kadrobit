# Shutterstock

Доставка по FTPS — [`docs/publishing.md`](../../publishing.md), раздел «Shutterstock» ([KB-31](https://github.com/Intiligent/kadrobit/issues/31)).
Категории, отправка на проверку и статусы — адаптер расширения Chrome
([KB-84](https://github.com/Intiligent/kadrobit/issues/84), `extension/src/adapters/shutterstock.js`, как устроено
расширение — [`docs/browser-extension.md`](../../browser-extension.md)).

## Как работает адаптер

Расширение запускает его на открытом `submit.shutterstock.com`: при открытии страницы, раз в 5 минут, пока вкладка
открыта и видна (новые файлы появляются в «Не отправлено» через несколько минут после загрузки), и по «Check now».

1. **Не отправлено.** Файлы Kadrobit ищутся по имени (`originalFilename` = `asset_listings.remote_filename`). Дата
   загрузки на Shutterstock должна быть не раньше дня отправки из Kadrobit (с запасом в сутки): у авторов бывают ручные
   загрузки с теми же именами.
   - Категории работы в Kadrobit переводятся в `catId` по справочнику сайта: по названию, а если оно другое — по коду
     (`Arts` у нас, `The Arts`, код `arts` на сайте).
   - Если категории на сайте другие или работа изменена после отправки (`outdated`) — `bulk/update` с описанием,
     ключевыми словами и категориями. Затем в Kadrobit уходит `metadata_hash`: публикация перестаёт быть «изменено
     после отправки».
   - Если у аккаунта включена автоотправка — `submit`, работа сразу получает статус `pending`.
   - Работа без категорий в Kadrobit или с категорией, которой нет на сайте, не меняется и не отправляется: ошибка
     в попапе.
2. **На рассмотрении** и **Недавно проверенные** (последние 21 день): статус проверки, ID работы и, у принятых, ссылка.

Фото и видео обходятся одинаково (`mediaType=photo`, `video`); векторы и иллюстрации у Shutterstock — тоже `photo`.

## Внутренний API (01.11.2026)

Авторизация — cookies сессии автора, без токена и CSRF. Вход закрыт DataDome, поэтому с сервера Kadrobit API
недоступен. Без входа запросы перенаправляют на `contributor-accounts.shutterstock.com`.

| Запрос | Что отдаёт или делает |
|---|---|
| `GET /api/next/users/current/account_settings` | Настройки аккаунта: адаптер проверяет им, что автор вошёл |
| `GET /api/next/media/list?mediaType=photo&workflowStatus=ingested&page=1&perPage=100&normalize=true` | «Не отправлено»: `{data, totalCount}`, у работы `id` (`U4224158505`), `originalFilename`, `created`, `categories` (`["12","13"]`), `keywords`, `description`, `releases`, `isAdult`. **Нет** `mediaId` и `uploadedDate` |
| `GET /api/next/media/review/list?mediaType=photo&status=pending&…` | «На рассмотрении»: `id` (`P4219104705`), `mediaId` (то же число, что в `id`), `originalFilename`, `uploadedDate`, `status = pending` |
| `GET /api/next/media/review/list?mediaType=photo&status=reviewed&…` | «Недавно проверенные»: `status` `approved` или `rejected`, причины — `hadronStatusInfo.reviewRejectionReasons` (`visible_trademark`). Есть и `status=approved`, `status=rejected` |
| `GET /api/next/media/categories?media_type=photo` | Справочник: `[{catId, name, code}]`, 26 категорий |
| `POST /api/next/media/bulk/update` | `{ids: ["U…"], updateBody: [{id, description, keywords, categories, releases, is_adult, submitter_note}]}` |
| `POST /api/next/media/submit` | `{media: [{mediaType, mediaId: "U…"}], skipSpellcheck, preview: "false"}`; с `preview: "true"` — только проверка, её делает сама страница «Не отправлено» |

- Число в `id` — номер работы на всех этапах; он и хранится в `asset_listings.external_id`.
- Страница принятой работы: `https://www.shutterstock.com/image-photo/-<номер>` (сайт перенаправляет на полный адрес).
- Формы запросов `bulk/update` и `submit` сверены с кодом сайта (`_app`, вызовы `bulkUpdateAssets` и отправки).

## Проверено на аккаунте

01.11.2026: работа из Kadrobit в «Не отправлено» получила категории Nature и People и ушла на проверку; у 21 из 22
отправленных работ в Kadrobit записаны ID, статусы (7 приняты, 14 на рассмотрении) и ссылки. `IMG_5423.jpg` не
найдена: на Shutterstock есть только ручная загрузка с тем же именем, сделанная раньше, копия из Kadrobit в списках
не появилась (вероятно, отклонена как дубль при обработке).
