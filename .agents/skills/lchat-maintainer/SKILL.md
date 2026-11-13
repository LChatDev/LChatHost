---
name: lchat-maintainer
description: "Configure, customize, and maintain LChat forks. Use for LChat branding, CSS themes, self-hosting, split LChatWeb/LChatHost deployments, and TURN troubleshooting."
---

# Maintain LChat

Identify the edition from its README and entry points before editing:

- LChat, LChatRU and LChatOriginal can serve the application. Keep the edition's language and existing configuration.
- LChatWeb serves the UI and forwards API requests to LChatHost. Change front-end branding on LChatWeb and server behavior on LChatHost.
- LChatCalls and LChatFree contain guides. LChatAi contains skills.

For appearance, follow the repository's CUSTOMIZATION.md when present. Make a small override in assets/custom.css and check login, chat and composer at mobile and desktop widths in both themes. Confirm readable text and visible keyboard focus. Keep existing accessibility behavior.

For split hosting, check LChatHost and LChatWeb separately. Verify the public HTTPS address and shared gateway secret, then confirm a browser session can send a message and still read it after reload. Use disposable test accounts and settings. Keep live config, databases, message keys and credentials out of commits, screenshots and logs.

For calls, distinguish access to the website from the WebRTC media route. Use the calling guide for the selected edition. Verify the microphone and sound in both directions from different networks. In relay-only mode, confirm TURN is used and repeat the test.

Use local checks before deployment. Report which checks actually ran and which parts need a live server or two devices.
