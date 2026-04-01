<?php
/**
 * OLT SNMP Monitoring Module
 * Fetches real-time status using SNMP
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/olt_api.php';

/**
 * Fetch All ONU Statuses from OLT via Telnet (v2.1)
 * Scans all PON ports to find online/offline/los states.
 */
function oltFetchAllOnuStates($olt_id) {
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) return ['success' => false, 'message' => 'OLT not found'];

    $client = new OltTelnetClient($olt['host'], $olt['port'], 5);
    $allStatuses = [];
    $log = [];

    try {
        $client->connect($olt['username'], $olt['password']);
        if (!empty($olt['enable_password'])) {
            $client->enable($olt['enable_password']);
        }

        // We scan 16 ports to be safe, standard VSOL usually has 8 or 16
        for ($p = 1; $p <= 16; $p++) {
            // Try standard command first
            $cmd = "show gpon onu state 0/{$p}";
            $client->write($cmd . "\n");
            $resp = $client->readUntil("/[>#]/", 2);
            
            // If unknown, try alternate
            if (stripos($resp, "Unknown") !== false) {
                $cmd = "show onu state 0/{$p}";
                $client->write($cmd . "\n");
                $resp = $client->readUntil("/[>#]/", 2);
            }

            // Parse the output table
            // Format expected: ONU-ID  SN  State  ... 
            $lines = explode("\n", $resp);
            foreach ($lines as $line) {
                $line = trim($line);
                // Look for SN pattern (usually 8-16 chars HEX/Alpha) + status
                // Example: 1  GPON01EF919  online
                if (preg_match('/(\d+)\s+([A-Z0-9]{8,16})\s+(online|offline|los|dying-gasp|power-off|auth-failed|mismatch)/i', $line, $matches)) {
                    $sn = strtoupper($matches[2]);
                    $status = strtolower($matches[3]);
                    $allStatuses[$sn] = $status;
                }
            }
        }

        $client->disconnect();
        return [
            'success' => true,
            'statuses' => $allStatuses,
            'count' => count($allStatuses)
        ];

    } catch (Exception $e) {
        if ($client) $client->disconnect();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Fetch OLT Status via SNMP (Legacy)
 * 
 * @param int $olt_id ID from olt_configs
 * @return array Status results [success => bool, metrics => array, message => string]
 */
function oltGetSnmpStatus($olt_id) {
    if (!function_exists('snmpget')) {
        return [
            'success' => false, 
            'message' => 'PHP SNMP extension is not enabled on this server.',
            'metrics' => []
        ];
    }

    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$olt) {
        return ['success' => false, 'message' => "OLT not found.", 'metrics' => []];
    }

    $host = $olt['host'];
    $community = empty($olt['snmp_community']) ? 'public' : $olt['snmp_community'];
    $version = empty($olt['snmp_version']) ? '2c' : $olt['snmp_version'];

    // VSOL OIDs (Commonly used in V1600 Series)
    $oids = [
        'uptime' => '1.3.6.1.2.1.1.3.0',
        'cpu_load' => '1.3.6.1.4.1.3320.9.1.1', // Verify for specific VSOL models
        'mem_usage' => '1.3.6.1.4.1.3320.9.48.1', // Verify for specific VSOL models
        'sysname' => '1.3.6.1.2.1.1.5.0'
    ];

    $metrics = [];
    if (function_exists('snmp_set_quick_print')) {
        snmp_set_quick_print(1);
    }
    
    // Set SNMP version
    if ($version == '1') {
        $snmp_func = 'snmpget';
    } else {
        $snmp_func = 'snmp2_get';
    }

    try {
        foreach ($oids as $key => $oid) {
            $val = @$snmp_func($host, $community, $oid, 1000000, 1); // 1s timeout, 1 retry
            if ($val !== false) {
                $metrics[$key] = trim(str_replace(['STRING: ', 'INTEGER: ', 'Gauge32: ', 'Counter32: ', 'Timeticks: '], '', $val));
            } else {
                $metrics[$key] = 'N/A';
            }
        }

        // Processing Uptime
        if (isset($metrics['uptime']) && is_numeric($metrics['uptime'])) {
            $ticks = $metrics['uptime'];
            $seconds = floor($ticks / 100);
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $metrics['uptime_readable'] = "{$days}d {$hours}h {$minutes}m";
        } else {
            $metrics['uptime_readable'] = $metrics['uptime'];
        }

        return [
            'success' => true,
            'metrics' => $metrics,
            'message' => 'Status fetched successfully.'
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => "SNMP Error: " . $e->getMessage(), 'metrics' => []];
    }
}
