<?php
require_once 'includes/auth.php';

try {
    $pdo = getDB();
    
    // Tambah kolom protocol dan ganti nama telnet_port menjadi port agar universal
    $pdo->exec("ALTER TABLE olts ADD COLUMN IF NOT EXISTS protocol ENUM('telnet', 'ssh') DEFAULT 'ssh' AFTER password");
    $pdo->exec("ALTER TABLE olts CHANGE COLUMN telnet_port port INT DEFAULT 22");
    
    echo "Database OLT berhasil diperbarui ke support SSH!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
