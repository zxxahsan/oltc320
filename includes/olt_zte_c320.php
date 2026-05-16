<?php
/**
 * ZTE C320 OLT SSH/Telnet Helper
 */

// Autoloader untuk phpseclib (tanpa composer)
spl_autoload_register(function ($class) {
    if (strpos($class, 'phpseclib\\') === 0) {
        $file = __DIR__ . '/phpseclib/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) require_once $file;
    }
});

use phpseclib\Net\SSH2;

class ZTE_OLT {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $protocol;
    private $socket;
    private $process;
    private $pipes = [];
    private $timeout = 10;
    private $lastError = '';
    private $last_output = '';

    public function __construct($host, $user, $pass, $port = 22, $protocol = 'ssh') {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
        $this->protocol = $protocol;
    }

    public function connect() {
        if ($this->protocol === 'ssh') {
            return $this->connectSSH();
        } else {
            return $this->connectTelnet();
        }
    }

    private function connectSSH() {
        if (!class_exists('\phpseclib\Net\SSH2')) {
            $this->lastError = "phpseclib tidak terinstall atau autoloader gagal.";
            return false;
        }

        $this->process = new SSH2($this->host, $this->port, $this->timeout);
        
        if (!$this->process->login($this->user, $this->pass)) {
            $this->lastError = "SSH Login Failed: Kredensial salah atau OLT menolak.";
            return false;
        }

        // Enable PTY for better terminal response from ZTE OLT
        $this->process->enablePTY();
        
        $output = $this->process->read('/[>#]/', SSH2::READ_REGEX);
        $this->last_output .= $output;
        @file_put_contents(__DIR__ . '/../logs/telnet_debug.log', "[" . date('Y-m-d H:i:s') . "] SSH_INIT: " . $output . "\n", FILE_APPEND);

        if ($output && strpos($output, '>') !== false) {
            $this->exec("enable");
        }
        return true;
    }

