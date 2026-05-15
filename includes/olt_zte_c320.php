<?php
/**
 * ZTE C320 OLT Telnet Helper
 */

class ZTE_OLT {
    private $host;
    private $port;
    private $username;
    private $password;
    private $socket;
    private $timeout = 10;
    private $lastError = '';

    public function __construct($host, $username, $password, $port = 23) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
    }

    public function connect() {
        $this->socket = fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            $this->lastError = "Connection failed: $errstr ($errno)";
            return false;
        }

        socket_set_timeout($this->socket, $this->timeout);

        // Login process
        if (!$this->waitFor('Username:')) return false;
        $this->send($this->username);
        
        if (!$this->waitFor('Password:')) return false;
        $this->send($this->password);

        // Wait for prompt
        $res = $this->readUntil(['>', '#']);
        if (!$res) {
            $this->lastError = "Login failed: Incorrect credentials or timeout.";
            return false;
        }

        // Enter enable mode if needed (usually already in # or needs 'enable')
        if (strpos($res, '>') !== false) {
            $this->send('enable');
            // ZTE might ask for enable password
            $res = $this->readUntil(['Password:', '#']);
            if (strpos($res, 'Password:') !== false) {
                $this->send($this->password); // Usually same as login
                $this->readUntil('#');
            }
        if ($this->protocol === 'ssh') {
            return $this->connectSSH();
        } else {
            return $this->connectTelnet();
        }
    }

    private function connectSSH() {
        // Menggunakan sshpass jika tersedia, atau ssh biasa
        // Pastikan sshpass terinstall: sudo apt-get install sshpass
        $cmd = "sshpass -p '{$this->pass}' ssh -o StrictHostKeyChecking=no -o ConnectTimeout={$this->timeout} -p {$this->port} {$this->user}@{$this->host}";
        
        $descriptorspec = array(
            0 => array("pipe", "r"), // stdin
            1 => array("pipe", "w"), // stdout
            2 => array("pipe", "w")  // stderr
        );

        $this->process = proc_open($cmd, $descriptorspec, $this->pipes);

        if (is_resource($this->process)) {
            stream_set_blocking($this->pipes[1], 0);
            stream_set_blocking($this->pipes[2], 0);
            
            // Tunggu prompt awal
            $output = $this->waitPrompt(['>', '#'], 5);
            if ($output) {
                $this->last_output .= $output;
                return true;
            }
        }
        return false;
    }

    private function connectTelnet() {
        $this->conn = fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->conn) return false;

        stream_set_timeout($this->conn, 2);
        
        $this->waitPrompt("Username:");
        fwrite($this->conn, $this->user . "\r\n");
        $this->waitPrompt("Password:");
        fwrite($this->conn, $this->pass . "\r\n");
        
        $out = $this->waitPrompt(['>', '#']);
        if ($out) {
            $this->last_output .= $out;
            return true;
        }
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
            fwrite($this->conn, $cmd . "\r\n");
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
            if ($this->protocol === 'ssh') {
                $read = fread($this->pipes[1], 4096);
                $err = fread($this->pipes[2], 4096);
                if ($read) $buffer .= $read;
            } else {
                $read = fread($this->conn, 4096);
                if ($read) $buffer .= $read;
            }

            foreach ($prompts as $p) {
                if (strpos($buffer, $p) !== false) return $buffer;
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
            // Logic parsing SN, Port, dll tetap sama
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

    public function provisionOnu($data) {
        $this->exec("conf t");
        $this->exec("interface gpon-olt_{$data['port']}");
        $this->exec("onu {$data['onu_index']} type {$data['type']} sn {$data['sn']}");
        $this->exec("exit");

        if ($data['mode'] == 'omci') {
            $this->provisionOmci($data);
        } else {
            $this->provisionStandard($data);
        }
        
        return ["status" => "success", "log" => $this->last_output];
    }

    private function provisionStandard($data) {
        $this->exec("conf t");
        $this->exec("interface gpon-onu_{$data['port']}:{$data['onu_index']}");
        $this->exec("tcont 1 profile {$data['tcont']}");
        $this->exec("gemport 1 tcont 1");
        $this->exec("exit");
        
        $this->exec("pon-onu-mng gpon-onu_{$data['port']}:{$data['onu_index']}");
        $this->exec("service pppoe gemport 1 vlan {$data['vlan']}");
        $this->exec("exit");
    }

    private function provisionOmci($data) {
        $this->exec("conf t");
        $this->exec("pon-onu-mng gpon-onu_{$data['port']}:{$data['onu_index']}");
        $this->exec("service pppoe gemport 1 vlan {$data['vlan']}");
        $this->exec("wan-config mode pppoe username {$data['username']} password {$data['password']} vlan {$data['vlan']}");
        $this->exec("security-mgmt 1 state enable mode forward protocol web");
        $this->exec("exit");
    }

    public function deleteOnu($port, $onu_index) {
        $this->exec("conf t");
        $this->exec("interface gpon-olt_{$port}");
        $this->exec("no onu {$onu_index}");
        $this->exec("exit");
        return true;
    }

    public function disconnect() {
        if ($this->protocol === 'ssh') {
            if (is_resource($this->process)) {
                fwrite($this->pipes[0], "exit\r\n");
                fclose($this->pipes[0]);
                fclose($this->pipes[1]);
                fclose($this->pipes[2]);
                proc_close($this->process);
            }
        } else {
            if ($this->conn) {
                fwrite($this->conn, "exit\r\n");
                fclose($this->conn);
            }
        }
    }

    public function getProfiles($type = 'vlan') {
        $cmd = ($type === 'vlan') ? 'show gpon profile vlan' : 'show gpon profile tcont';
        $output = $this->exec($cmd);
        $lines = explode("\n", $output);
        $profiles = [];

        foreach ($lines as $line) {
            // Adjust regex based on OLT output
            if (preg_match('/Name:\s*(\S+)/i', $line, $matches)) {
                $profiles[] = $matches[1];
            } elseif (preg_match('/^\s*(\d+)\s+(\S+)/', $line, $matches)) {
                // Some versions show ID and Name
                if (!is_numeric($matches[2])) $profiles[] = $matches[2];
            }
        }
        return array_unique($profiles);
    }

    public function provision($data) {
        $logs = [];
        $port = $data['port'];
        $sn = $data['sn'];
        $id = $data['onu_id'];
        $name = $data['name'];
        $desc = $data['description'];
        $vlan = $data['vlan'];
        
        $cmds = [
            "conf t",
            "interface gpon-olt_$port",
            "onu $id type ALL sn $sn",
            "exit",
            "interface gpon-onu_$port:$id",
            "name $name",
            "description $desc",
            "tcont 1 profile " . ($data['tcont_profile'] ?? 'default'),
            "gemport 1 tcont 1",
            "gemport 2 tcont 1",
        ];

        // Service ports
        if (isset($data['service_ports']) && is_array($data['service_ports'])) {
            foreach ($data['service_ports'] as $sp) {
                $cmds[] = "service-port {$sp['id']} vport {$sp['vport']} user-vlan {$sp['user_vlan']} vlan {$sp['vlan']}";
            }
        } else {
            // Default based on user's first script
            $cmds[] = "service-port 1 vport 1 user-vlan $vlan vlan $vlan";
            $cmds[] = "service-port 2 vport 2 user-vlan 100 vlan 100";
        }
        
        $cmds[] = "exit";
        $cmds[] = "pon-onu-mng gpon-onu_$port:$id";
        
        // Service mapping in MNG
        if (isset($data['service_ports']) && is_array($data['service_ports'])) {
            foreach ($data['service_ports'] as $sp) {
                $cmds[] = "service {$sp['id']} gemport {$sp['vport']} vlan {$sp['vlan']}";
            }
        } else {
            $cmds[] = "service 1 gemport 1 vlan $vlan";
            $cmds[] = "service 2 gemport 2 vlan 100";
        }

        // OMCI Features
        if ($data['mode'] === 'omci') {
            $pppoe_user = $data['pppoe_user'];
            $pppoe_pass = $data['pppoe_pass'];
            $vlan_prof = $data['vlan_profile'];
            $acs_url = $data['acs_url'];
            
            $cmds[] = "wan-ip 1 mode pppoe username $pppoe_user password $pppoe_pass vlan-profile $vlan_prof host 1";
            $cmds[] = "wan-ip 2 ping-response enable traceroute-response enable";
            $cmds[] = "security-mgmt 212 state enable mode forward protocol web";
            $cmds[] = "tr069-mgmt 1 state unlock";
            $cmds[] = "tr069-mgmt 1 acs $acs_url validate basic username admin password admin";
            $cmds[] = "tr069-mgmt 1 tag pri 7 vlan 100";
        }

        $cmds[] = "end";
        $cmds[] = "write"; // Save config

        foreach ($cmds as $cmd) {
            $logs[] = [
                'command' => $cmd,
                'response' => $this->exec($cmd)
            ];
        }

        return $logs;
    }

    public function deleteOnu($port, $id) {
        $cmds = [
            "conf t",
            "interface gpon-olt_$port",
            "no onu $id",
            "exit",
            "end",
            "write"
        ];
        
        $logs = [];
        foreach ($cmds as $cmd) {
            $logs[] = [
                'command' => $cmd,
                'response' => $this->exec($cmd)
            ];
        }
        return $logs;
    }

    private function send($data) {
        fputs($this->socket, $data . "\r\n");
    }

    private function waitFor($string) {
        $buffer = '';
        while (!feof($this->socket)) {
            $char = fgetc($this->socket);
            if ($char === false) break;
            $buffer .= $char;
            if (strpos($buffer, $string) !== false) return true;
        }
        return false;
    }

    private function readUntil($prompts) {
        if (!is_array($prompts)) $prompts = [$prompts];
        $buffer = '';
        while (!feof($this->socket)) {
            $char = fgetc($this->socket);
            if ($char === false) break;
            $buffer .= $char;
            foreach ($prompts as $prompt) {
                if (strpos($buffer, $prompt) !== false) return $buffer;
            }
        }
        return $buffer;
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function close() {
        if ($this->socket) fclose($this->socket);
    }
}
