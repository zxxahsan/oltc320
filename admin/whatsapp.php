<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAdminLogin();

$pdo = getDB();
$pageTitle = 'WhatsApp';

// Handle Form Submission for Device Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    try {
        $waSettings = [
            'wa_bot_url' => $_POST['wa_bot_url'] ?? '',
            'WHATSAPP_ADMIN_NUMBER' => $_POST['whatsapp_admin_number'] ?? '',
            'WA_GATEWAY' => $_POST['wa_gateway'] ?? 'fonnte',
            'FONNTE_TOKEN' => $_POST['fonnte_token'] ?? '',
            'WABLAS_TOKEN' => $_POST['wablas_token'] ?? '',
            'WABLAS_DOMAIN' => $_POST['wablas_domain'] ?? '',
            'MPWA_TOKEN' => $_POST['mpwa_token'] ?? '',
            'MPWA_URL' => $_POST['mpwa_url'] ?? '',
            'wa_reminder_1_days' => $_POST['wa_reminder_1_days'] ?? '7',
            'wa_reminder_2_days' => $_POST['wa_reminder_2_days'] ?? '3',
            'wa_reminder_3_days' => $_POST['wa_reminder_3_days'] ?? '1'
        ];
        
        foreach ($waSettings as $key => $value) {
            $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
            } else {
                insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
        }
        
        setFlash('success', 'Pengaturan Integrasi WhatsApp berhasil disimpan.');
        header("Location: whatsapp.php");
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Gagal menyimpan pengaturan: ' . $e->getMessage());
    }
}

// Ensure Schema exists for is_enabled (Self-healing)
try {
    $checkCol = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_templates' AND COLUMN_NAME = 'is_enabled'");
    $checkCol->execute();
    if (!$checkCol->fetch()) {
        $pdo->exec("ALTER TABLE whatsapp_templates ADD COLUMN is_enabled BOOLEAN DEFAULT 1");
    }
} catch (Exception $e) {}

