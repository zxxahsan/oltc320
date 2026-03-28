<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mikrotik_api.php';

$routers = getAllRouters();
$r = $routers[0];
$mk = getMikrotikConnection($r['id']);
if (!$mk) die("Failed to connect to router.\n");

function mikrotikReadAllAndParseTest($socket) {
    $allWords = [];
    $done = false;
    $timeout = time() + 5;
    while (!$done && time() < $timeout) {
        $words = mikrotikReadSentence($socket);
        if (empty($words)) break;
        foreach ($words as $word) {
            $allWords[] = $word;
            if ($word === '!done' || strpos((string)$word, '!trap') === 0) {
                $done = true;
                break;
            }
        }
    }
    return $allWords;
}

echo "=== BULK INTERFACE WITHOUT PROPLIST ===\n";
mikrotikWrite($mk, '/interface/print');
mikrotikWrite($mk, '');
$raw = mikrotikReadAllAndParseTest($mk);
echo print_r(array_slice($raw, 0, 50), true) . "\n...\n";

echo "\n=== BULK INTERFACE WITH PROPLIST ===\n";
mikrotikWrite($mk, '/interface/print');
mikrotikWrite($mk, '=.proplist=name,rx-byte,tx-byte');
mikrotikWrite($mk, '');
$rawProp = mikrotikReadAllAndParseTest($mk);
echo print_r(array_slice($rawProp, 0, 50), true) . "\n...\n";

echo "\n=== PPP ACTIVE WITH PROPLIST ===\n";
mikrotikWrite($mk, '/ppp/active/print');
mikrotikWrite($mk, '=.proplist=name');
mikrotikWrite($mk, '');
$pppProp = mikrotikReadAllAndParseTest($mk);
echo print_r(array_slice($pppProp, 0, 50), true) . "\n...\n";
