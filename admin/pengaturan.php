<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL
$success_message = '';
$error_message = '';
$admin_data = null;

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// AMBIL DATA ADMIN
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin_data = $result->fetch_assoc();
    $stmt->close();
}

// =============== UPDATE PROFILE ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    // Validasi nama lengkap
    if (empty($full_name)) {
        $errors[] = "Nama lengkap harus diisi!";
    }

    // Jika ingin ubah password
    if (!empty($new_password)) {
        // Validasi password saat ini
        if (empty($current_password)) {
            $errors[] = "Password saat ini harus diisi untuk mengubah password!";
        } else {
            // Verifikasi password saat ini
            if (!password_verify($current_password, $admin_data['password'])) {
                $errors[] = "Password saat ini salah!";
            }
        }

        // Validasi password baru
        if (strlen($new_password) < 6) {
            $errors[] = "Password baru minimal 6 karakter!";
        }

        if ($new_password !== $confirm_password) {
            $errors[] = "Password baru dan konfirmasi password tidak cocok!";
        }
    }

    if (empty($errors)) {
        if (!empty($new_password)) {
            // Update dengan password baru
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET full_name = ?, phone = ?, address = ?, password = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("ssssi", $full_name, $phone, $address, $hashed_password, $admin_id);
            }
        } else {
            // Update tanpa password
            $update_sql = "UPDATE users SET full_name = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("sssi", $full_name, $phone, $address, $admin_id);
            }
        }

        if ($update_stmt && $update_stmt->execute()) {
            // Update session
            $_SESSION['full_name'] = $full_name;

            $_SESSION['success_message'] = "✅ Profil berhasil diperbarui!";
            header('Location: pengaturan.php');
            exit();
        } else {
            $_SESSION['error_message'] = "❌ Gagal memperbarui profil!";
        }
    } else {
        $_SESSION['error_message'] = "❌ " . implode("<br>", $errors);
    }

    header('Location: pengaturan.php');
    exit();
}

// =============== UPDATE SYSTEM SETTINGS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_system_settings'])) {
    $nama_instansi = trim($_POST['nama_instansi']);
    $email_kontak = trim($_POST['email_kontak']);
    $telepon_kontak = trim($_POST['telepon_kontak']);
    $alamat_instansi = trim($_POST['alamat_instansi']);
    $jam_operasional = trim($_POST['jam_operasional']);
    
    // Cek apakah tabel settings sudah ada, jika tidak buat
    $check_table_sql = "SHOW TABLES LIKE 'settings'";
    $check_result = $conn->query($check_table_sql);
    
    if ($check_result->num_rows == 0) {
        // Buat tabel settings
        $create_table_sql = "CREATE TABLE settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if ($conn->query($create_table_sql)) {
            // Insert default settings
            $defaults = [
                ['nama_instansi', 'Bimbel Esc'],
                ['email_kontak', 'info@bimbelesc.com'],
                ['telepon_kontak', '(021) 12345678'],
                ['alamat_instansi', 'Jl. Contoh No. 123, Jakarta'],
                ['jam_operasional', '08:00 - 17:00']
            ];
            
            foreach ($defaults as $default) {
                $check_sql = "SELECT id FROM settings WHERE setting_key = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("s", $default[0]);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows == 0) {
                    $insert_sql = "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("ss", $default[0], $default[1]);
                    $insert_stmt->execute();
                }
            }
        }
    }
    
    // Update settings
    $settings = [
        'nama_instansi' => $nama_instansi,
        'email_kontak' => $email_kontak,
        'telepon_kontak' => $telepon_kontak,
        'alamat_instansi' => $alamat_instansi,
        'jam_operasional' => $jam_operasional
    ];
    
    $success = true;
    foreach ($settings as $key => $value) {
        $check_sql = "SELECT id FROM settings WHERE setting_key = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $key);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing
            $update_sql = "UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ss", $value, $key);
            if (!$update_stmt->execute()) {
                $success = false;
            }
        } else {
            // Insert new
            $insert_sql = "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ss", $key, $value);
            if (!$insert_stmt->execute()) {
                $success = false;
            }
        }
    }
    
    if ($success) {
        $_SESSION['success_message'] = "✅ Pengaturan sistem berhasil diperbarui!";
    } else {
        $_SESSION['error_message'] = "❌ Gagal memperbarui pengaturan sistem!";
    }
    
    header('Location: pengaturan.php');
    exit();
}

