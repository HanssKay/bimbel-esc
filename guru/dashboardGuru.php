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

if ($_SESSION['user_role'] != 'guru') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// Get guru_id from guru table based on user_id
$guru_id = 0;
$guru_detail = [];
try {
    $sql = "SELECT g.id, g.bidang_keahlian, g.pendidikan_terakhir, g.pengalaman_tahun, 
                   g.status, g.tanggal_bergabung
            FROM guru g 
            WHERE g.user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $guru_detail = $result->fetch_assoc();
        $guru_id = $guru_detail['id'] ?? 0;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error getting guru_id: " . $e->getMessage());
}

// Hitung statistik dengan error handling
$statistics = [
    'total_siswa' => 0,
    'total_siswa_jadwal' => 0,
    'total_penilaian' => 0,
    'penilaian_bulan_ini' => 0,
    'rata_nilai' => '0.0',
    'total_pelajaran' => 0,
    'siswa_terbaik' => ['nama_lengkap' => '-', 'total_score' => 0, 'nama_pelajaran' => '-']
];

$penilaian_terbaru = [];
$siswa_belum_dinilai = [];
$jadwal_per_hari = [];

try {
    if ($guru_id > 0) {
        // 1. TOTAL SISWA YANG DIAJAR
        $sql = "SELECT COUNT(DISTINCT sp.siswa_id) as total 
                FROM siswa_pelajaran sp 
                INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                WHERE sp.guru_id = ? 
                AND sp.status = 'aktif' 
                AND ps.status = 'aktif'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_siswa'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 2. SISWA DENGAN JADWAL AKTIF
        $sql = "SELECT COUNT(DISTINCT s.id) as total 
                FROM siswa_pelajaran sp 
                INNER JOIN siswa s ON sp.siswa_id = s.id
                INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                INNER JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                WHERE sp.guru_id = ? 
                AND sp.status = 'aktif' 
                AND ps.status = 'aktif'
                AND jb.status = 'aktif'
                AND smg.guru_id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $guru_id, $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_siswa_jadwal'] = (int)$row['total'];
                }
            }
            $stmt->close();
        } 

        // ============== PERBAIKAN DI SINI ==============
        // 4. TOTAL PENILAIAN - DENGAN SQL_NO_CACHE
        $sql = "SELECT SQL_NO_CACHE COUNT(*) as total FROM penilaian_siswa WHERE guru_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_penilaian'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 5. PENILAIAN BULAN INI - DENGAN SQL_NO_CACHE
        $current_month = date('Y-m');
        $current_month_name = date('F Y'); // Contoh: February 2026
        
        $sql = "SELECT SQL_NO_CACHE COUNT(*) as total FROM penilaian_siswa 
                WHERE guru_id = ? 
                AND (periode_penilaian = ? OR DATE_FORMAT(tanggal_penilaian, '%Y-%m') = ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $guru_id, $current_month_name, $current_month);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['penilaian_bulan_ini'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 6. RATA-RATA NILAI
        $sql = "SELECT COALESCE(AVG(total_score), 0) as rata_nilai 
                FROM penilaian_siswa 
                WHERE guru_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['rata_nilai'] = number_format((float)$row['rata_nilai'], 1);
                }
            }
            $stmt->close();
        }

        // 7. JUMLAH PELAJARAN YANG DIAMPU
        $sql = "SELECT COUNT(DISTINCT id) as total FROM siswa_pelajaran 
                WHERE guru_id = ? AND status = 'aktif'";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_pelajaran'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 8. SISWA TERBAIK
        $sql = "SELECT s.nama_lengkap, ps.total_score, sp.nama_pelajaran
                FROM penilaian_siswa ps 
                INNER JOIN siswa s ON ps.siswa_id = s.id 
                LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
                WHERE ps.guru_id = ? 
                ORDER BY ps.total_score DESC 
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['siswa_terbaik'] = [
                        'nama_lengkap' => $row['nama_lengkap'] ?? '-',
                        'total_score' => (int)($row['total_score'] ?? 0),
                        'nama_pelajaran' => $row['nama_pelajaran'] ?? '-'
                    ];
                }
            }
            $stmt->close();
        }

        // 9. PENILAIAN TERBARU - DENGAN SQL_NO_CACHE
        $sql = "SELECT SQL_NO_CACHE ps.*, s.nama_lengkap as nama_siswa, s.kelas, 
                       COALESCE(sp.nama_pelajaran, 'Tanpa Pelajaran') as nama_pelajaran
                FROM penilaian_siswa ps
                INNER JOIN siswa s ON ps.siswa_id = s.id
                LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
                WHERE ps.guru_id = ?
                ORDER BY ps.tanggal_penilaian DESC 
                LIMIT 5";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $penilaian_terbaru[] = $row;
                    }
                }
            }
            $stmt->close();
        }

        // 10. SISWA YANG BELUM DINILAI - TANPA BATASAN BULAN
        $sql = "SELECT DISTINCT s.id, s.nama_lengkap, s.kelas, sp.nama_pelajaran
                FROM siswa_pelajaran sp
                INNER JOIN siswa s ON sp.siswa_id = s.id
                INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                WHERE sp.guru_id = ? 
                AND sp.status = 'aktif'
                AND ps.status = 'aktif'
                AND NOT EXISTS (
                    SELECT 1 
                    FROM penilaian_siswa pn 
                    WHERE pn.siswa_id = sp.siswa_id 
                    AND pn.siswa_pelajaran_id = sp.id
                )
                ORDER BY s.nama_lengkap
                LIMIT 5";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $siswa_belum_dinilai[] = $row;
                    }
                }
            }
            $stmt->close();
        }
        // ============== SELESAI PERBAIKAN ==============
    }

} catch (Exception $e) {
    error_log("Error in dashboardGuru.php: " . $e->getMessage());
}
?>


