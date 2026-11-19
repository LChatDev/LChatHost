# LChat website source update — 19 November 2026

The current website source is synchronized from the REG.RU installation. LChat uses PHP 8.2+, PDO MySQL, mbstring, fileinfo, GD and OpenSSL. The remote gateway and webhook workers also require cURL.

## Choose a repository

| Repository | Purpose |
|---|---|
| [LChat](https://github.com/LChatDev/LChat) | Complete English website and API |
| [LChatRU](https://github.com/LChatDev/LChatRU) | Complete Russian website and API |
| [LChatOriginal](https://github.com/LChatDev/LChatOriginal) | Original source for contributing |
| [LChatWeb](https://github.com/LChatDev/LChatWeb) | Website assets and PHP gateway |
| [LChatHost](https://github.com/LChatDev/LChatHost) | Backend, storage and Windows/Linux launcher |
| [LChatCalls](https://github.com/LChatDev/LChatCalls) | Call and TURN deployment guides |
| [LChatFree](https://github.com/LChatDev/LChatFree) | Free hosting and hostname guides |
| [LChatAi](https://github.com/LChatDev/LChatAi) | GUI design skill and references |

## Existing installation

1. Back up the database, private config.php and storage. Keep an extra backup of the message encryption key. Reusing that key is required to read previously encrypted messages.
2. Update code and public assets together. Keep existing config.php and runtime data.
3. Review the additions in config.example.php. Set public_url to your own HTTPS origin.
4. Set storage_dir to a writable private upload directory. The default is files beside app.php. Existing uploads stored elsewhere need that path in storage_dir.
5. Configure SMTP host, TLS port (465), username and password privately in config.php or through the LCHAT_SMTP_HOST, LCHAT_SMTP_USER and LCHAT_SMTP_PASSWORD environment variables. Registration email and password reset need working SMTP.
6. Test email verification, sign-in, password reset, sending and deleting a message, uploading a file and a call between two networks.

No production credentials, account records or user uploads are distributed in Git.

## Fresh installation on a PHP host or Windows

Copy config.example.php to config.php, configure MySQL and a random install_key, then follow the repository installation guide. Enable the PHP extensions listed above in the PHP binary used by the web server. Serve the site with HTTPS and enable Apache rewrite rules. A PHP development server is for local checks.

Register the configured initial administrator and complete installation. Fill in the operator/contact/return details in the legal page templates before publishing your own service. Payment processing requires your own provider configuration.

## Split hosting

Update LChatHost before LChatWeb. Set the same long random proxy_secret on both. On LChatWeb configure backend.mode=pc and backend.url to the backend HTTPS URL. Use a certificate trusted by PHP cURL. LChatHost handles sessions, messages, private files, registration mail and call signaling. LChatWeb serves public assets and forwards the supported PHP endpoints.

Public emoji and gift files live in assets/lchat-emoji and assets/lchat-gifts in the website repositories. On split hosting those files belong on LChatWeb. User files belong on LChatHost.

## Interface and bots

The update includes the pixel theme, its original logo, light/dark modes, additional themes, responsive navigation and default pixel avatars. The existing Bot API provides polling, webhooks, files and reply/inline keyboards according to docs.php. User bot processes and the webhook worker require an external runner or cron. Site creation and compiler features need their corresponding bot/worker installation.

TURN is a separate service. Configure your own TURN URLs and shared secret, publish its listener and UDP relay range, and verify that temporary credentials use synchronized server clocks. See the bilingual call guides for Windows/Linux and tunnels. A website update alone does not verify calls on physical devices.

LChat was created entirely with AI assistance and includes third-party components under their respective licenses.
