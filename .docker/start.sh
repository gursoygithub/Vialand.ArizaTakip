#!/bin/bash

# Gerekli dizinlerin varlığından emin ol
mkdir -p /var/www/storage/framework/{sessions,cache,views}
mkdir -p /var/www/storage/logs
mkdir -p /var/www/bootstrap/cache
mkdir -p /var/run /var/log/supervisor

# İzinleri ayarla
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Laravel Optimizasyonları
php /var/www/artisan config:clear
php /var/www/artisan cache:clear
php /var/www/artisan config:cache

# Supervisor'ı başlat ve kontrolü ona ver
# -n: nodaemon mode (Docker için gerekli)
# -c: konfigürasyon dosyası yolu
echo "Starting Supervisor (Nginx, PHP-FPM and Scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
