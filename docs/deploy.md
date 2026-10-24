# Развёртывание на сервере

Особенности выкладки Kadrobit на сервер и всё, что там нужно настроить руками. Пополняется по мере появления новых требований.

## Сервер

Shared hosting ukraine.com.ua (панель adm.tools), аккаунт `ix569599`, SSH — `ix569599.ftp.tools` (в `~/.ssh/config` — `cubelab-host`). На этом же аккаунте живут cartanera.net, cubelab.store и wellcallyou.com; kadrobit.net пока не выложен.

- CloudLinux 8 (CageFS, лимиты LVE), x86_64, glibc 2.28, 32 ядра.
- Системные пакеты ставить нельзя: нет root, нет `gcc`. Свои программы ставятся в домашнюю папку (`~/opt`), запускать их оттуда разрешено, исходящая сеть есть.
- PHP 8.4: `/usr/local/php84/bin/php` (команда `php` в SSH — тоже 8.4). Не ниже 8.4.27: в ней исправлены критические RCE CVE-2026-109618 и CVE-2026-109619 (21.10.2026; для других веток — 8.5.12, 8.3.36, 8.2.35). Сайты работают через LiteSpeed (`lsphp`), версию сайта выбирают в панели; какая версия реально запущена, видно по процессам: `readlink /proc/$(pgrep -n lsphp)/exe`, а сайт — по `PHPRC` в `/proc/<pid>/environ` (`~/.system/php/<сайт>.ini`). Расширения: `imagick` (ImageMagick 7.1.2 с librsvg), `exif`, `fileinfo`, `gd`, `intl`, `pcntl`, `posix`, `pdo_pgsql`, `redis`, `zip`. `proc_open` не запрещён.
- `composer` есть, `node`/`npm` нет: фронтенд собирается не на сервере (`npm run build` локально или в CI), на сервер едет `public/build`.

## Программы для обработки загрузок

Превью видео и векторов ([KB-19](https://github.com/Intiligent/kadrobit/issues/19), `docs/library.md`, «По типам работ») делают внешние программы. Пути к ним — в `.env` (`config/assets.php`); по умолчанию берутся из `PATH`, как локально с Homebrew.

| Программа | На сервере | Откуда |
|---|---|---|
| `ffmpeg`, `ffprobe` | 9.0.1 с `libx264`, `/usr/bin` | Системные, подходят как есть |
| Ghostscript | Системный 9.27 (2019) **не использовать**: в версиях до 9.50 известны обходы `-dSAFER`, а EPS приходят от пользователей | 10.08 из conda-forge, `~/opt/media/bin/gs` |
| `rsvg-convert` (librsvg) | Нет | 2.62 из conda-forge, `~/opt/media/bin/rsvg-convert` |

### Установка (сделано 24.10.2026)

[micromamba](https://mamba.readthedocs.io/) — одна статическая программа, ставит пакеты conda-forge в домашнюю папку без root. Пакеты conda-forge собраны под старую glibc и работают на glibc 2.28. Не подошли: готовый Ghostscript от Artifex (только исходники и `.snap`), сборка из исходников (нет `gcc`), resvg (требует glibc 2.35).

```sh
mkdir -p ~/opt/micromamba/bin
curl -sSL https://micro.mamba.pm/api/micromamba/linux-64/latest | tar xj -C ~/opt/micromamba bin/micromamba
export MAMBA_ROOT_PREFIX=$HOME/opt/micromamba
~/opt/micromamba/bin/micromamba create -y -p $HOME/opt/media -c conda-forge ghostscript librsvg
~/opt/micromamba/bin/micromamba clean -y --all   # кеш пакетов не нужен
```

Окружение занимает около 330 МБ. Обновление до свежих версий:

```sh
MAMBA_ROOT_PREFIX=$HOME/opt/micromamba ~/opt/micromamba/bin/micromamba update -y -p $HOME/opt/media --all
```

### `.env`

Пути абсолютные: задачи из cron запускаются с урезанным `PATH`.

```ini
ASSETS_FFMPEG=/usr/bin/ffmpeg
ASSETS_FFPROBE=/usr/bin/ffprobe
ASSETS_GHOSTSCRIPT=/home/ix569599/opt/media/bin/gs
ASSETS_RSVG_CONVERT=/home/ix569599/opt/media/bin/rsvg-convert
```

Проверка — от того же пользователя, под которым работают обработчики очереди:

```sh
/usr/local/php84/bin/php artisan assets:tools
```

Команда показывает версии всех программ, есть ли `imagick` и `libx264`, и завершается с ошибкой, если чего-то не хватает. Команды, которые запускает приложение (`ffprobe`, видеокопия с прогрессом, `gs` для EPS, `rsvg-convert` для SVG, чтение PNG в Imagick), проверены на сервере из PHP 8.4 с урезанным `PATH`.

## Что ещё настроить при выкладке

- [ ] **Лимиты PHP для веба** (панель adm.tools): в CLI сейчас `upload_max_filesize=2M`, `post_max_size=8M` — загрузка видео с такими лимитами не пройдёт. Нужны значения из `docs/library.md` («Лимит размера файла»): `upload_max_filesize = 200M`, `post_max_size = 210M`, `max_execution_time = 300`, и такой же лимит тела запроса у веб-сервера.
- [ ] **Обработчики очереди.** Supervisor на shared hosting нет, задачи запускает cron панели (`/usr/local/bin/cron "…"`). Нужны свои обработчики для `default` и `uploads` (`docs/library.md`, «Очередь и обработчики»), с `--timeout=300` у `uploads` меньше `DB_QUEUE_RETRY_AFTER` (330). Запуск раз в минуту с `--max-time=3600`, как у cubelab, может набрать десятки параллельных обработчиков, если обёртка cron не защищает от наложения, — для `uploads` с тяжёлым `ffmpeg` нужен запуск с блокировкой (например, `flock -n` на файл блокировки) или `--stop-when-empty`.
- [ ] **Расширение PHP `ftp`** (панель adm.tools, для CLI и для `lsphp` сайта): его нет в списке расширений выше, а без него не работают подключение маркетплейсов по FTP/FTPS (`docs/settings.md`) и `composer install` (`ext-ftp` в `composer.json`).
- [ ] **`CREDENTIALS_KEY`** — ключ шифрования паролей маркетплейсов (`docs/settings.md`, «Хранение паролей»). Создать на сервере `php artisan credentials:key`, сохранить копию вне сервера: без ключа сохранённые пароли не расшифровать, придётся подключать маркетплейсы заново. Отдельных секретов окружения на shared hosting нет, поэтому ключ лежит в `.env` рядом с `APP_KEY` и от утечки `.env` целиком не защищает; защищает от утечки одной базы или бэкапа без `.env`.
- [ ] **Планировщик:** cron раз в минуту `php artisan schedule:run` (`passport:purge`, `model:prune`, `queue:prune-batches`).
- [ ] **После выкладки:** `php artisan queue:restart`, чтобы обработчики подхватили новый код.
- [ ] **Диск под загрузки:** файлы ждут обработки в `storage/app/private/uploads`, нужен запас не меньше `ASSETS_UPLOAD_MAX_MB` × число одновременных загрузок (на аккаунте свободно больше 1 ТБ).
