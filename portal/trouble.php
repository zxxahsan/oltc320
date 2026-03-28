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

    <!-- Trouble Header -->
    <div class="card" style="margin-bottom: 25px; border-left: 5px solid var(--neon-cyan);">
        <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
            <div style="width: 60px; height: 60px; background: rgba(0, 245, 255, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: var(--neon-cyan);">
                <i class="fas fa-headset"></i>
            </div>
            <div style="flex: 1; min-width: 250px;">
                <h3 style="margin-bottom: 5px; color: var(--text-primary);">Punya Kendala Internet?</h3>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0;">Sampaikan laporan gangguan Anda di sini. Teknisi kami akan segera menindaklanjuti keluhan Anda dalam waktu singkat.</p>
            </div>
            <button class="btn btn-primary" onclick="openTicketModal()" style="padding: 12px 25px;">
                <i class="fas fa-plus"></i> Buat Laporan Baru
            </button>
        </div>
    </div>

    <!-- Trouble Tickets List -->
    <div class="card">
        <h3 style="margin-bottom: 20px; color: var(--text-primary);">
            <i class="fas fa-history"></i> Riwayat Laporan Anda
        </h3>
        
        <div id="ticketsContainer">
            <div id="ticketsBody" class="ticket-list">
                <!-- Tickets will be loaded here via JS -->
                <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px;"></i>
                    <p>Memuat data laporan...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ticket-list { display: flex; flex-direction: column; gap: 12px; }
.ticket-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 15px;
    transition: all 0.2s;
    box-shadow: var(--shadow-card);
}
.ticket-card:hover { transform: translateY(-2px); border-color: var(--neon-cyan); }
.ticket-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.ticket-id { font-size: 0.75rem; color: var(--text-muted); font-weight: 700; margin-bottom: 3px; }
.ticket-desc { color: var(--text-primary); font-weight: 500; font-size: 0.95rem; line-height: 1.4; }
.ticket-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 12px; border-top: 1px solid var(--border-color); }
.ticket-date { font-size: 0.8rem; color: var(--text-muted); }
.badge-pill { padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
</style>

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
                    // Format status
                    let statusClass = 'badge-warning';
                    let statusText = 'Pending';
                    if (ticket.status === 'resolved') {
                        statusClass = 'badge-success';
                        statusText = 'Selesai';
                    } else if (ticket.status === 'in_progress') {
                        statusClass = 'badge-info';
                        statusText = 'Diproses';
                    }
                    
                    // Format priority
                    let priorityLabel = 'NORMAL';
                    let priorityColor = 'var(--text-muted)';
                    if (ticket.priority === 'high') { priorityLabel = 'TINGGI'; priorityColor = 'var(--neon-red)'; }
                    if (ticket.priority === 'low') { priorityLabel = 'RENDAH'; priorityColor = 'var(--neon-green)'; }
                    
                    const card = document.createElement('div');
                    card.className = 'ticket-card';
                    card.innerHTML = `
                        <div class="ticket-card-header">
                            <div>
                                <div class="ticket-id">TICKET #${ticket.id}</div>
                                <div class="ticket-desc">${ticket.description}</div>
                            </div>
                            <span class="badge ${statusClass}">${statusText}</span>
                        </div>
                        <div class="ticket-footer">
                            <div class="ticket-date">
                                <i class="far fa-calendar-alt" style="margin-right: 5px;"></i> ${formatDate(ticket.created_at)}
                            </div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: ${priorityColor};">
                                <i class="fas fa-exclamation-circle" style="margin-right: 4px;"></i> PRIORITY: ${priorityLabel}
                            </div>
                        </div>
                    `;
                    
                    tbody.appendChild(card);
                });
            } else {
                tbody.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                        <i class="fas fa-check-circle" style="font-size: 3rem; margin-bottom: 20px; opacity: 0.2;"></i>
                        <p>Hebat! Anda tidak memiliki laporan gangguan aktif saat ini.</p>
                    </div>
                `;
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
