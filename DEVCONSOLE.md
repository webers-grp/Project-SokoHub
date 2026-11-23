# SokoHub Developer Console (Internal Ops)

## 1. Overview & Isolation Architecture

The **SokoHub Developer Console** (`/devconsole`) is an isolated, internal operations platform built specifically for system developers, engineers, and ops owners. It provides diagnostic, testing, maintenance, and database administration tooling completely decoupled from the customer-facing e-commerce storefront.

### Strict Isolation Guarantees:
- **No Storefront Leaks**: No links, menus, badges, or hints pointing to `/devconsole` exist anywhere in the buyer, seller, rider, or business admin interfaces.
- **Dedicated Authentication**: Developer accounts reside in a dedicated `developers` database table with distinct password hashes and roles (`owner`, `developer`, `viewer`). Regular storefront accounts (`users` table) cannot authenticate against the console under any circumstance.
- **Mandatory Two-Factor Authentication (2FA)**: Standard RFC 6238 TOTP (Time-based One-Time Password) is enforced on every developer account. Compatible with Google Authenticator, Authy, and 1Password.
- **Isolated Sessions**: Operates under a dedicated session cookie (`SOKOHUB_DEV_SESSID`) with `HttpOnly`, `SameSite=Strict`, `path=/devconsole`, a strict **15-minute idle timeout**, and a **4-hour absolute session ceiling**.
- **Robots & Anti-Indexing**: Protected by `robots.txt` (`Disallow: /devconsole/`) and HTTP headers (`X-Robots-Tag: noindex, nofollow, noarchive`, `X-Frame-Options: DENY`).
- **Owner Re-Authentication**: Destructive or high-risk operations (e.g. database write mode, migrations, payout retries, feature flag changes) require re-entering the account password within a 10-minute sliding challenge window.
- **Zero Production Simulation**: M-Pesa STK simulation and test webhook replays are strictly locked down and disabled when `MPESA_ENV === 'production'`.

---

## 2. Tool Catalogue

The console is organized into 6 operational sections:

```
devconsole/
├── index.php                      # Front router
├── login.php                      # Rate-limited developer login
├── setup_2fa.php                  # First-login TOTP enrollment
├── verify_2fa.php                 # 2FA challenge on login
├── reauth.php                     # Sudo/re-authentication window challenge
├── logout.php                     # Session termination
├── dashboard.php                  # System Health & KPIs
├── bin/
│   └── create_developer.php       # CLI account provisioning tool
├── pages/
│   ├── payments/
│   │   ├── simulator.php          # STK push simulator (Sandbox only)
│   │   ├── inspector.php          # Payment ledger & escrow inspector
│   │   ├── reconciliation.php     # Daily ledger reconciliation & stuck payment sweeper
│   │   ├── payouts.php            # Vendor/rider payout inspector & retry trigger
│   │   └── tester.php             # Daraja OAuth2 connection & handshake tester
│   ├── database/
│   │   ├── schema.php             # Table schema, indexes, column types & table sizes
│   │   ├── migrations.php         # Migration runner with automated pre-run backup
│   │   ├── browser.php            # Read-only data viewer with phone/ID masking
│   │   ├── query.php              # SELECT query runner (Owner write mode with re-auth)
│   │   ├── backup.php             # Manual SQL backup creation & restore test
│   │   └── seed.php               # Test data / synthetic order generator (Non-prod only)
│   ├── logs/
│   │   ├── viewer.php             # Live log tail viewer, keyword search & export
│   │   ├── security.php           # Security events, locked accounts & throttle resets
│   │   └── cron.php               # Scheduled job runner & execution history
│   ├── config/
│   │   ├── env.php                # Environment variable inspector (secrets masked)
│   │   ├── flags.php              # Feature toggles & Platform Maintenance Mode switch
│   │   ├── cache.php              # OPCache flusher & static asset cache buster
│   │   └── notifications.php      # SMS gateway & telephony alert tester
│   └── api/
│       ├── webhooks.php           # Daraja callback log & sandbox replay
│       ├── tester.php             # Internal REST API tester
│       └── audit_log.php          # Append-only console audit trail viewer
```

---

## 3. Roles and Permissions Matrix

