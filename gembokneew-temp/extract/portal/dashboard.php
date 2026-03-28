<?php
/**
 * Customer Portal Dashboard
 */

require_once '../includes/auth.php';
requireCustomerLogin();

$customerSession = getCurrentCustomer();

// Fetch fresh customer data from database to ensure synchronization
if ($customerSession && isset($customerSession['id'])) {
    $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$customerSession['id']]);
    
    // Update session with fresh data
    if ($customer) {
        // Keep login info that might not be in DB or different structure
        $customer['logged_in'] = true;
        $customer['login_time'] = $customerSession['login_time'] ?? time();
        $_SESSION['customer'] = $customer;
    } else {
        $customer = $customerSession;
    }
} else {
    $customer = $customerSession;
}

// Safely get the package, handling cases where package_id might not exist or be valid
$package = null;
if (isset($customer['package_id']) && !empty($customer['package_id'])) {
    $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);
}

// Get current month invoice
$currentMonth = date('Y-m');
$currentInvoice = fetchOne("
    SELECT * FROM invoices 
    WHERE customer_id = ? 
    AND DATE_FORMAT(created_at, '%Y-%m') = ?
    ORDER BY created_at DESC 
    LIMIT 1",
    [$customer['id'], $currentMonth]
);

// Get recent invoices
$recentInvoices = fetchAll("
    SELECT * FROM invoices 
    WHERE customer_id = ? 
    ORDER BY created_at DESC 
    LIMIT 6",
    [$customer['id']]
);

// Get ONU data from GenieACS using PPPoE username lookup
$onuData = null;
$onuOnline = false;
$onuSignal = 'N/A';
$onuDevices = '-';

// Find device by customer's PPPoE username using the enhanced function
$customerDevice = genieacsFindDeviceByPppoe($customer['pppoe_username']);

