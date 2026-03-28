<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$_GET['invoice_id'] = 3;

try {
    require 'portal/payment.php';
} catch (Throwable $e) {
    echo "<h1>Fatal Error Caught</h1>";
    echo "<pre>" . print_r($e, true) . "</pre>";
}
