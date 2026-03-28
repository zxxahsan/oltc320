<?php
/**
 * Billing Page - Customer Portal
 */

require_once '../includes/auth.php';
requireCustomerLogin();

$customerSession = getCurrentCustomer();

// Fetch fresh customer data
if ($customerSession && isset($customerSession['id'])) {
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerSession['id']]);
} else {
    $customer = $customerSession;
}

$pageTitle = 'Tagihan & Riwayat';

// Get current month invoice
$currentMonth = date('Y-m');
$currentInvoice = fetchOne("
    SELECT * FROM invoices 
    WHERE customer_id = ? 
    AND DATE_FORMAT(created_at, '%Y-%m') = ?
    ORDER BY created_at DESC 
    LIMIT 1",
    [$customer['id'], $currentMonth]
);

// Get all recent invoices for history
$recentInvoices = fetchAll("
    SELECT * FROM invoices 
    WHERE customer_id = ? 
    ORDER BY created_at DESC 
    LIMIT 12",
    [$customer['id']]
);

// Calculate total outstanding
$totalUnpaidQuery = fetchOne("
    SELECT SUM(amount) as total 
    FROM invoices 
    WHERE customer_id = ? AND status = 'unpaid'",
    [$customer['id']]
);
$totalUnpaid = $totalUnpaidQuery['total'] ?? 0;

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <?php if ($totalUnpaid > 0): ?>
    <!-- Outstanding Alert -->
    <div class="alert alert-error" style="margin-bottom: 20px;">
        <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem;"></i>
        <div>
            <h4 style="margin: 0; color: var(--neon-red);">Anda memiliki Tagihan Tertunggak!</h4>
            <p style="margin: 5px 0 0 0; color: #fff;">
                Segera lakukan pembayaran sebesar <strong><?php echo formatCurrency($totalUnpaid); ?></strong> untuk menghindari isolasi layanan.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payment Status (Current Month) -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-file-invoice-dollar"></i> Status Pembayaran Bulan Ini
        </h3>
        
        <?php if ($currentInvoice): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h4 style="margin-bottom: 5px;">Tagihan: <?php echo date('F Y', strtotime($currentInvoice['created_at'])); ?></h4>
                    <p style="color: var(--text-secondary);">
                        Jatuh Tempo: <?php echo formatDate($currentInvoice['due_date']); ?>
                    </p>
                </div>
                <div style="text-align: right;">
                    <p style="font-size: 1.5rem; font-weight: 700; color: var(--neon-green);">
                        <?php echo formatCurrency($currentInvoice['amount']); ?>
                    </p>
                    <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                        <span class="badge badge-warning" style="font-size: 1.1rem; padding: 6px 15px;">Belum Bayar</span>
                    <?php else: ?>
                        <span class="badge badge-success" style="font-size: 1.1rem; padding: 6px 15px;">Lunas</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                <a href="payment.php?invoice_id=<?php echo $currentInvoice['id']; ?>" class="btn btn-primary" style="display: inline-block; width: 100%; text-align: center; font-size: 1.1rem; padding: 12px;">
                    <i class="fas fa-credit-card"></i> Bayar Sekarang
                </a>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 30px 10px;">
                <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--neon-green); margin-bottom: 15px;"></i>
                <h4 style="color: #fff;">Semua Tagihan Lunas</h4>
                <p style="color: var(--text-muted);">Tidak ada tagihan yang belum terbayar untuk bulan ini.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Billing History -->
    <div class="card" style="margin-top: 20px;">
        <h3 style="margin-bottom: 15px; color: var(--text-primary);">
            <i class="fas fa-history"></i> Riwayat Tagihan & Pembayaran
        </h3>
        <div style="overflow-x: auto;">
            <table class="data-table" style="width: 100%; min-width: 600px;">
                <thead>
                    <tr>
                        <th>ID Invoice</th>
                        <th>Periode</th>
                        <th>Jatuh Tempo</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentInvoices)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;" data-label="Data">
                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                Belum ada riwayat tagihan
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentInvoices as $inv): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                            <td style="padding: 12px;"><?php echo date('F Y', strtotime($inv['created_at'])); ?></td>
                            <td style="padding: 12px;"><?php echo formatDate($inv['due_date']); ?></td>
                            <td style="padding: 12px; font-weight: bold;"><?php echo formatCurrency($inv['amount']); ?></td>
                            <td style="padding: 12px;">
                                <?php if ($inv['status'] === 'paid'): ?>
                                    <span class="badge badge-success">Lunas</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Belum Bayar</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <?php if ($inv['status'] === 'unpaid'): ?>
                                    <a href="payment.php?invoice_id=<?php echo $inv['id']; ?>" class="btn btn-primary btn-sm">Bayar</a>
                                <?php else: ?>
                                    <span style="color: var(--neon-green);"><i class="fas fa-check"></i> Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<style>
.data-table th { background: rgba(0,0,0,0.2); }
</style>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
