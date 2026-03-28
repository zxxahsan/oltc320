<?php
/**
 * Webhook Handler - WhatsApp
 */

require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/payment.php';

header('Content-Type: application/json');

// Helper to get settings from database
// Redundant getSetting removed as it is already in includes/functions.php

try {
    // Get raw POST data
    $json = file_get_contents('php://input');
    
    // Debug: Log incoming payload
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) mkdir($logDir, 0777, true);
    file_put_contents($logDir . 'whatsapp_webhook.log', "[" . date('Y-m-d H:i:s') . "] PAYLOAD: " . $json . "\n", FILE_APPEND);
    
    logActivity('WHATSAPP_WEBHOOK', "Received webhook");
    
    // Validate signature if configured
    if (!empty(WHATSAPP_TOKEN)) {
        $webhookToken = $_SERVER['HTTP_X_WHATSAPP_TOKEN'] ?? '';
        
        if (!hash_equals(WHATSAPP_TOKEN, $webhookToken)) {
            logError('WhatsApp webhook: Invalid token');
            echo json_encode(['success' => false, 'message' => 'Invalid token']);
            exit;
        }
    }
    
    // Parse JSON data
    $data = json_decode($json, true);
    
    if (!$data) {
        logError('WhatsApp webhook: Invalid JSON');
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    // Log webhook
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO webhook_logs (source, payload, status_code, response, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute(['whatsapp', $json, 200, 'Received']);
    
    // Handle webhook based on type
    $webhookType = $data['type'] ?? '';
    
    switch ($webhookType) {
        case 'message_status':
            handleMessageStatus($data);
            break;
            
        case 'message_sent':
            handleMessageSent($data);
            break;

        case 'message_received':
        case 'incoming_message':
        case 'message':
            handleMessageReceived($data);
            break;
            
        default:
            // Some providers send message without explicit type
            if (!handleMessageReceived($data)) {
                logActivity('WHATSAPP_WEBHOOK', "Unknown type: {$webhookType}");
            }
    }
    
    echo json_encode(['success' => true, 'message' => 'Webhook processed']);
    
} catch (Exception $e) {
    logError("WhatsApp webhook error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handleMessageStatus($data) {
    $status = $data['status'] ?? '';
    $messageId = $data['message_id'] ?? '';
    $recipient = $data['recipient'] ?? '';
    
    logActivity('WHATSAPP_MESSAGE_STATUS', "Status: {$status}, Message ID: {$messageId}, Recipient: {$recipient}");
}

function handleMessageSent($data) {
    $recipient = $data['recipient'] ?? '';
    $message = $data['message'] ?? '';
    
    logActivity('WHATSAPP_MESSAGE_SENT', "To: {$recipient}, Message: " . substr($message, 0, 50));
}

function handleMessageReceived($data) {
    $payload = extractWhatsAppMessage($data);
    if (!$payload) {
        return false;
    }
    
    $from = $payload['from'];
    $text = $payload['text'];
    
    file_put_contents(__DIR__ . '/../logs/whatsapp_webhook.log', "[" . date('Y-m-d H:i:s') . "] PROCESSED SENDER: $from, TEXT: $text\n", FILE_APPEND);
    
    if ($from === '' || $text === '') {
        return false;
    }
    
    handleIncomingWhatsApp($from, $text);
    return true;
}

function extractWhatsAppMessage($data) {
    $candidates = [];
    if (is_array($data)) {
        $candidates[] = $data;
        if (isset($data['data']) && is_array($data['data'])) {
            $candidates[] = $data['data'];
        }
        if (isset($data['message']) && is_array($data['message'])) {
            $candidates[] = $data['message'];
        }
        if (isset($data['messages']) && is_array($data['messages']) && isset($data['messages'][0])) {
            $candidates[] = $data['messages'][0];
        }
    }
    
    $from = '';
    $text = '';
    $fromKeys = ['sender', 'from', 'phone', 'number', 'wa_id', 'participant', 'remoteJid'];
    $textKeys = ['message', 'text', 'body', 'content', 'caption'];
    
    foreach ($candidates as $c) {
        foreach ($fromKeys as $key) {
            if ($from === '' && isset($c[$key]) && is_string($c[$key])) {
                $from = $c[$key];
            }
        }
        foreach ($textKeys as $key) {
            if ($text === '' && isset($c[$key]) && is_string($c[$key])) {
                $text = $c[$key];
            }
        }
        if ($from === '' && isset($c['chat']['id']) && is_string($c['chat']['id'])) {
            $from = $c['chat']['id'];
        }
        if ($text === '' && isset($c['text']['body']) && is_string($c['text']['body'])) {
            $text = $c['text']['body'];
        }
    }
    
    $from = normalizeWhatsAppPhone($from);
    $text = trim((string)$text);
    
    if ($from === '' || $text === '') {
        return null;
    }
    
    return [
        'from' => $from,
        'text' => $text
    ];
}

function normalizeWhatsAppPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if ($phone === '') {
        return '';
    }
    if (strpos($phone, '62') === 0) {
        return $phone;
    }
    if (strpos($phone, '0') === 0) {
        return '62' . substr($phone, 1);
    }
    return $phone;
}

function isWhatsAppAdmin($phone) {
    $admin = getSetting('WHATSAPP_ADMIN_NUMBER', '');
    $is_admin = (!empty($admin) && normalizeWhatsAppPhone($phone) === normalizeWhatsAppPhone($admin));
    
    file_put_contents(__DIR__ . '/../logs/whatsapp_webhook.log', "[" . date('Y-m-d H:i:s') . "] VERIFY ADMIN: Phone=$phone, AdminSet=$admin, RESULT=" . ($is_admin ? 'YES' : 'NO') . "\n", FILE_APPEND);
    
    return $is_admin;
}

function sendWhatsAppResponse($phone, $message) {
    return sendWhatsApp($phone, $message);
}

function handleIncomingWhatsApp($from, $text) {
    $line = trim(strtok($text, "\n"));
    $lower = strtolower($line);
    
    if ($lower === 'help') {
        $line = '/help';
    } elseif ($lower === 'menu') {
        $line = '/menu';
    }
    
    if ($line === '' || $line[0] !== '/') {
        handleWhatsAppRegularMessage($from);
        return;
    }
    
    $parts = explode(' ', $line, 2);
    $command = strtolower($parts[0]);
    $args = $parts[1] ?? '';
    
    switch ($command) {
        case '/help':
            handleWhatsAppHelp($from);
            break;
        case '/menu':
            handleWhatsAppMenu($from);
            break;
        case '/pay_invoice':
            $invoiceId = trim($args);
            if ($invoiceId === '') {
                sendWhatsAppResponse($from, "Format: /pay_invoice <invoice_id>");
                break;
            }
            handleWhatsAppPayInvoice($from, $invoiceId);
            break;
        case '/check_status':
            $phone = trim($args);
            if ($phone === '') {
                $phone = $from;
            }
            handleWhatsAppCheckStatus($from, $phone);
            break;
        case '/billing_cek':
            handleWhatsAppBillingCheck($from, $args);
            break;
        case '/billing_invoice':
            handleWhatsAppBillingInvoice($from, $args);
            break;
        case '/billing_isolir':
            handleWhatsAppBillingIsolir($from, $args);
            break;
        case '/billing_bukaisolir':
            handleWhatsAppBillingBukaIsolir($from, $args);
            break;
        case '/billing_lunas':
            handleWhatsAppBillingLunas($from, $args);
            break;
        case '/invoice_create':
            handleWhatsAppInvoiceCreate($from, $args);
            break;
        case '/invoice_edit':
            handleWhatsAppInvoiceEdit($from, $args);
            break;
        case '/invoice_delete':
            handleWhatsAppInvoiceDelete($from, $args);
            break;
        case '/mt_setprofile':
            handleWhatsAppMikrotikSetProfile($from, $args);
            break;
        case '/mt_resource':
            handleWhatsAppMikrotikResource($from);
            break;
        case '/mt_online':
            handleWhatsAppMikrotikOnline($from);
            break;
        case '/mt_ping':
            handleWhatsAppMikrotikPing($from, $args);
            break;
        case '/pppoe_list':
            handleWhatsAppPppoeList($from);
            break;
        case '/pppoe_add':
            handleWhatsAppPppoeAdd($from, $args);
            break;
        case '/pppoe_edit':
            handleWhatsAppPppoeEdit($from, $args);
            break;
        case '/pppoe_del':
            handleWhatsAppPppoeDel($from, $args);
            break;
        case '/pppoe_disable':
            handleWhatsAppPppoeDisable($from, $args);
            break;
        case '/pppoe_enable':
            handleWhatsAppPppoeEnable($from, $args);
            break;
        case '/pppoe_profile_list':
            handleWhatsAppPppoeProfileList($from);
            break;
        case '/hs_list':
            handleWhatsAppHotspotList($from);
            break;
        case '/hs_add':
            handleWhatsAppHotspotAdd($from, $args);
            break;
        case '/hs_del':
            handleWhatsAppHotspotDel($from, $args);
            break;
        default:
            handleWhatsAppRegularMessage($from);
    }
}

function handleWhatsAppRegularMessage($phone) {
    $message = "Terima kasih atas pesan Anda.\n\nGunakan command yang tersedia.\nKetik /help untuk melihat daftar command.";
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppHelp($phone) {
    $message = "🤖 GEMBOK Bot Commands\n\n";
    $message .= "Untuk pelanggan:\n";
    $message .= "/pay_invoice <invoice_id> - Cek dan bayar invoice\n";
    $message .= "/check_status <no_hp> - Cek status pelanggan\n";
    $message .= "/help - Tampilkan bantuan ini\n\n";
    
    if (isWhatsAppAdmin($phone)) {
        $message .= "Untuk admin:\n";
        $message .= "/menu - Tampilkan menu utama\n";
        $message .= "/billing_cek <pppoe_username> - Cek tagihan pelanggan\n";
        $message .= "/billing_invoice <pppoe_username> - Daftar invoice pelanggan\n";
        $message .= "/billing_isolir <pppoe_username> - Isolir pelanggan\n";
        $message .= "/billing_bukaisolir <pppoe_username> - Buka isolir pelanggan\n";
        $message .= "/billing_lunas <no_invoice> - Tandai invoice lunas\n";
        $message .= "/invoice_create <pppoe_username> <amount> <due_date> [desc]\n";
        $message .= "/invoice_edit <invoice_number> <amount> <due_date> <status>\n";
        $message .= "/invoice_delete <invoice_number>\n";
        $message .= "/mt_setprofile <pppoe_username> <profile>\n";
        $message .= "/mt_resource - Cek resource MikroTik\n";
        $message .= "/mt_online - Cek user PPPoE online\n";
        $message .= "/mt_ping <ip/host> - Ping dari MikroTik\n";
        $message .= "/pppoe_list - Daftar user PPPoE\n";
        $message .= "/pppoe_add <user> <pass> <profile>\n";
        $message .= "/pppoe_edit <user> <pass> <profile>\n";
        $message .= "/pppoe_del <user>\n";
        $message .= "/pppoe_disable <user>\n";
        $message .= "/pppoe_enable <user>\n";
        $message .= "/pppoe_profile_list\n";
        $message .= "/hs_list - Daftar user Hotspot\n";
        $message .= "/hs_add <user> <pass> <profile>\n";
        $message .= "/hs_del <user>\n";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppMenu($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah menu hanya untuk admin.");
        return;
    }
    
    $message = "Menu Admin:\n";
    $message .= "1) Billing: /billing_cek, /billing_invoice, /billing_lunas\n";
    $message .= "2) MikroTik: /pppoe_list, /pppoe_add, /hs_list, /hs_add\n";
    $message .= "Ketik /help untuk daftar lengkap.";
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppPayInvoice($phone, $invoiceId) {
    $invoice = fetchOne("SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = ?", [$invoiceId]);
    if (!$invoice) {
        sendWhatsAppResponse($phone, "Invoice tidak ditemukan.");
        return;
    }
    
    $gateway = getSetting('DEFAULT_PAYMENT_GATEWAY', 'tripay');
    $result = generatePaymentLink(
        $invoice['invoice_number'],
        $invoice['amount'],
        $invoice['customer_name'] ?? '-',
        $invoice['customer_phone'] ?? '',
        $invoice['due_date'],
        $gateway
    );
    
    if (!($result['success'] ?? false)) {
        sendWhatsAppResponse($phone, $result['message'] ?? 'Gagal generate payment link.');
        return;
    }
    
    $message = "Invoice #{$invoice['invoice_number']}\n";
    $message .= "Pelanggan: {$invoice['customer_name']}\n";
    $message .= "Jumlah: " . formatCurrency($invoice['amount']) . "\n";
    $message .= "Jatuh Tempo: " . formatDate($invoice['due_date']) . "\n\n";
    $message .= "Link pembayaran:\n";
    $message .= $result['link'];
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppCheckStatus($phone, $targetPhone) {
    $targetPhone = normalizeWhatsAppPhone($targetPhone);
    $customer = fetchOne("SELECT * FROM customers WHERE phone = ?", [$targetPhone]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan tidak ditemukan dengan nomor HP tersebut.");
        return;
    }
    
    $status = $customer['status'] === 'active' ? 'Aktif' : 'Isolir';
    $message = "Status Pelanggan\n\n";
    $message .= "Nama: {$customer['name']}\n";
    $message .= "No HP: {$customer['phone']}\n";
    $message .= "PPPoE Username: {$customer['pppoe_username']}\n";
    $message .= "Status: {$status}\n";
    if ($customer['status'] === 'isolated') {
        $message .= "\nKoneksi sedang diisolir karena belum bayar.";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppBillingCheck($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $username = trim($args);
    if ($username === '') {
        sendWhatsAppResponse($phone, "Format: /billing_cek <pppoe_username>");
        return;
    }
    
    $customer = fetchOne("SELECT c.*, p.name AS package_name, p.price AS package_price FROM customers c LEFT JOIN packages p ON c.package_id = p.id WHERE c.pppoe_username = ?", [$username]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE customer_id = ? ORDER BY due_date DESC LIMIT 1", [$customer['id']]);
    
    $message = "Tagihan Pelanggan\n\n";
    $message .= "Nama: {$customer['name']}\n";
    $message .= "PPPoE: {$customer['pppoe_username']}\n";
    $message .= "Paket: " . ($customer['package_name'] ?? '-') . "\n";
    
    if ($invoice) {
        $status = $invoice['status'] === 'paid' ? 'Lunas' : 'Belum Lunas';
        $message .= "Invoice: {$invoice['invoice_number']}\n";
        $message .= "Jumlah: " . formatCurrency($invoice['amount']) . "\n";
        $message .= "Jatuh tempo: " . formatDate($invoice['due_date']) . "\n";
        $message .= "Status: {$status}\n";
    } else {
        $message .= "Belum ada invoice untuk pelanggan ini.\n";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppBillingInvoice($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $username = trim($args);
    if ($username === '') {
        sendWhatsAppResponse($phone, "Format: /billing_invoice <pppoe_username>");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    $invoices = fetchAll("SELECT * FROM invoices WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5", [$customer['id']]);
    if (empty($invoices)) {
        sendWhatsAppResponse($phone, "Belum ada invoice untuk pelanggan {$customer['name']}.");
        return;
    }
    
    $message = "Daftar Invoice {$customer['name']}\n\n";
    foreach ($invoices as $inv) {
        $status = $inv['status'] === 'paid' ? 'Lunas' : 'Belum Lunas';
        $message .= "#{$inv['invoice_number']} - " . formatCurrency($inv['amount']) . " - {$status}\n";
        $message .= "Jatuh tempo: " . formatDate($inv['due_date']) . "\n\n";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppBillingIsolir($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $username = trim($args);
    if ($username === '') {
        sendWhatsAppResponse($phone, "Format: /billing_isolir <pppoe_username>");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    if (isCustomerIsolated($customer['id'])) {
        sendWhatsAppResponse($phone, "Pelanggan ini sudah dalam status isolir.");
        return;
    }
    
    if (isolateCustomer($customer['id'])) {
        sendWhatsAppResponse($phone, "Pelanggan {$customer['name']} berhasil diisolir.");
    } else {
        sendWhatsAppResponse($phone, "Gagal mengisolir pelanggan {$customer['name']}.");
    }
}

function handleWhatsAppBillingBukaIsolir($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $username = trim($args);
    if ($username === '') {
        sendWhatsAppResponse($phone, "Format: /billing_bukaisolir <pppoe_username>");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    if (!isCustomerIsolated($customer['id'])) {
        sendWhatsAppResponse($phone, "Pelanggan ini tidak dalam status isolir.");
        return;
    }
    
    if (unisolateCustomer($customer['id'])) {
        sendWhatsAppResponse($phone, "Pelanggan {$customer['name']} berhasil dibuka isolirnya.");
    } else {
        sendWhatsAppResponse($phone, "Gagal membuka isolir pelanggan {$customer['name']}.");
    }
}

function handleWhatsAppBillingLunas($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $invoiceNumber = trim($args);
    if ($invoiceNumber === '') {
        sendWhatsAppResponse($phone, "Format: /billing_lunas <no_invoice>");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
    if (!$invoice) {
        sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} tidak ditemukan.");
        return;
    }
    
    if ($invoice['status'] === 'paid') {
        sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} sudah berstatus lunas.");
        return;
    }
    
    $updateData = [
        'status' => 'paid',
        'updated_at' => date('Y-m-d H:i:s'),
        'paid_at' => date('Y-m-d H:i:s'),
        'payment_method' => 'WhatsApp Bot'
    ];
    
    update('invoices', $updateData, 'id = ?', [$invoice['id']]);
    
    if (isCustomerIsolated($invoice['customer_id'])) {
        unisolateCustomer($invoice['customer_id']);
    }
    
    logActivity('BOT_INVOICE_PAID', "Invoice: {$invoice['invoice_number']}");
    sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} berhasil ditandai lunas dan isolir pelanggan (jika ada) dibuka.");
}

function handleWhatsAppInvoiceCreate($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah invoice hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendWhatsAppResponse($phone, "Format: /invoice_create <pppoe_username> <amount> <due_date> [desc]");
        return;
    }
    
    $username = $parts[0];
    $amount = (float)$parts[1];
    $dueDate = $parts[2];
    $description = '';
    if (count($parts) > 3) {
        $description = trim(implode(' ', array_slice($parts, 3)));
    }
    
    if (strtotime($dueDate) === false) {
        sendWhatsAppResponse($phone, "Format tanggal tidak valid. Gunakan YYYY-MM-DD.");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendWhatsAppResponse($phone, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    $invoiceData = [
        'invoice_number' => generateInvoiceNumber(),
        'customer_id' => $customer['id'],
        'amount' => $amount,
        'status' => 'unpaid',
        'due_date' => $dueDate,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    if ($description !== '') {
        $invoiceData['description'] = $description;
    }
    
    insert('invoices', $invoiceData);
    logActivity('CREATE_INVOICE', "Manual invoice via WhatsApp for customer: {$customer['name']}");
    
    sendWhatsAppResponse($phone, "Invoice berhasil dibuat: {$invoiceData['invoice_number']} untuk {$customer['name']}.");
}

function handleWhatsAppInvoiceEdit($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah invoice hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 4) {
        sendWhatsAppResponse($phone, "Format: /invoice_edit <invoice_number> <amount> <due_date> <status>");
        return;
    }
    
    $invoiceNumber = $parts[0];
    $amount = (float)$parts[1];
    $dueDate = $parts[2];
    $status = strtolower($parts[3]);
    
    if (strtotime($dueDate) === false) {
        sendWhatsAppResponse($phone, "Format tanggal tidak valid. Gunakan YYYY-MM-DD.");
        return;
    }
    
    if (!in_array($status, ['unpaid', 'paid', 'cancelled'], true)) {
        sendWhatsAppResponse($phone, "Status tidak valid. Gunakan unpaid, paid, atau cancelled.");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
    if (!$invoice) {
        sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} tidak ditemukan.");
        return;
    }
    
    $updateData = [
        'amount' => $amount,
        'due_date' => $dueDate,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if ($status === 'paid' && $invoice['status'] !== 'paid') {
        $updateData['paid_at'] = date('Y-m-d H:i:s');
        $updateData['payment_method'] = 'WhatsApp Bot';
        if (isCustomerIsolated($invoice['customer_id'])) {
            unisolateCustomer($invoice['customer_id']);
        }
    }
    
    update('invoices', $updateData, 'id = ?', [$invoice['id']]);
    logActivity('EDIT_INVOICE', "Invoice: {$invoice['invoice_number']}");
    
    sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} berhasil diperbarui.");
}

function handleWhatsAppInvoiceDelete($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah invoice hanya untuk admin.");
        return;
    }
    
    $invoiceNumber = trim($args);
    if ($invoiceNumber === '') {
        sendWhatsAppResponse($phone, "Format: /invoice_delete <invoice_number>");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
    if (!$invoice) {
        sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} tidak ditemukan.");
        return;
    }
    
    if ($invoice['status'] === 'paid') {
        sendWhatsAppResponse($phone, "Invoice yang sudah lunas tidak dapat dihapus.");
        return;
    }
    
    delete('invoices', 'id = ?', [$invoice['id']]);
    logActivity('DELETE_INVOICE', "Invoice: {$invoice['invoice_number']}");
    
    sendWhatsAppResponse($phone, "Invoice {$invoiceNumber} berhasil dihapus.");
}

function handleWhatsAppPppoeProfileList($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $profiles = mikrotikGetProfiles();
    if (empty($profiles)) {
        sendWhatsAppResponse($phone, "Tidak ada profile PPPoE atau gagal mengambil data.");
        return;
    }
    
    $message = "Profile PPPoE\n\n";
    foreach ($profiles as $p) {
        $id = $p['.id'] ?? '-';
        $name = $p['name'] ?? '-';
        $rate = $p['rate-limit'] ?? '-';
        $message .= "{$id} | {$name} | {$rate}\n";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppMikrotikSetProfile($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 2) {
        $msg = "Format: /mt_setprofile <pppoe_username> <profile>";
        sendWhatsAppResponse($phone, $msg);
        return;
    }
    
    $username = $parts[0];
    $profile = $parts[1];
    
    $ok = mikrotikSetProfile($username, $profile);
    if (!$ok) {
        sendWhatsAppResponse($phone, "Gagal mengubah profile PPPoE {$username} ke {$profile}.");
        return;
    }
    
    mikrotikRemoveActiveSessionByName($username);
    sendWhatsAppResponse($phone, "Profile PPPoE {$username} berhasil diubah ke {$profile} dan session aktifnya dihapus.");
}

function handleWhatsAppMikrotikResource($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $res = mikrotikGetResource();
    if (!$res) {
        sendWhatsAppResponse($phone, "Tidak dapat mengambil resource MikroTik. Cek konfigurasi di Settings.");
        return;
    }
    
    $cpu = $res['cpu-load'] ?? '-';
    $memTotal = $res['total-memory'] ?? '-';
    $memFree = $res['free-memory'] ?? '-';
    $hddTotal = $res['total-hdd-space'] ?? '-';
    $hddFree = $res['free-hdd-space'] ?? '-';
    $uptime = $res['uptime'] ?? '-';
    
    $message = "Resource MikroTik\n\n";
    $message .= "CPU Load: {$cpu}%\n";
    $message .= "Memory: {$memFree} / {$memTotal}\n";
    $message .= "HDD: {$hddFree} / {$hddTotal}\n";
    $message .= "Uptime: {$uptime}\n";
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppMikrotikOnline($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $sessions = mikrotikGetActiveSessions();
    if (!is_array($sessions)) {
        sendWhatsAppResponse($phone, "Tidak dapat mengambil data PPPoE aktif.");
        return;
    }
    
    $total = count($sessions);
    if ($total === 0) {
        sendWhatsAppResponse($phone, "Tidak ada PPPoE yang sedang online.");
        return;
    }
    
    $message = "PPPoE Online: {$total}\n\n";
    $maxList = 30;
    $count = 0;
    foreach ($sessions as $s) {
        $name = $s['name'] ?? '-';
        $addr = $s['address'] ?? '-';
        $uptime = $s['uptime'] ?? '-';
        $message .= "- {$name} ({$addr}) up {$uptime}\n";
        $count++;
        if ($count >= $maxList) {
            break;
        }
    }
    if ($total > $maxList) {
        $message .= "\n...dan " . ($total - $maxList) . " user lain.";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppMikrotikPing($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $target = trim($args);
    if ($target === '') {
        sendWhatsAppResponse($phone, "Format: /mt_ping <ip/host>");
        return;
    }
    
    $result = mikrotikPing($target);
    if (!$result) {
        sendWhatsAppResponse($phone, "Gagal melakukan ping dari MikroTik ke {$target}.");
        return;
    }
    
    $sent = $result['sent'];
    $recv = $result['received'];
    $loss = $result['loss'];
    $avg = $result['avg'] !== null ? round($result['avg'], 2) . " ms" : '-';
    
    $message = "Ping dari MikroTik\n\n";
    $message .= "Target: {$target}\n";
    $message .= "Terkirim: {$sent}\n";
    $message .= "Diterima: {$recv}\n";
    $message .= "Loss: {$loss}%\n";
    $message .= "Rata-rata: {$avg}\n";
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppPppoeList($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $users = mikrotikGetPppoeUsers();
    if (empty($users)) {
        sendWhatsAppResponse($phone, "Tidak ada user PPPoE atau gagal mengambil data.");
        return;
    }
    
    $message = "Daftar User PPPoE\n\n";
    $max = 50;
    $count = 0;
    foreach ($users as $u) {
        $name = $u['name'] ?? '-';
        $profile = $u['profile'] ?? '-';
        $disabled = $u['disabled'] ?? 'false';
        $status = $disabled === 'true' ? 'Nonaktif' : 'Aktif';
        $message .= "- {$name} ({$profile}) {$status}\n";
        $count++;
        if ($count >= $max) {
            break;
        }
    }
    if (count($users) > $max) {
        $message .= "\n...dan " . (count($users) - $max) . " user lain.";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppPppoeAdd($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        $msg = "Format: /pppoe_add <user> <pass> <profile>";
        sendWhatsAppResponse($phone, $msg);
        return;
    }
    
    $user = $parts[0];
    $pass = $parts[1];
    $profile = $parts[2];
    
    $result = mikrotikAddSecret($user, $pass, $profile, 'pppoe');
    if ($result['success']) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} berhasil ditambahkan dengan profile {$profile}.");
    } else {
        sendWhatsAppResponse($phone, "Gagal menambah user PPPoE {$user}: {$result['message']}");
    }
}

function handleWhatsAppPppoeEdit($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendWhatsAppResponse($phone, "Format: /pppoe_edit <user> <pass> <profile>");
        return;
    }
    
    $user = $parts[0];
    $pass = $parts[1];
    $profile = $parts[2];
    
    $secret = mikrotikGetSecretByName($user);
    if (!$secret || empty($secret['.id'])) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} tidak ditemukan.");
        return;
    }
    
    $result = mikrotikUpdateSecret($secret['.id'], ['password' => $pass, 'profile' => $profile]);
    if ($result['success']) {
        mikrotikRemoveActiveSessionByName($user);
        sendWhatsAppResponse($phone, "User PPPoE {$user} berhasil diperbarui.");
    } else {
        sendWhatsAppResponse($phone, "Gagal memperbarui user PPPoE {$user}: {$result['message']}");
    }
}

function handleWhatsAppPppoeDel($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $user = trim($args);
    if ($user === '') {
        sendWhatsAppResponse($phone, "Format: /pppoe_del <user>");
        return;
    }
    
    $secret = mikrotikGetSecretByName($user);
    if (!$secret || empty($secret['.id'])) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} tidak ditemukan.");
        return;
    }
    
    $result = mikrotikDeleteSecret($secret['.id']);
    if ($result['success']) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} berhasil dihapus.");
    } else {
        sendWhatsAppResponse($phone, "Gagal menghapus user PPPoE {$user}: {$result['message']}");
    }
}

function handleWhatsAppPppoeDisable($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $user = trim($args);
    if ($user === '') {
        sendWhatsAppResponse($phone, "Format: /pppoe_disable <user>");
        return;
    }
    
    $secret = mikrotikGetSecretByName($user);
    if (!$secret || empty($secret['.id'])) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} tidak ditemukan.");
        return;
    }
    
    $result = mikrotikUpdateSecret($secret['.id'], ['disabled' => 'true']);
    if ($result['success']) {
        mikrotikRemoveActiveSessionByName($user);
        sendWhatsAppResponse($phone, "User PPPoE {$user} berhasil dinonaktifkan.");
    } else {
        sendWhatsAppResponse($phone, "Gagal menonaktifkan user PPPoE {$user}: {$result['message']}");
    }
}

function handleWhatsAppPppoeEnable($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $user = trim($args);
    if ($user === '') {
        sendWhatsAppResponse($phone, "Format: /pppoe_enable <user>");
        return;
    }
    
    $secret = mikrotikGetSecretByName($user);
    if (!$secret || empty($secret['.id'])) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} tidak ditemukan.");
        return;
    }
    
    $result = mikrotikUpdateSecret($secret['.id'], ['disabled' => 'false']);
    if ($result['success']) {
        sendWhatsAppResponse($phone, "User PPPoE {$user} berhasil diaktifkan.");
    } else {
        sendWhatsAppResponse($phone, "Gagal mengaktifkan user PPPoE {$user}: {$result['message']}");
    }
}

function handleWhatsAppHotspotList($phone) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $users = mikrotikGetHotspotUsers();
    if (empty($users)) {
        sendWhatsAppResponse($phone, "Tidak ada user Hotspot atau gagal mengambil data.");
        return;
    }
    
    $message = "Daftar User Hotspot\n\n";
    $max = 50;
    $count = 0;
    foreach ($users as $u) {
        $name = $u['name'] ?? '-';
        $profile = $u['profile'] ?? '-';
        $message .= "- {$name} ({$profile})\n";
        $count++;
        if ($count >= $max) {
            break;
        }
    }
    if (count($users) > $max) {
        $message .= "\n...dan " . (count($users) - $max) . " user lain.";
    }
    
    sendWhatsAppResponse($phone, $message);
}

function handleWhatsAppHotspotAdd($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendWhatsAppResponse($phone, "Format: /hs_add <user> <pass> <profile>");
        return;
    }
    
    $user = $parts[0];
    $pass = $parts[1];
    $profile = $parts[2];
    
    $ok = mikrotikAddHotspotUser($user, $pass, $profile);
    if ($ok) {
        sendWhatsAppResponse($phone, "User Hotspot {$user} berhasil ditambahkan dengan profile {$profile}.");
    } else {
        sendWhatsAppResponse($phone, "Gagal menambah user Hotspot {$user}.");
    }
}

function handleWhatsAppHotspotDel($phone, $args) {
    if (!isWhatsAppAdmin($phone)) {
        sendWhatsAppResponse($phone, "Perintah MikroTik hanya untuk admin.");
        return;
    }
    
    $user = trim($args);
    if ($user === '') {
        sendWhatsAppResponse($phone, "Format: /hs_del <user>");
        return;
    }
    
    $ok = mikrotikDeleteHotspotUser($user);
    if ($ok) {
        sendWhatsAppResponse($phone, "User Hotspot {$user} berhasil dihapus.");
    } else {
        sendWhatsAppResponse($phone, "Gagal menghapus user Hotspot {$user}.");
    }
}
