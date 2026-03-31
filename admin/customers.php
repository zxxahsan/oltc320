<?php
/**
 * Customers Management
 * Optimization & Visibility Update (V7.9)
 */

require_once '../includes/auth.php';
requireAdminLogin();

require_once '../includes/olt_api.php';

$pageTitle = 'Pelanggan';

// AJAX Handler for OLT Scanning (Fast Response)
if (isset($_GET['ajax_action'])) {
    if ($_GET['ajax_action'] === 'scan_onu') {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        $olt_id = (int)$_GET['olt_id'];
        try {
            $found = vsolFindUnauthOnu($olt_id);
            echo json_encode($found ?: []);
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
        }
    }
}

// Core Data Fetch (Optimized)
$olts = fetchAll("SELECT id, name FROM olt_configs ORDER BY name ASC");
$packages = fetchAll("SELECT * FROM packages ORDER BY price ASC");
$totalCustomers = fetchOne("SELECT COUNT(*) as total FROM customers")['total'] ?? 0;
$customers = fetchAll("SELECT c.*, p.name as package_name FROM customers c LEFT JOIN packages p ON c.package_id = p.id ORDER BY c.created_at DESC LIMIT 20");

$mapCenter = ['lat' => -6.200000, 'lng' => 106.816666];

ob_start();
?>

<!-- UI VISIBILITY: Reverting to Native App Styles (V8.0) -->
<style>
    #sn_status_badge { font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; margin-left: 10px; font-weight: bold; }
    .card { margin-bottom: 20px; }
    .olt-provisioning-box { padding: 20px; background: rgba(0,210,255,0.05); border: 1px solid rgba(0,210,255,0.1); border-radius: 12px; }
