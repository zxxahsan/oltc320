<?php
require_once __DIR__ . '/includes/db.php';

$search = "a";

$customers = fetchAll("
    SELECT id, name, pppoe_username, serial_number, phone, address, router_id
    FROM customers 
    WHERE name LIKE ? OR pppoe_username LIKE ? OR phone LIKE ?
    LIMIT 5
", ["%$search%", "%$search%", "%$search%"]);

echo "Found: " . count($customers) . " customers\n";
print_r($customers);
