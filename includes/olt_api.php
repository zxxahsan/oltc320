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

    public function execute($command, $wait_for = "/(\r\n|\n|^)[^>\r\n#]*[>#]\s*$/") {
        // Clear buffer
        $this->readUntil("/.*/", 0.1); 
        
        $this->write($command . "\n");
        // Give OLT a tiny moment to echo and process
        usleep(100000); 
        
        $output = $this->readUntil($wait_for);
        
        // Robust echo removal:
        // Many OLTs echo the command + \n before the actual response.
        $lines = explode("\n", str_replace("\r", "", $output));
        if (count($lines) > 0 && stripos(trim($lines[0]), trim($command)) !== false) {
            array_shift($lines);
        }
        
        $clean_output = implode("\n", $lines);
        // Remove the prompt itself from the end of the clean output
        return preg_replace("/[^>\r\n#]*[>#]\s*$/", "", $clean_output);
    }

    /**
     * Enter Privilege Mode (Enable)
     */
    public function enable($password = null) {
        $this->write("enable\n");
        $res = $this->readUntil("/Password:|#\s*$/i");
        
        if (stripos($res, "Password:") !== false) {
            $this->write($password . "\n");
            $res = $this->readUntil("/[>#]\s*$/i");
        }
        
        if (!preg_match("/[>#]\s*$/", $res)) {
            throw new Exception("Enable mode failed: " . trim($res));
        }
        
        return true;
    }

    public function write($data) {
        return fwrite($this->socket, $data);
    }

    private function readUntil($regex, $timeout = null) {
        $result = "";
        $start = time();
        $wait = $timeout ?? $this->timeout;
        
        while (!feof($this->socket)) {
            $char = fgetc($this->socket);
            if ($char === false) {
                usleep(5000); 
                if (time() - $start > $wait) break;
                continue;
            }
            
            $result .= $char;
            
            // Check for prompt at the end of the buffer
            if ($char == '>' || $char == '#' || $char == "\n") {
                if (preg_match($regex, $result)) {
                    return $result;
                }
            }
            
            if (time() - $start > $wait) break;
        }
        // Final cleanup for potential ANSI escape codes (common in telnet)
        return preg_replace('/\x1b\[[0-9;]*[mKHFAB]/', '', $result);
    }

    public function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
        }
    }
}

/**
 * Find registered ONU ID by SN (for auto-learn support)
 */
function vsolGetOnuIdBySn($olt_id, $sn) {
    if (empty($sn)) return null;
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return null;

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $output = $client->execute("show gpon onu information");
        $client->disconnect();

        // Pattern: GPON0/1  1   6   FHTTXXXXXXXX (Support 8-16 chars SN)
        if (preg_match('/0\/(\d+)\s+(\d+)\s+.*?\s+([A-Z0-9]{8,16})/i', $output, $m)) {
            return [
                'port' => (int)$m[1],
                'id' => (int)$m[2]
            ];
        }
    } catch (Exception $e) {
        logError("V-SOL ID Lookup failed: " . $e->getMessage());
    }
    return null;
}

/**
 * Find ONUs waiting for authentication OR already auto-learned
 * @param int $olt_id
 * @return array List of found ONUs [['port' => X, 'sn' => Y, 'id' => Z], ...]
 */
