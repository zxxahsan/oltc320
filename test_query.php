<?php
require 'includes/config.php';
require 'includes/db.php';

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT i.*, c.name as customer_name, c.phone as customer_phone, p.name as package_name FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id LEFT JOIN packages p ON c.package_id = p.id WHERE i.id = ?');
    $stmt->execute([3]);
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