// AMBIL DATA SETTINGS DENGAN PENANGANAN ERROR
$settings_data = [];

// Cek apakah tabel settings ada
$table_check = $conn->query("SHOW TABLES LIKE 'settings'");
if ($table_check && $table_check->num_rows > 0) {
    // Tabel ada, ambil data
    $settings_sql = "SELECT setting_key, setting_value FROM settings";
    $settings_result = $conn->query($settings_sql);
    if ($settings_result) {
        while ($row = $settings_result->fetch_assoc()) {
            $settings_data[$row['setting_key']] = $row['setting_value'];
        }
    }
} else {
    // Tabel tidak ada, gunakan nilai default
    $settings_data = [
        'nama_instansi' => 'Bimbel Esc',
        'email_kontak' => 'info@bimbelesc.com',
        'telepon_kontak' => '(021) 12345678',
        'alamat_instansi' => 'Jl. Contoh No. 123, Jakarta',
        'jam_operasional' => '08:00 - 17:00'
    ];
}

// AMBIL STATISTIK DENGAN PENANGANAN ERROR
$stats = [];
$tables = ['siswa', 'guru', 'kelas', 'penilaian_siswa'];
foreach ($tables as $table) {
    // Cek apakah tabel ada sebelum query
    $check_table = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check_table && $check_table->num_rows > 0) {
        $sql = "SELECT COUNT(*) as total FROM $table";
        $result = $conn->query($sql);
        if ($result) {
            $stats[$table] = $result->fetch_assoc()['total'] ?? 0;
        } else {
            $stats[$table] = 0;
        }
    } else {
        $stats[$table] = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes modalFadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
        }
        .modal-header.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .modal-body {
            padding: 24px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background-color: #f9fafb;
            border-radius: 0 0 8px 8px;
        }
        .close {
            color: #fff;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }
        .close:hover {
            color: #f0f0f0;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            min-height: 100px;
            resize: vertical;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        
        /* Switch toggle */
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #3b82f6;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }

        /* ===== STYLE UNTUK MENU DINAMIS ===== */
        /* Dropdown styles untuk menu dinamis */
        .dropdown-submenu {
            display: none;
            max-height: 500px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .dropdown-submenu[style*="display: block"] {
            display: block;
        }
        
        .dropdown-toggle.open .arrow {
            transform: rotate(180deg);
        }
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }
        
        /* Sidebar menu hover */
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Logout button khusus */
        .logout-btn {
            margin-top: 2rem !important;
            color: #fca5a5 !important;
        }
        
        .logout-btn:hover {
            background-color: rgba(254, 226, 226, 0.9) !important;
            color: #b91c1c !important;
        }
        
        /* Mobile menu styles */
        #mobileMenu {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100%;
            z-index: 1100;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
            background-color: #1e40af;
        }
        
        #mobileMenu.menu-open {
            transform: translateX(0);
        }
        
        /* Overlay for mobile menu */
        .menu-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1099;
        }
        
        .menu-overlay.active {
            display: block;
        }
        
        /* Responsive untuk sidebar */
        @media (min-width: 768px) {
            .desktop-sidebar {
                display: block;
            }
            
            .mobile-header {
                display: none;
            }
            
            #mobileMenu {
                display: none;
            }
            
            .menu-overlay {
                display: none !important;
            }
        }
        
        @media (max-width: 767px) {
            .desktop-sidebar {
                display: none;
            }
            
            .mobile-header {
                display: block;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .modal-body {
                padding: 16px;
                max-height: 80vh;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        /* ===== STYLE UNTUK DATA TABEL ===== */
        /* Table styles */
        .data-table {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        /* Stats cards */
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            font-size: 14px;
            color: #6b7280;
        }
        
        /* Setting cards */
        .setting-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        /* Tab styles */
        .tab-buttons {
            display: flex;
            overflow-x: auto;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .tab-buttons::-webkit-scrollbar {
            display: none;
        }
        
        .tab-button {
            padding: 12px 16px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
            font-size: 14px;
        }
        
        .tab-button:hover {
            color: #374151;
        }
        
        .tab-button.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Danger zone */
        .danger-zone {
            border: 2px solid #ef4444;
            border-radius: 8px;
            padding: 20px;
            background-color: #fef2f2;
        }
        
        .danger-zone .title {
            color: #dc2626;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Admin Dashboard</p>
        </div>

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'admin'); ?>
        </nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 w-full fixed top-0 z-30 md:hidden">
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="text-white mr-3">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-3">
                    <p class="text-sm"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-blue-300">Admin</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Overlay -->
    <div id="menuOverlay" class="menu-overlay"></div>

    <!-- Mobile Menu Sidebar -->
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="h-full flex flex-col">
            <div class="p-4 bg-blue-900">
                <div class="flex items-center">
                    <button id="menuClose" class="text-white mr-3">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                    <h1 class="text-xl font-bold">Bimbel Esc</h1>
                </div>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-shield text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'admin'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <!-- Mobile header spacer -->
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-cog mr-2"></i> Pengaturan Sistem
                    </h1>
                    <p class="text-gray-600">Kelola pengaturan akun dan sistem bimbingan belajar</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Notifications -->
            <?php if ($success_message): ?>
                <div class="mb-4 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-4 bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 gap-3 mb-6">
                <div class="stat-card">
                    <div class="icon text-blue-600">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="value text-gray-900"><?php echo number_format($stats['siswa']); ?></div>
                    <div class="label">Total Siswa</div>
                </div>
                <div class="stat-card">
                    <div class="icon text-green-600">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="value text-gray-900"><?php echo number_format($stats['guru']); ?></div>
                    <div class="label">Total Guru</div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="tab-buttons">
                <button class="tab-button active" onclick="switchTab('profile')">
                    <i class="fas fa-user mr-2"></i>Profil
                </button>
                <button class="tab-button" onclick="switchTab('security')">
                    <i class="fas fa-shield-alt mr-2"></i>Keamanan
                </button>
                <button class="tab-button" onclick="switchTab('system')">
                    <i class="fas fa-sliders-h mr-2"></i>Sistem
                </button>
                <!--
                <button class="tab-button" onclick="switchTab('backup')">
                    <i class="fas fa-database mr-2"></i>Backup
                </button>
                -->
            </div>

            <!-- Tab Content: Profile -->
            <div id="tab-profile" class="tab-content active">
                <div class="setting-card">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-user-edit mr-2 text-blue-600"></i>Informasi Profil
                    </h3>

                    <form method="POST" action="pengaturan.php">
                        <input type="hidden" name="update_profile" value="1">

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="username">Username</label>
                                <input type="text" id="username" class="form-input bg-gray-50"
                                    value="<?php echo htmlspecialchars($admin_data['username']); ?>" readonly>
                                <p class="text-xs text-gray-500 mt-1">Username tidak dapat diubah</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" class="form-input bg-gray-50"
                                    value="<?php echo htmlspecialchars($admin_data['email']); ?>" readonly>
                                <p class="text-xs text-gray-500 mt-1">Email tidak dapat diubah</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="full_name">Nama Lengkap *</label>
                                <input type="text" id="full_name" name="full_name" class="form-input"
                                    value="<?php echo htmlspecialchars($admin_data['full_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="phone">Nomor Telepon</label>
                                <input type="text" id="phone" name="phone" class="form-input"
                                    value="<?php echo htmlspecialchars($admin_data['phone']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="address">Alamat</label>
                            <textarea id="address" name="address" class="form-textarea"><?php echo htmlspecialchars($admin_data['address']); ?></textarea>
                        </div>

                        <div class="flex justify-end space-x-3 mt-6">
                            <button type="submit"
                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i class="fas fa-save mr-2"></i>Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>

            </div>

            <!-- Tab Content: Security -->
            <div id="tab-security" class="tab-content">
                <div class="setting-card">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-key mr-2 text-blue-600"></i>Ubah Password
                    </h3>

                    <form method="POST" action="pengaturan.php">
                        <input type="hidden" name="update_profile" value="1">

                        <div class="space-y-4">
                            <div class="form-group">
                                <label class="form-label" for="current_password">Password Saat Ini</label>
                                <input type="password" id="current_password" name="current_password"
                                    class="form-input" placeholder="Masukkan password saat ini">
                                <p class="text-xs text-gray-500 mt-1">Wajib diisi untuk mengubah password</p>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="new_password">Password Baru</label>
                                    <input type="password" id="new_password" name="new_password"
                                        class="form-input" placeholder="Minimal 6 karakter">
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="confirm_password">Konfirmasi Password Baru</label>
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        class="form-input" placeholder="Ulangi password baru">
                                </div>
                            </div>

                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-yellow-700">
                                            <strong>Perhatian:</strong> Pastikan password baru Anda kuat dan mudah diingat.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit"
                                    class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-key mr-2"></i>Ubah Password
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!--<div class="setting-card">-->
                <!--    <h3 class="text-lg font-semibold text-gray-900 mb-4">-->
                <!--        <i class="fas fa-shield-alt mr-2 text-blue-600"></i>Keamanan Akun-->
                <!--    </h3>-->

                <!--    <div class="space-y-4">-->
                <!--        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">-->
                <!--            <div class="flex items-center">-->
                <!--                <i class="fas fa-history text-green-600 mr-3"></i>-->
                <!--                <div>-->
                <!--                    <p class="font-medium">Riwayat Login</p>-->
                <!--                    <p class="text-sm text-gray-500">Pantau aktivitas login terakhir</p>-->
                <!--                </div>-->
                <!--            </div>-->
                <!--            <button class="text-blue-600 hover:text-blue-800 font-medium text-sm" onclick="showLoginHistory()">-->
                <!--                Lihat-->
                <!--            </button>-->
                <!--        </div>-->

                <!--        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">-->
                <!--            <div class="flex items-center">-->
                <!--                <i class="fas fa-exclamation-triangle text-red-600 mr-3"></i>-->
                <!--                <div>-->
                <!--                    <p class="font-medium">Sesi Aktif</p>-->
                <!--                    <p class="text-sm text-gray-500">Keluar dari semua perangkat</p>-->
                <!--                </div>-->
                <!--            </div>-->
                <!--            <button class="text-red-600 hover:text-red-800 font-medium text-sm" onclick="logoutAllSessions()">-->
                <!--                Keluar Semua-->
                <!--            </button>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--</div>-->
            </div>

            <!-- Tab Content: System -->
            <div id="tab-system" class="tab-content">
                <div class="setting-card">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-cogs mr-2 text-blue-600"></i>Pengaturan Umum
                    </h3>

                    <form method="POST" action="pengaturan.php">
                        <input type="hidden" name="update_system_settings" value="1">

                        <div class="space-y-4">
                            <div class="form-group">
                                <label class="form-label">Nama Instansi</label>
                                <input type="text" name="nama_instansi" class="form-input" 
                                    value="<?php echo htmlspecialchars($settings_data['nama_instansi'] ?? 'Bimbel Esc'); ?>"
                                    placeholder="Nama bimbingan belajar">
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label">Email Kontak</label>
                                    <input type="email" name="email_kontak" class="form-input" 
                                        value="<?php echo htmlspecialchars($settings_data['email_kontak'] ?? 'info@bimbelesc.com'); ?>"
                                        placeholder="Email kontak">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Telepon Kontak</label>
                                    <input type="text" name="telepon_kontak" class="form-input" 
                                        value="<?php echo htmlspecialchars($settings_data['telepon_kontak'] ?? '(021) 12345678'); ?>"
                                        placeholder="Nomor telepon">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alamat Instansi</label>
                                <textarea name="alamat_instansi" class="form-textarea" 
                                    placeholder="Alamat lengkap"><?php echo htmlspecialchars($settings_data['alamat_instansi'] ?? 'Jl. Contoh No. 123, Jakarta'); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Jam Operasional</label>
                                <input type="text" name="jam_operasional" class="form-input" 
                                    value="<?php echo htmlspecialchars($settings_data['jam_operasional'] ?? '08:00 - 17:00'); ?>"
                                    placeholder="Jam operasional">
                            </div>

                            <div class="flex justify-end">
                                <button type="submit"
                                    class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-save mr-2"></i>Simpan Pengaturan
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

               
            </div>

            <!-- Tab Content: Backup (DIKOMENTARI) -->
            <!--
            <div id="tab-backup" class="tab-content">
                <div class="setting-card">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <i class="fas fa-database mr-2 text-blue-600"></i>Backup Database
                    </h3>

                    <div class="space-y-4">
                        <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-blue-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-blue-700">
                                        <strong>Informasi:</strong> Backup database akan menyimpan semua data ke dalam file SQL. 
                                        Disarankan untuk melakukan backup secara berkala.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Backup Terakhir</label>
                                <input type="text" class="form-input bg-gray-50" value="<?php echo date('d/m/Y H:i'); ?>" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Ukuran Database</label>
                                <input type="text" class="form-input bg-gray-50" value="15.2 MB" readonly>
                            </div>
                        </div>

                        <div class="flex space-x-3">
                            <button onclick="createBackup()"
                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                                <i class="fas fa-download mr-2"></i>Buat Backup
                            </button>
                            <button onclick="restoreBackup()"
                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700">
                                <i class="fas fa-upload mr-2"></i>Restore Backup
                            </button>
                        </div>
                    </div>
                </div>

                <div class="danger-zone">
                    <h3 class="title">
                        <i class="fas fa-exclamation-triangle"></i>Zona Berbahaya
                    </h3>
                    <p class="text-sm text-gray-700 mb-4">
                        Tindakan di bawah ini akan menghapus data secara permanen. Pastikan Anda telah membuat backup sebelum melanjutkan.
                    </p>
                    
                    <div class="space-y-3">
                        <button onclick="clearOldData()"
                            class="w-full px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                            <i class="fas fa-trash mr-2"></i>Hapus Data Lama ( > 1 tahun)
                        </button>
                        
                        <button onclick="resetDatabase()"
                            class="w-full px-4 py-2 border border-red-600 text-sm font-medium rounded-md text-red-600 bg-white hover:bg-red-50">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Reset Database (SEMUA DATA)
                        </button>
                    </div>
                </div>
            </div>
            -->

            <!-- System Info -->
            <!--<div class="setting-card mt-6">-->
            <!--    <h3 class="text-lg font-semibold text-gray-900 mb-4">-->
            <!--        <i class="fas fa-info-circle mr-2 text-blue-600"></i>Informasi Sistem-->
            <!--    </h3>-->

            <!--    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">-->
            <!--        <div class="p-3 bg-gray-50 rounded-lg">-->
            <!--            <p class="text-sm text-gray-500">Versi Sistem</p>-->
            <!--            <p class="font-medium">v2.1.0</p>-->
            <!--        </div>-->

            <!--        <div class="p-3 bg-gray-50 rounded-lg">-->
            <!--            <p class="text-sm text-gray-500">PHP Version</p>-->
            <!--            <p class="font-medium"><?php echo phpversion(); ?></p>-->
            <!--        </div>-->

            <!--        <div class="p-3 bg-gray-50 rounded-lg">-->
            <!--            <p class="text-sm text-gray-500">Database</p>-->
            <!--            <p class="font-medium">MySQL 10.4.32-MariaDB</p>-->
            <!--        </div>-->

            <!--        <div class="p-3 bg-gray-50 rounded-lg">-->
            <!--            <p class="text-sm text-gray-500">Server Time</p>-->
            <!--            <p class="font-medium"><?php echo date('d/m/Y H:i:s'); ?></p>-->
            <!--        </div>-->
            <!--    </div>-->
            <!--</div>-->
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Sistem Bimbingan Belajar</p>
                    <p class="mt-1 text-xs text-gray-400">Versi 2.1.0</p>
                </div>
            </div>
        </footer>
    </div>

    <script>
        // Mobile Menu Toggle
        const menuToggle = document.getElementById('menuToggle');
        const menuClose = document.getElementById('menuClose');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuOverlay = document.getElementById('menuOverlay');

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                mobileMenu.classList.add('menu-open');
                menuOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }

        if (menuClose) {
            menuClose.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        }

        if (menuOverlay) {
            menuOverlay.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        }

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownGroup = this.closest('.mb-1');
                const submenu = dropdownGroup.querySelector('.dropdown-submenu');
                const arrow = this.querySelector('.arrow');
                
                // Toggle current dropdown
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => {
                        sm.style.display = 'none';
                    });
                    document.querySelectorAll('.dropdown-toggle').forEach(t => {
                        t.classList.remove('open');
                        t.querySelector('.arrow').style.transform = 'rotate(0deg)';
                    });
                    
                    // Open this dropdown
                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(submenu => {
                    submenu.style.display = 'none';
                });
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('open');
                    toggle.querySelector('.arrow').style.transform = 'rotate(0deg)';
                });
            }
        });

        // Close menu when clicking on menu items (mobile)
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    mobileMenu.classList.remove('menu-open');
                    if (menuOverlay) {
                        menuOverlay.classList.remove('active');
                    }
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Switch Tab Function
       // Switch Tab Function
