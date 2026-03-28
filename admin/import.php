<?php
/**
 * Import Customers from Excel/CSV
 */

require_once '../includes/auth.php';
requireAdminLogin();

$pageTitle = 'Import Pelanggan';

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['importFile'])) {
        $file = $_FILES['importFile'];
        $filename = strtolower($file['name']);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $rows = [];
        
        // Parse file based on extension
        if ($extension === 'csv') {
            // Parse CSV
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                setFlash('error', 'Gagal membuka file!');
                redirect('export.php');
            }
            
            // Skip header row
            $headers = fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $rows[] = $data;
            }
            fclose($handle);
            
        } elseif (in_array($extension, ['xls', 'xlsx'])) {
            // For Excel files, we'll convert to CSV format using a simple approach
            // Since we don't have PHPSpreadsheet, we'll use a workaround
            
            // Try to read as XML (for .xls XML format)
            $content = file_get_contents($file['tmp_name']);
            
            if (strpos($content, '<?xml') !== false) {
                // XML Spreadsheet format
                $xml = simplexml_load_string($content);
                if ($xml) {
                    $namespaces = $xml->getNamespaces(true);
                    $xml->registerXPathNamespace('ss', 'urn:schemas-microsoft-com:office:spreadsheet');
                    
                    $cells = $xml->xpath('//ss:Row/ss:Cell/ss:Data');
                    $colCount = 0;
                    $row = [];
                    
                    // Determine column count from first row
                    foreach ($xml->xpath('//ss:Row[1]/ss:Cell') as $cell) {
                        $colCount++;
                    }
                    
                    $rowIndex = 0;
                    $currentRow = [];
                    foreach ($cells as $cell) {
                        $currentRow[] = (string)$cell;
                        if (count($currentRow) == $colCount) {
                            $rows[] = $currentRow;
                            $currentRow = [];
                        }
                    }
                    
                    // Remove header row
                    if (!empty($rows)) {
                        array_shift($rows);
                    }
                }
            } else {
                // Try to parse as tab-separated (common Excel export)
                $lines = explode("\n", $content);
                if (count($lines) > 1) {
                    // Skip header
                    for ($i = 1; $i < count($lines); $i++) {
                        $line = trim($lines[$i]);
                        if (!empty($line)) {
                            // Try tab first, then comma
                            if (strpos($line, "\t") !== false) {
                                $rows[] = explode("\t", $line);
                            } else {
                                $rows[] = str_getcsv($line);
                            }
                        }
                    }
                }
            }
        } else {
            setFlash('error', 'Format file tidak didukung! Gunakan CSV, XLS, atau XLSX.');
            redirect('export.php');
        }
        
        // Process rows
        foreach ($rows as $rowNum => $data) {
            $actualRow = $rowNum + 2; // +2 because row 1 is header
            
            // Map columns - expected order: Nama, No HP, PPPoE Username, Paket, Status, Tgl Isolir, Alamat, Latitude, Longitude
            $name = trim($data[0] ?? '');
            $phone = trim($data[1] ?? '');
            $pppoeUsername = trim($data[2] ?? '');
            $packageName = trim($data[3] ?? '');
            $statusText = trim($data[4] ?? 'Aktif');
            $isolationDate = trim($data[5] ?? '20');
            $address = trim($data[6] ?? '');
            $lat = str_replace(',', '.', trim($data[7] ?? ''));
            $lng = str_replace(',', '.', trim($data[8] ?? ''));
            
            // Validate required fields
            if (empty($name) || empty($phone) || empty($pppoeUsername)) {
                $errors[] = "Baris {$actualRow}: Data tidak lengkap (nama, no HP, PPPoE username wajib diisi)";
                $errorCount++;
                continue;
            }
            
            // Check if customer already exists
            $existing = fetchOne("SELECT id FROM customers WHERE pppoe_username = ?", [$pppoeUsername]);
            
            if ($existing) {
                $errors[] = "Baris {$actualRow}: Pelanggan dengan PPPoE username '{$pppoeUsername}' sudah ada!";
                $errorCount++;
                continue;
            }
            
            // Get package info
            $package = null;
            if (!empty($packageName)) {
                $package = fetchOne("SELECT id FROM packages WHERE name = ? OR name LIKE ?", [$packageName, "%{$packageName}%"]);
            }
            
            if (!$package) {
                $errors[] = "Baris {$actualRow}: Paket '{$packageName}' tidak ditemukan!";
                $errorCount++;
                continue;
            }

            // Map status
            $status = (strtolower($statusText) === 'isolir' || strtolower($statusText) === 'isolated') ? 'isolated' : 'active';
            
            // Insert customer
            $customerData = [
                'name' => sanitize($name),
                'phone' => sanitize($phone),
                'pppoe_username' => sanitize($pppoeUsername),
                'package_id' => $package['id'],
                'status' => $status,
                'isolation_date' => (int)($isolationDate ?: 20),
                'address' => sanitize($address),
                'lat' => $lat ? (float)$lat : null,
                'lng' => $lng ? (float)$lng : null,
                'portal_password' => password_hash('1234', PASSWORD_DEFAULT),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            if (insert('customers', $customerData)) {
                $successCount++;
                
                // Sync to onu_locations if lat/lng present
                if (!empty($lat) && !empty($lng)) {
                    $exists = fetchOne("SELECT id FROM onu_locations WHERE serial_number = ?", [$pppoeUsername]);
                    $payload = [
                        'name' => sanitize($name),
                        'lat' => (float)$lat,
                        'lng' => (float)$lng,
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    if ($exists) {
                        update('onu_locations', $payload, 'serial_number = ?', [$pppoeUsername]);
                    } else {
                        $payload['serial_number'] = sanitize($pppoeUsername);
                        $payload['created_at'] = date('Y-m-d H:i:s');
                        insert('onu_locations', $payload);
                    }
                }
            } else {
                $errors[] = "Baris {$actualRow}: Gagal menyimpan pelanggan!";
                $errorCount++;
            }
        }
        
        if ($errorCount > 0) {
            $importResult = [
                'success' => $successCount,
                'failed' => $errorCount,
                'errors' => $errors
            ];
            // Do not redirect so we can display the detailed error box below.
        } else {
            setFlash('success', "Import berhasil! {$successCount} pelanggan berhasil diimport.");
            logActivity('IMPORT_CUSTOMERS', "Imported {$successCount} customers");
            redirect('customers.php');
        }
    }
}

