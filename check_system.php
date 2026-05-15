<?php
require_once 'includes/auth.php';
echo "<h1>SSH Piped Command Test</h1>";

$olt = fetchOne("SELECT * FROM olts LIMIT 1");
if (!$olt) die("OLT tidak ditemukan.");

$host = $olt['host'];
$user = $olt['username'];
$pass = $olt['password'];
$port = 22; // Paksa SSH

echo "Testing SSH Piped Command to $user@$host...<br>";

// Gunakan printf untuk mengirim banyak baris perintah sekaligus lewat SSH
$cmds = "show version\nexit\n";
$cmd = "printf " . escapeshellarg($cmds) . " | sshpass -p " . escapeshellarg($pass) . " ssh -T -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p $port $user@$host 2>&1";

$output = shell_exec($cmd);

echo "<h2>Output:</h2>";
echo "<pre style='background:#000; color:#0f0; padding:10px;'>" . htmlspecialchars($output) . "</pre>";

if (strpos($output, 'ZXAN') !== false || strpos($output, 'product') !== false) {
    echo "<b style='color:green'>BERHASIL! Cara ini tembus tanpa TTY.</b>";
} else {
    echo "<b style='color:red'>Gagal. Pesan error: " . htmlspecialchars($output) . "</b>";
}
