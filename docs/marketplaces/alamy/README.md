# Alamy: загрузка по FTP

Источники (30.10.2026): [Uploading Images](https://www.alamy.com/help/contributor-uploading/) (справка для авторов),
FAQ Alamy о подписях и тегах для векторов, форум авторов
([папки на FTP](https://discussion.alamy.com/topic/12267-ftp-folders-for-upload-not-as-in-documentation/)).

## Подключение

- Сервер `upload.alamy.com`, порт 21. Логин — email аккаунта Alamy, пароль — от аккаунта.
- Только обычный FTP: в ответе на `FEAT` нет `AUTH TLS` (проверено 30.10.2026), FTPS и SFTP не поддерживаются.
  Пароль уходит открытым текстом — так устроен сервер Alamy. В Kadrobit: `upload_protocol = ftp` (`MarketplaceSeeder`).

## Папки на FTP

| Папка | Что | В Kadrobit |
|---|---|---|
| `Stock` | Фото. Первая отправка нового автора — ровно 3 изображения | Фото |
| `Vectors` | EPS и JPEG с метаданными под тем же именем | Векторы |
| `Archive stock` | Репортаж и архив, только с разрешения Alamy | Не используется |
| Live News | Новости младше 24 часов, отдельный сервер `newsupload.alamy.com`, только с разрешения | Не используется |

Имена папок — по справке; на форуме пишут, что у обычного аккаунта видны только `Stock` и `Vectors`. Точный вид
(регистр, пробелы) проверить на первом входе ([KB-74](https://github.com/Intiligent/kadrobit/issues/74)).

## Требования к файлам

- **Фото:** JPEG, RGB, baseline (не progressive), качество от 8 по шкале Photoshop. Без сжатия больше 17 МБ:
  17 МиБ / 3 байта на пиксель ≈ 5,94 Мп — это `min_megapixels`. Снимки с зеркальных и беззеркальных камер или
  смартфонов известных марок от 2020 года. ИИ-изображения не принимаются.
- **360° панорамы:** XMP Photosphere, равнопромежуточная проекция, ровно 2:1, до 200 МБ. Kadrobit их отдельно
  не выделяет.
- **Вектор:** только EPS (AI, SVG и DRW не принимаются), без заблокированных слоёв, контуры замкнуты. Метаданные — в
  JPEG с тем же именем в той же загрузке; размер и качество этого JPEG не важны, Alamy берёт из него только текст.
- **Видео** от авторов сейчас не принимается, только от агентств-партнёров.

## Метаданные

- Alamy читает IPTC из JPEG: Description (Caption-Abstract) становится подписью (Caption), Keywords — тегами.
  В справке это описано для JPEG к векторам, FAQ о подписях говорит о любом JPEG.
- Caption у Alamy — до 128 символов, по нему ищут; это наше название. Поэтому Kadrobit пишет название работы и в
  IPTC Description. Description у Alamy (до 2000 символов, по нему не ищут) из файла не заполняется — его автор
  вводит в Image Manager, если нужно.
- CSV нет.

## Категории

Основная (Primary) и необязательная дополнительная (Secondary) категория, обе из одного списка в 25 названий —
[Image Categories Guide](https://assets.ctfassets.net/kdawwlsweh27/34e5nFT9mqGAmUMUOiu2zn/47dde3cde483f974f710da859f1ee669/Alamy_Image_Categories_Guide.pdf)
(«Alamy Content Categorisation», по ссылке из [справки](https://www.alamy.com/help/contributor-image-sales-guide/), 30.10.2026).
Обязательными они не являются.

В Kadrobit список в `MarketplaceCategorySeeder` (`external_id` — название, номеров у Alamy нет), до двух на работу
(`categories_max = 2`, первая — основная). Работе их можно выбрать и сгенерировать, но до Alamy они не доходят: в
файле категории не передаются, CSV у Alamy нет. Автор выбирает их в Image Manager.

Abstracts and backgrounds, Animals and wildlife, Architecture and interiors, Archive or historical, Art and artwork,
Business, Celebrations and life events, Concepts, Education, Entertainment and celebrities, Food and drink,
Healthcare and medical, Industry and agriculture, Landscapes, Lifestyle, News and reportage, Objects and still life,
Occupations, Plants and gardens, Science, Sport, Technology, Transport, Travel, Weather and seasons.

Archive or historical — для архивной загрузки (снимки старше 20 лет), News and reportage и Entertainment and
celebrities — в основном для новостной ленты.

## После загрузки

1. Файлы проходят QC; из очереди QC их удалить нельзя. Прошедшее изображение — письмо на почту.
2. Подписи и теги проверяются и дополняются в Image Manager (My Alamy).
3. В продаже — после обновления поиска, раз в сутки.

Отдельной кнопки Submit нет: отправка на QC — сама загрузка.

## В Kadrobit

`AlamyDelivery` (`docs/publishing.md`, раздел «Alamy»):

- фото — JPEG в `Stock` с названием и ключевыми словами внутри; PNG, TIFF, CMYK и progressive JPEG уходят копией в
  baseline sRGB JPEG (`MakesJpegCopies`);
- вектор — EPS и его JPEG (`raster_companion`) с одним именем в `Vectors`;
- SVG и AI не уходят: `file_rules.vector.formats_strict`, проверка показывает «Формат не принимается»;
- фото меньше 5,94 Мп не уходят; работы, созданные ИИ, получают предупреждение.

## Не проверено на аккаунте

- Точные имена папок.
- Подхватывает ли Alamy подпись и теги из IPTC у фото, а не только у JPEG к вектору.
- Что Alamy делает с JPEG, у которого EPS не пришёл в той же сессии.
