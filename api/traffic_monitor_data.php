<?php
require_once '../includes/auth.php';

if (!isAdminLoggedIn() && !isTechnicianLoggedIn() && !isSalesLoggedIn()) {
    echo json_encode(['data' => []]);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/functions.php';
require_once '../includes/mikrotik_api.php';

$customers = fetchAll("SELECT id, name, pppoe_username, usage_bytes_in, usage_bytes_out, usage_last_rx, usage_last_tx, status, router_id FROM customers ORDER BY name ASC");

function mikrotikReadAllAndParse($socket) {
    if (!$socket) return [];
    
    $allWords = [];
    $done = false;
    $timeout = time() + 10;
    while (!$done && time() < $timeout) {
        $words = mikrotikReadSentence($socket);
        if (empty($words)) break;
        foreach ($words as $word) {
            $allWords[] = $word;
            if ($word === '!done' || strpos((string)$word, '!trap') === 0) {
                $done = true;
                break;
            }
        }
    }
    
    $parsed = []; $current = [];
    foreach ($allWords as $word) {
        if ($word === '!re') {
            if (!empty($current)) { $parsed[] = $current; $current = []; }
        } elseif ($word === '!done' || strpos((string)$word, '!trap') === 0) {
            if (!empty($current)) { $parsed[] = $current; }
            break;
        } elseif (strpos((string)$word, '=') === 0) {
            $parts = explode('=', substr((string)$word, 1), 2);
            if (count($parts) === 2) {
                $current[$parts[0]] = $parts[1];
            }
        }
    }
    return $parsed;
}

$allActiveSessions = [];
$routers = getAllRouters();
if (empty($routers)) {
    // Legacy fallback bridging Single Router installations without `routers` table records natively
    $routers = [['id' => 0, 'name' => 'Default']];
}

foreach ($routers as $r) {
    if ($mk = getMikrotikConnection($r['id'])) {
        // Bulk Interface Request fetching ONLY active PPPoE sessions scaling safely avoiding static interface bloat
        mikrotikWrite($mk, '/interface/print');
        mikrotikWrite($mk, '?type=pppoe-in');
        mikrotikWrite($mk, '');
        $interfaces = mikrotikReadAllAndParse($mk);
        
        file_put_contents(__DIR__ . '/debug_traffic_log.txt', "ROUTER {$r['id']} FETCHED PPPOE-IN: " . count($interfaces) . "\n", FILE_APPEND);
        
        $activeSessions = [];
        if (!empty($interfaces)) {
            foreach ($interfaces as $intf) {
                if (isset($intf['name'])) {
                    $name = strtolower(trim($intf['name']));
                    if (strpos($name, '<pppoe-') === 0) {
                        $username = substr($name, 7, -1);
                        $activeSessions[$username] = [
                            'rx' => (float)($intf['rx-byte'] ?? 0),
                            'tx' => (float)($intf['tx-byte'] ?? 0)
                        ];
                    }
                }
            }
        }
        $allActiveSessions[$r['id']] = $activeSessions;
    }
}

$data = [];

foreach ($customers as $c) {
    if (empty($c['pppoe_username'])) continue;
    
    $userOrig = $c['pppoe_username'];
    $user = strtolower(trim($c['pppoe_username'])); 
    $rid = $c['router_id'];
    
    $liveRx = 0;
    $liveTx = 0;
    $isOnline = false;
    
    if ($rid && isset($allActiveSessions[$rid]) && isset($allActiveSessions[$rid][$user])) {
        $isOnline = true;
    } else {
        foreach ($allActiveSessions as $routerId => $sessions) {
            if (isset($sessions[$user])) {
                $isOnline = true;
                $rid = $routerId;
                break;
            }
        }
    }
    
    if ($isOnline) {
        $liveRx = $allActiveSessions[$rid][$user]['rx'];
        $liveTx = $allActiveSessions[$rid][$user]['tx'];
    }
    
    $dbRx = (float)($c['usage_bytes_in'] ?? 0);
    $dbTx = (float)($c['usage_bytes_out'] ?? 0);
    $lastRx = (float)($c['usage_last_rx'] ?? 0);
    $lastTx = (float)($c['usage_last_tx'] ?? 0);
    
    // Auto-Save Aggregations natively mimicking background CRONs overriding offline Router resets unconditionally
    if ($isOnline) {
        $pdo = getDB();
        if ($liveRx < $lastRx || $liveTx < $lastTx) {
            // Router completely reset since last known tick! Move previous session quotas into permanent History!
            $dbRx += $lastRx;
            $dbTx += $lastTx;
            
            $stmt = $pdo->prepare("UPDATE customers SET usage_bytes_in = ?, usage_bytes_out = ?, usage_last_rx = ?, usage_last_tx = ? WHERE id = ?");
            $stmt->execute([$dbRx, $dbTx, $liveRx, $liveTx, $c['id']]);
            
            $lastRx = $liveRx;
            $lastTx = $liveTx;
        } else if ($liveRx > $lastRx || $liveTx > $lastTx) {
            // Normal linear persistent growth natively logging the active maximums!
            $stmt = $pdo->prepare("UPDATE customers SET usage_last_rx = ?, usage_last_tx = ? WHERE id = ?");
            $stmt->execute([$liveRx, $liveTx, $c['id']]);
            
            $lastRx = $liveRx;
            $lastTx = $liveTx;
        }
    }
    
    $activeRx = $isOnline ? $liveRx : $lastRx;
    $activeTx = $isOnline ? $liveTx : $lastTx;
    
    $totalRx = $dbRx + $activeRx; // Total Download (Bytes-In from Router)
    $totalTx = $dbTx + $activeTx; // Total Upload (Bytes-Out from Router)
    $grandTotal = $totalRx + $totalTx;
    
    // Status Badge Core
    $statusHtml = $isOnline ? '<span class="status-badge" style="background: rgba(0, 255, 136, 0.1); color: var(--neon-green); border: 1px solid rgba(0, 255, 136, 0.3); padding: 4px 8px; border-radius: 4px; font-size: 0.75rem;"><i class="fas fa-circle"></i> Online</span>' : '<span class="status-badge" style="background: rgba(255, 71, 87, 0.1); color: var(--danger); border: 1px solid rgba(255, 71, 87, 0.3); padding: 4px 8px; border-radius: 4px; font-size: 0.75rem;"><i class="fas fa-times-circle"></i> Offline</span>';

    $data[] = [
        'name' => htmlspecialchars($c['name']),
        'username' => htmlspecialchars($userOrig),
        'status' => $statusHtml,
        'download' => formatBytes($totalRx),
        'upload' => formatBytes($totalTx),
        'total' => formatBytes($grandTotal),
        'raw_total' => $grandTotal
    ];
}

// Sort by highest traffic usage automatically
usort($data, function($a, $b) {
    return $b['raw_total'] <=> $a['raw_total'];
});

// Clear implicit network timeouts masking clean responses
if (ob_get_length()) ob_clean();
echo json_encode(['data' => $data]);
