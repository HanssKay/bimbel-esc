<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php';

// Cek apakah user sudah login dan role guru
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    header('Location: ../auth/login.php');
    exit();
}

// Inisialisasi session untuk notifikasi jika belum ada
if (!isset($_SESSION['notifikasi_dibuka'])) {
    $_SESSION['notifikasi_dibuka'] = false;
}

// Tangani permintaan untuk menandai semua notifikasi sebagai sudah dilihat
if (isset($_GET['lihat_notif']) && $_GET['lihat_notif'] == 1) {
    $_SESSION['notifikasi_dibuka'] = true;
    $_SESSION['notifikasi_terakhir_dibuka'] = time();
    
    // Redirect untuk menghindari duplikasi
    $current_url = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $current_url);
    exit();
}

// AMBIL DATA GURU DARI SESSION
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Guru';
$email = $_SESSION['email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL ID GURU DARI TABLE GURU
$guru_id = 0;
$nama_guru = '';
$bidang_keahlian = '';
try {
    $sql_guru = "SELECT id, bidang_keahlian FROM guru WHERE user_id = ?";
    $stmt_guru = $conn->prepare($sql_guru);
    $stmt_guru->bind_param("i", $user_id);
    $stmt_guru->execute();
    $result_guru = $stmt_guru->get_result();
    if ($row_guru = $result_guru->fetch_assoc()) {
        $guru_id = $row_guru['id'];
        $bidang_keahlian = $row_guru['bidang_keahlian'] ?? '';
        $nama_guru = $full_name; // Gunakan full_name dari session
    }
    $stmt_guru->close();
} catch (Exception $e) {
    error_log("Error fetching guru data: " . $e->getMessage());
}

// Ambil data siswa yang diajar oleh guru ini
$siswa_data = [];
if ($guru_id > 0) {
    // Query siswa yang diajar oleh guru ini melalui siswa_pelajaran
    $sql_siswa = "SELECT DISTINCT s.id, s.nama_lengkap, s.kelas, sp.nama_pelajaran
                  FROM siswa s 
                  INNER JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
                  WHERE sp.guru_id = ? AND sp.status = 'aktif'
                  ORDER BY s.nama_lengkap";
    $stmt_siswa = $conn->prepare($sql_siswa);
    if ($stmt_siswa) {
        $stmt_siswa->bind_param("i", $guru_id);
        $stmt_siswa->execute();
        $result_siswa = $stmt_siswa->get_result();
        while ($row = $result_siswa->fetch_assoc()) {
            $siswa_data[] = $row;
        }
        $stmt_siswa->close();
    }
}

// Filter pengumuman (hanya yang aktif/publik untuk guru)
$current_date = date('Y-m-d H:i:s');
$pengumuman_list = [];

// Query sesuai struktur tabel pengumuman - untuk guru lihat pengumuman 'guru' atau 'semua'
$sql = "SELECT p.*, u.full_name as pembuat 
        FROM pengumuman p 
        JOIN users u ON p.dibuat_oleh = u.id 
        WHERE p.status = 'publik' 
        AND (p.target = 'guru' OR p.target = 'semua')
        AND p.ditampilkan_dari <= ?
        AND (p.ditampilkan_sampai IS NULL OR p.ditampilkan_sampai >= ?)
        ORDER BY p.ditampilkan_dari DESC 
        LIMIT 20";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ss", $current_date, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Format tanggal
        $row['ditampilkan_dari_formatted'] = date('d M Y, H:i', strtotime($row['ditampilkan_dari']));
        $row['dibuat_pada_formatted'] = date('d M Y', strtotime($row['dibuat_pada']));
        
        // Hitung apakah pengumuman baru (dibuat dalam 3 hari terakhir)
        $row['is_new'] = strtotime($row['dibuat_pada']) > strtotime('-3 days');
        
        // Get image URL - PERBAIKAN: path file gambar
        if (!empty($row['gambar'])) {
            $image_path = '../uploads/pengumuman/' . $row['gambar'];
            // Cek apakah file ada di server
            if (file_exists($image_path)) {
                $row['image_url'] = $image_path;
            } else {
                $row['image_url'] = ''; // Kosongkan jika file tidak ditemukan
            }
        } else {
            $row['image_url'] = '';
        }
        
        $pengumuman_list[] = $row;
    }
    $stmt->close();
} else {
    error_log("Error preparing pengumuman query: " . $conn->error);
}

