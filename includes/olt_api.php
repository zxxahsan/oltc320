<?php
/**
 * OLT API & Communication Module (V8.17)
 * Metadata Scraper & Parser for V-SOL V1.3.9R
 */

require_once __DIR__ . '/db.php';

class OltTelnetClient {
    private $socket;
    private $host;
    private $port;
    private $timeout;

    public function __construct($host, $port = 23, $timeout = 5) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function connect($username, $password) {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);
        if (!$this->socket) throw new Exception("Connection failed: $errstr ($errno)");
        stream_set_timeout($this->socket, 1);

        $this->readUntil("/Login:|Username:|User Name:/i", 2);
        $this->write($username . "\n"); // Using LF

        $this->readUntil("/Password:/i", 2);
        $this->write($password . "\n"); // Using LF

        $result = $this->readUntil("/[>#]/", 5);
        if (!$result) throw new Exception("Login failed or timed out.");
        
        $this->write("terminal length 0\n");
        $this->readUntil("/[>#]/", 1);
        
        return true;
    }

    public function execute($command, $wait_for = "/(\r\n|\n|^)[^>\r\n#]*[>#]\s*$/") {
        set_time_limit(60); // Give PHP more time
        $this->readUntil("/.*/", 0.05); 
        $this->write($command . "\n"); // Using LF
        $output = $this->readUntil($wait_for, 30); // Higher timeout for long outputs
        
        $lines = explode("\n", str_replace("\r", "", $output));
        if (count($lines) > 0 && stripos(trim($lines[0]), trim($command)) !== false) array_shift($lines);
        $clean_output = implode("\n", $lines);
        return preg_replace("/[^>\r\n#]*[>#]\s*$/", "", $clean_output);
    }

    public function enable($password = null) {
        $this->write("enable\n");
        $res = $this->readUntil("/Password:|#\s*$/i");
        if (stripos($res, "Password:") !== false) {
            $this->write($password . "\n");
            $res = $this->readUntil("/[>#]\s*$/i");
        }
        return true;
    }

    public function write($data) { return fwrite($this->socket, $data); }

    public function readUntil($regex, $timeout = null) {
        $result = "";
        $start = time();
        $wait = $timeout ?? $this->timeout;
        
        while (!feof($this->socket)) {
            // Read 1024 byte chunks instead of char-by-char for performance
            $data = fread($this->socket, 1024);
            if ($data === false || $data === "") {
                if (time() - $start > $wait) break;
                usleep(10000); 
                continue;
            }
            
            $result .= $data;
            
            // Periodically check for prompts or pagination at the end of buffer
            if (preg_match($regex, $result)) {
                return preg_replace('/\x1b\[[0-9;]*[mKHFAB]/', '', $result);
            }
            
            if (preg_match('/--\s*More.*?--\s*$/i', $result)) {
                $this->write(" ");
                $result = preg_replace('/--\s*More.*?--\s*$/i', '', $result);
            }

            if (time() - $start > $wait) break;
        }
        return preg_replace('/\x1b\[[0-9;]*[mKHFAB]/', '', $result);
    }

    public function disconnect() { if ($this->socket) @fclose($this->socket); }
}

/**
 * Parses running-config to extract ONU metadata
 */
function vsolParseRunningConfig($config_text) {
    $onus = [];
    $current_gpon = null;
    $lines = explode("\n", $config_text);

    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/interface gpon 0\/(\d+)/i', $line, $m)) {
            $current_gpon = (int)$m[1];
        }
        if ($line == 'exit' || $line == '!') {
            // Keep current_gpon until next interface or reset (optional)
        }
        
        // Match ONU registrations
        if ($current_gpon && preg_match('/onu add (\d+) profile .*? sn ([A-Z0-9]{8,16})/i', $line, $m)) {
            $onu_id = (int)$m[1];
            $sn = strtoupper($m[2]);
            $onus[$current_gpon . ":" . $onu_id] = [
                'port' => $current_gpon,
                'id' => $onu_id,
                'sn' => $sn,
                'desc' => "ONU $onu_id",
                'status' => 'registered'
            ];
        }

        // Match ONU descriptions
        if ($current_gpon && preg_match('/onu (\d+) desc (.*)/i', $line, $m)) {
            $onu_id = (int)$m[1];
            $desc = trim($m[2]);
            if (isset($onus[$current_gpon . ":" . $onu_id])) {
                $onus[$current_gpon . ":" . $onu_id]['desc'] = $desc;
            }
        }
    }
    return array_values($onus);
}

