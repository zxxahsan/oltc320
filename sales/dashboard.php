<?php
/**
 * Sales Dashboard
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Dashboard';

// Get Sales Info
$salesId = $_SESSION['sales']['id'];
$salesUser = getSalesUser($salesId);

// Update session balance
$_SESSION['sales']['deposit_balance'] = $salesUser['deposit_balance'];

// Pre-sync pending offline activations
syncHotspotSalesStatus();

// Get Stats
$today = date('Y-m-d');
$month = date('Y-m');

// Today's Sales
$todaySales = fetchOne("SELECT COUNT(*) as count, SUM(price) as capital, SUM(selling_price) as total 
    FROM hotspot_sales 
    WHERE sales_user_id = ? AND status = 'active' AND DATE(used_at) = ?", [$salesId, $today]);

// Month's Sales
$monthSales = fetchOne("SELECT COUNT(*) as count, SUM(price) as capital, SUM(selling_price) as total 
    FROM hotspot_sales 
    WHERE sales_user_id = ? AND status = 'active' AND DATE_FORMAT(used_at, '%Y-%m') = ?", [$salesId, $month]);

// Get Active Vouchers (Moved from history)
$activeVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'active' ORDER BY used_at DESC", [$salesId]);

// Get Realtime Active Users from Mikrotik for status dot
$activeMT = mikrotikGetHotspotActive();
$onlineUsers = [];
if (is_array($activeMT)) {
    foreach ($activeMT as $a) {
        $onlineUsers[] = $a['user'] ?? '';
    }
}

// Get Inactive Vouchers (Moved from history)
$inactiveVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'inactive' ORDER BY created_at DESC", [$salesId]);

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <!-- Welcome Header -->
    <div style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; align-items: flex-end; margin-bottom: 30px;">
        <div style="flex: 1; min-width: 300px;">
            <h2 style="color: var(--text-primary); margin-bottom: 5px;">Halo, <?php echo htmlspecialchars($salesUser['name']); ?>!</h2>
            <p style="color: var(--text-secondary);">Kelola deposit dan penjualan voucher hotspot Anda di sini.</p>
        </div>
        
        <div style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <!-- Balance Pill -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: 16px; display: flex; align-items: center; gap: 15px; flex: 1; min-width: 250px; box-shadow: var(--shadow-card); background: linear-gradient(135deg, rgba(0, 245, 255, 0.05) 0%, rgba(191, 0, 255, 0.05) 100%);">
                <i class="fas fa-wallet" style="color: var(--neon-cyan); font-size: 1.5rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Saldo Deposit</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--neon-cyan);"><?php echo formatCurrency($salesUser['deposit_balance']); ?></div>
                </div>
            </div>
            
            <!-- Today Sales Pill -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: 16px; display: flex; align-items: center; gap: 15px; flex: 1; min-width: 200px; box-shadow: var(--shadow-card);">
                <i class="fas fa-shopping-cart" style="color: var(--neon-green); font-size: 1.5rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Penjualan Hari Ini</div>
                    <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-primary);"><?php echo (int)$todaySales['count']; ?> <span style="font-size: 0.8rem; font-weight: 400; color: var(--text-muted);">Vcr</span></div>
                </div>
            </div>

            <!-- Profit/Total today -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 15px 20px; border-radius: 16px; display: flex; align-items: center; gap: 15px; flex: 1; min-width: 200px; box-shadow: var(--shadow-card);">
                <i class="fas fa-chart-line" style="color: var(--neon-purple); font-size: 1.5rem;"></i>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Total Omset (Hari Ini)</div>
                    <div style="font-size: 1.1rem; font-weight: 700; color: var(--neon-green);"><?php echo formatCurrency($todaySales['total']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions & Monthly Stats -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 30px;">
        <!-- Quick Actions -->
        <div class="card" style="border-top: 4px solid var(--neon-cyan); height: 100%;">
            <div style="padding: 10px 0 15px 0;">
                <h3 style="color: var(--neon-cyan); margin: 0; font-size: 1.1rem;">
                    <i class="fas fa-rocket"></i> Aksi Cepat
                </h3>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <a href="vouchers.php" class="btn btn-primary" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 15px; border-radius: 12px; background: var(--gradient-primary); box-shadow: 0 4px 10px rgba(0, 245, 255, 0.15);">
                    <i class="fas fa-plus-circle" style="font-size: 1.5rem;"></i>
                    <span style="font-size: 0.9rem; font-weight: 700;">Buat Voucher</span>
                </a>
                <a href="history.php" class="btn btn-secondary" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 15px; border-radius: 12px; background: var(--bg-secondary); border: 1px solid var(--border-color);">
                    <i class="fas fa-history" style="font-size: 1.5rem; color: var(--neon-purple);"></i>
                    <span style="font-size: 0.9rem; font-weight: 700;">Riwayat</span>
                </a>
            </div>
        </div>

        <!-- Monthly Stats Summary -->
        <div class="card" style="border-left: 5px solid var(--neon-green); height: 100%; display: flex; flex-direction: column; justify-content: center; padding: 25px;">
            <p style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-bottom: 10px;">Penjualan Bulan <?php echo date('F'); ?></p>
            <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                <div>
                    <h2 style="font-size: 2.2rem; font-weight: 800; color: var(--text-primary); margin: 0; line-height: 1;">
                        <?php echo (int)$monthSales['count']; ?> 
                        <span style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 400;">Unit</span>
                    </h2>
                </div>
                <div style="text-align: right;">
                    <div style="color: var(--neon-green); font-size: 1.5rem; font-weight: 800; line-height: 1; margin-bottom: 4px;"><?php echo formatCurrency($monthSales['total']); ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Total Omset</div>
                </div>
            </div>
            <div style="margin-top: 15px; height: 6px; background: rgba(0,255,136,0.1); border-radius: 3px; overflow: hidden;">
                <div style="width: 75%; height: 100%; background: var(--neon-green); box-shadow: 0 0 10px var(--neon-green);"></div>
            </div>
        </div>
    </div>

    <!-- Tables Row: Side-by-Side on Desktop -->
    <div style="display: flex; flex-wrap: wrap; gap: 25px;">
        <!-- Left: Active Vouchers (Desktop side-by-side, Mobile full-width) -->
        <div style="flex: 1.5; min-width: 280px; width: 100%;">
            <div class="card" style="border-top: 4px solid var(--neon-green); height: 100%;">
                <div class="card-header" style="border-bottom: 1px solid var(--border-color); padding: 15px 20px;">
                    <h3 class="card-title" style="color: var(--neon-green); font-size: 1.1rem; margin-bottom: 0;">
                        <i class="fas fa-bolt"></i> Voucher Sedang Aktif
                    </h3>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div class="table-responsive">
                        <table class="table table-hover" id="activeTable">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Login</th>
                                    <th>Username</th>
                                    <th>Paket</th>
                                    <th>Uptime</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeVouchers as $v): 
                                    $isOnline = in_array($v['username'], $onlineUsers);
                                ?>
                                    <tr>
                                        <td>
                                            <?php if ($isOnline): ?>
                                                <span class="status-dot status-online"></span> <span style="color: var(--neon-green); font-size: 0.75rem; font-weight: bold;">Online</span>
                                            <?php else: ?>
                                                <span class="status-dot"></span> <span style="color: var(--text-muted); font-size: 0.75rem;">Offline</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);"><?php echo date('d M, H:i', strtotime($v['used_at'])); ?></div>
                                        </td>
                                        <td>
                                            <strong style="color: var(--neon-cyan); letter-spacing: 0.5px;"><?php echo htmlspecialchars($v['username']); ?></strong>
                                        </td>
                                        <td><span class="badge badge-info" style="font-size: 0.7rem;"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                        <td style="font-weight: 700; color: var(--neon-green);"><?php echo htmlspecialchars($v['uptime'] ?? '0s'); ?></td>
                                        <td>
                                            <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-secondary" style="padding: 4px 8px;">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($activeVouchers)): ?>
                                    <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">Belum ada voucher aktif.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Inactive/Stock (Desktop side-by-side, Mobile full-width) -->
        <div style="flex: 1; min-width: 280px; width: 100%;">
            <div class="card" style="border-top: 4px solid var(--text-muted); height: 100%;">
                <div class="card-header" style="border-bottom: 1px solid var(--border-color); padding: 15px 20px;">
                    <h3 class="card-title" style="color: var(--text-secondary); font-size: 1.1rem; margin-bottom: 0;">
                        <i class="fas fa-clock"></i> Stok Ready
                    </h3>
                </div>
                <div class="card-body" style="padding: 20px;">
                    <div class="table-responsive">
                        <table class="table table-hover" id="inactiveTable">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Paket</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inactiveVouchers as $v): ?>
                                    <tr>
                                        <td><strong style="color: var(--text-primary);"><?php echo htmlspecialchars($v['username']); ?></strong></td>
                                        <td><span class="badge badge-secondary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                        <td>
                                            <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-info" style="padding: 4px 8px;">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($inactiveVouchers)): ?>
                                    <tr><td colspan="3" class="text-center text-muted" style="padding: 30px;">Stok kosong.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const activeTable = document.getElementById('activeTable');
        if (activeTable && typeof simpleDatatables !== 'undefined') {
            new simpleDatatables.DataTable(activeTable);
        }
        const inactiveTable = document.getElementById('inactiveTable');
        if (inactiveTable && typeof simpleDatatables !== 'undefined') {
            new simpleDatatables.DataTable(inactiveTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
