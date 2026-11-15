# Deploying Zellie Fleurs on a VPS

This guide assumes Debian or Ubuntu with a domain pointed at the VPS. Choose either Apache or Nginx, not both.

Replace `shop.example.com` with your real domain and `SERVER_IP` with your VPS address throughout.

## 1. DNS and packages

Create an `A` record for `shop.example.com` (and optionally `www`) pointing to the VPS.

### Nginx

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-sqlite3 php-mbstring sqlite3 rsync certbot python3-certbot-nginx
php -v
php -m | grep -E 'pdo_sqlite|sqlite3|mbstring'
ls /run/php/
```

### Apache

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-sqlite3 php-mbstring sqlite3 rsync certbot python3-certbot-apache
sudo a2enmod headers
sudo systemctl reload apache2
php -v
php -m | grep -E 'pdo_sqlite|sqlite3|mbstring'
```

The Nginx config uses `/run/php/php8.3-fpm.sock`. Change that line if `ls /run/php/` shows a different version.

## 2. Upload the application

From the local Termux project:

```bash
rsync -avz \
  --exclude 'data/shop.db*' \
  --exclude 'data/initial_admin_credentials.txt' \
  --exclude 'data/php-error.log' \
  --include 'public/uploads/' \
  --include 'public/uploads/.htaccess' \
  --include 'public/uploads/index.html' \
  --exclude 'public/uploads/*' \
  ./ user@SERVER_IP:/tmp/zellie-fleurs/
```

On the VPS:

```bash
sudo mkdir -p /var/www/zellie-fleurs
sudo rsync -a /tmp/zellie-fleurs/ /var/www/zellie-fleurs/
sudo chown -R www-data:www-data /var/www/zellie-fleurs
sudo find /var/www/zellie-fleurs -type d -exec chmod 755 {} \;
sudo find /var/www/zellie-fleurs -type f -exec chmod 644 {} \;
sudo chmod 775 /var/www/zellie-fleurs/data /var/www/zellie-fleurs/public/uploads
```

The document root must be `/var/www/zellie-fleurs/public`. The `config/`, `data/`, `src/`, `tests/`, `bin/` and `deploy/` directories stay outside the web root.

## 3. Initialise the database and admin

```bash
cd /var/www/zellie-fleurs
sudo -u www-data php bin/init_db.php
sudo cat data/initial_admin_credentials.txt
```

Sign in at `https://shop.example.com/admin/login.php`, change the password under **Settings → Security**, then delete the setup file:

```bash
sudo rm /var/www/zellie-fleurs/data/initial_admin_credentials.txt
```

## 4. Configure the web server

### Nginx

