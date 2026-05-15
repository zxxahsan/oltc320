<?php
require_once 'includes/auth.php';
$logFile = 'logs/telnet_debug.log';

echo "<h1>Telnet Debug Log</h1>";
if (file_exists($logFile)) {
    echo "<a href='?clear=1' style='color:red'>Hapus Log</a><br><br>";
    if (isset($_GET['clear'])) {
        @unlink($logFile);
        header("Location: view_telnet_log.php");
        exit;
    }
    echo "<pre style='background:#000; color:#0f0; padding:20px; border-radius:10px; white-space: pre-wrap; word-break: break-all;'>";
    echo htmlspecialchars(file_get_contents($logFile));
    echo "</pre>";
} else {
    echo "Log file tidak ditemukan di: " . htmlspecialchars(realpath('logs')) . "/telnet_debug.log";
}
