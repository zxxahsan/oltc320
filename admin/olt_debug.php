<?php
/**
 * OLT Forensic Tool (V8.10)
 * Intelligent detection for OLT Brand and Interface types
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
    <title>OLT Intelligence v8.10</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #0d0d1a; color: #e0e0e0; font-family: 'Segoe UI', Tahoma, sans-serif; padding: 20px; }
        .card { background: #161a2e; border-radius: 12px; padding: 25px; box-shadow: 0 15px 35px rgba(0,0,0,0.7); max-width: 1000px; margin: auto; border: 1px solid #2e3b55; }
        .console { background: #000; color: #39FF14; padding: 15px; border-radius: 8px; font-family: 'Consolas', monospace; height: 300px; overflow-y: auto; border: 1px solid #00d2ff55; margin-top: 15px; line-height: 1.4; border-left: 5px solid #00d2ff; }
        .btn { padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #00d2ff 0%, #3a7bd5 100%); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid #444; color: #888; margin-left: 10px; }
        select, input[type="text"] { padding: 12px; background: #1e2235; color: #fff; border: 1px solid #2e3b55; border-radius: 6px; outline: none; }
        textarea { width: 100%; height: 400px; background: #050505; color: #39FF14; border: 1px solid #2e3b55; padding: 15px; border-radius: 8px; font-family: 'Consolas', monospace; font-size: 13px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00d2ff; margin-top:0;"><i class="fas fa-search-plus"></i> OLT Intelligence v8.10</h2>
        <p style="color:#888;">Eksperimen pendaftaran pendaftarannya (Oops!) ... Deteksi otomatis tipe OLT dan perintah yang didukung.</p>
        
        <form method="POST">
            <div style="display: flex; gap: 10px; align-items: center;">
                <select name="olt_id" required>
                    <option value="">-- Pilih OLT --</option>
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>" <?php echo (isset($_POST['olt_id']) && $_POST['olt_id'] == $o['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($o['name']); ?> (<?php echo $o['host']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="manual_cmd" placeholder="Cth: show version" style="flex:1;">
                <button type="submit" class="btn btn-primary">DETECT OLT</button>
                <a href="customers.php" class="btn btn-outline">KEMBALI</a>
            </div>
        </form>

        <div class="console" id="console-logs">> Pilih OLT dan klik DETECT untuk memulai pendaftaran pendaftaran...</div>

        <h4 style="color: #64b5f6; margin-bottom: 5px; margin-top:25px;">JAWABAN MENTAH OLT:</h4>
        <textarea id="rawOutput" readonly placeholder="Semua respon OLT akan tumpah di sini..."></textarea>
    </div>

    <script>
        const logs = document.getElementById('console-logs');
        const raw = document.getElementById('rawOutput');
        function appendLog(msg, color) {
            const d = document.createElement('div');
            d.style.color = color;
            d.innerHTML = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logs.appendChild(d);
            logs.scrollTop = logs.scrollHeight;
        }
        function updateRaw(val) {
            raw.value += val + "\n";
            raw.scrollTop = raw.scrollHeight;
        }
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
            live_echo("Mencoba Menyusup ke OLT " . $selected_olt['name'] . "...");
            
            $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port'], 5);
            
            try {
                if (!$client->connect($selected_olt['username'], $selected_olt['password'])) throw new Exception("Login Gagal.");
                live_echo("BERHASIL MASUK!", 'success');
                if (!empty($selected_olt['enable_password'])) $client->enable($selected_olt['enable_password']);

                // Probing commands
                $cmds = $manual_cmd ? [$manual_cmd] : [
                    "show version", 
                    "show interface brief",
                    "show gpon onu unauth",
                    "show epon onu-list unauth",
                    "show running-config"
                ];
                
                foreach ($cmds as $c) {
                    live_echo("Eksekusi: <code>$c</code>");
                    $res = $client->execute($c);
                    
                    if (strlen(trim($res)) > 0) {
                        live_echo(">> Respon " . strlen($res) . " Karakter.", 'success');
                        $clean_res = addslashes($res);
                        $clean_res = str_replace(array("\r", "\n"), "\\n", $clean_res);
                        echo "<script>updateRaw('--- COMMAND: $c ---\\n$clean_res');</script>\n";
                        flush();
                        
                        // Stop if we found a massive config
                        if ($c == "show running-config") break; 
                    } else {
                        live_echo(">> Tanpa Jawaban.", 'warn');
                    }
                    usleep(100000);
                }

                $client->disconnect();
                live_echo("Pendeteksian Selesai.", 'info');

            } catch (Exception $e) {
                live_echo("GAGAL: " . $e->getMessage(), 'error');
            }
        }
    }
    ?>
</body>
</html>
