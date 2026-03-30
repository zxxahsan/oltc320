<?php
/**
 * Payment Page - Customer Portal
 */

require_once '../includes/auth.php';
requireCustomerLogin();

$pageTitle = 'Pembayaran';

// Get invoice ID
$invoiceId = (int)($_GET['invoice_id'] ?? 0);

if ($invoiceId === 0) {
    setFlash('error', 'Invoice tidak ditemukan');
    redirect('dashboard.php');
}

// Get invoice details with ownership check (IDOR Protection)
$customerSession = getCurrentCustomer();
$invoice = fetchOne("
    SELECT i.*, c.name as customer_name, c.phone as customer_phone, p.name as package_name 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    LEFT JOIN packages p ON c.package_id = p.id 
    WHERE i.id = ? AND i.customer_id = ?", 
    [$invoiceId, $customerSession['id']]
);

if (!$invoice) {
    setFlash('error', 'Invoice tidak ditemukan');
    redirect('dashboard.php');
}

// Gateway Settings
$gatewayEnabled = getSetting('ENABLE_TRIPAY_CUSTOMER', '1') === '1';
$manualEnabled = getSetting('ENABLE_MANUAL_CUSTOMER', '1') === '1';
$bankInfo = getSetting('MANUAL_PAYMENT_INFO', '');
$defaultGateway = 'tripay';

require_once '../includes/payment.php';

// Get dynamic channels
$channelResult = getTripayChannels();
$paymentMethods = ($channelResult['success'] ?? false) ? $channelResult['data'] : [];
$channelError = !($channelResult['success'] ?? false) ? ($channelResult['message'] ?? 'Unable to fetch channels') : null;

$paymentLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Sesi tidak valid atau telah kadaluarsa. Silakan coba lagi.');
        redirect('payment.php?invoice_id=' . $invoiceId);
    }
    
    $paymentType = $_POST['payment_type'] ?? '';
    
    if ($paymentType === 'manual' && $manualEnabled) {
        if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'Silakan upload bukti transfer yang valid.');
        } else {
            $file = $_FILES['payment_proof'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (!in_array($ext, $allowed)) {
                setFlash('error', 'Format file tidak diizinkan. Hanya JPG, PNG, atau PDF.');
            } else {
                $uploadDir = '../uploads/receipts/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                $filename = 'inv_' . $invoiceId . '_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    update('invoices', [
                        'payment_method' => 'manual',
                        'payment_proof' => 'receipts/' . $filename,
                        'status' => 'pending'
                    ], 'id = ?', [$invoiceId]);
                    
                    setFlash('success', 'Bukti pembayaran berhasil diupload. Silakan tunggu verifikasi admin.');
                    
                    // Notify Admin via WA
                    try {
                        require_once '../includes/whatsapp.php';
                        $adminWa = getSetting('WHATSAPP_ADMIN_PHONE');
                        if ($adminWa) {
                            $msg = "🏦 *Konfirmasi Pembayaran Manual*\n\n";
                            $msg .= "Pelanggan: {$invoice['customer_name']}\n";
                            $msg .= "Invoice: {$invoice['invoice_number']}\n";
                            $msg .= "Nominal: " . formatCurrency($invoice['amount']) . "\n\n";
                            $msg .= "Bukti transfer telah diupload. Silakan cek dashboard admin untuk proses Approve.";
                            sendWhatsAppMessage($adminWa, $msg);
                        }
                    } catch (\Exception $e) {}
                    
                    redirect('dashboard.php');
                    exit;
                } else {
                    setFlash('error', 'Gagal menyimpan file bukti pembayaran.');
                }
            }
        }
    } elseif ($paymentType === 'gateway' && $gatewayEnabled) {
        $selectedPaymentMethod = $_POST['payment_method'] ?? '';
        
        if (empty($selectedPaymentMethod)) {
            setFlash('error', 'Silakan pilih metode pembayaran otomatis');
        } else {
            $billingMonthYear = date('F Y', strtotime($invoice['due_date']));
            $result = generatePaymentLink(
                $invoice['invoice_number'],
                $invoice['amount'],
                $invoice['customer_name'],
                $invoice['customer_phone'],
                $invoice['due_date'],
                $defaultGateway,
                $selectedPaymentMethod,
                "Tagihan " . $billingMonthYear
            );
            
            if ($result['success']) {
                $paymentLink = $result['link'];
                update('invoices', ['payment_method' => $defaultGateway], 'id = ?', [$invoiceId]);
                
                // Direct redirect as requested by user
                header("Location: " . $paymentLink);
                exit;
            } else {
                setFlash('error', $result['message'] ?? 'Gagal generate payment link');
            }
        }
    }
}

ob_start();
?>

