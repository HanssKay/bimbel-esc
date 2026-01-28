<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    header('Location: ../index.php');
    exit();
}

$guru_id = $_SESSION['role_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// FILTER DEFAULT HANYA MINGGUAN
$filter_jenis = 'mingguan'; // Hanya mingguan
$filter_bulan = $_GET['bulan'] ?? date('n');
$filter_tahun = $_GET['tahun'] ?? date('Y');

// Hitung minggu dalam bulan (1-4)
$current_day = date('j');
$filter_minggu = $_GET['minggu'] ?? ceil($current_day / 7);
if ($filter_minggu > 4) $filter_minggu = 4;
if ($filter_minggu < 1) $filter_minggu = 1;

$filter_kelas_id = $_GET['kelas_id'] ?? 0;
$filter_siswa_id = $_GET['siswa_id'] ?? 0;

// AMBIL DATA KELAS YANG DIAJAR GURU
$kelas_options = [];
if ($guru_id > 0) {
    try {
        $sql_kelas = "SELECT k.id, k.nama_kelas, k.tingkat
                      FROM kelas k
                      JOIN kelas_guru kg ON k.id = kg.kelas_id
                      WHERE kg.guru_id = ? AND k.status = 'aktif'
                      ORDER BY k.tingkat, k.nama_kelas";

        $stmt = $conn->prepare($sql_kelas);
        $stmt->bind_param("i", $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $kelas_options[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching kelas options: " . $e->getMessage());
    }
}

// AMBIL DATA SISWA (filter by kelas jika dipilih)
$siswa_options = [];
if ($guru_id > 0) {
    try {
        $sql_siswa = "SELECT s.id, s.nis, s.nama_lengkap, s.kelas as kelas_sekolah
                      FROM siswa s
                      JOIN kelas_siswa ks ON s.id = ks.siswa_id
                      JOIN kelas_guru kg ON ks.kelas_id = kg.kelas_id
                      WHERE kg.guru_id = ? AND ks.status = 'aktif'";

        if ($filter_kelas_id > 0) {
            $sql_siswa .= " AND ks.kelas_id = ?";
            $stmt = $conn->prepare($sql_siswa);
            $stmt->bind_param("ii", $guru_id, $filter_kelas_id);
        } else {
            $stmt = $conn->prepare($sql_siswa);
            $stmt->bind_param("i", $guru_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $siswa_options[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching siswa options: " . $e->getMessage());
    }
}

// VARIABEL UNTUK DATA LAPORAN
$laporan_data = [];
$total_siswa = 0;
$rata_rata_keseluruhan = 0;
$statistik_kategori = [
    'Sangat Baik' => 0,
    'Baik' => 0,
    'Cukup' => 0,
    'Kurang' => 0
];

// GENERATE LAPORAN BERDASARKAN JENIS
if ($guru_id > 0) {
    try {
        // BASE QUERY
        $sql = "SELECT 
                    ps.*,
                    s.nama_lengkap as nama_siswa,
                    s.nis,
                    s.kelas as kelas_sekolah,
                    k.nama_kelas as kelas_bimbel,
                    k.tingkat,
                    DATE_FORMAT(ps.tanggal_penilaian, '%W') as hari_penilaian,
                    DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                    MONTH(ps.tanggal_penilaian) as bulan_angka,
                    YEAR(ps.tanggal_penilaian) as tahun_angka
                FROM penilaian_siswa ps
                JOIN siswa s ON ps.siswa_id = s.id
                JOIN kelas k ON ps.kelas_id = k.id
                WHERE ps.guru_id = ?";

        $params = [$guru_id];
        $types = "i";

        // FILTER BERDASARKAN JENIS LAPORAN - HANYA MINGGUAN
        $sql .= " AND YEAR(ps.tanggal_penilaian) = ? 
                  AND MONTH(ps.tanggal_penilaian) = ?
                  AND FLOOR((DAY(ps.tanggal_penilaian) - 1) / 7) + 1 = ?";
        $params[] = $filter_tahun;
        $params[] = $filter_bulan;
        $params[] = $filter_minggu;
        $types .= "iii";

        // FILTER KELAS
        if ($filter_kelas_id > 0) {
            $sql .= " AND ps.kelas_id = ?";
            $params[] = $filter_kelas_id;
            $types .= "i";
        }

        // FILTER SISWA
        if ($filter_siswa_id > 0) {
            $sql .= " AND ps.siswa_id = ?";
            $params[] = $filter_siswa_id;
            $types .= "i";
        }

        // GROUP BY SISWA UNTUK ANALISIS PERKEMBANGAN
        $sql .= " GROUP BY ps.siswa_id";

        // ORDER BY
        $sql .= " ORDER BY ps.tanggal_penilaian DESC, s.nama_lengkap";

        // EKSEKUSI QUERY
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                // Hitung persentase jika belum ada
                if (!isset($row['persentase']) || empty($row['persentase'])) {
                    $row['persentase'] = round(($row['total_score'] / 50) * 100);
                }
                $laporan_data[] = $row;
            }
        }

        // HITUNG STATISTIK
        $total_siswa = count($laporan_data);

        if ($total_siswa > 0) {
            // Hitung rata-rata total skor
            $total_skor = 0;
            foreach ($laporan_data as $data) {
                $total_skor += $data['total_score'];
                if (isset($statistik_kategori[$data['kategori']])) {
                    $statistik_kategori[$data['kategori']]++;
                }
            }
            $rata_rata_keseluruhan = round($total_skor / $total_siswa, 1);
        }
    } catch (Exception $e) {
        error_log("Error generating laporan: " . $e->getMessage());
    }
}

// AMBIL DATA PERKEMBANGAN SISWA (UNTUK GRAFIK) - HANYA MINGGUAN
$perkembangan_data = [];
if ($filter_siswa_id > 0) {
    try {
        $sql_perkembangan = "SELECT 
                                DATE_FORMAT(tanggal_penilaian, '%Y-%m-%d') as tanggal,
                                total_score,
                                persentase,
                                kategori,
                                DAY(tanggal_penilaian) as hari,
                                MONTHNAME(tanggal_penilaian) as bulan_nama
                             FROM penilaian_siswa 
                             WHERE siswa_id = ? 
                               AND guru_id = ?
                               AND YEAR(tanggal_penilaian) = ? 
                               AND MONTH(tanggal_penilaian) = ?
                               AND FLOOR((DAY(tanggal_penilaian) - 1) / 7) + 1 = ?
                             ORDER BY tanggal_penilaian";
        
        $stmt = $conn->prepare($sql_perkembangan);
        if ($stmt) {
            $stmt->bind_param("iiiii", $filter_siswa_id, $guru_id, $filter_tahun, $filter_bulan, $filter_minggu);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $perkembangan_data[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching perkembangan data: " . $e->getMessage());
    }
}

// FUNGSI UNTUK FORMAT TANGGAL
function formatTanggalIndonesia($date)
{
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    $dayOfWeek = date('w', strtotime($date));
    $day = date('j', strtotime($date));
    $month = date('n', strtotime($date)) - 1;
    $year = date('Y', strtotime($date));

    return $hari[$dayOfWeek] . ', ' . $day . ' ' . $bulan[$month] . ' ' . $year;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Perkembangan - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
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

        .hover-row:hover {
            background-color: #F9FAFB;
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


        /* Modal styles */
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

        .modal-content-lg {
            max-width: 900px;
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

        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
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
        }

        .close:hover {
            opacity: 0.8;
        }

        /* Mobile Menu Overlay Style */
        #mobileMenu {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100%;
            z-index: 1200;
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
            z-index: 1199;
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

        /* Mobile specific styles */
        @media (max-width: 767px) {
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 0.75rem !important;
            }

            .stat-card {
                padding: 1rem !important;
            }

            .stat-card .text-2xl {
                font-size: 1.5rem !important;
            }

            .table-container {
                overflow-x: auto;
                font-size: 0.8rem;
            }

            .table-container th,
            .table-container td {
                padding: 0.5rem !important;
            }

            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.25rem;
            }

            .nav-tab {
                padding: 0.75rem;
                text-align: center;
            }
        }

        /* Sidebar menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        /* Loading animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }

        /* Stat card styling */
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Mobile table view */
        @media (max-width: 640px) {
            .mobile-table-header {
                display: flex;
                flex-direction: column;
            }

            .mobile-table-cell {
                display: flex;
                flex-direction: column;
                padding: 0.5rem 0.25rem !important;
            }

            .mobile-table-label {
                font-weight: 600;
                color: #6B7280;
                font-size: 0.7rem;
                margin-bottom: 0.125rem;
            }

            .mobile-table-value {
                font-size: 0.8rem;
            }

            .nav-tabs-container {
                flex-direction: column;
                gap: 0.5rem;
            }

            .nav-tab {
                width: 100%;
                margin-bottom: 0.25rem;
            }
        }

        /* Progress bar styles */
        .progress-bar {
            height: 0.5rem;
            border-radius: 0.25rem;
            overflow: hidden;
            background-color: #E5E7EB;
        }

        .progress-fill {
            height: 100%;
            transition: width 0.5s ease-in-out;
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

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
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
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Guru</p>
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
        <div class="bg-white shadow p-4 md:p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Laporan Perkembangan Siswa</h1>
                    <p class="text-gray-600 text-sm md:text-base">Analisis dan pantau perkembangan siswa</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <a href="riwayat.php" class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                        <i class="fas fa-history mr-2"></i> Lihat Riwayat
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Jenis Laporan Tabs -->
            <div class="bg-white rounded-xl shadow p-4 mb-6">
                <div class="flex flex-col md:flex-row space-y-2 md:space-y-0 md:space-x-4">
                    <div class="nav-tab p-3 rounded-xl bg-blue-600 text-white">
                        <i class="fas fa-calendar-week mr-2"></i> Laporan Mingguan
                    </div>
                </div>
                <p class="text-start text-gray-600 text-sm mt-2">
                    Pilih periode untuk melihat laporan perkembangan siswa
                </p>
            </div>

            <!-- Form Filter -->
            <div class="bg-white rounded-xl shadow p-4 md:p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4 text-sm md:text-base">
                    <i class="fas fa-filter mr-2"></i> Filter Laporan Mingguan
                </h3>

                <form method="GET" action="" class="space-y-4">
                    <input type="hidden" name="jenis" value="mingguan">

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3 md:gap-4 filter-grid">
                        <!-- Filter Tahun -->
                        <div>
                            <label class="block text-xs md:text-sm font-medium text-gray-700 mb-1">Tahun</label>
                            <select name="tahun"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <?php
                                $current_year = date('Y');
                                for ($i = $current_year; $i >= 2020; $i--):
                                    ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($filter_tahun == $i) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Bulan -->
                        <div>
                            <label class="block text-xs md:text-sm font-medium text-gray-700 mb-1">Bulan</label>
                            <select name="bulan" id="bulanSelect"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <?php
                                $bulan = [
                                    'Januari',
                                    'Februari',
                                    'Maret',
                                    'April',
                                    'Mei',
                                    'Juni',
                                    'Juli',
                                    'Agustus',
                                    'September',
                                    'Oktober',
                                    'November',
                                    'Desember'
                                ];
                                foreach ($bulan as $index => $nama):
                                    ?>
                                    <option value="<?php echo $index + 1; ?>" <?php echo ($filter_bulan == ($index + 1)) ? 'selected' : ''; ?>>
                                        <?php echo $nama; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Minggu (1-4) -->
                        <div>
                            <label class="block text-xs md:text-sm font-medium text-gray-700 mb-1">Minggu Ke</label>
                            <select name="minggu" id="mingguSelect"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <?php 
                                // Generate minggu 1-4
                                for ($i = 1; $i <= 4; $i++):
                                    $selected = ($filter_minggu == $i) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $i; ?>" <?php echo $selected; ?>>
                                        Minggu <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Kelas -->
                        <div>
                            <label class="block text-xs md:text-sm font-medium text-gray-700 mb-1">Kelas Bimbel</label>
                            <select name="kelas_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <option value="0">Semua Kelas</option>
                                <?php foreach ($kelas_options as $kelas): ?>
                                    <option value="<?php echo $kelas['id']; ?>" <?php echo $filter_kelas_id == $kelas['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($kelas['nama_kelas'] . ' (' . $kelas['tingkat'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Siswa -->
                        <div>
                            <label class="block text-xs md:text-sm font-medium text-gray-700 mb-1">Siswa</label>
                            <select name="siswa_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <option value="0">Semua Siswa</option>
                                <?php foreach ($siswa_options as $siswa): ?>
                                    <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa_id == $siswa['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($siswa['nama_lengkap'] . ' - ' . $siswa['nis']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center pt-4 border-t gap-3 md:gap-0">
                        <div class="text-xs md:text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            <?php
                            $bulan_nama = [
                                1 => 'Januari',
                                2 => 'Februari',
                                3 => 'Maret',
                                4 => 'April',
                                5 => 'Mei',
                                6 => 'Juni',
                                7 => 'Juli',
                                8 => 'Agustus',
                                9 => 'September',
                                10 => 'Oktober',
                                11 => 'November',
                                12 => 'Desember'
                            ];
                            $label_periode = 'Minggu ke-' . $filter_minggu . ' Bulan ' . $bulan_nama[$filter_bulan] . ' Tahun ' . $filter_tahun;
                            ?>
                            Laporan Mingguan: <?php echo $label_periode; ?>
                        </div>
                        <div class="flex space-x-2 md:space-x-3">
                            <a href="laporanGuru.php"
                                class="px-3 md:px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-xs md:text-sm">
                                Reset Filter
                            </a>
                            <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-3 md:px-4 py-2 rounded-lg flex items-center text-xs md:text-sm">
                                <i class="fas fa-filter mr-1 md:mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Card Statistik Ringkas -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-6 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-users text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Total Siswa Dinilai</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $total_siswa; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-chart-line text-green-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Rata-rata Skor</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $rata_rata_keseluruhan; ?>/50</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-purple-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-trophy text-purple-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Sangat Baik</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $statistik_kategori['Sangat Baik']; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Perlu Perhatian</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $statistik_kategori['Kurang'] + $statistik_kategori['Cukup']; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grafik dan Analisis -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-6">
                <!-- Grafik Perkembangan (jika siswa spesifik dipilih) -->
                <?php if ($filter_siswa_id > 0 && count($perkembangan_data) > 0): ?>
                    <div class="bg-white rounded-xl shadow p-4 md:p-6">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">Grafik Perkembangan Minggu <?php echo $filter_minggu; ?></h3>
                        <div class="h-48 md:h-64">
                            <canvas id="perkembanganChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Distribusi Kategori -->
                <div class="bg-white rounded-xl shadow p-4 md:p-6">
                    <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">Distribusi Kategori</h3>
                    <div class="space-y-2 md:space-y-3">
                        <?php foreach ($statistik_kategori as $kategori => $jumlah):
                            if ($total_siswa > 0):
                                $persentase = round(($jumlah / $total_siswa) * 100, 1);
                                // Tentukan warna berdasarkan kategori
                                $color_class = '';
                                $bg_class = '';
                                if ($kategori == 'Sangat Baik') {
                                    $color_class = 'text-green-600';
                                    $bg_class = 'bg-green-500';
                                } elseif ($kategori == 'Baik') {
                                    $color_class = 'text-blue-600';
                                    $bg_class = 'bg-blue-500';
                                } elseif ($kategori == 'Cukup') {
                                    $color_class = 'text-yellow-600';
                                    $bg_class = 'bg-yellow-500';
                                } else {
                                    $color_class = 'text-red-600';
                                    $bg_class = 'bg-red-500';
                                }
                                ?>
                                <div>
                                    <div class="flex justify-between mb-1 text-xs md:text-sm">
                                        <span class="font-medium <?php echo $color_class; ?>">
                                            <?php echo $kategori; ?>
                                        </span>
                                        <span class="font-medium text-gray-700">
                                            <?php echo $jumlah; ?> (<?php echo $persentase; ?>%)
                                        </span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="<?php echo $bg_class; ?> h-2 rounded-full"
                                            style="width: <?php echo $persentase; ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Tabel Detail Laporan -->
            <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
                <div class="p-4 md:p-6 border-b">
                    <h3 class="text-base md:text-lg font-semibold text-gray-800">Detail Perkembangan Siswa</h3>
                    <p class="text-xs md:text-sm text-gray-600 mt-1">
                        <?php echo $total_siswa; ?> siswa ditemukan
                    </p>
                </div>

                <?php if ($total_siswa == 0): ?>
                    <div class="p-6 md:p-8 text-center">
                        <div class="text-gray-400 mb-3 md:mb-4">
                            <i class="fas fa-chart-line text-4xl md:text-5xl"></i>
                        </div>
                        <h3 class="text-base md:text-lg font-medium text-gray-700 mb-2">Belum ada data laporan</h3>
                        <p class="text-xs md:text-sm text-gray-500 mb-4">
                            Pilih periode dan filter untuk melihat laporan.
                        </p>
                        <a href="inputNilai.php" 
                           class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i> Input Penilaian Baru
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50 hidden md:table-header-group">
                                <tr>
                                    <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Siswa</th>
                                    <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Kelas</th>
                                    <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Total Skor</th>
                                    <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Kategori</th>
                                    <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($laporan_data as $data):
                                    // Tentukan warna badge berdasarkan kategori
                                    $badge_class = '';
                                    if ($data['kategori'] == 'Sangat Baik')
                                        $badge_class = 'badge-sangat-baik';
                                    elseif ($data['kategori'] == 'Baik')
                                        $badge_class = 'badge-baik';
                                    elseif ($data['kategori'] == 'Cukup')
                                        $badge_class = 'badge-cukup';
                                    else
                                        $badge_class = 'badge-kurang';
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <!-- Desktop View -->
                                        <td class="px-4 md:px-6 py-3 md:py-4 hidden md:table-cell">
                                            <div class="flex items-center">
                                                <div
                                                    class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <span class="text-blue-800 font-medium">
                                                        <?php echo strtoupper(substr($data['nama_siswa'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($data['nama_siswa']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        NIS: <?php echo $data['nis']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap hidden md:table-cell">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($data['kelas_bimbel']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?php echo $data['kelas_sekolah']; ?></div>
                                        </td>
                                        <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap hidden md:table-cell">
                                            <div class="text-lg font-bold text-gray-900">
                                                <?php echo $data['total_score']; ?>/50
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                Rata: <?php echo round($data['total_score'] / 5, 1); ?>/10
                                            </div>
                                        </td>
                                        <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap hidden md:table-cell">
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $data['kategori']; ?> (<?php echo $data['persentase']; ?>%)
                                            </span>
                                        </td>
                                        <td
                                            class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap text-sm font-medium hidden md:table-cell">
                                            <div class="">
                                                <button onclick="showDetail(<?php echo $data['id']; ?>)"
                                                    class="text-blue-600 text-center hover:text-blue-900" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>

                                        <!-- Mobile View -->
                                        <td class="md:hidden px-4 py-3 border-b border-gray-200">
                                            <div class="space-y-2">
                                                <!-- Row 1: Siswa Info -->
                                                <div class="flex justify-between items-start">
                                                    <div class="mobile-table-cell">
                                                        <div class="mobile-table-label">Siswa</div>
                                                        <div class="flex items-center">
                                                            <div
                                                                class="flex-shrink-0 w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-2">
                                                                <span class="text-blue-800 font-medium text-xs">
                                                                    <?php echo strtoupper(substr($data['nama_siswa'], 0, 1)); ?>
                                                                </span>
                                                            </div>
                                                            <div>
                                                                <div class="mobile-table-value font-medium">
                                                                    <?php echo htmlspecialchars($data['nama_siswa']); ?>
                                                                </div>
                                                                <div class="text-xs text-gray-500">
                                                                    NIS: <?php echo $data['nis']; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="action-buttons">
                                                        <button onclick="showDetail(<?php echo $data['id']; ?>)"
                                                            class="px-2 py-1 bg-blue-600 text-white rounded text-xs">
                                                            <i class="fas fa-eye"></i> Detail
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Row 2: Kelas -->
                                                <div class="mobile-table-cell">
                                                    <div class="mobile-table-label">Kelas</div>
                                                    <div class="text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($data['kelas_bimbel']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?php echo $data['kelas_sekolah']; ?>
                                                    </div>
                                                </div>

                                                <!-- Row 3: Total Skor & Kategori -->
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div class="mobile-table-cell">
                                                        <div class="mobile-table-label">Total Skor</div>
                                                        <div class="text-lg font-bold text-gray-900">
                                                            <?php echo $data['total_score']; ?>/50
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            Rata: <?php echo round($data['total_score'] / 5, 1); ?>/10
                                                        </div>
                                                    </div>
                                                    <div class="mobile-table-cell">
                                                        <div class="mobile-table-label">Kategori</div>
                                                        <span class="badge <?php echo $badge_class; ?> text-xs">
                                                            <?php echo $data['kategori']; ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <!-- Row 4: Action Buttons -->
                                                <div class="grid grid-cols-2 gap-2 mt-2">
                                                    <button onclick="showDetail(<?php echo $data['id']; ?>)"
                                                        class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-xs flex items-center justify-center">
                                                        <i class="fas fa-eye mr-2"></i> Detail
                                                    </button>
                                                    <button
                                                        onclick="showAnalisisDetail(<?php echo $data['siswa_id']; ?>, '<?php echo htmlspecialchars(addslashes($data['nama_siswa'])); ?>')"
                                                        class="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-xs flex items-center justify-center">
                                                        <i class="fas fa-chart-bar mr-2"></i> Analisis
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Kesimpulan dan Rekomendasi -->
            <?php if ($total_siswa > 0): ?>
                <div class="bg-white rounded-xl shadow p-4 md:p-6 mb-6">
                    <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">Analisis & Rekomendasi</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 md:p-4 rounded">
                            <h4 class="font-medium text-gray-800 mb-1 md:mb-2 text-sm md:text-base">
                                <i class="fas fa-lightbulb text-yellow-600 mr-1 md:mr-2"></i> Analisis Kesimpulan
                            </h4>
                            <p class="text-xs md:text-sm text-gray-700">
                                <?php
                                $persentase_baik = ($statistik_kategori['Sangat Baik'] + $statistik_kategori['Baik']) / $total_siswa * 100;
                                $persentase_perhatian = ($statistik_kategori['Kurang'] + $statistik_kategori['Cukup']) / $total_siswa * 100;

                                if ($persentase_baik >= 70) {
                                    echo "Prestasi siswa sangat baik. " .
                                        number_format($persentase_baik, 1) . "% siswa mencapai kategori Baik hingga Sangat Baik.";
                                } elseif ($persentase_baik >= 50) {
                                    echo "Prestasi siswa cukup baik. " .
                                        number_format($persentase_baik, 1) . "% siswa mencapai kategori Baik atau lebih tinggi.";
                                } else {
                                    echo "Perlu perhatian lebih. " .
                                        number_format($persentase_perhatian, 1) . "% siswa membutuhkan bimbingan tambahan.";
                                }
                                ?>
                            </p>
                        </div>
                        <div class="bg-green-50 border-l-4 border-green-400 p-3 md:p-4 rounded">
                            <h4 class="font-medium text-gray-800 mb-1 md:mb-2 text-sm md:text-base">
                                <i class="fas fa-tasks text-green-600 mr-1 md:mr-2"></i> Rekomendasi Tindakan
                            </h4>
                            <ul class="text-xs md:text-sm text-gray-700 space-y-1">
                                <?php
                                if ($statistik_kategori['Kurang'] > 0) {
                                    echo "<li>• Bimbingan intensif untuk " . $statistik_kategori['Kurang'] . " siswa (Kurang)</li>";
                                }
                                if ($statistik_kategori['Cukup'] > 0) {
                                    echo "<li>• Latihan tambahan untuk " . $statistik_kategori['Cukup'] . " siswa (Cukup)</li>";
                                }
                                if ($statistik_kategori['Sangat Baik'] > 0) {
                                    echo "<li>• Tantangan untuk " . $statistik_kategori['Sangat Baik'] . " siswa (Sangat Baik)</li>";
                                }
                                ?>
                                <li>• Review materi dengan nilai rendah</li>
                            </ul>
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
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Laporan Perkembangan</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Terakhir update: <?php echo date('d F Y H:i'); ?>
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

    <!-- MODAL DETAIL PENILAIAN -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold"><i class="fas fa-eye mr-2"></i> Detail Penilaian</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalContent">
                <!-- Content akan diisi oleh JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal()"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
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



        // Inisialisasi Chart.js untuk grafik perkembangan
        <?php if ($filter_siswa_id > 0 && count($perkembangan_data) > 0): ?>
            document.addEventListener('DOMContentLoaded', function () {
                const ctx = document.getElementById('perkembanganChart').getContext('2d');

                // Siapkan data
                const labels = [];
                const dataScores = [];

                <?php foreach ($perkembangan_data as $data): ?>
                    labels.push("Hari <?php echo $data['hari']; ?>");
                    dataScores.push(<?php echo $data['total_score']; ?>);
                <?php endforeach; ?>

                const perkembanganChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Total Skor',
                            data: dataScores,
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: false,
                                max: 50,
                                min: 0,
                                title: {
                                    display: true,
                                    text: 'Total Skor'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Hari'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: true
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return `Skor: ${context.raw}/50`;
                                    }
                                }
                            }
                        }
                    }
                });
            });
        <?php endif; ?>

        let currentPenilaianId = null;
        
        // Fungsi untuk menampilkan detail penilaian
        function showDetail(penilaianId) {
            currentPenilaianId = penilaianId;
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');
            
            // Close mobile menu if open
            if (mobileMenu) {
                mobileMenu.classList.remove('menu-open');
            }
            if (menuOverlay) {
                menuOverlay.classList.remove('active');
            }
            
            // Tampilkan loading
            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Memuat data penilaian...</p>
                </div>
            `;
            
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Ambil data dari server
            fetch(`get_penilaian_detail.php?id=${penilaianId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        content.innerHTML = renderDetailContent(data.data);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-8 text-red-600">
                                <i class="fas fa-exclamation-triangle text-3xl"></i>
                                <p class="mt-2 text-lg">${data.message || 'Gagal memuat data'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="text-center py-8 text-red-600">
                            <i class="fas fa-exclamation-triangle text-3xl"></i>
                            <p class="mt-2 text-lg">Terjadi kesalahan</p>
                            <p class="text-sm mt-2">Silakan refresh halaman dan coba lagi</p>
                        </div>
                    `;
                });
        }
        
        // Render konten detail
        function renderDetailContent(data) {
            // Tentukan warna kategori
            let kategoriClass = '';
            switch(data.kategori) {
                case 'Sangat Baik': kategoriClass = 'text-green-600 bg-green-100'; break;
                case 'Baik': kategoriClass = 'text-blue-600 bg-blue-100'; break;
                case 'Cukup': kategoriClass = 'text-yellow-600 bg-yellow-100'; break;
                default: kategoriClass = 'text-red-600 bg-red-100';
            }
            
            return `
                <div class="space-y-6">
                    <!-- Header Info -->
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div>
                                <h3 class="text-xl font-bold text-gray-800">${data.nama_siswa}</h3>
                                <p class="text-gray-600">NIS: ${data.nis} | ${data.kelas_bimbel}</p>
                                <p class="text-gray-600">${data.kelas_sekolah} | ${data.tanggal_format}</p>
                            </div>
                            <div class="mt-2 md:mt-0">
                                <span class="px-3 py-1 rounded-full text-sm font-medium ${kategoriClass}">
                                    ${data.kategori} (${data.persentase}%)
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Score -->
                    <div class="text-center p-4 md:p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg">
                        <h4 class="text-lg font-semibold text-gray-700 mb-2">Total Skor</h4>
                        <div class="text-4xl md:text-5xl font-bold text-blue-600">${data.total_score}/50</div>
                        <div class="mt-2 text-gray-600 text-sm md:text-base">Nilai rata-rata per indikator: ${(data.total_score/5).toFixed(1)}/10</div>
                    </div>
                    
                    <!-- Indikator Nilai -->
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800 mb-4">Detail Indikator</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                            ${renderIndicator('Kemauan Belajar', data.willingness_learn)}
                            ${renderIndicator('Konsentrasi', data.concentration)}
                            ${renderIndicator('Berpikir Kritis', data.critical_thinking)}
                            ${renderIndicator('Kemandirian', data.independence)}
                            ${renderIndicator('Pemecahan Masalah', data.problem_solving)}
                        </div>
                    </div>
                    
                    <!-- Catatan Guru -->
                    ${data.catatan_guru ? `
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">Catatan Guru</h4>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                            <p class="text-gray-700">${data.catatan_guru}</p>
                        </div>
                    </div>
                    ` : ''}
                    
                    <!-- Rekomendasi -->
                    ${data.rekomendasi ? `
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">Rekomendasi</h4>
                        <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded">
                            <p class="text-gray-700">${data.rekomendasi}</p>
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Helper untuk render indikator
        function renderIndicator(label, value) {
            const percentage = (value / 10) * 100;
            let color = '';
            if (value >= 9) color = 'bg-green-500';
            else if (value >= 7) color = 'bg-blue-500';
            else if (value >= 5) color = 'bg-yellow-500';
            else color = 'bg-red-500';
            
            return `
                <div class="bg-white border border-gray-200 rounded-lg p-3 md:p-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="font-medium text-gray-700 text-sm md:text-base">${label}</span>
                        <span class="font-bold text-gray-900 text-sm md:text-base">${value}/10</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="${color} h-2 rounded-full" style="width: ${percentage}%"></div>
                    </div>
                </div>
            `;
        }
        
        // Tutup modal detail
        function closeModal() {
            const modal = document.getElementById('detailModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            currentPenilaianId = null;
        }

        // ==============================
        // FUNGSI ANALISIS DETAIL
        // ==============================

        // Fungsi untuk menampilkan analisis detail siswa
        function showAnalisisDetail(siswaId, namaSiswa) {
            const modal = document.getElementById('analisisModal');
            const content = document.getElementById('analisisContent');

            // Close mobile menu if open
            if (mobileMenu) {
                mobileMenu.classList.remove('menu-open');
            }
            if (menuOverlay) {
                menuOverlay.classList.remove('active');
            }

            // Tampilkan loading
            content.innerHTML = `
                <div class="text-center py-4 md:py-8">
                    <div class="animate-spin rounded-full h-8 w-8 md:h-12 md:w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-2 md:mt-4 text-gray-600 text-sm md:text-base">Memuat analisis...</p>
                </div>
            `;

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Ambil data analisis dari server
            fetch(`get_analisis_siswa.php?siswa_id=${siswaId}&jenis=<?php echo $filter_jenis; ?>&tahun=<?php echo $filter_tahun; ?>&bulan=<?php echo $filter_bulan; ?>&minggu=<?php echo $filter_minggu; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        content.innerHTML = renderAnalisisContent(data.data, namaSiswa);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-4 md:py-8 text-red-600">
                                <i class="fas fa-exclamation-triangle text-2xl md:text-3xl"></i>
                                <p class="mt-2 text-base md:text-lg">${data.message || 'Gagal memuat analisis'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="text-center py-4 md:py-8 text-red-600">
                            <i class="fas fa-exclamation-triangle text-2xl md:text-3xl"></i>
                            <p class="mt-2 text-base md:text-lg">Terjadi kesalahan</p>
                        </div>
                    `;
                });
        }

        // // Render konten analisis
        // function renderAnalisisContent(data, namaSiswa) {
        //     // Format responsif untuk mobile
        //     const isMobile = window.innerWidth <= 768;

        //     return `
        //         <div class="space-y-4 md:space-y-6">
        //             <!-- Header -->
        //             <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-3 md:p-4 rounded-lg">
        //                 <h3 class="text-base md:text-xl font-bold text-gray-800">Analisis Perkembangan</h3>
        //                 <p class="text-xs md:text-sm text-gray-600 mt-1">${namaSiswa}</p>
        //             </div>
                    
        //             <!-- Statistik Ringkas -->
        //             <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-4">
        //                 <div class="bg-white border border-gray-200 rounded-lg p-3 text-center">
        //                     <div class="text-lg md:text-2xl font-bold text-blue-600">${data.rata_total || 0}</div>
        //                     <div class="text-xs md:text-sm text-gray-500">Rata Skor</div>
        //                 </div>
        //                 <div class="bg-white border border-gray-200 rounded-lg p-3 text-center">
        //                     <div class="text-lg md:text-2xl font-bold text-green-600">${data.total_penilaian || 0}</div>
        //                     <div class="text-xs md:text-sm text-gray-500">Jumlah Penilaian</div>
        //                 </div>
        //                 <div class="bg-white border border-gray-200 rounded-lg p-3 text-center">
        //                     <div class="text-lg md:text-2xl font-bold text-purple-600">${data.kategori_terakhir || '-'}</div>
        //                     <div class="text-xs md:text-sm text-gray-500">Kategori</div>
        //                 </div>
        //                 <div class="bg-white border border-gray-200 rounded-lg p-3 text-center">
        //                     <div class="text-lg md:text-2xl font-bold ${data.trend === 'naik' ? 'text-green-600' : data.trend === 'turun' ? 'text-red-600' : 'text-yellow-600'}">
        //                         ${data.trend === 'naik' ? '↑ Naik' : data.trend === 'turun' ? '↓ Turun' : '→ Stabil'}
        //                     </div>
        //                     <div class="text-xs md:text-sm text-gray-500">Trend</div>
        //                 </div>
        //             </div>
                    
        //             <!-- Pesan sederhana -->
        //             <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 md:p-4 rounded">
        //                 <p class="text-xs md:text-sm text-gray-700">
        //                     Untuk analisis detail lengkap, silakan buka di perangkat desktop atau tablet.
        //                     ${isMobile ? 'Gunakan mode landscape untuk pengalaman yang lebih baik.' : ''}
        //                 </p>
        //             </div>
        //         </div>
        //     `;
        // }

        // // Tutup modal analisis
        // function closeAnalisisModal() {
        //     const modal = document.getElementById('analisisModal');
        //     modal.style.display = 'none';
        //     document.body.style.overflow = 'auto';
        // }

        // ==============================
        // FUNGSI UMUM
        // ==============================

        // Fungsi untuk mencetak laporan
        function printLaporan() {
            window.print();
        }

        // Tutup semua modal saat klik di luar
        window.onclick = function (event) {
            const detailModal = document.getElementById('detailModal');
            const analisisModal = document.getElementById('analisisModal');
            
            if (event.target === detailModal) {
                closeModal();
            }
            if (event.target === analisisModal) {
                closeAnalisisModal();
            }
        }

        // Auto-update dropdown siswa saat kelas berubah
        document.querySelector('select[name="kelas_id"]')?.addEventListener('change', function () {
            const kelasId = this.value;

            // Jika kelas dipilih, submit form untuk update filter
            if (kelasId !== '0') {
                this.form.submit();
            }
        });

        // Auto update minggu saat bulan berubah
        document.getElementById('bulanSelect')?.addEventListener('change', function() {
            const tahun = document.querySelector('select[name="tahun"]').value;
            const bulan = this.value;
            
            // Reset minggu ke 1 saat bulan berubah
            document.getElementById('mingguSelect').value = 1;
            
            // Submit form
            this.form.submit();
        });

        // Validasi minggu 1-4
        document.getElementById('mingguSelect')?.addEventListener('change', function() {
            const minggu = parseInt(this.value);
            if (minggu < 1 || minggu > 4) {
                this.value = 1;
                alert('Minggu harus antara 1-4');
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