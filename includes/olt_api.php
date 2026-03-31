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
