<?php
/**
 * OLT Deep Diagnostic Tool (V8.6)
 * Standalone investigation tool for raw OLT CLI output with LIVE ECHO
 */

set_time_limit(60); 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Force immediate output to browser
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

function live_echo($msg, $type = 'info') {
    $colors = [
        'info' => '#00d2ff',
        'success' => '#39FF14',
        'error' => '#ff4b2b',
        'warn' => '#ffc107'
    ];
    $color = $colors[$type] ?? '#fff';
    echo "<script>appendLog('$msg', '$color');</script>";
}

// Simple ping function
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OLT Live Diagnostic</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #1a1a1a; color: #eee; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .card { background: #2a2a2a; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); max-width: 1000px; margin: auto; border: 1px solid #3d3d3d; }
        .console { background: #000; color: #39FF14; padding: 15px; border-radius: 8px; font-family: 'Consolas', 'Monaco', monospace; height: 450px; overflow-y: auto; border: 1px solid #444; margin-top: 20px; line-height: 1.6; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.3s; }
        .btn-primary { background: #00d2ff; color: #000; }
        .btn-primary:hover { background: #00b8e6; transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid #00d2ff; color: #00d2ff; }
        select { padding: 12px; background: #333; color: #fff; border: 1px solid #444; border-radius: 8px; width: 300px; outline: none; }
        #raw-area { margin-top: 20px; display: none; }
        textarea { width: 100%; height: 300px; background: #111; color: #ccc; border: 1px solid #444; padding: 10px; border-radius: 8px; font-family: monospace; }
        .copy-btn { margin-top: 10px; background: #444; color: #eee; padding: 5px 15px; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00d2ff; margin-bottom: 5px;"><i class="fas fa-satellite-dish"></i> OLT Live Tracker v8.6</h2>
        <p style="color:#888;">Diagnosa real-time pendaftaran untuk mendeteksi masalah koneksi dan pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pendaftaran pesan.</p>
        
        <form method="POST">
            <select name="olt_id" required>
                <option value="">-- Pilih OLT --</option>
                <?php foreach($olts as $o): ?>
                <option value="<?= $o['id'] ?>" <?= (isset($_POST['olt_id']) && $_POST['olt_id'] == $o['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($o['name']) ?> (<?= $o['host'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">MULAI DIAGNOSA</button>
            <a href="customers.php" class="btn btn-outline" style="text-decoration:none;">KEMBALI</a>
        </form>

        <div class="console" id="console-logs">
            > Menunggu perintah...
        </div>

        <div id="raw-area">
            <h4 style="color: #00d2ff; margin-top: 20px;">RAW OUTPUT (Salin ini jika diminta):</h4>
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
            alert('Berhasil disalin!');
        }
        function showRaw(content) {
            document.getElementById('raw-area').style.display = 'block';
            document.getElementById('rawOutput').value = content;
        }
    </script>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['olt_id'])) {
        $olt_id = (int)$_POST['olt_id'];
        $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);

        if (!$olt) {
            live_echo("OLT tidak ditemukan di database!", 'error');
        } else {
            echo "<script>consoleLogs.innerHTML = '';</script>";
            live_echo("=== MEMULAI DIAGNOSA UNTUK OLT: " . $olt['name'] . " ===");
            
            // 1. PING TEST
            live_echo("Langkah 1: Mengecek Jaringan (PING)...");
            if (pingHost($olt['host'])) {
                live_echo("PING BERHASIL! Host " . $olt['host'] . " merespon.", 'success');
            } else {
                live_echo("PING GAGAL! Pastikan IP OLT bisa dijangkau dari server.", 'error');
            }

            // 2. TELNET TEST
            live_echo("Langkah 2: Menghubungkan ke TELNET (Port " . $olt['port'] . ")...");
            $client = new OltTelnetClient($olt['host'], $olt['port'], 10);
            
            try {
                if (!$client->connect($olt['username'], $olt['password'])) {
                    throw new Exception("Gagal Login ke OLT (Cek Username/Password)");
                }
                live_echo("LOGIN BERHASIL!", 'success');

                if (!empty($olt['enable_password'])) {
                    live_echo("Masuk ke Mode Privilege (Enable)...");
                    $client->enable($olt['enable_password']);
                    live_echo("MODE PRIVILEGE AKTIF.", 'success');
                }

                // 3. FETCH DATA
                live_echo("Langkah 3: Menarik data unauth menggunakan 'show gpon onu unauthentication'...");
                $rawUnauth = $client->execute("show gpon onu unauthentication");
                $unauthCount = preg_match_all('/0\/(\d+)/', $rawUnauth, $matches);
                live_echo("Selesai. Ditemukan: $unauthCount data mentah.", 'info');

                live_echo("Langkah 4: Menarik data informasi menggunakan 'show gpon onu information'...");
                $rawInfo = $client->execute("show gpon onu information");
                $infoCount = preg_match_all('/0\/(\d+)/', $rawInfo, $matches);
                live_echo("Selesai. Ditemukan: $infoCount data mentah.", 'info');

                // 4. TEST SN
                live_echo("=== ANALISIS HASIL ===");
                $all_raw = $rawUnauth . "\n" . $rawInfo;
                if (preg_match_all('/0\/(\d+)\s+.*?\s+([A-Z0-9]{8,16})/i', $all_raw, $m, PREG_SET_ORDER)) {
                    live_echo("Robot berhasil mengenali " . count($m) . " Serial Number!", 'success');
                    foreach($m as $idx => $match) {
                        if ($idx < 10) {
                            live_echo("- [OK] Port 0/{$match[1]}: {$match[2]}", 'info');
                        }
                    }
                } else {
                    live_echo("PERINGATAN: OLT membalas teks, tapi Robot pendaftaran tidak mengenali format SN.", 'warn');
                    live_echo("Silakan salin teks di bawah (Harta Karun) dan kirim ke saya.", 'warn');
                }

                $raw_for_js = str_replace(array("\r", "\n"), "\\n", addslashes($all_raw));
                echo "<script>showRaw('$raw_for_js');</script>";

                $client->disconnect();
                live_echo("Koneksi ditutup.", 'info');
            } catch (Exception $e) {
                live_echo("ERROR: " . $e->getMessage(), 'error');
                live_echo("Solusi: Cek apakah ada IP Conflict atau port Telnet diblokir.", 'warn');
            }
        }
        live_echo("=== DIAGNOSA SELESAI ===");
    }
    ?>
</body>
</html>
