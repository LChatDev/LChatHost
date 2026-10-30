# Setting up voice and video calls

LChat uses WebRTC: PHP and MySQL exchange call signaling; participants exchange encrypted media directly where possible. TURN relays media when direct connections fail. The PHP website itself does **not** run a TURN server. Group calls use a mesh, so each participant connects to every other participant and bandwidth grows as the group grows. Start with a small limit such as 6–8.

## Before you start

1. Set up the site using [HOSTING.md](https://github.com/LChatDev/LChat/blob/main/HOSTING.md), including a trusted HTTPS certificate. Browsers require a secure context for microphone and camera access. Allow microphone/camera access when prompted.
2. Test two separate accounts on two different networks. STUN may be enough for some networks. If a call cannot connect, participants cannot hear one another, or one network blocks direct traffic, configure TURN.
3. For reliable TURN, use a VPS or a managed TURN provider with publicly reachable listener **and relay ports**. Ordinary shared PHP hosting normally cannot run a persistent TURN daemon. A single TCP tunnel to the TURN listener alone is not a complete TURN setup: relayed media also needs reachable relay addresses and ports. Check the tunnel provider's UDP and port-range support.

See [TUNNELS.md](https://github.com/LChatDev/LChat/blob/main/TUNNELS.md) for free tunneling and private networking options.

## Example: coturn on a Linux VPS

This example is for a VPS with a public IPv4 address and Ubuntu/Debian. Replace every placeholder with your own value. Follow your host's firewall documentation; keep your existing SSH access working. See the [coturn configuration reference](https://github.com/coturn/coturn/blob/master/docker/coturn/turnserver.conf) for platform-specific options.

1. Point `turn.example.com` to the VPS public IPv4 address and install coturn:

   ```sh
   sudo apt update
   sudo apt install coturn
   ```

2. Generate a long random secret, e.g. `openssl rand -hex 32`. Keep it private. Edit `/etc/turnserver.conf` and configure:

   ```ini
   listening-port=3478
   fingerprint
   use-auth-secret
   static-auth-secret=REPLACE_WITH_YOUR_RANDOM_SECRET
   realm=turn.example.com
   min-port=49160
   max-port=49200
   no-cli
   no-tls
   no-dtls
   ```

   This minimal example uses ordinary TURN on UDP or TCP, without a TLS listener. WebRTC media is encrypted independently. For TURN over TLS (`turns:`), configure coturn's certificate, private key and TLS listener according to its documentation and use a matching `turns:` URL. If the VPS is behind NAT, coturn must advertise the correct public address; configure `external-ip` as described in the coturn reference and use a one-to-one mapping for the listener and relay range.

3. Allow **UDP and TCP 3478** to the VPS, and **UDP 49160–49200** for relay allocations, in both the hosting firewall and the OS firewall. Adjust the range to your capacity needs and keep it consistent with the coturn config. Start and inspect the service:

   ```sh
   sudo systemctl enable --now coturn
   sudo systemctl status coturn
   sudo journalctl -u coturn -n 100 --no-pager
   ```

   The service name and enablement method can vary by distribution. A running service alone does not prove that external clients can reach its advertised relay ports.

## Connect LChat to TURN

In the private `config.php`, use the same secret as coturn and replace the host:

```php
'ice_servers' => [
    ['urls' => 'stun:stun.l.google.com:19302'],
],
'turn' => [
    'urls' => 'turn:turn.example.com:3478?transport=udp',
    'secret' => 'REPLACE_WITH_YOUR_RANDOM_SECRET',
    'ttl' => 3600,
],
'call_transport' => 'all',
'max_call_participants' => 8,
```

LChat derives short-lived TURN credentials from the shared secret; configure coturn's `use-auth-secret` and `static-auth-secret`, not an unrelated fixed username/password. Never put the shared secret in public HTML, Git, or release assets. If UDP is blocked between a client and the listener, a `turn:turn.example.com:3478?transport=tcp` URL can be used when TCP is enabled; UDP relay ports must still be reachable. The PHP config can hold an array of TURN URLs; the admin form displays and saves one URL.

Alternatively, the service owner can set the TURN URL, secret, credential lifetime, participant limit and transport in `admin.php`. A blank secret field keeps the current one. These database values override `config.php`; after changing the config directly, remove admin overrides with **Reset to config.php** if needed. `all` tries direct and relay candidates; `relay` requires correctly configured TURN and forces calls through it.

## Verify and troubleshoot

1. Sign in on two devices and networks, allow microphone access, start a call and join it. Test audio both ways and, if used, video. Try a small group only after a two-person call works.
2. Temporarily select **TURN only** in the admin panel, start a fresh call and test again. A successful call then confirms that this test used a relay. Restore **Direct and TURN** afterwards unless you deliberately want relay-only calls.
3. If relay-only fails, check the URL and DNS, coturn logs, matching shared secret, listener firewall, relay UDP port range, and advertised external IP. Check client browser errors and whether the network allows the chosen UDP or TCP listener. An expired credential may require rejoining the call.
4. In Chromium-based browsers, `chrome://webrtc-internals` can help inspect ICE candidate types and connection state. An established `relay` candidate is a useful sign, but test actual audio too.

For Android, allow microphone and notifications. The app uses background polling; device power saving can delay incoming call notifications. See [android/README.md](https://github.com/LChatDev/LChat/blob/main/android/README.md) for limitations. More about [WebRTC connectivity and ICE](https://developer.mozilla.org/en-US/docs/Web/API/WebRTC_API/Connectivity) and [microphone permissions](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia).
