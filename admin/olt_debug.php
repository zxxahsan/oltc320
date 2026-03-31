<?php
/**
 * OLT Smart Terminal (V8.14)
 * Stateful & Context-Aware 
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

session_start();

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");

// Handle Context Storage
if (!isset($_SESSION['olt_ctx'])) $_SESSION['olt_ctx'] = [];

if (isset($_GET['ajax_cmd'])) {
    $olt_id = (int)$_GET['olt_id'];
    $cmd = trim($_GET['ajax_cmd']);
    $selected_olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    
    // Manage Context
    if (!isset($_SESSION['olt_ctx'][$olt_id])) $_SESSION['olt_ctx'][$olt_id] = [];
    
    // Special Command Handlers
    if ($cmd == 'exit') {
        array_pop($_SESSION['olt_ctx'][$olt_id]);
        echo "Exiting sub-menu...";
        exit;
    }
    if ($cmd == 'clear') {
        $_SESSION['olt_ctx'][$olt_id] = [];
        echo "Context Cleared.";
        exit;
    }

    $client = new OltTelnetClient($selected_olt['host'], $selected_olt['port']);
    try {
        $client->connect($selected_olt['username'], $selected_olt['password']);
        if (!empty($selected_olt['enable_password'])) $client->enable($selected_olt['enable_password']);
        
        // Anti-Noise for session
        $client->execute("no logging console");
        
        // Re-apply Context
        foreach ($_SESSION['olt_ctx'][$olt_id] as $ctx) {
            $client->execute($ctx);
        }
        
        // Execute NEW Command
        $res = $client->execute($cmd);
        
        // Update Context if we enter sub-menus
        if (preg_match('/(configure terminal|config|interface|vlan)/i', $cmd)) {
            if (stripos($res, "%") === false) {
                $_SESSION['olt_ctx'][$olt_id][] = $cmd;
            }
        }
        
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
    <title>OLT Smart Terminal v8.14</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #0c0c0c; color: #00ff00; font-family: 'Consolas', monospace; padding: 20px; }
        .card { background: #1a1a1a; border-radius: 10px; padding: 20px; border: 1px solid #333; max-width: 1100px; margin: auto; }
        #terminal { background: #000; height: 500px; overflow-y: auto; padding: 15px; border: 1px solid #00ff0033; margin-bottom: 15px; white-space: pre-wrap; color: #39FF14; text-shadow: 0 0 5px #39FF14; }
        .input-group { display: flex; gap: 10px; border-top: 1px solid #333; padding-top: 15px; font-size: 18px; }
        input[type="text"] { flex: 1; background: #000; border: 1px solid #444; color: #00ff00; padding: 12px; outline: none; border-radius: 5px; font-weight: bold; }
        select { background: #222; color: #fff; border: 1px solid #444; padding: 10px; border-radius: 5px; }
        .btn { background: #238636; color: #fff; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
        .btn-red { background: #da3633; }
        .prompt { color: #58a6ff; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#00ff00; margin-top:0;"><i class="fas fa-microchip"></i> Smart Terminal v8.14</h2>
        <p style="color:#888;">Terminal ini pendaftarannya (X_X)... terminal ini "ingat" kalau Bapak masuk ke mode `config` atau `interface`. Ketik <b>clear</b> untuk reset.</p>
        
        <div id="terminal">> Ahsan-Network (Stateless Proxy Active) Ready...</div>

        <div class="input-group">
            <select id="olt_id">
                <?php foreach($olts as $o): ?>
                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="prompt" id="live_prompt">#</span>
            <input type="text" id="cmd_input" placeholder="Ketik perintah... (Enter)" autofocus>
            <button class="btn" onclick="sendCmd()">ENTER</button>
            <button class="btn btn-red" onclick="resetCtx()">RESET</button>
        </div>
    </div>

    <script>
        const terminal = document.getElementById('terminal');
        const cmdInput = document.getElementById('cmd_input');
        const oltSelect = document.getElementById('olt_id');
        const livePrompt = document.getElementById('live_prompt');

        function appendOutput(text) {
            terminal.innerText += text + "\n";
            terminal.scrollTop = terminal.scrollHeight;
        }

        async function resetCtx() {
            const oltId = oltSelect.value;
            await fetch(`?ajax_cmd=clear&olt_id=${oltId}`);
            appendOutput("\n[SYSTEM] Context Cleared. Session Reset.");
            livePrompt.innerText = "#";
        }

        async function sendCmd() {
            const cmd = cmdInput.value.trim();
            const oltId = oltSelect.value;
            if (!cmd) return;

            appendOutput(`\n${livePrompt.innerText} ${cmd}`);
            cmdInput.value = '';
            cmdInput.disabled = true;

            try {
                const response = await fetch(`?ajax_cmd=${encodeURIComponent(cmd)}&olt_id=${oltId}`);
                const text = await response.text();
                
                // Update Prompt visual based on command
                if (cmd.includes('config')) livePrompt.innerText = "(config)#";
                if (cmd.includes('interface')) livePrompt.innerText = "(config-if)#";
                if (cmd === 'exit') livePrompt.innerText = "#";

                appendOutput(text);
            } catch (e) {
                appendOutput("\nError: Communication Timeout.");
            } finally {
                cmdInput.disabled = false;
                cmdInput.focus();
            }
        }

        cmdInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendCmd(); });
    </script>
</body>
</html>