<div style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-credit-card"></i> Detail Tagihan</h3>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h4 style="color: var(--neon-cyan);">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h4>
            <p style="color: var(--text-secondary);">Paket: <?php echo htmlspecialchars($invoice['package_name'] ?? '-'); ?></p>
            <p style="color: var(--text-secondary);">Jatuh Tempo: <?php echo formatDate($invoice['due_date']); ?></p>
            <p style="font-size: 1.5rem; font-weight: bold; color: var(--neon-cyan);">
                Total: <?php echo formatCurrency($invoice['amount']); ?>
            </p>
        </div>
        
        <?php if ($invoice['status'] === 'paid'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Invoice ini sudah dibayar!
            </div>
        <?php elseif ($invoice['status'] === 'pending'): ?>
            <div class="alert alert-warning" style="background: rgba(255,165,0,0.1); border: 1px solid orange; color: orange;">
                <i class="fas fa-clock"></i> Bukti Transfer Sedang Diverifikasi Admin.
            </div>
        <?php else: ?>
        
            <?php if (!$gatewayEnabled && !$manualEnabled): ?>
                <div class="alert alert-error">Metode pembayaran belum diaktifkan oleh admin.</div>
            <?php else: ?>
                <!-- Tabs Control -->
                <div style="display: flex; gap: 10px; margin-bottom: 25px;">
                    <?php if ($gatewayEnabled): ?>
                    <button class="btn tab-btn <?php echo $gatewayEnabled ? 'active-tab' : ''; ?>" onclick="switchTab('gateway')" id="btnTabGateway" type="button" style="flex:1;">
                        <i class="fas fa-bolt"></i> Bayar Otomatis (Instant)
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($manualEnabled): ?>
                    <button class="btn tab-btn <?php echo (!$gatewayEnabled && $manualEnabled) ? 'active-tab' : ''; ?>" onclick="switchTab('manual')" id="btnTabManual" type="button" style="flex:1;">
                        <i class="fas fa-university"></i> Transfer Manual
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Gateway Form -->
                <?php if ($gatewayEnabled): ?>
                <div id="tabGateway" style="display: <?php echo $gatewayEnabled ? 'block' : 'none'; ?>;">
                    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin melanjutkan pembayaran otomatis?');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="payment_type" value="gateway">
                        
                        <div class="form-group">
                            <label class="form-label">Pilih Channel Pembayaran</label>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px;">
                                <?php if ($channelError): ?>
                                    <div class="alert alert-error" style="grid-column: 1/-1; background: rgba(255,0,0,0.1); border: 1px solid #ff0000; padding: 15px; border-radius: 8px; color: #ff6b6b; font-size: 0.9rem; margin-bottom: 10px;">
                                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($channelError); ?>
                                    </div>
                                <?php elseif (empty($paymentMethods)): ?>
                                    <div class="alert alert-warning" style="grid-column: 1/-1; background: rgba(255,165,0,0.1); border: 1px solid orange; padding: 15px; border-radius: 8px; color: orange; font-size: 0.9rem;">
                                        <i class="fas fa-info-circle"></i> Tidak ada channel pembayaran aktif yang ditemukan di Tripay.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($paymentMethods as $method): ?>
                                        <div class="payment-method-option" 
                                             style="border: 2px solid var(--border-color); border-radius: 8px; padding: 15px; cursor: pointer; transition: all 0.3s; text-align: center; position: relative;"
                                             onclick="selectPaymentMethod(this, '<?php echo $method['code']; ?>')">
                                            <input type="radio" name="payment_method" value="<?php echo $method['code']; ?>" id="method_<?php echo $method['code']; ?>" style="opacity: 0; position: absolute; width: 1px; height: 1px;" required>
                                            <div style="margin-bottom: 8px;">
                                                <img src="<?php echo $method['icon_url']; ?>" alt="<?php echo $method['name']; ?>" style="height: 30px; filter: drop-shadow(0 0 5px rgba(255,255,255,0.2));">
                                            </div>
                                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-primary);">
                                                <?php echo $method['name']; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-credit-card"></i> Lanjut Pembayaran Otomatis
                        </button>
                    </form>
                    
                    <?php if ($paymentLink): ?>
                        <div style="margin-top: 30px; padding: 20px; background: rgba(0, 245, 255, 0.1); border: 1px solid var(--neon-cyan); border-radius: 8px;">
                            <h4 style="color: var(--neon-cyan); margin-bottom: 15px;">
                                <i class="fas fa-external-link-alt"></i> Link Pembayaran
                            </h4>
                            <p style="color: var(--text-secondary); margin-bottom: 15px;">
                                Silakan klik link di bawah ini untuk melanjutkan:
                            </p>
                            <a href="<?php echo htmlspecialchars($paymentLink); ?>" target="_blank" class="btn btn-primary" style="display: inline-block; text-decoration: none; text-align: center;">
                                <i class="fas fa-external-link-alt"></i> Buka Layar Pembayaran
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Manual Form -->
                <?php if ($manualEnabled): ?>
                <div id="tabManual" style="display: <?php echo (!$gatewayEnabled && $manualEnabled) ? 'block' : 'none'; ?>;">
                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Apakah bukti transfer yang Anda pilih sudah benar?');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                        <input type="hidden" name="payment_type" value="manual">
                        
                        <div style="background: rgba(0,0,0,0.3); padding: 20px; border-radius: 8px; border: 1px dashed var(--neon-cyan); margin-bottom: 20px;">
                            <h4 style="color: var(--neon-cyan); margin-bottom: 10px;">Instruksi Transfer:</h4>
                            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 10px;">Silakan transfer sesuai Nominal Invoice (<b><?php echo formatCurrency($invoice['amount']); ?></b>) ke salah satu rekening berikut:</p>
                            <pre style="background: transparent; color: var(--text-primary); font-family: monospace; font-size: 1rem; white-space: pre-wrap; margin: 0; padding: 10px; border-left: 3px solid #00ff00; background: rgba(0,255,0,0.05);"><?php echo htmlspecialchars($bankInfo); ?></pre>
                        </div>

                        <div class="form-group" style="margin-top: 15px;">
                            <label class="form-label" style="font-weight: bold; color: var(--neon-green);"><i class="fas fa-upload"></i> Upload Bukti Transaksi (Wajib)</label>
                            <input type="file" name="payment_proof" accept="image/*,.pdf" required
                                style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); cursor: pointer;">
                            <small style="color: var(--text-muted); display:block; margin-top:5px;">Foto Struk ATM, Screenshot M-Banking, atau struk minimarket. Format JPG/PNG/PDF max 5MB.</small>
                        </div>
                        
                        <button type="submit" class="btn" style="width: 100%; background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-paper-plane"></i> Kirim Bukti Transfer
                        </button>
                    </form>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>
        
        <div style="margin-top: 40px; text-align: center; font-size: 0.8rem; color: var(--text-secondary);">
            Dengan melakukan pembayaran, Anda menyetujui <a href="#" onclick="openModal('tosModal'); return false;" style="color: var(--neon-cyan);">Syarat & Ketentuan</a>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </a>
        </div>
    </div>
