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

        // Self-heal: Ensure System Heartbeat exists
        $hasHeartbeat = fetchOne("SELECT id FROM cron_schedules WHERE task_type = 'system_ping'");
        if (!$hasHeartbeat) {
            $pdo->prepare("INSERT IGNORE INTO cron_schedules (name, task_type, schedule_days, schedule_time, is_active) VALUES (?, ?, ?, ?, ?)")
                ->execute(['System Heartbeat', 'system_ping', 'every_minute', '00:00', 1]);
        }
        // Self-heal: Force critical jobs to check continuously instead of daily
        $pdo->exec("UPDATE cron_schedules SET schedule_days = 'every_minute' WHERE task_type IN ('auto_isolir', 'auto_invoice')");
        // Self-heal: If any every_minute job is stuck more than 5 minutes in the future (due to old daily caching), pull it back to NOW
        $pdo->exec("UPDATE cron_schedules SET next_run = NULL WHERE schedule_days = 'every_minute' AND next_run > DATE_ADD(NOW(), INTERVAL 5 MINUTE)");

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

            // ATOMIC LOCK: Prevent concurrent execution duplicates (Double WA / Double Invoice bugs)
            $lockStmt = $pdo->prepare("UPDATE cron_schedules SET last_status = 'running' WHERE id = ? AND (last_status != 'running' OR last_run < DATE_SUB(NOW(), INTERVAL 2 HOUR))");
            $lockStmt->execute([$schedule['id']]);
            if ($lockStmt->rowCount() === 0) {
                echo "Schedule {$schedule['name']} is currently locked by another thread. Skipping to prevent duplicates...\n";
                continue;
            }

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

                    case 'system_ping':
                        runSystemPing($pdo);
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
    if ($schedule['schedule_days'] === 'every_minute') {
        return date('Y-m-d H:i:s', strtotime('+1 minute'));
    }

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
        SELECT c.id, c.name, c.phone, c.pppoe_username, c.package_id, i.invoice_number, i.amount, i.due_date
        FROM customers c
        INNER JOIN invoices i ON c.id = i.customer_id
        WHERE i.status = 'unpaid'
        AND i.due_date <= CURDATE()
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

            $customer = fetchOne("SELECT * FROM customers WHERE id = ?", [$invoice['customer_id']]);
            if ($customer && !empty($customer['phone'])) {
                require_once __DIR__ . '/../includes/whatsapp.php';
                $message = buildWhatsAppMessage('isolation_warning', getUniversalWaVariables($customer, $invoice));
                
                // Fallback just in case template system fails
                if (empty($message)) {
                    $paymentUrl = rtrim(APP_URL, '/') . "/portal/index.php";
                    $tripayUrl = "https://tripay.co.id/checkout?merchant_code=" . TRIPAY_MERCHANT_CODE . "&amount={$invoice['amount']}&merchant_ref={$invoice['invoice_number']}";
                    $message = "🔴 *KONEKSI TERPUTUS*\nMaaf {$invoice['name']}, internet Anda telah diisolir karena tagihan " . formatCurrency($invoice['amount']) . ".\nBayar via Portal: $paymentUrl \nBayar via Tripay: $tripayUrl";
                }
                
                sendWhatsApp($invoice['phone'], $message);
            }

        } else {
            echo "  ✗ Failed to isolate customer\n";
        }
    }
}

/**
 * Run auto invoice generation (Scheduled 7 days before due_date)
 */
