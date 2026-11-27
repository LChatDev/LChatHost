# LChat productivity update

All six features: anonymous single/multiple-choice polls with deadlines and results; private bookmarks and in-app reminders; membership-scoped search by text, author, UTC calendar dates, attachment type and chat; personal folders and pinned chats; granular group moderation permissions, temporary send restrictions and action logs; server-side scheduled text messages with cancellation and delivery status.

Configure CLI cron every minute: `php /absolute/path/to/site/scheduler.php`. The HTTP endpoint intentionally returns 404. Active-site sync is a fallback, not a replacement for cron when no visitors are online. Scheduled delivery rechecks membership, bans, channel rights and restrictions; parallel workers claim tasks atomically. Local browser time is converted to UTC. Reminders appear while using the site or at next sign-in; they are not offline push notifications.

The migration adds tables and indexes without modifying existing messages. Existing encryption covers messages, poll questions/options and queued text when configured; this is server-side storage encryption, not E2EE. Search scans bounded batches of decrypted history: use Continue to scan older batches. One personal folder per chat, at most 20 folders and 100 pending scheduled messages per account. Scheduling supports text only. New UI lives in the web client; provided APK binaries were not rebuilt.

LChatWeb must be deployed with a matching LChatHost backend update. Keep config.php, keys and user data private. Tests cover API roles/privacy/encryption, poll lifecycle, filters, folders, moderation restrictions, scheduling concurrency and real desktop/mobile browser flows. Russian installation and usage details follow.

---

# LChat — опросы, закладки, поиск, папки, администраторы и планировщик

ZIP обновления существующего PHP-сайта LChat (состояние от 27 ноября 2026). Это не APK и не установка с нуля. config.php, ключи шифрования, база данных, пользовательские файлы и токены в архив не включены.

## Установка на REG.RU

1. Сделайте резервную копию сайта и базы.
2. Загрузите файлы из архива в /www/chat.lbs.su с заменой, сохранив структуру папок. Текущий config.php сохраняется.
3. Откройте сайт. Дополнительные таблицы и индексы создадутся автоматически; существующая переписка не изменяется.
4. Добавьте задачу cron **каждую минуту**: `php /АБСОЛЮТНЫЙ/ПУТЬ/К/САЙТУ/scheduler.php`. На основном REG.RU абсолютный путь: `/var/www/u3607042/data/www/chat.lbs.su/scheduler.php`. Проверьте путь к PHP в панели своего хостинга.
5. Обновите страницу. В списке чатов и в шапке открытого чата есть кнопка «Функции».

Планировщик работает в CLI; через HTTP scheduler.php возвращает 404. Каждая задача берётся атомарно, поэтому параллельные запуски не создают дубликаты сообщения. При отсутствии cron очередь также обрабатывается при синхронизации сайта, но для отправки без посетителей cron обязателен. С точностью до минуты, а не до секунды. Вызов отправки проверяет актуальное членство, бан, право публикации и временные ограничения; при запрете задача получает статус ошибки.

## Возможности

- **Опросы:** «Функции» → «Создать опрос». 2–10 уникальных вариантов, один или несколько выборов, необязательный срок закрытия, анонимные результаты, изменение/отмена собственного голоса. Создатель и модератор могут закрыть опрос. В канале создавать опрос может владелец, голосовать — подписчики. Опубликованный вопрос и варианты не редактируются; можно удалить и создать новый опрос.
- **Закладки:** меню сообщения по ПКМ или долгому нажатию → «В закладки». Личный список доступен через «Функции». «Напомнить позже» сохраняет дату; напоминание показывается на открытом сайте или при следующем входе и остаётся до подтверждения. Это не push-уведомление при закрытом браузере.
- **Поиск:** «Расширенный поиск» — по тексту и имени файла, чату, автору, диапазону дат и виду вложения. Календарные даты фильтруются в UTC. Поиск читает и расшифровывает ограниченную часть истории за запрос; «Искать дальше» продолжает сканирование, в том числе когда в предыдущей части совпадений не было. Для старых сообщений кнопка «Открыть» загружает область истории с нужным ID. Результаты ограничены текущим членством в чатах.
- **Папки и закрепление:** до 20 личных папок. «Закрепить / папка» в функциях открытого чата; один чат относится к одной личной папке и независимо может быть закреплён. Удаление папки сохраняет чаты. Закреплённые чаты выводятся первыми. Состояние хранится на сервере для аккаунта.
- **Группы:** «Управление группой». Владелец выдаёт отдельные права: удалять сообщения/закрывать опросы, менять название/ссылку, исключать обычных участников, временно запрещать отправку. Назначать администраторов, менять/исключать других администраторов может только владелец. Удаление сообщения, изменения прав, ограничения, исключения и изменения группы записываются в журнал. Ограничение истекает автоматически; оно распространяется на текст, файлы, опросы и отложенную отправку, а также отправку через Bot API. Владелец защищён от исключения и ограничения.
- **Планировщик:** «Отправить позже» у строки ввода или «Запланировать отправку» в функциях. Поддерживается текст; вложения не планируются. До 100 ожидающих сообщений на аккаунт, срок до года. В личной очереди можно отменить ожидающее сообщение и увидеть доставку/ошибку. Ввод времени — в местном часовом поясе браузера, хранение — в UTC.

## Данные и проверки

Новые таблицы: chat_polls, poll_options, poll_votes, message_bookmarks, chat_folders, chat_organizer, group_grants, group_limits, group_action_log, scheduled_messages. Основная версия схемы не меняется; отдельный маркер productivity_v1 позволяет безопасно повторять установку. При включённом существующем шифровании зашифрованы текст сообщений, вопросы, варианты опросов и текст очереди. Это серверное шифрование хранения, не E2EE.

API-действия находятся в apis/productivity.php с префиксом tools.: poll.create/vote/close, bookmark.set/remove, bookmarks, reminder.ack, search, folders, folder.create/delete, organize, group.info/log/grant/restrict, schedule.create/cancel, scheduled. Используются существующие сессии и CSRF для изменений. Авторизация перепроверяется при каждом чтении и действии.

Проверены PHP/JS-синтаксис, сценарии API с владельцем/участником/посторонним, личные данные, шифрование, голоса и срок закрытия, фильтры поиска, отмена/доставка/ошибка отложенного сообщения, конкурирующие CLI-обработчики, права и ограничения, мобильный/настольный интерфейс. Дополнительно пройдены прежние проверки каналов и комментариев.

Существующие APK не пересобирались. Новые интерфейсы находятся в веб-клиенте; для отдельного нативного клиента нужны его исходники и обновление клиента.

## Откат

Восстановите заменённые файлы из резервной копии и удалите только добавленную задачу cron. Новые таблицы можно оставить: старый код их игнорирует. Не удаляйте таблицы сообщений и ключ шифрования. Откат не отменяет уже отправленные сообщения; перед откатом остановите задачу cron, чтобы она не отправляла ожидающую очередь.
