<?php
require_once '../../includes/auth.php';
requireTechnicianLogin();

$pageTitle = 'Peta Sebaran';
$tech = $_SESSION['technician'];

// Get all ODPs and standalone ONUs from onu_locations
// ONUs in onu_locations might not be in customers table yet
$onus = fetchAll("
    SELECT * FROM onu_locations 
    WHERE type != 'odp' 
    AND lat IS NOT NULL AND lng IS NOT NULL 
    AND serial_number NOT IN (SELECT pppoe_username FROM customers WHERE pppoe_username IS NOT NULL)
");
$odps = fetchAll("SELECT * FROM onu_locations WHERE type = 'odp' AND lat IS NOT NULL AND lng IS NOT NULL");

// Get Customers safely mapping phone keys natively over serial tags
$customers = fetchAll("
    SELECT c.id, c.name, 
           COALESCE(c.lat, o.lat) as lat, 
           COALESCE(c.lng, o.lng) as lng, 
           c.address, c.status, c.pppoe_username 
    FROM customers c
    LEFT JOIN onu_locations o ON c.phone = o.serial_number
    WHERE c.status IN ('registered', 'active')
    HAVING lat IS NOT NULL AND lng IS NOT NULL
");

// Get technician's tasks to highlight mapping Phone triggers directly representing ACS/Onu Serial constraints
$myTasks = fetchAll("
    SELECT t.id, t.customer_id, c.phone, 'ticket' as type 
    FROM trouble_tickets t
    JOIN customers c ON t.customer_id = c.id
    WHERE t.technician_id = ? AND t.status != 'resolved'
    UNION
    SELECT id, id as customer_id, phone, 'install' as type 
    FROM customers 
    WHERE installed_by = ? AND status = 'registered'
", [$tech['id'], $tech['id']]);

$taskMap = [];
foreach ($myTasks as $task) {
    if (!empty($task['phone'])) {
        $taskMap[$task['phone']] = $task['type'];
    }
}

$mapCenter = ['lat' => -6.200000, 'lng' => 106.816666];
$centerQuery = fetchOne("SELECT AVG(lat) as avg_lat, AVG(lng) as avg_lng FROM onu_locations WHERE lat IS NOT NULL AND lng IS NOT NULL");
if ($centerQuery && $centerQuery['avg_lat']) {
    $mapCenter['lat'] = $centerQuery['avg_lat'];
    $mapCenter['lng'] = $centerQuery['avg_lng'];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peta Sebaran - Teknisi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --primary: #00f5ff;
            --bg-dark: #0a0a12;
            --bg-card: #161628;
            --text-primary: #ffffff;
            --text-secondary: #b0b0c0;
            --success: #00ff88;
            --danger: #ff4757;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        
        body {
            background: var(--bg-dark);
            color: var(--text-primary);
            padding-bottom: 80px;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .header {
            background: var(--bg-card);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .back-btn {
            color: var(--text-primary);
            font-size: 1.2rem;
            text-decoration: none;
        }
        
        #map {
            flex: 1;
            width: 100%;
            min-height: 70vh;
            z-index: 1;
        }
        
        .legend {
            background: rgba(0,0,0,0.8);
            padding: 10px;
            border-radius: 8px;
            position: absolute;
            bottom: 90px;
            right: 10px;
            z-index: 1000;
            font-size: 0.8rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 5px;
        }
        
        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .gps-btn {
            position: absolute;
            bottom: 160px;
            right: 10px;
            z-index: 1000;
            background: white;
            color: black;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            font-size: 1.2rem;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal-card {
            background: var(--bg-card);
            width: 100%;
            max-width: 400px;
            border-radius: 12px;
            padding: 20px;
            position: relative;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-size: 0.9rem; color: var(--text-secondary); }
        .form-control {
            width: 100%;
            padding: 10px;
            background: rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px;
            color: var(--text-primary);
        }
        
        .btn {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 5px;
        }
        .btn-primary { background: var(--primary); color: #000; }
        .btn-danger { background: var(--danger); color: white; }
        
        .popup-btn {
            display: inline-block;
            background: var(--primary);
            color: black;
            padding: 5px 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.8rem;
            margin-top: 5px;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="../dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
        <h2>Peta Sebaran</h2>
    </div>

    <div id="map"></div>
    
    <button class="gps-btn" onclick="locateUser()"><i class="fas fa-crosshairs"></i></button>

    <div class="legend">
        <div class="legend-item"><span class="dot" style="background: blue;"></span> ODP</div>
        <div class="legend-item"><span class="dot" style="background: green;"></span> Pelanggan Aktif</div>
        <div class="legend-item"><span class="dot" style="background: orange;"></span> Tugas Saya</div>
    </div>

    <!-- ONU Detail Modal (Similar to Admin) -->
    <div id="onuDetailModal" class="modal-overlay">
        <div class="modal-card" style="width: 450px; max-width: 95%;">
            <button class="modal-close" onclick="closeOnuDetailModal()">&times;</button>
            <h3 style="margin-bottom: 15px; color: var(--primary);">Detail ONU</h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.9rem;">
                <div>
                    <p style="color: var(--text-secondary);">Nama:</p>
                    <p id="detailName" style="color: var(--primary); font-weight: bold;">-</p>
                </div>
                <div>
                    <p style="color: var(--text-secondary);">Serial:</p>
                    <p><code id="detailSerial" style="background: rgba(0, 245, 255, 0.1); padding: 2px 4px; border-radius: 4px; color: var(--primary);">-</code></p>
                </div>
                <div>
                    <p style="color: var(--text-secondary);">Status:</p>
                    <p id="detailStatus">-</p>
                </div>
                <div>
                    <p style="color: var(--text-secondary);">Sinyal (RX):</p>
                    <p id="detailRx">-</p>
                </div>
                <div>
                    <p style="color: var(--text-secondary);">IP Address:</p>
                    <p id="detailIp" style="color: var(--primary);">-</p>
                </div>
                <div>
                    <p style="color: var(--text-secondary);">Last Inform:</p>
                    <p id="detailLastInform" style="font-size: 0.8rem;">-</p>
                </div>
            </div>
            
            <hr style="border-color: rgba(255,255,255,0.1); margin: 15px 0;">
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a id="navLink" href="#" target="_blank" class="btn" style="flex: 1; background: #444; color: white; text-decoration: none; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-directions" style="margin-right: 5px;"></i> Navigasi
                </a>
                <button class="btn btn-primary" style="flex: 1;" onclick="goToManage()">
                    <i class="fas fa-cog" style="margin-right: 5px;"></i> Atur Alat
                </button>
            </div>
            
            <div style="margin-top: 10px;">
                <button class="btn btn-secondary" onclick="openLocationModalFromDetail()">
                    <i class="fas fa-map-marker-alt" style="margin-right: 5px;"></i> Update Lokasi
                </button>
            </div>
        </div>
    </div>

    <!-- Device Manage Modal -->
    <div id="deviceModal" class="modal-overlay">
        <div class="modal-card">
            <button class="modal-close" onclick="closeModal()">&times;</button>
            <h3 style="margin-bottom: 15px; color: var(--primary);">Atur Perangkat</h3>
            <p id="modalCustomerName" style="margin-bottom: 20px; font-weight: bold;"></p>
            
            <input type="hidden" id="modalUsername">
            
            <div class="form-group">
                <label class="form-label">SSID WiFi</label>
                <input type="text" id="modalSsid" class="form-control" placeholder="Loading...">
            </div>
            
            <div class="form-group">
                <label class="form-label">Password WiFi</label>
                <div style="display: flex; gap: 5px;">
                    <input type="text" id="modalPassword" class="form-control" placeholder="Loading...">
                    <button type="button" class="btn" style="width: auto; background: #444; color: white;" onclick="generatePass()"><i class="fas fa-random"></i></button>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="saveWifi()">Simpan WiFi</button>
                <button class="btn btn-danger" onclick="rebootDevice()">Reboot</button>
            </div>
            
            <div id="modalStatus" style="margin-top: 15px; font-size: 0.8rem; text-align: center; color: var(--text-secondary);"></div>
        </div>
    </div>

    <!-- Location Update Modal -->
    <div id="locationModal" class="modal-overlay" style="z-index: 2005;">
        <div class="modal-card" style="max-width: 500px;">
            <button class="modal-close" onclick="closeLocationModal()">&times;</button>
            <h3 style="margin-bottom: 15px; color: var(--primary);">Update Lokasi</h3>
            
            <input type="hidden" id="locSerial">
            <input type="hidden" id="locName">
            
            <div class="form-group">
                <label class="form-label">Geser marker atau isi koordinat:</label>
                <div id="locMap" style="height: 300px; width: 100%; border-radius: 8px; margin-bottom: 15px; background: #222;"></div>
            </div>
            
            <div class="form-group" style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label class="form-label">Latitude</label>
                    <input type="text" id="locLat" class="form-control" onchange="updateLocMarker()">
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Longitude</label>
                    <input type="text" id="locLng" class="form-control" onchange="updateLocMarker()">
                </div>
            </div>
            
            <button class="btn btn-primary" onclick="saveLocation()">Simpan Perubahan</button>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        var map = L.map('map').setView([<?php echo $mapCenter['lat']; ?>, <?php echo $mapCenter['lng']; ?>], 14);

        // Base layers
        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap'
        });
        
        var googleSat = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}', {
            maxZoom: 20,
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
        });

        // Add default layer (Satellite by default for technician)
        googleSat.addTo(map);

        // Layer control
        var baseMaps = {
            "Satelit": googleSat,
            "OpenStreetMap": osm
        };
        L.control.layers(baseMaps).addTo(map);

        // Icons
        var odpIcon = L.divIcon({
            className: 'custom-div-icon',
            html: "<div style='background-color:blue; width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center; color: white; box-shadow: 0 2px 5px rgba(0,0,0,0.5);'><i class='fas fa-network-wired' style='font-size: 12px;'></i></div>",
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });

        var customerIcon = L.divIcon({
            className: 'custom-div-icon',
            html: "<div style='background-color:green; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center; color: white; box-shadow: 0 2px 5px rgba(0,0,0,0.5);'><i class='fas fa-home' style='font-size: 10px;'></i></div>",
            iconSize: [20, 20],
            iconAnchor: [10, 10]
        });

        var taskIcon = L.divIcon({
            className: 'custom-div-icon',
            html: "<div style='background-color:orange; width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 5px orange; display: flex; align-items: center; justify-content: center; color: white;'><i class='fas fa-exclamation-triangle' style='font-size: 12px;'></i></div>",
            iconSize: [24, 24],
            iconAnchor: [12, 12]
        });
        
        // Pass the task map dynamically ensuring Object-casting securely
        var myTasks = <?php echo json_encode(empty($taskMap) ? new stdClass() : $taskMap); ?>;

        // Fetch data from API like admin/map.php
        fetch('../../api/onu_locations.php')
            .then(res => res.json())
            .then(result => {
                if (result.success && result.data) {
                    var onus = result.data;
                    for (var i = 0; i < onus.length; i++) {
                        var o = onus[i];
                        if (!o.lat || !o.lng) continue;
                        
                        var isOnline = o.status === 'online';
                        var color = isOnline ? '#00ff88' : (o.status === 'offline' ? '#ff4757' : '#9aa0a6');
                        var iconClass = 'fa-satellite-dish';
                        
                        // Override logic: if customer is flagged in $taskMap, illuminate Orange
                        if (myTasks[o.serial_number]) {
                            color = '#ff9f43'; // Orange Warning
                            iconClass = 'fa-exclamation-triangle';
                        }
                        
                        var markerIcon = L.divIcon({
                            className: 'custom-marker',
                            html: '<div style="background: ' + color + '; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.5); color: white;"><i class="fas ' + iconClass + '" style="font-size: 11px;"></i></div>',
                            iconSize: [24, 24],
                            iconAnchor: [12, 12]
                        });

                        var popupContent = `
                            <div id="popup-${o.serial_number}">
                                <b>${o.name || 'ONU'}</b><br>
                                SN: ${o.serial_number}<br>
                                Status: <b style="color:${color}">${o.status || 'Unknown'}</b><br>
                                <div style="margin-top:5px; font-size:0.85rem; color:#ccc;">
                                    RX Power: <span class="rx-val">Loading...</span>
                                </div>
                                <div style="margin-top:5px; display:flex; gap:5px; flex-wrap:wrap;">
                                    <a href="https://www.google.com/maps/dir/?api=1&destination=${o.lat},${o.lng}" target="_blank" class="popup-btn" style="background:#444; color:white; text-decoration:none; padding:5px 10px; border-radius:4px; font-size:0.8rem;">Navigasi</a>
                                    <button class="popup-btn" style="background:#007bff; color:white; border:none; padding:5px 10px; border-radius:4px; font-size:0.8rem; cursor:pointer;" onclick="openLocationModal('${o.serial_number}', '${o.name}', ${o.lat}, ${o.lng})">Lokasi</button>
                                    <button class="popup-btn" style="background:#00f5ff; color:black; border:none; padding:5px 10px; border-radius:4px; font-size:0.8rem; cursor:pointer;" onclick="window.location.href='../devices/manage.php?serial=${o.serial_number}'">Atur Alat</button>
                                </div>
                            </div>
                        `;
                        
                        var marker = L.marker([o.lat, o.lng], {icon: markerIcon})
                         .addTo(map);
                         
                        marker.on('click', function() {
                            openOnuDetail(o);
                        });
                         
                        // Draw line to ODP if exists
                        if (o.odp_id && result.odps) {
                             const odp = result.odps.find(od => od.id == o.odp_id);
                             if (odp && odp.lat && odp.lng) {
                                L.polyline([[odp.lat, odp.lng], [o.lat, o.lng]], {
                                    color: color,
                                    weight: 1,
                                    opacity: 0.5,
                                    dashArray: '5, 5'
                                }).addTo(map);
                             }
                        }
                    }
                }
            })
            .catch(err => console.error('Error fetching map data:', err));
        
        // ONU Detail Modal Functions
        var currentDetailOnu = null;

        function openOnuDetail(onu) {
            currentDetailOnu = onu;
            
            document.getElementById('onuDetailModal').style.display = 'flex';
            document.getElementById('detailName').textContent = onu.name || '-';
            document.getElementById('detailSerial').textContent = onu.serial_number;
            
            var isOnline = onu.status === 'online';
            var color = isOnline ? 'var(--success)' : (onu.status === 'offline' ? 'var(--danger)' : 'var(--text-secondary)');
            document.getElementById('detailStatus').innerHTML = `<span style="color:${color}; font-weight:bold">${onu.status || 'Unknown'}</span>`;
            
            // Set Nav Link
            document.getElementById('navLink').href = `https://www.google.com/maps/dir/?api=1&destination=${onu.lat},${onu.lng}`;
            
            // Fetch detailed info
            document.getElementById('detailRx').textContent = 'Loading...';
            document.getElementById('detailIp').textContent = '-';
            document.getElementById('detailLastInform').textContent = '-';
            
            fetch(`../../api/onu_wifi.php?pppoe_username=${onu.serial_number}`)
            .then(res => res.json())
            .then(data => {
                if(data.success && data.data) {
                    var info = data.data;
                    
                    // RX Power
                    if(info.signal) {
                        var sig = parseFloat(info.signal);
                        var sigColor = sig > -25 ? 'var(--success)' : (sig > -28 ? 'orange' : 'var(--danger)');
                        document.getElementById('detailRx').innerHTML = `<span style="color:${sigColor}; font-weight:bold">${info.signal} dBm</span>`;
                    } else {
                        document.getElementById('detailRx').textContent = '-';
                    }
                    
                    // IP & Last Inform (Assuming API returns these, if not we fallback)
                    // The current onu_wifi.php returns limited data. 
                    // If we want more details we might need to enhance the API or use what we have.
                    // For now let's use what we have.
                    
                    // Note: onu_wifi.php primarily returns wifi info and signal.
                    // If we want IP and Last Inform, we might need to fetch from genieacs.php
                    
                } else {
                    document.getElementById('detailRx').textContent = '-';
                }
            })
            .catch(err => {
                document.getElementById('detailRx').textContent = 'Error';
            });
            
            // Fetch more details from GenieACS API directly if needed
            fetch(`../../api/genieacs.php?action=get_device&id=${onu.serial_number}`)
            .then(res => res.json())
            .then(data => {
                if(data.success && data.data) {
                    var d = data.data;
                    document.getElementById('detailIp').textContent = d.ip_address || '-';
                    document.getElementById('detailLastInform').textContent = d.last_inform ? formatTimeAgo(d.last_inform) : '-';
                }
            });
        }
        
        function closeOnuDetailModal() {
            document.getElementById('onuDetailModal').style.display = 'none';
        }
        
        function goToManage() {
            if(currentDetailOnu) {
                // If we have serial number, use it. If not, fallback to username (for customers)
                // Actually currentDetailOnu.serial_number is populated for both cases in openOnuDetail
                window.location.href = `../devices/manage.php?serial=${currentDetailOnu.serial_number}`;
            }
        }
        
        function openLocationModalFromDetail() {
            if(currentDetailOnu) {
                closeOnuDetailModal();
                openLocationModal(currentDetailOnu.serial_number, currentDetailOnu.name, currentDetailOnu.lat, currentDetailOnu.lng);
            }
        }
        
        function formatTimeAgo(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            
            if (diff < 60) return diff + ' detik lalu';
            if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
            if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
            return date.toLocaleDateString('id-ID') + ' ' + date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        }

        // User Location Runtime Monitoring
        var userMarker = null;
        var watchId = null;
        var isFirstLocation = true; // Auto-center trigger

        if ("geolocation" in navigator) {
            watchId = navigator.geolocation.watchPosition(function(position) {
                var lat = position.coords.latitude;
                var lng = position.coords.longitude;
                var acc = position.coords.accuracy;
                
                if (userMarker) {
                    userMarker.setLatLng([lat, lng]);
                    userMarker.getPopup().setContent("Lokasi Teknisi Saat Ini<br>Akurasi: " + Math.round(acc) + "m");
                } else {
                    var techIcon = L.divIcon({
                        className: 'tech-marker',
                        html: '<div style="background: var(--primary); width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 15px var(--primary);"></div>',
                        iconSize: [14, 14],
                        iconAnchor: [7, 7]
                    });
                    
                    userMarker = L.marker([lat, lng], {icon: techIcon, zIndexOffset: 1000})
                        .addTo(map)
                        .bindPopup("Lokasi Teknisi Saat Ini<br>Akurasi: " + Math.round(acc) + "m");
                }
                
                // Auto Center Map to Technician natively on first location hit synchronously
                if (isFirstLocation) {
                    map.setView([lat, lng], 15);
                    isFirstLocation = false;
                }
                
            }, function(error) {
                console.warn("Live Geolocation disabled: " + error.message);
            }, {
                enableHighAccuracy: true,
                maximumAge: 10000,
                timeout: 5000
            });
        }

        function locateUser() {
            if (userMarker) {
                map.setView(userMarker.getLatLng(), 17);
            } else {
                map.locate({setView: true, maxZoom: 17});
            }
        }

        map.on('locationfound', function(e) {
            // Backup locator if watchPosition fails
            if (!userMarker) {
                var techIcon = L.divIcon({
                    className: 'tech-marker',
                    html: '<div style="background: var(--primary); width: 14px; height: 14px; border-radius: 50%; border: 2px solid white; box-shadow: 0 0 15px var(--primary);"></div>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7]
                });
                
                userMarker = L.marker(e.latlng, {icon: techIcon, zIndexOffset: 1000}).addTo(map)
                    .bindPopup("Lokasi Teknisi (Fallback)").openPopup();
            }
        });

        // Modal Functions
        let locMap, locMarker;

        function openLocationModal(serial, name, lat, lng) {
            document.getElementById('locationModal').style.display = 'flex';
            document.getElementById('locSerial').value = serial;
            document.getElementById('locName').value = name;
            
            // Default coords if empty
            if(!lat) lat = -6.200000;
            if(!lng) lng = 106.816666;
            
            setTimeout(() => {
                if(locMap) {
                    locMap.remove();
                    locMap = null;
                }
                
                locMap = L.map('locMap').setView([lat, lng], 16);
                L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}',{
                    maxZoom: 20,
                    subdomains:['mt0','mt1','mt2','mt3']
                }).addTo(locMap);
                
                locMarker = L.marker([lat, lng], {draggable: true}).addTo(locMap);
                
                document.getElementById('locLat').value = lat;
                document.getElementById('locLng').value = lng;
                
                locMarker.on('dragend', function(e) {
                    var pos = locMarker.getLatLng();
                    document.getElementById('locLat').value = pos.lat;
                    document.getElementById('locLng').value = pos.lng;
                });
                
                locMap.on('click', function(e) {
                    locMarker.setLatLng(e.latlng);
                    document.getElementById('locLat').value = e.latlng.lat;
                    document.getElementById('locLng').value = e.latlng.lng;
                });
            }, 200);
        }

        function closeLocationModal() {
            document.getElementById('locationModal').style.display = 'none';
        }

        function updateLocMarker() {
            const lat = parseFloat(document.getElementById('locLat').value);
            const lng = parseFloat(document.getElementById('locLng').value);
            
            if (!isNaN(lat) && !isNaN(lng) && locMarker) {
                locMarker.setLatLng([lat, lng]);
                locMap.setView([lat, lng], 16);
            }
        }

        function saveLocation() {
            const serial = document.getElementById('locSerial').value;
            const name = document.getElementById('locName').value;
            const lat = document.getElementById('locLat').value;
            const lng = document.getElementById('locLng').value;
            
            fetch('../../api/onu_locations.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    serial: serial,
                    name: name,
                    lat: lat,
                    lng: lng
                })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    alert('Lokasi berhasil disimpan!');
                    location.reload();
                } else {
                    alert('Gagal: ' + data.message);
                }
            })
            .catch(err => alert('Error koneksi'));
        }

        function openDeviceModal(username, name) {
            document.getElementById('deviceModal').style.display = 'flex';
            document.getElementById('modalCustomerName').innerText = name;
            document.getElementById('modalUsername').value = username;
            
            // Reset & Load
            document.getElementById('modalSsid').value = "Loading...";
            document.getElementById('modalPassword').value = "Loading...";
            document.getElementById('modalStatus').innerText = "Menghubungkan ke perangkat...";
            
            loadDeviceData(username);
        }
        
        function closeModal() {
            document.getElementById('deviceModal').style.display = 'none';
        }
        
        function loadDeviceData(username) {
            fetch(`../../api/onu_wifi.php?pppoe_username=${username}`)
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    document.getElementById('modalSsid').value = data.data.ssid || '';
                    document.getElementById('modalPassword').value = data.data.password || '';
                    
                    const signal = data.data.signal || '-';
                    const status = data.data.status;
                    const color = status === 'Online' ? 'green' : 'red';
                    
                    document.getElementById('modalStatus').innerHTML = `
                        Status: <span style="color:${color}; font-weight:bold">${status}</span> | 
                        Signal: <b>${signal} dBm</b>
                    `;
                } else {
                    document.getElementById('modalStatus').innerText = "Gagal: " + data.message;
                    document.getElementById('modalSsid').value = "";
                    document.getElementById('modalPassword').value = "";
                }
            })
            .catch(err => {
                document.getElementById('modalStatus').innerText = "Error koneksi API";
            });
        }
        
        function saveWifi() {
            const username = document.getElementById('modalUsername').value;
            const ssid = document.getElementById('modalSsid').value;
            const password = document.getElementById('modalPassword').value;
            
            if(!ssid || !password) return alert("SSID & Password wajib diisi");
            
            if(!confirm("Simpan perubahan WiFi?")) return;
            
            document.getElementById('modalStatus').innerText = "Menyimpan...";
            
            fetch('../../api/onu_wifi.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `pppoe_username=${username}&ssid=${encodeURIComponent(ssid)}&password=${encodeURIComponent(password)}`
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    alert("Berhasil disimpan!");
                    document.getElementById('modalStatus').innerText = "Perubahan tersimpan.";
                } else {
                    alert("Gagal: " + data.message);
                    document.getElementById('modalStatus').innerText = "Gagal menyimpan.";
                }
            })
            .catch(err => alert("Error koneksi"));
        }
        
        function rebootDevice() {
            const username = document.getElementById('modalUsername').value;
            if(!confirm("Reboot perangkat ini? Internet pelanggan akan mati sebentar.")) return;
            
            fetch('../../api/genieacs.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=reboot&device_id=${username}`
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
            })
            .catch(err => alert("Gagal mengirim perintah reboot"));
        }
        
        function generatePass() {
            const chars = "0123456789";
            let pass = "";
            for(let i=0; i<8; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('modalPassword').value = pass;
        }

        // Auto locate on load
        locateUser();
    </script>

    <?php require_once '../includes/bottom_nav.php'; ?>
</body>
</html>
