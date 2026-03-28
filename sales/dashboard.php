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

    <!-- Quick Actions -->
    <div class="card" style="margin-bottom: 30px; border-top: 4px solid var(--neon-cyan);">
        <div style="padding: 10px 0 20px 0;">
            <h3 style="color: var(--neon-cyan); margin: 0; font-size: 1.2rem;">
                <i class="fas fa-rocket"></i> Aksi Cepat
            </h3>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
            <a href="vouchers.php" class="btn btn-primary" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 25px; border-radius: 16px; background: var(--gradient-primary); box-shadow: 0 8px 15px rgba(0, 245, 255, 0.2);">
                <i class="fas fa-ticket-alt" style="font-size: 2rem;"></i>
                <span style="font-size: 1rem; font-weight: 700;">Buat Voucher Baru</span>
            </a>
            
            <a href="history.php" class="btn btn-secondary" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 25px; border-radius: 16px; background: var(--bg-secondary); border: 1px solid var(--border-color);">
                <i class="fas fa-history" style="font-size: 2rem; color: var(--neon-purple);"></i>
                <span style="font-size: 1rem; font-weight: 700;">Riwayat Penjualan</span>
            </a>

            <a href="profile.php" class="btn btn-secondary" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 25px; border-radius: 16px; background: var(--bg-secondary); border: 1px solid var(--border-color);">
                <i class="fas fa-user-circle" style="font-size: 2rem; color: var(--neon-orange);"></i>
                <span style="font-size: 1rem; font-weight: 700;">Profil & Pengaturan</span>
            </a>
        </div>
    </div>

    <!-- Monthly Stats Summary -->
    <div class="card" style="border-left: 5px solid var(--neon-green);">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <p style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; margin-bottom: 5px;">Penjualan Bulan <?php echo date('F'); ?></p>
                <h2 style="font-size: 2rem; font-weight: 800; color: var(--text-primary);"><?php echo (int)$monthSales['count']; ?> <span style="font-size: 0.9rem; color: var(--text-secondary); font-weight: 400;">Voucher Terjual</span></h2>
            </div>
            <div style="text-align: right;">
                <div style="color: var(--neon-green); font-size: 1.5rem; font-weight: 800;"><?php echo formatCurrency($monthSales['total']); ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Total Omset</div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
