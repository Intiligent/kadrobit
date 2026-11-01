# Vecteezy: загрузка по SFTP с CSV

Источники (31.10.2026), справка для авторов [eezycontributors.zendesk.com](https://eezycontributors.zendesk.com/hc/en-us):
[SFTP Upload Process](https://eezycontributors.zendesk.com/hc/en-us/articles/6313254444564-SFTP-Upload-Process),
[CSV Metadata Upload](https://eezycontributors.zendesk.com/hc/en-us/articles/360029388091-CSV-Metadata-Upload),
[Upload Settings](https://eezycontributors.zendesk.com/hc/en-us/articles/26668485078676-Upload-Settings),
[Auto Submit](https://eezycontributors.zendesk.com/hc/en-us/articles/26699705191700-Auto-Submit),
[Magic Metadata](https://eezycontributors.zendesk.com/hc/en-us/articles/26699723854484-Magic-Metadata),
[Contributor Guide: Add Data](https://eezycontributors.zendesk.com/hc/en-us/articles/360058026552-Contributor-Guide-Add-Data),
[Content Guidelines - Photo](https://eezycontributors.zendesk.com/hc/en-us/articles/360037454312-Content-Guidelines-Photo),
[Choosing Your License Type](https://eezycontributors.zendesk.com/hc/en-us/articles/360058481191-Choosing-Your-License-Type).
Страницы справки отдают 403 обычному запросу; текст статей читается через API Zendesk
(`/api/v2/help_center/en-us/articles/<id>.json`).

## Подключение

- Только SFTP, порт 22: обычный FTP выключен 23.05.2022. Сервер `content-ftp.eezy.com` (AWS Transfer, отвечает
  `SSH-2.0-AWS_SFTP_1.2`; на порту 21 не отвечает — проверено 31.10.2026), логин и пароль — свои у каждого автора, на
  странице Upload в Content Manager. В Kadrobit: `upload_protocol = sftp`, `upload_defaults.host` (`MarketplaceSeeder`).
- С августа 2024 файлы обрабатываются сами после загрузки, кнопка Process Uploads не нужна.

### SFTP открывается только после онбординга

У нового аккаунта поля Username и Password в блоке «sFTP Upload» пустые и не редактируются, сервер
(`content-ftp.eezy.com`) при этом показан. Кабинет берёт их из сессии (`/api/v1/session`, объект `ftp`: `host`,
`username`, `password`); у аккаунта в состоянии `onboarding` сервер отдаёт `username` и `password` равными `null`
(проверено 01.11.2026 в браузере автора, ничего не нажималось). Email и пароль от сайта для SFTP не подходят: сервер
отвечает отказом в логине.

Онбординг (виджет «Welcome to Vecteezy's Content Manager», статус «Awaiting Upload»):

1. Загрузить через сайт и отправить на проверку первые 25 файлов.
2. Подтвердить личность (Verify ID).
3. Пройти проверку Vecteezy.

После этого открывается «полный опыт автора» — по всей видимости, и логин с паролем SFTP. До этого Kadrobit
отправлять в Vecteezy не может. Ключи API для агрегаторов (API Key ID / API Secret) у такого аккаунта уже выданы.

## API для агрегаторов

[Vecteezy API Access Information for Stock Aggregators](https://eezycontributors.zendesk.com/hc/en-us/articles/16974025613716-Vecteezy-API-Access-Information-for-Stock-Aggregators):
API загрузки открыт только «доверенным стоковым агрегаторам» — сейчас [Microstock Plus](https://microstock.plus/) и
[StockSubmitter](https://stocksubmitter.com/). Автор генерирует на странице Upload (раздел «Stock Aggregator
Credentials», «Generate Keys») пару API Key / API Secret и отдаёт её агрегатору, тот загружает работы в аккаунт от
имени автора. Публичной документации нет; чтобы пользоваться ключами, Kadrobit должен одобрить Vecteezy. Пока
Kadrobit загружает по SFTP. [Vecteezy API для разработчиков](https://www.vecteezy.com/developers) — поиск и
скачивание контента, к загрузке не относится.

## Требования к файлам

- **Фото:** JPG, sRGB, от 4 до 50 Мп, до 50 МБ, без увеличения разрешения, водяных знаков и подписей.
- **Вектор:** EPS (или AI) и JPG с тем же именем; по SFTP — не в ZIP.
- **Видео:** MOV, MP4, R3D — Kadrobit пока не отправляет (`supported_types`).

## Метаданные

Порядок у Vecteezy: сначала CSV, загруженный вместе с файлами; нет CSV — IPTC/XMP из файла; нет и его — автор
заполняет Add Data. Kadrobit отправляет и то и другое.

CSV — колонки `Filename, Title, Description, Keywords, License`:

| Колонка | Значение | Откуда у нас |
|---|---|---|
| `Filename` | Имя файла на сервере **без расширения** (так требует справка для SFTP), без пробелов | Имя файла на SFTP |
| `Title` | От 3 слов, до 200 символов | `title` |
| `Description` | Необязательно | `description` |
| `Keywords` | Через запятую, от 5 до 50 | `keywords` |
| `License` | `pro`, `free`, `editorial` или пусто; важнее настройки License у автора | `pro`; у редакционных работ (`is_editorial`) — `editorial` |

Лицензии:

- **Pro** — только подписчикам Pro или за кредиты, 50 % выручки, самые строгие требования к качеству.
- **Free** — бесплатно всем, оплата за скачивание ($5 за 1000), коммерческое использование с указанием автора.
- **Editorial** — бесплатно всем, только редакционное использование. Работа должна документировать событие или тему;
  перевести потом во Free или Pro нельзя. Поэтому Kadrobit ставит её только редакционным работам.

Категория выбирается на Add Data из списка по типу файла; ни CSV, ни файл её не передают. Релизы — тоже только на сайте.

## После загрузки

- Валидные работы попадают в Add Data, где автор нажимает Submit For Review, а при включённой настройке
  **Auto Submit** (Upload Settings) сразу уходят в Pending Review. Auto Submit не включать для работ, которым нужен релиз.
- **Magic Metadata** (Upload Settings) заменяет CSV и IPTC названиями и ключевыми словами, сгенерированными Vecteezy, —
  должна быть выключена.
- Проверка — 7–10 дней.

## В Kadrobit

`VecteezyDelivery` (`docs/publishing.md`, раздел «Vecteezy»):

- у каждой работы свой CSV в одну строку (`<имя>.csv`), он уходит **перед** её файлами: файлы обрабатываются сразу по
  прибытии, и CSV должен уже лежать на сервере;
- фото и иллюстрации — sRGB JPEG в корень с названием, описанием и ключевыми словами внутри (`MakesJpegCopies` для
  PNG, TIFF, CMYK);
- вектор — EPS и его JPEG (`raster_companion`) с одним именем; SVG не уходит (`formats_strict`);
- пробелы в имени файла заменяются на `_`.

## Не проверено на аккаунте

- Что логин и пароль SFTP появляются сразу после онбординга.
- Папка, куда попадает вход, и права на запись в неё.
- Подхватывает ли Vecteezy CSV, пришедший за секунды до файла, и не мешают ли ему CSV других работ в той же папке.
- Разбирает ли Vecteezy CSV с кавычками по RFC 4180 (так пишет Kadrobit, как и для Dreamstime).
