# Going live — New Era Trading

This is the checklist to move the site from this PC to a real server. Do the steps in order; `php artisan app:check-production`
at the end tells you, line by line, what is still missing.

> **Before any public launch:** have a lawyer in your country review the plan (fixed daily ROI + multi-level referral income is
> treated as a regulated deposit scheme / pyramid scheme in many countries, including India). No software setting changes that.

## 0. What you need
| Item | Notes |
|---|---|
| A server | Linux VPS (Ubuntu 22.04+) or cPanel hosting with **PHP 8.2+**, extensions `bcmath mbstring openssl pdo_mysql fileinfo zip curl`, **MariaDB 10.4+/MySQL 8** |
| A domain with HTTPS | e.g. `neweratrading.net` — free certificate from Let's Encrypt (cPanel: "AutoSSL") |
| An SMTP mailbox | for `no-reply@neweratrading.net` (hosting mailbox, Brevo, Zoho, Mailgun, Amazon SES …) |
| A USDT (BEP20) wallet address | the company wallet that receives deposits. **The private key never goes on the server.** |
| (recommended) Etherscan API key | free at etherscan.io/apis — lets the site verify deposits on the blockchain automatically |

## 1. Build the upload package (on this PC)
```powershell
.\deploy\build-release.ps1
```
Creates `release\neweratrading.zip`. It contains the Laravel app **and** the website (HTML/CSS/JS) inside `backend/public`,
so the site and `/api` live on one domain. It never contains your local `.env`, databases or logs.

## 2. Upload and point the domain
Unzip on the server, e.g. to `/var/www/neweratrading`. The domain's **document root must be `backend/public`**.

**Apache** (the `.htaccess` in `public/` already does the routing, security headers and blocks dot-files):
```apache
<VirtualHost *:443>
    ServerName neweratrading.net
    DocumentRoot /var/www/neweratrading/backend/public
    <Directory /var/www/neweratrading/backend/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on  # + your certificate lines
</VirtualHost>
```
**Nginx:**
```nginx
server {
    listen 443 ssl http2;
    server_name neweratrading.net;
    root /var/www/neweratrading/backend/public;
    index index.html index.php;
    client_max_body_size 16m;                       # KYC uploads (3 files x 4 MB)

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(sql|gz|zip|log|bak|sqlite)$ { deny all; }
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
```
Also set in `php.ini`: `upload_max_filesize = 8M`, `post_max_size = 16M`, `expose_php = Off`.

Permissions (Linux): `chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache`.

## 3. Database (a dedicated, locked-down one)
Use the files in `deploy/database/` - they create the database and a **least-privilege user** (the website must never use `root`):
```bash
# 1. put a long random password into the file (replace __APP_PASSWORD__), then:
sed -i "s/__APP_PASSWORD__/YOUR-24+-CHARACTER-PASSWORD/g" deploy/database/setup-database.sql
mysql -u root -p < deploy/database/setup-database.sql
# 2. production settings (bind to 127.0.0.1, no LOAD DATA LOCAL, slow-query log, binary log, 600 connections ...)
sudo cp deploy/database/my.production.cnf /etc/mysql/mariadb.conf.d/99-neweratrading.cnf && sudo systemctl restart mariadb
```
Then in `backend/.env`: `DB_USERNAME=neweratrading_app`, `DB_PASSWORD=...`. The full structure of every table (columns, indexes, foreign keys) is in
`deploy/database/SCHEMA.md` (`php artisan app:db-schema` regenerates it). Never open port 3306 to the internet.

## 4. Configure
```bash
cd /var/www/neweratrading/backend
cp .env.production.example .env
nano .env                       # fill every line marked <<<   (DB, SMTP, domain)
php artisan key:generate --force
```
Keep `DEMO_MODE=false`, `APP_DEBUG=false`, `APP_ENV=production`. Back up the `.env` file somewhere safe (it holds `APP_KEY`).

## 5. Install (creates tables, the admin, the company root account — no demo data)
```bash
php artisan app:install --admin-email=you@neweratrading.net --deposit-address=0xYourCompanyWallet...
```
If you do not pass `--admin-password` a strong one is generated and shown **once**. The company account `NET1001` is the
first sponsor; share `https://neweratrading.net/register.html?sponsor=NET1001` (members then share their own links).

