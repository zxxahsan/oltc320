<?php
require_once 'includes/db.php';

$pdo = getDB();

$sql = "
CREATE TABLE IF NOT EXISTS olts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    host VARCHAR(100) NOT NULL,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(100) NOT NULL,
    telnet_port INT DEFAULT 23,
    type VARCHAR(50) DEFAULT 'ZTE C320',
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS olt_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    olt_id INT NOT NULL,
    profile_type ENUM('vlan', 'tcont') NOT NULL,
    profile_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (olt_id, profile_type, profile_name),
    FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS olt_provisioning_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    olt_id INT NOT NULL,
    customer_id INT DEFAULT NULL,
    onu_sn VARCHAR(100) NOT NULL,
    onu_type VARCHAR(50) DEFAULT 'ALL',
    gpon_port VARCHAR(50) NOT NULL,
    onu_index INT NOT NULL,
    onu_name VARCHAR(100),
    provisioning_mode ENUM('standard', 'omci') DEFAULT 'standard',
    vlan_id INT,
    pppoe_username VARCHAR(100),
    status ENUM('success', 'failed') DEFAULT 'success',
    output TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (olt_id) REFERENCES olts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);
    echo "SUCCESS: OLT tables created successfully.";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
