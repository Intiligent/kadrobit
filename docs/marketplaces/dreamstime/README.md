# Dreamstime: загрузка по FTP и CSV с метаданными

Источники (27.10.2026): [FTP upload](https://www.dreamstime.com/upload/help-ftp-upload),
[Create and upload CSV files](https://www.dreamstime.com/upload-csv-tutorial). Шаблоны таблиц с той же страницы
лежат в `templates/` (исходники `.xls`), список категорий — в `categories.csv`.

## Подключение

- Сервер `upload.dreamstime.com`, порт 21, пассивный режим.
- Логин — числовой User ID со страницы FTP upload в кабинете, не email. Пароль — от аккаунта Dreamstime.
- В справке написано FTP, но сервер отвечает на `AUTH TLS` (проверено по `FEAT`): подключаемся по FTPS, чтобы пароль
  не уходил открытым текстом. В Kadrobit: `upload_protocol = ftps`, `login_is_contributor_id = true` (`MarketplaceSeeder`).

## Папки на FTP

| Что | Куда | Требования |
|---|---|---|
| Фото и растровые иллюстрации | `/` (корень) | JPEG, RGB, лучшее качество сжатия, от 3 Мп, до 1 ГБ |
| Векторы и RAW | `/additional` | `.ai`, `.eps`, `.cdr`, `.svg`, RAW; имя как у парного JPEG: `12345.eps` к `12345.jpg` |
| Видео | `/video` | Лучше MOV, кодек Photo JPEG/MJPEG; до 30 с, в отдельных случаях до 60 с |
| Аудио | `/audio` | WAV или FLAC, от 44100 Гц, от 16 бит, до 600 с |
| Релизы моделей и собственности | `/modelrelease` | JPEG (RGB) или PDF, один документ на человека или объект |

## CSV с метаданными работ

Таблица заполняет поля уже загруженных по FTP файлов. Порядок важен:

1. Загрузить файлы и дождаться обработки: они должны появиться в разделе Unfinished.
2. Только потом загрузить CSV в корень FTP. Строки сопоставляются с файлами по имени; файлы, которых нет в таблице,
   не заполняются.

Kadrobit отправляет CSV сразу после файлов, в той же сессии, и на случай, если Dreamstime ещё не обработал какой-то
файл, ещё раз через 30 минут (`SendDreamstimeCsv`, `docs/publishing.md`, раздел «Dreamstime»).

### Колонки для фото (`Image_spreadsheet_template.xls`, лист «Enter data here»)

| Колонка | Значение | Откуда у нас |
|---|---|---|
| `Filename` | Имя загруженного файла, например `Dreamstime_1234.jpg` | Имя файла на FTP |
| `Image Name` | Название | `title` |
| `Description` | Описание | `description` |
| `Category 1`, `Category 2`, `Category 3` | Числовые ID категорий из `categories.csv`, `0` — нет категории | `categories.dreamstime` |
| `keywords` | Ключевые слова через запятую | `keywords` |
| `Free` | `1`: если работа не пройдёт в коммерческий раздел, согласие выложить её в бесплатный (RF-LL) | Нет, `0` |
| `W-EL`, `P-EL`, `SR-EL` | `1`/`0`: расширенные лицензии Web, Print, Sell the Rights | Нет, `0` |
| `SR-Price` | Цена Sell the Rights, только при `SR-EL = 1` | Нет, `0` |
| `Editorial` | `1`: редакционная (новостная) работа | `is_editorial` |
| `MR doc Ids` | ID релизов моделей, присвоенные Dreamstime, через запятую | Нет |
| `Pr Docs` | ID релизов собственности, так же | Нет |

Пример строки из шаблона:

```
Filename,Image Name,Description,Category 1,Category 2,Category 3,keywords,Free,W-EL,P-EL,SR-EL,SR-Price,Editorial,MR doc Ids,Pr Docs
Dreamstime_1234.jpg,Perfect start of a dream,"Dreamstime, a photo`s dream come true",165,0,0,"test1,test2,test3",1,1,0,0,0,1,"252233,252234",1358
```

Справка не говорит про разделитель, кодировку и кавычки. Kadrobit пишет запятую, UTF-8 и кавычки по RFC 4180: так
CSV принят на настоящем аккаунте 27.10.2026 (Uploads history: «Processed as csv»), категории проставились, хотя CSV
ушёл через 2 секунды после файла.

### Видео (`Video_spreadsheet_template.xls`)

Те же колонки, кроме `Image Name` → `Video Name` и без `Free` и `P-EL`:
`Filename, Video Name, Description, Category 1–3, keywords, W-EL, SR-EL, SR-Price, Editorial, MR doc Ids, Pr Docs`.
Категории те же. Куда класть CSV для видео, справка не говорит.

Есть и шаблон для аудио (`Audio_spreadsheet_template.xls`), он не скачан: аудио Kadrobit не загружает.

### Релизы моделей (`MR_spreadsheet_template.xls`)

`Filename, First Name, Last Name, Gender, Age Group, Ethnicity, Country`; значения — коды из листа
«Model Release Legend» (пол `0`/`1`, возрастная группа `1`–`9`, этническая принадлежность `1`–`6`, страна —
числовой код из списка). CSV загружается в `/modelrelease` **до** CSV работ: в таблицу работ идут ID, которые
Dreamstime присвоит релизам (их видно в MR Library). Через FTP эти ID не получить, поэтому Kadrobit оставляет
`MR doc Ids` и `Pr Docs` пустыми, а релизы автор прикрепляет на сайте.

## Категории

`categories.csv` — 182 категории из листа «Image Legend» (у видео тот же список): `id` и название вида
`Раздел -> Подраздел`. Заведены в `MarketplaceCategorySeeder` (название — `Раздел → Подраздел`), на работу — до трёх.
