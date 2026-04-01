<?php
/**
 * CRON: ONU Status Monitor & LOS Alerting (v2.1)
 * Detects fiber cuts and notifies admin/technicians.
 */

// Run from CLI only
if (php_sapi_name() !== 'cli' && !isset($_GET['run_manual'])) {
    die("This script must be run from the command line.");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/olt_monitor_api.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/telegram.php';

echo "🚀 Starting ONU Monitoring at " . date('Y-m-d H:i:s') . "\n";

$olts = fetchAll("SELECT id, name FROM olt_configs");
$totalAlerts = 0;

foreach ($olts as $olt) {
    echo "--- Monitoring OLT: {$olt['name']} ---\n";
    
    // 1. Fetch current status from OLT
    $statusResult = oltFetchAllOnuStates($olt['id']);
    if (!$statusResult['success']) {
        echo "   ✗ Error: {$statusResult['message']}\n";
        continue;
    }
    
    $currentOnuStates = $statusResult['statuses'];
    echo "   ✓ Scanned " . count($currentOnuStates) . " ONUs.\n";

    // 2. Fetch customers linked to this OLT
    $customers = fetchAll("SELECT id, name, onu_sn, onu_status, olt_pon_port, onu_id FROM customers WHERE olt_id = ?", [$olt['id']]);
    
    $portFailures = []; // To track bulk outages

    foreach ($customers as $c) {
        $sn = strtoupper(trim($c['onu_sn']));
        if (empty($sn)) continue;

        $oldStatus = strtolower($c['onu_status'] ?? 'unknown');
        $newStatus = isset($currentOnuStates[$sn]) ? $currentOnuStates[$sn] : 'offline';

        // Check for transition
        if ($newStatus !== $oldStatus) {
            echo "   ! Change: {$c['name']} ({$sn}) : {$oldStatus} -> {$newStatus}\n";
            
            // Update DB
            update('customers', [
                'onu_status' => $newStatus,
                'last_status_change' => date('Y-m-d H:i:s')
            ], 'id = ?', [$c['id']]);

            // If it's a critical failure (LOS or Dying Gasp)
            if ($newStatus === 'los' || $newStatus === 'dying-gasp') {
                $portKey = $olt['id'] . ':' . $c['olt_pon_port'];
                if (!isset($portFailures[$portKey])) $portFailures[$portKey] = [];
                $portFailures[$portKey][] = $c;
            }
        }
    }

    // 3. Process Alerts (with Bulk logic)
    foreach ($portFailures as $portKey => $failedOnus) {
        list($oid, $pnum) = explode(':', $portKey);
        $count = count($failedOnus);
        
        $msg = "";
        if ($count >= 4) {
             // BULK ALERT
             $msg = "🚨 *GANGGUAN MASSAL / KABEL PUTUS?*\n" .
                    "📍 OLT: {$olt['name']}\n" .
                    "🔌 Port: 0/{$pnum}\n" .
                    "📉 Terdeteksi {$count} ONU terputus (LOS) sekaligus.\n" .
                    "Segera cek ODP atau Kabel Utama!";
        } else {
             // INDIVIDUAL ALERTS
             foreach ($failedOnus as $fo) {
                 $type = ($fo['onu_status'] === 'dying-gasp') ? "Mati Lampu/Power Loss" : "KABEL PUTUS (LOS)";
                 $msg = "🔴 *NOTIFICATION GANGGUAN*\n" .
                        "Pelanggan: {$fo['name']}\n" .
                        "SN: {$fo['onu_sn']}\n" .
                        "Status: {$type}\n" .
                        "📍 OLT: {$olt['name']} (Port {$fo['olt_pon_port']}/{$fo['onu_id']})";
                 
                 // Send individual notification
                 sendAlertNotification($msg);
                 $totalAlerts++;
             }
             $msg = ""; // Prevent double send
        }

        if ($msg) {
            sendAlertNotification($msg);
            $totalAlerts++;
        }
    }
}

echo "✅ Monitoring Finished. Total Alerts Sent: {$totalAlerts}\n";

/**
 * Universal Notification Dispatcher
 */
function sendAlertNotification($message) {
    // 1. WhatsApp (if enabled)
    if (function_exists('whatsappSendAdmin')) {
        whatsappSendAdmin($message);
    }
    
    // 2. Telegram (if enabled)
    if (function_exists('telegramSendAdmin')) {
        telegramSendAdmin($message);
    }
}
