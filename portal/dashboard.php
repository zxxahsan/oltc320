<?php
/**
 * Customer Portal Dashboard
 */

require_once '../includes/auth.php';
requireCustomerLogin();

$customerSession = getCurrentCustomer();

// Fetch fresh customer data from database to ensure synchronization
if ($customerSession && isset($customerSession['id'])) {
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerSession['id']]);
    
    // Update session with fresh data
    if ($customer) {
        $customer['logged_in'] = true;
        $customer['login_time'] = $customerSession['login_time'] ?? time();
        $_SESSION['customer'] = $customer;
    } else {
        $customer = $customerSession;
    }
} else {
    $customer = $customerSession;
}

// Safely get the package
$package = null;
if (isset($customer['package_id']) && !empty($customer['package_id'])) {
    $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);
}

// Ensure Monthly Usage columns exist
try {
    $checkCol = getDB()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'usage_bytes_in'");
    $checkCol->execute();
    if (!$checkCol->fetch()) {
        getDB()->exec("ALTER TABLE customers ADD COLUMN usage_bytes_in BIGINT DEFAULT 0, ADD COLUMN usage_bytes_out BIGINT DEFAULT 0, ADD COLUMN usage_last_reset DATE");
    }
} catch (Exception $e) {}

// Monthly stats defaults
$monthlyRx = $customer['usage_bytes_in'] ?? 0;
$monthlyTx = $customer['usage_bytes_out'] ?? 0;
$lastReset = $customer['usage_last_reset'] ?? 'Belum Tercatat';

// ==========================================
// Integrasi Data ONU (GenieACS + MikroTik)
// ==========================================
$onuData = null;
$onuOnline = false;
$onuSignal = 'N/A';

require_once '../includes/mikrotik_api.php';
$pppoeUptime = 'Offline';
$pppoeUser = $customer['pppoe_username'] ?? '';

if (!empty($pppoeUser)) {
    // 1. Dapatkan Uptime Murni dari /ppp/active
    $activeSession = mikrotikGetActiveSessionByUsername($pppoeUser);
    if ($activeSession) {
        $pppoeUptime = $activeSession['uptime'] ?? 'N/A';
    }
}

// 2. Dapatkan Metadata Modem/Hardware dari GenieACS
$customerDevice = null;
if (!empty($customer['phone'])) {
    $customerDevice = genieacsGetDevice($customer['phone']);
}

if ($customerDevice) {
    $deviceId = $customerDevice['_id'] ?? $customerDevice['_deviceId']['_SerialNumber'] ?? $pppoeUser;
    $onuData = genieacsGetDeviceInfo($deviceId);
    
    if ($onuData && isset($onuData['status'])) {
        $onuOnline = ($onuData['status'] === 'online');
    }
    
    $rxPowerFromDevice = genieacsGetValue($customerDevice, 'VirtualParameters.RXPower');
    if ($rxPowerFromDevice !== null) {
        $onuSignal = $rxPowerFromDevice;
    } elseif ($onuData && isset($onuData['rx_power'])) {
        $onuSignal = is_array($onuData['rx_power']) ? ($onuData['rx_power']['_value'] ?? 'N/A') : $onuData['rx_power'];
    }
    if (is_array($onuSignal)) {
        $onuSignal = $onuSignal['_value'] ?? $onuSignal['value'] ?? 'N/A';
    }
    
    // Hitung Perangkat Terhubung (LAN/WiFi Hosts)
    $lanHosts = genieacsGetLanHosts($deviceId);
    $activeCount = 0;
    foreach ($lanHosts as $host) {
        if (isset($host['Active']) && $host['Active'] === true) {
            $activeCount++;
        }
    }
    $onuDevices = $activeCount > 0 ? $activeCount : count($lanHosts);
}

