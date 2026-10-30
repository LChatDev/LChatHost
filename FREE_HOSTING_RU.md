# Бесплатное размещение всего LChat

Ниже Apache/PHP, MariaDB и coturn размещаются на одной виртуальной машине [Oracle Cloud Always Free](https://www.oracle.com/cloud/free/). **Это возможный бесплатный вариант, но доступность и бесплатность не гарантированы.** Oracle обычно требует подтверждение личности подходящей банковской картой; свободных машин может не быть, неактивные машины могут изыматься. Выбирайте только ресурсы с пометкой **Always Free**, соблюдайте квоты и проверяйте [условия](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier.htm). Пробный период и Always Free — разные вещи. Свой домен может быть платным; подойдёт и бесплатное DNS-имя с управляемыми записями, если оно у вас есть.

В этом примере ваш ПК для работы сайта и TURN не нужен, посетителям достаточно ссылки. Инструкция не проверена на реальном аккаунте Oracle.

## 1. Сервер и DNS

1. Зарегистрируйтесь в Oracle Cloud Free Tier, выберите доступный регион и пройдите проверку. Условия и карты — в [FAQ Oracle](https://www.oracle.com/cloud/free/faq/).
2. Создайте Ubuntu VM в **публичной подсети** с публичным IPv4. Тип машины и загрузочный диск должны быть Always Free и укладываться в квоту. Сохраните закрытый SSH-ключ. Если свободной ёмкости нет, не выбирайте платный тариф без проверки цены.
3. В DNS создайте `A`-записи `chat.example.com` и `turn.example.com` на публичный IPv4 машины. В примерах ниже замените эти имена своими. Дождитесь обновления DNS.
4. В правилах OCI security list или network security group разрешите входящий TCP `22` только со своего IP для SSH, TCP `80` и `443` для сайта, TCP и UDP `3478` для TURN и UDP `49160–49200` для relay. Разрешите то же в брандмауэре ОС. Сохраните правило SSH и проверьте доступ до изменения правил. Сетевые правила OCI и брандмауэр ОС независимы: открыть только один недостаточно.

## 2. Установка веб-сервера

Подключитесь к VM по инструкции Oracle для SSH и выполните в Ubuntu:

```sh
sudo apt update
sudo apt install apache2 libapache2-mod-php php-mysql php-mbstring php-curl php-gd php-xml mariadb-server git rsync certbot python3-certbot-apache coturn
sudo a2enmod rewrite
sudo systemctl enable --now apache2 mariadb
```

В других дистрибутивах имена пакетов и служб могут отличаться. Проверьте `php -v` (нужен PHP 8.2+) и наличие `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `gd` у веб-версии PHP.

Скачайте код **за пределами** публичной папки и скопируйте веб-файлы без `.git`:

```sh
sudo git clone https://github.com/LChatDev/LChatRU.git /opt/lchat-source
sudo mkdir -p /var/www/lchat
sudo rsync -a --exclude='.git/' --exclude='android/' --exclude='tools/' /opt/lchat-source/ /var/www/lchat/
sudo cp /var/www/lchat/config.example.php /var/www/lchat/config.php
sudo chown -R root:root /var/www/lchat
sudo chown -R www-data:www-data /var/www/lchat/data /var/www/lchat/files
sudo chown root:www-data /var/www/lchat/config.php
sudo chmod 640 /var/www/lchat/config.php
```

Если ботам нужны запланированные повторы вебхуков, дополнительно скопируйте `tools/bot-webhook-worker.php` и `tools/.htaccess` и настройте планировщик по [BOT_API.md](https://github.com/LChatDev/LChatRU/blob/main/BOT_API.md). Для обычной работы сайта папка `tools/` не требуется.

Создайте `/etc/apache2/sites-available/lchat.conf`, указав свой домен:

```apache
<VirtualHost *:80>
    ServerName chat.example.com
    DocumentRoot /var/www/lchat
    <Directory /var/www/lchat>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Включите сайт и выпустите бесплатный HTTPS-сертификат. Домен уже должен указывать на VM, TCP 80 должен быть доступен, а домен — принадлежать вам:

```sh
sudo a2ensite lchat.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
sudo certbot --apache -d chat.example.com
sudo certbot renew --dry-run
```

Проверьте HTTPS без предупреждений. Certbot настраивает Apache и продление сертификата; следуйте его вопросам. Без DNS-имени и работающего сертификата браузер не выдаст микрофон удалённому сайту.

## 3. База и конфиг

Откройте консоль командой `sudo mariadb`. Следующие команды выполняйте **внутри MariaDB**. Замените пример пароля надёжным уникальным паролем:

```sql
CREATE DATABASE lchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lchat'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON lchat.* TO 'lchat'@'localhost';
EXIT;
```

Отредактируйте `/var/www/lchat/config.php` от root, сохраняя остальные настройки. Укажите тот же пароль и замените примеры:

```php
'database' => [
    'dsn' => 'mysql:host=localhost;port=3306;dbname=lchat;charset=utf8mb4',
    'username' => 'lchat',
    'password' => 'REPLACE_WITH_A_STRONG_DB_PASSWORD',
],
'site_name' => 'Мой мессенджер',
'initial_admin_username' => 'myadmin',
'public_url' => 'https://chat.example.com',
'install_key' => 'REPLACE_WITH_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS',
```

Ключ установки можно создать командой `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Откройте `https://chat.example.com/install.php`, введите ключ и создайте таблицы. Сразу зарегистрируйте логин из `initial_admin_username`, войдите и откройте `admin.php`. После установки удалите публичный `install.php` и очистите ключ в конфиге. Не публикуйте конфиг и пароль базы в Git.

## 4. TURN на той же VM

Следуйте [CALLS.md](CALLS_RU.md): создайте отдельный секрет TURN; настройте coturn с `use-auth-secret`, `static-auth-secret`, realm и диапазоном `49160–49200`; запустите coturn и укажите совпадающие адрес и секрет в админке или закрытом конфиге LChat. VM OCI часто находятся за NAT: проверьте публичный и внутренний адреса, настройте `external-ip` coturn и убедитесь, что публичный UDP-диапазон попадает на тот же локальный диапазон. Без прямого публичного IPv4 схема требует изменения.

Проверьте двух клиентов из разных сетей. Временно включите режим только TURN для проверки relay, затем при желании верните прямое соединение и TURN. Бесплатная VM всё равно требует доступных портов, HTTPS, совпадающих секретов и достаточных ресурсов; работа звонков во всех сетях не гарантируется.

## 5. Обслуживание

- Храните резервные копии базы, `config.php`, `data/` и `files/` в закрытом месте. Следите за квотами; изъятие неактивной VM или изменение условий могут прервать работу.
- Устанавливайте обновления безопасности Apache, PHP, MariaDB и coturn, проверяйте журналы и продление сертификата.
- Перед обновлением создайте копию, обновите исходники в `/opt/lchat-source` и копируйте веб-файлы, исключив `config.php`, `data/`, `files/` и `.git/`. Подробнее — [HOSTING.md](https://github.com/LChatDev/LChatRU/blob/main/HOSTING.md).
- Если VM недоступна, [InfinityFree](https://www.infinityfree.com/) предлагает бесплатный PHP/MySQL/SSL-хостинг. Проверьте расширения, `.htaccess`, доступ API и ботов, лимиты и хранение; TURN потребуется отдельно. С этим проектом хостинг не проверен. GitHub Pages сам по себе не запускает PHP и MySQL.

Другие способы подключения сайта на ПК описаны в [TUNNELS.md](https://github.com/LChatDev/LChatRU/blob/main/TUNNELS.md). Такой сайт работает только при включённом ПК и интернете; Cloudflare Tunnel для сайта не заменяет TURN.
