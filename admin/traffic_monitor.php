<?php
require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Traffic & Usage Monitor';
ob_start();
?>

<style>
    .monitor-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .live-pulse {
        display: inline-block;
        width: 10px;
        height: 10px;
        background-color: var(--neon-green);
        border-radius: 50%;
        margin-right: 8px;
        box-shadow: 0 0 10px var(--neon-green);
        animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
        0% { transform: scale(0.95); opacity: 0.7; }
        50% { transform: scale(1.1); opacity: 1; }
        100% { transform: scale(0.95); opacity: 0.7; }
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    
    .data-table th {
        background: rgba(255,255,255,0.05);
        color: var(--neon-cyan);
        padding: 12px 15px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid var(--border-color);
    }
    
    .data-table td {
        padding: 12px 15px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        color: var(--text-primary);
    }
    
    .data-table tr:hover td {
        background: rgba(0,245,255,0.02);
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="monitor-card">
            <h3 style="color: var(--neon-cyan); display: flex; align-items: center; justify-content: space-between;">
                <div><i class="fas fa-chart-line"></i> Pemantauan Trafik Real-Time</div>
                <div style="font-size: 0.9rem; color: var(--text-secondary);"><span class="live-pulse"></span>Live Auto-Sync</div>
            </h3>
            <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 20px;">
                Memantau Total Penggunaan (Download/Upload) seluruh pelanggan Aktif dan Offline secara otomatis setiap 5 detik langsung dari RouterOS API.
            </p>
            
            <div style="overflow-x: auto;">
                <table class="data-table" id="trafficTable">
                    <thead>
                        <tr>
                            <th>Pelanggan</th>
                            <th>PPPoE ID</th>
                            <th>Status Sesi</th>
                            <th>Total Download</th>
                            <th>Total Upload</th>
                            <th style="color: var(--neon-purple);">Total Pemakaian</th>
                        </tr>
                    </thead>
                    <tbody id="trafficBody">
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary);"></i>
                                <div style="margin-top: 10px;">Mengambil Data Mikrotik...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function fetchTrafficData() {
        fetch('../api/traffic_monitor_data.php')
            .then(res => res.json())
            .then(data => {
                const tbody = document.getElementById('trafficBody');
                tbody.innerHTML = '';
                
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Tidak ada data pelanggan PPPoE</td></tr>';
                    return;
                }
                
                data.data.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-weight: 500;">${row.name}</td>
                        <td style="color: var(--text-secondary); font-family: monospace;">${row.username}</td>
                        <td>${row.status}</td>
                        <td>${row.download}</td>
                        <td>${row.upload}</td>
                        <td style="font-weight: bold; color: var(--neon-purple);">${row.total}</td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(err => {
                console.error("Traffic Sync Failed:", err);
            });
    }

    // Initial load
    fetchTrafficData();
    
    // Auto sync every 5 seconds seamlessly
    setInterval(fetchTrafficData, 5000);
</script>

<?php 
$content = ob_get_clean();
require_once '../includes/layout.php'; 
?>