| Capability / Tool | Viewer | Developer | Owner | Notes |
|:---|:---:|:---:|:---:|:---|
| **View Dashboard & System Health** | Yes | Yes | Yes | DB latency, disk space, active counts |
| **View Logs, Cron & Environment** | Yes | Yes | Yes | Secrets are masked for all roles |
| **Inspect Payments & Ledger** | Yes | Yes | Yes | PII masked for privacy |
| **Read-Only Schema & Data Browser** | Yes | Yes | Yes | Masked phone numbers and national IDs |
| **Run SELECT Queries** | Yes | Yes | Yes | Hard 100-row limit applied |
| **Daraja Connection Tester** | No | Yes | Yes | Handshake test |
| **Notification / SMS Tester** | No | Yes | Yes | Telephony mock test |
| **M-Pesa STK Simulator** | No | Yes | Yes | Non-prod only; blocked in production |
| **Webhook Replayer** | No | Yes | Yes | Non-prod only |
| **Run Migrations** | No | No | Yes | Requires re-auth; auto-creates backup |
| **Database Write Mode (Query)** | No | No | Yes | Requires re-auth; blocked in production |
| **Manual DB Backup & Restore Test**| No | No | Yes | Requires re-auth |
| **Retry Stuck Payouts** | No | No | Yes | Requires re-auth |
| **Toggle Maintenance Mode & Flags**| No | No | Yes | Requires re-auth |
| **Create Developer Accounts** | CLI | CLI | CLI | CLI-only script for maximum security |

---

## 4. Account Provisioning CLI

For security, new developer accounts cannot be registered via the web interface. They must be provisioned via the command line on the server:

```bash
# Display help
php devconsole/bin/create_developer.php --help

# Create a developer account (random password generated automatically)
php devconsole/bin/create_developer.php --username=alice --email=alice@sokohub.co.ke --role=developer

# Create an owner account with a specified password
php devconsole/bin/create_developer.php --username=lead_dev --email=lead@sokohub.co.ke --role=owner --password="SuperSecurePassword@2026!"
```

The script generates a 16-character Base32 TOTP secret and provides an `otpauth://` setup URI. On first login at `/devconsole/login.php`, the user must scan the QR/URI in Google Authenticator or Authy to complete enrollment before accessing any tools.

---

## 5. Configuration & Environment Variables

Add the following variables to your `.env` file:

```dotenv
# ==============================================================================
# DEVELOPER CONSOLE CONFIGURATION (INTERNAL OPS)
# ==============================================================================
# Master kill-switch for developer console (true / false)
DEVCONSOLE_ENABLED=true

# Comma-separated list of IP addresses allowed to access /devconsole.
# Leave blank to allow access from any IP (e.g. during local development).
DEVCONSOLE_ALLOWED_IPS=127.0.0.1,192.168.1.100,10.0.0.5

# Allow database write queries in developer console during production (Default: false)
# Keep false in production to prevent accidental DDL/DML data loss.
DEVCONSOLE_ALLOW_PROD_WRITES=false
```

---

## 6. Web Server Deployment Guide

### Deployment Option A: Subpath on Existing Host (`/devconsole`)

When deployed under the same domain, configure your web server to restrict access by IP or require Basic Authentication at the web server layer.

#### Nginx (`/devconsole` block):
```nginx
location /devconsole {
    # Optional IP restriction
    # allow 197.237.0.0/16; # Office IP range
    # allow 127.0.0.1;
    # deny all;

    # Anti-indexing headers
    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
    add_header X-Frame-Options "DENY" always;

    try_files $uri $uri/ /devconsole/index.php?$args;
}
```

#### Apache (`devconsole/.htaccess`):
```apache
<IfModule mod_headers.c>
    Header set X-Robots-Tag "noindex, nofollow, noarchive"
    Header set X-Frame-Options "DENY"
</IfModule>

# Optional: IP Allowlisting
# <RequireAny>
#     Require ip 127.0.0.1
#     Require ip 197.237.10.5
# </RequireAny>
```

---

### Deployment Option B: Isolated Subdomain (`dev.sokohub.co.ke`)

For maximum isolation, bind the developer console to its own subdomain and virtual host.

#### Nginx VirtualHost (`dev.sokohub.co.ke`):
```nginx
server {
    listen 443 ssl http2;
    server_name dev.sokohub.co.ke;

    root /var/www/sokohub/devconsole;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/dev.sokohub.co.ke/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dev.sokohub.co.ke/privkey.pem;

    # Restrict to VPN or office IPs
    allow 197.237.0.0/16;
    allow 127.0.0.1;
    deny all;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 7. Audit Trail & Compliance

Every action performed inside the Developer Console is recorded in the `devconsole_audit_logs` table:
- **Timestamp** (`created_at`)
- **Developer ID & Username** (`developer_id`, `username`)
- **Client IP & User Agent** (`ip_address`, `user_agent`)
- **Category & Action** (e.g. `database.query_executed`, `payments.payout_retried`)
- **Target Entity** (e.g. `order_id: 104`, `table: users`)
- **Sanitized Metadata** (`details` JSON, with passwords, keys, and secrets automatically masked)
- **Result State** (`success`, `failed`, `blocked`)

The audit log is viewable in the console under **API & Auditing &rarr; Console Audit Log** and cannot be modified or truncated from within the web interface.
