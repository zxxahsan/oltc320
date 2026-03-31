<?php
/**
 * OLT Settings - Manage OLT Hardware Connections
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'OLT Settings';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('olt_settings.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_olt') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $data = [
            'name' => sanitize($_POST['name']),
            'host' => sanitize($_POST['host']),
            'port' => (int)$_POST['port'],
            'username' => sanitize($_POST['username']),
            'password' => $_POST['password'], // Stored as plain string for automated CLI login
            'enable_password' => $_POST['enable_password'] ?? '', 
            'type' => sanitize($_POST['type']),
            'protocol' => sanitize($_POST['protocol']),
            'snmp_community' => sanitize($_POST['snmp_community']),
            'snmp_version' => sanitize($_POST['snmp_version']),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($id > 0) {
            update('olt_configs', $data, 'id = ?', [$id]);
            setFlash('success', 'OLT updated successfully');
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            insert('olt_configs', $data);
            setFlash('success', 'OLT added successfully');
        }
        redirect('olt_settings.php');

    } elseif ($action === 'delete_olt') {
        $id = (int)$_POST['id'];
        delete('olt_configs', 'id = ?', [$id]);
        setFlash('success', 'OLT deleted successfully');
        redirect('olt_settings.php');
    }
}

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY id DESC");

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-microchip"></i> Manage OLT Connections</h3>
        <button class="btn btn-primary btn-sm" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Add OLT
        </button>
    </div>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Host</th>
                    <th>Type</th>
                    <th>Protocol</th>
                    <th>Port</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($olts)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: var(--text-muted);">
                            No OLTs configured yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($olts as $olt): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($olt['name']); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($olt['host']); ?></code></td>
                            <td><span class="badge badge-info"><?php echo strtoupper(str_replace('_', ' ', $olt['type'])); ?></span></td>
                            <td><?php echo strtoupper($olt['protocol']); ?></td>
                            <td><?php echo $olt['port']; ?></td>
                            <td>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-secondary btn-sm" onclick='editOlt(<?php echo json_encode($olt); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Hapus OLT ini?');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_olt">
                                        <input type="hidden" name="id" value="<?php echo $olt['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
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
<div id="oltModal" class="modal" style="display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px);">
    <div style="background: var(--bg-card); margin: 5% auto; padding: 25px; border: 1px solid var(--border-color); width: 90%; max-width: 500px; border-radius: 18px; box-shadow: var(--shadow-neon);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 id="modalTitle" style="color: var(--neon-cyan); margin: 0;">Add OLT</h3>
            <span onclick="closeModal()" style="cursor: pointer; font-size: 1.5rem; color: var(--text-muted);">&times;</span>
        </div>
        
        <form method="POST" id="oltForm">
            <input type="hidden" name="action" value="save_olt">
            <input type="hidden" name="id" id="olt_id" value="0">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            
            <div class="form-group">
                <label class="form-label">OLT Name</label>
                <input type="text" name="name" id="olt_name" class="form-control" required placeholder="e.g. OLT Pusat">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 80px; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Host IP</label>
                    <input type="text" name="host" id="olt_host" class="form-control" required placeholder="10.10.10.1">
                </div>
                <div class="form-group">
                    <label class="form-label">Port</label>
                    <input type="number" name="port" id="olt_port" class="form-control" value="23" required>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" id="olt_user" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" id="olt_pass" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Enable Password (Optional)</label>
                <input type="password" name="enable_password" id="olt_enable_pass" class="form-control" placeholder="Leave empty if same as password">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" id="olt_type" class="form-control">
                        <option value="vsol_gpon">VSOL GPON</option>
                        <option value="vsol_epon">VSOL EPON</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Protocol</label>
                    <select name="protocol" id="olt_protocol" class="form-control">
                        <option value="telnet">Telnet</option>
                        <option value="ssh">SSH</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">SNMP Community</label>
                    <input type="text" name="snmp_community" id="olt_snmp_community" class="form-control" value="public" placeholder="e.g. public">
                </div>
                <div class="form-group">
                    <label class="form-label">SNMP Version</label>
                    <select name="snmp_version" id="olt_snmp_version" class="form-control">
                        <option value="2c">v2c</option>
                        <option value="1">v1</option>
                        <option value="3">v3</option>
                    </select>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">
                    <i class="fas fa-save"></i> Save OLT
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('modalTitle').innerText = 'Add OLT';
    document.getElementById('oltForm').reset();
    document.getElementById('olt_id').value = '0';
    document.getElementById('oltModal').style.display = 'block';
}

function editOlt(olt) {
    document.getElementById('modalTitle').innerText = 'Edit OLT';
    document.getElementById('olt_id').value = olt.id;
    document.getElementById('olt_name').value = olt.name;
    document.getElementById('olt_host').value = olt.host;
    document.getElementById('olt_port').value = olt.port;
    document.getElementById('olt_user').value = olt.username;
    document.getElementById('olt_pass').value = olt.password;
    document.getElementById('olt_enable_pass').value = olt.enable_password || '';
    document.getElementById('olt_type').value = olt.type;
    document.getElementById('olt_protocol').value = olt.protocol;
    document.getElementById('olt_snmp_community').value = olt.snmp_community || 'public';
    document.getElementById('olt_snmp_version').value = olt.snmp_version || '2c';
    document.getElementById('oltModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('oltModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target == document.getElementById('oltModal')) {
        closeModal();
    }
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
