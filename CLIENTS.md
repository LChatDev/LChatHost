# LChat clients / Приложения LChat

[Download APK and EXE / Скачать APK и EXE](https://github.com/LChatDev/LChat/releases/tag/clients-2026.11.20)

Android 2.2.1: native Java client, Android 8.0+, four ABIs. Registration agreement acceptance and email-verification guidance, password reset link. Existing official signing certificate retained.

Windows 1.0.0: portable Electron client, Windows 10/11 x64. Current web UI, persistent session, tray, permission prompts. Closing the window keeps the client running; choose Exit in the tray to stop it. No publisher signing certificate. Screen sharing is not implemented yet.

Android: нативный интерфейс, принятие соглашения и подтверждение email. Windows: актуальный веб-интерфейс, сворачивание в трей, разрешения микрофона и камеры. Для полного выхода используйте меню в трее. EXE без подписи издателя.

Checks / Проверки: Android package, update certificate, signatures, ZIP/DEX integrity and four ABIs; Windows permission/navigation tests and real portable EXE startup with live login screen. Physical phone installation, signed-in messaging and two-device/background calls have not been tested here. Установка на телефон и разговор между устройствами в этой среде не проверены.

Android build notes: [source](https://github.com/LChatDev/LChat/blob/main/android/RELEASE_2.2.1.md). Windows: [English](https://github.com/LChatDev/LChat/blob/main/desktop/README.md), [Русский](https://github.com/LChatDev/LChat/blob/main/desktop/README_RU.md).

## Android 2.2.2 call-engine candidate

[Download / Скачать](https://github.com/LChatDev/LChat/releases/tag/android-v2.2.2). WebRTC 150.7871.08, standard addTrack negotiation and additional diagnostics. The reported Samsung SIGABRT is not yet device-verified as resolved. Обновление для проверки звонков; устранение сбоя на телефоне пока не подтверждено. Windows release unchanged.

## Android 2.2.3 pixel UI

[Download / Скачать](https://github.com/LChatDev/LChat/releases/tag/android-v2.2.3). Pixel palette, chat wallpaper, app icon and sharper native radii. Пиксельная тема, обои чата, иконка и угловатые радиусы. Windows release unchanged.
