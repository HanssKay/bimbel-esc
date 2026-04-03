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

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$full_name = $_SESSION['full_name'] ?? 'User';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL
$success_message = '';
$error_message = '';
$penilaian_data = [];
$penilaian_detail = null;
$penilaian_edit = null;
$siswa_options = [];
$guru_options = [];
$pendaftaran_options = [];
$active_tab = 'list';

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER PARAMETER (DIUBAH SEPERTI DI RIWAYAT.PHP)
$filter_bulan_tahun = isset($_GET['bulan_tahun']) ? $_GET['bulan_tahun'] : date('Y-m');
$filter_siswa_id = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;
$filter_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_guru_id = isset($_GET['guru_id']) && is_numeric($_GET['guru_id']) ? (int) $_GET['guru_id'] : 0;
$filter_nama_guru = isset($_GET['nama_guru']) ? trim($_GET['nama_guru']) : '';
$filter_kategori = isset($_GET['filter_kategori']) ? $_GET['filter_kategori'] : '';

// Parse bulan dan tahun dari filter_bulan_tahun
$filter_tahun = !empty($filter_bulan_tahun) ? substr($filter_bulan_tahun, 0, 4) : date('Y');
$filter_bulan = !empty($filter_bulan_tahun) ? (int) substr($filter_bulan_tahun, 5, 2) : 0;

// SET ACTIVE TAB
if (isset($_GET['tab'])) {
    $active_tab = $_GET['tab'];
    $_SESSION['active_tab'] = $active_tab;
} elseif (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
}

// =============== FUNGSI BANTU ===============
function getBulanList()
{
    return [
        '' => 'Semua Bulan',
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember'
    ];
}

function getTahunList()
{
    $current_year = date('Y');
    $years = [];
    for ($i = -5; $i <= 2; $i++) {
        $years[] = $current_year + $i;
    }
    rsort($years);
    return $years;
}

function getKategoriByPersentase($persentase)
{
    if ($persentase === null || $persentase === 0) {
        return 'Belum Dinilai';
    }
    if ($persentase >= 80)
        return 'Sangat Baik';
    if ($persentase >= 60)
        return 'Baik';
    if ($persentase >= 40)
        return 'Cukup';
    return 'Kurang';
}

// =============== LOAD OPTIONS UNTUK FILTER/FORM ===============
// Load data siswa untuk filter
$siswa_sql = "SELECT s.id, s.nama_lengkap, s.kelas 
              FROM siswa s 
              WHERE s.status = 'aktif'
              ORDER BY s.nama_lengkap";
$siswa_stmt = $conn->prepare($siswa_sql);
$siswa_stmt->execute();
$siswa_result = $siswa_stmt->get_result();
$all_siswa_options = [];
while ($siswa = $siswa_result->fetch_assoc()) {
    $all_siswa_options[] = $siswa;
}
$siswa_stmt->close();

// Load data guru untuk filter
$guru_sql = "SELECT g.id, u.full_name as nama_guru, g.bidang_keahlian 
             FROM guru g 
             JOIN users u ON g.user_id = u.id 
             WHERE g.status = 'aktif' 
             ORDER BY u.full_name";
$guru_stmt = $conn->prepare($guru_sql);
$guru_stmt->execute();
$guru_result = $guru_stmt->get_result();
$all_guru_options = [];
while ($guru = $guru_result->fetch_assoc()) {
    $all_guru_options[] = $guru;
}
$guru_stmt->close();

// Load data pendaftaran aktif untuk form tambah (jika guru)
if ($user_role == 'guru') {
    $guru_info_sql = "SELECT id FROM guru WHERE user_id = ?";
    $guru_info_stmt = $conn->prepare($guru_info_sql);
    $guru_info_stmt->bind_param("i", $user_id);
    $guru_info_stmt->execute();
    $guru_info_result = $guru_info_stmt->get_result();
    $guru_info = $guru_info_result->fetch_assoc();
    $guru_id = $guru_info['id'] ?? 0;
    $guru_info_stmt->close();

    $pendaftaran_sql = "SELECT sp.id as siswa_pelajaran_id, sp.pendaftaran_id, 
                               s.id as siswa_id, s.nama_lengkap, sp.nama_pelajaran,
                               ps.tingkat
                        FROM siswa_pelajaran sp
                        JOIN siswa s ON sp.siswa_id = s.id
                        JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                        WHERE sp.guru_id = ? 
                          AND sp.status = 'aktif'
                          AND ps.status = 'aktif'
                        ORDER BY s.nama_lengkap";
    $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
    $pendaftaran_stmt->bind_param("i", $guru_id);
    $pendaftaran_stmt->execute();
    $pendaftaran_result = $pendaftaran_stmt->get_result();
    $pendaftaran_options = [];
    while ($pendaftaran = $pendaftaran_result->fetch_assoc()) {
        $pendaftaran_options[] = $pendaftaran;
    }
    $pendaftaran_stmt->close();
}

