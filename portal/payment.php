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

// Get invoice details
$invoice = fetchOne("SELECT i.*, c.name as customer_name, c.phone as customer_phone, p.name as package_name FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN packages p ON i.package_id = p.id WHERE i.id = ?", [$invoiceId]);

if (!$invoice) {
    setFlash('error', 'Invoice tidak ditemukan');
    redirect('dashboard.php');
}

// Exclusive Tripay Integration
$defaultGateway = 'tripay';

// Get payment gateways
require_once '../includes/payment.php';

// Hardcoded Tripay Payment Methods
$paymentMethods = [
    ['code' => 'QRIS', 'name' => 'QRIS', 'icon' => 'fa-qrcode', 'color' => '#00f5ff'],
    ['code' => 'VIRTUAL_ACCOUNT_BCA', 'name' => 'BCA Virtual Account', 'icon' => 'fa-building', 'color' => '#667eea'],
    ['code' => 'VIRTUAL_ACCOUNT_BRI', 'name' => 'BRI Virtual Account', 'icon' => 'fa-building', 'color' => '#667eea'],
    ['code' => 'VIRTUAL_ACCOUNT_MANDIRI', 'name' => 'Mandiri Virtual Account', 'icon' => 'fa-building', 'color' => '#667eea'],
    ['code' => 'VIRTUAL_ACCOUNT_BNI', 'name' => 'BNI Virtual Account', 'icon' => 'fa-building', 'color' => '#667eea'],
    ['code' => 'EWALLET_OVO', 'name' => 'OVO', 'icon' => 'fa-wallet', 'color' => '#bf00ff'],
    ['code' => 'EWALLET_DANA', 'name' => 'DANA', 'icon' => 'fa-wallet', 'color' => '#bf00ff'],
    ['code' => 'EWALLET_LINKAJA', 'name' => 'LinkAja', 'icon' => 'fa-wallet', 'color' => '#bf00ff'],
    ['code' => 'EWALLET_SHOPEEPAY', 'name' => 'ShopeePay', 'icon' => 'fa-wallet', 'color' => '#bf00ff'],
    ['code' => 'ALFAMART', 'name' => 'Alfamart', 'icon' => 'fa-store', 'color' => '#00ff00'],
    ['code' => 'INDOMARET', 'name' => 'Indomaret', 'icon' => 'fa-store', 'color' => '#ff0000']
];

// Handle payment method selection
$selectedPaymentMethod = $_POST['payment_method'] ?? '';
$paymentLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Sesi tidak valid atau telah kadaluarsa. Silakan coba lagi.');
        redirect('payment.php?invoice_id=' . $invoiceId);
    }

    $selectedPaymentMethod = $_POST['payment_method'] ?? '';
    
    if (empty($selectedPaymentMethod)) {
        setFlash('error', 'Silakan pilih metode pembayaran');
    } else {
        // Generate payment link with payment method
        $result = generatePaymentLink(
            $invoice['invoice_number'],
            $invoice['amount'],
            $invoice['customer_name'],
            $invoice['customer_phone'],
            $invoice['due_date'],
            $defaultGateway,
            $selectedPaymentMethod
        );
        
        if ($result['success']) {
            $paymentLink = $result['link'];
            logActivity('PAYMENT_LINK_GENERATED', "Invoice: {$invoice['invoice_number']}, Gateway: {$defaultGateway}, Method: {$selectedPaymentMethod}");
        } else {
            setFlash('error', $result['message'] ?? 'Gagal generate payment link');
        }
    }
}

ob_start();
?>

