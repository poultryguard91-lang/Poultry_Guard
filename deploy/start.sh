#!/bin/sh
set -e
PORT="${PORT:-8080}"
sed -ri "s/Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Create the schema on first boot (no-op once the tables exist).
php /var/www/html/deploy/init-db.php || echo "[PoultryGuard] DB init failed; continuing" >&2

exec apache2-foreground
