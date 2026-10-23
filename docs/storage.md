# Хранилище файлов

Файлы работ лежат в Cloudflare R2, в двух бакетах. Окружения пока не разделяются: локальная разработка и продакшен пишут в одни и те же бакеты.

| Бакет | Диск Laravel | Доступ | Что хранит |
|---|---|---|---|
| `kadrobit-private` | `r2-private` | Закрытый, файлы отдаются только по подписанным ссылкам (`temporaryUrl`) | Оригиналы и JPEG-пары векторов: то, что пользователь продаёт |
| `kadrobit-public` | `r2-public` | Публичный, через домен бакета (`R2_PUBLIC_URL`) | Превью, миниатюры, лёгкие копии видео |

Какой диск у файла, решает его роль: `AssetFileRole::disk()` — `original` и `raster_companion` на приватном, остальные (`preview`, `thumbnail`, `video_preview`) на публичном. Диск записан в `asset_files.disk`. Имена дисков можно переопределить через `ASSETS_PRIVATE_DISK` и `ASSETS_PUBLIC_DISK` (в тестах — `Storage::fake`).

## Пути

```
assets/{user_ulid}/{asset_ulid}/{role}[.{hash}].{ext}
```

```
kadrobit-private  assets/01JB8Z…/01JB9A…/original.jpg
kadrobit-private  assets/01JB8Z…/01JB9A…/raster_companion.jpg
kadrobit-public   assets/01JB8Z…/01JB9A…/thumbnail.3f9a1c2b.jpg
kadrobit-public   assets/01JB8Z…/01JB9A…/preview.3f9a1c2b.jpg
```

- Путь строит одно место — `AssetFile::pathFor($asset, $role, $extension, $hash)`.
- **`user_ulid`, а не `id`:** числовой id пользователя не попадает в публичные ссылки. У пользователей есть `users.ulid`.
- **Папки библиотеки в путь не входят:** это записи в базе, перенос работы между папками не трогает файлы.
- **Имя файла — роль.** Исходное имя файла хранится только в `assets.original_filename`; при скачивании оригинал получает его через `Content-Disposition`.
- **Хеш в имени производных файлов** (первые 8 символов хеша содержимого): публичные файлы кешируются CDN, поэтому перегенерированное превью получает новый адрес. У оригиналов хеша нет: они не меняются.
- Все файлы работы лежат под одним префиксом `assets/{user_ulid}/{asset_ulid}/` в каждом бакете, все файлы пользователя — под `assets/{user_ulid}/`: удаление работы или аккаунта и подсчёт занятого места — операции по префиксу.

## Настройки

```dotenv
R2_ACCESS_KEY_ID=            # один API-токен R2 с правами Object Read & Write на оба бакета
R2_SECRET_ACCESS_KEY=
R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_PRIVATE_BUCKET=kadrobit-private
R2_PUBLIC_BUCKET=kadrobit-public
R2_PUBLIC_URL=               # публичный адрес kadrobit-public
```

Сейчас `R2_PUBLIC_URL` — адрес `r2.dev` (Public Development URL). У него ограничена частота запросов и нет кеша Cloudflare, поэтому для продакшена нужен свой домен (например, `media.kadrobit.net`). Свой домен подключается только из зоны, которая управляется DNS Cloudflare в том же аккаунте; `kadrobit.net` пока в нём нет.

## Что сделать владельцу

- [x] Создать API-токен R2 «Kadrobit app» (R2 → API Tokens → Create Account API token): права **Object Read & Write**, доступ только к бакетам `kadrobit-private` и `kadrobit-public`. Ключи — в `.env`: `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`.
- [x] Удалить пустой бакет `kadrobit`.
- [ ] Удалить старый токен «Kadrobit», у которого доступ был только к бакету `kadrobit`.
- [ ] Для продакшена: перенести DNS `kadrobit.net` в Cloudflare, подключить к `kadrobit-public` свой домен и указать его в `R2_PUBLIC_URL` вместо `r2.dev`.
