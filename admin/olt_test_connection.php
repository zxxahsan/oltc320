<?php
require_once __DIR__ . '/../includes/olt_api.php';

// Disable error reporting for cleaner output during test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "--- OLT Connection Test ---\n";

$olt = fetchOne("SELECT * FROM olt_configs LIMIT 1");
if (!$olt) {
    die("Error: No OLT configuration found in database.\n");
}

echo "Target: " . $olt['name'] . " (" . $olt['host'] . ":" . $olt['port'] . ")\n";
echo "Type: " . $olt['type'] . "\n";
echo "Connecting...\n";

$client = new OltTelnetClient($olt['host'], $olt['port']);

try {
    $client->connect($olt['username'], $olt['password']);
    echo "Success: Logged in to OLT!\n";

    if (!empty($olt['enable_password'])) {
        echo "Elevating privileges (enable mode)...\n";
        $client->enable($olt['enable_password']);
        echo "Success: Entered enable mode!\n\n";
    }

    // Ultimate Comprehensive Test
    $test_commands = [
        "terminal length 0",
        "show running-config",
        "show gpon onu unauthentication",
        "show epon onu-unauthentication",
        "configure terminal",
        "show gpon onu uncfg",
        "show onu unconfiguration",
        "show ?",
        "exit", // Exit config mode
        "exit"  // Exit OLT
    ];

    foreach ($test_commands as $cmd) {
        echo "Executing: $cmd\n";
        $response = $client->execute($cmd);
        echo "--- Response ---\n" . $response . "\n----------------\n\n";
    }

    $client->disconnect();
    echo "Test completed.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (isset($client)) $client->disconnect();
}
