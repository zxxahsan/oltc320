<?php
/**
 * OLT Deep Diagnostic Tool (V8.9)
 * Ultra-fast loading & Forensic Debugging
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

function pingHost($host) {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = "ping -n 1 -w 1000 $host";
    } else {
        $cmd = "ping -c 1 -W 1 $host";
    }
    exec($cmd, $output, $result);
    return ($result === 0);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Forensic Debug v8.9</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #121212; color: #e0e0e0; font-family: 'Segoe UI', Tahoma, Verdana, sans-serif; padding: 20px; }
        .card { background: #1e1e1e; border-radius: 12px; padding: 25px; box-shadow: 0 8px 32px rgba(0,0,0,0.6); max-width: 1000px; margin: auto; border: 1px solid #333; }
        .console { background: #000; color: #39FF14; padding: 15px; border-radius: 8px; font-family: 'Consolas', monospace; height: 300px; overflow-y: auto; border: 1px solid #444; margin-top: 15px; line-height: 1.4; border-left: 4px solid #00d2ff; }
        .btn { padding: 12px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .btn-primary { background: #00d2ff; color: #000; }
        .btn-outline { background: transparent; border: 1px solid #444; color: #888; margin-left: 10px; }
        .btn-outline:hover { color: #fff; border-color: #666; }
        select, input[type="text"] { padding: 12px; background: #252525; color: #fff; border: 1px solid #333; border-radius: 6px; outline: none; }
        textarea { width: 100%; height: 350px; background: #0a0a0a; color: #39FF14; border: 1px solid #333; padding: 15px; border-radius: 8px; font-family: 'Consolas', monospace; font-size: 13px; margin-top: 10px; }
        .success-box { color: #39FF14; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00d2ff; margin-top:0;"><i class="fas fa-bug"></i> Forensic Debug Tool v8.9</h2>
        <p style="color:#777;">Gunakan alat ini untuk melihat jawaban "ASLI" dari OLT jika pendaftaran pendaftaran (X_X)... jika pencarian ONU gagal.</p>
        
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
                <input type="text" name="manual_cmd" placeholder="Cth: ? atau show version" style="flex:1;">
                <button type="submit" class="btn btn-primary" id="btnRun">EKSEKUSI</button>
                <a href="customers.php" class="btn btn-outline">KEMBALI</a>
            </div>
        </form>

        <div class="console" id="console-logs">> Siap. Masukkan perintah atau biarkan kosong untuk Auto-Scan.</div>

        <h4 style="color: #555; margin-bottom: 5px; margin-top:25px;">JAWABAN ASLI OLT (RAW):</h4>
        <textarea id="rawOutput" readonly placeholder="Jawaban OLT akan muncul lengkap di sini..."></textarea>
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
        // Set up streaming for POST request
        @ini_set('implicit_flush', 1);
        ob_implicit_flush(true);
        while (ob_get_level()) ob_end_flush();

        $olt_id = (int)$_POST['olt_id'];
        $manual_cmd = trim($_POST['manual_cmd'] ?? '');
        $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);

        if ($selected_olt) {
            live_echo("Membuka jalur ke OLT " . $selected_olt['name'] . "...");
            
            $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port'], 5); // Fast connect 5s
            
            try {
                if (!$client->connect($selected_olt['username'], $selected_olt['password'])) {
                    throw new Exception("OLT menolak jabat tangan. Cek User/Pass.");
                }
                live_echo("LOGIN SUKSES!", 'success');

                if (!empty($selected_olt['enable_password'])) {
                    $client->enable($selected_olt['enable_password']);
                    live_echo("PRIVILEGE # DIAKTIFKAN.", 'success');
                }

                $cmds = $manual_cmd ? [$manual_cmd] : ["show gpon onu unauth", "show gpon onu uncfg", "show ?"];
                
                foreach ($cmds as $c) {
                    live_echo("Mengirim: <code>$c</code>");
                    $res = $client->execute($c);
                    
                    // Show in log
                    if (strlen(trim($res)) > 0) {
                        live_echo(">> OLT Merespon (" . strlen($res) . " Karakter)", 'success');
                        
                        // Push to Raw Textarea via JS
                        $clean_res = addslashes($res);
                        $clean_res = str_replace(array("\r", "\n"), "\\n", $clean_res);
                        echo "<script>updateRaw('--- RESPON UNTUK: $c ---\\n$clean_res');</script>\n";
                        flush();
                    } else {
                        live_echo(">> OLT Diam saja (Kosong).", 'warn');
                    }
                    usleep(100000);
                }

                $client->disconnect();
                live_echo("Selesai. Koneksi ditutup.", 'info');

            } catch (Exception $e) {
                live_echo("GAGAL: " . $e->getMessage(), 'error');
            }
        }
        live_echo("--- PROSES BERAKHIR ---");
    }
    ?>
</body>
</html>
