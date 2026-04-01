<?php
/**
 * Async Dashboard (V3.1)
 * Optimized for performance by moving router calls to AJAX
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Dashboard';

// Get purely LOCAL statistics (very fast)
$stats = [
    'totalCustomers' => fetchOne("SELECT COUNT(*) as total FROM customers")['total'] ?? 0,
    'activeCustomers' => fetchOne("SELECT COUNT(*) as total FROM customers WHERE status = 'active'")['total'] ?? 0,
    'isolatedCustomers' => fetchOne("SELECT COUNT(*) as total FROM customers WHERE status = 'isolated'")['total'] ?? 0,
    'totalPackages' => fetchOne("SELECT COUNT(*) as total FROM packages")['total'] ?? 0,
    'totalInvoices' => fetchOne("SELECT COUNT(*) as total FROM invoices")['total'] ?? 0,
    'paidInvoices' => fetchOne("SELECT COUNT(*) as total FROM invoices WHERE status = 'paid'")['total'] ?? 0,
    'pendingInvoices' => fetchOne("SELECT COUNT(*) as total FROM invoices WHERE status = 'unpaid'")['total'] ?? 0,
    'totalRevenue' => fetchOne("
        SELECT SUM(amount) as total 
        FROM invoices 
        WHERE status = 'paid' 
        AND paid_at IS NOT NULL
        AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ")['total'] ?? 0,
];

// Get recent local data (very fast)
$recentInvoices = fetchAll("
    SELECT i.*, c.name as customer_name 
    FROM invoices i 
    LEFT JOIN customers c ON i.customer_id = c.id 
    ORDER BY i.created_at DESC 
    LIMIT 10
");

$recentCustomers = fetchAll("
    SELECT c.*, p.name as package_name 
    FROM customers c 
    LEFT JOIN packages p ON c.package_id = p.id 
    ORDER BY c.created_at DESC 
    LIMIT 5
");

// Sales Stats (Local)
$salesStats = [
    'totalSales' => fetchOne("SELECT COUNT(*) as total FROM sales_users")['total'] ?? 0,
    'todayVouchers' => fetchOne("SELECT COUNT(*) as total FROM hotspot_sales WHERE DATE(created_at) = CURDATE()")['total'] ?? 0,
    'todayRevenue' => fetchOne("SELECT SUM(selling_price) as total FROM hotspot_sales WHERE DATE(created_at) = CURDATE()")['total'] ?? 0,
    'todayProfit' => fetchOne("SELECT SUM(selling_price - price) as total FROM hotspot_sales WHERE DATE(created_at) = CURDATE()")['total'] ?? 0,
];

// MikroTik interface list (needed for traffic monitor selector)
// We'll try to get this from a local cache if available, or just use a placeholder
$interfaces = []; 

ob_start();
?>

<style>
    /* Skeleton Loading Animation */
    .skeleton {
        background: linear-gradient(90deg, rgba(255,255,255,0.05) 25%, rgba(255,255,255,0.1) 50%, rgba(255,255,255,0.05) 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
        border-radius: 4px;
        display: inline-block;
        min-width: 50px;
        height: 1.2em;
        vertical-align: middle;
    }
    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
</style>

<!-- ==================== MIKHMON V3 DASHBOARD SECTION ==================== -->

<!-- Router Info Bar (AJAX LOADED) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <!-- Date/Time & Uptime -->
    <div class="card" style="margin-bottom: 0;">
        <div style="padding: 10px; display: flex; align-items: center; gap: 18px;">
            <div style="width: 50px; height: 50px; border-radius: 14px; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fas fa-calendar-alt" style="color: #fff; font-size: 1.4rem;"></i>
            </div>
            <div>
                <div style="color: var(--text-primary); font-weight: 700; font-size: 1.1rem;"><?php echo date('d M Y H:i:s'); ?></div>
                <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 2px;">Uptime:
                    <span id="router-uptime" class="skeleton" style="min-width: 80px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Board Info -->
    <div class="card" style="margin-bottom: 0;">
        <div style="padding: 15px; display: flex; align-items: center; gap: 15px;">
            <div style="width: 45px; height: 45px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fas fa-info-circle" style="color: #fff; font-size: 1.2rem;"></i>
            </div>
            <div>
                <div id="router-board" style="color: var(--text-primary); font-weight: 600;" class="skeleton"></div>
                <div id="router-version" style="color: var(--text-muted); font-size: 0.85rem;" class="skeleton"></div>
            </div>
        </div>
    </div>

    <!-- CPU / Memory -->
    <div class="card" style="margin-bottom: 0;">
        <div style="padding: 15px; display: flex; align-items: center; gap: 15px;">
            <div style="width: 45px; height: 45px; border-radius: 10px; background: linear-gradient(135deg, #f59e0b, #ef4444); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fas fa-server" style="color: #fff; font-size: 1.2rem;"></i>
            </div>
            <div>
                <div style="color: var(--text-primary); font-weight: 600;">CPU: <span id="router-cpu" class="skeleton"></span></div>
                <div style="color: var(--text-muted); font-size: 0.85rem;">Free RAM: <span id="router-ram" class="skeleton"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- Hotspot Stats (Colored boxes - AJAX LOADED) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <a href="hotspot-user.php" style="text-decoration: none;">
        <div style="background: linear-gradient(135deg, #00f5ff, #0088cc); border-radius: 16px; padding: 24px; text-align: center; color: white;">
            <div id="hotspot-active" style="font-size: 2.8rem; font-weight: 800; line-height: 1;"><div class="skeleton" style="width: 40px; height: 50px;"></div></div>
            <div style="margin-top: 10px; font-weight: 500;"><i class="fas fa-laptop"></i> Hotspot Online</div>
        </div>
    </a>
    <a href="hotspot-user.php" style="text-decoration: none;">
        <div style="background: linear-gradient(135deg, #00ff88, #00a859); border-radius: 16px; padding: 24px; text-align: center; color: white;">
            <div id="hotspot-total" style="font-size: 2.8rem; font-weight: 800; line-height: 1;"><div class="skeleton" style="width: 40px; height: 50px;"></div></div>
            <div style="margin-top: 10px; font-weight: 500;"><i class="fas fa-users"></i> Hotspot Users</div>
        </div>
    </a>
    <a href="mikrotik.php" style="text-decoration: none;">
        <div style="background: linear-gradient(135deg, #bf00ff, #7a00a3); border-radius: 16px; padding: 24px; text-align: center; color: white;">
            <div id="pppoe-active" style="font-size: 2.8rem; font-weight: 800; line-height: 1;"><div class="skeleton" style="width: 40px; height: 50px;"></div></div>
            <div style="margin-top: 10px; font-weight: 500;"><i class="fas fa-network-wired"></i> PPPoE Online</div>
        </div>
    </a>
    <a href="voucher-editor.php" style="text-decoration: none;">
        <div style="background: linear-gradient(135deg, #ff00aa, #b30077); border-radius: 16px; padding: 24px; text-align: center; color: white;">
            <div style="font-size: 2.8rem; font-weight: 800; line-height: 1;"><i class="fas fa-magic"></i></div>
            <div style="margin-top: 10px; font-weight: 500;">Magic Editor</div>
        </div>
    </a>
</div>

<!-- ISP Billing Section (LOCAL DATA - Loaded Instantly) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div style="background: rgba(0,245,255,0.05); border: 1px solid var(--neon-cyan); border-radius: 16px; padding: 24px; text-align: center;">
        <div style="font-size: 2.5rem; font-weight: 800; color: var(--neon-cyan);"><?php echo $stats['totalCustomers']; ?></div>
        <div style="font-size: 0.9rem; color: var(--text-secondary);">Total Pelanggan</div>
    </div>
    <div style="background: rgba(0,255,136,0.05); border: 1px solid var(--neon-green); border-radius: 16px; padding: 24px; text-align: center;">
        <div style="font-size: 2.5rem; font-weight: 800; color: var(--neon-green);"><?php echo formatCurrency($stats['totalRevenue']); ?></div>
        <div style="font-size: 0.9rem; color: var(--text-secondary);">Omzet Bulan Ini</div>
    </div>
    <div style="background: rgba(255,107,53,0.05); border: 1px solid var(--neon-orange); border-radius: 16px; padding: 24px; text-align: center;">
        <div style="font-size: 2.5rem; font-weight: 800; color: var(--neon-orange);"><?php echo $stats['isolatedCustomers']; ?></div>
        <div style="font-size: 0.9rem; color: var(--text-secondary);">Isolir</div>
    </div>
    <div style="background: rgba(191,0,255,0.05); border: 1px solid var(--neon-purple); border-radius: 16px; padding: 24px; text-align: center;">
        <div style="font-size: 2.5rem; font-weight: 800; color: var(--neon-purple);"><?php echo $stats['totalPackages']; ?></div>
        <div style="font-size: 0.9rem; color: var(--text-secondary);">Varian Paket</div>
    </div>
</div>

<!-- Main Grid Section (Mixed) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 24px; margin-bottom: 24px;">
    <!-- Traffic Monitor (AJAX LOADED) -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title"><i class="fas fa-chart-line"></i> Live Traffic</h3>
            <select id="interfaceSelector" onchange="changeInterface(this.value)" class="form-control" style="width: auto; height: 32px; padding: 0 10px;">
                <option value="ether1">ether1</option>
                <option value="bridge">bridge</option>
            </select>
        </div>
        <canvas id="trafficChart" height="250"></canvas>
    </div>

    <!-- Hotspot Log -->
    <div class="card" style="margin-bottom: 0;">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-align-left"></i> Hotspot Log</h3></div>
        <div style="max-height: 290px; overflow-y: auto;">
            <table class="data-table" style="font-size: 0.85rem;" id="hotspotLogTable">
                <tbody><tr><td class="text-center p-4">Menunggu data...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Collections (Table Section) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 24px;">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Invoice Terbaru</h3></div>
        <table class="data-table">
            <thead><tr><th>INV</th><th>Pelanggan</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach($recentInvoices as $inv): ?>
                <tr>
                    <td><?php echo $inv['invoice_number']; ?></td>
                    <td><?php echo $inv['customer_name']; ?></td>
                    <td><span class="badge badge-<?php echo $inv['status'] == 'paid' ? 'success' : 'warning'; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Pelanggan Baru</h3></div>
        <table class="data-table">
            <thead><tr><th>Nama</th><th>Paket</th><th>Join</th></tr></thead>
            <tbody>
                <?php foreach($recentCustomers as $c): ?>
                <tr>
                    <td><?php echo $c['name']; ?></td>
                    <td><?php echo $c['package_name']; ?></td>
                    <td><?php echo date('d/m', strtotime($c['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // DASHBOARD LIVE DATA LOADER
    async function fetchDashboardStats() {
        try {
            const resp = await fetch('../api/dashboard_mikrotik.php');
            const data = await resp.json();
            
            if (data.success) {
                // Remove skeletons and add data
                document.getElementById('router-uptime').textContent = data.resource.uptime;
                document.getElementById('router-uptime').classList.remove('skeleton');
                
                document.getElementById('router-board').textContent = data.resource['board-name'];
                document.getElementById('router-board').classList.remove('skeleton');
                
                document.getElementById('router-version').textContent = 'V' + data.resource.version + ' (' + data.resource['architecture-name'] + ')';
                document.getElementById('router-version').classList.remove('skeleton');
                
                document.getElementById('router-cpu').textContent = data.resource['cpu-load'] + '%';
                document.getElementById('router-cpu').classList.remove('skeleton');
                
                const freeRamGb = (data.resource['free-memory'] / 1024 / 1024).toFixed(1);
                document.getElementById('router-ram').textContent = freeRamGb + ' MB';
                document.getElementById('router-ram').classList.remove('skeleton');
                
                document.getElementById('hotspot-active').innerHTML = data.hotspot_active;
                document.getElementById('hotspot-total').innerHTML = data.hotspot_users;
                document.getElementById('pppoe-active').innerHTML = data.pppoe_active;
            }
        } catch (e) {
            console.error("Dashboard Load Error: ", e);
        }
    }

    // TRAFFIC MONITOR INITIALIZATION
    const trafficCtx = document.getElementById('trafficChart').getContext('2d');
    const trafficChart = new Chart(trafficCtx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'RX (Download)',
                borderColor: '#00f5ff',
                backgroundColor: 'rgba(0,245,255,0.1)',
                data: [],
                fill: true,
                tension: 0.4
            }, {
                label: 'TX (Upload)',
                borderColor: '#bf00ff',
                backgroundColor: 'rgba(191,0,255,0.1)',
                data: [],
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                x: { grid: { display: false } }
            },
            plugins: { legend: { labels: { color: '#fff' } } }
        }
    });

    function fetchTraffic() {
        const iface = document.getElementById('interfaceSelector').value;
        fetch('../api/traffic.php?interface=' + iface)
            .then(r => r.json())
            .then(data => {
                if(!data || data.length < 2) return;
                const now = new Date().toLocaleTimeString();
                trafficChart.data.labels.push(now);
                trafficChart.data.datasets[0].data.push(parseInt(data[1].data)); // RX
                trafficChart.data.datasets[1].data.push(parseInt(data[0].data)); // TX
                
                if(trafficChart.data.labels.length > 15) {
                    trafficChart.data.labels.shift();
                    trafficChart.data.datasets[0].data.shift();
                    trafficChart.data.datasets[1].data.shift();
                }
                trafficChart.update('none');
            });
    }

    function fetchLogs() {
        fetch('../api/hotspot-log.php?limit=10')
            .then(r => r.json())
            .then(logs => {
                const tbody = document.querySelector('#hotspotLogTable tbody');
                tbody.innerHTML = logs.map(l => `
                    <tr>
                        <td style="white-space:nowrap;"><small>${l.time}</small></td>
                        <td><code style="color:var(--neon-green)">${l.user}</code></td>
                        <td><small>${l.message}</small></td>
                    </tr>
                `).join('');
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        fetchDashboardStats();
        setInterval(fetchTraffic, 3000);
        setInterval(fetchLogs, 10000);
        fetchTraffic();
        fetchLogs();
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
