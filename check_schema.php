<?php
require_once 'includes/db.php';
$pdo = getDB();
$stmt = $pdo->query("DESCRIBE customers");
$columns = $stmt->fetchAll();
foreach ($columns as $column) {
    echo $column['Field'] . "\n";
}
?>