$pageTitle = 'Dashboard Pelanggan';

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <!-- Welcome Header & Hardware Indicators -->
    <div style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; align-items: flex-end; margin-bottom: 30px;">
        <div style="flex: 1; min-width: 300px;">
            <h2 style="color: var(--text-primary); margin-bottom: 5px;">Selamat Datang, <?php echo htmlspecialchars($customer['name']); ?>!</h2>
            <p style="color: var(--text-secondary);">Kelola layanan internet Anda dari portal ini.</p>
        </div>
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <!-- Status Pill -->
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px;">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>; box-shadow: 0 0 10px <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;"></div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Status ONU</div>
                    <div style="font-weight: 600; color: <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;"><?php echo $onuOnline ? 'Online' : 'Offline'; ?></div>
                </div>
            </div>
            
            <!-- Uptime Pill -->
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-clock" style="color: var(--neon-orange); font-size: 1.2rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Uptime PPPoE</div>
                    <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($pppoeUptime); ?></div>
                </div>
            </div>
            
            <!-- Devices Pill -->
            <?php if (isset($onuDevices)): ?>
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-users" style="color: var(--neon-purple); font-size: 1.2rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Perangkat Aktif</div>
                    <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($onuDevices); ?> Device</div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Signal Pill -->
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-color); padding: 10px 20px; border-radius: 12px; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-signal" style="color: var(--neon-cyan); font-size: 1.2rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Signal Fiber</div>
                    <div style="font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($onuSignal); ?> dBm</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Package Info -->
    <div class="card" style="margin-bottom: 30px;">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-box"></i> Paket Layanan Internet
        </h3>
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 8px;">
                    <?php echo htmlspecialchars($package['name'] ?? 'Tanpa Paket'); ?>
                </h2>
                <p style="color: var(--neon-green); font-size: 1.4rem; font-weight: 600;">
                    <?php echo formatCurrency($package['price'] ?? 0); ?> 
                    <span style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;">/ bulan</span>
                </p>
            </div>
            <div style="text-align: right;">
                <div style="margin-bottom: 10px;">
                    <span style="color: var(--text-secondary); font-size: 0.9rem; display: block; margin-bottom: 5px;">Status Berlangganan:</span>
                    <?php if (isset($customer['status']) && $customer['status'] === 'active'): ?>
                        <span class="badge badge-success" style="font-size: 1.1rem; padding: 8px 16px;">Aktif</span>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size: 1.1rem; padding: 8px 16px;">Isolir</span>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($customer['isolation_date'])): ?>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 10px;">
                        <i class="fas fa-calendar-alt"></i> Tanggal Isolir: Tanggal <?php echo $customer['isolation_date']; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Monthly Usage Info -->
    <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--neon-purple);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <h3 style="margin-bottom: 10px; color: var(--neon-purple);">
                    <i class="fas fa-chart-pie"></i> Total Penggunaan Bulan Ini
                </h3>
                <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
                    Periode berjalan dari <strong>1 <?php echo strftime('%B %Y'); ?></strong> hingga Hari Ini. (Termasuk total historis + aktif berjalan).
                </p>
            </div>
            
            <?php 
                // Kalkulasi Pendekatan WiFi + Historis Database
                $liveSessionRx = 0;
                $liveSessionTx = 0;
                if (!empty($pppoeUser)) {
                    $dynamicInterface = mikrotikGetInterfaceBytesByUsername($pppoeUser);
                    if ($dynamicInterface) {
                        $liveSessionRx = (float)($dynamicInterface['rx-byte'] ?? 0);
                        $liveSessionTx = (float)($dynamicInterface['tx-byte'] ?? 0);
                    }
                }
                
                // Live Session + DB Historical
                $dbTotalIn = (float)($customer['usage_bytes_in'] ?? 0);
                $dbTotalOut = (float)($customer['usage_bytes_out'] ?? 0);
                $lastRxTracked = (float)($customer['usage_last_rx'] ?? 0);
                $lastTxTracked = (float)($customer['usage_last_tx'] ?? 0);
                
                // Active session safely merges highest possible chunk to natively prevent tracking resets
                $activeRx = max((float)$liveSessionRx, $lastRxTracked);
                $activeTx = max((float)$liveSessionTx, $lastTxTracked);
                
                // Total Akumulasi Murni Keseluruhan
                $grandTotalBytes = $dbTotalIn + $dbTotalOut + $activeRx + $activeTx;
            ?>
            
            <div style="background: rgba(0,0,0,0.3); padding: 15px 30px; border-radius: 12px; text-align: center; border-left: 4px solid var(--neon-purple); margin-top: 10px;">
                <div style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary); letter-spacing: -1px;">
                    <?php echo formatBytes($grandTotalBytes); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Traffic Monitor -->
    <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--neon-cyan);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="color: var(--neon-cyan); margin: 0;">
                <i class="fas fa-satellite-dish"></i> Live Traffic Monitor
            </h3>
            <span id="trafficStatusBadge" class="badge badge-warning">Menyambungkan...</span>
        </div>
        
        <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 0.9rem;">
            Pantau kecepatan internet Anda secara <em>Real-time</em> langsung dari Router Utama.
        </p>
        
        <div style="background: rgba(0,0,0,0.3); border-radius: 12px; padding: 15px; height: 300px; position: relative;">
            <canvas id="liveTrafficChart"></canvas>
        </div>
    </div>

    <!-- Account Settings -->
    <div class="card" style="margin-bottom: 30px;">
        <h3 style="margin-bottom: 15px; color: var(--neon-green);">
            <i class="fas fa-user-cog"></i> Pengaturan Sandi Portal
        </h3>
        <p style="color: var(--text-secondary); margin-bottom: 15px;">
            Ubah kata sandi untuk login ke portal pelanggan.
        </p>
        
        <div class="form-group" style="max-width: 400px;">
            <label class="form-label">Password Baru</label>
            <input type="password" id="newPassword" class="form-control" placeholder="Minimal 6 karakter" style="margin-bottom: 15px;">
            <button class="btn btn-primary" onclick="changePortalPassword()">
                <i class="fas fa-key"></i> Simpan Password
            </button>
        </div>
    </div>

