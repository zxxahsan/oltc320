<?php
require_once 'includes/db.php';
$pdo = getDB();
$tables = ['customers', 'onu_locations', 'odps'];
foreach ($tables as $t) {
    echo "--- TABLE: $t ---\n";
    $stmt = $pdo->query("DESCRIBE $t");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']} | {$row['Default']}\n";
    }
    echo "\n";
}
