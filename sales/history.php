<?php
/**
 * Sales History
 */

require_once '../includes/auth.php';
requireSalesLogin();

$pageTitle = 'Riwayat Transaksi';
$salesId = $_SESSION['sales']['id'];

// Pre-sync vouchers
syncHotspotSalesStatus();

// Get Active Vouchers
$activeVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'active' ORDER BY used_at DESC", [$salesId]);

// Get Inactive Vouchers
$inactiveVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'inactive' ORDER BY created_at DESC", [$salesId]);

ob_start();
?>

<div style="row-gap: 30px; display: flex; flex-direction: column;">
    <!-- Active Vouchers -->
    <div class="col-md-12">
        <div class="card" style="border-top: 4px solid var(--neon-green); box-shadow: var(--shadow-card);">
            <div class="card-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                <h3 class="card-title" style="color: var(--neon-green); font-size: 1.2rem;">
                    <i class="fas fa-bolt"></i> Voucher Sedang Aktif
                </h3>
            </div>
            <div class="card-body" style="padding: 20px 0;">
                <div class="table-responsive">
                    <table class="table table-hover" id="activeTable">
                        <thead>
                            <tr>
                                <th>Waktu Login</th>
                                <th>Username</th>
                                <th>Paket</th>
                                <th>Perangkat (MAC/Host)</th>
                                <th>Uptime</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeVouchers as $v): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-primary);"><?php echo date('d M, H:i', strtotime($v['used_at'])); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--neon-orange);">Exp: <?php echo $v['expired_at'] ? date('d M, H:i', strtotime($v['expired_at'])) : '-'; ?></div>
                                    </td>
                                    <td>
                                        <strong style="color: var(--neon-cyan); letter-spacing: 1px;"><?php echo htmlspecialchars($v['username']); ?></strong>
                                    </td>
                                    <td><span class="badge badge-info" style="border-radius: 6px;"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                    <td>
                                        <div style="font-size: 0.85rem; color: var(--text-primary); font-family: monospace;"><?php echo htmlspecialchars($v['mac_address'] ?? 'Pending...'); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($v['hostname'] ?? ''); ?></div>
                                    </td>
                                    <td style="font-weight: 700; color: var(--neon-green);"><?php echo htmlspecialchars($v['uptime'] ?? '0s'); ?></td>
                                    <td>
                                        <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-secondary" style="background: var(--bg-input); border: 1px solid var(--border-color);">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($activeVouchers)): ?>
                                <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">Belum ada voucher yang aktif saat ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Inactive Vouchers -->
    <div class="col-md-12">
        <div class="card" style="border-top: 4px solid var(--text-muted);">
            <div class="card-header">
                <h3 class="card-title" style="color: var(--text-secondary);"><i class="fas fa-clock"></i> Voucher Tersedia (Standby)</h3>
            </div>
            <div class="card-body" style="padding: 20px 0;">
                <div class="table-responsive">
                    <table class="table table-hover" id="inactiveTable">
                        <thead>
                            <tr>
                                <th>Tgl Generate</th>
                                <th>Username</th>
                                <th>Password</th>
                                <th>Paket</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inactiveVouchers as $v): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($v['created_at'])); ?></td>
                                    <td><strong style="color: var(--text-primary);"><?php echo htmlspecialchars($v['username']); ?></strong></td>
                                    <td><code style="background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($v['password']); ?></code></td>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($v['profile']); ?></span></td>
                                    <td>
                                        <a href="print_voucher.php?users=<?php echo urlencode($v['username']); ?>" target="_blank" class="btn btn-sm btn-info">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($inactiveVouchers)): ?>
                                <tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">Tidak ada voucher dalam antrian.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Expired Vouchers -->
    <?php $expiredVouchers = fetchAll("SELECT * FROM hotspot_sales WHERE sales_user_id = ? AND status = 'expired' ORDER BY used_at DESC LIMIT 50", [$salesId]); ?>
    <div class="col-md-12">
        <div class="card" style="border-top: 4px solid var(--neon-red);">
            <div class="card-header">
                <h3 class="card-title" style="color: var(--neon-red);"><i class="fas fa-history"></i> Riwayat Voucher Selesai</h3>
            </div>
            <div class="card-body" style="padding: 20px 0;">
                <div class="table-responsive">
                    <table class="table table-hover" id="expiredTable">
                        <thead>
                            <tr>
                                <th>Digunakan</th>
                                <th>Username</th>
                                <th>Perangkat Akhir</th>
                                <th>Durasi Pakai</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiredVouchers as $v): ?>
                                <tr>
                                    <td>
                                        <div style="font-size: 0.85rem; color: var(--text-secondary);"><?php echo date('d/m/Y', strtotime($v['used_at'])); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo date('H:i', strtotime($v['used_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div style="color: var(--text-muted);"><?php echo htmlspecialchars($v['username']); ?></div>
                                        <div style="font-size: 0.7rem;"><?php echo htmlspecialchars($v['profile']); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.8rem; font-family: monospace;"><?php echo htmlspecialchars($v['mac_address'] ?? '-'); ?></div>
                                    </td>
                                    <td><span style="font-weight: 600;"><?php echo htmlspecialchars($v['uptime'] ?? '-'); ?></span></td>
                                    <td><span class="badge badge-danger" style="opacity: 0.7;">Expired</span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($expiredVouchers)): ?>
                                <tr><td colspan="5" class="text-center text-muted" style="padding: 30px;">Belum ada riwayat voucher kadaluarsa.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Initialize SimpleDatatables if available
    document.addEventListener('DOMContentLoaded', () => {
        const activeTable = document.getElementById('activeTable');
        if (activeTable) {
            new simpleDatatables.DataTable(activeTable);
        }
        const inactiveTable = document.getElementById('inactiveTable');
        if (inactiveTable) {
            new simpleDatatables.DataTable(inactiveTable);
        }
        const expiredTable = document.getElementById('expiredTable');
        if (expiredTable) {
            new simpleDatatables.DataTable(expiredTable);
        }
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/sales_layout.php';
?>
