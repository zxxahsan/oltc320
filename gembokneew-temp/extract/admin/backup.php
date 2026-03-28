<?php
/**
 * Backup Management
 * Allow admin to manage database backups
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$pageTitle = 'Backup & Restore';
$backupDir = __DIR__ . '/../backups/';

// Create backup directory if it doesn't exist
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Ensure .htaccess exists
if (!file_exists($backupDir . '.htaccess')) {
    file_put_contents($backupDir . '.htaccess', "Deny from all\n");
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        // ACTION: Create Backup
        if ($action === 'create') {
            $backupFile = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            
            // Replicate cron/scheduler.php backup logic
            $dbHost = DB_HOST;
            $dbName = DB_NAME;
            $dbUser = DB_USER;
            $dbPass = DB_PASS;
            
            // Password might be empty for local dev
            $passStr = empty($dbPass) ? "" : "-p" . escapeshellarg($dbPass);
            $command = sprintf(
                "mysqldump -h %s -u %s %s %s > %s 2>&1",
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                $passStr,
                escapeshellarg($dbName),
                escapeshellarg($backupFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
                setFlash('success', 'Backup berhasil dbuat.');
            } else {
                @unlink($backupFile); // Remove empty/failed file
                $errorMsg = empty($output) ? "Unknown error (mysqldump command failed)" : implode(", ", $output);
                setFlash('error', "Gagal membuat backup. Error: " . $errorMsg);
            }
            header("Location: backup.php");
            exit;
        }

        // ACTION: Upload Backup
        if ($action === 'upload') {
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['backup_file']['tmp_name'];
                $fileName = basename($_FILES['backup_file']['name']);
                
                // Security check
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if ($fileExtension !== 'sql') {
                    setFlash('error', 'Hanya file .sql yang diperbolehkan.');
                } else {
                    $destPath = $backupDir . 'uploaded_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        setFlash('success', 'File backup berhasil diupload.');
                    } else {
                        setFlash('error', 'Gagal mengupload file.');
                    }
                }
            } else {
                setFlash('error', 'Pilih file backup (SQL) terlebih dahulu.');
            }
            header("Location: backup.php");
            exit;
        }

        // ACTION: Restore Backup
        if ($action === 'restore' && isset($_POST['file'])) {
            $fileName = basename($_POST['file']);
            $filePath = $backupDir . $fileName;

            if (file_exists($filePath) && pathinfo($fileName, PATHINFO_EXTENSION) === 'sql') {
                $dbHost = DB_HOST;
                $dbName = DB_NAME;
                $dbUser = DB_USER;
                $dbPass = DB_PASS;
                
                $passStr = empty($dbPass) ? "" : "-p" . escapeshellarg($dbPass);
                $command = sprintf(
                    "mysql -h %s -u %s %s %s < %s 2>&1",
                    escapeshellarg($dbHost),
                    escapeshellarg($dbUser),
                    $passStr,
                    escapeshellarg($dbName),
                    escapeshellarg($filePath)
                );
                
                exec($command, $output, $returnCode);

                if ($returnCode === 0) {
                    setFlash('success', 'Database berhasil di-restore dari file: ' . $fileName);
                } else {
                    $errorMsg = empty($output) ? "Unknown error (mysql command failed)" : implode(", ", $output);
                    setFlash('error', 'Gagal me-restore database. Error: ' . $errorMsg);
                }
            } else {
                setFlash('error', 'File backup tidak valid atau tidak ditemukan.');
            }
            header("Location: backup.php");
            exit;
        }

        // ACTION: Delete Backup
        if ($action === 'delete' && isset($_POST['file'])) {
            $fileName = basename($_POST['file']);
            $filePath = $backupDir . $fileName;

            if (file_exists($filePath) && pathinfo($fileName, PATHINFO_EXTENSION) === 'sql') {
                if (unlink($filePath)) {
                    setFlash('success', 'File backup berhasil dihapus.');
                } else {
                    setFlash('error', 'Gagal menghapus file backup.');
                }
            }
            header("Location: backup.php");
            exit;
        }
    }
}

// Handle Download
if (isset($_GET['download'])) {
    $fileName = basename($_GET['download']);
    $filePath = $backupDir . $fileName;

    if (file_exists($filePath) && pathinfo($fileName, PATHINFO_EXTENSION) === 'sql') {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        setFlash('error', 'File tidak ditemukan.');
        header("Location: backup.php");
        exit;
    }
}

// Get list of backup files
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '*.sql');
    foreach ($files as $file) {
        $backupFiles[] = [
            'name' => basename($file),
            'size' => filesize($file),
            'date' => filemtime($file),
            'path' => $file
        ];
    }
}

// Sort newest first
usort($backupFiles, function ($a, $b) {
    return $b['date'] - $a['date'];
});

function formatBytes($bytes, $precision = 2) { 
    $units = ['B', 'KB', 'MB', 'GB', 'TB']; 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow]; 
}

ob_start();
?>

<div class="row" style="display: flex; gap: 20px; flex-wrap: wrap;">
    <!-- Backup Actions -->
    <div class="col-md-4" style="flex: 1; min-width: 300px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Buat Backup Baru</h3>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 0.95rem;">
                Sistem akan membuat salinan (dump) seluruh database saat ini dan menyimpannya ke server.
            </p>
            <form method="POST" onsubmit="return confirm('Mulai proses backup database?');">
                <input type="hidden" name="action" value="create">
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    <i class="fas fa-download"></i> Backup Sekarang
                </button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-upload"></i> Upload Backup SQL</h3>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 0.95rem;">
                Upload file <code>.sql</code> dari komputer Anda untuk ditambahkan ke daftar instalasi.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <input type="file" name="backup_file" class="form-control" accept=".sql" required style="padding: 10px;">
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; justify-content: center;">
                    <i class="fas fa-cloud-upload-alt"></i> Upload
                </button>
            </form>
        </div>
        
        <div class="alert alert-info" style="margin-top: 20px;">
            <i class="fas fa-info-circle" style="font-size: 1.5rem;"></i>
            <div style="font-size: 0.85rem;">
                <strong>Catatan:</strong> Fitur create & restore bergantung pada perintah <code>mysqldump</code> dan <code>mysql</code> yang harus tersedia (masuk path) di server lokal / hosting Anda.
            </div>
        </div>
    </div>

    <!-- Backup List -->
    <div class="col-md-8" style="flex: 2; min-width: 300px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list"></i> Daftar File Backup</h3>
            </div>
            
            <div class="table-responsive">
                <table class="data-table" id="backupsTable">
                    <thead>
                        <tr>
                            <th>Nama File</th>
                            <th>Ukuran</th>
                            <th>Tanggal Dibuat</th>
                            <th style="width: 250px; text-align: right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backupFiles)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 30px;">
                                    <i class="fas fa-folder-open" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 10px; display: block;"></i>
                                    Belum ada file backup. Silakan buat backup pertama Anda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($backupFiles as $file): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-database" style="color: var(--neon-cyan); margin-right: 8px;"></i>
                                        <?php echo htmlspecialchars($file['name']); ?>
                                    </td>
                                    <td><?php echo formatBytes($file['size']); ?></td>
                                    <td><?php echo date('d M Y H:i', $file['date']); ?></td>
                                    <td style="text-align: right; display: flex; gap: 5px; justify-content: flex-end;">
                                        <!-- Download -->
                                        <a href="?download=<?php echo urlencode($file['name']); ?>" class="btn btn-sm btn-info" style="background: var(--neon-cyan); color: #000; border: none;" title="Download File">
                                            <i class="fas fa-download"></i>
                                        </a>

                                        <!-- Restore -->
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('PERINGATAN: Me-restore database akan menimpa seluruh data saat ini secara PERMANEN! Apakah Anda sangat yakin ingin me-restore dari file <?php echo htmlspecialchars($file['name']); ?>?');">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" style="background: var(--neon-orange); color: #fff; border: none;" title="Restore Database">
                                                <i class="fas fa-sync"></i> Restore
                                            </button>
                                        </form>

                                        <!-- Delete -->
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus file backup ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Hapus File">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('backupsTable') && document.querySelectorAll('#backupsTable tbody tr').length > 1) {
        new window.simpleDatatables.DataTable("#backupsTable", {
            searchable: true,
            fixedHeight: false,
            perPage: 10,
            labels: {
                placeholder: "Cari backup...",
                perPage: "data per halaman",
                noRows: "Tidak ada data ditemukan",
                info: "Menampilkan {start} - {end} dari {rows} data"
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