/**
 * Automates the discovery of unauthenticated ONUs for V-SOL V1.3.9R
 */
function vsolFindUnauthOnu($olt_id) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return [];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $unauth = [];
        // Scan each port for autolearn list
        for ($port=1; $port<=8; $port++) {
            $output = $client->execute("show onu autolearn-list gpon 0/$port");
            if (preg_match_all('/([A-Z0-9]{8,16})/i', $output, $matches)) {
                foreach ($matches[1] as $sn) {
                    $unauth[] = ['port' => $port, 'sn' => strtoupper($sn), 'status' => 'unconfigured'];
                }
            }
        }
        
        $client->disconnect();
        return $unauth;
    } catch (Exception $e) { return []; }
}

/**
 * Full Sync: Fetch running-config and parse all ONUs
 */
function vsolSyncAllMetadata($olt_id) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['error' => 'OLT not found'];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $config = $client->execute("show running-config");
        $results = vsolParseRunningConfig($config);
        
        $client->disconnect();
        return $results;
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Provision ONU: Konfigurasi WAN, ACS, Bridge, WiFi
 * Berdasarkan pola dari running-config OLT V-SOL V1.3.9R
 *
 * @param int    $olt_id      ID OLT di database
 * @param int    $port        GPON port (1-8)
 * @param int    $onu_id      ONU ID di port
 * @param string $pppoe_user  Username PPPoE
 * @param string $pppoe_pass  Password PPPoE
 * @param array  $services    ['acs', 'pppoe', 'hotspot', 'wifi']
 * @param string $acs_url     URL ACS server
 */
