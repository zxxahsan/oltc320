<?php
/**
 * OLT Tank Terminal (V8.15)
 * Extreme Prompt Detection & Debug Mode
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
    $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    
    if (!$selected_olt) {
        echo "Error: OLT target hilang.";
        exit;
    }

    $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port']);
    try {
        $diag = "--- DIAGNOSTIC START ---\n";
        
        // 1. Connection
        $client->connect($selected_olt['username'], $selected_olt['password']);
        $diag .= "Login Success.\n";
        
        // 2. Strict Super Enable
        $diag .= "Requesting Enable Mode...\n";
        $client->write("enable\r\n");
        $resE = $client->readUntil("/Password:|#\s*$/i", 2);
        
        if (stripos($resE, "Password:") !== false) {
            $diag .= "Password Prompt Detected. Sending Enable Password...\n";
            $client->write($selected_olt['enable_password'] . "\r\n");
            $resE = $client->readUntil("/[>#]\s*$/i", 3);
        }
        
        if (preg_match("/#\s*$/", $resE)) {
            $diag .= "Privilege Mode ACTIVE (#).\n";
            // SILENCE THE NOISE IMMEDIATELY
            $client->execute("no logging console");
            $client->execute("terminal length 0");
        } else {
            $diag .= "WARNING: Stuck at User Mode (>). Commands might fail.\n";
        }

        // 3. Execute Command with high timeout
        $res = $client->execute($cmd);
        
        echo $res . "\n\n--- SESSION INFO ---\n" . $diag;
        $client->disconnect();
    } catch (Exception $e) {
        echo "CRITICAL ERROR: " . $e->getMessage();
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>OLT Tank Terminal v8.15</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #050505; color: #00ff41; font-family: 'Courier New', monospace; padding: 20px; }
        .card { background: #111; border-radius: 5px; padding: 20px; border: 2px solid #003b00; max-width: 1200px; margin: auto; box-shadow: 0 0 20px rgba(0,255,65,0.2); }
        #terminal { background: #000; height: 500px; overflow-y: auto; padding: 15px; border: 1px solid #003b00; white-space: pre-wrap; font-size: 13px; color: #00ff41; line-height: 1.4; }
        .input-area { display: flex; gap: 10px; margin-top: 15px; background: #001100; padding: 10px; border-radius: 4px; }
        input[type="text"] { flex: 1; background: transparent; border: none; color: #00ff41; font-size: 18px; outline: none; font-family: inherit; }
        select { background: #000; color: #00ff41; border: 1px solid #003b00; padding: 5px; }
        .btn { background: #003b00; color: #00ff41; border: 1px solid #00ff41; padding: 10px 25px; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #00ff41; color: #000; }
        .diag-msg { color: #888; font-style: italic; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00ff41; margin-top:0;"><i class="fas fa-biohazard"></i> OLT TANK TERMINAL v8.15</h2>
        <div id="terminal">--- TANK MODE READY ---
Tujuan: Menembus mode Admin (#) & Mematikan pesan Syslog.</div>

        <div class="input-area">
            <select id="olt_id">
                <?php foreach($olts as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <span style="color: #00ff41; font-weight: bold;">root@OLT#</span>
            <input type="text" id="cmdInput" placeholder="Ketik perintah (Lalu Enter)..." autofocus>
            <button class="btn" onclick="sendCmd()">TEMBAK</button>
        </div>
    </div>

    <script>
        const term = document.getElementById('terminal');
        const input = document.getElementById('cmdInput');
        const oltId = document.getElementById('olt_id');

        function append(txt) {
            term.innerText += "\n" + txt;
            term.scrollTop = term.scrollHeight;
        }

        async function sendCmd() {
            const cmd = input.value.trim();
            if(!cmd) return;
            
            append(`\n> ${cmd}`);
            input.value = '';
            input.disabled = true;

            try {
                const r = await fetch(`?ajax_cmd=${encodeURIComponent(cmd)}&olt_id=${oltId.value}`);
                const t = await r.text();
                append(t);
            } catch(e) {
                append("Error: Connection timeout.");
            } finally {
                input.disabled = false;
                input.focus();
            }
        }

        input.addEventListener('keypress', (e) => { if(e.key === 'Enter') sendCmd(); });
    </script>
</body>
</html>
