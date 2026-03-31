<?php
require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Update Aplikasi';

// Get local version primarily from version.txt
$localVersion = '1.0.0'; // Fallback
$localVersionFile = dirname(__DIR__) . '/version.txt';
if (file_exists($localVersionFile)) {
    $fileVersion = trim(file_get_contents($localVersionFile));
    if ($fileVersion !== '') {
        $localVersion = $fileVersion;
    }
} elseif (defined('APP_VERSION')) {
    $localVersion = APP_VERSION;
}

$remoteVersion = null;
$statusMessage = '';
$statusType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'check') {
        // Fallback URL if not defined in config
        $defaultUpdateUrl = 'https://raw.githubusercontent.com/zxxahsan/gembok/main/version.txt';
        $remoteUrl = defined('GEMBOK_UPDATE_VERSION_URL') ? GEMBOK_UPDATE_VERSION_URL : $defaultUpdateUrl;
        
        if ($remoteUrl === '') {
            $statusMessage = 'URL versi update belum dikonfigurasi.';
            $statusType = 'error';
        } else {
            // Disable SSL verification for simplicity (or configure cacert.pem properly)
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header' => "User-Agent: GEMBOK-Updater\r\n"
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $remoteContent = @file_get_contents($remoteUrl, false, $context);
            if ($remoteContent === false) {
                $error = error_get_last();
                $statusMessage = 'Gagal mengambil versi dari server update. Error: ' . ($error['message'] ?? 'Unknown');
                $statusType = 'error';
            } else {
                $remoteVersion = trim($remoteContent);
                if ($remoteVersion === '') {
                    $statusMessage = 'File versi di server update kosong.';
                    $statusType = 'error';
                } else {
                    // Compare versions
                    if (version_compare($localVersion, $remoteVersion, '>=')) {
                        $statusMessage = 'Versi aplikasi sudah terbaru (' . htmlspecialchars($localVersion) . ').';
                        $statusType = 'success';
                    } else {
                        $statusMessage = 'Tersedia versi baru: <strong>' . htmlspecialchars($remoteVersion) . '</strong> (saat ini: ' . htmlspecialchars($localVersion) . ').';
                        $statusType = 'info';
                    }
                }
            }
        }
    } elseif ($action === 'do_update_stream') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Encoding: none');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        
        echo str_repeat(" ", 1024); // Pad to bypass early browser buffers
        
        function sendLog($percent, $msg) {
            $msgJs = json_encode(nl2br(htmlspecialchars($msg)));
            echo "<script>window.parent.updateProgress($percent, $msgJs);</script>\n";
            flush();
            usleep(400000); // 400ms delay for visual animation
        }
        
        sendLog(5, "[*] Memulai Inisialisasi Update...");
        
        $projectRoot = realpath(dirname(__DIR__));
        sendLog(10, "[*] Target Direktori: " . $projectRoot);
        // Use fetch + reset --hard to always safely overwrite any local changes
        // Setting HOME environment variable is critical for git to work from some web servers
        putenv('HOME=' . $projectRoot);
        putenv('GIT_TERMINAL_PROMPT=0'); // Don't hang on credential prompts
        
        $cmd = 'cd ' . escapeshellarg($projectRoot) . ' && git fetch --all 2>&1 && git reset --hard origin/main 2>&1';
        exec($cmd, $output, $returnVar);
        
        $gitOutput = implode("\n", $output);
        sendLog(50, "[+] Output Git:\n" . $gitOutput);
        
        if ($returnVar === 0) {
            sendLog(60, "[*] Sinkronisasi Git Berhasil! Menyiapkan Migrasi Database...");
            
            require_once '../includes/db.php';
            try {
                $pdo = getDB();
                sendLog(70, "[*] Menjalankan pengecekan skema tabel...");
                
                try {
                    $pdo->query("SELECT bill_discount FROM sales_users LIMIT 1");
                } catch (Exception $e) {
                    $pdo->exec("ALTER TABLE sales_users ADD COLUMN bill_discount DECIMAL(15,2) DEFAULT 2000 AFTER status");
                    sendLog(72, " -> Added column: bill_discount");
                }
                
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM sales_transactions LIKE 'type'");
                    $col = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (strpos($col['Type'], 'enum') !== false) {
                        $pdo->exec("ALTER TABLE sales_transactions MODIFY type VARCHAR(50) NOT NULL");
                        sendLog(75, " -> Updated column: sales_transactions.type");
                    }
                } catch (Exception $e) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_transactions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sales_user_id INT NOT NULL,
                        type VARCHAR(50) NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        description TEXT,
                        related_username VARCHAR(100),
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (sales_user_id) REFERENCES sales_users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    sendLog(75, " -> Created table: sales_transactions");
                }
                
                $tables = ['sales_transactions', 'hotspot_sales', 'sales_users'];
                foreach($tables as $tbl) {
                    try {
                        $pdo->query("SELECT updated_at FROM $tbl LIMIT 1");
                    } catch (Exception $e) {
                        $pdo->exec("ALTER TABLE $tbl ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                        sendLog(80, " -> Added column updated_at to $tbl");
                    }
                }
                
                try { $pdo->query("SELECT voucher_mode FROM sales_users LIMIT 1"); } 
                catch (Exception $e) { $pdo->exec("ALTER TABLE sales_users ADD COLUMN voucher_mode VARCHAR(20) DEFAULT 'mix' AFTER status"); }
                
                try { $pdo->query("SELECT voucher_length FROM sales_users LIMIT 1"); } 
                catch (Exception $e) { $pdo->exec("ALTER TABLE sales_users ADD COLUMN voucher_length INT DEFAULT 6 AFTER voucher_mode"); }
                
                try { $pdo->query("SELECT voucher_type FROM sales_users LIMIT 1"); } 
                catch (Exception $e) { $pdo->exec("ALTER TABLE sales_users ADD COLUMN voucher_type VARCHAR(20) DEFAULT 'upp' AFTER voucher_length"); }
                sendLog(85, " -> Migrated Voucher table settings");
                
                try {
                    $pdo->query("SELECT id FROM site_settings LIMIT 1");
                } catch (Exception $e) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        setting_key VARCHAR(50) UNIQUE NOT NULL,
                        setting_value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    
                    $siteSettings = [
                        ['hero_title', 'Internet Cepat <br>Tanpa Batas'],
                        ['hero_description', 'Nikmati koneksi internet fiber optic super cepat, stabil, dan unlimited untuk kebutuhan rumah maupun bisnis Anda. Gabung sekarang!'],
                        ['contact_phone', '+62 812-3456-7890'],
                        ['contact_email', 'info@gembok.net'],
                        ['contact_address', 'Jakarta, Indonesia'],
                        ['footer_about', 'Penyedia layanan internet terpercaya.']
                    ];
                    
                    foreach ($siteSettings as $ss) {
                        $stmt = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
                        $stmt->execute($ss);
                    }
                    sendLog(90, " -> Created table: site_settings");
                }
                
                sendLog(95, "[*] Migrasi struktur Database telah selesai!");
            } catch (Exception $e) {
                sendLog(90, "[!] Ada peringatan Database: " . $e->getMessage());
            }
            sendLog(100, "[*] PROSES UPDATE SELESAI SELURUHNYA!");
        } else {
            sendLog(60, "[!] ERROR KEPARAHAN: Pull Gagal. Cek koneksi internet/GitHub.");
            echo "<script>alert('Update Terhenti!');</script>\n";
        }
        exit;
    } elseif ($action === 'bump_version') {
        $newVersion = trim($_POST['new_version'] ?? '');
        if ($newVersion !== '') {
            if (file_put_contents($localVersionFile, $newVersion) !== false) {
                $statusMessage = 'Versi aplikasi berhasil dinaikkan menjadi ' . htmlspecialchars($newVersion) . '. Jangan lupa Commit dan Push ke GitHub!';
                $statusType = 'success';
                $localVersion = $newVersion;
            } else {
                $statusMessage = 'Gagal menyimpan versi baru. Pastikan folder dapat ditulis.';
                $statusType = 'error';
            }
        }
    }
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-sync-alt"></i> Update Aplikasi</h3>
    </div>
    <div class="card-body">
        <p>Versi Terpasang: <strong><?php echo htmlspecialchars($localVersion); ?></strong></p>
        
        <?php if ($statusMessage): ?>
            <div class="alert alert-<?php echo $statusType === 'success' ? 'success' : ($statusType === 'error' ? 'error' : 'info'); ?>" style="white-space: pre-line;">
                <?php echo htmlspecialchars($statusMessage); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" style="margin-bottom: 15px;">
            <input type="hidden" name="action" value="check">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Cek Versi di Server Update
            </button>
        </form>
        
        <div id="update-ui" style="display:none; margin-top:20px;">
            <h4>Memproses Update...</h4>
            <!-- Setup simple bootstrap compatible progress bar dynamically injected styles in case no bootstrap -->
            <div style="background: rgba(255,255,255,0.1); border-radius: 8px; height: 26px; width: 100%; margin-bottom: 15px; overflow: hidden; box-shadow: inset 0 2px 5px rgba(0,0,0,0.5);">
                <div id="update-progress" style="background: linear-gradient(90deg, #17a2b8, #28a745); height: 100%; width: 0%; transition: width 0.4s ease; display:flex; align-items:center; justify-content:center; color:#fff; font-size:12px; font-weight:bold;">0%</div>
            </div>
            
            <div id="update-log" style="background: #0d1117; color: #58a6ff; font-family: 'Courier New', monospace; font-size: 13px; padding: 15px; border-radius: 8px; height: 350px; overflow-y: auto; box-shadow: inset 0 0 10px rgba(0,0,0,0.8); border: 1px solid #30363d; line-height: 1.5;">
                <span style="color:#8b949e;">Menunggu inisialisasi...</span><br>
            </div>
            <iframe id="update-frame" name="update_frame" style="display:none;"></iframe>
        </div>

        <div id="action-buttons">
            <button type="button" class="btn btn-primary" onclick="startUpdate()">
                <i class="fas fa-download"></i> Jalankan Update (git pull)
            </button>
        </div>
        
        <script>
        function startUpdate() {
            if(!confirm('Jalankan proses UPDATE SEKARANG?\nPastikan Anda sudah memiliki Full Backup dari menu Backup & Restore.')) return;
            
            document.getElementById('action-buttons').style.display = 'none';
            document.getElementById('update-ui').style.display = 'block';
            document.getElementById('update-log').innerHTML = '';
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.target = 'update_frame';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = 'do_update_stream';
            form.appendChild(input);
            
            document.body.appendChild(form);
            form.submit();
        }

        function updateProgress(percent, msg) {
            const bar = document.getElementById('update-progress');
            bar.style.width = percent + '%';
            bar.innerText = percent + '%';
            if (msg) {
                const log = document.getElementById('update-log');
                log.innerHTML += msg + '<br>';
                log.scrollTop = log.scrollHeight;
            }
            if (percent >= 100) {
                setTimeout(() => {
                    alert("PROSES UPDATE SUKSES! Aplikasi akan direfresh ulang.");
                    window.location.reload();
                }, 2000);
            }
        }
        </script>
        
        <p style="margin-top: 15px; color: var(--text-muted); font-size: 0.9rem;">
            Catatan:
            <br>- Update akan menjalankan perintah <code>git pull</code> di folder aplikasi.
            <br>- Pastikan server memiliki akses git dan izin file yang benar.
            <?php 
                $currentRepo = "zxxahsan/gembok"; // Default
                $updateUrl = defined('GEMBOK_UPDATE_VERSION_URL') ? GEMBOK_UPDATE_VERSION_URL : '';
                if (preg_match('/githubusercontent\.com\/([^\/]+\/[^\/]+)/', $updateUrl, $matches)) {
                    $currentRepo = $matches[1];
                }
            ?>
            <br>- Mengecek ke repositori GitHub <code><?php echo htmlspecialchars($currentRepo); ?></code>.
            <br>- Setelah instalasi awal, hapus file <code>install.sh</code> dari server jika pernah digunakan.
        </p>

        <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px;">
            <h4><i class="fas fa-code"></i> Developer Mode: Set Versi Baru</h4>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 10px;">
                Ubah angka versi di sini <strong>SEBELUM</strong> Anda melakukan Commit & Push ke GitHub. <br>Dengan begitu, server klien yang menggunakan aplikasi ini akan mendeteksi update baru.
            </p>
            <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="action" value="bump_version">
                <input type="text" name="new_version" value="<?php echo htmlspecialchars($localVersion); ?>" class="form-control" style="width: 150px; padding: 8px;">
                <button type="submit" class="btn btn-warning" style="padding: 8px 15px;">Set Versi Baru</button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
