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

// AMBIL STATISTIK
$statistics = [];

try {
    // 1. Total Siswa Aktif
    $sql = "SELECT COUNT(*) as total FROM siswa WHERE status = 'aktif'";
    $result = $conn->query($sql);
    $statistics['total_siswa'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 2. Total Guru Aktif
    $sql = "SELECT COUNT(*) as total FROM guru WHERE status = 'aktif'";
    $result = $conn->query($sql);
    $statistics['total_guru'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 3. Total Orang Tua
    $sql = "SELECT COUNT(*) as total FROM orangtua";
    $result = $conn->query($sql);
    $statistics['total_orangtua'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 4. Total Admin Aktif
    $sql = "SELECT COUNT(*) as total FROM users WHERE role = 'admin' AND is_active = 1";
    $result = $conn->query($sql);
    $statistics['total_admin'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 5. Total Penilaian
    $sql = "SELECT COUNT(*) as total FROM penilaian_siswa";
    $result = $conn->query($sql);
    $statistics['total_penilaian'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 6. Rata-rata Nilai Siswa
    $sql = "SELECT AVG(total_score) as rata_nilai FROM penilaian_siswa";
    $result = $conn->query($sql);
    $statistics['rata_nilai'] = $result && $result->num_rows > 0 ?
        number_format($result->fetch_assoc()['rata_nilai'] ?? 0, 1) : '0.0';

    // 7. Siswa dengan Nilai Tertinggi
    $sql = "SELECT s.nama_lengkap, ps.total_score 
            FROM penilaian_siswa ps 
            JOIN siswa s ON ps.siswa_id = s.id 
            ORDER BY ps.total_score DESC LIMIT 1";
    $result = $conn->query($sql);
    $statistics['siswa_terbaik'] = $result && $result->num_rows > 0 ?
        $result->fetch_assoc() : ['nama_lengkap' => '-', 'total_score' => 0];

    // 8. Penilaian Bulan Ini
    $current_month = date('m');
    $current_year = date('Y');
    $sql = "SELECT COUNT(*) as total FROM penilaian_siswa 
            WHERE MONTH(tanggal_penilaian) = ? AND YEAR(tanggal_penilaian) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $current_month, $current_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $statistics['penilaian_bulan_ini'] = $result && $result->num_rows > 0 ?
            $result->fetch_assoc()['total'] ?? 0 : 0;
        $stmt->close();
    } else {
        $statistics['penilaian_bulan_ini'] = 0;
    }

    // 9. Total Pembayaran Lunas Bulan Ini
    $sql = "SELECT COUNT(*) as total FROM pembayaran 
            WHERE status = 'lunas' 
            AND MONTH(tanggal_bayar) = ? AND YEAR(tanggal_bayar) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $current_month, $current_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $statistics['pembayaran_lunas_bulan_ini'] = $result && $result->num_rows > 0 ?
            $result->fetch_assoc()['total'] ?? 0 : 0;
        $stmt->close();
    } else {
        $statistics['pembayaran_lunas_bulan_ini'] = 0;
    }

    // 10. Total Pendaftaran Aktif
    $sql = "SELECT COUNT(*) as total FROM pendaftaran_siswa WHERE status = 'aktif'";
    $result = $conn->query($sql);
    $statistics['total_pendaftaran_aktif'] = $result && $result->num_rows > 0 ? $result->fetch_assoc()['total'] ?? 0 : 0;

    // 11. Total Pendapatan Bulan Ini
    $sql = "SELECT SUM(nominal_dibayar) as total FROM pembayaran 
            WHERE status = 'lunas' 
            AND MONTH(tanggal_bayar) = ? AND YEAR(tanggal_bayar) = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $current_month, $current_year);
        $stmt->execute();
        $result = $stmt->get_result();
        $statistics['total_pendapatan_bulan_ini'] = $result && $result->num_rows > 0 ?
            number_format($result->fetch_assoc()['total'] ?? 0, 0, ',', '.') : '0';
        $stmt->close();
    } else {
        $statistics['total_pendapatan_bulan_ini'] = '0';
    }

    // 1. Siswa Aktif
    $siswa_aktif = [];
    $sql = "SELECT s.*, o.nama_ortu, o.no_hp, o.email,
            ps.tingkat as tingkat_bimbel
            FROM siswa s
            LEFT JOIN orangtua o ON s.orangtua_id = o.id
            LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
            WHERE s.status = 'aktif'
            ORDER BY s.nama_lengkap ASC LIMIT 3";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $siswa_aktif[] = $row;
        }
    }

    // 2. Penilaian Terbaru
    $penilaian_terbaru = [];
    $sql = "SELECT ps.*, s.nama_lengkap as nama_siswa, 
            u.full_name as nama_guru, sp.nama_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            ORDER BY ps.created_at DESC LIMIT 5";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $penilaian_terbaru[] = $row;
        }
    }

    // 3. Mata Pelajaran Populer
    $mata_pelajaran_populer = [];
    $sql = "SELECT sp.nama_pelajaran, COUNT(DISTINCT sp.siswa_id) as jumlah_siswa 
            FROM siswa_pelajaran sp
            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
            WHERE ps.status = 'aktif' AND sp.status = 'aktif'
            GROUP BY sp.nama_pelajaran
            ORDER BY jumlah_siswa DESC LIMIT 5";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $mata_pelajaran_populer[] = $row;
        }
    }

    // 4. Guru Aktif dengan jumlah siswa yang diajar - COMPATIBLE VERSION
    $guru_aktif = [];
    $sql = "SELECT g.*, u.full_name, u.email, 
        g.bidang_keahlian, g.pendidikan_terakhir, 
        g.pengalaman_tahun, g.status, g.tanggal_bergabung,
        (SELECT COUNT(DISTINCT sp2.siswa_id) 
         FROM siswa_pelajaran sp2 
         WHERE sp2.guru_id = g.id AND sp2.status = 'aktif') as jumlah_siswa
        FROM guru g
        JOIN users u ON g.user_id = u.id
        WHERE g.status = 'aktif'
        ORDER BY u.full_name ASC LIMIT 3";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $guru_aktif[] = $row;
        }
    }

    // 5. Pendaftaran Terbaru
    $pendaftaran_terbaru = [];
    $sql = "SELECT ps.*, s.nama_lengkap, s.kelas,
            GROUP_CONCAT(DISTINCT sp.nama_pelajaran SEPARATOR ', ') as mata_pelajaran,
            u.full_name as nama_guru
            FROM pendaftaran_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            LEFT JOIN siswa_pelajaran sp ON ps.id = sp.pendaftaran_id AND sp.status = 'aktif'
            LEFT JOIN guru g ON sp.guru_id = g.id
            LEFT JOIN users u ON g.user_id = u.id
            GROUP BY ps.id
            ORDER BY ps.created_at DESC LIMIT 5";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pendaftaran_terbaru[] = $row;
        }
    }

    // 6. Pembayaran Terbaru
    $pembayaran_terbaru = [];
    $sql = "SELECT p.*, s.nama_lengkap, ps.tingkat,
            p.nominal_tagihan, p.nominal_dibayar
            FROM pembayaran p
            JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id
            JOIN siswa s ON ps.siswa_id = s.id
            ORDER BY p.dibuat_pada DESC LIMIT 5";

    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pembayaran_terbaru[] = $row;
        }
    }

} catch (Exception $e) {
    error_log("Error in dashboardAdmin.php: " . $e->getMessage());
    // Set default values if error occurs
    $siswa_terbaru = [];
    $penilaian_terbaru = [];
    $mata_pelajaran_populer = [];
    $guru_aktif = [];
    $pendaftaran_terbaru = [];
    $pembayaran_terbaru = [];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Bimbel Esc</title>
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
        }

        /* Custom colors for better UI */
        .bg-money {
            background-color: #10b981;
        }

        .text-money {
            color: #10b981;
        }

        .bg-registration {
            background-color: #8b5cf6;
        }

        .text-registration {
            color: #8b5cf6;
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
                    <h1 class="text-2xl font-bold text-gray-800">Dashboard Admin</h1>
                    <p class="text-gray-600">Selamat datang, <?php echo htmlspecialchars($full_name); ?>!</p>
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Siswa Aktif -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-users text-blue-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Siswa Aktif</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_siswa']); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Total Guru Aktif -->
                <div class="stat-card bg-white p-5 rounded-xl shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-chalkboard-teacher text-green-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Guru Aktif</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_guru']); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Total Admin -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-user-shield text-yellow-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Admin</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_admin']); ?>
                            </h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Pendapatan Bulan Ini -->
            <div class="stat-card bg-white rounded-xl p-5 mb-5 shadow">
                <div class="flex items-center">
                    <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                        <i class="fas fa-wallet text-green-600 text-xl md:text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-600 text-sm md:text-base">Pendapatan (<?php echo date('M'); ?>)</p>
                        <h3 class="text-2xl font-bold text-gray-800">Rp
                            <?php echo $statistics['total_pendapatan_bulan_ini']; ?>
                        </h3>
                    </div>
                </div>
            </div>

            <!-- Charts and Recent Data -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Siswa Terbaru -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">
                            <i class="fas fa-user-check mr-2"></i> Siswa Aktif
                            <span class="ml-2 text-sm text-gray-500 font-normal">
                                (<?php echo min(5, count($siswa_aktif)); ?> dari
                                <?php echo $statistics['total_siswa']; ?>)
                            </span>
                        </h3>
                    </div>
                    <div class="px-4 py-2 sm:p-6">
                        <div class="flow-root">
                            <ul class="divide-y divide-gray-200">
                                <?php if (count($siswa_aktif) > 0): ?>
                                    <?php foreach ($siswa_aktif as $siswa): ?>
                                        <li class="py-3">
                                            <div class="flex items-center space-x-4">
                                                <div class="flex-shrink-0">
                                                    <div class="h-10 w-10 rounded-full 
                                                        <?php
                                                        echo ($siswa['jenis_kelamin'] ?? '') == 'L'
                                                            ? 'bg-blue-100 text-blue-600'
                                                            : 'bg-pink-100 text-pink-600';
                                                        ?> flex items-center justify-center">
                                                        <i class="fas fa-user"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 truncate">
                                                        <?php echo htmlspecialchars($siswa['nama_lengkap'] ?? 'N/A'); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500 truncate">
                                                        <?php if (!empty($siswa['kelas'])): ?>
                                                            <?php echo htmlspecialchars($siswa['kelas']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($siswa['tingkat_bimbel'])): ?>
                                                            | Tingkat: <?php echo htmlspecialchars($siswa['tingkat_bimbel']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                        <?php echo ($siswa['jenis_kelamin'] ?? '') == 'L'
                                                            ? 'bg-blue-100 text-blue-800'
                                                            : 'bg-pink-100 text-pink-800'; ?>">
                                                        <?php echo ($siswa['jenis_kelamin'] ?? '') == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                                    </span>
                                                    <?php if (!empty($siswa['nama_ortu'])): ?>
                                                        <div class="text-xs text-gray-500 mt-1 truncate max-w-[100px]">
                                                            Ortu: <?php echo htmlspecialchars($siswa['nama_ortu']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="py-4 text-center text-gray-500">
                                        <i class="fas fa-user-slash text-2xl mb-2"></i>
                                        <p>Tidak ada siswa aktif</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="dataSiswa.php?status=aktif"
                                class="inline-flex items-center text-sm text-blue-600 hover:text-blue-900">
                                <i class="fas fa-list mr-1"></i> Lihat semua siswa aktif
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Guru Aktif -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">
                            <i class="fas fa-chalkboard-teacher mr-2"></i> Guru Aktif
                        </h3>
                    </div>
                    <div class="px-4 py-2 sm:p-6">
                        <div class="flow-root">
                            <ul class="divide-y divide-gray-200">
                                <?php if (count($guru_aktif) > 0): ?>
                                    <?php foreach ($guru_aktif as $guru): ?>
                                        <li class="py-3">
                                            <div class="flex items-center space-x-4">
                                                <div class="flex-shrink-0">
                                                    <div class="h-10 w-10 rounded-full 
                                                        <?php
                                                        // Warna berdasarkan pengalaman atau bidang
                                                        $experience = $guru['pengalaman_tahun'] ?? 0;
                                                        if ($experience >= 5) {
                                                            echo 'bg-purple-100 text-purple-600';
                                                        } elseif ($experience >= 3) {
                                                            echo 'bg-indigo-100 text-indigo-600';
                                                        } else {
                                                            echo 'bg-green-100 text-green-600';
                                                        }
                                                        ?> flex items-center justify-center">
                                                        <i class="fas fa-user-tie"></i>
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 truncate">
                                                        <?php echo htmlspecialchars($guru['full_name'] ?? 'N/A'); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500 truncate">
                                                        <?php if (!empty($guru['bidang_keahlian'])): ?>
                                                            <?php echo htmlspecialchars($guru['bidang_keahlian']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($guru['pendidikan_terakhir'])): ?>
                                                            | <?php echo htmlspecialchars($guru['pendidikan_terakhir']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-sm font-semibold text-gray-900">
                                                        <?php echo $guru['jumlah_siswa'] ?? 0; ?> siswa
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        Pengalaman: <?php echo $guru['pengalaman_tahun'] ?? 0; ?> tahun
                                                    </div>
                                                    <?php if (!empty($guru['status_pegawai'])): ?>
                                                        <span class="mt-1 px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                            <?php echo $guru['status_pegawai'] == 'tetap'
                                                                ? 'bg-green-100 text-green-800'
                                                                : 'bg-yellow-100 text-yellow-800'; ?>">
                                                            <?php echo ucfirst($guru['status_pegawai']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="py-4 text-center text-gray-500">
                                        <i class="fas fa-chalkboard-teacher text-2xl mb-2"></i>
                                        <p>Tidak ada guru aktif</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="dataGuru.php?status=aktif"
                                class="inline-flex items-center text-sm text-blue-600 hover:text-blue-900">
                                <i class="fas fa-list mr-1"></i> Lihat semua guru aktif
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                    <i class="fas fa-bolt mr-2"></i> Quick Actions
                </h3>
                <div class="grid grid-cols-1 grid-cols-2 gap-4">
                    <a href="dataSiswa.php?action=tambah"
                        class="flex flex-col items-center justify-center p-6 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-blue-600 flex items-center justify-center mb-3">
                            <i class="fas fa-user-plus text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Tambah Siswa</span>
                        <span class="text-xs text-gray-500 mt-1">Registrasi baru</span>
                    </a>

                    <a href="dataGuru.php?action=tambah"
                        class="flex flex-col items-center justify-center p-6 bg-green-50 hover:bg-green-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-green-600 flex items-center justify-center mb-3">
                            <i class="fas fa-chalkboard-teacher text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Tambah Guru</span>
                        <span class="text-xs text-gray-500 mt-1">Rekrut pengajar</span>
                    </a>

                    <a href="pembayaran.php"
                        class="flex flex-col items-center justify-center p-6 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-indigo-600 flex items-center justify-center mb-3">
                            <i class="fas fa-money-bill-wave text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Pembayaran</span>
                        <span class="text-xs text-gray-500 mt-1">Kelola keuangan</span>
                    </a>

                    <a href="pengumuman.php?action=tambah"
                        class="flex flex-col items-center justify-center p-6 bg-yellow-50 hover:bg-yellow-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-yellow-600 flex items-center justify-center mb-3">
                            <i class="fas fa-bullhorn text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Pengumuman</span>
                        <span class="text-xs text-gray-500 mt-1">Buat pengumuman</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200">
            <div class="md:ml-64">
                <div class="container mx-auto py-4 px-4">
                    <div class="md:flex md:items-center md:justify-between">
                        <div class="text-sm text-gray-500">
                            <p>© <?php echo date('Y'); ?> Bimbel Esc - Admin Dashboard</p>
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
            toggle.addEventListener('click', function (e) {
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
        document.addEventListener('click', function (e) {
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