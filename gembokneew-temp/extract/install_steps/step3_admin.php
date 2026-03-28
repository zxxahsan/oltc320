<?php
// Step 3: Admin Setup
?>

<h2>👤 Admin Setup</h2>
<p style="margin-bottom: 20px; color: #666;">Buat akun admin untuk login ke panel GEMBOK.</p>

<form method="POST" action="install.php?step=3">
    <div class="form-group">
        <label for="admin_username">Username</label>
        <input type="text" id="admin_username" name="admin_username" value="admin" required>
        <small style="color: #666;">Username untuk login admin panel</small>
    </div>
    
    <div class="form-group">
        <label for="admin_password">Password</label>
        <input type="password" id="admin_password" name="admin_password" required minlength="6">
        <small style="color: #666;">Minimal 6 karakter</small>
    </div>
    
    <div class="form-group">
        <label for="admin_password_confirm">Konfirmasi Password</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="6">
    </div>
    
    <div class="form-group">
        <label for="admin_email">Email (Opsional)</label>
        <input type="email" id="admin_email" name="admin_email" placeholder="admin@example.com">
        <small style="color: #666;">Email admin untuk notifikasi</small>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
        <a href="install.php?step=2" class="btn btn-secondary">← Kembali</a>
        <button type="submit" class="btn btn-primary">Lanjut →</button>
    </div>
</form>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    const pass = document.getElementById('admin_password').value;
    const confirm = document.getElementById('admin_password_confirm').value;
    
    if (pass !== confirm) {
        e.preventDefault();
        alert('Password tidak sama! Silakan cek kembali.');
        return false;
    }
    
    if (pass.length < 6) {
        e.preventDefault();
        alert('Password minimal 6 karakter!');
        return false;
    }
});
</script>