// =============== DETAIL PENILAIAN ===============
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $penilaian_id = intval($_GET['id']);

    $sql = "SELECT ps.*, 
                   s.nama_lengkap as nama_siswa, s.kelas as kelas_sekolah,
                   u.full_name as nama_guru, g.bidang_keahlian,
                   sp.nama_pelajaran, pd.tingkat as tingkat_bimbel,
                   pd.jenis_kelas as jenis_kelas_bimbel,
                   sp.id as siswa_pelajaran_id
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            WHERE ps.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $penilaian_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $row['total_manual'] = ($row['willingness_learn'] ?? 0) +
            ($row['problem_solving'] ?? 0) +
            ($row['critical_thinking'] ?? 0) +
            ($row['concentration'] ?? 0) +
            ($row['independence'] ?? 0);
        $row['persentase_manual'] = $row['total_manual'] > 0 ? round(($row['total_manual'] / 50) * 100) : 0;
        $row['kategori_manual'] = getKategoriByPersentase($row['persentase_manual']);
        $penilaian_detail = $row;
    }
    $stmt->close();
}

// =============== HAPUS PENILAIAN ===============
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $penilaian_id = intval($_GET['id']);
    $delete_sql = "DELETE FROM penilaian_siswa WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $penilaian_id);
    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = "✅ Penilaian berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "❌ Gagal menghapus penilaian!";
    }
    header('Location: dataNilai.php?tab=list');
    exit();
}

// =============== AMBIL DATA PENILAIAN DENGAN FILTER (DIUBAH) ===============
$sql = "SELECT ps.*, 
               s.id as siswa_id, s.nama_lengkap as nama_siswa, s.kelas as kelas_sekolah,
               u.full_name as nama_guru, g.bidang_keahlian,
               sp.nama_pelajaran, pd.tingkat as tingkat_bimbel,
               g.id as guru_pendaftaran_id
        FROM penilaian_siswa ps
        JOIN siswa s ON ps.siswa_id = s.id
        JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
        LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
        JOIN guru g ON ps.guru_id = g.id
        JOIN users u ON g.user_id = u.id
        WHERE 1=1";

$params = [];
$param_types = "";

// FILTER TAHUN DAN BULAN (dari input month)
if (!empty($filter_tahun) && $filter_tahun != 'all') {
    $sql .= " AND YEAR(ps.tanggal_penilaian) = ?";
    $params[] = $filter_tahun;
    $param_types .= "i";
}
if (!empty($filter_bulan) && $filter_bulan > 0) {
    $sql .= " AND MONTH(ps.tanggal_penilaian) = ?";
    $params[] = $filter_bulan;
    $param_types .= "i";
}

// FILTER SISWA (menggunakan ID atau nama)
if ($filter_siswa_id > 0) {
    $sql .= " AND ps.siswa_id = ?";
    $params[] = $filter_siswa_id;
    $param_types .= "i";
} elseif (!empty($filter_nama_siswa)) {
    $sql .= " AND s.nama_lengkap LIKE ?";
    $params[] = "%" . $filter_nama_siswa . "%";
    $param_types .= "s";
}

// FILTER GURU (menggunakan ID atau nama)
if ($filter_guru_id > 0) {
    $sql .= " AND ps.guru_id = ?";
    $params[] = $filter_guru_id;
    $param_types .= "i";
} elseif (!empty($filter_nama_guru)) {
    $sql .= " AND u.full_name LIKE ?";
    $params[] = "%" . $filter_nama_guru . "%";
    $param_types .= "s";
}

// FILTER KATEGORI
if (!empty($filter_kategori)) {
    $sql .= " AND ps.kategori = ?";
    $params[] = $filter_kategori;
    $param_types .= "s";
}

$sql .= " ORDER BY ps.tanggal_penilaian DESC, ps.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['kategori_display'] = getKategoriByPersentase($row['persentase'] ?? 0);
    $penilaian_data[] = $row;
}
$stmt->close();

// Hitung statistik dengan filter yang sama
$stats_sql = "SELECT 
              COUNT(*) as total_penilaian,
              AVG(total_score) as rata_nilai,
              AVG(persentase) as rata_persentase,
              MIN(total_score) as nilai_terendah,
              MAX(total_score) as nilai_tertinggi,
              COUNT(DISTINCT ps.siswa_id) as total_siswa_dinilai,
              COUNT(DISTINCT ps.guru_id) as total_guru_menilai
              FROM penilaian_siswa ps
              JOIN siswa s ON ps.siswa_id = s.id
              JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
              LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
              JOIN guru g ON ps.guru_id = g.id
              JOIN users u ON g.user_id = u.id
              WHERE 1=1";

