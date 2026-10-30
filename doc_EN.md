# Hosting LChatHost

Choose where the website requests are processed:

- **Hosting server** (`host`): PHP, database, files and call signaling run on the website host.
- **Your PC** (`pc`): the host serves the interface and forwards application requests to your PC's PHP server over HTTPS. The database, files and call signaling remain on the PC. Visitors use the website without installing a separate connection app.

The two modes do not synchronize databases. Back up the database, `config.php`, `data/` and `files/` before switching: accounts and messages come from the selected server. In `pc` mode requests fail while the PC is offline. GitHub stores source code; it does not run PHP or MySQL.

## 1. Prepare the website

Prepare PHP 8.2+, Apache with `.htaccess` and `mod_rewrite`, the `pdo_mysql`, `mbstring`, `fileinfo`, `curl` and `gd` extensions, a domain and trusted HTTPS. Upload the web files to one directory, including hidden `.htaccess`, and copy `config.example.php` to `config.php`. Keep the config private. On the server that stores files, `data/` and `files/` must be writable by PHP and protected by Apache rules.

## 2. Choose the hosting server

In the site's private `config.php` set:

```php
'backend' => ['mode' => 'host', 'url' => ''],
```

Create a MySQL/MariaDB database and user, configure `database` and set the public HTTPS site address in `public_url`. Set a unique random `install_key` of at least 32 characters. Open `https://your-domain/install.php`, enter the key and create tables. Register `leosmleol`, the initial owner in this edition. Remove public `install.php` and clear `install_key` afterwards. An absent `backend` setting defaults to `host`.

## 3. Choose the PC backend

1. Install PHP, Apache and MySQL/MariaDB on the PC. Deploy the **same LChatHost files and version**; verify `.htaccess` protects private files. Configure the PC database, install tables and create the owner as above. Keep the PC's own config at `'backend' => ['mode' => 'host', 'url' => '']` to avoid a proxy loop.
2. Make the PC PHP server accessible to the website host through a separate **HTTPS URL with a trusted certificate**, for example `https://backend.example.com/`. An appropriate HTTPS tunnel can be used. The gateway rejects HTTP and self-signed certificates. Do not expose the database port to the internet. Include any backend subdirectory in the URL.
3. In the **website host's** config select the PC:

   ```php
   'backend' => ['mode' => 'pc', 'url' => 'https://backend.example.com/'],
   ```

   Replace the address. The website host does not need an application database in this mode; PHP `curl` is required. The PC database and `data/` and `files/` must be accessible to PHP. The gateway supports regular GET/POST requests and uploads up to 10 MB; the application's attachment limit is 8 MB.
4. In the **PC** config set `public_url` to the public website address and leave `session_domain` empty. Start the PC and tunnel and open the public HTTPS website. Test registration, login, file upload and a call from two devices.

The PC's public HTTPS backend address is also reachable directly: protect the server and follow the application's access controls. Keep configs and tunnel tokens secret. Remove the installer on the PC after setup. The proxy is not a TURN server; calls across networks may need a separate TURN service with reachable UDP relay ports. Configure `turn` and `call_transport` in the **PC** config.

## Updates and troubleshooting

Update PHP code and assets on the website host and PC together. Preserve private `config.php` and user data. A 502 in PC mode means the website host could not reach the PC HTTPS server: check the URL, PC, certificate, tunnel, outbound connections and PHP logs. See the [tunnel tools guide](https://github.com/leosmleol-svg/LChat/blob/main/PORT_TOOLS.md) and [calling guide](https://github.com/leosmleol-svg/LChat/blob/main/CALLS.md). License and UI-source attribution remain in this repository.
