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

$customerDevice = genieacsGetDevice($phone);
if (!$customerDevice) {
    echo "Device not found for $phone\n";
    exit;
}

echo "Selected Device ID: " . ($customerDevice['_id'] ?? 'unknown') . "\n";

$possibleTrees = [
    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice',
    'InternetGatewayDevice.LANDevice.1.Hosts.Host',
    'Device.Hosts.Host',
    'Device.WiFi.AccessPoint.1.AssociatedDevice',
    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AssociatedDevice'
];

$aggregatedHosts = [];

foreach ($possibleTrees as $treePath) {
    echo "\n--- TESTING PATH: $treePath ---\n";
    $treeData = genieacsGetValue($customerDevice, $treePath);
    
    if (is_array($treeData)) {
        echo "genieacsGetValue RETURNED ARRAY! Keys count: " . count(array_keys($treeData)) . "\n";
        
        foreach ($treeData as $key => $hostData) {
            echo "  > Processing Host Key: $key\n";
            if (!is_numeric($key)) {
                echo "    SKIPPING non-numeric key.\n";
                continue;
            }
            
            $host = [];
            
            // HostName parsing
            if (isset($hostData['HostName'])) {
                $host['HostName'] = is_array($hostData['HostName']) ? ($hostData['HostName']['_value'] ?? '') : $hostData['HostName'];
            } else {
                $host['HostName'] = 'Unknown Device (WiFi Client)';
            }
            
            // MAC
            $candidateMac = '';
            $macSource = $hostData['MACAddress'] ?? $hostData['PhysAddress'] ?? $hostData['AssociatedDeviceMACAddress'] ?? null;
            $macRaw = is_array($macSource) ? ($macSource['_value'] ?? '') : $macSource;
            if (!empty($macRaw)) {
                $candidateMac = strtoupper(trim((string)$macRaw));
            }
            echo "    Extracted MAC: '$candidateMac'\n";
            
            $host['MACAddress'] = !empty($candidateMac) ? $candidateMac : 'UNKNOWN-' . $key;
            
            // Active
            if (isset($hostData['Active'])) {
                $host['Active'] = is_array($hostData['Active']) ? ($hostData['Active']['_value'] ?? false) : $hostData['Active'];
            } elseif (isset($hostData['AssociatedDeviceAuthenticationState'])) {
                $authState = is_array($hostData['AssociatedDeviceAuthenticationState']) ? ($hostData['AssociatedDeviceAuthenticationState']['_value'] ?? false) : $hostData['AssociatedDeviceAuthenticationState'];
                $host['Active'] = ($authState === '1' || $authState === true || $authState === 'true');
            } else {
                $host['Active'] = true;
            }
            
            if ($host['Active'] === '1' || $host['Active'] === true || $host['Active'] === 'true') {
                $host['Active'] = true;
            } else {
                $host['Active'] = false;
            }
            echo "    Parsed Active State: " . ($host['Active'] ? 'TRUE' : 'FALSE') . "\n";
            
            if (!empty($host['MACAddress']) && $host['MACAddress'] !== '-') {
                $aggregatedHosts[$host['MACAddress']] = $host;
                echo "    ADDED TO AGGREGATED HOSTS under key: " . $host['MACAddress'] . "\n";
            }
        }
    } else {
        echo "genieacsGetValue returned NOT AN ARRAY or NULL.\n";
    }
}

echo "\n\nFINAL LANHOSTS ARRAY:\n";
$lanHosts = array_values($aggregatedHosts);
print_r($lanHosts);

