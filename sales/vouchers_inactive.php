<?php
/**
 * Sales Voucher Belum Aktif
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Voucher Belum Aktif';
$salesId = $_SESSION['sales']['id'];

// Pre-sync vouchers
syncHotspotSalesStatus();

// Get Inactive Vouchers
$inactiveVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'inactive' ORDER BY created_at DESC", [$salesId]);

ob_start();
?>

<div class="row" style="display: flex; flex-direction: column; gap: 20px;">
    <!-- Inactive Vouchers -->
    <div class="col-md-12">
        <div class="card" style="border-top: 3px solid var(--text-muted);">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clock" style="color: var(--text-muted);"></i> Voucher Belum Aktif (Tersedia)</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="inactiveTable">
                        <thead>
                            <tr>
                                <th>Tanggal Generate</th>
                                <th>Username</th>
                                <th>Profile</th>
                                <th>Modal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactiveVouchers as $v): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($v['created_at'])); ?></td>
                                    <td><strong><?php echo htmlspecialchars($v['username']); ?></strong></td>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                    <td class="text-warning"><?php echo formatCurrency($v['price']); ?></td>
                                    <td>
                                        <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-info">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($inactiveVouchers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">Tidak ada voucher standby.</td></tr>
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
        const inactiveTable = document.getElementById('inactiveTable');
        if (inactiveTable) {
            new simpleDatatables.DataTable(inactiveTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
