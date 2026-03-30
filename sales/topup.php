<?php
/**
 * Sales Topup Portal
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Topup Saldo';
$salesId = $_SESSION['sales']['id'];

// Get individual settings
$enableTripay = getSettingValue('ENABLE_TRIPAY_SALES', '1') === '1';
$enableManual = getSettingValue('ENABLE_MANUAL_SALES', '1') === '1';
$manualInfo = getSettingValue('MANUAL_PAYMENT_INFO', '');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['amount'])) {
    $amount = (float)$_POST['amount'];
    $method = $_POST['method'] ?? 'manual';
    
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
                $res = generateTripayPaymentLink(
                    "TOPUP-" . $topupId,
                    $amount,
                    $sales['name'],
                    $sales['phone'] ?? '08123456789',
                    date('Y-m-d H:i:s', strtotime('+1 day'))
                );
                
                if ($res['success']) {
                    header("Location: " . $res['link']);
                    exit;
                } else {
                    setFlash('error', 'Gagal membuat link pembayaran: ' . $res['message']);
                }
            } else {
                setFlash('success', 'Permintaan topup manual berhasil dibuat. Silakan lakukan transfer.');
            }
        } else {
            setFlash('error', 'Gagal membuat permintaan topup');
        }
    }
    redirect('topup.php');
}

// Get history
$history = fetchAll("SELECT * FROM sales_topups WHERE sales_user_id = ? ORDER BY created_at DESC LIMIT 10", [$salesId]);

ob_start();
?>

<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 25px;">
    <!-- Topup Form -->
    <div>
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
                            <?php if ($enableTripay): ?>
                            <label style="display: flex; align-items: center; gap: 12px; padding: 15px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; transition: all 0.3s;" class="method-option">
                                <input type="radio" name="method" value="tripay" checked style="width: 18px; height: 18px;">
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; color: var(--neon-cyan);">Otomatis (Tripay)</div>
                                    <small style="color: var(--text-muted);">QRIS, VA, Bank Transfer (Instan)</small>
                                </div>
                                <i class="fas fa-bolt" style="color: var(--neon-cyan);"></i>
                            </label>
                            <?php endif; ?>
                            
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
    <div>
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
                                    <span class="badge badge-warning"><i class="fas fa-clock"></i> PENDING</span>
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

<style>
.method-option:has(input:checked) {
    border-color: var(--neon-cyan) !important;
    background: rgba(0, 245, 255, 0.1) !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const manualRadio = document.querySelector('input[value="manual"]');
    const tripayRadio = document.querySelector('input[value="tripay"]');
    const manualInfo = document.getElementById('manual-info');
    
    function updateInfo() {
        if (manualRadio && manualRadio.checked) {
            manualInfo.style.display = 'block';
        } else if (manualInfo) {
            manualInfo.style.display = 'none';
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
