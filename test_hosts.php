<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$customers = fetchAll("SELECT * FROM customers WHERE pppoe_username IS NOT NULL LIMIT 5");
foreach ($customers as $c) {
    $dev = null;
    if (!empty($c['phone'])) {
        $dev = genieacsGetDevice($c['phone']);
    }
    if (!$dev && !empty($c['pppoe_username'])) {
        $dev = genieacsGetDevice($c['pppoe_username']);
    }
    
    if ($dev) {
        $mac = $dev['_deviceId']['_OUI'] ?? 'Unknown';
        echo "Found device for " . $c['name'] . " (OUI: $mac)\n";
        
        $hostsRaw = genieacsGetValue($dev, 'InternetGatewayDevice.LANDevice.1.Hosts.Host');
        if (!$hostsRaw) {
            $hostsRaw = genieacsGetValue($dev, 'Device.Hosts.Host');
        }
        
        if ($hostsRaw) {
            echo "----> Found " . count($hostsRaw) . " hosts dynamically via getter!\n";
            $firstHost = null;
            foreach($hostsRaw as $k => $h) {
                if (is_numeric($k)) {
                    $firstHost = $h;
                    break;
                }
            }
            if ($firstHost) {
                print_r($firstHost);
                break;
            }
        } else {
            echo "----> Host property missing in API response!\n";
            // Check top level keys
            echo "Top Level Keys: " . implode(', ', array_keys($dev)) . "\n";
            if (isset($dev['InternetGatewayDevice']['LANDevice'])) {
                echo "LANDevice keys: " . implode(', ', array_keys($dev['InternetGatewayDevice']['LANDevice'])) . "\n";
                if (isset($dev['InternetGatewayDevice']['LANDevice']['1'])) {
                    echo "LANDevice.1 keys: " . implode(', ', array_keys($dev['InternetGatewayDevice']['LANDevice']['1'])) . "\n";
                    if (isset($dev['InternetGatewayDevice']['LANDevice']['1']['Hosts'])) {
                        echo "LANDevice.1.Hosts keys: " . implode(', ', array_keys($dev['InternetGatewayDevice']['LANDevice']['1']['Hosts'])) . "\n";
                    } else {
                        echo "LANDevice.1.Hosts object is missing completely!\n";
                    }
                }
            }
        }
    }
}