if ($customerDevice) {
    // Get detailed device info using the actual device ID/serial, not the username
    $deviceId = $customerDevice['_id'] ?? $customerDevice['_deviceId']['_SerialNumber'] ?? $customer['pppoe_username'];
    $onuData = genieacsGetDeviceInfo($deviceId);
    
    // Determine online status
    if ($onuData && isset($onuData['status'])) {
        $onuOnline = ($onuData['status'] === 'online');
    }
    
    // Get signal strength
    $rxPowerFromDevice = genieacsGetValue($customerDevice, 'VirtualParameters.RXPower');
    if ($rxPowerFromDevice !== null) {
        $onuSignal = $rxPowerFromDevice;
    } elseif ($onuData && isset($onuData['rx_power'])) {
        $onuSignal = is_array($onuData['rx_power']) ? ($onuData['rx_power']['_value'] ?? 'N/A') : $onuData['rx_power'];
    }
    if (is_array($onuSignal)) {
        $onuSignal = $onuSignal['_value'] ?? $onuSignal['value'] ?? 'N/A';
    }
    
    // Get connected devices (SSID 1)
    $rawDevices = genieacsGetValue($customerDevice, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations') ?? ($onuData['total_associations'] ?? '-');
    if (is_array($rawDevices)) {
        $rawDevices = $rawDevices['_value'] ?? $rawDevices['value'] ?? '-';
    }
    $onuDevices = is_numeric($rawDevices) ? (int)$rawDevices : '-';
}

$pageTitle = 'Dashboard Pelanggan';

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <!-- Package Info -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-box"></i> Paket Internet
        </h3>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 5px;">
                    <?php echo htmlspecialchars($package['name'] ?? 'Tanpa Paket'); ?>
                </h2>
                <p style="color: var(--neon-green); font-size: 1.2rem; font-weight: 600;">
                    <?php echo formatCurrency($package['price'] ?? 0); ?>
                </p>
            </div>
            <div>
                <?php if (isset($customer['status']) && $customer['status'] === 'active'): ?>
                    <span class="badge badge-success" style="font-size: 1rem;">Aktif</span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size: 1rem;">Isolir</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment Status -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-file-invoice-dollar"></i> Status Pembayaran
        </h3>
        
        <?php if ($currentInvoice): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <h4 style="margin-bottom: 5px;">Tagihan Bulan Ini</h4>
                    <p style="color: var(--text-secondary);">
                        Jatuh Tempo: <?php echo formatDate($currentInvoice['due_date']); ?>
                    </p>
                </div>
                <div style="text-align: right;">
                    <p style="font-size: 1.5rem; font-weight: 700; color: var(--neon-green);">
                        <?php echo formatCurrency($currentInvoice['amount']); ?>
                    </p>
                    <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                        <span class="badge badge-warning">Belum Bayar</span>
                    <?php else: ?>
                        <span class="badge badge-success">Lunas</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($currentInvoice['status'] === 'unpaid'): ?>
                <button class="btn btn-primary" onclick="payInvoice(<?php echo $currentInvoice['id']; ?>)">
                    <i class="fas fa-credit-card"></i> Bayar Sekarang
                </button>
            <?php endif; ?>
        <?php else: ?>
            <p style="color: var(--text-muted);">Belum ada tagihan bulan ini.</p>
        <?php endif; ?>
        
        <div style="margin-top: 20px;">
            <h4 style="margin-bottom: 10px;">Riwayat Tagihan</h4>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th>Jumlah</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentInvoices)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 20px;" data-label="Data">
                                Belum ada riwayat tagihan
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentInvoices as $inv): ?>
                        <tr>
                            <td data-label="Periode"><?php echo date('F Y', strtotime($inv['created_at'])); ?></td>
                            <td data-label="Jumlah"><?php echo formatCurrency($inv['amount']); ?></td>
                            <td data-label="Jatuh Tempo"><?php echo formatDate($inv['due_date']); ?></td>
                            <td data-label="Status">
                                <?php if ($inv['status'] === 'paid'): ?>
                                    <span class="badge badge-success">Lunas</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Belum Bayar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ONU Info -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-satellite-dish"></i> Informasi ONU
        </h3>
        
        <?php if ($onuData): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <div>
                    <p style="color: var(--text-secondary); margin-bottom: 5px;">Username PPPoE</p>
                    <p><code><?php echo htmlspecialchars($customer['pppoe_username'] ?? '-'); ?></code></p>
                </div>
                <div>
                    <p style="color: var(--text-secondary); margin-bottom: 5px;">Perangkat Terhubung</p>
                    <p><?php echo htmlspecialchars($onuDevices); ?> Device</p>
                </div>
                <div>
                    <p style="color: var(--text-secondary); margin-bottom: 5px;">Status</p>
                    <p>
                        <?php if ($onuOnline): ?>
                            <span class="badge badge-success">Online</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Offline</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <p style="color: var(--text-secondary); margin-bottom: 5px;">Signal</p>
                    <p><?php echo $onuSignal; ?> dBm</p>
                </div>
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted);">
                <i class="fas fa-info-circle"></i> Data ONU tidak tersedia. Pastikan ONU terhubung ke GenieACS.
            </p>
        <?php endif; ?>
    </div>

    <!-- WiFi Settings -->
    <?php 
    $isCustomerDeviceOnline = $customerDevice && $onuOnline;
    ?>
    
    <?php if ($isCustomerDeviceOnline && $customerDevice): ?>
    <div class="card" id="wifi-settings">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-wifi"></i> Pengaturan WiFi
        </h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
            <div style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div class="form-group">
                        <label class="form-label">SSID WiFi Saat Ini</label>
                        <input type="text" class="form-control" 
                               value="<?php echo htmlspecialchars($onuData['ssid'] ?? 'N/A'); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SSID WiFi Baru</label>
                        <input type="text" id="wifiSsid" class="form-control" 
                               placeholder="Masukkan SSID baru">
                    </div>
                </div>
                <button class="btn btn-primary" onclick="updateSsid()" style="align-self: flex-start; margin-top: auto;">
                    <i class="fas fa-save"></i> Simpan SSID
                </button>
            </div>
            
            <div style="display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div class="form-group">
                        <label class="form-label">Password WiFi Saat Ini</label>
                        <input type="password" class="form-control" 
                               value="<?php echo htmlspecialchars($onuData['wifi_password'] ?? ''); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password WiFi Baru</label>
                        <input type="password" id="wifiPassword" class="form-control" 
                               placeholder="Masukkan password baru">
                    </div>
                </div>
                <button class="btn btn-primary" onclick="updatePassword()" style="align-self: flex-start; margin-top: auto;">
                    <i class="fas fa-save"></i> Simpan Password
                </button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <p style="color: var(--text-muted);">
            <i class="fas fa-exclamation-triangle"></i> 
            <?php if ($customerDevice): ?>
                Pengaturan WiFi hanya tersedia saat perangkat ONU online.
            <?php else: ?>
                Perangkat ONU tidak ditemukan untuk username PPPoE: <?php echo htmlspecialchars($customer['pppoe_username']); ?>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Account Settings -->
    <div class="card">
        <h3 style="margin-bottom: 15px; color: var(--neon-cyan);">
            <i class="fas fa-user-cog"></i> Pengaturan Akun Portal
        </h3>
        
        <div class="form-group">
            <label class="form-label">Password Baru</label>
            <input type="password" id="newPassword" class="form-control" placeholder="Minimal 6 karakter">
        </div>
        
        <button class="btn btn-warning" onclick="changePortalPassword()">
            <i class="fas fa-key"></i> Ubah Password
        </button>
    </div>
    
    <!-- Trouble Tickets -->
    <div class="card" id="lapor-gangguan">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="color: var(--neon-cyan);">
                <i class="fas fa-ticket-alt"></i> Laporan Gangguan
            </h3>
            <button class="btn btn-primary" onclick="openTicketModal()">
                <i class="fas fa-plus"></i> Buat Laporan
            </button>
        </div>
        
        <div id="ticketsContainer">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Deskripsi</th>
                        <th>Status</th>
                        <th>Prioritas</th>
                        <th>Tanggal</th>
                    </tr>
                </thead>
                <tbody id="ticketsBody">
                    <!-- Tickets will be loaded here -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Ticket Modal -->
