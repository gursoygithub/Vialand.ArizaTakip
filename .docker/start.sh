#!/bin/bash

echo "Fixing Laravel permissions..."

# Create directories
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache
mkdir -p /var/run
mkdir -p /var/log/supervisor

# Fix ownership (now UID matches host)
chown -R www-data:www-data /var/www/storage
chown -R www-data:www-data /var/www/bootstrap/cache

chmod -R ug+rwx /var/www/storage
chmod -R ug+rwx /var/www/bootstrap/cache

echo "Clearing Laravel cache..."

php artisan optimize:clear || true
php artisan config:cache || true

echo "Starting Supervisor..."

exec /usr/bin/supervisord -n -c /etc/supervisord.conf