<div style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-credit-card"></i> Pembayaran Invoice</h3>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h4 style="color: var(--neon-cyan);">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h4>
            <p style="color: var(--text-secondary);">Paket: <?php echo htmlspecialchars($invoice['package_name']); ?></p>
            <p style="color: var(--text-secondary);">Jatuh Tempo: <?php echo formatDate($invoice['due_date']); ?></p>
            <p style="font-size: 1.5rem; font-weight: bold; color: var(--neon-cyan);">
                Total: <?php echo formatCurrency($invoice['amount']); ?>
            </p>
        </div>
        
        <?php if ($invoice['status'] === 'paid'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Invoice ini sudah dibayar
            </div>
        <?php else: ?>
            <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin melanjutkan pembayaran?');">
                <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 0.9rem;">
                        Pilih metode pembayaran untuk invoice ini:
                    </p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <?php foreach ($paymentMethods as $method): ?>
                            <div class="payment-method-option" 
                                 style="border: 2px solid var(--border-color); 
                                        border-radius: 8px; 
                                        padding: 15px; 
                                        cursor: pointer; 
                                        transition: all 0.3s;
                                        text-align: center;"
                                 onclick="selectPaymentMethod('<?php echo $method['code']; ?>')">
                                <input type="radio" 
                                       name="payment_method" 
                                       value="<?php echo $method['code']; ?>"
                                       id="method_<?php echo $method['code']; ?>"
                                       style="display: none;">
                                <div style="color: <?php echo $method['color']; ?>; font-size: 1.5rem; margin-bottom: 8px;">
                                    <i class="fas <?php echo $method['icon']; ?>"></i>
                                </div>
                                <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-primary);">
                                    <?php echo $method['name']; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-credit-card"></i> Lanjut Pembayaran
                </button>
                
                <div style="margin-top: 20px; text-align: center; font-size: 0.8rem; color: var(--text-secondary);">
                    Dengan melanjutkan pembayaran, Anda menyetujui 
                    <a href="#" onclick="openModal('tosModal'); return false;" style="color: var(--neon-cyan);">Syarat & Ketentuan</a> 
                    dan 
                    <a href="#" onclick="openModal('refundModal'); return false;" style="color: var(--neon-cyan);">Kebijakan Pengembalian Dana</a>.
                </div>
            </form>
            
            <?php if ($paymentLink): ?>
                <div style="margin-top: 30px; padding: 20px; background: rgba(0, 245, 255, 0.1); border: 1px solid var(--neon-cyan); border-radius: 8px;">
                    <h4 style="color: var(--neon-cyan); margin-bottom: 15px;">
                        <i class="fas fa-external-link-alt"></i> Link Pembayaran
                    </h4>
                    <p style="color: var(--text-secondary); margin-bottom: 15px;">
                        Silakan klik link di bawah ini untuk melanjutkan pembayaran:
                    </p>
                    <a href="<?php echo htmlspecialchars($paymentLink); ?>" 
                       target="_blank" 
                       class="btn btn-primary" 
                       style="display: inline-block; text-decoration: none; text-align: center;">
                        <i class="fas fa-external-link-alt"></i> Buka Payment Gateway
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div style="margin-top: 30px;">
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </a>
        </div>
    </div>
    
    <!-- Contact Support -->
    <div style="margin-top: 30px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
        <p>Butuh bantuan? Hubungi Layanan Pelanggan kami:</p>
        <p>
            <i class="fab fa-whatsapp" style="color: #25D366;"></i> 
            <a href="https://wa.me/6281234567890" style="color: var(--text-primary); text-decoration: none;">+62 812-3456-7890</a>
            &nbsp;|&nbsp; 
            <i class="fas fa-envelope" style="color: var(--neon-cyan);"></i> 
            <a href="mailto:support@gembok.net" style="color: var(--text-primary); text-decoration: none;">support@gembok.net</a>
        </p>
    </div>
</div>

<!-- TOS Modal -->
<div id="tosModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 600px; max-width: 90%; max-height: 80vh; overflow-y: auto; padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="color: var(--neon-cyan);">Syarat & Ketentuan</h3>
            <button onclick="closeModal('tosModal')" style="background: none; border: none; color: var(--text-secondary); font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div style="color: var(--text-primary); line-height: 1.6;">
            <p>1. Pembayaran tagihan layanan internet wajib dilakukan sebelum tanggal jatuh tempo setiap bulannya.</p>
            <p>2. Keterlambatan pembayaran dapat mengakibatkan isolir layanan sementara secara otomatis oleh sistem.</p>
            <p>3. Biaya administrasi pembayaran melalui payment gateway ditanggung oleh pelanggan (kecuali ada promo tertentu).</p>
            <p>4. Simpan bukti pembayaran jika transaksi berhasil namun status belum berubah di sistem.</p>
            <p>5. Kami menjamin keamanan data transaksi Anda melalui enkripsi standar industri.</p>
        </div>
    </div>
</div>

<!-- Refund Policy Modal -->
<div id="refundModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 600px; max-width: 90%; max-height: 80vh; overflow-y: auto; padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
        <div style="display: flex; justify-content: space-between; margin-bottom: 20px;">
            <h3 style="color: var(--neon-cyan);">Kebijakan Pengembalian Dana</h3>
            <button onclick="closeModal('refundModal')" style="background: none; border: none; color: var(--text-secondary); font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div style="color: var(--text-primary); line-height: 1.6;">
            <p>1. Pembayaran yang sudah berhasil diverifikasi sistem <strong>tidak dapat dibatalkan atau dikembalikan (non-refundable)</strong>.</p>
            <p>2. Jika terjadi kelebihan pembayaran (double payment), dana akan dikreditkan sebagai saldo deposit untuk pembayaran tagihan bulan berikutnya.</p>
            <p>3. Jika layanan tidak dapat digunakan karena gangguan teknis dari sisi kami lebih dari 3x24 jam, pelanggan berhak mengajukan kompensasi potongan tagihan (prorata).</p>
            <p>4. Pengajuan komplain pembayaran harus disertai bukti transfer yang valid maksimal 7 hari setelah transaksi.</p>
        </div>
    </div>
</div>

<style>
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--border-color);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--neon-cyan);
}
.form-group { margin-bottom: 20px; }
.form-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-secondary); }
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%);
    transition: all 0.3s;
    display: inline-block;
    text-decoration: none;
}
.btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,245,255,0.3); }
.btn-secondary {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}
.btn-secondary:hover { background: rgba(255, 255,255,0.05); }
.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid #00ff00; color: #00ff00; }
.alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid #ff0000; color: #ff0000; }
.gateway-option:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,245,255,0.2); }
</style>

<script>
function selectPaymentMethod(methodCode) {
    document.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.checked = false;
    });
    document.getElementById('method_' + methodCode).checked = true;
    
    // Highlight selected method
    document.querySelectorAll('.payment-method-option').forEach(el => {
        el.style.borderColor = 'var(--border-color)';
    });
    event.currentTarget.style.borderColor = 'var(--neon-cyan)';
}

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.id === 'tosModal') {
        closeModal('tosModal');
    }
    if (event.target.id === 'refundModal') {
        closeModal('refundModal');
    }
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
