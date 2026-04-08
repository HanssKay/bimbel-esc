<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['user_role'] != 'admin') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$full_name = htmlspecialchars($_SESSION['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$currentPage = basename($_SERVER['PHP_SELF']);

// FUNGSI LOGGING
function writeLog($message, $type = 'INFO')
{
    $log_message = date('Y-m-d H:i:s') . " [$type] " . $message . PHP_EOL;
    error_log($log_message);
}

// Set default periode (bulan ini)
$tahun = date('Y');
$bulan = date('m');
$periode = "$tahun-$bulan";

if (isset($_GET['periode']) && !empty($_GET['periode'])) {
    $periode = $_GET['periode'];
    list($tahun, $bulan) = explode('-', $periode);
}

// Filter dengan search
$filter_guru = isset($_GET['guru_id']) && is_numeric($_GET['guru_id']) ? (int) $_GET['guru_id'] : 0;
$filter_siswa = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;
$filter_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_nama_guru = isset($_GET['nama_guru']) ? trim($_GET['nama_guru']) : '';

// Hitung tanggal awal dan akhir bulan
$tanggal_awal = date('Y-m-01', strtotime("$tahun-$bulan-01"));
$tanggal_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));

// AMBIL DATA UNTUK FILTER (siswa dan guru)
$siswa_list = [];
$guru_list = [];

try {
    // Daftar semua siswa aktif 
    $sql_siswa = "SELECT s.id, s.nama_lengkap, s.kelas, s.sekolah_asal
                  FROM siswa s
                  WHERE s.status = 'aktif'
                  ORDER BY s.nama_lengkap";
    $result_siswa = $conn->query($sql_siswa);
    if ($result_siswa) {
        while ($row = $result_siswa->fetch_assoc()) {
            $siswa_list[] = $row;
        }
    }

    // Daftar semua guru aktif
    $sql_guru = "SELECT g.id, u.full_name as nama_guru, g.bidang_keahlian
                FROM guru g 
                INNER JOIN users u ON g.user_id = u.id 
                WHERE g.status = 'aktif' 
                ORDER BY u.full_name";
    $result_guru = $conn->query($sql_guru);
    if ($result_guru) {
        while ($row = $result_guru->fetch_assoc()) {
            $guru_list[] = $row;
        }
    }

    writeLog("Data filter loaded: " . count($siswa_list) . " siswa, " . count($guru_list) . " guru", 'INFO');

} catch (Exception $e) {
    writeLog("Error fetching filter data: " . $e->getMessage(), 'ERROR');
}

