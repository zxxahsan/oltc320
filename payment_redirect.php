<?php
/**
 * Safe Payment Redirector (Deep-Link Handler)
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$targetUrl = $_GET['url'] ?? '';
$qrUrl = $_GET['qr'] ?? '';
$payUrl = $_GET['pay'] ?? '';

if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    die('Invalid payment URL');
}

// Ensure the URL is from Tripay (Security)
$host = parse_url($targetUrl, PHP_URL_HOST);
if (strpos($host, 'tripay.co.id') === false) {
    die('Unauthorized payment domain');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selesaikan Pembayaran...</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0b1e;
            --bg-card: #14152a;
            --neon-cyan: #00f5ff;
            --neon-purple: #bf00ff;
            --text-primary: #ffffff;
            --text-secondary: #b0b0c0;
            --border-color: rgba(255, 255, 255, 0.1);
        }
        body {
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
            text-align: center;
        }
        .container {
            background: var(--bg-card);
            padding: 40px;
            max-width: 440px;
            width: 100%;
            border-radius: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 15px 50px rgba(0,0,0,0.5);
            position: relative;
            overflow: hidden;
        }
        .container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(191,0,255,0.05) 0%, transparent 70%);
            z-index: 0;
        }
        .content { position: relative; z-index: 1; }
        .loader {
            width: 60px;
            height: 60px;
            border: 3px solid rgba(0, 245, 255, 0.1);
            border-left-color: var(--neon-cyan);
            border-radius: 50%;
            margin: 0 auto 25px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .qr-container {
            background: #fff;
            padding: 15px;
            border-radius: 16px;
            margin: 20px auto;
            width: fit-content;
            box-shadow: 0 0 20px rgba(0,0,0,0.3), 0 0 40px rgba(0, 245, 255, 0.2);
        }
        .qr-container img {
            width: 220px;
            height: 220px;
            display: block;
        }
        
        h1 { font-size: 1.5rem; margin-bottom: 12px; font-weight: 700; letter-spacing: -0.5px; }
        p { color: var(--text-secondary); margin-bottom: 30px; line-height: 1.5; font-size: 0.95rem; }
        
        .btn-group { display: flex; flex-direction: column; gap: 12px; }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 14px 25px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.95rem;
        }
        .btn-primary {
            background: var(--neon-cyan);
            color: #000;
            box-shadow: 0 4px 20px rgba(0, 245, 255, 0.3);
        }
        .btn-primary:active { transform: scale(0.98); }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.1); }
        
        .timer {
            margin-top: 25px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        .security-badge {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <?php if (!empty($qrUrl)): ?>
                <h1>Selesaikan Bayar</h1>
                <p>Silakan scan Kode QR di bawah ini (ShopeePay / Dana / QRIS) atau klik tombol buka aplikasi.</p>
                <div class="qr-container">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Payment QR Code">
                </div>
            <?php else: ?>
                <div class="loader"></div>
                <h1>Menyiapkan Gateway</h1>
                <p>Harap sedia bank aplikasi / VA nomor Anda. <br>Anda akan dialihkan otomatis dalam beberapa detik.</p>
            <?php endif; ?>

            <div class="btn-group">
                <a href="<?php echo htmlspecialchars(!empty($payUrl) ? $payUrl : $targetUrl); ?>" class="btn btn-primary">
                    <i class="fas fa-external-link-alt"></i> Buka Aplikasi Pembayaran
                </a>
                <?php if (!empty($qrUrl)): ?>
                    <a href="<?php echo htmlspecialchars($targetUrl); ?>" class="btn btn-secondary">
                        <i class="fas fa-info-circle"></i> Lihat Instruksi Lengkap
                    </a>
                <?php endif; ?>
            </div>

            <div class="timer" id="redirectText">
                <?php echo empty($qrUrl) ? 'Mengalihkan otomatis dalam <span id="countdown">3</span> detik...' : 'Halaman instruksi otomatis dibuka dalam 15 detik.'; ?>
            </div>

            <div class="security-badge">
                <i class="fas fa-shield-alt" style="color: var(--neon-cyan);"></i>
                Transaksi Aman Terverifikasi oleh Tripay
            </div>
        </div>
    </div>

    <script>
        const qrPresent = <?php echo !empty($qrUrl) ? 'true' : 'false'; ?>;
        const targetUrl = "<?php echo $targetUrl; ?>";
        const payUrl = "<?php echo $payUrl; ?>";
        
        if (!qrPresent) {
            let count = 3;
            const timer = setInterval(() => {
                count--;
                document.getElementById('countdown').innerText = count;
                if (count <= 0) {
                    clearInterval(timer);
                    window.location.replace(payUrl || targetUrl);
                }
            }, 1000);
        } else {
            // If QR is present, wait longer before redirecting to instruction page
            setTimeout(() => {
                // Only redirect if the user hasn't left the page
                // window.location.href = targetUrl;
            }, 15000);
        }
    </script>
</body>
</html>