<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Guru - Bimbel Esc</title>
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

            .quick-action-btn {
                padding: 0.75rem !important;
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Hidden debug info -->
    <!-- <?php echo $debug_info; ?> -->

    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Guru</p>
        </div>

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
                    <?php if (!empty($guru_detail['bidang_keahlian'])): ?>
                        <p class="text-xs text-blue-200"><?php echo htmlspecialchars($guru_detail['bidang_keahlian']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Navigation -->
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
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-3">
                    <p class="text-sm"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-blue-300">Guru</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher"></i>
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
                        <i class="fas fa-chalkboard-teacher text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Guru</p>
                        <?php if (!empty($guru_detail['bidang_keahlian'])): ?>
                            <p class="text-xs text-blue-200">
                                <?php echo htmlspecialchars($guru_detail['bidang_keahlian']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
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
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Dashboard Guru</h1>
                    <p class="text-gray-600">Selamat datang, <?php echo htmlspecialchars($full_name); ?>!</p>
                    <?php if (!empty($guru_detail['tanggal_bergabung'])): ?>
                        <p class="text-sm text-gray-500 mt-1">
                            Bergabung sejak: <?php echo date('d F Y', strtotime($guru_detail['tanggal_bergabung'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <!-- Total Siswa Diajar -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center md:mt-5">
                        <div class="p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-users text-blue-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Siswa Diajar</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_siswa']); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Siswa dengan Jadwal Aktif -->
                <div class="stat-card bg-white p-5 rounded-xl shadow border-2 border-green-300">
                    <div class="flex items-center ">
                        <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-calendar-check text-green-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Siswa dengan Jadwal</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_siswa_jadwal']); ?>
                            </h3>
                            <p class="text-xs text-gray-500 mt-1">Memiliki jadwal aktif</p>
                        </div>
                    </div>
                </div>

                <!-- Total Penilaian -->
                <div class="stat-card bg-white p-5 rounded-xl shadow">
                    <div class="flex items-center md:mt-5 ">
                        <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-clipboard-check text-green-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Penilaian</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_penilaian']); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Penilaian Bulan Ini -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center  md:mt-3">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-calendar-day text-yellow-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Penilaian Bulan Ini</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['penilaian_bulan_ini']); ?>
                            </h3>
                            <p class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Data -->
            <div class="grid grid-cols-1 gap-8 mb-8">
                <!-- Penilaian Terbaru -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">
                            <i class="fas fa-history mr-2"></i> Penilaian Terbaru
                        </h3>
                    </div>
                    <div class="px-4 py-2 sm:p-6">
                        <div class="flow-root">
                            <ul class="divide-y divide-gray-200">
                                <?php if (count($penilaian_terbaru) > 0): ?>
                                        <?php foreach ($penilaian_terbaru as $penilaian): ?>
                                                                        <li class="py-3 hover:bg-gray-50">
                                                                            <div class="flex items-center space-x-4">
                                                                                <div class="flex-shrink-0">
                                                                                    <div class="h-10 w-10 rounded-full 
                                                        <?php
                                                        $score = $penilaian['total_score'] ?? 0;
                                                        if ($score >= 40)
                                                            echo 'bg-green-100 text-green-800';
                                                        elseif ($score >= 30)
                                                            echo 'bg-blue-100 text-blue-800';
                                                        elseif ($score >= 20)
                                                            echo 'bg-yellow-100 text-yellow-800';
                                                        else
                                                            echo 'bg-red-100 text-red-800';
                                                        ?> flex items-center justify-center">
                                                                                        <span class="text-xs font-bold">
                                                                                            <?php echo $score; ?>
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="flex-1 min-w-0">
                                                                                    <p class="text-sm font-medium text-gray-900 truncate">
                                                                                        <?php echo htmlspecialchars($penilaian['nama_siswa'] ?? 'N/A'); ?>
                                                                                    </p>
                                                                                    <p class="text-sm text-gray-500 truncate">
                                                                                        <?php echo htmlspecialchars($penilaian['nama_pelajaran'] ?? 'N/A'); ?> | 
                                                                                        Kelas: <?php echo htmlspecialchars($penilaian['kelas'] ?? 'N/A'); ?>
                                                                                    </p>
                                                                                </div>
                                                                                <div class="text-right">
                                                                                    <div class="text-sm font-semibold text-gray-900">
                                                                                        <?php echo $penilaian['total_score'] ?? 0; ?>/50
                                                                                    </div>
                                                                                    <div class="text-xs text-gray-500">
                                                                                        <?php echo isset($penilaian['tanggal_penilaian']) ? date('d M Y', strtotime($penilaian['tanggal_penilaian'])) : '-'; ?>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </li>
                                                    <?php endforeach; ?>
                                <?php else: ?>
                                                    <li class="py-4 text-center text-gray-500">
                                                        <i class="fas fa-clipboard-list text-2xl mb-2"></i>
                                                        <p>Belum ada data penilaian</p>
                                                        <p class="text-xs text-gray-400 mt-1">Silakan input penilaian baru</p>
                                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="riwayat.php" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-900">
                                <i class="fas fa-eye mr-1"></i> Lihat semua penilaian
                            </a>
                        </div>
                    </div>
                </div>

               
            </div>

            

            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                    <i class="fas fa-bolt mr-2"></i> Aksi Cepat
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="inputNilai.php" class="flex flex-col items-center justify-center p-6 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-blue-600 flex items-center justify-center mb-3">
                            <i class="fas fa-plus-circle text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Input Nilai</span>
                        <span class="text-xs text-gray-500 mt-1">Penilaian baru</span>
                    </a>

                    <a href="dataSiswa.php" class="flex flex-col items-center justify-center p-6 bg-green-50 hover:bg-green-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-green-600 flex items-center justify-center mb-3">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Data Siswa</span>
                        <span class="text-xs text-gray-500 mt-1">Lihat siswa</span>
                    </a>

                    <a href="absensiGuru.php" class="flex flex-col items-center justify-center p-6 bg-orange-50 hover:bg-orange-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-orange-600 flex items-center justify-center mb-3">
                            <i class="fas fa-calendar-check text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Absensi</span>
                        <span class="text-xs text-gray-500 mt-1">Input kehadiran</span>
                    </a>

                    <a href="riwayat.php" class="flex flex-col items-center justify-center p-6 bg-purple-50 hover:bg-purple-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-purple-600 flex items-center justify-center mb-3">
                            <i class="fas fa-history text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Riwayat</span>
                        <span class="text-xs text-gray-500 mt-1">Histori penilaian</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Dashboard Guru</p>
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

        // Debug info di console
        console.log('Guru ID:', <?php echo $guru_id; ?>);
        console.log('Total Penilaian:', <?php echo $statistics['total_penilaian']; ?>);
        console.log('Penilaian Bulan Ini:', <?php echo $statistics['penilaian_bulan_ini']; ?>);
        console.log('Data Penilaian Terbaru:', <?php echo json_encode($penilaian_terbaru); ?>);
    </script>
</body>
</html>