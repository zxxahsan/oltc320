<?php
/**
 * OLT Precision Terminal & Metadata Scraper (V8.17)
 * Stateful Mode + Auto Sync Metadata
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

session_start();

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

// Handle AJAX Actions
if (isset($_GET['action'])) {
    $olt_id = (int)$_GET['olt_id'];
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    
    if ($_GET['action'] == 'sync_metadata') {
        $results = vsolSyncAllMetadata($olt_id);
        header('Content-Type: application/json');
        echo json_encode($results);
        exit;
    }

    if ($_GET['action'] == 'terminal') {
        $cmd = trim($_GET['cmd']);
        $line_ending = $_GET['ending'] ?? 'n';
        $eol = ($line_ending == 'rn') ? "\r\n" : "\n";
        
        $client = new OltTelnetClient($olt['host'], $olt['port']);
        try {
            $client->connect($olt['username'], $olt['password']);
            if (!empty($olt['enable_password'])) $client->enable($olt['enable_password']);
            
            $client->write($cmd . $eol);
            $res = $client->readUntil("/(\r\n|\n|^)[^>\r\n#]*[>#]\s*$/", 8);
            echo $res;
            $client->disconnect();
        } catch (Exception $e) { echo "Error: " . $e->getMessage(); }
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Metadata Autopilot v8.17</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #0c0c0c; color: #00ff41; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .container { max-width: 1200px; margin: auto; }
        .card { background: #1a1a1a; border-radius: 10px; padding: 20px; border: 1px solid #333; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        h2 { color: #00ff41; border-bottom: 1px solid #333; padding-bottom: 10px; margin-top: 0; }
        
        /* Terminal Style */
        #terminal { background: #000; height: 400px; overflow-y: auto; padding: 15px; border: 1px solid #00ff4133; font-family: 'Consolas', monospace; white-space: pre-wrap; margin-bottom: 15px; }
        .input-group { display: flex; gap: 10px; background: #222; padding: 10px; border-radius: 5px; }
        input[type="text"] { flex: 1; background: transparent; border: none; color: #00ff41; font-size: 16px; outline: none; }
        
        /* Table Style */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #111; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #333; }
        th { background: #222; color: #888; text-transform: uppercase; font-size: 12px; }
        
        .btn { background: #238636; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; transition: 0.3s; }
        .btn:hover { background: #2ea043; transform: translateY(-2px); }
        .btn-sync { background: #00d2ff; color: #000; }
        .loading { display: none; margin-left: 10px; color: #ffcc00; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2><i class="fas fa-satellite-dish"></i> OLT Metadata Sync (v8.17)</h2>
            <p style="color: #888;">Klik tombol di bawah untuk menarik pendaftaran semua metadata pelanggan langsung dari `running-config` OLT.</p>
            <div style="display: flex; align-items: center;">
                <select id="olt_id" style="background: #222; border: 1px solid #444; color: #fff; padding: 10px; border-radius: 5px;">
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sync" style="margin-left:10px;" onclick="syncMetadata()">
                    <i class="fas fa-sync-alt"></i> SYNC ALL METADATA
                </button>
                <span id="sync_status" class="loading"><i class="fas fa-spinner fa-spin"></i> Sedang menarik data OLT... Mohon tunggu.</span>
            </div>

            <div id="sync_results" style="display:none;">
                <table>
                    <thead>
                        <tr>
                            <th>Port</th>
                            <th>ONU ID</th>
                            <th>SN</th>
                            <th>Deskripsi pelanggan</th>
                            <th>Status pendaftarannya</th>
                        </tr>
                    </thead>
                    <tbody id="metadata_body"></tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-terminal"></i> Live Session Terminal</h2>
            <div id="terminal">--- Precision Session Terminal Active ---</div>
            <div class="input-group">
                <select id="line_ending" style="background:transparent; border:none; color:#888; cursor:pointer;">
                    <option value="n">LF (\n)</option>
                    <option value="rn">CRLF (\r\n)</option>
                </select>
                <span style="color: #00ff41;">#</span>
                <input type="text" id="cmd_input" placeholder="Ketik perintah (Enter)...">
                <button class="btn" onclick="sendCmd()">ENTER</button>
            </div>
        </div>
    </div>

    <script>
        const term = document.getElementById('terminal');
        const input = document.getElementById('cmd_input');
        const olt_id = document.getElementById('olt_id');
        const ending = document.getElementById('line_ending');
        const syncStatus = document.getElementById('sync_status');

        async function syncMetadata() {
            syncStatus.style.display = 'inline-block';
            document.getElementById('sync_results').style.display = 'none';
            
            try {
                const r = await fetch(`?action=sync_metadata&olt_id=${olt_id.value}`);
                const data = await r.json();
                
                if (data.error) {
                    alert("Error: " + data.error);
                } else {
                    const tbody = document.getElementById('metadata_body');
                    tbody.innerHTML = '';
                    data.forEach(item => {
                        tbody.innerHTML += `<tr>
                            <td>GPON 0/${item.port}</td>
                            <td>${item.id}</td>
                            <td><b style="color:#00d2ff">${item.sn}</b></td>
                            <td>${item.desc}</td>
                            <td><span style="color:#00ff41">${item.status}</span></td>
                        </tr>`;
                    });
                    document.getElementById('sync_results').style.display = 'block';
                }
            } catch (e) {
                alert("Koneksi OLT sibuk atau timeout.");
            } finally {
                syncStatus.style.display = 'none';
            }
        }

        async function sendCmd() {
            const cmd = input.value.trim();
            if(!cmd) return;
            term.innerText += "\n# " + cmd;
            input.value = '';
            input.disabled = true;

            try {
                const r = await fetch(`?action=terminal&cmd=${encodeURIComponent(cmd)}&olt_id=${olt_id.value}&ending=${ending.value}`);
                const t = await r.text();
                term.innerText += "\n" + t;
                term.scrollTop = term.scrollHeight;
            } catch(e) {
                term.innerText += "\nError: Timeout.";
            } finally {
                input.disabled = false;
                input.focus();
            }
        }

        input.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendCmd(); });
    </script>
</body>
</html>
