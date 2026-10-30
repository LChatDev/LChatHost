# A free hostname for LChat

The easiest free option is a **subdomain**, such as `my-lchat.duckdns.org`: the provider owns the parent domain. It is more stable than a temporary URL, but provider terms and availability may change. DNS alone does not open ports, run a website, or issue an HTTPS certificate.

## Option 1: Duck DNS and your own server

Use a VPS with a public IPv4 address or a home PC with a reachable public IPv4 and port forwarding.

1. Visit [Duck DNS](https://www.duckdns.org/), sign in using one of the offered methods, enter an available name such as `my-lchat` in **sub domain** and click **add domain**. The result is `my-lchat.duckdns.org`.
2. Enter your server's public IPv4 in **current ip** and click **update ip**. For a cloud VM use the public IP from the provider dashboard, not a private `192.168...` address. At home, check whether your address is actually public: port forwarding alone may not work behind CGNAT.
3. If your IP changes, follow [Duck DNS Install](https://www.duckdns.org/install.jsp) for your operating system to update it automatically. Keep the update token secret. A static VPS IP usually does not need automatic updates.
4. Open or forward TCP 80 and 443 to the web server, including cloud firewall rules and Windows Firewall. When DNS points to the website server, issue a trusted HTTPS certificate: for example, configure Caddy to reverse proxy to `127.0.0.1:8365` with your hostname. Caddy can issue and renew the certificate automatically when ports 80 and 443 are reachable. For Apache on Ubuntu see [free hosting](FREE_HOSTING_EN.md).
5. On Windows, after installing [Caddy](https://caddyserver.com/docs/install), run this in a new CMD window, replacing the hostname:

   ```bat
   caddy reverse-proxy --from my-lchat.duckdns.org --to 127.0.0.1:8365
   ```

   The website must already be running on port 8365. Leave Caddy running, open `https://my-lchat.duckdns.org/` on another device, and verify its certificate. Set LChat's `public_url` to this HTTPS address and keep its files and database protected. Automatic issuance may fail if ports 80/443 are occupied, DNS has not propagated, or the ISP blocks inbound connections.

Use `nslookup my-lchat.duckdns.org` to check DNS. **For Playit:** DNS does not store a port: an A record pointing to the tunnel IP does not turn a nonstandard public port into 443. A TCP tunnel at `IP:port` alone does not provide normal `https://hostname/`; you need a gateway with public port 443 and correct TLS or another access method. TURN needs its own reachable listener and relay ports: [calls guide](CALLS_EN.md).

## Option 2: FreeDNS

Register at [FreeDNS](https://freedns.afraid.org/), open **Subdomains → Add**, select an available shared domain and unused hostname, choose type `A`, enter your website's public IPv4 and save. Configure HTTPS and ports as above. The service [offers free subdomains](https://freedns.afraid.org/signup/moreinfo/); availability of a shared domain depends on its owner, so do not assume another owner's domain is permanently available.

## Option 3: a temporary HTTPS URL without a domain or port forwarding

For testing a website on your PC, use [Cloudflare Quick Tunnel](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/). Install `cloudflared`, start the website on `127.0.0.1:8365`, and run:

```bat
cloudflared tunnel --url http://127.0.0.1:8365
```

Open the generated `https://...trycloudflare.com` URL and leave the process running. The URL changes each time you start it; this is a test tunnel without an uptime guarantee, not a permanent domain. Set LChat's `public_url` to the current URL. This tunnels the website, not TURN; keep credentials private and avoid using the test mode as a permanent public service.

See also [port access tools](PORT_TOOLS_EN.md) and [Windows setup](WINDOWS_HOST_EN.md).
