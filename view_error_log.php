<?php
require_once 'includes/auth.php';
$logFile = 'logs/error.log';

echo "<h1>Error Log</h1>";
if (file_exists($logFile)) {
    echo "<a href='?clear=1' style='color:red'>Hapus Log</a><br><br>";
    if (isset($_GET['clear'])) {
        @unlink($logFile);
        header("Location: view_error_log.php");
        exit;
    }
    
    // Tampilkan 100 baris terakhir saja
    $lines = file($logFile);
    $last_lines = array_slice($lines, -100);
    
    echo "<pre style='background:#000; color:#ff5555; padding:20px; border-radius:10px; white-space: pre-wrap; word-break: break-all;'>";
    foreach ($last_lines as $line) {
        echo htmlspecialchars($line);
    }
    echo "</pre>";
} else {
    echo "Log file tidak ditemukan di: " . htmlspecialchars(realpath('logs')) . "/error.log";
}
