# LChat Bot API

Публичная HTML-документация для хостинга: `https://chat.lbs.su/docs.php`.

В мессенджере найдите `@BotCreator` или нажмите **🤖 Мои боты → Написать @BotCreator**. Команда `/newbot` пошагово спрашивает имя и логин и выдаёт API-токен. `/mybots` показывает ваших ботов; `/token @имябота` выпускает новый токен и сразу отключает старый; `/setname @имябота Новое имя` меняет имя; `/cancel` отменяет создание.

Bot API работает на том же PHP-хостинге, что и сайт. Войдите в LChat, нажмите **🤖 Мои боты** либо напишите `@BotCreator`, укажите имя и логин с окончанием `bot`. Скопируйте показанный токен и храните его как пароль. Кнопка «Новый токен» и команда `/token` немедленно отключают старый.

Откройте личный чат с ботом или добавьте его в свою группу/канал через «Мои боты». Бот получает только сообщения чатов, в которых состоит, и только после вступления. Участники видят отправителя как отдельный аккаунт бота.

Основной путь: `https://chat.lbs.su/bot<TOKEN>/<METHOD>`. Если хостинг не применяет правила `.htaccess`, используйте `https://chat.lbs.su/bot-api.php?token=<TOKEN>&method=<METHOD>`. Для уменьшения попадания токена в URL и журналы запросов можно передать `Authorization: Bearer <TOKEN>` и указать только `?method=<METHOD>`.

Ответ всегда JSON: `{"ok":true,"result":...}` или `{"ok":false,"error_code":400,"description":"..."}`. Текстовые методы принимают JSON или обычные поля формы. Токен храните как пароль; не вставляйте его в клиентский JavaScript или публичную страницу.

## Методы

| Метод | HTTP | Поля | Результат |
| --- | --- | --- | --- |
| `getMe` | GET | — | Данные бота |
| `getUpdates` | GET | `offset` (с 0), `limit` (1–100) | Очередь сообщений и нажатий; следующий offset = последний `update_id + 1` |
| `getChat` | GET | `chat_id` | Чат, доступный боту |
| `setWebhook` / `deleteWebhook` | POST | `url`, необязательные `secret_token`, `drop_pending_updates` | Включить или отключить HTTPS-вебхук |
| `getWebhookInfo` | GET | — | Адрес, число ожидающих событий и последняя ошибка |
| `sendMessage` | POST | `chat_id`, `text`, необязательные `reply_to_message_id`, `reply_markup` | Отправленное сообщение, inline-кнопки или обычная клавиатура |
| `sendDocument`, `sendPhoto`, `sendAudio`, `sendVideo`, `sendVoice`, `sendAnimation` | POST | `chat_id`, файл через multipart или `file_id`, необязательные `caption`, `reply_markup` | Медиа-сообщение |
| `getFile` | GET | `file_id` | Метаданные и путь для скачивания через `bot-file.php` |
| `editMessageText` | POST | `chat_id`, `message_id`, `text` | Изменённое сообщение бота |
| `editMessageReplyMarkup` | POST | `chat_id`, `message_id`, `reply_markup` | Изменённые inline-кнопки |
| `answerCallbackQuery` | POST | `callback_query_id`, необязательный `text` | Ответ на нажатие кнопки |
| `deleteMessage` | POST | `chat_id`, `message_id` | `true`; только собственное сообщение бота |

`getUpdates` использует короткий опрос и несовместим с одновременно активным вебхуком. Установка `setWebhook` требует публичный HTTPS-домен на порту 443 или 8443 и PHP-расширение `curl`; доставка проверяет TLS и IP адрес. После входящего запроса LChat пробует отправить ожидающие события. Для надежных повторов при простое сайта запустите `php tools/bot-webhook-worker.php` по расписанию. Если у хостинга нет cron, события будут повторяться только после следующего запроса. Параметр `drop_pending_updates` удаляет ожидающие события лишь при значении `true`, `1`, `yes` или `on`.

Файлы до 8 МБ требуют PHP `fileinfo`; суммарная квота файлов бота — 200 МБ. `getFile.result.file_path` возвращает относительный путь; скачивание требует тот же Bearer-токен или параметр `token`. В `reply_markup` поддерживается `inline_keyboard` с кнопками `callback_data` или HTTPS `url` (до 32 кнопок). Нажатие приходит как `callback_query` в очереди, ответ пользователю отправляется через `answerCallbackQuery`. Обычная `keyboard` показывает текстовые кнопки под полем ввода; нажатие отправляет боту сообщение с текстом кнопки. Поддерживаются `resize_keyboard`, `one_time_keyboard`, `input_field_placeholder` и `remove_keyboard`. Кнопки запроса контакта и местоположения пока не поддерживаются. После обновления на очередь старые сообщения не переотправляются. Реализован перечисленный набор совместимых методов; полный Telegram Bot API, включая платежи, стикеры и игры, не поддерживается.

Пример обычной клавиатуры в поле `reply_markup` метода `sendMessage`:

```json
{"keyboard":[[{"text":"Помощь"},{"text":"Профиль"}],[{"text":"Настройки"}]],"resize_keyboard":true,"one_time_keyboard":false,"input_field_placeholder":"Выберите команду"}
```

Убрать её можно следующим сообщением с `"reply_markup":{"remove_keyboard":true}`. Редактирование клавиатуры через `editMessageReplyMarkup` относится только к inline-кнопкам.

## Пример

```bash
curl -H "Authorization: Bearer ВАШ_ТОКЕН" "https://chat.lbs.su/bot-api.php?method=getMe"
curl -H "Authorization: Bearer ВАШ_ТОКЕН" "https://chat.lbs.su/bot-api.php?method=getUpdates&offset=0"
curl -X POST -H "Authorization: Bearer ВАШ_ТОКЕН" -H "Content-Type: application/json" -d '{"chat_id":123,"text":"Привет!"}' "https://chat.lbs.su/bot-api.php?method=sendMessage"
```

`chat_id` берите из `message.chat.id` ответа `getUpdates` или результата `getChat`. Запускайте опрос с паузой между запросами и сохраняйте последний offset на стороне своего бота.
