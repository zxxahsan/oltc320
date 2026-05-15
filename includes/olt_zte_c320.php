<?php
/**
 * ZTE C320 OLT SSH/Telnet Helper
 */

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
        // Ensure we have correct escape for password if needed
        $password = escapeshellarg($this->pass);
        $cmd = "sshpass -p $password ssh -q -T -o RequestTTY=no -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout={$this->timeout} -p {$this->port} {$this->user}@{$this->host}";
        
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin
            1 => array("pipe", "w"), // stdout
            2 => array("pipe", "w")  // stderr
        );

        $this->process = proc_open($cmd, $descriptorspec, $this->pipes);

        if (is_resource($this->process)) {
            stream_set_blocking($this->pipes[1], 0);
            stream_set_blocking($this->pipes[2], 0);
            
            // Wait for initial prompt
            $output = $this->waitPrompt(['>', '#'], 8);
            if ($output && (strpos($output, '>') !== false || strpos($output, '#') !== false)) {
                $this->last_output .= $output;
                
                // If in user mode, try enable
                if (strpos($output, '>') !== false) {
                    $this->exec("enable");
                }
                return true;
            } else {
                // Check stderr for clues
                $error = stream_get_contents($this->pipes[2]);
                $this->lastError = "SSH Failed: " . ($error ?: "Timeout or unknown error.");
            }
        } else {
            $this->lastError = "Could not start sshpass process. Is sshpass installed?";
        }
        return false;
    }

    private function connectTelnet() {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            $this->lastError = "Telnet Failed: $errstr ($errno)";
            return false;
        }

        stream_set_timeout($this->socket, 2);
        
        $this->waitPrompt("Username:");
        fwrite($this->socket, $this->user . "\r\n");
        $this->waitPrompt("Password:");
        fwrite($this->socket, $this->pass . "\r\n");
        
        $out = $this->waitPrompt(['>', '#']);
        if ($out) {
            if (strpos($out, '>') !== false) $this->exec("enable");
            return true;
        }
        
        $this->lastError = "Telnet Login Timeout";
        return false;
    }

    public function exec($cmd) {
        $this->last_output = "";
        if ($this->protocol === 'ssh') {
            fwrite($this->pipes[0], $cmd . "\r\n");
            $out = $this->waitPrompt(['#', '>', '(config)'], 5);
            $this->last_output = $out;
            return $out;
        } else {
            fwrite($this->socket, $cmd . "\r\n");
            $out = $this->waitPrompt(['#', '>', '(config)']);
            $this->last_output = $out;
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
                $read = fread($this->pipes[1], 4096);
            } else {
                $read = fread($this->socket, 4096);
            }

            if ($read) {
                $buffer .= $read;
                foreach ($prompts as $p) {
                    if (strpos($buffer, $p) !== false) return $buffer;
                }
            }
            usleep(100000); // 100ms
        }
        return $buffer;
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
        $name = escapeshellarg($data['name']);
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
            $logs[] = [
                'command' => $cmd,
                'response' => $this->exec($cmd)
            ];
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
