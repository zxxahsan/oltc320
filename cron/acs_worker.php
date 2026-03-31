<?php
/**
 * GenieACS Task Worker
 * Runs periodically to process delayed tasks (e.g., tagging devices)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Prevent concurrent runs
$lockFile = __DIR__ . '/acs_worker.lock';
$lock = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    die("Worker already running.\n");
}

$now = date('Y-m-d H:i:s');
$tasks = fetchAll("SELECT * FROM task_queue WHERE status = 'pending' AND execute_after <= ?", [$now]);

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
                // Perform GenieACS tagging
                // We'll use the existing genieacsSetParameter or similar logic
                // But specifically for tagging, we might need a custom endpoint if not exists
                // For now, let's assume we use a tag property if configured
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

/**
 * Helper to tag device in GenieACS
 */
function genieacsTagDevice($sn, $tag) {
    $acsUrl = defined('GENIEACS_URL') ? GENIEACS_URL : 'http://172.16.200.3:7557';
    $url = rtrim($acsUrl, '/') . "/devices/" . urlencode($sn) . "/tags/" . urlencode($tag);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200 || $httpCode === 204);
}
