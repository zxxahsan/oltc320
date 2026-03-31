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
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        $olt_id = (int)$_GET['olt_id'];
        try {
            $found = vsolFindUnauthOnu($olt_id);
            echo json_encode($found);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('customers.php');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $pppoePassword = isset($_POST['pppoe_password']) ? trim((string) $_POST['pppoe_password']) : '';
                $rawPortalPassword = generateRandomString(8);
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
                    'portal_password' => generateRandomString(4, 'numeric'),
                    'olt_id' => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn' => sanitize($_POST['onu_sn'] ?? ''),
                    'olt_pon_port' => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id' => (int)($_POST['onu_id'] ?? 0),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                if ($newCustomerId = insert('customers', $data)) {
                    if ($data['olt_id'] > 0 && !empty($data['onu_sn'])) {
                        try {
                            $onu_id = $data['onu_id'];
                            $pon = $data['olt_pon_port'];
                            $sn = $data['onu_sn'];
                            $regRes = ['success' => true];
                            if (($_POST['onu_status'] ?? '') !== 'registered') {
                                $regRes = vsolRegisterOnu($data['olt_id'], $pon, $sn, $onu_id, 'Jinom', $data['name']);
                            }
                            if ($regRes['success']) {
                                $services = $_POST['olt_services'] ?? [];
                                vsolProvisionUltimate($data['olt_id'], $pon, $onu_id, 100, $data['pppoe_username'], $pppoePassword,$services);
                            }
                        } catch (Exception $e) { logError("OLT Automation failed: " . $e->getMessage()); }
                    }
                    setFlash('success', 'Pelanggan berhasil ditambahkan');
                    logActivity('ADD_CUSTOMER', "Name: " . (string)$data['name']);
                    $customerData = fetchOne("SELECT * FROM customers WHERE id = ?", [$newCustomerId]);
                    if ($customerData) sendCustomerWelcomeWA($customerData, $rawPortalPassword);
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
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                if (update('customers', $data, 'id = ?', [$customerId])) {
                    setFlash('success', 'Berhasil memperbarui pelanggan');
                    logActivity('UPDATE_CUSTOMER', "ID: {$customerId}");
                } else { setFlash('error', 'Gagal memperbarui pelanggan'); }
                redirect('customers.php');
                break;
        }
    }
}

$olts = fetchAll("SELECT id, name FROM olt_configs ORDER BY name ASC");
$packages = fetchAll("SELECT * FROM packages ORDER BY price ASC");

// Pagination
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$totalCustomers = fetchOne("SELECT COUNT(*) as total FROM customers")['total'] ?? 0;
$customers = fetchAll("SELECT c.*, p.name as package_name FROM customers c LEFT JOIN packages p ON c.package_id = p.id ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset");

$mapCenter = ['lat' => -6.200000, 'lng' => 106.816666];

ob_start();
?>

<!-- Libraries (V7.8 INTERACTIVITY) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/html5-qrcode@latest/html5-qrcode.min.js"></script>

