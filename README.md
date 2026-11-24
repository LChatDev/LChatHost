> Current downloads: see [CLIENTS.md](CLIENTS.md). Previous application binaries have been withdrawn. The supplied APK uses a different signing key; old build/test instructions below describe retained source, not this binary.
> Актуальный APK: см. CLIENTS.md. Старые сборки удалены; подпись APK отличается. Исторические инструкции ниже относятся к сохранённым исходникам.

# LChatHost

<!-- live-sync-20261119 -->

[Website update and deployment (English)](SYNC_EN.md) · [Обновление и размещение (русский)](SYNC_RU.md)


**Windows и доступ к портам / Windows and port access:** [Инструкция для Windows](WINDOWS_HOST_RU.md) · [Windows guide](WINDOWS_HOST_EN.md) · [Программы и настройка](PORT_TOOLS_RU.md) · [Tools and setup](PORT_TOOLS_EN.md)


> LChat был полностью создан с помощью ИИ и использует сторонние компоненты. / LChat was created entirely with AI assistance and uses third-party components.

Серверная часть LChat: обработка запросов, сессий, сообщений, файлов и сигнализации звонков. Страницы и ресурсы находятся в отдельном [LChatWeb](https://github.com/LChatDev/LChatWeb).

Backend for requests, sessions, messages, files and call signaling. Pages and assets live in [LChatWeb](https://github.com/LChatDev/LChatWeb).

Инструкции / Guides: [русский](doc_RU.md) · [English](doc_EN.md). Лицензия / License: [GPL-3.0](LICENSE).

## Руководства / Guides

- Windows: [Русский](WINDOWS_HOST_RU.md) · [English](WINDOWS_HOST_EN.md)
- Открытие портов и туннели / Port forwarding and tunnels: [Русский](PORT_TOOLS_RU.md) · [English](PORT_TOOLS_EN.md)
- Звонки и TURN / Calls and TURN: [Русский](CALLS_RU.md) · [English](CALLS_EN.md)
- Бесплатный хостинг / Free hosting: [Русский](FREE_HOSTING_RU.md) · [English](FREE_HOSTING_EN.md)

Дополнительные репозитории / Dedicated guides: [LChatCalls](https://github.com/LChatDev/LChatCalls) · [LChatFree](https://github.com/LChatDev/LChatFree).

- Бесплатный домен / Free hostname: [Русский](FREE_DOMAIN_RU.md) · [English](FREE_DOMAIN_EN.md)

Текст сообщения: до **10 МиБ в UTF-8**. При обновлении создайте резервную копию базы; MySQL-миграция расширяет поля сообщений и очереди ботов. Для больших запросов проверьте лимиты PHP, веб-сервера и `max_allowed_packet` MySQL (не менее 32 МиБ). Лимит вложений задаётся отдельно.

## Панель для Windows и Linux / Windows and Linux panel

Скачать / Download (previous binary withdrawn / прежняя сборка удалена) · [Русский](launcher/README_RU.md) · [English](launcher/README_EN.md)


[Codex skill / ИИ-скилл](.agents/skills/lchat-maintainer/SKILL.md). Open the repository in Codex and invoke `$lchat-maintainer`.
