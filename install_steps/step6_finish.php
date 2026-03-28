<?php
// Step 6: Finish Installation
?>

<h2>🎉 Ready to Install!</h2>
<p style="margin-bottom: 20px; color: #666;">Installer sudah siap untuk menginstall GEMBOK ke server Anda.</p>

<div class="alert alert-info">
    <strong>📋 Summary:</strong>
    <ul style="margin: 10px 0 0 20px; color: #666;">
        <li>Database: <?php echo htmlspecialchars($_SESSION['db_config']['name'] ?? 'Not configured'); ?></li>
        <li>Admin Username: <?php echo htmlspecialchars($_SESSION['admin_config']['username'] ?? 'Not configured'); ?></li>
        <li>MikroTik: <?php echo !empty($_SESSION['mikrotik_config']['host']) ? 'Configured' : 'Not configured (optional)'; ?></li>
        <li>Integrations: <?php echo !empty($_SESSION['integrations_config']['whatsapp_url']) ? 'Configured' : 'Not configured (optional)'; ?></li>
    </ul>
</div>

<div class="alert alert-success">
    <strong>✅ Apa yang akan dilakukan installer:</strong>
    <ul style="margin: 10px 0 0 20px; color: #666;">
        <li>Create configuration file (includes/config.php)</li>
        <li>Create all database tables</li>
        <li>Insert admin user dan pengaturan dasar (tanpa data sample)</li>
        <li>Create installation lock file</li>
    </ul>
</div>

<div class="alert alert-warning">
    <strong>⚠️ Important:</strong>
    <ul style="margin: 10px 0 0 20px; color: #666;">
        <li>Pastikan database sudah dibuat di cPanel/phpMyAdmin</li>
        <li>Installer akan menghapus semua data di database yang sama</li>
        <li>Backup data penting sebelum melanjutkan</li>
        <li>Setelah instalasi berhasil, hapus file install.sh dari server jika digunakan untuk instalasi agar tidak dijalankan ulang dan menghapus data yang sudah ada</li>
    </ul>
</div>

<form method="POST" action="install.php?step=6">
    <div style="display: flex; gap: 10px; margin-top: 20px;">
        <a href="install.php?step=5" class="btn btn-secondary">← Kembali</a>
        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
            🚀 Install Now
        </button>
    </div>
</form>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    if (!confirm('Apakah Anda yakin ingin menginstall GEMBOK?\n\nDatabase akan di-reset dan semua data akan dihapus.\n\nLanjutkan?')) {
        e.preventDefault();
        return false;
    }
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Installing... Please wait...';
});
</script>
