<?php
// Step 5: Integrations Setup (Optional)
?>

<h2>🔗 Integrations Setup (Opsional)</h2>
<p style="margin-bottom: 20px; color: #666;">Setup integrasi dengan WhatsApp, Payment Gateway, dan Telegram.</p>

<div class="alert alert-info">
    <strong>ℹ️ Info:</strong> Setup ini opsional. Jika Anda ingin setup nanti, bisa skip langkah ini dan setup melalui panel admin.
</div>

<form method="POST" action="install.php?step=5">
    <h3 style="margin-bottom: 15px; color: #667eea;">📱 WhatsApp Integration</h3>
    <div class="form-group">
        <label for="whatsapp_url">WhatsApp API URL</label>
        <input type="text" id="whatsapp_url" name="whatsapp_url" placeholder="https://api.fonnte.com/send">
        <small style="color: #666;">URL API WhatsApp (Fonnte, dll)</small>
    </div>
    
    <div class="form-group">
        <label for="whatsapp_token">WhatsApp Token</label>
        <input type="text" id="whatsapp_token" name="whatsapp_token" placeholder="Masukkan token WhatsApp">
        <small style="color: #666;">Token API WhatsApp</small>
    </div>
    
    <hr style="margin: 30px 0; border-color: #e9ecef;">
    
    <h3 style="margin-bottom: 15px; color: #667eea;">💳 Payment Gateway (Tripay)</h3>
    <div class="form-group">
        <label for="tripay_api_key">Tripay API Key</label>
        <input type="text" id="tripay_api_key" name="tripay_api_key" placeholder="Masukkan API Key Tripay">
        <small style="color: #666;">API Key dari Tripay dashboard</small>
    </div>
    
    <div class="form-group">
        <label for="tripay_private_key">Tripay Private Key</label>
        <input type="text" id="tripay_private_key" name="tripay_private_key" placeholder="Masukkan Private Key Tripay">
        <small style="color: #666;">Private Key dari Tripay dashboard</small>
    </div>
    
    <div class="form-group">
        <label for="tripay_merchant_code">Tripay Merchant Code</label>
        <input type="text" id="tripay_merchant_code" name="tripay_merchant_code" placeholder="Masukkan Merchant Code">
        <small style="color: #666;">Merchant Code dari Tripay dashboard</small>
    </div>
    
    <hr style="margin: 30px 0; border-color: #e9ecef;">
    
    <h3 style="margin-bottom: 15px; color: #667eea;">🤖 Telegram Bot</h3>
    <div class="form-group">
        <label for="telegram_token">Telegram Bot Token</label>
        <input type="text" id="telegram_token" name="telegram_token" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
        <small style="color: #666;">Token dari @BotFather</small>
    </div>
    
    <div style="display: flex; gap: 10px; margin-top: 20px;">
        <a href="install.php?step=4" class="btn btn-secondary">← Kembali</a>
        <button type="submit" class="btn btn-primary">Lanjut →</button>
    </div>
</form>

<script>
document.querySelector('form').addEventListener('submit', function(e) {
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});
</script>
