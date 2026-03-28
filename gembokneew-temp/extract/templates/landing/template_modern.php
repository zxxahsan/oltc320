<?php
/**
 * Template 2: Modern Clean Theme
 * Light, clean design with blue accents
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $appName; ?> - Internet Service Provider</title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo $appName; ?>">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192x192.png">
    <link rel="icon" type="image/png" href="/assets/icons/icon-192x192.png">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #3b82f6;
            --light: #ffffff;
            --dark: #1e293b;
            --gray: #64748b;
            --bg-light: #f8fafc;
            --bg-white: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: var(--dark); line-height: 1.6; }

        .navbar {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 5%; background: var(--bg-white);
            position: fixed; top: 0; left: 0; width: 100%; z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .logo { font-size: 1.5rem; font-weight: 700; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 10px; }
        .nav-links { display: flex; gap: 30px; }
        .nav-links a { color: var(--dark); text-decoration: none; font-size: 0.95rem; font-weight: 500; transition: 0.3s; }
        .nav-links a:hover { color: var(--primary); }
        
        /* Dropdown Menu */
        .dropdown { position: relative; display: inline-block; }
        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--bg-white);
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            z-index: 1;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            top: 100%;
            right: 0;
            overflow: hidden;
            margin-top: 10px;
        }
        .dropdown-content a {
            color: var(--dark);
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            text-align: left;
            transition: 0.3s;
        }
        .dropdown-content a:hover {
            background: var(--bg-light);
            color: var(--primary);
        }
        .dropdown:hover .dropdown-content { display: block; }
        
        .login-btn {
            background: var(--primary); padding: 12px 28px; border-radius: 8px;
            color: #fff !important; font-weight: 600; border: none; cursor: pointer;
            transition: 0.3s; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.2);
        }
        .login-btn:hover { background: var(--primary-dark); transform: translateY(-2px); }

        .hero {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 120px 5% 60px; background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
            text-align: center;
        }

        .hero-content { max-width: 800px; }
        .hero h1 { font-size: 3rem; line-height: 1.2; margin-bottom: 20px; color: var(--dark); }
        .hero p { font-size: 1.2rem; color: var(--gray); margin-bottom: 30px; }
        .cta-buttons { display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; }
        .btn { padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: #fff; }

        .features { padding: 80px 5%; background: var(--bg-white); }
        .section-title { font-size: 2.5rem; margin-bottom: 15px; color: var(--dark); text-align: center; }
        .section-subtitle { color: var(--gray); text-align: center; margin-bottom: 50px; }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px; }
        .feature-card {
            background: var(--bg-light); padding: 40px; border-radius: 16px;
            transition: 0.3s; border: 1px solid #e2e8f0;
        }
        .feature-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); }
        .feature-icon { font-size: 2.5rem; color: var(--primary); margin-bottom: 20px; }
        .feature-card h3 { font-size: 1.3rem; margin-bottom: 12px; color: var(--dark); }
        .feature-card p { color: var(--gray); }

        .packages { padding: 80px 5%; background: var(--bg-light); text-align: center; }
        .package-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 50px; }
        .package-card {
            background: var(--bg-white); padding: 40px; border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05); transition: 0.3s;
        }
        .package-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1); }
        .package-card h3 { font-size: 1.5rem; margin-bottom: 15px; color: var(--dark); }
        .package-price { font-size: 2.5rem; color: var(--primary); font-weight: 700; margin-bottom: 20px; }
        .package-features { text-align: left; margin: 20px 0; }
        .package-features li { padding: 8px 0; color: var(--gray); list-style: none; }
        .package-features li i { color: #10b981; margin-right: 10px; }

        .contact { padding: 80px 5%; background: var(--bg-white); text-align: center; }
        .contact-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-top: 50px; }
        .contact-item { background: var(--bg-light); padding: 30px; border-radius: 12px; }
        .contact-item i { font-size: 2rem; color: var(--primary); margin-bottom: 15px; }
        .contact-item h3 { margin-bottom: 10px; color: var(--dark); }
        .contact-item p { color: var(--gray); }

        .footer { padding: 40px 5%; background: var(--dark); color: var(--light); text-align: center; }
        .social-links { display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; }
        .social-links a { color: var(--light); font-size: 1.5rem; transition: 0.3s; }
        .social-links a:hover { color: var(--primary); }

        @media (max-width: 768px) {
            .hero h1 { font-size: 2rem; }
            .navbar { padding: 15px 20px; }
            .nav-links { display: none; }
            .features, .packages, .contact { padding: 60px 20px; }
            .cta-buttons { flex-direction: column; }
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
        <h2 class="section-title">Kenapa Memilih <?php echo $appName; ?></h2>
        <p class="section-subtitle">Layanan internet terbaik untuk kebutuhan Anda</p>
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
        <h2 class="section-title">Paket Internet</h2>
        <p class="section-subtitle">Pilih paket yang sesuai dengan kebutuhan Anda</p>
        <div class="package-grid">
            <?php foreach ($packages as $pkg): ?>
            <div class="package-card">
                <h3><?php echo htmlspecialchars($pkg['name']); ?></h3>
                <div class="package-price"><?php echo formatCurrency($pkg['price']); ?></div>
                <ul class="package-features">
                    <li><i class="fas fa-check"></i> Koneksi stabil</li>
                    <li><i class="fas fa-check"></i> Unlimited kuota</li>
                    <li><i class="fas fa-check"></i> Support 24/7</li>
                </ul>
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
                <a href="https://wa.me/<?php echo $waPhone; ?>?text=<?php echo $waMessage; ?>" target="_blank" class="btn btn-primary" style="width: 100%;">Pilih Paket</a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="contact" id="contact">
        <h2 class="section-title">Hubungi Kami</h2>
        <p class="section-subtitle">Kami siap membantu Anda</p>
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
