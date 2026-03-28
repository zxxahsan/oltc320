# Gembok - Simple ISP & Hotspot Management

Gembok adalah aplikasi pengelolaan ISP RT/RW Net dan layanan WiFi Hotspot yang ringan, modern, dan canggih. Gembok mendukung manajemen tiket teknisi (laporan gangguan), pembuatan *voucher*, notifikasi *WhatsApp*, integrasi otomatis ke Mikrotik (Isolir/Unisolir), pengelolaan PPOE pelanggan, portal pembayaran (Payment Gateway Tripay/Midtrans), dan Sistem *Full Backup* terpusat.

Didesain agar sangat mudah dijalankan di atas **Ubuntu Server** dengan **Nginx** sebagai web server.

---

## 🌟 Fitur Utama
1. **Otomatisasi Mikrotik**: Integrasi langsung dengan API Mikrotik untuk tendang (*kick*) dan ubah profil Isolir / Aktif saat pembayaran lunas.
2. **Dashboard Admin**: Pengelolaan pelanggan, sinkronisasi data *voucher*, manajemen *router*, dan konfigurasi master server.
3. **Portal Pelanggan & Teknisi**: Fitur khusus bagi pelanggan untuk memantau tagihan dan mengajukan lapor gangguan. Portal teknisi bertugas mengirim foto bukti lapangan.
4. **Notifikasi WhatsApp**: Pemberitahuan otomatis ketika tagihan keluar, tiket teknisi ditugaskan, hingga konfirmasi pembayaran.
5. **Full System Backup**: Menu cerdas untuk membungkus 100% kode + *database* menjadi ekstensi Zip yang dapat langsung dipulihkan (Restore) dengan sekali klik.
6. **Smart Updater**: Integrasi langsung auto-update yang memungkinkan Anda menarik (*pull*) pembaruan GitHub hanya lewat ketukan tombol di Panel Admin.

---

## 🛠️ Persyaratan Sistem
Aplikasi ini kompatibel dengan lingkungan berbasis Linux (LEMP Stack):
- **OS**: Ubuntu Server 20.04 LTS / 22.04 LTS (Disarankan)
- **Web Server**: Nginx
- **Database**: MySQL atau MariaDB
- **PHP**: PHP 7.4 / 8.1 / 8.2 beserta paket ekstensinya (gd, pdo_mysql, curl, mbstring, zip)

---

## 🚀 Panduan Instalasi (Ubuntu Server Fresh)

Langkah-langkah berikut akan menuntun Anda menginstal Gembok pada server Ubuntu yang baru. Disarankan masuk sebagai pengguna `root` atau menggunakan perintah `sudo`.

### 1. Update Server & Instalasi Komponen Inti
```bash
sudo apt update && sudo apt upgrade -y

# Instalasi Nginx, MariaDB, Git, Unzip, dan PHP beserta ekstensinya
sudo apt install -y nginx mariadb-server git unzip php-fpm \
php-mysql php-gd php-curl php-mbstring php-xml php-zip \
php-cli
```

### 2. Konfigurasi MariaDB (Database)
Amankan instalasi MySQL/MariaDB:
```bash
sudo mysql_secure_installation
```
Selanjutnya, buat database dan user baru untuk Gembok:
```bash
sudo mysql -u root
```
Di dalam konsol MySQL teks layar berganti, salin ini:
```sql
CREATE DATABASE gembok_db;
CREATE USER 'gembok_user'@'localhost' IDENTIFIED BY 'passwordKuat123';
GRANT ALL PRIVILEGES ON gembok_db.* TO 'gembok_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 3. Mengunduh Aplikasi (Clone)
Dianjurkan untuk menaruh file aplikasi pada *root* direktori buatan sendiri (`/var/www/gembok`) agar lebih terbebas dari file bawaan *default* web server:
```bash
sudo mkdir -p /var/www/gembok
cd /var/www/gembok

