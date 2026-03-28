<?php
/**
 * Webhook Handler - Telegram Bot
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
    
    logActivity('TELEGRAM_WEBHOOK', "Received webhook");
    
    // Parse JSON data
    $data = json_decode($json, true);
    
    if (!$data) {
        logError('Telegram webhook: Invalid JSON');
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    // Log webhook
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO webhook_logs (source, payload, status_code, response, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute(['telegram', $json, 200, 'Received']);
    
    $message = $data['message'] ?? null;
    $callbackQuery = $data['callback_query'] ?? null;
    
    if ($callbackQuery) {
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $callbackDataString = $callbackQuery['data'] ?? '';
        $callbackData = [];
        
        if ($callbackDataString !== '') {
            parse_str($callbackDataString, $callbackData);
        }
        
        $action = $callbackData['action'] ?? '';
        
        switch ($action) {
            case 'pay_invoice':
                handlePayInvoice($chatId, $callbackData);
                break;
                
            case 'check_status':
                handleCheckStatus($chatId, $callbackData);
                break;
                
            case 'help':
                handleHelp($chatId);
                break;
                
            case 'billing_menu':
                handleBillingMenu($chatId);
                break;
                
            case 'billing_help_cek':
                handleBillingHelpCek($chatId);
                break;
                
            case 'billing_help_isolir':
                handleBillingHelpIsolir($chatId);
                break;
                
            case 'billing_help_bukaisolir':
                handleBillingHelpBukaIsolir($chatId);
                break;
            
            case 'billing_help_invoice':
                handleBillingHelpInvoice($chatId);
                break;
            
            case 'billing_help_lunas':
                handleBillingHelpLunas($chatId);
                break;
            
            case 'billing_mark_paid':
                handleBillingMarkPaidCallback($chatId, $callbackData, $callbackQuery);
                break;
            
            case 'mt_pppoe_kick':
                handlePppoeKickCallback($chatId, $callbackData);
                break;
            
            case 'mt_pppoe_disable':
                handlePppoeDisableCallback($chatId, $callbackData);
                break;
            
            case 'mt_pppoe_enable':
                handlePppoeEnableCallback($chatId, $callbackData);
                break;
            
            case 'mt_pppoe_del':
                handlePppoeDelCallback($chatId, $callbackData, $callbackQuery);
                break;
            
            case 'mt_hotspot_del':
                handleHotspotDelCallback($chatId, $callbackData, $callbackQuery);
                break;
            
            case 'mikrotik_menu':
                handleMikrotikMenu($chatId);
                break;
            
            case 'mt_resource':
                handleMikrotikResource($chatId);
                break;
            
            case 'mt_online':
                handleMikrotikOnline($chatId);
                break;
            
            case 'mt_ping_help':
                handleMikrotikPingHelp($chatId);
                break;
            
            case 'mt_pppoe_help':
                handleMikrotikPppoeHelp($chatId);
                break;
            
            case 'mt_hotspot_help':
                handleMikrotikHotspotHelp($chatId);
                break;
                
            default:
                handleHelp($chatId);
        }
    } elseif ($message) {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        
        if ($text !== '' && $text[0] === '/') {
            handleCommand($chatId, $text);
        } else {
            handleRegularMessage($chatId, $text);
        }
    }
    
    echo json_encode(['success' => true, 'message' => 'Webhook processed']);
    
} catch (Exception $e) {
    logError("Telegram webhook error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handlePayInvoice($chatId, $data) {
    $invoiceId = $data['invoice_id'] ?? '';
    
    // Get invoice details
    $invoice = fetchOne("SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = ?", [$invoiceId]);
    
    if (!$invoice) {
        sendMessage($chatId, "❌ Invoice tidak ditemukan.");
        return;
    }
    
    $gateway = getSetting('DEFAULT_PAYMENT_GATEWAY', 'tripay');
    $payResult = generatePaymentLink(
        $invoice['invoice_number'],
        $invoice['amount'],
        $invoice['customer_name'] ?? '-',
        $invoice['customer_phone'] ?? '',
        $invoice['due_date'],
        $gateway
    );
    
    $paymentLink = ($payResult['success'] ?? false) ? $payResult['link'] : 'Gateway error';
    
    $message = "💳 *Invoice #{$invoice['invoice_number']}*\n\n";
    $message .= "Pelanggan: {$invoice['customer_name']}\n";
    $message .= "Jumlah: " . formatCurrency($invoice['amount']) . "\n";
    $message .= "Jatuh Tempo: " . formatDate($invoice['due_date']) . "\n\n";
    $message .= "Silakan bayar melalui link berikut:\n";
    $message .= $paymentLink;
    
    sendMessage($chatId, $message);
}

function handleCheckStatus($chatId, $data) {
    $phone = $data['phone'] ?? '';
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);

    // Get customer by phone
    $customer = fetchOne("SELECT * FROM customers WHERE phone LIKE ?", ["%{$phone}"]);
    
    if (!$customer) {
        sendMessage($chatId, "❌ Pelanggan tidak ditemukan dengan nomor HP tersebut.");
        return;
    }
    
    // Get customer status
    $status = $customer['status'] === 'active' ? 'Aktif' : 'Isolir';
    
    $message = "📊 *Status Pelanggan*\n\n";
    $message .= "Nama: {$customer['name']}\n";
    $message .= "No HP: {$customer['phone']}\n";
    $message .= "PPPoE Username: {$customer['pppoe_username']}\n";
    $message .= "Status: {$status}\n";
    
    if ($customer['status'] === 'isolated') {
        $message .= "\n⚠️ Koneksi sedang diisolir karena belum bayar.";
    }
    
    sendMessage($chatId, $message);
}

function handleHelp($chatId) {
    $message = "🤖 GEMBOK Bot Commands\n\n";
    $message .= "Untuk pelanggan:\n";
    $message .= "/pay_invoice &lt;invoice_id&gt; - Cek dan bayar invoice\n";
    $message .= "/check_status &lt;no_hp&gt; - Cek status pelanggan\n";
    $message .= "/help - Tampilkan bantuan ini\n\n";
    
    if (isAdminChat($chatId)) {
        $message .= "Untuk admin:\n";
        $message .= "/menu - Tampilkan menu utama\n";
        $message .= "/billing_cek &lt;pppoe_username&gt; - Cek tagihan pelanggan\n";
        $message .= "/billing_invoice &lt;pppoe_username&gt; - Daftar invoice pelanggan\n";
        $message .= "/billing_isolir &lt;pppoe_username&gt; - Isolir pelanggan\n";
        $message .= "/billing_bukaisolir &lt;pppoe_username&gt; - Buka isolir pelanggan\n";
        $message .= "/billing_lunas &lt;no_invoice&gt; - Tandai invoice lunas\n";
        $message .= "/invoice_create &lt;pppoe_username&gt; &lt;amount&gt; &lt;due_date&gt; [desc]\n";
        $message .= "/invoice_edit &lt;invoice_number&gt; &lt;amount&gt; &lt;due_date&gt; &lt;status&gt;\n";
        $message .= "/invoice_delete &lt;invoice_number&gt;\n";
        $message .= "/mt_setprofile &lt;pppoe_username&gt; &lt;profile&gt; - Ganti profile PPPoE\n";
        $message .= "/mt_resource - Cek resource MikroTik\n";
        $message .= "/mt_online - Cek user PPPoE online\n";
        $message .= "/mt_ping &lt;ip/host&gt; - Ping dari MikroTik\n";
        $message .= "/pppoe_list - Daftar user PPPoE\n";
        $message .= "/pppoe_add &lt;user&gt; &lt;pass&gt; &lt;profile&gt; - Tambah PPPoE\n";
        $message .= "/pppoe_edit &lt;user&gt; &lt;pass&gt; &lt;profile&gt; - Ubah PPPoE\n";
        $message .= "/pppoe_del &lt;user&gt; - Hapus PPPoE\n";
        $message .= "/pppoe_disable &lt;user&gt; - Nonaktifkan PPPoE\n";
        $message .= "/pppoe_enable &lt;user&gt; - Aktifkan PPPoE\n";
        $message .= "/pppoe_profile_list\n";
        $message .= "/hs_list - Daftar user Hotspot\n";
        $message .= "/hs_add &lt;user&gt; &lt;pass&gt; &lt;profile&gt; - Tambah Hotspot\n";
        $message .= "/hs_del &lt;user&gt; - Hapus Hotspot\n";
    }
    
    sendMessage($chatId, $message);
}

function handleRegularMessage($chatId, $text) {
    $message = "Terima kasih atas pesan Anda.\n\n";
    $message .= "Untuk menggunakan bot ini, silakan gunakan command yang tersedia.\n";
    $message .= "Ketik /help untuk melihat daftar command.";
    
    sendMessage($chatId, $message);
}

function sendMessage($chatId, $text, $options = []) {
    if (empty(TELEGRAM_BOT_TOKEN)) {
        logError('Telegram bot token not configured');
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if (!empty($options)) {
        $data = array_merge($data, $options);
        if (isset($data['reply_markup']) && is_array($data['reply_markup'])) {
            $data['reply_markup'] = json_encode($data['reply_markup']);
        }
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logActivity('TELEGRAM_SEND', "To: {$chatId}, Status code: {$httpCode}");
    
    return $httpCode === 200;
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
    if (empty(TELEGRAM_BOT_TOKEN)) {
        logError('Telegram bot token not configured');
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/editMessageText";
    
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup !== null) {
        $data['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    logActivity('TELEGRAM_EDIT', "To: {$chatId}, Msg: {$messageId}, Status code: {$httpCode}");
    
    return $httpCode === 200;
}

function isAdminChat($chatId) {
    $adminChatId = getSetting('TELEGRAM_ADMIN_CHAT_ID', '');
    if ($adminChatId === '') {
        return false;
    }
    return (string)$chatId === (string)$adminChatId;
}

function handleCommand($chatId, $text) {
    $parts = explode(' ', trim($text), 2);
    $command = strtolower($parts[0]);
    $args = $parts[1] ?? '';
    
    switch ($command) {
        case '/start':
        case '/menu':
            handleMenu($chatId);
            break;
            
        case '/help':
            handleHelp($chatId);
            break;
            
        case '/pay_invoice':
            $invoiceId = trim($args);
            if ($invoiceId === '') {
                sendMessage($chatId, "Format: /pay_invoice &lt;invoice_id&gt;");
                break;
            }
            handlePayInvoice($chatId, ['invoice_id' => $invoiceId]);
            break;
            
        case '/check_status':
            $phone = trim($args);
            if ($phone === '') {
                sendMessage($chatId, "Format: /check_status &lt;no_hp&gt;");
                break;
            }
            handleCheckStatus($chatId, ['phone' => $phone]);
            break;
            
        case '/billing_cek':
            handleBillingCheck($chatId, $args);
            break;
            
        case '/billing_invoice':
            handleBillingInvoice($chatId, $args);
            break;
            
        case '/billing_isolir':
            handleBillingIsolir($chatId, $args);
            break;
            
        case '/billing_bukaisolir':
            handleBillingBukaIsolir($chatId, $args);
            break;
        
        case '/billing_lunas':
            handleBillingLunas($chatId, $args);
            break;
            
        case '/invoice_create':
            handleInvoiceCreate($chatId, $args);
            break;
            
        case '/invoice_edit':
            handleInvoiceEdit($chatId, $args);
            break;
            
        case '/invoice_delete':
            handleInvoiceDelete($chatId, $args);
            break;
            
        case '/mt_resource':
            handleMikrotikResource($chatId);
            break;
            
        case '/mt_online':
            handleMikrotikOnline($chatId);
            break;
            
        case '/mt_ping':
            handleMikrotikPing($chatId, $args);
            break;
        
        case '/mt_setprofile':
            handleMikrotikSetProfile($chatId, $args);
            break;
        
        case '/pppoe_list':
            handlePppoeList($chatId);
            break;
        
        case '/pppoe_add':
            handlePppoeAdd($chatId, $args);
            break;
        
        case '/pppoe_edit':
            handlePppoeEdit($chatId, $args);
            break;
        
        case '/pppoe_del':
            handlePppoeDel($chatId, $args);
            break;
        
        case '/pppoe_disable':
            handlePppoeDisable($chatId, $args);
            break;
        
        case '/pppoe_enable':
            handlePppoeEnable($chatId, $args);
            break;
        
        case '/pppoe_profile_list':
            handlePppoeProfileList($chatId);
            break;
        
        case '/hs_list':
            handleHotspotList($chatId);
            break;
        
        case '/hs_add':
            handleHotspotAdd($chatId, $args);
            break;
        
        case '/hs_del':
            handleHotspotDel($chatId, $args);
            break;
            
        default:
            handleRegularMessage($chatId, $text);
    }
}

function handleMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📄 Billing', 'callback_data' => 'action=billing_menu'],
                ['text' => '📡 MikroTik', 'callback_data' => 'action=mikrotik_menu']
            ],
            [
                ['text' => '❓ Help', 'callback_data' => 'action=help']
            ]
        ]
    ];
    
    sendMessage($chatId, "Pilih menu:", ['reply_markup' => $keyboard]);
}

function handleBillingMenu($chatId) {
    if (!isAdminChat($chatId)) {
        sendMessage($chatId, "Perintah billing hanya untuk admin.");
        return;
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📄 Cek Tagihan', 'callback_data' => 'action=billing_help_cek'],
                ['text' => '📜 Daftar Invoice', 'callback_data' => 'action=billing_help_invoice']
            ],
            [
                ['text' => '🔒 Isolir Pelanggan', 'callback_data' => 'action=billing_help_isolir'],
                ['text' => '🔓 Buka Isolir', 'callback_data' => 'action=billing_help_bukaisolir']
            ],
            [
                ['text' => '✅ Tandai Lunas', 'callback_data' => 'action=billing_help_lunas']
            ]
        ]
    ];
    
    sendMessage($chatId, "Menu Billing Admin:", ['reply_markup' => $keyboard]);
}

function handleBillingHelpCek($chatId) {
    if (!isAdminChat($chatId)) return;
    $message = "📄 Cek Tagihan Pelanggan\n\nGunakan perintah:\n/billing_cek &lt;pppoe_username&gt;\n\nContoh:\n/billing_cek pelanggan001";
    sendMessage($chatId, $message);
}

function handleBillingHelpIsolir($chatId) {
    if (!isAdminChat($chatId)) return;
    $message = "🔒 Isolir Pelanggan\n\nGunakan perintah:\n/billing_isolir &lt;pppoe_username&gt;\n\nContoh:\n/billing_isolir pelanggan001";
    sendMessage($chatId, $message);
}

function handleBillingHelpBukaIsolir($chatId) {
    if (!isAdminChat($chatId)) return;
    $message = "🔓 Buka Isolir Pelanggan\n\nGunakan perintah:\n/billing_bukaisolir &lt;pppoe_username&gt;\n\nContoh:\n/billing_bukaisolir pelanggan001";
    sendMessage($chatId, $message);
}

function handleBillingHelpInvoice($chatId) {
    if (!isAdminChat($chatId)) return;
    $message = "📜 Daftar Invoice Pelanggan\n\nGunakan perintah:\n/billing_invoice &lt;pppoe_username&gt;\n\nContoh:\n/billing_invoice pelanggan001";
    sendMessage($chatId, $message);
}

function handleBillingHelpLunas($chatId) {
    if (!isAdminChat($chatId)) return;
    $message = "✅ Tandai Invoice Lunas\n\nGunakan perintah:\n/billing_lunas &lt;no_invoice&gt;\n\nContoh:\n/billing_lunas INV-2026-0001";
    sendMessage($chatId, $message);
}

function handleBillingCheck($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $username = trim($args);
    if ($username === '') {
        sendMessage($chatId, "Format: /billing_cek &lt;pppoe_username&gt;");
        return;
    }
    
    $customer = fetchOne("SELECT c.*, p.name AS package_name, p.price AS package_price FROM customers c LEFT JOIN packages p ON c.package_id = p.id WHERE c.pppoe_username = ?", [$username]);
    if (!$customer) {
        sendMessage($chatId, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE customer_id = ? ORDER BY due_date DESC LIMIT 1", [$customer['id']]);
    $message = "📄 Tagihan Pelanggan\n\nNama: {$customer['name']}\nPPPoE: {$customer['pppoe_username']}\nPaket: " . ($customer['package_name'] ?? '-') . "\n";
    
    if ($invoice) {
        $status = $invoice['status'] === 'paid' ? 'Lunas' : 'Belum Lunas';
        $message .= "Invoice: {$invoice['invoice_number']}\nJumlah: " . formatCurrency($invoice['amount']) . "\nJatuh tempo: " . formatDate($invoice['due_date']) . "\nStatus: {$status}\n";
    } else {
        $message .= "Belum ada invoice untuk pelanggan ini.\n";
    }
    
    $options = [];
    if ($invoice) {
        $buttons = [];
        $buttons[] = [['text' => '📜 Daftar Invoice', 'callback_data' => 'action=billing_help_invoice']];
        if ($invoice['status'] !== 'paid') {
            $buttons[] = [['text' => '✅ Tandai Lunas', 'callback_data' => 'action=billing_mark_paid&inv=' . urlencode($invoice['invoice_number'])]];
        }
        $options['reply_markup'] = ['inline_keyboard' => $buttons];
    }
    sendMessage($chatId, $message, $options);
}

function handleBillingInvoice($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $username = trim($args);
    if ($username === '') {
        sendMessage($chatId, "Format: /billing_invoice &lt;pppoe_username&gt;");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendMessage($chatId, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    $invoices = fetchAll("SELECT * FROM invoices WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5", [$customer['id']]);
    if (empty($invoices)) {
        sendMessage($chatId, "Belum ada invoice untuk pelanggan {$customer['name']}.");
        return;
    }
    
    $message = "📜 Daftar Invoice {$customer['name']}\n\n";
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($invoices as $inv) {
        $status = $inv['status'] === 'paid' ? 'Lunas' : 'Belum Lunas';
        $message .= "#{$inv['invoice_number']} - " . formatCurrency($inv['amount']) . " - {$status}\nJatuh tempo: " . formatDate($inv['due_date']) . "\n\n";
        
        if ($inv['status'] !== 'paid') {
            $keyboard['inline_keyboard'][] = [['text' => "✅ {$inv['invoice_number']}", 'callback_data' => 'action=billing_mark_paid&inv=' . urlencode($inv['invoice_number'])]];
        }
    }
    
    if (empty($keyboard['inline_keyboard'])) {
        sendMessage($chatId, $message);
    } else {
        sendMessage($chatId, $message, ['reply_markup' => $keyboard]);
    }
}

function handleBillingIsolir($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $username = trim($args);
    if ($username === '') {
        sendMessage($chatId, "Format: /billing_isolir &lt;pppoe_username&gt;");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendMessage($chatId, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    if (isCustomerIsolated($customer['id'])) {
        sendMessage($chatId, "Pelanggan ini sudah dalam status isolir.");
        return;
    }
    
    if (isolateCustomer($customer['id'])) {
        sendMessage($chatId, "Pelanggan {$customer['name']} berhasil diisolir.");
    } else {
        sendMessage($chatId, "Gagal mengisolir pelanggan {$customer['name']}.");
    }
}

function handleBillingBukaIsolir($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $username = trim($args);
    if ($username === '') {
        sendMessage($chatId, "Format: /billing_bukaisolir &lt;pppoe_username&gt;");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendMessage($chatId, "Pelanggan dengan PPPoE username {$username} tidak ditemukan.");
        return;
    }
    
    if (!isCustomerIsolated($customer['id'])) {
        sendMessage($chatId, "Pelanggan ini tidak dalam status isolir.");
        return;
    }
    
    if (unisolateCustomer($customer['id'])) {
        sendMessage($chatId, "Pelanggan {$customer['name']} berhasil dibuka isolirnya.");
    } else {
        sendMessage($chatId, "Gagal membuka isolir pelanggan {$customer['name']}.");
    }
}

function handleBillingLunas($chatId, $args, $silent = false) {
    if (!isAdminChat($chatId)) return;
    $invoiceNumber = trim($args);
    if ($invoiceNumber === '') {
        sendMessage($chatId, "Format: /billing_lunas &lt;no_invoice&gt;");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
    if (!$invoice) {
        sendMessage($chatId, "Invoice {$invoiceNumber} tidak ditemukan.");
        return;
    }
    
    if ($invoice['status'] === 'paid') {
        sendMessage($chatId, "Invoice {$invoiceNumber} sudah berstatus lunas.");
        return;
    }
    
    $updateData = ['status' => 'paid', 'updated_at' => date('Y-m-d H:i:s'), 'paid_at' => date('Y-m-d H:i:s'), 'payment_method' => 'Telegram Bot'];
    update('invoices', $updateData, 'id = ?', [$invoice['id']]);
    
    if (isCustomerIsolated($invoice['customer_id'])) {
        unisolateCustomer($invoice['customer_id']);
    }
    
    logActivity('BOT_INVOICE_PAID', "Invoice: {$invoice['invoice_number']}");
    if (!$silent) {
        sendMessage($chatId, "Invoice {$invoiceNumber} berhasil ditandai lunas.");
    }
}

function handleInvoiceCreate($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendMessage($chatId, "Format: /invoice_create <u_pppoe> <amount> <due_date> [desc]");
        return;
    }
    
    $username = $parts[0];
    $amount = (float)$parts[1];
    $dueDate = $parts[2];
    $description = (count($parts) > 3) ? trim(implode(' ', array_slice($parts, 3))) : '';
    
    if (strtotime($dueDate) === false) {
        sendMessage($chatId, "Format tanggal tidak valid (YYYY-MM-DD).");
        return;
    }
    
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
    if (!$customer) {
        sendMessage($chatId, "Pelanggan {$username} tidak ditemukan.");
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
    if ($description !== '') $invoiceData['description'] = $description;
    
    insert('invoices', $invoiceData);
    sendMessage($chatId, "Invoice {$invoiceData['invoice_number']} dibuat.");
}

function handleInvoiceEdit($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 4) {
        sendMessage($chatId, "Format: /invoice_edit <inv> <amount> <due> <status>");
        return;
    }
    
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$parts[0]]);
    if (!$invoice) {
        sendMessage($chatId, "Invoice {$parts[0]} tidak ditemukan.");
        return;
    }
    
    $status = strtolower($parts[3]);
    $updateData = [
        'amount' => (float)$parts[1],
        'due_date' => $parts[2],
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if ($status === 'paid' && $invoice['status'] !== 'paid') {
        $updateData['paid_at'] = date('Y-m-d H:i:s');
        $updateData['payment_method'] = 'Telegram Bot';
        if (isCustomerIsolated($invoice['customer_id'])) unisolateCustomer($invoice['customer_id']);
    }
    
    update('invoices', $updateData, 'id = ?', [$invoice['id']]);
    sendMessage($chatId, "Invoice diperbarui.");
}

function handleInvoiceDelete($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [trim($args)]);
    if (!$invoice) {
        sendMessage($chatId, "Invoice tidak ditemukan.");
        return;
    }
    if ($invoice['status'] === 'paid') {
        sendMessage($chatId, "Tidak bisa hapus invoice lunas.");
        return;
    }
    delete('invoices', 'id = ?', [$invoice['id']]);
    sendMessage($chatId, "Invoice dihapus.");
}

function handlePppoeProfileList($chatId) {
    if (!isAdminChat($chatId)) return;
    $profiles = mikrotikGetProfiles();
    if (empty($profiles)) {
        sendMessage($chatId, "Gagal ambil profile.");
        return;
    }
    $msg = "👤 *Profile PPPoE*\n\n";
    foreach ($profiles as $p) {
        $msg .= "- {$p['name']} | {$p['rate-limit']}\n";
    }
    sendMessage($chatId, $msg);
}

function handleBillingMarkPaidCallback($chatId, $data, $callbackQuery) {
    if (!isAdminChat($chatId)) return;
    $invoiceNumber = $data['inv'] ?? '';
    if ($invoiceNumber === '') {
        sendMessage($chatId, "Data invoice tidak valid.");
        return;
    }
    
    handleBillingLunas($chatId, $invoiceNumber, true);
    
    $messageId = $callbackQuery['message']['message_id'] ?? null;
    if ($messageId) {
        $invoice = fetchOne("SELECT * FROM invoices WHERE invoice_number = ?", [$invoiceNumber]);
        if ($invoice) {
            $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$invoice['customer_id']]);
            if ($customer) {
                // Refresh list
                handleBillingInvoice($chatId, $customer['pppoe_username']);
            }
        }
    }
}

function handleMikrotikMenu($chatId) {
    if (!isAdminChat($chatId)) return;
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📊 Resource', 'callback_data' => 'action=mt_resource'], ['text' => '📡 Online PPPoE', 'callback_data' => 'action=mt_online']],
            [['text' => '📶 Ping IP/Host', 'callback_data' => 'action=mt_ping_help']],
            [['text' => '👤 PPPoE Commands', 'callback_data' => 'action=mt_pppoe_help'], ['text' => '🌐 Hotspot Commands', 'callback_data' => 'action=mt_hotspot_help']]
        ]
    ];
    sendMessage($chatId, "Menu MikroTik Admin:", ['reply_markup' => $keyboard]);
}

function handleMikrotikResource($chatId) {
    if (!isAdminChat($chatId)) return;
    $res = mikrotikGetResource();
    if (!$res) {
        sendMessage($chatId, "Tidak dapat mengambil resource MikroTik.");
        return;
    }
    
    $cpu = $res['cpu-load'] ?? '-';
    $memFree = $res['free-memory'] ?? '-';
    $memTotal = $res['total-memory'] ?? '-';
    $uptime = $res['uptime'] ?? '-';
    
    $message = "📊 *Resource MikroTik*\n\nCPU Load: {$cpu}%\nMemory: {$memFree} / {$memTotal} bytes\nUptime: {$uptime}";
    sendMessage($chatId, $message);
}

function handleMikrotikOnline($chatId) {
    if (!isAdminChat($chatId)) return;
    $sessions = mikrotikGetActiveSessions();
    if (!is_array($sessions)) {
        sendMessage($chatId, "Tidak dapat mengambil data PPPoE aktif.");
        return;
    }
    
    $total = count($sessions);
    if ($total === 0) {
        sendMessage($chatId, "Tidak ada PPPoE yang sedang online.");
        return;
    }
    
    $message = "📡 *PPPoE Online: {$total}*\n\n";
    $keyboard = ['inline_keyboard' => []];
    $count = 0;
    
    foreach ($sessions as $s) {
        $name = $s['name'] ?? '-';
        $message .= "- {$name} ({$s['address']}) up {$s['uptime']}\n";
        if ($count < 10) {
            $keyboard['inline_keyboard'][] = [['text' => "❌ Kick {$name}", 'callback_data' => 'action=mt_pppoe_kick&name=' . urlencode($name)]];
        }
        $count++;
        if ($count >= 20) break;
    }
    
    sendMessage($chatId, $message, ['reply_markup' => $keyboard]);
}

function handleMikrotikPing($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $target = trim($args);
    if ($target === '') return;
    
    $result = mikrotikPing($target);
    if (!$result) {
        sendMessage($chatId, "Gagal melakukan ping.");
        return;
    }
    
    $avg = $result['avg'] !== null ? round($result['avg'], 2) . " ms" : '-';
    $message = "📶 *Ping Result*\nTarget: {$target}\nSent: {$result['sent']}\nRecv: {$result['received']}\nLoss: {$result['loss']}%\nAvg: {$avg}";
    sendMessage($chatId, $message);
}

function handleMikrotikPingHelp($chatId) {
    sendMessage($chatId, "📶 *Ping Help*\n/mt_ping &lt;ip/host&gt;");
}

function handleMikrotikSetProfile($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 2) {
        sendMessage($chatId, "Format: /mt_setprofile &lt;user&gt; &lt;profile&gt;");
        return;
    }
    
    if (mikrotikSetProfile($parts[0], $parts[1])) {
        mikrotikRemoveActiveSessionByName($parts[0]);
        sendMessage($chatId, "Profile {$parts[0]} diubah ke {$parts[1]}.");
    } else {
        sendMessage($chatId, "Gagal mengubah profile.");
    }
}

function handleMikrotikPppoeHelp($chatId) {
    $msg = "👤 *PPPoE Commands*\n/pppoe_list\n/pppoe_add &lt;u&gt; &lt;p&gt; &lt;prof&gt;\n/pppoe_edit &lt;u&gt; &lt;p&gt; &lt;prof&gt;\n/pppoe_del &lt;u&gt;\n/pppoe_disable &lt;u&gt;\n/pppoe_enable &lt;u&gt;";
    sendMessage($chatId, $msg);
}

function handleMikrotikHotspotHelp($chatId) {
    $msg = "🌐 *Hotspot Commands*\n/hs_list\n/hs_add &lt;u&gt; &lt;p&gt; &lt;prof&gt;\n/hs_del &lt;u&gt;";
    sendMessage($chatId, $msg);
}

function handlePppoeList($chatId) {
    if (!isAdminChat($chatId)) return;
    $users = mikrotikGetPppoeUsers();
    if (empty($users)) {
        sendMessage($chatId, "Tidak ada user PPPoE.");
        return;
    }
    
    $message = "👤 *Daftar User PPPoE*\n\n";
    $keyboard = ['inline_keyboard' => []];
    $count = 0;
    foreach ($users as $u) {
        $status = ($u['disabled'] ?? 'false') === 'true' ? '🚫' : '✅';
        $message .= "- {$u['name']} ({$u['profile']}) {$status}\n";
        if ($count < 10) {
            $keyboard['inline_keyboard'][] = [
                ['text' => "🚫 Kick", 'callback_data' => 'action=mt_pppoe_kick&name=' . urlencode($u['name'])],
                ['text' => "🗑 Del", 'callback_data' => 'action=mt_pppoe_del&name=' . urlencode($u['name'])]
            ];
        }
        $count++;
        if ($count >= 20) break;
    }
    sendMessage($chatId, $message, ['reply_markup' => $keyboard]);
}

function handlePppoeAdd($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendMessage($chatId, "Format: /pppoe_add &lt;u&gt; &lt;p&gt; &lt;prof&gt;");
        return;
    }
    
    $res = mikrotikAddSecret($parts[0], $parts[1], $parts[2]);
    sendMessage($chatId, $res['success'] ? "User ditambahkan." : "Gagal: " . $res['message']);
}

function handlePppoeEdit($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendMessage($chatId, "Format: /pppoe_edit &lt;u&gt; &lt;p&gt; &lt;prof&gt;");
        return;
    }
    
    $secret = mikrotikGetSecretByName($parts[0]);
    if (!$secret) {
        sendMessage($chatId, "User tidak ditemukan.");
        return;
    }
    
    $res = mikrotikUpdateSecret($secret['.id'], ['password' => $parts[1], 'profile' => $parts[2]]);
    if ($res['success']) {
        mikrotikRemoveActiveSessionByName($parts[0]);
        sendMessage($chatId, "User diperbarui.");
    } else {
        sendMessage($chatId, "Gagal: " . $res['message']);
    }
}

function handlePppoeDel($chatId, $args, $silent = false) {
    if (!isAdminChat($chatId)) return;
    $user = trim($args);
    $secret = mikrotikGetSecretByName($user);
    if (!$secret) {
        if (!$silent) sendMessage($chatId, "User tidak ditemukan.");
        return;
    }
    
    $res = mikrotikDeleteSecret($secret['.id']);
    if (!$silent) sendMessage($chatId, $res['success'] ? "User dihapus." : "Gagal.");
}

function handlePppoeDisable($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $user = trim($args);
    $secret = mikrotikGetSecretByName($user);
    if (!$secret) {
        sendMessage($chatId, "User tidak ditemukan.");
        return;
    }
    
    $res = mikrotikUpdateSecret($secret['.id'], ['disabled' => 'true']);
    if ($res['success']) {
        mikrotikRemoveActiveSessionByName($user);
        sendMessage($chatId, "User dinonaktifkan.");
    } else {
        sendMessage($chatId, "Gagal.");
    }
}

function handlePppoeEnable($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $user = trim($args);
    $secret = mikrotikGetSecretByName($user);
    if (!$secret) {
        sendMessage($chatId, "User tidak ditemukan.");
        return;
    }
    
    $res = mikrotikUpdateSecret($secret['.id'], ['disabled' => 'false']);
    sendMessage($chatId, $res['success'] ? "User diaktifkan." : "Gagal.");
}

function handlePppoeKickCallback($chatId, $data) {
    if (!isAdminChat($chatId)) return;
    $user = $data['name'] ?? '';
    if (mikrotikRemoveActiveSessionByName($user)) {
        sendMessage($chatId, "Session {$user} diputus.");
    } else {
        sendMessage($chatId, "Gagal memutus session.");
    }
}

function handlePppoeDisableCallback($chatId, $data) {
    handlePppoeDisable($chatId, $data['name'] ?? '');
}

function handlePppoeEnableCallback($chatId, $data) {
    handlePppoeEnable($chatId, $data['name'] ?? '');
}

function handlePppoeDelCallback($chatId, $data, $callbackQuery) {
    handlePppoeDel($chatId, $data['name'] ?? '', true);
    sendMessage($chatId, "User dihapus.");
}

function handleHotspotList($chatId) {
    if (!isAdminChat($chatId)) return;
    $users = mikrotikGetHotspotUsers();
    if (empty($users)) {
        sendMessage($chatId, "Tidak ada user Hotspot.");
        return;
    }
    
    $message = "🌐 *Daftar User Hotspot*\n\n";
    $keyboard = ['inline_keyboard' => []];
    $count = 0;
    foreach ($users as $u) {
        $message .= "- {$u['name']} ({$u['profile']})\n";
        if ($count < 10) {
            $keyboard['inline_keyboard'][] = [['text' => "🗑 Del {$u['name']}", 'callback_data' => 'action=mt_hotspot_del&name=' . urlencode($u['name'])]];
        }
        $count++;
        if ($count >= 20) break;
    }
    sendMessage($chatId, $message, ['reply_markup' => $keyboard]);
}

function handleHotspotAdd($chatId, $args) {
    if (!isAdminChat($chatId)) return;
    $parts = preg_split('/\s+/', trim($args));
    if (count($parts) < 3) {
        sendMessage($chatId, "Format: /hs_add &lt;u&gt; &lt;p&gt; &lt;prof&gt;");
        return;
    }
    
    if (mikrotikAddHotspotUser($parts[0], $parts[1], $parts[2])) {
        sendMessage($chatId, "User Hotspot ditambahkan.");
    } else {
        sendMessage($chatId, "Gagal.");
    }
}

function handleHotspotDel($chatId, $args, $silent = false) {
    if (!isAdminChat($chatId)) return;
    if (mikrotikDeleteHotspotUser(trim($args))) {
        if (!$silent) sendMessage($chatId, "User Hotspot dihapus.");
    } else {
        if (!$silent) sendMessage($chatId, "Gagal.");
    }
}

function handleHotspotDelCallback($chatId, $data, $callbackQuery) {
    handleHotspotDel($chatId, $data['name'] ?? '', true);
    sendMessage($chatId, "User dihapus.");
}

function getHotspotUserByName($name) {
    $users = mikrotikGetHotspotUsers();
    foreach ($users as $u) {
        if (($u['name'] ?? '') === $name) return $u;
    }
    return null;
}
