<?php
/**
 * Unified Monitoring Dashboard (V8.1)
 * MikroTik, OLT, & ACS Logs
 */
require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Monitoring & Log';
$pageDescription = 'Pusat pemantauan aktivitas sistem, OLT, dan provisioning';

// Tabs handling
$activeTab = $_GET['tab'] ?? 'mikrotik';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle Log Clearing
if (isset($_POST['clear_logs'])) {
    $targetTab = $_POST['tab_to_clear'];
    if ($targetTab === 'mikrotik' && tableExists('cron_logs')) {
        query("DELETE FROM cron_logs");
        setFlash('success', 'Riwayat MikroTik dibersihkan.');
    } elseif ($targetTab === 'olt' && tableExists('olt_alerts')) {
        query("DELETE FROM olt_alerts");
        setFlash('success', 'Peringatan OLT dibersihkan.');
    } elseif ($targetTab === 'acs' && tableExists('task_queue')) {
        query("DELETE FROM task_queue WHERE status IN ('completed', 'failed')");
        setFlash('success', 'Riwayat tugas ACS dibersihkan.');
    }
    header("Location: cron_logs.php?tab=$targetTab");
    exit;
}

// Data Fetching
$logs = [];
$totalLogs = 0;

if ($activeTab === 'olt' && tableExists('olt_alerts')) {
    $totalLogs = fetchOne("SELECT COUNT(*) as total FROM olt_alerts")['total'] ?? 0;
    $logs = fetchAll("
        SELECT a.*, o.name as olt_name 
        FROM olt_alerts a
        LEFT JOIN olt_configs o ON a.olt_id = o.id
        ORDER BY a.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
} elseif ($activeTab === 'acs' && tableExists('task_queue')) {
    $totalLogs = fetchOne("SELECT COUNT(*) as total FROM task_queue")['total'] ?? 0;
    $logs = fetchAll("
        SELECT * FROM task_queue
        ORDER BY created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
} else { // Default: MikroTik
    if (tableExists('cron_logs')) {
        $totalLogs = fetchOne("SELECT COUNT(*) as total FROM cron_logs")['total'] ?? 0;
        $logs = fetchAll("
            SELECT l.*, s.name as schedule_name, s.task_type
            FROM cron_logs l
            LEFT JOIN cron_schedules s ON l.schedule_id = s.id
            ORDER BY l.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
    }
}

$totalPages = ceil($totalLogs / $perPage);
$extraHead = '<meta http-equiv="refresh" content="60">';

ob_start();
?>
<style>
    .log-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
        background: rgba(255, 255, 255, 0.03);
        padding: 8px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
    }
    .log-tab {
        flex: 1;
        padding: 12px 20px;
        text-align: center;
        text-decoration: none;
        color: var(--text-secondary);
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .log-tab:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--text-primary);
    }
    .log-tab.active {
        background: var(--gradient-primary);
        color: white;
        box-shadow: var(--shadow-neon);
    }
    .log-tab i { font-size: 1.1rem; }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
    }
    
    .severity-critical { color: var(--neon-red); }
    .severity-warning { color: var(--neon-orange); }
    .severity-info { color: var(--neon-cyan); }
</style>

<div class="log-tabs">
    <a href="?tab=mikrotik" class="log-tab <?php echo $activeTab === 'mikrotik' ? 'active' : ''; ?>">
        <i class="fas fa-network-wired"></i> MikroTik Scheduler
    </a>
    <a href="?tab=olt" class="log-tab <?php echo $activeTab === 'olt' ? 'active' : ''; ?>">
        <i class="fas fa-microchip"></i> OLT Monitoring
    </a>
    <a href="?tab=acs" class="log-tab <?php echo $activeTab === 'acs' ? 'active' : ''; ?>">
        <i class="fas fa-satellite-dish"></i> ACS Provisioning
    </a>
</div>

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <h3 class="card-title" style="margin: 0;">
            <?php 
            if($activeTab === 'olt') echo '<i class="fas fa-exclamation-triangle"></i> Peringatan & Log OLT';
            elseif($activeTab === 'acs') echo '<i class="fas fa-sync"></i> Antrian Tugas GenieACS';
            else echo '<i class="fas fa-history"></i> Riwayat MikroTik Scheduler';
            ?>
        </h3>
        <div class="card-actions" style="display: flex; gap: 10px;">
            <form method="POST" onsubmit="return confirm('Yakin ingin membersihkan riwayat ini?')">
                <input type="hidden" name="tab_to_clear" value="<?php echo $activeTab; ?>">
                <button type="submit" name="clear_logs" class="btn btn-secondary btn-sm">
                    <i class="fas fa-trash"></i> Bersihkan
                </button>
            </form>
            <button onclick="location.reload()" class="btn btn-primary btn-sm">
                <i class="fas fa-sync"></i> Refresh
            </button>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table">
            <?php if($activeTab === 'olt'): ?>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>OLT</th>
                        <th>Tingkat</th>
                        <th>Pesan Alert</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="4" style="text-align:center; padding:40px; color:var(--text-muted);">Tidak ada alert OLT.</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['olt_name'] ?? 'OLT #'.$log['olt_id']); ?></strong></td>
                                <td>
                                    <?php 
                                    $sev = strtolower($log['severity']);
                                    $class = "severity-$sev";
                                    echo "<span class='$class'><i class='fas fa-circle' style='font-size:0.6rem; vertical-align:middle;'></i> " . ucfirst($sev) . "</span>";
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

            <?php elseif($activeTab === 'acs'): ?>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipe</th>
                        <th>Payload</th>
                        <th>Status</th>
                        <th>Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted);">Antrian tugas kosong.</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td>#<?php echo $log['id']; ?></td>
                                <td><code style="color:var(--neon-purple);"><?php echo htmlspecialchars($log['task_type']); ?></code></td>
                                <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <small><?php echo htmlspecialchars($log['payload']); ?></small>
                                </td>
                                <td>
                                    <?php if($log['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Selesai</span>
                                    <?php elseif($log['status'] === 'failed'): ?>
                                        <span class="badge badge-danger">Gagal</span>
                                    <?php elseif($log['status'] === 'processing'): ?>
                                        <span class="badge badge-info"><i class="fas fa-spinner fa-spin"></i> Proses</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Antri</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo date('H:i:s', strtotime($log['updated_at'])); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

            <?php else: ?>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Jadwal</th>
                        <th>Tipe</th>
                        <th>Durasi</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:40px; color:var(--text-muted);">Belum ada riwayat MikroTik.</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['schedule_name'] ?? 'unknown'); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($log['task_type'] ?? '-'); ?></code></td>
                                <td><?php echo number_format($log['execution_time'], 2); ?>s</td>
                                <td>
                                    <?php if($log['status'] === 'success'): ?>
                                        <span class="badge badge-success">Sukses</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Gagal</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 10px; padding: 20px; border-top: 1px solid var(--border-color);">
        <a href="?tab=<?php echo $activeTab; ?>&page=<?php echo max(1, $page - 1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === 1 ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
            <i class="fas fa-chevron-left"></i>
        </a>
        <span style="color: var(--text-secondary); font-size: 0.9rem;">
            Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
        </span>
        <a href="?tab=<?php echo $activeTab; ?>&page=<?php echo min($totalPages, $page + 1); ?>" class="btn btn-secondary btn-sm" <?php echo $page === $totalPages ? 'style="opacity: 0.5; pointer-events: none;"' : ''; ?>>
            <i class="fas fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
?>
