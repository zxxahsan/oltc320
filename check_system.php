<?php
echo "<h1>System Capability Check</h1>";

echo "<h2>Network Test (PING)</h2>";
$host = "100.69.9.2";
$output = shell_exec("ping -c 4 $host 2>&1");
echo "<pre style='background:#000; color:#fff; padding:10px;'>$output</pre>";

echo "<h2>Network Test (PORT SCAN)</h2>";
$ports = [22, 23, 80, 443];
foreach ($ports as $port) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if ($fp) {
        echo "Port $port: <b style='color:green'>OPEN</b><br>";
        fclose($fp);
    } else {
        echo "Port $port: <b style='color:red'>CLOSED</b> ($errstr)<br>";
    }
}

echo "<h2>PHP Extensions</h2>";
$extensions = ['ssh2', 'sockets', 'openssl', 'curl'];
foreach ($extensions as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? "OK" : "MISSING") . "<br>";
}
