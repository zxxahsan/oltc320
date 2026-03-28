<?php
/**
 * Admin Landing Page Custom HTML Settings
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Halaman Utama Kustom';

// Initialize table logic
$pdo = getDB();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Token CSRF tidak valid. Silakan coba lagi.');
        redirect('landing.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_landing') {
        $customHtml = trim($_POST['custom_landing_html']);
        
        $existing = fetchOne("SELECT id FROM site_settings WHERE setting_key = 'custom_landing_html'");
        if ($existing) {
            update('site_settings', ['setting_value' => $customHtml], "setting_key = 'custom_landing_html'");
        } else {
            insert('site_settings', ['setting_key' => 'custom_landing_html', 'setting_value' => $customHtml]);
        }
        
        setFlash('success', 'Kode HTML Halaman Utama berhasil disimpan!');
        redirect('landing.php');
    }
}

// Fetch existing HTML
$currentHtml = '';
$record = fetchOne("SELECT setting_value FROM site_settings WHERE setting_key = 'custom_landing_html'");
if ($record) {
    $currentHtml = $record['setting_value'];
}

// SELF ASSMBLY / DB WIPE: If the legacy HTML is cached, force load the new Parallax UI payload dynamically 
if (file_exists('../landing_css.txt') && strpos($currentHtml, 'Internet Cepat & Stabil') === false) {
    $css = file_get_contents('../landing_css.txt');
    $js = file_get_contents('../landing_script.txt');
    $htmlbody = file_get_contents('../landing_html.txt');

    $fullHtml = '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ahsan Network - Internet Cepat & Stabil</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>' . $css . '</style>
</head>
<body>' . $htmlbody . '<script>' . $js . '</script></body>
</html>';

    // Force Database update reflecting the new default
    if ($record) {
        update('site_settings', ['setting_value' => $fullHtml], "setting_key = 'custom_landing_html'");
    } else {
        insert('site_settings', ['setting_key' => 'custom_landing_html', 'setting_value' => $fullHtml]);
    }
    $currentHtml = $fullHtml;
}

if (empty($currentHtml)) {
    $appNameDisplay = getSetting('app_name', 'GEMBOK ISP');
    $currentHtml = <<<HTML
<div style="height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px; text-align: center; color: white;">
    <h1 style="font-size: 3rem; margin-bottom: 20px; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 800;">$appNameDisplay</h1>
    <p style="font-size: 1.15rem; color: #b0b0c0; max-width: 80%; line-height: 1.7; margin-bottom: 40px;">
        Selamat datang di Portal Layanan Internet modern. Kami menyediakan konektivitas fiber optik tanpa batas dengan kestabilan penuh untuk rumah dan bisnis Anda.
    </p>
    
    <div style="display: flex; gap: 20px; flex-wrap: wrap; justify-content: center;">
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 25px; border-radius: 16px; width: 220px; text-align: left; transition: transform 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
            <h3 style="color: #00f5ff; margin-bottom: 15px; font-size: 1.2rem;"><i class="fas fa-rocket"></i> Fiber Kilat</h3>
            <p style="font-size: 0.9rem; color: #9ca3af; margin: 0;">Teknologi kabel serat optik dengan latensi terendah untuk gaming.</p>
        </div>
        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); padding: 25px; border-radius: 16px; width: 220px; text-align: left; transition: transform 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
            <h3 style="color: #bf00ff; margin-bottom: 15px; font-size: 1.2rem;"><i class="fas fa-infinity"></i> Anti FUP</h3>
            <p style="font-size: 0.9rem; color: #9ca3af; margin: 0;">Nikmati internet Unlimited sungguhan tanpa takut kecepatan diturunkan.</p>
        </div>
    </div>
</div>
HTML;
}

ob_start();
?>

<style>
.editor-container { 
    display: flex; 
    gap: 20px; 
    height: 600px; 
    margin-top: 15px; 
}
.editor-pane { 
    flex: 1; 
    display: flex; 
    flex-direction: column; 
    background: #0a0a0f; 
    border-radius: 8px; 
    border: 1px solid var(--border-color); 
    overflow: hidden; 
}
.editor-header { 
    background: #1a1a24; 
    padding: 12px 15px; 
    font-weight: bold; 
    border-bottom: 2px solid var(--neon-cyan); 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
}
textarea.code-editor { 
    flex: 1; 
    width: 100%; 
    resize: none; 
    border: none; 
    background: transparent; 
    color: #4ade80; 
    font-family: monospace; 
    font-size: 14px;
    padding: 15px; 
    outline: none; 
    line-height: 1.5;
}
iframe.preview-frame { 
    flex: 1; 
    width: 100%; 
    height: 100%; 
    border: none; 
    background: radial-gradient(circle at center, #1a1a2e 0%, #0a0a12 100%); 
}

@media (max-width: 900px) {
    .editor-container { flex-direction: column; height: auto; }
    textarea.code-editor { min-height: 300px; }
    iframe.preview-frame { min-height: 400px; }
}
</style>

<!-- Custom HTML Editor -->
<div class="card" style="border-top: 3px solid var(--neon-cyan);">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-desktop" style="color: var(--neon-cyan);"></i> Live Code Editor - Halaman Utama / Promo</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 5px;">Ketik kode HTML / CSS Anda di bilah sebelah Kiri, dan saksikan perubahannya secara *Real-time* di bilah Kanan. Hasil render ini akan tampil persis di samping form Login pada halaman depan utama (index).</p>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_landing">
        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
        
        <div class="editor-container">
            <!-- PANE 1: Editor -->
            <div class="editor-pane">
                <div class="editor-header">
                    <span><i class="fas fa-code"></i> HTML / CSS Raw Code</span>
                </div>
                <textarea id="htmlEditor" name="custom_landing_html" class="code-editor" spellcheck="false"><?php echo htmlspecialchars($currentHtml); ?></textarea>
            </div>
            
            <!-- PANE 2: Live Preview -->
            <div class="editor-pane">
                <div class="editor-header" style="border-bottom-color: #ff00aa;">
                    <span><i class="fas fa-eye"></i> Live Render Preview</span>
                </div>
                <!-- Sandbox frame untuk mencegah JS internal tabrakan dengan admin portal -->
                <iframe id="previewFrame" class="preview-frame" sandbox="allow-same-origin allow-scripts"></iframe>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 20px; font-weight: bold; background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%); border: none;">
            <i class="fas fa-cloud-upload-alt"></i> Simpan Desain & Terapkan Publik
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editor = document.getElementById('htmlEditor');
    const frame = document.getElementById('previewFrame');

    function updatePreview() {
        const code = editor.value;
        const htmlDoc = `
            <!DOCTYPE html>
            <html>
            <head>
                <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    body { 
                        margin: 0; 
                        padding: 0;
                        font-family: 'Inter', sans-serif; 
                        color: #ffffff;
                        background: transparent;
                    }
                </style>
            </head>
            <body>
                ${code}
            </body>
            </html>
        `;
        // Injecting safely via srcdoc avoiding cross-origin sandboxing blocks entirely.
        frame.srcdoc = htmlDoc;
    }

    // Update on every keystroke
    editor.addEventListener('input', updatePreview);
    
    // Initial load
    updatePreview();
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
