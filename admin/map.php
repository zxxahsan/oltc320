<?php
/**
 * Map - ONU Location Management with GenieACS Integration
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Peta ONU';

$pageTitle = 'Peta ONU';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('map.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_all_onus') {
        if ($_POST['confirm_text'] !== 'HAPUS SEMUA') {
            setFlash('error', 'Konfirmasi gagal. Ketik "HAPUS SEMUA" untuk menghapus semua ONU.');
        } else {
            $pdo = getDB();
            // Delete all ONUs from the map
            $pdo->exec("DELETE FROM onu_locations");
            $pdo->exec("ALTER TABLE onu_locations AUTO_INCREMENT = 1");
            
            setFlash('success', 'Seluruh data ONU berhasil dihapus dari peta.');
            logActivity('DELETE_ALL_ONUS', 'Admin cleared all ONU locations from the map.');
        }
        redirect('map.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'sync_customers') {
        $pdo = getDB();
        
        // 1. Pull all devices from GenieACS and extract their Tags
        $devices = genieacsGetDevices();
        $deviceTags = []; // Map: tag (phone) => serial_number
        
        if (is_array($devices)) {
            foreach ($devices as $device) {
                if (isset($device['_tags']) && is_array($device['_tags']) && isset($device['_deviceId']['_SerialNumber'])) {
                    $serial = $device['_deviceId']['_SerialNumber'];
                    foreach ($device['_tags'] as $tag) {
                        $deviceTags[$tag] = $serial;
                    }
                }
            }
        }

        // 2. Cross-reference with our Customer database
        $customers = fetchAll("SELECT name, phone, lat, lng FROM customers WHERE lat IS NOT NULL AND lng IS NOT NULL AND lat != '' AND lng != ''");
        $added = 0;
        $updated = 0;
        
        foreach ($customers as $c) {
            $phone = trim($c['phone']);
            if (empty($phone)) continue;

            // Does this phone number exist as a Tag in any GenieACS device?
            $hasTag = isset($deviceTags[$phone]);

            if ($hasTag) {
                // User prefers the phone number itself to serve as the map's identifying serial key
                $serialToSave = $phone;
                
                $existing = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$serialToSave]);
                if (!$existing) {
                    insert('onu_locations', [
                        'name' => $c['name'],
                        'serial_number' => $serialToSave,
                        'lat' => $c['lat'],
                        'lng' => $c['lng'],
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $added++;
                } else {
                    update('onu_locations', [
                        'name' => $c['name'],
                        'lat' => $c['lat'],
                        'lng' => $c['lng'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                    $updated++;
                }
            }
        }
        
        setFlash('success', "Sinkronisasi Pintar selesai. Ditambahkan: {$added}, Diperbarui: {$updated}");
        logActivity('SYNC_CUSTOMERS', "Synced Map with Customer ACS Tags.");
        redirect('map.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_onu') {
        $id = (int)$_POST['onu_id'];
        delete('onu_locations', 'id = ?', [$id]);
        setFlash('success', 'ONU berhasil dihapus');
        redirect('map.php');
    }
}

$onuLocations = fetchAll("
    SELECT o.*, c.pppoe_username 
    FROM onu_locations o
    LEFT JOIN customers c ON c.phone = o.serial_number
    ORDER BY o.name
");

    // Strip massive multi-router bulk arrays mimicking the 100% secure Customer Portal logic!
    $apiRouters = getAllRouters();
    if (empty($apiRouters)) {
        $apiRouters = [['id' => 0, 'name' => 'Default']];
    }

    $totalOnu = count($onuLocations);
    $onlineOnu = 0;
    $offlineOnu = 0;

    $mapCenter = ['lat' => -6.252471, 'lng' => 107.920660];
    $centerQuery = fetchOne("SELECT AVG(lat) as avg_lat, AVG(lng) as avg_lng FROM onu_locations WHERE lat IS NOT NULL AND lng IS NOT NULL");
    if ($centerQuery && $centerQuery['avg_lat']) {
        $mapCenter['lat'] = $centerQuery['avg_lat'];
        $mapCenter['lng'] = $centerQuery['avg_lng'];
    }

    // Fetch Open Tickets for Highlighting
    $openTicketsRaw = fetchAll("SELECT customer_id, phone, status FROM tasks WHERE status != 'Selesai'");
    $openTickets = [];
    foreach ($openTicketsRaw as $t) {
        if (!empty($t['phone'])) {
            $openTickets[$t['phone']] = true;
        }
    }

    $onuData = [];
    foreach ($onuLocations as $onu) {
        $pu = trim((string)$onu['pppoe_username']); 
        $isOnline = false;
        
        if (!empty($pu)) {
            $rid = $onu['router_id'] ?? null;
            $dynamicInterface = mikrotikGetInterfaceBytesByUsername($pu, $rid);
            
            if (!$dynamicInterface) {
                foreach ($apiRouters as $r) {
                    if ($r['id'] == $rid) continue;
                    $dynamicInterface = mikrotikGetInterfaceBytesByUsername($pu, $r['id']);
                    if ($dynamicInterface) {
                        break;
                    }
                }
            }
            if ($dynamicInterface) {
                $isOnline = true;
            }
        }
        
        $hasOpenTicket = (!empty($onu['serial_number']) && isset($openTickets[$onu['serial_number']]));

        if ($isOnline) {
            $onlineOnu++;
        } else {
            $offlineOnu++;
        }
    
    $onuData[] = [
        'id' => $onu['id'],
        'name' => $onu['name'],
        'serial_number' => $onu['serial_number'],
        'lat' => $onu['lat'],
        'lng' => $onu['lng'],
        'odp_id' => $onu['odp_id'],
        'status' => $isOnline ? 'online' : 'offline',
        'device_info' => null
    ];
}

ob_start();
?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="fas fa-satellite-dish"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalOnu; ?></h3>
            <p>Total ONU</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-wifi"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $onlineOnu; ?></h3>
            <p>Online</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $offlineOnu; ?></h3>
            <p>Offline</p>
        </div>
    </div>
    
    <?php
    $odpCount = fetchOne("SELECT COUNT(*) as total FROM odps")['total'] ?? 0;
    ?>
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-map-marker-alt"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $odpCount; ?></h3>
            <p>Total ODP</p>
        </div>
    </div>
</div>

<style>
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
        }
        .stat-card {
            padding: 15px;
        }
        .stat-icon {
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
        }
        .stat-info h3 {
            font-size: 1.5rem;
        }
        .stat-info p {
            font-size: 0.8rem;
        }
    }
</style>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-map-marked-alt"></i> Lokasi ONU</h3>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-secondary btn-sm" onclick="location.reload()">
                <i class="fas fa-redo"></i> Reload
            </button>
            <button class="btn btn-secondary btn-sm" id="toggleLayer" onclick="toggleLayer()">
                <i class="fas fa-layer-group"></i> Street
            </button>
            <button class="btn btn-secondary btn-sm" onclick="resetMap()">
                <i class="fas fa-crosshairs"></i> Reset
            </button>
            <button class="btn btn-primary btn-sm" onclick="loadMarkers()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    
    <div id="mapContainer" style="position: relative;">
        <div id="map" style="height: 500px;"></div>
    </div>
    
    <p style="margin-top: 10px; color: var(--text-muted); font-size: 0.85rem;">
        Klik marker untuk melihat detail ONU. Garis hijau menunjukkan jalur aktif, merah menunjukkan ONU offline.
    </p>
</div>

<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-project-diagram"></i> ODP & Jalur</h3>
        <button class="btn btn-secondary btn-sm" onclick="loadMarkers()">
            <i class="fas fa-sync-alt"></i> Refresh
        </button>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <form id="addOdpForm">
            <h4 style="margin-bottom: 10px; color: var(--neon-cyan);"><i class="fas fa-map-pin"></i> Tambah ODP</h4>
            <div style="margin-bottom: 10px; color: var(--text-secondary); font-size: 0.9rem;">
                Klik peta untuk memilih titik ODP, lalu simpan.
            </div>
            <div class="form-group">
                <label class="form-label">Nama ODP</label>
                <input type="text" id="odpName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Kode ODP</label>
                <input type="text" id="odpCode" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Kapasitas (Port)</label>
                <input type="number" id="odpTotalPorts" class="form-control" value="8" min="1" max="128">
            </div>
            <div class="form-group">
                <label class="form-label">Latitude</label>
                <input type="number" id="odpLat" class="form-control" step="0.00000001">
            </div>
            <div class="form-group">
                <label class="form-label">Longitude</label>
                <input type="number" id="odpLng" class="form-control" step="0.00000001">
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="useMapCenter()">Gunakan Titik Peta</button>
                <button type="submit" class="btn btn-primary">Simpan ODP</button>
            </div>
        </form>
        
        <form id="addOdpLinkForm">
            <h4 style="margin-bottom: 10px; color: var(--neon-cyan);"><i class="fas fa-link"></i> Tambah Jalur ODP</h4>
            <div class="form-group">
                <label class="form-label">Dari ODP</label>
                <select id="fromOdp" class="form-control" required></select>
            </div>
            <div class="form-group">
                <label class="form-label">Ke ODP</label>
                <select id="toOdp" class="form-control" required></select>
            </div>
            <button type="submit" class="btn btn-primary">Simpan Jalur</button>
        </form>
    </div>
    
    <div style="margin-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div>
            <h4 style="margin-bottom: 10px; color: var(--text-secondary);"><i class="fas fa-list"></i> Daftar ODP</h4>
            <div id="odpList" style="max-height: 240px; overflow: auto;"></div>
        </div>
        <div>
            <h4 style="margin-bottom: 10px; color: var(--text-secondary);"><i class="fas fa-route"></i> Daftar Jalur</h4>
            <div id="odpLinkList" style="max-height: 240px; overflow: auto;"></div>
        </div>
    </div>
</div>

<!-- ONU/Customer Map List -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h3 class="card-title" style="margin: 0;"><i class="fas fa-list"></i> Pelanggan Terdaftar di Peta (<?php echo $totalOnu; ?>)</h3>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="text" id="onuSearch" class="form-control" placeholder="Cari..." style="width: 150px;">
            <form method="POST" style="display:inline;" onsubmit="return confirm('Tarik lokasi Peta berdasarkan pencocokan Tag ACS dan Nomor Telepon pelanggan?');">
                <input type="hidden" name="action" value="sync_customers">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <button type="submit" class="btn btn-primary" title="Sync Pelanggan & ACS" style="white-space: nowrap; background: var(--neon-purple); border-color: var(--neon-purple);">
                    <i class="fas fa-satellite-dish"></i> Sync Tag ACS
                </button>
            </form>
            <button class="btn btn-danger" onclick="openDeleteAllOnusModal()" title="Hapus Semua Titik" style="white-space: nowrap;">
                <i class="fas fa-trash-alt"></i> Bersihkan Area
            </button>
        </div>
    </div>
    
    <table class="data-table">
        <thead>
            <tr>
                <th>Nama</th>
                <th>No Telepon</th>
                <th>Model</th>
                <th>IP Address</th>
                <th>SSID</th>
                <th>RX/TX Power</th>
                <th>Status</th>
                <th>Last Inform</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($onuData)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        Belum ada ONU terdaftar
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($onuData as $onu): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($onu['name']); ?></strong></td>
                    <td><code><?php echo htmlspecialchars($onu['serial_number']); ?></code></td>
                    <td>
                        <?php 
                        if (is_array($onu['device_info'])) {
                            $model = trim(($onu['device_info']['manufacturer'] ?? '') . ' ' . ($onu['device_info']['model'] ?? ''));
                            echo htmlspecialchars($model ?: '-');
                        } else {
                            echo '<span style="color: var(--text-muted);">-</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if (is_array($onu['device_info']) && !empty($onu['device_info']['ip_address'])) {
                            echo '<code style="background: rgba(0, 245, 255, 0.1); padding: 2px 6px; border-radius: 4px;">' . htmlspecialchars($onu['device_info']['ip_address']) . '</code>';
                        } else {
                            echo '<span style="color: var(--text-muted);">-</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if (is_array($onu['device_info']) && !empty($onu['device_info']['ssid'])) {
                            echo '<span style="color: var(--neon-green);"><i class="fas fa-wifi"></i> ' . htmlspecialchars($onu['device_info']['ssid']) . '</span>';
                        } else {
                            echo '<span style="color: var(--text-muted);">-</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if (is_array($onu['device_info'])) {
                            $rx = $onu['device_info']['rx_power'] ?? null;
                            $tx = $onu['device_info']['tx_power'] ?? null;
                            if ($rx || $tx) {
                                $rxColor = $rx > -25 ? 'var(--neon-green)' : ($rx > -30 ? 'orange' : 'var(--danger)');
                                echo '<span style="color: ' . $rxColor . ';">RX: ' . htmlspecialchars($rx ?? '-') . ' dBm</span><br>';
                                echo '<span style="color: var(--text-secondary);">TX: ' . htmlspecialchars($tx ?? '-') . ' dBm</span>';
                            } else {
                                echo '<span style="color: var(--text-muted);">-</span>';
                            }
                        } else {
                            echo '<span style="color: var(--text-muted);">-</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($onu['status'] === 'online') {
                            echo '<span class="badge badge-success"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i>Online</span>';
                        } elseif ($onu['status'] === 'offline') {
                            echo '<span class="badge badge-danger"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i>Offline</span>';
                        } else {
                            echo '<span class="badge badge-warning"><i class="fas fa-question" style="font-size: 8px; margin-right: 4px;"></i>Unknown</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if (is_array($onu['device_info']) && !empty($onu['device_info']['last_inform'])) {
                            $lastInform = strtotime($onu['device_info']['last_inform']);
                            $diff = time() - $lastInform;
                            if ($diff < 60) {
                                echo '<span style="color: var(--neon-green);">' . $diff . ' detik lalu</span>';
                            } elseif ($diff < 3600) {
                                echo '<span style="color: var(--neon-green);">' . floor($diff / 60) . ' menit lalu</span>';
                            } elseif ($diff < 86400) {
                                echo '<span style="color: var(--text-secondary);">' . floor($diff / 3600) . ' jam lalu</span>';
                            } else {
                                echo '<span style="color: var(--text-muted);">' . date('d/m/Y H:i', $lastInform) . '</span>';
                            }
                        } else {
                            echo '<span style="color: var(--text-muted);">-</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <button class="btn btn-secondary btn-sm" onclick='editOnuBasic(<?php echo json_encode($onu); ?>)' title="Edit Profil">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus ONU ini secara permanen?');">
                                <input type="hidden" name="action" value="delete_onu">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                <input type="hidden" name="onu_id" value="<?php echo $onu['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit ONU Modal -->
<div id="onuModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 450px; max-width: 90%; margin: 2rem; max-height: 90vh; overflow-y: auto;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-satellite-dish"></i> Detail ONU</h3>
            <button onclick="closeOnuModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="onuDetails" style="margin-bottom: 15px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div>
                    <p><strong>Nama:</strong></p>
                    <p id="modalName" style="color: var(--neon-cyan);">-</p>
                </div>
                <div>
                    <p><strong>Serial:</strong></p>
                    <p><code id="modalSerial" style="background: rgba(0, 245, 255, 0.1); padding: 2px 4px; border-radius: 4px; color: var(--neon-cyan);">-</code></p>
                </div>
                <div>
                    <p><strong>Status:</strong></p>
                    <p id="modalStatus">-</p>
                </div>
                <div>
                    <p><strong>Last Inform:</strong></p>
                    <p id="modalLastInform" style="color: var(--text-secondary);">-</p>
                </div>
                <div>
                    <p><strong>Model:</strong></p>
                    <p id="modalModel" style="color: var(--text-secondary);">-</p>
                </div>
                <div>
                    <p><strong>IP Address:</strong></p>
                    <p id="modalIP" style="color: var(--neon-cyan);">-</p>
                </div>
                <div>
                    <p><strong>RX Power:</strong></p>
                    <p id="modalRxPower">-</p>
                </div>
                <div>
                    <p><strong>TX Power:</strong></p>
                    <p id="modalTxPower" style="color: var(--text-secondary);">-</p>
                </div>
            </div>
        </div>
        
        <hr style="border-color: var(--border-color); margin: 15px 0;">
        
        <h4 style="color: var(--neon-cyan); margin-bottom: 10px;"><i class="fas fa-network-wired"></i> ODP</h4>
        
        <div class="form-group">
            <label class="form-label">Pilih ODP</label>
            <select id="onuOdpSelect" class="form-control"></select>
        </div>
        
        <div style="display: flex; gap: 10px; margin-bottom: 15px;">
            <button type="button" class="btn btn-primary" onclick="saveOnuOdp()">
                <i class="fas fa-save"></i> Simpan ODP
            </button>
        </div>
        
        <h4 style="color: var(--neon-green); margin-bottom: 10px;"><i class="fas fa-wifi"></i> WiFi Settings</h4>
        
        <div style="background: rgba(0, 255, 136, 0.05); border: 1px solid rgba(0, 255, 136, 0.15); border-radius: 10px; padding: 15px; margin-bottom: 15px;">
            <div class="form-group" style="margin-bottom: 10px;">
                <label class="form-label">SSID WiFi</label>
                <input type="text" id="wifiSsid" class="form-control" placeholder="Masukkan SSID baru">
            </div>
            <button type="button" class="btn btn-primary" onclick="saveSsid()" style="width: 100%;">
                <i class="fas fa-save"></i> Simpan SSID
            </button>
        </div>
        
        <div style="background: rgba(0, 245, 255, 0.05); border: 1px solid rgba(0, 245, 255, 0.15); border-radius: 10px; padding: 15px; margin-bottom: 15px;">
            <div class="form-group" style="margin-bottom: 10px;">
                <label class="form-label">Password WiFi</label>
                <div style="display: flex; gap: 10px;">
                    <input type="password" id="wifiPassword" class="form-control" placeholder="Masukkan password baru">
                    <button type="button" class="btn btn-secondary" onclick="togglePassword()" style="padding: 10px 15px;">
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>
            <button type="button" class="btn btn-primary" onclick="saveWifiPassword()" style="width: 100%;">
                <i class="fas fa-save"></i> Simpan Password
            </button>
        </div>
        
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" onclick="closeOnuModal()">Batal</button>
            <button type="button" class="btn btn-danger" onclick="rebootOnu()">
                <i class="fas fa-redo"></i> Reboot
            </button>
        </div>
    </div>
</div>

<!-- Edit ODP Modal -->
<div id="odpEditModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 400px; max-width: 90%; margin: 2rem;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-edit"></i> Edit ODP</h3>
            <button onclick="closeOdpEditModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="editOdpForm">
            <input type="hidden" id="editOdpId">
            <div class="form-group">
                <label class="form-label">Nama ODP</label>
                <input type="text" id="editOdpName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Kode ODP</label>
                <input type="text" id="editOdpCode" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Kapasitas (Port)</label>
                <input type="number" id="editOdpTotalPorts" class="form-control" min="1" max="128">
            </div>
            <div class="form-group">
                <label class="form-label">Latitude</label>
                <input type="number" id="editOdpLat" class="form-control" step="0.00000001">
            </div>
            <div class="form-group">
                <label class="form-label">Longitude</label>
                <input type="number" id="editOdpLng" class="form-control" step="0.00000001">
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeOdpEditModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

</div>

<!-- Delete All ONUs Modal -->
<div id="deleteAllOnusModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 400px; max-width: 90%; margin: 2rem; border-top: 4px solid #ff4757;">
        <div class="card-header">
            <h3 class="card-title" style="color: #ff4757;"><i class="fas fa-exclamation-triangle"></i> Peringatan Bahaya</h3>
            <button onclick="closeDeleteAllOnusModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="card-body">
            <p style="margin-bottom: 15px; color: var(--text-primary);">
                Anda yakin ingin menghapus <strong>SELURUH</strong> data ONU terdaftar dari peta? Tindakan ini <strong>TIDAK BISA DIBATALKAN</strong> dan semua pin lokasi akan hilang.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_all_onus">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                
                <div class="form-group">
                    <label class="form-label">Ketik <strong>HAPUS SEMUA</strong> untuk konfirmasi:</label>
                    <input type="text" name="confirm_text" class="form-control" required autocomplete="off" placeholder="HAPUS SEMUA">
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteAllOnusModal()" style="flex: 1;">Batal</button>
                    <button type="submit" class="btn btn-danger" style="flex: 1;">
                        <i class="fas fa-trash-alt"></i> Hapus Permanen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
<style>
.flow-line-odp {
    stroke-dasharray: 12 12;
    stroke-linecap: round;
    animation: odp-flow 2s linear infinite;
}
.flow-line-onu {
    stroke-dasharray: 8 12;
    stroke-linecap: round;
    animation: onu-flow 1.6s linear infinite;
}
@keyframes odp-flow {
    from { stroke-dashoffset: 0; }
    to { stroke-dashoffset: -48; }
}
@keyframes onu-flow {
    from { stroke-dashoffset: 0; }
    to { stroke-dashoffset: -40; }
}
</style>

<script>
let map, markers = [], odpMarkers = [], lines = [];
let currentLayer = 'satellite'; // Default Satellite
let osmLayer, satelliteLayer;
let currentOnuSerial = null;
let odpsCache = [];
let odpLinksCache = [];
let tempOdpMarker = null;

function initMap() {
    map = L.map('map').setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 15);
    
    // Google Satellite (Hybrid)
    satelliteLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}',{
        maxZoom: 20,
        subdomains:['mt0','mt1','mt2','mt3']
    });
    
    // Google Streets
    osmLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',{
        maxZoom: 20,
        subdomains:['mt0','mt1','mt2','mt3']
    });
    
    // Add default layer
    satelliteLayer.addTo(map);

    // Layer control
    var baseMaps = {
        "Satelit": satelliteLayer,
        "Jalan (Street)": osmLayer
    };
    L.control.layers(baseMaps).addTo(map);
    
    // Handle manual toggle button if exists (for backward compatibility)
    // but Layer Control is preferred
    
    map.on('click', function(e) {
        // Automatically assign coordinates when adding or editing an ODP
        setOdpPoint(e.latlng.lat, e.latlng.lng, true);
    });
    
    loadMarkers();
}

function loadMarkers() {
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];
    odpMarkers.forEach(marker => map.removeLayer(marker));
    odpMarkers = [];
    lines.forEach(line => map.removeLayer(line));
    lines = [];
    
    fetch('../api/onu_locations.php')
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                alert(result.message || 'Gagal memuat data peta');
                return;
            }
            if (!result.data) {
                return;
            }
            
            odpsCache = result.odps || [];
            odpLinksCache = result.odp_links || [];
            
            const odpIndex = {};
            odpsCache.forEach(odp => {
                if (odp.lat === null || odp.lng === null) return;
                odpIndex[odp.id] = odp;
                const marker = L.marker([odp.lat, odp.lng], {
                    icon: L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background: #00f5ff; width: 26px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #0a0a12; font-size: 12px; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas fa-network-wired"></i></div>'
                    })
                });
                const totalPorts = odp.total_ports || 8;
                const connectedClients = odp.connected_clients || 0;
                const freeSlots = Math.max(0, totalPorts - connectedClients);

                marker.bindPopup(`
                    <div style="min-width: 150px;">
                        <strong>${odp.name || 'ODP'}</strong><br>
                        ${odp.code || ''}<br>
                        <hr style="margin: 5px 0; border-color: #ddd;">
                        <span style="font-size: 0.85rem; color: var(--text-secondary);">
                            Terhubung: <span style="color: var(--neon-cyan); font-weight: bold;">${connectedClients}</span><br>
                            Sisa Slot: <span style="color: var(--neon-green); font-weight: bold;">${freeSlots}</span><br>
                            Kapasitas: ${totalPorts} Port
                        </span>
                        <button class="btn btn-sm btn-secondary" onclick="editOdp(${odp.id})" style="width: 100%; margin-top: 5px;">
                            <i class="fas fa-edit"></i> Edit ODP
                        </button>
                    </div>
                `);
                odpMarkers.push(marker);
                marker.addTo(map);
            });
            
            odpLinksCache.forEach(link => {
                const from = odpIndex[link.from_odp_id];
                const to = odpIndex[link.to_odp_id];
                if (from && to) {
                    const line = L.polyline([[from.lat, from.lng], [to.lat, to.lng]], {
                        color: '#00f5ff',
                        weight: 3,
                        opacity: 0.7,
                        className: 'flow-line-odp'
                    });
                    lines.push(line);
                    line.addTo(map);
                }
            });
            
            result.data.forEach(onu => {
                if (onu.lat === null || onu.lng === null) {
                    return;
                }
                const isOnline = onu.status === 'online';
                const hasTicket = onu.has_ticket || false;
                
                let bgHex = '#ff4757';
                let iconClass = 'fa-satellite-dish';
                
                if (hasTicket) {
                    bgHex = '#ffa502'; // Bright Orange Warning
                    iconClass = 'fa-exclamation-triangle';
                } else if (isOnline) {
                    bgHex = '#00ff88'; // Neon Green
                }
                
                const marker = L.marker([onu.lat, onu.lng], {
                    icon: L.divIcon({
                        className: 'custom-marker',
                        html: '<div style="background: ' + bgHex + '; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"><i class="fas ' + iconClass + '"></i></div>'
                    })
                });
                
                marker.on('click', function() {
                    showOnuDetails(onu);
                });
                
                markers.push(marker);
                marker.addTo(map);
                
                if (onu.odp_id && odpIndex[onu.odp_id]) {
                    const odp = odpIndex[onu.odp_id];
                    let color = '#9aa0a6';
                    if (hasTicket) {
                        color = '#ffa502';
                    } else if (isOnline) {
                        color = '#00ff88';
                    } else if (onu.status === 'offline') {
                        color = '#ff4757';
                    }
                    
                    const line = L.polyline([[odp.lat, odp.lng], [onu.lat, onu.lng]], {
                        color,
                        weight: 2,
                        opacity: 0.9,
                        className: 'flow-line-onu'
                    });
                    lines.push(line);
                    line.addTo(map);
                }
            });
            
            renderOdpLists();
        });
}

function showOnuDetails(onu) {
    currentOnuSerial = onu.serial_number || onu.serial;
    
    document.getElementById('modalName').textContent = onu.name || '-';
    document.getElementById('modalSerial').textContent = currentOnuSerial;
    
    // Status badge
    const statusEl = document.getElementById('modalStatus');
    if (onu.status === 'online') {
        statusEl.innerHTML = '<span class="badge badge-success"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i>Online</span>';
    } else if (onu.status === 'offline') {
        statusEl.innerHTML = '<span class="badge badge-danger"><i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i>Offline</span>';
    } else {
        statusEl.innerHTML = '<span class="badge badge-warning"><i class="fas fa-question" style="font-size: 8px; margin-right: 4px;"></i>Unknown</span>';
    }
    
    // Place Spinners to delegate active background validation gracefully
    document.getElementById('modalLastInform').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    document.getElementById('modalModel').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    document.getElementById('modalIP').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    document.getElementById('modalRxPower').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    document.getElementById('modalTxPower').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    const odpSelect = document.getElementById('onuOdpSelect');
    odpSelect.innerHTML = '<option value="">-</option>';
    odpsCache.forEach(odp => {
        const option = document.createElement('option');
        option.value = odp.id;
        option.textContent = odp.code ? odp.code + ' - ' + odp.name : odp.name;
        odpSelect.appendChild(option);
    });
    odpSelect.value = onu.odp_id ? String(onu.odp_id) : '';
    
    document.getElementById('wifiSsid').value = '';
    document.getElementById('wifiPassword').value = '';
    
    document.getElementById('onuModal').style.display = 'flex';
    
    // Fetch live telemetry instantaneously avoiding map generation freezes
    fetch(`../api/genieacs.php?action=get_device&id=${currentOnuSerial}`)
    .then(res => res.json())
    .then(data => {
        if(data.success && data.data) {
            const info = data.data;
            document.getElementById('modalLastInform').textContent = info.last_inform ? formatTimeAgo(info.last_inform) : '-';
            document.getElementById('modalModel').textContent = (info.manufacturer ? info.manufacturer + ' ' : '') + (info.model || '-');
            document.getElementById('modalIP').textContent = info.ip_address || '-';
            
            const rxPowerEl = document.getElementById('modalRxPower');
            if (info.rx_power && info.rx_power !== 'N/A') {
                const rxValue = parseFloat(info.rx_power);
                let color = 'var(--neon-green)';
                if (rxValue < -27) color = 'var(--danger)';
                else if (rxValue < -25) color = 'orange';
                rxPowerEl.innerHTML = '<span style="color: ' + color + ';">' + info.rx_power + ' dBm</span>';
            } else {
                rxPowerEl.textContent = '-';
            }
            
            document.getElementById('modalTxPower').textContent = info.tx_power ? info.tx_power + ' dBm' : '-';
            document.getElementById('wifiSsid').value = info.ssid || '';
            document.getElementById('wifiPassword').value = info.wifi_password || '';
        } else {
            document.getElementById('modalLastInform').textContent = 'Error';
            document.getElementById('modalModel').textContent = 'Error';
            document.getElementById('modalIP').textContent = 'Error';
            document.getElementById('modalRxPower').textContent = 'Error';
            document.getElementById('modalTxPower').textContent = 'Error';
        }
    })
    .catch(err => {
        document.getElementById('modalLastInform').textContent = 'Timeout';
        document.getElementById('modalModel').textContent = 'Timeout';
        document.getElementById('modalIP').textContent = 'Timeout';
        document.getElementById('modalRxPower').textContent = 'Timeout';
        document.getElementById('modalTxPower').textContent = 'Timeout';
    });
}

function formatTimeAgo(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 60) return diff + ' detik lalu';
    if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
    if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
    return date.toLocaleDateString('id-ID') + ' ' + date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

function closeOnuModal() {
    document.getElementById('onuModal').style.display = 'none';
    currentOnuSerial = null;
}

function togglePassword() {
    const input = document.getElementById('wifiPassword');
    const icon = document.getElementById('passwordToggleIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function saveSsid() {
    const serial = currentOnuSerial;
    const ssid = document.getElementById('wifiSsid').value;
    
    if (!ssid || ssid.trim() === '') {
        alert('SSID tidak boleh kosong');
        return;
    }
    
    if (ssid.length < 3) {
        alert('SSID minimal 3 karakter');
        return;
    }
    
    if (!confirm('Simpan SSID baru: "' + ssid + '"?')) return;
    
    fetch('../api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial, ssid, password: '' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SSID berhasil diperbarui');
            loadMarkers();
        } else {
            alert('Gagal memperbarui SSID: ' + data.message);
        }
    })
    .catch(error => {
        alert('Terjadi kesalahan: ' + error.message);
    });
}

function saveWifiPassword() {
    const serial = currentOnuSerial;
    const password = document.getElementById('wifiPassword').value;
    
    if (!password || password.trim() === '') {
        alert('Password tidak boleh kosong');
        return;
    }
    
    if (password.length < 8) {
        alert('Password minimal 8 karakter');
        return;
    }
    
    if (!confirm('Simpan password WiFi baru?')) return;
    
    fetch('../api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial, ssid: '', password })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Password WiFi berhasil diperbarui');
            loadMarkers();
        } else {
            alert('Gagal memperbarui password: ' + data.message);
        }
    })
    .catch(error => {
        alert('Terjadi kesalahan: ' + error.message);
    });
}

function saveOnuOdp() {
    const serial = currentOnuSerial;
    const odpId = document.getElementById('onuOdpSelect').value;
    
    if (!serial) return;
    
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'onu_odp', serial, odp_id: odpId ? parseInt(odpId, 10) : null })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('ODP berhasil diperbarui');
            loadMarkers();
        } else {
            alert('Gagal memperbarui ODP: ' + data.message);
        }
    })
    .catch(error => {
        alert('Terjadi kesalahan: ' + error.message);
    });
}

function rebootOnu() {
    if (!confirm('Yakin ingin reboot ONU ini?')) return;
    
    const serial = currentOnuSerial;
    
    fetch('../api/genieacs.php?action=reboot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reboot berhasil dijalankan');
            closeOnuModal();
        } else {
            alert('Gagal reboot: ' + data.message);
        }
    })
    .catch(error => {
        alert('Terjadi kesalahan: ' + error.message);
    });
}

function toggleLayer() {
    if (currentLayer === 'osm') {
        map.removeLayer(osmLayer);
        satelliteLayer.addTo(map);
        currentLayer = 'satellite';
        document.getElementById('toggleLayer').innerHTML = '<i class="fas fa-layer-group"></i> Street';
    } else {
        map.removeLayer(satelliteLayer);
        osmLayer.addTo(map);
        currentLayer = 'osm';
        document.getElementById('toggleLayer').innerHTML = '<i class="fas fa-layer-group"></i> Satellite';
    }
}

function resetMap() {
    map.setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 15);
}

function useMapCenter() {
    if (!map) return;
    const center = map.getCenter();
    setOdpPoint(center.lat, center.lng, true);
}

function setOdpPoint(lat, lng, draggable = false) {
    document.getElementById('odpLat').value = lat.toFixed(8);
    document.getElementById('odpLng').value = lng.toFixed(8);
    
    if (tempOdpMarker) {
        map.removeLayer(tempOdpMarker);
    }
    
    if (draggable) {
        tempOdpMarker = L.marker([lat, lng], {
            draggable: true,
            icon: L.divIcon({
                className: 'custom-marker',
                html: '<div style="background: #00f5ff; width: 20px; height: 20px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>'
            })
        }).addTo(map);
        
        tempOdpMarker.on('drag', function(e) {
            const pos = e.target.getLatLng();
            document.getElementById('odpLat').value = pos.lat.toFixed(8);
            document.getElementById('odpLng').value = pos.lng.toFixed(8);
        });
    } else {
        tempOdpMarker = L.circleMarker([lat, lng], {
            radius: 6,
            color: '#00f5ff',
            fillColor: '#00f5ff',
            fillOpacity: 0.9,
            weight: 2
        }).addTo(map);
    }
}

function renderOdpLists() {
    const list = document.getElementById('odpList');
    const linkList = document.getElementById('odpLinkList');
    const fromSelect = document.getElementById('fromOdp');
    const toSelect = document.getElementById('toOdp');
    
    fromSelect.innerHTML = '';
    toSelect.innerHTML = '';
    
    odpsCache.forEach(odp => {
        const option1 = document.createElement('option');
        option1.value = odp.id;
        option1.textContent = odp.code ? odp.code + ' - ' + odp.name : odp.name;
        const option2 = option1.cloneNode(true);
        fromSelect.appendChild(option1);
        toSelect.appendChild(option2);
    });
    
    if (odpsCache.length === 0) {
        list.innerHTML = '<div style="color: var(--text-muted);">Belum ada ODP</div>';
    } else {
        list.innerHTML = odpsCache.map(odp => {
            const label = odp.code ? odp.code + ' - ' + odp.name : odp.name;
            const coord = (odp.lat && odp.lng) ? (parseFloat(odp.lat).toFixed(6) + ', ' + parseFloat(odp.lng).toFixed(6)) : '-';
            return '<div style="display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-color);">' +
                '<div><strong>' + label + '</strong><br><small style="color: var(--text-muted);">' + coord + '</small></div>' +
                '<div style="display: flex; gap: 5px;">' +
                    '<button class="btn btn-secondary btn-sm" onclick="editOdp(' + odp.id + ')"><i class="fas fa-edit"></i></button>' +
                    '<button class="btn btn-danger btn-sm" onclick="deleteOdp(' + odp.id + ')"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        }).join('');
    }
    
    if (odpLinksCache.length === 0) {
        linkList.innerHTML = '<div style="color: var(--text-muted);">Belum ada jalur</div>';
    } else {
        const odpIndex = {};
        odpsCache.forEach(odp => { odpIndex[odp.id] = odp; });
        linkList.innerHTML = odpLinksCache.map(link => {
            const from = odpIndex[link.from_odp_id];
            const to = odpIndex[link.to_odp_id];
            const fromLabel = from ? (from.code ? from.code : from.name) : link.from_odp_id;
            const toLabel = to ? (to.code ? to.code : to.name) : link.to_odp_id;
            return '<div style="display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-color);">' +
                '<div><strong>' + fromLabel + '</strong> → <strong>' + toLabel + '</strong></div>' +
                '<button class="btn btn-danger btn-sm" onclick="deleteOdpLink(' + link.id + ')"><i class="fas fa-trash"></i></button>' +
            '</div>';
        }).join('');
    }
}

function editOdp(id) {
    const odp = odpsCache.find(o => o.id == id);
    if (!odp) return;
    
    document.getElementById('editOdpId').value = odp.id;
    document.getElementById('editOdpName').value = odp.name;
    document.getElementById('editOdpCode').value = odp.code || '';
    document.getElementById('editOdpLat').value = odp.lat;
    document.getElementById('editOdpLng').value = odp.lng;
    document.getElementById('editOdpTotalPorts').value = odp.total_ports || 8;
    
    document.getElementById('odpEditModal').style.display = 'flex';
}

function closeOdpEditModal() {
    document.getElementById('odpEditModal').style.display = 'none';
}

document.getElementById('editOdpForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const id = document.getElementById('editOdpId').value;
    const name = document.getElementById('editOdpName').value.trim();
    const code = document.getElementById('editOdpCode').value.trim();
    const lat = document.getElementById('editOdpLat').value;
    const lng = document.getElementById('editOdpLng').value;
    const totalPorts = document.getElementById('editOdpTotalPorts').value;
    
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'odp_update',
            id,
            name,
            code,
            lat: lat ? parseFloat(lat.replace(',', '.')) : null,
            lng: lng ? parseFloat(lng.replace(',', '.')) : null,
            total_ports: totalPorts ? parseInt(totalPorts, 10) : 8
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            closeOdpEditModal();
            loadMarkers();
        } else {
            alert(result.message || 'Gagal mengupdate ODP');
        }
    });
});

document.getElementById('addOdpForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const name = document.getElementById('odpName').value.trim();
    const code = document.getElementById('odpCode').value.trim();
    const lat = document.getElementById('odpLat').value;
    const lng = document.getElementById('odpLng').value;
    const totalPorts = document.getElementById('odpTotalPorts').value;
    
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'odp',
            name,
            code,
            lat: lat ? parseFloat(lat.replace(',', '.')) : null,
            lng: lng ? parseFloat(lng.replace(',', '.')) : null,
            total_ports: totalPorts ? parseInt(totalPorts, 10) : 8
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            document.getElementById('odpName').value = '';
            document.getElementById('odpCode').value = '';
            document.getElementById('odpLat').value = '';
            document.getElementById('odpLng').value = '';
            loadMarkers();
        } else {
            alert(result.message || 'Gagal menambah ODP');
        }
    });
});

document.getElementById('addOdpLinkForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fromOdp = document.getElementById('fromOdp').value;
    const toOdp = document.getElementById('toOdp').value;
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'odp_link', from_odp_id: parseInt(fromOdp, 10), to_odp_id: parseInt(toOdp, 10) })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            loadMarkers();
        } else {
            alert(result.message || 'Gagal menambah jalur');
        }
    });
});

function deleteOdp(id) {
    if (!confirm('Hapus ODP ini? Jalur terkait juga akan terhapus.')) return;
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'odp_delete', id })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            loadMarkers();
        } else {
            alert(result.message || 'Gagal menghapus ODP');
        }
    });
}

function deleteOdpLink(id) {
    if (!confirm('Hapus jalur ini?')) return;
    fetch('../api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'odp_link_delete', id })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            loadMarkers();
        } else {
            alert(result.message || 'Gagal menghapus jalur');
        }
    });
}

document.getElementById('onuSearch').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.data-table tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
});

function openDeleteAllOnusModal() {
    document.getElementById('deleteAllOnusModal').style.display = 'flex';
}

function closeDeleteAllOnusModal() {
    document.getElementById('deleteAllOnusModal').style.display = 'none';
}

document.getElementById('onuModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOnuModal();
    }
});

document.getElementById('deleteAllOnusModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteAllOnusModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeOnuModal();
        closeDeleteAllOnusModal();
    }
});

// Initialize map when page loads
setTimeout(initMap, 500);
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
