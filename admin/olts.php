<?php
/**
 * OLT Management
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'OLT Management';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('olts.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $data = [
            'name' => $_POST['name'],
            'host' => $_POST['host'],
            'username' => $_POST['username'],
            'password' => $_POST['password'],
            'telnet_port' => (int) ($_POST['telnet_port'] ?: 23),
            'type' => $_POST['type'] ?? 'ZTE C320',
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        if ($action === 'add') {
            insert('olts', $data);
            setFlash('success', 'OLT berhasil ditambahkan.');
        } else {
            $id = $_POST['id'];
            update('olts', $data, "id = ?", [$id]);
            setFlash('success', 'OLT berhasil diperbarui.');
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        query("DELETE FROM olts WHERE id = ?", [$id]);
        setFlash('success', 'OLT berhasil dihapus.');
    }

    redirect('olts.php');
}

$olts = getAllOlts();

ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <div>
        <p style="color: var(--text-secondary);">Manajemen perangkat OLT untuk provisioning otomatis.</p>
    </div>
    <button onclick="showAddModal()" class="btn btn-primary">
        <i class="fas fa-plus"></i> Tambah OLT
    </button>
</div>

<!-- OLTs Table -->
<div class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama OLT</th>
                    <th>Host / IP</th>
                    <th>Username</th>
                    <th>Port</th>
                    <th>Tipe</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($olts)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-network-wired" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                            Belum ada OLT ditambahkan
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($olts as $o): ?>
                        <tr>
                            <td data-label="Nama OLT">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 35px; height: 35px; border-radius: 8px; background: rgba(0, 245, 255, 0.1); display: flex; align-items: center; justify-content: center; color: var(--neon-cyan);">
                                        <i class="fas fa-server"></i>
                                    </div>
                                    <strong><?php echo htmlspecialchars($o['name']); ?></strong>
                                </div>
                            </td>
                            <td data-label="Host / IP"><code><?php echo htmlspecialchars($o['host']); ?></code></td>
                            <td data-label="Username"><?php echo htmlspecialchars($o['username']); ?></td>
                            <td data-label="Port"><?php echo htmlspecialchars($o['telnet_port']); ?></td>
                            <td data-label="Tipe"><span class="badge badge-info"><?php echo htmlspecialchars($o['type']); ?></span></td>
                            <td data-label="Status">
                                <?php if ($o['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi">
                                <div style="display: flex; gap: 8px;">
                                    <button onclick='editOlt(<?php echo json_encode($o); ?>)' class="btn btn-secondary btn-sm" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus OLT ini?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $o['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
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
</div>

<!-- Add/Edit Modal -->
<div id="oltModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah OLT</h3>
            <button onclick="closeModal()" class="close">&times;</button>
        </div>
        <form method="POST" id="oltForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="oltId">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <div class="form-group">
                <label class="form-label">Nama OLT</label>
                <input type="text" name="name" id="oltName" class="form-control" required placeholder="Contoh: OLT Pusat C320">
            </div>

            <div class="form-group">
                <label class="form-label">Host (IP Address)</label>
                <input type="text" name="host" id="oltHost" class="form-control" required placeholder="10.10.10.1">
            </div>

            <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Username Telnet</label>
                    <input type="text" name="username" id="oltUser" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password Telnet</label>
                    <input type="password" name="password" id="oltPass" class="form-control">
                </div>
            </div>

            <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Telnet Port</label>
                    <input type="number" name="telnet_port" id="oltPort" class="form-control" value="23">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipe OLT</label>
                    <select name="type" id="oltType" class="form-control">
                        <option value="ZTE C320">ZTE C320</option>
                        <option value="ZTE C300">ZTE C300</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="is_active" id="oltActive" checked> Aktifkan OLT ini
                </label>
            </div>

            <div style="text-align: right; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perangkat</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showAddModal() {
        document.getElementById('modalTitle').innerText = 'Tambah OLT Baru';
        document.getElementById('formAction').value = 'add';
        document.getElementById('oltForm').reset();
        document.getElementById('oltModal').style.display = 'flex';
    }

    function editOlt(data) {
        document.getElementById('modalTitle').innerText = 'Edit OLT';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('oltId').value = data.id;
        document.getElementById('oltName').value = data.name;
        document.getElementById('oltHost').value = data.host;
        document.getElementById('oltUser').value = data.username;
        document.getElementById('oltPass').value = data.password;
        document.getElementById('oltPort').value = data.telnet_port;
        document.getElementById('oltType').value = data.type;
        document.getElementById('oltActive').checked = data.is_active == 1;
        document.getElementById('oltModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('oltModal').style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('oltModal')) {
            closeModal();
        }
    }
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
