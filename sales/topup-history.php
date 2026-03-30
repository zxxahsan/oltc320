<?php
/**
 * Sales Topup History
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Riwayat Topup';
$salesId = $_SESSION['sales']['id'];

// Get topup history
$topups = fetchAll("
    SELECT * FROM sales_topups 
    WHERE sales_user_id = ? 
    ORDER BY created_at DESC
", [$salesId]);

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-history"></i> Riwayat Topup Saya</h3>
        <a href="topup.php" class="btn btn-sm btn-primary">
            <i class="fas fa-plus"></i> Topup Baru
        </a>
    </div>
    
    <div class="table-responsive">
        <table class="data-table" id="historyTable">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Jumlah</th>
                    <th>Metode</th>
                    <th>Referensi</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topups as $t): ?>
                <tr>
                    <td data-label="Waktu">
                        <?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?>
                    </td>
                    <td data-label="Jumlah">
                        <strong><?php echo formatCurrency($t['amount']); ?></strong>
                    </td>
                    <td data-label="Metode">
                        <span class="badge badge-info">
                            <?php echo strtoupper($t['payment_method']); ?>
                        </span>
                    </td>
                    <td data-label="Referensi">
                        <?php echo htmlspecialchars($t['payment_reference'] ?? '-'); ?>
                    </td>
                    <td data-label="Status">
                        <?php if ($t['status'] === 'paid'): ?>
                            <span class="badge badge-success">SUKSES</span>
                        <?php elseif ($t['status'] === 'pending'): ?>
                            <span class="badge badge-warning">PENDING</span>
                        <?php else: ?>
                            <span class="badge badge-danger">DIBATALKAN</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    new simpleDatatables.DataTable("#historyTable", {
        searchable: true,
        fixedHeight: false,
        perPage: 10
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
