# Фронтенд

Задача: [KB-3](https://github.com/Intiligent/kadrobit/issues/3).

## Стек

| Что | Пакет |
|---|---|
| SPA без отдельного API для страниц | Inertia 3 (`inertiajs/inertia-laravel`, `@inertiajs/vue3`, `@inertiajs/vite`) |
| UI | Vue 3, Element Plus (компоненты и стили подключаются автоматически через `unplugin-vue-components`), Tailwind 4 |
| Переводы | `laravel-vue-i18n`: фронт читает те же `lang/{locale}/*.php`, что и бэкенд |
| HTTP-запросы вне Inertia | `resources/js/core/api.js` (axios) |
| Тесты JS | Vitest, `npm test` |

SSR выключен (`ssr: false` в `vite.config.js`, `INERTIA_SSR_ENABLED=false` по умолчанию).

## Стили

```
resources/style/tailwind.css            Tailwind 4 и его токены (@theme)
resources/style/app.scss                стили Element Plus для вызовов из JS (уведомления, диалоги, сообщения)
resources/style/theme/variables.scss    переменные темы Element Plus (цвета, шрифт, радиусы)
resources/style/theme/element.scss      CSS-переменные Element Plus и переопределения стилей компонентов
```

- Компоненты Element Plus подключаются автоматически вместе со своими **SCSS-исходниками** (`ElementPlusResolver({ importStyle: 'sass' })`), а не с готовым CSS. Поэтому они собираются с нашими значениями переменных.
- `theme/variables.scss` через `css.preprocessorOptions.scss.additionalData` подмешивается в каждый SCSS-файл, включая исходники Element Plus. Файл только настраивает переменные (`@forward … with (…)`) и сам CSS не выводит, поэтому в бандле ничего не дублируется. Список переменных — `node_modules/element-plus/theme-chalk/src/common/var.scss`.
- **Высота элементов формы** задаётся в теме, а не в компонентах: `$common-component-size` (сейчас `default` — 34px) даёт высоту `el-input` (через `--el-component-size`), `el-select` и `el-button`. `el-radio-button` считает высоту из отступа `$button-padding-vertical`: 14px текста + 2 × (отступ − 1px). Эти два значения меняются вместе.
- После правки `variables.scss` перезапустите Vite (`overmind restart vite`): файл подмешивается через `additionalData`, и HMR его изменения не подхватывает.
- `theme/element.scss` подключается один раз из `app.scss`: выводит CSS-переменные `--el-*` (`:root`, тёмная тема) и содержит переопределения классов компонентов.
- Стили `ElNotification`, `ElMessageBox`, `ElMessage`, `ElLoading` подключены в `app.scss`: автоимпорт добавляет стили только для компонентов из шаблонов, а эти вызываются из JS.
- Готовые CSS из Element Plus (`element-plus/dist/index.css`, `…/style/css`) не подключаем: в них стандартные значения, и переопределить переменные нельзя.

## Структура `resources/js`

```
app.js                    точка входа: createInertiaApp, i18n, восстановление после деплоя
config/constant.js        таймауты, URL, коды ошибок (ERROR_CODE)
core/api.js               API-клиент
core/ApiError.js          нормализованная ошибка
core/notify.js            адаптер уведомлений и диалогов (Element Plus)
composables/useLoading.js состояние загрузки по запросам
layouts/DefaultLayout.vue layout по умолчанию
pages/<раздел>/index.vue  страницы: Inertia::render('home/index') → pages/home/index.vue
```

## Страницы и общие props

- Страница рендерится через `Inertia::render('<путь>/index')`, layout по умолчанию — `DefaultLayout`.
- `HandleInertiaRequests` передаёт во все страницы:
  - `appName`, `locale`;
  - `auth.user` — `UserResource` или `null`;
- Одноразовые сообщения — через `Inertia::flash('success' | 'error', '…')`, например `return Inertia::flash('success', __('…'))->back();`. В `app.js` их показывает обработчик `router.on('flash')`. Inertia не сохраняет flash в истории браузера, поэтому при «Назад» сообщение не появится снова.

## API-клиент

```js
import api, { newRequestId } from '@/core/api';
import { useLoading } from '@/composables/useLoading';

const users = await api.get('/api/users', { page: 2 });

const loading = useLoading();
const requestId = newRequestId();
await api.post('/api/assets', data, { loadingState: loading, requestId, notify: true });
loading.is(requestId); // идёт ли этот запрос
loading.any.value;     // идёт ли хоть один запрос с этим состоянием
```

Методы `get`, `post`, `put`, `patch`, `delete`, `request` возвращают тело ответа и при ошибке выбрасывают `ApiError`.

### Опции запроса

| Опция | Что делает |
|---|---|
| `requestId` | Свой id запроса. Если не задан, генерируется UUID. |
| `loadingState` | Объект из `useLoading()` или `reactive({})`. Пока запрос идёт, в нём лежит запись `requestId → { method, url, startedAt }`. |
| `notify: true` | Показать уведомление об успехе с `message` из ответа. |
| `silent: true` / `silent: [422]` | Не показывать глобальное уведомление об ошибке: совсем или только для указанных статусов и кодов. |
| `retry: n` | Сколько раз повторить при сбое сети или 502/503/504. По умолчанию 2 для GET/HEAD/OPTIONS и 0 для остальных. |

Все запросы приложения видны в `pendingRequests` (например, для общего индикатора загрузки).

### Идемпотентность

- Для POST/PUT/PATCH/DELETE `requestId` уходит в заголовке `Idempotency-Key`.
- Повторы (после 419, при `retry`) идут с тем же ключом.
- **Один `requestId` — одна отправка.** Повторно использованный id вернёт сохранённый ответ прошлой отправки, поэтому `api.*` отклоняет запрос, если запрос с таким id ещё выполняется.
- На бэкенде `EnsureIdempotency` (группы `web` и `api`, настройки в `config/idempotency.php`):
  - ключ должен быть UUID, иначе 400;
  - ключ действует в рамках пользователя (или сессии, или IP для гостя), метода и пути;
  - успешный ответ (2xx/3xx) до `IDEMPOTENCY_MAX_BODY_SIZE` (64 КБ) хранится `IDEMPOTENCY_TTL` (1 час); повтор с тем же ключом возвращает его с заголовком `Idempotent-Replayed: true` и не выполняет действие второй раз;
  - больший ответ не хранится: пока запрос выполняется, дубль получает 409, но повтор после завершения выполнится заново;
  - ответы с ошибкой не хранятся: запрос можно повторить;
  - тот же ключ с другим телом запроса — 422, параллельный дубль, пока первый не завершился, — 409.

#### Нагрузка

На ключ — одна запись в кеше: атомарный `add()` занимает ключ (она же метка «выполняется»), после ответа `put()` сохраняет ответ или `forget()` освобождает ключ. Отдельной блокировки нет.

| Хранилище | Успешный запрос | Повтор |
|---|---|---|
| `database` | 3 запроса к БД (select + insert, upsert или delete) | 2 select |
| `redis` | 2 команды Redis, без обращения к БД | 2 команды |

На проде задайте `IDEMPOTENCY_STORE=redis` — проверка не будет нагружать базу.

### Ошибки

`ApiError`: `status`, `code`, `message`, `errors` (ошибки полей), `fieldError(field)`, `retryAfter`, `requestId`, `isCanceled`, `isTimeout`, `isNetworkError`, `data` (тело ответа).

Глобальная обработка (если запрос не `silent`):

| Случай | Что видит пользователь |
|---|---|
| Нет сети, тайм-аут | Уведомление (одно, сколько бы запросов ни упало) |
| 419, CSRF | Сначала cookie обновляется через `GET /csrf-token` (один запрос на все параллельные 419), запрос повторяется. Если снова 419 — диалог «обновить страницу» |
| 401 | Если задан `setTokenRefresher()` — токен перевыпускается и запрос повторяется. Иначе диалог «войти» с переходом на `/login?redirect=<текущая страница>` |
| 403, 404, 409, 429, 422, ошибка с сообщением | Уведомление; для 422 — список ошибок полей, для 429 — через сколько секунд повторить |
| 5xx | Уведомление; текст исключения в продакшене не показывается |

В dev-режиме, если бэкенд прислал `exception`, файл, строку и trace, они выводятся в консоль.

Одинаковые диалоги и уведомления с ключом (`key` в `notify`) не открываются повторно, пока первое не закрыто.

### Другие методы

- `api.upload(url, data, { onProgress, method })` — multipart без тайм-аута. Объект превращается в `FormData`: `meta[key]`, `tags[]`, массивы объектов — `items[0][id]`, файлы, `true/false` → `1/0`. PUT/PATCH отправляются как POST с `_method`: PHP разбирает multipart только для POST.
- `api.download(url, params, { save, filename, onProgress })` — скачивание; имя берётся из `Content-Disposition`, ошибки в JSON читаются даже при `responseType: 'blob'`.
- `api.cancellable()` — каждый вызов отменяет предыдущий (поиск, фильтры); отменённый запрос отклоняется с `isCanceled: true`, `.catch(api.ignoreCanceled)` его глушит.
- `api.raw` — сам экземпляр axios. `loadingState` работает только через методы `api`, не через `api.raw`.

## Формат ошибок JSON

`App\Exceptions\ExceptionHandler` отдаёт любую ошибку запроса к `api/*` или с `Accept: application/json` так:

```json
{ "message": "…", "status": 422, "code": 142201, "errors": { "title": ["…"] }, "retry_after": 30 }
```

- `errors` — только для валидации, `retry_after` — только для 429.
- При `APP_DEBUG=true` добавляются `exception`, `file`, `line`, `trace`.
- Для 5xx без debug `message` — общий текст из `lang/en/errors.php`, не текст исключения.
- `App\Exceptions\ExceptionWithMessage` — ошибка с сообщением для пользователя (по умолчанию 400, код `WITH_MESSAGE`).
- Inertia-переход с истёкшим CSRF (419) возвращает пользователя назад с flash-сообщением вместо модального окна с HTML.

| Код | Случай |
|---|---|
| 140001 | Прочие 4xx |
| 140101 | 419, CSRF |
| 140302 | 401 |
| 140303 | 403 |
| 140305 | `ExceptionWithMessage` |
| 140401 | 404 |
| 140901 | 409 |
| 142201 | 422, валидация |
| 142901 | 429 |
| 150001 | 5xx |

Коды заданы в `App\Enums\ErrorCode` и продублированы в `resources/js/config/constant.js`. Тест `test_frontend_error_codes_match_the_enum` падает, если они разошлись.

## Переводы

Задача: [KB-48](https://github.com/Intiligent/kadrobit/issues/48).

- Языки интерфейса — `App\Enums\Locale`: `en` (по умолчанию) и `ru`. Тексты — в `lang/{locale}/*.php`, на фронте `trans('errors.network')` или `$t('home.title')`.
- **Новая или изменённая строка добавляется сразу во все языки.** `tests/Unit/TranslationsTest.php` падает, если у языков разные файлы, ключи или плейсхолдеры (`:name`).
- При сборке и в `npm run dev` плагин `laravel-vue-i18n/vite` создаёт `lang/php_{locale}.json` (в `.gitignore`), `app.js` загружает файл активного языка.
- Язык запроса выбирает `App\Http\Middleware\SetLocale` (группы `web` и `api`), он же попадает в общий prop `locale`. Как пользователь меняет язык — в [settings.md](settings.md#язык-интерфейса).
- Встроенные тексты Element Plus (пустые списки, кнопки диалогов) переводятся через `provideGlobalConfig` в `app.js`: новый язык добавляется в `ELEMENT_LOCALES`.
- `lang/{locale}/validation.php` и `pagination.php` — сообщения фреймворка. Названия полей в ошибках (`:attribute`) — в `validation.attributes`, одинаковые ключи во всех языках.

### Числа, даты, единицы

- Даты, числа и списки форматируются через `Intl` с `getActiveLanguage()`: `utils/format.js` (`formatNumber`, `formatBytes`, `formatDateTime`), `utils/relativeTime.js`, `Intl.ListFormat`. В русском `3.1` — «3,1», `6000` — «6 000».
- Единицы измерения — в переводах, а не в коде: `library.file.megapixels_value` («:mp MP» / «:mp Мп»), `library.camera.focal` и т. п.
- Числа в параметрах сообщения форматируются перед подстановкой (`formatNumberParams`).

### Множественное число

- В английском — интервалы: `'{0} No works|{1} :count work|[2,*] :count works'`, маркер у каждого варианта.
- В русском — три формы без маркеров, выбор по числу (1, 21 — первая; 2–4, 22 — вторая; 5–20, 11 — третья): `':count работа|:count работы|:count работ'`. Интервалы вроде `[2,4]` не годятся: 22 в них не попадает.
- Точные числа можно добавить **после** трёх форм: `':count работа|:count работы|:count работ|{0} Нет работ'`. Форма выбирается по позиции среди всех вариантов, поэтому `{0}` в начале сдвинул бы формы.
- В PHP — `trans_choice('key', $n, ['count' => $n])`, на фронте — `transChoice()` / `$tChoice()`. Где форма не нужна, фразу строят так, чтобы число не склонялось: «Минимум ключевых слов: :min», «через :seconds с».

## Тесты

- `npm test` — Vitest (`tests/js`): API-клиент с подменённым транспортом axios и адаптер уведомлений.
- `php artisan test` — формат ошибок, идемпотентность, общие props Inertia.
