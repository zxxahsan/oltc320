<?php
require_once __DIR__ . '/includes/db.php';

$pdo = getDB();

try {
    // Menambahkan kolom total_ports jika belum ada
    $pdo->exec("ALTER TABLE odps ADD COLUMN total_ports INT DEFAULT 8");
    echo "BERHASIL: Kolom total_ports berhasil ditambahkan ke tabel odps.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "INFO: Kolom total_ports sudah ada.\n";
    } else {
        echo "GAGAL: " . $e->getMessage() . "\n";
    }
}
