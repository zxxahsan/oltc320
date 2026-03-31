<?php
/**
 * OLT Provisioning Tool - Add WAN (PPPoE) via OMCI
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$pageTitle = 'ONU Provisioning';

$olts = fetchAll("SELECT id, name, host FROM olt_configs ORDER BY id DESC");

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'provision_wan') {
    // Verify CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $result = ['success' => false, 'message' => 'Invalid CSRF token'];
    } else {
        $olt_id = (int)$_POST['olt_id'];
        $onu_id = sanitize($_POST['onu_id']);
        $vlan = (int)$_POST['vlan'];
        $pppoe_user = sanitize($_POST['pppoe_user']);
        $pppoe_pass = $_POST['pppoe_pass'];

        // Trigger Provisioning
        $result = vsolProvisionWan($olt_id, $onu_id, $vlan, $pppoe_user, $pppoe_pass);
        
        if ($result['success']) {
            logActivity('OLT_PROVISION_WAN', "ONU: $onu_id, VLAN: $vlan, User: $pppoe_user");
        }
    }
}

ob_start();
?>

<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 24px; align-items: start;">
    <!-- Provisioning Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-rocket"></i> Gaskan WAN ONU</h3>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="provision_wan">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            
            <div class="form-group">
                <label class="form-label">Pilih OLT</label>
                <select name="olt_id" class="form-control" required>
                    <option value="">-- Pilih OLT --</option>
                    <?php foreach ($olts as $olt): ?>
                        <option value="<?php echo $olt['id']; ?>" <?php echo (isset($_POST['olt_id']) && $_POST['olt_id'] == $olt['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($olt['name']); ?> (<?php echo $olt['host']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">ONU ID (Port/ID)</label>
                <input type="text" name="onu_id" class="form-control" placeholder="Contoh: 1/1 atau G0/1:1" required value="<?php echo htmlspecialchars($_POST['onu_id'] ?? ''); ?>">
                <small style="color: var(--text-muted); margin-top: 5px;">Format V-SOL: port/id</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">VLAN ID</label>
                <input type="number" name="vlan" class="form-control" placeholder="Contoh: 100" required value="<?php echo htmlspecialchars($_POST['vlan'] ?? ''); ?>">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">PPPoE User</label>
                    <input type="text" name="pppoe_user" class="form-control" placeholder="Username" required value="<?php echo htmlspecialchars($_POST['pppoe_user'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">PPPoE Password</label>
                    <input type="password" name="pppoe_pass" class="form-control" placeholder="Password" required>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; font-size: 1.1rem; padding: 15px;">
                    <i class="fas fa-paper-plane"></i> GAS PROVISIONING!
                </button>
            </div>
        </form>
    </div>

    <!-- Execution Results -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-terminal"></i> Execution Log</h3>
        </div>
        
        <?php if ($result): ?>
            <?php if ($result['success']): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($result['message']); ?>
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($result['message']); ?>
                </div>
            <?php endif; ?>
            
            <div style="background: #000; color: #00ff88; padding: 15px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; font-size: 0.85rem; overflow-x: auto; max-height: 400px; border: 1px solid var(--border-color);">
                <pre style="margin: 0; white-space: pre-wrap;"><?php echo htmlspecialchars($result['log'] ?? 'No log data available.'); ?></pre>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 50px; color: var(--text-muted);">
                <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 15px; display: block;"></i>
                Hasil eksekusi OMCI akan tampil di sini setelah Anda menekan tombol Gaskan.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.alert-danger {
    background: rgba(255, 71, 87, 0.1);
    border: 1px solid var(--neon-red);
    color: var(--neon-red);
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
</style>

<script>
document.querySelector('form').addEventListener('submit', function() {
    this.querySelector('button[type="submit"]').disabled = true;
    this.querySelector('button[type="submit"]').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sedang Memproses...';
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
