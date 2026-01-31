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
$staff_data = [];
$staff_detail = null;
$staff_edit = null;

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// =============== DETAIL STAFF ===============
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $staff_id = intval($_GET['id']);

    // Load data staff
    $sql = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $staff_detail = $row;

            // Hitung statistik login
            $login_stats_sql = "SELECT 
                               COUNT(*) as total_login,
                               MAX(last_login) as last_login,
                               MIN(last_login) as first_login
                               FROM users WHERE id = ?";
            $login_stats_stmt = $conn->prepare($login_stats_sql);
            if ($login_stats_stmt) {
                $login_stats_stmt->bind_param("i", $staff_id);
                $login_stats_stmt->execute();
                $login_stats_result = $login_stats_stmt->get_result();
                $staff_detail['login_stats'] = $login_stats_result->fetch_assoc();
                $login_stats_stmt->close();
            }
        }
        $stmt->close();
    }
}

// =============== EDIT STAFF ===============
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $staff_id = intval($_GET['id']);

    $sql = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $staff_edit = $row;
        }
        $stmt->close();
    }
}

// =============== TAMBAH STAFF ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_staff'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = trim($_POST['email']);
    $full_name_input = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validasi
    $errors = [];

    if (empty($username) || empty($password) || empty($email) || empty($full_name_input)) {
        $errors[] = "❌ Username, password, email, dan nama lengkap harus diisi!";
    }

    if ($password !== $confirm_password) {
        $errors[] = "❌ Password dan konfirmasi password tidak cocok!";
    }

    if (strlen($password) < 6) {
        $errors[] = "❌ Password minimal 6 karakter!";
    }

    // Validasi email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "❌ Format email tidak valid!";
    }

    // Cek apakah username sudah ada
    $check_username_sql = "SELECT id FROM users WHERE username = ?";
    $check_username_stmt = $conn->prepare($check_username_sql);
    if ($check_username_stmt) {
        $check_username_stmt->bind_param("s", $username);
        $check_username_stmt->execute();
        if ($check_username_stmt->get_result()->num_rows > 0) {
            $errors[] = "❌ Username sudah digunakan!";
        }
        $check_username_stmt->close();
    }

    // Cek apakah email sudah ada
    $check_email_sql = "SELECT id FROM users WHERE email = ?";
    $check_email_stmt = $conn->prepare($check_email_sql);
    if ($check_email_stmt) {
        $check_email_stmt->bind_param("s", $email);
        $check_email_stmt->execute();
        if ($check_email_stmt->get_result()->num_rows > 0) {
            $errors[] = "❌ Email sudah digunakan!";
        }
        $check_email_stmt->close();
    }

    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert data
        $insert_sql = "INSERT INTO users (username, password, email, role, full_name, 
                       phone, address, is_active, created_at, updated_at)
                       VALUES (?, ?, ?, 'admin', ?, ?, ?, ?, NOW(), NOW())";

        $insert_stmt = $conn->prepare($insert_sql);
        if ($insert_stmt) {
            $insert_stmt->bind_param(
                "ssssssi",
                $username,
                $hashed_password,
                $email,
                $full_name_input,
                $phone,
                $address,
                $is_active
            );

            if ($insert_stmt->execute()) {
                $_SESSION['success_message'] = "✅ Staff/admin baru berhasil ditambahkan!";
                header('Location: dataStaff.php');
                exit();
            } else {
                $_SESSION['error_message'] = "❌ Gagal menambahkan staff/admin baru!";
            }
            $insert_stmt->close();
        } else {
            $_SESSION['error_message'] = "❌ Error dalam menyiapkan query!";
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }

    header('Location: dataStaff.php?action=tambah');
    exit();
}

