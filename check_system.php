<?php
require_once 'includes/auth.php';
echo "<h1>SSH PTY Wrapper Test (Using 'script' command)</h1>";

$olt = fetchOne("SELECT * FROM olts LIMIT 1");
if (!$olt) die("OLT tidak ditemukan.");

$host = $olt['host'];
$user = $olt['username'];
$pass = $olt['password'];

echo "Testing SSH with PTY Wrapper to $user@$host...<br>";

// Trik menggunakan command 'script' untuk memalsukan Terminal (PTY)
$cmd = "sshpass -p " . escapeshellarg($pass) . " script -q -c \"ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p 22 $user@$host \\\"show version; exit\\\"\" /dev/null 2>&1";

$output = shell_exec($cmd);

echo "<h2>Output:</h2>";
echo "<pre style='background:#000; color:#0f0; padding:10px;'>" . htmlspecialchars($output) . "</pre>";

if (strpos($output, 'ZXAN') !== false || strpos($output, 'product') !== false) {
    echo "<b style='color:green'>BERHASIL! Trik 'script' berhasil memalsukan TTY.</b>";
} else {
    echo "<b style='color:red'>Masih gagal. Pesan error: " . htmlspecialchars($output) . "</b>";
}
