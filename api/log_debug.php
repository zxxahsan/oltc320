<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

$logFile = __DIR__ . '/debug_traffic_log.txt';
if (file_exists($logFile)) {
    echo "=== TRAFFIC MONITOR DEBUG LOG ===\n";
    echo file_get_contents($logFile);
} else {
    echo "No debug log found. Please open Traffic Monitor first to generate logs.\n";
}
