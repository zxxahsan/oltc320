<?php
echo "<h1>System Capability Check</h1>";

echo "<h2>PHP Extensions</h2>";
$extensions = ['ssh2', 'sockets', 'openssl', 'curl', 'mbstring'];
foreach ($extensions as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? "<b style='color:green'>LOADED</b>" : "<b style='color:red'>NOT FOUND</b>") . "<br>";
}

echo "<h2>External Commands</h2>";
$commands = ['ssh', 'sshpass', 'telnet', 'ping'];
foreach ($commands as $cmd) {
    $out = shell_exec("which $cmd 2>&1");
    echo "$cmd: " . ($out ? "<b style='color:green'>$out</b>" : "<b style='color:red'>NOT FOUND</b>") . "<br>";
}

echo "<h2>Network Test</h2>";
require_once 'includes/auth.php';
$olts = fetchAll("SELECT * FROM olts");
foreach ($olts as $olt) {
    echo "Testing connection to {$olt['name']} ({$olt['host']}:{$olt['port']})...<br>";
    $start = microtime(true);
    $fp = @fsockopen($olt['host'], $olt['port'], $errno, $errstr, 5);
    $end = microtime(true);
    if ($fp) {
        echo "Socket to {$olt['host']}:{$olt['port']} <b style='color:green'>OPEN</b> (Time: " . round($end - $start, 3) . "s)<br>";
        fclose($fp);
    } else {
        echo "Socket to {$olt['host']}:{$olt['port']} <b style='color:red'>CLOSED / TIMEOUT</b> ($errstr)<br>";
    }
}
