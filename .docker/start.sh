#!/bin/bash

# Ensure necessary directories exist
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache

# Set proper permissions
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R ug+rwx /var/www/storage /var/www/bootstrap/cache

# Laravel setup
php /var/www/artisan cache:clear
php /var/www/artisan config:clear
php /var/www/artisan view:clear
php /var/www/artisan config:cache
php /var/www/artisan storage:link

# Optional: Run background services with supervisord if role is 'background'
role=${CONTAINER_ROLE:-app}

echo "Starting services"
service php8.4-fpm start
nginx -g "daemon off;"

if [ "$role" = "background" ]; then
    supervisord -n -c /etc/supervisor/supervisord.conf
fi
