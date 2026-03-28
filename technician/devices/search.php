<?php
require_once '../../includes/auth.php';
requireTechnicianLogin();

// ==========================================
// AJAX ENDPOINT FOR LIVE SEARCH
// ==========================================
if (isset($_GET['ajax']) && isset($_GET['q'])) {
    header('Content-Type: application/json');
    require_once '../../includes/functions.php';
    require_once '../../includes/mikrotik_api.php';
    
    $search = trim($_GET['q']);
    if (strlen($search) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    
    // 1. Search DB for matching customers generically supporting multi-tier schemas inherently 
    $customers = fetchAll("
        SELECT *
        FROM customers 
        WHERE name LIKE ? OR pppoe_username LIKE ? OR phone LIKE ?
        LIMIT 5
    ", ["%$search%", "%$search%", "%$search%"]);
    
    $output = [];
    foreach ($customers as $r) {
        $isOnline = false;
        $redaman = '-';
        
        $user = trim((string)$r['pppoe_username']);
        
        // 2. Fetch True Online Status from Mikrotik mirroring Verified Customer interface hooks seamlessly avoiding PPP drops
        if (!empty($user)) {
            $rid = $r['router_id'] ?? null;
            $intf = mikrotikGetInterfaceBytesByUsername($user, $rid);
            
            if (!$intf) {
                $routers = fetchAll("SELECT id FROM routers");
                if (empty($routers)) $routers = [['id' => 0]];
                foreach ($routers as $rt) {
                    if ($rt['id'] == $rid) continue;
                    $intf = mikrotikGetInterfaceBytesByUsername($user, $rt['id']);
                    if ($intf) { $isOnline = true; break; }
                }
            } else {
                $isOnline = true;
            }
        }
        
        // 3. Fetch Optical RX Power from GenieACS parsing smart `_tags` integrations natively 
        $device = null;
        if (!empty($r['serial_number'])) $device = genieacsGetDevice($r['serial_number']);
        if (!$device && !empty($r['phone'])) $device = genieacsGetDevice($r['phone']);
        if (!$device && !empty($user)) $device = genieacsGetDevice($user);
        
        if ($device) {
            $rxPower = genieacsGetValue($device, 'VirtualParameters.RXPower');
            if ($rxPower === null) $rxPower = genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RxPower');
            if ($rxPower === null) $rxPower = genieacsGetValue($device, 'Device.Optical.Interface.1.RXPower');
            if ($rxPower === null) $rxPower = genieacsGetValue($device, 'InternetGatewayDevice.WANDevice.1.WANDSLInterfaceConfig.UpstreamAttenuation'); // Random backup
            
            if (is_array($rxPower)) $rxPower = $rxPower['_value'] ?? $rxPower['value'] ?? '-';
            if ($rxPower !== null && $rxPower !== '') $redaman = $rxPower;
        }
        
        $output[] = [
            'name' => $r['name'],
            'username' => $r['pppoe_username'] ?? '-',
            'address' => substr($r['address'] ?? '-', 0, 30),
            'url' => 'manage.php?serial=' . urlencode(!empty($r['serial_number']) ? $r['serial_number'] : (!empty($r['phone']) ? $r['phone'] : $user)),
            'is_online' => $isOnline,
            'redaman' => $redaman
        ];
    }
    
    echo json_encode(['results' => $output]);
    exit;
}

// ==========================================
// REGULAR PAGE RENDER
// ==========================================
$pageTitle = 'Cari Perangkat';
$tech = $_SESSION['technician'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Perangkat - Teknisi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        }
        
        .header {
            background: var(--bg-card);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .back-btn {
            color: var(--text-primary);
            font-size: 1.2rem;
            text-decoration: none;
        }
        
        .container { padding: 20px; }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-control {
            flex: 1;
            padding: 15px 20px;
            background: var(--bg-card);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 10px rgba(0,245,255,0.2);
        }
        
        .search-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.2rem;
        }
        
        .result-card {
            background: var(--bg-card);
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: inherit;
            border: 1px solid rgba(255,255,255,0.05);
            transition: all 0.2s;
        }
        
        .result-card:active {
            transform: scale(0.98);
        }
        
        .result-info h3 { font-size: 1.1rem; margin-bottom: 8px; color: var(--primary); }
        .result-info p { font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 4px; }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
            margin-right: 10px;
        }
        
        .status-online { background: rgba(0,255,136,0.1); color: var(--success); border: 1px solid rgba(0,255,136,0.3); }
        .status-offline { background: rgba(255,71,87,0.1); color: var(--danger); border: 1px solid rgba(255,71,87,0.3); }
        
        .signal-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            display: inline-block;
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .loader {
            display: none;
            text-align: center;
            padding: 20px;
            color: var(--primary);
        }
        
        .loader i {
            animation: spin 1s linear infinite;
            font-size: 2rem;
        }
        
        @keyframes spin { 100% { transform: rotate(360deg); } }
        
        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            margin-top: 50px;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="../dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
        <h2>Cari Perangkat</h2>
    </div>

    <div class="container">
        <div class="search-box">
            <input type="text" id="searchInput" class="form-control" placeholder="Ketik Nama, PPPoE, atau HP..." autocomplete="off">
            <i class="fas fa-search search-icon"></i>
        </div>
        
        <div id="loader" class="loader">
            <i class="fas fa-circle-notch"></i>
            <p style="margin-top: 10px; font-size: 0.9rem; color: var(--text-secondary);">Mencari pelanggan & sinkronisasi ALAT...</p>
        </div>
        
        <div id="emptyState" class="empty-state">
            <i class="fas fa-satellite-dish" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
            <p>Mulai ketik (min 2 huruf) nama, nomor HP, atau id PPPoE pelanggan.</p>
        </div>
        
        <div id="resultsContainer"></div>
    </div>

    <?php require_once '../includes/bottom_nav.php'; ?>
    
    <script>
        const searchInput = document.getElementById('searchInput');
        const resultsContainer = document.getElementById('resultsContainer');
        const loader = document.getElementById('loader');
        const emptyState = document.getElementById('emptyState');
        let typingTimer;
        
        // Auto-focus input for instant typing
        searchInput.focus();
        
        searchInput.addEventListener('input', function() {
            clearTimeout(typingTimer);
            const query = this.value.trim();
            
            if (query.length < 2) {
                resultsContainer.innerHTML = '';
                emptyState.style.display = 'block';
                loader.style.display = 'none';
                return;
            }
            
            // Wait 600ms after user finishes bouncing to avoid crashing MikroTik / ACS API loops
            emptyState.style.display = 'none';
            resultsContainer.innerHTML = '';
            loader.style.display = 'block';
            
            typingTimer = setTimeout(() => {
                fetchDevices(query);
            }, 600);
        });
        
        function fetchDevices(query) {
            fetch(`search.php?ajax=1&q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    loader.style.display = 'none';
                    resultsContainer.innerHTML = '';
                    
                    if (!data.results || data.results.length === 0) {
                        resultsContainer.innerHTML = `
                            <div style="text-align: center; color: var(--text-secondary); margin-top: 30px;">
                                <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p>Pelanggan "${query}" tidak ditemukan.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    data.results.forEach(r => {
                        const statusClass = r.is_online ? 'status-online' : 'status-offline';
                        const statusIcon = r.is_online ? 'fa-check-circle' : 'fa-times-circle';
                        const statusText = r.is_online ? 'Online' : 'Offline';
                        
                        const card = document.createElement('a');
                        card.href = r.url;
                        card.className = 'result-card';
                        card.innerHTML = `
                            <div class="result-info" style="width: 100%;">
                                <h3>${r.name}</h3>
                                <p><i class="fas fa-user-circle"></i> ${r.username}</p>
                                <p style="margin-bottom: 10px;"><i class="fas fa-map-marker-alt"></i> ${r.address}</p>
                                
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <span class="status-badge ${statusClass}">
                                        <i class="fas ${statusIcon}"></i> ${statusText}
                                    </span>
                                    <span class="signal-badge">
                                        <i class="fas fa-signal" style="color: var(--primary);"></i> ${r.redaman} dBm
                                    </span>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right" style="color: var(--text-secondary); font-size: 1.2rem;"></i>
                        `;
                        resultsContainer.appendChild(card);
                    });
                })
                .catch(err => {
                    loader.style.display = 'none';
                    resultsContainer.innerHTML = `<div style="text-align:center; padding: 20px; color: var(--danger);"><i class="fas fa-exclamation-triangle"></i> Gagal terhubung ke API Mikrotik/ACS.</div>`;
                    console.error(err);
                });
        }
    </script>
</body>
</html>