// Handle Template Generation / Restitution if table is brand new or dropped
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'migrate_table') {
    $createTable = "CREATE TABLE IF NOT EXISTS whatsapp_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL UNIQUE,
        message TEXT NOT NULL,
        variables_hint TEXT,
        is_enabled BOOLEAN DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($createTable);
    
    $waTemplates = [
        ['new_customer', "Halo *{customer_name}*,\n\nSelamat datang di Layanan Internet *{app_name}*!\nBerikut detail layanan Anda:\n- Paket: {package_name}\n- Harga: Rp {package_price}/bulan\n- Jatuh Tempo: Tanggal {due_date} tiap bulan\n- Username PPPoE: {pppoe_username}\n- Password: {pppoe_password}\n\nGunakan Portal Pelanggan kami:\n{portal_url}", '{customer_name}, {app_name}, {package_name}, {package_price}, {due_date}, {pppoe_username}, {pppoe_password}, {portal_url}'],
        ['invoice_created', "Halo *{customer_name}*,\n\nTagihan internet periode *{period}* telah terbit.\n\n- Nomor: {invoice_number}\n- Total: Rp {amount}\n- Jatuh Tempo: {due_date}\n\nBayar via Portal:\n{payment_url}\n\nAtau Checkout Cepat via Tripay:\n{tripay_url}", '{customer_name}, {period}, {invoice_number}, {amount}, {due_date}, {payment_url}, {tripay_url}, {app_name}'],
        ['invoice_reminder_1', "⚠️ *PENGINGAT TAGIHAN 1* ⚠️\n\nHalo *{customer_name}*,\nTagihan internet sebesar *Rp {amount}* akan jatuh tempo pada *{due_date}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nTerima kasih.", '{customer_name}, {amount}, {due_date}, {payment_url}, {tripay_url}'],
        ['invoice_reminder_2', "⚠️ *PENGINGAT TAGIHAN 2* ⚠️\n\nHalo *{customer_name}*,\nMohon segera melunasi tagihan internet sebesar *Rp {amount}* yang akan jatuh tempo pada *{due_date}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nTerima kasih.", '{customer_name}, {amount}, {due_date}, {payment_url}, {tripay_url}'],
        ['invoice_reminder_3', "‼️ *HARI JATUH TEMPO* ‼️\n\nHalo *{customer_name}*,\nHari ini atau besok adalah jadwal pemutusan sementara. Segera lakukan pembayaran tagihan internet sebesar *Rp {amount}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nAbaikan pesan ini bila sudah membayar.", '{customer_name}, {amount}, {due_date}, {payment_url}, {tripay_url}'],
        ['isolation_warning', "🔴 *KONEKSI TERPUTUS* 🔴\n\nMaaf *{customer_name}*, layanan internet telah diisolir karena tagihan *Rp {amount}* melewati batas ({due_date}).\n\nAktifkan kembali dalam 1 menit dengan pembayaran di:\n{payment_url}\n\nCheckout Cepat:\n{tripay_url}", '{customer_name}, {amount}, {due_date}, {payment_url}, {tripay_url}'],
        ['payment_success_normal', "✅ *PEMBAYARAN DITERIMA* ✅\n\nHalo *{customer_name}*,\nPembayaran tagihan internet Anda sebesar *Rp {amount}* untuk periode *{period}* (Invoice: {invoice_number}) telah kami terima.\n\nTerima kasih atas pembayaran Anda.", '{customer_name}, {amount}, {period}, {invoice_number}'],
        ['payment_success_isolated', "✅ *PEMBAYARAN DITERIMA & KONEKSI DIPULIHKAN* ✅\n\nHalo *{customer_name}*,\nPembayaran tagihan internet Anda sebesar *Rp {amount}* untuk periode *{period}* (Invoice: {invoice_number}) telah sukses.\n\nStatus Isolasi Anda telah DIBUKA otomatis. Layanan internet Anda segera aktif kembali dalam hitungan 1-2 menit ke depan.\nTerima kasih.", '{customer_name}, {amount}, {period}, {invoice_number}'],
        ['ticket_created', "Halo *{customer_name}*,\n\nTiket gangguan Anda telah dibuat dengan detail:\n- No. Tiket: {ticket_number}\n- Keluhan: {complaint}\n- Lokasi: {location_url}\n\nTeknisi kami akan segera menangani keluhan Anda.", '{customer_name}, {ticket_number}, {complaint}, {location_url}'],
        ['ticket_updated', "Halo *{customer_name}*,\n\nStatus tiket gangguan Anda (No. {ticket_number}) saat ini: *{status}*.\nCatatan: {notes}\n\nTerima kasih.", '{customer_name}, {ticket_number}, {status}, {notes}'],
        ['ticket_tech_alert', "⚠️ GANGGUAN BARU ⚠️\n\nHalo Teknisi *{tech_name}*,\nTerdapat tiket gangguan baru:\n- Pelanggan: {customer_name}\n- HP: {customer_phone}\n- Keluhan: {complaint}\n- Lokasi (Map): {location_url}\n\nSegera cek portal teknisi.", '{tech_name}, {customer_name}, {complaint}, {location_url}, {customer_phone}']
    ];
    foreach ($waTemplates as $watmp) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO whatsapp_templates (type, message, variables_hint) VALUES (?, ?, ?)");
        $stmt->execute($watmp);
    }
    setFlash('success', 'Tabel Template berhasil ditenagai!');
    header("Location: whatsapp.php");
    exit;
}

// Handle Template Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    try {
        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE whatsapp_templates SET message = ?, is_enabled = ? WHERE type = ?");
        $stmt->execute([$_POST['message'], $isEnabled, $_POST['type']]);
        setFlash('success', 'Template ' . $_POST['type'] . ' berhasil diubah.');
        header("Location: whatsapp.php");
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Gagal merubah template: ' . $e->getMessage());
    }
}

