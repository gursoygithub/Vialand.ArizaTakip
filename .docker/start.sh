#!/bin/bash

echo "Preparing Laravel environment..."

# Create required directories
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache
mkdir -p /var/run
mkdir -p /var/log/supervisor

# HARD FIX permissions (volume mount sorununu garanti çözer)
chmod -R 777 /var/www/storage
chmod -R 777 /var/www/bootstrap/cache

echo "Clearing Laravel cache..."

php artisan optimize:clear || true
php artisan config:cache || true

echo "Starting Supervisor..."

exec /usr/bin/supervisord -n -c /etc/supervisord.conf