// ============================================
// AMBIL DATA REKAP ABSENSI - SEMUA SISWA
// ============================================
$rekap_data = [];
$statistik = [
    'total_siswa' => 0,
    'total_guru' => 0,
    'total_sesi' => 0,
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

// Ambil semua guru (atau filter guru)
$guru_ids = [];
if ($filter_guru > 0) {
    $guru_ids[] = $filter_guru;
} else {
    $sql_guru_all = "SELECT id FROM guru WHERE status = 'aktif'";
    $result_guru_all = $conn->query($sql_guru_all);
    if ($result_guru_all) {
        while ($row = $result_guru_all->fetch_assoc()) {
            $guru_ids[] = $row['id'];
        }
    }
}
$statistik['total_guru'] = count($guru_ids);

// Untuk setiap guru, ambil data siswa dan rekap absensi
foreach ($guru_ids as $guru_id) {
    // Ambil data guru
    $sql_nama_guru = "SELECT u.full_name FROM guru g JOIN users u ON g.user_id = u.id WHERE g.id = ?";
    $stmt_nama = $conn->prepare($sql_nama_guru);
    $stmt_nama->bind_param("i", $guru_id);
    $stmt_nama->execute();
    $result_nama = $stmt_nama->get_result();
    $nama_guru = $result_nama->fetch_assoc()['full_name'] ?? 'Guru';
    $stmt_nama->close();

    // ========== PERBAIKAN: AMBIL SEMUA SISWA (TANPA FILTER JADWAL) ==========
    $sql_siswa = "SELECT DISTINCT 
                    s.id,
                    s.nama_lengkap,
                    s.kelas as kelas_sekolah
                  FROM siswa s
                  INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                  WHERE ps.status = 'aktif'
                  AND s.status = 'aktif'";

    $params = [];
    $types = "";

    // Filter siswa jika ada
    if ($filter_siswa > 0) {
        $sql_siswa .= " AND s.id = ?";
        $params[] = $filter_siswa;
        $types .= "i";
    } elseif (!empty($filter_nama_siswa)) {
        $sql_siswa .= " AND s.nama_lengkap LIKE ?";
        $params[] = "%" . $filter_nama_siswa . "%";
        $types .= "s";
    }

    $sql_siswa .= " ORDER BY s.nama_lengkap";

    $stmt_siswa = $conn->prepare($sql_siswa);
    if (!empty($params)) {
        $stmt_siswa->bind_param($types, ...$params);
    }
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();

    $siswa_data = [];
    while ($row = $result_siswa->fetch_assoc()) {
        $siswa_data[$row['id']] = [
            'id' => $row['id'],
            'nama_lengkap' => $row['nama_lengkap'],
            'kelas_sekolah' => $row['kelas_sekolah'],
            'total_hadir' => 0,
            'total_izin' => 0,
            'total_sakit' => 0,
            'total_alpha' => 0,
            'total_sesi' => 0
        ];
    }
    $stmt_siswa->close();

    // Jika ada siswa untuk guru ini, ambil data absensi
    if (!empty($siswa_data)) {
        $siswa_ids = array_keys($siswa_data);
        $placeholders = implode(',', array_fill(0, count($siswa_ids), '?'));

        // Ambil data absensi untuk periode ini (dari GURU INI)
        $sql_absensi = "SELECT 
                          a.siswa_id,
                          a.status,
                          COUNT(*) as jumlah
                        FROM absensi_siswa a
                        WHERE a.siswa_id IN ($placeholders)
                        AND a.guru_id = ?
                        AND a.tanggal_absensi BETWEEN ? AND ?
                        GROUP BY a.siswa_id, a.status";

        $params_absensi = array_merge($siswa_ids, [$guru_id, $tanggal_awal, $tanggal_akhir]);
        $types_absensi = str_repeat('i', count($siswa_ids)) . "iss";

        $stmt_absensi = $conn->prepare($sql_absensi);
        $stmt_absensi->bind_param($types_absensi, ...$params_absensi);
        $stmt_absensi->execute();
        $result_absensi = $stmt_absensi->get_result();

        while ($row = $result_absensi->fetch_assoc()) {
            $siswa_id = $row['siswa_id'];
            $status = $row['status'];
            $jumlah = $row['jumlah'];

            if (isset($siswa_data[$siswa_id])) {
                $siswa_data[$siswa_id]['total_' . $status] = $jumlah;
                $siswa_data[$siswa_id]['total_sesi'] += $jumlah;
                $statistik[$status] += $jumlah;
                $statistik['total_sesi'] += $jumlah;
            }
        }
        $stmt_absensi->close();

        // TAMPILKAN SEMUA SISWA YANG PUNYA ABSENSI (total_sesi > 0)
        $siswa_with_absensi = array_filter($siswa_data, function ($s) {
            return $s['total_sesi'] > 0;
        });

        if (!empty($siswa_with_absensi)) {
            $rekap_data[] = [
                'guru_id' => $guru_id,
                'nama_guru' => $nama_guru,
                'siswa' => array_values($siswa_with_absensi)
            ];
            $statistik['total_siswa'] += count($siswa_with_absensi);
        }
    }
}

// Urutkan rekap_data berdasarkan nama guru
usort($rekap_data, function ($a, $b) {
    return strcmp($a['nama_guru'], $b['nama_guru']);
});

// Ambil nama yang dipilih untuk ditampilkan
$selected_guru_name = '';
if ($filter_guru > 0) {
    foreach ($guru_list as $guru) {
        if ($guru['id'] == $filter_guru) {
            $selected_guru_name = $guru['nama_guru'];
            break;
        }
    }
} elseif (!empty($filter_nama_guru)) {
    $selected_guru_name = $filter_nama_guru;
}

$selected_siswa_name = '';
if ($filter_siswa > 0) {
    foreach ($siswa_list as $siswa) {
        if ($siswa['id'] == $filter_siswa) {
            $selected_siswa_name = $siswa['nama_lengkap'] . ' (' . $siswa['kelas'] . ')';
            break;
        }
    }
} elseif (!empty($filter_nama_siswa)) {
    $selected_siswa_name = $filter_nama_siswa;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .table-responsive {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 800px;
            }
        }

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

        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

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

            .filter-form {
                grid-template-columns: 1fr !important;
            }
        }

        @media (min-width: 768px) {
            .filter-form {
                grid-template-columns: repeat(5, 1fr) !important;
            }
        }

        .badge-count {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Autocomplete styles */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-input {
            width: 100%;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            background-color: white;
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .autocomplete-clear {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            display: none;
        }

        .autocomplete-clear:hover {
            color: #6b7280;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .autocomplete-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover {
            background-color: #f9fafb;
        }

        .autocomplete-item.active {
            background-color: #eff6ff;
        }

        .autocomplete-item .item-nama {
            font-weight: 600;
            color: #1f2937;
        }

        .autocomplete-item .item-info {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .no-results {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .modal-header .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .modal-header .close-modal:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
        }

        .detail-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            border-left: 4px solid;
            transition: transform 0.2s;
        }

        .detail-card:hover {
            transform: translateX(5px);
        }

        .detail-card.hadir {
            border-left-color: #10b981;
        }

        .detail-card.izin {
            border-left-color: #f59e0b;
        }

        .detail-card.sakit {
            border-left-color: #3b82f6;
        }

        .detail-card.alpha {
            border-left-color: #ef4444;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-hadir {
            background: #d1fae5;
            color: #065f46;
        }

        .status-izin {
            background: #fed7aa;
            color: #92400e;
        }

        .status-sakit {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-alpha {
            background: #fee2e2;
            color: #991b1b;
        }

        .loading-spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .btn-detail {
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            transition: all 0.2s;
        }

        .btn-detail:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .btn-detail i {
            margin-right: 0.25rem;
        }

        /* Sembunyikan tombol detail di mobile jika perlu */
        @media (max-width: 640px) {
            .btn-detail span {
                display: none;
            }

            .btn-detail i {
                margin-right: 0;
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Rekap Absensi Admin</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Admin</p>
                </div>
            </div>
        </div>
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
                        <p class="text-sm text-blue-300">Admin</p>
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
                    <h1 class="md:text-2xl text-xl font-bold text-gray-800">
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi Seluruh Siswa
                    </h1>
                    <p class="text-gray-600 md:text-md text-sm">Rekapitulasi absensi per siswa - Klik tombol Detail
                        untuk melihat tanggal</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Rekap</h3>
                <form method="GET" id="filterForm" class="filter-form grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Periode (Bulan-Tahun)
                        </label>
                        <input type="month" name="periode" value="<?php echo $periode; ?>"
                             onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Filter Guru -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> Cari Guru
                        </label>
                        <div class="autocomplete-container">
                            <input type="text" id="searchGuru" class="autocomplete-input"
                                placeholder="Ketik nama guru..." autocomplete="off"
                                value="<?php echo htmlspecialchars($selected_guru_name); ?>">
                            <input type="hidden" name="guru_id" id="selectedGuruId" value="<?php echo $filter_guru; ?>">
                            <input type="hidden" name="nama_guru" id="selectedGuruName"
                                value="<?php echo htmlspecialchars($selected_guru_name); ?>">
                            <button type="button" id="clearGuruSearch" class="autocomplete-clear">
                                <i class="fas fa-times"></i>
                            </button>
                            <div id="guruDropdown" class="autocomplete-dropdown"></div>
                        </div>
                    </div>

                    <!-- Filter Siswa -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-graduate mr-1"></i> Cari Siswa
                        </label>
                        <div class="autocomplete-container">
                            <input type="text" id="searchSiswa" class="autocomplete-input"
                                placeholder="Ketik nama siswa..." autocomplete="off"
                                value="<?php echo htmlspecialchars($selected_siswa_name); ?>">
                            <input type="hidden" name="siswa_id" id="selectedSiswaId"
                                value="<?php echo $filter_siswa; ?>">
                            <input type="hidden" name="nama_siswa" id="selectedSiswaName"
                                value="<?php echo htmlspecialchars($selected_siswa_name); ?>">
                            <button type="button" id="clearSiswaSearch" class="autocomplete-clear">
                                <i class="fas fa-times"></i>
                            </button>
                            <div id="siswaDropdown" class="autocomplete-dropdown"></div>
                        </div>
                    </div>

                    <div class="flex items-end">
                        <div class="flex space-x-1 w-full mb-1">
                            <button type="submit"
                                class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-filter mr-2"></i> Terapkan
                            </button>
                            <a href="rekapAbsensi.php"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>

                <!-- Active Filters Display -->
                <?php if ($filter_guru > 0 || $filter_siswa > 0 || !empty($filter_nama_guru) || !empty($filter_nama_siswa)): ?>
                    <div class="mt-3 pt-3 border-t border-gray-200">
                        <div class="flex flex-wrap gap-2 items-center">
                            <span class="text-sm text-gray-600">Filter aktif:</span>
                            <?php if ($filter_guru > 0 && $selected_guru_name): ?>
                                <span
                                    class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <i class="fas fa-chalkboard-teacher mr-1"></i>
                                    Guru: <?php echo htmlspecialchars($selected_guru_name); ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['guru_id' => 0, 'nama_guru' => ''])); ?>"
                                        class="ml-2 text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </span>
                            <?php endif; ?>
                            <?php if ($filter_siswa > 0 && $selected_siswa_name): ?>
                                <span
                                    class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <i class="fas fa-user-graduate mr-1"></i>
                                    Siswa: <?php echo htmlspecialchars($selected_siswa_name); ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['siswa_id' => 0, 'nama_siswa' => ''])); ?>"
                                        class="ml-2 text-green-600 hover:text-green-800">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-gray-500 mt-3">
                    <i class="fas fa-info-circle"></i> Menampilkan rekap absensi bulan
                    <?php echo date('F Y', strtotime($periode . '-01')); ?>
                </p>
            </div>

            <!-- Statistik Total -->
            <div class="mb-6 bg-white rounded-lg shadow p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-chart-pie mr-2"></i>
                            Statistik Rekap - <?php echo date('F Y', strtotime($periode . '-01')); ?>
                        </h3>
                        <p class="text-gray-600 text-sm">
                            <?php if ($filter_guru > 0 && $selected_guru_name): ?>
                                Guru: <?php echo htmlspecialchars($selected_guru_name); ?>
                            <?php endif; ?>
                            <?php if ($filter_siswa > 0 && $selected_siswa_name): ?>
                                <?php echo ($filter_guru > 0) ? ' | ' : ''; ?>
                                Siswa: <?php echo htmlspecialchars($selected_siswa_name); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-check text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Hadir</p>
                                <p class="text-xl font-bold text-green-600"><?php echo $statistik['hadir']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-yellow-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-envelope text-yellow-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Izin</p>
                                <p class="text-xl font-bold text-yellow-600"><?php echo $statistik['izin']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-thermometer text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Sakit</p>
                                <p class="text-xl font-bold text-blue-600"><?php echo $statistik['sakit']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-red-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-red-100 rounded-lg mr-3">
                                <i class="fas fa-times text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Alpha</p>
                                <p class="text-xl font-bold text-red-600"><?php echo $statistik['alpha']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 mt-3 gap-3">
                    <div class="bg-gray-100 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-100 rounded-lg mr-3">
                                <i class="fas fa-users text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Guru</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_guru']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-100 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-100 rounded-lg mr-3">
                                <i class="fas fa-user-graduate text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Siswa</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_siswa']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rekap Per Guru -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-table mr-2"></i>
                            Rekap Absensi Per Guru - <?php echo date('F Y', strtotime($periode . '-01')); ?>
                            <?php if (!empty($rekap_data)): ?>
                                <span class="text-sm text-gray-600 font-normal">
                                    (<?php echo count($rekap_data); ?> guru ditemukan)
                                </span>
                            <?php endif; ?>
                        </h3>
                        <div class="text-sm text-gray-500">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo date('d/m/Y H:i:s'); ?>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <?php if (empty($rekap_data)): ?>
                        <div class="p-12 text-center text-gray-500">
                            <i class="fas fa-database text-5xl mb-4 text-gray-300"></i>
                            <p class="text-lg font-medium text-gray-700 mb-2">Tidak Ada Data Absensi</p>
                            <p class="text-sm text-gray-500 max-w-md mx-auto">
                                Tidak ditemukan data absensi untuk periode
                                <?php echo date('F Y', strtotime($periode . '-01')); ?>.
                                <?php if ($filter_guru > 0 || $filter_siswa > 0): ?>
                                    Coba sesuaikan filter yang dipilih.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($rekap_data as $index => $guru): ?>
                            <div class="border-b border-gray-200 last:border-b-0">
                                <!-- Header Guru -->
                                <div class="bg-gray-50 px-6 py-4">
                                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                                        <div>
                                            <h4 class="font-medium text-gray-900 text-lg">
                                                <i class="fas fa-chalkboard-teacher mr-2 text-blue-600"></i>
                                                <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                            </h4>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span class="badge-count bg-blue-100 text-blue-800">
                                                    <i class="fas fa-users mr-1"></i>
                                                    <?php echo count($guru['siswa']); ?> siswa
                                                </span>
                                                <?php
                                                $total_hadir_guru = array_sum(array_column($guru['siswa'], 'total_hadir'));
                                                $total_izin_guru = array_sum(array_column($guru['siswa'], 'total_izin'));
                                                $total_sakit_guru = array_sum(array_column($guru['siswa'], 'total_sakit'));
                                                $total_alpha_guru = array_sum(array_column($guru['siswa'], 'total_alpha'));
                                                $total_sesi_guru = $total_hadir_guru + $total_izin_guru + $total_sakit_guru + $total_alpha_guru;
                                                ?>
                                                <span class="badge-count bg-green-100 text-green-800">
                                                    <i class="fas fa-check mr-1"></i> <?php echo $total_hadir_guru; ?>
                                                </span>
                                                <span class="badge-count bg-yellow-100 text-yellow-800">
                                                    <i class="fas fa-envelope mr-1"></i> <?php echo $total_izin_guru; ?>
                                                </span>
                                                <span class="badge-count bg-blue-100 text-blue-800">
                                                    <i class="fas fa-thermometer mr-1"></i> <?php echo $total_sakit_guru; ?>
                                                </span>
                                                <span class="badge-count bg-red-100 text-red-800">
                                                    <i class="fas fa-times mr-1"></i> <?php echo $total_alpha_guru; ?>
                                                </span>
                                                <span class="badge-count bg-purple-100 text-purple-800">
                                                    <i class="fas fa-calendar-check mr-1"></i> <?php echo $total_sesi_guru; ?>
                                                    sesi
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tabel Siswa -->
                                <div class="px-6 py-4">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    No</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Nama Siswa</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Kelas</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Hadir</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Izin</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Sakit</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Alpha</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Total</th>
                                                <th
                                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($guru['siswa'] as $idx => $siswa): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo $idx + 1; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-green-600">
                                                        <?php echo $siswa['total_hadir']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-yellow-600">
                                                        <?php echo $siswa['total_izin']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-blue-600">
                                                        <?php echo $siswa['total_sakit']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-red-600">
                                                        <?php echo $siswa['total_alpha']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        <?php echo $siswa['total_sesi']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-sm">
                                                        <button type="button" class="btn-detail"
                                                            data-siswa-id="<?php echo $siswa['id']; ?>"
                                                            data-siswa-nama="<?php echo htmlspecialchars($siswa['nama_lengkap']); ?>"
                                                            data-guru-id="<?php echo $guru['guru_id']; ?>"
                                                            data-guru-nama="<?php echo htmlspecialchars($guru['nama_guru']); ?>"
                                                            data-periode="<?php echo $periode; ?>"
                                                            onclick="showAbsensiDetail(this)">
                                                            <i class="fas fa-calendar-alt"></i>
                                                            <span>Detail</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Rekap Absensi Admin</p>
                    </div>
                    <div class="mt-3 md:mt-0">
                        <span class="text-sm text-gray-500">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo date('H:i:s'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Absensi -->
    <div id="absensiModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Detail Absensi Siswa</h3>
                <button type="button" class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-8">
                    <div class="loading-spinner"></div>
                    <p class="mt-3 text-gray-600">Memuat data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal()"
                    class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                    Tutup
                </button>
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

        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
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
                        if (t.querySelector('.arrow')) {
                            t.querySelector('.arrow').style.transform = 'rotate(0deg)';
                        }
                    });

                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });

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

        // ==================== AUTOCOMPLETE FUNCTIONS ====================
        const siswaData = <?php echo json_encode($siswa_list); ?>;
        const guruData = <?php echo json_encode($guru_list); ?>;

        let selectedIndex = -1;

        function initGuruAutocomplete() {
            const searchInput = document.getElementById('searchGuru');
            const clearButton = document.getElementById('clearGuruSearch');
            const dropdown = document.getElementById('guruDropdown');
            const guruIdInput = document.getElementById('selectedGuruId');
            const guruNameInput = document.getElementById('selectedGuruName');

            if (!searchInput) return;

            let searchTimeout;

            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) {
                    filterGuru(this.value);
                }
            });

            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimeout);
                const query = this.value;

                if (query.length < 2) {
                    dropdown.style.display = 'none';
                    clearButton.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(() => {
                    filterGuru(query);
                }, 300);

                clearButton.style.display = query.length > 0 ? 'block' : 'none';
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                guruIdInput.value = '';
                guruNameInput.value = '';
                clearButton.style.display = 'none';
                dropdown.style.display = 'none';
                document.getElementById('filterForm').submit();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelectedItem(dropdown, items, selectedIndex);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem(dropdown, items, selectedIndex);
                        break;
                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0 && items[selectedIndex]) {
                            selectGuru(items[selectedIndex].dataset);
                        }
                        break;
                    case 'Escape':
                        dropdown.style.display = 'none';
                        selectedIndex = -1;
                        break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        function filterGuru(query) {
            const dropdown = document.getElementById('guruDropdown');
            if (!dropdown) return;

            const filtered = guruData.filter(guru =>
                guru.nama_guru.toLowerCase().includes(query.toLowerCase()) ||
                (guru.bidang_keahlian && guru.bidang_keahlian.toLowerCase().includes(query.toLowerCase()))
            );

            renderGuruDropdown(filtered);
        }

        function renderGuruDropdown(data) {
            const dropdown = document.getElementById('guruDropdown');
            if (!dropdown) return;

            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="no-results">Tidak ditemukan guru</div>';
                dropdown.style.display = 'block';
                return;
            }

            data.forEach((guru, index) => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.dataset.id = guru.id;
                item.dataset.nama = guru.nama_guru;
                item.dataset.keahlian = guru.bidang_keahlian || '';

                item.innerHTML = `
                    <div class="item-nama">${escapeHtml(guru.nama_guru)}</div>
                    <div class="item-info">
                        ${guru.bidang_keahlian ? 'Bidang: ' + escapeHtml(guru.bidang_keahlian) : '-'}
                    </div>
                `;

                item.addEventListener('click', function () {
                    selectGuru(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.autocomplete-item');
                    selectedIndex = Array.from(items).indexOf(this);
                    updateSelectedItem(dropdown, items, selectedIndex);
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
            selectedIndex = -1;
        }

        function selectGuru(data) {
            const searchInput = document.getElementById('searchGuru');
            const guruIdInput = document.getElementById('selectedGuruId');
            const guruNameInput = document.getElementById('selectedGuruName');
            const dropdown = document.getElementById('guruDropdown');
            const clearButton = document.getElementById('clearGuruSearch');

            searchInput.value = data.nama;
            guruIdInput.value = data.id;
            guruNameInput.value = data.nama;
            dropdown.style.display = 'none';
            clearButton.style.display = 'block';

            document.getElementById('filterForm').submit();
        }

        function initSiswaAutocomplete() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSiswaSearch');
            const dropdown = document.getElementById('siswaDropdown');
            const siswaIdInput = document.getElementById('selectedSiswaId');
            const siswaNameInput = document.getElementById('selectedSiswaName');

            if (!searchInput) return;

            let searchTimeout;

            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) {
                    filterSiswaFilter(this.value);
                }
            });

            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimeout);
                const query = this.value;

                if (query.length < 2) {
                    dropdown.style.display = 'none';
                    clearButton.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(() => {
                    filterSiswaFilter(query);
                }, 300);

                clearButton.style.display = query.length > 0 ? 'block' : 'none';
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                siswaIdInput.value = '';
                siswaNameInput.value = '';
                clearButton.style.display = 'none';
                dropdown.style.display = 'none';
                document.getElementById('filterForm').submit();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelectedItem(dropdown, items, selectedIndex);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem(dropdown, items, selectedIndex);
                        break;
                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0 && items[selectedIndex]) {
                            selectSiswaFilter(items[selectedIndex].dataset);
                        }
                        break;
                    case 'Escape':
                        dropdown.style.display = 'none';
                        selectedIndex = -1;
                        break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        function filterSiswaFilter(query) {
            const dropdown = document.getElementById('siswaDropdown');
            if (!dropdown) return;

            const filtered = siswaData.filter(siswa =>
                siswa.nama_lengkap.toLowerCase().includes(query.toLowerCase()) ||
                (siswa.kelas && siswa.kelas.toLowerCase().includes(query.toLowerCase()))
            );

            renderSiswaDropdown(filtered);
        }

        function renderSiswaDropdown(data) {
            const dropdown = document.getElementById('siswaDropdown');
            if (!dropdown) return;

            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="no-results">Tidak ditemukan siswa</div>';
                dropdown.style.display = 'block';
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas;
                item.dataset.sekolah = siswa.sekolah_asal || '';

                item.innerHTML = `
                    <div class="item-nama">${escapeHtml(siswa.nama_lengkap)}</div>
                    <div class="item-info">
                        Kelas: ${escapeHtml(siswa.kelas)} | 
                        Sekolah: ${escapeHtml(siswa.sekolah_asal || '-')}
                    </div>
                `;

                item.addEventListener('click', function () {
                    selectSiswaFilter(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.autocomplete-item');
                    selectedIndex = Array.from(items).indexOf(this);
                    updateSelectedItem(dropdown, items, selectedIndex);
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
            selectedIndex = -1;
        }

        function selectSiswaFilter(data) {
            const searchInput = document.getElementById('searchSiswa');
            const siswaIdInput = document.getElementById('selectedSiswaId');
            const siswaNameInput = document.getElementById('selectedSiswaName');
            const dropdown = document.getElementById('siswaDropdown');
            const clearButton = document.getElementById('clearSiswaSearch');

            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            siswaNameInput.value = data.nama;
            dropdown.style.display = 'none';
            clearButton.style.display = 'block';

            document.getElementById('filterForm').submit();
        }

        function updateSelectedItem(dropdown, items, index) {
            items.forEach((item, i) => {
                if (i === index) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ==================== MODAL FUNCTIONS ====================
        let currentModal = null;

        function showAbsensiDetail(button) {
            const siswaId = button.dataset.siswaId;
            const siswaNama = button.dataset.siswaNama;
            const guruId = button.dataset.guruId;
            const guruNama = button.dataset.guruNama;
            const periode = button.dataset.periode;

            const modal = document.getElementById('absensiModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');

            modalTitle.innerHTML = `<i class="fas fa-calendar-alt mr-2"></i> Detail Absensi: ${escapeHtml(siswaNama)}`;
            modalBody.innerHTML = `
                <div class="text-center py-8">
                    <div class="loading-spinner"></div>
                    <p class="mt-3 text-gray-600">Memuat data absensi...</p>
                </div>
            `;

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Fetch data via AJAX
            $.ajax({
                url: 'ajax_get_absensi_detail.php',
                type: 'GET',
                data: {
                    siswa_id: siswaId,
                    guru_id: guruId,
                    periode: periode
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        renderAbsensiDetail(response, siswaNama, guruNama, periode);
                    } else {
                        modalBody.innerHTML = `
                            <div class="text-center py-8">
                                <i class="fas fa-exclamation-triangle text-5xl text-red-400 mb-4"></i>
                                <p class="text-gray-700">Gagal memuat data: ${response.message}</p>
                            </div>
                        `;
                    }
                },
                error: function (xhr, status, error) {
                    modalBody.innerHTML = `
                        <div class="text-center py-8">
                            <i class="fas fa-exclamation-triangle text-5xl text-red-400 mb-4"></i>
                            <p class="text-gray-700">Terjadi kesalahan: ${error}</p>
                        </div>
                    `;
                }
            });
        }

        function renderAbsensiDetail(data, siswaNama, guruNama, periode) {
            const modalBody = document.getElementById('modalBody');

            if (data.total === 0) {
                modalBody.innerHTML = `
            <div class="text-center py-8">
                <i class="fas fa-calendar-times text-5xl text-gray-400 mb-4"></i>
                <p class="text-gray-700">Tidak ada data absensi untuk periode ini.</p>
            </div>
        `;
                return;
            }

            const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            const [tahun, bulan] = periode.split('-');
            const bulanName = monthNames[parseInt(bulan) - 1];

            let html = `
        <div class="mb-4 p-3 bg-gray-100 rounded-lg">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                <div class="bg-green-100 rounded-lg p-2">
                    <div class="text-sm text-gray-600">Hadir</div>
                    <div class="text-xl font-bold text-green-600">${data.summary.hadir}</div>
                </div>
                <div class="bg-yellow-100 rounded-lg p-2">
                    <div class="text-sm text-gray-600">Izin</div>
                    <div class="text-xl font-bold text-yellow-600">${data.summary.izin}</div>
                </div>
                <div class="bg-blue-100 rounded-lg p-2">
                    <div class="text-sm text-gray-600">Sakit</div>
                    <div class="text-xl font-bold text-blue-600">${data.summary.sakit}</div>
                </div>
                <div class="bg-red-100 rounded-lg p-2">
                    <div class="text-sm text-gray-600">Alpha</div>
                    <div class="text-xl font-bold text-red-600">${data.summary.alpha}</div>
                </div>
            </div>
        </div>
        
        <div class="mb-3 text-sm text-gray-500">
            <i class="fas fa-chalkboard-teacher mr-1"></i> Guru: ${escapeHtml(guruNama)}
            <span class="mx-2">|</span>
            <i class="fas fa-calendar mr-1"></i> Periode: ${bulanName} ${tahun}
            <span class="mx-2">|</span>
            <i class="fas fa-chart-line mr-1"></i> Total Sesi: ${data.total}
        </div>
        
        <div class="border-t border-gray-200 mt-2 pt-3">
            <h4 class="font-medium text-gray-800 mb-3">
                <i class="fas fa-list-ul mr-1"></i> Daftar Absensi
            </h4>
            <div class="space-y-2">
    `;

            const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

            data.data.forEach(function (item) {
                const statusClass = `detail-card ${item.status}`;
                const statusBadgeClass = `status-badge status-${item.status}`;
                let statusIcon = '';
                let statusText = '';

                switch (item.status) {
                    case 'hadir':
                        statusIcon = 'fa-check-circle';
                        statusText = 'Hadir';
                        break;
                    case 'izin':
                        statusIcon = 'fa-envelope';
                        statusText = 'Izin';
                        break;
                    case 'sakit':
                        statusIcon = 'fa-thermometer-half';
                        statusText = 'Sakit';
                        break;
                    case 'alpha':
                        statusIcon = 'fa-times-circle';
                        statusText = 'Alpha';
                        break;
                }

                const tanggal = new Date(item.tanggal_absensi);
                const dayName = dayNames[tanggal.getDay()];
                const tanggalFormatted = `${dayName}, ${tanggal.getDate()} ${monthNames[tanggal.getMonth()]} ${tanggal.getFullYear()}`;

                html += `
            <div class="${statusClass} p-3">
                <div class="flex justify-between items-start flex-wrap gap-2">
                    <div class="flex-1">
                        <div class="flex items-center mb-2 flex-wrap gap-1">
                            <i class="fas ${statusIcon} mr-2 text-lg"></i>
                            <div class="font-medium">${tanggalFormatted}</div>
                            <span class="mx-2 text-gray-400">|</span>
                            <span class="${statusBadgeClass}">${statusText}</span>
                        </div>
                        <div class="text-xs text-gray-500 ml-6">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <div>
                                    <i class="fas fa-book mr-1"></i> <span class="font-medium">Mapel:</span> ${escapeHtml(item.nama_pelajaran)}
                                </div>
                                <div>
                                    <i class="fas fa-layer-group mr-1"></i> <span class="font-medium">Sesi ke-${item.sesi_ke}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
        `;

                if (item.keterangan && item.keterangan.trim() !== '') {
                    html += `
                <div class="mt-2 text-sm text-gray-600 pl-6">
                    <i class="fas fa-comment mr-1"></i> Keterangan: ${escapeHtml(item.keterangan)}
                </div>
            `;
                }

                if (item.bukti_izin && item.bukti_izin.trim() !== '') {
                    html += `
                <div class="mt-1 text-sm text-gray-500 pl-6">
                    <i class="fas fa-paperclip mr-1"></i> Ada bukti izin
                </div>
            `;
                }

                html += `</div>`;
            });

            html += `
                    </div>
                </div>
            </div>
        `;

            modalBody.innerHTML = html;
        }

        function closeModal() {
            const modal = document.getElementById('absensiModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modal = document.getElementById('absensiModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        // Initialize autocomplete
        initGuruAutocomplete();
        initSiswaAutocomplete();

        // Show clear buttons if there are values
        if (document.getElementById('searchGuru').value) {
            document.getElementById('clearGuruSearch').style.display = 'block';
        }
        if (document.getElementById('searchSiswa').value) {
            document.getElementById('clearSiswaSearch').style.display = 'block';
        }
    </script>
</body>

</html>