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
$channelResult = getTripayChannels();
$paymentMethods = ($channelResult['success'] ?? false) ? $channelResult['data'] : [];
$channelError = !($channelResult['success'] ?? false) ? ($channelResult['message'] ?? 'Unable to fetch channels') : null;

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
                        $tripayMethod,
                        "Top Up Saldo"
                    );
                    
                    if ($res['success']) {
                        // Use the new robust redirector to fix ShopeePay/Dana deep-link issues
                        $redirectUrl = APP_URL . "/payment_redirect.php?url=" . urlencode($res['link']) . "&qr=" . urlencode($res['qr_url']) . "&pay=" . urlencode($res['pay_url']);
                        header("Location: " . $redirectUrl);
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

<?php
$sales = getSalesUser($salesId);
?>

<div style="display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px;">
    <!-- Sales Context Card -->
    <div class="card" style="flex: 1; min-width: 300px; background: linear-gradient(135deg, rgba(191,0,255,0.1) 0%, rgba(0,245,255,0.05) 100%); border: 1px solid rgba(0, 245, 255, 0.2);">
        <div class="card-body" style="display: flex; align-items: center; gap: 20px;">
            <div style="width: 60px; height: 60px; background: var(--neon-purple); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #fff; box-shadow: 0 0 15px rgba(191, 0, 255, 0.5);">
                <i class="fas fa-user-tie"></i>
            </div>
            <div>
                <div style="font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">Topup Saldo Atas Nama:</div>
                <h2 style="margin: 5px 0; color: var(--text-primary); font-size: 1.4rem;"><?php echo htmlspecialchars($sales['name']); ?></h2>
                <div style="display: flex; gap: 15px; font-size: 0.9rem;">
                    <span><i class="fas fa-wallet" style="color: var(--neon-cyan);"></i> Saldo: <strong style="color: var(--neon-cyan);"><?php echo formatCurrency($sales['balance']); ?></strong></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div style="display: flex; flex-wrap: wrap; gap: 25px; align-items: flex-start;">
    <!-- Topup Form -->
    <div style="flex: 1; min-width: 320px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Buat Permintaan Topup</h3>
            </div>
            
            <?php if (!$enableTripay && !$enableManual): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    Maaf, layanan topup sedang dinonaktifkan oleh Admin.
                </div>
            <?php else: ?>
                <form method="POST" id="topupForm">
                    <div class="form-group">
                        <label class="form-label">Nominal Topup (IDR)</label>
                        <input type="number" name="amount" class="form-control" placeholder="Contoh: 100000" min="50000" required style="font-size: 1.2rem; font-weight: 700; height: 50px;">
                        <small style="color: var(--text-muted);">Minimal pengisian Rp 50.000</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran</label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 15px;">
                            
                            <?php if ($enableTripay): ?>
                            <div class="selection-card" onclick="selectPaymentGroup('tripay')" id="card-tripay" style="border: 1px solid var(--border-color); border-radius: 12px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--bg-input);">
                                <input type="radio" name="method" value="tripay" id="radio-tripay" style="display: none;" <?php echo $enableTripay ? 'checked' : ''; ?>>
                                <div style="font-size: 1.5rem; color: var(--neon-cyan); margin-bottom: 8px;">
                                    <i class="fas fa-bolt"></i>
                                </div>
                                <div style="font-weight: 600; font-size: 0.9rem;">Otomatis</div>
                                <small style="font-size: 0.65rem; color: var(--text-muted);">Tripay Gateway</small>
                            </div>
                            <?php endif; ?>

                            <?php if ($enableManual): ?>
                            <div class="selection-card" onclick="selectPaymentGroup('manual')" id="card-manual" style="border: 1px solid var(--border-color); border-radius: 12px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--bg-input);">
                                <input type="radio" name="method" value="manual" id="radio-manual" style="display: none;" <?php echo !$enableTripay ? 'checked' : ''; ?>>
                                <div style="font-size: 1.5rem; color: var(--neon-purple); margin-bottom: 8px;">
                                    <i class="fas fa-university"></i>
                                </div>
                                <div style="font-weight: 600; font-size: 0.9rem;">Manual</div>
                                <small style="font-size: 0.65rem; color: var(--text-muted);">Transfer Bank</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Tripay Channels Subset -->
                    <div id="tripay-selection" style="display: none; margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 20px;">
                        <label class="form-label" style="font-size: 0.8rem; color: var(--text-muted);"><i class="fas fa-chevron-right"></i> Pilih Bank / Dompet Digital:</label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; margin-top: 10px;">
                            <?php if ($channelError): ?>
                                <div style="grid-column: 1/-1; text-align: center; color: #ff6b6b; font-size: 0.8rem; padding: 10px; border: 1px solid rgba(255,0,0,0.2); border-radius: 8px;">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($channelError); ?>
                                </div>
                            <?php elseif (empty($paymentMethods)): ?>
                                <div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); font-size: 0.8rem; padding: 10px;">
                                    <i class="fas fa-info-circle"></i> Tidak ada channel aktif.
                                </div>
                            <?php else: ?>
                                <?php foreach ($paymentMethods as $m): ?>
                                    <label class="tripay-option-card" style="border: 1px solid var(--border-color); border-radius: 10px; padding: 12px; text-align: center; cursor: pointer; transition: all 0.3s; display: block; position: relative; background: rgba(255,255,255,0.02);" onclick="selectTripayMethod(this)">
                                        <input type="radio" name="tripay_method" value="<?php echo $m['code']; ?>" style="opacity: 0; position: absolute; width:1px; height:1px;" required>
                                        <img src="<?php echo $m['icon_url']; ?>" alt="<?php echo $m['name']; ?>" style="height: 20px; filter: grayscale(0.2); transition: all 0.3s;">
                                        <div style="font-size: 0.7rem; font-weight: 500; color: var(--text-secondary); margin-top: 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo $m['name']; ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($enableManual): ?>
                    <div id="manual-info" style="display: none; background: rgba(191,0,255,0.05); border: 1px solid rgba(191, 0, 255, 0.3); padding: 15px; border-radius: 12px; margin-top: 20px;">
                        <div style="font-weight: 600; color: var(--neon-purple); margin-bottom: 8px; font-size: 0.9rem;">
                            <i class="fas fa-university"></i> Rekening Tujuan:
                        </div>
                        <div style="white-space: pre-wrap; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6;"><?php echo htmlspecialchars($manualInfo); ?></div>
                        <div style="margin-top: 12px; font-size: 0.75rem; color: var(--text-muted);">* Setelah transfer, silakan lampirkan bukti pada tabel riwayat di bawah.</div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" id="submitBtn" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 15px; font-size: 1rem; margin-top: 25px; box-shadow: 0 4px 15px rgba(0, 245, 255, 0.2);">
                        <i class="fas fa-check-circle"></i> Konfirmasi & Bayar
                    </button>
                    <div id="processing" style="display: none; text-align: center; margin-top: 15px; color: var(--neon-cyan); font-size: 0.9rem;">
                        <i class="fas fa-spinner fa-spin"></i> Menghubungkan ke Gateway...
                    </div>
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
.selection-card.active {
    border-color: var(--neon-cyan) !important;
    background: rgba(0, 245, 255, 0.1) !important;
    box-shadow: 0 0 15px rgba(0, 245, 255, 0.2);
    transform: translateY(-2px);
}
.tripay-option-card.active {
    border-color: var(--neon-cyan) !important;
    background: rgba(0, 245, 255, 0.08) !important;
    box-shadow: 0 0 10px rgba(0, 245, 255, 0.15);
}
.tripay-option-card.active img {
    filter: grayscale(0) !important;
    transform: scale(1.1);
}
</style>