# Clone Gembok dari Github
sudo git clone https://github.com/zxxahsan/gembok.git .
```
*(Catatan: Tanda titik `.` pada akhir perintah bertujuan agar file diekstrak ke dalam folder saat ini dan tidak menciptakan grup sub-folder lagi).*

### 4. Mengatur File Konfigurasi Gembok
Duplikasi file `config.sample.php` menjadi `config.php` dan edit informasinya:
```bash
sudo cp includes/config.sample.php includes/config.php
sudo nano includes/config.php
```
Carilah bagian konfigurasi Database dan ubah nilainya persis seperti yang Anda atur di Tahap 2:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gembok_db');
define('DB_USER', 'gembok_user');
define('DB_PASS', 'passwordKuat123');
```
*(Tekan `CTRL+O`, `Enter`, lalu `CTRL+X` untuk menyimpan).*

### 5. Memberikan Izin Akses (Permissions)
Langkah ini sangat krusial agar Nginx dan PHP memiliki wewenang untuk menulis log, membuat file .zip backup, dan mengolah foto instalasi buatan Teknisi.
```bash
sudo chown -R www-data:www-data /var/www/gembok
sudo chmod -R 755 /var/www/gembok
```

### 6. Mentautkan Nginx dengan PHP-FPM
Agar Nginx bisa membaca file `.php`, kita perlu mengubah konfigurasi *Virtual Host* bawaannya:
```bash
sudo nano /etc/nginx/sites-available/default
```
Cari pengaturan berikut dan modifikasi agar tampak seperti ini (sesuaikan angka versi PHP-FPM Anda, misal `8.1` atau `7.4`):
```nginx
server {
    ...
    # Tunjuk direktori file web utama (root) ke gembok
    root /var/www/gembok;

    # Tambahkan index.php pada prioritas utama
    index index.php index.html index.htm index.nginx-debian.html;

    ...
    
    # Hapus tanda pagar (#) pada blok pembacaan php:
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        
        # Opsi ini wajib disamakan dengan versi instalasi PHP Anda
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }
    
    # Mencegah akses publik ke folder internal (Keamanan ekstra)
    location ~ /\.ht {
        deny all;
    }
}
```
Cek apakah konfigurasi Nginx aman dari salah ketik, kemudian *restart* Nginx:
```bash
sudo nginx -t
sudo systemctl restart nginx
```

### 7. Tahap Penyelesaian (Web Installer)
Kini server ISP Anda telah mandiri dan siap melayani!
1. Buka Google Chrome di perangkat mana pun dan akses IP Server Ubuntu Anda.
   **`http://IP_SERVER_ANDA/install.php`**
2. Ikuti instruksi pendaftaran akun Admin, sistem otomatis akan menuangkan seluruh isi *database* aplikasi.
3. Setelah *dashboard* Admin tebuka, pastikan Anda juga mengakses URL ini minimal 1x:
   **`http://IP_SERVER_ANDA/update_db_technician.php`** 
   Langkah ini menjamin infrastruktur struktur tabel fitur **Multi-Foto** milik teknisi tereksekusi tanpa keluhan *error* di masa depan.

### 8. Pemasangan Otomatisasi Mikrotik (Cronjob)
Agar penagihan otomatis dan sistem tendang (*Kick/Isolir*) pelanggan via Mikrotik berjalan mulus tanpa campur tangan manusia, silakan jalankan *script* pemasang cron bawaan Gembok:
```bash
sudo bash /var/www/gembok/install_cron.sh
```
*(Script ini akan menanamkan perintah ke sistem Ubuntu Anda untuk mengeksekusi `scheduler.php` secara stabil 1x setiap menit).*

> **PENTING**: Ketika segala fitur telah dites berjalan lancar, Anda berkewajiban menghapus file instalasi untuk mencegah penyusup mereset *database* Anda.
> `sudo rm -f /var/www/gembok/install.php`

---

✨ **Selamat, Gembok telah sukses di-deploy dan siap dikelola 100%!** ✨
