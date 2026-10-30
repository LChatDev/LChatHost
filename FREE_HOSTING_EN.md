# Hosting the whole LChat stack for free

The example below puts Apache/PHP, MariaDB and coturn on one [Oracle Cloud Always Free](https://www.oracle.com/cloud/free/) virtual machine. **This is a possible free-tier deployment, not a guarantee of free access or availability.** Oracle generally requires identity verification with an accepted payment card, free capacity may be unavailable, and inactive instances may be reclaimed. Only choose resources explicitly marked **Always Free**, stay within the current quotas, and review the [current terms and quotas](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier.htm). The promotional trial and Always Free are different. Your own domain may cost money; a free DNS name with configurable records also works if you can obtain one.

You do not need to run the website or TURN on your own PC in this example. Site visitors need only the website URL; they do not install a tunnel application. This guide has not been tested on an actual Oracle account.

## 1. Create the server and DNS records

1. Create an Oracle Cloud Free Tier account. Select a home region where the service is available and complete its verification. Consult [Oracle's FAQ](https://www.oracle.com/cloud/free/faq/) for accepted cards and restrictions.
2. Create an Ubuntu virtual machine in a **public subnet**, with a public IPv4 address, choosing a shape and boot volume marked Always Free and within your remaining quota. Save the SSH private key securely. Wait for the machine to start. Capacity can be unavailable; do not select a paid shape as a substitute without checking its price.
3. In your DNS provider, create `A` records for `chat.example.com` and `turn.example.com` pointing to the VM's public IPv4. Replace these example names throughout this guide. Wait for DNS propagation.
4. In the VM's OCI network security list or network security group, allow inbound TCP `22` only from your own IP for SSH, TCP `80` and `443` for the website, TCP and UDP `3478` for the TURN listener, and UDP `49160–49200` for TURN relay traffic. Also allow the same traffic through the VM's OS firewall. Keep the existing SSH rule and verify access before changing firewall rules. OCI network rules and OS firewall rules are separate; opening just one is insufficient.

## 2. Install the web stack

Connect to the VM using its Oracle-provided SSH instructions, then on Ubuntu run:

```sh
sudo apt update
sudo apt install apache2 libapache2-mod-php php-mysql php-mbstring php-curl php-gd php-xml mariadb-server git rsync certbot python3-certbot-apache coturn
sudo a2enmod rewrite
sudo systemctl enable --now apache2 mariadb
```

On other distributions, package and service names can differ. Check `php -v` (PHP 8.2 or newer), and check your web PHP configuration has `pdo_mysql`, `mbstring`, `fileinfo`, `curl` and `gd`. Install missing extensions if needed.

Download the source **outside** the public directory and copy web files into the document root without the Git metadata:

```sh
sudo git clone https://github.com/LChatDev/LChat.git /opt/lchat-source
sudo mkdir -p /var/www/lchat
sudo rsync -a --exclude='.git/' --exclude='android/' --exclude='tools/' /opt/lchat-source/ /var/www/lchat/
sudo cp /var/www/lchat/config.example.php /var/www/lchat/config.php
sudo chown -R root:root /var/www/lchat
sudo chown -R www-data:www-data /var/www/lchat/data /var/www/lchat/files
sudo chown root:www-data /var/www/lchat/config.php
sudo chmod 640 /var/www/lchat/config.php
```

Configure an Apache site at `/etc/apache2/sites-available/lchat.conf`, replacing the hostname:

```apache
<VirtualHost *:80>
    ServerName chat.example.com
    DocumentRoot /var/www/lchat
    <Directory /var/www/lchat>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Activate the site and issue a free HTTPS certificate. The hostname must already resolve to the VM, TCP 80 must be reachable, and you must control the domain:

```sh
sudo a2ensite lchat.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
sudo certbot --apache -d chat.example.com
sudo certbot renew --dry-run
```

Verify the site opens over HTTPS without a certificate warning. Certbot configures Apache and certificate renewal; follow any prompts. A DNS name and working certificate are required for browser microphone access.

## 3. Create the database and configure LChat

Open the database console with `sudo mariadb`. Run the following SQL **inside MariaDB**, replacing the password with a strong unique password and keeping it private:

```sql
CREATE DATABASE lchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lchat'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON lchat.* TO 'lchat'@'localhost';
EXIT;
```

Edit `/var/www/lchat/config.php` as root, preserving the other keys. Use the same DB password and replace all placeholders:

```php
'database' => [
    'dsn' => 'mysql:host=localhost;port=3306;dbname=lchat;charset=utf8mb4',
    'username' => 'lchat',
    'password' => 'REPLACE_WITH_A_STRONG_DB_PASSWORD',
],
'site_name' => 'My Messenger',
'initial_admin_username' => 'myadmin',
'public_url' => 'https://chat.example.com',
'install_key' => 'REPLACE_WITH_A_RANDOM_SECRET_AT_LEAST_32_CHARACTERS',
```

Generate an installation key with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Open `https://chat.example.com/install.php`, enter the key in the form, and create the tables. Register the exact configured `initial_admin_username` promptly, then open `admin.php` while signed in. After installation remove the public `install.php` and clear `install_key` in the private config. The installed application and its DB credentials must not be exposed through a public Git repository.

## 4. Add TURN on the same VM

Follow [CALLS.md](CALLS_EN.md): generate a separate TURN secret; configure coturn's `use-auth-secret`, `static-auth-secret`, realm and relay range `49160–49200`; start coturn; and enter the matching URL and secret in LChat's admin panel or private config. OCI VMs are commonly behind NAT: check the actual public/private address mapping, set coturn's `external-ip` as documented upstream, and ensure the public UDP relay range reaches the same local port range. If the VM has no direct public IPv4, this setup must be adjusted.

Test two clients on different networks. Select **TURN only** temporarily to test the relay, then restore **Direct and TURN** if desired. The free VM still needs reachable ports, working HTTPS, matching secrets and sufficient resources; the free tier does not guarantee calls will work on every network.

## 5. Maintain and verify

- Back up the database, `config.php`, `data/` and `files/` somewhere private. Review actual usage and quotas in the cloud console. Idle-instance reclaim and provider changes can interrupt service.
- Apply security updates and keep Apache, PHP, MariaDB and coturn patched. Monitor logs and set up certificate renewal.
- To update, back up first, fetch the desired source revision under `/opt/lchat-source`, then copy updated web files while **excluding** `config.php`, `data/` and `files/`; follow [HOSTING.md](https://github.com/LChatDev/LChat/blob/main/HOSTING.md). Do not copy `.git/` into the web root.
- If this option is unavailable, [InfinityFree](https://www.infinityfree.com/) advertises free PHP/MySQL/SSL hosting, but it is a separate shared-hosting option: verify PHP extensions, `.htaccess`, API/bot access, request limits and storage, and host TURN elsewhere. It has not been verified with this project. GitHub Pages alone cannot execute this PHP/MySQL application.

Other ways to expose a PC-hosted website are listed in [TUNNELS.md](https://github.com/LChatDev/LChat/blob/main/TUNNELS.md). A PC-hosted setup is only online while the PC and its internet connection are running; Cloudflare Tunnel for the website does not itself provide the TURN relay.
