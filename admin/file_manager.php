<?php
/**
 * Admin File Manager
 * Manage system uploads (Proofs, Receipts, Tickets)
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Manajemen File';

// Target Directories
$directories = [
    'proofs' => '../uploads/proofs/',
    'receipts' => '../uploads/receipts/',
    'tickets' => '../uploads/tickets/'
];

// Handle Individual File Deletion
if (isset($_GET['delete']) && !empty($_GET['file']) && !empty($_GET['type'])) {
    $type = $_GET['type'];
    $filename = basename($_GET['file']);
    
    if (isset($directories[$type])) {
        $filePath = $directories[$type] . $filename;
        if (file_exists($filePath)) {
            unlink($filePath);
            setFlash('success', "File $filename berhasil dihapus.");
        } else {
            setFlash('error', "File tidak ditemukan.");
        }
    }
    redirect('file_manager.php?type=' . $type);
}

// Handle Bulk Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $type = $_POST['type'] ?? '';
    $files = $_POST['selected_files'] ?? [];
    
    if (!empty($files) && isset($directories[$type])) {
        $deletedCount = 0;
        foreach ($files as $file) {
            $filePath = $directories[$type] . basename($file);
            if (file_exists($filePath)) {
                if (unlink($filePath)) $deletedCount++;
            }
        }
        setFlash('success', "$deletedCount file berhasil dihapus secara massal.");
    } else {
        setFlash('error', "Tidak ada file yang dipilih.");
    }
    redirect('file_manager.php?type=' . $type);
}

// Get current directory/type
$currentType = $_GET['type'] ?? 'proofs';
if (!isset($directories[$currentType])) $currentType = 'proofs';

$targetDir = $directories[$currentType];
if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);

// Scan files
$files = [];
$dirHandle = opendir($targetDir);
if ($dirHandle) {
    while (($file = readdir($dirHandle)) !== false) {
        if ($file !== '.' && $file !== '..') {
            $path = $targetDir . $file;
            $files[] = [
                'name' => $file,
                'path' => $path,
                'size' => filesize($path),
                'date' => filemtime($path),
                'ext' => strtolower(pathinfo($file, PATHINFO_EXTENSION))
            ];
        }
    }
    closedir($dirHandle);
}

// Sort by date DESC
usort($files, function($a, $b) {
    return $b['date'] <=> $a['date'];
});

ob_start();
?>

<!-- Categories Tabs -->
<div class="card" style="margin-bottom: 20px;">
    <div style="display: flex; gap: 10px; padding: 5px; background: rgba(0,0,0,0.2); border-radius: 12px;">
        <a href="?type=proofs" class="tab-link <?php echo $currentType === 'proofs' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 12px; border-radius: 8px; text-decoration:none; font-weight: 600;">
            <i class="fas fa-wallet"></i> Topup (Proofs)
        </a>
        <a href="?type=receipts" class="tab-link <?php echo $currentType === 'receipts' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 12px; border-radius: 8px; text-decoration:none; font-weight: 600;">
            <i class="fas fa-file-invoice-dollar"></i> Invoice (Receipts)
        </a>
        <a href="?type=tickets" class="tab-link <?php echo $currentType === 'tickets' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 12px; border-radius: 8px; text-decoration:none; font-weight: 600;">
            <i class="fas fa-ticket-alt"></i> Tiket (Gangguan)
        </a>
    </div>
</div>

<!-- Main Manager -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px;">
        <h3 class="card-title">
            <i class="fas fa-folder-open"></i> 
            Kategori: <?php echo ucfirst($currentType); ?> 
            <small style="font-weight: normal; color: var(--text-secondary); margin-left: 10px;">(<?php echo count($files); ?> File)</small>
        </h3>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="fileSearch" placeholder="Cari file..." class="form-control" style="width: 250px; padding: 8px 15px;">
            <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()" style="padding: 8px 15px;">
                <i class="fas fa-trash-alt"></i> Hapus Terpilih
            </button>
        </div>
    </div>

    <form method="POST" id="bulkForm">
        <input type="hidden" name="type" value="<?php echo $currentType; ?>">
        <input type="hidden" name="bulk_delete" value="1">
        
        <div class="table-responsive">
            <table class="data-table" id="fileTable">
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" style="cursor: pointer; transform: scale(1.2);">
                        </th>
                        <th style="width: 80px;">Pratinjau</th>
                        <th>Nama File</th>
                        <th>Ukuran</th>
                        <th>Tanggal Unggah</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($files)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-folder-open" style="font-size: 3rem; display: block; margin-bottom: 15px; opacity: 0.3;"></i>
                                Tidak ada file di kategori ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($files as $f): ?>
                            <tr class="file-row">
                                <td>
                                    <input type="checkbox" name="selected_files[]" value="<?php echo htmlspecialchars($f['name']); ?>" class="file-checkbox" style="cursor: pointer; transform: scale(1.2);">
                                </td>
                                <td>
                                    <?php if (in_array($f['ext'], ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                        <div class="file-thumb" style="width: 50px; height: 50px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-color);">
                                            <img src="<?php echo $f['path']; ?>" style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;" onclick="openPreview('<?php echo $f['path']; ?>')">
                                        </div>
                                    <?php else: ?>
                                        <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; border-radius: 6px; color: var(--text-secondary);">
                                            <i class="far fa-file-pdf" style="font-size: 1.5rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="file-name"><?php echo htmlspecialchars($f['name']); ?></strong><br>
                                    <small style="color: var(--text-secondary);"><?php echo $f['ext']; ?></small>
                                </td>
                                <td><?php echo formatBytes($f['size']); ?></td>
                                <td><?php echo date('d M Y, H:i', $f['date']); ?></td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <a href="<?php echo $f['path']; ?>" target="_blank" class="btn btn-secondary btn-sm" title="Buka File">
                                            <i class="fas fa-external-link-alt"></i>
                                        </a>
                                        <a href="?delete=1&type=<?php echo $currentType; ?>&file=<?php echo urlencode($f['name']); ?>" 
                                           class="btn btn-danger btn-sm" 
                                           onclick="return confirm('Apakah Anda yakin ingin menghapus file ini secara permanen?')"
                                           title="Hapus Permanen">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<!-- Preview Modal -->
<div id="previewModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.9); z-index: 2000; align-items: center; justify-content: center; padding: 20px;" onclick="closePreview()">
    <img id="previewImg" src="" style="max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 0 30px rgba(0,0,0,0.5);">
    <button style="position: absolute; top: 20px; right: 20px; background: rgba(255,255,255,0.1); border: none; color: #fff; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 1.5rem;">&times;</button>
</div>

<style>
    .tab-link {
        color: var(--text-secondary);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .tab-link:hover {
        background: rgba(0, 245, 255, 0.05);
        color: var(--neon-cyan);
    }
    .tab-link.active {
        background: var(--gradient-primary);
        color: #000;
        box-shadow: 0 4px 15px rgba(0, 245, 255, 0.3);
    }
    .file-row:hover {
        background: rgba(255,255,255,0.02);
    }
</style>

<script>
    // Search Functionality
    document.getElementById('fileSearch').addEventListener('input', function(e) {
        const search = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('.file-row');
        
        rows.forEach(row => {
            const fileName = row.querySelector('.file-name').textContent.toLowerCase();
            row.style.display = fileName.includes(search) ? '' : 'none';
        });
    });

    // Select All
    document.getElementById('selectAll').addEventListener('change', function(e) {
        const checkboxes = document.querySelectorAll('.file-checkbox');
        checkboxes.forEach(cb => {
            if(cb.closest('.file-row').style.display !== 'none') {
                cb.checked = e.target.checked;
            }
        });
    });

    // Bulk Delete Confirmation
    function confirmBulkDelete() {
        const selected = document.querySelectorAll('.file-checkbox:checked').length;
        if (selected === 0) {
            alert('Silakan pilih minimal satu file.');
            return;
        }
        
        if (confirm(`Yakin ingin menghapus ${selected} file terpilih secara permanen? Tindakan ini tidak dapat dibatalkan.`)) {
            document.getElementById('bulkForm').submit();
        }
    }

    // Modal Preview
    function openPreview(src) {
        const modal = document.getElementById('previewModal');
        const img = document.getElementById('previewImg');
        img.src = src;
        modal.style.display = 'flex';
    }

    function closePreview() {
        document.getElementById('previewModal').style.display = 'none';
    }

    // Close on esc
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closePreview();
    });
</script>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
