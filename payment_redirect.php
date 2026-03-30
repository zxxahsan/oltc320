<?php
/**
 * Safe Payment Redirector (Deep-Link Handler)
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$targetUrl = $_GET['url'] ?? '';

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
    <title>Mengalihkan ke Pembayaran...</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/all.min.css">
    <style>
        :root {
            --bg-primary: #0a0b1e;
            --neon-cyan: #00f5ff;
            --text-primary: #ffffff;
            --text-secondary: #b0b0c0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            overflow: hidden;
            text-align: center;
        }
        .container {
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }
        .loader {
            width: 80px;
            height: 80px;
            border: 4px solid rgba(0, 245, 255, 0.1);
            border-left-color: var(--neon-cyan);
            border-radius: 50%;
            margin: 0 auto 30px;
            animation: spin 1s linear infinite;
            filter: drop-shadow(0 0 10px rgba(0, 245, 255, 0.5));
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        h1 { font-size: 1.4rem; margin-bottom: 10px; font-weight: 700; }
        p { color: var(--text-secondary); margin-bottom: 30px; line-height: 1.5; font-size: 0.95rem; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--neon-cyan);
            color: #000000;
            padding: 12px 25px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 245, 255, 0.3);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 245, 255, 0.4);
        }
        .security-note {
            margin-top: 40px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="loader"></div>
        <h1>Sedang Menyiapkan Pembayaran</h1>
        <p>Mohon jangan tutup halaman ini. <br>Anda akan dialihkan otomatis ke aplikasi pembayaran atau Gerai dalam beberapa detik.</p>
        
        <a href="<?php echo htmlspecialchars($targetUrl); ?>" class="btn" id="manualBtn">
            <i class="fas fa-external-link-alt"></i> Buka Pembayaran Manual
        </a>
        
        <div class="security-note">
            <i class="fas fa-lock"></i> Koneksi Aman & Terenkripsi oleh Tripay
        </div>
    </div>

    <script>
        // Use window.location.replace to prevent users from going "back" to this loader
        setTimeout(() => {
            window.location.replace("<?php echo $targetUrl; ?>");
            
            // Second try for deep-link heavy mobile browsers
            window.location.href = "<?php echo $targetUrl; ?>";
        }, 800);

        // Track if it worked or not - if user is still here after 5s, highlight the manual button
        setTimeout(() => {
            const btn = document.getElementById('manualBtn');
            btn.style.animation = 'pulse 1.5s infinite';
            const h1 = document.querySelector('h1');
            h1.innerText = "Redirect Terhambat? ";
            const p = document.querySelector('p');
            p.innerText = "Klik tombol di bawah ini jika halaman pembayaran tidak terbuka otomatis.";
        }, 5000);
    </script>
    <style>
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</body>
</html>
