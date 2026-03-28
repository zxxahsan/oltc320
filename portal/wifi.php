<?php
/**
 * WiFi & Router Settings Page - Customer Portal
 */

require_once '../includes/auth.php';
requireCustomerLogin();

$customerSession = getCurrentCustomer();

// Fetch fresh customer data
if ($customerSession && isset($customerSession['id'])) {
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerSession['id']]);
} else {
    $customer = $customerSession;
}

$pageTitle = 'WiFi & Router';

// Get ONU data from GenieACS
$onuData = null;
$onuOnline = false;
$onuSignal = 'N/A';
$onuDevices = '-';

// Fetch PPPoE Session Uptime from MikroTik (since RouterOS ppp/active still accurately tracks duration)
require_once '../includes/mikrotik_api.php';
$pppoeUptime = 'Offline';
$pppoeUser = $customer['pppoe_username'] ?? '';

if (!empty($pppoeUser)) {
    // We try to pull strictly from the first active router
    $activeSession = mikrotikGetActiveSessionByUsername($pppoeUser);
    if ($activeSession) {
        $pppoeUptime = $activeSession['uptime'] ?? 'N/A';
    }
}

$customerDevice = null;
if (!empty($customer['phone'])) {
    $customerDevice = genieacsGetDevice($customer['phone']);
}

// Compute Gigabytes bandwidth usage purely from MikroTik /interface API (Per User Request)
$pppoeUsage = '0.00 GB';

if (!empty($pppoeUser)) {
    $dynamicInterface = mikrotikGetInterfaceBytesByUsername($pppoeUser);
    if ($dynamicInterface) {
        // RouterOS names rx-byte (download into router) and tx-byte (upload from router)
        $bIn = (float)($dynamicInterface['rx-byte'] ?? 0);
        $bOut = (float)($dynamicInterface['tx-byte'] ?? 0);
        
        $totalGB = ($bIn + $bOut) / (1024 * 1024 * 1024);
        if ($totalGB > 0) {
            $pppoeUsage = number_format($totalGB, 2) . ' GB';
        }
    }
}

