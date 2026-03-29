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

// Calculate Next Invoice Generation Schedule
$leadDays = (int)getSetting('invoice_generate_days', 7);
$billingDay = (int)($customer['due_date'] ?? 1);
if ($billingDay == 0) $billingDay = 1;

$today = date('Y-m-d');
$todayTs = strtotime($today);
$currentMonth = (int)date('n');
$currentYear = (int)date('Y');

// We check this month and next month
$potentialDueDates = [
    date('Y-m-d', mktime(0, 0, 0, $currentMonth, $billingDay, $currentYear)),
    date('Y-m-d', mktime(0, 0, 0, $currentMonth + 1, $billingDay, $currentYear))
];

$nextGenDate = '';
$displayNextDueDate = '';

foreach ($potentialDueDates as $dueDate) {
    if (strtotime($dueDate) > $todayTs) {
        $genDate = date('Y-m-d', strtotime("-{$leadDays} days", strtotime($dueDate)));
        
        $nextGenDate = $genDate;
        $displayNextDueDate = $dueDate;
        
        // Check if an invoice for this due date already exists
        $existing = fetchOne("SELECT id FROM invoices WHERE customer_id = ? AND due_date = ?", [$customer['id'], $dueDate]);
        if (!$existing) {
            // This is the next one to be generated
            break;
        }
    }
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
        
        <div style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <!-- Status Pill -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 140px; box-shadow: var(--shadow-card);">
                <div style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>; box-shadow: 0 0 8px <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;"></div>
                <div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Status ONU</div>
                    <div style="font-size: 0.9rem; font-weight: 600; color: <?php echo $onuOnline ? 'var(--neon-green)' : 'var(--neon-red)'; ?>;"><?php echo $onuOnline ? 'Online' : 'Offline'; ?></div>
                </div>
            </div>
            
            <!-- Uptime Pill -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 140px; box-shadow: var(--shadow-card);">
                <i class="fas fa-clock" style="color: var(--neon-orange); font-size: 1.1rem;"></i>
                <div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Uptime</div>
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($pppoeUptime); ?></div>
                </div>
            </div>
            
            <!-- Devices Pill -->
            <?php if (isset($onuDevices)): ?>
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 140px; box-shadow: var(--shadow-card);">
                <i class="fas fa-users" style="color: var(--neon-purple); font-size: 1.1rem;"></i>
                <div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Perangkat</div>
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($onuDevices); ?> Device</div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Signal Pill -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 10px 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 140px; box-shadow: var(--shadow-card);">
                <i class="fas fa-signal" style="color: var(--neon-cyan); font-size: 1.1rem;"></i>
                <div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Signal</div>
                    <div style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($onuSignal); ?> dBm</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Package Info & Billing Schedule -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px;">
        <div class="card" style="margin-bottom: 0; border-left: 5px solid var(--neon-cyan); padding: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 30px;">
                <div style="flex: 1; min-width: 250px;">
                    <p style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; margin-bottom: 12px; letter-spacing: 1px;">
                        <i class="fas fa-box" style="margin-right: 8px; color: var(--neon-cyan);"></i> Paket Layanan Saat Ini
                    </p>
                    <h2 style="font-size: 2.5rem; font-weight: 900; margin-bottom: 10px; color: var(--text-primary); letter-spacing: -1px; line-height: 1.1;">
                        <?php echo htmlspecialchars($package['name'] ?? 'Tanpa Paket'); ?>
                    </h2>
                    <div class="price-stack" style="color: var(--neon-green); font-size: 1.6rem; font-weight: 800; display: flex; align-items: baseline; gap: 8px;">
                        <?php echo formatCurrency($package['price'] ?? 0); ?> 
                        <span style="font-size: 0.9rem; color: var(--text-secondary); font-weight: 400; text-transform: lowercase;">per bulan</span>
                    </div>
                </div>
                
                <div style="text-align: right; min-width: 155px; border-left: 1px solid var(--border-color); padding-left: 20px;">
                    <div style="margin-bottom: 15px;">
                        <div style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-bottom: 10px;">Status Layanan</div>
                        <?php if (isset($customer['status']) && $customer['status'] === 'active'): ?>
                            <span class="badge badge-success" style="font-size: 1rem; padding: 6px 18px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 255, 136, 0.2);">Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-warning" style="font-size: 1rem; padding: 6px 18px; border-radius: 8px; box-shadow: 0 4px 10px rgba(253, 126, 20, 0.2);">Isolir</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 0; background: rgba(0, 200, 255, 0.05); border: 1px solid rgba(0, 200, 255, 0.2); padding: 25px;">
            <h3 style="margin-bottom: 15px; color: var(--neon-cyan); font-size: 1.1rem; text-transform: uppercase; font-weight: 800; letter-spacing: 1px;">
                <i class="fas fa-calendar-check"></i> Jadwal Tagihan
            </h3>
            <div style="margin-bottom: 18px;">
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">Invoice Berikutnya</div>
                <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-primary); letter-spacing: -0.5px;">
                    <?php echo $nextGenDate ? formatDate($nextGenDate) : 'Segera'; ?>
                </div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">Batas Akhir Bayar</div>
                <div style="font-size: 1.2rem; font-weight: 700; color: var(--neon-orange); letter-spacing: -0.5px;">
                    <?php echo $displayNextDueDate ? formatDate($displayNextDueDate) : 'Sesuai Siklus'; ?>
                </div>
            </div>
            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <small style="color: var(--text-muted); line-height: 1.4; font-size: 0.75rem; display: block;">
                    <i class="fas fa-info-circle" style="color: var(--neon-cyan); margin-right: 5px;"></i> Tagihan akan dikirimkan otomatis melalui WhatsApp.
                </small>
            </div>
        </div>
    </div>

    <!-- Monthly Usage Info -->
    <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--neon-purple);">
        <div style="text-align: center; padding: 10px 0;">
            <h3 style="margin-bottom: 10px; color: var(--neon-purple);">
                <i class="fas fa-chart-line"></i> Total Penggunaan Bulan <?php echo date('F Y'); ?>
            </h3>
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
            
            <div style="display: flex; justify-content: center; margin-top: 15px;">
                <div style="background: var(--bg-secondary); padding: 20px 40px; border-radius: 12px; text-align: center; border-bottom: 4px solid var(--neon-purple); box-shadow: var(--shadow-card); min-width: 250px;">
                    <div style="font-size: 2.5rem; font-weight: 800; color: var(--text-primary); letter-spacing: -1px;">
                        <?php echo formatBytes($grandTotalBytes); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Traffic Monitor -->
    <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--neon-cyan);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="color: var(--neon-cyan); margin: 0;">
                <i class="fas fa-satellite-dish"></i> Kecepatan Internet Real-time
            </h3>
            <span id="trafficStatusBadge" class="badge badge-warning">...</span>
        </div>
        
        <div style="background: var(--bg-secondary); border-radius: 12px; padding: 15px; height: 300px; position: relative; border: 1px solid var(--border-color);">
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
    
    function getChartColors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            dnBorder: isDark ? '#00f5ff' : '#0d6efd',
            dnBg: isDark ? 'rgba(0, 245, 255, 0.2)' : 'rgba(13, 110, 253, 0.1)',
            upBorder: isDark ? '#ff00aa' : '#d63384',
            upBg: isDark ? 'rgba(255, 0, 170, 0.2)' : 'rgba(214, 51, 132, 0.1)',
            grid: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.05)',
            text: isDark ? '#888' : '#6c757d'
        };
    }

    let colors = getChartColors();

    const config = {
        type: 'line',
        data: {
            labels: Array(20).fill(''),
            datasets: [
                {
                    label: 'Download (Mbps)',
                    borderColor: colors.dnBorder,
                    backgroundColor: colors.dnBg,
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: true,
                    data: Array(20).fill(0),
                    tension: 0.4
                },
                {
                    label: 'Upload (Mbps)',
                    borderColor: colors.upBorder,
                    backgroundColor: colors.upBg,
                    borderWidth: 2,
                    pointRadius: 0,
                    fill: true,
                    data: Array(20).fill(0),
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
                    grid: { color: colors.grid },
                    ticks: { color: colors.text }
                },
                x: {
                    grid: { display: false },
                    ticks: { display: false }
                }
            },
            plugins: {
                legend: { labels: { color: colors.text } },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    };
    
    const trafficChart = new Chart(ctx, config);

    // Listen for theme changes to update chart
    window.addEventListener('themeChanged', function() {
        colors = getChartColors();
        trafficChart.data.datasets[0].borderColor = colors.dnBorder;
        trafficChart.data.datasets[0].backgroundColor = colors.dnBg;
        trafficChart.data.datasets[1].borderColor = colors.upBorder;
        trafficChart.data.datasets[1].backgroundColor = colors.upBg;
        trafficChart.options.scales.y.grid.color = colors.grid;
        trafficChart.options.scales.y.ticks.color = colors.text;
        trafficChart.options.plugins.legend.labels.color = colors.text;
        trafficChart.update();
    });
    
    let lastRx = 0;
    let lastTx = 0;
    let lastTime = 0;

    function fetchLiveTraffic() {
        fetch('<?php echo APP_URL; ?>/api/customer_traffic.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    badge.className = 'badge badge-success';
                    badge.innerHTML = 'Online';
                    
                    const currentTime = data.timestamp_ms;
                    
                    let dnMbps = 0;
                    let upMbps = 0;
                    
                    if (lastTime !== 0) {
                        const timeDelta = currentTime - lastTime;
                        if (timeDelta > 0) {
                            const byteDeltaDn = Math.max(0, data.tx_bytes - lastTx);
                            const byteDeltaUp = Math.max(0, data.rx_bytes - lastRx);
                            
                            dnMbps = ((byteDeltaDn * 8) / timeDelta) / 1000000;
                            upMbps = ((byteDeltaUp * 8) / timeDelta) / 1000000;
                        }
                    }
                    
                    lastRx = data.rx_bytes;
                    lastTx = data.tx_bytes;
                    lastTime = currentTime;

                    if (lastTime !== 0) {
                        trafficChart.data.datasets[0].data.push(dnMbps.toFixed(2));
                        trafficChart.data.datasets[1].data.push(upMbps.toFixed(2));
                        trafficChart.data.datasets[0].data.shift();
                        trafficChart.data.datasets[1].data.shift();
                        trafficChart.update('none'); // Update without animation for smoother real-time
                    }
                } else {
                    badge.className = 'badge badge-warning';
                    badge.innerHTML = 'Offline';
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

    setInterval(fetchLiveTraffic, 3000);
    fetchLiveTraffic();
});
</script>

<style>
@media (max-width: 600px) {
    .price-stack {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 2px !important;
    }
    .price-stack span {
        font-size: 0.8rem !important;
    }
}
</style>

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
