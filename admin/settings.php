<?php
/**
 * Admin Settings
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

// Helper function to get setting with fallback to config.php constant
function getSettingValue($key, $default = '') {
    global $settings;
    
    // First check database
    if (isset($settings[$key]) && $settings[$key] !== '') {
        return $settings[$key];
    }
    
    // Fallback to config.php constant
    if (defined($key)) {
        return constant($key);
    }
    
    return $default;
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
                    'DEFAULT_MONITOR_INTERFACE' => sanitize($_POST['default_monitor_interface'])
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
                    'CRON_TOKEN' => sanitize($_POST['cron_token'])
                ];
                
                foreach ($integrationSettings as $key => $value) {
                    $existing = fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                    if ($existing) {
                        update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                    } else {
                        insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                    }
                }
                
                setFlash('success', 'Pengaturan integrasi berhasil disimpan');
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
        }
    }
}

ob_start();
?>

<!-- System Settings -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-cog"></i> Pengaturan Sistem</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_system">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="form-group">
            <label class="form-label">Nama Aplikasi</label>
            <input type="text" name="app_name" class="form-control" value="<?php echo htmlspecialchars($settings['app_name'] ?? 'GEMBOK'); ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">Timezone</label>
            <select name="timezone" class="form-control">
                <option value="Asia/Jakarta" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jakarta' ? 'selected' : ''; ?>>Asia/Jakarta (WIB)</option>
                <option value="Asia/Makassar" <?php echo ($settings['timezone'] ?? '') === 'Asia/Makassar' ? 'selected' : ''; ?>>Asia/Makassar (WITA)</option>
                <option value="Asia/Jayapura" <?php echo ($settings['timezone'] ?? '') === 'Asia/Jayapura' ? 'selected' : ''; ?>>Asia/Jayapura (WIT)</option>
                <option value="Asia/Pontianak" <?php echo ($settings['timezone'] ?? '') === 'Asia/Pontianak' ? 'selected' : ''; ?>>Asia/Pontianak (WIB)</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Mata Uang</label>
            <select name="currency" class="form-control">
                <option value="IDR" <?php echo ($settings['currency'] ?? '') === 'IDR' ? 'selected' : ''; ?>>IDR - Rupiah</option>
                <option value="USD" <?php echo ($settings['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD - Dollar</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Traffic Monitor Interface Default</label>
            <input type="text" name="default_monitor_interface" class="form-control" value="<?php echo htmlspecialchars($settings['DEFAULT_MONITOR_INTERFACE'] ?? 'ether1'); ?>" placeholder="ether1, pppoe-out1, wlan1...">
            <small style="color: var(--text-muted);">Interface awal yang langsung ditampilkan di grafik Dashboard.</small>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label">Invoice Prefix</label>
                <input type="text" name="invoice_prefix" class="form-control" value="<?php echo htmlspecialchars($settings['invoice_prefix'] ?? 'INV'); ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Invoice Start Number</label>
                <input type="number" name="invoice_start" class="form-control" value="<?php echo (int)($settings['invoice_start'] ?? 1); ?>">
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
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label">MikroTik IP Address</label>
                <input type="text" name="mikrotik_host" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('MIKROTIK_HOST')); ?>" placeholder="192.168.1.1">
            </div>
            
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="mikrotik_user" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('MIKROTIK_USER')); ?>" placeholder="admin">
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="mikrotik_pass" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('MIKROTIK_PASS')); ?>" placeholder="Masukkan password">
            </div>
            
            <div class="form-group">
                <label class="form-label">API Port</label>
                <input type="number" name="mikrotik_port" class="form-control" value="<?php echo (int)getSettingValue('MIKROTIK_PORT', 8728); ?>">
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
        
        <div class="form-group">
            <label class="form-label">GenieACS URL</label>
            <input type="text" name="genieacs_url" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('GENIEACS_URL')); ?>" placeholder="http://192.168.1.1:7557">
            <small style="color: var(--text-muted);">URL lengkap termasuk port (default: 7557)</small>
            <?php if (defined('GENIEACS_URL') && GENIEACS_URL && !isset($settings['GENIEACS_URL'])): ?>
                <small style="color: var(--neon-cyan);"><i class="fas fa-info-circle"></i> Nilai dari config.php (belum disimpan di database)</small>
            <?php endif; ?>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label">Username (Opsional)</label>
                <input type="text" name="genieacs_username" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('GENIEACS_USERNAME')); ?>" placeholder="Username GenieACS">
            </div>
            
            <div class="form-group">
                <label class="form-label">Password (Opsional)</label>
                <input type="password" name="genieacs_password" class="form-control" value="<?php echo htmlspecialchars(getSettingValue('GENIEACS_PASSWORD')); ?>" placeholder="Password GenieACS">
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
        
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan);">Payment Gateway (Tripay)</h4>
        
        <!-- Tripay Webhook URL Info Box -->
        <div style="background: rgba(0,200,255,0.08); border: 1px solid var(--neon-cyan); border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-link" style="color: var(--neon-cyan);"></i>
                <strong style="color: var(--neon-cyan);">URL Callback / Webhook Tripay</strong>
            </div>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 10px;">
                Paste URL ini ke menu <strong>Callback URL</strong> di pengaturan merchant Tripay Anda.
            </p>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="tripay_webhook_url" readonly
                    value="<?php echo APP_URL; ?>/webhooks/tripay.php"
                    style="flex: 1; background: rgba(0,0,0,0.3); border: 1px solid rgba(0,200,255,0.3); color: #fff; border-radius: 6px; padding: 8px 12px; font-size: 13px; cursor: pointer;"
                    onclick="this.select()">
                <button type="button" onclick="copyWebhookUrl('tripay_webhook_url', this)" style="background: var(--neon-cyan); color: #000; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; white-space: nowrap;">
                    <i class="fas fa-copy"></i> Salin
                </button>
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Tripay API Key</label>
            <input type="text" name="tripay_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_API_KEY'] ?? ''); ?>" placeholder="Masukkan API Key Tripay">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label class="form-label">Tripay Private Key</label>
                <input type="password" name="tripay_private_key" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_PRIVATE_KEY'] ?? ''); ?>" placeholder="Masukkan Private Key">
            </div>
            
            <div class="form-group">
                <label class="form-label">Tripay Merchant Code</label>
                <input type="text" name="tripay_merchant_code" class="form-control" value="<?php echo htmlspecialchars($settings['TRIPAY_MERCHANT_CODE'] ?? ''); ?>" placeholder="Masukkan Merchant Code">
            </div>
        </div>
        
        <hr style="margin: 30px 0; border-color: var(--border-color);">
        
        <h4 style="margin-bottom: 15px; color: var(--neon-cyan);">Cronjob & Task Scheduler</h4>
        
        <!-- Cronjob Info Box -->
        <div style="background: rgba(0,255,136,0.08); border: 1px solid #00ff88; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <i class="fas fa-clock" style="color: #00ff88;"></i>
                <strong style="color: #00ff88;">Konfigurasi Cronjob</strong>
            </div>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 10px;">
                Gunakan salah satu metode di bawah ini untuk menjalankan tugas otomatis (isolir otomatis, kirim invoice, dll). Sangat disarankan untuk menjalankan setiap <strong>1 menit</strong>.
            </p>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 12px; color: #00ff88; margin-bottom: 5px;">Metode 1: Script CLI (Direkomendasikan untuk VPS)</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" id="cron_cli_path" readonly
                        value="* * * * * /usr/bin/php <?php echo str_replace('\\', '/', realpath(__DIR__ . '/../cron/scheduler.php')); ?>"
                        style="flex: 1; background: rgba(0,0,0,0.3); border: 1px solid rgba(0,255,136,0.3); color: #fff; border-radius: 6px; padding: 8px 12px; font-size: 12px; font-family: monospace; cursor: pointer;"
                        onclick="this.select()">
                    <button type="button" onclick="copyWebhookUrl('cron_cli_path', this)" style="background: #00ff88; color: #000; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; white-space: nowrap;">
                        <i class="fas fa-copy"></i> Salin
                    </button>
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 12px; color: #00ff88; margin-bottom: 5px;">Metode 2: URL Task (Untuk aaPanel / Cloud Hosting)</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php 
                    $cronToken = getSettingValue('CRON_TOKEN');
                    if (!$cronToken) {
                        $cronToken = bin2hex(random_bytes(16));
                        // Save immediately so run.php can validate it
                        insert('settings', ['setting_key' => 'CRON_TOKEN', 'setting_value' => $cronToken]);
                    }
                    $cronUrl = APP_URL . "/cron/run.php?token=" . $cronToken;
                    ?>
                    <input type="text" id="cron_web_url" readonly
                        value="<?php echo $cronUrl; ?>"
                        style="flex: 1; background: rgba(0,0,0,0.3); border: 1px solid rgba(0,255,136,0.3); color: #fff; border-radius: 6px; padding: 8px 12px; font-size: 12px; font-family: monospace; cursor: pointer;"
                        onclick="this.select()">
                    <button type="button" onclick="copyWebhookUrl('cron_web_url', this)" style="background: #00ff88; color: #000; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: bold; white-space: nowrap;">
                        <i class="fas fa-copy"></i> Salin
                    </button>
                </div>
                <input type="hidden" name="cron_token" value="<?php echo $cronToken; ?>">
            </div>
        </div>

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
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