## 6. Scheduler (ROI + compounding, team ranking, deposit re-checks, nightly backup) — one cron line
```
* * * * * cd /var/www/neweratrading/backend && php artisan schedule:run >> /dev/null 2>&1
```
(cPanel → Cron Jobs → every minute.) Without it **no ROI is credited**.

## 7. E-mail: make it arrive (not in spam)
1. Put the SMTP details in `.env`, then test: `php artisan app:test-mail you@gmail.com`
2. In your DNS add, from your mail provider's instructions: **SPF** (TXT), **DKIM** (TXT/CNAME) and **DMARC**
   (`_dmarc` TXT: `v=DMARC1; p=none; rua=mailto:support@neweratrading.net`). Without these OTP mails land in spam.
3. The sender is always `New Era Trading <no-reply@neweratrading.net>`.

## 8. Deposits and payouts — how the money flows
* **Deposit:** member sends USDT (BEP20) to your wallet and submits the transaction hash. With `ETHERSCAN_API_KEY` set the
  site checks the hash on-chain (correct token, correct recipient, amount) and shows ✔ / mismatch in *Admin → Deposits*.
  Tick *Credit deposits automatically…* in *Plan Settings* to credit verified deposits without clicking; otherwise you approve each one.
  Auto-credit only happens when the transaction was **sent from the wallet address saved in the member's profile** (a transaction hash is
  public, so without this check anybody could submit somebody else's payment). Any other deposit stays pending, with the real sender
  address written in the note for you to compare before approving.
* **Withdrawal:** member requests (needs verified e-mail + approved KYC + transaction PIN + an e-mailed code, Mon–Fri 10:00–14:00 IST).
  You send the USDT from **your own wallet**, then press *Approve* and paste the payout hash — the member gets it by e-mail.
  The server deliberately holds **no wallet key**: automating payouts means a hacked server could drain the company wallet.
* *Admin → KYC* reviews documents (stored outside the web folder, viewable only by admins, every view is logged).
* *Admin → Audit Log* shows who did what, when, from which IP.