// Fetch Settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('wa_bot_url', 'WHATSAPP_ADMIN_NUMBER', 'WA_GATEWAY', 'FONNTE_TOKEN', 'WABLAS_TOKEN', 'WABLAS_DOMAIN', 'MPWA_TOKEN', 'MPWA_URL', 'wa_reminder_1_days', 'wa_reminder_2_days', 'wa_reminder_3_days')");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Fetch Templates (Graceful Fallback if Array doesnt exist yet!)
$templates = [];
try {
    $stmt = $pdo->query("SELECT * FROM whatsapp_templates");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Auto-seed missing core templates silently!
    $coreTemplates = [
        ['new_customer', "Halo *{customer_name}*,\n\nSelamat datang di Layanan Internet *{app_name}*!\nBerikut detail layanan Anda:\n- Paket: {package_name}\n- Harga: Rp {package_price}/bulan\n... (Edit via Portal) ...", ''],
        ['invoice_created', "Halo *{customer_name}*,\n\nTagihan internet periode *{period}* telah terbit.\n\n- Nomor: {invoice_number}\n- Total: Rp {amount}\n- Jatuh Tempo: {due_date}\n\nBayar via Portal:\n{payment_url}\n\nAtau Checkout Cepat via Tripay:\n{tripay_url}", ''],
        ['invoice_reminder_1', "⚠️ *PENGINGAT TAGIHAN 1* ⚠️\n\nHalo *{customer_name}*,\nTagihan internet sebesar *Rp {amount}* akan jatuh tempo pada *{due_date}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nTerima kasih.", ''],
        ['invoice_reminder_2', "⚠️ *PENGINGAT TAGIHAN 2* ⚠️\n\nHalo *{customer_name}*,\nMohon segera melunasi tagihan internet sebesar *Rp {amount}* yang akan jatuh tempo pada *{due_date}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nTerima kasih.", ''],
        ['invoice_reminder_3', "‼️ *HARI JATUH TEMPO* ‼️\n\nHalo *{customer_name}*,\nHari ini atau besok adalah jadwal pemutusan sementara. Segera lakukan pembayaran tagihan internet sebesar *Rp {amount}*.\n\nLakukan pembayaran online di:\n{payment_url}\n\nAtau bypass payment langsung:\n{tripay_url}\n\nAbaikan pesan ini bila sudah membayar.", ''],
        ['isolation_warning', "🔴 *KONEKSI TERPUTUS* 🔴\n\nMaaf *{customer_name}*, layanan internet telah diisolir karena tagihan *Rp {amount}* melewati batas ({due_date}).\n\nAktifkan kembali dalam 1 menit dengan pembayaran di:\n{payment_url}\n\nCheckout Cepat:\n{tripay_url}", ''],
        ['payment_success_normal', "✅ *PEMBAYARAN DITERIMA* ✅\n\nHalo *{customer_name}*,\nPembayaran tagihan internet Anda sebesar *Rp {amount}* untuk periode *{period}* (Invoice: {invoice_number}) telah kami terima.\n\nTerima kasih atas pembayaran Anda.", '{customer_name}, {amount}, {period}, {invoice_number}'],
        ['payment_success_isolated', "✅ *PEMBAYARAN DITERIMA & KONEKSI DIPULIHKAN* ✅\n\nHalo *{customer_name}*,\nPembayaran tagihan internet Anda sebesar *Rp {amount}* untuk periode *{period}* (Invoice: {invoice_number}) telah sukses.\n\nStatus Isolasi Anda telah DIBUKA otomatis. Layanan internet Anda terhubung kembali dalam waktu 1-2 menit.\nTerima kasih.", '{customer_name}, {amount}, {period}, {invoice_number}'],
        ['ticket_created', "Halo *{customer_name}*,\n\nTiket gangguan Anda telah dibuat dengan detail:\n- No. Tiket: {ticket_number}\n- Keluhan: {complaint}\n- Lokasi: {location_url}\n\nTeknisi kami akan segera menangani keluhan Anda.", '{customer_name}, {ticket_number}, {complaint}, {location_url}'],
        ['ticket_updated', "Halo *{customer_name}*,\n\nStatus tiket gangguan Anda (No. {ticket_number}) saat ini: *{status}*.\nCatatan: {notes}\n\nTerima kasih.", '{customer_name}, {ticket_number}, {status}, {notes}'],
        ['ticket_tech_alert', "⚠️ GANGGUAN BARU ⚠️\n\nHalo Teknisi *{tech_name}*,\nTerdapat tiket gangguan baru:\n- Pelanggan: {customer_name}\n- HP: {customer_phone}\n- Keluhan: {complaint}\n- Lokasi (Map): {location_url}\n\nSegera cek portal teknisi.", '{tech_name}, {customer_name}, {complaint}, {location_url}, {customer_phone}']
    ];
    foreach($coreTemplates as $ct) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO whatsapp_templates (type, message, variables_hint) VALUES (?, ?, ?)");
        $stmt->execute($ct);
    }
    
    // Re-fetch after seeding
    $stmt = $pdo->query("SELECT * FROM whatsapp_templates");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Expected on first load without DB Migration!
    $templates = null;
}

