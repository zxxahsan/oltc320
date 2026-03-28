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
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="display: flex; flex-direction: column;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">SSID WiFi Saat Ini</label>
                    <div style="font-size: 1.1rem; font-weight: 600; padding: 12px; background: var(--bg-secondary); border-radius: 10px; border: 2px solid var(--border-color); color: var(--text-primary); display: flex; align-items: center; overflow: hidden;">
                        <i class="fas fa-signal" style="color: var(--neon-cyan); margin-right: 12px; flex-shrink: 0;"></i>
                        <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?php 
                                $currentSsid = $onuData['ssid'] ?? null;
                                echo htmlspecialchars(is_array($currentSsid) ? ($currentSsid['_value'] ?? 'Unknown') : ($currentSsid ?? 'Unknown')); 
                            ?>
                        </span>
                    </div>
                 </div>
                <button type="button" class="btn btn-primary" onclick="openModal('modalChangeSsid')" style="width: 100%;">
                    <i class="fas fa-edit"></i> Ubah Nama WiFi
                </button>
            </div>
            
            <div style="display: flex; flex-direction: column;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="form-label">Password WiFi Saat Ini</label>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: var(--bg-secondary); border-radius: 10px; border: 2px solid var(--border-color); color: var(--text-primary); overflow: hidden;">
                        <div style="display: flex; align-items: center; width: 100%; overflow: hidden;">
                            <i class="fas fa-key" style="color: var(--neon-cyan); margin-right: 12px; flex-shrink: 0;"></i>
                            <?php 
                                $currentPass = $onuData['wifi_password'] ?? null;
                                $passVal = htmlspecialchars(is_array($currentPass) ? ($currentPass['_value'] ?? '') : ($currentPass ?? '')); 
                            ?>
                            <input type="password" id="currentWifiPass" value="<?php echo $passVal; ?>" readonly style="background: transparent; border: none; color: var(--text-primary); font-size: 1.1rem; font-weight: 600; width: 100%; outline: none;">
                        </div>
                        <button type="button" onclick="togglePasswordVisibility('currentWifiPass', this)" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; margin-left: 10px; flex-shrink: 0;">
                            <i class="fas fa-eye" style="font-size: 1.1rem;"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary" onclick="openModal('modalChangeWifiPass')" style="width: 100%;">
                    <i class="fas fa-lock"></i> Ubah Password WiFi
                </button>
            </div>
        </div>
    </div>
    
    <!-- Connected Devices List -->
    <div class="card" style="margin-top: 20px;">
        <h3 style="margin-bottom: 20px; color: var(--neon-cyan);">
            <i class="fas fa-network-wired"></i> Perangkat Terhubung
        </h3>
        
        <div class="device-list">
            <?php if (empty($lanHosts)): ?>
                <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <i class="fas fa-ghost" style="font-size: 2.5rem; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                    Sistem tidak dapat mendeteksi daftar perangkat dari Router Anda.
                </div>
            <?php else: ?>
                <?php foreach ($lanHosts as $host): ?>
                <div class="device-item">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div class="device-icon <?php echo $host['Active'] ? 'active' : ''; ?>">
                            <i class="fas <?php 
                                $name = strtolower($host['HostName']);
                                if (strpos($name, 'iphone') !== false || strpos($name, 'android') !== false || strpos($name, 'phone') !== false) echo 'fa-mobile-alt';
                                elseif (strpos($name, 'macbook') !== false || strpos($name, 'laptop') !== false || strpos($name, 'pc') !== false) echo 'fa-laptop';
                                elseif (strpos($name, 'tv') !== false) echo 'fa-tv';
                                else echo 'fa-wifi';
                            ?>"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: var(--text-primary); font-size: 1rem;">
                                <?php echo htmlspecialchars($host['HostName'] ?: 'Perangkat Tanpa Nama'); ?>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <?php echo $host['Active'] ? 'Sedang Terhubung' : 'Terakhir terlihat baru-baru ini'; ?>
                            </div>
                        </div>
                        <?php if ($host['Active']): ?>
                            <div style="width: 8px; height: 8px; background: var(--neon-green); border-radius: 50%; box-shadow: 0 0 8px var(--neon-green);"></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Reboot Router Section -->
    <div class="card" style="margin-top: 30px; border-top: 4px solid var(--neon-orange);">
        <h3 style="margin-bottom: 15px; color: var(--neon-orange);">
            <i class="fas fa-power-off"></i> Mulai Ulang Router
        </h3>
        <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 0.9rem;">
            Jika koneksi internet terasa lambat atau bermasalah, Anda dapat me-restart router WiFi dari sini tanpa mematikan listrik. Proses memakan waktu sekitar 1-2 menit.
        </p>
        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin me-restart router WiFi? Internet akan terputus selama proses restart.');">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="reboot_router">
            <button type="submit" class="btn btn-danger" style="width: 100%; border-radius: 8px;">
                <i class="fas fa-sync-alt"></i> Restart Router Sekarang
            </button>
        </form>
    </div>

    <style>
    .device-list { display: flex; flex-direction: column; gap: 12px; }
    .device-item { 
        padding: 15px; 
        background: var(--bg-secondary); 
        border: 1px solid var(--border-color); 
        border-radius: 12px; 
        box-shadow: var(--shadow-card);
        transition: transform 0.2s;
    }
    .device-item:hover { transform: translateX(5px); border-color: var(--neon-cyan); }
    .device-icon { 
        width: 45px; 
        height: 45px; 
        background: rgba(0,0,0,0.05); 
        border-radius: 10px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        font-size: 1.2rem; 
        color: var(--text-muted); 
    }
    .device-icon.active { background: rgba(0, 245, 255, 0.1); color: var(--neon-cyan); }
    </style>

    <?php else: ?>
        <!-- Device Offline Message -->
        <?php if ($customerDevice): ?>
        <div class="card" style="border-color: rgba(255, 71, 87, 0.3); margin-bottom: 20px;">
            <h3 style="margin-bottom: 15px; color: var(--neon-red);">
                <i class="fas fa-exclamation-triangle"></i> Perangkat Offline
            </h3>
            <p style="color: var(--text-secondary);">
                Router Anda saat ini tidak terhubung ke sistem kami. Pengaturan WiFi tidak dapat diakses saat ini. Pastikan router Anda menyala dan kabel fiber optic terpasang dengan benar.
            </p>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- Modals -->
