<?php
/**
 * API: ONU Locations with GenieACS Integration
 */

header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allow Admin or Technician
if (!isAdminLoggedIn() && !isTechnicianLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function ensureOdpSchema()
{
    if (!tableExists('odps')) {
        $created = query("CREATE TABLE IF NOT EXISTS odps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) UNIQUE,
            lat DECIMAL(11,8),
            lng DECIMAL(11,8),
            total_ports INT DEFAULT 8,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$created) {
            return ['success' => false, 'message' => 'Gagal membuat tabel ODP'];
        }
    }

    $portColumn = fetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'odps' AND COLUMN_NAME = 'total_ports'", [DB_NAME]);
    if (!$portColumn) {
        query("ALTER TABLE odps ADD COLUMN total_ports INT DEFAULT 8");
    }

    if (!tableExists('odp_links')) {
        $created = query("CREATE TABLE IF NOT EXISTS odp_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_odp_id INT NOT NULL,
            to_odp_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (from_odp_id) REFERENCES odps(id) ON DELETE CASCADE,
            FOREIGN KEY (to_odp_id) REFERENCES odps(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$created) {
            return ['success' => false, 'message' => 'Gagal membuat tabel jalur ODP'];
        }
    }

    $column = fetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'onu_locations' AND COLUMN_NAME = 'odp_id'", [DB_NAME]);
    if (!$column) {
        $altered = query("ALTER TABLE onu_locations ADD COLUMN odp_id INT NULL, ADD CONSTRAINT fk_onu_odp FOREIGN KEY (odp_id) REFERENCES odps(id) ON DELETE SET NULL");
        if (!$altered) {
            return ['success' => false, 'message' => 'Gagal menambahkan kolom ODP pada ONU'];
        }
    }

    return ['success' => true];
}

$method = $_SERVER['REQUEST_METHOD'];

try {

if ($method === 'GET') {
    $schema = ensureOdpSchema();
    if (!$schema['success']) {
        echo json_encode(['success' => false, 'message' => $schema['message']]);
        exit;
    }
    // Mute incidental PHP errors protecting JSON schemas
    error_reporting(0);

        $onuLocations = fetchAll("
            SELECT o.*, c.pppoe_username, c.phone 
            FROM onu_locations o
            LEFT JOIN customers c ON (c.onu_sn = o.serial_number OR c.phone = o.serial_number)
            WHERE o.lat IS NOT NULL AND o.lng IS NOT NULL AND o.lat != 0 AND o.lng != 0
            ORDER BY o.name
        ");
        
        if (empty($onuLocations) || !is_array($onuLocations)) {
            $onuLocations = fetchAll("
                SELECT o.*, c.pppoe_username, c.phone
                FROM onu_locations o
                LEFT JOIN customers c ON c.phone = o.serial_number
                WHERE o.lat IS NOT NULL AND o.lng IS NOT NULL AND o.lat != 0 AND o.lng != 0
                ORDER BY o.name
            ");
            
            if (!is_array($onuLocations)) $onuLocations = [];
        }


    // Transitioning from Heavy sequential MikroTik API pings to instantaneous GenieACS tag resolutions!
    require_once '../includes/functions.php';
    
    $acsDevices = genieacsGetDevices();
    $acsOnlineMap = [];
    if (is_array($acsDevices)) {
        foreach ($acsDevices as $d) {
            $isOnline = false;
            if (!empty($d['_lastInform'])) {
                // If the device has communicated within the last 15 minutes
                $informTime = strtotime($d['_lastInform']);
                if (time() - $informTime < 900) {
                    $isOnline = true;
                }
            }
            if ($isOnline && !empty($d['_tags']) && is_array($d['_tags'])) {
                foreach ($d['_tags'] as $tag) {
                    $acsOnlineMap[(string)$tag] = true;
                }
            }
        }
    }

    // Fetch Open Trouble Tickets targeting customer phones natively
    $openTicketsRaw = fetchAll("
        SELECT c.phone 
        FROM trouble_tickets t
        JOIN customers c ON t.customer_id = c.id
        WHERE t.status != 'resolved'
    ");
    $hasTicketMap = [];
    foreach ($openTicketsRaw as $t) {
        if (!empty($t['phone'])) $hasTicketMap[$t['phone']] = true;
    }

    // Natively inject all valid Customers with Coordinates directly into Map avoiding manual sync bottlenecks
    $allCustomers = fetchAll("SELECT * FROM customers WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat != 0 AND lng != 0");
    if (!is_array($allCustomers)) {
        $allCustomers = [];
    }
    
    $existingSerials = [];
    foreach ($onuLocations as $onu) {
        if (!empty($onu['serial_number'])) $existingSerials[$onu['serial_number']] = true;
    }
    
    foreach ($allCustomers as $c) {
        $sn = !empty($c['onu_sn']) ? $c['onu_sn'] : ($c['phone'] ?? '');
        if (empty($sn)) $sn = 'CUST-' . ($c['id'] ?? uniqid());
        
        // Only inject if they aren't already represented by the explicit onu_locations mapping
        if (!isset($existingSerials[$sn])) {
            $onuLocations[] = [
                'id' => 'c_' . ($c['id'] ?? ''),
                'name' => $c['name'] ?? 'Unknown',
                'serial_number' => $sn,
                'phone' => $c['phone'] ?? '',
                'lat' => $c['lat'],
                'lng' => $c['lng'],
                'odp_id' => null,
                'pppoe_username' => $c['pppoe_username'] ?? '',
                'olt_status' => $c['status'] ?? 'active' // Safely fallback to customer status
            ];
            $existingSerials[$sn] = true;
        }
    }

    foreach ($onuLocations as &$onu) {
        $ph = trim((string)($onu['phone'] ?? ''));
        // Online relies solely on TR-069 Phone Tag arrays
        $onu['status'] = (!empty($ph) && isset($acsOnlineMap[$ph])) ? 'online' : 'offline';
        $onu['has_ticket'] = (!empty($onu['serial_number']) && isset($hasTicketMap[$onu['serial_number']]));
        $onu['device_info'] = null;
        $onu['ssid'] = '';
        $onu['password'] = '';
    }

    $odps = fetchAll("
        SELECT o.*, 
            (SELECT COUNT(*) FROM onu_locations WHERE odp_id = o.id) as connected_clients 
        FROM odps o 
        ORDER BY o.name
    ");
    $odpLinks = fetchAll("SELECT * FROM odp_links");

    echo json_encode([
        'success' => true,
        'data' => $onuLocations,
        'odps' => $odps,
        'odp_links' => $odpLinks
    ]);

} elseif ($method === 'POST') {
    $schema = ensureOdpSchema();
    if (!$schema['success']) {
        echo json_encode(['success' => false, 'message' => $schema['message']]);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'onu';

    if ($type === 'odp' || $type === 'odp_update') {
        $id = $input['id'] ?? null;
        $name = trim($input['name'] ?? '');
        $code = trim($input['code'] ?? '');
        $lat = ($input['lat'] === '' || $input['lat'] === null) ? null : $input['lat'];
        $lng = ($input['lng'] === '' || $input['lng'] === null) ? null : $input['lng'];
        $totalPorts = isset($input['total_ports']) ? (int)$input['total_ports'] : 8;

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Nama ODP wajib diisi']);
            exit;
        }

        if ($code !== '') {
            $existingCode = fetchOne("SELECT id FROM odps WHERE code = ?", [$code]);
            if ($existingCode && (!$id || (int) $existingCode['id'] !== (int) $id)) {
                echo json_encode(['success' => false, 'message' => 'Kode ODP sudah digunakan']);
                exit;
            }
        }

        if ($id && $type === 'odp_update') {
            $updated = update('odps', [
                'name' => $name,
                'code' => $code ?: null,
                'lat' => $lat,
                'lng' => $lng,
                'total_ports' => $totalPorts
            ], 'id = ?', [$id]);
            
            echo json_encode(['success' => true, 'message' => 'ODP berhasil diperbarui']);
        } else {
            $inserted = insert('odps', [
                'name' => $name,
                'code' => $code ?: null,
                'lat' => $lat,
                'lng' => $lng,
                'total_ports' => $totalPorts
            ]);
            
            if ($inserted) {
                echo json_encode(['success' => true, 'message' => 'ODP berhasil ditambahkan']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menambah ODP']);
            }
        }
        exit;
    }

    if ($type === 'odp_delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            $deleted = delete('odps', 'id = ?', [$id]);
            if ($deleted !== false) {
                echo json_encode(['success' => true, 'message' => 'ODP dihapus']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menghapus ODP']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'ODP tidak valid']);
        }
        exit;
    }

    if ($type === 'odp_link') {
        $fromId = (int) ($input['from_odp_id'] ?? 0);
        $toId = (int) ($input['to_odp_id'] ?? 0);
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            echo json_encode(['success' => false, 'message' => 'Jalur ODP tidak valid']);
            exit;
        }

        $exists = fetchOne("SELECT id FROM odp_links WHERE (from_odp_id = ? AND to_odp_id = ?) OR (from_odp_id = ? AND to_odp_id = ?)", [$fromId, $toId, $toId, $fromId]);
        if (!$exists) {
            $inserted = insert('odp_links', [
                'from_odp_id' => $fromId,
                'to_odp_id' => $toId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            if (!$inserted) {
                echo json_encode(['success' => false, 'message' => 'Gagal menambah jalur ODP']);
                exit;
            }
        }
        echo json_encode(['success' => true, 'message' => 'Jalur ODP ditambahkan']);
        exit;
    }

    if ($type === 'odp_link_delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            $deleted = delete('odp_links', 'id = ?', [$id]);
            if ($deleted !== false) {
                echo json_encode(['success' => true, 'message' => 'Jalur ODP dihapus']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Gagal menghapus jalur ODP']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Jalur tidak valid']);
        }
        exit;
    }

    if ($type === 'onu_odp') {
        $serial = $input['serial'] ?? '';
        $odpId = $input['odp_id'] ?? null;
        if ($serial === '') {
            echo json_encode(['success' => false, 'message' => 'Serial number is required']);
            exit;
        }
        $updated = update('onu_locations', [
            'odp_id' => $odpId,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'serial_number = ?', [$serial]);
        if ($updated) {
            echo json_encode(['success' => true, 'message' => 'ODP ONU diperbarui']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui ODP ONU']);
        }
        exit;
    }

    $serial = $input['serial'] ?? '';
    $name = $input['name'] ?? '';
    // Debug Log
    error_log("API onu_locations.php received: " . print_r($input, true));
    
    $lat = ($input['lat'] === '' || $input['lat'] === null) ? null : str_replace(',', '.', trim($input['lat']));
    $lng = ($input['lng'] === '' || $input['lng'] === null) ? null : str_replace(',', '.', trim($input['lng']));
    $odpId = $input['odp_id'] ?? null;

    if (empty($serial)) {
        echo json_encode(['success' => false, 'message' => 'Serial number is required']);
        exit;
    }

    $existing = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$serial]);

    if ($existing) {
        $updated = update('onu_locations', [
            'name' => $name,
            'lat' => $lat,
            'lng' => $lng,
            'odp_id' => $odpId,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'serial_number = ?', [$serial]);
        
        // Also update customers table if serial matches (assuming we can link by pppoe/serial)
        // Or try to match by serial number if we store it
        // Since we don't strictly have serial in customers table yet (we use pppoe_username),
        // we might need to look it up.
        // But for now, let's assume onu_locations IS the source of truth for map.
        
        // However, the technician map uses `customers` table for coordinates.
        // We MUST update `customers` table too if we can link them.
        // Let's try to link via GenieACS pppoeUsername -> customers.pppoe_username
        
        $deviceInfo = genieacsGetDeviceInfo($serial);
        if ($deviceInfo && !empty($deviceInfo['pppoe_username'])) {
            update('customers', [
                'lat' => $lat,
                'lng' => $lng,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'pppoe_username = ?', [$deviceInfo['pppoe_username']]);
        }

        if ($updated) {
            echo json_encode(['success' => true, 'message' => 'ONU location updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui ONU']);
        }
    } else {
        $inserted = insert('onu_locations', [
            'name' => $name,
            'serial_number' => $serial,
            'lat' => $lat,
            'lng' => $lng,
            'odp_id' => $odpId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Sync to customers table
        $deviceInfo = genieacsGetDeviceInfo($serial);
        if ($deviceInfo && !empty($deviceInfo['pppoe_username'])) {
            update('customers', [
                'lat' => $lat,
                'lng' => $lng,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'pppoe_username = ?', [$deviceInfo['pppoe_username']]);
        }
        
        if ($inserted) {
            echo json_encode(['success' => true, 'message' => 'ONU location added']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menambah ONU']);
        }
    }
}

} catch (Exception $e) {
    logError("API Error (onu_locations.php): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
