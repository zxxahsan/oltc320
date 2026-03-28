#!/usr/bin/php
<?php
/**
 * Cron Job Scheduler
 * Run this script every minute via server cron
 * Usage: * * * * * /usr/bin/php /path/to/gembok-simple/cron/scheduler.php
 */

// Load dependencies
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// CLI Check - Only run if called directly from CLI
if (php_sapi_name() === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    runScheduler();
} else if (php_sapi_name() !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    die("This script can only be run from CLI or authorized web runner");
}

/**
 * Main function to run the scheduler
 */
function runScheduler() {
    echo "[" . date('Y-m-d H:i:s') . "] Cron Scheduler started\n";

    try {
        $pdo = getDB();

        // Get all active schedules
        $schedules = fetchAll("
            SELECT * FROM cron_schedules 
            WHERE is_active = 1 
            AND (next_run IS NULL OR next_run <= NOW())
            ORDER BY next_run ASC
        ");

        if (empty($schedules)) {
            echo "No active schedules to run.\n";
            return;
        }

        echo "Found " . count($schedules) . " schedule(s) to run.\n";

        foreach ($schedules as $schedule) {
            echo "\n--- Running schedule: {$schedule['name']} ---\n";

            $startTime = microtime(true);
            $status = 'started';

            try {
                switch ($schedule['task_type']) {
                    case 'auto_isolir':
                        runAutoIsolir($pdo);
                        break;

                    case 'auto_invoice':
                        runAutoInvoice($pdo);
                        break;

                    case 'backup_db':
                        runBackupDb();
                        break;

                    case 'send_reminders':
                        sendReminders($pdo);
                        break;

                    case 'custom_script':
                        runCustomScript($pdo, $schedule);
                        break;

                    default:
                        echo "Unknown task type: {$schedule['task_type']}\n";
                        $status = 'failed';
                }

                $status = 'success';

            } catch (Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
                $status = 'failed';
            }

            $executionTime = round(microtime(true) - $startTime, 2);

            // Update schedule
            update('cron_schedules', [
                'last_run' => date('Y-m-d H:i:s'),
                'last_status' => $status,
                'next_run' => calculateNextRun($schedule)
            ], 'id = ?', [$schedule['id']]);

            // Log execution
            $pdo->prepare("INSERT INTO cron_logs (schedule_id, status, execution_time, created_at) VALUES (?, ?, ?, NOW())")
                ->execute([$schedule['id'], $status, $executionTime]);

            echo "Status: {$status}\n";
            echo "Execution time: {$executionTime}s\n";
        }

        echo "\n[" . date('Y-m-d H:i:s') . "] Cron Scheduler completed\n";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return;
    }
}

/**
 * Calculate next run time based on schedule
 */
function calculateNextRun($schedule)
{
    $scheduleTime = explode(':', $schedule['schedule_time']);
    $hour = (int) $scheduleTime[0];
    $minute = (int) $scheduleTime[1];

    $scheduleDays = $schedule['schedule_days'];

    // Calculate next run date
    $nextRun = date('Y-m-d') . ' ' . sprintf('%02d:%02d:00', $hour, $minute);

    // If today's time has passed, move to next valid day
    if (strtotime($nextRun) < time()) {
        $nextRun = date('Y-m-d', strtotime('+1 day')) . ' ' . sprintf('%02d:%02d:00', $hour, $minute);

        // Find the next valid day
        $daysMap = [
            'daily' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'weekly' => [$scheduleDays],
            'monthly' => null
        ];

        if ($scheduleDays === 'daily') {
            // Already handled above
        } elseif (in_array($scheduleDays, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])) {
            // Specific day of week
            $dayNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            $targetDay = array_search($scheduleDays, $dayNames);

            while (date('w', strtotime($nextRun)) != $targetDay) {
                $nextRun = date('Y-m-d', strtotime('+1 day', strtotime($nextRun))) . ' ' . sprintf('%02d:%02d:00', $hour, $minute);
            }
        }
    }

    return date('Y-m-d H:i:s', strtotime($nextRun));
}

/**
 * Run auto-isolir task
 */
