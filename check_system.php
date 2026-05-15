<?php
require_once 'includes/auth.php';
echo "<h1>SSH Direct Command Test</h1>";

$olt = fetchOne("SELECT * FROM olts LIMIT 1");
if (!$olt) {
    die("Tidak ada data OLT di database.");
}

$host = $olt['host'];
$user = $olt['username'];
$pass = $olt['password'];
$port = $olt['port'] == 23 ? 22 : $olt['port']; // Paksa pakai port 22 untuk tes SSH

echo "Testing SSH Direct Command to $user@$host:$port...<br>";

// Gunakan -T (disable TTY) dan langsung kirim perintah
$cmd = "sshpass -p " . escapeshellarg($pass) . " ssh -T -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p $port $user@$host \"show version\" 2>&1";

$output = shell_exec($cmd);

echo "<h2>Output:</h2>";
echo "<pre style='background:#000; color:#0f0; padding:10px;'>" . htmlspecialchars($output) . "</pre>";

if (strpos($output, 'pseudo-terminal') !== false) {
    echo "<b style='color:red'>Masih gagal TTY.</b>";
} elseif (empty($output)) {
    echo "<b style='color:orange'>Output Kosong.</b>";
} else {
    echo "<b style='color:green'>BERHASIL! OLT merespon tanpa butuh TTY.</b>";
}
