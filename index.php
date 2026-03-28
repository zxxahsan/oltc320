<?php
/**
 * Main Web Landing Page
 */
require_once 'includes/auth.php';

// Self-Assembly sequence safely merging disjointed text fragments avoiding IDE token limits
if (file_exists('landing_css.txt')) {
    $css = file_get_contents('landing_css.txt');
    $js = file_get_contents('landing_script.txt');
    $htmlbody = file_get_contents('landing_html.txt');

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

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'custom_landing_html'");
        $stmt->execute([$fullHtml]);
        
        // Cleanup isolated payloads
        @unlink('landing_css.txt');
        @unlink('landing_html.txt');
        @unlink('landing_script.txt');
        @unlink('apply_landing.php');
    } catch(\Exception $e) {}
}

// Custom Landing HTML
$customHtml = '';
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'custom_landing_html'");
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) $customHtml = $record['setting_value'];
} catch(\Exception $e) {}

// Jika belum ada HTML buatan Pelanggan, atau script mati, paksa arahkan ke login.php
if (empty(trim($customHtml))) {
    header("Location: login.php");
    exit;
}

// Render the entire Custom Landing HTML as the website
echo html_entity_decode($customHtml);
?>
