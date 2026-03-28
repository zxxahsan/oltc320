<?php
header('Content-Type: text/plain');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

$phone = $_GET['phone'] ?? $_GET['username'] ?? '';

if (empty($phone)) {
    echo "ERROR: Please provide ?phone=XXX\n";
    exit;
}

$device = genieacsGetDevice($phone);
if (!$device) {
    echo "Device not found.\n";
    exit;
}

echo "Dump of InternetGatewayDevice.LANDevice.1:\n";
$lan1 = $device['InternetGatewayDevice']['LANDevice']['1'] ?? null;
if ($lan1) {
    echo "Keys in LANDevice.1: \n  " . implode("\n  ", array_keys($lan1)) . "\n\n";

    if (isset($lan1['WLANConfiguration'])) {
        echo "Keys in WLANConfiguration: \n  " . implode("\n  ", array_keys($lan1['WLANConfiguration'])) . "\n\n";
        
        if (isset($lan1['WLANConfiguration']['1'])) {
            echo "Keys in WLANConfiguration.1: \n  " . implode("\n  ", array_keys($lan1['WLANConfiguration']['1'])) . "\n\n";

            if (isset($lan1['WLANConfiguration']['1']['AssociatedDevice'])) {
                echo "AssociatedDevice IS SET!\n";
                // Don't print whole associated device since it might be recursive, just keys
                $assocObj = $lan1['WLANConfiguration']['1']['AssociatedDevice'];
                unset($assocObj['_object']);
                unset($assocObj['_writable']);
                echo "AssociatedDevice keys: \n  " . implode("\n  ", array_keys($assocObj)) . "\n";
            } else {
                echo "AssociatedDevice IS MISSING in WLANConfiguration.1\n";
            }
        }
    }

    if (isset($lan1['Hosts'])) {
        echo "Keys in Hosts: \n  " . implode("\n  ", array_keys($lan1['Hosts'])) . "\n\n";
        
        if (isset($lan1['Hosts']['Host'])) {
            echo "Host IS SET in Hosts!\n";
            $hostObj = $lan1['Hosts']['Host'];
            unset($hostObj['_object']);
            unset($hostObj['_writable']);
            echo "Host keys: \n  " . implode("\n  ", array_keys($hostObj)) . "\n";
        } else {
            echo "Host IS MISSING in Hosts\n";
        }
    }
} else {
    echo "LANDevice.1 is missing!\n";
}
