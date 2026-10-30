# Telegram Web A source used in LChat

LChat remains a separate PHP messenger. It does not connect to Telegram accounts or Telegram servers.

The following UI components were adapted from the [official Telegram Web A source](https://github.com/TelegramOrg/Telegram-web-z), commit `f67a40879a7d78c2683ec5a296cf55bc22417d56` (called “Telegram Web A” in that repository's README):

- `assets/weba-icons.woff2` is copied from `src/styles/icons.woff2`.
- `assets/weba-pattern.svg` is copied from `src/assets/pattern.svg`.
- `assets/weba-fonts/` contains the Roboto CSS and WOFF2 subsets from `src/assets/fonts/`.
- Icon code points in `assets/app.js` and `assets/weba-ui.js` follow `src/styles/icons.scss`.
- Color tokens, the 64 px column header, 760 px message area, 464 px message limit, 48 px composer, 15 px message corner radius, and motion timings in `assets/weba.css` follow `src/styles/index.scss`, `src/styles/_variables.scss`, `src/styles/_composer-primitives.scss`, and the relevant component styles. They are adapted to LChat's existing PHP interface and features.

Telegram Web A is distributed under the GNU General Public License version 3. The corresponding license is included in [LICENSE](LICENSE). This LChat source distribution is available under GPL-3.0. Telegram's names and logos are not used for LChat branding.