```bash
cd /var/www/zellie-fleurs
sudo cp deploy/nginx-zellie-fleurs.conf /etc/nginx/sites-available/zellie-fleurs
sudo sed -i 's/shop\.example\.com/your-real-domain.com/g' /etc/nginx/sites-available/zellie-fleurs
sudo ln -sf /etc/nginx/sites-available/zellie-fleurs /etc/nginx/sites-enabled/zellie-fleurs
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

If the PHP socket is not `php8.3-fpm.sock`, update the `fastcgi_pass` line:

```bash
sudo sed -i 's#php8.3-fpm.sock#php8.2-fpm.sock#' /etc/nginx/sites-available/zellie-fleurs
sudo nginx -t
sudo systemctl reload nginx
```

### Apache

```bash
cd /var/www/zellie-fleurs
sudo cp deploy/apache-zellie-fleurs.conf /etc/apache2/sites-available/zellie-fleurs.conf
sudo sed -i 's/shop\.example\.com/your-real-domain.com/g' /etc/apache2/sites-available/zellie-fleurs.conf
sudo a2ensite zellie-fleurs
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl reload apache2
```

`AllowOverride All` is required for `public/.htaccess` directory protection.

## 5. Enable HTTPS

The shipped server configs already do the right thing: the port-80 vhost
redirects **everything** to HTTPS, and the 443 vhost sends an HSTS header
(`max-age=31536000`). This is mandatory, not optional — the session cookie
authenticates the admin panel, so it must never travel over plaintext.
Until TLS is live, the site effectively serves nothing but redirects.

1. Point DNS at the server first (§3) — certbot needs a resolvable domain.
2. Obtain the certificate:

Nginx:

```bash
sudo certbot --nginx -d shop.example.com -d www.shop.example.com
```

Apache:

```bash
sudo certbot --apache -d shop.example.com -d www.shop.example.com
```

3. Certbot will usually rewrite the shipped vhosts with the real
   certificate paths it created and install its own auto-renew timer
   (`certbot renew --dry-run` to verify). If it creates a second port-80
   vhost instead of using the shipped redirect, remove the duplicate so
   exactly one redirect vhost remains.

4. Verify after reload (§8 checklist): `curl -I http://your-domain` must
   return `301` to `https://…`, and the HTTPS response must include
   `Strict-Transport-Security` exactly once alongside the PHP security
   headers (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy`, `Permissions-Policy`).

## 6. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
# or: sudo ufw allow 'Apache Full'
sudo ufw enable
```

## 7. Backups

Install the backup helper:

```bash
cd /var/www/zellie-fleurs
sudo install -m 750 deploy/backup.sh /usr/local/bin/zellie-backup
sudo /usr/local/bin/zellie-backup
```

It uses SQLite’s online backup API, verifies the copy, archives the database and `public/uploads`, and keeps 14 days by default.

Schedule it daily:

```bash
sudo crontab -e
```

Add:

```cron
15 2 * * * /usr/local/bin/zellie-backup >> /var/log/zellie-backup.log 2>&1
```

## 8. Verify the deployment

```bash
curl -I https://shop.example.com/
curl -I https://shop.example.com/admin/index.php
curl -I https://shop.example.com/data/shop.db
curl -I https://shop.example.com/config/database.php
curl -I https://shop.example.com/tests/run.php
```

Expected:

- Storefront returns `200`.
- `/admin/index.php` redirects to the login page.
- `data/`, `config/`, and `tests/` return `404` or `403`.
- HTTPS is valid.

## 9. Updating the site

Always back up first:

```bash
sudo /usr/local/bin/zellie-backup
```

Upload the new code while preserving server data:

```bash
rsync -avz --delete \
  --exclude 'data/' \
  --include 'public/uploads/' \
  --include 'public/uploads/.htaccess' \
  --include 'public/uploads/index.html' \
  --exclude 'public/uploads/*' \
  ./ user@SERVER_IP:/tmp/zellie-fleurs-new/
```

On the VPS:

```bash
sudo rsync -a --delete --exclude 'data/' --include 'public/uploads/' --include 'public/uploads/.htaccess' --include 'public/uploads/index.html' --exclude 'public/uploads/*' /tmp/zellie-fleurs-new/ /var/www/zellie-fleurs/
sudo chown -R www-data:www-data /var/www/zellie-fleurs
sudo -u www-data php /var/www/zellie-fleurs/bin/init_db.php
sudo systemctl reload nginx
# or: sudo systemctl reload apache2
```

Do not run the test suites against the production database.

## Production checklist

- [ ] Domain resolves to the VPS.
- [ ] Document root is `/var/www/zellie-fleurs/public`.
- [ ] `ZF_DEBUG` is `0` (set in the supplied Apache/Nginx config).
- [ ] HTTPS is active and auto-renewing.
- [ ] Admin password has been changed.
- [ ] `data/initial_admin_credentials.txt` has been deleted.
- [ ] `data/` and `public/uploads/` are writable by `www-data`.
- [ ] `data/shop.db` and backups are not web-accessible.
- [ ] Daily backups are running and restore has been tested.
- [ ] `curl` checks above return the expected status codes.
- [ ] PHP built-in server (`php -S`) is not used in production.
