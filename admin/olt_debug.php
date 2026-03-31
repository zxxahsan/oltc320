<?php
/**
 * OLT Config Parser Tool (V8.22)
 * Parse running-config secara OFFLINE - Zero Timeout Risk
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/db.php';

function vsolParseRunningConfig($config_text) {
    $onus = [];
    $current_gpon = null;
    $lines = explode("\n", str_replace("\r", "", $config_text));

    foreach ($lines as $line) {
        $line = trim($line);

        // Detect current GPON interface
        if (preg_match('/^interface gpon 0\/(\d+)/i', $line, $m)) {
            $current_gpon = (int)$m[1];
            continue;
        }

        // Reset context on top-level exit
        if ($line === '!' && $current_gpon !== null) {
            // Don't reset yet, description lines come after onu add
        }

        if ($current_gpon === null) continue;

        // Match: onu add <id> profile <x> sn <SN>
        if (preg_match('/^onu add (\d+) profile \S+ sn ([A-Z0-9]+)/i', $line, $m)) {
            $key = $current_gpon . ':' . (int)$m[1];
            $onus[$key] = [
                'port'   => $current_gpon,
                'id'     => (int)$m[1],
                'sn'     => strtoupper($m[2]),
                'desc'   => '',
                'profile'=> '',
            ];
        }

        // Match: onu <id> desc <description>
        if (preg_match('/^onu (\d+) desc (.+)/i', $line, $m)) {
            $key = $current_gpon . ':' . (int)$m[1];
            if (isset($onus[$key])) {
                $onus[$key]['desc'] = trim($m[2]);
            }
        }

        // Match: onu <id> profile line name <profile>
        if (preg_match('/^onu (\d+) profile line name (.+)/i', $line, $m)) {
            $key = $current_gpon . ':' . (int)$m[1];
            if (isset($onus[$key])) {
                $onus[$key]['profile'] = trim($m[2]);
            }
        }
    }

    return array_values($onus);
}

// Handle AJAX parse request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['config_text'])) {
    header('Content-Type: application/json');
    $results = vsolParseRunningConfig($_POST['config_text']);
    echo json_encode(['count' => count($results), 'data' => $results]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Config Parser v8.22</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0c0c0c; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; padding: 24px; }
        h1 { color: #00d2ff; margin-bottom: 8px; font-size: 22px; }
        p.sub { color: #888; margin-bottom: 20px; font-size: 14px; }
        .card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        textarea {
            width: 100%; height: 220px; background: #111; border: 1px solid #333;
            color: #00ff41; font-family: 'Consolas', monospace; font-size: 13px;
            padding: 12px; border-radius: 6px; resize: vertical;
        }
        .btn {
            background: #00d2ff; color: #000; border: none; padding: 12px 28px;
            border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 15px;
            margin-top: 12px; transition: 0.2s;
        }
        .btn:hover { background: #00b8d9; transform: translateY(-1px); }
        .stats { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
        .stat-box { background: #222; border-radius: 8px; padding: 12px 20px; text-align: center; min-width: 120px; }
        .stat-box .num { font-size: 28px; font-weight: bold; color: #00d2ff; }
        .stat-box .lbl { font-size: 12px; color: #888; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #222; color: #888; padding: 10px 14px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
        td { padding: 10px 14px; border-bottom: 1px solid #222; }
        tr:hover td { background: #1f1f1f; }
        .sn { color: #00d2ff; font-family: monospace; font-weight: bold; }
        .port-badge { background: #1a3a4a; color: #00d2ff; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        #results-section { display: none; }
        .search-box { background: #111; border: 1px solid #333; color: #fff; padding: 10px 14px; border-radius: 6px; width: 100%; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
    <h1><i class="fas fa-satellite-dish"></i> OLT Config Parser</h1>
    <p class="sub">Paste teks <code>show running-config</code> dari OLT Bapak di bawah ini. Parser akan membaca semua ONU secara offline — tanpa perlu koneksi Telnet.</p>

    <div class="card">
        <label style="color:#aaa; font-size:13px; display:block; margin-bottom:8px;">
            <i class="fas fa-paste"></i> Paste isi <b>running-config</b> OLT di sini:
        </label>
        <textarea id="config_input" placeholder="Paste hasil 'show running-config' dari terminal OLT Bapak di sini..."></textarea>
        <br>
        <button class="btn" onclick="parseConfig()">
            <i class="fas fa-cogs"></i> PARSE SEKARANG
        </button>
    </div>

    <div id="results-section">
        <div class="stats">
            <div class="stat-box"><div class="num" id="total_onu">0</div><div class="lbl">Total ONU</div></div>
            <div class="stat-box"><div class="num" id="total_ports">0</div><div class="lbl">Port GPON Aktif</div></div>
            <div class="stat-box"><div class="num" id="total_with_desc">0</div><div class="lbl">ONU Dengan Nama</div></div>
        </div>

        <div class="card">
            <input class="search-box" type="text" id="search_box" placeholder="Cari SN, nama pelanggan, port..." oninput="filterTable()">
            <div style="overflow-x:auto;">
                <table id="onu_table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Port GPON</th>
                            <th>ONU ID</th>
                            <th>Serial Number</th>
                            <th>Nama Pelanggan</th>
                            <th>Profil</th>
                        </tr>
                    </thead>
                    <tbody id="onu_tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        let allData = [];

        async function parseConfig() {
            const config = document.getElementById('config_input').value.trim();
            if (!config) { alert('Silakan paste running-config terlebih dahulu!'); return; }

            const fd = new FormData();
            fd.append('config_text', config);

            try {
                const r = await fetch(window.location.href, { method: 'POST', body: fd });
                const json = await r.json();
                allData = json.data;
                renderTable(allData);
                document.getElementById('results-section').style.display = 'block';
                document.getElementById('results-section').scrollIntoView({ behavior: 'smooth' });
            } catch(e) {
                alert('Error: ' + e.message);
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('onu_tbody');
            tbody.innerHTML = '';
            const ports = new Set();
            let withDesc = 0;

            data.forEach((item, i) => {
                ports.add(item.port);
                if (item.desc) withDesc++;
                tbody.innerHTML += `<tr>
                    <td style="color:#555">${i + 1}</td>
                    <td><span class="port-badge">GPON 0/${item.port}</span></td>
                    <td style="color:#aaa">${item.id}</td>
                    <td class="sn">${item.sn}</td>
                    <td>${item.desc || '<span style="color:#555">-</span>'}</td>
                    <td style="color:#888; font-size:13px">${item.profile || '-'}</td>
                </tr>`;
            });

            document.getElementById('total_onu').textContent = data.length;
            document.getElementById('total_ports').textContent = ports.size;
            document.getElementById('total_with_desc').textContent = withDesc;
        }

        function filterTable() {
            const q = document.getElementById('search_box').value.toLowerCase();
            const filtered = allData.filter(item =>
                item.sn.toLowerCase().includes(q) ||
                item.desc.toLowerCase().includes(q) ||
                String(item.port).includes(q) ||
                String(item.id).includes(q)
            );
            renderTable(filtered);
        }
    </script>
</body>
</html>
