<?php
/**
 * Sales Topup History
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Riwayat Topup';
$salesId = $_SESSION['sales']['id'];

// Handle Proof Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_proof') {
    $id = (int)$_POST['id'];
    $topup = fetchOne("SELECT * FROM sales_topups WHERE id = ? AND sales_user_id = ?", [$id, $salesId]);
    
    if ($topup && $topup['status'] === 'pending' && isset($_FILES['proof'])) {
        $file = $_FILES['proof'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png'];

        if (in_array($ext, $allowed)) {
            $uploadDir = '../uploads/proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileName = 'topup_' . $id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                update('sales_topups', [
                    'payment_proof' => 'uploads/proofs/' . $fileName,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);
                setFlash('success', 'Bukti transfer berhasil diunggah.');
            } else {
                setFlash('error', 'Gagal mengunggah file.');
            }
        } else {
            setFlash('error', 'Format file tidak didukung.');
        }
    }
    redirect('topup-history.php');
}

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
                            <div style="display: flex; flex-direction: column; gap: 5px; align-items: start;">
                                <span class="badge badge-warning">PENDING</span>
                                <?php if ($t['payment_method'] === 'manual'): ?>
                                    <?php if (empty($t['payment_proof'])): ?>
                                        <button onclick="openUploadModal(<?php echo $t['id']; ?>)" class="btn btn-sm btn-info" style="font-size: 0.7rem; padding: 3px 6px;">
                                            <i class="fas fa-upload"></i> Upload Bukti
                                        </button>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="font-size: 0.7rem; cursor: pointer;" onclick="viewProof('<?php echo APP_URL . '/' . $t['payment_proof']; ?>')">
                                            <i class="fas fa-image"></i> Bukti Terkirim
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($t['payment_method'] === 'tripay'): ?>
                                    <a href="<?php echo APP_URL; ?>/api/repay_topup.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary" style="font-size: 0.7rem; padding: 3px 6px;">
                                        <i class="fas fa-external-link-alt"></i> Bayar
                                    </a>
                                <?php endif; ?>
                            </div>
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

<!-- Upload Modal -->
<div id="uploadModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div class="modal-content" style="background: var(--bg-card); margin: 15% auto; padding: 25px; width: 90%; max-width: 400px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        <h3 style="color: var(--neon-cyan); margin-top: 0; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-file-upload"></i> Upload Bukti Transfer
        </h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_proof">
            <input type="hidden" name="id" id="upload_id">
            
            <div class="form-group" style="margin: 20px 0;">
                <label style="display: block; margin-bottom: 8px; font-size: 0.9rem;">Pilih Foto Bukti (JPG/PNG)</label>
                <input type="file" name="proof" class="form-control" accept="image/*" required 
                       style="background: rgba(255,255,255,0.02); border: 1px dashed var(--border-color); padding: 10px;">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Kirim</button>
            </div>
        </form>
    </div>
</div>

<!-- Image View Modal -->
<div id="viewModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1001; align-items: center; justify-content: center;">
    <span style="position: absolute; top: 20px; right: 30px; font-size: 30px; color: #fff; cursor: pointer;" onclick="closeViewModal()">&times;</span>
    <img id="img_preview" style="max-width: 90%; max-height: 90%; border-radius: 8px;">
</div>

<script>
function openUploadModal(id) {
    document.getElementById('upload_id').value = id;
    document.getElementById('uploadModal').style.display = 'block';
}

function viewProof(url) {
    document.getElementById('img_preview').src = url;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('uploadModal').style.display = 'none';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('uploadModal')) closeModal();
    if (event.target == document.getElementById('viewModal')) closeViewModal();
}

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
