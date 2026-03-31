<?php
/**
 * OLT Deep Diagnostic Tool (V8.4)
 * Standalone investigation tool for raw OLT CLI output
 */

require_once '../includes/auth.php';
requireAdminLogin();
require_once '../includes/olt_api.php';

$olts = fetchAll("SELECT * FROM olt_configs ORDER BY name ASC");
$output = "";
$regex_results = [];
$status_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['olt_id'])) {
    $olt_id = (int)$_POST['olt_id'];
    $action = $_POST['action'] ?? 'scan';
    $olt = fetchOne("SELECT * FROM olt_configs WHERE id = ?", [$olt_id]);
    
    if ($olt) {
        $client = new OltTelnetClient($olt['host'], $olt['port']);
        try {
            $status_msg .= "Menghubungkan ke " . $olt['host'] . ":" . $olt['port'] . "... ";
            $client->connect($olt['username'], $olt['password']);
            $status_msg .= "LOGIN OK. ";
            
            if (!empty($olt['enable_password'])) {
                $status_msg .= "Memasuki mode privilege... ";
                $client->enable($olt['enable_password']);
                $status_msg .= "ENABLE OK. ";
            }

            if ($action === 'check') {
                $output = "Koneksi ke OLT BERHASIL.\nHost: " . $olt['host'] . "\nPrompt: Login Berhasil.";
            } else {
                $status_msg .= "Menjalankan perintah pindaian... ";
                
                // 1. RAW GPON INFO
                $rawInfo = $client->execute("show gpon onu information");
                $output .= "=== RAW GPON ONU INFORMATION ===\n" . $rawInfo . "\n\n";
                
                // 2. RAW GPON UNAUTH
                $rawUnauth = $client->execute("show gpon onu unauthentication");
                $output .= "=== RAW GPON ONU UNAUTHENTICATION ===\n" . $rawUnauth . "\n\n";
                
                // 3. RAW EPON (FALLBACK)
                $rawEpon = $client->execute("show epon onu-list");
                $output .= "=== RAW EPON ONU-LIST (Fallback) ===\n" . $rawEpon . "\n\n";
                
                // TEST REGEX ON THE FLY
                $all_raw = $rawInfo . "\n" . $rawUnauth;
                if (preg_match_all('/0\/(\d+)\s+.*?\s+([A-Z0-9]{8,16})/i', $all_raw, $m, PREG_SET_ORDER)) {
                    foreach($m as $match) {
                        $regex_results[] = "Ditemukan Port: 0/" . $match[1] . " | SN: " . $match[2];
                    }
                }
            }
            $client->disconnect();
        } catch (Exception $e) {
            $status_msg .= "GAGAL: " . $e->getMessage();
            $output = "ERROR DIAGNOSA:\n" . $e->getMessage();
        }
    }
}

ob_start();
?>

<div class="card" style="max-width: 1000px; margin: 20px auto;">
    <div class="card-header"><h3><i class="fas fa-stethoscope"></i> OLT Deep Diagnostic Tool (V8.4)</h3></div>
    <div style="padding: 20px;">
        <p style="color: var(--text-secondary); margin-bottom: 20px;">Gunakan alat ini untuk melihat data asli dari OLT jika pendaftaran otomatis tidak menemukan SN.</p>
        
        <?php if ($status_msg): ?>
        <div style="padding: 15px; background: rgba(0,210,255,0.1); border: 1px solid var(--neon-cyan); border-radius: 8px; margin-bottom: 20px; color: #fff;">
            <strong>Status:</strong> <?php echo $status_msg; ?>
        </div>
        <?php endif; ?>

        <form method="POST" style="display: flex; gap: 15px; margin-bottom: 30px; align-items: flex-end;">
            <div class="form-group" style="flex: 1; margin:0;">
                <label>Pilih OLT untuk Investigasi</label>
                <select name="olt_id" class="form-control" required>
                    <option value="">-- Pilih OLT --</option>
                    <?php foreach($olts as $o): ?>
                    <option value="<?php echo $o['id']; ?>" <?php echo (isset($olt_id) && $olt_id == $o['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($o['name']); ?> (<?php echo $o['host']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="action" value="check" class="btn btn-secondary">Cek Koneksi</button>
            <button type="submit" name="action" value="scan" class="btn btn-primary"><i class="fas fa-search"></i> Ambil Data Mentah</button>
        </form>

        <?php if ($regex_results): ?>
        <div style="margin-bottom: 25px;">
            <h4 style="color: var(--neon-green); margin-bottom: 10px;">Simulasi Deteksi SN (System "Vision"):</h4>
            <ul style="background: rgba(57, 255, 20, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(57, 255, 20, 0.2); list-style: none;">
                <?php foreach($regex_results as $res): ?>
                <li style="margin-bottom: 5px; color: #fff;"><i class="fas fa-check-circle" style="color: var(--neon-green);"></i> <?php echo htmlspecialchars($res); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($output): ?>
        <div>
            <h4 style="color: var(--neon-cyan); margin-bottom: 10px;">Raw CLI Output (Harta Karun Asli):</h4>
            <div style="position: relative;">
                <textarea id="rawOutput" style="width: 100%; height: 500px; background: #000; color: #39FF14; font-family: 'Courier New', Courier, monospace; padding: 15px; border-radius: 8px; border: 1px solid #333; font-size: 13px;" readonly><?php echo htmlspecialchars($output); ?></textarea>
                <button onclick="copyRaw()" style="position: absolute; top: 10px; right: 20px; background: var(--neon-cyan); color: #000; border: none; padding: 5px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px;">Salin Teks</button>
            </div>
            <p style="margin-top: 10px; font-size: 14px; color: #ffc107;"><i class="fas fa-info-circle"></i> Jika "RAW" kosong tapi SN ada di OLT, hubungi IT untuk cek firewall/izin telnet.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyRaw() {
    const el = document.getElementById('rawOutput');
    el.select();
    document.execCommand('copy');
    alert('Teks berhasil disalin. Silakan berikan kepada asisten pendaftaran Anda!');
}
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