$stats_params = [];
$stats_param_types = "";

if (!empty($filter_tahun) && $filter_tahun != 'all') {
    $stats_sql .= " AND YEAR(ps.tanggal_penilaian) = ?";
    $stats_params[] = $filter_tahun;
    $stats_param_types .= "i";
}
if (!empty($filter_bulan) && $filter_bulan > 0) {
    $stats_sql .= " AND MONTH(ps.tanggal_penilaian) = ?";
    $stats_params[] = $filter_bulan;
    $stats_param_types .= "i";
}
if ($filter_siswa_id > 0) {
    $stats_sql .= " AND ps.siswa_id = ?";
    $stats_params[] = $filter_siswa_id;
    $stats_param_types .= "i";
} elseif (!empty($filter_nama_siswa)) {
    $stats_sql .= " AND s.nama_lengkap LIKE ?";
    $stats_params[] = "%" . $filter_nama_siswa . "%";
    $stats_param_types .= "s";
}
if ($filter_guru_id > 0) {
    $stats_sql .= " AND ps.guru_id = ?";
    $stats_params[] = $filter_guru_id;
    $stats_param_types .= "i";
} elseif (!empty($filter_nama_guru)) {
    $stats_sql .= " AND u.full_name LIKE ?";
    $stats_params[] = "%" . $filter_nama_guru . "%";
    $stats_param_types .= "s";
}
if (!empty($filter_kategori)) {
    $stats_sql .= " AND ps.kategori = ?";
    $stats_params[] = $filter_kategori;
    $stats_param_types .= "s";
}

