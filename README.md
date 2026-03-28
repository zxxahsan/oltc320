# Panduan Gembok untuk Ubuntu Server di MikroTik Container

Karena *Ubuntu Server* yang berjalan di dalam fitur **Container MikroTik ROS 7** tidak memiliki sistem *init* (seperti `systemd` atau `systemctl`), perintah standar server seperti `systemctl restart nginx` atau fungsi otomatis *Cron Job* bawaan OS Linux **tidak akan berjalan secara otomatis/normal**.

Repositori ini telah disesuaikan dengan skrip khusus untuk menembus keterbatasan *Container MikroTik* tersebut.

---

## 🛠️ Tahap 1: Instalasi Paket Inti di Ubuntu (Via Shell Container)

Pastikan container Ubuntu Anda sudah berstatus `running` di WinBox MikroTik, lalu masuk ke terminal Shell-nya (misal: `/container shell 0`).

Jalankan instalasi Nginx, MariaDB, PHP, dan ekstensi secara manual:
```bash
apt update && apt upgrade -y
apt install -y nginx mariadb-server git unzip php-fpm \
php-mysql php-gd php-curl php-mbstring php-xml php-zip php-cli
```

### Penting: Menyalakan Service Tanpa `systemctl`
Karena `systemctl` mati di lingkungan MikroTik Container, Anda harus menyalakan layanan web & database menggunakan _SystemV Init_:
```bash
/etc/init.d/nginx start
/etc/init.d/mysql start
/etc/init.d/php8.1-fpm start  # (Sesuaikan angka 8.1 dengan versi PHP Anda)
```

---

## 📂 Tahap 2: Setup Database & Gembok

Amankan MariaDB dan buat database:
```bash
mysql -u root

CREATE DATABASE gembok_db;
CREATE USER 'gembok_user'@'localhost' IDENTIFIED BY 'passwordKuat123';
GRANT ALL PRIVILEGES ON gembok_db.* TO 'gembok_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Unduh *source code* khusus container ini ke folder Root:
```bash
cd /var/www/html
rm -rf * index.nginx-debian.html   # Hapus file bawaan Nginx
git clone https://github.com/zxxahsan/gembokcontainer.git .

# Berikan izin tulis krusial
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
```

Jangan lupa untuk mengubah `includes/config.sample.php` menjadi `includes/config.php` dan setting koneksi `gembok_user` Anda!

Konfigurasikan Nginx Virtual Host persis seperti aturan normal Gembok di `/etc/nginx/sites-available/default`. Setelah selesai memodifikasi *Virtual Host*, restart kembali Nginx Anda:
```bash
/etc/init.d/nginx restart
```

*(Kini Anda sudah bisa mengakses Web Installer Gembok via IP Container Anda di Web Browser lokal).*

---

## 🔁 Tahap 3: Solusi Cronjob / Scheduler (SANGAT PENTING!)

Dalam server *bare-metal* Linux, Isolir & Tagihan digerakkan oleh `crontab`. Di dalam Container MikroTik, *Cron service* tidak bisa hidup sendiri secara agresif. Anda punya **2 Opsi Solusi Paling Tepat**:

### Opsi 1: Menggunakan PHP Daemon Loop (Disarankan)
Saya telah merakit skrip khusus `cron/daemon.php` yang memutar waktu (infinity loop 60 detik) untuk memicu task isolir & notifikasi WhatsApp otomatis tanpa campur tangan `crontab` OS.
Nyalakan skrip ini langsung di latar belakang terminal Ubuntu Container:
```bash
php /var/www/html/cron/daemon.php &
```
*Catatan: Anda perlu menjalankan ulang command di atas setiap kali Anda me-restart container Mikrotik.*

### Opsi 2: Eksekusi Langsung dari Scheduller MikroTik (Trigger Luar)
Karena Container ini berada langsung *di dalam* router Anda, Anda bisa menyuruh **RouterOS** yang mengeksekusi *Cron* melalui API `fetch`. Ini sangat paten karena ikut menyala bersama router tanpa error!
1. Buka WinBox -> **System** -> **Scheduler**
2. Buat jadwal baru dengan Interval `00:01:00` (1 Menit)
3. Masukkan script RouterOS berikut (ganti IP dengan IP container Ubuntu Anda):
   ```routeros
   /tool fetch url="http://172.17.0.2/cron/scheduler.php" keep-result=no;
   ```
*(Opsi 2 ini adalah bentuk simbiosis mutualisme paling kokoh antara Gembok dan sistem asli MikroTik Anda!)*

---

## 🛑 Troubleshooting Sering Ditanyakan (FAQ)

**1. Q: Saat Upload File Backup / Setting Logo muncul "413 Request Entity Too Large"**  
**A:** Ini artinya batas unggah Nginx dan PHP bawaan terlalu kecil (biasanya hanya 1-2MB). Anda perlu membesarkannya di sisi Container Anda.
Jalankan di shell Container:
* **Pada Nginx (`/etc/nginx/sites-available/default`)**  
  Tambahkan baris `client_max_body_size 100M;` tepat di bawah `server_name _;`
* **Pada PHP (`/etc/php/VERSION/fpm/php.ini`)**  
  *(Contoh untuk php7.4-fpm)* Buka `nano /etc/php/7.4/fpm/php.ini` cari `upload_max_filesize` ubah menjadi `100M` dan `post_max_size` menjadi `100M`.
* Setelah diubah, jalankan ulang:
  ```bash
  /etc/init.d/nginx restart
  /etc/init.d/php7.4-fpm restart
  ```
