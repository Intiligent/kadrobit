# 123RF: загрузка по FTP

Источники (31.10.2026): [How To Upload Photos Or Illustrations](https://www.blog.123rf.com/how-to-upload-photos-or-illustrations),
[гайд по описаниям и ключевым словам](https://www.blog.123rf.com/complete-guide-to-describing-keywording-photos-vectors),
[FAQ для авторов](https://www.123rf.com/submit/photographersfaq.php).

## Подключение

- Сервер `ftp.123rf.com`, порт 21. Логин — имя пользователя 123RF (не email), пароль — от аккаунта.
- В справке — обычный FTP, но сервер (Pure-FTPd) на `AUTH TLS` отвечает `234 AUTH TLS OK` (проверено 31.10.2026 без
  входа), поэтому в Kadrobit `upload_protocol = ftps`. Если вход или загрузка по TLS не пройдут на настоящем аккаунте —
  вернуть `ftp` в `MarketplaceSeeder`.
- В папке FTP одновременно до 4 ГБ. Видео и аудио — на отдельных серверах, Kadrobit их пока не отправляет
  (`supported_types`: фото, иллюстрации, векторы).

## Папки

Файлы кладутся в корень: отдельных папок в справке нет. Проверить на первом входе.

## Требования к файлам

- **Фото и иллюстрации:** JPEG от 6 Мп, до 30 МБ, качество сжатия от 80 %. Редакционные — те же требования, без
  монтажа и клонирования.
- **Вектор:** EPS (совместимый с Illustrator 8, без flatten) и JPG с тем же именем (`illustration001.eps`,
  `illustration001.jpg`), JPG от 1600 × 1600, качество от 80 %.
- На изображении не должно быть брендов, товарных знаков и дат; в описании и ключевых словах — брендов и марки камеры.
- Люди — с model release, частные места — с property release (Manage Releases в кабинете).

## Метаданные

- 123RF читает IPTC: подпись (description) и ключевые слова. Если в файле их нет, поля остаются пустыми.
- Одно текстовое поле — Description; лимит длины официально не указан. Kadrobit пишет описание работы и в поля
  заголовка IPTC/XMP (`metadata_rules.text_fields`), так что какое бы поле 123RF ни прочитал, текст один.
- Ключевых слов — от 7, рекомендуют 30–50, максимума нет (`keywords_min = 7`).
- CSV (до 2 МБ, колонки `oldfilename`, `123rf_filename`, `description`, `keywords`, `country`) загружается только
  через сайт; Kadrobit его не использует — хватает IPTC.

## Категории

Нет: ни в справке, ни в гайдах, ни в CSV выбора категории нет (проверено 31.10.2026), `MarketplaceCategorySeeder`
для 123RF пуст. Если в форме Draft в кабинете он найдётся — добавить список
([KB-76](https://github.com/Intiligent/kadrobit/issues/76)).

## После загрузки

1. В кабинете: Contributor Dashboard → Upload Content → Photos → Commercial → **Upload Via FTP**.
2. Страница показывает файлы с FTP: принятые и не принятые с причиной. Отметить согласие и нажать **Proceed**.
3. Файлы попадают в Manage Content → Draft с описанием и ключевыми словами из IPTC; оттуда их отправляют на проверку.
4. Дальше вкладки Pending (на проверке), Rejected, Approved.

Без шагов 1–2 файлы остаются на FTP и в кабинете не видны. Проверено 31.10.2026: `IMG_0930u.jpg` из Kadrobit после
Proceed и отправки попал в Pending с описанием и всеми ключевыми словами из IPTC.

### Адаптер расширения

`extension/src/adapters/rf123.js` ([KB-80](https://github.com/Intiligent/kadrobit/issues/80), как устроено расширение —
[`docs/browser-extension.md`](../../browser-extension.md)). Работает на открытом `www.123rf.com`: при открытии страницы,
раз в 5 минут, пока вкладка открыта и видна, раз в минуту и в фоне, пока работы ждут отправки на проверку, и по
«Check now».

1. **Upload Via FTP.** Если на FTP лежит файл Kadrobit аккаунта с автоотправкой — Proceed (`prequeue`). Proceed
   забирает все файлы с FTP, как кнопка на сайте, в том числе загруженные автором вручную; если там только чужие
   файлы, адаптер его не нажимает. Затем ждёт до 2 минут, пока эти файлы не появятся в черновиках. Список обработки
   для этого не годится: 123RF сначала ставит файлы в очередь, и в первые секунды он пуст ([KB-92](https://github.com/Intiligent/kadrobit/issues/92)).
2. **Черновики.** Работы аккаунтов с автоотправкой уходят на проверку (`to_pending`). Черновик без описания или
   меньше чем с 7 ключевыми словами не отправляется (так же отказывает сам сайт) — ошибка в попапе. Работа, изменённая
   в Kadrobit после отправки, уходит с описанием из файла: черновик адаптер не переписывает.
3. **Проверка.** Статус (`pending`, `accepted`, `rejected`) и ID 123RF (`filename`, `vitaliyartyukh261100004`) — в
   Kadrobit. Ссылка на принятую работу пока не пишется: формат её адреса выяснить на первой принятой работе.

Работа находится по имени файла без расширения: 123RF хранит его в `oldFilename` (`IMG_6679`), а у вектора EPS и
JPEG — одно имя. Дата загрузки (`createdAt`, время сервера 123RF, UTC−5) должна быть не раньше дня отправки из
Kadrobit с запасом в сутки: у автора бывают ручные загрузки с теми же именами.

### Внутренний API кабинета (31.10.2026)

Кабинет `123rf.com/contributor` работает через `/apicore-contributors/…`, авторизация — cookies сессии автора.

| Запрос | Что делает |
|---|---|
| `GET /apicore-contributors/ftp?license_type=commercial&media_type=image` | Файлы, лежащие на FTP: `{status, data: [{filename, filenameWithoutExt, extension, status}]}`; векторы тоже здесь, `media_type=vector` отвечает 500 |
| `POST /apicore-contributors/process_images/prequeue?upload_method=ftp&allow_conversion=0\|1&license_type=commercial&media_type=image` | Кнопка Proceed со страницы Upload Via FTP: забрать файлы с FTP в обработку (тело для FTP пустое, для веб-загрузки — `{data: {filenames}, media_type}`) |
| `GET /apicore-contributors/process_images?page=1&limit=20&filetype=all_image` | Файлы в обработке; пустой список — `204 No Content` |
| `GET /apicore-contributors/draft_items?page=1&limit=20&content_type=photo` | Черновики: `id`, `oldFilename`, `createdAt`, `kwDesc`; `content_type=vector` — векторы |
| `POST /apicore-contributors/draft_items/to_pending` | Отправка черновиков на проверку: `{data: {type: "draft_item", attributes: {draft_ids: [...]}}}` |
| `GET /apicore-contributors/review_images?page=1&limit=20&status=pending&content_type=photo` | Работы на проверке (`status`: `pending`, `accepted`, `rejected`; неизвестное значение сайт читает как `pending`): `filename` (ID на 123RF, `vitaliyartyukh261000011`), `oldFilename` (исходное имя без расширения), `oldFilenameProcess` (с расширением), `createdAt` (только день), `kwDesc.description`, `kwDesc.keywords`, `approval`. С `type=image_rf`, который добавляют некоторые страницы кабинета, список неполный (1 работа из 11, 01.11.2026) |
| `GET /apicore-contributors/manage_content/images?filetype=all_image` | Счётчики вкладок: `draft`, `pending`, `accepted`, `rejected` |
| `POST /apicore-contributors/csv_process/{…}` | Загрузка CSV (`csv_file` в form-data) |

С сервера Kadrobit этот API недоступен: вход на 123rf.com закрыт reCAPTCHA, а входить паролем пользователя и обходить
капчу мы не делаем. Вызывать его можно только в браузере пользователя, в его сессии — из расширения Chrome
([KB-54](https://github.com/Intiligent/kadrobit/issues/54), адаптер 123RF — [KB-80](https://github.com/Intiligent/kadrobit/issues/80)).
Согласие в Proceed — это принятие соглашения 123RF от имени автора: расширение нажимает его само, только если автор
включил автоматическую отправку для 123RF, и подсказка к настройке об этом говорит.

## В Kadrobit

`Rf123Delivery` (`docs/publishing.md`, раздел «123RF»):

- фото и иллюстрации — JPEG в корень с описанием и ключевыми словами внутри; PNG, TIFF и CMYK уходят копией в
  sRGB JPEG (`MakesJpegCopies`, качество 95);
- вектор — EPS и его JPEG (`raster_companion`) с одним именем в корень;
- SVG и AI не уходят (`file_rules.vector.formats_strict`), фото и иллюстрации меньше 6 Мп — тоже;
- лимит 30 МБ не проверяется: он про JPEG, который уходит, а не про оригинал (PNG и TIFF больше, чем их копия).
  Размер JPG у вектора (от 1600 × 1600) тоже не проверяется.

## Проверено на аккаунте (31.10.2026)

- Вход и загрузка по FTPS, файлы в корне FTP.
- Описание и ключевые слова из IPTC доходят до 123RF.
- 01.11.2026, расширение: три работы из Kadrobit прошли Proceed, стали черновиками с описанием и 48–49 ключевыми
  словами и ушли на проверку; у них и у `IMG_0930u` в Kadrobit записаны ID 123RF и статус «на проверке».
- Аккаунт, созданный через Google, пароля не имеет: его задают на странице Upload Content («Registered with Google?
  Get your password»), иначе FTP отвечает `Login authentication failed`.
