<?php
/**
 * Backup Management
 * Allow admin to manage database backups
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

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
            $backupType = $_POST['backup_type'] ?? 'all';
            $dbDumpFile = $backupDir . 'database_dump.sql';
            
            $dbHost = DB_HOST;
            $dbName = DB_NAME;
            $dbUser = DB_USER;
            $dbPass = DB_PASS;
            $passStr = empty($dbPass) ? "" : "-p" . escapeshellarg($dbPass);
            $command = sprintf("mysqldump -h %s -u %s %s %s > %s 2>&1", escapeshellarg($dbHost), escapeshellarg($dbUser), $passStr, escapeshellarg($dbName), escapeshellarg($dbDumpFile));
            
            if ($backupType === 'db') {
                $backupFile = $backupDir . 'backup_db_' . date('Y-m-d_H-i-s') . '.sql';
                exec($command, $output, $returnCode);
                if ($returnCode === 0 && file_exists($dbDumpFile)) {
                    rename($dbDumpFile, $backupFile);
                    setFlash('success', 'Backup Database berhasil dibuat.');
                } else {
                    @unlink($dbDumpFile);
                    $errorMsg = empty($output) ? "mysqldump error" : implode(", ", $output);
                    setFlash('error', "Gagal mem-backup database. Error: " . $errorMsg);
                }
            } else {
                $backupFile = $backupDir . 'backup_' . $backupType . '_' . date('Y-m-d_H-i-s') . '.zip';
                $zip = new ZipArchive();
                if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                    $rootPath = realpath(__DIR__ . '/../');
                    
                    if ($backupType === 'all') {
                        exec($command, $output, $returnCode);
                        if ($returnCode === 0 && file_exists($dbDumpFile)) {
                            $zip->addFile($dbDumpFile, 'database_dump.sql');
                        } else {
                            @unlink($dbDumpFile);
                            setFlash('error', 'Gagal mem-backup database, pembentukan file ZIP dihentikan.');
                            header("Location: backup.php");
                            exit;
                        }
                    }
                    
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($rootPath),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = substr($filePath, strlen($rootPath) + 1);
                            
                            if (strpos($relativePath, 'backups') === 0 || strpos($relativePath, '.git') === 0 || strpos($relativePath, 'gembokneew-temp') === 0) {
                                continue; 
                            }
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                    $zip->close();
                    @unlink($dbDumpFile);
                    setFlash('success', 'Backup (' . ($backupType === 'all' ? 'Files+DB' : 'Files Only') . ') berhasil dbuat.');
                } else {
                    @unlink($dbDumpFile);
                    setFlash('error', 'Gagal memproses ZipArchive. Pastikan ekstensi ZIP PHP aktif.');
                }
            }
            header("Location: backup.php");
            exit;
        }

        // ACTION: Upload Backup
        if ($action === 'upload') {
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['backup_file']['tmp_name'];
                $fileName = basename($_FILES['backup_file']['name']);
                
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['zip', 'sql'])) {
                    setFlash('error', 'Hanya file .zip atau .sql yang diperbolehkan.');
                } else {
                    $destPath = $backupDir . 'uploaded_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $fileName);
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        setFlash('success', 'File backup berhasil diupload.');
                    } else {
                        setFlash('error', 'Gagal mengupload file.');
                    }
                }
            } else {
                setFlash('error', 'Pilih file backup (ZIP/SQL) terlebih dahulu.');
            }
            header("Location: backup.php");
            exit;
        }

        // ACTION: Restore Backup
        if ($action === 'restore' && isset($_POST['file'])) {
            $fileName = basename($_POST['file']);
            $filePath = $backupDir . $fileName;
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (file_exists($filePath)) {
                if ($ext === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($filePath) === TRUE) {
                        $rootPath = realpath(__DIR__ . '/../');
                        $zip->extractTo($rootPath);
                        $zip->close();
                        
                        $dbDumpFile = $rootPath . '/database_dump.sql';
                        if (file_exists($dbDumpFile)) {
                            $dbHost = DB_HOST;
                            $dbName = DB_NAME;
                            $dbUser = DB_USER;
                            $dbPass = DB_PASS;
                            $passStr = empty($dbPass) ? "" : "-p" . escapeshellarg($dbPass);
                            $command = sprintf("mysql -h %s -u %s %s %s < %s 2>&1", escapeshellarg($dbHost), escapeshellarg($dbUser), $passStr, escapeshellarg($dbName), escapeshellarg($dbDumpFile));
                            exec($command, $output, $returnCode);

                            if ($returnCode === 0) {
                                @unlink($dbDumpFile);
                                setFlash('success', 'Seluruh file dan database berhasil di-restore!');
                            } else {
                                $errorMsg = empty($output) ? "mysql error" : implode(", ", $output);
                                setFlash('error', 'Gagal me-restore SQL. Error: ' . $errorMsg);
                            }
                        } else {
                            setFlash('success', 'Berhasil me-restore Files ke web, (File ZIP ini tidak memuat Database).');
                        }
                    } else {
                        setFlash('error', 'Gagal membuka file ZIP untuk di-restore.');
                    }
                } elseif ($ext === 'sql') {
                    $dbHost = DB_HOST;
                    $dbName = DB_NAME;
                    $dbUser = DB_USER;
                    $dbPass = DB_PASS;
                    $passStr = empty($dbPass) ? "" : "-p" . escapeshellarg($dbPass);
                    $command = sprintf("mysql -h %s -u %s %s %s < %s 2>&1", escapeshellarg($dbHost), escapeshellarg($dbUser), $passStr, escapeshellarg($dbName), escapeshellarg($filePath));
                    exec($command, $output, $returnCode);

                    if ($returnCode === 0) {
                        setFlash('success', 'Database berhasil di-restore!');
                    } else {
                        $errorMsg = empty($output) ? "mysql error" : implode(", ", $output);
                        setFlash('error', 'Gagal me-restore database SQL. Error: ' . $errorMsg);
                    }
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

            if (file_exists($filePath) && in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['zip', 'sql'])) {
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

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (file_exists($filePath) && in_array($ext, ['zip', 'sql'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($ext === 'zip' ? 'application/zip' : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        setFlash('error', 'File tidak ditemukan atau format salah.');
        header("Location: backup.php");
        exit;
    }
}

// Get list of backup files
$backupFiles = [];
if (is_dir($backupDir)) {
    $filesZip = glob($backupDir . '*.zip') ?: [];
    $filesSql = glob($backupDir . '*.sql') ?: [];
    $files = array_merge($filesZip, $filesSql);
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



ob_start();
?>

<div class="row" style="display: flex; gap: 20px; flex-wrap: wrap;">
    <!-- Backup Actions -->
    <div class="col-md-4" style="flex: 1; min-width: 300px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> Buat Backup</h3>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 0.95rem;">
                Pilih cakupan backup yang ingin Anda buat untuk proyek ini.
            </p>
            <form method="POST" onsubmit="return confirm('Mulai proses backup?');">
                <input type="hidden" name="action" value="create">
                <div class="form-group" style="margin-bottom: 15px;">
                    <select name="backup_type" class="form-control" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); padding: 8px;">
                        <option value="all" style="color: #000;">Full System (Files + Database)</option>
                        <option value="db" style="color: #000;">Database Only (.sql)</option>
                        <option value="files" style="color: #000;">Source Code Only (.zip)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                    <i class="fas fa-download"></i> Buat Backup Sekarang
                </button>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-upload"></i> Upload Backup</h3>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 20px; font-size: 0.95rem;">
                Upload file <code>.zip</code> atau <code>.sql</code> Backup Anda untuk dipulihkan secara instan.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <input type="file" name="backup_file" class="form-control" accept=".zip,.sql" required style="padding: 10px;">
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; justify-content: center;">
                    <i class="fas fa-cloud-upload-alt"></i> Upload
                </button>
            </form>
        </div>
        
        <div class="alert alert-info" style="margin-top: 20px;">
            <i class="fas fa-info-circle" style="font-size: 1.5rem;"></i>
            <div style="font-size: 0.85rem;">
                <strong>Catatan:</strong> Ekstensi <code>ZipArchive</code> dan library <code>mysqldump/mysql</code> PHP harus diijinkan untuk menjalankan siklus backup. File akan tersimpan di dalam folder <code>/backups/</code>.
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
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('PERINGATAN: Me-restore file ZIP ini akan MENIMPA (OVERWRITE) seluruh data saat ini secara PERMANEN! Apakah Anda sangat yakin ingin me-restore dari file <?php echo htmlspecialchars($file['name']); ?>?');">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" style="background: var(--neon-orange); color: #fff; border: none;" title="Restore Full System">
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