function vsolFindUnauthOnu($olt_id) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return [];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        // 1. Check Unauthentication list (Broad Regex)
        $outputU = $client->execute("show gpon onu unauthentication");
        $onus = [];
        if (preg_match_all('/0\/(\d+)\s+\d+\s+([A-Z0-9]{8,16})/i', $outputU, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $onus[] = ['port' => (int)$m[1], 'sn' => $m[2], 'id' => null, 'status' => 'unconfigured'];
            }
        }

        // 2. Check already auto-learned list (Broad Regex)
        $outputI = $client->execute("show gpon onu information");
        if (preg_match_all('/0\/(\d+)\s+(\d+)\s+.*?\s+([A-Z0-9]{8,16})/i', $outputI, $mI, PREG_SET_ORDER)) {
            foreach ($mI as $m) {
                $onus[] = ['port' => (int)$m[1], 'sn' => $m[3], 'id' => (int)$m[2], 'status' => 'registered'];
            }
        }
        
        $client->disconnect();
        return $onus;
    } catch (Exception $e) {
        logError("V-SOL Scan failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Register a new ONU on V-SOL OLT
 */
function vsolRegisterOnu($olt_id, $port, $sn, $onu_id, $profile = 'default', $desc = '') {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['success' => false, 'message' => "OLT not found"];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $commands = [
            "configure terminal",
            "interface gpon 0/$port",
            "onu add $onu_id profile $profile sn $sn",
        ];
        
        if (!empty($desc)) {
            $commands[] = "onu $onu_id desc $desc";
        }
        
        $commands[] = "exit";
        $commands[] = "exit";

        $log = "";
        foreach ($commands as $cmd) {
            $res = $client->execute($cmd);
            $log .= "> $cmd\n$res\n";
            if (stripos($res, "error") !== false || stripos($res, "fail") !== false) {
                 if (stripos($res, "already exist") === false) {
                     throw new Exception("Error on '$cmd': " . trim($res));
                 }
            }
        }
        $client->disconnect();
        return ['success' => true, 'log' => $log];
    } catch (Exception $e) {
        $client->disconnect();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Provision WAN (PPPoE) on a V-SOL ONU using pri wan_adv (ONU 8:45 template)
 */
function vsolProvisionWan($olt_id, $port, $onu_id, $vlan, $pppoe_user, $pppoe_pass) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['success' => false, 'message' => "OLT not found"];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        // Template from ONU 8:45
        $commands = [
            "configure terminal",
            "interface gpon 0/$port",
            
            // TR069/Management WAN (Index 1) - VLAN 101, DHCP
            "onu $onu_id pri wan_adv add route",
            "onu $onu_id pri wan_adv index 1 route mode tr069 mtu 1500",
            "onu $onu_id pri wan_adv index 1 route ipv4 dhcp",
            "onu $onu_id pri wan_adv index 1 vlan tag wan_vlan 101 0",
            
            // Internet WAN (Index 2) - VLAN 100, PPPoE
            "onu $onu_id pri wan_adv add route",
            "onu $onu_id pri wan_adv index 2 route mode internet mtu 1492",
            "onu $onu_id pri wan_adv index 2 route ipv4 pppoe proxy disable user $pppoe_user pwd $pppoe_pass mode auto nat enable",
            "onu $onu_id pri wan_adv index 2 vlan tag wan_vlan $vlan 0",
            "onu $onu_id pri wan_adv index 2 bind ssid1",
            
            // Hotspot Bridge (Index 3) - VLAN 200
            "onu $onu_id pri wan_adv add bridge",
            "onu $onu_id pri wan_adv index 3 bridge mode other mtu 1500",
            "onu $onu_id pri wan_adv index 3 vlan tag wan_vlan 200 0",
            "onu $onu_id pri wan_adv index 3 bind lan1 ssid2",
            
            // WiFi SSID 2 for Hotspot
            "onu $onu_id pri wifi_ssid 2 name Jinom_Hotspot hide disable auth_mode open encrypt_type none",
            
            // TR069 Management release (ACS)
            "onu $onu_id pri tr069_mng enable acs_server url http://172.16.200.3:7547 username acs password acs certificate disable inform enable inform_interval 200 reverse_connection username acs password acs",
            
            "exit",
            "exit"
        ];

        $log = "";
        foreach ($commands as $cmd) {
            $res = $client->execute($cmd);
            $log .= "> $cmd\n$res\n";
        }

        $client->disconnect();
        return ['success' => true, 'message' => "Provisioning Success (WAN + WiFi + TR069)", 'log' => $log];

    } catch (Exception $e) {
        $client->disconnect();
        return ['success' => false, 'message' => "Provisioning failed: " . $e->getMessage()];
    }
}

/**
 * Ultimate Provisioning Wrapper - Executes only selected services
 */
function vsolProvisionUltimate($olt_id, $port, $onu_id, $vlan, $pppoe_user, $pppoe_pass, $services = []) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['success' => false, 'message' => "OLT not found"];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $commands = [
            "configure terminal",
            "interface gpon 0/$port",
        ];

        // 1. ACS / TR069 Management
        if (in_array('acs', $services)) {
            $commands[] = "onu $onu_id pri wan_adv add route";
            $commands[] = "onu $onu_id pri wan_adv index 1 route mode tr069 mtu 1500";
            $commands[] = "onu $onu_id pri wan_adv index 1 route ipv4 dhcp";
            $commands[] = "onu $onu_id pri wan_adv index 1 vlan tag wan_vlan 101 0";
            $commands[] = "onu $onu_id pri tr069_mng enable acs_server url http://172.16.200.3:7547 username acs password acs certificate disable inform enable inform_interval 200 reverse_connection username acs password acs";
        }

        // 2. Internet PPPoE
        if (in_array('pppoe', $services)) {
            $commands[] = "onu $onu_id pri wan_adv add route";
            $commands[] = "onu $onu_id pri wan_adv index 2 route mode internet mtu 1492";
            $commands[] = "onu $onu_id pri wan_adv index 2 route ipv4 pppoe proxy disable user $pppoe_user pwd $pppoe_pass mode auto nat enable";
            $commands[] = "onu $onu_id pri wan_adv index 2 vlan tag wan_vlan $vlan 0";
            
            // Default bind ssid1 for internet
            $commands[] = "onu $onu_id pri wan_adv index 2 bind ssid1";
        }

        // 3. Hotspot Bridge
        if (in_array('hotspot', $services)) {
            $commands[] = "onu $onu_id pri wan_adv add bridge";
            $commands[] = "onu $onu_id pri wan_adv index 3 bridge mode other mtu 1500";
            $commands[] = "onu $onu_id pri wan_adv index 3 vlan tag wan_vlan 200 0";
            $commands[] = "onu $onu_id pri wan_adv index 3 bind lan1 ssid2";
        }

        // 4. WiFi SSID 2
        if (in_array('wifi', $services)) {
            $commands[] = "onu $onu_id pri wifi_ssid 2 name Jinom_Hotspot hide disable auth_mode open encrypt_type none";
        }

        $commands[] = "exit";
        $commands[] = "exit";

        $log = "";
        foreach ($commands as $cmd) {
            $res = $client->execute($cmd);
            $log .= "> $cmd\n$res\n";
        }

        $client->disconnect();
        return ['success' => true, 'message' => "Ultimate Provisioning completed", 'log' => $log];

    } catch (Exception $e) {
        $client->disconnect();
        return ['success' => false, 'message' => "Ultimate Provisioning failed: " . $e->getMessage()];
    }
}
