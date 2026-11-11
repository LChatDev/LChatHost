# LChatHost Panel

Download the Windows or Linux x86-64 file ending in `-en`. On Windows, run the EXE. Use **Install Docker Desktop** if needed, then complete Docker Desktop and WSL 2 setup.

On Ubuntu 24.04, run:

~~~sh
chmod +x LChatHost-linux-amd64-en
./LChatHost-linux-amd64-en --install-docker
./LChatHost-linux-amd64-en
~~~

If Docker denies access, follow its [Linux post-install instructions](https://docs.docker.com/engine/install/linux-postinstall/). The launcher prints the local panel address and opens it in your browser. Keep the launcher running while managing the backend.

1. Enter your **LChatWeb** HTTPS address and select **Install and start backend**. The panel builds Apache/PHP, starts MariaDB, and saves your settings. First setup requires internet access. Free port 18365 if another service uses it.
2. Select **Temporary tunnel** to test. For a stable address, create a named Cloudflare tunnel, route its service to `http://backend:80`, save its token in the backend form, and select **Named tunnel**.
3. Set the tunnel URL and the panel's `proxy_secret` in LChatWeb's config. Finish setup from LChatWeb's `install.php` with the panel's install key. See the [host setup guide](../doc_EN.md).
4. Enter your TURN settings if needed for calls. The tunnel carries the application's HTTPS traffic. TURN for media is configured separately.

**Stop all** preserves database and file volumes. To build all four binaries from a Git checkout, install Go and Python and run `python launcher/build.py`.
