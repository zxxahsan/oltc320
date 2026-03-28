<?php
/**
 * Cronjob Execution Logs Viewer
 */
require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Riwayat Cronjob';
$pageDescription = 'Pantau eksekusi jadwal Cronjob secara detail';

// Get pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$hasSchedules = tableExists('cron_schedules');
$hasLogs = tableExists('cron_logs');

if ($hasSchedules && $hasLogs) {
    $totalLogs = fetchOne("SELECT COUNT(*) as total FROM cron_logs")['total'] ?? 0;
    
    $logs = fetchAll("
        SELECT l.*, s.name as schedule_name, s.task_type
        FROM cron_logs l
        LEFT JOIN cron_schedules s ON l.schedule_id = s.id
        ORDER BY l.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
} else {
    $totalLogs = 0;
    $logs = [];
}
$totalPages = ceil($totalLogs / $perPage);

ob_start();
?>
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h3 class="card-title" style="margin: 0;"><i class="fas fa-microchip"></i> Riwayat Eksekusi Cronjob</h3>
        <div>
            <a href="settings.php" class="btn btn-secondary btn-sm"><i class="fas fa-cog"></i> Pengaturan Cron</a>
            <button onclick="location.reload()" class="btn btn-primary btn-sm"><i class="fas fa-sync"></i> Refresh Data</button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Waktu Eksekusi</th>
                    <th>Nama Jadwal</th>
                    <th>Tipe Tugas</th>
                    <th>Durasi Proses</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i><br>
                            Belum ada rekam jejak eksekusi Cronjob. Pastikan Cronjob server sudah berjalan dengan mengetik `crontab -e` di terminal.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo date('d M Y - H:i:s', strtotime($log['created_at'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($log['schedule_name'] ?? 'Jadwal Terhapus'); ?></strong></td>
                            <td><code style="background: rgba(0,255,136,0.1); padding: 2px 6px; border-radius: 4px; color: var(--neon-green);"><?php echo htmlspecialchars($log['task_type'] ?? 'unknown'); ?></code></td>
                            <td><?php echo number_format($log['execution_time'], 3); ?> Detik</td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle" style="margin-right: 4px;"></i>Sukses</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times-circle" style="margin-right: 4px;"></i>Gagal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 20px;">
        <a href="?page=1" class="btn btn-secondary btn-sm" <?php echo $page === 1 ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-double-left"></i>
        </a>
        <a href="?page=<?php echo max(1, $page - 1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === 1 ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-left"></i>
        </a>
        <span style="color: var(--text-secondary);">
            Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
        </span>
        <a href="?page=<?php echo min($totalPages, $page + 1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === $totalPages ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-right"></i>
        </a>
        <a href="?page=<?php echo $totalPages; ?>" class="btn btn-secondary btn-sm" <?php echo $page === $totalPages ? 'disabled style="opacity: 0.5;"' : ''; ?>>
            <i class="fas fa-angle-double-right"></i>
        </a>
    </div>
    <?php endif; ?>
    
</div>
<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
