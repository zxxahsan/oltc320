<?php
require_once '../../includes/auth.php';
requireTechnicianLogin();

$tech = $_SESSION['technician'];
$customerId = $_GET['id'] ?? 0;

try {
    $pdo = getDB();
    $pdo->exec("ALTER TABLE customers MODIFY installation_photo TEXT;");
} catch(Exception $e) {}

// Fetch Customer Detail
$customer = fetchOne("
    SELECT c.*, p.name as package_name 
    FROM customers c 
    LEFT JOIN packages p ON c.package_id = p.id 
    WHERE c.id = ? AND c.installed_by = ?
", [$customerId, $tech['id']]);

if (!$customer) {
    setFlash('error', 'Data pelanggan tidak ditemukan atau bukan tugas Anda.');
    redirect('index.php?type=install');
}

// Handle Activation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serialNumber = trim($_POST['serial_number']);
    $lat = $_POST['lat'];
    $lng = $_POST['lng'];
    $photoPath = $customer['installation_photo'];
    
    // Validasi
    if (empty($serialNumber)) {
        setFlash('error', 'Serial Number ONT wajib diisi!');
        redirect("view_install.php?id=$customerId");
    }
    
    // Handle Multiple Photo Upload (Wajib)
    if (!empty($_FILES['photos']['name'][0])) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $uploadedPhotos = [];
        
        $fileCount = count($_FILES['photos']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                $filename = $_FILES['photos']['name'][$i];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $newName = "install_{$customerId}_" . time() . "_{$i}.jpg";
                    $targetDir = "../../uploads/installations/";
                    if (!is_dir($targetDir)) @mkdir($targetDir, 0777, true);
                    $targetFile = $targetDir . $newName;
                    
                    $source = $_FILES['photos']['tmp_name'][$i];
                    $imageInfo = @getimagesize($source);
                    
                    if ($imageInfo) {
                        list($width, $height) = $imageInfo;
                        $newWidth = 800;
                        $newHeight = ($height / $width) * $newWidth;
                        
                        $tmpImg = imagecreatetruecolor($newWidth, $newHeight);
                        $sourceImg = null;
                        
                        switch ($ext) {
                            case 'jpg': case 'jpeg': $sourceImg = @imagecreatefromjpeg($source); break;
                            case 'png': $sourceImg = @imagecreatefrompng($source); break;
                            case 'webp': $sourceImg = @imagecreatefromwebp($source); break;
                        }
                        
                        if ($sourceImg) {
                            if ($ext == 'png' || $ext == 'webp') {
                                imagecolortransparent($tmpImg, imagecolorallocatealpha($tmpImg, 0, 0, 0, 127));
                                imagealphablending($tmpImg, false);
                                imagesavealpha($tmpImg, true);
                            }
                            
                            imagecopyresampled($tmpImg, $sourceImg, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                            
                            if (imagejpeg($tmpImg, $targetFile, 70)) {
                                $uploadedPhotos[] = "uploads/installations/" . $newName;
                                imagedestroy($tmpImg);
                                imagedestroy($sourceImg);
                            }
                        }
                    }
                }
            }
        }
        
        if (!empty($uploadedPhotos)) {
            $photoPath = implode(',', $uploadedPhotos);
        } else {
            setFlash('error', 'Gagal memproses gambar apa pun. Pastikan format valid.');
            redirect("view_install.php?id=$customerId");
        }
    } elseif (empty($customer['installation_photo'])) {
        setFlash('error', 'Wajib upload foto bukti instalasi (bisa lebih dari satu)!');
        redirect("view_install.php?id=$customerId");
    }
    
    // Update DB: Activate Customer
    $updateData = [
        'status' => 'active',
        'serial_number' => $serialNumber,
        'installation_photo' => $photoPath,
        'installation_date' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Update Lat/Lng if provided
    if (!empty($lat) && !empty($lng)) {
        $updateData['lat'] = str_replace(',', '.', $lat);
        $updateData['lng'] = str_replace(',', '.', $lng);
    }
    
    if (update('customers', $updateData, 'id = ?', [$customerId])) {
        // Log Activity
        logActivity('INSTALL_COMPLETE', "Customer #$customerId activated by Tech #{$tech['id']}");
        setFlash('success', 'Instalasi selesai! Pelanggan kini Aktif.');
        redirect('index.php?type=install');
    } else {
        setFlash('error', 'Gagal menyimpan data.');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proses Instalasi - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #00f5ff;
            --bg-dark: #0a0a12;
            --bg-card: #161628;
            --text-primary: #ffffff;
            --text-secondary: #b0b0c0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        
        body {
            background: var(--bg-dark);
            color: var(--text-primary);
            padding-bottom: 20px;
        }
        
        .header {
            background: var(--bg-card);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .back-btn {
            color: var(--text-primary);
            font-size: 1.2rem;
            text-decoration: none;
        }
        
        .container { padding: 20px; }
        
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        
        .label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
            display: block;
        }
        
        .value {
            font-size: 1rem;
            margin-bottom: 15px;
            display: block;
        }
        
        .map-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(0, 245, 255, 0.1);
            color: var(--primary);
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            background: rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: var(--text-primary);
            margin-bottom: 15px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--primary);
            border: none;
            border-radius: 10px;
            color: #000;
            font-weight: bold;
            font-size: 1rem;
            cursor: pointer;
        }
        
        .photo-preview {
            width: 100%;
            height: 200px;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            overflow: hidden;
            border: 2px dashed rgba(255,255,255,0.1);
        }
        
        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .gps-btn {
            background: #2ed573;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
            cursor: pointer;
            margin-bottom: 10px;
        }
        
        .coord-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="index.php?type=install" class="back-btn"><i class="fas fa-arrow-left"></i></a>
        <h2>Instalasi #C<?php echo $customerId; ?></h2>
    </div>

    <div class="container">
        <!-- Customer Info -->
        <div class="card">
            <h3 style="margin-bottom: 15px; color: var(--primary);">Data Pelanggan</h3>
            
            <span class="label">Nama Pelanggan</span>
            <span class="value"><?php echo htmlspecialchars($customer['name']); ?></span>
            
            <span class="label">Alamat</span>
            <span class="value"><?php echo htmlspecialchars($customer['address']); ?></span>
            
            <span class="label">Paket Internet</span>
            <span class="value"><?php echo htmlspecialchars($customer['package_name']); ?></span>
            
            <?php if ($customer['lat'] && $customer['lng']): ?>
                <a href="https://www.google.com/maps/dir/?api=1&destination=<?php echo $customer['lat'] . ',' . $customer['lng']; ?>" target="_blank" class="map-btn">
                    <i class="fas fa-directions"></i> Petunjuk Arah
                </a>
            <?php endif; ?>
        </div>

        <?php if ($customer['status'] === 'active'): ?>
            <div class="card" style="text-align: center; border-color: #00ff88;">
                <i class="fas fa-check-circle" style="font-size: 3rem; color: #00ff88; margin-bottom: 15px;"></i>
                <h3>Instalasi Selesai</h3>
                <p style="color: var(--text-secondary);">Pelanggan ini sudah aktif.</p>
                <?php if ($customer['installation_photo']): 
                    $photos = explode(',', $customer['installation_photo']);
                    foreach($photos as $p):
                ?>
                    <img src="../../<?php echo htmlspecialchars(trim($p)); ?>" style="width: 100%; border-radius: 8px; margin-top: 15px; object-fit: cover;">
                <?php endforeach; endif; ?>
            </div>
        <?php else: ?>
            <!-- Action Form -->
            <div class="card">
                <h3 style="margin-bottom: 15px;">Form Aktivasi</h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <span class="label">Serial Number (SN) ONT</span>
                    <input type="text" name="serial_number" class="form-control" placeholder="Contoh: ZTEGC8E..." required>
                    
                    <span class="label">Koordinat Lokasi (Update jika perlu)</span>
                    <button type="button" class="gps-btn" onclick="getLocation()"><i class="fas fa-map-marker-alt"></i> Ambil Lokasi Saya</button>
                    <div class="coord-grid">
                        <input type="text" name="lat" id="lat" class="form-control" placeholder="Latitude" value="<?php echo htmlspecialchars($customer['lat'] ?? ''); ?>">
                        <input type="text" name="lng" id="lng" class="form-control" placeholder="Longitude" value="<?php echo htmlspecialchars($customer['lng'] ?? ''); ?>">
                    </div>
                    
                    <span class="label">Foto Bukti Instalasi (Wajib, bisa lebih dari satu)</span>
                    <div class="photo-preview" onclick="document.getElementById('photo-input').click()" style="flex-wrap: wrap; height: auto; min-height: 200px; padding: 10px; gap: 10px;">
                        <div id="placeholder" style="text-align: center; color: var(--text-secondary); width: 100%;">
                            <i class="fas fa-camera" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                            Pilih banyak foto peralatan
                        </div>
                    </div>
                    <input type="file" name="photos[]" id="photo-input" accept="image/*" multiple capture="environment" style="display: none;" onchange="previewImages(this)">
                    
                    <button type="submit" class="btn-submit" onclick="return confirm('Pastikan semua data sudah benar. Aktifkan pelanggan?');">Simpan & Aktifkan</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Multiple Images Preview
        function previewImages(input) {
            if (input.files && input.files.length > 0) {
                const container = document.querySelector('.photo-preview');
                container.innerHTML = ''; // Clear placeholder or existing images
                
                for (let i = 0; i < input.files.length; i++) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'preview-img';
                        img.style.width = 'calc(50% - 5px)';
                        img.style.height = '150px';
                        img.style.objectFit = 'cover';
                        img.style.borderRadius = '8px';
                        container.appendChild(img);
                    }
                    reader.readAsDataURL(input.files[i]);
                }
            }
        }

        // GPS Location
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition, showError, { enableHighAccuracy: true });
            } else {
                alert("Geolocation tidak didukung browser ini.");
            }
        }

        function showPosition(position) {
            document.getElementById("lat").value = position.coords.latitude;
            document.getElementById("lng").value = position.coords.longitude;
        }

        function showError(error) {
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    alert("Izin lokasi ditolak.");
                    break;
                case error.POSITION_UNAVAILABLE:
                    alert("Informasi lokasi tidak tersedia.");
                    break;
                case error.TIMEOUT:
                    alert("Waktu permintaan lokasi habis.");
                    break;
                case error.UNKNOWN_ERROR:
                    alert("Terjadi kesalahan yang tidak diketahui.");
                    break;
            }
        }
    </script>

    <?php require_once '../includes/bottom_nav.php'; ?>
</body>
</html>
