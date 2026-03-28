<?php
require_once __DIR__ . '/includes/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create table for WhatsApp Templates
    $createTable = "
    CREATE TABLE IF NOT EXISTS whatsapp_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL UNIQUE,
        message TEXT NOT NULL,
        variables_hint TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($createTable);
    echo "Table 'whatsapp_templates' created or already exists.\n";

    // Insert Default Templates
    $defaults = [
        [
            'type' => 'new_customer',
            'message' => "Halo *{customer_name}*,\n\nSelamat datang di Layanan Internet *{app_name}*!\nBerikut adalah detail layanan Anda:\n- Paket: {package_name}\n- Harga: Rp {package_price}/bulan\n- Jatuh Tempo: Tanggal {due_date} setiap bulannya\n- Username PPPoE: {pppoe_username}\n- Password PPPoE: {pppoe_password}\n\nUntuk konfigurasi dan pengaturan WiFi, cek Customer Portal kami di:\n{portal_url}\n\nTerima kasih!",
            'variables_hint' => '{customer_name}, {app_name}, {package_name}, {package_price}, {due_date}, {pppoe_username}, {pppoe_password}, {portal_url}'
        ],
        [
            'type' => 'invoice_created',
            'message' => "Halo *{customer_name}*,\n\nTagihan internet Anda untuk periode *{period}* telah terbit.\n\n- Nomor Tagihan: {invoice_number}\n- Total Tagihan: Rp {amount}\n- Jatuh Tempo: {due_date}\n\nSegera lakukan pembayaran untuk menghindari pemutusan layanan. Bayar sekarang via Portal Pelanggan:\n{payment_url}\n\nAbaikan pesan ini jika Anda sudah membayar.\nTerima kasih, *{app_name}*",
            'variables_hint' => '{customer_name}, {period}, {invoice_number}, {amount}, {due_date}, {payment_url}, {app_name}'
        ],
        [
            'type' => 'invoice_reminder',
            'message' => "⚠️ *PENGINGAT TAGIHAN* ⚠️\n\nHalo *{customer_name}*,\nSekadar mengingatkan bahwa tagihan internet Anda sebesar *Rp {amount}* akan jatuh tempo pada *{due_date}*.\n\nJangan biarkan internet Anda terputus! Lakukan pembayaran secara online sekarang juga di:\n{payment_url}\n\nTerima kasih atas kerja samanya.",
            'variables_hint' => '{customer_name}, {amount}, {due_date}, {payment_url}'
        ],
        [
            'type' => 'isolation_warning',
            'message' => "🔴 *KONEKSI TERPUTUS* 🔴\n\nMohon maaf *{customer_name}*, layanan internet Anda telah diisolir oleh sistem kami karena tagihan sebesar *Rp {amount}* telah melewati batas jatuh tempo ({due_date}).\n\nUntuk mengaktifkan kembali layanan internet Anda secara otomatis dalam 1 menit, silakan lunasi tagihan Anda melalui link berikut:\n{payment_url}\n\nHubungi admin jika Anda membutuhkan bantuan.",
            'variables_hint' => '{customer_name}, {amount}, {due_date}, {payment_url}'
        ]
    ];

    $stmt = $pdo->prepare("INSERT INTO whatsapp_templates (type, message, variables_hint) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE variables_hint = VALUES(variables_hint)");
    
    foreach ($defaults as $tmpl) {
        $stmt->execute([$tmpl['type'], $tmpl['message'], $tmpl['variables_hint']]);
    }
    echo "Default WhatsApp templates populated successfully.\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
