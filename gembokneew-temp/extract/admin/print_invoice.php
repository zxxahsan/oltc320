<?php
/**
 * Print Invoice (Admin)
 */

require_once '../includes/auth.php';
requireAdminLogin();

$adminUser = $_SESSION['admin'];

// Get Invoice IDs from URL (comma separated)
$invoiceIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$invoiceIds = array_map('intval', $invoiceIds);

if (empty($invoiceIds)) {
    die("Invoice ID tidak valid.");
}

// Fetch Invoices
$placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
$invoices = fetchAll("SELECT i.*, c.name as customer_name, c.address, c.pppoe_username, p.name as package_name 
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    JOIN packages p ON c.package_id = p.id 
    WHERE i.id IN ($placeholders)", $invoiceIds);

if (empty($invoices)) {
    die("Invoice tidak ditemukan.");
}

$customer = [
    'name' => $invoices[0]['customer_name'],
    'address' => $invoices[0]['address'],
    'pppoe_username' => $invoices[0]['pppoe_username']
];

$totalAmount = 0;
foreach ($invoices as $inv) {
    $totalAmount += $inv['amount'];
}

// App Settings
$appName = APP_NAME;
$appUrl = APP_URL;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Invoice - <?php echo $customer['name']; ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            margin: 0;
            padding: 20px;
            background: #fff;
            color: #000;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            border: 1px solid #eee;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        .header h2 { margin: 0; }
        .details {
            margin-bottom: 20px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table th, .table td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #eee;
        }
        .total {
            text-align: right;
            font-weight: bold;
            font-size: 16px;
            margin-top: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .invoice-box { border: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">Cetak Invoice</button>
        <button onclick="window.location.href='customers.php'" style="padding: 10px 20px; cursor: pointer;">Kembali</button>
    </div>

    <div class="invoice-box">
        <div class="header">
            <h2><?php echo $appName; ?></h2>
            <p>Bukti Pembayaran Tagihan Internet</p>
        </div>

        <div class="details">
            <table>
                <tr>
                    <td width="100">Nama</td>
                    <td>: <?php echo htmlspecialchars($customer['name']); ?></td>
                </tr>
                <tr>
                    <td>ID Pelanggan</td>
                    <td>: <?php echo htmlspecialchars($customer['pppoe_username']); ?></td>
                </tr>
                <tr>
                    <td>Alamat</td>
                    <td>: <?php echo htmlspecialchars($customer['address']); ?></td>
                </tr>
                <tr>
                    <td>Kasir</td>
                    <td>: Admin (<?php echo htmlspecialchars($adminUser['name'] ?? $adminUser['username']); ?>)</td>
                </tr>
                <tr>
                    <td>Tanggal</td>
                    <td>: <?php echo date('d/m/Y H:i'); ?></td>
                </tr>
            </table>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th>No. Invoice</th>
                    <th>Keterangan</th>
                    <th>Periode</th>
                    <th style="text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><?php echo $inv['invoice_number']; ?></td>
                    <td><?php echo $inv['package_name']; ?></td>
                    <td><?php echo formatDate($inv['due_date'], 'M Y'); ?></td>
                    <td style="text-align: right;"><?php echo formatCurrency($inv['amount']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total">
            Total Bayar: <?php echo formatCurrency($totalAmount); ?>
        </div>

        <div class="footer">
            <p>Terima kasih atas pembayaran Anda.</p>
            <p>Simpan struk ini sebagai bukti pembayaran yang sah.</p>
        </div>
    </div>
    
    <script>
        // Auto print on load
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
