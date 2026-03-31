<?php
/**
 * OLT Sniper Tool (V8.11)
 * Optimized for V-SOL Firmware V1.3.9R
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

function live_echo($msg, $type = 'info') {
    $colors = ['info' => '#00d2ff', 'success' => '#39FF14', 'error' => '#ff4b2b', 'warn' => '#ffc107', 'cmd' => '#ffffff'];
    $color = $colors[$type] ?? '#fff';
    $msg = addslashes($msg);
    echo "<script>appendLog('$msg', '$color');</script>\n";
    if (ob_get_level() > 0) ob_flush();
    flush(); 
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Sniper v8.11</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #0a0e14; color: #b0b8c1; font-family: 'Consolas', monospace; padding: 20px; }
        .card { background: #1c232d; border-radius: 8px; padding: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.8); max-width: 1000px; margin: auto; border: 1px solid #30363d; }
        .console { background: #0d1117; color: #58a6ff; padding: 15px; border-radius: 6px; height: 300px; overflow-y: auto; border: 1px solid #30363d; margin-top: 15px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; background: #238636; color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #30363d; color: #8b949e; margin-left: 10px; }
        select, input[type="text"] { padding: 10px; background: #0d1117; color: #c9d1d9; border: 1px solid #30363d; border-radius: 6px; outline: none; }
        textarea { width: 100%; height: 400px; background: #0d1117; color: #79c0ff; border: 1px solid #30363d; padding: 10px; border-radius: 6px; font-family: 'Consolas', monospace; font-size: 12px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#58a6ff; margin-top:0;"><i class="fas fa-bullseye"></i> OLT Sniper v8.11</h2>
        <p style="color:#8b949e;">Firmware v1.3.9R Terdeteksi. Menjalankan pendaftaran pendaftarannya (X_X)... Menjalankan misi pencarian khusus.</p>
        
        <form method="POST" id="diagForm">
            <div style="display: flex; gap: 10px; align-items: center;">
                <select name="olt_id" required>
                    <option value="">-- PILIH TARGET --</option>
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>" <?php echo (isset($_POST['olt_id']) && $_POST['olt_id'] == $o['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($o['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="manual_cmd" placeholder="Tembak manual ke OLT..." style="flex:1;">
                <button type="submit" class="btn">Tembak!</button>
                <a href="customers.php" class="btn btn-outline">Batal</a>
            </div>
        </form>

        <div class="console" id="console-logs">> Sniper Siaga. Siap membidik ONU pendaftaran pendaftarannya pendaftaran...</div>

        <h4 style="color: #58a6ff; margin-bottom: 5px; margin-top:20px;">HASIL TEMBAKAN (RAW):</h4>
        <textarea id="rawOutput" readonly></textarea>
    </div>

    <script>
        const logs = document.getElementById('console-logs');
        const raw = document.getElementById('rawOutput');
        function appendLog(msg, color) {
            const d = document.createElement('div');
            if(color) d.style.color = color;
            d.innerHTML = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logs.appendChild(d);
            logs.scrollTop = logs.scrollHeight;
        }
        function updateRaw(val) {
            raw.value += val + "\n";
            raw.scrollTop = raw.scrollHeight;
        }
        document.getElementById('diagForm').onsubmit = function() {
            appendLog("--- MEMULAI OPERASI SNIPER ---", "#58a6ff");
        };
    </script>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['olt_id'])) {
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(true);
        while (ob_get_level()) ob_end_flush();

        $olt_id = (int)$_POST['olt_id'];
        $manual_cmd = trim($_POST['manual_cmd'] ?? '');
        $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);

        if ($selected_olt) {
            $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port'], 2);
            
            try {
                if (!$client->connect($selected_olt['username'], $selected_olt['password'])) throw new Exception("Zonk. Gagal Login.");
                live_echo("Target Terkoneksi.", 'success');
                if (!empty($selected_olt['enable_password'])) $client->enable($selected_olt['enable_password']);

                // Target commands for V1.3.9R
                $cmds = $manual_cmd ? [$manual_cmd] : [
                    "show onu unauth", 
                    "show onu uncfg", 
                    "show onu auto-learn",
                    "show onu authentication-list",
                    "show interface gpon 0/1 onu unauth"
                ];
                
                foreach ($cmds as $c) {
                    live_echo("Menembak: <code>$c</code>");
                    $res = $client->execute($c);
                    
                    if (strlen(trim($res)) > 0) {
                        live_echo(">> Respon Masuk!", 'success');
                        $clean_res = addslashes($res);
                        $clean_res = str_replace(array("\r", "\n"), "\\n", $clean_res);
                        echo "<script>updateRaw('--- TARGET: $c ---\\n$clean_res');</script>\n";
                        flush();
                    } else {
                        live_echo(">> Meleset (Kosong).", 'warn');
                    }
                    usleep(150000);
                }

                $client->disconnect();
                live_echo("Misi Selesai.", 'info');

            } catch (Exception $e) {
                live_echo("MISI GAGAL: " . $e->getMessage(), 'error');
            }
        }
    }
    ?>
</body>
</html>
