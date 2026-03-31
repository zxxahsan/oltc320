<?php
require_once '../includes/auth.php';
requireAdminLogin();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Config Parser v8.23</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0c0c0c; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; padding: 24px; }
        h1 { color: #00d2ff; margin-bottom: 6px; font-size: 22px; }
        p.sub { color: #888; margin-bottom: 20px; font-size: 14px; }
        code { background: #222; padding: 2px 6px; border-radius: 4px; color: #00ff41; font-size: 13px; }
        .card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        textarea { width:100%; height:200px; background:#111; border:1px solid #333; color:#00ff41; font-family:'Consolas',monospace; font-size:13px; padding:12px; border-radius:6px; resize:vertical; outline:none; }
        .btn { background:#00d2ff; color:#000; border:none; padding:12px 32px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:15px; margin-top:12px; transition:0.2s; }
        .btn:hover { background:#00b8d9; }
        .stats { display:flex; gap:14px; margin-bottom:16px; flex-wrap:wrap; }
        .stat-box { background:#222; border-radius:8px; padding:12px 20px; text-align:center; min-width:110px; }
        .stat-box .num { font-size:26px; font-weight:bold; color:#00d2ff; }
        .stat-box .lbl { font-size:11px; color:#888; margin-top:4px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th { background:#222; color:#888; padding:10px 14px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:1px; }
        td { padding:10px 14px; border-bottom:1px solid #1e1e1e; }
        tr:hover td { background:#1f1f1f; }
        .sn { color:#00d2ff; font-family:monospace; font-weight:bold; letter-spacing:1px; }
        .port-badge { background:#1a3a4a; color:#00d2ff; padding:2px 8px; border-radius:4px; font-size:12px; }
        #results-section { display:none; }
        .search-box { background:#111; border:1px solid #333; color:#fff; padding:10px 14px; border-radius:6px; width:100%; margin-bottom:14px; font-size:14px; outline:none; }
        .msg { padding:12px 16px; border-radius:6px; margin-top:10px; font-size:14px; display:none; }
        .msg.err { color:#ff6b6b; background:#2a1a1a; border:1px solid #5a2a2a; }
        .msg.info { color:#aaa; background:#1a1a2a; border:1px solid #2a2a5a; }
        .debug { background:#111; border:1px solid #333; border-radius:6px; padding:10px; margin-top:10px; font-size:12px; color:#666; font-family:monospace; display:none; }
    </style>
</head>
<body>
    <h1><i class="fas fa-satellite-dish"></i> OLT Config Parser <span style="font-size:13px;color:#555">v8.23</span></h1>
    <p class="sub">Ketik <code>show running-config</code> di terminal OLT → Copy semua output → Paste di bawah → Klik Parse.</p>

    <div class="card">
        <label style="color:#aaa;font-size:13px;display:block;margin-bottom:8px;">
            <i class="fas fa-paste"></i> Paste output <b>show running-config</b> di sini:
        </label>
        <textarea id="config_input" placeholder="Paste disini..."></textarea>
        <div class="msg err" id="err_box"></div>
        <div class="debug" id="debug_box"></div>
        <button class="btn" onclick="parseConfig()">
            <i class="fas fa-cogs"></i> PARSE SEKARANG
        </button>
    </div>

    <div id="results-section">
        <div class="stats">
            <div class="stat-box"><div class="num" id="total_onu">0</div><div class="lbl">Total ONU</div></div>
            <div class="stat-box"><div class="num" id="total_ports">0</div><div class="lbl">Port Aktif</div></div>
            <div class="stat-box"><div class="num" id="total_desc">0</div><div class="lbl">Ada Nama</div></div>
        </div>
        <div class="card">
            <input class="search-box" type="text" id="search_box" placeholder="Cari SN, nama, port..." oninput="filterTable()">
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>#</th><th>Port GPON</th><th>ID</th>
                            <th>Serial Number</th><th>Nama Pelanggan</th><th>Profil</th>
                        </tr>
                    </thead>
                    <tbody id="onu_tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

<script>
let allData = [];

function parseConfig() {
    // Hide previous results
    document.getElementById('err_box').style.display = 'none';
    document.getElementById('debug_box').style.display = 'none';
    document.getElementById('results-section').style.display = 'none';

    const rawText = document.getElementById('config_input').value;
    if (!rawText.trim()) {
        showMsg('err', 'Silakan paste running-config terlebih dahulu!');
        return;
    }

    // === CLEAN THE TEXT ===
    // 1. Remove ANSI escape codes
    let text = rawText.replace(/\x1b\[[0-9;?]*[a-zA-Z]/g, '');
    // 2. Remove telnet IAC sequences
    text = text.replace(/[\xFF][\xFB-\xFF]./g, '');
    // 3. Normalize line endings
    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

    const lines = text.split('\n');
    const debugInfo = {
        totalLines: lines.length,
        gponFound: [],
        onuAddFound: 0
    };

    const onus = {};
    let currentPort = null;

    for (let raw of lines) {
        // Clean each line
        const line = raw.replace(/\x1b\[[0-9;?]*[a-zA-Z]/g, '').trim();
        if (!line) continue;

        // Detect GPON interface — flexible match anywhere in line
        const ifGpon = line.match(/interface\s+gpon\s+0\/(\d+)/i);
        if (ifGpon) {
            currentPort = parseInt(ifGpon[1]);
            if (!debugInfo.gponFound.includes(currentPort)) debugInfo.gponFound.push(currentPort);
            continue;
        }

        // Reset on other interface types
        if (/^\s*interface\s+[a-z]/i.test(line) && !/gpon/i.test(line)) {
            currentPort = null;
            continue;
        }

        if (currentPort === null) continue;

        // onu add <id> profile <name> sn <SN>
        const addM = line.match(/onu\s+add\s+(\d+)\s+profile\s+\S+\s+sn\s+([A-Za-z0-9]+)/i);
        if (addM) {
            const key = currentPort + ':' + addM[1];
            onus[key] = {
                port: currentPort,
                id: parseInt(addM[1]),
                sn: addM[2].toUpperCase(),
                desc: '',
                profile: ''
            };
            debugInfo.onuAddFound++;
            continue;
        }

        // onu <id> desc <text>
        const descM = line.match(/onu\s+(\d+)\s+desc\s+(.+)/i);
        if (descM) {
            const key = currentPort + ':' + descM[1];
            if (onus[key]) onus[key].desc = descM[2].trim();
            continue;
        }

        // onu <id> profile line name <name>
        const profM = line.match(/onu\s+(\d+)\s+profile\s+line\s+name\s+(.+)/i);
        if (profM) {
            const key = currentPort + ':' + profM[1];
            if (onus[key]) onus[key].profile = profM[2].trim();
            continue;
        }
    }

    // === DEBUG OUTPUT ===
    const dbg = document.getElementById('debug_box');
    dbg.textContent = `Debug: ${debugInfo.totalLines} baris terbaca | `
        + `GPON port ditemukan: [${debugInfo.gponFound.join(', ')}] | `
        + `Baris "onu add": ${debugInfo.onuAddFound}`;
    dbg.style.display = 'block';

    allData = Object.values(onus).sort((a,b) => a.port - b.port || a.id - b.id);

    if (allData.length === 0) {
        showMsg('err', `Tidak ada ONU ditemukan. (${debugInfo.totalLines} baris dibaca, port GPON: [${debugInfo.gponFound.join(',')||'tidak ada'}]). Pastikan teks lengkap dari "show running-config" ada di atas.`);
        return;
    }

    renderTable(allData);
    document.getElementById('results-section').style.display = 'block';
    document.getElementById('results-section').scrollIntoView({ behavior: 'smooth' });
}

function showMsg(type, msg) {
    const el = document.getElementById('err_box');
    el.className = 'msg ' + type;
    el.textContent = msg;
    el.style.display = 'block';
}

function renderTable(data) {
    const tbody = document.getElementById('onu_tbody');
    tbody.innerHTML = '';
    const ports = new Set();
    let withDesc = 0;

    data.forEach((o, i) => {
        ports.add(o.port);
        if (o.desc) withDesc++;
        tbody.innerHTML += `<tr>
            <td style="color:#555">${i+1}</td>
            <td><span class="port-badge">GPON 0/${o.port}</span></td>
            <td style="color:#888">${o.id}</td>
            <td class="sn">${o.sn}</td>
            <td>${o.desc || '<span style="color:#444">-</span>'}</td>
            <td style="color:#888;font-size:12px">${o.profile || '-'}</td>
        </tr>`;
    });

    document.getElementById('total_onu').textContent  = data.length;
    document.getElementById('total_ports').textContent = ports.size;
    document.getElementById('total_desc').textContent  = withDesc;
}

function filterTable() {
    const q = document.getElementById('search_box').value.toLowerCase();
    renderTable(allData.filter(o =>
        o.sn.toLowerCase().includes(q) ||
        o.desc.toLowerCase().includes(q) ||
        String(o.port).includes(q) ||
        String(o.id).includes(q) ||
        o.profile.toLowerCase().includes(q)
    ));
}
</script>
</body>
</html>
