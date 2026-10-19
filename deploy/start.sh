#!/bin/sh
set -e
PORT="${PORT:-8080}"

# Exactly one MPM may be loaded (mod_php requires prefork).
# 1) Drop every enabled MPM module, then enable prefork only.
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# 2) Comment out any other LoadModule mpm_* (non-prefork) hiding in other config files.
grep -rlE '^\s*LoadModule\s+mpm_(event|worker)_module' /etc/apache2 2>/dev/null \
    | while read -r f; do sed -ri 's/^(\s*LoadModule\s+mpm_(event|worker)_module.*)$/# \1/' "$f"; done

echo "[start] MPM modules loaded:"
apache2ctl -M 2>&1 | grep -i mpm || true

sed -ri "s/Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Create the schema on first boot (no-op once the tables exist).
php /var/www/html/deploy/init-db.php || echo "[PoultryGuard] DB init failed; continuing" >&2

exec apache2-foreground
