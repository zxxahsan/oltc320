<?php
/**
 * OLT Live Terminal (V8.12)
 * Pure Interactive Mode
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

// Handle AJAX Command Execution
if (isset($_GET['ajax_cmd'])) {
    $olt_id = (int)$_GET['olt_id'];
    $cmd = trim($_GET['ajax_cmd']);
    $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    
    if (!$selected_olt) {
        echo "Error: OLT tidak ditemukan.";
        exit;
    }

    $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port'], 5);
    try {
        $client->connect($selected_olt['username'], $selected_olt['password']);
        if (!empty($selected_olt['enable_password'])) $client->enable($selected_olt['enable_password']);
        
        $res = $client->execute($cmd);
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
    <title>OLT Live Terminal v8.12</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #0c0c0c; color: #00ff00; font-family: 'Consolas', 'Monaco', monospace; padding: 20px; }
        .card { background: #1a1a1a; border-radius: 10px; padding: 20px; border: 1px solid #333; max-width: 1100px; margin: auto; }
        #terminal { background: #000; height: 500px; overflow-y: auto; padding: 15px; border: 1px solid #00ff0033; margin-bottom: 15px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
        .input-group { display: flex; gap: 10px; border-top: 1px solid #333; padding-top: 15px; }
        input[type="text"] { flex: 1; background: #000; border: 1px solid #444; color: #00ff00; padding: 12px; outline: none; border-radius: 5px; }
        select { background: #222; color: #fff; border: 1px solid #444; padding: 10px; border-radius: 5px; }
        .btn { background: #00ff00; color: #000; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .prompt { color: #00d2ff; }
        .error { color: #ff4b2b; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00ff00; margin-top:0;"><i class="fas fa-terminal"></i> OLT Live Terminal v8.12</h2>
        <p style="color:#888;">Gunakan terminal ini untuk mencari perintah discovery yang benar menggunakan tanda tanya pendaftarannya pendaftaran (OKE!) ... menggunakan <b>?</b> secara langsung.</p>
        
        <div id="terminal">> Siaga. Pilih OLT dan ketik perintah... (Contoh: show ?)</div>

        <div class="input-group">
            <select id="olt_id">
                <?php foreach($olts as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="prompt">Ahsan-Network#</span>
            <input type="text" id="cmd_input" placeholder="Ketik perintah di sini... (Lalu Enter)" autofocus>
            <button class="btn" onclick="sendCmd()">KIRIM</button>
        </div>
    </div>

    <script>
        const terminal = document.getElementById('terminal');
        const cmdInput = document.getElementById('cmd_input');
        const oltSelect = document.getElementById('olt_id');

        function appendOutput(text, isError = false) {
            const span = document.createElement('span');
            if (isError) span.className = 'error';
            span.textContent = text + "\n";
            terminal.appendChild(span);
            terminal.scrollTop = terminal.scrollHeight;
        }

        async function sendCmd() {
            const cmd = cmdInput.value.trim();
            const oltId = oltSelect.value;
            if (!cmd) return;

            appendOutput(`\nAhsan-Network# ${cmd}`);
            cmdInput.value = '';
            cmdInput.disabled = true;

            try {
                const response = await fetch(`?ajax_cmd=${encodeURIComponent(cmd)}&olt_id=${oltId}`);
                const text = await response.text();
                appendOutput(text);
            } catch (e) {
                appendOutput("Error: Gagal menghubungi server.", true);
            } finally {
                cmdInput.disabled = false;
                cmdInput.focus();
            }
        }

        cmdInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendCmd();
        });
    </script>
</body>
</html>
