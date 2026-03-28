<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

$phone = $_GET['phone'] ?? $_GET['username'] ?? '';

if (empty($phone)) {
    echo "ERROR: Please provide ?phone=XXX\n";
    exit;
}

$device = genieacsGetDevice($phone);
echo "Device ID Tracker: " . ($device['_id'] ?? 'Not Found') . "\n";

$path = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice';
$assocObj = genieacsGetValue($device, $path);

if (is_array($assocObj)) {
    echo "\nFound AssociatedDevice!\n";
    foreach ($assocObj as $key => $client) {
        if (!is_numeric($key)) continue;
        echo "Client ID $key:\n";
        foreach ($client as $k => $v) {
            $valStr = is_array($v) ? ($v['_value'] ?? 'array') : $v;
            echo "  - $k => " . $valStr . "\n";
        }
        break; // Just dump the first one we find!
    }
} else {
    echo "Could not load AssociatedDevice!\n";
}

$hostsPath = 'InternetGatewayDevice.LANDevice.1.Hosts.Host';
$hostsObj = genieacsGetValue($device, $hostsPath);

if (is_array($hostsObj)) {
    echo "\nFound Hosts.Host!\n";
    foreach ($hostsObj as $key => $client) {
        if (!is_numeric($key)) continue;
        echo "Client ID $key:\n";
        foreach ($client as $k => $v) {
            $valStr = is_array($v) ? ($v['_value'] ?? 'array') : $v;
            echo "  - $k => " . $valStr . "\n";
        }
        break;
    }
}