$stats_stmt = $conn->prepare($stats_sql);
if (!empty($stats_params)) {
    $stats_stmt->bind_param($stats_param_types, ...$stats_params);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$statistik = $stats_result->fetch_assoc();
$stats_stmt->close();

// Handle NULL values
$statistik['rata_nilai'] = $statistik['rata_nilai'] !== null ? round($statistik['rata_nilai'], 1) : 0;
$statistik['rata_persentase'] = $statistik['rata_persentase'] !== null ? round($statistik['rata_persentase'], 1) : 0;
$statistik['nilai_terendah'] = $statistik['nilai_terendah'] !== null ? $statistik['nilai_terendah'] : 0;
$statistik['nilai_tertinggi'] = $statistik['nilai_tertinggi'] !== null ? $statistik['nilai_tertinggi'] : 0;
$statistik['total_penilaian'] = $statistik['total_penilaian'] !== null ? $statistik['total_penilaian'] : 0;
$statistik['total_siswa_dinilai'] = $statistik['total_siswa_dinilai'] !== null ? $statistik['total_siswa_dinilai'] : 0;
$statistik['total_guru_menilai'] = $statistik['total_guru_menilai'] !== null ? $statistik['total_guru_menilai'] : 0;

$bulan_list = getBulanList();
$tahun_list = getTahunList();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penilaian - <?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?> Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .badge.sangat-baik {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge.baik {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge.cukup {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge.kurang {
            background-color: #fee2e2;
            color: #991b1b;
        }

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
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .indicator-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            background: white;
        }

        .indicator-card .score {
            font-size: 20px;
            font-weight: bold;
            color: #3b82f6;
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
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
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
            margin-top: 4px;
        }

        .autocomplete-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }

        .autocomplete-item:hover {
            background-color: #f9fafb;
        }

        .autocomplete-item.active {
            background-color: #eff6ff;
        }

        .autocomplete-item .item-name {
            font-weight: 600;
            color: #1f2937;
        }

        .autocomplete-item .item-info {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Mobile menu */
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

            .mobile-header {
                display: block;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .grid-2 {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .table-responsive {
                overflow-x: auto;
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
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200"><?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?> Dashboard</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <?php if ($user_role == 'admin'): ?>
                        <i class="fas fa-user-shield"></i>
                    <?php else: ?>
                        <i class="fas fa-chalkboard-teacher"></i>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300"><?php echo $user_role == 'admin' ? 'Administrator' : 'Guru'; ?></p>
                </div>
            </div>
        </div>
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, $user_role); ?>
        </nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 w-full fixed top-0 z-30 md:hidden">
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="text-white mr-3"><i class="fas fa-bars text-xl"></i></button>
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-3">
                    <p class="text-sm"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-blue-300"><?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?></p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <?php if ($user_role == 'admin'): ?>
                        <i class="fas fa-user-shield"></i>
                    <?php else: ?>
                        <i class="fas fa-chalkboard-teacher"></i>
                    <?php endif; ?>
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
                    <button id="menuClose" class="text-white mr-3"><i class="fas fa-times text-xl"></i></button>
                    <h1 class="text-xl font-bold">Bimbel Esc</h1>
                </div>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <?php if ($user_role == 'admin'): ?>
                            <i class="fas fa-user-shield text-lg"></i>
                        <?php else: ?>
                            <i class="fas fa-chalkboard-teacher text-lg"></i>
                        <?php endif; ?>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300"><?php echo $user_role == 'admin' ? 'Administrator' : 'Guru'; ?>
                        </p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, $user_role); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-clipboard-list mr-2"></i> Laporan Penilaian
                    </h1>
                    <p class="text-gray-600">Kelola data penilaian perkembangan siswa</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i> <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <?php if ($success_message): ?>
                <div class="mb-4 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0"><i class="fas fa-check-circle text-green-400"></i></div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="mb-4 bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0"><i class="fas fa-exclamation-circle text-red-400"></i></div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Statistik -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center"><i
                                    class="fas fa-clipboard-list text-blue-600"></i></div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Penilaian</p>
                            <p class="text-lg font-semibold text-gray-900">
                                <?php echo number_format($statistik['total_penilaian']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center"><i
                                    class="fas fa-chart-line text-green-600"></i></div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Rata-rata Nilai</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['rata_nilai']; ?>/50
                            </p>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center"><i
                                    class="fas fa-users text-yellow-600"></i></div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Siswa Dinilai</p>
                            <p class="text-lg font-semibold text-gray-900">
                                <?php echo number_format($statistik['total_siswa_dinilai']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center"><i
                                    class="fas fa-chalkboard-teacher text-purple-600"></i></div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Guru Menilai</p>
                            <p class="text-lg font-semibold text-gray-900">
                                <?php echo number_format($statistik['total_guru_menilai']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section (DIUBAH SEPERTI DI RIWAYAT.PHP) -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4"><i class="fas fa-filter mr-2"></i> Filter Data</h3>

                <form method="GET" action="dataNilai.php" id="filterForm">
                    <input type="hidden" name="tab" value="list">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <!-- Filter Siswa dengan Search -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cari Siswa</label>
                            <div class="relative">
                                <input type="text" id="filterSearchSiswa"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 pl-8 text-sm focus:ring-2 focus:ring-blue-500"
                                    placeholder="Ketik nama siswa..." autocomplete="off"
                                    value="<?php echo htmlspecialchars($filter_nama_siswa); ?>">
                                <input type="hidden" name="siswa_id" id="filterSiswaId"
                                    value="<?php echo $filter_siswa_id; ?>">
                                <input type="hidden" name="nama_siswa" id="filterNamaSiswa"
                                    value="<?php echo htmlspecialchars($filter_nama_siswa); ?>">
                                <i
                                    class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                <button type="button" id="clearFilterSearchSiswa"
                                    class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 <?php echo ($filter_siswa_id > 0 || !empty($filter_nama_siswa)) ? '' : 'hidden'; ?>">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div id="filterSiswaDropdown"
                                class="absolute z-50 w-full md:w-96 bg-white mt-1 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                            </div>
                        </div>

                        <!-- Filter Guru dengan Search -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cari Guru</label>
                            <div class="relative">
                                <input type="text" id="filterSearchGuru"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 pl-8 text-sm focus:ring-2 focus:ring-blue-500"
                                    placeholder="Ketik nama guru..." autocomplete="off"
                                    value="<?php echo htmlspecialchars($filter_nama_guru); ?>">
                                <input type="hidden" name="guru_id" id="filterGuruId"
                                    value="<?php echo $filter_guru_id; ?>">
                                <input type="hidden" name="nama_guru" id="filterNamaGuru"
                                    value="<?php echo htmlspecialchars($filter_nama_guru); ?>">
                                <i
                                    class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                <button type="button" id="clearFilterSearchGuru"
                                    class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 <?php echo ($filter_guru_id > 0 || !empty($filter_nama_guru)) ? '' : 'hidden'; ?>">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div id="filterGuruDropdown"
                                class="absolute z-50 w-full md:w-96 bg-white mt-1 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <!-- Filter Bulan & Tahun (kalender) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Periode (Bulan/Tahun)</label>
                            <input type="month" name="bulan_tahun" value="<?php echo $filter_bulan_tahun; ?>"
                                onchange="this.form.submit()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                        </div>

                        <!-- Filter Kategori (tetap dipertahankan) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Kategori</label>
                            <div class="flex flex-wrap gap-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="filter_kategori" value="" class="mr-1" <?php echo empty($filter_kategori) ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <span class="text-sm">Semua</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="filter_kategori" value="Sangat Baik" class="mr-1" <?php echo $filter_kategori == 'Sangat Baik' ? 'checked' : ''; ?>
                                        onchange="this.form.submit()">
                                    <span class="text-sm text-green-600">Sangat Baik</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="filter_kategori" value="Baik" class="mr-1" <?php echo $filter_kategori == 'Baik' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <span class="text-sm text-blue-600">Baik</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="filter_kategori" value="Cukup" class="mr-1" <?php echo $filter_kategori == 'Cukup' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <span class="text-sm text-yellow-600">Cukup</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="filter_kategori" value="Kurang" class="mr-1" <?php echo $filter_kategori == 'Kurang' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <span class="text-sm text-red-600">Kurang</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-4 border-t">
                        <div class="text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            Menampilkan <?php echo count($penilaian_data); ?> hasil
                        </div>
                        <div class="flex space-x-3">
                            <?php if (!empty($filter_nama_siswa) || !empty($filter_nama_guru) || !empty($filter_bulan_tahun) || !empty($filter_kategori)): ?>
                                <a href="dataNilai.php?tab=list&clear_filter=1"
                                    class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-gray-300 text-gray-700 hover:bg-gray-400">
                                    <i class="fas fa-redo mr-2"></i> Reset Filter
                                </a>
                            <?php endif; ?>
                            <button type="submit"
                                class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabel Penilaian -->
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <?php if (count($penilaian_data) > 0): ?>
                    <div class="table-responsive overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Siswa</th>
                                    <th
                                        class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Guru</th>
                                    <th
                                        class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Mata Pelajaran</th>
                                    <th
                                        class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tanggal</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Nilai</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kategori</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($penilaian_data as $index => $penilaian): ?>
                                                                    <?php
                                                                    $persentase = $penilaian['persentase'] ?? 0;
                                                                    $kategori_class = '';
                                                                    if ($persentase >= 80)
                                                                        $kategori_class = 'sangat-baik';
                                                                    elseif ($persentase >= 60)
                                                                        $kategori_class = 'baik';
                                                                    elseif ($persentase >= 40)
                                                                        $kategori_class = 'cukup';
                                                                    elseif ($persentase > 0)
                                                                        $kategori_class = 'kurang';
                                                                    else
                                                                        $kategori_class = 'belum-dinilai';
                                                                    ?>
                                                                    <tr class="hover:bg-gray-50">
                                                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?php echo $index + 1; ?></td>
                                                                        <td class="px-4 py-3 whitespace-nowrap">
                                                                            <div class="flex items-center">
                                                                                <div class="flex-shrink-0 h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                                                    <i class="fas fa-user-graduate text-blue-600 text-sm"></i>
                                                                                </div>
                                                                                <div class="ml-2">
                                                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($penilaian['nama_siswa']); ?></div>
                                                                                    <div class="text-xs text-gray-500">Kelas: <?php echo $penilaian['kelas_sekolah']; ?></div>
                                                                                </div>
                                                                            </div>
                                                                        </td>
                                                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($penilaian['nama_guru']); ?></td>
                                                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($penilaian['nama_pelajaran'] ?? '-'); ?></td>
                                                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?php echo date('d/m/Y', strtotime($penilaian['tanggal_penilaian'])); ?></td>
                                                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $penilaian['total_score'] ?? 0; ?>/50</td>
                                                                        <td class="px-4 py-3 whitespace-nowrap"><span class="badge <?php echo $kategori_class; ?>"><?php echo getKategoriByPersentase($persentase); ?></span></td>
                                                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                                                            <div class="flex space-x-2">
                                                                                <a href="?action=detail&id=<?php echo $penilaian['id']; ?>&tab=list" class="text-blue-600 hover:text-blue-900" title="Detail"><i class="fas fa-eye"></i></a>
                                                                                <?php if ($user_role == 'admin'): ?>
                                                                                                <a href="#" onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')" class="text-red-600 hover:text-red-900" title="Hapus"><i class="fas fa-trash"></i></a>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="bg-white px-4 py-3 border-t border-gray-200">
                                        <div class="text-sm text-gray-700">Menampilkan <span class="font-medium"><?php echo count($penilaian_data); ?></span> hasil</div>
                                    </div>
                <?php else: ?>
                                    <div class="text-center py-12">
                                        <i class="fas fa-clipboard-list text-gray-300 text-5xl mb-4"></i>
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">Data penilaian tidak ditemukan</h3>
                                        <p class="text-gray-500">Coba ubah filter pencarian.</p>
                                    </div>
                <?php endif; ?>
            </div>
        </div>

        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Penilaian</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Penilaian -->
    <?php if ($penilaian_detail): ?>
                    <div id="detailModal" class="modal" style="display: block;">
                        <div class="modal-content">
                            <div class="modal-header blue">
                                <h2 class="text-xl font-bold"><i class="fas fa-clipboard-list mr-2"></i> Detail Penilaian</h2>
                                <span class="close" onclick="closeModal()">&times;</span>
                            </div>
                            <div class="modal-body">
                                <div class="grid-2 mb-6">
                                    <div class="border rounded-lg p-4">
                                        <h4 class="font-medium text-gray-900 mb-3"><i class="fas fa-user-graduate text-blue-500 mr-2"></i> Informasi Siswa</h4>
                                        <div class="space-y-2">
                                            <div class="flex"><span class="w-32 text-gray-500 text-sm">Nama</span><span class="font-medium"><?php echo htmlspecialchars($penilaian_detail['nama_siswa']); ?></span></div>
                                            <div class="flex"><span class="w-32 text-gray-500 text-sm">Kelas Sekolah</span><span><?php echo $penilaian_detail['kelas_sekolah']; ?></span></div>
                                        </div>
                                    </div>
                                    <div class="border rounded-lg p-4">
                                        <h4 class="font-medium text-gray-900 mb-3"><i class="fas fa-chalkboard-teacher text-green-500 mr-2"></i> Informasi Guru</h4>
                                        <div class="space-y-2">
                                            <div class="flex"><span class="w-32 text-gray-500 text-sm">Nama Guru</span><span class="font-medium"><?php echo htmlspecialchars($penilaian_detail['nama_guru']); ?></span></div>
                                            <div class="flex"><span class="w-32 text-gray-500 text-sm">Mata Pelajaran</span><span><?php echo htmlspecialchars($penilaian_detail['nama_pelajaran']); ?></span></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-6">
                                    <h4 class="font-medium text-gray-900 mb-4">Ringkasan Penilaian</h4>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                        <div class="bg-blue-50 rounded-lg p-4 text-center"><div class="text-2xl font-bold text-blue-600"><?php echo $penilaian_detail['total_score'] ?? 0; ?>/50</div><div class="text-sm text-blue-500">Total Skor</div></div>
                                        <div class="bg-green-50 rounded-lg p-4 text-center"><div class="text-2xl font-bold text-green-600"><?php echo $penilaian_detail['persentase'] ?? 0; ?>%</div><div class="text-sm text-green-500">Persentase</div></div>
                                        <div class="bg-purple-50 rounded-lg p-4 text-center"><div class="text-2xl font-bold text-purple-600"><?php echo $penilaian_detail['kategori_manual']; ?></div><div class="text-sm text-purple-500">Kategori</div></div>
                                        <div class="bg-yellow-50 rounded-lg p-4 text-center"><div class="text-2xl font-bold text-yellow-600"><?php echo date('d M Y', strtotime($penilaian_detail['tanggal_penilaian'])); ?></div><div class="text-sm text-yellow-500">Tanggal</div></div>
                                    </div>
                                </div>

                                <div class="mb-6">
                                    <h4 class="font-medium text-gray-900 mb-4">Nilai Per Indikator</h4>
                                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                                        <?php
                                        $indicators = ['willingness_learn' => 'Kemauan Belajar', 'problem_solving' => 'Pemecahan Masalah', 'critical_thinking' => 'Berpikir Kritis', 'concentration' => 'Konsentrasi', 'independence' => 'Kemandirian'];
                                        foreach ($indicators as $key => $label):
                                            $score = $penilaian_detail[$key] ?? 0;
                                            $percentage = ($score / 10) * 100;
                                            $color = $score >= 8 ? 'bg-green-500' : ($score >= 6 ? 'bg-blue-500' : ($score >= 4 ? 'bg-yellow-500' : 'bg-red-500'));
                                            ?>
                                                            <div class="indicator-card">
                                                                <div class="score"><?php echo $score; ?>/10</div>
                                                                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2"><div class="h-2.5 rounded-full <?php echo $color; ?>" style="width: <?php echo $percentage; ?>%"></div></div>
                                                                <div class="label"><?php echo $label; ?></div>
                                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-6">
                                    <div class="grid-2 gap-6">
                                        <div><h5 class="text-sm font-medium text-gray-700 mb-2"><i class="fas fa-sticky-note text-yellow-500 mr-2"></i> Catatan Guru</h5><div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm"><?php echo nl2br(htmlspecialchars($penilaian_detail['catatan_guru'] ?: 'Tidak ada catatan')); ?></div></div>
                                        <div><h5 class="text-sm font-medium text-gray-700 mb-2"><i class="fas fa-lightbulb text-green-500 mr-2"></i> Rekomendasi</h5><div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm"><?php echo nl2br(htmlspecialchars($penilaian_detail['rekomendasi'] ?: 'Tidak ada rekomendasi')); ?></div></div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <div class="flex justify-end space-x-3">
                                    <button type="button" onclick="closeModal()" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Tutup</button>
                                </div>
                            </div>
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
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => sm.style.display = 'none');
                    document.querySelectorAll('.dropdown-toggle').forEach(t => { t.classList.remove('open'); t.querySelector('.arrow').style.transform = 'rotate(0deg)'; });
                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(submenu => submenu.style.display = 'none');
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => { toggle.classList.remove('open'); toggle.querySelector('.arrow').style.transform = 'rotate(0deg)'; });
            }
        });

        // Data untuk autocomplete
        let semuaSiswa = <?php echo json_encode($all_siswa_options); ?>;
        let semuaGuru = <?php echo json_encode($all_guru_options); ?>;

        // ==================== FILTER SEARCH SISWA ====================
        let filterSearchTimeoutSiswa;
        let filterSelectedIndexSiswa = -1;

        function initFilterSearchSiswa() {
            const searchInput = document.getElementById('filterSearchSiswa');
            const clearButton = document.getElementById('clearFilterSearchSiswa');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');

            if (!searchInput || !clearButton || !dropdown || !siswaIdInput || !namaSiswaInput) return;

            searchInput.addEventListener('input', function () {
                clearTimeout(filterSearchTimeoutSiswa);
                const query = this.value.trim();
                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    clearButton.classList.add('hidden');
                    return;
                }
                filterSearchTimeoutSiswa = setTimeout(() => {
                    filterSiswaList(query);
                }, 300);
                clearButton.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', function () {
                if (this.value.length >= 2) filterSiswaList(this.value);
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                siswaIdInput.value = '';
                namaSiswaInput.value = '';
                clearButton.classList.add('hidden');
                dropdown.classList.add('hidden');
                document.getElementById('filterForm').submit();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.filter-siswa-item');
                switch (e.key) {
                    case 'ArrowDown': e.preventDefault(); filterSelectedIndexSiswa = Math.min(filterSelectedIndexSiswa + 1, items.length - 1); updateFilterSelectedItemSiswa(items); break;
                    case 'ArrowUp': e.preventDefault(); filterSelectedIndexSiswa = Math.max(filterSelectedIndexSiswa - 1, -1); updateFilterSelectedItemSiswa(items); break;
                    case 'Enter': e.preventDefault(); if (filterSelectedIndexSiswa >= 0 && items[filterSelectedIndexSiswa]) selectFilterSiswa(items[filterSelectedIndexSiswa].dataset); break;
                    case 'Escape': dropdown.classList.add('hidden'); filterSelectedIndexSiswa = -1; break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.add('hidden');
            });
        }

        function filterSiswaList(query) {
            const filtered = semuaSiswa.filter(siswa => siswa.nama_lengkap.toLowerCase().includes(query.toLowerCase()) || (siswa.kelas && siswa.kelas.toLowerCase().includes(query.toLowerCase())));
            renderFilterSiswaDropdown(filtered);
        }

        function renderFilterSiswaDropdown(data) {
            const dropdown = document.getElementById('filterSiswaDropdown');
            if (!dropdown) return;
            dropdown.innerHTML = '';
            if (data.length === 0) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Tidak ada siswa ditemukan</div>';
                dropdown.classList.remove('hidden');
                return;
            }
            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'filter-siswa-item px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                if (index === 0) item.classList.add('bg-blue-50');
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas || '-';
                item.innerHTML = `<div class="font-medium text-gray-900">${siswa.nama_lengkap}</div><div class="text-xs text-gray-600 mt-1">Kelas: ${siswa.kelas || '-'}</div>`;
                item.addEventListener('click', () => selectFilterSiswa(item.dataset));
                dropdown.appendChild(item);
            });
            dropdown.classList.remove('hidden');
            filterSelectedIndexSiswa = 0;
        }

        function updateFilterSelectedItemSiswa(items) {
            items.forEach((item, i) => {
                if (i === filterSelectedIndexSiswa) {
                    item.classList.add('bg-blue-50');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('bg-blue-50');
                }
            });
        }

        function selectFilterSiswa(data) {
            const searchInput = document.getElementById('filterSearchSiswa');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const clearButton = document.getElementById('clearFilterSearchSiswa');
            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            namaSiswaInput.value = data.nama;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');
            document.getElementById('filterForm').submit();
        }

        // ==================== FILTER SEARCH GURU ====================
        let filterSearchTimeoutGuru;
        let filterSelectedIndexGuru = -1;

        function initFilterSearchGuru() {
            const searchInput = document.getElementById('filterSearchGuru');
            const clearButton = document.getElementById('clearFilterSearchGuru');
            const dropdown = document.getElementById('filterGuruDropdown');
            const guruIdInput = document.getElementById('filterGuruId');
            const namaGuruInput = document.getElementById('filterNamaGuru');

            if (!searchInput || !clearButton || !dropdown || !guruIdInput || !namaGuruInput) return;

            searchInput.addEventListener('input', function () {
                clearTimeout(filterSearchTimeoutGuru);
                const query = this.value.trim();
                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    clearButton.classList.add('hidden');
                    return;
                }
                filterSearchTimeoutGuru = setTimeout(() => {
                    filterGuruList(query);
                }, 300);
                clearButton.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', function () {
                if (this.value.length >= 2) filterGuruList(this.value);
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                guruIdInput.value = '';
                namaGuruInput.value = '';
                clearButton.classList.add('hidden');
                dropdown.classList.add('hidden');
                document.getElementById('filterForm').submit();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.filter-guru-item');
                switch (e.key) {
                    case 'ArrowDown': e.preventDefault(); filterSelectedIndexGuru = Math.min(filterSelectedIndexGuru + 1, items.length - 1); updateFilterSelectedItemGuru(items); break;
                    case 'ArrowUp': e.preventDefault(); filterSelectedIndexGuru = Math.max(filterSelectedIndexGuru - 1, -1); updateFilterSelectedItemGuru(items); break;
                    case 'Enter': e.preventDefault(); if (filterSelectedIndexGuru >= 0 && items[filterSelectedIndexGuru]) selectFilterGuru(items[filterSelectedIndexGuru].dataset); break;
                    case 'Escape': dropdown.classList.add('hidden'); filterSelectedIndexGuru = -1; break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.add('hidden');
            });
        }

        function filterGuruList(query) {
            const filtered = semuaGuru.filter(guru => guru.nama_guru.toLowerCase().includes(query.toLowerCase()));
            renderFilterGuruDropdown(filtered);
        }

        function renderFilterGuruDropdown(data) {
            const dropdown = document.getElementById('filterGuruDropdown');
            if (!dropdown) return;
            dropdown.innerHTML = '';
            if (data.length === 0) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Tidak ada guru ditemukan</div>';
                dropdown.classList.remove('hidden');
                return;
            }
            data.forEach((guru, index) => {
                const item = document.createElement('div');
                item.className = 'filter-guru-item px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                if (index === 0) item.classList.add('bg-blue-50');
                item.dataset.id = guru.id;
                item.dataset.nama = guru.nama_guru;
                item.innerHTML = `<div class="font-medium text-gray-900">${guru.nama_guru}</div><div class="text-xs text-gray-600 mt-1">${guru.bidang_keahlian || '-'}</div>`;
                item.addEventListener('click', () => selectFilterGuru(item.dataset));
                dropdown.appendChild(item);
            });
            dropdown.classList.remove('hidden');
            filterSelectedIndexGuru = 0;
        }

        function updateFilterSelectedItemGuru(items) {
            items.forEach((item, i) => {
                if (i === filterSelectedIndexGuru) {
                    item.classList.add('bg-blue-50');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('bg-blue-50');
                }
            });
        }

        function selectFilterGuru(data) {
            const searchInput = document.getElementById('filterSearchGuru');
            const guruIdInput = document.getElementById('filterGuruId');
            const namaGuruInput = document.getElementById('filterNamaGuru');
            const dropdown = document.getElementById('filterGuruDropdown');
            const clearButton = document.getElementById('clearFilterSearchGuru');
            searchInput.value = data.nama;
            guruIdInput.value = data.id;
            namaGuruInput.value = data.nama;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');
            document.getElementById('filterForm').submit();
        }

        function closeModal() {
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('id');
            url.searchParams.set('tab', 'list');
            window.history.replaceState({}, '', url.toString());
            const modal = document.getElementById('detailModal');
            if (modal) modal.style.display = 'none';
        }

        function confirmDelete(id, name) {
            if (confirm(`Apakah Anda yakin ingin menghapus penilaian untuk "${name}"?\n\nAksi ini tidak dapat dibatalkan!`)) {
                window.location.href = `dataNilai.php?action=hapus&id=${id}&tab=list`;
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('detailModal');
                if (modal && modal.style.display === 'block') closeModal();
            }
        });

        $(document).ready(function() {
            initFilterSearchSiswa();
            initFilterSearchGuru();
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>