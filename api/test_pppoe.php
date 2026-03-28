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

$genieacs = getGenieacsSettings();
if (empty($genieacs['url'])) {
    echo "GenieACS URL empty.\n"; exit;
}

echo "=== DIAGNOSING GENIEACS TAG SORTING FOR PHONE: $phone ===\n";

$query = json_encode(['_tags' => $phone]);
$url = rtrim($genieacs['url'], '/') . '/devices/?query=' . urlencode($query) . '&projection=_id,InternetGatewayDevice,VirtualParameters,Device,_lastInform';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
if (!empty($genieacs['username']) && !empty($genieacs['password'])) {
    curl_setopt($ch, CURLOPT_USERPWD, $genieacs['username'] . ':' . $genieacs['password']);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 200) {
    $devices = json_decode($response, true);
    
    echo "Total Matching Devices Found: " . count($devices) . "\n\n";
    
    foreach ($devices as $index => $device) {
        echo "Device Index [$index]:\n";
        echo "  ID: " . ($device['_id'] ?? 'unknown') . "\n";
        echo "  Last Inform: " . ($device['_lastInform'] ?? 'NONE') . "\n";
        
        $hostsVal = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'] ?? null;
        if (is_array($hostsVal)) {
            echo "  Found Hosts.Host! Count: " . count(array_filter(array_keys($hostsVal), 'is_numeric')) . "\n";
        } else {
            echo "  Hosts.Host is NOT an array.\n";
        }
        
        $assocVal = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['AssociatedDevice'] ?? null;
        if (is_array($assocVal)) {
            echo "  Found AssociatedDevice! Count: " . count(array_filter(array_keys($assocVal), 'is_numeric')) . "\n";
        } else {
            echo "  AssociatedDevice is NOT an array.\n";
        }
        echo "\n";
    }
    
    echo "\n=== TESTING genieacsGetDevice() ABSTRACTION ===\n";
    $bestDevice = genieacsGetDevice($phone);
    echo "Selected Device ID: " . ($bestDevice['_id'] ?? 'unknown') . "\n";
    echo "Selected Last Inform: " . ($bestDevice['_lastInform'] ?? 'NONE') . "\n";
    
} else {
    echo "FAILED TO FETCH DEVICES: HTTP $httpCode\n";
}