function vsolProvisionOnu($olt_id, $port, $onu_id, $pppoe_user, $pppoe_pass, $services = [], $acs_url = 'http://172.16.200.3:7547') {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['success' => false, 'log' => 'OLT tidak ditemukan'];

    $log = [];
    $client = new OltTelnetClient($olt['host'], $olt['port'], 5);

    try {
        // === LOGIN ===
        $client->connect($olt['username'], $olt['password']);
        $log[] = "✓ Login berhasil ke {$olt['host']}";

        if (!empty($olt['enable_password'])) {
            $client->enable($olt['enable_password']);
            $log[] = "✓ Masuk mode privileged (#)";
        }

        // === MASUK CONFIGURE TERMINAL ===
        $client->write("configure terminal\n");
        $client->readUntil("/[>#\(]/", 3);
        $log[] = "✓ configure terminal";

        // === MASUK INTERFACE GPON ===
        $client->write("interface gpon 0/{$port}\n");
        $client->readUntil("/[>#\(]/", 3);
        $log[] = "✓ interface gpon 0/{$port}";

        // === PRE-CLEANUP: Hapus index 1-4 agar fresh ===
        $log[] = "⏳ Cleaning up old indices (1-4)...";
        for ($i = 1; $i <= 4; $i++) {
            $client->write("onu {$onu_id} pri wan_adv delete index $i\n");
            $client->readUntil("/[>#]/", 1);
        }

        // === INDEX WAN COUNTER ===
        $idx = 1;

        // === ACS / TR-069 ===
        if (in_array('acs', $services)) {
            $cmds = [
                "onu {$onu_id} pri wan_adv add route",
                "onu {$onu_id} pri wan_adv index {$idx} route mode tr069 mtu 1500",
                "onu {$onu_id} pri wan_adv index {$idx} route ipv4 dhcp",
                "onu {$onu_id} pri wan_adv index {$idx} vlan tag wan_vlan 101 0",
            ];
            foreach ($cmds as $cmd) {
                $client->write($cmd . "\n");
                $resp = $client->readUntil("/[>#]/", 2);
                $log[] = "  → $cmd";
                if (trim($resp) && strpos($resp, $cmd) === false) $log[] = "    RESP: " . trim($resp);
            }
            $log[] = "✓ WAN #{$idx} ACS/TR-069 (VLAN 101) OK";
            $idx++;
        }

        // === PPPoE INTERNET ===
        if (in_array('pppoe', $services)) {
            $cmds = [
                "onu {$onu_id} pri wan_adv add route",
                "onu {$onu_id} pri wan_adv index {$idx} route mode internet mtu 1492",
                "onu {$onu_id} pri wan_adv index {$idx} route ipv4 pppoe proxy disable user {$pppoe_user} pwd {$pppoe_pass} mode auto nat enable",
                "onu {$onu_id} pri wan_adv index {$idx} vlan tag wan_vlan 100 0",
                "onu {$onu_id} pri wan_adv index {$idx} bind SSID1",
            ];
            foreach ($cmds as $cmd) {
                $client->write($cmd . "\n");
                $resp = $client->readUntil("/[>#]/", 2);
                $log[] = "  → $cmd";
                if (trim($resp) && strpos($resp, $cmd) === false) $log[] = "    RESP: " . trim($resp);
            }
            $log[] = "✓ WAN #{$idx} PPPoE Internet (VLAN 100) OK";
            $idx++;
        }

        // === HOTSPOT BRIDGE (VLAN 200) ===
        if (in_array('hotspot', $services)) {
            $cmds = [
                "onu {$onu_id} pri wan_adv add bridge",
                "onu {$onu_id} pri wan_adv index {$idx} service other",
                "onu {$onu_id} pri wan_adv index {$idx} bridge other",
                "onu {$onu_id} pri wan_adv index {$idx} vlan tag wan_vlan 200 0",
                "onu {$onu_id} pri wan_adv index {$idx} bind LAN1",
                "onu {$onu_id} pri wan_adv index {$idx} bind SSID2",
            ];
            foreach ($cmds as $cmd) {
                $client->write($cmd . "\n");
                $resp = $client->readUntil("/[>#]/", 2);
                $log[] = "  → $cmd";
                if (trim($resp) && strpos($resp, $cmd) === false) $log[] = "    RESP: " . trim($resp);
            }
            $log[] = "✓ WAN #{$idx} Hotspot Bridge (VLAN 200) DONE";
            $idx++;
        }

        // === WIFI SSID 2 (Jinom Hotspot) ===
        if (in_array('wifi', $services)) {
            $cmd = "onu {$onu_id} pri wifi_ssid 2 name Jinom_Hotspot hide disable auth_mode open encrypt_type none";
            $client->write($cmd . "\n");
            $resp = $client->readUntil("/[>#]/", 2);
            $log[] = "  → $cmd";
            if (trim($resp) && strpos($resp, $cmd) === false) $log[] = "    RESP: " . trim($resp);
            $log[] = "✓ WiFi SSID 2 (Jinom_Hotspot) OK";
        }

        // === ACS MANAGEMENT ===
        if (in_array('acs', $services)) {
            $cmd = "onu {$onu_id} pri tr069_mng enable acs_server url {$acs_url} username acs password acs certificate disable inform enable inform_interval 60 reverse_connection username acs password acs";
            $client->write($cmd . "\n");
            $resp = $client->readUntil("/[>#]/", 5);
            $log[] = "  → $cmd";
            if (trim($resp) && strpos($resp, $cmd) === false) $log[] = "    RESP: " . trim($resp);
            $log[] = "✓ TR069 Management active";
        }

        // === COMMIT PER ONU ===
        $client->write("onu {$onu_id} pri wan_adv commit\n");
        $client->readUntil("/[>#]/", 5);
        $log[] = "✓ ONU Commit successful";

        // === EXIT & SAVE OLT ===
        $client->write("exit\n");
        $client->readUntil("/[>#]/", 2);
        $client->write("exit\n");
        $client->readUntil("/[>#]/", 2);
        $client->write("write\n");
        $client->readUntil("/[>#]/", 5);
        $log[] = "✓ OLT Save (write) successful";

        $client->disconnect();
        $log[] = "\n🎉 Provisioning complete! ONU {$port}/{$onu_id} configured.";

        return ['success' => true, 'log' => implode("\n", $log)];

    } catch (Exception $e) {
        $log[] = "✗ ERROR: " . $e->getMessage();
        $client->disconnect();
        return ['success' => false, 'log' => implode("\n", $log)];
    }
}
