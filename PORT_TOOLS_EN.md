# Managing free tunnel tools

A tunnel exposes a local service through a provider; it does not necessarily open your router's ports. Start your site first and confirm `http://127.0.0.1:8365/` works on the host PC. MySQL must be running separately. Examples use Windows and local port `8365`; replace the port as needed.

Visitors need HTTPS for microphone access. These web tunnels do not automatically provide TURN: TURN separately requires a public listener and a UDP relay port range. See [CALLS.md](https://github.com/LChatDev/LChat/blob/main/CALLS.md). Provider plans and interfaces can change; these instructions have not been tested against every service.

## Cloudflare Tunnel: a site on your domain

1. Create a Cloudflare account, add your domain and update the registrar's DNS as instructed by Cloudflare. A permanent public hostname requires a domain on Cloudflare. Check the local site works.
2. In the dashboard open **Networking → Tunnels → Create Tunnel**, create a `cloudflared` tunnel, select Windows and install the agent using the dashboard's command. Keep the token in that command private.
3. Add a route with a subdomain such as `chat`, your domain, service type `HTTP` and URL `http://127.0.0.1:8365`. Save and check the agent is connected.
4. Open the assigned HTTPS URL and test login. Set `public_url` in `config.php` to that address.
5. Change the route or delete the tunnel in the dashboard. Stop a foreground process with `Ctrl+C`; if installed as a service, manage it in Windows Services. Stopping the agent interrupts all its routes.

[Cloudflare instructions](https://developers.cloudflare.com/tunnel/get-started/). [Quick Tunnels](https://developers.cloudflare.com/tunnel/setup/) can be used for temporary tests; their URL can change.

## Tailscale Funnel: public HTTPS without your own domain

1. Install [Tailscale](https://tailscale.com/download), sign in on the host PC, and enable MagicDNS, HTTPS and Funnel as described by Tailscale.
2. Open Command Prompt **as administrator** and run:

   ```cmd
   tailscale funnel 8365
   ```

3. Approve enablement if prompted and test the resulting `https://…ts.net` URL from another device. Visitors do not need Tailscale.
4. Use `tailscale funnel status` to inspect and `tailscale funnel off` to disable the publication. A foreground command can also be stopped with `Ctrl+C`.

Funnel uses `*.ts.net` names, a limited set of public TLS ports and bandwidth limits. Tailscale Serve is private and generally requires clients on participants' devices. [Funnel documentation](https://tailscale.com/docs/features/tailscale-funnel).

## LocalTunnel: temporary test URL

1. Install [Node.js](https://nodejs.org/) on the host PC.
2. Run `npx localtunnel --port 8365` in Command Prompt and wait for the URL.
3. Test the link on another device. Keep the local site and command running; press `Ctrl+C` to stop the tunnel.

The URL and availability can change, and an interstitial may interfere with the app or API. Use this for testing rather than a permanent service. [LocalTunnel](https://github.com/localtunnel/localtunnel).

## Playit.gg: managing tunnels

1. Install and run the [Playit](https://playit.gg/) agent on the host PC, then link it to your account using the displayed link.
2. Create a tunnel in the dashboard, select an available protocol and enter local IP `127.0.0.1` and the service's port. Check the assigned public address and port in its properties.
3. Disable or delete the tunnel in the dashboard; exit the agent to stop all its tunnels. The local service must remain running.

Check current plans: [Playit's documentation](https://playit.gg/support/playit-scpsl-server/) states TCP requires Premium. Verify UDP and port-range availability in your account; one tunnel alone is insufficient for a full TURN relay range.

## LocaltoNet and ngrok

**[LocaltoNet](https://www.localtonet.com/):** create an account, install and start its agent using the dashboard instructions, and create an HTTP/TCP/UDP tunnel for `127.0.0.1` and the local service port. Check its public address and status in the dashboard, where you can also disable it; exit the agent to stop all tunnels. Its free tier advertises one tunnel: check current terms and port availability.

**[ngrok](https://ngrok.com/):** install the agent and connect it to your account as instructed by the provider. For the site run `ngrok http 8365` and open its HTTPS URL; press `Ctrl+C` to stop it. The free tier has usage limits, and TCP requires [payment-card verification](https://ngrok.com/faq). Keep the agent token private. A web URL does not provide a TURN relay.

See [TUNNELS.md](https://github.com/LChatDev/LChat/blob/main/TUNNELS.md) for more tools and limitations. Do not expose the MySQL port to the public internet for this setup: LChat only needs a local database connection.