// =============== UPDATE STAFF ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_staff'])) {
    $staff_id = intval($_POST['staff_id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $full_name_input = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validasi
    $errors = [];

    if (empty($username) || empty($email) || empty($full_name_input)) {
        $errors[] = "❌ Username, email, dan nama lengkap harus diisi!";
    }

    // Validasi email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "❌ Format email tidak valid!";
    }

    // Cek apakah username sudah ada (selain user ini)
    $check_username_sql = "SELECT id FROM users WHERE username = ? AND id != ?";
    $check_username_stmt = $conn->prepare($check_username_sql);
    if ($check_username_stmt) {
        $check_username_stmt->bind_param("si", $username, $staff_id);
        $check_username_stmt->execute();
        if ($check_username_stmt->get_result()->num_rows > 0) {
            $errors[] = "❌ Username sudah digunakan!";
        }
        $check_username_stmt->close();
    }

    // Cek apakah email sudah ada (selain user ini)
    $check_email_sql = "SELECT id FROM users WHERE email = ? AND id != ?";
    $check_email_stmt = $conn->prepare($check_email_sql);
    if ($check_email_stmt) {
        $check_email_stmt->bind_param("si", $email, $staff_id);
        $check_email_stmt->execute();
        if ($check_email_stmt->get_result()->num_rows > 0) {
            $errors[] = "❌ Email sudah digunakan!";
        }
        $check_email_stmt->close();
    }

    // Update password jika diisi
    $update_password = false;
    $hashed_password = '';

    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($password !== $confirm_password) {
            $errors[] = "❌ Password dan konfirmasi password tidak cocok!";
        }

        if (strlen($password) < 6) {
            $errors[] = "❌ Password minimal 6 karakter!";
        }

        if (empty($errors)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_password = true;
        }
    }

    if (empty($errors)) {
        if ($update_password) {
            // Update dengan password
            $update_sql = "UPDATE users SET 
                           username = ?,
                           password = ?,
                           email = ?,
                           full_name = ?,
                           phone = ?,
                           address = ?,
                           is_active = ?,
                           updated_at = NOW()
                           WHERE id = ? AND role = 'admin'";

            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param(
                    "ssssssii",
                    $username,
                    $hashed_password,
                    $email,
                    $full_name_input,
                    $phone,
                    $address,
                    $is_active,
                    $staff_id
                );
            }
        } else {
            // Update tanpa password
            $update_sql = "UPDATE users SET 
                           username = ?,
                           email = ?,
                           full_name = ?,
                           phone = ?,
                           address = ?,
                           is_active = ?,
                           updated_at = NOW()
                           WHERE id = ? AND role = 'admin'";

            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param(
                    "sssssii",
                    $username,
                    $email,
                    $full_name_input,
                    $phone,
                    $address,
                    $is_active,
                    $staff_id
                );
            }
        }

        if ($update_stmt && $update_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Data staff berhasil diperbarui!";
            $update_stmt->close();
        } else {
            $_SESSION['error_message'] = "❌ Gagal memperbarui data staff!";
        }

        header('Location: dataStaff.php?action=detail&id=' . $staff_id);
        exit();
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
        header('Location: dataStaff.php?action=edit&id=' . $staff_id);
        exit();
    }
}

// =============== HAPUS STAFF ===============
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $staff_id = intval($_GET['id']);

    // Cek apakah yang dihapus adalah admin sendiri
    if ($staff_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus akun sendiri!";
        header('Location: dataStaff.php');
        exit();
    }

    // Cek apakah admin hanya tinggal 1
    $check_count_sql = "SELECT COUNT(*) as total FROM users WHERE role = 'admin'";
    $check_count_stmt = $conn->prepare($check_count_sql);
    $total_admins = 0;
    if ($check_count_stmt) {
        $check_count_stmt->execute();
        $result = $check_count_stmt->get_result();
        $total_admins = $result->fetch_assoc()['total'];
        $check_count_stmt->close();
    }

    if ($total_admins <= 1) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus admin terakhir!";
        header('Location: dataStaff.php');
        exit();
    }

    // Hapus staff
    $delete_sql = "DELETE FROM users WHERE id = ? AND role = 'admin'";
    $delete_stmt = $conn->prepare($delete_sql);
    if ($delete_stmt) {
        $delete_stmt->bind_param("i", $staff_id);

        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Staff berhasil dihapus!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal menghapus staff!";
        }
        $delete_stmt->close();
    }

    header('Location: dataStaff.php');
    exit();
}

// =============== AMBIL DATA STAFF ===============
$sql = "SELECT * FROM users WHERE role = 'admin'";
$params = [];
$param_types = "";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(username LIKE ? OR full_name LIKE ? OR email LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "sss";
}

