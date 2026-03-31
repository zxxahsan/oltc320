<?php
/**
 * OLT Provisioning Center v1.0
 * Konfigurasi ONU: WAN, ACS/TR-069, Hotspot Bridge, WiFi
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$pageTitle = 'ONU Provisioning';

// === AJAX: Provision ===
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'provision') {
    header('Content-Type: application/json');
    $olt_id     = (int)($_POST['olt_id'] ?? 0);
    $port       = (int)($_POST['port'] ?? 0);
    $onu_id     = (int)($_POST['onu_id'] ?? 0);
    $pppoe_user = trim($_POST['pppoe_user'] ?? '');
    $pppoe_pass = trim($_POST['pppoe_pass'] ?? '12345678');
    $services   = $_POST['services'] ?? [];
    $acs_url    = trim($_POST['acs_url'] ?? 'http://172.16.200.3:7547');

    if (!$olt_id || !$port || !$onu_id) {
        echo json_encode(['success' => false, 'log' => '✗ OLT ID, Port, atau ONU ID tidak boleh kosong.']);
        exit;
    }

    $result = vsolProvisionOnu($olt_id, $port, $onu_id, $pppoe_user, $pppoe_pass, $services, $acs_url);
    echo json_encode($result);
    exit;
}

// === AJAX: Ambil data pelanggan berdasarkan SN ===
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'lookup_customer') {
    header('Content-Type: application/json');
    $sn = strtoupper(trim($_GET['sn'] ?? ''));
    if (!$sn) { echo json_encode(null); exit; }
    $c = fetchOne("SELECT c.*, o.name as olt_name FROM customers c LEFT JOIN olt_configs o ON c.olt_id = o.id WHERE UPPER(c.onu_sn) = ?", [$sn]);
    echo json_encode($c);
    exit;
}

$olts      = fetchAll("SELECT id, name, host FROM olt_configs ORDER BY name ASC");
$customers = fetchAll("SELECT c.id, c.name, c.pppoe_username, c.onu_sn, c.olt_pon_port, c.onu_id, c.olt_id FROM customers WHERE onu_sn != '' AND onu_sn IS NOT NULL ORDER BY c.name ASC");

ob_start();
?>

<style>
.prov-grid { display: grid; grid-template-columns: 380px 1fr; gap: 20px; align-items: start; }
@media(max-width:900px) { .prov-grid { grid-template-columns: 1fr; } }
.svc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 14px 0; }
.svc-label { display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 14px; border:1px solid rgba(255,255,255,0.1); border-radius:8px; transition:0.2s; }
.svc-label:hover { border-color: rgba(0,210,255,0.4); background:rgba(0,210,255,0.05); }
.svc-label input:checked ~ span { color: #00d2ff; }
.log-box { background:#000; color:#00ff41; font-family:'Consolas',monospace; font-size:12px; padding:16px; border-radius:8px; min-height:300px; max-height:500px; overflow-y:auto; border:1px solid #1a3a1a; white-space:pre-wrap; word-break:break-all; }
.log-box .ok  { color: #00ff41; }
.log-box .err { color: #ff5252; }
.log-box .cmd { color: #888; }
.cust-table { width:100%; border-collapse:collapse; font-size:13px; }
.cust-table th { background:#222; color:#888; padding:8px 12px; text-align:left; font-size:11px; text-transform:uppercase; }
.cust-table td { padding:8px 12px; border-bottom:1px solid #1e1e1e; cursor:pointer; }
.cust-table tr:hover td { background:rgba(0,210,255,0.05); }
.sn-tag { font-family:monospace; color:#00d2ff; font-size:12px; }
.btn-provision { background: linear-gradient(135deg,#00ff41,#00d2ff); color:#000; border:none; width:100%; height:50px; border-radius:8px; font-weight:bold; font-size:15px; cursor:pointer; letter-spacing:1px; transition:0.2s; }
.btn-provision:hover { opacity:0.9; transform:translateY(-1px); }
.btn-provision:disabled { opacity:0.5; cursor:not-allowed; transform:none; }
.status-idle  { color:#888; }
.status-ok    { color:#00ff41; }
.status-err   { color:#ff5252; }
.badge-port { background:#1a3a4a; color:#00d2ff; padding:2px 8px; border-radius:4px; font-size:11px; }
</style>

<div class="prov-grid">

    <!-- ===== KIRI: FORM PROVISIONING ===== -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-rocket"></i> ONU Provisioning</h3>
            </div>

            <!-- PILIH DARI DATABASE -->
            <div style="margin-bottom:16px;">
                <label style="color:#888;font-size:12px;display:block;margin-bottom:6px;"><i class="fas fa-search"></i> Cari dari daftar pelanggan terdaftar:</label>
                <input type="text" id="search_cust" class="form-control" placeholder="Ketik nama atau SN pelanggan..." oninput="filterCust()">
                <div id="cust_dropdown" style="display:none;background:#111;border:1px solid #333;border-radius:6px;max-height:180px;overflow-y:auto;margin-top:4px;">
                    <?php foreach($customers as $c): ?>
                    <div class="cust-row" data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>" data-sn="<?php echo strtolower($c['onu_sn']); ?>"
                         onclick='fillFromCustomer(<?php echo json_encode($c); ?>)'
                         style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #1a1a1a;font-size:13px;">
                        <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                        <span class="sn-tag" style="float:right"><?php echo $c['onu_sn']; ?></span>
                        <br><small style="color:#666"><?php echo $c['pppoe_username']; ?> &bull; Port <?php echo $c['olt_pon_port']; ?> / ID <?php echo $c['onu_id']; ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr style="border-color:#222;margin:16px 0;">

            <!-- FORM MANUAL -->
            <div class="form-group">
                <label>OLT</label>
                <select id="f_olt" class="form-control">
                    <option value="0">-- Pilih OLT --</option>
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?> (<?php echo $o['host']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>PON Port (0/<b>x</b>)</label>
                    <input type="number" id="f_port" class="form-control" placeholder="1-8" min="1" max="8">
                </div>
                <div class="form-group">
                    <label>ONU ID</label>
                    <input type="number" id="f_onu_id" class="form-control" placeholder="1-128">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>PPPoE Username</label>
                    <input type="text" id="f_pppoe_user" class="form-control" placeholder="username">
                </div>
                <div class="form-group">
                    <label>PPPoE Password</label>
                    <input type="text" id="f_pppoe_pass" class="form-control" value="12345678">
                </div>
            </div>

            <div class="form-group">
                <label>URL ACS Server</label>
                <input type="text" id="f_acs_url" class="form-control" value="http://172.16.200.3:7547">
            </div>

            <!-- LAYANAN -->
            <label style="color:#aaa;font-size:12px;display:block;margin-bottom:6px;">Layanan yang dikonfigurasi:</label>
            <div class="svc-grid">
                <label class="svc-label">
                    <input type="checkbox" id="svc_acs" checked>
                    <span><i class="fas fa-broadcast-tower" style="color:#00d2ff"></i> TR-069 / ACS<br><small style="color:#666">VLAN 101 DHCP</small></span>
                </label>
                <label class="svc-label">
                    <input type="checkbox" id="svc_pppoe" checked>
                    <span><i class="fas fa-globe" style="color:#00d2ff"></i> Internet PPPoE<br><small style="color:#666">VLAN 100</small></span>
                </label>
                <label class="svc-label">
                    <input type="checkbox" id="svc_hotspot" checked>
                    <span><i class="fas fa-server" style="color:#00d2ff"></i> Hotspot Bridge<br><small style="color:#666">VLAN 200</small></span>
                </label>
                <label class="svc-label">
                    <input type="checkbox" id="svc_wifi" checked>
                    <span><i class="fas fa-wifi" style="color:#00d2ff"></i> WiFi SSID 2<br><small style="color:#666">Jinom_Hotspot</small></span>
                </label>
            </div>

            <button class="btn-provision" id="btn_prov" onclick="doProvision()">
                <i class="fas fa-bolt"></i> KIRIM KE OLT
            </button>

            <div style="margin-top:12px;color:#555;font-size:12px;text-align:center;">
                Proses membutuhkan ~15-30 detik untuk menyelesaikan semua perintah ke OLT.
            </div>
        </div>
    </div>

    <!-- ===== KANAN: LOG ===== -->
    <div>
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
                <h3><i class="fas fa-terminal"></i> Execution Log</h3>
                <span id="status_badge" class="status-idle">Menunggu...</span>
            </div>
            <div class="log-box" id="log_box">Klik "KIRIM KE OLT" untuk memulai provisioning.

Perintah yang akan dikirim (sesuai pola OLT V-SOL V1.3.9R):

  configure terminal
  interface gpon 0/&lt;port&gt;
  onu &lt;id&gt; pri wan_adv add route         → WAN ACS (VLAN 101)
  onu &lt;id&gt; pri wan_adv index 1 ...
  onu &lt;id&gt; pri wan_adv add route         → WAN PPPoE (VLAN 100)
  onu &lt;id&gt; pri wan_adv index 2 ...
  onu &lt;id&gt; pri wan_adv add bridge        → Hotspot Bridge (VLAN 200)
  onu &lt;id&gt; pri wan_adv index 3 ...
  onu &lt;id&gt; pri wifi_ssid 2 name ...      → WiFi SSID 2
  onu &lt;id&gt; pri tr069_mng enable ...      → ACS Management
  exit
  write                                   → Simpan ke OLT</div>
        </div>

        <!-- Daftar Pelanggan dengan ONU -->
        <div class="card" style="margin-top:20px;">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Pelanggan dengan ONU Terdaftar</h3>
                <input type="text" id="tbl_search" class="form-control" placeholder="Cari..." style="max-width:200px;" oninput="filterTable()">
            </div>
            <div style="overflow-x:auto;max-height:400px;overflow-y:auto;">
                <table class="cust-table" id="cust_tbl">
                    <thead><tr><th>Nama</th><th>SN</th><th>Port</th><th>ID</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach($customers as $c): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($c['name']); ?></strong><br><small style="color:#666"><?php echo $c['pppoe_username']; ?></small></td>
                        <td class="sn-tag"><?php echo $c['onu_sn']; ?></td>
                        <td><span class="badge-port">0/<?php echo $c['olt_pon_port']; ?></span></td>
                        <td style="color:#888"><?php echo $c['onu_id']; ?></td>
                        <td><button class="btn btn-secondary btn-sm" onclick='fillFromCustomer(<?php echo json_encode($c); ?>)'>
                            <i class="fas fa-arrow-left"></i> Isi Form
                        </button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
// ── Filter dropdown pencarian
function filterCust() {
    const q = document.getElementById('search_cust').value.toLowerCase();
    const dd = document.getElementById('cust_dropdown');
    const rows = document.querySelectorAll('.cust-row');
    let shown = 0;
    rows.forEach(r => {
        const match = r.dataset.name.includes(q) || r.dataset.sn.includes(q);
        r.style.display = match ? '' : 'none';
        if (match) shown++;
    });
    dd.style.display = (q.length > 0 && shown > 0) ? 'block' : 'none';
}

// ── Isi form dari data pelanggan
function fillFromCustomer(c) {
    document.getElementById('f_olt').value       = c.olt_id || 0;
    document.getElementById('f_port').value      = c.olt_pon_port || '';
    document.getElementById('f_onu_id').value    = c.onu_id || '';
    document.getElementById('f_pppoe_user').value = c.pppoe_username || '';
    document.getElementById('search_cust').value = c.name;
    document.getElementById('cust_dropdown').style.display = 'none';

    // Flash feedback
    document.getElementById('log_box').textContent = `✓ Data diisi dari pelanggan: ${c.name}\n  SN: ${c.onu_sn}\n  OLT Port: 0/${c.olt_pon_port} | ONU ID: ${c.onu_id}\n\nSilakan klik "KIRIM KE OLT" untuk memulai provisioning.`;
}

// ── Filter tabel bawah
function filterTable() {
    const q = document.getElementById('tbl_search').value.toLowerCase();
    document.querySelectorAll('#cust_tbl tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Kirim provisioning ke OLT
async function doProvision() {
    const olt_id    = document.getElementById('f_olt').value;
    const port      = document.getElementById('f_port').value;
    const onu_id    = document.getElementById('f_onu_id').value;
    const pppoe_u   = document.getElementById('f_pppoe_user').value.trim();
    const pppoe_p   = document.getElementById('f_pppoe_pass').value.trim();
    const acs_url   = document.getElementById('f_acs_url').value.trim();
    const btn       = document.getElementById('btn_prov');
    const logBox    = document.getElementById('log_box');
    const badge     = document.getElementById('status_badge');

    if (!olt_id || olt_id == 0) { alert('Pilih OLT terlebih dahulu!'); return; }
    if (!port)   { alert('Isi PON Port!'); return; }
    if (!onu_id) { alert('Isi ONU ID!'); return; }

    const services = [];
    ['acs','pppoe','hotspot','wifi'].forEach(s => {
        if (document.getElementById('svc_' + s).checked) services.push(s);
    });

    if (services.length === 0) { alert('Pilih minimal satu layanan!'); return; }

    // Set UI state
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> MENGIRIM KE OLT...';
    badge.className = 'status-idle';
    badge.textContent = '⏳ Proses...';
    logBox.textContent = `⏳ Menghubungkan ke OLT...\n   Port: 0/${port} | ONU ID: ${onu_id}\n   PPPoE: ${pppoe_u}\n   Layanan: ${services.join(', ')}\n\nMohon tunggu, jangan tutup halaman ini...`;

    const fd = new FormData();
    fd.append('olt_id',    olt_id);
    fd.append('port',      port);
    fd.append('onu_id',    onu_id);
    fd.append('pppoe_user', pppoe_u);
    fd.append('pppoe_pass', pppoe_p);
    fd.append('acs_url',   acs_url);
    services.forEach(s => fd.append('services[]', s));

    try {
        const r = await fetch('olt_provision.php?ajax_action=provision', {
            method: 'POST',
            body: fd
        });
        const data = await r.json();
        logBox.textContent = data.log || 'Tidak ada log.';
        logBox.scrollTop = logBox.scrollHeight;

        if (data.success) {
            badge.className = 'status-ok';
            badge.textContent = '✓ SELESAI';
        } else {
            badge.className = 'status-err';
            badge.textContent = '✗ GAGAL';
        }
    } catch(e) {
        logBox.textContent = '✗ Request gagal: ' + e.message;
        badge.className = 'status-err';
        badge.textContent = '✗ ERROR';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-bolt"></i> KIRIM KE OLT';
    }
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
