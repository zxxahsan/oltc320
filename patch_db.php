<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = getDB();
    
    // Check if column status exists in hotspot_sales
    $colCheck = $pdo->query("SHOW COLUMNS FROM hotspot_sales LIKE 'status'");
    if ($colCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN status ENUM('inactive', 'active') DEFAULT 'inactive'");
        echo "Added 'status' column.\n";
    }
    
    // Check if column used_at exists in hotspot_sales
    $colCheck2 = $pdo->query("SHOW COLUMNS FROM hotspot_sales LIKE 'used_at'");
    if ($colCheck2->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN used_at DATETIME NULL");
        echo "Added 'used_at' column.\n";
    }

    echo "DB Patch Success!";
} catch (Exception $e) {
    echo "DB Patch Error: " . $e->getMessage();
}
