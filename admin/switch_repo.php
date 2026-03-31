<?php
/**
 * CLI Tool: Switch Repository Source
 * Purpose: Toggle between Public (gembokcontainer) and Personal (bill) repositories.
 * Usage: php admin/switch_repo.php --mode=[bill|public]
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the CLI.\n");
}

// Parse arguments
$options = getopt("", ["mode:"]);
$mode = isset($options['mode']) ? strtolower($options['mode']) : '';

if (!in_array($mode, ['bill', 'public'])) {
    echo "Usage: php admin/switch_repo.php --mode=[bill|public]\n";
    exit(1);
}

// Configuration
$repos = [
    'bill' => [
        'git_url' => 'https://github.com/zxxahsan/bill.git',
        'update_url' => 'https://raw.githubusercontent.com/zxxahsan/bill/main/version.txt'
    ],
    'public' => [
        'git_url' => 'https://github.com/zxxahsan/gembokcontainer.git',
        'update_url' => 'https://raw.githubusercontent.com/zxxahsan/gembokcontainer/main/version.txt'
    ]
];

$target = $repos[$mode];

echo "[*] Switching to mode: " . strtoupper($mode) . "\n";

// 1. Update Git Remote 'origin'
echo "[*] Updating Git remote 'origin'...\n";
$cmd = "git remote set-url origin " . escapeshellarg($target['git_url']);
exec($cmd, $output, $returnVar);

if ($returnVar === 0) {
    echo "[+] Git remote updated successfully to: " . $target['git_url'] . "\n";
} else {
    echo "[!] Failed to update Git remote. Is this a Git repository?\n";
}

// 2. Update includes/config.php (if it exists or from sample)
$configFile = dirname(__DIR__) . '/includes/config.php';
$sampleFile = dirname(__DIR__) . '/includes/config.sample.php';

if (!file_exists($configFile)) {
    if (file_exists($sampleFile)) {
        echo "[*] config.php not found. Creating from config.sample.php...\n";
        copy($sampleFile, $configFile);
    } else {
        echo "[!] FATAL: No config.php or config.sample.php found in includes/.\n";
        exit(1);
    }
}

echo "[*] Updating GEMBOK_UPDATE_VERSION_URL in includes/config.php...\n";
$configContent = file_get_contents($configFile);

$pattern = "/define\('GEMBOK_UPDATE_VERSION_URL', '.*'\);/";
$replacement = "define('GEMBOK_UPDATE_VERSION_URL', '" . $target['update_url'] . "');";

if (preg_match($pattern, $configContent)) {
    $newConfigContent = preg_replace($pattern, $replacement, $configContent);
} else {
    // If it doesn't exist, append it (though it should be in the sample)
    $newConfigContent = $configContent . "\ndefine('GEMBOK_UPDATE_VERSION_URL', '" . $target['update_url'] . "');\n";
}

if (file_put_contents($configFile, $newConfigContent)) {
    echo "[+] Update URL updated to: " . $target['update_url'] . "\n";
} else {
    echo "[!] Failed to write to config.php.\n";
}

echo "[*] ALL DONE! You can now use the Update menu in your admin dashboard.\n";
