# Установка сервера LChat на Windows

Эта инструкция подходит для Windows 10/11 и бесплатного [XAMPP](https://www.apachefriends.org/faq_windows). Он включает Apache, PHP и MariaDB. Для постоянного публичного сервиса требуется дополнительно защитить сервер и следить за обновлениями. GitHub и GitHub Pages сами PHP не запускают.

## 1. Установите компоненты

1. Установите XAMPP в `C:\xampp` и [Git для Windows](https://git-scm.com/install/windows). В XAMPP Control Panel запустите **Apache** и **MySQL** (это MariaDB). Если порт занят, проверьте журнал XAMPP и настройте порт.
2. Проверьте именно PHP из XAMPP в командной строке. Каждую команду выполняйте отдельно:

   ```cmd
   C:\xampp\php\php.exe -v
   C:\xampp\php\php.exe -m
   ```

   Нужны PHP 8.2+ и расширения `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `gd`. Если расширение отсутствует, настройте `C:\xampp\php\php.ini` и перезапустите Apache. Для вложений до 8 МБ задайте в PHP `upload_max_filesize = 8M` и `post_max_size = 10M` как на backend, так и на веб-шлюзе. Версия `php` из PATH может быть другой.

3. Выберите **одну** копию и загрузите её в папку `C:\xampp\htdocs\lchat`:

   ```cmd
   git clone https://github.com/LChatDev/LChatHost.git C:\xampp\htdocs\lchat
   ```

   Для самостоятельного сайта вместо этой команды используйте `git clone https://github.com/LChatDev/LChatRU.git C:\xampp\htdocs\lchat` или английский `https://github.com/LChatDev/LChat.git`. Если папка уже существует, сначала сохраните её и выберите другой путь: `git clone` не заменяет существующие файлы. LChatHost — только серверная часть; к нему нужен отдельный [LChatWeb](https://github.com/LChatDev/LChatWeb).

## 2. Настройте Apache для одной папки

Включите `mod_rewrite` в `C:\xampp\apache\conf\httpd.conf` (строка `LoadModule rewrite_module modules/mod_rewrite.so` без `#`). Добавьте в конфигурацию Apache отдельный локальный порт и виртуальный хост; путь должен совпадать с папкой проекта:

```apache
Listen 127.0.0.1:8365
<VirtualHost 127.0.0.1:8365>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs/lchat"
    <Directory "C:/xampp/htdocs/lchat">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Сохраните скрытые `.htaccess`, перезапустите Apache и проверьте `http://127.0.0.1:8365/`. Для LChatHost корень может вернуть 403: он не содержит веб-страниц. Проверьте, что `http://127.0.0.1:8365/config.php` и файлы в `data/`, `files/`, `apis/` недоступны напрямую. Если они доступны, исправьте Apache и не публикуйте сервер. При ошибке запуска проверьте конфигурацию, журнал Apache и занятость порта `netstat -ano`.

Публикуйте туннелем **порт 8365**, а не весь стандартный XAMPP на порту 80: иначе могут стать доступны другие проекты и средства администрирования. PHP-сервер `php -S` не применяет `.htaccess` и для такого размещения не подходит.

## 3. Создайте базу и конфиг

1. Откройте phpMyAdmin **локально** через XAMPP, создайте базу `lchat` и отдельного пользователя с правами на неё. Не публикуйте phpMyAdmin и MySQL в интернете. Для существующей базы используйте её реальные параметры.
2. Скопируйте `config.example.php` в `config.php` внутри проекта. В `database` укажите хост, порт, имя базы, логин и пароль. Обычно порт `3306`, но если ваша MariaDB слушает `33060`, укажите `33060`. Убедитесь, что пользователь базы может подключаться с выбранного хоста.
3. Задайте случайный `install_key` от 32 символов. Например, выполните локально:

   ```cmd
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Храните ключи и `config.php` закрыто. `public_url` — публичный HTTPS-адрес сайта. Для LChatHost дополнительно задайте `proxy_secret`: создайте **другой** случайный ключ и укажите его также в закрытом конфиге LChatWeb. Для LChat/LChatRU первоначальный администратор задаётся через `initial_admin_username`; в LChatHost первоначальный логин — `leosmleol`.

## 4. HTTPS, установка и звонки

1. Дайте доступ к локальному веб-порту через HTTPS-туннель или свой обратный прокси с доверенным сертификатом. См. [бесплатные программы и управление](PORT_TOOLS_RU.md). Для LChatHost в конфиге LChatWeb укажите HTTPS-адрес backend и тот же `proxy_secret`. Если сайт и backend на разных хостах, хост сайта должен иметь возможность подключиться к backend.
2. Для самостоятельного LChat/LChatRU откройте `https://ваш-сайт/install.php`. Для LChatHost откройте `install.php` **на LChatWeb**. Введите `install_key`, создайте таблицы и зарегистрируйте владельца. После установки удалите `install.php` из опубликованных копий и очистите ключ в конфиге backend.
3. Пример конфига использует TURN основного чата и режим `relay`: укажите секрет TURN в закрытом `config.php` серверной части или переменной `LCHAT_TURN_SECRET`. Без него звонки в этом режиме не подключатся. Сайт должен работать по HTTPS; разрешите микрофон и проверьте звонок с двух устройств в разных сетях. Для TURN нужны доступные порт подключения и UDP-диапазон relay: один туннель сайта этого не обеспечивает.
4. Оставьте Apache, MariaDB и туннель запущенными. Делайте резервные копии базы, приватного `config.php`, `data/` и `files/`; не заменяйте их при обновлении. Эта инструкция не проверялась как развёртывание на реальном хостинге.

Подробности: [LChatHost](https://github.com/LChatDev/LChatHost/blob/main/doc_RU.md), [LChatWeb](https://github.com/LChatDev/LChatWeb/blob/main/doc_RU.md), [звонки и TURN](https://github.com/LChatDev/LChatRU/blob/main/CALLS.md).

### Если LChatWeb тоже на этом ПК

Клонируйте `https://github.com/LChatDev/LChatWeb.git` в отдельную папку, например `C:\xampp\htdocs\lchat-web`. Добавьте в Apache второй `Listen 127.0.0.1:8366` и отдельный `<VirtualHost 127.0.0.1:8366>` по образцу выше, указав для `DocumentRoot` и `<Directory>` папку `C:/xampp/htdocs/lchat-web`. Перезапустите Apache. Скопируйте конфиг LChatWeb и задайте в `backend.url` отдельный доверенный HTTPS-адрес LChatHost, а в `backend.proxy_secret` — тот же секрет, что на backend. Публичный HTTPS сайта направьте на локальный порт 8366, а HTTPS backend — на 8365; хост сайта должен иметь доступ к обоим адресам. Например, два маршрута Cloudflare Tunnel могут направлять два доменных имени на разные локальные порты. Не указывайте обычный `http://127.0.0.1:8365` в `backend.url`: шлюз требует проверяемый HTTPS.
