<?php
/**
 * Sales History
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Riwayat Transaksi';
$salesId = $_SESSION['sales']['id'];

// Get Transactions
$transactions = fetchAll("SELECT * FROM sales_transactions WHERE sales_user_id = ? ORDER BY created_at DESC LIMIT 50", [$salesId]);

// Get Voucher Sales
$voucherSales = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? ORDER BY created_at DESC LIMIT 50", [$salesId]);

// Get Bill Payments (Invoices paid by this sales)
// Since we don't store sales_user_id in invoices directly (we only used payment_method='sales_deposit'),
// we rely on the sales_transactions table to find related payments.
// However, to make it easier to display, let's query invoices linked to sales transactions.
// Or better, let's fetch from sales_transactions where type='bill_payment' and join with invoices if possible?
// Actually, sales_transactions doesn't have invoice_id.
// But we can query invoices that were created by this sales logic.
// For now, let's use a workaround:
// We'll add a new column `sales_user_id` to `invoices` table to track who processed the payment?
// Or we can just list the transactions that are type='bill_payment' in a separate table below.

// Let's create a dedicated section for "Riwayat Pembayaran Tagihan" using sales_transactions
$billPayments = fetchAll("SELECT st.*, c.name as customer_name, c.pppoe_username 
    FROM sales_transactions st 
    LEFT JOIN customers c ON st.related_username = c.pppoe_username
    WHERE st.sales_user_id = ? AND st.type = 'bill_payment' 
    ORDER BY st.created_at DESC LIMIT 50", [$salesId]);

ob_start();
?>

<div class="row" style="display: flex; flex-direction: column; gap: 20px;">
    <!-- Bill Payment History -->
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-invoice-dollar"></i> Riwayat Pembayaran Tagihan</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="billTable">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Pelanggan</th>
                                <th>Keterangan</th>
                                <th>Total Bayar</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($billPayments as $b): ?>
                                <tr>
                                    <td><?php echo $b['created_at']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($b['customer_name'] ?? $b['related_username']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($b['related_username']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($b['description']); ?></td>
                                    <td class="text-success"><?php echo formatCurrency(abs($b['amount'])); ?></td>
                                    <td>
                                        <?php 
                                            // Find Invoice ID
                                            $cust = fetchOne("SELECT id FROM customers WHERE pppoe_username = ?", [$b['related_username']]);
                                            if ($cust):
                                                $inv = fetchOne("SELECT id FROM invoices WHERE customer_id = ? AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) < 60 LIMIT 1", [$cust['id'], $b['created_at']]);
                                                if ($inv):
                                        ?>
                                            <a href="print_invoice.php?ids=<?php echo $inv['id']; ?>" target="_blank" class="btn btn-sm btn-info" title="Cetak Struk">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        <?php endif; endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Voucher History -->
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-ticket-alt"></i> Riwayat Penjualan Voucher</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="voucherTable">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Username</th>
                                <th>Profile</th>
                                <th>Harga Jual</th>
                                <th>Modal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($voucherSales as $v): ?>
                                <tr>
                                    <td><?php echo $v['created_at']; ?></td>
                                    <td><?php echo htmlspecialchars($v['username']); ?></td>
                                    <td><?php echo htmlspecialchars($v['profile']); ?></td>
                                    <td><?php echo formatCurrency($v['selling_price']); ?></td>
                                    <td><?php echo formatCurrency($v['price']); ?></td>
                                    <td>
                                        <a href="print_voucher.php?users=<?php echo $v['username']; ?>" target="_blank" class="btn btn-sm btn-info">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize SimpleDatatables if available
    document.addEventListener('DOMContentLoaded', () => {
        const transactionTable = document.getElementById('transactionTable');
        if (transactionTable) {
            new simpleDatatables.DataTable(transactionTable);
        }
        const voucherTable = document.getElementById('voucherTable');
        if (voucherTable) {
            new simpleDatatables.DataTable(voucherTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
