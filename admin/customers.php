<?php
/**
 * Customers Management v8.24
 * - Edit pelanggan dengan field ONU lengkap
 * - Scan ONU dari running-config (offline, zero timeout)
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/db.php';

$pageTitle = 'Pelanggan';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('customers.php');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $rawPortalPassword = generateRandomString(4, 'numeric');
                $data = [
                    'name'           => sanitize($_POST['name']),
                    'phone'          => sanitize($_POST['phone']),
                    'pppoe_username' => sanitize($_POST['pppoe_username']),
                    'package_id'     => (int)$_POST['package_id'],
                    'router_id'      => (int)($_POST['router_id'] ?? 0),
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address'        => sanitize($_POST['address'] ?? ''),
                    'lat'            => (!isset($_POST['lat']) || trim($_POST['lat']) === '') ? null : str_replace(',', '.', trim($_POST['lat'])),
                    'lng'            => (!isset($_POST['lng']) || trim($_POST['lng']) === '') ? null : str_replace(',', '.', trim($_POST['lng'])),
                    'portal_password'=> $rawPortalPassword,
                    'olt_id'         => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn'         => strtoupper(sanitize($_POST['onu_sn'] ?? '')),
                    'olt_pon_port'   => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id'         => (int)($_POST['onu_id'] ?? 0),
                    'created_at'     => date('Y-m-d H:i:s')
                ];
                if (insert('customers', $data)) {
                    setFlash('success', 'Pelanggan berhasil ditambahkan');
                    logActivity('ADD_CUSTOMER', "Name: " . $data['name']);
                } else {
                    setFlash('error', 'Gagal menambahkan pelanggan');
                }
                redirect('customers.php');
                break;

            case 'edit':
                $id = (int)$_POST['customer_id'];
                $data = [
                    'name'           => sanitize($_POST['name']),
                    'phone'          => sanitize($_POST['phone']),
                    'pppoe_username' => sanitize($_POST['pppoe_username']),
                    'package_id'     => (int)$_POST['package_id'],
                    'isolation_date' => (int)$_POST['isolation_date'],
                    'address'        => sanitize($_POST['address'] ?? ''),
                    'olt_id'         => (int)($_POST['olt_id'] ?? 0),
                    'onu_sn'         => strtoupper(sanitize($_POST['onu_sn'] ?? '')),
                    'olt_pon_port'   => (int)($_POST['olt_pon_port'] ?? 0),
                    'onu_id'         => (int)($_POST['onu_id'] ?? 0),
                ];
                if (update('customers', $data, 'id = ?', [$id])) {
                    setFlash('success', 'Data pelanggan berhasil diperbarui');
                    logActivity('EDIT_CUSTOMER', "ID: $id");
                } else {
                    setFlash('error', 'Gagal memperbarui pelanggan');
                }
                redirect('customers.php');
                break;

            case 'delete':
                $id = (int)$_POST['customer_id'];
                if (deleteRecord('customers', 'id = ?', [$id])) {
                    setFlash('success', 'Pelanggan berhasil dihapus');
                } else {
                    setFlash('error', 'Gagal menghapus pelanggan');
                }
                redirect('customers.php');
                break;
        }
    }
}

$olts     = fetchAll("SELECT id, name FROM olt_configs ORDER BY name ASC");
$packages = fetchAll("SELECT * FROM packages ORDER BY price ASC");
$customers = fetchAll("SELECT c.*, p.name as package_name FROM customers c LEFT JOIN packages p ON c.package_id = p.id ORDER BY c.created_at DESC");

ob_start();
?>

<style>
.olt-box { padding: 16px; background: rgba(0,210,255,0.04); border: 1px solid rgba(0,210,255,0.15); border-radius: 10px; margin-top: 10px; }
.modal-overlay { display:none; position:fixed; top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center; }
.modal-overlay.active { display:flex; }
.modal-box { background:var(--bg-card,#1e1e1e); border-radius:14px; padding:28px; width:95%; max-width:640px; max-height:90vh; overflow-y:auto; }
.modal-box h3 { margin:0 0 20px; color:var(--neon-cyan,#00d2ff); }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
.grid-2.full { grid-template-columns:1fr; }
.badge-active { background:#00c853; color:#000; padding:2px 8px; border-radius:5px; font-size:12px; font-weight:bold; }
.badge-inactive { background:#ff5252; color:#fff; padding:2px 8px; border-radius:5px; font-size:12px; font-weight:bold; }
.onu-tag { display:inline-block; background:rgba(0,210,255,0.1); border:1px solid rgba(0,210,255,0.3); color:#00d2ff; padding:2px 8px; border-radius:5px; font-family:monospace; font-size:12px; }
</style>

<!-- TAMBAH PELANGGAN -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-plus"></i> Tambah Pelanggan</h3></div>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        <div class="grid-2">
            <div class="form-group"><label>Nama Pelanggan</label><input type="text" name="name" class="form-control" required placeholder="Nama Lengkap"></div>
            <div class="form-group"><label>Nomor WhatsApp</label><input type="text" name="phone" class="form-control" required placeholder="08xxxx"></div>
            <div class="form-group"><label>PPPoE Username</label><input type="text" name="pppoe_username" class="form-control" required placeholder="User internet"></div>
            <div class="form-group"><label>Jatuh Tempo (tgl)</label><input type="number" name="isolation_date" class="form-control" value="20" min="1" max="28" required></div>

            <div class="form-group" style="grid-column:1/-1">
                <label>Paket Langganan</label>
                <select name="package_id" class="form-control" required>
                    <option value="">Pilih Paket</option>
                    <?php foreach ($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?></option><?php endforeach; ?>
                </select>
            </div>

            <!-- ONU SECTION -->
            <div class="form-group olt-box" style="grid-column:1/-1">
                <label style="color:var(--neon-cyan,#00d2ff);display:block;margin-bottom:12px;"><i class="fas fa-microchip"></i> Data ONU / OLT</label>
                <div class="grid-2">
                    <div><label>OLT</label>
                        <select name="olt_id" id="add_olt_id" class="form-control">
                            <option value="0">-- Tanpa OLT --</option>
                            <?php foreach ($olts as $o): ?><option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Serial Number (SN)</label>
                        <input type="text" name="onu_sn" id="add_onu_sn" class="form-control" placeholder="Contoh: FHTTC098844B">
                    </div>
                    <div><label>PON Port (0/x)</label>
                        <input type="number" name="olt_pon_port" id="add_pon_port" class="form-control" placeholder="1-8" min="1" max="8">
                    </div>
                    <div><label>ONU ID</label>
                        <input type="number" name="onu_id" id="add_onu_id" class="form-control" placeholder="Nomor ONU di port">
                    </div>
                </div>
                <small style="color:#888;margin-top:8px;display:block">Isi dari hasil paste running-config di halaman Debug. Atau isi manual dari info OLT.</small>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:20px;width:100%;height:50px;font-weight:bold;"><i class="fas fa-save"></i> SIMPAN PELANGGAN</button>
    </form>
</div>

<!-- DAFTAR PELANGGAN -->
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <h3><i class="fas fa-users"></i> Daftar Pelanggan (<?php echo count($customers); ?>)</h3>
        <input type="text" id="search_cust" class="form-control" placeholder="Cari nama / SN / username..." style="max-width:300px;" oninput="filterCustomers()">
    </div>
    <div style="overflow-x:auto">
        <table class="data-table" id="cust_table">
            <thead><tr><th>Nama</th><th>PPPoE</th><th>Paket</th><th>ONU Info</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach($customers as $c): ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                    <br><small style="color:#888"><?php echo htmlspecialchars($c['phone']); ?></small>
                </td>
                <td style="font-family:monospace;font-size:13px"><?php echo htmlspecialchars($c['pppoe_username'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($c['package_name'] ?? '-'); ?></td>
                <td>
                    <?php if (!empty($c['onu_sn'])): ?>
                        <span class="onu-tag"><?php echo htmlspecialchars($c['onu_sn']); ?></span>
                        <br><small style="color:#888">Port <?php echo $c['olt_pon_port']; ?> / ID <?php echo $c['onu_id']; ?></small>
                    <?php else: ?>
                        <span style="color:#555">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="<?php echo ($c['status'] ?? '') === 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                        <?php echo ucfirst($c['status'] ?? 'inactive'); ?>
                    </span>
                </td>
                <td>
                    <button class="btn btn-secondary btn-sm" onclick='openEdit(<?php echo json_encode($c); ?>)'>
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL EDIT -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <h3><i class="fas fa-edit"></i> Edit Pelanggan</h3>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="customer_id" id="edit_id">
            <div class="grid-2">
                <div class="form-group"><label>Nama</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
                <div class="form-group"><label>WhatsApp</label><input type="text" name="phone" id="edit_phone" class="form-control" required></div>
                <div class="form-group"><label>PPPoE Username</label><input type="text" name="pppoe_username" id="edit_pppoe" class="form-control"></div>
                <div class="form-group"><label>Jatuh Tempo</label><input type="number" name="isolation_date" id="edit_iso" class="form-control" min="1" max="28"></div>
                <div class="form-group" style="grid-column:1/-1"><label>Paket</label>
                    <select name="package_id" id="edit_pkg" class="form-control">
                        <?php foreach ($packages as $pkg): ?><option value="<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>

                <!-- ONU EDIT -->
                <div class="olt-box" style="grid-column:1/-1">
                    <label style="color:var(--neon-cyan,#00d2ff);display:block;margin-bottom:12px;"><i class="fas fa-microchip"></i> Data ONU</label>
                    <div class="grid-2">
                        <div><label>OLT</label>
                            <select name="olt_id" id="edit_olt_id" class="form-control">
                                <option value="0">-- Tanpa OLT --</option>
                                <?php foreach ($olts as $o): ?><option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Serial Number (SN)</label>
                            <input type="text" name="onu_sn" id="edit_onu_sn" class="form-control" placeholder="FHTTC098844B">
                        </div>
                        <div><label>PON Port (0/x)</label>
                            <input type="number" name="olt_pon_port" id="edit_pon_port" class="form-control" min="1" max="8">
                        </div>
                        <div><label>ONU ID</label>
                            <input type="number" name="onu_id" id="edit_onu_id" class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="submit" class="btn btn-primary" style="flex:1;height:46px;font-weight:bold;"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <button type="button" class="btn btn-secondary" onclick="closeEdit()" style="height:46px;padding:0 20px;">Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter tabel
function filterCustomers() {
    const q = document.getElementById('search_cust').value.toLowerCase();
    document.querySelectorAll('#cust_table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// Buka modal edit
function openEdit(c) {
    document.getElementById('edit_id').value      = c.id;
    document.getElementById('edit_name').value    = c.name || '';
    document.getElementById('edit_phone').value   = c.phone || '';
    document.getElementById('edit_pppoe').value   = c.pppoe_username || '';
    document.getElementById('edit_iso').value     = c.isolation_date || 20;
    document.getElementById('edit_olt_id').value  = c.olt_id || 0;
    document.getElementById('edit_onu_sn').value  = c.onu_sn || '';
    document.getElementById('edit_pon_port').value = c.olt_pon_port || '';
    document.getElementById('edit_onu_id').value  = c.onu_id || '';

    // Set paket
    const pkg = document.getElementById('edit_pkg');
    for (let opt of pkg.options) { if (opt.value == c.package_id) { opt.selected = true; break; } }

    document.getElementById('editModal').classList.add('active');
}

function closeEdit() {
    document.getElementById('editModal').classList.remove('active');
}

// Tutup modal klik di luar
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
