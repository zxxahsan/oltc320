<?php
/**
 * Customers Management
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_zte_c320.php';
require_once '../includes/mikrotik_api.php';

ob_start();

// Auto-migrate task_queue table
if (!tableExists('task_queue')) {
    getDB()->query("CREATE TABLE task_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_type VARCHAR(50) NOT NULL,
        payload TEXT NOT NULL,
        execute_after DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_execute (execute_after, status)
    )");
}

$pageTitle = 'Pelanggan';

// === AJAX: Provision ONU ===
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'provision') {
    header('Content-Type: application/json');
    $olt_id = (int)($_POST['olt_id'] ?? 0);
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['port'], $olt['protocol'] ?? 'ssh');
    if ($client->connect()) {
        $data = [
            'port' => $_POST['port'],
            'onu_index' => $_POST['onu_id'],
            'sn' => $_POST['sn'],
            'type' => $_POST['onu_type'] ?? 'ALL',
            'mode' => in_array('omci', $_POST['services'] ?? []) ? 'omci' : 'standard',
            'vlan' => $_POST['vlan'] ?? 100,
            'username' => $_POST['pppoe_user'] ?? '',
            'password' => $_POST['pppoe_pass'] ?? '',
            'tcont' => 'default'
        ];
        
        $result = $client->provisionOnu($data);
        $client->disconnect();
        echo json_encode(['success' => true, 'log' => $result['log']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal konek ke OLT via SSH.']);
    }
    exit;
}

// === AJAX: Cari SN di OLT ===
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'find_sn') {
    header('Content-Type: application/json');
    $olt_id = (int)($_GET['olt_id'] ?? 0);
    $sn = strtoupper(trim($_GET['sn'] ?? ''));
    $olt = getOlt($olt_id);
    
    if (!$olt) {
        echo json_encode(['success' => false, 'message' => 'OLT tidak ditemukan.']);
        exit;
    }

    $client = new ZTE_OLT($olt['host'], $olt['username'], $olt['password'], $olt['port'], $olt['protocol'] ?? 'ssh');
    if ($client->connect()) {
        $uncfg = $client->getUnconfiguredOnu();
        $found = null;
        foreach ($uncfg as $o) {
            if (strcasecmp($o['sn'], $sn) === 0) {
                $found = $o;
                break;
            }
        }
        $client->disconnect();
        
        if ($found) {
            echo json_encode(['success' => true, 'port' => $found['port'], 'onu_id' => $found['id']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'SN tidak ditemukan di daftar unconfigured OLT.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal konek ke OLT via SSH.']);
    }
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('customers.php');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $pppoeUsername = sanitize($_POST['pppoe_username']);
                $pppoePassword = isset($_POST['pppoe_password']) ? trim((string) $_POST['pppoe_password']) : '12345678';
                if (empty($pppoePassword)) $pppoePassword = '12345678';

                $data = [
                    'name' => sanitize($_POST['name']),
                    'phone' => sanitize($_POST['phone']),
                    'pppoe_username' => $pppoeUsername,
                    'pppoe_password' => $pppoePassword,
                    'package_id' => (int)$_POST['package_id'],
                    'router_id' => (int)($_POST['router_id'] ?? 0),
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address' => sanitize($_POST['address']),
                    'lat' => (!isset($_POST['lat']) || trim($_POST['lat']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lat'])),
                    'lng' => (!isset($_POST['lng']) || trim($_POST['lng']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lng'])),
                    'installed_by' => (int)($_POST['installed_by'] ?? 0),
                    'olt_id' => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn' => sanitize($_POST['onu_sn'] ?? ''),
                    'olt_pon_port' => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id' => (int)($_POST['onu_id'] ?? 0),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $id = insert('customers', $data);
                if ($id) {
                    // Sync to Map (onu_locations) if checkbox checked and coordinates present
                    if (isset($_POST['sync_map']) && !empty($data['lat']) && !empty($data['lng'])) {
                        $lookupSn = !empty($data['onu_sn']) ? $data['onu_sn'] : $data['phone'];
                        $existingOnu = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$lookupSn]);
                        $onuDataSave = [
                            'name' => $data['name'],
                            'lat' => $data['lat'],
                            'lng' => $data['lng'],
                            'updated_at' => date('Y-m-d H:i:s')
                        ];
                        if ($existingOnu) {
                            update('onu_locations', $onuDataSave, 'id = ?', [$existingOnu['id']]);
                        } else {
                            $onuDataSave['serial_number'] = $lookupSn;
                            $onuDataSave['created_at'] = date('Y-m-d H:i:s');
                            insert('onu_locations', $onuDataSave);
                        }
                    }

                    // Push to MikroTik if checkbox checked
                    if (isset($_POST['sync_mikrotik'])) {
                        $pkg = fetchOne("SELECT profile_normal FROM packages WHERE id = ?", [$data['package_id']]);
                        $profile = $pkg ? $pkg['profile_normal'] : 'default';
                        $mt = mikrotikAddSecret($pppoeUsername, $pppoePassword, $profile, $data['router_id']);
                        
                        if ($mt['success']) {
                            setFlash('success', 'Pelanggan berhasil ditambahkan dan di-push ke MikroTik');
                        } else {
                            setFlash('warning', 'Pelanggan disimpan di database, tapi GAGAL push ke MikroTik: ' . $mt['message']);
                        }
                    } else {
                        setFlash('success', 'Pelanggan berhasil ditambahkan ke database (Tanpa Sync MikroTik)');
                    }
                } else {
                    setFlash('error', 'Gagal menambahkan pelanggan ke database');
                }
                break;

            case 'edit':
                $id = (int)$_POST['customer_id'];
                $oldData = fetchOne("SELECT * FROM customers WHERE id = ?", [$id]);
                $pppoePassword = isset($_POST['pppoe_password']) ? trim((string) $_POST['pppoe_password']) : '';

                $data = [
                    'name' => sanitize($_POST['name']),
                    'phone' => sanitize($_POST['phone']),
                    'package_id' => (int)$_POST['package_id'],
                    'router_id' => (int)($_POST['router_id'] ?? 0),
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address' => sanitize($_POST['address']),
                    'lat' => (!isset($_POST['lat']) || trim($_POST['lat']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lat'])),
                    'lng' => (!isset($_POST['lng']) || trim($_POST['lng']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lng'])),
                    'installed_by' => (int)($_POST['installed_by'] ?? 0),
                    'olt_id' => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn' => sanitize($_POST['onu_sn'] ?? ''),
                    'olt_pon_port' => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id' => (int)($_POST['onu_id'] ?? 0),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if (!empty($pppoePassword)) {
                    $data['pppoe_password'] = $pppoePassword;
                }

                if (update('customers', $data, 'id = ?', [$id])) {
                    // Sync to Map (onu_locations) if checkbox checked and coordinates present
                    if (isset($_POST['sync_map']) && !empty($data['lat']) && !empty($data['lng'])) {
                        $lookupSn = !empty($data['onu_sn']) ? $data['onu_sn'] : $data['phone'];
                        $existingOnu = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$lookupSn]);
                        $onuDataSave = [
                            'name' => $data['name'],
                            'lat' => $data['lat'],
                            'lng' => $data['lng'],
                            'updated_at' => date('Y-m-d H:i:s')
                        ];
                        if ($existingOnu) {
                            update('onu_locations', $onuDataSave, 'id = ?', [$existingOnu['id']]);
                        } else {
                            $onuDataSave['serial_number'] = $lookupSn;
                            $onuDataSave['created_at'] = date('Y-m-d H:i:s');
                            insert('onu_locations', $onuDataSave);
                        }
                    }

                    // Update MikroTik if checkbox checked
                    if (isset($_POST['sync_mikrotik']) && $oldData) {
                        $pkg = fetchOne("SELECT profile_normal, profile_isolir FROM packages WHERE id = ?", [$data['package_id']]);
                        $profile = 'default';
                        if ($pkg) {
                            $profile = ($oldData['status'] === 'isolated') ? $pkg['profile_isolir'] : $pkg['profile_normal'];
                        }
                        
                        $mtUpdate = [
                            'name' => $oldData['pppoe_username'], // Username doesn't change
                            'profile' => $profile
                        ];
                        if (!empty($pppoePassword)) {
                            $mtUpdate['password'] = $pppoePassword;
                        }
                        
                        $mt = mikrotikUpdateSecret($oldData['pppoe_username'], $mtUpdate, $data['router_id']);
                        
                        if (!$mt['success']) {
                            // If update fails (e.g. secret missing on router), try to Add it
                            $mt = mikrotikAddSecret($oldData['pppoe_username'], $mtUpdate['password'] ?? $oldData['pppoe_password'] ?: '12345678', $profile, $data['router_id']);
                        }
                        
                        if ($mt['success']) {
                            setFlash('success', 'Data pelanggan berhasil diperbarui dan disinkronkan ke MikroTik');
                        } else {
                            setFlash('warning', 'Data database diupdate, tapi GAGAL sinkron MikroTik: ' . $mt['message']);
                        }
                    } else {
                        setFlash('success', 'Data pelanggan berhasil diperbarui di database');
                    }
                } else {
                    setFlash('error', 'Gagal memperbarui data pelanggan di database');
                }
                break;

            case 'delete':
                $id = (int)$_POST['customer_id'];
                $oldData = fetchOne("SELECT pppoe_username, router_id FROM customers WHERE id = ?", [$id]);
                if (delete('customers', 'id = ?', [$id])) {
                    // Try to delete from MikroTik too
                    if ($oldData && !empty($oldData['pppoe_username'])) {
                        mikrotikDeleteSecret($oldData['pppoe_username'], $oldData['router_id']);
                    }
                    setFlash('success', 'Pelanggan berhasil dihapus dari database dan MikroTik');
                } else {
                    setFlash('error', 'Gagal menghapus pelanggan');
                }
                break;

            case 'sync_all':
                $allCustomers = fetchAll("SELECT id, name, pppoe_username, pppoe_password, package_id, router_id, status FROM customers");
                $successCount = 0;
                $failCount = 0;
                foreach ($allCustomers as $c) {
                    $pkg = fetchOne("SELECT profile_normal, profile_isolir FROM packages WHERE id = ?", [$c['package_id']]);
                    if ($pkg) {
                        $profile = ($c['status'] === 'isolated') ? $pkg['profile_isolir'] : $pkg['profile_normal'];
                        $res = mikrotikUpdateSecret($c['pppoe_username'], [
                            'password' => $c['pppoe_password'] ?: '12345678',
                            'profile' => $profile
                        ], $c['router_id']);
                        
                        if (!$res['success']) {
                            // If doesn't exist, try to Add
                            $res = mikrotikAddSecret($c['pppoe_username'], $c['pppoe_password'] ?: '12345678', $profile, $c['router_id']);
                        }
                        
                        if ($res['success']) $successCount++;
                        else $failCount++;
                    }
                }
                setFlash('info', "Sinkronisasi Massal MikroTik Selesai: $successCount Berhasil, $failCount Gagal.");
                break;

            case 'sync_map':
                $allCustomers = fetchAll("SELECT id, name, phone, onu_sn, lat, lng FROM customers");
                $successCount = 0;
                $skipCount = 0;
                
                if (is_array($allCustomers)) {
                    foreach ($allCustomers as $c) {
                        // PHP-level validation circumvents strict SQL constraints
                        if (empty($c['lat']) || empty($c['lng']) || $c['lat'] == 0 || $c['lng'] == 0 || trim($c['lat']) === '') {
                            $skipCount++;
                            continue;
                        }
                        
                        $sn = !empty($c['onu_sn']) ? $c['onu_sn'] : (!empty($c['phone']) ? $c['phone'] : 'CUST-' . $c['id']);
                        
                        $existingOnu = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$sn]);
                        if ($existingOnu) {
                            if (update('onu_locations', ['lat' => $c['lat'], 'lng' => $c['lng'], 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$existingOnu['id']])) {
                                $successCount++;
                            }
                        } else {
                            if (insert('onu_locations', [
                                'name' => $c['name'] ?: 'Customer #' . $c['id'],
                                'serial_number' => $sn,
                                'lat' => $c['lat'],
                                'lng' => $c['lng'],
                                'created_at' => date('Y-m-d H:i:s')
                            ])) {
                                $successCount++;
                            }
                        }
                    }
                    setFlash('success', "Sinkronisasi Peta Selesai: $successCount disuntikkan ke map, $skipCount dilewati (tanpa koordinat).");
                } else {
                    setFlash('error', "Gagal memuat data pelanggan untuk sinkronisasi.");
                }
                break;
        }
        redirect('customers.php');
    }
}

// Fetch all packages for dropdown
$packages = fetchAll("SELECT id, name, price FROM packages ORDER BY price ASC");

// Fetch routers
$routers = fetchAll("SELECT id, name, host FROM routers ORDER BY name ASC");

// Fetch OLTs
$olts = fetchAll("SELECT id, name FROM olts ORDER BY name ASC");

// Fetch Technicians
$technicians = fetchAll("SELECT id, name, username FROM technician_users WHERE status = 'active' ORDER BY name ASC");

// Pagination settings
$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter and Search
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$whereClause = "1=1";
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (name LIKE ? OR phone LIKE ? OR pppoe_username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Total records for pagination
$totalRecords = fetchOne("SELECT COUNT(*) as count FROM customers WHERE $whereClause", $params)['count'];
$totalPages = ceil($totalRecords / $limit);

// Fetch customers with tagging status
$customers = fetchAll("
    SELECT c.*, p.name as package_name, p.price as package_price, r.name as router_name, t.name as technician_name,
    (SELECT status FROM task_queue WHERE task_type = 'acs_tag' AND JSON_EXTRACT(payload, '$.tag') = c.phone ORDER BY created_at DESC LIMIT 1) as tag_status
    FROM customers c
    LEFT JOIN packages p ON c.package_id = p.id
    LEFT JOIN routers r ON c.router_id = r.id
    LEFT JOIN technician_users t ON c.installed_by = t.id
    WHERE $whereClause
    ORDER BY c.created_at DESC
    LIMIT $limit OFFSET $offset
", $params);

// === Stats Metrics ===
$stat_total = $totalRecords;
$stat_active = fetchOne("SELECT COUNT(*) as count FROM customers WHERE status = 'active'")['count'];
$stat_no_olt = fetchOne("SELECT COUNT(*) as count FROM customers WHERE olt_id = 0 OR olt_id IS NULL")['count'];
$stat_pending_acs = fetchOne("SELECT COUNT(*) as count FROM task_queue WHERE task_type = 'acs_tag' AND status IN ('pending', 'processing')")['count'];

?>

<style>
    .olt-box {
        background: rgba(0, 245, 255, 0.05);
        border: 1px solid var(--border-color);
        padding: 20px;
        border-radius: var(--border-radius-md);
        margin-top: 15px;
    }

    .prov-log-box {
        margin-top: 15px;
        padding: 12px;
        background: #000;
        color: var(--neon-green);
        font-family: 'JetBrains Mono', 'Courier New', monospace;
        font-size: 11px;
        border-radius: 8px;
        max-height: 200px;
        overflow-y: auto;
        white-space: pre-wrap;
        border: 1px solid var(--border-color);
    }

    .svc-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-bottom: 20px;
    }
    .svc-label {
        font-size: 13px;
        background: rgba(255,255,255,0.03);
        padding: 12px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
    }
    .binding-box {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed var(--border-color);
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .bind-item {
        font-size: 10px;
        padding: 4px 8px;
        background: rgba(0,0,0,0.4);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 4px;
        transition: all 0.3s;
    }
    .bind-item:hover { background: rgba(0, 245, 255, 0.1); }
    .bind-item.active {
        border-color: var(--neon-green);
        background: rgba(0, 255, 136, 0.1);
        color: var(--neon-green);
    }
    .bind-item input { display: none; }

    .add-form-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding: 15px 20px;
        border-radius: var(--border-radius-md);
        transition: all 0.3s;
    }
    .add-form-header:hover { background: rgba(255,255,255,0.05); }
    .add-form-content { display: none; margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px; }
    .add-form-content.show { display: block; }
    .icon-toggle { transition: transform 0.3s; }
    .icon-toggle.active { transform: rotate(180deg); }

    .magic-save-btn {
        background: linear-gradient(135deg, var(--neon-cyan), var(--neon-purple));
        box-shadow: 0 0 15px rgba(0, 245, 255, 0.3);
    }
    .magic-save-btn:hover { box-shadow: 0 0 25px rgba(0, 245, 255, 0.5); }
</style>

<!-- Metrics Overview -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-users"></i></div>
        <div class="stat-info">
            <h3><?php echo $stat_total; ?></h3>
            <p>Total Pelanggan</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
        <div class="stat-info">
            <h3><?php echo $stat_active; ?></h3>
            <p>Layanan Aktif</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-microchip"></i></div>
        <div class="stat-info">
            <h3><?php echo $stat_no_olt; ?></h3>
            <p>Belum Setting OLT</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon cyan"><i class="fas fa-tags"></i></div>
        <div class="stat-info">
            <h3><?php echo $stat_pending_acs; ?></h3>
            <p>Tagging ACS Pending</p>
        </div>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="add-form-header" onclick="toggleAddForm()">
        <h3 class="card-title" style="margin:0;">
            <i class="fas fa-user-plus"></i> Tambah Pelanggan Baru
        </h3>
        <i class="fas fa-chevron-down icon-toggle" id="icon-toggle-add"></i>
    </div>
    
    <div class="add-form-content" id="add-form-content">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                <div class="form-group">
                    <label class="form-label">Nama Pelanggan</label>
                    <input type="text" name="name" id="add_customer_name" class="form-control" required placeholder="Nama Lengkap">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nomor HP (WhatsApp)</label>
                    <input type="text" name="phone" class="form-control" required placeholder="08xxxxxxxxxx">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Username PPPoE (MikroTik)</label>
                    <div style="position:relative;">
                        <input type="text" name="pppoe_username" id="pppoe_username_input" class="form-control" placeholder="Otomatis dari Nama Pelanggan" readonly style="background: rgba(255,255,255,0.05); cursor: default; padding-right: 40px;">
                        <div id="username_spinner" style="display:none; position:absolute; right:12px; top:12px; color:var(--neon-cyan);"><i class="fas fa-spinner fa-spin"></i></div>
                        <div id="username_ok" style="display:none; position:absolute; right:12px; top:12px; color:var(--neon-green);"><i class="fas fa-check-circle"></i></div>
                    </div>
                    <small style="color: var(--text-secondary);">Akan dibuat otomatis saat Anda mengetik nama</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Password PPPoE</label>
                    <input type="text" name="pppoe_password" class="form-control" placeholder="Default: 12345678" value="12345678">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Paket Langganan</label>
                    <select name="package_id" class="form-control" required style="color: var(--text-primary); background: var(--bg-card);">
                        <option value="">Pilih Paket</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>">
                                <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo formatCurrency($pkg['price']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Router / MikroTik</label>
                    <select name="router_id" class="form-control" required style="color: var(--text-primary); background: var(--bg-card);">
                        <option value="0">Default Router</option>
                        <?php foreach ($routers as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['host']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tanggal Isolir (1-28)</label>
                    <input type="number" name="isolation_date" class="form-control" min="1" max="28" value="20" required>
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" class="form-control" rows="2" placeholder="Alamat rumah"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Teknisi Pemasang</label>
                    <select name="installed_by" class="form-control" style="background: var(--bg-card);">
                        <option value="0">-- Pilih Teknisi --</option>
                        <?php foreach ($technicians as $tech): ?>
                            <option value="<?php echo $tech['id']; ?>"><?php echo htmlspecialchars($tech['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- OLT DATA (ADD) -->
            <div class="form-group olt-box">
                <label class="form-label" style="color:var(--neon-cyan,#00d2ff);display:block;margin-bottom:12px;"><i class="fas fa-microchip"></i> Registrasi ONU / OLT (Opsional)</label>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div><label class="form-label">OLT</label>
                        <select name="olt_id" id="add_olt_id" class="form-control" style="background: var(--bg-card);">
                            <option value="0">-- Tanpa OLT --</option>
                            <?php foreach ($olts as $o): ?>
                                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="form-label">Serial Number (SN)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="onu_sn" id="add_onu_sn" class="form-control" placeholder="Contoh: FHTTC098844B">
                            <button type="button" class="btn btn-secondary" onclick="startScanner('add_onu_sn')" title="Scan Barcode SN">
                                <i class="fas fa-camera"></i>
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="findOnOlt('add')" id="btn_find_olt_add" title="Cari Port & ID di OLT">
                                <i class="fas fa-search-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div><label class="form-label">PON Port (0/x)</label>
                        <input type="number" name="olt_pon_port" id="add_pon_port" class="form-control" min="1" max="8">
                    </div>
                    <div><label class="form-label">ONU ID</label>
                        <input type="number" name="onu_id" id="add_onu_id" class="form-control" placeholder="Nomor ONU di port">
                    </div>
                </div>

                <!-- PROVISIONING PANEL (ADD FORM) -->
                <div style="margin-top:15px;padding:12px;background:rgba(0,255,65,0.03);border:1px solid rgba(0,255,65,0.1);border-radius:8px;">
                    <label style="color:#00ff41;display:block;margin-bottom:10px;font-size:12px;font-weight:bold;"><i class="fas fa-rocket"></i> Provisioning ONU (Opsional)</label>
                    <div class="svc-grid">
                        <div class="svc-label">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" id="add_svc_acs" value="acs" checked>
                                <span>TR-069<br><small>VLAN 101</small></span>
                            </div>
                            <label style="display:flex; align-items:center; gap:5px; margin-top:5px; font-size:10px; color:#888;">
                                <input type="checkbox" id="add_confirm_tag" checked> Kirim Tag ke ACS
                            </label>
                        </div>
                        <div class="svc-label">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" id="add_svc_wifi" value="wifi" checked>
                                <span>WiFi SSID 2<br><small>Jinom_Hotspot</small></span>
                            </div>
                        </div>
                        <div class="svc-label" style="grid-column: span 2;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" id="add_svc_pppoe" value="pppoe" checked>
                                <span>PPPoE (VLAN 100)</span>
                            </div>
                            <div class="binding-box">
                                <?php for($i=1;$i<=4;$i++): ?>
                                <label class="bind-item active"><input type="checkbox" name="add_p_bind[]" value="LAN<?php echo $i; ?>" checked onchange="syncBindings('add')">L<?php echo $i; ?></label>
                                <?php endfor; ?>
                                <?php for($i=1;$i<=8;$i++): ?>
                                <label class="bind-item active"><input type="checkbox" name="add_p_bind[]" value="SSID<?php echo $i; ?>" checked onchange="syncBindings('add')">W<?php echo $i; ?></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="svc-label" style="grid-column: span 2;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" id="add_svc_hotspot" value="hotspot">
                                <span>Bridge (VLAN 200)</span>
                            </div>
                            <div class="binding-box">
                                <?php for($i=1;$i<=4;$i++): ?>
                                <label class="bind-item"><input type="checkbox" name="add_b_bind[]" value="LAN<?php echo $i; ?>" onchange="syncBindings('add')">L<?php echo $i; ?></label>
                                <?php endfor; ?>
                                <?php for($i=1;$i<=8;$i++): ?>
                                <label class="bind-item"><input type="checkbox" name="add_b_bind[]" value="SSID<?php echo $i; ?>" onchange="syncBindings('add')">W<?php echo $i; ?></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    <div id="add_prov_log" class="prov-log-box" style="display:none;margin-top:10px;"></div>
                    <button type="button" class="btn btn-sm" style="background:#00ff41;color:#000;width:100%;margin-top:8px;" onclick="doProvision('add')">
                        <i class="fas fa-bolt"></i> PROVISIONING SEKARANG
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Lokasi (Latitude, Longitude)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <input type="text" name="lat" id="add_lat" class="form-control" placeholder="Latitude" readonly>
                    <input type="text" name="lng" id="add_lng" class="form-control" placeholder="Longitude" readonly>
                </div>
                <small style="color: var(--text-secondary); margin-top: 5px; display: block;">Klik pada peta untuk menentukan lokasi rumah pelanggan</small>
            </div>
            
            <!-- SYNC CONTROLLERS (ADD) -->
            <div style="margin-bottom: 20px; padding: 15px; background: rgba(0,210,255,0.05); border: 1px dashed rgba(0,210,255,0.2); border-radius: 10px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: var(--neon-cyan); font-size: 13px;">
                    <i class="fas fa-sync-alt"></i> PENGATURAN SINKRONISASI
                </label>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <label class="svc-label" style="flex: 1; min-width: 150px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="sync_map" value="1" checked style="width:16px; height:16px;">
                        <span>Update Pin/Lokasi ke Peta (ONU Map)</span>
                    </label>
                    <label class="svc-label" style="flex: 1; min-width: 150px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="sync_mikrotik" value="1" checked style="width:16px; height:16px;">
                        <span>Push Akun ke MikroTik (Traffic Monitor)</span>
                    </label>
                </div>
                <small style="color: var(--text-secondary); margin-top: 5px; display: block; font-size: 11px;">
                    * Menggunakan <strong>Nomor HP Pelanggan</strong> sebagai Tag Identifikasi (ACS Tag).
                </small>
            </div>
            
            <button type="button" id="btn_magic_save_add" class="btn btn-primary magic-save-btn" style="width: 100%;" onclick="magicSave('add')">
                <i class="fas fa-magic"></i> Simpan Pelanggan (Magic Save)
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-users"></i> Daftar Pelanggan
        </h3>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <form method="POST" style="margin:0;" onsubmit="return confirm('Suntikkan / Sinkronisasikan semua pelanggan yang memiliki koordinat ke dalam sistem Peta (Map)?')">
                <input type="hidden" name="action" value="sync_map">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-map-marked-alt"></i> Sync Map
                </button>
            </form>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Update semua pelanggan ke MikroTik?')">
                <input type="hidden" name="action" value="sync_all">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <button type="submit" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sync"></i> Sync MikroTik
                </button>
            </form>
            <input type="text" id="searchCustomer" class="form-control" placeholder="Cari pelanggan..." style="width: 250px;">
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table" id="customerTable">
            <thead>
                <tr>
                    <th>Pelanggan</th>
                    <th>PPPoE User</th>
                    <th>Paket</th>
                    <th>Router</th>
                    <th>Alamat</th>
                    <th>Pemasang</th>
                    <th style="width: 100px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: var(--text-secondary);">
                            Tidak ada data pelanggan ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td data-label="Pelanggan">
                                <div style="font-weight: 600; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;">
                                    <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($customer['phone']); ?>
                                    <?php 
                                        $tagColor = '#444'; 
                                        $tagTitle = 'Belum/Tidak ditag ke ACS';
                                        if ($customer['tag_status'] === 'completed') { $tagColor = 'var(--neon-green)'; $tagTitle = 'Terkonfirmasi di ACS'; }
                                        elseif ($customer['tag_status'] === 'pending' || $customer['tag_status'] === 'processing') { $tagColor = 'var(--neon-orange)'; $tagTitle = 'Menunggu konfirmasi ACS (5 menit)'; }
                                        elseif ($customer['tag_status'] === 'failed') { $tagColor = 'var(--neon-red)'; $tagTitle = 'Gagal konfirmasi ACS'; }
                                    ?>
                                    <i class="fas fa-tag" style="color:<?php echo $tagColor; ?>; font-size: 10px;" title="<?php echo $tagTitle; ?>"></i>
                                </div>
                            </td>
                            <td data-label="PPPoE User">
                                <code style="color: var(--neon-cyan)">
                                    <?php echo htmlspecialchars($customer['pppoe_username'] ?: '-'); ?>
                                </code>
                            </td>
                            <td data-label="Paket">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($customer['package_name'] ?: 'N/A'); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                    Isolir Tgl <?php echo $customer['isolation_date'] ?: 20; ?>
                                </div>
                            </td>
                            <td data-label="Router">
                                <div style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?php echo htmlspecialchars($customer['router_name'] ?: 'Default'); ?>
                                </div>
                            </td>
                            <td data-label="Alamat">
                                <div style="font-size: 0.85rem; max-width: 15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($customer['address']); ?>">
                                    <?php echo htmlspecialchars($customer['address'] ?: '-'); ?>
                                </div>
                            </td>
                            <td data-label="Pemasang">
                                <div style="font-size: 0.85rem; color: var(--text-muted);">
                                    <i class="fas fa-tools" style="font-size:0.75rem opacity: 0.7"></i> <?php echo htmlspecialchars($customer['technician_name'] ?: '-'); ?>
                                </div>
                            </td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: 8px;">
                                    <button onclick='editCustomer(<?php echo json_encode($customer); ?>)' class="btn btn-secondary btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus pelanggan ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="color: var(--neon-red);">
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
    
    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 24px;">
        <a href="?page=1" class="btn btn-secondary btn-sm" <?php echo $page === 1 ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-double-left"></i>
        </a>
        <a href="?page=<?php echo max(1, $page-1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === 1 ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-chevron-left"></i>
        </a>
        
        <span style="display: flex; align-items: center; padding: 0 15px; font-size: 0.9rem; color: var(--text-secondary);">
            Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
        </span>
        
        <a href="?page=<?php echo min($totalPages, $page+1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === $totalPages ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-chevron-right"></i>
        </a>
        <a href="?page=<?php echo $totalPages; ?>" class="btn btn-secondary btn-sm" <?php echo $page === $totalPages ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-double-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
        
<!-- PPPoE User Modal -->
<div id="pppoeUserModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000;">
    <div class="card" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 90%; max-width: 360px; max-height: 80vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0; color: var(--neon-cyan);">
                <i class="fas fa-network-wired"></i> Pilih Username PPPoE
            </h3>
            <button type="button" onclick="closePppoeUserModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">&times;</button>
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <input type="text" id="pppoeUserSearch" class="form-control" placeholder="Cari username PPPoE...">
        </div>
        <div id="pppoeUserList" style="max-height: 60vh; overflow-y: auto;"></div>
    </div>
</div>
        
<!-- Edit Customer Modal -->
<div id="editCustomerModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 800px; max-width: 90%; margin: 2rem; max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: var(--neon-cyan);">
                <i class="fas fa-edit"></i> Edit Pelanggan
            </h3>
            <button onclick="closeEditModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">&times;</button>
        </div>
        
        <form method="POST" id="editCustomerForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="customer_id" id="edit_customer_id">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                <div class="form-group">
                    <label class="form-label">Nama Pelanggan</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required placeholder="Nama Lengkap">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nomor HP (WhatsApp)</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control" required placeholder="08xxxxxxxxxx">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Username PPPoE</label>
                    <input type="text" name="pppoe_username" id="edit_pppoe_username" class="form-control" required placeholder="Username di MikroTik" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed;">
                    <small style="color: var(--text-muted);">Username PPPoE tidak dapat diubah</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Password PPPoE</label>
                    <input type="text" name="pppoe_password" id="edit_pppoe_password" class="form-control" placeholder="Biarkan kosong jika tidak ingin mengubah password">
                    <small style="color: var(--text-muted);">Isi hanya jika ingin mengubah password di MikroTik</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Paket Langganan</label>
                    <select name="package_id" id="edit_package_id" class="form-control" required style="color: var(--text-primary); background: var(--bg-card);">
                        <option value="">Pilih Paket</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>">
                                <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo formatCurrency($pkg['price']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Router / MikroTik</label>
                    <select name="router_id" id="edit_router_id" class="form-control" required style="color: var(--text-primary); background: var(--bg-card);">
                        <option value="0">Default Router</option>
                        <?php foreach ($routers as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['host']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tanggal Isolir (1-28)</label>
                    <input type="number" name="isolation_date" id="edit_isolation_date" class="form-control" min="1" max="28" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" id="edit_address" class="form-control" rows="2" placeholder="Alamat rumah"></textarea>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Lokasi (Latitude, Longitude)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <input type="text" name="lat" id="edit_lat" class="form-control" placeholder="Latitude" readonly>
                    <input type="text" name="lng" id="edit_lng" class="form-control" placeholder="Longitude" readonly>
                </div>
                <small style="color: var(--text-muted);">Klik pada peta untuk set lokasi</small>
            </div>
            
            <div style="height: 300px; margin-top: 15px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color);" id="edit-map-picker"></div>

            <!-- OLT DATA -->
            <div class="form-group olt-box" style="margin-top: 15px; grid-column: 1 / -1;">
                <label class="form-label" style="color:var(--neon-cyan,#00d2ff);display:block;margin-bottom:12px;"><i class="fas fa-microchip"></i> Data ONU / OLT</label>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div><label class="form-label">OLT</label>
                        <select name="olt_id" id="edit_olt_id" class="form-control" style="background: var(--bg-card);">
                            <option value="0">-- Tanpa OLT --</option>
                            <?php foreach ($olts as $o): ?>
                                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="form-label">Serial Number (SN)</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" name="onu_sn" id="edit_onu_sn" class="form-control" placeholder="Contoh: FHTTC098844B">
                            <button type="button" class="btn btn-secondary" onclick="startScanner('edit_onu_sn')" title="Scan Barcode SN">
                                <i class="fas fa-camera"></i>
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="findOnOlt()" id="btn_find_olt" title="Cari Port & ID di OLT">
                                <i class="fas fa-search-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div><label class="form-label">PON Port (0/x)</label>
                        <input type="number" name="olt_pon_port" id="edit_pon_port" class="form-control" min="1" max="8">
                    </div>
                    <div><label class="form-label">ONU ID</label>
                        <input type="number" name="onu_id" id="edit_onu_id" class="form-control" placeholder="Nomor ONU di port">
                    </div>
                </div>
            </div>

            <!-- PROVISIONING PANEL -->
            <div style="margin-top:20px;padding:16px;background:rgba(0,255,65,0.04);border:1px solid rgba(0,255,65,0.15);border-radius:10px;grid-column: 1 / -1;">
                <label style="color:#00ff41;display:block;margin-bottom:12px;font-weight:bold;"><i class="fas fa-rocket"></i> Auto-Provisioning ONU</label>
                <input type="hidden" id="prov_acs_url" value="http://172.16.200.3:7547">
                
                <div class="svc-grid">
                    <div class="svc-label">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="svc_acs" value="acs" checked>
                            <span><i class="fas fa-broadcast-tower" style="color:#00d2ff"></i> TR-069 / ACS<br><small>VLAN 101 DHCP</small></span>
                        </div>
                    </div>
                    <div class="svc-label">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="svc_wifi" value="wifi" checked>
                            <span><i class="fas fa-wifi" style="color:#00d2ff"></i> WiFi SSID 2<br><small>Jinom_Hotspot</small></span>
                        </div>
                    </div>
                    <div class="svc-label">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="svc_pppoe" value="pppoe" checked>
                            <span><i class="fas fa-globe" style="color:#00d2ff"></i> PPPoE (VLAN 100)</span>
                        </div>
                        <div class="binding-box">
                            <?php for($i=1;$i<=4;$i++): ?>
                            <label class="bind-item active"><input type="checkbox" name="edit_p_bind[]" value="LAN<?php echo $i; ?>" checked onchange="syncBindings()">L<?php echo $i; ?></label>
                            <?php endfor; ?>
                            <?php for($i=1;$i<=8;$i++): ?>
                            <label class="bind-item active"><input type="checkbox" name="edit_p_bind[]" value="SSID<?php echo $i; ?>" checked onchange="syncBindings()">W<?php echo $i; ?></label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="svc-label">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <input type="checkbox" id="svc_hotspot" value="hotspot">
                            <span><i class="fas fa-server" style="color:#00d2ff"></i> Bridge (VLAN 200)</span>
                        </div>
                        <div class="binding-box">
                            <?php for($i=1;$i<=4;$i++): ?>
                            <label class="bind-item"><input type="checkbox" name="edit_b_bind[]" value="LAN<?php echo $i; ?>" onchange="syncBindings()">L<?php echo $i; ?></label>
                            <?php endfor; ?>
                            <?php for($i=1;$i<=8;$i++): ?>
                            <label class="bind-item"><input type="checkbox" name="edit_b_bind[]" value="SSID<?php echo $i; ?>" onchange="syncBindings()">W<?php echo $i; ?></label>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:12px;color:#888">PPPoE Password (digunakan saat provisioning)</label>
                    <input type="text" id="prov_pppoe_pass" class="form-control" placeholder="12345678" value="12345678">
                </div>

                <button type="button" class="btn" style="background:#00ff41;color:#000;width:100%;font-weight:bold;height:44px;" onclick="doProvision()">
                    <i class="fas fa-bolt"></i> KIRIM KE OLT SEKARANG
                </button>
                <div id="prov_log" class="prov-log-box" style="display:none;"></div>
            </div>
            
            <!-- SYNC CONTROLLERS (EDIT) -->
            <div style="margin-bottom: 20px; padding: 15px; background: rgba(0,210,255,0.05); border: 1px dashed rgba(0,210,255,0.2); border-radius: 10px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: var(--neon-cyan); font-size: 13px;">
                    <i class="fas fa-sync-alt"></i> PENGATURAN SINKRONISASI
                </label>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <label class="svc-label" style="flex: 1; min-width: 150px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="sync_map" value="1" checked style="width:16px; height:16px;">
                        <span>Update Pin/Lokasi ke Peta (ONU Map)</span>
                    </label>
                    <label class="svc-label" style="flex: 1; min-width: 150px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="sync_mikrotik" value="1" checked style="width:16px; height:16px;">
                        <span>Push Akun ke MikroTik (Traffic Monitor)</span>
                    </label>
                </div>
                <small style="color: var(--text-secondary); margin-top: 5px; display: block; font-size: 11px;">
                    * Menggunakan <strong>Nomor HP Pelanggan</strong> sebagai Tag Identifikasi (ACS Tag).
                </small>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" id="btn_magic_save_edit" class="btn btn-primary magic-save-btn" style="flex: 1;" onclick="magicSave('edit')">
                    <i class="fas fa-magic"></i> Simpan Perubahan (Magic Save)
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="flex: 1;">
                    Batal
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<script>
let map, marker;
let editMap, editMarker;
let pppoeUsers = [];

function initMap() {
    if (map) return;
    map = L.map('map-picker').setView([-6.200000, 106.816666], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);
    map.on('click', function(e) {
        if (marker) map.removeLayer(marker);
        marker = L.marker(e.latlng).addTo(map);
        document.getElementById('add_lat').value = e.latlng.lat.toFixed(6);
        document.getElementById('add_lng').value = e.latlng.lng.toFixed(6);
    });
    setTimeout(() => { map.invalidateSize(); }, 300);
}

function initEditMap() {
    if (editMap) return;
    editMap = L.map('edit-map-picker').setView([-6.200000, 106.816666], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(editMap);
    editMap.on('click', function(e) {
        if (editMarker) editMap.removeLayer(editMarker);
        editMarker = L.marker(e.latlng).addTo(editMap);
        document.getElementById('edit_lat').value = e.latlng.lat.toFixed(6);
        document.getElementById('edit_lng').value = e.latlng.lng.toFixed(6);
    });
    setTimeout(() => { editMap.invalidateSize(); }, 300);
}

function openPppoeUserModal() {
    const modal = document.getElementById('pppoeUserModal');
    if (!modal) return;
    modal.style.display = 'flex';
    
    const list = document.getElementById('pppoeUserList');
    if (list) {
        list.innerHTML = '<div style="padding: 10px; color: var(--text-secondary);">Memuat data dari MikroTik...</div>';
    }
    
    fetch('../api/mikrotik.php?action=users')
        .then(response => response.text())
        .then(text => {
            let data = null;
            try {
                const start = text.indexOf('{');
                if (start !== -1) {
                    data = JSON.parse(text.slice(start));
                }
            } catch (e) {
                console.error('Respon MikroTik tidak valid:', text, e);
            }
            
            if (data && data.success && data.data && Array.isArray(data.data.users)) {
                pppoeUsers = data.data.users;
                renderPppoeUserList(pppoeUsers);
            } else if (list) {
                list.innerHTML = '<div style="padding: 10px; color: var(--text-secondary);">Gagal mengambil data dari MikroTik</div>';
            }
        })
        .catch(error => {
            console.error('Fetch MikroTik error:', error);
            if (list) {
                list.innerHTML = '<div style="padding: 10px; color: var(--text-secondary);">Gagal mengambil data dari MikroTik</div>';
            }
        });
}

function renderPppoeUserList(users) {
    const list = document.getElementById('pppoeUserList');
    if (!list) return;
    list.innerHTML = '';
    users.forEach(u => {
        const div = document.createElement('div');
        div.style.padding = '10px';
        div.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
        div.style.cursor = 'pointer';
        div.innerHTML = `<strong>${u.name}</strong><br><small style="color:#888">${u.profile}</small>`;
        div.onclick = () => {
            const input = document.getElementById('edit_pppoe_username') || document.getElementById('pppoe_username_input');
            if (input) input.value = u.name;
            closePppoeUserModal();
        };
        list.appendChild(div);
    });
}

function closePppoeUserModal() {
    document.getElementById('pppoeUserModal').style.display = 'none';
}

async function findOnOlt(mode = 'edit') {
    const olt_id = document.getElementById(mode + '_olt_id').value;
    const sn     = document.getElementById(mode + '_onu_sn').value.trim();
    const btn    = document.getElementById('btn_find_olt' + (mode === 'add' ? '_add' : ''));
    const logBox = document.getElementById(mode + '_prov_log');

    if (!olt_id || olt_id == 0) { alert('Pilih OLT terlebih dahulu!'); return; }
    if (!sn || sn.length < 8) { alert('Masukkan Serial Number (min 8 karakter)!'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    if (logBox) {
        logBox.style.display = 'block';
        logBox.textContent = `⏳ Mencari SN: ${sn} di OLT...`;
    }

    try {
        const r = await fetch(`customers.php?ajax_action=find_sn&olt_id=${olt_id}&sn=${sn}`);
        const data = await r.json();
        
        if (data.success) {
            document.getElementById(mode + '_pon_port').value = data.port;
            document.getElementById(mode + '_onu_id').value = data.onu_id;
            if (logBox) logBox.innerHTML = `<span style="color:#00ff41">✓ SN Ditemukan!</span>\n  Port: 0/${data.port}\n  ONU ID: ${data.onu_id}`;
        } else {
            if (logBox) logBox.innerHTML = `<span style="color:#ff3131">✗ SN Tidak Ditemukan di OLT.</span>\n  Pesan: ${data.message}`;
        }
    } catch(e) {
        if (logBox) logBox.textContent = '✗ Error: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-search-plus"></i>';
    }
}

// Port Binding Sync (v1.17) - Refactored for Multi-mode
function syncBindings(mode = 'edit') {
    const p_binds = document.querySelectorAll(`input[name="${mode}_p_bind[]"]`);
    const b_binds = document.querySelectorAll(`input[name="${mode}_b_bind[]"]`);

    const updateLabel = (el) => {
        const lbl = el.parentElement;
        if (el.disabled) { lbl.classList.add('disabled'); lbl.classList.remove('active'); }
        else { lbl.classList.remove('disabled'); if (el.checked) lbl.classList.add('active'); else lbl.classList.remove('active'); }
    };

    p_binds.forEach((p, i) => {
        const b = b_binds[i];
        if (p.checked) { b.disabled = true; b.checked = false; } else { b.disabled = false; }
        if (b.checked) { p.disabled = true; p.checked = false; } else { p.disabled = false; }
        updateLabel(p);
        updateLabel(b);
    });
}

// Global provisioning trigger
async function doProvision(mode = 'edit') {
    const olt_id  = document.getElementById(mode + '_olt_id').value;
    const port    = document.getElementById(mode + '_pon_port').value;
    const onu_id  = document.getElementById(mode + '_onu_id').value;
    const pppoe_u = (mode === 'edit') ? document.getElementById('edit_pppoe_username').value : document.getElementById('pppoe_username_input').value;
    const pppoe_p = document.getElementById(mode === 'edit' ? 'prov_pppoe_pass' : 'add_pppoe_pass')?.value || '12345678';
    const acs_url = 'http://172.16.200.3:7547';

    const logBox = document.getElementById(mode + '_prov_log');
    if (logBox) logBox.style.display = 'block';
    
    if (!olt_id || olt_id == 0 || !port || !onu_id) {
        alert('Pastikan OLT, PON Port, dan ONU ID sudah diisi!');
        return;
    }

    const services = [];
    ['acs','pppoe','hotspot','wifi'].forEach(s => {
        if (document.getElementById(mode + '_svc_' + s)?.checked) services.push(s);
    });

    const p_bind = Array.from(document.querySelectorAll(`input[name="${mode}_p_bind[]"]:checked`)).map(el => el.value);
    const b_bind = Array.from(document.querySelectorAll(`input[name="${mode}_b_bind[]"]:checked`)).map(el => el.value);
    
    // Check confirmation tag checkbox
    const confirmTag = document.getElementById(mode + '_confirm_tag')?.checked;

    if (logBox) logBox.textContent = `⏳ Menghubungkan ke OLT... (~15-30 detik)\n   Target: ${olt_id} Port: ${port} ID: ${onu_id}`;

    const fd = new FormData();
    fd.append('olt_id', olt_id);
    fd.append('port', port);
    fd.append('onu_id', onu_id);
    fd.append('pppoe_user', pppoe_u);
    fd.append('pppoe_pass', pppoe_p);
    fd.append('acs_url', acs_url);
    if (!confirmTag) fd.append('skip_tag', '1'); // Pass opt-out if needed (backend logic update below)
    
    services.forEach(s => fd.append('services[]', s));
    p_bind.forEach(b => fd.append('pppoe_bind[]', b));
    b_bind.forEach(b => fd.append('hotspot_bind[]', b));

    try {
        const r = await fetch('customers.php?ajax_action=provision', { method: 'POST', body: fd });
        const data = await r.json();
        if (logBox) {
            logBox.textContent = data.log || 'Selesai.';
            logBox.scrollTop = logBox.scrollHeight;
        }
    } catch(e) {
        if (logBox) logBox.textContent = '✗ Error: ' + e.message;
    }
}

// Magic Save logic: Provision -> Clear Log -> Submit Form
async function magicSave(mode) {
    const btn = document.getElementById('btn_magic_save_' + mode);
    const olt_id = document.getElementById(mode + '_olt_id').value;
    const sn = document.getElementById(mode + '_onu_sn').value.trim();
    const isProvisionEnabled = document.getElementById(mode + '_svc_acs')?.checked || 
                               document.getElementById(mode + '_svc_pppoe')?.checked || 
                               document.getElementById(mode + '_svc_hotspot')?.checked;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-cog fa-spin"></i> Memproses...';

    // 1. Provisioning workflow if OLT data is present and services checked
    if (olt_id != 0 && sn.length > 8 && isProvisionEnabled) {
        btn.innerHTML = '<i class="fas fa-bolt fa-spin"></i> Provisioning ONU...';
        await doProvision(mode);
        
        // Wait a small delay to see the result
        await new Promise(r => setTimeout(r, 2000));
        
        if (confirm('OLT Provisioning selesai. Lanjutkan simpan data ke Database?')) {
            // Proceed to final save
        } else {
            btn.disabled = false;
            btn.innerHTML = mode === 'add' ? '<i class="fas fa-magic"></i> Simpan Pelanggan (Magic Save)' : '<i class="fas fa-magic"></i> Simpan Perubahan (Magic Save)';
            return;
        }
    }

    // 2. Final Form Submit
    const form = (mode === 'add') ? document.querySelector('#add-form-content form') : document.getElementById('editCustomerForm');
    if (form) form.submit();
}

function editCustomer(customer) {
    document.getElementById('edit_customer_id').value = customer.id;
    document.getElementById('edit_name').value = customer.name;
    document.getElementById('edit_phone').value = customer.phone;
    document.getElementById('edit_pppoe_username').value = customer.pppoe_username || '';
    document.getElementById('edit_pppoe_password').value = customer.pppoe_password || '';
    document.getElementById('edit_package_id').value = customer.package_id;
    document.getElementById('edit_router_id').value = customer.router_id || 0;
    document.getElementById('edit_isolation_date').value = customer.isolation_date || 20;
    document.getElementById('edit_address').value = customer.address || '';
    document.getElementById('edit_olt_id').value = customer.olt_id || 0;
    document.getElementById('edit_onu_sn').value = customer.onu_sn || '';
    document.getElementById('edit_pon_port').value = customer.olt_pon_port || '';
    document.getElementById('edit_onu_id').value = customer.onu_id || '';
    document.getElementById('edit_lat').value = customer.lat || '';
    document.getElementById('edit_lng').value = customer.lng || '';

    document.getElementById('editCustomerModal').style.display = 'flex';
    setTimeout(() => {
        initEditMap();
        if (editMap) {
            editMap.invalidateSize();
            if (customer.lat && customer.lng) {
                const latlng = [customer.lat, customer.lng];
                editMap.setView(latlng, 15);
                if (editMarker) editMap.removeLayer(editMarker);
                editMarker = L.marker(latlng).addTo(editMap);
            }
        }
    }, 200);
}

function closeEditModal() {
    document.getElementById('editCustomerModal').style.display = 'none';
}

function loadOdpOptions() {
    fetch('../api/onu_locations.php')
        .then(r => r.json())
        .then(j => {
            if (!j.success) return;
            const odps = j.odps || [];
            const addSel = document.getElementById('add_odp_select');
            const editSel = document.getElementById('edit_odp_select');
            const makeOptions = (sel) => {
                if (!sel) return;
                sel.innerHTML = '<option value="">-- Pilih ODP --</option>';
                odps.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = o.id;
                    opt.textContent = o.name + (o.code ? (' (' + o.code + ')') : '');
                    sel.appendChild(opt);
                });
            };
            makeOptions(addSel);
            makeOptions(editSel);
        })
        .catch(() => {});
}

document.addEventListener('DOMContentLoaded', loadOdpOptions);

let html5QrCode;

function toggleAddForm() {
    const content = document.getElementById('add-form-content');
    const icon = document.getElementById('icon-toggle-add');
    content.classList.toggle('show');
    icon.classList.toggle('active');
    
    if (content.classList.contains('show')) {
        setTimeout(() => { initMap(); map.invalidateSize(); }, 100);
    }
}

const addNameInput = document.getElementById('add_customer_name');
let usernameTimeout;
if (addNameInput) {
    addNameInput.addEventListener('input', function(e) {
        clearTimeout(usernameTimeout);
        const name = e.target.value;
        if (!name) {
            document.getElementById('pppoe_username_input').value = '';
            document.getElementById('username_ok').style.display = 'none';
            return;
        }
        
        usernameTimeout = setTimeout(() => {
            let username = name.toLowerCase().replace(/[^a-z0-9]/g, '');
            if (username) checkAndSetUsername(username, 0);
        }, 500); // 500ms debounce
    });
}

function checkAndSetUsername(baseUsername, counter) {
    const spinner = document.getElementById('username_spinner');
    const okIcon = document.getElementById('username_ok');
    const input = document.getElementById('pppoe_username_input');
    
    spinner.style.display = 'block';
    okIcon.style.display = 'none';
    
    const targetUsername = counter > 0 ? baseUsername + counter : baseUsername;

    fetch('../api/mikrotik.php?action=check_user&username=' + targetUsername)
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                checkAndSetUsername(baseUsername, counter + 1);
            } else {
                if (input) input.value = targetUsername;
                spinner.style.display = 'none';
                okIcon.style.display = 'block';
            }
        })
        .catch(() => {
            spinner.style.display = 'none';
        });
}

function startScanner(targetId) {
    document.getElementById('scannerModal').style.display = 'flex';
    html5QrCode = new Html5Qrcode("reader");
    const qrCodeSuccessCallback = (decodedText) => {
        document.getElementById(targetId).value = decodedText.trim().toUpperCase();
        stopScanner();
    };
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback);
}

function stopScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            document.getElementById('scannerModal').style.display = 'none';
            html5QrCode = null;
        }).catch(() => {
            document.getElementById('scannerModal').style.display = 'none';
        });
    } else {
        document.getElementById('scannerModal').style.display = 'none';
    }
}
</script>

<div id="scannerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center; flex-direction:column;">
    <div style="background:#111; padding:20px; border-radius:12px; width:90%; max-width:500px; text-align:center; border: 1px solid #00d2ff;">
        <h3 style="color:#00d2ff; margin-bottom:15px;"><i class="fas fa-barcode"></i> Scan Serial Number</h3>
        <div id="reader" style="width:100%; border-radius:8px; overflow:hidden; background:#000;"></div>
        <div style="margin-top:20px; display:flex; gap:10px;">
            <button type="button" class="btn btn-secondary" onclick="stopScanner()" style="flex:1;">Tutup</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
