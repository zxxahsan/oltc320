<?php
require_once '../../includes/auth.php';
requireTechnicianLogin();

$pageTitle = 'Manajemen Perangkat';
$tech = $_SESSION['technician'];
$username = $_GET['username'] ?? '';
$serial = $_GET['serial'] ?? '';

if (empty($username) && empty($serial)) {
    redirect('search.php');
}

// 1. Try to find customer in DB parsing generalized phone schema maps natively 
$customer = null;
if (!empty($username)) {
    $customer = fetchOne("SELECT * FROM customers WHERE pppoe_username = ?", [$username]);
} 
if (!$customer && !empty($serial)) {
    $customer = fetchOne("SELECT * FROM customers WHERE phone = ? OR pppoe_username = ?", [$serial, $serial]);
}

if (!$customer) {
    if (empty($username) && empty($serial)) {
        setFlash('error', 'Data pelanggan tidak ditemukan.');
        redirect('search.php');
    }
    $customer = [
        'name' => 'Unregistered Device',
        'pppoe_username' => $username ?: $serial,
        'serial_number' => $serial ?: '',
        'address' => 'Alamat tidak diketahui',
        'status' => 'unknown'
    ];
}

$device = null;
$error = null;

if (!empty($customer['serial_number'])) {
    $device = genieacsGetDevice($customer['serial_number']);
} else if (!empty($serial)) {
    $device = genieacsGetDevice($serial);
}

if (!$device && !empty($customer['phone'])) {
    $device = genieacsFindDeviceByPppoe($customer['phone']);
}

if (!$device && !empty($customer['pppoe_username'])) {
    $device = genieacsFindDeviceByPppoe($customer['pppoe_username']);
}

if (!$device && !empty($username)) {
    $device = genieacsGetDevice($username);
}

// Dapatkan Status Online Mikrotik SAJA
require_once '../../includes/mikrotik_api.php';
$isOnline = false;
$pppoeUptime = '-';
if (!empty($customer['pppoe_username'])) {
    $activeSession = mikrotikGetActiveSessionByUsername($customer['pppoe_username']);
    if ($activeSession) {
        $isOnline = true;
        $pppoeUptime = $activeSession['uptime'] ?? '00:00:00';
    }
}

function getDeviceVal($data, $path) {
    return genieacsGetValue($data, $path);
}

