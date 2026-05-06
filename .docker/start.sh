#!/bin/bash
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 777 /var/www/storage /var/www/bootstrap/cache

php /var/www/artisan cache:clear
php /var/www/artisan config:clear
php /var/www/artisan view:clear
php /var/www/artisan config:cache
php /var/www/artisan storage:link

echo "Starting services"
supervisord -n -c /etc/supervisor/supervisord.conf