</div>

<!-- TOS Modal omitted for brevity, logic remains the same -->
<div id="tosModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 600px; max-width: 90%; max-height: 80vh; overflow-y: auto; padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="color: var(--neon-cyan);">Syarat & Ketentuan</h3>
            <button onclick="closeModal('tosModal')" style="background: none; border: none; color: var(--text-secondary); font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div style="color: var(--text-primary); line-height: 1.6;">
            <p>1. Transaksi Manual wajib direview oleh tim Admin dan akan diproses maksimal 1x24 jam.</p>
            <p>2. Pastikan nominal transfer sesuai agar proses aktivasi berjalan lancar.</p>
        </div>
    </div>
</div>

<style>
.card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
.card-title { font-size: 1.1rem; font-weight: 600; color: var(--neon-cyan); }
.form-group { margin-bottom: 20px; }
.form-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-secondary); }
.btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; color: #fff; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); transition: all 0.3s; display: inline-block; text-decoration: none; }
.btn-secondary { background: transparent; border: 1px solid var(--border-color); color: var(--text-primary); }
.alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid #00ff00; color: #00ff00; }
.alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid #ff0000; color: #ff0000; }

.tab-btn { background: rgba(255,255,255,0.05); color: var(--text-primary); border: 1px solid transparent; }
.tab-btn.active-tab { background: linear-gradient(135deg, #00f5ff 0%, #0088cc 100%); border-color: var(--neon-cyan); color: #fff; box-shadow: 0 0 15px rgba(0,245,255,0.3); }
</style>

<script>
function selectPaymentMethod(el, methodCode) {
    document.querySelectorAll('input[name="payment_method"]').forEach(input => { input.checked = false; });
    document.getElementById('method_' + methodCode).checked = true;
    document.querySelectorAll('.payment-method-option').forEach(item => { item.style.borderColor = 'var(--border-color)'; });
    el.style.borderColor = 'var(--neon-cyan)';
}

function switchTab(tabName) {
    if(document.getElementById('tabGateway')) document.getElementById('tabGateway').style.display = 'none';
    if(document.getElementById('tabManual')) document.getElementById('tabManual').style.display = 'none';
    
    if(document.getElementById('btnTabGateway')) document.getElementById('btnTabGateway').classList.remove('active-tab');
    if(document.getElementById('btnTabManual')) document.getElementById('btnTabManual').classList.remove('active-tab');
    
    if(tabName === 'gateway') {
        document.getElementById('tabGateway').style.display = 'block';
        document.getElementById('btnTabGateway').classList.add('active-tab');
    } else {
        document.getElementById('tabManual').style.display = 'block';
        document.getElementById('btnTabManual').classList.add('active-tab');
    }
}

function openModal(modalId) { document.getElementById(modalId).style.display = 'flex'; }
function closeModal(modalId) { document.getElementById(modalId).style.display = 'none'; }
window.onclick = function(e) { if (e.target.id === 'tosModal') closeModal('tosModal'); }
</script>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
