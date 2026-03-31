<?php
/**
 * OLT Deep Diagnostic Tool (V8.6)
 * Real-time OLT CLI inspection with Live Echo & Manual Command Support
 */

set_time_limit(120); 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bypass buffering for real-time output
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

function live_echo($msg, $type = 'info') {
    $colors = ['info' => '#00d2ff', 'success' => '#39FF14', 'error' => '#ff4b2b', 'warn' => '#ffc107', 'cmd' => '#ffffff'];
    $color = $colors[$type] ?? '#fff';
    $msg = addslashes($msg);
    echo "<script>appendLog('$msg', '$color');</script>\n";
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
    <title>OLT Live Diagnostic v8.6</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #1a1a1a; color: #eee; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .card { background: #2a2a2a; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-width: 1000px; margin: auto; border: 1px solid #3d3d3d; }
        .console { background: #000; color: #39FF14; padding: 15px; border-radius: 8px; font-family: 'Consolas', 'Monaco', monospace; height: 350px; overflow-y: auto; border: 1px solid #444; margin-top: 20px; line-height: 1.6; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; display: inline-block; text-decoration: none; text-align: center; }
        .btn-primary { background: #00d2ff; color: #000; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-outline { background: transparent; border: 1px solid #00d2ff; color: #00d2ff; }
        select, input[type="text"] { padding: 12px; background: #333; color: #fff; border: 1px solid #444; border-radius: 8px; outline: none; }
        #raw-area { margin-top: 20px; display: none; }
        textarea { width: 100%; height: 300px; background: #111; color: #ccc; border: 1px solid #444; padding: 10px; border-radius: 8px; font-family: monospace; }
        .copy-btn { margin-top: 10px; background: #444; color: #eee; padding: 8px 15px; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00d2ff; margin-bottom: 5px;"><i class="fas fa-terminal"></i> OLT Live Tracker v8.6</h2>
        <p style="color:#888;">Diagnosa real-time untuk mendeteksi masalah koneksi OLT secara mendalam.</p>
        
        <form method="POST" id="diagForm">
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <select name="olt_id" required>
                    <option value="">-- Pilih OLT --</option>
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>" <?php echo (isset($_POST['olt_id']) && $_POST['olt_id'] == $o['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($o['name']); ?> (<?php echo $o['host']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="manual_cmd" placeholder="Perintah manual (opsional)..." style="flex:1;">
                <button type="submit" class="btn btn-primary" id="btnSubmit">EKSEKUSI</button>
                <a href="customers.php" class="btn btn-outline">KEMBALI</a>
            </div>
        </form>

        <div class="console" id="console-logs">
            > Siap. Pilih OLT dan klik EKSEKUSI.
        </div>

        <div id="raw-area">
            <h4 style="color: #00d2ff; margin-top: 20px;">HASIL LENGKAP (RAW):</h4>
            <textarea id="rawOutput" readonly></textarea>
            <button class="copy-btn" onclick="copyRaw()">Salin ke Clipboard</button>
        </div>
    </div>

    <script>
        const consoleLogs = document.getElementById('console-logs');
        function appendLog(msg, color) {
            const div = document.createElement('div');
            div.style.color = color;
            div.innerHTML = `[${new Date().toLocaleTimeString()}] ${msg}`;
            consoleLogs.appendChild(div);
            consoleLogs.scrollTop = consoleLogs.scrollHeight;
        }
        function copyRaw() {
            const el = document.getElementById('rawOutput');
            el.select();
            document.execCommand('copy');
            alert('Sukses disalin!');
        }
        function showRaw(content) {
            document.getElementById('raw-area').style.display = 'block';
            document.getElementById('rawOutput').value = content;
        }
        
        document.getElementById('diagForm').onsubmit = function() {
            document.getElementById('btnSubmit').disabled = true;
            document.getElementById('btnSubmit').innerText = 'PROSES...';
            consoleLogs.innerHTML = '';
        };
    </script>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['olt_id'])) {
        $olt_id = (int)$_POST['olt_id'];
        $manual_cmd = trim($_POST['manual_cmd'] ?? '');
        $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);

        if (!$olt) {
            live_echo("OLT tidak ditemukan di database!", 'error');
        } else {
            live_echo("=== MEMULAI ANALISIS OLT: " . $olt['name'] . " ===");
            
            // 1. PING
            live_echo("Langkah 1: Cek Konektivitas Fisik (PING)...");
            if (pingHost($olt['host'])) {
                live_echo("HASIL: Jalur jaringan ke OLT terdeteksi.", 'success');
            } else {
                live_echo("HASIL: OLT tidak merespon PING. Mungkin ICMP diblokir, tetap lanjut...", 'warn');
            }

            // 2. CONNECTION
            live_echo("Langkah 2: Membuka Jalur TELNET ke port " . $olt['port'] . "...");
            $client = new OltTelnetClient($olt['host'], $olt['port'], 5); // 5s timeout
            
            try {
                if (!$client->connect($olt['username'], $olt['password'])) {
                    throw new Exception("OLT menolak koneksi. Periksa Username/Password atau batas sesi (max session).");
                }
                live_echo("LOGIN SUKSES!", 'success');

                if (!empty($olt['enable_password'])) {
                    live_echo("Meminta izin Privilege (Enable)...");
                    $client->enable($olt['enable_password']);
                    live_echo("MODE PRIVILEGE # AKTIF.", 'success');
                }

                // 3. EXECUTION
                $all_raw = "";
                if ($manual_cmd) {
                    live_echo("Menjalankan perintah manual: <code>$manual_cmd</code>", 'cmd');
                    $res = $client->execute($manual_cmd);
                    $all_raw = $res;
                    live_echo("Selesai.", 'success');
                } else {
                    $commands = [
                        "show gpon onu unauthentication",
                        "show gpon onu unconfigured",
                        "show gpon onu state",
                        "show onu unauth",
                        "show gpon onu information"
                    ];
                    live_echo("Mencoba berbagai variasi perintah penemuan ONU...");
                    foreach ($commands as $cmd) {
                        live_echo("Coba: <code>$cmd</code>");
                        $res = $client->execute($cmd);
                        if (strpos($res, "Unknown command") === false && strlen(trim($res)) > 15) {
                            live_echo(">> BERHASIL DAPAT DATA!", 'success');
                            $all_raw .= "\n--- CMD: $cmd ---\n" . $res;
                        } else {
                            live_echo(">> Perintah tidak cocok.", 'warn');
                        }
                    }
                }

                $cleaned_raw = str_replace(array("\r", "\n"), "\\n", addslashes($all_raw));
                echo "<script>showRaw('$cleaned_raw');</script>";

                $client->disconnect();
                live_echo("Koneksi ditutup.", 'info');

            } catch (Exception $e) {
                live_echo("Gagal: " . $e->getMessage(), 'error');
            }
        }
        live_echo("=== PROSES SELESAI ===");
    }
    ?>
    <script>document.getElementById('btnSubmit').disabled = false; document.getElementById('btnSubmit').innerText = 'EKSEKUSI';</script>
</body>
</html>