function runAutoIsolir($pdo)
{
    echo "Running auto-isolir...\n";

    // Get customers with unpaid invoices that are overdue
    $overdueInvoices = fetchAll("
        SELECT c.id, c.name, c.phone, c.pppoe_username, c.package_id, i.invoice_number, i.amount
        FROM customers c
        INNER JOIN invoices i ON c.id = i.customer_id
        WHERE i.status = 'unpaid'
        AND i.due_date < CURDATE()
        AND c.status = 'active'
    ");

    echo "Found " . count($overdueInvoices) . " overdue invoices\n";

    foreach ($overdueInvoices as $invoice) {
        echo "Isolating customer: {$invoice['name']} (Invoice: {$invoice['invoice_number']})\n";

        // Isolate customer
        if (isolateCustomer($invoice['id'])) {
            echo "  ✓ Customer isolated\n";
            
            // Kick the user so they reconnect to the isolated profile immediately
            if (!empty($invoice['pppoe_username'])) {
                mikrotikRemoveActivePppoe($invoice['pppoe_username']);
                echo "  ✓ Dropped active PPPoE connection\n";
            }

            // Send WhatsApp notification
            $message = "Halo {$invoice['name']},\n\nPembayaran internet Anda sudah melewati tanggal jatuh tempo.\n\nTagihan: " . formatCurrency($invoice['amount']) . "\nInvoice: {$invoice['invoice_number']}\n\nMohon segera lakukan pembayaran untuk mengaktifkan kembali koneksi internet Anda.\n\nTerima kasih.";
            sendWhatsApp($invoice['phone'], $message);

        } else {
            echo "  ✗ Failed to isolate customer\n";
        }
    }
}

/**
 * Run auto invoice generation (for 1st of each month)
 */
function runAutoInvoice($pdo)
{
    echo "Running auto invoice generation...\n";

    // Only run on the 1st of the month
    if (date('j') != '1') {
        echo "  Skipping - not the 1st of the month\n";
        return;
    }

    $currentMonth = date('Y-m');
    $generatedCount = 0;

    // Get all active customers
    $customers = fetchAll("SELECT * FROM customers WHERE status = 'active'");

    echo "Found " . count($customers) . " active customers\n";

    foreach ($customers as $customer) {
        // Check if invoice already exists for this month
        $existingInvoice = fetchOne("
            SELECT id FROM invoices 
            WHERE customer_id = ? 
            AND DATE_FORMAT(created_at, '%Y-%m') = ?",
            [$customer['id'], $currentMonth]
        );

        if (!$existingInvoice) {
            $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);

            if ($package) {
                $dueDate = getCustomerDueDate($customer, $currentMonth . '-01');
                $invoiceData = [
                    'invoice_number' => generateInvoiceNumber(),
                    'customer_id' => $customer['id'],
                    'amount' => $package['price'],
                    'status' => 'unpaid',
                    'due_date' => $dueDate,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                insert('invoices', $invoiceData);
                $generatedCount++;
                echo "  ✓ Generated invoice for: {$customer['name']}\n";
            }
        }
    }

    echo "Generated {$generatedCount} invoices for " . date('F Y') . "\n";

    // Log activity
    logActivity('AUTO_INVOICE', "Auto-generated {$generatedCount} invoices for " . date('F Y'));
}

/**
 * Run database backup
 */
function runBackupDb()
{
    echo "Running database backup...\n";

    $backupDir = __DIR__ . '/../backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    $backupFile = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';

    // Get database config
    $dbHost = DB_HOST;
    $dbName = DB_NAME;
    $dbUser = DB_USER;
    $dbPass = DB_PASS;

    // Create backup using mysqldump
    $command = sprintf(
        "mysqldump -h %s -u %s -p%s %s > %s",
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName),
        escapeshellarg($backupFile)
    );

    exec($command, $output, $returnCode);

    if ($returnCode === 0) {
        $fileSize = filesize($backupFile);
        echo "  ✓ Backup created: {$backupFile} (" . round($fileSize / 1024 / 1024, 2) . " MB)\n";

        // Delete backups older than 7 days
        $files = glob($backupDir . 'backup_*.sql');
        foreach ($files as $file) {
            if (filemtime($file) < strtotime('-7 days')) {
                unlink($file);
                echo "  ✓ Deleted old backup: " . basename($file) . "\n";
            }
        }
    } else {
        echo "  ✗ Backup failed\n";
    }
}

/**
 * Send payment reminders
 */
function sendReminders($pdo)
{
    echo "Sending payment reminders...\n";

    // Get customers with unpaid invoices due in 3 days
    $upcomingInvoices = fetchAll("
        SELECT c.id, c.name, c.phone, c.pppoe_username, i.invoice_number, i.amount, i.due_date
        FROM customers c
        INNER JOIN invoices i ON c.id = i.customer_id
        WHERE i.status = 'unpaid'
        AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        AND c.status = 'active'
    ");

    echo "Found " . count($upcomingInvoices) . " upcoming invoice reminders\n";

    foreach ($upcomingInvoices as $invoice) {
        $daysUntilDue = (strtotime($invoice['due_date']) - time()) / 86400;

        $message = "Halo {$invoice['name']},\n\n";
        $message .= "Pengingat: Tagihan internet Anda akan jatuh tempo dalam " . ceil($daysUntilDue) . " hari.\n\n";
        $message .= "Tagihan: " . formatCurrency($invoice['amount']) . "\n";
        $message .= "Invoice: {$invoice['invoice_number']}\n";
        $message .= "Jatuh Tempo: " . formatDate($invoice['due_date']) . "\n\n";
        $message .= "Mohon lakukan pembayaran sebelum jatuh tempo untuk menghindari isolir.\n\n";
        $message .= "Terima kasih.";

        echo "  Sending reminder to: {$invoice['name']} ({$invoice['phone']})\n";
        sendWhatsApp($invoice['phone'], $message);
    }
}

/**
 * Run custom script
 */
function runCustomScript($pdo, $schedule)
{
    echo "Running custom script...\n";
    // Placeholder for custom scripts
    echo "  Custom script execution not implemented yet.\n";
}

echo "\n";