// Hitung total pengumuman baru (dalam 3 hari terakhir)
$total_pengumuman_baru = 0;
foreach ($pengumuman_list as $p) {
    if ($p['is_new']) {
        $total_pengumuman_baru++;
    }
}

// Hitung total pengumuman
$total_pengumuman = count($pengumuman_list);

// Cek apakah ada notifikasi baru yang belum dilihat
$ada_notifikasi_baru = $total_pengumuman_baru > 0 && !$_SESSION['notifikasi_dibuka'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengumuman - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom styles */
        .pengumuman-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .pengumuman-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-left-color: #3b82f6;
        }
        
        .pengumuman-card.penting {
            border-left-color: #ef4444;
            background: linear-gradient(135deg, #fef2f2 0%, #fff 100%);
        }
        
        .pengumuman-card.terbaru {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4 0%, #fff 100%);
        }
        
        .image-container {
            position: relative;
            overflow: hidden;
            border-radius: 0.5rem;
        }
        
        .image-container img {
            transition: transform 0.5s ease;
        }
        
        .image-container:hover img {
            transform: scale(1.05);
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-sangat-baik {
            background-color: #10B981;
            color: white;
        }

        .badge-baik {
            background-color: #3B82F6;
            color: white;
        }

        .badge-cukup {
            background-color: #F59E0B;
            color: white;
        }

        .badge-kurang {
            background-color: #EF4444;
            color: white;
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
            padding: 1rem;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 1rem;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease;
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

        /* Mobile Menu Styles */
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

        /* Desktop sidebar tetap terlihat */
        .desktop-sidebar {
            display: none;
        }

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

        /* Menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
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

        /* Notification badge animation */
        @keyframes ping {
            75%, 100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }

        .animate-ping {
            animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Guru</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($nama_guru ?: $full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                </div>
            </div>
            <!--<div class="mt-3 text-sm">-->
            <!--    <p><i class="fas fa-book mr-2"></i> <?php echo htmlspecialchars($bidang_keahlian); ?></p>-->
            <!--    <p><i class="fas fa-users mr-2"></i> <?php echo count($siswa_data); ?> Siswa</p>-->
            <!--</div>-->
        </div>
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'guru'); ?>
        </nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 w-full fixed top-0 z-30 md:hidden">
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="text-white mr-3">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Pengumuman</h1>
            </div>
            <div class="flex items-center">
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-tie"></i>
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
                        <i class="fas fa-user-tie text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($nama_guru ?: $full_name); ?></p>
                        <p class="text-sm text-blue-300">Guru</p>
                        <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                </div>
                <!--<div class="mt-3 text-sm">-->
                <!--    <p><i class="fas fa-book mr-2"></i> <?php echo htmlspecialchars($bidang_keahlian); ?></p>-->
                <!--    <p><i class="fas fa-users mr-2"></i> <?php echo count($siswa_data); ?> Siswa</p>-->
                <!--</div>-->
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'guru'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <!-- Mobile header spacer -->
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <header class="bg-white shadow">
            <div class="container mx-auto px-4 py-4">
                <div class="flex justify-between">
                    <div class="flex items-center mb-4 md:mb-0">
                        <div class="md:hidden mr-4">
                            <!-- Space for mobile -->
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Pengumuman</h1>
                            <p class="text-gray-600">Informasi terbaru dari Bimbel Esc untuk Guru</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <!-- Notification bell -->
                        <div class="relative">
                            <button id="notificationBtn" class="p-2 text-gray-600 hover:text-blue-600 relative">
                                <i class="fas fa-bell text-xl"></i>
                                <?php if ($ada_notifikasi_baru): ?>
                                <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                                    <?php echo min($total_pengumuman_baru, 9); ?>
                                </span>
                                <?php endif; ?>
                            </button>
                            
                            <!-- Notification dropdown -->
                            <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-72 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                                <div class="p-4 border-b">
                                    <h3 class="font-semibold text-gray-800">Pengumuman Terbaru</h3>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php 
                                        if ($_SESSION['notifikasi_dibuka']) {
                                            echo 'Sudah dilihat';
                                        } else {
                                            echo $total_pengumuman_baru . ' belum dilihat';
                                        }
                                        ?>
                                    </p>
                                </div>
                                <div class="max-h-64 overflow-y-auto" id="notificationList">
                                    <?php 
                                    $new_pengumuman_count = 0;
                                    
                                    if ($total_pengumuman_baru > 0): 
                                        foreach ($pengumuman_list as $p): 
                                            if ($p['is_new']):
                                                $new_pengumuman_count++;
                                    ?>
                                    <div class="p-3 border-b notification-item <?php echo $_SESSION['notifikasi_dibuka'] ? 'seen' : 'unseen'; ?>">
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0 mt-1">
                                                <i class="fas fa-bullhorn <?php echo $_SESSION['notifikasi_dibuka'] ? 'text-gray-400' : 'text-blue-500'; ?>"></i>
                                            </div>
                                            <div class="ml-3 flex-1">
                                                <p class="text-sm font-medium <?php echo $_SESSION['notifikasi_dibuka'] ? 'text-gray-600' : 'text-gray-800'; ?>">
                                                    <?php echo htmlspecialchars($p['judul']); ?>
                                                </p>
                                                <p class="text-xs text-gray-500 mt-1">
                                                    <?php echo $p['ditampilkan_dari_formatted']; ?>
                                                </p>
                                                <?php if (!$_SESSION['notifikasi_dibuka']): ?>
                                                <span class="inline-block mt-1 px-2 py-1 text-xs bg-green-100 text-green-800 rounded">
                                                    <i class="fas fa-star mr-1"></i>Baru
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php 
                                            endif;
                                            if ($new_pengumuman_count >= 5) break;
                                        endforeach; 
                                    ?>
                                    <?php elseif ($total_pengumuman_baru == 0): ?>
                                    <div class="p-4 text-center text-gray-500">
                                        <i class="fas fa-bell-slash text-2xl mb-2"></i>
                                        <p class="text-sm">Belum ada pengumuman baru</p>
                                        <p class="text-xs mt-1">Semua pengumuman sudah dilihat</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="p-3 border-t">
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Notifikasi akan hilang setelah dropdown dibuka
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main class="container mx-auto px-4 py-6">
            <!-- Stats Banner -->
            <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-white bg-opacity-20 rounded-lg mr-4">
                            <i class="fas fa-bullhorn text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Total Pengumuman</p>
                            <h3 class="text-2xl font-bold"><?php echo $total_pengumuman; ?></h3>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-white bg-opacity-20 rounded-lg mr-4">
                            <i class="fas fa-calendar-check text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Aktif Hari Ini</p>
                            <?php
                            $today = date('Y-m-d');
                            $today_count = 0;
                            foreach ($pengumuman_list as $p) {
                                $p_date = date('Y-m-d', strtotime($p['ditampilkan_dari']));
                                if ($p_date == $today) $today_count++;
                            }
                            ?>
                            <h3 class="text-2xl font-bold"><?php echo $today_count; ?></h3>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-white bg-opacity-20 rounded-lg mr-4">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Siswa Anda</p>
                            <h3 class="text-2xl font-bold"><?php echo count($siswa_data); ?></h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Content -->
            <div class="w-full">
                <!-- Main Pengumuman List -->
                <div class="w-full">
                    <div class="mb-4 flex justify-between items-center">
                        <h2 class="text-xl font-bold text-gray-800">
                            <i class="fas fa-newspaper mr-2"></i> Daftar Pengumuman
                        </h2>
                        <div class="text-sm text-gray-600">
                            <span id="showingCount"><?php echo $total_pengumuman; ?></span> dari <?php echo $total_pengumuman; ?> pengumuman
                        </div>
                    </div>
                    
                    <!-- Empty State -->
                    <?php if ($total_pengumuman == 0): ?>
                    <div class="text-center py-12 bg-white rounded-xl shadow">
                        <i class="fas fa-bullhorn text-5xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-600 mb-2">Belum ada pengumuman</h3>
                        <p class="text-gray-500 mb-6">Tidak ada pengumuman yang aktif saat ini.</p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Pengumuman Cards -->
                    <div id="pengumumanContainer">
                        <?php foreach ($pengumuman_list as $index => $pengumuman): ?>
                        <?php 
                        $is_new = $pengumuman['is_new'];
                        $is_today = date('Y-m-d', strtotime($pengumuman['ditampilkan_dari'])) == date('Y-m-d');
                        $card_class = 'pengumuman-card bg-white rounded-xl shadow p-5 mb-6 ';
                        $card_class .= $is_new ? 'terbaru ' : '';
                        $card_class .= $is_today ? 'penting ' : '';
                        ?>
                        <div class="<?php echo $card_class; ?>" 
                             data-id="<?php echo $pengumuman['id']; ?>"
                             data-date="<?php echo date('Y-m-d', strtotime($pengumuman['ditampilkan_dari'])); ?>"
                             data-title="<?php echo htmlspecialchars(strtolower($pengumuman['judul'])); ?>"
                             data-content="<?php echo htmlspecialchars(strtolower(strip_tags($pengumuman['isi']))); ?>"
                             data-new="<?php echo $is_new ? 'true' : 'false'; ?>"
                             data-today="<?php echo $is_today ? 'true' : 'false'; ?>">
                            
                            <!-- Card Header -->
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <?php if ($is_new): ?>
                                        <span class="badge badge-success mr-2">
                                            <i class="fas fa-star mr-1"></i> Baru
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($is_today): ?>
                                        <span class="badge badge-warning mr-2">
                                            <i class="fas fa-bolt mr-1"></i> Hari Ini
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($pengumuman['target']): ?>
                                        <span class="badge badge-info">
                                            <i class="fas fa-user-tag mr-1"></i>
                                            <?php 
                                            if ($pengumuman['target'] == 'semua') {
                                                echo 'Semua';
                                            } elseif ($pengumuman['target'] == 'guru') {
                                                echo 'Guru';
                                            } elseif ($pengumuman['target'] == 'orangtua') {
                                                echo 'Orang Tua';
                                            }
                                            ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <h3 class="text-xl font-bold text-gray-800 mb-2">
                                        <?php echo htmlspecialchars($pengumuman['judul']); ?>
                                    </h3>
                                    
                                    <div class="flex items-center text-sm text-gray-500 mb-4">
                                        <i class="fas fa-user-edit mr-1"></i>
                                        <span class="mr-3"><?php echo htmlspecialchars($pengumuman['pembuat']); ?></span>
                                        
                                        <i class="fas fa-clock mr-1"></i>
                                        <span><?php echo $pengumuman['ditampilkan_dari_formatted']; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Card Body -->
                            <div class="mb-4">
                                <?php if (!empty($pengumuman['image_url'])): ?>
                                <div class="image-container mb-4">
                                    <img src="<?php echo $pengumuman['image_url']; ?>" 
                                         alt="Gambar pengumuman" 
                                         class="md:w-1/4 w-full h-40 object-contain rounded-lg cursor-pointer view-image"
                                         data-src="<?php echo $pengumuman['image_url']; ?>"
                                         data-caption="<?php echo htmlspecialchars($pengumuman['judul']); ?>">
                                </div>
                                <?php endif; ?>
                                
                                <div class="prose max-w-none text-gray-700">
                                    <?php echo nl2br(htmlspecialchars($pengumuman['isi'])); ?>
                                </div>
                            </div>
                            
                            <!-- Card Footer -->
                            <div class="pt-4 border-t border-gray-100">
                                <div class="text-xs text-gray-500">
                                    <i class="far fa-calendar mr-1"></i>
                                    Dibuat: <?php echo $pengumuman['dibuat_pada_formatted']; ?>
                                    
                                    <?php if (!empty($pengumuman['diupdate_pada'])): ?>
                                    | <i class="fas fa-sync-alt mr-1"></i>
                                    Diperbarui: <?php echo date('d M Y', strtotime($pengumuman['diupdate_pada'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-12">
            <div class="container mx-auto px-4 py-6">
                <div class="flex flex-col md:flex-row justify-between items-center">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Pengumuman</p>
                        <p class="mt-1 text-xs">
                            <i class="fas fa-sync-alt mr-1"></i>
                            Diperbarui: <?php echo date('d M Y H:i'); ?>
                        </p>
                    </div>
                    <div class="text-sm text-gray-500 mt-2 md:mt-0">
                        <p>Guru: <?php echo htmlspecialchars($nama_guru); ?> | Siswa: <?php echo count($siswa_data); ?></p>
                    </div>
                </div>
            </div>
        </footer>
    </div>
    
    <!-- ============================
    MODAL IMAGE VIEWER
    ============================= -->
    <div id="imageModal" class="modal">
        <div class="modal-content max-w-4xl bg-black bg-opacity-90">
            <div class="modal-header bg-black bg-opacity-75">
                <h2 class="text-xl font-bold text-white" id="imageTitle"></h2>
                <button class="close-image-modal text-white hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="flex items-center justify-center min-h-[60vh]">
                    <img id="modalImage" src="" alt="" class="max-w-full max-h-[70vh] object-contain">
                </div>
            </div>
            <div class="modal-footer bg-black bg-opacity-75 text-white text-center py-3">
                <button id="downloadImageBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 mr-2">
                    <i class="fas fa-download mr-2"></i> Unduh
                </button>
                <button id="zoomInBtn" class="px-4 py-2 bg-gray-700 text-white rounded-md hover:bg-gray-600 mr-2">
                    <i class="fas fa-search-plus"></i>
                </button>
                <button id="zoomOutBtn" class="px-4 py-2 bg-gray-700 text-white rounded-md hover:bg-gray-600">
                    <i class="fas fa-search-minus"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        // DOM Elements
        const menuToggle = document.getElementById('menuToggle');
        const menuClose = document.getElementById('menuClose');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuOverlay = document.getElementById('menuOverlay');
        
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const viewImages = document.querySelectorAll('.view-image');
        
        // Modal elements
        const imageModal = document.getElementById('imageModal');
        const closeImageModal = document.querySelector('.close-image-modal');
        
        // State untuk notifikasi
        let notificationsMarkedAsRead = <?php echo $_SESSION['notifikasi_dibuka'] ? 'true' : 'false'; ?>;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile Menu Toggle
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

            // Close menu when clicking on menu items
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', () => {
                    mobileMenu.classList.remove('menu-open');
                    menuOverlay.classList.remove('active');
                    document.body.style.overflow = 'auto';
                });
            });

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
            
            // Notification toggle - Langsung tandai sebagai sudah dibuka saat dropdown terbuka
            if (notificationBtn) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    
                    // Toggle dropdown
                    notificationDropdown.classList.toggle('hidden');
                    
                    // Jika dropdown terbuka DAN belum ditandai sebagai sudah dibaca
                    if (!notificationDropdown.classList.contains('hidden') && !notificationsMarkedAsRead) {
                        markNotificationsAsRead();
                    }
                });
            }
            
            // Image viewers
            viewImages.forEach(img => {
                img.addEventListener('click', function() {
                    const src = this.dataset.src;
                    const caption = this.dataset.caption;
                    showImageModal(src, caption);
                });
            });
            
            // Modal close buttons
            if (closeImageModal) {
                closeImageModal.addEventListener('click', closeImageModalFunc);
            }
            
            // Close modal on outside click
            imageModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeImageModalFunc();
                }
            });
            
            // Image modal controls
            document.getElementById('downloadImageBtn')?.addEventListener('click', downloadImage);
            document.getElementById('zoomInBtn')?.addEventListener('click', zoomInImage);
            document.getElementById('zoomOutBtn')?.addEventListener('click', zoomOutImage);
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Escape to close modal
                if (e.key === 'Escape') {
                    if (imageModal.classList.contains('active')) closeImageModalFunc();
                    if (!notificationDropdown.classList.contains('hidden')) {
                        notificationDropdown.classList.add('hidden');
                    }
                }
            });
            
            // Close notification dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (notificationBtn && !notificationBtn.contains(e.target) && 
                    notificationDropdown && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.add('hidden');
                }
            });
        });
        
        // Functions
        function markNotificationsAsRead() {
            // Kirim request ke server untuk menandai semua sebagai sudah dilihat
            fetch(window.location.href + '?lihat_notif=1', {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => {
                // Update UI secara instan tanpa perlu refresh
                updateNotificationUI();
                notificationsMarkedAsRead = true;
            })
            .catch(error => {
                console.error('Error:', error);
                // Fallback: update UI meskipun request gagal
                updateNotificationUI();
                notificationsMarkedAsRead = true;
            });
        }
        
        function updateNotificationUI() {
            // Hilangkan badge notifikasi
            if (notificationBadge) {
                notificationBadge.style.display = 'none';
            }
            
            // Update tampilan dropdown
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.classList.remove('unseen');
                item.classList.add('seen');
                
                // Update icon dan text
                const icon = item.querySelector('.fa-bullhorn');
                if (icon) {
                    icon.classList.remove('text-blue-500');
                    icon.classList.add('text-gray-400');
                }
                
                const text = item.querySelector('.text-sm');
                if (text) {
                    text.classList.remove('text-gray-800');
                    text.classList.add('text-gray-600');
                }
                
                // Hilangkan badge "Baru"
                const badge = item.querySelector('.bg-green-100');
                if (badge) {
                    badge.style.display = 'none';
                }
            });
            
            // Update judul dropdown
            const dropdownTitle = notificationDropdown.querySelector('h3');
            if (dropdownTitle) {
                dropdownTitle.textContent = 'Semua Pengumuman';
            }
            
            // Update subtitle
            const subtitle = notificationDropdown.querySelector('.text-xs');
            if (subtitle) {
                subtitle.textContent = 'Sudah dilihat';
            }
        }
        
        function showImageModal(src, caption) {
            document.getElementById('modalImage').src = src;
            document.getElementById('imageTitle').textContent = caption || 'Gambar Pengumuman';
            imageModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Reset zoom
            document.getElementById('modalImage').style.transform = 'scale(1)';
        }
        
        function closeImageModalFunc() {
            imageModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
        
        function downloadImage() {
            const img = document.getElementById('modalImage');
            const link = document.createElement('a');
            link.href = img.src;
            link.download = `pengumuman-${Date.now()}.jpg`;
            link.click();
        }
        
        function zoomInImage() {
            const img = document.getElementById('modalImage');
            const currentScale = parseFloat(img.style.transform.replace('scale(', '').replace(')', '')) || 1;
            img.style.transform = `scale(${currentScale + 0.2})`;
        }
        
        function zoomOutImage() {
            const img = document.getElementById('modalImage');
            const currentScale = parseFloat(img.style.transform.replace('scale(', '').replace(')', '')) || 1;
            if (currentScale > 0.5) {
                img.style.transform = `scale(${currentScale - 0.2})`;
            }
        }
        
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
    </script>
</body>
</html>