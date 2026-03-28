<?php
/**
 * Sales Voucher Aktif
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Voucher Aktif';
$salesId = $_SESSION['sales']['id'];

// Pre-sync vouchers
syncHotspotSalesStatus();

// Get Active Vouchers
$activeVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'active' ORDER BY used_at DESC", [$salesId]);

ob_start();
?>

<div class="row" style="display: flex; flex-direction: column; gap: 20px;">
    <!-- Active Vouchers -->
    <div class="col-md-12">
        <div class="card" style="border-top: 3px solid var(--neon-green);">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-check-circle" style="color: var(--neon-green);"></i> Voucher Aktif (Digunakan)</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="activeTable">
                        <thead>
                            <tr>
                                <th>Tanggal Aktif</th>
                                <th>Username</th>
                                <th>Profile</th>
                                <th>Harga Jual</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeVouchers as $v): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($v['used_at'])); ?></td>
                                    <td><strong style="color: var(--neon-cyan);"><?php echo htmlspecialchars($v['username']); ?></strong></td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                    <td class="text-success"><?php echo formatCurrency($v['selling_price']); ?></td>
                                    <td>
                                        <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print Ulang">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($activeVouchers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Belum ada voucher yang digunakan pelanggan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const activeTable = document.getElementById('activeTable');
        if (activeTable) {
            new simpleDatatables.DataTable(activeTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
