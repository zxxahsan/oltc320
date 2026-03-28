<?php
/**
 * GenieACS Device Management
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'GenieACS Monitoring';

// Get devices from GenieACS with specific projection
$devices = genieacsGetDevices();
$totalDevices = count($devices);

// Get existing locations
$locations = fetchAll("SELECT * FROM onu_locations");
$locMap = [];
foreach ($locations as $loc) {
    if (!empty($loc['serial_number'])) {
        $locMap[$loc['serial_number']] = $loc;
    }
}

// Calculate stats
$onlineCount = 0;
$offlineCount = 0;
$weakSignalCount = 0;

foreach ($devices as $device) {
    // Determine online/offline status
    $lastInform = $device['_lastInform'] ?? null;
    if ($lastInform && (time() - strtotime($lastInform)) < 300) {
        $onlineCount++;
    } else {
        $offlineCount++;
    }

    // Check RX Power
    $rxPower = $device['VirtualParameters']['RXPower']['_value'] ?? $device['VirtualParameters']['RXPower'] ?? null;
    if (is_numeric($rxPower) && $rxPower < -25 && $rxPower != 0) {
        $weakSignalCount++;
    }
}

// Helper to safely get value whether it's direct or wrapped in _value
function getVal($data, $path) {
    $parts = explode('.', $path);
    $current = $data;
    
    foreach ($parts as $part) {
        if (isset($current[$part])) {
            $current = $current[$part];
        } else {
            return null;
        }
    }
    
    if (is_array($current)) {
        return $current['_value'] ?? null;
    }
    
    return $current;
}

ob_start();
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
<style>
    .map-container { height: 400px; width: 100%; border-radius: 8px; margin-bottom: 15px; }
</style>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="fas fa-satellite-dish"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $totalDevices; ?></h3>
            <p>Total Device</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $onlineCount; ?></h3>
            <p>Online</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $offlineCount; ?></h3>
            <p>Offline</p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
            <h3><?php echo $weakSignalCount; ?></h3>
            <p>Signal Lemah</p>
        </div>
    </div>
</div>

<!-- Connection Status -->
<?php if (!empty(GENIEACS_URL)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        GenieACS Connected: <?php echo htmlspecialchars(GENIEACS_URL); ?>
    </div>
<?php else: ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        GenieACS tidak terkonfigurasi. Silakan setup di Settings.
    </div>
<?php endif; ?>

<!-- Devices Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-server"></i> Monitoring ONU (Virtual Parameters)</h3>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="searchDevice" class="form-control" placeholder="Cari ID, IP, SN..." style="width: 250px;">
            <button class="btn btn-primary btn-sm" onclick="loadDevices()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="data-table" style="font-size: 0.85rem; white-space: nowrap;">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Lokasi</th>
                    <th>ID (PPPoE)</th>
                    <th>SSID</th>
                    <th>Active</th>
                    <th>Hotspot</th>
                    <th>RX Power</th>
                    <th>Temp</th>
                    <th>Uptime</th>
                    <th>IP PPPoE</th>
                    <th>IP WAN</th>
                    <th>PON Mode</th>
                    <th>SN</th>
                    <th>MAC</th>
                    <th>Last Inform</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="15" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <i class="fas fa-server" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                            Tidak ada device ditemukan atau GenieACS tidak terkoneksi
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $device):
                        // Extract standard values
                        $realDeviceId = $device['_id'] ?? ''; // Use ID for reliable API calls
                        $serialNumber = $device['_deviceId']['_SerialNumber'] ?? getVal($device, 'DeviceID.SerialNumber') ?? '-';
                        if (empty($realDeviceId)) $realDeviceId = $serialNumber; // Fallback
                        $lastInform = $device['_lastInform'] ?? null;
                        $isOnline = $lastInform && (time() - strtotime($lastInform)) < 300;

                        // Extract Virtual Parameters
                        $pppoeUser2 = getVal($device, 'VirtualParameters.pppoeUsername2') ?? '-';
                        $ssid = getVal($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID') ?? '-';
                        $wifiPass = getVal($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase') ?? '';
                        $totalAssoc = getVal($device, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations') ?? '0';
                        $hotspotActive = getVal($device, 'VirtualParameters.activedevices') ?? '-';
                        $rxPower = getVal($device, 'VirtualParameters.RXPower') ?? '-';
                        $temp = getVal($device, 'VirtualParameters.gettemp') ?? '-';
                        $uptime = getVal($device, 'VirtualParameters.getdeviceuptime') ?? '-';
                        $pppoeIp = getVal($device, 'VirtualParameters.pppoeIP') ?? '-';
                        $wanIp = getVal($device, 'VirtualParameters.IPTR069') ?? '-';
                        $ponMode = getVal($device, 'VirtualParameters.getponmode') ?? '-';
                        $ponMac = getVal($device, 'VirtualParameters.PonMac') ?? getVal($device, 'VirtualParameters.pppoeMac') ?? '-';
                        $sn = getVal($device, 'VirtualParameters.getSerialNumber') ?? $serialNumber;

                        // Format uptime if it's in seconds
                        if (is_numeric($uptime)) {
                            $days = floor($uptime / 86400);
                            $hours = floor(($uptime % 86400) / 3600);
                            $uptime = "{$days}d {$hours}h";
                        }
                    ?>
                    <tr>
                        <td>
                            <?php if ($isOnline): ?>
                                <span class="badge badge-success">Online</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php 
                                $hasLoc = isset($locMap[$serialNumber]);
                                $lat = $hasLoc ? $locMap[$serialNumber]['lat'] : '';
                                $lng = $hasLoc ? $locMap[$serialNumber]['lng'] : '';
                                $locName = $hasLoc ? $locMap[$serialNumber]['name'] : $pppoeUser2;
                            ?>
                            <button class="btn btn-sm <?php echo $hasLoc ? 'btn-success' : 'btn-secondary'; ?>" 
                                    onclick="openMapModal('<?php echo $serialNumber; ?>', '<?php echo $lat; ?>', '<?php echo $lng; ?>', '<?php echo htmlspecialchars($locName); ?>')"
                                    title="<?php echo $hasLoc ? 'Lokasi Tersimpan' : 'Set Lokasi'; ?>"
                                    style="padding: 2px 6px;">
                                <i class="fas fa-map-marker-alt"></i>
                            </button>
                        </td>
                        <td>
                            <strong style="color: var(--neon-cyan);"><?php echo htmlspecialchars($pppoeUser2); ?></strong>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 5px;">
                                <span><?php echo htmlspecialchars($ssid); ?></span>
                                <button class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.7rem;" 
                                        onclick="openWifiEdit('<?php echo htmlspecialchars($realDeviceId); ?>', '<?php echo htmlspecialchars($ssid); ?>', '<?php echo htmlspecialchars($wifiPass); ?>')" 
                                        title="Edit SSID & Password">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </td>
                        <td style="text-align: center;"><?php echo htmlspecialchars($totalAssoc); ?></td>
                        <td style="text-align: center;"><?php echo htmlspecialchars($hotspotActive); ?></td>
                        <td>
                            <?php 
                            $rxVal = floatval($rxPower);
                            $rxColor = ($rxVal < -25) ? 'red' : (($rxVal < -20) ? 'orange' : 'green');
                            echo "<span style='color: $rxColor; font-weight: bold;'>" . htmlspecialchars($rxPower) . " dBm</span>"; 
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($temp); ?> °C</td>
                        <td><?php echo htmlspecialchars($uptime); ?></td>
                        <td>
                            <?php if ($pppoeIp !== '-'): ?>
                                <a href="http://<?php echo htmlspecialchars($pppoeIp); ?>" target="_blank" style="color: var(--neon-blue);">
                                    <?php echo htmlspecialchars($pppoeIp); ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($wanIp !== '-'): ?>
                                <a href="http://<?php echo htmlspecialchars($wanIp); ?>" target="_blank" style="color: var(--neon-purple);">
                                    <?php echo htmlspecialchars($wanIp); ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($ponMode); ?></td>
                        <td>
                            <code><?php echo htmlspecialchars($sn); ?></code>
                        </td>
                        <td><?php echo htmlspecialchars($ponMac); ?></td>
                        <td><?php echo $lastInform ? formatDate($lastInform, 'd M H:i') : '-'; ?></td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="rebootDevice('<?php echo htmlspecialchars($realDeviceId); ?>')">
                                <i class="fas fa-redo"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- WiFi Edit Modal -->
<div id="wifiModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 400px; max-width: 90%;">
        <div class="card-header">
            <h3 class="card-title">Edit WiFi</h3>
            <button onclick="closeWifiModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">&times;</button>
        </div>
        
        <input type="hidden" id="editSerial">

        <div class="form-group" style="margin-bottom: 20px;">
            <label class="form-label">SSID</label>
            <div style="display: flex; gap: 10px;">
                <input type="text" id="editSsid" class="form-control">
                <button class="btn btn-primary" onclick="saveSsid()" title="Simpan SSID">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label class="form-label">Password</label>
            <div style="display: flex; gap: 10px;">
                <div style="position: relative; flex: 1;">
                    <input type="password" id="editPassword" class="form-control" style="padding-right: 35px;">
                    <i class="fas fa-eye" id="togglePass" onclick="togglePasswordVisibility()" 
                       style="position: absolute; right: 10px; top: 12px; cursor: pointer; color: var(--text-secondary);"></i>
                </div>
                <button class="btn btn-primary" onclick="savePassword()" title="Simpan Password">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>

        <div style="text-align: right; margin-top: 15px;">
            <button class="btn btn-secondary" onclick="closeWifiModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Map Modal -->
<div id="mapModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2005; align-items: center; justify-content: center; padding: 10px;">
    <div class="card" style="width: 600px; max-width: 100%; display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; padding: 0;">
        <div class="card-header" style="flex-shrink: 0; padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title" style="margin: 0;"><i class="fas fa-map-marked-alt"></i> Set Lokasi ONU</h3>
            <button onclick="closeMapModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.5rem; line-height: 1;">&times;</button>
        </div>
        
        <div style="flex: 1; overflow-y: auto; padding: 15px;">
            <input type="hidden" id="mapSerial">
            
            <div class="form-group">
                <label class="form-label">Nama Lokasi</label>
                <input type="text" id="mapName" class="form-control" placeholder="Nama Pelanggan/Lokasi">
            </div>

            <div id="map" class="map-container" style="height: 300px; width: 100%; border-radius: 8px; margin-bottom: 15px; min-height: 200px;"></div>
            
            <div class="form-group" style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label class="form-label">Latitude</label>
                    <input type="text" id="mapLat" class="form-control" style="font-size: 0.85rem;" onchange="updateMarkerFromInput()">
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Longitude</label>
                    <input type="text" id="mapLng" class="form-control" style="font-size: 0.85rem;" onchange="updateMarkerFromInput()">
                </div>
            </div>
        </div>

        <div style="flex-shrink: 0; padding: 15px; border-top: 1px solid rgba(255,255,255,0.1); text-align: right; background: var(--bg-card);">
            <button class="btn btn-secondary" onclick="closeMapModal()">Batal</button>
            <button class="btn btn-primary" onclick="saveLocation()">Simpan Lokasi</button>
        </div>
    </div>
</div>

<script>
let map, marker;

function initMap() {
    if (map) return;
    
    // Default Alijaya net
    map = L.map('map').setView([-6.252471, 107.920660], 16);
    
    var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    });

    var googleSat = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}',{
        maxZoom: 20,
        subdomains:['mt0','mt1','mt2','mt3']
    });

    googleSat.addTo(map);

    var baseMaps = {
        "Satelit": googleSat,
        "OpenStreetMap": osm
    };
    L.control.layers(baseMaps).addTo(map);

    map.on('click', function(e) {
        setMarker(e.latlng.lat, e.latlng.lng);
    });
}

function setMarker(lat, lng) {
    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng], {draggable: true}).addTo(map);
        marker.on('dragend', function(e) {
            var position = marker.getLatLng();
            updateInputs(position.lat, position.lng);
        });
    }
    updateInputs(lat, lng);
    map.setView([lat, lng]);
}

function updateInputs(lat, lng) {
    document.getElementById('mapLat').value = lat;
    document.getElementById('mapLng').value = lng;
}

function updateMarkerFromInput() {
    const lat = parseFloat(document.getElementById('mapLat').value);
    const lng = parseFloat(document.getElementById('mapLng').value);
    
    if (!isNaN(lat) && !isNaN(lng)) {
        setMarker(lat, lng);
        map.setView([lat, lng], 16);
    }
}

function openMapModal(serial, lat, lng, name) {
    document.getElementById('mapModal').style.display = 'flex';
    document.getElementById('mapSerial').value = serial;
    document.getElementById('mapName').value = name || serial;
    
    // Initialize map if needed
    // We use setTimeout to ensure modal is visible so Leaflet can calculate size
    setTimeout(() => {
        if (map) {
            map.remove();
            map = null;
            marker = null;
        }
        
        initMap();
        map.invalidateSize();
        
        if (lat && lng && lat != 0 && lng != 0) {
            setMarker(parseFloat(lat), parseFloat(lng));
        } else {
            // Try geolocation or default
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    map.setView([position.coords.latitude, position.coords.longitude], 16);
                }, function() {
                    map.setView([-6.252471, 107.920660], 16);
                });
            } else {
                map.setView([-6.252471, 107.920660], 16);
            }
            
            document.getElementById('mapLat').value = '';
            document.getElementById('mapLng').value = '';
        }
    }, 200);
}

function closeMapModal() {
    document.getElementById('mapModal').style.display = 'none';
    if (map) {
        map.remove();
        map = null;
        marker = null;
    }
}

function saveLocation() {
    const serial = document.getElementById('mapSerial').value;
    const name = document.getElementById('mapName').value;
    const lat = document.getElementById('mapLat').value;
    const lng = document.getElementById('mapLng').value;

    if (!lat || !lng) {
        alert('Silakan tentukan lokasi pada peta');
        return;
    }

    fetch('<?php echo APP_URL; ?>/api/onu_locations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            serial: serial,
            name: name,
            lat: lat,
            lng: lng
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Lokasi berhasil disimpan');
            location.reload();
        } else {
            alert('Gagal simpan: ' + data.message);
        }
    })
    .catch(error => alert('Error: ' + error));
}

function loadDevices() {
    location.reload();
}

function rebootDevice(serial) {
    if (!confirm('Reboot device ' + serial + '?')) {
        return;
    }

    fetch('<?php echo APP_URL; ?>/api/genieacs.php?action=reboot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial: serial })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reboot berhasil dijalankan untuk device ' + serial);
        } else {
            alert('Gagal reboot: ' + data.message);
        }
    })
    .catch(error => {
        alert('Terjadi kesalahan: ' + error.message);
    });
}

// WiFi Modal Functions
function openWifiEdit(serial, ssid, password) {
    document.getElementById('editSerial').value = serial;
    document.getElementById('editSsid').value = ssid;
    document.getElementById('editPassword').value = password;
    document.getElementById('wifiModal').style.display = 'flex';
}

function closeWifiModal() {
    document.getElementById('wifiModal').style.display = 'none';
}

function togglePasswordVisibility() {
    const input = document.getElementById('editPassword');
    const icon = document.getElementById('togglePass');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

function saveSsid() {
    const serial = document.getElementById('editSerial').value;
    const ssid = document.getElementById('editSsid').value;

    if (ssid.length < 3) {
        alert('SSID minimal 3 karakter');
        return;
    }

    if (!confirm('Simpan perubahan SSID?')) return;

    fetch('<?php echo APP_URL; ?>/api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial: serial, ssid: ssid })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('SSID berhasil diperbarui');
            location.reload();
        } else {
            alert('Gagal update SSID: ' + data.message);
        }
    })
    .catch(error => alert('Error: ' + error));
}

function savePassword() {
    const serial = document.getElementById('editSerial').value;
    const password = document.getElementById('editPassword').value;

    if (password.length < 8) {
        alert('Password minimal 8 karakter');
        return;
    }

    if (!confirm('Simpan perubahan Password?')) return;

    fetch('<?php echo APP_URL; ?>/api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial: serial, password: password })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Password berhasil diperbarui');
            location.reload();
        } else {
            alert('Gagal update Password: ' + data.message);
        }
    })
    .catch(error => alert('Error: ' + error));
}

document.getElementById('searchDevice').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.data-table tbody tr');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(search) ? '' : 'none';
    });
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
