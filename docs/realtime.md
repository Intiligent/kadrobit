# Уведомления в реальном времени

Задача: [KB-44](https://github.com/Intiligent/kadrobit/issues/44).

Сервер сообщает браузеру, что у пользователя что-то изменилось, а браузер сам запрашивает данные через API. Транспорт — Pusher Channels через Laravel Broadcasting.

## Устройство

- **Канал** — приватный канал пользователя `private-users.{ulid}` (`routes/channels.php`: только свой ulid). Авторизация — `POST /broadcasting/auth` с web-сессией (`bootstrap/app.php`, `withBroadcasting`, middleware `web` + `auth`). `ulid` пользователя приходит во фронт в `auth.user.ulid`.
- **Событие** — одно на всё: `App\Events\EntityChanged` (`ShouldBroadcastNow`, имя `entity.changed`) с телом `{"type": "asset", "id": "<ulid>", "reason": "metadata"}`. Данных в событии нет: оно около 100 байт при любом содержимом (лимит Pusher — 10 КБ на событие), а данные пользователей не проходят через сторонний сервис.
- **Отправка** — `App\Support\Realtime::changed($user, $type, $id, $reason)`. Ошибка Pusher вызывающего не роняет — только предупреждение в лог: задача, которая сделала свою работу, не должна повторяться из-за уведомления. Тайм-ауты HTTP к Pusher — 5 с (`config/broadcasting.php`).
- **Клиент** — `resources/js/core/realtime.js`: `laravel-echo` + `pusher-js`, подключение один раз на сессию из `AppLayout`. Авторизация канала идёт через `core/api.js` (cookie сессии, XSRF, обновление CSRF).
  - `onEntityChanged(type, handler)` — обработчик уведомлений одного типа; возвращает функцию отписки.
  - `onReconnect(handler)` — после восстановления соединения: уведомления за время обрыва могли пропасть, ожидающие данные надо перезапросить.
  - `realtime.connected` — есть ли соединение.
- **Без Pusher** (нет `VITE_PUSHER_APP_KEY`, нет сети) ничего не подключается, `realtime.connected` остаётся `false`, и страницы опрашивают то, чего ждут, раз в `REALTIME_FALLBACK_POLL_INTERVAL` (5 с). Приложение работает так же, только медленнее.

## Применения

| `type` | `reason` | Кто отправляет | Что делает клиент |
|---|---|---|---|
| `asset` | `metadata` | `AssetMetadataService::finish()` — фоновая генерация закончилась (успешно, нечего записать, лимит, окончательная ошибка) | `usePendingAssets` в библиотеке запрашивает работу (`GET /api/assets?ids[]=…`) и сливает её с локальной без потери несохранённых правок |

### Генерация метаданных

`AssetMetadataService::queue()` ставит `assets.metadata_generating_at` и задачу в очередь; в `AssetResource` это `metadata_pending`. Сетка и панель показывают «Generating metadata…». Когда задача закончилась, `finish()` снимает отметку и отправляет уведомление. Отметка старше 20 минут (`Asset::GENERATING_STALE_MINUTES`) считается потерянной: обработчик убит посреди задачи — библиотека перестаёт ждать.

## Как добавить новый тип

1. На сервере, когда сущность изменилась: `Realtime::changed($user, 'listing', $listing->ulid, 'status')`.
2. Эндпоинт, который отдаёт свежую версию сущности по идентификаторам (по образцу `GET /api/assets`).
3. На странице: `onEntityChanged('listing', ({ id }) => …)` — запросить и слить; если страница ждёт чего-то определённого — перезапросить это в `onReconnect()` и опрашивать, пока `!realtime.connected` (по образцу `composables/usePendingAssets.js`).

## Настройки

| Переменная | Что |
|---|---|
| `BROADCAST_CONNECTION` | `pusher` — включить; `log` или `null` — события только в лог / никуда (локально без ключей) |
| `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` | Приложение Channels на pusher.com, вкладка App Keys |
| `VITE_PUSHER_APP_KEY`, `VITE_PUSHER_APP_CLUSTER` | Берутся из `PUSHER_APP_KEY` и `PUSHER_APP_CLUSTER`; в браузер попадают только публичный ключ и кластер. Подхватываются сборкой: после изменения — перезапуск Vite или пересборка |

Тариф Sandbox: 200 тыс. сообщений в день, 100 одновременных подключений (каждая открытая вкладка — одно подключение).
