<?php
/**
 * OLT API & Communication Module
 * Specifically for V-SOL OLT automation
 */

require_once __DIR__ . '/db.php';

/**
 * Lightweight Telnet Client for OLT CLI
 */
class OltTelnetClient {
    private $socket;
    private $host;
    private $port;
    private $timeout;
    private $buffer = "";

    public function __construct($host, $port = 23, $timeout = 10) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect($username, $password) {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            throw new Exception("Connection failed: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 2);

        // Wait for Login prompt
        $this->readUntil("/Login:|Username:|User Name:/i");
        $this->write($username . "\n");

        // Wait for Password prompt
        $this->readUntil("/Password:/i");
        $this->write($password . "\n");

        // Check if login successful (wait for prompt like # or >)
        $result = $this->readUntil("/[>#]/");
        if (!$result) {
            throw new Exception("Login failed or timed out.");
        }

        return true;
    }

    public function execute($command, $wait_for = "/[>#]/") {
        $this->write($command . "\n");
        return $this->readUntil($wait_for);
    }

    private function write($data) {
        return fwrite($this->socket, $data);
    }

    private function readUntil($regex) {
        $result = "";
        $start = time();
        while (!feof($this->socket)) {
            $char = fgetc($this->socket);
            if ($char === false) break;
            $result .= $char;
            if (preg_match($regex, $result)) {
                return $result;
            }
            if (time() - $start > $this->timeout) {
                break;
            }
        }
        return $result;
    }

    public function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
        }
    }
}

/**
 * Provision WAN (PPPoE) on a V-SOL ONU
 * 
 * @param int $olt_id ID from olt_configs
 * @param string $onu_id Format "port/onu" (e.g. 1/1)
 * @param int $vlan VLAN ID
 * @param string $pppoe_user PPPoE Username
 * @param string $pppoe_pass PPPoE Password
 * @return array Result [success => bool, message => string, log => string]
 */
function vsolProvisionWan($olt_id, $onu_id, $vlan, $pppoe_user, $pppoe_pass) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) {
        return ['success' => false, 'message' => "OLT not found in database."];
    }

    // Parse ONU ID (assuming format like "G0/1:1" or "1/1")
    // For V-SOL: interface gpon-olt 0/1 -> onu 1
    if (preg_match('/(?:G|GPON)?0?\/(\d+):(\d+)/i', $onu_id, $matches)) {
        $port = $matches[1];
        $id = $matches[2];
    } elseif (strpos($onu_id, '/') !== false) {
        $parts = explode('/', $onu_id);
        $port = $parts[0];
        $id = $parts[1];
    } else {
        return ['success' => false, 'message' => "Invalid ONU ID format. Use 'port/id' (e.g. 1/1)"];
    }

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    $log = "";

    try {
        $client->connect($olt['username'], $olt['password']);
        
        $commands = [
            "enable",
            "configure terminal",
            "interface gpon-olt 0/$port",
            "onu $id wan_conn add route internet nat enable",
            "onu $id wan_conn index 1 pppoe proxy enable user $pppoe_user pwd $pppoe_pass mode auto",
            "onu $id wan_conn index 1 vlan tag $vlan",
            "onu $id wan_conn commit",
            "exit",
            "exit"
        ];

        foreach ($commands as $cmd) {
            $res = $client->execute($cmd);
            $log .= "> $cmd\n$res\n";
            
            // Check for obvious errors
            if (stripos($res, "error") !== false || stripos($res, "invalid") !== false || stripos($res, "fail") !== false) {
                // If it's already exists error, it might be fine, but we'll record it
                if (stripos($res, "already exist") === false) {
                    throw new Exception("OLT returned error on command '$cmd': " . trim($res));
                }
            }
        }

        $client->disconnect();
        return ['success' => true, 'message' => "WAN provisioned successfully on ONU $onu_id", 'log' => $log];

    } catch (Exception $e) {
        $client->disconnect();
        return ['success' => false, 'message' => "Provisioning failed: " . $e->getMessage(), 'log' => $log];
    }
}