<script>
function selectPaymentGroup(group) {
    document.querySelectorAll('input[name="method"]').forEach(input => {
        input.checked = (input.value === group);
    });
    
    document.querySelectorAll('.selection-card').forEach(card => {
        card.classList.remove('active');
    });
    document.getElementById('card-' + group).classList.add('active');
    
    const manualInfo = document.getElementById('manual-info');
    const tripaySelection = document.getElementById('tripay-selection');
    
    if (group === 'manual') {
        if (manualInfo) manualInfo.style.display = 'block';
        if (tripaySelection) tripaySelection.style.display = 'none';
        document.querySelectorAll('input[name="tripay_method"]').forEach(input => input.required = false);
    } else {
        if (manualInfo) manualInfo.style.display = 'none';
        if (tripaySelection) tripaySelection.style.display = 'block';
        document.querySelectorAll('input[name="tripay_method"]').forEach(input => input.required = true);
    }
}

function selectTripayMethod(el) {
    document.querySelectorAll('input[name="tripay_method"]').forEach(input => { input.checked = false; });
    el.querySelector('input').checked = true;
    
    document.querySelectorAll('.tripay-option-card').forEach(item => { 
        item.classList.remove('active');
    });
    el.classList.add('active');
}

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

// Form Submission handling
document.getElementById('topupForm')?.addEventListener('submit', function() {
    const submitBtn = document.getElementById('submitBtn');
    const processing = document.getElementById('processing');
    if (submitBtn) submitBtn.style.display = 'none';
    if (processing) processing.style.display = 'block';
});

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target == document.getElementById('uploadModal')) closeModal();
    if (event.target == document.getElementById('viewModal')) closeViewModal();
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize default selection
    const defaultGroup = document.querySelector('input[name="method"]:checked')?.value || 'tripay';
    selectPaymentGroup(defaultGroup);
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
