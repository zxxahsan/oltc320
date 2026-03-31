<?php
/**
 * OLT API & Communication Module (V8.13)
 * Optimized with CRLF and Dynamic Discovery
 */

require_once __DIR__ . '/db.php';

class OltTelnetClient {
    private $socket;
    private $host;
    private $port;
    private $timeout;
    private $buffer = "";

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
        $this->write($username . "\r\n"); // Using CRLF

        $this->readUntil("/Password:/i", 2);
        $this->write($password . "\r\n"); // Using CRLF

        $result = $this->readUntil("/[>#]/", 5);
        if (!$result) throw new Exception("Login failed or timed out.");
        
        // Anti-Pagination
        $this->write("terminal length 0\r\n");
        $this->readUntil("/[>#]/", 1);
        
        return true;
    }

    public function execute($command, $wait_for = "/(\r\n|\n|^)[^>\r\n#]*[>#]\s*$/") {
        $this->readUntil("/.*/", 0.05); 
        $this->write($command . "\r\n"); // Using CRLF
        usleep(100000); 
        $output = $this->readUntil($wait_for, 5); 
        
        $lines = explode("\n", str_replace("\r", "", $output));
        if (count($lines) > 0 && stripos(trim($lines[0]), trim($command)) !== false) array_shift($lines);
        $clean_output = implode("\n", $lines);
        return preg_replace("/[^>\r\n#]*[>#]\s*$/", "", $clean_output);
    }

    public function enable($password = null) {
        $this->write("enable\r\n");
        $res = $this->readUntil("/Password:|#\s*$/i");
        if (stripos($res, "Password:") !== false) {
            $this->write($password . "\r\n");
            $res = $this->readUntil("/[>#]\s*$/i");
        }
        return true;
    }

    public function write($data) { return fwrite($this->socket, $data); }

    private function readUntil($regex, $timeout = null) {
        $result = "";
        $start = time();
        $wait = $timeout ?? $this->timeout;
        while (!feof($this->socket)) {
            $char = fgetc($this->socket);
            if ($char === false) {
                if (time() - $start > $wait) break;
                usleep(5000); 
                continue;
            }
            $result .= $char;
            if (preg_match('/--\s*More.*?--\s*$/i', $result)) {
                $this->write(" ");
                $result = preg_replace('/--\s*More.*?--\s*$/i', '', $result);
                continue;
            }
            if ($char == '>' || $char == '#' || $char == "\n") {
                if (preg_match($regex, $result)) return preg_replace('/\x1b\[[0-9;]*[mKHFAB]/', '', $result);
            }
            if (time() - $start > $wait) break;
        }
        return preg_replace('/\x1b\[[0-9;]*[mKHFAB]/', '', $result);
    }

    public function disconnect() { if ($this->socket) @fclose($this->socket); }
}

/**
 * Discovery with Brute-Force Command Set for V1.3.9R
 */
function vsolFindUnauthOnu($olt_id) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return [];

    $client = new OltTelnetClient($olt['host'], $olt['port']);
    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
        
        $onus = [];
        // Brute-force discovery commands for V1.3.9R
        $discovery_cmds = [
            "show gpon onu unauthentication",
            "show onu unauth",
            "show onu auth-info",
            "show onu uncfg-list"
        ];
        
        foreach ($discovery_cmds as $cmd) {
            $output = $client->execute($cmd);
            if (preg_match_all('/0\/(\d+)\s+.*?\s+([A-Z0-9]{8,16})/i', $output, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $exists = false;
                    foreach($onus as $known) { if(strcasecmp($known['sn'], $m[2]) === 0) { $exists = true; break; } }
                    if (!$exists) {
                        $onus[] = ['port' => (int)$m[1], 'sn' => $m[2], 'id' => null, 'status' => 'unconfigured'];
                    }
                }
            }
        }
        
        $client->disconnect();
        return $onus;
    } catch (Exception $e) { return []; }
}
