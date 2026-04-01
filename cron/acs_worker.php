<?php
/**
 * GenieACS Task Worker
 * Runs periodically to process delayed tasks (e.g., tagging devices)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// If called directly from CLI (e.g., cron or manual test), execute the worker
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    runAcsWorker();
}

/**
 * Main function to process GenieACS tasks
 */
function runAcsWorker() {
    // Prevent concurrent runs
    $lockFile = __DIR__ . '/acs_worker.lock';
    $lock = fopen($lockFile, 'w');
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        echo "Worker already running. Skipped.\n";
        return false;
    }

    $now = date('Y-m-d H:i:s');
    $tasks = fetchAll("SELECT * FROM task_queue WHERE status = 'pending' AND execute_after <= ?", [$now]);

    if (empty($tasks)) {
        echo "No pending ACS tasks.\n";
    }

    foreach ($tasks as $task) {
        update('task_queue', ['status' => 'processing'], 'id = ?', [$task['id']]);
        
        $payload = json_decode($task['payload'], true);
        $success = false;
        $message = '';

        try {
            if ($task['task_type'] === 'acs_tag') {
                $sn = $payload['sn'] ?? '';
                $tag = $payload['tag'] ?? '';
                
                if ($sn && $tag) {
                    $success = genieacsTagDevice($sn, $tag);
                    $message = $success ? "Tagged $sn with $tag" : "Failed to tag $sn";
                } else {
                    $message = "Invalid payload: SN or Tag missing";
                }
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }

        update('task_queue', [
            'status' => $success ? 'completed' : 'failed',
            'payload' => json_encode(array_merge($payload, ['worker_log' => $message])),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$task['id']]);
        
        echo "[Task #{$task['id']}] " . ($success ? "SUCCESS" : "FAILED") . ": $message\n";
    }

    // BROAD SCAN: Find untagged devices registered in the last 24 hours
    echo "Starting Broad Scan for untagged recent devices...\n";
    scanAndTagRecentDevices();

    flock($lock, LOCK_UN);
    fclose($lock);
    return true;
}

/**
 * Helper to tag device in GenieACS
 */
function genieacsTagDevice($sn, $tag) {
    $acsUrl = defined('GENIEACS_URL') ? GENIEACS_URL : 'http://172.16.200.3:7557';
    $baseUrl = rtrim($acsUrl, '/');
    
    // 1. Check if device exists and already has the tag
    $checkUrl = "$baseUrl/devices?query=" . urlencode(json_encode(['_id' => $sn])) . "&projection=_tags";
    
    $ch = curl_init($checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($response)) {
        $devices = json_decode($response, true);
        if (!empty($devices) && is_array($devices)) {
            $device = $devices[0];
            if (isset($device['_tags']) && is_array($device['_tags'])) {
                if (in_array($tag, $device['_tags'])) {
                    // Tag already exists, ignore/skip
                    return true; 
                }
            }
        }
    }

    // 2. Add tag if not present
    $tagUrl = "$baseUrl/devices/" . urlencode($sn) . "/tags/" . urlencode($tag);
    
    $ch = curl_init($tagUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200 || $httpCode === 204);
}

/**
 * Broad scan for recent untagged devices
 */
function scanAndTagRecentDevices() {
    $genieacs = getGenieacsSettings();
    if (empty($genieacs['url'])) return;

    $baseUrl = rtrim($genieacs['url'], '/');
    $twentyFourHoursAgo = date('Y-m-d\TH:i:s\Z', time() - 86400);
    
    // Query: Devices with Registered event in last 24h AND no tags
    $query = [
        'Events.Registered._time' => ['$gt' => $twentyFourHoursAgo],
        '_tags' => ['$exists' => false]
    ];
    
    $projection = [
        '_id',
        'VirtualParameters.pppoeUsername',
        'VirtualParameters.pppoeUsername2'
    ];
    
    $url = "$baseUrl/devices?query=" . urlencode(json_encode($query)) . "&projection=" . implode(',', $projection);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($response)) {
        echo "  [SCAN] Failed to fetch devices from GenieACS (HTTP $httpCode)\n";
        return;
    }
    
    $devices = json_decode($response, true);
    if (!is_array($devices) || empty($devices)) {
        echo "  [SCAN] No untagged recently registered devices found.\n";
        return;
    }
    
    echo "  [SCAN] Found " . count($devices) . " potential untagged devices. Matching...\n";
    
    foreach ($devices as $device) {
        $sn = $device['_id'];
        $pppoe = genieacsGetValue($device, 'VirtualParameters.pppoeUsername') ?? genieacsGetValue($device, 'VirtualParameters.pppoeUsername2');
        
        if (empty($pppoe)) {
            echo "    - $sn: No PPPoE username found. Skipping.\n";
            continue;
        }
        
        // Match against database
        $customer = fetchOne("SELECT id FROM customers WHERE pppoe_username = ?", [$pppoe]);
        if ($customer) {
            $tag = (string)$customer['id'];
            echo "    - $sn: MATCH found for '$pppoe'. Tagging with '$tag'...\n";
            if (genieacsTagDevice($sn, $tag)) {
                echo "      ✓ Tagged successfully.\n";
            } else {
                echo "      ✗ Failed to tag.\n";
            }
        } else {
            echo "    - $sn: No customer match for '$pppoe'.\n";
        }
    }
}
