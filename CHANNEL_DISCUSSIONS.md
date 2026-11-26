# Channel comments and discussion groups

Channel owners can link a group they own, or create a new discussion group from channel info → Discussion. A group belongs to one channel at a time. Each post has a comments button and a live count. Comments are actual group messages; replying to a comment inside that group also adds the reply to the original post thread.

Reading requires channel membership. Sending a first comment joins its author to the discussion group and grants access to its ordinary history: consider this before linking a private group. Authors delete their own comments; channel/group owners moderate all comments. Unlinking preserves group messages. Deleting a post removes its thread mapping but preserves the messages in the group. Switching groups does not expose the former group's thread in the new group.

PHP/MySQL or SQLite; two additive tables, automatic migration. Existing server-side message encryption is reused if configured; this is not end-to-end encryption. API actions: discussion.configure, discussion.messages, discussion.send, discussion.delete. Existing session authentication and CSRF apply. No configuration files or user data are replaced.

Tests cover roles, thread isolation, encryption roundtrip, idempotency, pagination, deletion, group replies, unlink/relink, CSRF and mobile/desktop browser flows. LChatWeb uses its existing backend proxy; deploy the matching backend update too. APK binaries are unchanged.

---

# Комментарии и группы обсуждений LChat

Это ZIP обновления для существующего PHP-сайта LChat на REG.RU. Он содержит только изменённые файлы; config.php, ключи шифрования, база, загрузки пользователей и APK не включены и не заменяются.

## Установка

1. Сохраните резервную копию сайта и базы данных.
2. Загрузите содержимое архива в каталог сайта /www/chat.lbs.su с заменой файлов и сохранением структуры папок. Этот архив предназначен для версии сайта LChat от 26 ноября 2026 года, а не для установки с нуля.
3. Откройте сайт: две дополнительные таблицы создаются автоматически. Существующие сообщения не мигрируются и не удаляются.
4. Обновите страницу, чтобы загрузить новые JS и CSS.

## Как включить

Откройте свой канал → нажмите на его название → «Обсуждение». Выберите свою группу и нажмите «Подключить группу» либо «Создать группу обсуждений». Одна группа подключается к одному каналу. Для управления связкой нужно владеть и каналом, и выбранной группой.

Под каждой публикацией появляется кнопка комментариев со счётчиком. В ней доступны отправка, история с загрузкой предыдущих комментариев и удаление. Свои комментарии удаляет автор; любые комментарии — владелец канала или группы.

Комментарий — настоящее сообщение в группе. Ответ на него через функцию «Ответить» в группе также попадает в обсуждение исходной публикации. Для чтения ветки нужна подписка на канал. При первой отправке комментария пользователь вступает в группу и получает доступ к её обычной истории; учитывайте это при подключении существующей закрытой группы.

При отключении обсуждения сообщения остаются в группе. При подключении другой группы старые комментарии не показываются в новой группе. При удалении публикации её ветка комментариев становится недоступна; сами сообщения сохраняются в группе.

Текст использует существующее серверное шифрование LChat, если оно настроено. Это не сквозное шифрование. Новые API-действия: discussion.configure, discussion.messages, discussion.send, discussion.delete; авторизация и CSRF соответствуют существующему API.

Проверено: права владельца/подписчика/постороннего, изоляция публикаций, повторная отправка с тем же nonce, пагинация, удаление, отключение и подключение группы, ответы из группы, шифрование, CSRF, мобильный и настольный интерфейс.
