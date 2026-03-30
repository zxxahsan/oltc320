<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
$pdo = getDB();
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($tables);
