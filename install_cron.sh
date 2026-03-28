#!/bin/bash
# Script Instalasi Gembok Cronjob Otomatis (Ubuntu)
# Dijalankan dengan: sudo bash install_cron.sh

echo "======================================"
echo "  Pemasangan Gembok Auto-Scheduler  "
echo "======================================"

if [ "$EUID" -ne 0 ]; then
  echo "Gagal: Script ini harus dijalankan dengan hak akses root (sudo)."
  exit 1
fi

SCHEDULER="/var/www/gembok/cron/scheduler.php"
LOG="/var/www/gembok/cron/scheduler.log"

if [ ! -f "$SCHEDULER" ]; then
    echo "Error: File scheduler tidak ditemukan di $SCHEDULER"
    echo "Pastikan aplikasi Gembok diinstall pada direktori /var/www/gembok"
    exit 1
fi

# Membuat file log dan mengatur kepemilikan
touch "$LOG"
chown www-data:www-data "$LOG"
chmod 664 "$LOG"

# Format CRON system-wide: Menit Jam Tanggal Bulan Hari User Command
# Kita jalankan langsung mengeksekusi PHP sebagai www-data
CRON_CMD="* * * * * www-data /usr/bin/php $SCHEDULER >> $LOG 2>&1"

if grep -q "$SCHEDULER" /etc/crontab; then
    echo "Info: Cronjob Gembok sudah terpasang di /etc/crontab."
else
    echo "$CRON_CMD" >> /etc/crontab
    systemctl restart cron
    echo "Berhasil! Script Isolir Mikrotik & Penagihan otomatis kini berjalan rutin (1 Menit)."
    echo "Catatan log dapat Anda pantau di: $LOG"
fi
