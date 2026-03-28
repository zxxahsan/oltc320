<?php
/**
 * GEMBOK ISP - Modern Landing Page
 */

// Check for installation
if (!file_exists(__DIR__ . '/includes/installed.lock')) {
    header("Location: install.php");
    exit;
}

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Fetch Packages
$packages = [];
try {
    $pdo = getDB();
    $packages = $pdo->query("SELECT * FROM packages ORDER BY price ASC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fail silently
}

// App settings
$appName = getSetting('app_name', 'GEMBOK');

// Landing settings
$heroTitle = getSiteSetting('hero_title', 'Internet Cepat <br>Tanpa Batas');
$heroDesc = getSiteSetting('hero_description', 'Nikmati koneksi internet fiber optic super cepat, stabil, dan unlimited untuk kebutuhan rumah maupun bisnis Anda. Gabung sekarang!');
$contactPhone = getSiteSetting('contact_phone', '+62 812-3456-7890');
$contactEmail = getSiteSetting('contact_email', 'info@gembok.net');
$contactAddress = getSiteSetting('contact_address', 'Jakarta, Indonesia');
$footerAbout = getSiteSetting('footer_about', 'Penyedia layanan internet terpercaya dengan jaringan fiber optic berkualitas untuk menunjang aktivitas digital Anda.');

// Feature settings
$f1_title = getSiteSetting('feature_1_title', 'Kecepatan Tinggi');
$f1_desc = getSiteSetting('feature_1_desc', 'Koneksi fiber optic dengan kecepatan simetris upload dan download.');

$f2_title = getSiteSetting('feature_2_title', 'Unlimited Quota');
$f2_desc = getSiteSetting('feature_2_desc', 'Akses internet sepuasnya tanpa batasan kuota (FUP).');

$f3_title = getSiteSetting('feature_3_title', 'Support 24/7');
$f3_desc = getSiteSetting('feature_3_desc', 'Tim teknis kami siap membantu Anda kapanpun jika terjadi gangguan.');

// Social settings
$s_fb = getSiteSetting('social_facebook', '#');
$s_ig = getSiteSetting('social_instagram', '#');
$s_tw = getSiteSetting('social_twitter', '#');
$s_yt = getSiteSetting('social_youtube', '#');

// Theme settings
$themeColor = getSiteSetting('theme_color', 'neon');

// Landing template settings
$landingTemplate = getSiteSetting('landing_template', 'neon');

// Map template names to file paths
$templateFiles = [
    'neon' => 'templates/landing/template_neon.php',
    'modern' => 'templates/landing/template_modern.php',
    'corporate' => 'templates/landing/template_corporate.php',
    'minimal' => 'templates/landing/template_minimal.php',
    'glassmorphism' => 'templates/landing/template_glassmorphism.php',
    'neumorphism' => 'templates/landing/template_neumorphism.php',
    'bento' => 'templates/landing/template_bento.php',
    'modern_ultra' => 'templates/landing/template_modern_ultra.php'
];

// Validate template selection
$templateFile = isset($templateFiles[$landingTemplate]) ? $templateFiles[$landingTemplate] : $templateFiles['neon'];

// Include the selected template
if (file_exists(__DIR__ . '/' . $templateFile)) {
    include __DIR__ . '/' . $templateFile;
} else {
    // Fallback to neon template if file doesn't exist
    include __DIR__ . '/templates/landing/template_neon.php';
}
?>
