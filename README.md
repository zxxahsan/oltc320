# 🚀 Panduan Instalasi Gembok (Ubuntu Server Container)

Panduan ini disusun khusus untuk instalasi di **MikroTik Container (ROS 7)** atau **Ubuntu Server Minimal** yang tidak memiliki `systemd`. Ikuti langkah-langkah di bawah ini secara berurutan.

---

## 🛠️ Langkah 1: Instalasi Paket Core (Copy & Paste)
Jalankan perintah ini di Terminal/Shell Ubuntu Anda:
```bash
apt update && apt upgrade -y && \
apt install -y nginx mariadb-server git unzip php-fpm php-mysql php-gd php-curl php-mbstring php-xml php-zip php-cli nano
```

### Jalankan Service (Tanpa systemctl)
Karena di Container `systemctl` tidak aktif, gunakan perintah ini:
```bash
/etc/init.d/mysql start
/etc/init.d/nginx start
/etc/init.d/php$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")-fpm start
```

---

## 💾 Langkah 2: Setup Database
Copy & Paste baris per baris ke Terminal:
```bash
mysql -u root -e "CREATE DATABASE gembok_db;"
mysql -u root -e "CREATE USER 'gembok_user'@'localhost' IDENTIFIED BY 'passwordKuat123';"
mysql -u root -e "GRANT ALL PRIVILEGES ON gembok_db.* TO 'gembok_user'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;"
```

---

## 🌐 Langkah 3: Nginx Virtual Host (Copy & Paste)
Buka file konfigurasi default Nginx:
```bash
nano /etc/nginx/sites-available/default
```
**Hapus semua carries** di dalam file tersebut dan ganti dengan kode di bawah ini:
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html;
    index index.php index.html index.htm;

    client_max_body_size 100M;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        # Sesuaikan versi PHP jika berbeda (cek dengan: ls /var/run/php/)
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```
*Simpan dengan menekan `CTRL+O`, `Enter`, lalu `CTRL+X`.*

Setelah itu, buat link ke php-fpm yang benar agar Nginx bisa mendeteksi socketnya (opsional jika error):
```bash
ln -s /var/run/php/php$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")-fpm.sock /var/run/php/php-fpm.sock
/etc/init.d/nginx restart
```

---

## 📂 Langkah 4: Download & Izin Folder
Jalankan perintah ini untuk mengambil aplikasi:
```bash
cd /var/www/html
rm -rf *
git clone https://github.com/zxxahsan/gembokcontainer.git .
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
```

---

## ⚙️ Langkah 5: Finalisasi via Browser
1. Buka browser dan akses **IP Address Container** Anda.
2. Anda akan melihat **Installer Wizard**.
3. Masukkan data database sesuai Langkah 2:
   - **DB Host:** `localhost`
   - **DB Name:** `gembok_db`
   - **DB User:** `gembok_user`
   - **DB Pass:** `passwordKuat123`
4. Selesaikan instalasi sampai muncul tombol **Masuk ke Admin**.

---

## 🔁 Langkah 6: Setup Scheduler (Wajib!)
Agar Isolir Otomatis dan Notifikasi berjalan, gunakan Scheduler dari RouterOS Anda (Sangat Disarankan):

1. Buka **WinBox** -> **System** -> **Scheduler**.
2. Buat jadwal baru (Name: `Gembok_Cron`, Interval: `00:01:00`).
3. Isi script berikut (Ganti `[IP_CONTAINER]` dengan IP Ubuntu Anda):
   ```routeros
   /tool fetch url="http://[IP_CONTAINER]/cron/scheduler.php" keep-result=no;
   ```

---

## ❓ Troubleshooting (FAQ)
Jika muncul error **"413 Request Entity Too Large"** saat upload backup, pastikan `client_max_body_size 100M;` sudah ada di config Nginx seperti pada Langkah 3 di atas.

Jika cron tidak berjalan, pastikan file `/var/www/html/includes/config.php` sudah terisi dengan benar (otomatis terisi setelah Langkah 5).
