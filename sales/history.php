<?php
/**
 * Sales History
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Riwayat Voucher Selesai';
$salesId = $_SESSION['sales']['id'];

// Pre-sync vouchers
syncHotspotSalesStatus();

ob_start();
?>

<div style="row-gap: 30px; display: flex; flex-direction: column;">
    <!-- Expired Vouchers -->
    <?php $expiredVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'expired' ORDER BY used_at DESC LIMIT 100", [$salesId]); ?>
    <div class="col-md-12">
        <div class="card" style="border-top: 4px solid var(--neon-red);">
            <div class="card-header">
                <h3 class="card-title" style="color: var(--neon-red);"><i class="fas fa-history"></i> Riwayat Voucher Selesai</h3>
            </div>
            <div class="card-body" style="padding: 20px 0;">
                <div class="table-responsive">
                    <table class="table table-hover" id="expiredTable">
                        <thead>
                            <tr>
                                <th>Digunakan</th>
                                <th>Username</th>
                                <th>Perangkat Akhir</th>
                                <th>Durasi Pakai</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiredVouchers as $v): ?>
                                <tr>
                                    <td>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary);"><?php echo date('d/m/Y', strtotime($v['used_at'])); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('H:i', strtotime($v['used_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div style="color: var(--text-muted);"><?php echo htmlspecialchars($v['username']); ?></div>
                                        <div style="font-size: 0.7rem;"><?php echo htmlspecialchars($v['profile']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.8rem; font-family: monospace;"><?php echo htmlspecialchars($v['mac_address'] ?? '-'); ?></div>
                                    </td>
                                    <td><span style="font-weight: 600;"><?php echo htmlspecialchars($v['uptime'] ?? '-'); ?></span></td>
                                    <td><span class="badge badge-danger" style="opacity: 0.7;">Expired</span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($expiredVouchers)): ?>
                                <tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">Belum ada riwayat voucher kadaluarsa.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize SimpleDatatables if available
    document.addEventListener('DOMContentLoaded', () => {
        const activeTable = document.getElementById('activeTable');
        if (activeTable) {
            new simpleDatatables.DataTable(activeTable);
        }
        const inactiveTable = document.getElementById('inactiveTable');
        if (inactiveTable) {
            new simpleDatatables.DataTable(inactiveTable);
        }
        const expiredTable = document.getElementById('expiredTable');
        if (expiredTable) {
            new simpleDatatables.DataTable(expiredTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
