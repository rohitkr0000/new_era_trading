#!/usr/bin/env bash
# Run any "php artisan ..." command as the web user (www-data), so the log/cache files it creates stay writable by the website.
#   bash /var/www/neweratrading/deploy/server/artisan.sh app:check-production
#   bash /var/www/neweratrading/deploy/server/artisan.sh app:install --admin-email=you@x.com --deposit-address=0x...
APP_DIR="${APP_DIR:-/var/www/neweratrading}"
[ "$(id -u)" = "0" ] || { echo "Run as root."; exit 1; }
cd "$APP_DIR/backend" && exec runuser -u www-data -- php artisan "$@"
