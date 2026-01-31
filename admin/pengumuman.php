<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Periksa apakah session sudah dimulai sebelumnya
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE dengan cara yang lebih aman
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['user_role'] != 'admin') {
    header('Location: ../index.php'); // Redirect ke halaman utama jika bukan admin
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// Pesan sukses/error
$message = '';
$message_type = '';

// Handle POST untuk tambah pengumuman (dari modal)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah') {
        // Debug: log data yang diterima
        error_log("POST Data: " . print_r($_POST, true));
        
        $judul = trim($_POST['judul'] ?? '');
        $isi = trim($_POST['isi'] ?? '');
        $target = $_POST['target'] ?? 'orangtua'; // Default ke 'orangtua' sesuai database
        $ditampilkan_dari = $_POST['ditampilkan_dari'] ?? '';
        $ditampilkan_sampai = $_POST['ditampilkan_sampai'] ?? '';
        $status = $_POST['status'] ?? 'draft'; // Default ke draft jika tidak ada
        
        $errors = [];
        
        // Validasi
        if (empty($judul)) {
            $errors[] = "Judul tidak boleh kosong";
        }
        
        if (empty($isi)) {
            $errors[] = "Isi pengumuman tidak boleh kosong";
        }
        
        if (empty($ditampilkan_dari)) {
            $errors[] = "Tanggal tampil dari tidak boleh kosong";
        }
        
        // Validasi target sesuai enum di database
        if (!in_array($target, ['orangtua', 'guru', 'semua'])) {
            $errors[] = "Target pengumuman tidak valid";
        }
        
        // Handle file upload
        $gambar_nama = null;
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] != 4) {
            $file = $_FILES['gambar'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $errors[] = "Format file tidak didukung. Gunakan JPG, PNG, atau GIF";
            } elseif ($file['size'] > $max_size) {
                $errors[] = "Ukuran file maksimal 2MB";
            } else {
                // Create uploads directory if not exists
                $upload_dir = '../uploads/pengumuman/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $gambar_nama = uniqid() . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $gambar_nama;
                
                if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $errors[] = "Gagal mengupload gambar";
                    $gambar_nama = null;
                }
            }
        }
        
        if (empty($errors)) {
            // Convert datetime format
            $ditampilkan_dari_dt = date('Y-m-d H:i:s', strtotime($ditampilkan_dari));
            $ditampilkan_sampai_dt = !empty($ditampilkan_sampai) ? date('Y-m-d H:i:s', strtotime($ditampilkan_sampai)) : null;
            
            // Insert ke database
            $sql = "INSERT INTO pengumuman (judul, isi, gambar, target, ditampilkan_dari, ditampilkan_sampai, status, dibuat_oleh) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("sssssssi", $judul, $isi, $gambar_nama, $target, $ditampilkan_dari_dt, $ditampilkan_sampai_dt, $status, $admin_id);
                
                if ($stmt->execute()) {
                    $message = "Pengumuman berhasil ditambahkan!";
                    $message_type = "success";
                    
                    // Redirect untuk mencegah resubmit pada refresh
                    $_SESSION['flash_message'] = $message;
                    $_SESSION['flash_message_type'] = $message_type;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $message = "Gagal menambahkan pengumuman: " . $conn->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
        } else {
            $message = implode("<br>", $errors);
            $message_type = "error";
        }
    }
    // Handle UPDATE pengumuman dari modal popup
    elseif ($_POST['action'] == 'update') {
        $pengumuman_id = $_POST['id'] ?? 0;
        $judul = trim($_POST['judul'] ?? '');
        $isi = trim($_POST['isi'] ?? '');
        $target = $_POST['target'] ?? 'orangtua'; // Default sesuai database
        $ditampilkan_dari = $_POST['ditampilkan_dari'] ?? '';
        $ditampilkan_sampai = $_POST['ditampilkan_sampai'] ?? '';
        $status = $_POST['status'] ?? 'draft';
        $hapus_gambar = isset($_POST['hapus_gambar']) ? true : false;
        
        // Ambil data gambar lama
        $sql_old = "SELECT gambar FROM pengumuman WHERE id = ?";
        $stmt_old = $conn->prepare($sql_old);
        $gambar_lama = null;
        if ($stmt_old) {
            $stmt_old->bind_param("i", $pengumuman_id);
            $stmt_old->execute();
            $result = $stmt_old->get_result();
            if ($result->num_rows > 0) {
                $data = $result->fetch_assoc();
                $gambar_lama = $data['gambar'];
            }
            $stmt_old->close();
        }
        
        $gambar_nama = $gambar_lama; // Default: gambar lama
        
        // Jika hapus gambar dicentang
        if ($hapus_gambar && !empty($gambar_lama)) {
            $file_path = "../uploads/pengumuman/" . $gambar_lama;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $gambar_nama = null;
        }
        
        // Handle upload gambar baru
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] != 4) {
            $file = $_FILES['gambar'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $message = "Format file tidak didukung. Gunakan JPG, PNG, atau GIF";
                $message_type = "error";
            } elseif ($file['size'] > $max_size) {
                $message = "Ukuran file maksimal 2MB";
                $message_type = "error";
            } else {
                // Hapus file lama jika ada
                if (!empty($gambar_lama)) {
                    $old_file_path = "../uploads/pengumuman/" . $gambar_lama;
                    if (file_exists($old_file_path)) {
                        unlink($old_file_path);
                    }
                }
                
                // Upload file baru
                $upload_dir = '../uploads/pengumuman/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $gambar_nama = uniqid() . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $gambar_nama;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Success
                } else {
                    $gambar_nama = $gambar_lama; // Tetap pakai yang lama jika gagal upload
                }
            }
        }
        
        // Convert datetime format
        $ditampilkan_dari_dt = date('Y-m-d H:i:s', strtotime($ditampilkan_dari));
        $ditampilkan_sampai_dt = !empty($ditampilkan_sampai) ? date('Y-m-d H:i:s', strtotime($ditampilkan_sampai)) : null;
        
        // Update ke database
        $sql = "UPDATE pengumuman 
                SET judul = ?, isi = ?, gambar = ?, target = ?, ditampilkan_dari = ?, 
                    ditampilkan_sampai = ?, status = ?, diupdate_pada = NOW() 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssssi", $judul, $isi, $gambar_nama, $target, $ditampilkan_dari_dt, 
                             $ditampilkan_sampai_dt, $status, $pengumuman_id);
            
            if ($stmt->execute()) {
                $message = "Pengumuman berhasil diperbarui!";
                $message_type = "success";
                
                $_SESSION['flash_message'] = $message;
                $_SESSION['flash_message_type'] = $message_type;
            } else {
                $message = "Gagal memperbarui pengumuman: " . $conn->error;
                $message_type = "error";
            }
            $stmt->close();
        }
        
        // Redirect ke halaman yang sama
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Cek flash message dari session
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Handle delete pengumuman
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pengumuman_id = $_GET['delete'];
    
    // Cek apakah ada gambar, hapus file jika ada
    $sql = "SELECT gambar FROM pengumuman WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $pengumuman_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            // Hapus file gambar jika ada
            if (!empty($data['gambar'])) {
                $file_path = "../uploads/pengumuman/" . $data['gambar'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
        $stmt->close();
    }
    
    // Hapus dari database
    $sql = "DELETE FROM pengumuman WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $pengumuman_id);
        
        if ($stmt->execute()) {
            $message = "Pengumuman berhasil dihapus!";
            $message_type = "success";
            
            // Redirect untuk refresh data
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=deleted");
            exit();
        } else {
            $message = "Gagal menghapus pengumuman: " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Handle ubah status - HANYA untuk GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $pengumuman_id = $_GET['toggle_status'];
    
    // Get current status
    $sql = "SELECT status FROM pengumuman WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $pengumuman_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $new_status = ($data['status'] == 'draft') ? 'publik' : 'draft';
            
            // Update status
            $sql = "UPDATE pengumuman SET status = ? WHERE id = ?";
            $stmt2 = $conn->prepare($sql);
            if ($stmt2) {
                $stmt2->bind_param("si", $new_status, $pengumuman_id);
                
                if ($stmt2->execute()) {
                    $action = ($new_status == 'publik') ? 'dipublikasikan' : 'disimpan sebagai draft';
                    $message = "Pengumuman berhasil $action!";
                    $message_type = "success";
                    
                    // Redirect untuk refresh data
                    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=toggled");
                    exit();
                } else {
                    $message = "Gagal mengubah status: " . $conn->error;
                    $message_type = "error";
                }
                $stmt2->close();
            }
        }
        $stmt->close();
    }
}

