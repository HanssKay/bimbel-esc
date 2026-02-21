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

$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Orangtua';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL DATA ORANGTUA
// AMBIL DATA ORANGTUA - PERBAIKI QUERY
$orangtua_id = 0;
$orangtua_data = [];
$email = '';
$nama_ortu = '';
$no_hp = '';

try {
    // Query yang benar - cari berdasarkan user_id
    $sql_orangtua = "SELECT o.*, u.email, u.full_name 
                     FROM orangtua o 
                     JOIN users u ON o.user_id = u.id 
                     WHERE o.user_id = ?";
    $stmt_orangtua = $conn->prepare($sql_orangtua);

    if ($stmt_orangtua === false) {
        throw new Exception("Prepare error: " . $conn->error);
    }

    $stmt_orangtua->bind_param("i", $user_id);
    $stmt_orangtua->execute();
    $result_orangtua = $stmt_orangtua->get_result();

    if ($row_orangtua = $result_orangtua->fetch_assoc()) {
        $orangtua_id = $row_orangtua['id'];
        $orangtua_data = $row_orangtua;
        $nama_ortu = $row_orangtua['nama_ortu'] ?? $row_orangtua['full_name'];
        $email = $row_orangtua['email'] ?? '';
        $no_hp = $row_orangtua['no_hp'] ?? '';
    } else {
        // Jika tidak ditemukan, coba cari di tabel users langsung
        error_log("Warning: User ID $user_id tidak ditemukan di tabel orangtua");

        // Fallback ke data dari session
        $nama_ortu = $_SESSION['full_name'] ?? 'Orang Tua';
        $email = $_SESSION['email'] ?? '';
    }
    $stmt_orangtua->close();
} catch (Exception $e) {
    error_log("Error fetching orangtua data: " . $e->getMessage());
    // Fallback ke data dari session
    $nama_ortu = $_SESSION['full_name'] ?? 'Orang Tua';
    $email = $_SESSION['email'] ?? '';
}

// AMBIL DATA ANAK-ANAK (SISWA) DARI ORANGTUA INI
$anak_orangtua = [];

