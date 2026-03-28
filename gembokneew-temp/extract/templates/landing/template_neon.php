<?php
/**
 * Template 1: Neon Dark Theme (Default)
 * Modern dark theme with neon accents and gradients
 */
?>
<!DOCTYPE html>
<html lang="id" <?php echo $themeColor !== 'neon' ? 'data-theme="' . htmlspecialchars($themeColor) . '"' : ''; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $appName; ?> - Internet Service Provider</title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#0a0a12">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo $appName; ?>">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192x192.png">
    <link rel="icon" type="image/png" href="/assets/icons/icon-192x192.png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #00f5ff;
            --secondary: #bf00ff;
            --dark: #0a0a12;
            --light: #ffffff;
            --gray: #b0b0c0;
            --bg-dark: #0f0f1a;
            --bg-card: #1a1a2e;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: var(--dark); color: var(--light); overflow-x: hidden; }

        .navbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 50px; background: rgba(10, 10, 18, 0.95);
            position: fixed; top: 0; left: 0; width: 100%; z-index: 1000;
            backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .logo { font-size: 1.5rem; font-weight: 700; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; gap: 30px; }
        .nav-links a { color: var(--light); text-decoration: none; font-size: 0.95rem; font-weight: 500; transition: 0.3s; }
        .nav-links a:hover { color: var(--primary); }
        
        /* Dropdown Menu */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--bg-card);
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.3);
            z-index: 1;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            top: 100%;
            right: 0;
            overflow: hidden;
            margin-top: 10px;
        }
        .dropdown-content a {
            color: var(--light);
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            text-align: left;
            transition: 0.3s;
        }
        .dropdown-content a:hover {
            background: rgba(0, 245, 255, 0.1);
            color: var(--primary);
        }
        .dropdown:hover .dropdown-content { display: block; }
        
        .login-btn {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            padding: 10px 25px; border-radius: 50px; color: #fff !important;
            font-weight: 600; box-shadow: 0 4px 15px rgba(0, 245, 255, 0.3);
            border: none; cursor: pointer; transition: transform 0.2s;
        }
        .login-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 245, 255, 0.4); }

        .hero {
            height: 100vh; display: flex; align-items: center; justify-content: space-between;
            padding: 0 50px; position: relative;
            background: radial-gradient(circle at top right, rgba(191, 0, 255, 0.1), transparent 40%),
                        radial-gradient(circle at bottom left, rgba(0, 245, 255, 0.1), transparent 40%);
            margin-top: 60px;
        }

        .hero-content { max-width: 600px; z-index: 1; }
        .hero h1 { font-size: 3.5rem; line-height: 1.2; margin-bottom: 20px; background: linear-gradient(to right, #fff, #b0b0c0); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .hero p { font-size: 1.1rem; color: var(--gray); margin-bottom: 30px; line-height: 1.6; }
        .cta-buttons { display: flex; gap: 20px; }
        .btn { padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
        .btn-primary { background: var(--primary); color: #000; }
        .btn-primary:hover { background: #00dcec; }
        .btn-outline { border: 2px solid #333; color: var(--light); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }

        .features { padding: 80px 50px; background: var(--bg-dark); text-align: center; }
        .section-title { font-size: 2.5rem; margin-bottom: 50px; }
        .section-title span { color: var(--primary); }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; }
        .feature-card {
            background: var(--bg-card); padding: 40px; border-radius: 20px;
            transition: 0.3s; border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .feature-card:hover { transform: translateY(-10px); border-color: var(--primary); }
        .feature-icon { font-size: 3rem; color: var(--primary); margin-bottom: 20px; }
        .feature-card h3 { font-size: 1.3rem; margin-bottom: 15px; }
        .feature-card p { color: var(--gray); line-height: 1.6; }

        .packages { padding: 80px 50px; background: var(--dark); text-align: center; }
        .package-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 50px; }
        .package-card {
            background: var(--bg-card); padding: 40px; border-radius: 20px;
            border: 2px solid rgba(255, 255, 255, 0.05); transition: 0.3s;
        }
        .package-card:hover { border-color: var(--primary); transform: scale(1.05); }
        .package-card h3 { font-size: 1.5rem; margin-bottom: 15px; }
        .package-price { font-size: 2.5rem; color: var(--primary); font-weight: 700; margin-bottom: 20px; }

        .contact { padding: 80px 50px; background: var(--bg-dark); text-align: center; }
        .contact-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-top: 50px; }
        .contact-item { background: var(--bg-card); padding: 30px; border-radius: 15px; }
        .contact-item i { font-size: 2rem; color: var(--primary); margin-bottom: 15px; }

        .footer { padding: 40px 50px; background: var(--dark); border-top: 1px solid rgba(255, 255, 255, 0.05); text-align: center; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; }
        .social-links a { color: var(--gray); font-size: 1.5rem; transition: 0.3s; }
        .social-links a:hover { color: var(--primary); }

        @media (max-width: 768px) {
            .hero { flex-direction: column; text-align: center; padding: 100px 20px; }
            .hero h1 { font-size: 2.5rem; }
            .navbar { padding: 15px 20px; }
            .nav-links { display: none; }
            .features, .packages, .contact { padding: 60px 20px; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <a href="#" class="logo"><i class="fas fa-wifi"></i> <?php echo $appName; ?></a>
        <div class="nav-links">
            <a href="#features">Fitur</a>
            <a href="#packages">Paket</a>
            <a href="#contact">Kontak</a>
        </div>
        <div class="dropdown">
            <a href="#" class="login-btn">Login <i class="fas fa-chevron-down"></i></a>
            <div class="dropdown-content">
                <a href="portal/login.php"><i class="fas fa-user"></i> Pelanggan</a>
                <a href="sales/login.php"><i class="fas fa-user-tie"></i> Sales / Agen</a>
                <a href="technician/login.php"><i class="fas fa-tools"></i> Teknisi</a>
                <a href="admin/login.php"><i class="fas fa-user-shield"></i> Admin</a>
            </div>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-content">
            <h1><?php echo $heroTitle; ?></h1>
            <p><?php echo $heroDesc; ?></p>
            <div class="cta-buttons">
                <a href="#packages" class="btn btn-primary"><i class="fas fa-rocket"></i> Mulai Sekarang</a>
                <a href="#contact" class="btn btn-outline"><i class="fas fa-phone"></i> Hubungi Kami</a>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <h2 class="section-title">Kenapa Memilih <span><?php echo $appName; ?></span></h2>
        <div class="feature-grid">
            <div class="feature-card">
                <i class="fas fa-tachometer-alt feature-icon"></i>
                <h3><?php echo $f1_title; ?></h3>
                <p><?php echo $f1_desc; ?></p>
            </div>
            <div class="feature-card">
                <i class="fas fa-infinity feature-icon"></i>
                <h3><?php echo $f2_title; ?></h3>
                <p><?php echo $f2_desc; ?></p>
            </div>
            <div class="feature-card">
                <i class="fas fa-headset feature-icon"></i>
                <h3><?php echo $f3_title; ?></h3>
                <p><?php echo $f3_desc; ?></p>
            </div>
        </div>
    </section>

    <section class="packages" id="packages">
        <h2 class="section-title">Paket <span>Internet</span></h2>
        <div class="package-grid">
            <?php foreach ($packages as $pkg): ?>
            <div class="package-card">
                <h3><?php echo htmlspecialchars($pkg['name']); ?></h3>
                <div class="package-price"><?php echo formatCurrency($pkg['price']); ?></div>
                <p><?php echo htmlspecialchars($pkg['description'] ?? ''); ?></p>
                <br>
                <?php
                // Clean phone number and ensure proper format
                $waPhone = preg_replace('/[^0-9]/', '', $contactPhone);
                // If starts with 0, replace with 62
                if (substr($waPhone, 0, 1) === '0') {
                    $waPhone = '62' . substr($waPhone, 1);
                }
                $waMessage = urlencode("Halo, saya tertarik dengan paket " . htmlspecialchars($pkg['name']) . " - " . formatCurrency($pkg['price']));
                ?>
                <a href="https://wa.me/<?php echo $waPhone; ?>?text=<?php echo $waMessage; ?>" target="_blank" class="btn btn-primary">Pilih Paket</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="contact" id="contact">
        <h2 class="section-title">Hubungi <span>Kami</span></h2>
        <div class="contact-grid">
            <div class="contact-item">
                <i class="fas fa-phone"></i>
                <h3>Telepon</h3>
                <p><?php echo $contactPhone; ?></p>
            </div>
            <div class="contact-item">
                <i class="fas fa-envelope"></i>
                <h3>Email</h3>
                <p><?php echo $contactEmail; ?></p>
            </div>
            <div class="contact-item">
                <i class="fas fa-map-marker-alt"></i>
                <h3>Alamat</h3>
                <p><?php echo $contactAddress; ?></p>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="social-links">
            <a href="<?php echo $s_fb; ?>"><i class="fab fa-facebook"></i></a>
            <a href="<?php echo $s_ig; ?>"><i class="fab fa-instagram"></i></a>
            <a href="<?php echo $s_tw; ?>"><i class="fab fa-twitter"></i></a>
            <a href="<?php echo $s_yt; ?>"><i class="fab fa-youtube"></i></a>
        </div>
        <p><?php echo $footerAbout; ?></p>
    </footer>

    <script>
        // Register Service Worker for PWA
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful with scope: ', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('ServiceWorker registration failed: ', error);
                    });
            });
        }
    </script>
</body>
</html>
