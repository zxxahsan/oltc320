<?php
/**
 * Sales Voucher Module
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Buat Voucher';

$salesId = $_SESSION['sales']['id'];
$salesUser = getSalesUser($salesId);

// Get Assigned Profiles
$profiles = fetchAll("SELECT * FROM sales_profile_prices WHERE sales_user_id = ? AND is_active = 1", [$salesId]);

// Handle Voucher Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Sesi tidak valid atau telah kadaluarsa. Silakan coba lagi.');
        redirect('vouchers.php');
    }

    $profileId = (int) $_POST['profile_id'];
    $qty = (int) $_POST['qty'];
    $prefix = sanitize($_POST['prefix'] ?? '');
    
    // Validate
    if ($qty < 1 || $qty > 50) {
        setFlash('error', 'Jumlah voucher harus antara 1 - 50.');
        redirect('vouchers.php');
    }
    
    // Get Profile Data
    $selectedProfile = fetchOne("SELECT * FROM sales_profile_prices WHERE id = ? AND sales_user_id = ?", [$profileId, $salesId]);
    
    if (!$selectedProfile) {
        setFlash('error', 'Profile tidak valid.');
        redirect('vouchers.php');
    }
    
    // Calculate Total Cost
    $totalCost = $selectedProfile['base_price'] * $qty;
    
    // Check Balance
    if ($salesUser['deposit_balance'] < $totalCost) {
        setFlash('error', 'Saldo deposit tidak mencukupi. Total: ' . formatCurrency($totalCost) . ', Saldo: ' . formatCurrency($salesUser['deposit_balance']));
        redirect('vouchers.php');
    }
    
    // Process Transaction
    $successCount = 0;
    $generatedVouchers = [];
    $pdo = getDB();
    
    try {
        $pdo->beginTransaction();
        
        // Deduct Balance
        $newBalance = $salesUser['deposit_balance'] - $totalCost;
        update('sales_users', ['deposit_balance' => $newBalance], 'id = ?', [$salesId]);
        
        // Record Transaction
        insert('sales_transactions', [
            'sales_user_id' => $salesId,
            'type' => 'voucher_sale',
            'amount' => -$totalCost,
            'description' => "Pembelian $qty Voucher {$selectedProfile['profile_name']}",
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Generate Vouchers
        for ($i = 0; $i < $qty; $i++) {
            // Use Sales User Voucher Settings
            $length = $salesUser['voucher_length'] ?: 6;
            $mode = $salesUser['voucher_mode'] ?: 'mix'; // mix, num, alp
            $type = $salesUser['voucher_type'] ?: 'upp'; // upp, up

            $charSet = 'alphanumeric'; // default
            if ($mode === 'num') $charSet = 'numeric';
            if ($mode === 'alp') $charSet = 'low'; // use lowercase for alpha

            $user = $prefix . generateRandomString($length, $charSet);
            
            if ($type === 'up') {
                $pass = generateRandomString($length, $charSet);
            } else {
                $pass = $user; // Default u=p
            }
            
            // Add to Mikrotik
            // Note: We need mikrotik_api.php functions. auth.php includes functions.php which includes mikrotik_api.php
            
            // Extra data for Mikrotik comment
            // Format: vc-namasales-tanggal (e.g., vc-jhon-26/02/26)
            $comment = "vc-" . strtolower($salesUser['username']) . "-" . date('d/m/y');
            
            if (mikrotikAddHotspotUser($user, $pass, $selectedProfile['profile_name'], ['comment' => $comment])) {
                // Record Sale
                recordHotspotSale(
                    $user, 
                    $selectedProfile['profile_name'], 
                    $selectedProfile['base_price'], 
                    $selectedProfile['selling_price'], 
                    $prefix, 
                    $salesId
                );
                
                $generatedVouchers[] = [
                    'username' => $user,
                    'password' => $pass,
                    'profile' => $selectedProfile['profile_name'],
                    'price' => formatCurrency($selectedProfile['selling_price']),
                    'validity' => '-' // We don't have validity in sales_profile_prices, maybe fetch from Mikrotik or assume profile default
                ];
                $successCount++;
            }
        }
        
        if ($successCount > 0) {
            $pdo->commit();
            $_SESSION['generated_vouchers'] = $generatedVouchers;
            
            // Send Notification (Optional)
            if (function_exists('sendWhatsApp')) {
                // Notif to Sales
                $message = "Voucher Created!\n\n";
                $message .= "Profile: {$selectedProfile['profile_name']}\n";
                $message .= "Qty: {$qty}\n";
                $message .= "Total Modal: " . formatCurrency($totalCost) . "\n";
                $message .= "Sisa Saldo: " . formatCurrency($newBalance) . "\n\n";
                $message .= "Terima kasih.";
                
                // Assuming sales user has phone number in sales_users table
                if (!empty($salesUser['phone'])) {
                    sendWhatsApp($salesUser['phone'], $message);
                }
            }

            setFlash('success', "Berhasil membuat $successCount voucher.");
            redirect('print_voucher.php');
        } else {
            $pdo->rollBack();
            setFlash('error', "Gagal membuat voucher (Error Mikrotik). Saldo tidak terpotong.");
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        logError("Sales Transaction Error: " . $e->getMessage());
        setFlash('error', "Terjadi kesalahan sistem.");
    }
    
    redirect('vouchers.php');
}

ob_start();
?>

<div class="row" style="display: flex; flex-wrap: wrap; gap: 20px;">
    <!-- Form Card -->
    <div class="col-md-6" style="flex: 1; min-width: 300px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Buat Voucher Baru</h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <div class="form-group">
                        <label class="form-label">Pilih Paket / Profile</label>
                        <select name="profile_id" class="form-control" required>
                            <option value="">-- Pilih Paket --</option>
                            <?php foreach ($profiles as $p): ?>
                                <option value="<?php echo $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['profile_name']); ?> 
                                    (Modal: <?php echo formatCurrency($p['base_price']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Jumlah (Qty)</label>
                        <input type="number" name="qty" class="form-control" value="1" min="1" max="50" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Prefix (Opsional)</label>
                        <input type="text" name="prefix" class="form-control" placeholder="Contoh: VC-">
                        <small style="color: var(--text-muted);">Awalan untuk username voucher</small>
                    </div>

                    <div class="alert alert-info" style="margin-top: 20px;">
                        <i class="fas fa-info-circle"></i> Saldo akan otomatis terpotong sesuai harga modal.
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                        <i class="fas fa-check"></i> Proses Transaksi
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Result Card -->
    <?php if (isset($_SESSION['generated_vouchers'])): 
        $vouchers = $_SESSION['generated_vouchers'];
        unset($_SESSION['generated_vouchers']);
    ?>
    <div class="col-md-6" style="flex: 1; min-width: 300px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-receipt"></i> Voucher Berhasil Dibuat</h3>
                <button onclick="printDiv('voucher-list')" class="btn btn-sm btn-secondary" style="margin-left: auto;">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
            <div class="card-body">
                <div id="voucher-list" style="max-height: 500px; overflow-y: auto;">
                    <?php foreach ($vouchers as $v): ?>
                    <div style="border: 1px dashed var(--border-color); padding: 15px; margin-bottom: 10px; border-radius: 8px; background: rgba(0,0,0,0.2);">
                        <div style="font-weight: bold; font-size: 1.2rem; color: var(--neon-cyan); text-align: center; letter-spacing: 2px;">
                            <?php echo $v['username']; ?>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px; font-size: 0.9rem; color: var(--text-secondary);">
                            <span><?php echo $v['profile']; ?></span>
                            <span><?php echo $v['price']; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function printDiv(divName) {
    var printContents = document.getElementById(divName).innerHTML;
    var originalContents = document.body.innerHTML;

    document.body.innerHTML = "<div style='padding: 20px; font-family: monospace;'>" + printContents + "</div>";
    window.print();
    document.body.innerHTML = originalContents;
    location.reload(); // Reload to restore event listeners
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
