#!/bin/bash
set -e

# Pastikan folder apache dan var/www dimilliki oleh www-data
chown -R www-data:www-data /var/www/html/

# Jalankan daemon Gembok PHP di background untuk memicu cronjobs
echo "[START] Menjalankan Gembok Daemon Loop untuk Cronjobs..."
php /var/www/html/cron/daemon.php &

# Jalankan Apache2 di foreground agar container tetap hidup
echo "[START] Menjalankan Apache2 Webserver..."
source /etc/apache2/envvars
exec apache2 -D FOREGROUND
