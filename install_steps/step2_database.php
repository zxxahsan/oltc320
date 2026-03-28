<?php
// Step 2: Database Setup
?>

<h2>🗄️ Database Setup</h2>
<p style="margin-bottom: 20px; color: #666;">Setup koneksi database untuk GEMBOK.</p>

<form method="POST" action="install.php?step=2">
    <div class="form-group">
        <label for="db_host">Database Host</label>
        <input type="text" id="db_host" name="db_host" value="localhost" required>
        <small style="color: #666;">Biasanya: localhost</small>
    </div>
    
    <div class="form-group">
        <label for="db_name">Database Name</label>
        <input type="text" id="db_name" name="db_name" placeholder="gembok_db" required>
        <small style="color: #666;">Nama database yang sudah dibuat di cPanel/phpMyAdmin</small>
    </div>
    
    <div class="form-group">
        <label for="db_user">Database Username</label>
        <input type="text" id="db_user" name="db_user" placeholder="root" required>
        <small style="color: #666;">Username database MySQL</small>
    </div>
    
    <div class="form-group">
        <label for="db_pass">Database Password</label>
        <input type="password" id="db_pass" name="db_pass" placeholder="Masukkan password database">
        <small style="color: #666;">Password database MySQL (kosongkan jika tidak ada)</small>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
        <a href="install.php?step=1" class="btn btn-secondary">← Kembali</a>
        <button type="submit" class="btn btn-primary">Test Connection & Lanjut →</button>
    </div>
</form>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing Connection...';
});
</script>
