# Install LChatHost on REG.RU or another PHP host

This repository contains the Russian-language PHP site. The login footer and menu link to its [source code](https://github.com/leosmleol-svg/LChatHost). Publishing here does not automatically update a deployed site.

1. Prepare PHP 8.2+, MySQL/MariaDB, the `pdo_mysql`, `mbstring`, `fileinfo`, `curl` and `gd` extensions, and Apache with `.htaccess` and `mod_rewrite`. Attach a domain and enable a trusted HTTPS certificate.
2. Create a database and a user with access to it. Upload the site files to one document root with `index.php`, `apis/`, `assets/`, `data/` and `files/` together. Keep hidden `.htaccess` files and allow PHP to write to `data/` and `files/`. Verify the host applies the Apache protection rules.
3. Copy `config.example.php` to `config.php`. Set the DB DSN, host, port, database name, username, password and HTTPS `public_url`. Set a unique random `install_key` of at least 32 characters. The DB port depends on the provider; `3306` is common. Never publish your config.
4. Open `https://your-domain/install.php`, enter the key in the form and create the tables. Register `leosmleol`: this version assigns that account as the initial owner. Remove `install.php` from the server and clear `install_key` afterwards.
5. Allow microphone access and configure TURN when needed. The PHP host does not run TURN itself; TURN needs a reachable listener and UDP relay port range. [Detailed calling guide](https://github.com/leosmleol-svg/LChat/blob/main/CALLS.md).

When updating, back up and preserve your existing `config.php`, database, `data/` and `files/`. To add the source link to an existing site, upload this project's updated `index.php` after checking compatibility with your other deployed files.

[Free hosting](https://github.com/leosmleol-svg/LChat/blob/main/FREE_HOSTING.md) · [Tunnel setup and controls](https://github.com/leosmleol-svg/LChat/blob/main/PORT_TOOLS.md) · [Bot API in Russian](BOT_API.md). Free-service terms can change. GitHub Pages alone cannot run PHP and MySQL.