ob_start();
?>

<?php if (isset($importResult)): ?>
<div style="max-width: 800px; margin: 20px auto;">
    <div class="alert alert-warning" style="border-radius: 8px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
        <h4 style="color: #ff9800; font-weight: bold; margin-bottom: 10px;">
            <i class="fas fa-exclamation-triangle"></i> Import Selesai dengan Beberapa Kendala
        </h4>
        <p style="font-size: 1.05rem; margin-bottom: 5px;">
            Berhasil Di-import: <strong style="color: #4caf50;"><?php echo $importResult['success']; ?></strong> baris.<br>
            Gagal Di-import: <strong style="color: #f44336;"><?php echo $importResult['failed']; ?></strong> baris.
        </p>
        
        <p style="margin-top: 15px; font-weight: bold;">Rincian Error (Baris pada file Excell/CSV):</p>
        <div style="background: rgba(0,0,0,0.5); padding: 15px; border-radius: 8px; max-height: 250px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px; border: 1px solid rgba(255,255,255,0.1);">
            <?php foreach ($importResult['errors'] as $err): ?>
                <div style="color: #ffaaaa; margin-bottom: 6px; border-bottom: 1px dashed rgba(255,255,255,0.1); padding-bottom: 4px;">
                    <i class="fas fa-times-circle" style="margin-right: 5px;"></i> <?php echo htmlspecialchars($err); ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin-top: 20px; display: flex; gap: 10px;">
            <a href="customers.php" class="btn btn-success"><i class="fas fa-users"></i> Lanjut Lihat Data Pelanggan</a>
            <a href="import.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Coba Import Lagi</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-upload"></i> Import Pelanggan</h3>
        </div>
        
        <p style="margin-bottom: 20px; color: var(--text-secondary);">
            Upload file Excel atau CSV untuk import pelanggan secara massal.
        </p>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Pilih File (Excel/CSV)</label>
                <input type="file" name="importFile" class="form-control" accept=".csv,.xls,.xlsx" required>
                <small style="color: var(--text-muted);">Format yang didukung: CSV, XLS, XLSX</small>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Upload & Import
                </button>
                <a href="export.php?action=export_excel" class="btn btn-secondary">
                    <i class="fas fa-download"></i> Download Template
                </a>
                <a href="customers.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Batal
                </a>
            </div>
        </form>
    </div>
    
    <div class="card" style="margin-top: 20px;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-info-circle"></i> Format File</h3>
        </div>
        
        <p style="color: var(--text-secondary);">
            File harus memiliki kolom-kolom berikut (baris pertama sebagai header):
        </p>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kolom</th>
                    <th>Deskripsi</th>
                    <th>Contoh</th>
                    <th>Wajib</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Nama</td>
                    <td>Nama lengkap pelanggan</td>
                    <td>John Doe</td>
                    <td><span class="badge badge-success">Ya</span></td>
                </tr>
                <tr>
                    <td>No HP</td>
                    <td>Nomor WhatsApp</td>
                    <td>08123456789</td>
                    <td><span class="badge badge-success">Ya</span></td>
                </tr>
                <tr>
                    <td>PPPoE Username</td>
                    <td>Username PPPoE di MikroTik</td>
                    <td>pelanggan01</td>
                    <td><span class="badge badge-success">Ya</span></td>
                </tr>
                <tr>
                    <td>Paket</td>
                    <td>Nama paket (harus sama dengan di sistem)</td>
                    <td>Paket 10 Mbps</td>
                    <td><span class="badge badge-success">Ya</span></td>
                </tr>
                <tr>
                    <td>Status</td>
                    <td>Status pelanggan (Aktif / Isolir)</td>
                    <td>Aktif</td>
                    <td><span class="badge badge-info">Opsional</span></td>
                </tr>
                <tr>
                    <td>Tgl Isolir</td>
                    <td>Tanggal isolir (1-28)</td>
                    <td>20</td>
                    <td><span class="badge badge-info">Opsional</span></td>
                </tr>
                <tr>
                    <td>Alamat</td>
                    <td>Alamat lengkap</td>
                    <td>Jl. Contoh No. 123</td>
                    <td><span class="badge badge-info">Opsional</span></td>
                </tr>
                <tr>
                    <td>Latitude</td>
                    <td>Titik koordinat</td>
                    <td>-6.200000</td>
                    <td><span class="badge badge-info">Opsional</span></td>
                </tr>
                <tr>
                    <td>Longitude</td>
                    <td>Titik koordinat</td>
                    <td>106.816666</td>
                    <td><span class="badge badge-info">Opsional</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--border-color);
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--neon-cyan);
}
.form-group { margin-bottom: 20px; }
.form-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-secondary); }
.form-control {
    width: 100%;
    padding: 12px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 1rem;
}
.form-control:focus { outline: none; border-color: var(--neon-cyan); }
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(135deg, #00f5ff 0%, #bf00ff 100%);
    transition: all 0.3s;
}
.btn:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(0,245,255,0.3); }
.btn-secondary {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}
.btn-secondary:hover { background: rgba(255, 255,255,0.05); }
.badge-success { background: var(--neon-green); color: #000; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; }
.badge-info { background: var(--neon-cyan); color: #000; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; }
.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table thead { background: var(--bg-secondary); }
.data-table th, .data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.data-table th {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
}
</style>

<?php
$content = ob_get_clean();
require_once '../includes/layout.php';