// Map Types to Friendly Names
$typeNames = [
    'new_customer' => 'Pelanggan Baru',
    'invoice_created' => 'Informasi Penagihan Baru',
    'invoice_reminder_1' => 'Reminder Tagihan 1 (H-X)',
    'invoice_reminder_2' => 'Reminder Tagihan 2 (H-Y)',
    'invoice_reminder_3' => 'Reminder Tagihan 3 (H-Z)',
    'isolation_warning' => 'Peringatan Isolir (PPPoE Terputus)',
    'payment_success_normal' => 'Pembayaran Berhasil (Status Aktif)',
    'payment_success_isolated' => 'Pembayaran Berhasil & Buka Isolir',
    'ticket_created' => 'Gangguan: Tiket Baru Dibuat (Pelanggan)',
    'ticket_updated' => 'Gangguan: Status Tiket Berubah (Pelanggan)',
    'ticket_tech_alert' => 'Gangguan: Notifikasi ke Teknisi'
];
?>

<?php ob_start(); // BEGIN OUTPUT WRAPPER ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-plug"></i> Pengaturan Global WhatsApp & Gateway</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save_settings">
            
            <div class="form-group">
                <label class="form-label" style="font-weight: bold; color: var(--neon-cyan);">Gateway WhatsApp Aktif</label>
                <select name="wa_gateway" id="wa_gateway_select" class="form-control" onchange="toggleWaSettings()" style="background: rgba(0,0,0,0.5); border: 1px solid var(--neon-cyan);">
                    <option value="fonnte" <?php echo ($settings['WA_GATEWAY'] ?? '') === 'fonnte' ? 'selected' : ''; ?>>Fonnte API</option>
                    <option value="wablas" <?php echo ($settings['WA_GATEWAY'] ?? '') === 'wablas' ? 'selected' : ''; ?>>Wablas</option>
                    <option value="mpwa" <?php echo ($settings['WA_GATEWAY'] ?? '') === 'mpwa' ? 'selected' : ''; ?>>MPWA Official</option>
                    <option value="custom" <?php echo ($settings['WA_GATEWAY'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom Node.js Gateway</option>
                </select>
                <small style="color: var(--text-muted); display: block; margin-top: 5px;">Pilih metode mana yang akan digunakan sistem Gembok saat mengirim notifikasi.</small>
            </div>
            
            <!-- FONNTE SETTINGS -->
            <div id="cfg_fonnte" class="wa-cfg-box" style="display: none; padding: 15px; border: 1px dashed var(--border-color); border-radius: 8px; margin-bottom: 15px;">
                <div class="form-group">
                    <label class="form-label">Fonnte API Token</label>
                    <input type="text" name="fonnte_token" class="form-control" value="<?php echo htmlspecialchars($settings['FONNTE_TOKEN'] ?? ''); ?>">
                </div>
            </div>

            <!-- WABLAS SETTINGS -->
            <div id="cfg_wablas" class="wa-cfg-box" style="display: none; padding: 15px; border: 1px dashed var(--border-color); border-radius: 8px; margin-bottom: 15px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">Wablas Domain Server</label>
                        <input type="text" name="wablas_domain" class="form-control" value="<?php echo htmlspecialchars($settings['WABLAS_DOMAIN'] ?? ''); ?>" placeholder="https://solo.wablas.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Wablas API Token</label>
                        <input type="text" name="wablas_token" class="form-control" value="<?php echo htmlspecialchars($settings['WABLAS_TOKEN'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- MPWA SETTINGS -->
            <div id="cfg_mpwa" class="wa-cfg-box" style="display: none; padding: 15px; border: 1px dashed var(--border-color); border-radius: 8px; margin-bottom: 15px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label class="form-label">MPWA API URL</label>
                        <input type="text" name="mpwa_url" class="form-control" value="<?php echo htmlspecialchars($settings['MPWA_URL'] ?? ''); ?>" placeholder="https://mpwa.official.id/api/send">
                    </div>
                    <div class="form-group">
                        <label class="form-label">MPWA API Token (Key)</label>
                        <input type="text" name="mpwa_token" class="form-control" value="<?php echo htmlspecialchars($settings['MPWA_TOKEN'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- CUSTOM NODE.JS SETTINGS -->
            <div id="cfg_custom" class="wa-cfg-box" style="display: none; padding: 15px; border: 1px dashed var(--border-color); border-radius: 8px; margin-bottom: 15px;">
                <div class="form-group">
                    <label class="form-label">Custom Node.js Gateway URL</label>
                    <input type="text" name="wa_bot_url" class="form-control" value="<?php echo htmlspecialchars($settings['wa_bot_url'] ?? ''); ?>" placeholder="http://127.0.0.1:3000">
                    <small style="color: var(--text-muted); display: block; margin-top: 5px;">Arahkan ke proxy Baileys/WWeb.js Anda. Endpoint yang dipukul otomatis + `/send-message`.</small>
                </div>
            </div>
            
            <hr style="margin: 20px 0; border-color: rgba(255,255,255,0.05);">
            
            <h4><i class="fas fa-clock"></i> Jadwal Reminder Otomatis</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" style="color: var(--neon-green)">Reminder 1 (H-?)</label>
                    <input type="number" name="wa_reminder_1_days" class="form-control" value="<?php echo htmlspecialchars($settings['wa_reminder_1_days'] ?? '7'); ?>" placeholder="7">
                    <small style="color: var(--text-muted); display:block; margin-top:4px;">Dikirim berapa hari sebelum jatuh tempo?</small>
                </div>
                <div class="form-group">
                    <label class="form-label" style="color: var(--neon-orange)">Reminder 2 (H-?)</label>
                    <input type="number" name="wa_reminder_2_days" class="form-control" value="<?php echo htmlspecialchars($settings['wa_reminder_2_days'] ?? '3'); ?>" placeholder="3">
                </div>
                <div class="form-group">
                    <label class="form-label" style="color: var(--neon-red)">Reminder 3 (H-?)</label>
                    <input type="number" name="wa_reminder_3_days" class="form-control" value="<?php echo htmlspecialchars($settings['wa_reminder_3_days'] ?? '1'); ?>" placeholder="1">
                </div>
            </div>

            <hr style="margin: 20px 0; border-color: rgba(255,255,255,0.05);">

            <div class="form-group">
                <label class="form-label">WhatsApp Admin Number</label>
                <input type="text" name="whatsapp_admin_number" class="form-control" value="<?php echo htmlspecialchars($settings['WHATSAPP_ADMIN_NUMBER'] ?? ''); ?>" placeholder="628xxxxxxxxxx">
                <small style="color: var(--text-muted); display: block; margin-top: 5px;">Nomor WhatsApp admin yang akan menerima rangkuman / notifikasi peringatan sistem (format internasional 628...).</small>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Simpan Penyetelan WhatsApp
            </button>
        </form>
    </div>
</div>

<script>
function toggleWaSettings() {
    document.querySelectorAll('.wa-cfg-box').forEach(el => el.style.display = 'none');
    const selected = document.getElementById('wa_gateway_select').value;
    const targetBox = document.getElementById('cfg_' + selected);
    if(targetBox) targetBox.style.display = 'block';
}
// Run on load
document.addEventListener('DOMContentLoaded', toggleWaSettings);
</script>

<?php if ($templates === null): ?>
<div class="card" style="margin-top: 20px; border-color: var(--neon-orange);">
    <div class="card-header">
        <h3 class="card-title" style="color: var(--neon-orange);"><i class="fas fa-exclamation-triangle"></i> Instalasi Template Engine Diperlukan!</h3>
    </div>
    <div class="card-body">
        <p style="margin-bottom: 15px; color: var(--text-secondary);">Sistem mendeteksi bahwa tabel <code>whatsapp_templates</code> belum tertanam di dalam database Anda. Silakan tekan tombol di bawah ini agar sistem melakukan injeksi database secara otomatis.</p>
        <form method="POST">
            <input type="hidden" name="action" value="migrate_table">
            <button type="submit" class="btn btn-warning"><i class="fas fa-database"></i> Jalankan Migrasi Database WhatsApp Sekarang</button>
        </form>
    </div>
</div>
<?php else: ?>

<div style="margin-top: 30px;">
    <h2><i class="fas fa-comment-dots"></i> Master Template Pesan</h2>
    <p style="color: var(--text-secondary); margin-bottom: 20px;">Sesuaikan format kalimat otomatis yang akan disalurkan oleh sistem Gembok ke nomor WhatsApp Pelanggan.</p>
    
    <!-- GLOBAL VARIABLES HINT -->
    <div class="alert alert-info" style="background: rgba(0, 204, 255, 0.1); border-left: 4px solid #00ccff; padding: 15px; margin-bottom: 25px; border-radius: 4px;">
        <h4 style="margin-top: 0; color: #00ccff;"><i class="fas fa-info-circle"></i> Daftar Variabel Global (Bisa Digunakan di SEMUA Template)</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px; font-family: monospace; font-size: 0.9rem; color: #eee;">
            <div><strong>{customer_name}</strong> - Nama Pelanggan</div>
            <div><strong>{app_name}</strong> - Nama Aplikasi Sistem</div>
            <div><strong>{package_name}</strong> - Nama Paket Internet</div>
            <div><strong>{package_price}</strong> - Harga Paket Pribadi</div>
            <div><strong>{pppoe_username}</strong> - Username PPPoE</div>
            <div><strong>{pppoe_password}</strong> - Password PPPoE</div>
            <div><strong>{due_date}</strong> - Tanggal Jatuh Tempo</div>
            <div><strong>{period}</strong> - Nama Bulan & Tahun Tagihan</div>
            <div><strong>{invoice_number}</strong> - Nomor Invoice (Jika ada)</div>
            <div><strong>{amount}</strong> - Total Tagihan Pembayaran</div>
            <div><strong>{portal_url}</strong> - Link Portal Pelanggan</div>
            <div><strong>{payment_url}</strong> - Link Bayar Standar (Sama)</div>
            <div><strong>{tripay_url}</strong> - Link Checkout Instan TriPay</div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
        <?php foreach ($templates as $tmpl): ?>
        <div class="card" style="border-top: 3px solid var(--neon-cyan);">
            <div class="card-header" style="justify-content: space-between;">
                <h3 class="card-title"><?php echo htmlspecialchars($typeNames[$tmpl['type']] ?? $tmpl['type']); ?></h3>
                <span class="badge badge-info" style="font-size: 0.7rem;"><?php echo htmlspecialchars($tmpl['type']); ?></span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_template">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($tmpl['type']); ?>">
                    
                    <div class="form-group" style="display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); margin-bottom: 15px;">
                        <label style="margin: 0; font-weight: bold; color: var(--text-primary); cursor: pointer;">
                            <i class="fas fa-power-off" style="color: <?php echo (!isset($tmpl['is_enabled']) || $tmpl['is_enabled']) ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;"></i> Aktifkan Template Ini
                        </label>
                        <div style="display: flex; align-items: center;">
                            <input type="checkbox" name="is_enabled" value="1" <?php echo (!isset($tmpl['is_enabled']) || $tmpl['is_enabled']) ? 'checked' : ''; ?> style="width: 20px; height: 20px; cursor: pointer; accent-color: var(--neon-cyan);">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <textarea name="message" class="form-control" rows="8" style="font-family: monospace; font-size: 0.9rem; line-height: 1.5;" required><?php echo htmlspecialchars($tmpl['message']); ?></textarea>
                    </div>
                    
                    <div style="margin-bottom: 15px; padding: 10px; background: rgba(0, 245, 255, 0.05); border: 1px solid rgba(0, 245, 255, 0.2); border-radius: 8px;">
                        <span style="display: block; font-size: 0.8rem; font-weight: bold; margin-bottom: 5px; color: var(--neon-cyan);">Variabel yang diizinkan:</span>
                        <code style="font-size: 0.8rem; color: var(--text-secondary); word-wrap: break-word;"><?php echo htmlspecialchars($tmpl['variables_hint']); ?></code>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Perbarui Template
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php 
$content = ob_get_clean(); // END OUTPUT WRAPPER 
require_once '../includes/layout.php';
?>
