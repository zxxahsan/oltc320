<?php
/**
 * API: Debug ONU LAN Hosts
 */

header('Content-Type: text/plain');

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Allow anyone to access this temporarily for debugging
$pppoeUsername = $_GET['username'] ?? $_GET['pppoe_username'] ?? '';
$phone = $_GET['phone'] ?? '';

if (empty($pppoeUsername) && empty($phone)) {
    echo "ERROR: Please provide ?username=PPPOE_USERNAME or ?phone=PHONE_NUMBER\n";
    exit;
}

$device = null;

if (!empty($phone)) {
    $device = genieacsGetDevice($phone);
}

if (!$device && !empty($pppoeUsername)) {
    $custDb = fetchOne("SELECT phone FROM customers WHERE pppoe_username = ?", [$pppoeUsername]);
    if ($custDb && !empty($custDb['phone'])) {
        $device = genieacsGetDevice($custDb['phone']);
    }
}

if (!$device && !empty($pppoeUsername)) {
    $device = genieacsFindDeviceByPppoe($pppoeUsername);
}

if (!$device) {
    echo "ERROR: Device not found in GenieACS for the provided username/phone.\n";
    exit;
}

$serial = $device['_id'];
echo "SUCCESS! Device found: $serial\n";
echo "=========================================\n\n";

if (isset($_GET['refresh'])) {
    echo "=========================================\n";
    echo "TRIGGERING LIVE REFRESH TASK ON ACS...\n";
    
    // Test refreshObject
    $target = $_GET['target'] ?? 'InternetGatewayDevice.LANDevice.1.Hosts.Host.';
    echo "Target: $target\n\n";
    
    $genieacs = getGenieacsSettings();
    $url = rtrim($genieacs['url'], '/') . "/devices/" . rawurlencode($serial) . "/tasks?timeout=5000&connection_request";
    
    $data = [];
    if (isset($_GET['get_params'])) {
        echo "Using Task: getParameterValues\n";
        $data = [
            'name' => 'getParameterValues',
            'parameterNames' => [$target]
        ];
    } else {
        echo "Using Task: refreshObject\n";
        $data = [
            'name' => 'refreshObject',
            'objectName' => $target
        ];
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    
    if (!empty($genieacs['username'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
    }
    
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "CURL Executed in " . round($endTime - $startTime, 2) . "s\n";
    echo "HTTP Status: $httpCode\n";
    echo "Response: $response\n\n";
    
    // Re-fetch device
    $device = genieacsGetDevice($serial);
}

echo "Attempting to locate LAN Hosts/Associated Devices trees...\n\n";

$paths = [
    'InternetGatewayDevice.LANDevice.1.Hosts',
    'InternetGatewayDevice.LANDevice.1.Hosts.Host',
    'Device.Hosts',
    'Device.Hosts.Host',
    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice',
    'Device.WiFi.AccessPoint.1.AssociatedDevice',
    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AssociatedDevice',
    'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement.IPInterface.1.IPInterfaceStaticRoute',
    'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries'
];

foreach ($paths as $path) {
    $val = genieacsGetValue($device, $path);
    if ($val !== null) {
        echo "FOUND PATH: $path\n";
        echo "CONTENT:\n";
        if (is_array($val)) {
            // Unset object metadata to make it readable
            unset($val['_object']);
            unset($val['_writable']);
            unset($val['_timestamp']);
            print_r(array_keys($val));
            foreach ($val as $k => $v) {
                if (is_numeric($k)) {
                    echo "  Index [$k] keys: " . implode(', ', array_keys($v)) . "\n";
                }
            }
        } else {
            echo "  Value: " . print_r($val, true) . "\n";
        }
        echo "-----------------------------------------\n";
    }
}

echo "\nTOP LEVEL KEYS:\n";
print_r(array_keys($device));

echo "\nIf none of the paths above were found, it means GenieACS hasn't cached the Hosts tree.\n";
echo "You may need to go to the GenieACS 'Devices' page and click the 'Refresh' button on the Hosts parameter to fetch it from the ONT.\n";

