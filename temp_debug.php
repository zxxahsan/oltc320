<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
$pdo = getDB();

echo "Testing DB Connection...\n";

// 1. Get Table Structure for Invoices
$stmt = $pdo->query("DESCRIBE invoices");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_PRETTY_PRINT) . "\n";

// 2. Test Insert manually
try {
    $data = [
        'invoice_number' => 'INV-TEST-9999',
        'customer_id' => 1,
        'amount' => 100000,
        'status' => 'unpaid',
        'due_date' => date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s')
    ];
    $id = insert('invoices', $data);
    echo "Insert ID returned by insert(): " . var_export($id, true) . "\n";
    
    // Cleanup
    if ($id) {
        $pdo->exec("DELETE FROM invoices WHERE id = $id");
        echo "Cleanup Done.\n";
    }
} catch (Exception $e) {
    echo "Exception Caught: " . $e->getMessage() . "\n";
}
