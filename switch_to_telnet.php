<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Update all ZTE OLTs to use Telnet as a fallback
$res = query("UPDATE olts SET protocol = 'telnet', port = 23 WHERE 1=1");

if ($res) {
    echo "BERHASIL: Protokol OLT telah diubah ke TELNET di port 23.\n";
    echo "Silakan pastikan fitur Telnet sudah aktif di OLT Anda.";
} else {
    echo "GAGAL mengubah database.";
}