// Ambil semua pengumuman
$pengumuman_list = [];
$sql = "SELECT p.*, u.full_name as pembuat 
        FROM pengumuman p 
        JOIN users u ON p.dibuat_oleh = u.id 
        ORDER BY p.dibuat_pada DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Format tanggal untuk display
        $row['ditampilkan_dari_formatted'] = date('d M Y H:i', strtotime($row['ditampilkan_dari']));
        if ($row['ditampilkan_sampai']) {
            $row['ditampilkan_sampai_formatted'] = date('d M Y H:i', strtotime($row['ditampilkan_sampai']));
        } else {
            $row['ditampilkan_sampai_formatted'] = '-';
        }
        $row['dibuat_pada_formatted'] = date('d M Y H:i', strtotime($row['dibuat_pada']));
        
        // Format target untuk display
        $target_labels = [
            'orangtua' => 'Orangtua',
            'guru' => 'Guru',
            'semua' => 'Semua'
        ];
        $row['target_label'] = $target_labels[$row['target']] ?? $row['target'];
        
        $pengumuman_list[] = $row;
    }
}

// Hitung statistik berdasarkan target
$stats_target = [
    'orangtua' => 0,
    'guru' => 0,
    'semua' => 0
];

foreach ($pengumuman_list as $p) {
    if (isset($stats_target[$p['target']])) {
        $stats_target[$p['target']]++;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengumuman - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Dropdown styles */
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
            transform: rotate(90deg);
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
        
        /* Responsive */
        @media (min-width: 768px) {
            .desktop-sidebar { display: block; }
            .mobile-header { display: none; }
            #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }
        @media (max-width: 767px) {
            .desktop-sidebar { display: none; }
        }
        
        /* Custom styles for pengumuman */
        .truncate-2-lines {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background-color: white;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body {
            padding: 1.5rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Image preview */
        .image-preview {
            max-width: 100%;
            max-height: 200px;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
        }
        
        /* Badge untuk target */
        .badge-target {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .badge-orangtua {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .badge-guru {
            background-color: #f0f9ff;
            color: #0c4a6e;
        }
        .badge-semua {
            background-color: #f0fdf4;
            color: #166534;
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
            <?php 
            ob_start();
            echo renderMenu($currentPage, 'admin');
            ob_end_flush();
            ?>
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
                <?php 
                ob_start();
                echo renderMenu($currentPage, 'admin');
                ob_end_flush();
                ?>
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
                    <h1 class="text-2xl font-bold text-gray-800">Pengumuman</h1>
                    <p class="text-gray-600">Kelola pengumuman untuk orangtua, guru, atau semua pengguna</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <button id="openModalBtn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300">
                        <i class="fas fa-plus mr-2"></i> Buat Pengumuman Baru
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Message Alert -->
            <?php if ($message): ?>
            <div class="mb-4 p-4 rounded-lg <?php echo $message_type == 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <div class="flex items-center">
                    <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Total Pengumuman -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-bullhorn text-blue-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Pengumuman</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo count($pengumuman_list); ?></h3>
                        </div>
                    </div>
                </div>
                
                <!-- Publik -->
                <div class="stat-card bg-white p-5 rounded-xl shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-eye text-green-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Sudah Dipublikasi</p>
                            <?php 
                                $publik_count = 0;
                                foreach ($pengumuman_list as $p) {
                                    if ($p['status'] == 'publik') $publik_count++;
                                }
                            ?>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $publik_count; ?></h3>
                        </div>
                    </div>
                </div>
                
                <!-- Draft -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-file-alt text-yellow-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Draft</p>
                            <?php 
                                $draft_count = 0;
                                foreach ($pengumuman_list as $p) {
                                    if ($p['status'] == 'draft') $draft_count++;
                                }
                            ?>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $draft_count; ?></h3>
                        </div>
                    </div>
                </div>
                
                <!-- Aktif Sekarang -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-clock text-purple-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Sedang Aktif</p>
                            <?php 
                                $aktif_count = 0;
                                $now = date('Y-m-d H:i:s');
                                foreach ($pengumuman_list as $p) {
                                    if ($p['status'] == 'publik' && 
                                        $p['ditampilkan_dari'] <= $now && 
                                        ($p['ditampilkan_sampai'] == null || $p['ditampilkan_sampai'] >= $now)) {
                                        $aktif_count++;
                                    }
                                }
                            ?>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $aktif_count; ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Berdasarkan Target -->
            <div class="stat-card bg-white rounded-xl p-5 mb-5 shadow">
                <div class="flex items-center">
                    <div class="p-3 bg-indigo-100 rounded-lg mr-3 md:mr-4">
                        <i class="fas fa-users text-indigo-600 text-xl md:text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm md:text-base">Target Pengguna</p>
                        <div class="flex space-x-2 mt-1">
                            <span class="badge-target badge-semua">Semua: <?php echo $stats_target['semua']; ?></span>
                            <span class="badge-target badge-orangtua">Orangtua: <?php echo $stats_target['orangtua']; ?></span>
                            <span class="badge-target badge-guru">Guru: <?php echo $stats_target['guru']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabel Pengumuman -->
            <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-list mr-2"></i> Daftar Pengumuman
                    </h3>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Judul</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Target</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tampil Dari</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tampil Sampai</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dibuat Oleh</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($pengumuman_list) > 0): ?>
                                <?php foreach ($pengumuman_list as $pengumuman): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <?php if (!empty($pengumuman['gambar'])): ?>
                                                    <div class="flex-shrink-0 h-10 w-10 mr-3">
                                                        <img class="h-10 w-10 rounded object-cover" 
                                                             src="../uploads/pengumuman/<?php echo htmlspecialchars($pengumuman['gambar']); ?>" 
                                                             alt="Gambar pengumuman">
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($pengumuman['judul']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 truncate-2-lines max-w-xs">
                                                        <?php echo htmlspecialchars(substr($pengumuman['isi'], 0, 100)); ?>...
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="badge-target badge-<?php echo htmlspecialchars($pengumuman['target']); ?>">
                                                <?php echo htmlspecialchars($pengumuman['target_label']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php echo $pengumuman['status'] == 'publik' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo $pengumuman['status'] == 'publik' ? 'Publik' : 'Draft'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $pengumuman['ditampilkan_dari_formatted']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $pengumuman['ditampilkan_sampai_formatted']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($pengumuman['pembuat']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="#" 
                                                   onclick="openEditModal(<?php echo $pengumuman['id']; ?>)"
                                                   class="text-blue-600 hover:text-blue-900"
                                                   title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?toggle_status=<?php echo $pengumuman['id']; ?>" 
                                                   class="<?php echo $pengumuman['status'] == 'publik' ? 'text-yellow-600 hover:text-yellow-900' : 'text-green-600 hover:text-green-900'; ?>"
                                                   title="<?php echo $pengumuman['status'] == 'publik' ? 'Simpan sebagai draft' : 'Publikasikan'; ?>">
                                                    <i class="fas <?php echo $pengumuman['status'] == 'publik' ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                </a>
                                                <a href="#" 
                                                   onclick="confirmDelete(<?php echo $pengumuman['id']; ?>, '<?php echo htmlspecialchars(addslashes($pengumuman['judul'])); ?>')"
                                                   class="text-red-600 hover:text-red-900"
                                                   title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-bullhorn text-3xl mb-3 text-gray-400"></i>
                                            <p class="text-lg mb-2">Belum ada pengumuman</p>
                                            <p class="text-sm text-gray-400 mb-4">Buat pengumuman pertama Anda</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Info Box -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0 mt-1">
                        <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-800">Informasi Fitur Pengumuman</h3>
                        <div class="mt-1 text-sm text-blue-700">
                            <ul class="list-disc pl-5 space-y-1">
                                <li>Klik tombol <strong>"Buat Pengumuman Baru"</strong> untuk menambahkan pengumuman</li>
                                <li>Pilih <strong>Target</strong> pengumuman: Orangtua, Guru, atau Semua</li>
                                <li>Klik ikon <i class="fas fa-edit text-blue-600"></i> untuk mengedit pengumuman</li>
                                <li>Pengumuman hanya akan ditampilkan jika status <strong>Publik</strong></li>
                                <li>Pengumuman akan otomatis muncul/hilang sesuai jadwal tampil</li>
                                <li>Gambar pengumuman opsional (max 2MB, format: JPG, PNG)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Pengumuman Admin</p>
                        <p class="mt-1 text-xs text-gray-400">
                            <i class="fas fa-bullhorn mr-1"></i>
                            Total: <?php echo count($pengumuman_list); ?> pengumuman
                        </p>
                    </div>
                    <div class="mt-3 md:mt-0">
                        <div class="flex items-center space-x-4">
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <i class="fas fa-clock mr-1"></i>
                                <span id="serverTime"><?php echo date('H:i:s'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <!-- ============================
    MODAL TAMBAH PENGUMUMAN
    ============================= -->
    <div id="tambahPengumumanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold text-gray-800">Buat Pengumuman Baru</h2>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" id="pengumumanForm" class="modal-body space-y-4">
                <input type="hidden" name="action" value="tambah">
                
                <!-- Judul -->
                <div>
                    <label for="modal_judul" class="block text-sm font-medium text-gray-700 mb-1">
                        Judul Pengumuman <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="modal_judul" name="judul" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Masukkan judul pengumuman">
                </div>
                
                <!-- Isi Pengumuman -->
                <div>
                    <label for="modal_isi" class="block text-sm font-medium text-gray-700 mb-1">
                        Isi Pengumuman <span class="text-red-500">*</span>
                    </label>
                    <textarea id="modal_isi" name="isi" rows="6" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Tulis isi pengumuman disini..."></textarea>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Gambar -->
                    <div>
                        <label for="modal_gambar" class="block text-sm font-medium text-gray-700 mb-1">
                            Gambar (Opsional)
                        </label>
                        <div class="mt-1 border border-gray-300 rounded-md p-4">
                            <div class="flex items-center justify-center">
                                <div class="text-center">
                                    <div class="flex text-sm text-gray-600">
                                        <label for="modal_gambar" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500">
                                            <i class="fas fa-cloud-upload-alt mr-2"></i>
                                            <span>Upload gambar</span>
                                            <input id="modal_gambar" name="gambar" type="file" class="sr-only" accept="image/*">
                                        </label>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">
                                        PNG, JPG, GIF maksimal 2MB
                                    </p>
                                </div>
                            </div>
                            <div id="fileNameDisplay" class="text-sm text-gray-600 mt-2 text-center hidden"></div>
                        </div>
                    </div>
                    
                    <!-- Informasi -->
                    <div class="space-y-4">
                        <!-- Target -->
                        <div>
                            <label for="modal_target" class="block text-sm font-medium text-gray-700 mb-1">
                                Target Pengumuman <span class="text-red-500">*</span>
                            </label>
                            <select id="modal_target" name="target" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="orangtua" selected>Orangtua Siswa</option>
                                <option value="guru">Guru</option>
                                <option value="semua">Semua Pengguna (Orangtua & Guru)</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
                                Pilih kepada siapa pengumuman ini akan ditampilkan
                            </p>
                        </div>
                        
                        <!-- Status -->
                        <div>
                            <label for="modal_status" class="block text-sm font-medium text-gray-700 mb-1">
                                Status
                            </label>
                            <select id="modal_status" name="status" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="draft" selected>Simpan sebagai Draft</option>
                                <option value="publik">Publikasikan Sekarang</option>
                            </select>
                        </div>
                        
                        <!-- Tampil Dari -->
                        <div>
                            <label for="modal_ditampilkan_dari" class="block text-sm font-medium text-gray-700 mb-1">
                                Tampilkan Dari <span class="text-red-500">*</span>
                            </label>
                            <input type="datetime-local" id="modal_ditampilkan_dari" name="ditampilkan_dari" required
                                   value="<?php echo date('Y-m-d\TH:i'); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <!-- Tampil Sampai -->
                        <div>
                            <label for="modal_ditampilkan_sampai" class="block text-sm font-medium text-gray-700 mb-1">
                                Tampilkan Sampai (Opsional)
                            </label>
                            <input type="datetime-local" id="modal_ditampilkan_sampai" name="ditampilkan_sampai"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- Informasi Tambahan -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 mt-0.5">
                            <i class="fas fa-info-circle text-blue-500"></i>
                        </div>
                        <div class="ml-2">
                            <h3 class="text-sm font-medium text-blue-800">Catatan</h3>
                            <p class="text-xs text-blue-700 mt-1">
                                Pengumuman akan ditampilkan sesuai target yang dipilih. Jika status Draft, pengumuman tidak akan ditampilkan.
                                Jika tanggal "Sampai" dikosongkan, pengumuman akan tetap aktif.
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Loading Indicator -->
                <div id="loadingIndicator" class="hidden text-center">
                    <div class="spinner mx-auto mb-2"></div>
                    <p class="text-sm text-gray-600">Menyimpan pengumuman...</p>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" id="cancelModalBtn" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition">
                    Batal
                </button>
                <button type="button" id="saveDraftBtn" class="px-4 py-2 bg-yellow-500 text-white rounded-md hover:bg-yellow-600 transition">
                    Simpan Draft
                </button>
                <button type="button" id="savePublishBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                    Simpan & Publikasikan
                </button>
            </div>
        </div>
    </div>

    <!-- ============================
    MODAL EDIT PENGUMUMAN (POPUP)
    ============================= -->
    <div id="editPengumumanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold text-gray-800">Edit Pengumuman</h2>
                <button id="closeEditModalBtn" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="editModalContent" class="modal-body">
                <!-- Content akan diisi oleh JavaScript -->
                <div class="text-center py-8">
                    <div class="spinner mx-auto mb-4"></div>
                    <p class="text-sm text-gray-600">Memuat data pengumuman...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Konfirmasi Delete Modal -->
    <div id="confirmDeleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden">
        <div class="relative top-20 mx-auto p-5 border w-26 h-[280px] shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900 text-center mb-2">Hapus Pengumuman</h3>
                <div class="px-7 py-3">
                    <p class="text-sm text-gray-500 text-center mb-4" id="deleteMessage">
                        Apakah Anda yakin ingin menghapus pengumuman ini?
                    </p>
                </div>
                <div class="items-center px-4 py-3 flex justify-center space-x-4">
                    <button id="cancelDeleteBtn" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition">
                        Batal
                    </button>
                    <a href="#" id="confirmDeleteBtn" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition">
                        Hapus
                    </a>
                </div>
            </div>
        </div>
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
                
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => {
                        sm.style.display = 'none';
                    });
                    document.querySelectorAll('.dropdown-toggle').forEach(t => {
                        t.classList.remove('open');
                        t.querySelector('.arrow').style.transform = 'rotate(0deg)';
                    });
                    
                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });
        
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
        
        // Update server time
        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('serverTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        setInterval(updateServerTime, 1000);
        updateServerTime();
        
        // ============================
        // MODAL FUNCTIONS
        // ============================
        
        const tambahModal = document.getElementById('tambahPengumumanModal');
        const editModal = document.getElementById('editPengumumanModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const openModalBtnEmpty = document.getElementById('openModalBtnEmpty');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const closeEditModalBtn = document.getElementById('closeEditModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const saveDraftBtn = document.getElementById('saveDraftBtn');
        const savePublishBtn = document.getElementById('savePublishBtn');
        const pengumumanForm = document.getElementById('pengumumanForm');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const statusSelect = document.getElementById('modal_status');
        const fileInput = document.getElementById('modal_gambar');
        const fileNameDisplay = document.getElementById('fileNameDisplay');
        
        // Open tambah modal
        if (openModalBtn) {
            openModalBtn.addEventListener('click', openTambahModal);
        }
        
        if (openModalBtnEmpty) {
            openModalBtnEmpty.addEventListener('click', openTambahModal);
        }
        
        // Close tambah modal
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', closeTambahModal);
        }
        
        if (cancelModalBtn) {
            cancelModalBtn.addEventListener('click', closeTambahModal);
        }
        
        // Close edit modal
        if (closeEditModalBtn) {
            closeEditModalBtn.addEventListener('click', closeEditModal);
        }
        
        // Close modal when clicking outside
        tambahModal.addEventListener('click', function(e) {
            if (e.target === tambahModal) {
                closeTambahModal();
            }
        });
        
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) {
                closeEditModal();
            }
        });
        
        // File input change handler
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    fileNameDisplay.textContent = `File: ${file.name}`;
                    fileNameDisplay.classList.remove('hidden');
                } else {
                    fileNameDisplay.classList.add('hidden');
                }
            });
        }
        
        // Save as draft
        if (saveDraftBtn) {
            saveDraftBtn.addEventListener('click', function(e) {
                e.preventDefault();
                submitTambahForm('draft');
            });
        }
        
        // Save and publish
        if (savePublishBtn) {
            savePublishBtn.addEventListener('click', function(e) {
                e.preventDefault();
                submitTambahForm('publik');
            });
        }
        
        function openTambahModal() {
            tambahModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Reset form
            if (pengumumanForm) {
                pengumumanForm.reset();
                
                // Set default datetime to now
                const now = new Date();
                const localDateTime = now.toISOString().slice(0, 16);
                const dateTimeInput = document.getElementById('modal_ditampilkan_dari');
                if (dateTimeInput) {
                    dateTimeInput.value = localDateTime;
                }
                
                // Set default target to 'orangtua' sesuai database
                const targetSelect = document.getElementById('modal_target');
                if (targetSelect) {
                    targetSelect.value = 'orangtua';
                }
                
                // Clear file name display
                if (fileNameDisplay) {
                    fileNameDisplay.classList.add('hidden');
                }
                
                // Reset status dropdown to draft
                if (statusSelect) {
                    statusSelect.value = 'draft';
                }
            }
        }
        
        function closeTambahModal() {
            tambahModal.classList.remove('active');
            document.body.style.overflow = 'auto';
            if (loadingIndicator) {
                loadingIndicator.classList.add('hidden');
            }
            // Enable buttons when closing modal
            if (saveDraftBtn) saveDraftBtn.disabled = false;
            if (savePublishBtn) savePublishBtn.disabled = false;
            if (cancelModalBtn) cancelModalBtn.disabled = false;
        }
        
        function closeEditModal() {
            editModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function submitTambahForm(status) {
            // Show loading
            if (loadingIndicator) {
                loadingIndicator.classList.remove('hidden');
            }
            
            // Set status before submit
            if (statusSelect) {
                statusSelect.value = status;
            }
            
            // Disable buttons
            if (saveDraftBtn) saveDraftBtn.disabled = true;
            if (savePublishBtn) savePublishBtn.disabled = true;
            if (cancelModalBtn) cancelModalBtn.disabled = true;
            
            // Submit form
            setTimeout(() => {
                if (pengumumanForm) {
                    pengumumanForm.submit();
                }
            }, 100);
        }
        
        // ============================
        // EDIT MODAL FUNCTIONS
        // ============================
        
        function openEditModal(id) {
            editModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Show loading
            const editModalContent = document.getElementById('editModalContent');
            editModalContent.innerHTML = `
                <div class="text-center py-8">
                    <div class="spinner mx-auto mb-4"></div>
                    <p class="text-sm text-gray-600">Memuat data pengumuman...</p>
                </div>
            `;
            
            // Load edit form via AJAX
            fetch(`get_pengumuman.php?id=${id}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    editModalContent.innerHTML = html;
                    initEditForm();
                })
                .catch(error => {
                    console.error('Error loading edit form:', error);
                    editModalContent.innerHTML = `
                        <div class="text-center py-8">
                            <i class="fas fa-exclamation-triangle text-red-500 text-3xl mb-3"></i>
                            <p class="text-sm text-gray-600">Gagal memuat data pengumuman</p>
                            <p class="text-xs text-gray-500 mb-4">${error.message}</p>
                            <button onclick="closeEditModal()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                Tutup
                            </button>
                        </div>
                    `;
                });
        }
        
        function initEditForm() {
            const form = document.getElementById('editPengumumanForm');
            if (!form) return;
            
            // File preview for new image
            const fileInput = form.querySelector('input[name="gambar"]');
            if (fileInput) {
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            // Show preview
                            let preview = form.querySelector('#newImagePreview');
                            if (!preview) {
                                preview = document.createElement('img');
                                preview.id = 'newImagePreview';
                                preview.className = 'image-preview mt-3';
                                fileInput.parentNode.appendChild(preview);
                            }
                            preview.src = e.target.result;
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }
            
            // Form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitBtn = form.querySelector('button[type="submit"]');
                
                // Show loading
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Menyimpan...';
                }
                
                // Submit form
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        // Close modal and reload page
                        closeEditModal();
                        window.location.reload();
                    } else {
                        throw new Error('Update failed');
                    }
                })
                .catch(error => {
                    alert('Gagal menyimpan perubahan: ' + error.message);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Simpan Perubahan';
                    }
                });
            });
        }
        
        // ============================
        // DELETE FUNCTIONS
        // ============================
        
        // Confirm delete function
        function confirmDelete(id, title) {
            const modal = document.getElementById('confirmDeleteModal');
            const deleteMessage = document.getElementById('deleteMessage');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
            
            deleteMessage.textContent = `Apakah Anda yakin ingin menghapus pengumuman "${title}"?`;
            confirmDeleteBtn.href = `?delete=${id}`;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            cancelDeleteBtn.onclick = function() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            };
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            };
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape to close modal
            if (e.key === 'Escape') {
                if (tambahModal.classList.contains('active')) {
                    closeTambahModal();
                }
                if (editModal.classList.contains('active')) {
                    closeEditModal();
                }
            }
            
            // Ctrl+Shift+N to open tambah modal
            if (e.ctrlKey && e.shiftKey && e.key === 'N') {
                e.preventDefault();
                if (!tambahModal.classList.contains('active')) {
                    openTambahModal();
                }
            }
        });
    </script>
</body>
</html>