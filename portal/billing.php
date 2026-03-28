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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="color: var(--text-primary); margin: 0;">
                <i class="fas fa-history"></i> Riwayat Tagihan
            </h3>
            <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);" class="d-none-mobile">
                Menampilkan 12 transaksi terakhir
            </p>
        </div>

        <div class="billing-list">
            <?php if (empty($recentInvoices)): ?>
                <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <i class="fas fa-folder-open" style="font-size: 2.5rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                    <p>Belum ada riwayat tagihan tercatat.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentInvoices as $inv): ?>
                <div class="billing-item <?php echo $inv['status'] === 'unpaid' ? 'unpaid-border' : ''; ?>" id="inv_<?php echo $inv['id']; ?>">
                    <div class="billing-item-header" onclick="toggleInvoice(<?php echo $inv['id']; ?>)">
                        <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
                            <div class="inv-icon <?php echo $inv['status'] === 'paid' ? 'paid' : 'unpaid'; ?>">
                                <i class="fas <?php echo $inv['status'] === 'paid' ? 'fa-check' : 'fa-file-invoice'; ?>"></i>
                            </div>
                            <div>
                                <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">
                                    <?php echo date('F Y', strtotime($inv['created_at'])); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                    #<?php echo htmlspecialchars($inv['invoice_number']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="text-align: right; margin-right: 10px;">
                            <div style="font-weight: 700; color: var(--text-primary);"><?php echo formatCurrency($inv['amount']); ?></div>
                            <div style="font-size: 0.75rem;">
                                <?php if ($inv['status'] === 'paid'): ?>
                                    <span style="color: var(--neon-green);">Lunas</span>
                                <?php else: ?>
                                    <span style="color: var(--neon-orange);">Belum Bayar</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="chevron">
                            <i class="fas fa-chevron-down" id="chevron_<?php echo $inv['id']; ?>"></i>
                        </div>
                    </div>
                    
                    <div class="billing-item-details" id="details_<?php echo $inv['id']; ?>">
                        <div style="padding: 15px; background: rgba(0,0,0,0.02); border-top: 1px solid var(--border-color); display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px;">
                            <div>
                                <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-size: 0.7rem; font-weight: 700;">Tanggal Invoice</small>
                                <span style="font-size: 0.9rem;"><?php echo formatDate($inv['created_at']); ?></span>
                            </div>
                            <div>
                                <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-size: 0.7rem; font-weight: 700;">Jatuh Tempo</small>
                                <span style="font-size: 0.9rem;"><?php echo formatDate($inv['due_date']); ?></span>
                            </div>
                            <div>
                                <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-size: 0.7rem; font-weight: 700;">Metode</small>
                                <span style="font-size: 0.9rem; text-transform: capitalize;"><?php echo htmlspecialchars($inv['payment_method'] ?: '-'); ?></span>
                            </div>
                            <div style="display: flex; align-items: flex-end; justify-content: flex-end;">
                                <?php if ($inv['status'] === 'unpaid'): ?>
                                    <a href="payment.php?invoice_id=<?php echo $inv['id']; ?>" class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;">
                                        Bayar Sekarang
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm" disabled style="width: 100%; justify-content: center; opacity: 0.6;">
                                        Sudah Terbayar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
.billing-list { display: flex; flex-direction: column; gap: 10px; }
.billing-item { 
    border: 1px solid var(--border-color); 
    border-radius: 10px; 
    overflow: hidden; 
    transition: all 0.2s; 
    background: var(--bg-card);
}
.billing-item.unpaid-border { border-left: 4px solid var(--neon-orange); }
.billing-item-header { 
    padding: 15px; 
    display: flex; 
    align-items: center; 
    cursor: pointer; 
    user-select: none;
}
.billing-item-header:hover { background: rgba(0,0,0,0.02); }
.inv-icon { 
    width: 36px; 
    height: 36px; 
    border-radius: 8px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-size: 1rem;
}
.inv-icon.paid { background: rgba(25, 135, 84, 0.1); color: var(--neon-green); }
.inv-icon.unpaid { background: rgba(253, 126, 20, 0.1); color: var(--neon-orange); }

.chevron { color: var(--text-muted); transition: transform 0.3s; }
.billing-item.active .chevron { transform: rotate(180deg); color: var(--neon-cyan); }

.billing-item-details { 
    display: none; 
    animation: slideDown 0.3s ease-out;
}
.billing-item.active .billing-item-details { display: block; }

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 576px) {
    .d-none-mobile { display: none; }
}
</style>

<script>
function toggleInvoice(id) {
    const item = document.getElementById('inv_' + id);
    const isActive = item.classList.contains('active');
    
    // Smooth close others if desired, or just toggle this one
    item.classList.toggle('active');
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
