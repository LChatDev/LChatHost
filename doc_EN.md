# LChatHost: backend

LChat now consists of two projects: [LChatWeb](https://github.com/leosmleol-svg/LChatWeb) serves the pages, styles and JavaScript; **LChatHost** processes requests, sessions, messages, files and call signaling. Both projects are needed. WebRTC media travels between participants or through a separately configured TURN server.

## 1. Deploy the backend

Install LChatHost on your hosting server or a PC reachable over HTTPS. It requires PHP 8.2+, Apache with `.htaccess` and `mod_rewrite`, MySQL/MariaDB, and `pdo_mysql`, `mbstring`, `fileinfo`, `curl` and `gd`. Use a trusted HTTPS certificate: LChatWeb verifies it and rejects plain HTTP and self-signed certificates. Preserve `.htaccess`, prevent direct access to `config.php`, `apis/`, `data/` and `files/`, and give PHP write access to `data/` and `files/`. Confirm Apache applies these rules.

Copy `config.example.php` to `config.php`; configure your database and user, a unique random `install_key` of at least 32 characters, and `public_url` as the public HTTPS address of **LChatWeb**. Generate a separate random `proxy_secret` (for example, with `php -r "echo bin2hex(random_bytes(32));"`); LChatWeb will need the same secret. Usually leave `session_domain` empty. Keep the config private. For a PC backend, do not expose the database port to the internet; an appropriate HTTPS tunnel can expose the PC web server.

## 2. Deploy the website

Upload [LChatWeb](https://github.com/leosmleol-svg/LChatWeb) to a separate website directory on a host with PHP `curl` and Apache `mod_rewrite`. Copy its `config.example.php` to `config.php` and set the trusted HTTPS LChatHost address:

```php
'backend' => ['mode' => 'pc', 'url' => 'https://backend.example.com/', 'proxy_secret' => 'YOUR_SHARED_RANDOM_SECRET'],
```

Replace the example with the **same** `proxy_secret` configured on LChatHost: use at least 32 characters without whitespace. The gateway sends it only to the backend over HTTPS, not to visitors. `pc` is the gateway mode name: the backend may run on your PC or another provider. If website and backend use the same machine, use a separate HTTPS domain or directory with correct access rules. Include a backend subdirectory in the URL if necessary. Check that the web host can make outbound HTTPS requests to the backend. Never upload private configs or data to GitHub.

## 3. Install and test

Open the public LChatWeb `https://your-site/install.php` and enter the **LChatHost** installation key; tables are created on LChatHost. Register `leosmleol`, the initial owner in this edition. Remove `install.php` from both installations and clear `install_key` on LChatHost afterwards. Test login, messages, attachments and calls from two devices. The admin page is served by LChatWeb; authorization and actions are checked on LChatHost. The owner can change the site name, registration, call limit and TURN settings in its settings section. These values override the config until reset. The backend URL and gateway secret are configured in LChatWeb's config, not this form.

Use compatible versions of LChatWeb and LChatHost. If the backend is down the web page may load, but login and messages fail. For a 502, check the HTTPS URL, certificate, connectivity, PHP `curl`, tunnel and both PHP logs. The backend's public URL is also reachable directly: enforce the application's access controls, update the server and protect config and tunnel tokens.

Calls across networks may require a separate TURN server with a reachable listener and UDP relay range. Configure `turn` on **LChatHost**. See [calls and TURN](https://github.com/leosmleol-svg/LChat/blob/main/CALLS.md) and [tunnel tools](https://github.com/leosmleol-svg/LChat/blob/main/PORT_TOOLS.md).

Before updating, back up the LChatHost database, `config.php`, `data/` and `files/`, and the LChatWeb config. GitHub Pages cannot run PHP; GitHub stores the source only. This split deployment has not been tested on a real hosting provider.
