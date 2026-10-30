# LChat 2.2 update / Обновление LChat 2.2

## English

Deploy the updated PHP API and website assets together. In split hosting, update
LChatHost first, then LChatWeb. The Android 2.2 client needs the matching backend.
No database migration or configuration reset is required for this update.

Owners can rename groups/channels and remove members; the owner cannot remove
themselves. The chat info endpoint requires membership. Web search and file lists
cover loaded messages; load older history to include it. Temporary TURN credentials
refresh during a call. This does not replace a working TURN relay or fix closed ports.

The website uses a Telegram Web A-inspired layout. Android uses native screens,
including a collapsible call view, message replies, reactions and editing.
The 10 MiB UTF-8 text limit remains in place.

Download the signed experimental Android APK from
[LChat Releases](https://github.com/LChatDev/LChat/releases/tag/android-v2.2.0).
The APK is built and signature-verified; native audio/background behavior and the
previous Samsung native crash have not been verified on a physical device.

## Русский

Обновите PHP API и файлы сайта вместе. При раздельном размещении сначала обновите
LChatHost, затем LChatWeb. Android 2.2 нужен совместимый сервер.
Миграция БД и сброс конфигурации для этого обновления не требуются.

Владелец может переименовать группу/канал и исключать участников, кроме себя.
Информация о чате доступна только участникам. Поиск и список файлов на сайте
работают по загруженным сообщениям; для старой истории сначала загрузите её.
Временные данные TURN обновляются во время звонка. Рабочий TURN и открытые
порты по-прежнему необходимы.

Оформление сайта приближено к Telegram Web A. Android использует нативные экраны:
сворачиваемый экран звонка, ответы, реакции и редактирование сообщений.
Лимит текста 10 МиБ UTF-8 сохранён.

[Скачать подписанный тестовый APK](https://github.com/LChatDev/LChat/releases/tag/android-v2.2.0).
Сборка и подпись проверены. Звук, фоновая работа и исправление прежнего нативного
падения Samsung на физическом телефоне пока не подтверждены.