if ($customerDevice) {
    $deviceId = $customerDevice['_id'] ?? $customerDevice['_deviceId']['_SerialNumber'] ?? $customer['pppoe_username'];
    $onuData = genieacsGetDeviceInfo($deviceId);
    
    if ($onuData && isset($onuData['status'])) {
        $onuOnline = ($onuData['status'] === 'online');
    }
    
    $rxPowerFromDevice = genieacsGetValue($customerDevice, 'VirtualParameters.RXPower');
    if ($rxPowerFromDevice !== null) {
        $onuSignal = $rxPowerFromDevice;
    } elseif ($onuData && isset($onuData['rx_power'])) {
        $onuSignal = is_array($onuData['rx_power']) ? ($onuData['rx_power']['_value'] ?? 'N/A') : $onuData['rx_power'];
    }
    if (is_array($onuSignal)) {
        $onuSignal = $onuSignal['_value'] ?? $onuSignal['value'] ?? 'N/A';
    }
    
    $rawDevices = genieacsGetValue($customerDevice, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations') ?? ($onuData['total_associations'] ?? '-');
    if (is_array($rawDevices)) {
        $rawDevices = $rawDevices['_value'] ?? $rawDevices['value'] ?? '-';
    }
    $onuDevices = is_numeric($rawDevices) ? (int)$rawDevices : '-';

    // Gentle targeted refresh to wake up FiberHome's Connected Devices array
    // We only call ONE isolated branch to deliberately avoid OMCI resource crashes
    if ($onuDevices > 0) {
        $didRefresh = genieacsRefreshObjects($deviceId, ['InternetGatewayDevice.LANDevice.1.Hosts']);
        if ($didRefresh) {
            $newGraph = genieacsGetDevice($deviceId);
            if ($newGraph) $customerDevice = $newGraph;
        }
    }

    // Extract LAN Hosts (Connected Devices List)
    $lanHosts = [];
    
    // We will extract and merge devices from ALL possible trees because some ONUs 
    // separate Ethernet devices (Hosts.Host) from WiFi devices (AssociatedDevice).
    $possibleTrees = [
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDevice',
        'InternetGatewayDevice.LANDevice.1.Hosts.Host',
        'Device.Hosts.Host',
        'Device.WiFi.AccessPoint.1.AssociatedDevice',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.AssociatedDevice'
    ];
    
    $aggregatedHosts = [];
    
    foreach ($possibleTrees as $treePath) {
        $treeData = genieacsGetValue($customerDevice, $treePath);
        if (is_array($treeData)) {
            foreach ($treeData as $key => $hostData) {
                if (!is_numeric($key)) continue; // Ignore '_object' or timestamps
                
                $host = [];
                
                // HostName parsing
                if (isset($hostData['HostName'])) {
                    $host['HostName'] = is_array($hostData['HostName']) ? ($hostData['HostName']['_value'] ?? '') : $hostData['HostName'];
                } else {
                    $host['HostName'] = 'Unknown Device (WiFi Client)';
                }
                
                // IP Address parsing
                if (isset($hostData['IPAddress'])) {
                    $host['IPAddress'] = is_array($hostData['IPAddress']) ? ($hostData['IPAddress']['_value'] ?? '') : $hostData['IPAddress'];
                } elseif (isset($hostData['AssociatedDeviceIPAddress'])) {
                    $host['IPAddress'] = is_array($hostData['AssociatedDeviceIPAddress']) ? ($hostData['AssociatedDeviceIPAddress']['_value'] ?? '') : $hostData['AssociatedDeviceIPAddress'];
                } else {
                    $host['IPAddress'] = '-';
                }
                
                // MAC Address parsing
                $candidateMac = '';
                // Look for MAC explicitly:
                $macSource = $hostData['MACAddress'] ?? $hostData['PhysAddress'] ?? $hostData['AssociatedDeviceMACAddress'] ?? null;
                $macRaw = is_array($macSource) ? ($macSource['_value'] ?? '') : $macSource;
                if (!empty($macRaw)) {
                    $candidateMac = strtoupper(trim((string)$macRaw));
                }
                
                // Brutal fallback: Find ANY string that looks like a MAC if standard keys fail
                if (empty($candidateMac)) {
                    $macRegex = '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/';
                    foreach ($hostData as $k => $val) {
                        $valCheck = is_array($val) ? ($val['_value'] ?? (is_string($val[0] ?? null) ? $val[0] : '')) : (is_string($val) ? $val : '');
                        if (is_string($valCheck) && preg_match($macRegex, trim($valCheck))) {
                            $candidateMac = strtoupper(trim($valCheck));
                            break;
                        }
                    }
                }
                
                $host['MACAddress'] = !empty($candidateMac) ? $candidateMac : 'UNKNOWN-' . $key;
                
                // Active status parsing
                if (isset($hostData['Active'])) {
                    $host['Active'] = is_array($hostData['Active']) ? ($hostData['Active']['_value'] ?? false) : $hostData['Active'];
                } elseif (isset($hostData['AssociatedDeviceAuthenticationState'])) {
                    $authState = is_array($hostData['AssociatedDeviceAuthenticationState']) ? ($hostData['AssociatedDeviceAuthenticationState']['_value'] ?? false) : $hostData['AssociatedDeviceAuthenticationState'];
                    $host['Active'] = ($authState === '1' || $authState === true || $authState === 'true');
                } else {
                    $host['Active'] = true; // Fallback assume active if it's cached in WLAN list
                }
                
                // Normalize boolean behavior
                if ($host['Active'] === '1' || $host['Active'] === true || $host['Active'] === 'true') {
                    $host['Active'] = true;
                } else {
                    $host['Active'] = false;
                }
                
                // Deduplicate by MAC
                if (!empty($host['MACAddress']) && $host['MACAddress'] !== '-') {
                    $aggregatedHosts[$host['MACAddress']] = $host;
                }
            }
        }
    }
    
    $lanHosts = array_values($aggregatedHosts);
    
    // Sort explicitly so Active devices sit on top
    usort($lanHosts, function($a, $b) {
        return $b['Active'] <=> $a['Active'];
    });
    
    // OVERRIDE erroneous hardware counts:
    // If FiberHome's `TotalAssociations` lied and reported 0, but we actually 
    // proved there are devices nested in the Hosts arrays, overwrite the UI counter!
    if (count($lanHosts) > 0 && ($onuDevices === 0 || $onuDevices === '-')) {
        $activeCount = count(array_filter($lanHosts, function($h) { return $h['Active'] === true; }));
        $onuDevices = $activeCount > 0 ? $activeCount : count($lanHosts);
    }
}

// Handle POST actions for WiFi & Reboot
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        setFlash('error', 'Sesi tidak valid.');
        redirect('wifi.php');
    }
    
    $action = $_POST['action'] ?? '';
    
    if (!$customerDevice || !$onuOnline) {
        setFlash('error', 'Perangkat sedang offline. Tidak dapat melakukan pengaturan.');
        redirect('wifi.php');
    }

    $deviceId = $customerDevice['_id'];

    if ($action === 'change_ssid') {
        $newSsid = trim($_POST['new_ssid'] ?? '');
        if (empty($newSsid)) {
            setFlash('error', 'Nama WiFi tidak boleh kosong');
        } else {
            // Dynamically locate where the SSID is stored on this specific router brand
            $ssidPath = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID';
            $possiblePaths = [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                'InternetGatewayDevice.LANDevice.1.WiFi.Radio.1.SSID',
                'Device.WiFi.SSID.1.SSID'
            ];
            foreach ($possiblePaths as $path) {
                if (genieacsGetValue($customerDevice, $path) !== null) {
                    $ssidPath = $path;
                    break;
                }
            }
            
            if (genieacsSetParameter($deviceId, $ssidPath, $newSsid)) {
                setFlash('success', 'Nama WiFi berhasil diubah. Router mungkin perlu restart.');
                logActivity('CUSTOMER_CHANGE_SSID', "Customer {$customer['name']} changed SSID");
            } else {
                setFlash('error', 'Gagal mengubah nama WiFi');
            }
        }
        redirect('wifi.php');
        
    } elseif ($action === 'change_wifi_pass') {
        $newPass = trim($_POST['new_wifi_pass'] ?? '');
        if (strlen($newPass) < 8) {
            setFlash('error', 'Password WiFi minimal 8 karakter');
        } else {
            // Dynamically locate where the PreSharedKey is stored on this specific router brand
            $passPath = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey';
            $possiblePaths = [
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
                'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                'Device.WiFi.AccessPoint.1.Security.KeyPassphrase'
            ];
            foreach ($possiblePaths as $path) {
                if (genieacsGetValue($customerDevice, $path) !== null) {
                    $passPath = $path;
                    break;
                }
            }
            
            if (genieacsSetParameter($deviceId, $passPath, $newPass)) {
                setFlash('success', 'Password WiFi berhasil diubah. Router mungkin perlu restart.');
                logActivity('CUSTOMER_CHANGE_WIFI_PASS', "Customer {$customer['name']} changed WiFi password");
            } else {
                setFlash('error', 'Gagal mengubah password WiFi');
            }
        }
        redirect('wifi.php');
        
    } elseif ($action === 'reboot_router') {
        if (genieacsReboot($deviceId)) {
            setFlash('success', 'Perintah mulai ulang (reboot) berhasil dikirim. Perangkat akan merestart dalam 1-2 menit.');
            logActivity('CUSTOMER_REBOOT_ROUTER', "Customer {$customer['name']} initiated router reboot");
        } else {
            setFlash('error', 'Gagal mengirim perintah restart ke router.');
        }
        redirect('wifi.php');
    }
}

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <?php 
    $isCustomerDeviceOnline = $customerDevice && $onuOnline;
    ?>

    <!-- WiFi Settings -->
    <?php if ($isCustomerDeviceOnline && $customerDevice): ?>
    <div class="card" id="wifi-settings" style="margin-bottom: 30px;">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-wifi"></i> Pengaturan WiFi
        </h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 15px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                <i class="fas fa-desktop" style="font-size: 1.5rem; color: var(--neon-cyan); margin-bottom: 10px;"></i>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 5px;">IP Address Router</div>
                <div style="font-size: 1.1rem; font-weight: bold; color: var(--text-primary);"><?php echo htmlspecialchars($onuData['ip_address'] ?? 'N/A'); ?></div>
            </div>
            <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 15px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                <i class="fas fa-users" style="font-size: 1.5rem; color: var(--primary); margin-bottom: 10px;"></i>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 5px;">Perangkat Terhubung</div>
                <div style="font-size: 1.1rem; font-weight: bold; color: var(--text-primary);"><?php echo htmlspecialchars($onuDevices); ?> Device</div>
            </div>
            <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 15px; border: 1px solid rgba(255,255,255,0.05); text-align: center;">
                <i class="fas fa-microchip" style="font-size: 1.5rem; color: var(--neon-purple); margin-bottom: 10px;"></i>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 5px;">Model Perangkat</div>
                <div style="font-size: 1.1rem; font-weight: bold; color: var(--text-primary);"><?php echo htmlspecialchars(trim(($onuData['manufacturer'] ?? '') . ' ' . ($onuData['model'] ?? 'N/A'))); ?></div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <div style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div class="form-group">
                        <label class="form-label">SSID WiFi Saat Ini</label>
                        <p style="font-size: 1.2rem; font-weight: 600; padding: 10px; background: rgba(0, 245, 255, 0.05); border-radius: 8px; border: 1px solid rgba(0, 245, 255, 0.2);">
                            <i class="fas fa-signal" style="color: var(--neon-cyan); margin-right: 10px;"></i>
                            <?php 
                                $currentSsid = $onuData['ssid'] ?? null;
                                echo htmlspecialchars(is_array($currentSsid) ? ($currentSsid['_value'] ?? 'Unknown') : ($currentSsid ?? 'Unknown')); 
                            ?>
                        </p>
                     </div>
                </div>
                <button type="button" class="btn btn-primary" onclick="openModal('modalChangeSsid')">
                    <i class="fas fa-edit"></i> Ubah Nama WiFi
                </button>
            </div>
            
            <div style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div class="form-group">
                        <label class="form-label">Password WiFi Saat Ini</label>
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px; background: rgba(0, 245, 255, 0.05); border-radius: 8px; border: 1px solid rgba(0, 245, 255, 0.2);">
                            <div style="display: flex; align-items: center; width: 100%;">
                                <i class="fas fa-key" style="color: var(--neon-cyan); margin-right: 10px;"></i>
                                <?php 
                                    $currentPass = $onuData['wifi_password'] ?? null;
                                    $passVal = htmlspecialchars(is_array($currentPass) ? ($currentPass['_value'] ?? '') : ($currentPass ?? '')); 
                                ?>
                                <input type="password" id="currentWifiPass" value="<?php echo $passVal; ?>" readonly style="background: transparent; border: none; color: var(--text-primary); font-size: 1.2rem; font-weight: 600; width: 100%; outline: none;">
                            </div>
                            <button type="button" onclick="togglePasswordVisibility('currentWifiPass', this)" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; margin-left: 10px;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary" onclick="openModal('modalChangeWifiPass')">
                    <i class="fas fa-lock"></i> Ubah Password WiFi
                </button>
            </div>
        </div>
    </div>
    
    <!-- Reboot Router -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-orange);">
            <i class="fas fa-power-off"></i> Mulai Ulang Router
        </h3>
        <p style="color: var(--text-secondary); margin-bottom: 15px;">
            Jika koneksi internet terasa lambat atau bermasalah, Anda dapat mencoba me-restart router WiFi dari sini tanpa perlu mematikan saklar listrik. Proses restart memakan waktu sekitar 1-2 menit.
        </p>
        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin me-restart router WiFi? Internet akan terputus selama proses restart.');">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="reboot_router">
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-sync-alt"></i> Restart Router Sekarang
            </button>
        </form>
    </div>
    
    <!-- Connected Devices List -->
    <div class="card" style="margin-top: 20px;">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-network-wired"></i> Daftar Perangkat Terhubung (LAN/WiFi)
        </h3>
        <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 0.9rem;">
            Daftar komputer, smartphone, atau Smart TV yang sedang menggunakan jaringan WiFi Anda.
        </p>
        
        <div class="table-responsive" style="overflow-x: auto; width: 100%;">
            <table class="table" style="width: 100%; border-collapse: collapse; min-width: 600px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); text-align: left; font-size: 0.9rem; color: var(--text-secondary);">
                        <th style="padding: 12px; background: rgba(0,0,0,0.2);">Nama Perangkat</th>
                        <th style="background: rgba(0,0,0,0.2);">IP Address</th>
                        <th style="background: rgba(0,0,0,0.2);">MAC Address</th>
                        <th style="background: rgba(0,0,0,0.2);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lanHosts)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                <i class="fas fa-ghost" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                Sistem tidak dapat mendeteksi daftar perangkat dari Router Anda.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lanHosts as $host): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px; font-weight: 600; color: #fff;">
                                <i class="fas <?php echo (stripos($host['HostName'], 'android') !== false || stripos($host['HostName'], 'iphone') !== false) ? 'fa-mobile-alt' : 'fa-laptop'; ?>" style="color: var(--text-secondary); margin-right: 8px;"></i>
                                <?php echo htmlspecialchars($host['HostName'] ?: 'Unknown Device'); ?>
                            </td>
                            <td style="padding: 12px; font-family: monospace; color: var(--neon-cyan);"><?php echo htmlspecialchars($host['IPAddress']); ?></td>
                            <td style="padding: 12px; font-family: monospace; color: var(--text-secondary);"><?php echo htmlspecialchars($host['MACAddress']); ?></td>
                            <td style="padding: 12px;">
                                <?php if ($host['Active']): ?>
                                    <span class="badge badge-success" style="font-size: 0.8rem; padding: 4px 10px;">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="font-size: 0.8rem; padding: 4px 10px;">Tidak Aktif</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Device Offline Message -->
    <?php if ($customerDevice): ?>
    <div class="card" style="border-color: rgba(255, 71, 87, 0.3);">
        <h3 style="margin-bottom: 15px; color: var(--neon-red);">
            <i class="fas fa-exclamation-triangle"></i> Perangkat Offline
        </h3>
        <p style="color: var(--text-secondary);">
            Router Anda saat ini tidak terhubung ke sistem kami. Pengaturan WiFi dan fitur Restart Router tidak dapat digunakan saat ini. Pastikan router Anda dalam keadaan menyala.
        </p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<!-- Modals -->
