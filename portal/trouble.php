<?php
/**
 * Customer Trouble Tickets Page
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
requireCustomerLogin();

$customer = getCurrentCustomer();
$pageTitle = 'Laporan Gangguan';

ob_start();
?>

<div style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <!-- Trouble Tickets -->
    <div class="card" id="lapor-gangguan">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="color: var(--neon-cyan);">
                <i class="fas fa-ticket-alt"></i> Daftar Laporan Gangguan
            </h3>
            <button class="btn btn-primary" onclick="openTicketModal()">
                <i class="fas fa-plus"></i> Buat Laporan Baru
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
                    <!-- Tickets will be loaded here via JS -->
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
.badge-info { background: rgba(0, 245, 255, 0.2); color: var(--neon-cyan); border: 1px solid var(--neon-cyan); }

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

<?php
$content = ob_get_clean();
require_once '../includes/customer_layout.php';
