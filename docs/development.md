# Локальная разработка

## Процессы: Overmind

Очереди, планировщик, логи и Vite запускаются одной командой через [Overmind](https://github.com/DarthSim/overmind) (нужен tmux):

```sh
brew install overmind   # один раз
overmind start          # Procfile.dev указан в .overmind.env
```

| Процесс | Команда | Зачем |
|---|---|---|
| `queue` | `php artisan queue:listen --queue=default` | Письма и другие фоновые задачи. `queue:listen` перечитывает код на каждую задачу, так что правки видны без перезапуска |
| `schedule` | `php artisan schedule:work` | Задачи по расписанию (`routes/console.php`), например `passport:purge` |
| `logs` | `php artisan pail` | Логи приложения в реальном времени |
| `vite` | `npm run dev` | Фронтенд приложения с горячей перезагрузкой |

Фронтенд админки (`vite-dashboard`) добавится вместе с ней — [KB-8](https://github.com/Intiligent/kadrobit/issues/8).

Полезное:

- `overmind connect queue` — подключиться к процессу и смотреть его вывод (выход — `Ctrl+B`, затем `D`);
- `overmind restart queue` — перезапустить процесс;
- `overmind ps` — список процессов.

Каждая команда обёрнута в `while true` и перезапускается, если упадёт.

## Письма

- Все письма уходят через очередь (`ShouldQueue`), без запущенного `queue` они не отправятся.
- Локально `MAIL_MAILER=log`: письмо целиком пишется в `storage/logs/laravel.log` (видно и в `logs`).

## Просмотр писем

Каждое письмо можно открыть в браузере, не отправляя его: `/dev/emails` — список всех писем со ссылками.

| Письмо | Адрес |
|---|---|
| Сброс пароля | `/dev/emails/reset-password` |
| Подтверждение email | `/dev/emails/verify-email` |

- Группа маршрутов `dev/emails` в `routes/web.php`, контроллер `App\Http\Controllers\Dev\EmailPreviewController`. Вне `APP_ENV=local` адреса отвечают 404 (middleware `local.only`).
- Превью рендерит настоящее письмо через `toMail()` уведомления, поэтому совпадает с тем, что уходит пользователю.
- Получатель — вошедший пользователь, а без входа — несохранённый тестовый (Jane Doe): превью ничего не пишет в базу.
- `?locale=xx` — письмо на другом языке.
- Новое письмо: метод в `EmailPreviewController` и маршрут в группе `dev/emails`; в списке оно появится само.

## База данных и часовой пояс

Соединение с PostgreSQL работает в UTC, как приложение (`config/database.php` → `pgsql.timezone`, `DB_TIMEZONE`). Laravel пишет время без смещения, и если часовой пояс сервера БД другой (например, `Asia/Tbilisi`), PostgreSQL сдвигал бы все значения `timestampTz`. Тест `DatabaseTest` следит за этим.

