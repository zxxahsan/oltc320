<?php
/**
 * OLT AJAX Handler
 */

require_once '../includes/auth.php';
requireAdminLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'test_connection') {
    require_once '../includes/olt_zte_c320.php';
    $olt_id = $_GET['olt_id'];
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['telnet_port']);
    if ($client->connect()) {
        echo json_encode(['success' => true, 'message' => 'Koneksi ke OLT Berhasil!']);
    } else {
        echo json_encode(['success' => false, 'message' => $client->getLastError()]);
    }
} elseif ($action === 'detect_onu') {
    require_once '../includes/olt_zte_c320.php';
    $olt_id = $_GET['olt_id'];
    $sn = $_GET['sn'];
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['telnet_port']);
    if ($client->connect()) {
        $unconfigured = $client->getUnconfiguredOnus();
        $found = null;
        
        foreach ($unconfigured as $onu) {
            if (strcasecmp($onu['sn'], $sn) === 0) {
                $found = $onu;
                break;
            }
        }

        if ($found) {
            // Also suggest next available ONU ID for this port
            $output = $client->exec("show gpon onu state gpon-olt_{$found['port']}");
            $lines = explode("\n", $output);
            $used_ids = [];
            foreach ($lines as $line) {
                if (preg_match('/^(\d+)\s+/', trim($line), $m)) {
                    $used_ids[] = (int)$m[1];
                }
            }
            
            $next_id = 1;
            while (in_array($next_id, $used_ids)) $next_id++;
            
            $found['next_id'] = $next_id;
            echo json_encode(['success' => true, 'data' => $found]);
        } else {
            echo json_encode(['success' => false, 'message' => 'ONU dengan SN tersebut tidak ditemukan di daftar Unconfigured.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal konek ke OLT.']);
    }
} elseif ($action === 'sync_profiles') {
    require_once '../includes/olt_zte_c320.php';
    $olt_id = $_GET['olt_id'];
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['telnet_port']);
    if ($client->connect()) {
        $vlans = $client->getProfiles('vlan');
        $tconts = $client->getProfiles('tcont');
        
        // Save to DB
        query("DELETE FROM olt_profiles WHERE olt_id = ?", [$olt_id]);
        foreach ($vlans as $v) {
            query("INSERT INTO olt_profiles (olt_id, profile_type, profile_name) VALUES (?, 'vlan', ?)", [$olt_id, $v]);
        }
        foreach ($tconts as $t) {
            query("INSERT INTO olt_profiles (olt_id, profile_type, profile_name) VALUES (?, 'tcont', ?)", [$olt_id, $t]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Profil berhasil disinkronisasi.', 'vlans' => $vlans, 'tconts' => $tconts]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal konek ke OLT.']);
    }
} elseif ($action === 'get_cached_profiles') {
    $olt_id = $_GET['olt_id'];
    $vlans = getOltProfiles($olt_id, 'vlan');
    $tconts = getOltProfiles($olt_id, 'tcont');
    
    echo json_encode([
        'success' => true, 
        'vlans' => array_column($vlans, 'profile_name'), 
        'tconts' => array_column($tconts, 'profile_name')
    ]);
} elseif ($action === 'provision') {
    require_once '../includes/olt_zte_c320.php';
    $data = json_decode(file_get_contents('php://input'), true);
    $olt_id = $data['olt_id'];
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['telnet_port']);
    if ($client->connect()) {
        $logs = $client->provision($data);
        
        // Log to database
        logOltProvisioning([
            'olt_id' => $olt_id,
            'customer_id' => $data['customer_id'] ?? null,
            'onu_sn' => $data['sn'],
            'onu_type' => $data['onu_type'] ?? 'ALL',
            'gpon_port' => $data['port'],
            'onu_index' => $data['onu_id'],
            'onu_name' => $data['name'],
            'provisioning_mode' => $data['mode'],
            'vlan_id' => $data['vlan'],
            'pppoe_username' => $data['pppoe_user'] ?? null,
            'status' => 'success',
            'output' => json_encode($logs)
        ]);

        echo json_encode(['success' => true, 'message' => 'Provisioning selesai.', 'logs' => $logs]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal konek ke OLT.']);
    }
}
