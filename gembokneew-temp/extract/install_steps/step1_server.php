<?php
// Step 1: Server Check
$canWriteConfig = is_writable('includes/') && (!file_exists('includes/config.php') || is_writable('includes/config.php'));
$canWriteLock = is_writable('includes/') && (!file_exists('includes/installed.lock') || is_writable('includes/installed.lock'));
$permissionsOk = is_writable('.') && $canWriteConfig && $canWriteLock;

// Check logs and uploads directories
$logsWritable = (is_dir('logs') && is_writable('logs')) || (!is_dir('logs') && is_writable('.'));
$uploadsWritable = (is_dir('uploads') && is_writable('uploads')) || (!is_dir('uploads') && is_writable('.'));

// Detect web server
$webServer = php_sapi_name();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$isNginx = stripos($serverSoftware, 'nginx') !== false;
$isApache = stripos($serverSoftware, 'apache') !== false;
$isLiteSpeed = stripos($serverSoftware, 'litespeed') !== false;

$checks = [
    'PHP Version' => [
        'check' => version_compare(PHP_VERSION, '7.4', '>='),
        'message' => 'PHP Version: ' . PHP_VERSION . ' (Required: 7.4+)',
        'icon' => version_compare(PHP_VERSION, '7.4', '>=') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'PDO MySQL Extension' => [
        'check' => extension_loaded('pdo_mysql'),
        'message' => 'PDO MySQL Extension: ' . (extension_loaded('pdo_mysql') ? 'Installed' : 'Not Installed'),
        'icon' => extension_loaded('pdo_mysql') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'CURL Extension' => [
        'check' => extension_loaded('curl'),
        'message' => 'CURL Extension: ' . (extension_loaded('curl') ? 'Installed' : 'Not Installed'),
        'icon' => extension_loaded('curl') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'JSON Extension' => [
        'check' => extension_loaded('json'),
        'message' => 'JSON Extension: ' . (extension_loaded('json') ? 'Installed' : 'Not Installed'),
        'icon' => extension_loaded('json') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'MBString Extension' => [
        'check' => extension_loaded('mbstring'),
        'message' => 'MBString Extension: ' . (extension_loaded('mbstring') ? 'Installed' : 'Not Installed — aktifkan di PHP Settings'),
        'icon' => extension_loaded('mbstring') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'GD Extension' => [
        'check' => extension_loaded('gd'),
        'message' => 'GD Extension: ' . (extension_loaded('gd') ? 'Installed' : 'Not Installed (Optional)'),
        'icon' => extension_loaded('gd') ? 'fas fa-check-circle' : 'fas fa-exclamation-circle',
        'optional' => true
    ],
    'Intl Extension' => [
        'check' => extension_loaded('intl'),
        'message' => 'Intl Extension: ' . (extension_loaded('intl') ? 'Installed' : 'Not Installed (Optional)'),
        'icon' => extension_loaded('intl') ? 'fas fa-check-circle' : 'fas fa-exclamation-circle',
        'optional' => true
    ],
    'FileInfo Extension' => [
        'check' => extension_loaded('fileinfo'),
        'message' => 'FileInfo Extension: ' . (extension_loaded('fileinfo') ? 'Installed' : 'Not Installed (Optional)'),
        'icon' => extension_loaded('fileinfo') ? 'fas fa-check-circle' : 'fas fa-exclamation-circle',
        'optional' => true
    ],
    'Session Support' => [
        'check' => function_exists('session_start'),
        'message' => 'Session Support: ' . (function_exists('session_start') ? 'Available' : 'Not Available'),
        'icon' => function_exists('session_start') ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'File Permissions' => [
        'check' => $permissionsOk,
        'message' => 'File Permissions (includes/): ' . ($permissionsOk ? 'Writable' : 'Not Writable — chmod 755 atau 775'),
        'icon' => $permissionsOk ? 'fas fa-check-circle' : 'fas fa-times-circle'
    ],
    'Logs Directory' => [
        'check' => $logsWritable,
        'message' => 'Logs Directory: ' . ($logsWritable ? 'Writable' : 'Not Writable — akan dibuat otomatis saat install'),
        'icon' => $logsWritable ? 'fas fa-check-circle' : 'fas fa-exclamation-circle',
        'optional' => true
    ],
    'Web Server' => [
        'check' => true,
        'message' => 'Web Server: ' . htmlspecialchars($serverSoftware),
        'icon' => 'fas fa-check-circle'
    ]
];

$allPassed = true;
foreach ($checks as $check) {
    if (!$check['check'] && !isset($check['optional'])) {
        $allPassed = false;
        break;
    }
}
?>

<h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 10px; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">🔍 Server Requirements Check</h2>
<p style="margin-bottom: 20px; color: #b0b0c0;">Kami akan mengecek apakah server Anda memenuhi requirements untuk menjalankan GEMBOK.</p>

<?php foreach ($checks as $name => $check): ?>
    <div class="check-item" style="display: flex; align-items: center; padding: 15px; background: #161628; border-radius: 8px; margin-bottom: 10px;">
        <i class="<?php echo $check['icon']; ?>" style="font-size: 1.2rem; margin-right: 15px; color: <?php echo $check['check'] ? '#00ff88' : '#ff4757'; ?>"></i>
        <div>
            <strong style="color: #ffffff;"><?php echo $name; ?></strong>
            <p style="margin: 5px 0 0 0; color: #b0b0c0; font-size: 0.9rem;"><?php echo $check['message']; ?></p>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($isNginx): ?>
    <div style="margin-top: 15px; padding: 15px; background: rgba(255, 107, 53, 0.1); border: 1px solid #ff6b35; border-radius: 8px;">
        <strong style="color: #ff6b35;">⚠️ Nginx Detected — Perlu Konfigurasi Tambahan</strong>
        <p style="margin: 8px 0 0 0; color: #b0b0c0; font-size: 0.9rem;">
            Nginx tidak support <code>.htaccess</code>. Tambahkan rewrite rules di konfigurasi site Nginx/aaPanel:
        </p>
        <pre style="margin: 10px 0 0 0; padding: 10px; background: rgba(0,0,0,0.3); border-radius: 6px; color: #00f5ff; font-size: 0.8rem; overflow-x: auto;">location ~ /\.(htaccess|env|git) {
    deny all;
}
location ~ ^/(logs|uploads)/ {
    deny all;
}
location ~ ^/includes/ {
    deny all;
}</pre>
    </div>
<?php endif; ?>

<?php if ($allPassed): ?>
    <div class="alert alert-success" style="margin-top: 20px; background: rgba(0, 255, 136, 0.1); border: 1px solid #00ff88; color: #00ff88; padding: 15px; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-check-circle"></i>
        <div>
            <strong>✅ Semua Requirements Terpenuhi!</strong>
            <p style="margin: 5px 0 0 0;">Server Anda siap untuk menginstal GEMBOK.</p>
        </div>
    </div>
    <div style="text-align: right; margin-top: 20px;">
        <a href="install.php?step=2" class="btn btn-primary" style="padding: 12px 24px; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; color: #ffffff; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); transition: all 0.3s; box-shadow: 0 4px 20px rgba(191, 0, 255, 0.3); border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
            Lanjut →
        </a>
    </div>
<?php else: ?>
    <div class="alert alert-error" style="margin-top: 20px; background: rgba(255, 71, 87, 0.2); border: 1px solid #ff4757; color: #ff4757; padding: 15px; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>⚠️ Beberapa Requirements Tidak Terpenuhi</strong>
            <p style="margin: 5px 0 0 0;">Silakan hubungi provider hosting Anda untuk mengaktifkan extension yang diperlukan.<br>
            <small>Jika menggunakan aaPanel: Website → PHP → Extensions → Install yang dibutuhkan</small></p>
        </div>
    </div>
    <div style="text-align: right; margin-top: 20px;">
        <button class="btn btn-primary" style="padding: 12px 24px; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; color: #ffffff; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); transition: all 0.3s; box-shadow: 0 4px 20px rgba(191, 0, 255, 0.3); border: 1px solid transparent;" onclick="location.reload()">Cek Ulang</button>
    </div>
<?php endif; ?>

