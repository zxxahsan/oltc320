<?php
require_once 'includes/db.php';

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

$pdo = getDB();
$stmt = $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'custom_landing_html'");
$stmt->execute([$fullHtml]);

echo "Successfully re-wrote custom_landing_html to DB!";
?>