if ($device) {
    $lastInform = $device['_lastInform'] ?? null;
    $rxPower = getDeviceVal($device, 'VirtualParameters.RXPower');
    if ($rxPower === null) {
        $rxPower = getDeviceVal($device, 'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RxPower') ?? 
                   getDeviceVal($device, 'Device.Optical.Interface.1.RXPower');
    }
    if (is_array($rxPower)) {
        $rxPower = $rxPower['_value'] ?? $rxPower['value'] ?? 'N/A';
    }
    if ($rxPower === null || $rxPower === '') $rxPower = 'N/A';
} else {
    $error = "Redaman tidak tersedia (Perangkat tidak ditemukan di GenieACS). Pastikan tagging No HP / PPPoE sesuai.";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage ONT - <?php echo htmlspecialchars($customer['name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #00f5ff;
            --bg-dark: #0a0a12;
            --bg-card: #161628;
            --text-primary: #ffffff;
            --text-secondary: #b0b0c0;
            --success: #00ff88;
            --danger: #ff4757;
            --warning: #ffcc00;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background: var(--bg-dark); color: var(--text-primary); padding-bottom: 80px; }
        .header { background: var(--bg-card); padding: 15px 20px; display: flex; align-items: center; gap: 15px; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        .back-btn { color: var(--text-primary); font-size: 1.2rem; text-decoration: none; }
        .container { padding: 20px; }
        .card { background: var(--bg-card); border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,0.05); }
        .customer-header { text-align: center; margin-bottom: 20px; }
        .customer-name { font-size: 1.2rem; font-weight: bold; color: var(--primary); }
        .status-badge { display: inline-block; padding: 5px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: bold; margin-top: 5px; }
        .badge-success { background: rgba(0, 255, 136, 0.2); color: var(--success); border: 1px solid rgba(0, 255, 136, 0.3); }
        .badge-danger { background: rgba(255, 71, 87, 0.2); color: var(--danger); border: 1px solid rgba(255, 71, 87, 0.3); }
        .badge-warning { background: rgba(255, 204, 0, 0.2); color: var(--warning); border: 1px solid rgba(255, 204, 0, 0.3); }
        .error-msg { background: rgba(255, 71, 87, 0.1); color: var(--danger); padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; font-size: 1rem; transition: 0.2s; text-decoration: none; margin-bottom: 10px; }
        .btn-secondary { background: rgba(255,255,255,0.1); color: var(--text-primary); }
    </style>
</head>
<body>
    <div class="header">
        <a href="search.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
        <h2>Detail Perangkat</h2>
    </div>

    <div class="container">
        <?php if ($error): ?>
            <div class="error-msg">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
            <div class="card">
                <div class="customer-header">
                    <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                    <div style="color: var(--text-secondary); margin-top: 5px;"><?php echo htmlspecialchars($customer['pppoe_username']); ?></div>
                </div>
            </div>
            <a href="search.php" class="btn btn-secondary">Kembali Cari</a>
        <?php else: ?>
            <div class="card" style="text-align: center;">
                <div class="customer-header">
                    <div class="customer-name" style="font-size: 1.5rem; margin-bottom: 10px;"><?php echo htmlspecialchars($customer['name']); ?></div>
                    <div style="color: var(--text-secondary);"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($customer['pppoe_username']); ?></div>
                </div>

                <div style="display: flex; gap: 20px; justify-content: center; margin-top: 30px; margin-bottom: 20px; flex-wrap: wrap;">
                    <div style="padding: 20px; background: rgba(0,0,0,0.3); border-radius: 12px; width: 160px; border: 1px solid rgba(255,255,255,0.05);">
                        <?php if ($isOnline): ?>
                            <i class="fas fa-globe" style="font-size: 3rem; color: var(--success); margin-bottom: 15px;"></i>
                            <div style="font-weight: bold; font-size: 1.3rem; color: var(--success);">ONLINE</div>
                        <?php else: ?>
                            <i class="fas fa-globe" style="font-size: 3rem; color: var(--danger); margin-bottom: 15px;"></i>
                            <div style="font-weight: bold; font-size: 1.3rem; color: var(--danger);">OFFLINE</div>
                        <?php endif; ?>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">Status Jaringan</div>
                    </div>
                    
                    <div style="padding: 20px; background: rgba(0,0,0,0.3); border-radius: 12px; width: 160px; border: 1px solid rgba(255,255,255,0.05);">
                        <i class="fas fa-broadcast-tower" style="font-size: 3rem; color: var(--neon-cyan); margin-bottom: 15px;"></i>
                        <div style="font-weight: bold; font-size: 1.3rem; color: var(--neon-cyan);">
                            <?php 
                                $rxClass = '';
                                $rxVal = floatval($rxPower);
                                if ($rxVal > -25 && $rxVal < 0) $rxClass = 'color: var(--success);';
                                elseif ($rxVal > -28 && $rxVal <= -25) $rxClass = 'color: var(--warning);';
                                else if ($rxVal <= -28) $rxClass = 'color: var(--danger);';
                            ?>
                            <span style="<?php echo $rxClass; ?>">
                                <?php echo $rxPower ? htmlspecialchars($rxPower) . ' dBm' : '-'; ?>
                            </span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">Redaman Laser RX</div>
                    </div>
                </div>
                
                <?php if (isset($lastInform) && $lastInform): ?>
                <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.05);">
                    <i class="fas fa-clock"></i> Sinkronisasi Terakhir: <?php echo date('d M Y H:i', strtotime($lastInform)); ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php require_once '../../includes/bottom_nav.php'; ?>
</body>
</html>