<div id="ticketModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 2000; align-items: center; justify-content: center;">
    <div class="card" style="width: 500px; max-width: 90%; margin: 2rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: var(--neon-cyan);">
                <i class="fas fa-ticket-alt"></i> Buat Laporan Gangguan
            </h3>
            <button onclick="closeTicketModal()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 1.25rem;">&times;</button>
        </div>
        
        <div class="form-group">
            <label class="form-label">Prioritas</label>
            <select id="ticketPriority" class="form-control">
                <option value="low">Rendah</option>
                <option value="medium" selected>Sedang</option>
                <option value="high">Tinggi</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">Deskripsi Gangguan</label>
            <textarea id="ticketDescription" class="form-control" rows="4" placeholder="Jelaskan gangguan yang Anda alami..."></textarea>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" onclick="submitTicket()" style="flex: 1;">
                <i class="fas fa-paper-plane"></i> Kirim Laporan
            </button>
            <button class="btn btn-secondary" onclick="closeTicketModal()" style="flex: 1;">
                Batal
            </button>
        </div>
    </div>
</div>

<!-- Alert Modal -->
<div id="alertModal" style="display: none; position: fixed; top: 20px; right: 20px; z-index: 3000;">
    <div class="alert" id="alertContent"></div>
</div>

<script>
const customerPppoeUsername = '<?php echo $customer['pppoe_username']; ?>';

function showAlert(message, type = 'success') {
    const modal = document.getElementById('alertModal');
    const content = document.getElementById('alertContent');
    
    content.className = 'alert alert-' + type;
    content.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
    
    modal.style.display = 'block';
    
    setTimeout(() => {
        modal.style.display = 'none';
    }, 5000);
}

function updateSsid() {
    const ssid = document.getElementById('wifiSsid').value;
    
    if (ssid.length < 3) {
        showAlert('SSID minimal 3 karakter', 'error');
        return;
    }
    
    fetch('<?php echo APP_URL; ?>/api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            pppoe_username: customerPppoeUsername,
            ssid: ssid 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('SSID WiFi berhasil diperbarui');
        } else {
            showAlert('Gagal memperbarui SSID: ' + data.message, 'error');
        }
    });
}

function updatePassword() {
    const password = document.getElementById('wifiPassword').value;
    
    if (password.length < 8) {
        showAlert('Password minimal 8 karakter', 'error');
        return;
    }
    
    fetch('<?php echo APP_URL; ?>/api/onu_wifi.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            pppoe_username: customerPppoeUsername,
            password: password 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('Password WiFi berhasil diperbarui');
            document.getElementById('wifiPassword').value = '';
        } else {
            showAlert('Gagal memperbarui password: ' + data.message, 'error');
        }
    });
}

function changePortalPassword() {
    const newPassword = document.getElementById('newPassword').value;
    
    if (newPassword.length < 6) {
        showAlert('Password minimal 6 karakter', 'error');
        return;
    }
    
    if (!confirm('Yakin ingin mengubah password portal?')) {
        return;
    }
    
    fetch('<?php echo APP_URL; ?>/api/portal_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: newPassword })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('Password portal berhasil diubah');
            document.getElementById('newPassword').value = '';
        } else {
            showAlert('Gagal mengubah password: ' + data.message, 'error');
        }
    });
}