    private function connectTelnet() {
        $logFile = __DIR__ . '/../logs/telnet_debug.log';
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            $this->lastError = "Socket Create Failed";
            return false;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 3, 'usec' => 0));
        
        if (!@socket_connect($this->socket, $this->host, $this->port)) {
            $err = socket_strerror(socket_last_error($this->socket));
            $this->lastError = "Socket Connect Failed: $err";
            return false;
        }

        // Wait a bit for OLT to send initial bytes
        usleep(500000);
        
        // Kirim Negosiasi Telnet
        $neg = "\xFF\xFB\x01\xFF\xFB\x03\xFF\xFD\x01\xFF\xFD\x03";
        socket_write($this->socket, $neg, strlen($neg));
        usleep(500000);
        socket_write($this->socket, "\r\n", 2);
        
        // Wait for Username
        $out = $this->waitPrompt(['Username:', 'login:', 'User Name:', 'Login:'], 15);
        if (!$out) {
            $this->lastError = "Telnet Login Timeout: Did not see Username prompt.";
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] DEBUG: Received: " . bin2hex($this->last_output) . "\n", FILE_APPEND);
            return false;
        }
        
        socket_write($this->socket, $this->user . "\r\n", strlen($this->user) + 2);
        
        if (!$this->waitPrompt(['Password:', 'password:'], 5)) {
            $this->lastError = "Telnet Login Timeout: Did not see Password prompt.";
            return false;
        }
        socket_write($this->socket, $this->pass . "\r\n", strlen($this->pass) + 2);
        
        $out = $this->waitPrompt(['>', '#'], 5);
        if ($out) {
            if (strpos($out, '>') !== false) $this->exec("enable");
            return true;
        }
        
        $this->lastError = "Telnet Login Timeout: Login failed.";
        return false;
    }

    public function exec($cmd) {
        $this->last_output = "";
        if ($this->protocol === 'ssh') {
            $this->process->write($cmd . "\n");
            $out = $this->process->read('/[>#]|\(config\)/', SSH2::READ_REGEX);
            $this->last_output = $out;
            return $out;
        } else {
            @socket_write($this->socket, $cmd . "\r\n", strlen($cmd) + 2);
            $out = $this->waitPrompt(['#', '>', '(config)']);
            $this->last_output = $out;
            @file_put_contents(__DIR__ . '/../logs/telnet_debug.log', "[" . date('Y-m-d H:i:s') . "] TELNET_EXEC: $cmd\nRESP: $out\n", FILE_APPEND);
            return $out;
        }
    }

    private function waitPrompt($prompts, $timeout = 5) {
        if (!is_array($prompts)) $prompts = [$prompts];
        $buffer = "";
        $start = time();
        
        while (time() - $start < $timeout) {
            $read = "";
            if ($this->protocol === 'ssh') {
                $read = @fread($this->pipes[1], 4096);
            } else {
                $read = @socket_read($this->socket, 4096, PHP_BINARY_READ);
            }

            if ($read) {
                // Filter Telnet IAC codes (0xff ...) and non-printable chars
                $clean = "";
                for ($i = 0; $i < strlen($read); $i++) {
                    $ord = ord($read[$i]);
                    if ($ord >= 32 && $ord <= 126) {
                        $clean .= $read[$i];
                    } elseif ($ord == 10 || $ord == 13 || $ord == 58) { // LF, CR, Colon
                        $clean .= $read[$i];
                    }
                }
                
                $buffer .= $clean;
                $this->last_output .= $clean;
                
                foreach ($prompts as $p) {
                    if (stripos($buffer, $p) !== false) return $buffer;
                }
            }
            usleep(50000); // 50ms
        }
        return false;
    }

    public function getUnconfiguredOnu() {
        $this->exec("show gpon onu uncfg");
        $lines = explode("\n", $this->last_output);
        $onus = [];
        foreach ($lines as $line) {
            if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)\s+([A-Z0-9]+)/', $line, $matches)) {
                $onus[] = [
                    'port' => $matches[1],
                    'sn' => $matches[3],
                    'id' => $matches[2]
                ];
            }
        }
        return $onus;
    }

    public function provision($data) {
        $logs = [];
        $port = $data['port'];
        $sn = $data['sn'];
        $id = $data['onu_id'];
        // Remove escapeshellarg, just sanitize spaces for ZTE CLI compatibility
        $name = str_replace(' ', '_', $data['name']); 
        $vlan = $data['vlan'];
        
        $cmds = [
            "conf t",
            "interface gpon-olt_$port",
            "onu $id type ALL sn $sn",
            "exit",
            "interface gpon-onu_$port:$id",
            "name $name",
            "tcont 1 profile " . ($data['tcont_profile'] ?? 'default'),
            "gemport 1 tcont 1",
            "exit",
            "pon-onu-mng gpon-onu_$port:$id",
            "service 1 gemport 1 vlan $vlan"
        ];

        if (($data['mode'] ?? '') === 'omci') {
            $cmds[] = "wan-ip 1 mode pppoe username {$data['pppoe_user']} password {$data['pppoe_pass']} vlan-profile {$data['vlan_profile']} host 1";
            $cmds[] = "security-mgmt 212 state enable mode forward protocol web";
        }

        $cmds[] = "end";
        $cmds[] = "write";

        foreach ($cmds as $cmd) {
            $res = $this->exec($cmd);
            $logs[] = [
                'command' => $cmd,
                'response' => $res
            ];
            // Tiny delay for OLT processing
            usleep(200000); 
        }

        return $logs;
    }

    public function getProfiles($type = 'vlan') {
        $cmd = ($type === 'vlan') ? 'show gpon profile vlan' : 'show gpon profile tcont';
        $output = $this->exec($cmd);
        $lines = explode("\n", $output);
        $profiles = [];
        foreach ($lines as $line) {
            if (preg_match('/Name:\s*(\S+)/i', $line, $matches)) {
                $profiles[] = $matches[1];
            }
        }
        return array_unique($profiles);
    }

    public function deleteOnu($port, $id) {
        $cmds = ["conf t", "interface gpon-olt_$port", "no onu $id", "exit", "end", "write"];
        $logs = [];
        foreach ($cmds as $cmd) {
            $logs[] = ['command' => $cmd, 'response' => $this->exec($cmd)];
        }
        return $logs;
    }

    public function disconnect() {
        if ($this->protocol === 'ssh') {
            if (is_resource($this->process)) {
                fwrite($this->pipes[0], "exit\r\n");
                @fclose($this->pipes[0]);
                @fclose($this->pipes[1]);
                @fclose($this->pipes[2]);
                proc_close($this->process);
            }
        } else {
            if ($this->socket) {
                fwrite($this->socket, "exit\r\n");
                fclose($this->socket);
            }
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}
