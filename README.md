# Gembok Container (MikroTik ROS 7 Edition)

Repositori ini adalah versi **Dockerized / Container** dari aplikasi *Gembok - Simple ISP & Hotspot Management* yang dioptimalkan khusus untuk berjalan di atas fitur **Container MikroTik RouterOS v7**. 

Lingkungan ini secara natif mem-bypass kelemahan spesifik fitur *Linux crontab* pada MikroTik Container dengan menggunakan simulasi **PHP Daemon Loop** interaktif, sehingga seluruh pengecekan tagihan, otomatisasi *Isolir/Unisolir* PPPoE, dan Sinkronisasi Notifikasi berjalan mulus 100% tepat setiap menit tanpa intervensi manual.

---

## 🌟 Fitur Unggulan Versi Container
1. **Otomatisasi Penuh di Dalam Router**: Berjalan secara langsung di satu box MikroTik Anda. Hemat daya, hemat listrik, tidak perlu server PC tambahan.
2. **PHP Daemon Cron**: Pengganti tangguh untuk *OS Schedulers*. Anda tidak akan pernah mengalami gagal reset tagihan karena daemon berjalan stabil (`cron/daemon.php`).
3. **Pra-Konfigurasi Apache & PHP**: *Dockerfile* yang tersedia memuat spesifikasi paket ekstensi (curl, pdo, gd, zip) versi paling pas.

---

## 🛠️ Persyaratan Sistem MikroTik
1. Perangkat menggunakan arsitektur **ARM, ARM64, atau x86**. (Contoh: seri *RB5009*, *RB4011*, *CHR*, *x86 PC*).
2. Sistem operasi minimal **RouterOS v7.4+** dengan paket **`container`** (`container.npk`) yang sudah diunduh dan dipasang aktif.
3. Fitur **Device Mode Container** sudah diaktifkan di terminal MikroTik (`/system/device-mode/update container=yes`, lalu *hard-reboot*).
4. **Media Penyimpanan Tambahan** (Flashdisk USB, SD Card, atau disk sekunder) untuk memuat *Image* dan *Mounts*. Jangan gunakan internal disk bawaan MikroTik!
5. **Database Server Eksternal**: Container ini murni memuat sistem *Web Server Apache & PHP Daemon*. Sistem *Database (MySQL/MariaDB)* bisa Anda pisahkan di *Container* MikroTik yang berbeda, dipasang pada panel *Cloud*, atau server komputer terpisah.

---

## 🚀 Panduan Instalasi (MikroTik ROS v7)

### Langkah 1: Persiapan File & Build Image (Di Komputer Anda)
Karena MikroTik tidak dapat melakukan `docker build` kode *source*, Anda perlu merakit konfigurasinya menjadi sebuah *file image* tarball (`.tar`) di PC Anda (harus terinstal Docker Desktop/Engine).

```bash
# Clone Repositori Ini ke PC Anda
git clone https://github.com/zxxahsan/gembokcontainer.git
cd gembokcontainer

# Build File DockerImage (Beri nama 'gembok-app')
docker build -t gembok-app .

# Konversikan Image menjadi File TAR agar bisa dibaca MikroTik
docker save gembok-app > gembok-app.tar
```

### Langkah 2: Upload File Tar ke MikroTik
1. Buka WinBox.
2. Seret dan jatuhkan (*Drag & Drop*) file `gembok-app.tar` yang baru Anda buat ke menu **Files** di dalam disk ekstensi Anda (misal ke `disk1/gembok-app.tar`).

### Langkah 3: Konfigurasi Virtual Network (VETH) di MikroTik
Container memerlukan "Antarmuka Virtual" dan "IP Address" agar bisa terkoneksi ke jaringan Anda.
Buka **Terminal** WinBox dan salin perintah berikut:

```routeros
# 1. Buat VETH Interface (Misal IP Container: 172.17.0.2)
/interface/veth/add name=veth-gembok address=172.17.0.2/24 gateway=172.17.0.1

# 2. Buat Bridge Khusus Container
/interface/bridge/add name=bridge-container
/ip/address/add address=172.17.0.1/24 interface=bridge-container

# 3. Masukkan VETH ke dalam Bridge
/interface/bridge/port/add bridge=bridge-container interface=veth-gembok

# 4. (Opsional) Beri Akses Internet ke NAT jika MikroTik Anda belum mengizinkannya
/ip/firewall/nat/add chain=srcnat action=masquerade src-address=172.17.0.0/24
```

### Langkah 4: Deployment Container
Kini saatnya memasang OS Container Gembok tersebut.
Di Terminal MikroTik, jalankan:

```routeros
/container/add file=disk1/gembok-app.tar interface=veth-gembok root-dir=disk1/gembok-root hostname=GembokServer logging=yes start-on-boot=yes
```

> **Catatan**: Proses pertama kali (Extracting) akan memakan waktu 2 - 5 menit tergantung kecepatan flashdisk/disk tambahan Anda. Pantau status ekstrak di menu `Container`.

### Langkah 5: Hubungkan Gembok ke Database Eksternal MySQL
Setelah Container selesai di-ekstrak dan Berjalan (Status: `running`), Anda perlu mengakses antarmuka IP Container Anda (`http://172.17.0.2`) melalui Browser untuk menyelesaikan Instalasi Konfigurasi Database.
1. Pastikan Anda punya server MySQL/MariaDB yang hidup dan bisa diakses dari IP 172.17.0.2.
2. Masukkan alamat IP server MySQL, Nama Database, User, dan Password di form instalasi Gembok Web.
3. Selesai!

---

## 🔧 Akses Terminal Container (Debugging)
Jika Anda perlu masuk ke *filesystem* Ubuntu webserver Anda dari MikroTik (untuk memperbaiki file `includes/config.php` dsb):

1. Cek index nomor urut container Anda di WinBox (`/container/print`).
2. Masuk ke cangkang Linux (Shell):
   ```routeros
   /container/shell 0
   ```
3. Selamat! Anda sekarang berada di root terminal Ubuntu. (Lokasi folder Gembok ada di `/var/www/html/`).

---

## 🔁 Catatan Penting Mengenai Cron / Daemon
Tidak seperti instalasi Ubuntu versi *Bare-metal*, Anda **TIDAK PERLU** menginstal skrip `install_cron.sh` sama sekali. Sistem *Gembok Container* sudah dipersenjatai dengan fitur paralel *Apache* dan **Daemon Script** (`start.sh` -> `daemon.php`) yang mendeteksi setiap jadwal faktur pelanggan secara senyiap sesaat setelah status Container berubah menjadi `Running`.
