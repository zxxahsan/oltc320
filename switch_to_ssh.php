<?php
require_once 'includes/auth.php'; // Ini akan memanggil db.php dan functions.php secara otomatis

// Kembalikan semua OLT ke protokol SSH (Port 22)
$res = query("UPDATE olts SET protocol = 'ssh', port = 22 WHERE 1=1");
query("UPDATE olt_configs SET protocol = 'ssh', port = 22 WHERE 1=1");

if ($res) {
    echo "<h1>BERHASIL!</h1>";
    echo "<p>Protokol OLT telah dikembalikan ke <b>SSH (Port 22)</b>.</p>";
    echo "<p>Sekarang sistem akan menggunakan mesin 'phpseclib' yang baru kita pasang.</p>";
    echo "<hr><a href='admin/customers.php'>Kembali ke Dashboard</a>";
} else {
    echo "Gagal memperbarui database.";
}
