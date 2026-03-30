<?php
/**
 * Repay Pending Topup (Tripay)
 */

require_once '../includes/auth.php';
requireSalesLogin();

if (!isset($_GET['id'])) {
    redirect('../sales/topup.php');
}

$id = (int)$_GET['id'];
$salesId = $_SESSION['sales']['id'];

$topup = fetchOne("SELECT * FROM sales_topups WHERE id = ? AND sales_user_id = ?", [$id, $salesId]);

if ($topup && $topup['status'] === 'pending' && $topup['payment_method'] === 'tripay') {
    require_once '../includes/payment.php';
    $sales = getSalesUser($salesId);
    
    $res = generateTripayPaymentLink(
        "TOPUP-" . $id,
        $topup['amount'],
        $sales['name'],
        $sales['phone'] ?? '08123456789',
        date('Y-m-d H:i:s', strtotime('+1 day'))
    );
    
    if ($res['success']) {
        $redirectUrl = APP_URL . "/payment_redirect.php?url=" . urlencode($res['link']) . "&qr=" . urlencode($res['qr_url']) . "&pay=" . urlencode($res['pay_url']);
        header("Location: " . $redirectUrl);
        exit;
    } else {
        setFlash('error', 'Gagal membuat link pembayaran: ' . $res['message']);
    }
} else {
    setFlash('error', 'Permintaan pembayaran tidak valid.');
}

redirect('../sales/topup.php');
