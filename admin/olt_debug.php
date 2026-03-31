<?php
/**
 * OLT Precision Terminal (V8.16)
 * Fixing Line Endings (\n vs \r\n)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

session_start();

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

if (isset($_GET['ajax_cmd'])) {
    $olt_id = (int)$_GET['olt_id'];
    $cmd = trim($_GET['ajax_cmd']);
    $line_ending = $_GET['ending'] ?? 'n'; // 'n' or 'rn'
    
    $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    if (!$selected_olt) { echo "Error: Target OLT lost."; exit; }

    $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port']);
    try {
        $client->connect($selected_olt['username'], $selected_olt['password']);
        
        // Use user-specified ending (default \n)
        $eol = ($line_ending == 'rn') ? "\r\n" : "\n";
        
        // Manual Enable Handshake for diagnostic
        $client->write("enable" . $eol);
        $resE = $client->readUntil("/Password:|#\s*$/i", 2);
        if (stripos($resE, "Password:") !== false) {
            $client->write($selected_olt['enable_password'] . $eol);
            $client->readUntil("/[>#]\s*$/i", 2);
        }
        
        // Execute Command
        $client->write($cmd . $eol);
        usleep(50000); 
        $res = $client->readUntil("/(\r\n|\n|^)[^>\r\n#]*[>#]\s*$/", 5);
        
        echo $res;
        $client->disconnect();
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Precision Terminal v8.16</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #000; color: #0f0; font-family: 'Consolas', monospace; padding: 20px; }
        .card { background: #111; border-radius: 8px; padding: 20px; border: 1px solid #0f0; max-width: 1000px; margin: auto; }
        #terminal { background: #000; height: 500px; overflow-y: auto; padding: 15px; border: 1px solid #0f0; white-space: pre-wrap; font-size: 14px; }
        .input-group { display: flex; gap: 10px; margin-top: 20px; }
        input { flex: 1; background: #000; border: 1px solid #0f0; color: #0f0; padding: 10px; font-weight: bold; }
        select { background: #222; color: #fff; border: 1px solid #444; padding: 10px; }
        .btn { background: #0f0; color: #000; padding: 10px 20px; border: none; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2><i class="fas fa-crosshairs"></i> OLT PRECISION TERMINAL v8.16</h2>
        <p style="color:#888;">Gunakan pendaftarannya (X_X)... gunakan terminal ini untuk pendaftarannya (OKE!). Jika perintah gagal, coba ganti <b>Ending</b> ke \r\n.</p>
        
        <div id="terminal">> Ahsan-Network Ready. Precision Mode Active.</div>

        <div class="input-group">
            <select id="olt_id">
                <?php foreach($olts as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="ending">
                <option value="n">LF (\n)</option>
                <option value="rn">CRLF (\r\n)</option>
            </select>
            <input type="text" id="cmd_input" placeholder="Ketik perintah (Enter)..." autofocus>
            <button class="btn" onclick="sendCmd()">TEMBAK</button>
        </div>
    </div>

    <script>
        const terminal = document.getElementById('terminal');
        const input = document.getElementById('cmd_input');
        const olt_id = document.getElementById('olt_id');
        const ending = document.getElementById('ending');

        function log(txt) {
            terminal.innerText += "\n" + txt;
            terminal.scrollTop = terminal.scrollHeight;
        }

        async function sendCmd() {
            const cmd = input.value.trim();
            if(!cmd) return;
            log(`Ahsan-Network# ${cmd}`);
            input.value = '';
            input.disabled = true;

            try {
                const r = await fetch(`?ajax_cmd=${encodeURIComponent(cmd)}&olt_id=${olt_id.value}&ending=${ending.value}`);
                const t = await r.text();
                log(t);
            } catch(e) {
                log("Error: Request failed.");
            } finally {
                input.disabled = false;
                input.focus();
            }
        }
        input.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendCmd(); });
    </script>
</body>
</html>