## 9. Final check, then optimise
```bash
php artisan app:check-production        # must show 0 FAIL
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
After every `.env` change run `php artisan config:cache` again.

## 10. Backups — and a restore drill
* Nightly 02:30 the scheduler writes `storage/app/backups/db-*.sql.gz` (+ `kyc-*.zip`) and keeps the newest `BACKUP_KEEP` (14).
* **Copy them off the server** (set `BACKUP_COPY_PATH` to a mounted/synced folder, or pull them with rsync/SFTP). A backup on the same disk dies with the disk.
* Run it by hand any time: `php artisan app:backup`
* **Test a restore once before you need it** — into a spare database, never the live one:
  `DB_DATABASE=neweratrading_restoretest php artisan app:restore storage/app/backups/db-YYYYMMDD-HHMMSS.sql.gz --force`

## 11. Updating the site later
Build a new zip, upload over the old files (keep `.env` and `storage/`), then:
`php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache`

## 12. Traffic: 1000 people online at once (measured)
**How it was tested** (`deploy/load-test/`): a private Apache with mod_php + OPcache, production caches (`config:cache`, `route:cache`),
MariaDB with **5,013 members**, and **1,000 virtual users that each have their own login**, keep-alive off, worker threads capped at 128
(= PHP-FPM `pm.max_children`), on a **2-core / 4-thread laptop with 7.7 GB RAM that also ran the load generator**. Run it yourself on a copy of your server:
`prepare-load-db.ps1`, `make-apache-config.ps1`, `run-load-test.ps1` (Windows) or `php backend/tests/loadtest_users.php <url> <tokens> 1000 60 15000 45000` anywhere.

| Profile (1000 members online) | Result |
|---|---|
| **Realistic** - each opens a page every 15-45 s | **0 errors**. The first 30 s are a "cold start" (1000 different dashboards are built for the first time): up to 8-18 s while the queue drains; then **150-600 ms**. |
| **Busy** - a request every 3-8 s each | **0 errors**, 60 requests/s served, requests wait in the queue (this laptop cannot do more) |
| **Stress** - everybody reloads every 1-3 s | **0 errors**, 56 requests/s served, queue latency 10-18 s |

* **Nothing crashed or failed** at 1000 simultaneous users in any profile - overload shows as waiting, not as errors (workers are capped, extra requests queue; the database never gets more connections than the workers).
* A request costs **~13 ms (Laravel start) + 10-30 ms (a member's dashboard)** of CPU; this 2-core laptop sustains ~60 requests/s. 1000 people each opening a page every ~20 s is ~50 requests/s, so this laptop is right at its limit; **a 4-core server serves about twice that, an 8-core server 4-5 times** (extrapolated - measure on your own server).
* Before the optimisations the same test gave 18-26 requests/s, 25-second answers and 33% errors. What changed: team ranking moved out of web requests into a once-a-minute job (`net:stats`), dashboards read only the columns they need and are cached for a few seconds per member (dropped on the member's own writes), settings/public numbers are cached, Sanctum no longer writes `last_used_at` on every call, mails are sent after the response, cache failures can no longer fail a request, and `config:cache`/`route:cache` are used.

**Sizing for a real launch (1000+ online):**
| Part | Recommendation |
|---|---|
| Server | 4-8 vCPU, 8-16 GB RAM, SSD (Linux). Web server **nginx + PHP-FPM**, OPcache on (`opcache.validate_timestamps=0`, 256 MB) |
| PHP-FPM | `pm = static`, `pm.max_children = 4 x CPU cores` (e.g. 32). More requests wait in the queue instead of exhausting the database |
| MariaDB | `deploy/database/my.production.cnf` (buffer pool ~60% of RAM, `max_connections` well above `pm.max_children`), same machine or a fast private network |
| Cache | `CACHE_STORE=redis` (or `file` on one server). Never `database` under load |
| In front | **Cloudflare** (free plan is enough to start): DDoS protection, caches the static site, rate limits. Keep `TRUSTED_PROXIES=*` so the real client IP is used |
| Scheduler | the one cron line - `net:stats` (every minute), `net:accrue` (ROI + compounding), backups |

**Known limits - plan for them as you grow**
* A member's dashboard downloads their *whole team*: fine for a few thousand people below a member; a leader with 5,000+ people needs the team pages paginated.
* The admin dashboard loads *everything*; fine to a few thousand members, past ~20,000 it needs paging / search on the server.
* All numbers above are from Windows with a laptop CPU; a Linux server with PHP-FPM and Redis is faster per request, but verify with the same test on your staging server **before** launch day.

## 13. Security checklist (what is built in, what is on you)
**Built in:** passwords hashed (bcrypt), 8+ characters with letters and digits; 5 wrong passwords lock the account for 15 min; transaction PIN (5 wrong tries = 15 min lock);
e-mailed codes for password change / reset, **every withdrawal and every P2P transfer** (each bound to the exact request), and for **admin sign-in** (`ADMIN_2FA=true`);
login tokens expire (24 h members, 2 h admins); per-user rate limits; KYC documents outside the web root; append-only audit log; every money action is inside a
database transaction with row locks; all SQL parameterised; CSP / nosniff / frame-deny headers; cache failures cannot take the site down; `app:check-production` refuses to call a weak setup "ready".

**On you:** HTTPS everywhere (HSTS once it works) - Cloudflare or Let's Encrypt; firewall (open 80/443 only, SSH by key only, `fail2ban`); keep PHP / MariaDB / OS updated
(`composer audit` before each release); the admin password in a password manager and the admin mailbox protected with its own 2FA; no secrets in the repository (`.env` stays on the server);
off-server backups and a tested restore; **a penetration test by a third party before real money flows**; and a lawyer's review.

## 14. Things still on you
* **Legal review** (see top) and Terms / Privacy / risk-disclosure pages written for your country.
* **A server firewall** (open 80/443 only; SSH keys only), OS updates, and database not exposed to the internet.
* **Admin password** in a password manager; consider restricting `/admin/*.html` by IP at the web-server level.
* Monitoring: watch `storage/logs/laravel-*.log` and an uptime monitor on `https://neweratrading.net/up`.
* Support mailbox for `support@neweratrading.net`.
