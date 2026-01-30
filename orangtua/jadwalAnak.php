<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['user_role'] != 'orangtua') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$orangtua_id = $_SESSION['role_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Orangtua';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL DATA ORANGTUA LENGKAP
$orangtua_data = [];
$email = '';
$nama_ortu = '';
$no_hp = '';

// Ambil data orangtua berdasarkan user_id dari session - AMAN dengan prepared statement
try {
    $sql_orangtua = "SELECT o.*, u.email 
                     FROM orangtua o 
                     JOIN users u ON o.user_id = u.id 
                     WHERE o.user_id = ?";
    $stmt_orangtua = $conn->prepare($sql_orangtua);
    $stmt_orangtua->bind_param("i", $_SESSION['user_id']);
    $stmt_orangtua->execute();
    $result_orangtua = $stmt_orangtua->get_result();
    
    if ($row_orangtua = $result_orangtua->fetch_assoc()) {
        $orangtua_id = $row_orangtua['id'];
        $orangtua_data = $row_orangtua;
        $nama_ortu = $row_orangtua['nama_ortu'] ?? $full_name;
        $email = $row_orangtua['email'] ?? '';
        $no_hp = $row_orangtua['no_hp'] ?? '';
        
        $_SESSION['role_id'] = $orangtua_id;
    } else {
        die("Data orangtua tidak ditemukan untuk user ini");
    }
    
    $stmt_orangtua->close();
} catch (Exception $e) {
    error_log("Error fetching orangtua data: " . $e->getMessage());
    die("Terjadi kesalahan saat mengambil data orangtua");
}

if ($orangtua_id == 0) {
    die("Data orangtua tidak ditemukan");
}

// AMBIL DATA ANAK-ANAK (SISWA) DARI ORANGTUA INI
$anak_orangtua = [];

// Query untuk mengambil semua anak dari orangtua ini - AMAN dengan prepared statement
$sql_anak = "
    SELECT DISTINCT s.id, s.nama_lengkap, s.kelas as kelas_sekolah
    FROM siswa s
    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
    WHERE (s.orangtua_id = ? OR so.orangtua_id = ?)
    AND s.status = 'aktif'
    ORDER BY s.nama_lengkap
";

try {
    $stmt = $conn->prepare($sql_anak);
    $stmt->bind_param("ii", $orangtua_id, $orangtua_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $anak_orangtua[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching anak data: " . $e->getMessage());
}

// HITUNG TOTAL ANAK
$total_anak = count($anak_orangtua);

// JIKA TIDAK ADA ANAK YANG TERDAFTAR
if (empty($anak_orangtua)) {
    $jadwal_anak = [];
    $total_jadwal = 0;
    $jadwal_by_hari = [];
    $total_anak_with_jadwal = 0;
} else {
    // AMBIL ID SEMUA ANAK
    $anak_ids = array_column($anak_orangtua, 'id');
    
    // FILTER HARI
    $filter_hari = isset($_GET['hari']) && $_GET['hari'] !== '' ? $_GET['hari'] : '';
    $filter_anak = isset($_GET['anak_id']) && $_GET['anak_id'] !== '' ? (int)$_GET['anak_id'] : 0;
    
    // QUERY JADWAL ANAK - SESUAIKAN DENGAN STRUKTUR DATABASE
    $jadwal_anak = [];
    
    // Gunakan IN clause dengan prepared statement yang aman
    if (!empty($anak_ids)) {
        $placeholders = implode(',', array_fill(0, count($anak_ids), '?'));
        
        // Query utama dengan JOIN yang sesuai struktur database
        $sql_jadwal_anak = "
            SELECT 
                jb.id as jadwal_id,
                smg.hari,
                smg.jam_mulai,
                smg.jam_selesai,
                TIME_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
                TIME_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format,
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah,
                ps.tingkat as tingkat_bimbel,
                ps.jenis_kelas,
                sp.nama_pelajaran,
                sp.id as siswa_pelajaran_id,
                g.id as guru_id,
                g.bidang_keahlian,
                u.full_name as nama_guru,
                smg.kapasitas_maks,
                smg.kapasitas_terisi,
                smg.status as status_sesi
            FROM jadwal_belajar jb
            JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
            JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
            JOIN siswa s ON ps.siswa_id = s.id
            LEFT JOIN guru g ON smg.guru_id = g.id
            LEFT JOIN users u ON g.user_id = u.id
            WHERE jb.status = 'aktif'
            AND smg.status = 'tersedia'
            AND ps.status = 'aktif'
            AND sp.status = 'aktif'
            AND s.status = 'aktif'
            AND s.id IN ($placeholders)
        ";
        
        // Tambahkan kondisi filter
        $where_conditions = [];
        $params = array_merge([], $anak_ids); // Start with student IDs
        $types = str_repeat('i', count($anak_ids)); // Types for student IDs
        
        if ($filter_hari !== '') {
            $sql_jadwal_anak .= " AND smg.hari = ?";
            $params[] = $filter_hari;
            $types .= 's';
        }
        
        if ($filter_anak > 0) {
            $sql_jadwal_anak .= " AND s.id = ?";
            $params[] = $filter_anak;
            $types .= 'i';
        }
        
        // Tambahkan ORDER BY
        $sql_jadwal_anak .= " ORDER BY 
            FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
            smg.jam_mulai,
            s.nama_lengkap";
        
        try {
            $stmt = $conn->prepare($sql_jadwal_anak);
            
            // Bind parameters dinamis
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $jadwal_anak[] = $row;
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching jadwal anak: " . $e->getMessage());
        }
    }
    
    // HITUNG STATISTIK
    $total_jadwal = count($jadwal_anak);
    
    // Hitung berapa anak yang memiliki jadwal
    $anak_with_jadwal = [];
    foreach ($jadwal_anak as $jadwal) {
        if (!in_array($jadwal['siswa_id'], $anak_with_jadwal)) {
            $anak_with_jadwal[] = $jadwal['siswa_id'];
        }
    }
    $total_anak_with_jadwal = count($anak_with_jadwal);
    
    // Group by hari untuk statistik
    $jadwal_by_hari = [];
    foreach ($jadwal_anak as $jadwal) {
        $hari = $jadwal['hari'];
        if (!isset($jadwal_by_hari[$hari])) {
            $jadwal_by_hari[$hari] = 0;
        }
        $jadwal_by_hari[$hari]++;
    }
}

// Fungsi untuk menghitung durasi
function hitungDurasi($jam_mulai, $jam_selesai) {
    $start = strtotime($jam_mulai);
    $end = strtotime($jam_selesai);
    $durasi = ($end - $start) / 3600; // dalam jam
    return number_format($durasi, 1);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Anak - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        /* Table styles */
        .table-container {
            overflow-x: auto;
        }

        .table-jadwal {
            width: 100%;
            border-collapse: collapse;
        }

        .table-jadwal th {
            background-color: #f3f4f6;
            font-weight: 600;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid #e5e7eb;
        }

        .table-jadwal td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table-jadwal tr:hover {
            background-color: #f9fafb;
        }

        /* Badge styles */
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-primary {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background-color: #d1f5ff;
            color: #0369a1;
        }

        .badge-excellent {
            background-color: #fce7f3;
            color: #9d174d;
        }

        .badge-champion {
            background-color: #f0f9ff;
            color: #0c4a6e;
        }

        /* Responsive */
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
            
            .stat-card {
                padding: 1rem !important;
            }
        }

        /* Card styles */
        .anak-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .anak-card:hover {
            border-color: #60a5fa;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Kapasitas progress bar */
        .capacity-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .capacity-fill {
            height: 100%;
            background-color: #10b981;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Orang Tua</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                </div>
            </div>
            <div class="mt-3 text-sm">
                <p><i class="fas fa-child mr-2"></i> <?php echo $total_anak; ?> Anak</p>
            </div>
        </div>
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'orangtua'); ?>
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
                    <p class="text-xs text-blue-300">Orangtua</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
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
                    <i class="fas fa-user text-lg"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <?php if (!empty($email)): ?>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3 text-sm">
                <p class="flex items-center">
                    <i class="fas fa-child mr-2"></i> 
                    <span><?php echo $total_anak; ?> Anak</span>
                </p>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto">
            <?php echo renderMenu($currentPage, 'orangtua'); ?>
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
                        <i class="fas fa-calendar-alt mr-2"></i> Jadwal Anak
                    </h1>
                    <p class="text-gray-600">
                        Lihat jadwal bimbingan belajar anak Anda
                    </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-green-100 text-green-800">
                        <i class="fas fa-child mr-2"></i>
                        <?php echo $total_anak; ?> Anak
                    </span>
                </div>
            </div>
        </div>

        <!-- Notifikasi -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="container mx-auto p-4">
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="container mx-auto p-4">
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Anak -->
                <div class="stat-card items-center bg-white rounded-xl p-3 shadow">
                    <div class="flex items-center md:ms-3">
                        <div class="p-5 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-child text-green-600 text-xl md:text-3xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Anak</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_anak; ?></h3>
                        </div>
                    </div>
                </div>

                <!-- Total Jadwal -->
                <div class="stat-card items-center bg-white p-3 rounded-xl shadow">
                    <div class="flex items-center md:mt-2 md:ms-3">
                        <div class="p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-calendar-alt text-blue-600 text-xl md:text-3xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Jadwal</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_jadwal); ?></h3>
                        </div>
                    </div>
                </div>

                <!-- Total Guru -->
                <div class="stat-card items-center bg-white p-3 rounded-xl shadow">
                    <div class="flex items-center md:mt-2 md:ms-3">
                        <div class="p-3 bg-purple-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-chalkboard-teacher text-purple-600 text-xl md:text-3xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Guru</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php 
                                if ($total_jadwal > 0) {
                                    $guru_ids = array_filter(array_column($jadwal_anak, 'guru_id'), function($value) {
                                        return !is_null($value) && $value > 0;
                                    });
                                    echo count(array_unique($guru_ids));
                                } else {
                                    echo '0';
                                }
                                ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter -->
            <div class="bg-white overflow-hidden shadow rounded-lg p-5 mb-5">
                <form method="GET" action="" class="flex flex-col space-y-2">
                    <label class="text-sm font-medium text-gray-900">
                        Filter Jadwal
                    </label>
                    <div class="space-y-2">
                        <select name="anak_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Anak</option>
                            <?php foreach ($anak_orangtua as $anak): ?>
                            <option value="<?php echo $anak['id']; ?>" <?php echo $filter_anak == $anak['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($anak['nama_lengkap']); ?> (<?php echo $anak['kelas_sekolah']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="hari" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Hari</option>
                            <option value="Senin" <?php echo $filter_hari == 'Senin' ? 'selected' : ''; ?>>Senin</option>
                            <option value="Selasa" <?php echo $filter_hari == 'Selasa' ? 'selected' : ''; ?>>Selasa</option>
                            <option value="Rabu" <?php echo $filter_hari == 'Rabu' ? 'selected' : ''; ?>>Rabu</option>
                            <option value="Kamis" <?php echo $filter_hari == 'Kamis' ? 'selected' : ''; ?>>Kamis</option>
                            <option value="Jumat" <?php echo $filter_hari == 'Jumat' ? 'selected' : ''; ?>>Jumat</option>
                            <option value="Sabtu" <?php echo $filter_hari == 'Sabtu' ? 'selected' : ''; ?>>Sabtu</option>
                            <option value="Minggu" <?php echo $filter_hari == 'Minggu' ? 'selected' : ''; ?>>Minggu</option>
                        </select>
                    </div>
                    
                    <div class="flex space-x-2 pt-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                        <?php if ($filter_hari || $filter_anak): ?>
                        <a href="jadwalAnak.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                            Reset
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php if (empty($anak_orangtua)): ?>
            <!-- Jika belum ada anak terdaftar -->
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-6">
                    <i class="fas fa-child text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-2xl font-bold text-gray-700 mb-2">Belum Ada Anak Terdaftar</h3>
                    <p class="text-gray-600 mb-6">
                        Anda belum memiliki anak yang terdaftar di bimbingan belajar ini.
                    </p>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 max-w-md mx-auto">
                        <p class="text-blue-800">
                            <i class="fas fa-info-circle mr-2"></i>
                            Silakan hubungi administrator untuk mendaftarkan anak Anda.
                        </p>
                    </div>
                </div>
            </div>
            
            <?php elseif ($total_jadwal == 0): ?>
            <!-- Jika ada anak tapi belum ada jadwal -->
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-calendar-times mr-2"></i> Daftar Anak
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Anda memiliki <?php echo $total_anak; ?> anak yang terdaftar
                    </p>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                        <?php foreach ($anak_orangtua as $anak): ?>
                        <div class="anak-card p-5 bg-white rounded-lg">
                            <div class="flex items-center mb-4">
                                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="font-bold text-gray-900"><?php echo htmlspecialchars($anak['nama_lengkap']); ?></h4>
                                    <p class="text-sm text-gray-600">Kelas: <?php echo $anak['kelas_sekolah']; ?></p>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-school text-gray-400 mr-2 w-4"></i>
                                    <span class="text-gray-700"><?php echo $anak['kelas_sekolah']; ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-calendar text-gray-400 mr-2 w-4"></i>
                                    <span class="text-gray-700 font-medium text-red-500">Belum ada jadwal</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-calendar-plus text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium mb-2">Belum Ada Jadwal Bimbingan</h3>
                        <p class="mb-4">
                            Anak-anak Anda belum memiliki jadwal bimbingan belajar.
                        </p>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 max-w-md mx-auto">
                            <p class="text-yellow-800 text-sm">
                                <i class="fas fa-clock mr-2"></i>
                                Silakan tunggu hingga admin atau guru membuat jadwal untuk anak Anda.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Jika ada jadwal -->
            
            <!-- Kartu Anak-anak -->
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-users mr-2"></i> Daftar Anak
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        <?php echo $total_anak; ?> anak terdaftar, <?php echo $total_anak_with_jadwal; ?> memiliki jadwal
                    </p>
                </div>
                
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php 
                        // Hitung jadwal per anak
                        $jadwal_per_anak = [];
                        foreach ($jadwal_anak as $jadwal) {
                            $siswa_id = $jadwal['siswa_id'];
                            if (!isset($jadwal_per_anak[$siswa_id])) {
                                $jadwal_per_anak[$siswa_id] = 0;
                            }
                            $jadwal_per_anak[$siswa_id]++;
                        }
                        
                        foreach ($anak_orangtua as $anak): 
                            $jumlah_jadwal = $jadwal_per_anak[$anak['id']] ?? 0;
                        ?>
                        <div class="anak-card p-5 bg-white rounded-lg">
                            <div class="flex items-center mb-4">
                                <div class="w-12 h-12 <?php echo $jumlah_jadwal > 0 ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600'; ?> rounded-full flex items-center justify-center">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="font-bold text-gray-900"><?php echo htmlspecialchars($anak['nama_lengkap']); ?></h4>
                                    <p class="text-sm text-gray-600">Kelas: <?php echo $anak['kelas_sekolah']; ?></p>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-school text-gray-400 mr-2 w-4"></i>
                                    <span class="text-gray-700"><?php echo $anak['kelas_sekolah']; ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-calendar text-gray-400 mr-2 w-4"></i>
                                    <span class="text-gray-700">
                                        <span class="font-medium <?php echo $jumlah_jadwal > 0 ? 'text-green-600' : 'text-red-500'; ?>">
                                            <?php echo $jumlah_jadwal; ?> jadwal
                                        </span>
                                    </span>
                                </div>
                                <?php if ($jumlah_jadwal > 0): 
                                    // Ambil hari-hari jadwal untuk anak ini
                                    $hari_anak = [];
                                    foreach ($jadwal_anak as $jadwal) {
                                        if ($jadwal['siswa_id'] == $anak['id']) {
                                            $hari_anak[] = $jadwal['hari'];
                                        }
                                    }
                                    $hari_anak = array_unique($hari_anak);
                                ?>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-clock text-gray-400 mr-2 w-4"></i>
                                    <span class="text-gray-700">
                                        Hari: <?php echo implode(', ', array_slice($hari_anak, 0, 3)); ?>
                                        <?php if (count($hari_anak) > 3) echo '...'; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Tabel Jadwal Anak -->
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-table mr-2"></i> Daftar Jadwal Bimbingan
                        <?php if ($filter_hari || $filter_anak): ?>
                        <span class="text-sm font-normal text-gray-500 ml-2">
                            (Filter: 
                            <?php 
                            if ($filter_anak > 0) {
                                foreach ($anak_orangtua as $anak) {
                                    if ($anak['id'] == $filter_anak) {
                                        echo htmlspecialchars($anak['nama_lengkap']);
                                        break;
                                    }
                                }
                            } else {
                                echo 'Semua Anak';
                            }
                            
                            if ($filter_hari) {
                                echo ' | ' . htmlspecialchars($filter_hari);
                            }
                            ?>
                            )
                        </span>
                        <?php endif; ?>
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Total <?php echo $total_jadwal; ?> jadwal ditemukan
                    </p>
                </div>
                <div class="px-4 py-2 sm:p-6">
                    <div class="table-container">
                        <table class="table-jadwal">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Anak</th>
                                    <th>Kelas Sekolah</th>
                                    <th>Tingkat Bimbel</th>
                                    <th>Jenis Kelas</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Hari</th>
                                    <th>Jam</th>
                                    <th>Durasi</th>
                                    <th>Guru</th>
                                    <th>Kapasitas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($jadwal_anak as $jadwal): 
                                    $durasi = hitungDurasi($jadwal['jam_mulai'], $jadwal['jam_selesai']);
                                    $kapasitas_persen = ($jadwal['kapasitas_terisi'] / $jadwal['kapasitas_maks']) * 100;
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($jadwal['nama_lengkap']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $jadwal['kelas_sekolah']; ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $badge_class = '';
                                        switch($jadwal['tingkat_bimbel']) {
                                            case 'TK': $badge_class = 'badge-warning'; break;
                                            case 'SD': $badge_class = 'badge-info'; break;
                                            case 'SMP': $badge_class = 'badge-success'; break;
                                            case 'SMA': $badge_class = 'badge-primary'; break;
                                            default: $badge_class = 'badge-primary';
                                        }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo $jadwal['tingkat_bimbel']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-excellent' : 'badge-champion'; ?>">
                                            <?php echo $jadwal['jenis_kelas']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo $jadwal['hari']; ?></span>
                                    </td>
                                    <td>
                                        <div class="font-medium">
                                            <?php echo $jadwal['jam_mulai_format']; ?> - <?php echo $jadwal['jam_selesai_format']; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $durasi; ?> jam
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($jadwal['nama_guru'])): ?>
                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_guru']); ?></div>
                                        <?php else: ?>
                                        <div class="text-gray-500 italic">Belum ditetapkan</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-sm">
                                            <span class="font-medium"><?php echo $jadwal['kapasitas_terisi']; ?>/<?php echo $jadwal['kapasitas_maks']; ?></span>
                                            <div class="capacity-bar">
                                                <div class="capacity-fill" style="width: <?php echo min($kapasitas_persen, 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Jadwal Anak</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Login terakhir: <?php echo date('d F Y H:i'); ?>
                        </p>
                    </div>
                    <div class="mt-3 md:mt-0">
                        <div class="flex items-center space-x-4">
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <i class="fas fa-clock mr-1"></i>
                                <span id="serverTime"><?php echo date('H:i:s'); ?></span>
                            </span>
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <i class="fas fa-users mr-1"></i>
                                <?php echo $total_anak; ?> Anak
                            </span>
                        </div>
                    </div>
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