function runAutoInvoice($pdo)
{
    echo "Running auto invoice generation...\n";

    $generatedCount = 0;
    $today = date('Y-m-d');
    $targetDueDate = date('Y-m-d', strtotime('+7 days')); // Invoices due in exactly 7 days from now

    // Get all active and isolated customers
    $customers = fetchAll("SELECT * FROM customers WHERE status IN ('active', 'isolated')");

    echo "Found " . count($customers) . " active/isolated customers\n";

    foreach ($customers as $customer) {
        $billingDay = (int)($customer['due_date'] ?? 1);
        if ($billingDay === 0) $billingDay = 1;

        // Calculate the due date for the *next* invoice based on the customer's billing day
        // This logic ensures we always look for the next upcoming invoice period.
        $currentMonth = date('n');
        $currentYear = date('Y');

        $potentialDueDateThisMonth = date('Y-m-d', mktime(0, 0, 0, $currentMonth, $billingDay, $currentYear));
        $potentialDueDateNextMonth = date('Y-m-d', mktime(0, 0, 0, $currentMonth + 1, $billingDay, $currentYear));

        $invoiceDueDate = '';
        if ($potentialDueDateThisMonth >= $today) {
            // If the billing day for this month is in the future or today, consider it.
            $invoiceDueDate = $potentialDueDateThisMonth;
        } else {
            // Otherwise, the invoice should be for the next month.
            $invoiceDueDate = $potentialDueDateNextMonth;
        }

        // Check if this calculated invoiceDueDate is exactly 7 days from today
        if ($invoiceDueDate === $targetDueDate) {
            $targetMonth = date('Y-m', strtotime($invoiceDueDate));
            
            // Check if invoice already exists for this exact target month and customer
            $existingInvoice = fetchOne("
                SELECT id FROM invoices 
                WHERE customer_id = ? 
                AND DATE_FORMAT(due_date, '%Y-%m') = ?",
                [$customer['id'], $targetMonth]
            );

            if (!$existingInvoice) {
                $package = fetchOne("SELECT * FROM packages WHERE id = ?", [$customer['package_id']]);

                if ($package) {
                    $invoiceData = [
                        'invoice_number' => generateInvoiceNumber(),
                        'customer_id' => $customer['id'],
                        'amount' => $package['price'],
                        'status' => 'unpaid',
                        'due_date' => $invoiceDueDate,
                        'created_at' => date('Y-m-d H:i:s')
                    ];

                    insert('invoices', $invoiceData);
                    $generatedCount++;
                    echo "  ✓ Generated invoice for: {$customer['name']} (Due: {$invoiceData['due_date']})\n";
                
                    // Dispatch via WhatsApp Gateway
                    if (!empty($customer['phone'])) {
                        require_once __DIR__ . '/../includes/whatsapp.php';
                        $message = buildWhatsAppMessage('invoice_created', getUniversalWaVariables($customer, $invoiceData));
                        if (!empty($message)) sendWhatsApp($customer['phone'], $message);
                    }
                }
            }
        }
    }

    echo "Generated {$generatedCount} new invoices.\n";

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
    
    // Fetch Configuration Settings for Days Offsets
    $settingsRaw = fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('wa_reminder_1_days', 'wa_reminder_2_days', 'wa_reminder_3_days')");
    $dayConfig = [
        'wa_reminder_1_days' => 7,
        'wa_reminder_2_days' => 3,
        'wa_reminder_3_days' => 1
    ];
    foreach($settingsRaw as $row) {
        if(is_numeric($row['setting_value'])) {
            $dayConfig[$row['setting_key']] = (int)$row['setting_value'];
        }
    }
    
    // Build the execution mapping matrix
    $configs = [
        [ 'days' => $dayConfig['wa_reminder_1_days'], 'template' => 'invoice_reminder_1' ],
        [ 'days' => $dayConfig['wa_reminder_2_days'], 'template' => 'invoice_reminder_2' ],
        [ 'days' => $dayConfig['wa_reminder_3_days'], 'template' => 'invoice_reminder_3' ]
    ];
    
    foreach ($configs as $cfg) {
        $daysParam = $cfg['days'];
        $templateKey = $cfg['template'];

        // Get customers with unpaid invoices EXACTLY matching the day offset
        $upcomingInvoices = fetchAll("
            SELECT c.id, c.name, c.phone, c.pppoe_username, i.invoice_number, i.amount, i.due_date
            FROM customers c
            INNER JOIN invoices i ON c.id = i.customer_id
            WHERE i.status = 'unpaid'
            AND i.due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
            AND c.status = 'active'
        ", [$daysParam]);

        echo "Found " . count($upcomingInvoices) . " upcoming invoice reminders for H-{$daysParam} (Template: {$templateKey})\n";

        foreach ($upcomingInvoices as $invoice) {
            require_once __DIR__ . '/../includes/whatsapp.php';
            // Use joined query mapping
            $message = buildWhatsAppMessage($templateKey, getUniversalWaVariables($invoice, $invoice));
            
            if (empty($message)) {
                $paymentUrl = rtrim(APP_URL, '/') . "/portal/index.php";
                $message = "⚠️ *PENGINGAT TAGIHAN*\nHalo {$invoice['name']}, Tagihan " . formatCurrency($invoice['amount']) . " akan memasukin batas akhir dalam $daysParam hari (" . formatDate($invoice['due_date']) . ").\nBayar disini: $paymentUrl \nAtau Tripay Langsung: $tripayUrl";
            }

            echo "  Sending reminder to: {$invoice['name']} ({$invoice['phone']})\n";
            sendWhatsApp($invoice['phone'], $message);
        }
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

/**
 * Run system heartbeat ping
 */
function runSystemPing($pdo)
{
    echo "  System Heartbeat OK\n";

    // Monthly Traffic Aggregator (Radius Simulation)
    echo "  Polling RouterOS PPPoE arrays for Delta Aggregation...\n";
    require_once __DIR__ . '/../includes/mikrotik_api.php';

    try {
        $pdo->exec("ALTER TABLE customers ADD COLUMN usage_last_rx BIGINT DEFAULT 0, ADD COLUMN usage_last_tx BIGINT DEFAULT 0");
    } catch (Exception $e) {}
    
    // Prevent 32-bit Integer overflow causing phantom traffic "resets" upon crossing 2.14GB demarcations
    try {
        $pdo->exec("ALTER TABLE customers MODIFY COLUMN usage_bytes_in BIGINT UNSIGNED DEFAULT 0, MODIFY COLUMN usage_bytes_out BIGINT UNSIGNED DEFAULT 0");
    } catch (Exception $e) {}

    // Auto-Reset cascades executing at Midnight on the 1st of every month automatically purging old vectors
    $pdo->exec("UPDATE customers SET usage_bytes_in=0, usage_bytes_out=0, usage_last_rx=0, usage_last_tx=0, usage_last_reset=CURDATE() WHERE DATE_FORMAT(usage_last_reset, '%Y-%m') != DATE_FORMAT(CURDATE(), '%Y-%m')");

    $routers = getAllRouters();
    foreach ($routers as $r) {
        $mk = getMikrotikConnection($r['id']);
        if ($mk) {
            mikrotikWrite($mk, '/ppp/active/print');
            mikrotikWrite($mk, '=.proplist=name,bytes-in,bytes-out');
            $activeList = mikrotikRead($mk);

            if (!empty($activeList) && !isset($activeList['!trap'])) {
                foreach ($activeList as $session) {
                    if (isset($session['name'], $session['bytes-in'], $session['bytes-out'])) {
                        $user = $session['name'];
                        $rx = (int)$session['bytes-in'];
                        $tx = (int)$session['bytes-out'];

                        $cust = fetchOne("SELECT id, usage_bytes_in, usage_bytes_out, usage_last_rx, usage_last_tx FROM customers WHERE pppoe_username = ?", [$user]);
                        if ($cust) {
                            $lastRx = (int)$cust['usage_last_rx'];
                            $lastTx = (int)$cust['usage_last_tx'];

                            // Diff Matrix ensuring disconnect metrics roll correctly natively bypassing negative crashes
                            if ($rx < $lastRx || $tx < $lastTx) {
                                // Router reconnected. Previous session effectively terminated.
                                // Move previous $lastRx/$lastTx into Historical Base securely!
                                $pdo->prepare("UPDATE customers SET usage_bytes_in = usage_bytes_in + ?, usage_bytes_out = usage_bytes_out + ?, usage_last_rx = ?, usage_last_tx = ? WHERE id = ?")
                                    ->execute([$lastRx, $lastTx, $rx, $tx, $cust['id']]);
                            } else {
                                // Session is still alive and linearly accumulating
                                $pdo->prepare("UPDATE customers SET usage_last_rx = ?, usage_last_tx = ? WHERE id = ?")
                                    ->execute([$rx, $tx, $cust['id']]);
                            }
                        }
                    }
                }
            }
        }
    }
}

echo "\n";