if (!empty($filter_status)) {
    if ($filter_status == 'aktif') {
        $conditions[] = "is_active = 1";
    } elseif ($filter_status == 'non-aktif') {
        $conditions[] = "is_active = 0";
    }
}

if (count($conditions) > 0) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $staff_data[] = $row;
    }
    $stmt->close();
}

// Hitung statistik
$stats_sql = "SELECT 
              COUNT(*) as total_staff,
              SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as aktif,
              SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as non_aktif,
              (SELECT COUNT(*) FROM users WHERE role = 'admin' AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as aktif_minggu_ini
              FROM users WHERE role = 'admin'";
$stats_stmt = $conn->prepare($stats_sql);
$statistik = ['total_staff' => 0, 'aktif' => 0, 'non_aktif' => 0, 'aktif_minggu_ini' => 0];
if ($stats_stmt) {
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    if ($stats_row = $stats_result->fetch_assoc()) {
        $statistik = $stats_row;
    }
    $stats_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Staff/Admin - Admin Bimbel Esc</title>
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
            max-width: 700px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes modalFadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }

            to {
                opacity: 0;
                transform: translateY(-20px);
            }
        }

        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
        }

        .modal-header.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .modal-header.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .modal-header.purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
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

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
        }

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

            .grid-2 {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            /* Table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table-responsive table {
                min-width: 640px;
            }
        }

        /* Table styles */
        .data-table {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .data-table th {
            background-color: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }

        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }

        .data-table tr:hover {
            background-color: #f9fafb;
        }

        .data-table tr:last-child td {
            border-bottom: none;
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
                        <i class="fas fa-users-cog mr-2"></i> Data Staff/Admin
                    </h1>
                    <p class="text-gray-600">Kelola data staff dan administrator sistem</p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <!-- TOMBOL TAMBAH STAFF -->
                    <a href="?action=tambah"
                        class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                        <i class="fas fa-plus-circle mr-2"></i> Tambah Staff
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Statistik -->
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-users-cog text-blue-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Staff</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['total_staff']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-check text-green-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Aktif</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['aktif']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-times text-yellow-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Non-Aktif</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['non_aktif']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

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

            <!-- Filter Section -->
            <?php if (!isset($staff_detail) && !isset($staff_edit) && !isset($_GET['action'])): ?>
                <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                    <div class="px-4 py-5 sm:p-6">
                        <form method="GET" action="dataStaff.php" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Search -->
                                <div>
                                    <label for="search" class="block text-sm font-medium text-gray-700">Pencarian</label>
                                    <div class="mt-1 relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <i class="fas fa-search text-gray-400"></i>
                                        </div>
                                        <input type="text" name="search" id="search"
                                            class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md"
                                            placeholder="Username, nama, atau email..."
                                            value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Filter Status -->
                                <div>
                                    <label for="filter_status"
                                        class="block text-sm font-medium text-gray-700">Status</label>
                                    <select id="filter_status" name="filter_status"
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Status</option>
                                        <option value="aktif" <?php echo $filter_status == 'aktif' ? 'selected' : ''; ?>>Aktif
                                        </option>
                                        <option value="non-aktif" <?php echo $filter_status == 'non-aktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex justify-between">
                                <button type="submit"
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-filter mr-2"></i> Filter Data
                                </button>

                                    <?php if (!empty($search) || !empty($filter_status)): ?>
                                                                    <a href="dataStaff.php" 
                                                                       class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                                        <i class="fas fa-times mr-2"></i> Reset Filter
                                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
            <?php endif; ?>

            <!-- Tabel Staff -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <?php if (count($staff_data) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        No
                                                    </th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Staff
                                                    </th>
                                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Kontak
                                                    </th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Status
                                                    </th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Aksi
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($staff_data as $index => $staff): ?>
                                                                    <tr class="hover:bg-gray-50">
                                                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                                            <?php echo $index + 1; ?>
                                                                        </td>
                                                                        <td class="px-4 py-3 whitespace-nowrap">
                                                                            <div class="flex items-center">
                                                                                <div class="flex-shrink-0 h-8 w-8">
                                                                                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                                                        <i class="fas fa-user-shield text-blue-600 text-sm"></i>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="ml-2">
                                                                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                                                        <?php echo htmlspecialchars($staff['full_name']); ?>
                                                                                    </div>
                                                                                    <div class="text-xs text-gray-500 truncate max-w-[120px]">
                                                                                        @<?php echo htmlspecialchars($staff['username']); ?>
                                                                                    </div>
                                                                                    <div class="text-xs text-gray-500">
                                                                                        <?php echo date('d/m/Y', strtotime($staff['created_at'])); ?>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                                                            <div class="text-sm text-gray-900 truncate max-w-[150px]">
                                                                                <?php echo htmlspecialchars($staff['email']); ?>
                                                                            </div>
                                                                            <div class="text-xs text-gray-500">
                                                                                <?php echo htmlspecialchars($staff['phone']); ?>
                                                                            </div>
                                                                        </td>
                                                                        <td class="px-4 py-3 whitespace-nowrap">
                                                                            <span class="status-badge <?php echo $staff['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                                                <?php echo $staff['is_active'] ? 'Aktif' : 'Non-Aktif'; ?>
                                                                            </span>
                                                                            <?php if ($staff['last_login']): ?>
                                                                                                <div class="text-xs text-gray-500 mt-1">
                                                                                                    Login: <?php echo date('d/m/Y H:i', strtotime($staff['last_login'])); ?>
                                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                                                            <div class="flex space-x-2">
                                                                                <a href="?action=detail&id=<?php echo $staff['id']; ?>" 
                                                                                   class="text-blue-600 hover:text-blue-900 p-1" 
                                                                                   title="Detail">
                                                                                    <i class="fas fa-eye"></i>
                                                                                </a>
                                                                                <?php if ($staff['id'] != $_SESSION['user_id']): ?>
                                                                                                    <a href="?action=edit&id=<?php echo $staff['id']; ?>" 
                                                                                                       class="text-yellow-600 hover:text-yellow-900 p-1" 
                                                                                                       title="Edit">
                                                                                                        <i class="fas fa-edit"></i>
                                                                                                    </a>
                                                                                                    <a href="#" 
                                                                                                       onclick="confirmDelete(<?php echo $staff['id']; ?>, '<?php echo htmlspecialchars(addslashes($staff['full_name'])); ?>')"
                                                                                                       class="text-red-600 hover:text-red-900 p-1" 
                                                                                                       title="Hapus">
                                                                                                        <i class="fas fa-trash"></i>
                                                                                                    </a>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                <?php else: ?>
                                    <div class="text-center py-12">
                                        <i class="fas fa-users-cog text-gray-300 text-5xl mb-4"></i>
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                                            Data staff tidak ditemukan
                                        </h3>
                                        <p class="text-gray-500 mb-4">
                                            <?php if (!empty($search) || !empty($filter_status)): ?>
                                                                Coba ubah filter pencarian atau
                                            <?php endif; ?>
                                            Tambahkan staff baru untuk memulai.
                                        </p>
                                        <a href="?action=tambah" 
                                           class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                            <i class="fas fa-plus-circle mr-2"></i> Tambah Staff
                                        </a>
                                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Staff</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Staff -->
    <?php if ($staff_detail): ?>
                        <div id="detailModal" class="modal" style="display: block;">
                            <div class="modal-content">
                                <div class="modal-header blue">
                                    <h2 class="text-xl font-bold">
                                        <i class="fas fa-user-shield mr-2"></i> Detail Staff
                                    </h2>
                                    <span class="close" onclick="closeModal()">&times;</span>
                                </div>
                                <div class="modal-body">
                                    <!-- Header Info -->
                                    <div class="mb-6">
                                        <div class="flex items-center mb-4">
                                            <div class="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user-shield text-blue-600 text-2xl"></i>
                                            </div>
                                            <div class="ml-4 flex-1">
                                                <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($staff_detail['full_name']); ?></h3>
                                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                                    <span class="text-sm text-gray-600">
                                                        <i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($staff_detail['username']); ?>
                                                    </span>
                                                    <span class="text-sm text-gray-600">
                                                        <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($staff_detail['email']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="status-badge <?php echo $staff_detail['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $staff_detail['is_active'] ? 'Aktif' : 'Non-Aktif'; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Informasi Staff -->
                                        <div class="grid-2">
                                            <div class="form-group">
                                                <label class="form-label">Username</label>
                                                <div class="p-2 bg-gray-50 rounded font-mono text-sm"><?php echo htmlspecialchars($staff_detail['username']); ?></div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Email</label>
                                                <div class="p-2 bg-gray-50 rounded"><?php echo htmlspecialchars($staff_detail['email']); ?></div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Nomor Telepon</label>
                                                <div class="p-2 bg-gray-50 rounded"><?php echo htmlspecialchars($staff_detail['phone']); ?></div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Alamat</label>
                                                <div class="p-2 bg-gray-50 rounded"><?php echo htmlspecialchars($staff_detail['address']); ?></div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Status</label>
                                                <div class="p-2 bg-gray-50 rounded">
                                                    <span class="status-badge <?php echo $staff_detail['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                        <?php echo $staff_detail['is_active'] ? 'Aktif' : 'Non-Aktif'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Role</label>
                                                <div class="p-2 bg-gray-50 rounded">
                                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                        Administrator
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Tanggal Dibuat</label>
                                                <div class="p-2 bg-gray-50 rounded text-sm">
                                                    <?php echo date('d/m/Y H:i', strtotime($staff_detail['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Terakhir Update</label>
                                                <div class="p-2 bg-gray-50 rounded text-sm">
                                                    <?php echo date('d/m/Y H:i', strtotime($staff_detail['updated_at'])); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Statistik Login -->
                                        <?php if (isset($staff_detail['login_stats'])): ?>
                                                            <div class="mt-6 border-t pt-6">
                                                                <h4 class="font-medium text-gray-900 mb-4">Statistik Login</h4>
                                                                <div class="grid-2">
                                                                    <div class="form-group">
                                                                        <label class="form-label">Login Terakhir</label>
                                                                        <div class="p-2 bg-gray-50 rounded">
                                                                            <?php echo $staff_detail['login_stats']['last_login']
                                                                                ? date('d/m/Y H:i', strtotime($staff_detail['login_stats']['last_login']))
                                                                                : 'Belum pernah login'; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label class="form-label">Login Pertama</label>
                                                                        <div class="p-2 bg-gray-50 rounded">
                                                                            <?php echo $staff_detail['login_stats']['first_login']
                                                                                ? date('d/m/Y H:i', strtotime($staff_detail['login_stats']['first_login']))
                                                                                : 'Belum pernah login'; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label class="form-label">Total Login</label>
                                                                        <div class="p-2 bg-gray-50 rounded">
                                                                            <?php echo $staff_detail['login_stats']['total_login']; ?> kali
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <div class="flex justify-end space-x-3">
                                        <button type="button" onclick="closeModal()" 
                                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Tutup
                                        </button>
                                        <?php if ($staff_detail['id'] != $_SESSION['user_id']): ?>
                                                            <a href="?action=edit&id=<?php echo $staff_detail['id']; ?>" 
                                                               class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700">
                                                                <i class="fas fa-edit mr-2"></i> Edit Data
                                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
    <?php endif; ?>

    <!-- Modal Edit Staff -->
    <?php if ($staff_edit): ?>
                        <div id="editModal" class="modal" style="display: block;">
                            <div class="modal-content">
                                <div class="modal-header green">
                                    <h2 class="text-xl font-bold">
                                        <i class="fas fa-edit mr-2"></i> Edit Data Staff
                                    </h2>
                                    <span class="close" onclick="closeModal()">&times;</span>
                                </div>
                                <form method="POST" action="dataStaff.php">
                                    <input type="hidden" name="update_staff" value="1">
                                    <input type="hidden" name="staff_id" value="<?php echo $staff_edit['id']; ?>">
                    
                                    <div class="modal-body">
                                        <div class="mb-6">
                                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Staff</h3>
                                            <div class="grid-2">
                                                <div class="form-group">
                                                    <label class="form-label" for="username">Username *</label>
                                                    <input type="text" id="username" name="username" 
                                                           class="form-input" 
                                                           value="<?php echo htmlspecialchars($staff_edit['username']); ?>" 
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="email">Email *</label>
                                                    <input type="email" id="email" name="email" 
                                                           class="form-input" 
                                                           value="<?php echo htmlspecialchars($staff_edit['email']); ?>" 
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="full_name">Nama Lengkap *</label>
                                                    <input type="text" id="full_name" name="full_name" 
                                                           class="form-input" 
                                                           value="<?php echo htmlspecialchars($staff_edit['full_name']); ?>" 
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="phone">Nomor Telepon</label>
                                                    <input type="text" id="phone" name="phone" 
                                                           class="form-input" 
                                                           value="<?php echo htmlspecialchars($staff_edit['phone']); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="address">Alamat</label>
                                                    <textarea id="address" name="address" 
                                                              class="form-input" 
                                                              rows="3"><?php echo htmlspecialchars($staff_edit['address']); ?></textarea>
                                                </div>
                                            </div>
                            
                                            <!-- Password (opsional) -->
                                            <div class="mt-6 border-t pt-6">
                                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Ubah Password (Opsional)</h4>
                                                <div class="grid-2">
                                                    <div class="form-group">
                                                        <label class="form-label" for="password">Password Baru</label>
                                                        <input type="password" id="password" name="password" 
                                                               class="form-input" 
                                                               placeholder="Kosongkan jika tidak diubah">
                                                        <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter</p>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label" for="confirm_password">Konfirmasi Password</label>
                                                        <input type="password" id="confirm_password" name="confirm_password" 
                                                               class="form-input" 
                                                               placeholder="Kosongkan jika tidak diubah">
                                                    </div>
                                                </div>
                                            </div>
                            
                                            <!-- Status -->
                                            <div class="mt-6 border-t pt-6">
                                                <div class="form-group">
                                                    <label class="form-label">Status</label>
                                                    <div class="flex items-center mt-2">
                                                        <input type="checkbox" id="is_active" name="is_active" 
                                                               value="1" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                                               <?php echo $staff_edit['is_active'] ? 'checked' : ''; ?>>
                                                        <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                                            Akun aktif
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <div class="flex justify-between">
                                            <button type="button" onclick="closeModal()" 
                                                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                Batal
                                            </button>
                                            <div class="space-x-3">
                                                <?php if ($staff_edit['id'] != $_SESSION['user_id']): ?>
                                                                    <a href="?action=detail&id=<?php echo $staff_edit['id']; ?>" 
                                                                       class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                                        Kembali ke Detail
                                                                    </a>
                                                <?php endif; ?>
                                                <button type="submit" 
                                                        class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
    <?php endif; ?>

    <!-- Modal Tambah Staff -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'tambah'): ?>
                        <div id="tambahModal" class="modal" style="display: block;">
                            <div class="modal-content">
                                <div class="modal-header green">
                                    <h2 class="text-xl font-bold">
                                        <i class="fas fa-plus-circle mr-2"></i> Tambah Staff Baru
                                    </h2>
                                    <span class="close" onclick="closeModal()">&times;</span>
                                </div>
                                <form method="POST" action="dataStaff.php">
                                    <input type="hidden" name="tambah_staff" value="1">
                    
                                    <div class="modal-body">
                                        <div class="mb-6">
                                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Staff</h3>
                                            <div class="grid-2">
                                                <div class="form-group">
                                                    <label class="form-label" for="username">Username *</label>
                                                    <input type="text" id="username" name="username" 
                                                           class="form-input" 
                                                           placeholder="contoh: admin2"
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="email">Email *</label>
                                                    <input type="email" id="email" name="email" 
                                                           class="form-input" 
                                                           placeholder="contoh: admin2@bimbel.com"
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="full_name">Nama Lengkap *</label>
                                                    <input type="text" id="full_name" name="full_name" 
                                                           class="form-input" 
                                                           placeholder="Nama lengkap staff"
                                                           required>
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="phone">Nomor Telepon</label>
                                                    <input type="text" id="phone" name="phone" 
                                                           class="form-input" 
                                                           placeholder="08xxxxxxxxxx">
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label" for="address">Alamat</label>
                                                    <textarea id="address" name="address" 
                                                              class="form-input" 
                                                              rows="3"
                                                              placeholder="Alamat lengkap"></textarea>
                                                </div>
                                            </div>
                            
                                            <!-- Password -->
                                            <div class="mt-6 border-t pt-6">
                                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Password</h4>
                                                <div class="grid-2">
                                                    <div class="form-group">
                                                        <label class="form-label" for="new_password">Password *</label>
                                                        <input type="password" id="new_password" name="password" 
                                                               class="form-input" 
                                                               placeholder="Minimal 6 karakter"
                                                               required>
                                                        <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter</p>
                                                    </div>
                                                    <div class="form-group">
                                                        <label class="form-label" for="new_confirm_password">Konfirmasi Password *</label>
                                                        <input type="password" id="new_confirm_password" name="confirm_password" 
                                                               class="form-input" 
                                                               placeholder="Ulangi password"
                                                               required>
                                                    </div>
                                                </div>
                                            </div>
                            
                                            <!-- Status -->
                                            <div class="mt-6 border-t pt-6">
                                                <div class="form-group">
                                                    <label class="form-label">Status</label>
                                                    <div class="flex items-center mt-2">
                                                        <input type="checkbox" id="new_is_active" name="is_active" 
                                                               value="1" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                                               checked>
                                                        <label for="new_is_active" class="ml-2 block text-sm text-gray-900">
                                                            Akun aktif
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <div class="flex justify-between">
                                            <button type="button" onclick="closeModal()" 
                                                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                Batal
                                            </button>
                                            <button type="submit" 
                                                    class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                                                <i class="fas fa-save mr-2"></i> Simpan Staff Baru
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
    <?php endif; ?>

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

        // Fungsi untuk menutup modal
        function closeModal() {
            // Hilangkan parameter action dan id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('id');
            
            // Update URL tanpa reload halaman
            window.history.replaceState({}, '', url.toString());
            
            // Sembunyikan modal dengan efek
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.animation = 'modalFadeOut 0.3s';
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 250);
            });
        }

        // Tambahkan event listener untuk klik di luar modal
        document.addEventListener('DOMContentLoaded', function() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            });
            
            // Auto focus pada input pertama di modal
            <?php if (isset($_GET['action']) && ($_GET['action'] == 'tambah' || $_GET['action'] == 'edit' || $_GET['action'] == 'detail')): ?>
                                const firstInput = document.querySelector('.modal input:not([type="hidden"]), .modal select, .modal textarea');
                                if (firstInput) {
                                    firstInput.focus();
                                }
            <?php endif; ?>
        });

        // Konfirmasi Hapus
        function confirmDelete(id, name) {
            if (confirm(`Apakah Anda yakin ingin menghapus staff "${name}"?\n\nPerhatian: Aksi ini tidak dapat dibatalkan!`)) {
                window.location.href = `dataStaff.php?action=hapus&id=${id}`;
            }
        }

        // Auto-close modals on ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    closeModal();
                }
            }
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                        } else {
                            field.style.borderColor = '#d1d5db';
                        }
                    });
                    
                    // Validasi password match
                    const password = form.querySelector('input[name="password"]');
                    const confirmPassword = form.querySelector('input[name="confirm_password"]');
                    
                    if (password && confirmPassword && password.value && confirmPassword.value) {
                        if (password.value !== confirmPassword.value) {
                            isValid = false;
                            confirmPassword.style.borderColor = '#ef4444';
                            alert('Password dan konfirmasi password tidak cocok!');
                        }
                        
                        if (password.value.length < 6) {
                            isValid = false;
                            password.style.borderColor = '#ef4444';
                            alert('Password minimal 6 karakter!');
                        }
                    }
                    
                    // Validasi email format
                    const email = form.querySelector('input[type="email"]');
                    if (email && email.value) {
                        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailPattern.test(email.value)) {
                            isValid = false;
                            email.style.borderColor = '#ef4444';
                            alert('Format email tidak valid!');
                        }
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Harap lengkapi semua field yang wajib diisi dengan benar!');
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