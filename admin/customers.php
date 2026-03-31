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

<!-- Libraries Consolidated at the top of content (V7.4 RESET) -->
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

<!-- Add Customer Form Card -->
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-user-plus"></i> Tambah Pelanggan</h3></div>
    <form method="POST">
        <input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; max-width: 100%;">
            <div class="form-group"><label class="form-label">Nama</label> <input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Nomor HP</label> <input type="text" name="phone" id="customer_phone_input" class="form-control" required></div>
            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">Username PPPoE</label><div style="display: flex; gap:10px;"><input type="text" name="pppoe_username" id="pppoe_username_input" class="form-control" required><button type="button" class="btn btn-secondary" onclick="openPppoeUserModal()">Pilih MikroTik</button></div></div>
            <div class="form-group" style="grid-column: 1 / -1;"><label class="form-label">OLT Provisioning</label><div style="display: flex; gap:10px; margin-bottom:10px;"><select name="olt_id" id="olt_selector" class="form-control"><?php foreach($olts as $o): ?><option value="<?php echo $o['id']; ?>"><?php echo $o['name']; ?></option><?php endforeach; ?></select><input type="text" name="onu_sn" id="onu_sn_input" list="onu_sn_list" class="form-control" placeholder="Scan/Type SN"><datalist id="onu_sn_list"></datalist><button type="button" class="btn btn-secondary" onclick="startCameraScan()"><i class="fas fa-camera"></i></button><button type="button" class="btn btn-secondary" onclick="scanOltOnu()"><i id="scan-icon" class="fas fa-search"></i></button></div></div>
            <input type="hidden" name="olt_pon_port" id="olt_pon_port_input"><input type="hidden" name="onu_id" id="onu_id_input"><input type="hidden" name="onu_status" id="onu_status_hidden" value="unconfigured">
            <div class="form-group"><label class="form-label">Paket</label><select name="package_id" class="form-control" required><?php foreach ($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo $pkg['name']; ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Jatuh Tempo (Tgl)</label><input type="number" name="isolation_date" class="form-control" value="20" min="1" max="28" required></div>
        </div>
        <div class="form-group" style="margin-top:15px;"><label class="form-label">Lokasi</label><div style="display:flex; gap:10px; margin-bottom:10px;"><input type="text" name="lat" class="form-control" readonly><input type="text" name="lng" class="form-control" readonly></div><div id="map-picker" style="height: 300px; border-radius:8px;"></div></div>
        <button type="submit" class="btn btn-primary" style="margin-top:20px;">Simpan Pelanggan</button>
    </form>
</div>

<!-- Modal Dialogs -->
<div id="cameraScanModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; flex-direction:column; align-items:center; justify-content:center;"><div style="background:var(--bg-card); padding:20px; border-radius:10px; width:90%; max-width:500px; text-align:center;"><h3 style="color:var(--neon-cyan);">Barcode Scanner</h3><div id="reader" style="width:100%; min-height:300px; border-radius:5px; overflow:hidden;"></div><button class="btn btn-secondary" onclick="stopCameraScan()" style="margin-top:15px;">Tutup</button></div></div>
<div id="pppoeUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:2000; align-items:center; justify-content:center;"><div class="card" style="width: 360px;"><div class="card-header"><h3>MikroTik Users</h3><button onclick="closePppoeUserModal()">&times;</button></div><div style="padding:15px;"><input type="text" id="pppoeUserSearch" class="form-control" placeholder="Cari..."><div id="pppoeUserList" style="max-height:400px; overflow-y:auto; margin-top:10px;"></div></div></div></div>

<!-- Customer Table -->
<div class="card" style="margin-top:30px;"><div class="card-header"><h3>Daftar Pelanggan</h3></div><table class="data-table"><thead><tr><th>Nama</th><th>Paket</th><th>PPPoE</th><th>Aksi</th></tr></thead><tbody><?php foreach($customers as $c): ?><tr><td><strong><?php echo $c['name']; ?></strong></td><td><?php echo $c['package_name']; ?></td><td><code><?php echo $c['pppoe_username']; ?></code></td><td><button class="btn btn-secondary btn-sm" onclick='editCustomer(<?php echo json_encode($c); ?>)'><i class="fas fa-edit"></i></button></td></tr><?php endforeach; ?></tbody></table></div>

<!-- UNIFIED SCRIPT (V7.4 TOTAL RESET) -->
<script>
window.map = null;
window.marker = null;
window.pppoeUsers = [];
window.html5QrcodeScanner = null;

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

function startCameraScan() {
    if (typeof Html5Qrcode === 'undefined') return alert('Pustaka Scan Gagal Dimuat!');
    document.getElementById('cameraScanModal').style.display = 'flex';
    window.html5QrcodeScanner = new Html5Qrcode("reader");
    window.html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, (txt) => {
        document.getElementById('onu_sn_input').value = txt;
        document.getElementById('onu_sn_input').dispatchEvent(new Event('input'));
        stopCameraScan();
    }).catch(e => { alert('Kamera Error: ' + e); stopCameraScan(); });
}

function stopCameraScan() {
    if (window.html5QrcodeScanner) window.html5QrcodeScanner.stop().finally(() => document.getElementById('cameraScanModal').style.display = 'none');
    else document.getElementById('cameraScanModal').style.display = 'none';
}

function scanOltOnu() {
    const oltId = document.getElementById('olt_selector').value;
    const icon = document.getElementById('scan-icon');
    icon.className = 'fas fa-spinner fa-spin';
    fetch('customers.php?ajax_action=scan_onu&olt_id=' + oltId).then(r => r.json()).then(data => {
        const dl = document.getElementById('onu_sn_list'); dl.innerHTML = '';
        data.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.sn; opt.textContent = 'PON: ' + o.port;
            dl.appendChild(opt);
        });
    }).finally(() => icon.className = 'fas fa-search');
}

function openPppoeUserModal() {
    document.getElementById('pppoeUserModal').style.display = 'flex';
    fetch('../api/mikrotik.php?action=users').then(r => r.json()).then(d => {
        const list = document.getElementById('pppoeUserList'); list.innerHTML = '';
        d.data.users.forEach(u => {
            const b = document.createElement('button'); b.className = 'btn btn-secondary'; b.style.width = '100%'; b.style.marginBottom = '5px';
            b.textContent = u.name; b.onclick = () => { document.getElementById('pppoe_username_input').value = u.name; closePppoeUserModal(); };
            list.appendChild(b);
        });
    });
}

function closePppoeUserModal() { document.getElementById('pppoeUserModal').style.display = 'none'; }
function editCustomer(c) { alert('Edit Feature Under Maintenance (Patch 7.4 Reset)'); }

window.addEventListener('load', () => { setTimeout(initMap, 500); });
console.log("Patch v7.4 (Total Reset) Fully Operational.");
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
