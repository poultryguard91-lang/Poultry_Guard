#!/bin/sh
set -e
PORT="${PORT:-8080}"

# Exactly one MPM may be loaded (mod_php requires prefork).
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
sed -ri "s/Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Create the schema on first boot (no-op once the tables exist).
php /var/www/html/deploy/init-db.php || echo "[PoultryGuard] DB init failed; continuing" >&2

exec apache2-foreground
