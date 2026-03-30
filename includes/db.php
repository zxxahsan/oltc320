<?php
/**
 * Database Connection
 */

require_once __DIR__ . '/config.php';

function getDB() {
    static $pdo = null;
    
    // Create logs directory if not exists
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
                // Set global SQL mode to non-strict for compatibility
                $pdo->exec("SET sql_mode = ''");
                
                // Auto-Migration for new Voucher Sales Columns
                try {
                    $pdo->exec("ALTER TABLE sales_profile_prices ADD COLUMN validity VARCHAR(50) DEFAULT NULL AFTER selling_price");
                } catch (\Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE sales_profile_prices ADD COLUMN timelimit VARCHAR(50) DEFAULT NULL AFTER validity");
                } catch (\Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN validity VARCHAR(50) DEFAULT NULL AFTER profile");
                } catch (\Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN timelimit VARCHAR(50) DEFAULT NULL AFTER validity");
                } catch (\Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN status ENUM('active','inactive') DEFAULT 'inactive', ADD COLUMN used_at DATETIME NULL");
                } catch (\Exception $e) {}
                try {
                    $pdo->exec("ALTER TABLE hotspot_sales ADD COLUMN router_id INT DEFAULT 0");
                } catch (\Exception $e) {}
                
                try {
                    $pdo->exec("ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'paid', 'cancelled', 'pending') DEFAULT 'unpaid'");
                    $pdo->exec("ALTER TABLE invoices ADD COLUMN payment_proof VARCHAR(255) DEFAULT NULL");
                } catch (\Exception $e) {}

                // Sales Topups Table
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_topups (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        sales_user_id INT NOT NULL,
                        amount DECIMAL(15,2) NOT NULL,
                        payment_method VARCHAR(20) NOT NULL,
                        payment_reference VARCHAR(100) DEFAULT NULL,
                        status ENUM('pending', 'paid', 'cancelled') DEFAULT 'pending',
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                } catch (\Exception $e) {}

                // Default Payment Settings
                $defaultSettings = [
                    'ENABLE_TRIPAY_CUSTOMER' => '1',
                    'ENABLE_MANUAL_CUSTOMER' => '1',
                    'ENABLE_TRIPAY_SALES' => '1',
                    'ENABLE_MANUAL_SALES' => '1',
                    'MANUAL_PAYMENT_INFO' => 'Bank BCA: 1234567890 a/n Admin\nBank Mandiri: 0987654321 a/n Admin'
                ];
                foreach ($defaultSettings as $key => $val) {
                    try {
                        $check = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
                        $check->execute([$key]);
                        if (!$check->fetch()) {
                            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
                        }
                    } catch (\Exception $e) {}
                }
        } catch (PDOException $e) {
            $logFile = __DIR__ . '/../logs/db_error.log';
            $message = "[" . date('Y-m-d H:i:s') . "] Connection Error: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
            die("Maaf, terjadi kesalahan koneksi database. Silakan coba beberapa saat lagi.");
        }
    }
    
    return $pdo;
}

function query($sql, $params = []) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        logError("Database Query Error: " . $e->getMessage());
        return false;
    }
}

function fetchAll($sql, $params = []) {
    $stmt = query($sql, $params);
    return $stmt ? $stmt->fetchAll() : [];
}

function fetchOne($sql, $params = []) {
    $stmt = query($sql, $params);
    return $stmt ? $stmt->fetch() : null;
}

function insert($table, $data) {
    $pdo = getDB();
    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');
    
    $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        logError("Insert Error: " . $e->getMessage());
        return false;
    }
}

function update($table, $data, $where, $whereParams = []) {
    $pdo = getDB();
    $set = [];
    foreach (array_keys($data) as $column) {
        $set[] = "{$column} = ?";
    }
    
    $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}";
    $params = array_merge(array_values($data), $whereParams);
    
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        logError("Update Error: " . $e->getMessage());
        return false;
    }
}

function delete($table, $where, $params = []) {
    $sql = "DELETE FROM {$table} WHERE {$where}";
    $stmt = query($sql, $params);
    return $stmt ? $stmt->rowCount() : false;
}

function tableExists($table) {
    try {
        $pdo = getDB();
        $quoted = $pdo->quote($table);
        $stmt = $pdo->query("SHOW TABLES LIKE {$quoted}");
        return $stmt ? $stmt->rowCount() > 0 : false;
    } catch (PDOException $e) {
        logError("Database Query Error: " . $e->getMessage());
        return false;
    }
}

function beginTransaction() {
    getDB()->beginTransaction();
}

function commit() {
    getDB()->commit();
}

function rollback() {
    getDB()->rollBack();
}
