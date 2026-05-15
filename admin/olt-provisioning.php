<?php
/**
 * OLT Provisioning UI
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'OLT Provisioning';
$olts = getAllOlts();
$logs = getOltProvisioningLogs(10);
$customers = fetchAll("SELECT id, name, pppoe_username FROM customers ORDER BY name ASC");

ob_start();
?>

<style>
    .provisioning-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 24px;
    }

    .form-section {
        background: var(--bg-card);
        border-radius: var(--border-radius-lg);
        border: 1px solid var(--border-color);
        padding: 30px;
        box-shadow: var(--shadow-card);
    }

    .section-title {
        font-size: 1.2rem;
        font-weight: 700;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--neon-cyan);
    }

    .input-group {
        display: flex;
        gap: 10px;
        align-items: flex-end;
    }

    .input-group .form-group {
        flex: 1;
        margin-bottom: 0;
    }

    .terminal-window {
        background: #000;
        color: #00ff00;
        font-family: 'Courier New', Courier, monospace;
        padding: 15px;
        border-radius: 12px;
        height: 300px;
        overflow-y: auto;
        font-size: 0.85rem;
        border: 1px solid #333;
        margin-top: 20px;
        box-shadow: inset 0 0 10px rgba(0, 255, 0, 0.1);
    }

    .terminal-line { margin-bottom: 4px; }
    .terminal-cmd { color: #fff; font-weight: bold; }
    .terminal-res { color: #00ff00; opacity: 0.8; }

    .omci-fields {
        display: none;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px dashed var(--border-color);
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .detect-btn {
        height: 48px;
        padding: 0 20px;
    }

    .status-badge {
        font-size: 0.75rem;
        padding: 2px 8px;
        border-radius: 4px;
    }

    @media (max-width: 992px) {
        .provisioning-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="provisioning-grid">
    <!-- Left Column: Form -->
    <div class="form-section">
        <div class="section-title">
            <i class="fas fa-microchip"></i> <span>Form Provisioning ONU</span>
        </div>

        <form id="provisionForm">
            <!-- OLT Selection -->
            <div class="form-group">
                <label class="form-label">Pilih OLT</label>
                <select name="olt_id" id="oltSelect" class="form-control" required onchange="updateProfiles()">
                    <option value="">-- Pilih OLT --</option>
                    <?php foreach ($olts as $o): ?>
                        <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['name']); ?> (<?php echo htmlspecialchars($o['host']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- SN & Detection -->
            <div class="form-group">
                <label class="form-label">Serial Number (SN)</label>
                <div class="input-group">
                    <div class="form-group">
                        <input type="text" name="sn" id="onuSN" class="form-control" placeholder="Contoh: ZTEGC1234567" required>
                    </div>
                    <button type="button" onclick="detectOnu()" class="btn btn-primary detect-btn" id="btnDetect">
                        <i class="fas fa-search"></i> Deteksi
                    </button>
                </div>
                <small id="detectStatus" style="display:block; margin-top:5px;"></small>
            </div>

            <!-- Basic Config -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="form-group">
                    <label class="form-label">Port GPON</label>
                    <input type="text" name="port" id="gponPort" class="form-control" placeholder="1/1/1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">ONU ID</label>
                    <input type="number" name="onu_id" id="onuId" class="form-control" placeholder="Otomatis" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Nama Pelanggan (Description)</label>
                <input type="text" name="name" id="onuName" class="form-control" placeholder="Nama Lengkap" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label class="form-label">VLAN Service</label>
                    <input type="number" name="vlan" id="serviceVlan" class="form-control" placeholder="444" required>
                </div>
                <div class="form-group">
                    <label class="form-label">TCONT Profile</label>
                    <select name="tcont_profile" id="tcontProfile" class="form-control">
                        <option value="default">default</option>
                    </select>
                </div>
            </div>

            <!-- Mode Switch -->
            <div style="margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.03); border-radius: 10px;">
                <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; font-weight: bold;">
                    <input type="checkbox" name="mode" id="omciToggle" value="omci" onchange="toggleOmci()">
                    Aktivasi Mode OMCI (Full Otomatis)
                </label>
            </div>

            <!-- OMCI Advanced Fields -->
            <div id="omciFields" class="omci-fields">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">PPPoE Username</label>
                        <input type="text" name="pppoe_user" id="pppoeUser" class="form-control" placeholder="user123">
                    </div>
                    <div class="form-group">
                        <label class="form-label">PPPoE Password</label>
                        <input type="text" name="pppoe_pass" id="pppoePass" class="form-control" placeholder="pass123">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label class="form-label">VLAN Profile</label>
                        <select name="vlan_profile" id="vlanProfile" class="form-control">
                            <option value="">-- Pilih Profile --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ACS URL (TR-069)</label>
                        <input type="text" name="acs_url" id="acsUrl" class="form-control" value="http://172.17.1.100:7547">
                    </div>
                </div>
            </div>

            <div style="margin-top: 30px;">
                <button type="submit" class="btn btn-success" style="width: 100%; height: 50px; font-size: 1.1rem;">
                    <i class="fas fa-rocket"></i> JALANKAN PROVISIONING
                </button>
            </div>
        </form>

        <!-- Command Terminal -->
        <div id="terminalContainer" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 25px;">
                <span style="font-weight: bold; font-size: 0.9rem; color: var(--text-secondary);">Command Execution Output</span>
                <button onclick="clearTerminal()" class="btn btn-secondary btn-sm">Clear</button>
            </div>
            <div class="terminal-window" id="terminal">
                <div class="terminal-line">Waiting for commands...</div>
            </div>
        </div>
    </div>

    <!-- Right Column: Sidebar info -->
    <div>
        <!-- Riwayat Singkat -->
        <div class="card" style="padding: 20px;">
            <div class="section-title" style="font-size: 1rem; margin-bottom: 15px;">
                <i class="fas fa-history"></i> <span>Riwayat Terakhir</span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php if (empty($logs)): ?>
                    <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center;">Belum ada riwayat.</p>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <div style="background: rgba(255,255,255,0.03); padding: 12px; border-radius: 10px; border-left: 3px solid <?php echo $l['status'] === 'success' ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px;">
                                <span style="font-weight: bold; font-size: 0.9rem;"><?php echo htmlspecialchars($l['onu_sn']); ?></span>
                                <span class="status-badge" style="background: <?php echo $l['status'] === 'success' ? 'rgba(0, 255, 136, 0.1)' : 'rgba(255, 71, 87, 0.1)'; ?>; color: <?php echo $l['status'] === 'success' ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;">
                                    <?php echo strtoupper($l['status']); ?>
                                </span>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                <?php echo htmlspecialchars($l['olt_name']); ?> | Port <?php echo htmlspecialchars($l['gpon_port']); ?>:<?php echo $l['onu_index']; ?>
                            </div>
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 5px;">
                                <?php echo formatDate($l['created_at'], 'd/m/Y H:i'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <a href="olt-logs.php" style="display: block; text-align: center; margin-top: 15px; font-size: 0.85rem; color: var(--neon-cyan);">Lihat Semua Riwayat</a>
        </div>

        <!-- Sync OLT Data -->
        <div class="card" style="padding: 20px; margin-top: 24px; background: linear-gradient(135deg, rgba(0, 245, 255, 0.05), rgba(191, 0, 255, 0.05)); border: 1px solid var(--border-color);">
            <div class="section-title" style="font-size: 1rem; margin-bottom: 10px;">
                <i class="fas fa-sync-alt"></i> <span>Sinkronisasi OLT</span>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 15px;">Ambil profil VLAN dan TCONT terbaru dari perangkat OLT pilihan Anda.</p>
            <button onclick="syncProfiles()" id="btnSync" class="btn btn-secondary" style="width: 100%; border-color: var(--neon-cyan); color: var(--neon-cyan);">
                SINKRON PROFIL SEKARANG
            </button>
        </div>
    </div>
</div>

<script>
    function toggleOmci() {
        const fields = document.getElementById('omciFields');
        const isChecked = document.getElementById('omciToggle').checked;
        fields.style.display = isChecked ? 'block' : 'none';
        
        // Make fields required if OMCI is active
        const inputs = fields.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (isChecked && input.id !== 'acsUrl') { // acsUrl has default
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        });
    }

    function updateProfiles() {
        const oltId = document.getElementById('oltSelect').value;
        if (!oltId) return;

        // Reset dropdowns
        const tcont = document.getElementById('tcontProfile');
        const vlan = document.getElementById('vlanProfile');
        tcont.innerHTML = '<option value="default">default</option>';
        vlan.innerHTML = '<option value="">-- Pilih Profile --</option>';

        // Fetch from API (local database cache)
        fetch(`../api/olt_handler.php?action=get_cached_profiles&olt_id=${oltId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    data.tconts.forEach(p => tcont.innerHTML += `<option value="${p}">${p}</option>`);
                    data.vlans.forEach(p => vlan.innerHTML += `<option value="${p}">${p}</option>`);
                }
            });
    }

    function syncProfiles() {
        const oltId = document.getElementById('oltSelect').value;
        if (!oltId) {
            alert('Silakan pilih OLT terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btnSync');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mensinkronisasi...';
        btn.disabled = true;

        fetch(`../api/olt_handler.php?action=sync_profiles&olt_id=${oltId}`)
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                if (data.success) {
                    alert('Sinkronisasi Berhasil!');
                    updateProfiles();
                } else {
                    alert('Gagal: ' + data.message);
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                alert('Terjadi kesalahan koneksi.');
            });
    }

    function detectOnu() {
        const oltId = document.getElementById('oltSelect').value;
        const sn = document.getElementById('onuSN').value;
        const status = document.getElementById('detectStatus');

        if (!oltId || !sn) {
            alert('Pilih OLT dan isi SN terlebih dahulu.');
            return;
        }

        const btn = document.getElementById('btnDetect');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;
        status.innerHTML = '<span style="color:var(--neon-cyan)">Sedang mencari di OLT...</span>';

        fetch(`../api/olt_handler.php?action=detect_onu&olt_id=${oltId}&sn=${sn}`)
            .then(res => res.json())
            .then(data => {
                btn.innerHTML = '<i class="fas fa-search"></i> Deteksi';
                btn.disabled = false;
                if (data.success) {
                    status.innerHTML = '<span style="color:var(--neon-green)">ONU Ditemukan! Memetakan port...</span>';
                    document.getElementById('gponPort').value = data.data.port;
                    document.getElementById('onuId').value = data.data.next_id;
                } else {
                    status.innerHTML = `<span style="color:var(--neon-red)">${data.message}</span>`;
                }
            })
            .catch(err => {
                btn.innerHTML = '<i class="fas fa-search"></i> Deteksi';
                btn.disabled = false;
                status.innerHTML = '<span style="color:var(--neon-red)">Kesalahan Koneksi.</span>';
            });
    }

    document.getElementById('provisionForm').onsubmit = function(e) {
        e.preventDefault();
        
        const terminal = document.getElementById('terminal');
        const container = document.getElementById('terminalContainer');
        const btn = this.querySelector('button[type="submit"]');
        
        if (!confirm('Apakah data sudah benar? Proses provisioning akan segera dimulai.')) return;

        container.style.display = 'block';
        terminal.innerHTML = '<div class="terminal-line">Connecting to OLT...</div>';
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> SEDANG MEMPROSES...';

        const formData = new FormData(this);
        const jsonData = {};
        formData.forEach((value, key) => jsonData[key] = value);
        jsonData.mode = document.getElementById('omciToggle').checked ? 'omci' : 'standard';

        fetch('../api/olt_handler.php?action=provision', {
            method: 'POST',
            body: JSON.stringify(jsonData)
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket"></i> JALANKAN PROVISIONING';
            
            if (data.success) {
                terminal.innerHTML = '';
                data.logs.forEach(log => {
                    terminal.innerHTML += `<div class="terminal-line"><span class="terminal-cmd">> ${log.command}</span></div>`;
                    terminal.innerHTML += `<div class="terminal-line"><span class="terminal-res">${log.response.replace(/\n/g, '<br>')}</span></div>`;
                });
                terminal.innerHTML += '<div class="terminal-line" style="color:var(--neon-green); font-weight:bold; margin-top:10px;">>>> SUCCESS: Provisioning ONU selesai!</div>';
                terminal.scrollTop = terminal.scrollHeight;
                alert('Provisioning Berhasil!');
            } else {
                terminal.innerHTML += `<div class="terminal-line" style="color:var(--neon-red)">>>> ERROR: ${data.message}</div>`;
                alert('Gagal: ' + data.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket"></i> JALANKAN PROVISIONING';
            terminal.innerHTML += '<div class="terminal-line" style="color:var(--neon-red)">>>> ERROR: Network failed.</div>';
            alert('Terjadi kesalahan koneksi.');
        });
    };

    function clearTerminal() {
        document.getElementById('terminal').innerHTML = '<div class="terminal-line">Waiting for commands...</div>';
    }

    // Load profiles on first selection
    document.addEventListener('DOMContentLoaded', updateProfiles);
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
