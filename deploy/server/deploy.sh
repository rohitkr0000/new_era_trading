#!/usr/bin/env bash
# =====================================================================
#  New Era Trading - install / update the site on the server.  Run as root:
#      bash /var/www/neweratrading/deploy/server/deploy.sh
#
#  First time: after setup-server.sh and after filling backend/.env.
#  Later updates: the same command (it pulls the newest code from GitHub first).
#  Run it again after every change to backend/.env (the settings are cached).
#
#  Pass  --no-pull  to skip "git pull" (e.g. when you uploaded the files by hand).
# =====================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/neweratrading}"
PHPV="8.3"
[ "$(id -u)" = "0" ] || { echo "Run as root."; exit 1; }
cd "$APP_DIR"

if [ "${1:-}" != "--no-pull" ] && [ -d .git ]; then
  echo "== 1/6  newest code from GitHub =="
  git pull --ff-only
else
  echo "== 1/6  (skipping git pull) =="
fi

echo "== 2/6  PHP packages =="
cd "$APP_DIR/backend"
[ -f .env ] || { echo "backend/.env is missing - run setup-server.sh first."; exit 1; }
if grep -q '^DB_PASSWORD=<<<' .env; then echo "backend/.env still has DB_PASSWORD=<<< - run setup-server.sh first."; exit 1; fi
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

echo "== 3/6  publishing the website next to the API (backend/public) =="
PUB="$APP_DIR/backend/public"
for f in index.html login.html register.html forgot.html calculator.html; do cp "$APP_DIR/$f" "$PUB/$f"; done
for d in css fonts images js user admin; do
  mkdir -p "$PUB/$d"
  rsync -a --delete "$APP_DIR/$d/" "$PUB/$d/"
done

echo "== 4/6  settings and database =="
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force      # only the very first time (never changes an existing key)
php artisan migrate --force

echo "== 5/6  speed caches =="
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "== 6/6  permissions and reload =="
chown root:www-data .env; chmod 640 .env
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
systemctl reload "php$PHPV-fpm"        # OPcache keeps the old code until this
systemctl reload nginx

echo
echo "Done. Check:  curl -I http://127.0.0.1/up   (should say 200)"
echo "Then run:     php artisan app:check-production"
