<?php
require_once 'includes/auth.php'; // Ini akan memanggil db.php dan functions.php secara otomatis

// Coba update tabel 'olts' (tabel baru kita)
$res = query("UPDATE olts SET protocol = 'telnet', port = 23 WHERE 1=1");

// Coba juga update tabel 'olt_configs' (jaga-jaga jika ada versi lama)
query("UPDATE olt_configs SET protocol = 'telnet', port = 23 WHERE 1=1");

if ($res) {
    echo "<h1>BERHASIL!</h1>";
    echo "<p>Protokol OLT telah diubah ke <b>TELNET (Port 23)</b>.</p>";
    echo "<p>Silakan kembali ke dashboard dan coba lagi.</p>";
    echo "<hr><a href='admin/olt-provisioning.php'>Kembali ke Provisioning</a>";
} else {
    echo "Gagal memperbarui database. Pastikan tabel 'olts' sudah ada.";
}
