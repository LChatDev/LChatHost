# Install the LChat server on Windows

These steps target Windows 10/11 using free [XAMPP](https://www.apachefriends.org/faq_windows), which includes Apache, PHP and MariaDB. A public long-running service needs additional server hardening and updates. GitHub and GitHub Pages do not run PHP for you.

## 1. Install components

1. Install XAMPP to `C:\xampp` and [Git for Windows](https://git-scm.com/install/windows). In XAMPP Control Panel start **Apache** and **MySQL** (MariaDB). If a port is occupied, check XAMPP logs and configure a free port.
2. Check the PHP included with XAMPP in Command Prompt, running each command separately:

   ```cmd
   C:\xampp\php\php.exe -v
   C:\xampp\php\php.exe -m
   ```

   PHP 8.2+ and `pdo_mysql`, `mbstring`, `fileinfo`, `curl` and `gd` are required. If needed, edit `C:\xampp\php\php.ini` and restart Apache. For attachments up to 8 MB, set `upload_max_filesize = 8M` and `post_max_size = 10M` in PHP on both the backend and web gateway. The `php` in PATH may be a different installation.

3. Choose **one** project and clone it to `C:\xampp\htdocs\lchat`:

   ```cmd
   git clone https://github.com/LChatDev/LChatHost.git C:\xampp\htdocs\lchat
   ```

   For a standalone site, use `git clone https://github.com/LChatDev/LChat.git C:\xampp\htdocs\lchat` instead, or use the Russian `https://github.com/LChatDev/LChatRU.git`. Back up an existing folder and choose another path; `git clone` does not replace existing files. LChatHost contains only the backend and needs a separate [LChatWeb](https://github.com/LChatDev/LChatWeb).

## 2. Configure Apache for one directory

Enable `mod_rewrite` in `C:\xampp\apache\conf\httpd.conf` (remove `#` from `LoadModule rewrite_module modules/mod_rewrite.so`). Add a dedicated local listener and virtual host; the path must match the project directory:

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

Keep hidden `.htaccess` files, restart Apache and check `http://127.0.0.1:8365/`. LChatHost's root may return 403 because it has no webpages. Confirm `http://127.0.0.1:8365/config.php` and files in `data/`, `files/`, `apis/` cannot be accessed directly. If they are accessible, fix Apache before publishing. For startup errors check the Apache log, configuration and port use with `netstat -ano`.

Tunnel **port 8365**, not the entire default XAMPP server on port 80, which may expose other projects and administrative tools. PHP's `php -S` server ignores `.htaccess` and is unsuitable for this deployment.

## 3. Create the database and config

1. Open phpMyAdmin **locally** through XAMPP; create database `lchat` and a separate user with access to it. Do not publish phpMyAdmin or MySQL on the internet. For an existing database use its actual settings.
2. Copy the project's `config.example.php` to `config.php`. Set the database host, port, name, username and password. The usual port is `3306`; if your MariaDB listens on `33060`, use `33060`. Make sure the database user can connect from the chosen host.
3. Generate a random `install_key` of at least 32 characters, for example:

   ```cmd
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Keep keys and `config.php` private. Set `public_url` to the public HTTPS site URL. For LChatHost, also generate a **different** random `proxy_secret` and enter the same value in LChatWeb's private config. LChat/LChatRU use `initial_admin_username` for the first owner; in LChatHost the initial username is `leosmleol`.

## 4. HTTPS, installation and calls

1. Expose the dedicated local web port through an HTTPS tunnel or a reverse proxy with a trusted certificate. See [free tools and controls](PORT_TOOLS_EN.md). For LChatHost, configure the backend HTTPS URL and matching `proxy_secret` in LChatWeb. If website and backend run on different hosts, the web host must be able to reach the backend.
2. For standalone LChat/LChatRU open `https://your-site/install.php`. For LChatHost open `install.php` **on LChatWeb**. Enter `install_key`, create tables and register the owner. Afterwards remove `install.php` from the published installations and clear the key on the backend.
3. The example config uses the main chat's TURN and `relay` mode: set the TURN secret in the backend's private `config.php` or `LCHAT_TURN_SECRET`. Calls in relay mode cannot connect without it. Use HTTPS, allow microphone access and test with two devices on different networks. TURN needs a reachable listener and UDP relay port range; a website tunnel alone does not provide it.
4. Keep Apache, MariaDB and the tunnel running. Back up the database, private `config.php`, `data/` and `files/`, and preserve them during updates. This guide has not been tested as a deployment on a real hosting provider.

More: [LChatHost](https://github.com/LChatDev/LChatHost/blob/main/doc_EN.md), [LChatWeb](https://github.com/LChatDev/LChatWeb/blob/main/doc_EN.md), [calls and TURN](https://github.com/LChatDev/LChat/blob/main/CALLS.md).

### If LChatWeb also runs on this PC

Clone `https://github.com/LChatDev/LChatWeb.git` into a separate folder such as `C:\xampp\htdocs\lchat-web`. Add a second `Listen 127.0.0.1:8366` and a separate `<VirtualHost 127.0.0.1:8366>` following the example above, using `C:/xampp/htdocs/lchat-web` for `DocumentRoot` and `<Directory>`. Restart Apache. Copy the LChatWeb config and set `backend.url` to a separate trusted HTTPS LChatHost address and `backend.proxy_secret` to the same secret as the backend. Route the website's public HTTPS address to local port 8366 and the backend HTTPS address to 8365; the website host must reach both addresses. For example, two Cloudflare Tunnel routes can map two hostnames to different local ports. Do not use plain `http://127.0.0.1:8365` for `backend.url`: the gateway requires verified HTTPS.