<div class="card" style="position: relative; z-index: 5;">
    <div class="card-header"><h3><i class="fas fa-user-plus"></i> Tambah Pelanggan</h3></div>
    <form method="POST">
        <input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            <div class="form-group"><label>Nama</label> <input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label>Nomor HP</label> <input type="text" name="phone" class="form-control" required></div>
            <div class="form-group" style="grid-column: 1 / -1;"><label>Username PPPoE</label><input type="text" name="pppoe_username" class="form-control" required></div>
            
            <!-- SMART OLT PROVISIONING (V7.8 INTERACTIVITY FIX) -->
            <div class="form-group" style="grid-column: 1 / -1; padding: 15px; background: rgba(0,210,255,0.05); border: 1px solid rgba(0,210,255,0.1); border-radius: 8px; position: relative; z-index: 10;">
                <label style="color: var(--neon-cyan); display: block; margin-bottom: 10px;"><i class="fas fa-microchip"></i> OLT Provisioning (V-SOL)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div><label>Pilih OLT</label>
                        <select name="olt_id" id="olt_selector" class="form-control" style="background:#2a2a2a; color:#fff; cursor: pointer;">
                            <option value="0">-- Lewati OLT --</option>
                            <?php foreach ($olts as $o): ?><option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Scan / SN ONT <span id="sn_status_badge" style="display:none; font-size:0.75em; padding:2px 8px; border-radius:4px; margin-left:10px; background:var(--neon-green); color:#000;">DITEMUKAN</span></label>
                        <div style="display: flex; gap: 5px;">
                            <input type="text" id="onu_sn_input" name="onu_sn" list="onu_sn_list" class="form-control" placeholder="Scan atau Paste SN" style="flex: 1; background:#2a2a2a; color:#fff;">
                            <datalist id="onu_sn_list"></datalist>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="startCameraScan()"><i class="fas fa-camera"></i></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="scanOltOnu()"><i id="scan-icon" class="fas fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="olt_pon_port" id="olt_pon_port_input"><input type="hidden" name="onu_id" id="onu_id_input"><input type="hidden" name="onu_status" id="onu_status_hidden" value="unconfigured">
                <div style="margin-top: 15px; display: flex; gap: 15px; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 5px;">
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="olt_services[]" value="acs" checked> <span>TR069</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="olt_services[]" value="pppoe" checked> <span>Internet</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="olt_services[]" value="hotspot" checked> <span>Hotspot</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 5px;"><input type="checkbox" name="olt_services[]" value="wifi" checked> <span>WiFi 2</span></label>
                </div>
            </div>

            <div class="form-group"><label>Paket</label>
                <select name="package_id" class="form-control" required style="background:#2a2a2a; color:#fff;">
                    <option value="">Pilih Paket</option>
                    <?php foreach ($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Jatuh Tempo (1-28)</label><input type="number" name="isolation_date" class="form-control" value="20" min="1" max="28" required></div>
        </div>
        <div class="form-group" style="margin-top:15px;"><label>Lokasi (Klik Peta)</label>
            <div style="display:flex; gap:10px; margin-bottom:10px;"><input type="text" name="lat" class="form-control" readonly><input type="text" name="lng" class="form-control" readonly></div>
            <div id="map-picker" style="height: 350px; border-radius:8px;"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:20px; width:100%; height:50px; font-weight: bold;">SIMPAN PELANGGAN</button>
    </form>
</div>

<div id="cameraScanModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; flex-direction:column; align-items:center; justify-content:center;"><div style="background:var(--bg-card); padding:20px; border-radius:10px; width:90%; max-width:500px; text-align:center;"><h3 style="color:var(--neon-cyan);">Scan ONT Barcode</h3><div id="reader" style="width:100%; height:300px; background:#000;"></div><button class="btn btn-secondary" onclick="stopCameraScan()" style="margin-top:15px;">Tutup</button></div></div>

<div id="editCustomerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:2000; align-items:center; justify-content:center;">
    <div class="card" style="width: 900px; max-width: 95%;">
        <div class="card-header" style="display:flex; justify-content:space-between;"><h3>Edit Pelanggan</h3> <button onclick="closeEditModal()" style="background:none; border:none; color:#fff; font-size:1.5em; cursor:pointer;">&times;</button></div>
        <form method="POST" style="padding:20px;"><input type="hidden" name="action" value="edit"><input type="hidden" name="customer_id" id="edit_id"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;"><div class="form-group"><label>Nama</label><input type="text" name="name" id="edit_name" class="form-control" required></div><div class="form-group"><label>HP</label><input type="text" name="phone" id="edit_phone" class="form-control" required></div></div>
            <button type="submit" class="btn btn-primary" style="margin-top:20px; width:100%;">SIMPAN</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top:20px; position: relative; z-index: 1;"><div class="card-header"><h3>Daftar Pelanggan</h3></div><table class="data-table"><thead><tr><th>Nama</th><th>Paket</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($customers as $c): ?><tr><td><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small><?php echo htmlspecialchars($c['phone']); ?></small></td><td><?php echo htmlspecialchars($c['package_name']); ?></td><td><span class="badge badge-<?php echo $c['status']==='active'?'success':'warning'; ?>"><?php echo $c['status']; ?></span></td><td><button class="btn btn-secondary btn-sm" onclick='editCustomer(<?php echo json_encode($c); ?>)'><i class="fas fa-edit"></i></button></td></tr><?php endforeach; ?></tbody></table></div>

<script>
window.map = null; window.marker = null; window.lastScanResults = []; window.html5QrcodeScanner = null;

console.log('Interactivity V7.8 Initializing...');

window.addEventListener('load', () => { setTimeout(() => { initMap(); console.log('UI READY.'); }, 500); });

function initMap() {
    if (window.map || typeof L === 'undefined') return;
    const div = document.getElementById('map-picker'); if (!div) return;
    window.map = L.map('map-picker').setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(window.map);
    window.map.on('click', (e) => {
        if (window.marker) window.map.removeLayer(window.marker);
        window.marker = L.marker(e.latlng).addTo(window.map);
        document.querySelector('input[name="lat"]').value = e.latlng.lat.toFixed(6);
        document.querySelector('input[name="lng"]').value = e.latlng.lng.toFixed(6);
    });
}

function checkOnuMatch() {
    const input = document.getElementById('onu_sn_input'); if (!input) return;
    const sn = input.value.trim().toLowerCase();
    const badge = document.getElementById('sn_status_badge'); if (!badge) return;
    if (!sn) { badge.style.display = 'none'; return; }
    
    const onu = (window.lastScanResults || []).find(o => (o.sn || '').toLowerCase() === sn);
    if (onu) {
        document.getElementById('olt_pon_port_input').value = onu.port;
        document.getElementById('onu_id_input').value = onu.id;
        document.getElementById('onu_status_hidden').value = onu.status;
        badge.style.display = 'inline-block'; badge.style.background = 'var(--neon-green)'; badge.style.color='#000'; badge.textContent = 'DITEMUKAN';
    } else {
        badge.style.display = 'inline-block'; badge.style.background = 'var(--danger)'; badge.style.color='#fff'; badge.textContent = (window.lastScanResults.length > 0) ? 'BELUM DISCAN' : 'TIDAK ADA';
    }
}

function scanOltOnu() {
    const oltId = document.getElementById('olt_selector').value;
    const icon = document.getElementById('scan-icon');
    const badge = document.getElementById('sn_status_badge');
    if (oltId === '0') return alert('Pilih OLT!');
    
    icon.className = 'fas fa-spinner fa-spin';
    if (badge) { badge.style.display='inline-block'; badge.style.background='var(--warning)'; badge.style.color='#000'; badge.textContent='SCANNING...'; }
    
    fetch('customers.php?ajax_action=scan_onu&olt_id=' + oltId)
        .then(r => { if(!r.ok) throw new Error('Network fail'); return r.json(); })
        .then(data => {
            const dl = document.getElementById('onu_sn_list'); dl.innerHTML = '';
            window.lastScanResults = Array.isArray(data) ? data : [];
            window.lastScanResults.forEach(o => { const opt = document.createElement('option'); opt.value = o.sn; dl.appendChild(opt); });
            checkOnuMatch();
        })
        .catch(err => { if(badge){ badge.style.background='var(--danger)'; badge.style.color='#fff'; badge.textContent='GAGAL'; } })
        .finally(() => { icon.className = 'fas fa-search'; });
}

// SAFE BINDING (V7.8)
document.addEventListener('DOMContentLoaded', () => {
    const snInput = document.getElementById('onu_sn_input');
    if (snInput) snInput.addEventListener('input', checkOnuMatch);
});

function startCameraScan() {
    document.getElementById('cameraScanModal').style.display = 'flex';
    window.html5QrcodeScanner = new Html5Qrcode("reader");
    window.html5QrcodeScanner.start({ facingMode: "environment" }, { fps: 15, qrbox: 250 }, (txt) => {
        document.getElementById('onu_sn_input').value = txt; checkOnuMatch(); stopCameraScan();
    }).catch(e => { stopCameraScan(); });
}
function stopCameraScan() { if (window.html5QrcodeScanner) window.html5QrcodeScanner.stop().finally(() => document.getElementById('cameraScanModal').style.display='none'); else document.getElementById('cameraScanModal').style.display='none'; }
function closeEditModal() { document.getElementById('editCustomerModal').style.display = 'none'; }
function editCustomer(c) {
    document.getElementById('edit_id').value = c.id; document.getElementById('edit_name').value = c.name; document.getElementById('edit_phone').value = c.phone;
    document.getElementById('editCustomerModal').style.display = 'flex';
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
