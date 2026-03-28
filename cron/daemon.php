<?php
/**
 * Gembok Container Daemon Scheduler
 * Pengganti crontab untuk lingkungan Docker (MikroTik ROS Container).
 * Skrip ini akan berputar otomatis tanpa henti.
 */

// Pastikan berjalan pada mode CLI
if (php_sapi_name() !== 'cli') {
    die("Akses ditolak: Skrip ini hanya dapat dijalankan melalui Terminal/CLI.");
}

echo "[GEMBOK DAEMON] Memulai layanan scheduler tak terbatas...\n";

while (true) {
    echo "[GEMBOK DAEMON] Menjalankan cron task: " . date('Y-m-d H:i:s') . "\n";
    
    // Eksekusi scheduler utama (Isolir, reset kuota, dll)
    exec('php ' . __DIR__ . '/scheduler.php');
    
    // Eksekusi daemon whatsapp (jika dipakai untuk notifikasi berkala)
    exec('php ' . __DIR__ . '/wa_daemon.php');
    
    echo "[GEMBOK DAEMON] Task selesai. Menunggu putaran berikutnya...\n";
    
    // Jeda selama 60 detik (1 menit) sesuai spesifikasi Cronjob normal
    sleep(60);
}
