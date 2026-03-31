<?php
/**
 * OLT Monitoring Dashboard - SNMP Visualizer
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_monitor_api.php';

$pageTitle = 'OLT Monitoring Dashboard';

$olts = fetchAll("SELECT id, name, host FROM olt_configs ORDER BY id DESC");

$selected_olt_id = isset($_GET['olt_id']) ? (int)$_GET['olt_id'] : (count($olts) > 0 ? $olts[0]['id'] : 0);

$status = null;
if ($selected_olt_id > 0) {
    $status = getOltSnmpStatus($selected_olt_id);
}

ob_start();
?>

<div class="row" style="margin-bottom: 20px;">
    <div class="col-md-6">
        <form method="GET" id="oltSelectorForm">
            <div class="form-group" style="display: flex; gap: 10px; align-items: center;">
                <label style="white-space: nowrap; margin: 0;">Pilih OLT:</label>
                <select name="olt_id" class="form-control" onchange="document.getElementById('oltSelectorForm').submit()">
                    <?php foreach ($olts as $olt): ?>
                        <option value="<?php echo $olt['id']; ?>" <?php echo $selected_olt_id == $olt['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($olt['name']); ?> (<?php echo $olt['host']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-sync"></i> Refresh</button>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_olt_id == 0): ?>
    <div class="card">
        <div class="card-body" style="text-align: center; padding: 50px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--neon-red); margin-bottom: 20px; display: block;"></i>
            <h3>Belum ada OLT yang dikonfigurasi.</h3>
            <p>Silakan tambahkan OLT di menu <a href="olt_settings.php">Settings OLT</a> terlebih dahulu.</p>
        </div>
    </div>
<?php elseif ($status && !$status['success']): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($status['message']); ?>
        <?php if (strpos($status['message'], 'extension') !== false): ?>
            <br><small>Pastikan ekstensi <code>php-snmp</code> sudah terinstal di server Anda.</small>
        <?php endif; ?>
    </div>
<?php elseif ($status): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <!-- Uptime Card -->
        <div class="card" style="border-left: 4px solid var(--neon-cyan);">
            <div class="card-body" style="display: flex; align-items: center; gap: 20px;">
                <div style="background: rgba(0, 245, 255, 0.1); color: var(--neon-cyan); width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">System Uptime</h5>
                    <h3 style="margin: 5px 0 0 0; font-size: 1.5rem;"><?php echo htmlspecialchars($status['metrics']['uptime_readable'] ?? 'N/A'); ?></h3>
                </div>
            </div>
        </div>

        <!-- CPU Load Card -->
        <?php 
            $cpu = (int)($status['metrics']['cpu_load'] ?? 0); 
            $cpu_color = $cpu > 80 ? 'var(--neon-red)' : ($cpu > 50 ? 'var(--neon-yellow)' : 'var(--neon-green)');
        ?>
        <div class="card" style="border-left: 4px solid <?php echo $cpu_color; ?>;">
            <div class="card-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h5 style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">CPU Usage</h5>
                    <span style="color: <?php echo $cpu_color; ?>; font-weight: bold; font-size: 1.2rem;"><?php echo $cpu; ?>%</span>
                </div>
                <div style="height: 10px; background: rgba(255,255,255,0.05); border-radius: 5px; overflow: hidden;">
                    <div style="width: <?php echo $cpu; ?>%; height: 100%; background: <?php echo $cpu_color; ?>; box-shadow: 0 0 10px <?php echo $cpu_color; ?>;"></div>
                </div>
            </div>
        </div>

        <!-- Memory Usage Card -->
        <?php 
            $mem = (int)($status['metrics']['mem_usage'] ?? 0); 
            $mem_color = $mem > 80 ? 'var(--neon-red)' : ($mem > 50 ? 'var(--neon-yellow)' : 'var(--neon-green)');
        ?>
        <div class="card" style="border-left: 4px solid <?php echo $mem_color; ?>;">
            <div class="card-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h5 style="margin: 0; color: var(--text-muted); font-size: 0.9rem;">Memory Usage</h5>
                    <span style="color: <?php echo $mem_color; ?>; font-weight: bold; font-size: 1.2rem;"><?php echo $mem; ?>%</span>
                </div>
                <div style="height: 10px; background: rgba(255,255,255,0.05); border-radius: 5px; overflow: hidden;">
                    <div style="width: <?php echo $mem; ?>%; height: 100%; background: <?php echo $mem_color; ?>; box-shadow: 0 0 10px <?php echo $mem_color; ?>;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 25px;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-info-circle"></i> OLT System Info</h3>
        </div>
        <div class="card-body">
            <table class="table" style="width: 100%;">
                <tr>
                    <td style="width: 200px; color: var(--text-muted);">Hostname (SNMP)</td>
                    <td><strong><?php echo htmlspecialchars($status['metrics']['sysname'] ?? 'N/A'); ?></strong></td>
                </tr>
                <tr>
                    <td style="color: var(--text-muted);">Host IP Address</td>
                    <td><code><?php echo htmlspecialchars($olts[array_search($selected_olt_id, array_column($olts, 'id'))]['host']); ?></code></td>
                </tr>
                <tr>
                    <td style="color: var(--text-muted);">Last Fetch</td>
                    <td><?php echo date('Y-m-d H:i:s'); ?></td>
                </tr>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
// Auto-refresh every 30 seconds
setTimeout(function() {
    location.reload();
}, 30000);
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