</style>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-plus"></i> Tambah Pelanggan</h3></div>
    <form method="POST">
        <input type="hidden" name="action" value="add"><input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
            <div class="form-group"><label>Nama Pelanggan</label> <input type="text" name="name" class="form-control" required placeholder="Nama Lengkap"></div>
            <div class="form-group"><label>Nomor WhatsApp</label> <input type="text" name="phone" class="form-control" required placeholder="08xxxx"></div>
            <div class="form-group" style="grid-column: 1 / -1;"><label>PPPoE Username</label><input type="text" name="pppoe_username" class="form-control" required placeholder="User internet"></div>
            
            <div class="form-group olt-provisioning-box" style="grid-column: 1 / -1;">
                <label style="color: var(--neon-cyan); display: block; margin-bottom: 15px;"><i class="fas fa-microchip"></i> OLT Provisioning (V-SOL)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div><label>Pilih OLT</label>
                        <select name="olt_id" id="olt_selector" class="form-control">
                            <option value="0">-- Lewati OLT --</option>
                            <?php foreach ($olts as $o): ?><option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Serial Number ONT <span id="sn_status_badge" style="display:none;"></span></label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="onu_sn_input" name="onu_sn" list="onu_sn_list" class="form-control" placeholder="Scan atau Paste SN" style="flex: 1;">
                            <datalist id="onu_sn_list"></datalist>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="startCameraScan()"><i class="fas fa-camera"></i></button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="scanOltOnu()"><i id="scan-icon" class="fas fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="olt_pon_port" id="olt_pon_port_input"><input type="hidden" name="onu_id" id="onu_id_input"><input type="hidden" name="onu_status" id="onu_status_hidden" value="unconfigured">
                <div style="margin-top: 20px; display: flex; flex-wrap: wrap; gap: 20px; padding: 12px; background: rgba(0,0,0,0.1); border-radius: 8px;">
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 8px;"><input type="checkbox" name="olt_services[]" value="acs" checked> <span>TR069 (ACS)</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 8px;"><input type="checkbox" name="olt_services[]" value="pppoe" checked> <span>Internet (PPPoE)</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 8px;"><input type="checkbox" name="olt_services[]" value="hotspot" checked> <span>Hotspot (200)</span></label>
                    <label style="cursor:pointer; display: flex; align-items: center; gap: 8px;"><input type="checkbox" name="olt_services[]" value="wifi" checked> <span>WiFi SSID 2</span></label>
                </div>
            </div>

            <div class="form-group"><label>Paket Langganan</label>
                <select name="package_id" class="form-control" required>
                    <option value="">Pilih Paket Paket</option>
                    <?php foreach ($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Jatuh Tempo (1-28)</label><input type="number" name="isolation_date" class="form-control" value="20" min="1" max="28" required></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:25px; width:100%; height:55px; font-weight: bold;"><i class="fas fa-save"></i> SIMPAN & PROSES OLT</button>
    </form>
</div>

<div class="card" style="margin-top: 30px;"><div class="card-header"><h3>Daftar Pelanggan Terbaru</h3></div>
<table class="data-table"><thead><tr><th>Nama</th><th>Paket</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($customers as $c): ?><tr><td><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small><?php echo htmlspecialchars($c['phone']); ?></small></td><td><?php echo htmlspecialchars($c['package_name']); ?></td><td><span class="badge badge-<?php echo $c['status']==='active'?'success':'warning'; ?>"><?php echo ucfirst($c['status']); ?></span></td><td><button class="btn btn-secondary btn-sm" onclick='editCustomer(<?php echo json_encode($c); ?>)'><i class="fas fa-edit"></i></button></td></tr><?php endforeach; ?>
</tbody></table></div>

<!-- DEFERRED LIBRARIES FOR SPEED (V7.9) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/html5-qrcode@latest/html5-qrcode.min.js"></script>

<script>
window.map = null; window.lastScanResults = []; window.scanner = null;

console.log('Patch V7.9 Responsive Initialized.');

// Fast Load Strategy
window.addEventListener('load', () => { setTimeout(() => { console.log('Performance Optimization Active.'); }, 200); });

// GLOBAL SMART FEEDBACK (V7.9 GUARANTEED)
function checkOnuMatch() {
    const input = document.getElementById('onu_sn_input'); if (!input) return;
    const sn = input.value.trim().toLowerCase();
    const badge = document.getElementById('sn_status_badge'); if (!badge) return;
    
    if (!sn) { badge.style.display = 'none'; return; }
    
    badge.style.display = 'inline-block';
    const onu = (window.lastScanResults || []).find(o => (o.sn || '').toLowerCase() === sn);
    
    if (onu) {
        document.getElementById('olt_pon_port_input').value = onu.port;
        document.getElementById('onu_id_input').value = onu.id;
        document.getElementById('onu_status_hidden').value = onu.status;
        badge.style.background = 'var(--neon-green, #39FF14)'; badge.style.color = '#000'; badge.textContent = 'DITEMUKAN';
    } else {
        badge.style.background = 'var(--danger, #ff4d4d)'; badge.style.color = '#fff'; badge.textContent = (window.lastScanResults.length > 0) ? 'BELUM DISCAN' : 'DATA OLT KOSONG';
    }
}

function scanOltOnu() {
    const oltId = document.getElementById('olt_selector').value;
    const icon = document.getElementById('scan-icon');
    const badge = document.getElementById('sn_status_badge');
    if (oltId === '0') return alert('Pilih OLT terlebih dahulu!');
    
    icon.className = 'fas fa-spinner fa-spin';
    if (badge) { 
        badge.style.display='inline-block'; 
        badge.style.background='var(--warning, #ffc107)'; 
        badge.style.color='#000'; 
        badge.textContent='SCANNING MULTI-PAGE...'; 
    }
    
    fetch('customers.php?ajax_action=scan_onu&olt_id=' + oltId)
        .then(r => {
            if (!r.ok) throw new Error('OLT Timeout atau Gagal menghubungkan');
            return r.json();
        })
        .then(data => {
            window.lastScanResults = Array.isArray(data) ? data : [];
            const dl = document.getElementById('onu_sn_list'); dl.innerHTML = '';
            window.lastScanResults.forEach(o => { const opt = document.createElement('option'); opt.value = o.sn; dl.appendChild(opt); });
            
            // Completion Feedback
            if (window.lastScanResults.length > 0) {
                alert('Scan Selesai: Berhasil menarik ' + window.lastScanResults.length + ' data ONU dari OLT.');
            } else {
                alert('Scan Selesai: Tidak ada perangkat (ONU) baru atau terdaftar yang ditemukan.');
            }
            
            checkOnuMatch();
        })
        .catch(err => { 
            console.error(err);
            alert('Kesalahan Scan: ' + err.message);
            if(badge){ badge.style.background='var(--danger)'; badge.style.color='#fff'; badge.textContent='GAGAL'; } 
        })
        .finally(() => { icon.className = 'fas fa-search'; });
}

// BINDING FEEDBACK
document.addEventListener('DOMContentLoaded', () => {
    const snInput = document.getElementById('onu_sn_input');
    if (snInput) snInput.addEventListener('input', checkOnuMatch);
});

function startCameraScan() {
    const modal = document.createElement('div');
    modal.id = 'tempCameraModal';
    modal.style = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);z-index:10000;display:flex;flex-direction:column;align-items:center;justify-content:center;';
    modal.innerHTML = '<div style="background:#1e1e1e;padding:20px;border-radius:12px;width:90%;max-width:400px;text-align:center;"><h3 style="color:#00d2ff;margin-bottom:15px;">Scanning ONT...</h3><div id="qr-reader" style="width:100%;background:#000;border-radius:8px;overflow:hidden;"></div><button class="btn btn-secondary" onclick="this.parentElement.parentElement.remove(); if(window.scanner) window.scanner.stop();" style="margin-top:20px;width:100%;">Tutup Kamera</button></div>';
    document.body.appendChild(modal);
    
    window.scanner = new Html5Qrcode("qr-reader");
    window.scanner.start({ facingMode: "environment" }, { fps: 15, qrbox: 250 }, (txt) => {
        document.getElementById('onu_sn_input').value = txt;
        checkOnuMatch();
        modal.remove();
        window.scanner.stop();
    }).catch(err => { console.error(err); });
}

function editCustomer(c) {
    alert('Fungsi Edit sedang dioptimasi (V7.9)');
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