<div id="modalChangeSsid" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 400px; max-width: 90%; padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
        <h3 style="color: var(--neon-cyan); margin-bottom: 20px;">Ubah Nama WiFi</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="change_ssid">
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: var(--text-secondary);">Nama WiFi Baru (SSID)</label>
                <input type="text" name="new_ssid" class="form-control" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white; border-radius: 6px;" required>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalChangeSsid')" style="padding: 8px 15px; background: transparent; border: 1px solid var(--border-color); color: white; border-radius: 6px; cursor: pointer;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px; background: var(--gradient-primary); border: none; color: white; border-radius: 6px; cursor: pointer;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div id="modalChangeWifiPass" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 400px; max-width: 90%; padding: 25px; border-radius: 12px; border: 1px solid var(--border-color);">
        <h3 style="color: var(--neon-cyan); margin-bottom: 20px;">Ubah Password WiFi</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="change_wifi_pass">
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; color: var(--text-secondary);">Password WiFi Baru (Minimal 8 karakter)</label>
                <input type="password" name="new_wifi_pass" class="form-control" minlength="8" style="width: 100%; padding: 10px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: white; border-radius: 6px;" required>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalChangeWifiPass')" style="padding: 8px 15px; background: transparent; border: 1px solid var(--border-color); color: white; border-radius: 6px; cursor: pointer;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 8px 15px; background: var(--gradient-primary); border: none; color: white; border-radius: 6px; cursor: pointer;">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
function togglePasswordVisibility(inputId, btnElement) {
    const input = document.getElementById(inputId);
    const icon = btnElement.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
