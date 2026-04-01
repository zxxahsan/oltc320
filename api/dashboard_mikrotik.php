<?php
/**
 * Dashboard MikroTik Stats API (V2.1)
 * Asynchronous Fetcher to prevent Page-Load Lag
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mikrotik_api.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$start = microtime(true);
$response = [
    'success' => true,
    'hotspot_active' => 0,
    'hotspot_users' => 0,
    'pppoe_active' => 0,
    'resource' => [
        'uptime' => 'Unknown',
        'board-name' => 'Unknown',
        'version' => 'Unknown',
        'architecture-name' => 'Unknown',
        'cpu-load' => 0,
        'free-memory' => 0
    ],
    'time_ms' => 0
];

try {
    // 1. System Resource (Uptime, CPU, RAM)
    $res = mikrotikGetSystemResource();
    if($res) $response['resource'] = $res;
    
    // 2. Hotspot Active & Users (Optimized with count instead of full array if possible, but for now using count())
    $response['hotspot_active'] = count(mikrotikGetHotspotActive() ?: []);
    $response['hotspot_users'] = count(mikrotikGetHotspotUsers() ?: []);
    
    // 3. PPPoE Active Sessions
    $response['pppoe_active'] = count(mikrotikGetActiveSessions() ?: []);

} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
}

$response['time_ms'] = round((microtime(true) - $start) * 1000);
echo json_encode($response);
