<?php
/**
 * Admin Settings - Emergency Fix v1.2 (Clean Encoding)
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Settings';

// Get current settings
$settings = [];
$settingsData = fetchAll("SELECT * FROM settings");
foreach ($settingsData as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Invalid CSRF token');
        redirect('settings.php');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_system':
                $systemSettings = [
                    'app_name' => sanitize($_POST['app_name']),
                    'timezone' => sanitize($_POST['timezone']),
                    'currency' => sanitize($_POST['currency']),
                    'invoice_prefix' => sanitize($_POST['invoice_prefix']),
                    'invoice_start' => (int)$_POST['invoice_start'],
                    'invoice_generate_days' => (int)($_POST['invoice_generate_days'] ?? 7),
                    'DEFAULT_MONITOR_INTERFACE' => sanitize($_POST['default_monitor_interface']),
                    'master_customer_password' => sanitize($_POST['master_customer_password'])
                ];
                
                foreach ($systemSettings as $key => $value) {
                    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                    if ($existing) {
                        update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                    } else {
                        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
                
                setFlash('success', 'Pengaturan sistem berhasil disimpan');
                redirect('settings.php');
                break;
                
            case 'save_mikrotik':
                $mikrotikSettings = [
                    'MIKROTIK_HOST' => sanitize($_POST['mikrotik_host']),
                    'MIKROTIK_USER' => sanitize($_POST['mikrotik_user']),
                    'MIKROTIK_PASS' => sanitize($_POST['mikrotik_pass']),
                    'MIKROTIK_PORT' => (int)$_POST['mikrotik_port']
                ];
                
                foreach ($mikrotikSettings as $key => $value) {
                    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                    if ($existing) {
                        update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                    } else {
                        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
                
                setFlash('success', 'Pengaturan MikroTik berhasil disimpan');
                redirect('settings.php');
                break;
                
            case 'save_genieacs':
                $genieacsSettings = [
                    'GENIEACS_URL' => sanitize($_POST['genieacs_url']),
                    'GENIEACS_USERNAME' => sanitize($_POST['genieacs_username']),
                    'GENIEACS_PASSWORD' => sanitize($_POST['genieacs_password'])
                ];
                
                foreach ($genieacsSettings as $key => $value) {
                    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                    if ($existing) {
                        update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                    } else {
                        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
                
                setFlash('success', 'Pengaturan GenieACS berhasil disimpan');
                redirect('settings.php');
                break;
                
            case 'save_integrations':
                $integrationSettings = [
                    'TRIPAY_API_KEY' => sanitize($_POST['tripay_api_key']),
                    'TRIPAY_PRIVATE_KEY' => sanitize($_POST['tripay_private_key']),
                    'TRIPAY_MERCHANT_CODE' => sanitize($_POST['tripay_merchant_code']),
                    'TELEGRAM_BOT_TOKEN' => sanitize($_POST['telegram_token']),
                    'CRON_TOKEN' => sanitize($_POST['cron_token']),
                    // Payment Toggles
                    'ENABLE_TRIPAY_CUSTOMER' => isset($_POST['enable_tripay_customer']) ? '1' : '0',
                    'ENABLE_MANUAL_CUSTOMER' => isset($_POST['enable_manual_customer']) ? '1' : '0',
                    'ENABLE_TRIPAY_SALES' => isset($_POST['enable_tripay_sales']) ? '1' : '0',
                    'ENABLE_MANUAL_SALES' => isset($_POST['enable_manual_sales']) ? '1' : '0',
                    'TRIPAY_SANDBOX' => isset($_POST['tripay_sandbox']) ? '1' : '0',
                    'MANUAL_PAYMENT_INFO' => sanitize($_POST['manual_payment_info'])
                ];
                
                foreach ($integrationSettings as $key => $value) {
                    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                    if ($existing) {
                        update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                    } else {
                        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
                
                setFlash('success', 'Pengaturan integrasi & pembayaran berhasil disimpan');
                redirect('settings.php');
                break;
                

            case 'change_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                $sessionAdmin = getCurrentAdmin();
                $admin = getAdmin($sessionAdmin['id']);
                
                if (!$admin || !password_verify($currentPassword, $admin['password'])) {
                    setFlash('error', 'Password saat ini salah');
                    redirect('settings.php');
                }
                
                if ($newPassword !== $confirmPassword) {
                    setFlash('error', 'Password baru tidak sama');
                    redirect('settings.php');
                }
                
                if (strlen($newPassword) < 6) {
                    setFlash('error', 'Password minimal 6 karakter');
                    redirect('settings.php');
                }
                
                if (updateAdminPassword($admin['id'], $newPassword)) {
                    setFlash('success', 'Password berhasil diubah');
                    logActivity('CHANGE_PASSWORD', 'Admin ID: ' . $admin['id']);
                } else {
                    setFlash('error', 'Gagal mengubah password');
                }
                redirect('settings.php');
                break;

            case 'sync_mikrotik_trigger':
                require_once '../includes/mikrotik_api.php';
                $router_id = (int)$_POST['router_id'];
                $interval = (int)($_POST['interval'] ?? 1);
                $server_url = $_POST['server_url'];

                // 1. Find if exists to avoid error
                $existing = mikrotikQuery('/system/scheduler/print', ['?name' => 'Gembok_OLT_Monitor']);
                if (!empty($existing) && isset($existing[0]['.id'])) {
                    mikrotikRunRaw($router_id, '/system/scheduler/remove', ['.id' => $existing[0]['.id']]);
                }

                // 2. Add new
                $params = [
                    'name' => 'Gembok_OLT_Monitor',
                    'interval' => $interval . 'm',
                    'on-event' => "/tool fetch url=\"{$server_url}\" keep-result=no"
                ];
                $res = mikrotikRunRaw($router_id, '/system/scheduler/add', $params);

                if ($res) {
                    setFlash('success', "Trigger berhasil dipasang di Mikrotik (Interval: {$interval} menit)");
                } else {
                    setFlash('error', "Gagal memasang trigger. Pastikan koneksi Mikrotik OK.");
                }
                redirect('settings.php');
                break;
        }
    }
}

ob_start();
?>

<style>
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}
.settings-item {
    display: flex;
    flex-direction: column;
}
.settings-item label {
    display: block !important;
    width: 100% !important;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-secondary);
}
.settings-item.full-width {
    grid-column: 1 / -1;
}
@media (max-width: 768px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- System Settings -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-cog"></i> Pengaturan Sistem</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_system">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="settings-grid">
            <div class="settings-item">
                <label class="form-label">Nama Aplikasi</label>
                <input type="text" name="app_name" class="form-control" value="<?php echo htmlspecialchars($settings['app_name'] ?? 'GEMBOK'); ?>">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Master Password Pelanggan</label>
                <input type="password" name="master_customer_password" class="form-control" value="<?php echo htmlspecialchars($settings['master_customer_password'] ?? ''); ?>" placeholder="Password rahasia untuk semua pelanggan">
                <small style="color: var(--text-muted); margin-top: 5px;">Gunakan password ini untuk masuk ke dashboard pelanggan mana pun secara cepat.</small>
            </div>

            <div class="settings-item">
                <label class="form-label">Timezone</label>
                <select name="timezone" class="form-control">
                    <option value="Asia/Jakarta" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jakarta' ? 'selected' : ''; ?>>Asia/Jakarta (WIB)</option>
                    <option value="Asia/Makassar" <?php echo ($settings['timezone'] ?? '') === 'Asia/Makassar' ? 'selected' : ''; ?>>Asia/Makassar (WITA)</option>
                    <option value="Asia/Jayapura" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jayapura' ? 'selected' : ''; ?>>Asia/Jayapura (WIT)</option>
                    <option value="Asia/Pontianak" <?php echo ($settings['timezone'] ?? '') === 'Asia/Pontianak' ? 'selected' : ''; ?>>Asia/Pontianak (WIB)</option>
                </select>
            </div>
            
            <div class="settings-item">
                <label class="form-label">Mata Uang</label>
                <select name="currency" class="form-control">
                    <option value="IDR" <?php echo ($settings['currency'] ?? '') === 'IDR' ? 'selected' : ''; ?>>IDR - Rupiah</option>
                    <option value="USD" <?php echo ($settings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD - Dollar</option>
                </select>
            </div>
            
            <div class="settings-item full-width">
                <label class="form-label">Traffic Monitor Interface Default</label>
                <input type="text" name="default_monitor_interface" class="form-control" value="<?php echo htmlspecialchars($settings['DEFAULT_MONITOR_INTERFACE'] ?? 'ether1'); ?>" placeholder="ether1, pppoe-out1, wlan1...">
                <small style="color: var(--text-muted); margin-top: 5px;">Interface awal yang langsung ditampilkan di grafik Dashboard.</small>
            </div>
            
            <div class="settings-item">
                <label class="form-label">Invoice Prefix</label>
                <input type="text" name="invoice_prefix" class="form-control" value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'INV'); ?>">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Invoice Start Number</label>
                <input type="number" name="invoice_start" class="form-control" value="<?php echo (int)($settings['invoice_start'] ?? 1); ?>">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Gen Invoices (H- Hari)</label>
                <input type="number" name="invoice_generate_days" class="form-control" value="<?php echo (int)($settings['invoice_generate_days'] ?? 7); ?>" min="1" max="28">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </form>
</div>

<!-- MikroTik Settings -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-network-wired"></i> Pengaturan MikroTik</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_mikrotik">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="settings-grid">
            <div class="settings-item">
                <label class="form-label">MikroTik IP Address</label>
                <input type="text" name="mikrotik_host" class="form-control" value="<?php echo htmlspecialchars(getSetting('MIKROTIK_HOST')); ?>" placeholder="192.168.1.1">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Username</label>
                <input type="text" name="mikrotik_user" class="form-control" value="<?php echo htmlspecialchars(getSetting('MIKROTIK_USER')); ?>" placeholder="admin">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Password</label>
                <input type="password" name="mikrotik_pass" class="form-control" value="<?php echo htmlspecialchars(getSetting('MIKROTIK_PASS')); ?>" placeholder="Masukkan password">
            </div>
            
            <div class="settings-item">
                <label class="form-label">API Port</label>
                <input type="number" name="mikrotik_port" class="form-control" value="<?php echo (int)getSetting('MIKROTIK_PORT', 8728); ?>">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </form>
</div>

<!-- GenieACS Settings -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-server"></i> Pengaturan GenieACS</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_genieacs">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="settings-grid">
            <div class="settings-item full-width">
                <label class="form-label">GenieACS URL</label>
                <input type="text" name="genieacs_url" class="form-control" value="<?php echo htmlspecialchars(getSetting('GENIEACS_URL')); ?>" placeholder="http://192.168.1.10:7557">
                <small style="color: var(--text-muted); margin-top: 5px;">URL lengkap termasuk port (default: 7557)</small>
            </div>
            
            <div class="settings-item">
                <label class="form-label">Username (Opsional)</label>
                <input type="text" name="genieacs_username" class="form-control" value="<?php echo htmlspecialchars(getSetting('GENIEACS_USERNAME')); ?>" placeholder="Username GenieACS">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Password (Opsional)</label>
                <input type="password" name="genieacs_password" class="form-control" value="<?php echo htmlspecialchars(getSetting('GENIEACS_PASSWORD')); ?>" placeholder="Password GenieACS">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </form>
</div>


<!-- Integration Settings -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-plug"></i> Integrasi & API</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_integrations">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan); opacity: 0.9;">Payment Gateway (Tripay)</h4>
        
        <div class="settings-grid">
            <div class="settings-item full-width">
                <div style="background: rgba(0, 245, 255, 0.05); border: 1px solid rgba(0, 245, 255, 0.1); padding: 20px; border-radius: 12px; margin-bottom: 10px;">
                    <h5 style="margin-bottom: 10px; color: var(--text-primary);">URL Callback / Webhook Tripay</h5>
                    <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 15px;">Paste URL ini ke menu <strong>Callback URL</strong> di pengaturan merchant Tripay Anda.</p>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="tripay_webhook_url" readonly value="<?php echo APP_URL; ?>/webhooks/tripay.php" class="form-control" style="background: rgba(0,0,0,0.3);">
                        <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl('tripay_webhook_url', this)">Salin</button>
                    </div>
                </div>
            </div>
            
            <div class="settings-item">
                <label class="form-label">Tripay API Key</label>
                <input type="text" name="tripay_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_API_KEY'] ?? ''); ?>" placeholder="Masukkan API Key Tripay">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Tripay Private Key</label>
                <input type="password" name="tripay_private_key" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_PRIVATE_KEY'] ?? ''); ?>" placeholder="Masukkan Private Key">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Tripay Merchant Code</label>
                <input type="text" name="tripay_merchant_code" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_MERCHANT_CODE'] ?? ''); ?>" placeholder="Masukkan Merchant Code">
            </div>
            
            <div class="settings-item">
                <label class="form-label">Mode Sandbox</label>
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; margin-top: 10px;">
                    <input type="checkbox" name="tripay_sandbox" value="1" <?php echo (getSetting('TRIPAY_SANDBOX', '0') === '1') ? 'checked' : ''; ?>>
                    Aktifkan Sandbox (Gunakan jika Key Anda adalah Key Sandbox)
                </label>
            </div>
        </div>

        <hr style="border-color: var(--border-color); margin: 30px 0;">
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan); opacity: 0.9;">Fitur Metode Pembayaran</h4>
        <div class="settings-grid">
            <div class="settings-item" style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border: 1px solid var(--border-color);">
                <label class="form-label" style="color: var(--neon-cyan); margin-bottom: 12px; display: block;"><i class="fas fa-users"></i> Gateway Pelanggan</label>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="enable_tripay_customer" value="1" <?php echo (getSetting('ENABLE_TRIPAY_CUSTOMER', '1') === '1') ? 'checked' : ''; ?>>
                        Tripay (Otomatis)
                    </label>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="enable_manual_customer" value="1" <?php echo (getSetting('ENABLE_MANUAL_CUSTOMER', '1') === '1') ? 'checked' : ''; ?>>
                        Manual (Transfer Bank)
                    </label>
                </div>
            </div>
            <div class="settings-item" style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 12px; border: 1px solid var(--border-color);">
                <label class="form-label" style="color: var(--neon-cyan); margin-bottom: 12px; display: block;"><i class="fas fa-user-tie"></i> Gateway Sales</label>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="enable_tripay_sales" value="1" <?php echo (getSetting('ENABLE_TRIPAY_SALES', '1') === '1') ? 'checked' : ''; ?>>
                        Tripay (Otomatis)
                    </label>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="enable_manual_sales" value="1" <?php echo (getSetting('ENABLE_MANUAL_SALES', '1') === '1') ? 'checked' : ''; ?>>
                        Manual (Transfer Bank)
                    </label>
                </div>
            </div>
            <div class="settings-item full-width">
                <label class="form-label">Informasi Rekening Manual</label>
                <textarea name="manual_payment_info" class="form-control" rows="3" placeholder="Contoh: BCA 1234567890 a/n Admin"><?php echo htmlspecialchars(getSetting('MANUAL_PAYMENT_INFO')); ?></textarea>
                <small style="color: var(--text-muted); margin-top: 5px;">Teks ini akan muncul saat user memilih metode pembayaran Manual.</small>
            </div>
        </div>

        <hr style="border-color: var(--border-color); margin: 30px 0;">
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan); opacity: 0.9;">Telegram Notifications</h4>
        <div class="settings-grid">
            <div class="settings-item">
                <label class="form-label">Telegram Bot Token</label>
                <input type="password" name="telegram_token" class="form-control" value="<?php echo htmlspecialchars($settings['TELEGRAM_BOT_TOKEN'] ?? ''); ?>" placeholder="Masukkan Token Bot">
            </div>
            <div class="settings-item">
                <label class="form-label">Cron/Webhook Token</label>
                <input type="text" name="cron_token" class="form-control" value="<?php echo htmlspecialchars($settings['CRON_TOKEN'] ?? ''); ?>" placeholder="Token Keamanan Cron">
                <small style="color: var(--text-muted); margin-top: 5px;">Token unik untuk memicu script otomatis (Cron Job).</small>
            </div>
        </div>

        <hr style="border-color: var(--border-color); margin: 30px 0;">
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan); opacity: 0.9;">Cronjob & Task Scheduler</h4>
        <div style="background: rgba(0, 255, 136, 0.05); border: 1px solid rgba(0, 255, 136, 0.1); padding: 20px; border-radius: 12px; margin-bottom: 25px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <i class="fas fa-clock" style="color: var(--neon-green);"></i>
                <h5 style="margin: 0; color: var(--text-primary);">Konfigurasi Cronjob</h5>
            </div>
            <p style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 15px;">Gunakan perintah di bawah ini untuk menjalankan tugas otomatis setiap 1 menit.</p>
            
            <div class="settings-item" style="margin-bottom: 15px;">
                <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 5px;">Metode 1: CLI (VPS)</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="cron_cli" readonly value="* * * * * /usr/bin/php <?php echo str_replace('\\', '/', realpath(__DIR__ . '/../cron/scheduler.php')); ?>" class="form-control" style="background: rgba(0,0,0,0.3); font-family: monospace; font-size: 0.85rem;">
                    <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl('cron_cli', this)">Salin</button>
                </div>
            </div>

            <div class="settings-item">
                <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 5px;">Metode 2: URL Task (Cloud Hosting)</label>
                <div style="display: flex; gap: 10px;">
                    <?php 
                    $cronToken = getSetting('CRON_TOKEN');
                    if (!$cronToken) {
                        $cronToken = bin2hex(random_bytes(16));
                        insert('settings', ['setting_key' => 'CRON_TOKEN', 'setting_value' => $cronToken]);
                    }
                    $cronUrl = APP_URL . "/cron/monitor_onu.php?run_manual=1";
                    ?>
                    <input type="text" id="cron_web" readonly value="<?php echo $cronUrl; ?>" class="form-control" style="background: rgba(0,0,0,0.3); font-family: monospace; font-size: 0.85rem;">
                    <button type="button" class="btn btn-secondary" onclick="copyWebhookUrl('cron_web', this)">Salin</button>
                </div>
            </div>

            <hr style="border-color: rgba(255,255,255,0.05); margin: 20px 0;">

            <div class="settings-item">
                <label style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 10px;">Metode 3: Mikrotik Scheduler (Container) - OLT Monitor</label>
                <?php
                $routers = fetchAll("SELECT id, name, host FROM routers ORDER BY id DESC");
                ?>
                <div style="background: rgba(162, 89, 255, 0.05); border: 1px solid rgba(162, 89, 255, 0.2); padding: 15px; border-radius: 10px;">
                    <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 10px;">Instal trigger otomatis ke Scheduler Mikrotik untuk monitoring OLT (LOS/Kabel Putus).</p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 120px 150px; gap: 10px; align-items: end;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.75rem;">Pilih Router</label>
                            <select id="trigger_router_id" class="form-control" style="height: 38px;">
                                <?php foreach ($routers as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" style="font-size: 0.75rem;">Interval</label>
                            <select id="trigger_interval" class="form-control" style="height: 38px;">
                                <option value="1">1 Menit</option>
                                <option value="2">2 Menit</option>
                                <option value="5">5 Menit</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-primary" style="height: 38px; background: var(--neon-purple); border-color: var(--neon-purple);" onclick="installMikrotikTrigger()">
                            <i class="fas fa-sync"></i> Install Trigger
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Hidden Trigger Form -->
        <form id="mikrotikTriggerForm" method="POST" style="display: none;">
            <input type="hidden" name="action" value="sync_mikrotik_trigger">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="router_id" id="form_router_id">
            <input type="hidden" name="interval" id="form_interval">
            <input type="hidden" name="server_url" value="<?php echo $cronUrl; ?>">
        </form>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </form>
</div>

<!-- Change Password -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-key"></i> Ganti Password Admin</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="form-group">
            <label class="form-label">Password Saat Ini</label>
            <input type="password" name="current_password" class="form-control" placeholder="•••••••••" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">Password Baru</label>
            <input type="password" name="new_password" class="form-control" placeholder="Minimal 6 karakter" required minlength="6">
        </div>
        
        <div class="form-group">
            <label class="form-label">Konfirmasi Password Baru</label>
            <input type="password" name="confirm_password" class="form-control" placeholder="Ketik ulang password baru" required minlength="6">
        </div>
        
        <button type="submit" class="btn btn-warning">
            <i class="fas fa-key"></i> Ubah Password
        </button>
    </form>
</div>

<script>
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    });
});

function copyWebhookUrl(inputId, btn) {
    const input = document.getElementById(inputId);
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Tersalin!';
        btn.style.background = '#00ff88';
        setTimeout(function() {
            btn.innerHTML = original;
            btn.style.background = 'var(--neon-cyan)';
        }, 2000);
    }).catch(function() {
        // Fallback for older browsers
        document.execCommand('copy');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Tersalin!';
        btn.style.background = '#00ff88';
        setTimeout(function() {
            btn.innerHTML = original;
            btn.style.background = 'var(--neon-cyan)';
        }, 2000);
    });
}

function installMikrotikTrigger() {
    const routerId = document.getElementById('trigger_router_id').value;
    const interval = document.getElementById('trigger_interval').value;
    
    if (!routerId) {
        alert('Pilih router terlebih dahulu');
        return;
    }
    
    document.getElementById('form_router_id').value = routerId;
    document.getElementById('form_interval').value = interval;
    document.getElementById('mikrotikTriggerForm').submit();
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
