# Фотостоки: API для загрузки фото с метаданными

Oct 20, 2026 · @Vitaliy

## Коротко

Ни у одного из 11 проверенных стоков нет публичного API для загрузки фото контрибьютором. API у Shutterstock, Adobe, Depositphotos, Dreamstime, Alamy, Pond5 есть, но они для покупателей и партнёров-реселлеров. Сотрудник Adobe на форуме прямо подтвердил: публичного API для отправки работ на Adobe Stock нет.

Реальный путь автоматизации у всех один и тот же:

1. **Файл с метаданными внутри** (IPTC/XMP) — часть стоков сама читает title и ключи из JPG.
2. **Загрузка по FTP / FTPS / SFTP** — есть почти у всех, кроме iStock/Getty и Canva.
3. **CSV с метаданными** — догружается отдельно, перекрывает данные из файла.
4. **Финальный submit на модерацию** — только через веб-интерфейс. Именно эту часть Microstock+ и StockSubmitter автоматизируют эмуляцией браузера.

## Стоки и способы загрузки

| Сток | API загрузки для контрибьютора | Загрузка файлов | Метаданные | Примечание |
| --- | --- | --- | --- | --- |
| [Adobe Stock](https://community.adobe.com/t5/stock-contributors-discussions/upload-images-through-api/m-p/14481643) | Нет (подтверждено сотрудником Adobe) | Веб; SFTP — только для «квалифицированных» аккаунтов | [CSV](https://helpx.adobe.com/stock/contributor/manage-your-portfolio/upload-csv-file.html) до 5000 строк на пакет; до 49 ключей | Adobe сам советует SFTP как обходной путь для автоматизации |
| [Shutterstock](https://submit.shutterstock.com/help/en/articles/10594645-how-do-i-submit-photos-for-review) | Нет — в [API](https://api-reference.shutterstock.com/) только чтение данных контрибьютора | Веб (до 50 МБ, 100 файлов), FTPS ftps.shutterstock.com | Описание из IPTC/XMP в JPG; CSV: Filename, Description, Keywords, Categories; 7–50 ключей | CSV грузится только через сайт, не по FTPS |
| [iStock / Getty Images](https://www.mystockphoto.org/?p=13990) | Нет публичного | Веб-портал ESP, мобильное приложение | Вводятся в ESP | FTP не поддерживается; Getty принимает FTP только у одобренных партнёров (например, через [PhotoShelter](https://go.photoshelter.com/integration/getty-images/)) |
| [Alamy](https://www.alamy.com/help/contributor-uploading) | Нет — API только для покупателей | Веб, FTP upload.alamy.com:21 | Подписи и теги в Image Manager; IPTC из JPG читается для векторов |  |
| [Dreamstime](https://www.dreamstime.com/dreamstime-api) | Нет — API для партнёров, бизнеса, реселлеров | Веб, FTP upload.dreamstime.com | [CSV](https://www.dreamstime.com/upload-csv-tutorial) через FTP, отдельный CSV для релизов | AI-подсказки ключей PhotoEye |
| [Depositphotos](https://depositphotos.com/api-agreement.html) | Нет — API только для реселлеров | Веб, FTP | Нет данных |  |
| [123RF](https://blog.photokeyworder.ai/?p=233) | Не найдено | Веб, FTP ftp.123rf.com (квота 4 ГБ) | IPTC caption и ключи читаются; CSV до 2 МБ | Отдельные FTP для видео и аудио |
| [Pond5](https://www.pond5.com/help/en/articles/10086192-using-the-ftp-file-transfer-protocol-uploader) | Не найдено — API для покупателей | Веб, FTP ftp.pond5.com (отдельный пароль) | Разметка после обработки | Основной сток для видео |
| [Magnific (бывш. Freepik)](https://www.magnific.com/ai/contributors/ftp-upload-method) | Не найдено | Веб, FTP | Title и ключи должны быть зашиты в JPG; или CSV |  |
| [Vecteezy](https://www.vecteezy.com/blog/contributor/contributors-use-a-csv-to-upload-metadata-faster) | Не найдено | Веб, FTP | CSV: Filename, Title, Description, Keywords — можно класть по FTP вместе с файлами |  |
| [Canva](https://blog.photokeyworder.ai/?p=222) | Нет | Только веб-портал Canva Creators | Вручную | Отбор в программу по заявке |

«Не найдено» означает, что в документации стока нет упоминания API загрузки — это не официальное подтверждение отсутствия.

## Что это значит для своей платформы

Платформу придётся строить без официальных API: загрузку — через FTP/SFTP, отправку на модерацию — через автоматизацию браузера или руками пользователя.

- **Уровень 1, надёжный:** запись title, description и ключей в IPTC/XMP + загрузка по FTP/FTPS/SFTP + генерация CSV под каждый сток. Работает для Shutterstock, Adobe (при доступе к SFTP), Alamy, Dreamstime, Depositphotos, 123RF, Pond5, Magnific, Vecteezy.
- **Уровень 2, хрупкий:** автоматический submit через headless-браузер (Playwright) — так делают Microstock+ и ImStocker. Ломается при любом редизайне сайта стока.
- **Без автоматизации:** iStock/Getty и Canva — только веб-портал.
- **Хранение доступов:** FTP-пароли пользователей придётся хранить — нужен шифрованный vault и понятная политика безопасности.
- **Для MVP под Adobe Stock + Shutterstock** достаточно уровня 1: файлы по FTPS/SFTP, CSV формируется автоматически, пользователь жмёт Submit в портале сам.
- **Открытый вопрос:** допускают ли правила Adobe Stock и Shutterstock автоматический submit сторонним сервисом — стоит проверить условия контрибьютора до разработки уровня 2.

## Источники

- [Adobe Community — нет публичного API для отправки на Adobe Stock](https://community.adobe.com/t5/stock-contributors-discussions/upload-images-through-api/m-p/14481643)
- [Adobe Community — SFTP только для квалифицированных аккаунтов](https://community.adobe.com/t5/stock-contributors-discussions/option-to-upload-photos-and-videos-using-sftp-disappeared/td-p/14687655)
- [Adobe Stock — загрузка CSV](https://helpx.adobe.com/stock/contributor/manage-your-portfolio/upload-csv-file.html)
- [Adobe Stock — заголовки и ключевые слова](https://helpx.adobe.com/stock/contributor/help/titles-and-keyword.html)
- [Shutterstock — как отправить фото на проверку](https://submit.shutterstock.com/help/en/articles/10594645-how-do-i-submit-photos-for-review)
- [Shutterstock API Reference](https://api-reference.shutterstock.com/)
- [Гайд по Shutterstock: FTPS, CSV, IPTC](https://blog.photokeyworder.ai/?p=185)
- [Getty/iStock — приложение Contributor и ESP](https://www.mystockphoto.org/?p=13990)
- [PhotoShelter — интеграция с Getty Images](https://go.photoshelter.com/integration/getty-images/)
- [Alamy — загрузка для контрибьюторов](https://www.alamy.com/help/contributor-uploading)
- [Dreamstime — API](https://www.dreamstime.com/dreamstime-api) · [FTP](https://ar.dreamstime.com/ftp_instructions) · [CSV](https://www.dreamstime.com/upload-csv-tutorial)
- [Depositphotos — соглашение API](https://depositphotos.com/api-agreement.html)
- [Гайд по 123RF: FTP и CSV](https://blog.photokeyworder.ai/?p=233)
- [Pond5 — FTP-загрузка](https://www.pond5.com/help/en/articles/10086192-using-the-ftp-file-transfer-protocol-uploader)
- [Magnific — FTP-загрузка](https://www.magnific.com/ai/contributors/ftp-upload-method)
- [Vecteezy — CSV для метаданных](https://www.vecteezy.com/blog/contributor/contributors-use-a-csv-to-upload-metadata-faster)
- [Гайд по Canva Creators](https://blog.photokeyworder.ai/?p=222)
