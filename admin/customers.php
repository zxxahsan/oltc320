<?php
/**
 * Customers Management
 */

require_once '../includes/auth.php';
requireAdminLogin();

require_once '../includes/olt_api.php';

$pageTitle = 'Pelanggan';

// AJAX Handler for OLT Scanning
if (isset($_GET['ajax_action'])) {
    if ($_GET['ajax_action'] === 'scan_onu') {
        header('Content-Type: application/json');
        $olt_id = (int)$_GET['olt_id'];
        $found = vsolFindUnauthOnu($olt_id);
        echo json_encode($found);
        exit;
    }
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
            case 'delete_all':
                if (isset($_POST['confirm_text']) && $_POST['confirm_text'] === 'HAPUS SEMUA') {
                    if (query("DELETE FROM customers")) {
                        try { query("ALTER TABLE customers AUTO_INCREMENT = 1"); } catch (Exception $e) {}
                        logActivity('DELETE_ALL_CUSTOMERS', "Berhasil menghapus seluruh data pelanggan di sistem");
                        setFlash('success', 'Seluruh data pelanggan berhasil dihapus secara permanen!');
                    } else {
                        setFlash('error', 'Gagal menghapus seluruh pelanggan');
                    }
                } else {
                    setFlash('error', 'Kata kunci konfirmasi salah. Penghapusan dibatalkan.');
                }
                redirect('customers.php');
                break;

            case 'add':
                $pppoePassword = isset($_POST['pppoe_password']) ? trim((string) $_POST['pppoe_password']) : '';
                $rawPortalPassword = generateRandomString(8); // Generate random password for portal
                $data = [
                    'name' => sanitize($_POST['name']),
                    'phone' => sanitize($_POST['phone']),
                    'pppoe_username' => sanitize($_POST['pppoe_username']),
                    'package_id' => (int)$_POST['package_id'],
                    'router_id' => (int)($_POST['router_id'] ?? 0),
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address' => sanitize($_POST['address']),
                    'lat' => (!isset($_POST['lat']) || trim($_POST['lat']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lat'])),
                    'lng' => (!isset($_POST['lng']) || trim($_POST['lng']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lng'])),
                    'installed_by' => !empty($_POST['installed_by']) ? (int)$_POST['installed_by'] : null,
                    'portal_password' => generateRandomString(4, 'numeric'),
                    'olt_id' => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn' => sanitize($_POST['onu_sn'] ?? ''),
                    'olt_pon_port' => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id' => (int)($_POST['onu_id'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                if ($newCustomerId = insert('customers', $data)) {
                    // OLT Automation if OLT is selected
                    if ($data['olt_id'] > 0 && !empty($data['onu_sn'])) {
                        try {
                            $onu_id = $data['onu_id'];
                            $pon = $data['olt_pon_port'];
                            $sn = $data['onu_sn'];
                            $vlan = 100; // Standard Internet VLAN based on logs
                            
                            // 1. Register ONU if NOT already registered (auto-learn)
                            $regRes = ['success' => true];
                            if (($_POST['onu_status'] ?? '') !== 'registered') {
                                $regRes = vsolRegisterOnu($data['olt_id'], $pon, $sn, $onu_id, 'Jinom', $data['name']);
                            }
                            
                            // 2. Provision WAN (Check selected services)
                            if ($regRes['success']) {
                                $services = $_POST['olt_services'] ?? [];
                                $vsolVlan = 100;
                                
                                // Enhanced Provisioning Logic
                                vsolProvisionUltimate(
                                    $data['olt_id'], 
                                    $pon, 
                                    $onu_id, 
                                    $vsolVlan, 
                                    $data['pppoe_username'], 
                                    $pppoePassword,
                                    $services
                                );
                            }
                        } catch (Exception $e) {
                            logError("OLT Automation (add customer) failed: " . $e->getMessage());
                        }
                    }
                    // Auto-sync Map coordinates using the phone identifier
                    try {
                        $phoneObj = trim((string)$data['phone']);
                        if (!empty($phoneObj)) {
                            $exists = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$phoneObj]);
                            $payload = [
                                'name' => $data['name'],
                                'lat' => $data['lat'],
                                'lng' => $data['lng'],
                                'updated_at' => date('Y-m-d H:i:s')
                            ];
                            
                            // If user explicitly chose save_onu, we also attempt to save odp_id
                            if (isset($_POST['save_onu']) && $_POST['save_onu'] == '1') {
                                $payload['odp_id'] = isset($_POST['odp_id']) && $_POST['odp_id'] !== '' ? (int) $_POST['odp_id'] : null;
                            }
                            
                            if ($exists) {
                                update('onu_locations', $payload, 'serial_number = ?', [$phoneObj]);
                            } elseif (!empty($data['lat']) && !empty($data['lng'])) {
                                $payload['serial_number'] = $phoneObj;
                                $payload['created_at'] = date('Y-m-d H:i:s');
                                insert('onu_locations', $payload);
                            }
                        }

                        // Legacy ACS PPPoE Username injection
                        if (isset($_POST['save_onu']) && $_POST['save_onu'] == '1') {
                            $serial = (string)$data['pppoe_username'];
                            if (!empty($serial)) {
                                genieacsSetParameter($serial, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username', $serial);
                                if ($pppoePassword !== '') {
                                    genieacsSetParameter($serial, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password', $pppoePassword);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Do not block customer creation if ONU sync fails
                        logError('ONU sync (add customer) failed: ' . $e->getMessage());
                    }
                    
                    setFlash('success', 'Pelanggan berhasil ditambahkan');
                    logActivity('ADD_CUSTOMER', "Name: " . (string)$data['name']);

                    // 1. Send Welcome Message to Customer (with their new password)
                    $customerData = fetchOne("SELECT * FROM customers WHERE id = ?", [$newCustomerId]);
                    if ($customerData) {
                        sendCustomerWelcomeWA($customerData, $rawPortalPassword);
                    }
                    
                    // 2. Notify Technician if assigned
                    if (!empty($data['installed_by'])) {
                        $tech = fetchOne("SELECT phone, name FROM technician_users WHERE id = ?", [$data['installed_by']]);
                        if ($tech && !empty($tech['phone'])) {
                            require_once '../includes/whatsapp.php';
                            $msg = "🔔 *TUGAS INSTALASI BARU*\n\n";
                            $msg .= "Pelanggan: " . (string)$data['name'] . "\n";
                            $msg .= "Kontak (WA): " . (string)$data['phone'] . "\n";
                            $msg .= "Alamat: " . ($data['address'] ?: '-') . "\n";
                            $msg .= "Paket: " . fetchOne("SELECT name FROM packages WHERE id = ?", [$data['package_id']])['name'] . "\n";
                            $msg .= "Maps: https://www.google.com/maps?q=" . (string)$data['lat'] . "," . (string)$data['lng'] . "\n\n";
                            $msg .= "Mohon segera diproses. Terima kasih.";
                            
                            sendWhatsAppMessage($tech['phone'], $msg);
                        }
                    }
                } else {
                    setFlash('error', 'Gagal menambahkan pelanggan');
                }
                redirect('customers.php');
                break;
                
            case 'edit':
                $customerId = (int)$_POST['customer_id'];
                $data = [
                    'name' => sanitize($_POST['name']),
                    'phone' => sanitize($_POST['phone']),
                    'package_id' => (int)$_POST['package_id'],
                    'router_id' => (int)($_POST['router_id'] ?? 0),
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address' => sanitize($_POST['address']),
                    'lat' => (!isset($_POST['lat']) || trim($_POST['lat']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lat'])),
                    'lng' => (!isset($_POST['lng']) || trim($_POST['lng']) === '') ? null : (string) str_replace(',', '.', trim($_POST['lng'])),
                    'installed_by' => !empty($_POST['installed_by']) ? (int)$_POST['installed_by'] : null,
                    'portal_password' => !empty($_POST['portal_password']) ? sanitize($_POST['portal_password']) : '1234',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                if (update('customers', $data, 'id = ?', [$customerId])) {
                    // Auto-sync Map coordinates using the phone identifier
                    try {
                        $phoneObj = trim((string)$data['phone']);
                        if (!empty($phoneObj)) {
                            $exists = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$phoneObj]);
                            $payload = [
                                'name' => $data['name'],
                                'lat' => $data['lat'],
                                'lng' => $data['lng'],
                                'updated_at' => date('Y-m-d H:i:s')
                            ];
                            
                            // If user explicitly chose save_onu, we also attempt to save odp_id
                            if (isset($_POST['save_onu']) && $_POST['save_onu'] == '1') {
                                $payload['odp_id'] = isset($_POST['odp_id']) && $_POST['odp_id'] !== '' ? (int) $_POST['odp_id'] : null;
                            }
                            
                            if ($exists) {
                                update('onu_locations', $payload, 'serial_number = ?', [$phoneObj]);
                            } elseif (!empty($data['lat']) && !empty($data['lng'])) {
                                $payload['serial_number'] = $phoneObj;
                                $payload['created_at'] = date('Y-m-d H:i:s');
                                insert('onu_locations', $payload);
                            }
                        }

                        // Legacy ACS PPPoE Username injection
                        if (isset($_POST['save_onu']) && $_POST['save_onu'] == '1') {
                            $customer = fetchOne("SELECT pppoe_username FROM customers WHERE id = ?", [$customerId]);
                            if ($customer && !empty($customer['pppoe_username'])) {
                                $serial = $customer['pppoe_username'];
                                genieacsSetParameter($serial, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username', $serial);
                            }
                        }
                    } catch (Exception $e) {
                        logError('ONU auto-sync (edit customer) failed: ' . $e->getMessage());
                    }
                    setFlash('success', 'Pelanggan berhasil diperbarui');
                    logActivity('UPDATE_CUSTOMER', "ID: {$customerId}");
                } else {
                    setFlash('error', 'Gagal memperbarui pelanggan');
                }
                redirect('customers.php');
                break;
                
            case 'delete':
                $customerId = (int)$_POST['customer_id'];
                if (delete('customers', 'id = ?', [$customerId])) {
                    setFlash('success', 'Pelanggan berhasil dihapus');
                    logActivity('DELETE_CUSTOMER', "ID: {$customerId}");
                } else {
                    setFlash('error', 'Gagal menghapus pelanggan');
                }
                redirect('customers.php');
                break;
                
            case 'unisolate':
                $customerId = (int)$_POST['customer_id'];
                if (unisolateCustomer($customerId)) {
                    setFlash('success', 'Pelanggan berhasil di-unisolate');
                } else {
                    setFlash('error', 'Gagal meng-unisolate pelanggan');
                }
                redirect('customers.php');
                break;
        }
    }
}

// Get data with pagination
$page = (int)($_GET['page'] ?? 1);
$perPageLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : (defined('ITEMS_PER_PAGE') ? ITEMS_PER_PAGE : 20);
$allowedLimits = [10, 25, 50, 100, 500, 1000];
if (!in_array($perPageLimit, $allowedLimits)) $perPageLimit = 20;
$perPage = $perPageLimit;
$offset = ($page - 1) * $perPage;

$customersTableExists = tableExists('customers');
$packagesTableExists = tableExists('packages');
$routersTableExists = tableExists('routers');

// Calculate map center from onu_locations or fallback
$mapCenter = ['lat' => -6.200000, 'lng' => 106.816666];
if (tableExists('onu_locations')) {
    $centerQuery = fetchOne("SELECT AVG(lat) as avg_lat, AVG(lng) as avg_lng FROM onu_locations WHERE lat IS NOT NULL AND lng IS NOT NULL");
    if ($centerQuery && $centerQuery['avg_lat']) {
        $mapCenter['lat'] = $centerQuery['avg_lat'];
        $mapCenter['lng'] = $centerQuery['avg_lng'];
    }
}

// Get billing lead time
$leadDays = (int)getSetting('invoice_generate_days', 7);
if ($leadDays < 1) $leadDays = 1;

// Get OLTs and Packages for the form
$olts = fetchAll("SELECT id, name FROM olt_configs ORDER BY name ASC");
$packages = fetchAll("SELECT * FROM packages ORDER BY price ASC");


if ($customersTableExists) {
    $totalCustomers = fetchOne("SELECT COUNT(*) as total FROM customers")['total'] ?? 0;
    $totalPages = ceil($totalCustomers / $perPage);

    $selectParts = [
        'c.*',
        $packagesTableExists ? 'p.name as package_name' : "'Tanpa Paket' as package_name",
        $packagesTableExists ? 'p.price as package_price' : '0 as package_price',
        $routersTableExists ? 'r.name as router_name' : "'' as router_name",
        "(SELECT odp_id FROM onu_locations WHERE serial_number = c.pppoe_username LIMIT 1) as onu_odp_id"
    ];

    $joinParts = [];
    if ($packagesTableExists) {
        $joinParts[] = 'LEFT JOIN packages p ON c.package_id = p.id';
    }
    if ($routersTableExists) {
        $joinParts[] = 'LEFT JOIN routers r ON c.router_id = r.id';
    }

    $customers = fetchAll("
        SELECT " . implode(', ', $selectParts) . "
        FROM customers c 
        " . implode("\n        ", $joinParts) . "
        ORDER BY c.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
} else {
    $totalCustomers = 0;
    $totalPages = 0;
    $customers = [];
}

$packages = $packagesTableExists ? fetchAll("SELECT * FROM packages ORDER BY name") : [];
$routers = $routersTableExists ? getAllRouters() : [];

ob_start();
?>

<!-- Libraries Consolidated at the top of content (V7.6 SMART FEEDBACK) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/html5-qrcode@latest/html5-qrcode.min.js"></script>

<!-- Stats Grid -->
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-icon cyan"><i class="fas fa-users"></i></div>
        <div class="stat-info"><h3><?php echo (int) $totalCustomers; ?></h3><p>Total Pelanggan</p></div>
    </div>
    <?php
    $activeCount = fetchOne("SELECT COUNT(*) as total FROM customers WHERE status = 'active'")['total'] ?? 0;
    $isolatedCount = fetchOne("SELECT COUNT(*) as total FROM customers WHERE status = 'isolated'")['total'] ?? 0;
    $unpaidCount = fetchOne("SELECT COUNT(*) as total FROM customers c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.customer_id = c.id AND MONTH(i.due_date) = MONTH(CURRENT_DATE) AND YEAR(i.due_date) = YEAR(CURRENT_DATE) AND i.status = 'paid')")['total'] ?? 0;
    ?>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info"><h3><?php echo $activeCount; ?></h3><p>Aktif</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-ban"></i></div>
        <div class="stat-info"><h3><?php echo $isolatedCount; ?></h3><p>Terisolir</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
        <div class="stat-info"><h3><?php echo $unpaidCount; ?></h3><p>Belum Lunas</p></div>
    </div>
</div>

<style>
    .stats-grid { grid-template-columns: repeat(4, 1fr) !important; gap: 15px; }
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr) !important; }
    }
</style>

<!-- Add Customer Form Card -->
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus"></i> Tambah Pelanggan</h3></div>
    <form method="POST">
        <input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; max-width: 100%;">
            <div class="form-group"><label class="form-label">Nama</label> <input type="text" name="name" class="form-control" required placeholder="Nama Lengkap"></div>
            <div class="form-group"><label class="form-label">Nomor HP</label> <input type="text" name="phone" class="form-control" required placeholder="WhatsApp"></div>
            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">Username PPPoE</label><div style="display: flex; gap:10px;"><input type="text" name="pppoe_username" id="pppoe_username_input" class="form-control" required><button type="button" class="btn btn-secondary" onclick="openPppoeUserModal()">Pilih MikroTik</button></div></div>
            
            <!-- SMART OLT PROVISIONING UI (V7.6 SMART FEEDBACK) -->
            <div class="form-group" style="grid-column: 1 / -1; margin-top: 10px; padding: 15px; background: rgba(0,210,255,0.05); border: 1px solid rgba(0,210,255,0.1); border-radius: 8px;">
                <label class="form-label" style="color: var(--neon-cyan);"><i class="fas fa-microchip"></i> OLT Provisioning (V-SOL)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px;">
                    <div>
                        <label class="form-label">Pilih OLT</label>
                        <select name="olt_id" id="olt_selector" class="form-control" style="background: var(--bg-card); color: var(--text-primary);">
                            <option value="0">-- Lewati OLT --</option>
                            <?php foreach ($olts as $idx => $o): ?>
                                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Scan Barcode / SN ONT <span id="sn_status_badge" style="display:none; font-size:0.7em; padding:2px 6px; border-radius:4px; margin-left:10px; background:var(--neon-green); color:#000; font-weight:bold;">TUNGGU...</span></label>
                        <div style="display: flex; gap: 5px;">
                            <input type="text" id="onu_sn_input" name="onu_sn" list="onu_sn_list" autocomplete="off" class="form-control" placeholder="Scan SN Tag atau Box" style="flex: 1; background: var(--bg-card); color: var(--text-primary);">
                            <datalist id="onu_sn_list"></datalist>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="startCameraScan()" title="Scan Kamera"><i class="fas fa-camera"></i></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="scanOltOnu()" title="Scan OLT"><i id="scan-icon" class="fas fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="olt_pon_port" id="olt_pon_port_input">
                <input type="hidden" name="onu_id" id="onu_id_input">
                <input type="hidden" name="onu_status" id="onu_status_hidden" value="unconfigured">

                <!-- Service Multi-Selection -->
                <div style="margin-top: 15px; display: flex; flex-wrap: wrap; gap: 15px; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 5px;">
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;"><input type="checkbox" name="olt_services[]" value="acs" checked> <span>TR069 (ACS)</span></label>
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;"><input type="checkbox" name="olt_services[]" value="pppoe" checked> <span>Internet (PPPoE)</span></label>
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;"><input type="checkbox" name="olt_services[]" value="hotspot" checked> <span>Hotspot (200)</span></label>
                    <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;"><input type="checkbox" name="olt_services[]" value="wifi" checked> <span>WiFi SSID 2</span></label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Paket Langganan</label>
                <select name="package_id" class="form-control" required style="background: var(--bg-card); color: var(--text-primary);">
                    <option value="">Pilih Paket</option>
                    <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?> (Rp <?php echo number_format($pkg['price'], 0, ',', '.'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group"><label class="form-label">Jatuh Tempo (Tgl 1-28)</label><input type="number" name="isolation_date" class="form-control" value="20" min="1" max="28" required></div>
        </div>

        <div class="form-group" style="margin-top:15px;">
            <label class="form-label">Lokasi Geografis (Klik Peta)</label>
            <div style="display:flex; gap:10px; margin-bottom:10px;">
                <input type="text" name="lat" class="form-control" placeholder="Lat" readonly>
                <input type="text" name="lng" class="form-control" placeholder="Lng" readonly>
            </div>
            <div id="map-picker" style="height: 350px; border-radius:8px; border:1px solid rgba(255,255,255,0.1);"></div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:20px; width:100%; height:50px; font-weight:bold;"><i class="fas fa-save"></i> SIMPAN PELANGGAN BARU</button>
    </form>
</div>

<!-- Modal Dialogs -->
<div id="cameraScanModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; flex-direction:column; align-items:center; justify-content:center;"><div style="background:var(--bg-card); padding:20px; border-radius:10px; width:90%; max-width:500px; text-align:center;"><h3 style="color:var(--neon-cyan);">Scan Barcode ONT</h3><div id="reader" style="width:100%; min-height:300px; border-radius:5px; overflow:hidden; background:#000;"></div><button class="btn btn-secondary" onclick="stopCameraScan()" style="margin-top:15px;">Tutup Kamera</button></div></div>
<div id="pppoeUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:2000; align-items:center; justify-content:center;"><div class="card" style="width: 360px;"><div class="card-header"><h3>MikroTik PPPoE</h3><button onclick="closePppoeUserModal()" style="background:none; border:none; color:#fff; font-size:1.5em; cursor:pointer;">&times;</button></div><div style="padding:15px;"><input type="text" id="pppoeUserSearch" class="form-control" placeholder="Cari username..."><div id="pppoeUserList" style="max-height: 400px; overflow-y: auto; margin-top:10px;"></div></div></div></div>

<!-- Edit Customer Modal -->
<div id="editCustomerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 900px; max-width: 95%; max-height: 95vh; overflow-y: auto;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;"><h3>Edit Pelanggan</h3> <button onclick="closeEditModal()" style="background:none; border:none; color:#fff; font-size:1.5em; cursor:pointer;">&times;</button></div>
        <form method="POST" id="editCustomerForm" style="padding:20px;">
            <input type="hidden" name="action" value="edit"><input type="hidden" name="customer_id" id="edit_customer_id"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group"><label>Nama</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="form-control" required></div>
                <div class="form-group"><label>Paket</label><select name="package_id" id="edit_package_id" class="form-control"><?php foreach($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo $pkg['name']; ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Jatuh Tempo</label><input type="number" name="isolation_date" id="edit_isolation_date" class="form-control"></div>
            </div>
            <div class="form-group" style="margin-top:15px;"><label>Lokasi</label><div style="display:flex; gap:10px; margin-bottom:10px;"><input type="text" name="lat" id="edit_lat" class="form-control" readonly><input type="text" name="lng" id="edit_lng" class="form-control" readonly></div><div id="edit-map-picker" style="height: 300px; border-radius:8px;"></div></div>
            <button type="submit" class="btn btn-primary" style="margin-top:20px; width:100%;">SIMPAN PERUBAHAN</button>
        </form>
    </div>
</div>

<!-- Customer Table -->
<div class="card" style="margin-top:30px;"><div class="card-header"><h3>Daftar Pelanggan</h3></div><table class="data-table" id="customerTable"><thead><tr><th>Nama & Kontak</th><th>Paket & Router</th><th>Status</th><th>Tagihan</th><th>Aksi</th></tr></thead><tbody><?php foreach($customers as $c): ?><tr><td><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small><?php echo htmlspecialchars($c['phone']); ?></small></td><td><?php echo htmlspecialchars($c['package_name']); ?><br><small><?php echo htmlspecialchars($c['router_name']); ?></small></td><td><span class="badge badge-<?php echo $c['status']==='active'?'success':'warning'; ?>"><?php echo $c['status']==='active'?'Aktif':'Isolir'; ?></span></td><td>Tgl <?php echo (int)$c['isolation_date']; ?></td><td><button class="btn btn-secondary btn-sm" onclick='editCustomer(<?php echo json_encode($c); ?>)'><i class="fas fa-edit"></i></button></td></tr><?php endforeach; ?></tbody></table></div>

<!-- UNIFIED SCRIPT (V7.6 SMART FEEDBACK) -->
<script>
window.map = null;
window.marker = null;
window.editMap = null;
window.editMarker = null;
window.pppoeUsers = [];
window.html5QrcodeScanner = null;
window.lastScanResults = [];

console.log('Smart Feedback v7.6 Initializing...');

window.addEventListener('load', () => { 
    setTimeout(() => {
        initMap(); 
        loadOdpOptions();
        console.log('Patch v7.6 Components Ready.');
    }, 500);
});

function initMap() {
    if (window.map || typeof L === 'undefined') return;
    const div = document.getElementById('map-picker');
    if (!div) return;
    window.map = L.map('map-picker').setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(window.map);
    window.map.on('click', (e) => {
        if (window.marker) window.map.removeLayer(window.marker);
        window.marker = L.marker(e.latlng).addTo(window.map);
        document.querySelector('input[name="lat"]').value = e.latlng.lat.toFixed(6);
        document.querySelector('input[name="lng"]').value = e.latlng.lng.toFixed(6);
    });
}

function initEditMap() {
    if (window.editMap || typeof L === 'undefined') return;
    const div = document.getElementById('edit-map-picker');
    if (!div) return;
    window.editMap = L.map('edit-map-picker').setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(window.editMap);
    window.editMap.on('click', (e) => {
        if (window.editMarker) window.editMap.removeLayer(window.editMarker);
        window.editMarker = L.marker(e.latlng).addTo(window.editMap);
        document.getElementById('edit_lat').value = e.latlng.lat.toFixed(6);
        document.getElementById('edit_lng').value = e.latlng.lng.toFixed(6);
    });
}

// UNIFIED SMART SN CHECKER (V7.6)
function checkOnuMatch() {
    const input = document.getElementById('onu_sn_input');
    if (!input) return;
    
    const sn = input.value.trim().toLowerCase();
    const badge = document.getElementById('sn_status_badge');
    if (!badge) return;

    if (!sn) {
        badge.style.display = 'none';
        return;
    }

    // Attempt to match in scan results (case-insensitive)
    const onu = (window.lastScanResults || []).find(o => (o.sn || '').toLowerCase() === sn);
    
    if (onu) {
        // Auto-fill technical data
        document.getElementById('olt_pon_port_input').value = onu.port;
        document.getElementById('onu_id_input').value = onu.id;
        document.getElementById('onu_status_hidden').value = onu.status;
        
        // Show success badge
        badge.style.display = 'inline-block';
        badge.style.background = 'var(--neon-green)';
        badge.style.color = '#000';
        badge.textContent = 'ONU DITEMUKAN';
        console.log('Smart Match Success: PON ' + onu.port);
    } else {
        // Show "not found" state if something is typed but no match
        badge.style.display = 'inline-block';
        badge.style.background = 'var(--danger)';
        badge.style.color = '#fff';
        badge.textContent = 'BELUM DISCAN';
        document.getElementById('onu_status_hidden').value = 'unconfigured';
    }
}

function startCameraScan() {
    if (typeof Html5Qrcode === 'undefined') return alert('Pustaka Scan Gagal!');
    document.getElementById('cameraScanModal').style.display = 'flex';
    window.html5QrcodeScanner = new Html5Qrcode("reader");
    window.html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 15, qrbox: 250 }, (txt) => {
        const input = document.getElementById('onu_sn_input');
        input.value = txt;
        checkOnuMatch();
        stopCameraScan();
    }).catch(e => { alert('Kamera Gagal: ' + e); stopCameraScan(); });
}

function stopCameraScan() {
    if (window.html5QrcodeScanner) window.html5QrcodeScanner.stop().finally(() => document.getElementById('cameraScanModal').style.display = 'none');
    else document.getElementById('cameraScanModal').style.display = 'none';
}

function scanOltOnu() {
    const oltId = document.getElementById('olt_selector').value;
    const icon = document.getElementById('scan-icon');
    const badge = document.getElementById('sn_status_badge');
    
    if (oltId === '0') return alert('Pilih OLT terlebih dahulu!');
    
    // Set visual scanning state
    icon.className = 'fas fa-spinner fa-spin';
    if (badge) {
        badge.style.display = 'inline-block';
        badge.style.background = 'var(--warning)';
        badge.style.color = '#000';
        badge.textContent = 'SCANNING...';
    }
    
    fetch('customers.php?ajax_action=scan_onu&olt_id=' + oltId).then(r => r.json()).then(data => {
        const dl = document.getElementById('onu_sn_list'); 
        dl.innerHTML = '';
        window.lastScanResults = data;
        
        data.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.sn; 
            opt.textContent = `PON: ${o.port} [${o.status}]`;
            dl.appendChild(opt);
        });

        // AUTO-TRIGGER MATCH CHECK AFTER SCAN
        checkOnuMatch();
        
        if(data.length > 0) console.log(data.length + ' ONU detected from OLT.');
    }).finally(() => {
        icon.className = 'fas fa-search';
    });
}

// Bind the checker to the input event
document.getElementById('onu_sn_input').addEventListener('input', checkOnuMatch);

function openPppoeUserModal() {
    document.getElementById('pppoeUserModal').style.display = 'flex';
    const list = document.getElementById('pppoeUserList');
    list.innerHTML = 'Memuat User MikroTik...';
    fetch('../api/mikrotik.php?action=users').then(r => r.json()).then(d => {
        if (!d.success) return list.innerHTML = 'Gagal!';
        window.pppoeUsers = d.data.users;
        renderPppoeUserList(window.pppoeUsers);
    });
}

function renderPppoeUserList(users) {
    const list = document.getElementById('pppoeUserList');
    list.innerHTML = '';
    users.forEach(u => {
        const b = document.createElement('button');
        b.className = 'btn btn-secondary'; b.style.width = '100%'; b.style.marginBottom = '5px'; b.style.textAlign = 'left';
        b.textContent = u.name;
        b.onclick = () => { document.getElementById('pppoe_username_input').value = u.name; closePppoeUserModal(); };
        list.appendChild(b);
    });
}
document.getElementById('pppoeUserSearch').addEventListener('input', (e) => {
    const txt = e.target.value.toLowerCase();
    renderPppoeUserList(window.pppoeUsers.filter(u => (u.name || '').toLowerCase().includes(txt)));
});

function closePppoeUserModal() { document.getElementById('pppoeUserModal').style.display = 'none'; }
function closeEditModal() { document.getElementById('editCustomerModal').style.display = 'none'; }

function editCustomer(c) {
    document.getElementById('edit_customer_id').value = c.id;
    document.getElementById('edit_name').value = c.name;
    document.getElementById('edit_phone').value = c.phone;
    document.getElementById('edit_package_id').value = c.package_id;
    document.getElementById('edit_isolation_date').value = c.isolation_date;
    document.getElementById('edit_lat').value = c.lat || '';
    document.getElementById('edit_lng').value = c.lng || '';
    document.getElementById('editCustomerModal').style.display = 'flex';
    setTimeout(() => {
        initEditMap();
        window.editMap.invalidateSize();
        if (c.lat && c.lng) {
            const ll = [c.lat, c.lng];
            window.editMap.setView(ll, 15);
            if (window.editMarker) window.editMap.removeLayer(window.editMarker);
            window.editMarker = L.marker(ll).addTo(window.editMap);
        }
    }, 250);
}

function loadOdpOptions() {
    fetch('../api/onu_locations.php').then(r => r.json()).then(j => {
        if (!j.success) return;
        const odps = j.odps || [];
        const addSel = document.getElementById('add_odp_select');
        odps.forEach(o => {
            if(addSel) {
                const opt = document.createElement('option');
                opt.value = o.id; opt.textContent = o.name;
                addSel.appendChild(opt);
            }
        });
    });
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
