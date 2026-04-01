<?php
/**
 * OLT Status Poller & Alert Generator
 * Runs via Cron (e.g., every 5 mins)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/olt_api.php';

// If called directly from CLI, execute the monitor
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    runOltMonitor();
}

/**
 * Main function to poll OLT devices for alerts
 */
function runOltMonitor() {
    // Prevent concurrent runs
    $lockFile = __DIR__ . '/olt_monitor.lock';
    $lock = fopen($lockFile, 'w');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo "OLT Monitor already running. Skipped.\n";
        return false;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Starting OLT Poller...\n";

    $olts = fetchAll("SELECT * FROM olt_configs");

    if (empty($olts)) {
        echo "No OLT configurations found.\n";
    }

    foreach ($olts as $olt) {
        echo "Processing OLT: {$olt['name']} ({$olt['host']})...\n";
        
        try {
            // Simple Ping/Connection Check
            $socket = @fsockopen($olt['host'], $olt['port'], $errno, $errstr, 2);
            if (!$socket) {
                insert('olt_alerts', [
                    'olt_id' => $olt['id'],
                    'severity' => 'critical',
                    'message' => "OLT Offline: $errstr ($errno)",
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                echo "  [ALERT] OLT Offline\n";
                continue;
            }
            fclose($socket);

            // Optional: Perform deeper health check via Telnet
            // For now, we'll just log an 'info' heartbeat
            /*
            insert('olt_alerts', [
                'olt_id' => $olt['id'],
                'severity' => 'info',
                'message' => "OLT Heartbeat OK",
                'created_at' => date('Y-m-d H:i:s')
            ]);
            */

        } catch (Exception $e) {
            insert('olt_alerts', [
                'olt_id' => $olt['id'],
                'severity' => 'warning',
                'message' => "Poller Error: " . $e->getMessage(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo "  [ERROR] " . $e->getMessage() . "\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] OLT Poller Finished.\n";

    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}
