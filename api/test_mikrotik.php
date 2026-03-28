<?php
require_once '../includes/functions.php';
require_once '../includes/mikrotik_api.php';

header('Content-Type: application/json');

$routers = getAllRouters();
$output = [];

foreach ($routers as $r) {
    if ($mk = getMikrotikConnection($r['id'])) {
        mikrotikWrite($mk, '/interface/print');
        mikrotikWrite($mk, '=.proplist=name,rx-byte,tx-byte');
        $interfaces = mikrotikRead($mk);
        
        $intfNames = [];
        if (!empty($interfaces) && !isset($interfaces['!trap'])) {
            foreach ($interfaces as $intf) {
                if (isset($intf['name'])) {
                    $intfNames[] = $intf['name'];
                }
            }
        }
        
        mikrotikWrite($mk, '/ppp/active/print');
        mikrotikWrite($mk, '=.proplist=name');
        $pppActive = mikrotikRead($mk);
        $pppNames = [];
        if (!empty($pppActive) && !isset($pppActive['!trap'])) {
            foreach ($pppActive as $p) {
                if (isset($p['name'])) {
                    $pppNames[] = $p['name'];
                }
            }
        }
        
        $output[$r['id']] = [
            'router' => $r['name'],
            'interfaces' => $intfNames,
            'ppp_active' => $pppNames
        ];
    } else {
        $output[$r['id']] = "Failed to connect";
    }
}

// Check some customer DB entries
$customers = fetchAll("SELECT pppoe_username, router_id FROM customers WHERE status IN ('registered', 'active') LIMIT 10");
$output['customers'] = $customers;

echo json_encode($output, JSON_PRETTY_PRINT);