if ($orangtua_id > 0) {
    $sql_anak = "
        SELECT DISTINCT s.id, s.nama_lengkap, s.kelas as kelas_sekolah
        FROM siswa_orangtua so
        JOIN siswa s ON so.siswa_id = s.id
        WHERE so.orangtua_id = ? AND s.status = 'aktif'
        ORDER BY s.nama_lengkap
    ";

    try {
        $stmt = $conn->prepare($sql_anak);

        if ($stmt === false) {
            throw new Exception("Prepare error: " . $conn->error);
        }

        $stmt->bind_param("i", $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $anak_orangtua[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching anak data: " . $e->getMessage());
    }
}

// HITUNG TOTAL ANAK
$total_anak = count($anak_orangtua);

// FILTER
$filter_hari = isset($_GET['hari']) && $_GET['hari'] !== '' ? $_GET['hari'] : '';
$filter_anak = isset($_GET['anak_id']) && $_GET['anak_id'] !== '' ? (int) $_GET['anak_id'] : 0;

// JIKA TIDAK ADA ANAK YANG TERDAFTAR
if (empty($anak_orangtua)) {
    $jadwal_anak = [];
    $total_jadwal = 0;
    $jadwal_by_hari = [];
    $total_anak_with_jadwal = 0;
    $guru_ids = [];
} else {
    // AMBIL ID SEMUA ANAK
    $anak_ids = array_column($anak_orangtua, 'id');

    // QUERY JADWAL ANAK - SESUAIKAN DENGAN STRUKTUR DATABASE
    $jadwal_anak = [];

    if (!empty($anak_ids)) {
        $placeholders = implode(',', array_fill(0, count($anak_ids), '?'));

        // Query utama - tanpa kolom kapasitas_maks, kapasitas_terisi, status_sesi
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
            g.id as guru_id,
            g.bidang_keahlian,
            u_guru.full_name as nama_guru,
            ps.tanggal_mulai,
            ps.tahun_ajaran
        FROM jadwal_belajar jb
        INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
        INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
        INNER JOIN siswa s ON ps.siswa_id = s.id
        LEFT JOIN guru g ON smg.guru_id = g.id
        LEFT JOIN users u_guru ON g.user_id = u_guru.id
        WHERE jb.status = 'aktif'
        AND ps.status = 'aktif'
        AND s.status = 'aktif'
        AND s.id IN ($placeholders)
    ";

        // Parameter untuk query
        $params = $anak_ids;
        $types = str_repeat('i', count($anak_ids));

        // Tambahkan kondisi filter hari
        if ($filter_hari !== '') {
            $sql_jadwal_anak .= " AND smg.hari = ?";
            $params[] = $filter_hari;
            $types .= 's';
        }

        // Tambahkan kondisi filter anak
        if ($filter_anak > 0 && in_array($filter_anak, $anak_ids)) {
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

            if ($stmt === false) {
                throw new Exception("Prepare jadwal error: " . $conn->error);
            }

            if (!empty($params)) {
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
            $jadwal_anak = [];
        }
    }

    // Hitung statistik - HILANGKAN referensi ke kapasitas
    $total_jadwal = count($jadwal_anak);

    // Hitung berapa anak yang memiliki jadwal
    $anak_with_jadwal = [];
    $guru_ids = [];
    foreach ($jadwal_anak as $jadwal) {
        if (!in_array($jadwal['siswa_id'], $anak_with_jadwal)) {
            $anak_with_jadwal[] = $jadwal['siswa_id'];
        }
        if (!empty($jadwal['guru_id']) && !in_array($jadwal['guru_id'], $guru_ids)) {
            $guru_ids[] = $jadwal['guru_id'];
        }
    }
    $total_anak_with_jadwal = count($anak_with_jadwal);
    $total_guru = count($guru_ids);
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
function hitungDurasi($jam_mulai, $jam_selesai)
{
    $start = strtotime($jam_mulai);
    $end = strtotime($jam_selesai);
    $durasi = ($end - $start) / 3600; // dalam jam
    return number_format($durasi, 1);
}

// Urutan hari dalam seminggu
$urutan_hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Anak - Bimbel Esc</title>
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

        .badge-purple {
            background-color: #ede9fe;
            color: #5b21b6;
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
            transition: width 0.3s ease;
        }

        .capacity-fill.low {
            background-color: #10b981;
        }

        .capacity-fill.medium {
            background-color: #f59e0b;
        }

        .capacity-fill.high {
            background-color: #ef4444;
        }

        /* Hari card untuk tampilan grid */
        .hari-card {
            background-color: white;
            border-radius: 8px;
            padding: 12px;
            border-left: 4px solid;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
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
                <?php if (!empty($no_hp)): ?>
                    <p class="text-xs mt-1"><i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($no_hp); ?></p>
                <?php endif; ?>
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
                    <?php if (!empty($no_hp)): ?>
                        <p class="flex items-center text-xs mt-1">
                            <i class="fas fa-phone mr-1"></i>
                            <span><?php echo htmlspecialchars($no_hp); ?></span>
                        </p>
                    <?php endif; ?>
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
                        Lihat jadwal belajar anak Anda di Bimbel Esc
                    </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-green-100 text-green-800">
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
                        <span><?php echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="container mx-auto p-4">
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
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

            <?php else: ?>
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <!-- Total Anak -->
                    <div class="stat-card bg-white rounded-xl p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-100 rounded-lg mr-4">
                                <i class="fas fa-child text-blue-600 text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-600 text-sm">Total Anak</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_anak; ?></h3>
                                <p class="text-xs text-gray-500">terdaftar aktif</p>
                            </div>
                        </div>
                    </div>

                    <!-- Anak dengan Jadwal -->
                    <div class="stat-card bg-white rounded-xl p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-100 rounded-lg mr-4">
                                <i class="fas fa-calendar-check text-green-600 text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-600 text-sm">Anak dengan Jadwal</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_anak_with_jadwal; ?></h3>
                                <p class="text-xs text-gray-500">dari <?php echo $total_anak; ?> anak</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Jadwal -->
                    <div class="stat-card bg-white rounded-xl p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-100 rounded-lg mr-4">
                                <i class="fas fa-clock text-purple-600 text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-600 text-sm">Total Jadwal</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_jadwal); ?>
                                </h3>
                                <p class="text-xs text-gray-500">pertemuan per minggu</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Guru -->
                    <div class="stat-card bg-white rounded-xl p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-3 bg-yellow-100 rounded-lg mr-4">
                                <i class="fas fa-chalkboard-teacher text-yellow-600 text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-gray-600 text-sm">Total Guru</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_guru ?? 0; ?></h3>
                                <p class="text-xs text-gray-500">pengajar anak Anda</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="bg-white shadow rounded-lg p-5 mb-6">
                    <form method="GET" action="" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pilih Anak</label>
                            <select name="anak_id"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Anak</option>
                                <?php foreach ($anak_orangtua as $anak): ?>
                                    <option value="<?php echo $anak['id']; ?>" <?php echo $filter_anak == $anak['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                        (<?php echo $anak['kelas_sekolah']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Filter Hari</label>
                            <select name="hari"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Hari</option>
                                <?php foreach ($urutan_hari as $hari): ?>
                                    <option value="<?php echo $hari; ?>" <?php echo $filter_hari == $hari ? 'selected' : ''; ?>>
                                        <?php echo $hari; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex items-end space-x-2">
                            <button type="submit"
                                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-filter mr-2"></i> Terapkan
                            </button>
                            <?php if ($filter_hari || $filter_anak): ?>
                                <a href="jadwalAnak.php"
                                    class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                                    Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($filter_hari || $filter_anak): ?>
                        <div class="mt-3 text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            Menampilkan jadwal untuk:
                            <?php
                            if ($filter_anak > 0) {
                                foreach ($anak_orangtua as $anak) {
                                    if ($anak['id'] == $filter_anak) {
                                        echo "<strong>" . htmlspecialchars($anak['nama_lengkap']) . "</strong>";
                                        break;
                                    }
                                }
                            } else {
                                echo "<strong>Semua Anak</strong>";
                            }

                            if ($filter_hari) {
                                echo " pada hari <strong>" . htmlspecialchars($filter_hari) . "</strong>";
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($total_jadwal == 0): ?>
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
                                            <div
                                                class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user-graduate text-xl"></i>
                                            </div>
                                            <div class="ml-3">
                                                <h4 class="font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                                </h4>
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
                                                <span class="text-gray-700 font-medium text-amber-500">Belum ada jadwal</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-calendar-plus text-5xl mb-4 text-gray-300"></i>
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
                    <!-- Kartu Ringkasan Anak-anak -->
                    <div class="bg-white shadow rounded-lg mb-8">
                        <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                            <h3 class="text-lg font-medium leading-6 text-gray-900">
                                <i class="fas fa-users mr-2"></i> Ringkasan Anak
                            </h3>
                            <p class="mt-1 text-sm text-gray-500">
                                <?php echo $total_anak; ?> anak terdaftar, <?php echo $total_anak_with_jadwal; ?> memiliki
                                jadwal aktif
                            </p>
                        </div>

                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php
                                // Hitung jadwal per anak
                                $jadwal_per_anak = [];
                                $hari_per_anak = [];
                                foreach ($jadwal_anak as $jadwal) {
                                    $siswa_id = $jadwal['siswa_id'];
                                    if (!isset($jadwal_per_anak[$siswa_id])) {
                                        $jadwal_per_anak[$siswa_id] = 0;
                                        $hari_per_anak[$siswa_id] = [];
                                    }
                                    $jadwal_per_anak[$siswa_id]++;
                                    $hari_per_anak[$siswa_id][] = $jadwal['hari'];
                                }

                                foreach ($anak_orangtua as $anak):
                                    $jumlah_jadwal = $jadwal_per_anak[$anak['id']] ?? 0;
                                    $hari_unik = isset($hari_per_anak[$anak['id']]) ? array_unique($hari_per_anak[$anak['id']]) : [];
                                    ?>
                                    <div class="anak-card p-5 bg-white rounded-lg">
                                        <div class="flex items-center mb-4">
                                            <div
                                                class="w-12 h-12 <?php echo $jumlah_jadwal > 0 ? 'bg-green-100 text-green-600' : 'bg-blue-100 text-blue-600'; ?> rounded-full flex items-center justify-center">
                                                <i class="fas fa-user-graduate text-xl"></i>
                                            </div>
                                            <div class="ml-3">
                                                <h4 class="font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                                </h4>
                                                <p class="text-sm text-gray-600">Kelas: <?php echo $anak['kelas_sekolah']; ?></p>
                                            </div>
                                        </div>
                                        <div class="space-y-2">
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-calendar text-gray-400 mr-2 w-4"></i>
                                                <span class="text-gray-700">
                                                    <span
                                                        class="font-medium <?php echo $jumlah_jadwal > 0 ? 'text-green-600' : 'text-red-500'; ?>">
                                                        <?php echo $jumlah_jadwal; ?> jadwal
                                                    </span>
                                                </span>
                                            </div>
                                            <?php if (!empty($hari_unik)): ?>
                                                <div class="flex items-center text-sm">
                                                    <i class="fas fa-clock text-gray-400 mr-2 w-4"></i>
                                                    <span class="text-gray-700">
                                                        Hari: <?php echo implode(', ', array_slice($hari_unik, 0, 3)); ?>
                                                        <?php if (count($hari_unik) > 3)
                                                            echo '...'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Ringkasan per Hari -->
                    <?php if (!empty($jadwal_by_hari)): ?>
                        <div class="bg-white shadow rounded-lg mb-8">
                            <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                                <h3 class="text-lg font-medium leading-6 text-gray-900">
                                    <i class="fas fa-chart-pie mr-2"></i> Sebaran Jadwal per Hari
                                </h3>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
                                    <?php foreach ($urutan_hari as $hari):
                                        $jumlah = $jadwal_by_hari[$hari] ?? 0;
                                        $bg_color = $jumlah > 0 ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200';
                                        $text_color = $jumlah > 0 ? 'text-blue-800' : 'text-gray-500';
                                        ?>
                                        <div class="hari-card <?php echo $bg_color; ?> border">
                                            <div class="text-center">
                                                <div class="font-medium <?php echo $text_color; ?>"><?php echo $hari; ?></div>
                                                <div class="text-xl font-bold <?php echo $text_color; ?>"><?php echo $jumlah; ?></div>
                                                <div class="text-xs <?php echo $text_color; ?>">jadwal</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tabel Jadwal Lengkap -->
                    <div class="bg-white shadow rounded-lg mb-8">
                        <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                            <h3 class="text-lg font-medium leading-6 text-gray-900">
                                <i class="fas fa-table mr-2"></i> Daftar Jadwal Bimbingan
                                <?php if ($filter_hari || $filter_anak): ?>
                                    <span class="text-sm font-normal text-gray-500 ml-2">
                                        (Filter diterapkan)
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
                                            <th>Kelas</th>
                                            <th>Tingkat Bimbel</th>
                                            <!-- <th>Jenis Kelas</th> -->
                                            <th>Hari</th>
                                            <th>Jam</th>
                                            <!-- <th>Durasi</th> -->
                                            <th>Guru</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $no = 1;
                                        foreach ($jadwal_anak as $jadwal):
                                            $durasi = hitungDurasi($jadwal['jam_mulai'], $jadwal['jam_selesai']);
                                            ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="text-sm text-gray-500"><?php echo $no++; ?></td>
                                                <td>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($jadwal['nama_lengkap']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-primary">
                                                        <?php echo htmlspecialchars($jadwal['kelas_sekolah']); ?>
                                                    </span>
                                                </td>
                                                <td >
                                                    <span class="badge badge-info">
                                                        <?php echo $jadwal['tingkat_bimbel']; ?>
                                                    </span>
                                                </td>
                                                <!-- <td>
                                                    <span class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-excellent' : 'badge-champion'; ?>">
                                                        <?php echo $jadwal['jenis_kelas']; ?>
                                                    </span>
                                                </td> -->
                                                <td>
                                                    <span class="badge badge-primary"><?php echo $jadwal['hari']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="font-medium text-sm">
                                                        <?php echo $jadwal['jam_mulai_format']; ?> -
                                                        <?php echo $jadwal['jam_selesai_format']; ?>
                                                    </div>
                                                </td>
                                                <!-- <td>
                                                    <span class="badge badge-purple">
                                                        <?php echo $durasi; ?> jam
                                                    </span>
                                                </td> -->
                                                <td>
                                                    <?php if (!empty($jadwal['nama_guru'])): ?>
                                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_guru']); ?>
                                                        </div>
                                                        <?php if (!empty($jadwal['bidang_keahlian'])): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo htmlspecialchars($jadwal['bidang_keahlian']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="text-gray-400 italic">-</div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Info Tambahan -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h4 class="font-semibold text-blue-800 mb-2 flex items-center">
                            <i class="fas fa-info-circle mr-2"></i> Informasi Jadwal
                        </h4>
                        <ul class="text-blue-700 text-sm space-y-1">
                            <li>• Jadwal ditampilkan berdasarkan sesi mengajar yang aktif.</li>
                            <li>• Setiap jadwal adalah sesi belajar selama <?php echo hitungDurasi('09:00:00', '11:00:00'); ?>
                                jam.</li>
                            <li>• Jika ada perubahan jadwal, akan segera diperbarui di sini.</li>
                            <li>• Hubungi guru atau admin jika ada pertanyaan tentang jadwal.</li>
                        </ul>
                    </div>
                <?php endif; ?>
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
                                <i class="fas fa-child mr-1"></i>
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
                        t.querySelector('.arrow').style.transform = 'rotate(0deg)';
                    });

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
<?php $conn->close(); ?>