function switchTab(tabName) {
    // Hide all tab contents and remove active class from all buttons
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });

    // Show selected tab content
    const targetTab = document.getElementById('tab-' + tabName);
    if (targetTab) {
        targetTab.classList.add('active');
    }

    // Activate clicked tab button
    event.target.classList.add('active');
}

        // Show login history modal
        function showLoginHistory() {
            alert('Fitur ini akan menampilkan riwayat login. Dalam implementasi nyata, Anda perlu membuat tabel untuk menyimpan riwayat login.');
        }

        // Logout all sessions
        function logoutAllSessions() {
            if (confirm('Apakah Anda yakin ingin keluar dari semua perangkat? Anda akan diminta login kembali.')) {
                // Implementasi logout all sessions
                alert('Sesi telah dihapus dari semua perangkat.');
            }
        }

        // Create database backup
        function createBackup() {
            if (confirm('Buat backup database sekarang?')) {
                // Show loading
                const button = event.target;
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Membuat Backup...';
                button.disabled = true;

                // Simulate backup process
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.disabled = false;
                    alert('Backup berhasil dibuat! File: backup_' + new Date().toISOString().split('T')[0] + '.sql');
                }, 2000);
            }
        }

        // Restore database backup
        function restoreBackup() {
            alert('Fitur restore backup memerlukan upload file SQL. Silakan pilih file backup yang ingin di-restore.');
            // Implement file upload for restore
        }

        // Clear old data
        function clearOldData() {
            if (confirm('Hapus data yang lebih dari 1 tahun? Tindakan ini tidak dapat dibatalkan!')) {
                if (confirm('PASTIKAN Anda sudah membuat backup database! Lanjutkan?')) {
                    alert('Data lama berhasil dihapus.');
                }
            }
        }

        // Reset database
        function resetDatabase() {
            if (confirm('RESET DATABASE AKAN MENGHAPUS SEMUA DATA! Tindakan ini tidak dapat dibatalkan!')) {
                if (confirm('APAKAH ANDA YAKIN 100%? SEMUA DATA AKAN HILANG!')) {
                    const password = prompt('Masukkan password admin untuk konfirmasi:');
                    if (password === 'admin123') { // Change this to actual verification
                        alert('Database telah direset. Sistem akan restart.');
                        // Redirect to setup page
                        window.location.href = '../setup/install.php';
                    } else {
                        alert('Password salah! Reset dibatalkan.');
                    }
                }
            }
        }

        // Password strength indicator
        const passwordInput = document.getElementById('new_password');
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const strength = checkPasswordStrength(this.value);
                updatePasswordStrength(strength);
            });
        }

        function checkPasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            return strength;
        }

        function updatePasswordStrength(strength) {
            const indicator = document.getElementById('password-strength');
            if (indicator) {
                let text = '';
                let color = '';
                switch (strength) {
                    case 0:
                    case 1:
                        text = 'Sangat Lemah';
                        color = '#ef4444';
                        break;
                    case 2:
                        text = 'Lemah';
                        color = '#f97316';
                        break;
                    case 3:
                        text = 'Sedang';
                        color = '#eab308';
                        break;
                    case 4:
                        text = 'Kuat';
                        color = '#22c55e';
                        break;
                    case 5:
                        text = 'Sangat Kuat';
                        color = '#16a34a';
                        break;
                }
                indicator.textContent = text;
                indicator.style.color = color;
            }
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;
                    let firstInvalidField = null;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                            if (!firstInvalidField) {
                                firstInvalidField = field;
                            }
                        } else {
                            field.style.borderColor = '#d1d5db';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        if (firstInvalidField) {
                            firstInvalidField.focus();
                        }
                        alert('Harap lengkapi semua field yang wajib diisi!');
                    }
                });
            });

            // Auto focus on first input if there's an error
            <?php if ($error_message): ?>
                const firstInput = document.querySelector('form input:not([type="hidden"])');
                if (firstInput) {
                    firstInput.focus();
                }
            <?php endif; ?>
        });

        // Auto-close modals on ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    modals.forEach(modal => {
                        modal.style.display = 'none';
                    });
                }
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add password toggle functionality
            document.querySelectorAll('.toggle-password').forEach(button => {
                button.addEventListener('click', function() {
                    const input = this.previousElementSibling;
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>