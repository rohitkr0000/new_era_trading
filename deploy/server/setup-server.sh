#!/usr/bin/env bash
# =====================================================================
#  New Era Trading - FIRST-TIME setup of a fresh Ubuntu 22.04 / 24.04 VPS (e.g. Hostinger KVM 4).
#
#  Run ONCE, as root, after the project has been cloned to /var/www/neweratrading:
#      bash /var/www/neweratrading/deploy/server/setup-server.sh yourdomain.com
#
#  It installs and configures: nginx, PHP 8.3-FPM (+ OPcache), MariaDB (tuned, local only), firewall (ufw), fail2ban,
#  certbot, the cron line for the scheduler, and a locked-down database user. It writes backend/.env with the database
#  password it generated. It is safe to run again (it re-applies the same settings).
#
#  It does NOT: install the site (run deploy.sh next), create the admin (php artisan app:install), or get the HTTPS
#  certificate (certbot, after the domain points to this server). See the PDF guide / DEPLOY.md.
# =====================================================================
set -euo pipefail

DOMAIN="${1:-}"
APP_DIR="${2:-/var/www/neweratrading}"
PHPV="8.3"

[ "$(id -u)" = "0" ] || { echo "Run this as root (you are in the browser terminal as root already)."; exit 1; }
[ -n "$DOMAIN" ] || { echo "Usage: bash setup-server.sh yourdomain.com"; exit 1; }
DOMAIN="${DOMAIN#www.}"
[ -d "$APP_DIR/backend" ] || { echo "Cannot find $APP_DIR/backend - clone the project there first."; exit 1; }
HERE="$APP_DIR/deploy"

export DEBIAN_FRONTEND=noninteractive
CORES="$(nproc)"
RAM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
echo "Server: $CORES CPU core(s), ${RAM_MB} MB RAM, domain: $DOMAIN"
[ "$RAM_MB" -ge 3500 ] || echo "WARNING: less than 4 GB RAM - this will not hold 1000 people online. Use KVM 4 (16 GB)."

echo; echo "== 1/9  installing packages (a few minutes) =="
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl git unzip rsync ufw fail2ban nginx mariadb-server certbot python3-certbot-nginx openssl
if [ "$(lsb_release -rs)" != "24.04" ]; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi
apt-get install -y "php$PHPV-fpm" "php$PHPV-cli" "php$PHPV-common" "php$PHPV-mysql" "php$PHPV-mbstring" "php$PHPV-xml" "php$PHPV-curl" "php$PHPV-zip" "php$PHPV-bcmath" "php$PHPV-intl" "php$PHPV-opcache" composer
update-alternatives --set php "/usr/bin/php$PHPV" || true      # make sure "php" means 8.3 (Laravel 12 needs 8.2+)

echo; echo "== 2/9  firewall (only SSH, HTTP, HTTPS are open) =="
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
systemctl enable --now fail2ban

echo; echo "== 3/9  MariaDB (tuned, reachable from this machine only) =="
BP_MB=$(( RAM_MB * 55 / 100 ))
CNF=/etc/mysql/mariadb.conf.d/99-neweratrading.cnf
sed -e "s/^innodb_buffer_pool_size .*/innodb_buffer_pool_size       = ${BP_MB}M/" \
    -e "s/^innodb_buffer_pool_instances/loose-innodb_buffer_pool_instances/" \
    -e "s/^expire_logs_days/loose-expire_logs_days/" \
    "$HERE/database/my.production.cnf" > "$CNF"
mkdir -p /var/log/mysql && chown mysql:mysql /var/log/mysql
mkdir -p /var/lib/mysql-files && chown mysql:mysql /var/lib/mysql-files && chmod 750 /var/lib/mysql-files
systemctl restart mariadb || { echo "MariaDB did not start with the tuned settings. Output:"; journalctl -u mariadb --no-pager -n 30; exit 1; }

