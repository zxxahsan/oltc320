<?php
/**
 * OLT Provisioning Logs
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Riwayat Provisioning OLT';
$logs = getOltProvisioningLogs(100);

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> Log Provisioning Terakhir</h3>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>OLT</th>
                    <th>SN ONU</th>
                    <th>Port:Index</th>
                    <th>Nama Pelanggan</th>
                    <th>Mode</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-muted);">Belum ada data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><?php echo formatDate($l['created_at'], 'd/m/Y H:i'); ?></td>
                            <td><?php echo htmlspecialchars($l['olt_name']); ?></td>
                            <td><code><?php echo htmlspecialchars($l['onu_sn']); ?></code></td>
                            <td><?php echo htmlspecialchars($l['gpon_port']); ?>:<?php echo $l['onu_index']; ?></td>
                            <td><?php echo htmlspecialchars($l['onu_name']); ?></td>
                            <td>
                                <span class="badge <?php echo $l['provisioning_mode'] === 'omci' ? 'badge-info' : 'badge-secondary'; ?>">
                                    <?php echo strtoupper($l['provisioning_mode']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $l['status'] === 'success' ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo strtoupper($l['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button onclick='showLogDetail(<?php echo json_encode($l); ?>)' class="btn btn-secondary btn-sm" title="Lihat Detail Output">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Log Detail Modal -->
<div id="logModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Detail Output OLT</h3>
            <button onclick="closeModal()" class="close">&times;</button>
        </div>
        <div style="background: #000; color: #00ff00; padding: 20px; border-radius: 8px; font-family: monospace; max-height: 500px; overflow-y: auto;" id="logOutput">
            <!-- Output here -->
        </div>
    </div>
</div>

<script>
    function showLogDetail(data) {
        const modal = document.getElementById('logModal');
        const output = document.getElementById('logOutput');
        
        let html = '';
        try {
            const rawLogs = JSON.parse(data.output);
            rawLogs.forEach(entry => {
                html += `<div style="color:#fff; font-weight:bold; margin-top:10px;">> ${entry.command}</div>`;
                html += `<div style="opacity:0.8; white-space:pre-wrap;">${entry.response}</div>`;
            });
        } catch(e) {
            html = data.output;
        }
        
        output.innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('logModal').style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('logModal')) {
            closeModal();
        }
    }
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
