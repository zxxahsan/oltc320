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
