<?php
/**
 * Customer Trouble Tickets API
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

// Check if customer is logged in
if (!isCustomerLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$customer = getCurrentCustomer();
$customerId = $customer['id'];

// Get PDO connection
$pdo = getDB();

// Handle GET request (fetch tickets)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM trouble_tickets 
            WHERE customer_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$customerId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Sanitize output
        foreach ($tickets as &$ticket) {
            $ticket['description'] = htmlspecialchars($ticket['description']);
            $ticket['priority'] = htmlspecialchars($ticket['priority']);
            $ticket['status'] = htmlspecialchars($ticket['status']);
        }
        
        echo json_encode(['success' => true, 'tickets' => $tickets]);
    } catch (Exception $e) {
        http_response_code(500);
        logError("Trouble ticket fetch error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Handle POST request (create ticket)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $description = trim($input['description'] ?? '');
    $priority = trim($input['priority'] ?? 'medium');
    
    // Validate inputs
    if (empty($description)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Description is required']);
        exit;
    }
    
    // Sanitize input (strip tags to be safe)
    $description = strip_tags($description);
    
    if (!in_array($priority, ['low', 'medium', 'high'])) {
        $priority = 'medium';
    }
    
    try {
        // Insert the new ticket
        $stmt = $pdo->prepare("
            INSERT INTO trouble_tickets (customer_id, description, priority, status, created_at) 
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $result = $stmt->execute([$customerId, $description, $priority]);
        
        if ($result) {
            $ticketId = $pdo->lastInsertId();
            
            // Log activity
            logActivity('CREATE_TICKET', "Customer ID: {$customerId}, Ticket ID: {$ticketId}");
            
            // WA: Notify Customer
            require_once '../includes/whatsapp.php';
            if (!empty($customer['phone'])) {
                $msgCust = "Halo {$customer['name']},\n\nLaporan gangguan Anda telah kami terima:\n\nTicket ID: #{$ticketId}\nMasalah: " . substr($description, 0, 100) . "...\n\nTim kami akan segera menindaklanjuti. Terima kasih.";
                sendWhatsAppMessage($customer['phone'], $msgCust);
            }
            
            // WA: Broadcast to Technicians
            $gmapsLink = "Tidak ada kordinat map.";
            if (!empty($customer['latitude']) && !empty($customer['longitude'])) {
                $gmapsLink = "https://www.google.com/maps?q={$customer['latitude']},{$customer['longitude']}";
            }
            
            $msgTech = "🚨 *TUGAS GANGGUAN BARU (VIA PORTAL)*\n\n";
            $msgTech .= "Ticket: #{$ticketId}\n";
            $msgTech .= "Pelanggan: {$customer['name']}\n";
            $msgTech .= "Kontak (WA): {$customer['phone']}\n";
            $msgTech .= "Alamat: " . ($customer['address'] ?? '-') . "\n";
            $msgTech .= "Lokasi Map: {$gmapsLink}\n";
            $msgTech .= "Masalah: {$description}\n";
            $msgTech .= "Prioritas: " . strtoupper($priority) . "\n\n";
            $msgTech .= "Mohon segera dicek. Terima kasih.";
            
            $techs = $pdo->query("SELECT phone FROM technician_users WHERE status = 'active'")->fetchAll();
            foreach ($techs as $tech) {
                if (!empty($tech['phone'])) {
                    sendWhatsAppMessage($tech['phone'], $msgTech);
                }
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Ticket created successfully',
                'ticket_id' => $ticketId
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create ticket']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        logError("Trouble ticket create error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);