function payInvoice(invoiceId) {
    window.location.href = 'payment.php?invoice_id=' + invoiceId;
}

// Trouble Ticket Functions
function openTicketModal() {
    document.getElementById('ticketModal').style.display = 'flex';
    document.getElementById('ticketDescription').focus();
}

function closeTicketModal() {
    document.getElementById('ticketModal').style.display = 'none';
    document.getElementById('ticketDescription').value = '';
    document.getElementById('ticketPriority').value = 'medium';
}

function submitTicket() {
    const description = document.getElementById('ticketDescription').value.trim();
    const priority = document.getElementById('ticketPriority').value;
    
    if (!description) {
        showAlert('Mohon masukkan deskripsi gangguan', 'error');
        return;
    }
    
    // Show loading state
    const submitBtn = document.querySelector('#ticketModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
    submitBtn.disabled = true;
    
    fetch('<?php echo APP_URL; ?>/api/customer_trouble.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            description: description,
            priority: priority
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('Laporan gangguan berhasil dikirim');
            closeTicketModal();
            loadTickets(); // Refresh the tickets list
        } else {
            showAlert('Gagal mengirim laporan: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('Terjadi kesalahan saat mengirim laporan', 'error');
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

function loadTickets() {
    fetch('<?php echo APP_URL; ?>/api/customer_trouble.php')
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('ticketsBody');
            tbody.innerHTML = '';
            
            if (data.tickets && data.tickets.length > 0) {
                data.tickets.forEach(ticket => {
                    const row = document.createElement('tr');
                    
                    // Format status class
                    let statusClass = 'badge-warning';
                    let statusText = 'Pending';
                    if (ticket.status === 'resolved') {
                        statusClass = 'badge-success';
                        statusText = 'Selesai';
                    } else if (ticket.status === 'in_progress') {
                        statusClass = 'badge-info';
                        statusText = 'Diproses';
                    }
                    
                    // Format priority class
                    let priorityClass = 'badge-info';
                    if (ticket.priority === 'high') priorityClass = 'badge-danger';
                    if (ticket.priority === 'medium') priorityClass = 'badge-warning';
                    
                    row.innerHTML = `
                        <td data-label="No">${ticket.id}</td>
                        <td data-label="Deskripsi">${ticket.description.substring(0, 50)}${ticket.description.length > 50 ? '...' : ''}</td>
                        <td data-label="Status"><span class="badge ${statusClass}">${statusText}</span></td>
                        <td data-label="Prioritas"><span class="badge ${priorityClass}">${ticket.priority.charAt(0).toUpperCase() + ticket.priority.slice(1)}</span></td>
                        <td data-label="Tanggal">${formatDate(ticket.created_at)}</td>
                    `;
                    
                    tbody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = `<td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;" data-label="Data">Belum ada laporan gangguan</td>`;
                tbody.appendChild(row);
            }
        })
        .catch(error => {
            console.error('Error loading tickets:', error);
            const tbody = document.getElementById('ticketsBody');
            tbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;" data-label="Data">Gagal memuat data laporan</td></tr>`;
        });
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Load tickets when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadTickets();
});
</script>

<style>
.card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-card);
}

.form-group { margin-bottom: 20px; }
.form-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-secondary); }
.form-control {
    width: 100%;
    padding: 12px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 1rem;
}
.form-control:focus { outline: none; border-color: var(--neon-cyan); }

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-primary { background: var(--gradient-primary); color: #fff; }
.btn-primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-neon); }
.btn-secondary { background: transparent; border: 1px solid var(--border-color); color: var(--text-primary); }
.btn-warning { background: var(--gradient-warning); color: #fff; }

.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table thead { background: var(--bg-secondary); }
.data-table th, .data-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.data-table th {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
    text-transform: uppercase;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}
.badge-success { background: rgba(0, 255, 136, 0.2); color: var(--neon-green); border: 1px solid var(--neon-green); }
.badge-warning { background: rgba(255, 107, 53, 0.2); color: var(--neon-orange); border: 1px solid var(--neon-orange); }
.badge-danger { background: rgba(255, 71, 87, 0.2); color: var(--neon-red); border: 1px solid var(--neon-red); }

.alert {
    padding: 15px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: rgba(0, 255, 136, 0.1); border: 1px solid var(--neon-green); color: var(--neon-green); }
.alert-error { background: rgba(255, 71, 87, 0.1); border: 1px solid var(--neon-red); color: var(--neon-red); }
</style>

</div> <!-- Close the wrapper div -->

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
