<?php
// Get current page context
$current_path = $_SERVER['PHP_SELF'];
$path_parts = explode('/', str_replace('\\', '/', dirname($current_path)));
$current_dir = end($path_parts); // 'technician', 'tasks', 'map', 'devices'

// Determine relative path to technician root
$rel_path = '';
// List of subdirectories inside technician
$subdirs = ['tasks', 'map', 'devices', 'includes'];

if (in_array($current_dir, $subdirs)) {
    $rel_path = '../';
}

// Normalize active state check
$current_file = basename($current_path);
$is_home = ($current_file == 'dashboard.php');
// Active if in tasks folder OR filename contains 'task'
$is_tasks = ($current_dir == 'tasks' || strpos($current_file, 'task') !== false);
// Active if in map folder OR filename contains 'map'
$is_map = ($current_dir == 'map' || strpos($current_file, 'map') !== false);
// Active if in devices folder OR filename contains 'search' or 'manage'
$is_devices = ($current_dir == 'devices' || strpos($current_file, 'search') !== false || strpos($current_file, 'manage') !== false);
$is_profile = ($current_file == 'profile.php');
?>
<style>
    /* Bottom Navigation */
    .bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background: #161628; /* var(--bg-card) */
        display: flex;
        justify-content: space-around;
        padding: 12px 0;
        border-top: 1px solid rgba(255,255,255,0.05);
        box-shadow: 0 -5px 20px rgba(0,0,0,0.5);
        z-index: 9999;
    }
    
    .nav-item {
        color: #b0b0c0; /* var(--text-secondary) */
        text-decoration: none;
        text-align: center;
        font-size: 0.75rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        transition: 0.2s;
    }
    
    .nav-item.active {
        color: #00f5ff; /* var(--primary) */
    }
    
    .nav-item i {
        font-size: 1.2rem;
        margin-bottom: 2px;
    }
    
    /* Add padding to body to prevent content being hidden behind nav */
    body {
        padding-bottom: 70px !important;
    }
</style>

<div class="bottom-nav">
    <a href="<?php echo $rel_path; ?>dashboard.php" class="nav-item <?php echo $is_home ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        Home
    </a>
    <a href="<?php echo $rel_path; ?>tasks/index.php" class="nav-item <?php echo $is_tasks ? 'active' : ''; ?>">
        <i class="fas fa-clipboard-list"></i>
        Tugas
    </a>
    <a href="<?php echo $rel_path; ?>devices/search.php" class="nav-item <?php echo $is_devices ? 'active' : ''; ?>">
        <i class="fas fa-search"></i>
        Cek Alat
    </a>
    <a href="<?php echo $rel_path; ?>map/index.php" class="nav-item <?php echo $is_map ? 'active' : ''; ?>">
        <i class="fas fa-map-marked-alt"></i>
        Peta
    </a>
    <a href="<?php echo $rel_path; ?>profile.php" class="nav-item <?php echo $is_profile ? 'active' : ''; ?>">
        <i class="fas fa-user-circle"></i>
        Profil
    </a>
</div>