echo; echo "== 4/9  database + a locked-down user for the website =="
DB_PASS="$(openssl rand -hex 16)"
TMP_SQL="$(mktemp)"
sed "s/__APP_PASSWORD__/$DB_PASS/g" "$HERE/database/setup-database.sql" > "$TMP_SQL"
mysql < "$TMP_SQL"
rm -f "$TMP_SQL"

echo; echo "== 5/9  PHP-FPM + OPcache =="
CAP_CPU=$(( CORES * 4 ))
CAP_RAM=$(( RAM_MB * 40 / 100 / 80 ))        # ~80 MB per worker, at most 40% of RAM
CHILDREN=$(( CAP_CPU < CAP_RAM ? CAP_CPU : CAP_RAM )); [ "$CHILDREN" -ge 4 ] || CHILDREN=4
[ -f "/etc/php/$PHPV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PHPV/fpm/pool.d/www.conf" "/etc/php/$PHPV/fpm/pool.d/www.conf.disabled"
cat > "/etc/php/$PHPV/fpm/pool.d/neweratrading.conf" <<EOF
[neweratrading]
user = www-data
group = www-data
listen = /run/php/neweratrading.sock
listen.owner = www-data
listen.group = www-data
pm = static
pm.max_children = $CHILDREN
pm.max_requests = 500
request_terminate_timeout = 60s
EOF
cat > "/etc/php/$PHPV/fpm/conf.d/99-neweratrading.ini" <<EOF
expose_php = Off
upload_max_filesize = 8M
post_max_size = 16M
memory_limit = 256M
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
systemctl enable "php$PHPV-fpm"
systemctl restart "php$PHPV-fpm"
echo "PHP-FPM: $CHILDREN workers"

echo; echo "== 6/9  nginx =="
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" "$HERE/server/nginx-site.conf" > /etc/nginx/sites-available/neweratrading
ln -sf /etc/nginx/sites-available/neweratrading /etc/nginx/sites-enabled/neweratrading
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx
systemctl reload nginx

echo; echo "== 7/9  settings file (backend/.env) =="
ENVF="$APP_DIR/backend/.env"
if [ ! -f "$ENVF" ]; then cp "$APP_DIR/backend/.env.production.example" "$ENVF"; fi
setenv() { # setenv KEY VALUE  -> replaces the whole line  KEY=...
  if grep -q "^$1=" "$ENVF"; then sed -i "s|^$1=.*|$1=$2|" "$ENVF"; else echo "$1=$2" >> "$ENVF"; fi
}
setenv APP_URL "https://$DOMAIN"
setenv FRONTEND_URL "https://$DOMAIN"
setenv CORS_ALLOWED_ORIGINS "https://$DOMAIN"
setenv DB_PASSWORD "$DB_PASS"
setenv TRUSTED_PROXIES ""                 # no Cloudflare / proxy in front yet: trust nobody's X-Forwarded-For
setenv MAIL_FROM_ADDRESS "\"no-reply@$DOMAIN\""
setenv SUPPORT_EMAIL "support@$DOMAIN"
chown root:www-data "$ENVF"; chmod 640 "$ENVF"

echo; echo "== 8/9  folders and permissions =="
cd "$APP_DIR/backend"
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/private storage/app/public storage/app/backups bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo; echo "== 9/9  scheduler (ROI, compounding, deposit checks, risk scan, backups) =="
cat > /etc/cron.d/neweratrading <<EOF
* * * * * www-data cd $APP_DIR/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/neweratrading

echo
echo "====================================================================="
echo " Server is ready.  NEXT STEPS"
echo "  1. nano $ENVF      -> fill MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD (and ETHERSCAN_API_KEY)"
echo "  2. bash $APP_DIR/deploy/server/deploy.sh"
echo "  3. certbot --nginx -d $DOMAIN -d www.$DOMAIN      (the domain must already point to this server)"
echo "  4. cd $APP_DIR/backend && php artisan app:install --admin-email=YOU@EMAIL --deposit-address=0xYOUR_WALLET"
echo " The database password was written into $ENVF - you never need to type it."
echo "====================================================================="
