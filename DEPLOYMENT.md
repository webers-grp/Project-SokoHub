# SokoHub Production Deployment Guide

This guide covers production deployment for **SokoHub**, Kenya's digital marketplace platform running on PHP 8.0+ and MySQL 8.0+ / MariaDB 10.4+.

---

## 1. System & Server Requirements

- **PHP Version**: PHP 8.0, 8.1, or 8.2 (64-bit recommended)
- **Required Extensions**:
  - `pdo_mysql` (Database connectivity)
  - `curl` (Daraja M-Pesa API HTTPS communication)
  - `json` (API payloads and responses)
  - `mbstring` (Multibyte text and normalization)
  - `fileinfo` (MIME validation for KYC and product images)
  - `openssl` (Encryption and token generation)
- **Web Server**: Apache 2.4+ (with `mod_rewrite`, `mod_headers`, `mod_authz_core`) or Nginx 1.18+
- **Database**: MySQL 8.0+ or MariaDB 10.4+ with InnoDB and `utf8mb4_unicode_ci`

---

## 2. Directory Layout & File Permissions

```bash
# Recommended Linux ownership and permissions:
chown -R www-data:www-data /var/www/sokohub
find /var/www/sokohub -type d -exec chmod 755 {} \;
find /var/www/sokohub -type f -exec chmod 644 {} \;

# Restrict sensitive folders & files:
chmod 600 /var/www/sokohub/.env
chmod 700 /var/www/sokohub/logs
chmod 700 /var/www/sokohub/backups
chmod 750 /var/www/sokohub/assets/img/uploads
chmod 700 /var/www/sokohub/assets/img/uploads/kyc
```

---

## 3. Web Server Configuration

### Apache (`.htaccess` is provided in root):
Ensure `AllowOverride All` is set for the document root so that security rules and folder restrictions apply.

### Nginx Server Block (Example):
```nginx
server {
    listen 443 ssl http2;
    server_name sokohub.co.ke www.sokohub.co.ke;
    root /var/www/sokohub;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/sokohub.co.ke/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/sokohub.co.ke/privkey.pem;

    # Security Headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

    # Block sensitive directories & files
    location ~ ^/(config|database|includes|tests|docs|scripts|logs|tools)/ {
        deny all;
        return 404;
    }
    location ~ ^/assets/img/uploads/kyc/ {
        deny all;
        return 403;
    }
    location ~ /\.(env|git|sql|log|bak|ini) {
        deny all;
        return 404;
    }

    # Block script execution inside user uploads
    location ~* /assets/img/uploads/.*\.php$ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }
}
```

---

## 4. Environment Variables (`.env`)

In production, set `APP_ENV=production` and populate all real credentials:

```ini
APP_ENV=production
APP_URL=https://sokohub.co.ke

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=sokohub_prod
DB_USER=sokohub_app
DB_PASS=A_Very_Strong_Database_Password_Here

# Daraja M-Pesa Live Production Settings
MPESA_ENV=production
MPESA_CONSUMER_KEY=YOUR_LIVE_CONSUMER_KEY
MPESA_CONSUMER_SECRET=YOUR_LIVE_CONSUMER_SECRET
MPESA_PASSKEY=YOUR_LIVE_PASSKEY
MPESA_SHORTCODE=YOUR_LIVE_SHORTCODE
MPESA_TILL_NUMBER=1648753
MPESA_CALLBACK_URL=https://sokohub.co.ke/pages/api/mpesa_callback.php
MPESA_RESULT_URL=https://sokohub.co.ke/pages/api/mpesa_payout_callback.php
MPESA_TIMEOUT_URL=https://sokohub.co.ke/pages/api/mpesa_payout_timeout.php
MPESA_TRANSACTION_TYPE=CustomerBuyGoodsOnline
MPESA_ALLOW_SIMULATION=false
PLATFORM_FEE_PERCENT=2.0

# Session Configuration
SESSION_IDLE_TIMEOUT=1800
SESSION_MAX_LIFETIME=43200
```

---

## 5. Automated Background Cron Jobs

Add the following entries to the server's crontab (`crontab -e`):

```bash
# 1. Daily automated database & uploads backup at 02:00 AM (keeps last 7 days)
0 2 * * * /usr/bin/php /var/www/sokohub/scripts/backup.php >> /var/www/sokohub/logs/backup.log 2>&1

# 2. Reconcile pending M-Pesa payments every 5 minutes
*/5 * * * * /usr/bin/php /var/www/sokohub/scripts/reconcile_payments.php >> /var/www/sokohub/logs/reconciliation.log 2>&1

# 3. Prune expired rate limit entries daily at midnight
0 0 * * * /usr/bin/php -r "require '/var/www/sokohub/config/db.php'; get_db_connection()->exec('DELETE FROM rate_limits WHERE reset_at < NOW()');"
```

---

## 6. Daraja Production Go-Live Checklist

1. **Safaricom Developer Portal**:
   - Request Go-Live on Safaricom Daraja Portal.
   - Provide KYC and business documents for Till `1648753`.
   - Safaricom issues live `Consumer Key`, `Consumer Secret`, and `Passkey`.
2. **Public HTTPS Webhook URL**:
   - Verify `https://sokohub.co.ke/pages/api/mpesa_callback.php` is accessible to Safaricom IPs and returns HTTP 200 with `{ "ResultCode": 0, "ResultDesc": "Callback received successfully" }`.
3. **Whitelist IPs & SSL**:
   - Ensure a valid TLS certificate (Let's Encrypt / DigiCert) without chain errors.

---

## 7. Rollback Plan

If a major issue occurs post-deployment:
1. Revert code via git: `git checkout v-security-audit-start` (or target tag).
2. Restore database from pre-deployment backup:
   ```bash
   php scripts/backup.php --restore backups/sokohub_backup_PRE_DEPLOY.sql --target-db sokohub_prod
   ```
3. Restart PHP-FPM and Nginx/Apache.
