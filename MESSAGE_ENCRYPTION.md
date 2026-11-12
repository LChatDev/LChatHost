## Шифрование сообщений в базе

Сервер может хранить тексты и очередь Bot API зашифрованными с AES-256-GCM. Для включения установите PHP OpenSSL и mbstring. На MySQL используйте MEDIUMTEXT для messages.body и max_allowed_packet не менее 16 МиБ.

Создайте файл с 32 случайными байтами в hex за пределами веб-каталога. Например, с помощью PHP:

~~~sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
~~~

Сохраните результат в приватный файл, доступный пользователю PHP, и укажите его абсолютный путь в `message_key_file` в config.php или в переменной `LCHAT_MESSAGE_KEY_FILE`. Используйте один ключ для всех процессов этого сервера. Храните резервную копию ключа отдельно от БД.

Запустите из каталога сервера `php tools/encrypt-messages.php`, затем `php tools/encrypt-messages.php --check`. Проверка должна показать ноль старых записей. Старые копии БД могут содержать открытый текст. Сервер расшифровывает сообщения для разрешённых функций приложения. Шифрование относится к тексту сообщений и очереди ботов.

## Message encryption at rest

The backend supports AES-256-GCM for message bodies and the Bot API queue. Enable PHP OpenSSL and mbstring. On MySQL, use MEDIUMTEXT for messages.body and at least 16 MiB for max_allowed_packet.

Create a key file with 32 random bytes encoded as hex, outside the document root. For example, generate the value with PHP:

~~~sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
~~~

Store the result in a private file readable by PHP. Set its absolute path with `message_key_file` in config.php or the `LCHAT_MESSAGE_KEY_FILE` environment variable. Use the same key for all backend processes. Back it up separately from the database.

From the backend directory, run `php tools/encrypt-messages.php` followed by `php tools/encrypt-messages.php --check`. The check should report zero legacy rows. Older database backups may still contain plaintext. The backend decrypts messages for authorized application features. Encryption covers message bodies and the bot queue.