<div id="modalChangeSsid" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 400px; max-width: 95%; padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
        <h3 style="color: var(--neon-cyan); margin-bottom: 25px; font-weight: 800;">Ubah Nama WiFi</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="change_ssid">
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="display: block; margin-bottom: 10px; color: var(--text-secondary); font-weight: 600;">Nama WiFi Baru (SSID)</label>
                <input type="text" name="new_ssid" class="form-control" style="width: 100%; padding: 12px; background: var(--bg-secondary); border: 2px solid var(--border-color); color: var(--text-primary); border-radius: 8px; font-size: 1rem;" required>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalChangeSsid')" style="padding: 10px 20px; font-weight: 600;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<div id="modalChangeWifiPass" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: var(--bg-card); width: 400px; max-width: 95%; padding: 30px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
        <h3 style="color: var(--neon-cyan); margin-bottom: 25px; font-weight: 800;">Ubah Password WiFi</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <input type="hidden" name="action" value="change_wifi_pass">
            
            <div class="form-group" style="margin-bottom: 25px;">
                <label style="display: block; margin-bottom: 10px; color: var(--text-secondary); font-weight: 600;">Password Baru (Minimal 8 karakter)</label>
                <input type="password" name="new_wifi_pass" class="form-control" minlength="8" style="width: 100%; padding: 12px; background: var(--bg-secondary); border: 2px solid var(--border-color); color: var(--text-primary); border-radius: 8px; font-size: 1rem;" required>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalChangeWifiPass')" style="padding: 10px 20px; font-weight: 600;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 600;">Simpan Perubahan</button>
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