</div>

<!-- Alert Modal -->
<div id="alertModal" style="display: none; position: fixed; top: 20px; right: 20px; z-index: 3000;">
    <div class="alert" id="alertContent"></div>
</div>

<script>
function showAlert(message, type = 'success') {
    const modal = document.getElementById('alertModal');
    const content = document.getElementById('alertContent');
    
    content.className = 'alert alert-' + type;
    content.innerHTML = '<i class="' + (type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle') + '"></i> ' + message;
    
    modal.style.display = 'block';
    
    setTimeout(() => {
        modal.style.display = 'none';
    }, 5000);
}

function changePortalPassword() {
    const newPassword = document.getElementById('newPassword').value;
    
    if (newPassword.length < 6) {
        showAlert('Password portal minimal 6 karakter', 'error');
        return;
    }
    
    fetch('<?php echo APP_URL; ?>/api/customer_portal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'change_password',
            new_password: newPassword 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('Password portal berhasil diperbarui. Silakan gunakan password baru pada login berikutnya.');
            document.getElementById('newPassword').value = '';
        } else {
            showAlert('Gagal memperbarui password: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showAlert('Terjadi kesalahan sistem.', 'error');
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('liveTrafficChart').getContext('2d');
    const badge = document.getElementById('trafficStatusBadge');
    
    // Gradient configs
    let gradientDn = ctx.createLinearGradient(0, 0, 0, 300);
    gradientDn.addColorStop(0, 'rgba(0, 245, 255, 0.5)'); // Neon Cyan
    gradientDn.addColorStop(1, 'rgba(0, 245, 255, 0.0)');
    
    let gradientUp = ctx.createLinearGradient(0, 0, 0, 300);
    gradientUp.addColorStop(0, 'rgba(255, 0, 170, 0.5)'); // Neon Pink
    gradientUp.addColorStop(1, 'rgba(255, 0, 170, 0.0)');

    const config = {
        type: 'line',
        data: {
            labels: Array(15).fill(''),
            datasets: [
                {
                    label: 'Download (Mbps)',
                    borderColor: '#00f5ff',
                    backgroundColor: gradientDn,
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: true,
                    data: Array(15).fill(0),
                    tension: 0.4
                },
                {
                    label: 'Upload (Mbps)',
                    borderColor: '#ff00aa',
                    backgroundColor: gradientUp,
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: true,
                    data: Array(15).fill(0),
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 400,
                easing: 'linear'
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.05)' },
                    ticks: { color: '#888' }
                },
                x: {
                    grid: { display: false },
                    ticks: { display: false }
                }
            },
            plugins: {
                legend: { labels: { color: '#fff' } },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    };
    
    
    let lastRx = 0;
    let lastTx = 0;
    let lastTime = 0;

    const trafficChart = new Chart(ctx, config);

    function fetchLiveTraffic() {
        fetch('<?php echo APP_URL; ?>/api/customer_traffic.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    badge.className = 'badge badge-success';
                    badge.innerHTML = '<i class="fas fa-wifi"></i> Online';
                    
                    const currentTime = data.timestamp_ms;
                    
                    let dnMbps = 0;
                    let upMbps = 0;
                    
                    // First poll establishes the baseline
                    if (lastTime !== 0) {
                        const timeDelta = currentTime - lastTime;
                        if (timeDelta > 0) {
                            // RouterOS: tx_bytes is Customer's Download stream, rx_bytes is Customer's Upload
                            const byteDeltaDn = Math.max(0, data.tx_bytes - lastTx);
                            const byteDeltaUp = Math.max(0, data.rx_bytes - lastRx);
                            
                            // Convert pure Bytes to Bits, divide by TimeDelta, scale down to Mbps
                            dnMbps = ((byteDeltaDn * 8) / timeDelta) / 1000000;
                            upMbps = ((byteDeltaUp * 8) / timeDelta) / 1000000;
                        }
                    }
                    
                    lastRx = data.rx_bytes;
                    lastTx = data.tx_bytes;
                    lastTime = currentTime;

                    // Push new data and shift old
                    if (lastTime !== 0 && dnMbps !== 0 && upMbps !== 0) {
                        trafficChart.data.datasets[0].data.push(dnMbps.toFixed(2));
                        trafficChart.data.datasets[1].data.push(upMbps.toFixed(2));
                        
                        trafficChart.data.datasets[0].data.shift();
                        trafficChart.data.datasets[1].data.shift();
                        
                        trafficChart.update();
                    }
                } else {
                    badge.className = 'badge badge-error';
                    badge.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.message || 'Offline');
                    
                    trafficChart.data.datasets[0].data.push(0);
                    trafficChart.data.datasets[1].data.push(0);
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                    trafficChart.update();
                }
            })
            .catch(err => {
                badge.className = 'badge badge-warning';
                badge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Gagal';
            });
    }

    // Ping every 3 seconds
    setInterval(fetchLiveTraffic, 3000);
    fetchLiveTraffic();
});
</script>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
