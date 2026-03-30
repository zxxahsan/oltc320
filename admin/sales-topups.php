<?php
/**
 * Sales Topup Management
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Manajemen Topup Sales';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];
    
    $topup = fetchOne("SELECT * FROM sales_topups WHERE id = ?", [$id]);
    
    if ($topup && $topup['status'] === 'pending') {
        if ($action === 'revisi_approve') {
            $amount = (float)$_POST['amount'];
            if ($amount <= 0) {
                setFlash('error', 'Jumlah harus lebih dari 0');
            } else {
                try {
                    beginTransaction();
                    
                    // Update topup status and amount
                    update('sales_topups', [
                        'amount' => $amount,
                        'status' => 'paid',
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$id]);
                    
                    // Add balance to sales user
                    $pdo = getDB();
                    $stmt = $pdo->prepare("UPDATE sales_users SET deposit_balance = deposit_balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $topup['sales_user_id']]);
                    
                    // Record Transaction
                    insert('sales_transactions', [
                        'sales_user_id' => $topup['sales_user_id'],
                        'type' => 'deposit',
                        'amount' => $amount,
                        'description' => 'Topup (Revisi Admin): ID ' . $id,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    logActivity('ADMIN_REVISI_APPROVE_TOPUP', "Topup ID: {$id}, Amount: {$amount}, Prev: {$topup['amount']}");
                    
                    commit();
                    setFlash('success', 'Topup berhasil direvisi dan disetujui');
                } catch (Exception $e) {
                    rollback();
                    setFlash('error', 'Gagal memproses: ' . $e->getMessage());
                }
            }
        }
    }
    redirect('sales-topups.php');
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    $topup = fetchOne("SELECT * FROM sales_topups WHERE id = ?", [$id]);
    
    if ($topup && $topup['status'] === 'pending') {
        if ($action === 'approve') {
            // ... existing approve logic ...
            try {
                beginTransaction();
                update('sales_topups', ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                $pdo = getDB();
                $stmt = $pdo->prepare("UPDATE sales_users SET deposit_balance = deposit_balance + ? WHERE id = ?");
                $stmt->execute([$topup['amount'], $topup['sales_user_id']]);
                
                // Also record in transactions
                insert('sales_transactions', [
                    'sales_user_id' => $topup['sales_user_id'],
                    'type' => 'deposit',
                    'amount' => $topup['amount'],
                    'description' => 'Topup (Approve Admin): ID ' . $id,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                logActivity('ADMIN_APPROVE_TOPUP', "Topup ID: {$id}, Sales ID: {$topup['sales_user_id']}, Amount: {$topup['amount']}");
                commit();
                setFlash('success', 'Topup berhasil disetujui');
            } catch (Exception $e) {
                rollback();
                setFlash('error', 'Gagal memproses topup: ' . $e->getMessage());
            }
        } elseif ($action === 'cancel') {
            update('sales_topups', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
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
                            <div style="display: flex; flex-direction: column; gap: 5px; align-items: start;">
                                <span class="badge badge-warning">PENDING</span>
                                <?php if (!empty($t['payment_proof'])): ?>
                                    <button class="btn btn-sm btn-info" style="font-size: 0.7rem; padding: 3px 6px;" 
                                            onclick="viewProof('<?php echo APP_URL . '/' . $t['payment_proof']; ?>')">
                                        <i class="fas fa-image"></i> Lihat Bukti
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-danger">DIBATALKAN</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Aksi">
                        <?php if ($t['status'] === 'pending'): ?>
                            <div style="display: flex; gap: 5px;">
                                <a href="?action=approve&id=<?php echo $t['id']; ?>" 
                                   class="btn btn-sm btn-success" 
                                   onclick="return confirm('Setujui topup ini?')">
                                    <i class="fas fa-check"></i> Approve
                                </a>
                                <button type="button" class="btn btn-sm btn-info" onclick='showEditModal(<?php echo json_encode($t); ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <a href="?action=cancel&id=<?php echo $t['id']; ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Batalkan permintaan ini?')">
                                    <i class="fas fa-times"></i> Batalkan
                                </a>
                            </div>
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

<!-- Edit Modal -->
<div id="editModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div class="modal-content" style="background: var(--bg-card); margin: 10% auto; padding: 20px; width: 90%; max-width: 400px; border-radius: 10px; border: 1px solid var(--border-color);">
        <h3>Revisi & Setujui Topup</h3>
        <p>Sales: <strong id="edit_sales_name"></strong></p>
        <form method="POST">
            <input type="hidden" name="action" value="revisi_approve">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Jumlah Revisi (Rp)</label>
                <input type="number" name="amount" id="edit_amount" class="form-control" required min="1">
                <small class="text-muted">Ubah jika jumlah yang ditransfer berbeda.</small>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-success">Sesuai & Approve</button>
            </div>
        </form>
    </div>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1001; align-items: center; justify-content: center;">
    <span style="position: absolute; top: 20px; right: 30px; font-size: 30px; color: #fff; cursor: pointer;" onclick="closeViewModal()">&times;</span>
    <img id="img_preview" style="max-width: 90%; max-height: 90%; border-radius: 8px;">
</div>

<script>
function showEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_sales_name').textContent = data.sales_name;
    document.getElementById('edit_amount').value = data.amount;
    document.getElementById('editModal').style.display = 'block';
}

function viewProof(url) {
    document.getElementById('img_preview').src = url;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    new simpleDatatables.DataTable("#topupsTable", {
        searchable: true,
        fixedHeight: false,
        perPage: 10
    });
});

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target == document.getElementById('editModal')) closeModal();
    if (event.target == document.getElementById('viewModal')) closeViewModal();
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
