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

// Calculate Next Invoice Generation Schedule
$leadDays = (int)getSetting('invoice_generate_days', 7);
$billingDay = (int)($customer['due_date'] ?? 1);
if ($billingDay == 0) $billingDay = 1;

$today = date('Y-m-d');
$todayTs = strtotime($today);
$currentMonthNum = (int)date('n');
$currentYearNum = (int)date('Y');

$potentialDueDates = [
    date('Y-m-d', mktime(0, 0, 0, $currentMonthNum, $billingDay, $currentYearNum)),
    date('Y-m-d', mktime(0, 0, 0, $currentMonthNum + 1, $billingDay, $currentYearNum))
];

$nextGenDate = '';
$displayNextDueDate = '';

foreach ($potentialDueDates as $dueDate) {
    if (strtotime($dueDate) > $todayTs) {
        $genDate = date('Y-m-d', strtotime("-{$leadDays} days", strtotime($dueDate)));
        $nextGenDate = $genDate;
        $displayNextDueDate = $dueDate;
        
        $existing = fetchOne("SELECT id FROM invoices WHERE customer_id = ? AND due_date = ?", [$customer['id'], $dueDate]);
        if (!$existing) break;
    }
}

ob_start();
?>

    <!-- Automated Billing Schedule -->
    <div class="card" style="margin-bottom: 25px; background: rgba(0, 200, 255, 0.03); border: 1px solid rgba(0, 200, 255, 0.15); border-left: 4px solid var(--neon-cyan); padding: 25px;">
        <h3 style="margin-bottom: 20px; color: var(--neon-cyan); font-size: 1.1rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px;">
            <i class="fas fa-calendar-check"></i> Jadwal Penagihan Otomatis
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 25px;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 8px;">Invoice Berikutnya</div>
                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);">
                    <i class="fas fa-file-invoice" style="margin-right: 8px; font-size: 1rem; opacity: 0.5;"></i>
                    <?php echo $nextGenDate ? formatDate($nextGenDate) : 'Segera'; ?>
                </div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 8px;">Batas Akhir Bayar</div>
                <div style="font-size: 1.4rem; font-weight: 700; color: var(--neon-orange);">
                    <i class="fas fa-clock" style="margin-right: 8px; font-size: 1rem; opacity: 0.5;"></i>
                    <?php echo $displayNextDueDate ? formatDate($displayNextDueDate) : 'Sesuai Siklus'; ?>
                </div>
            </div>
            <div style="display: flex; align-items: center; border-left: 1px solid var(--border-color); padding-left: 20px;">
                <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5; margin: 0;">
                    <i class="fas fa-info-circle" style="color: var(--neon-cyan); margin-right: 5px;"></i> Tagihan dikirimkan otomatis ke WhatsApp. Pastikan nomor terdaftar aktif agar tidak melewatkan info tagihan.
                </p>
            </div>
        </div>
    </div>
    <!-- Payment Status (Current Month) -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-file-invoice-dollar"></i> Status Pembayaran Bulan Ini
        </h3>
        
        <?php if ($currentInvoice): ?>
            <div style="background: var(--bg-secondary); border-radius: 12px; padding: 25px; border: 1px solid var(--border-color); text-align: center; margin-bottom: 20px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <div style="margin-bottom: 20px;">
                    <p style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Tagihan Bulan</p>
                    <h2 style="font-size: 1.8rem; font-weight: 800; color: var(--text-primary); margin: 0;">
                        <?php echo date('F Y', strtotime($currentInvoice['created_at'])); ?>
                    </h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 5px;">
                        Jatuh Tempo: <span style="font-weight: 600;"><?php echo formatDate($currentInvoice['due_date']); ?></span>
                    </p>
                </div>
                
                <div style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-bottom: 8px;">Total Tagihan</p>
                    <div style="font-size: 2.2rem; font-weight: 900; color: var(--text-primary); line-height: 1; margin-bottom: 12px;">
                        <?php echo formatCurrency($currentInvoice['amount']); ?>
                    </div>
                    <div>
                        <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                            <span class="badge badge-warning" style="font-size: 1rem; padding: 6px 20px; border-radius: 50px; box-shadow: 0 4px 10px rgba(253, 126, 20, 0.2);">Belum Bayar</span>
                        <?php else: ?>
                            <span class="badge badge-success" style="font-size: 1rem; padding: 6px 20px; border-radius: 50px; box-shadow: 0 4px 10px rgba(0, 255, 136, 0.2);">Lunas</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                <a href="payment.php?invoice_id=<?php echo $currentInvoice['id']; ?>" class="btn btn-primary" style="display: inline-block; width: 100%; text-align: center; font-size: 1.1rem; padding: 12px;">
                    <i class="fas fa-credit-card"></i> Bayar Sekarang
                </a>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px 10px; background: var(--bg-secondary); border-radius: 12px; border: 1px dashed var(--border-color);">
                <i class="fas fa-check-circle" style="font-size: 3.5rem; color: var(--neon-green); margin-bottom: 20px; filter: drop-shadow(0 0 10px rgba(0, 255, 136, 0.3));"></i>
                <h4 style="color: var(--text-primary); font-size: 1.3rem; margin-bottom: 10px;">Semua Tagihan Lunas</h4>
                <p style="color: var(--text-secondary);">Hebat! Tidak ada tagihan yang belum terbayar untuk bulan ini.</p>
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
