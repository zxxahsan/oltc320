<?php
require_once 'includes/auth.php';
$logFile = 'logs/telnet_debug.log';

echo "<h1>Telnet Debug Log</h1>";
if (file_exists($logFile)) {
    echo "<pre style='background:#000; color:#0f0; padding:20px; border-radius:10px;'>";
    echo htmlspecialchars(file_get_contents($logFile));
    echo "</pre>";
} else {
    echo "Log file tidak ditemukan. Pastikan Anda sudah mencoba fitur Provisioning setidaknya sekali.";
}
