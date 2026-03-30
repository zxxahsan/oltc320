<?php
/**
 * Sales Topup Management
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Manajemen Topup Sales';

// Handle Actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    $topup = fetchOne("SELECT * FROM sales_topups WHERE id = ?", [$id]);
    
    if ($topup && $topup['status'] === 'pending') {
        if ($action === 'approve') {
            // Transactional update
            try {
                beginTransaction();
                
                // Update topup status
                update('sales_topups', [
                    'status' => 'paid',
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);
                
                // Add balance
                $pdo = getDB();
                $stmt = $pdo->prepare("UPDATE sales_users SET deposit_balance = deposit_balance + ? WHERE id = ?");
                $stmt->execute([$topup['amount'], $topup['sales_user_id']]);
                
                logActivity('ADMIN_APPROVE_TOPUP', "Topup ID: {$id}, Sales ID: {$topup['sales_user_id']}, Amount: {$topup['amount']}");
                
                commit();
                setFlash('success', 'Topup berhasil disetujui');
            } catch (Exception $e) {
                rollback();
                setFlash('error', 'Gagal memproses topup: ' . $e->getMessage());
            }
        } elseif ($action === 'cancel') {
            update('sales_topups', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);
            setFlash('success', 'Permintaan topup berhasil dibatalkan');
        }
    }
    redirect('sales-topups.php');
}

// Get topups
$topups = fetchAll("
    SELECT t.*, s.name as sales_name, s.username as sales_username 
    FROM sales_topups t 
    JOIN sales_users s ON t.sales_user_id = s.id 
    ORDER BY t.created_at DESC
");

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-wallet"></i> Riwayat Topup Sales</h3>
    </div>
    
    <div class="table-responsive">
        <table class="data-table" id="topupsTable">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Sales</th>
                    <th>Jumlah</th>
                    <th>Metode</th>
                    <th>Referensi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topups as $t): ?>
                <tr>
                    <td data-label="Waktu">
                        <?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?>
                    </td>
                    <td data-label="Sales">
                        <strong><?php echo htmlspecialchars($t['sales_name']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($t['sales_username']); ?></small>
                    </td>
                    <td data-label="Jumlah">
                        <?php echo formatCurrency($t['amount']); ?>
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
                    <td data-label="Aksi">
                        <?php if ($t['status'] === 'pending' && $t['payment_method'] === 'manual'): ?>
                            <div style="display: flex; gap: 5px;">
                                <a href="?action=approve&id=<?php echo $t['id']; ?>" 
                                   class="btn btn-sm btn-success" 
                                   onclick="return confirm('Setujui topup manual ini?')">
                                    <i class="fas fa-check"></i> Approve
                                </a>
                                <a href="?action=cancel&id=<?php echo $t['id']; ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Batalkan permintaan ini?')">
                                    <i class="fas fa-times"></i> Tolak
                                </a>
                            </div>
                        <?php elseif ($t['status'] === 'pending' && $t['payment_method'] === 'tripay'): ?>
                             <a href="?action=cancel&id=<?php echo $t['id']; ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Batalkan permintaan ini?')">
                                    <i class="fas fa-times"></i> Batalkan
                             </a>
                        <?php else: ?>
                            -
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
    const dataTable = new simpleDatatables.DataTable("#topupsTable", {
        searchable: true,
        fixedHeight: false,
        perPage: 10
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
