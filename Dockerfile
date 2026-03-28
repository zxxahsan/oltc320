FROM ubuntu:22.04

# Abaikan input interaktif selama instalasi paket APT
ENV DEBIAN_FRONTEND=noninteractive

# Update dan instal webserver, PHP, dan ekstensi database yang esensial
RUN apt-get update && apt-get install -y \
    apache2 \
    php \
    php-cli \
    php-mysql \
    php-pdo \
    php-curl \
    php-mbstring \
    php-dom \
    php-zip \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Aktifkan modul mod_rewrite Apache untuk .htaccess
RUN a2enmod rewrite

# Konfigurasi Apache (opsional bisa mengubah document root jika diperlukan)
# COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Hapus index.html default Ubuntu
RUN rm -f /var/www/html/index.html

# Copy semua file aplikasi Gembok ke direktori webserver container
COPY . /var/www/html/

# Beri akses eksekusi ke start.sh
RUN chmod +x /var/www/html/start.sh

# Pasang kepemilikan direktori utama ke www-data agar bisa upload data
RUN chown -R www-data:www-data /var/www/html/

# Expose port HTTP
EXPOSE 80

# Jalankan skrip startup yang akan menghidupkan Apache & PHP Daemon Cron
CMD ["/var/www/html/start.sh"]
