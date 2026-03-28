<?php
/**
 * API: Payment Gateway Integration
 */

header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireAdminLogin();

try {
    $action = $_GET['action'] ?? '';
    $gateway = $_GET['gateway'] ?? '';

    if ($action === 'create_transaction') {
        // Create payment transaction
        $invoiceId = (int) ($_GET['invoice_id'] ?? 0);

        if ($invoiceId === 0) {
            echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $invoice = fetchOne("SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = ?", [$invoiceId]);

        if (!$invoice) {
            echo json_encode(['success' => false, 'message' => 'Invoice not found']);
            exit;
        }

        // Generate payment link based on gateway
        $paymentLink = generatePaymentLink($invoice, $gateway);

        echo json_encode([
            'success' => true,
            'data' => [
                'payment_link' => $paymentLink,
                'invoice' => [
                    'number' => $invoice['invoice_number'],
                    'amount' => $invoice['amount'],
                    'customer' => $invoice['customer_name'],
                    'due_date' => $invoice['due_date']
                ]
            ]
        ]);

    } elseif ($action === 'get_gateways') {
        // Get list of supported payment gateways
        $gateways = [
            [
                'id' => 'tripay',
                'name' => 'Tripay',
                'icon' => 'fa-credit-card',
                'color' => '#00f5ff',
                'description' => 'Payment gateway populer Indonesia',
                'features' => ['QRIS', 'Virtual Account', 'VA']
            ],
            [
                'id' => 'midtrans',
                'name' => 'Midtrans',
                'icon' => 'fa-credit-card',
                'color' => '#667eea',
                'description' => 'Payment gateway populer Indonesia',
                'features' => ['QRIS', 'Virtual Account', 'VA', 'Bank Transfer']
            ]
        ];

        echo json_encode([
            'success' => true,
            'data' => [
                'gateways' => $gateways
            ]
        ]);
    }

} catch (Exception $e) {
    logError("Payment API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function generatePaymentLink($invoice, $gateway = 'tripay')
{
    $invoiceNumber = $invoice['invoice_number'];
    $amount = $invoice['amount'];

    switch ($gateway) {
        case 'tripay':
            if (empty(TRIPAY_API_KEY) || empty(TRIPAY_MERCHANT_CODE)) {
                return [
                    'success' => false,
                    'message' => 'Payment gateway not configured',
                    'link' => null
                ];
            }

            // Generate Tripay payment link
            $merchantRef = $invoiceNumber;
            $paymentLink = "https://tripay.co.id/checkout?merchant_code=" . TRIPAY_MERCHANT_CODE . "&amount={$amount}&merchant_ref={$merchantRef}";

            return [
                'success' => true,
                'link' => $paymentLink
            ];

        case 'midtrans':
            if (empty(MIDTRANS_API_KEY) || empty(MIDTRANS_MERCHANT_CODE)) {
                return [
                    'success' => false,
                    'message' => 'Payment gateway not configured',
                    'link' => null
                ];
            }

            // Generate Midtrans payment link
            $merchantRef = $invoiceNumber;
            $paymentLink = "https://app.midtrans.com/paymentlink.php?merchant_code=" . MIDTRANS_MERCHANT_CODE . "&amount={$amount}&order_id={$merchantRef}";

            return [
                'success' => true,
                'link' => $paymentLink
            ];

        default:
            return [
                'success' => false,
                'message' => 'Payment gateway not supported',
                'link' => null
            ];
    }
}
