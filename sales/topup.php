<?php
/**
 * Sales Topup Portal
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Topup Saldo';
$salesId = $_SESSION['sales']['id'];

// Get individual settings
$enableTripay = getSetting('ENABLE_TRIPAY_SALES', '1') === '1';
$enableManual = getSetting('ENABLE_MANUAL_SALES', '1') === '1';
$manualInfo = getSetting('MANUAL_PAYMENT_INFO', '');
require_once '../includes/payment.php';
$paymentMethods = getTripayChannels();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Initial Topup Request
    if (isset($_POST['amount'])) {
        $amount = (float)$_POST['amount'];
        $method = $_POST['method'] ?? 'manual';
        $tripayMethod = $_POST['tripay_method'] ?? '';
        
        if ($amount < 50000) {
            setFlash('error', 'Minimal topup adalah Rp 50.000');
        } elseif ($method === 'tripay' && !$enableTripay) {
            setFlash('error', 'Metode Tripay sedang tidak tersedia');
        } elseif ($method === 'manual' && !$enableManual) {
            setFlash('error', 'Metode Manual sedang tidak tersedia');
        } else {
            // Create topup record
            $topupId = insert('sales_topups', [
                'sales_user_id' => $salesId,
                'amount' => $amount,
                'payment_method' => $method,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            if ($topupId) {
                if ($method === 'tripay') {
                    require_once '../includes/payment.php';
                    $sales = getSalesUser($salesId);
                    // Updated Tripay Integration logic could go here if needed
                    $res = generateTripayPaymentLink(
                        "TOPUP-" . $topupId,
                        $amount,
                        $sales['name'],
                        $sales['phone'] ?? '08123456789',
                        date('Y-m-d H:i:s', strtotime('+1 day')),
                        $tripayMethod
                    );
                    
                    if ($res['success']) {
                        header("Location: " . $res['link']);
                        exit;
                    } else {
                        setFlash('error', 'Gagal membuat link pembayaran: ' . $res['message']);
                    }
                } else {
                    setFlash('success', 'Permintaan topup manual berhasil dibuat. Silakan upload bukti transfer.');
                }
            } else {
                setFlash('error', 'Gagal membuat permintaan topup');
            }
        }
        redirect('topup.php');
    }

    // 2. Proof Upload
    if (isset($_POST['action']) && $_POST['action'] === 'upload_proof') {
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
                    setFlash('success', 'Bukti transfer berhasil diunggah. Mohon tunggu verifikasi Admin.');
                } else {
                    setFlash('error', 'Gagal mengunggah file.');
                }
            } else {
                setFlash('error', 'Format file tidak didukung (Gunakan JPG/PNG).');
            }
        }
        redirect('topup.php');
    }
}

// Get history
$history = fetchAll("SELECT * FROM sales_topups WHERE sales_user_id = ? ORDER BY created_at DESC LIMIT 10", [$salesId]);

ob_start();
?>

<div style="display: flex; flex-wrap: wrap; gap: 25px; align-items: flex-start;">
    <!-- Topup Form -->
    <div style="flex: 1; min-width: 320px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Isi Saldo</h3>
            </div>
            
            <?php if (!$enableTripay && !$enableManual): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    Maaf, layanan topup sedang dinonaktifkan oleh Admin.
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Jumlah Topup (IDR)</label>
                        <input type="number" name="amount" class="form-control" placeholder="Minimal 50000" min="50000" required>
                        <small style="color: var(--text-muted);">Minimal Rp 50.000</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran</label>
                        <div style="display: grid; gap: 10px;">
                            <div id="tripay-selection" style="display: <?php echo $enableTripay ? 'block' : 'none'; ?>; border: 1px solid var(--border-color); border-radius: 10px; padding: 15px; background: rgba(0,0,0,0.2);">
                                <label class="form-label">Pilih Channel Tripay (Otomatis)</label>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; margin-top: 10px;">
                                    <?php if (empty($paymentMethods)): ?>
                                        <div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                                            Gagal mengambil channel dari Tripay.
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($paymentMethods as $m): ?>
                                            <label class="tripay-option" style="border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.3s; display: block;">
                                                <input type="radio" name="tripay_method" value="<?php echo $m['code']; ?>" style="display: none;">
                                                <img src="<?php echo $m['icon_url']; ?>" alt="<?php echo $m['name']; ?>" style="height: 25px; margin-bottom: 5px;">
                                                <div style="font-size: 0.75rem; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    <?php echo $m['name']; ?>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="method" value="tripay">
                            </div>
                            
                            <?php if ($enableManual): ?>
                            <label style="display: flex; align-items: center; gap: 12px; padding: 15px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; transition: all 0.3s;" class="method-option">
                                <input type="radio" name="method" value="manual" <?php echo !$enableTripay ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; color: var(--neon-purple);">Manual (Transfer Bank)</div>
                                    <small style="color: var(--text-muted);">Konfirmasi manual oleh Admin</small>
                                </div>
                                <i class="fas fa-university" style="color: var(--neon-purple);"></i>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($enableManual): ?>
                    <div id="manual-info" style="display: none; background: rgba(191,0,255,0.05); border: 1px solid var(--neon-purple); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                        <div style="font-weight: 600; color: var(--neon-purple); margin-bottom: 8px;">
                            <i class="fas fa-info-circle"></i> Instruksi Pembayaran:
                        </div>
                        <div style="white-space: pre-wrap; font-size: 0.9rem; color: var(--text-secondary);"><?php echo htmlspecialchars($manualInfo); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 15px; font-size: 1rem;">
                        <i class="fas fa-paper-plane"></i> Lanjutkan Pembayaran
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Topup History -->
    <div style="flex: 1.5; min-width: 350px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history"></i> Riwayat Topup Terakhir</h3>
            </div>
            
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Jumlah</th>
                            <th>Metode</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                Belum ada riwayat topup.
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td data-label="Waktu">
                                <?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?>
                            </td>
                            <td data-label="Jumlah">
                                <strong><?php echo formatCurrency($h['amount']); ?></strong>
                            </td>
                            <td data-label="Metode">
                                <span class="badge badge-info"><?php echo strtoupper($h['payment_method']); ?></span>
                            </td>
                            <td data-label="Status">
                                <?php if ($h['status'] === 'paid'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> BERHASIL</span>
                                <?php elseif ($h['status'] === 'pending'): ?>
                                    <div style="display: flex; flex-direction: column; gap: 5px; align-items: start;">
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> PENDING</span>
                                        <?php if ($h['payment_method'] === 'manual'): ?>
                                            <?php if (empty($h['payment_proof'])): ?>
                                                <button onclick="openUploadModal(<?php echo $h['id']; ?>)" class="btn btn-sm btn-info" style="font-size: 0.75rem; padding: 4px 8px;">
                                                    <i class="fas fa-upload"></i> Upload Bukti
                                                </button>
                                            <?php else: ?>
                                                <span class="badge badge-info" style="font-size: 0.7rem; cursor: pointer;" onclick="viewProof('<?php echo APP_URL . '/' . $h['payment_proof']; ?>')">
                                                    <i class="fas fa-image"></i> Bukti Terkirim
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($h['payment_method'] === 'tripay'): ?>
                                            <a href="<?php echo APP_URL; ?>/api/repay_topup.php?id=<?php echo $h['id']; ?>" class="btn btn-sm btn-primary" style="font-size: 0.75rem; padding: 4px 8px;">
                                                <i class="fas fa-external-link-alt"></i> Bayar Sekarang
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> BATAL</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                <small style="color: var(--text-muted);">Pastikan tulisan terlihat jelas.</small>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Kirim Bukti
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Image View Modal -->
<div id="viewModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1001; align-items: center; justify-content: center;">
    <span style="position: absolute; top: 20px; right: 30px; font-size: 30px; color: #fff; cursor: pointer;" onclick="closeViewModal()">&times;</span>
    <img id="img_preview" style="max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 0 20px rgba(0,0,0,0.5);">
</div>

<style>
.method-option:has(input:checked) {
    border-color: var(--neon-cyan) !important;
    background: rgba(0, 245, 255, 0.1) !important;
}
.tripay-option:has(input:checked) {
    border-color: var(--neon-cyan) !important;
    background: rgba(0, 245, 255, 0.05) !important;
    box-shadow: 0 0 10px rgba(0, 245, 255, 0.2);
}
</style>

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

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target == document.getElementById('uploadModal')) closeModal();
    if (event.target == document.getElementById('viewModal')) closeViewModal();
}

document.addEventListener('DOMContentLoaded', function() {
    const manualRadio = document.querySelector('input[value="manual"]');
    const tripayRadio = document.querySelector('input[value="tripay"]');
    const manualInfo = document.getElementById('manual-info');
    
    function updateInfo() {
        if (manualRadio && manualRadio.checked) {
            manualInfo.style.display = 'block';
            if (document.getElementById('tripay-selection')) document.getElementById('tripay-selection').style.display = 'none';
        } else if (manualInfo) {
            manualInfo.style.display = 'none';
            if (document.getElementById('tripay-selection')) document.getElementById('tripay-selection').style.display = 'block';
        }
    }
    
    if (manualRadio) manualRadio.addEventListener('change', updateInfo);
    if (tripayRadio) tripayRadio.addEventListener('change', updateInfo);
    
    updateInfo();
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
