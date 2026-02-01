<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'orangtua') {
    header('Location: ../index.php');
    exit();
}

// AMBIL DATA ORANG TUA DARI SESSION
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Orang Tua';
$email = $_SESSION['email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL ID ORANGTUA DARI TABLE ORANGTUA
$orangtua_id = 0;
$nama_ortu = '';
try {
    $sql_ortu = "SELECT id, nama_ortu FROM orangtua WHERE user_id = ?";
    $stmt_ortu = $conn->prepare($sql_ortu);
    $stmt_ortu->bind_param("i", $user_id);
    $stmt_ortu->execute();
    $result_ortu = $stmt_ortu->get_result();
    if ($row_ortu = $result_ortu->fetch_assoc()) {
        $orangtua_id = $row_ortu['id'];
        $nama_ortu = $row_ortu['nama_ortu'] ?? $full_name;
    }
    $stmt_ortu->close();
} catch (Exception $e) {
    error_log("Error fetching orangtua data: " . $e->getMessage());
}

// Ambil data siswa yang dimiliki oleh orang tua ini
$siswa_list = [];
if ($orangtua_id > 0) {
    $sql_siswa = "SELECT DISTINCT s.id, s.nama_lengkap, s.kelas 
                  FROM siswa s 
                  LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                  WHERE (s.orangtua_id = ? OR so.orangtua_id = ?)
                  AND s.status = 'aktif'
                  ORDER BY s.nama_lengkap";
    $stmt_siswa = $conn->prepare($sql_siswa);
    if ($stmt_siswa) {
        $stmt_siswa->bind_param("ii", $orangtua_id, $orangtua_id);
        $stmt_siswa->execute();
        $result_siswa = $stmt_siswa->get_result();
        
        while ($row = $result_siswa->fetch_assoc()) {
            $siswa_list[] = $row;
        }
        $stmt_siswa->close();
    }
}

// Pilih siswa (default siswa pertama)
$selected_siswa_id = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;
if ($selected_siswa_id == 0 && !empty($siswa_list)) {
    $selected_siswa_id = $siswa_list[0]['id'];
}

// Filter bulan
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

// Filter tahun ajaran
$tahun_ajaran_filter = isset($_GET['tahun_ajaran']) ? $_GET['tahun_ajaran'] : '';

// Inisialisasi variabel
$rekap_data = [];
$statistik_total = [
    'total_sesi' => 0,
    'total_hadir' => 0,
    'total_izin' => 0,
    'total_sakit' => 0,
    'total_alpha' => 0,
    'persentase_hadir' => 0
];
$siswa_detail = [];
$detail_absensi = [];

if ($selected_siswa_id > 0 && $orangtua_id > 0) {
    // Verifikasi bahwa siswa ini benar milik orangtua ini
    $sql_verify = "SELECT 1 
                   FROM siswa s 
                   LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                   WHERE s.id = ? 
                   AND (s.orangtua_id = ? OR so.orangtua_id = ?)
                   AND s.status = 'aktif'
                   LIMIT 1";
    
    $stmt_verify = $conn->prepare($sql_verify);
    $stmt_verify->bind_param("iii", $selected_siswa_id, $orangtua_id, $orangtua_id);
    $stmt_verify->execute();
    $result_verify = $stmt_verify->get_result();
    $is_verified = $result_verify->num_rows > 0;
    $stmt_verify->close();
    
    if ($is_verified) {
        // Ambil detail siswa
        $sql_detail = "SELECT 
                        s.*,
                        ps.tingkat as tingkat_bimbel,
                        ps.jenis_kelas,
                        ps.tahun_ajaran
                       FROM siswa s 
                       LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
                       WHERE s.id = ?
                       LIMIT 1";
        
        $stmt_detail = $conn->prepare($sql_detail);
        $stmt_detail->bind_param("i", $selected_siswa_id);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        $siswa_detail = $result_detail->fetch_assoc() ?? [];
        $stmt_detail->close();
        
        // AMBIL REKAP ABSENSI PER MINGGU UNTUK BULAN YANG DIPILIH
        // Query yang aman dengan prepared statement
        $sql_rekap = "SELECT 
                        DATE_FORMAT(a.tanggal_absensi, '%Y-%m') as bulan,
                        WEEK(a.tanggal_absensi, 1) as minggu_ke,
                        MIN(a.tanggal_absensi) as minggu_mulai,
                        MAX(a.tanggal_absensi) as minggu_selesai,
                        COUNT(*) as total_sesi,
                        SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) as total_hadir,
                        SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) as total_izin,
                        SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) as total_sakit,
                        SUM(CASE WHEN a.status = 'alpha' THEN 1 ELSE 0 END) as total_alpha
                    FROM absensi_siswa a
                    WHERE a.siswa_id = ?
                    AND DATE_FORMAT(a.tanggal_absensi, '%Y-%m') = ?
                    GROUP BY DATE_FORMAT(a.tanggal_absensi, '%Y-%m'), WEEK(a.tanggal_absensi, 1)
                    ORDER BY minggu_ke";
        
        $stmt_rekap = $conn->prepare($sql_rekap);
        $stmt_rekap->bind_param("is", $selected_siswa_id, $bulan_filter);
        $stmt_rekap->execute();
        $result_rekap = $stmt_rekap->get_result();
        
        while ($row = $result_rekap->fetch_assoc()) {
            // Hitung persentase
            $total_sesi = $row['total_sesi'] ?? 0;
            $total_hadir = $row['total_hadir'] ?? 0;
            $persentase = $total_sesi > 0 ? round(($total_hadir / $total_sesi) * 100, 2) : 0;
            
            $row['persentase_hadir'] = $persentase;
            $rekap_data[] = $row;
            
            // Akumulasi total
            $statistik_total['total_sesi'] += $total_sesi;
            $statistik_total['total_hadir'] += $total_hadir;
            $statistik_total['total_izin'] += $row['total_izin'] ?? 0;
            $statistik_total['total_sakit'] += $row['total_sakit'] ?? 0;
            $statistik_total['total_alpha'] += $row['total_alpha'] ?? 0;
        }
        $stmt_rekap->close();
        
        // Hitung persentase total
        if ($statistik_total['total_sesi'] > 0) {
            $statistik_total['persentase_hadir'] = round(($statistik_total['total_hadir'] / $statistik_total['total_sesi']) * 100, 2);
        }
        
        // AMBIL DETAIL ABSENSI UNTUK TABEL DETAIL
        if (!empty($rekap_data)) {
            // Ambil semua data absensi untuk bulan ini
            $sql_detail_absensi = "SELECT 
                                    a.*,
                                    DATE_FORMAT(a.tanggal_absensi, '%W') as hari_nama,
                                    DATE_FORMAT(a.tanggal_absensi, '%d') as tanggal,
                                    sp.nama_pelajaran,
                                    u.full_name as nama_guru,
                                    WEEK(a.tanggal_absensi, 1) as minggu_ke
                                FROM absensi_siswa a
                                LEFT JOIN siswa_pelajaran sp ON a.siswa_pelajaran_id = sp.id
                                LEFT JOIN guru g ON a.guru_id = g.id
                                LEFT JOIN users u ON g.user_id = u.id
                                WHERE a.siswa_id = ?
                                AND DATE_FORMAT(a.tanggal_absensi, '%Y-%m') = ?
                                ORDER BY a.tanggal_absensi DESC";
            
            $stmt_detail_absensi = $conn->prepare($sql_detail_absensi);
            $stmt_detail_absensi->bind_param("is", $selected_siswa_id, $bulan_filter);
            $stmt_detail_absensi->execute();
            $result_detail_absensi = $stmt_detail_absensi->get_result();
            
            while ($row = $result_detail_absensi->fetch_assoc()) {
                $detail_absensi[] = $row;
            }
            $stmt_detail_absensi->close();
        }
        
        // Ambil daftar pelajaran yang diambil siswa
        $pelajaran_list = [];
        $sql_pelajaran = "SELECT DISTINCT sp.id, sp.nama_pelajaran, g.user_id, u.full_name as nama_guru
                          FROM siswa_pelajaran sp
                          JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                          LEFT JOIN guru g ON sp.guru_id = g.id
                          LEFT JOIN users u ON g.user_id = u.id
                          WHERE ps.siswa_id = ? 
                          AND ps.status = 'aktif'
                          AND sp.status = 'aktif'
                          ORDER BY sp.nama_pelajaran";
        
        $stmt_pelajaran = $conn->prepare($sql_pelajaran);
        $stmt_pelajaran->bind_param("i", $selected_siswa_id);
        $stmt_pelajaran->execute();
        $result_pelajaran = $stmt_pelajaran->get_result();
        
        while ($row = $result_pelajaran->fetch_assoc()) {
            $pelajaran_list[] = $row;
        }
        $stmt_pelajaran->close();
    }
}

// Fungsi untuk mendapatkan nama hari dalam Bahasa Indonesia
function getHariIndonesia($hari_inggris) {
    $hari = [
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
        'Sunday' => 'Minggu'
    ];
    return $hari[$hari_inggris] ?? $hari_inggris;
}

// Fungsi untuk mendapatkan kelas CSS berdasarkan status
function getStatusClass($status) {
    switch($status) {
        case 'hadir': return 'bg-green-100 text-green-800';
        case 'izin': return 'bg-yellow-100 text-yellow-800';
        case 'sakit': return 'bg-blue-100 text-blue-800';
        case 'alpha': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getStatusIcon($status) {
    switch($status) {
        case 'hadir': return 'fa-check-circle';
        case 'izin': return 'fa-envelope';
        case 'sakit': return 'fa-thermometer';
        case 'alpha': return 'fa-times-circle';
        default: return 'fa-question-circle';
    }
}

// Generate list bulan (12 bulan terakhir)
$bulan_list = [];
for ($i = 0; $i < 12; $i++) {
    $timestamp = strtotime("-$i months");
    $bulan_list[] = date('Y-m', $timestamp);
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
    <style>
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            background-color: #e5e7eb;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.5s ease;
        }
        
        .progress-green { background-color: #10b981; }
        .progress-yellow { background-color: #f59e0b; }
        .progress-red { background-color: #ef4444; }
        
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
        
        /* Highlight untuk data terpilih */
        .filter-active {
            background-color: #3b82f6 !important;
            color: white !important;
        }
        
        /* Chart container */
        .chart-container {
            position: relative;
            height: 300px;
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
                <p><i class="fas fa-child mr-2"></i> <?php echo count($siswa_list); ?> Anak</p>
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
                    <span><?php echo count($siswa_list); ?> Anak</span>
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
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi
                    </h1>
                    <p class="text-gray-600">
                        Lihat rekap kehadiran anak anda selama belajar
                    </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-green-100 text-green-800">
                        <i class="fas fa-child mr-2"></i>
                        <?php echo count($siswa_list); ?> Anak
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-filter mr-2"></i> Filter Data
                </h3>
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-user-graduate mr-1"></i> Pilih Siswa
                            </label>
                            <select name="siswa_id" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <option value="">Semua Siswa</option>
                                <?php foreach ($siswa_list as $siswa): ?>
                                <option value="<?php echo $siswa['id']; ?>" 
                                    <?php echo ($selected_siswa_id == $siswa['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($siswa['nama_lengkap'] . ' (' . $siswa['kelas'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-calendar mr-1"></i> Bulan
                            </label>
                            <select name="bulan" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <?php foreach ($bulan_list as $bulan): ?>
                                <option value="<?php echo $bulan; ?>" 
                                    <?php echo ($bulan_filter == $bulan) ? 'selected' : ''; ?>>
                                    <?php echo date('F Y', strtotime($bulan . '-01')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-2 flex items-end space-x-2">
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-search mr-2"></i> Tampilkan Data
                            </button>
                            <a href="rekapAbsensi.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (empty($siswa_list)): ?>
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
            
            <?php elseif ($selected_siswa_id == 0): ?>
            <!-- Pilih siswa dulu -->
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-6">
                    <i class="fas fa-user-graduate text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-2xl font-bold text-gray-700 mb-2">Pilih Siswa Terlebih Dahulu</h3>
                    <p class="text-gray-600 mb-6">
                        Silakan pilih salah satu siswa dari daftar di bawah ini untuk melihat rekap absensi.
                    </p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($siswa_list as $siswa): ?>
                    <a href="?siswa_id=<?php echo $siswa['id']; ?>&bulan=<?php echo $bulan_filter; ?>" 
                       class="anak-card p-6 bg-white hover:bg-blue-50 transition-colors">
                        <div class="flex items-center mb-4">
                            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-graduate text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($siswa['nama_lengkap']); ?></h4>
                                <p class="text-sm text-gray-600">Kelas: <?php echo $siswa['kelas']; ?></p>
                            </div>
                        </div>
                        <div class="text-left">
                            <p class="text-sm text-gray-500">
                                <i class="fas fa-info-circle mr-2"></i>
                                Klik untuk melihat rekap absensi
                            </p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php else: ?>
            <!-- Jika ada siswa terpilih -->
            
            <!-- Info Siswa -->
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-6 py-5 border-b border-gray-200">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                        <div>
                            <h3 class="text-lg font-medium leading-6 text-gray-900">
                                <i class="fas fa-user-graduate mr-2"></i>
                                <?php echo htmlspecialchars($siswa_detail['nama_lengkap'] ?? ''); ?>
                            </h3>
                            <div class="mt-2 grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <p class="text-sm text-gray-600">Kelas Sekolah</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($siswa_detail['kelas'] ?? '-'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600">Tingkat Bimbel</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($siswa_detail['tingkat_bimbel'] ?? '-'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600">Jenis Kelas</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($siswa_detail['jenis_kelas'] ?? '-'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600">Tahun Ajaran</p>
                                    <p class="font-medium"><?php echo htmlspecialchars($siswa_detail['tahun_ajaran'] ?? '-'); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-calendar mr-2"></i>
                                <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($pelajaran_list)): ?>
                <div class="p-6 border-b border-gray-200">
                    <p class="text-sm font-medium text-gray-700 mb-2">Mata Pelajaran:</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($pelajaran_list as $pelajaran): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                            <i class="fas fa-book mr-1"></i>
                            <?php echo htmlspecialchars($pelajaran['nama_pelajaran']); ?>
                            <?php if (!empty($pelajaran['nama_guru'])): ?>
                            <span class="ml-1 text-xs">(<?php echo htmlspecialchars($pelajaran['nama_guru']); ?>)</span>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Statistik Summary -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg mr-4">
                            <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Hadir</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $statistik_total['total_hadir']; ?></h3>
                            
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-lg p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-4">
                            <i class="fas fa-envelope text-yellow-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Izin</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $statistik_total['total_izin']; ?></h3>
                            
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-lg p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-4">
                            <i class="fas fa-thermometer text-blue-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Sakit</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $statistik_total['total_sakit']; ?></h3>
                            
                        </div>
                    </div>
                </div>
                
                <div class="stat-card bg-white rounded-lg p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-red-100 rounded-lg mr-4">
                            <i class="fas fa-times-circle text-red-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Alpha</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo $statistik_total['total_alpha']; ?></h3>
                            
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar Persentase -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-chart-line mr-2"></i> Tingkat Kehadiran
                </h3>
                <div class="space-y-4">
                    
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                        <div>
                            <p class="text-sm text-gray-600">Total Sesi</p>
                            <p class="text-xl font-bold text-gray-800"><?php echo $statistik_total['total_sesi']; ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Hadir</p>
                            <p class="text-xl font-bold text-green-600"><?php echo $statistik_total['total_hadir']; ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Tidak Hadir</p>
                            <p class="text-xl font-bold text-red-600"><?php echo $statistik_total['total_izin'] + $statistik_total['total_sakit'] + $statistik_total['total_alpha']; ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Rata-rata/Minggu</p>
                            <p class="text-xl font-bold text-blue-600">
                                <?php 
                                $jumlah_minggu = count($rekap_data);
                                echo $jumlah_minggu > 0 ? round($statistik_total['total_sesi'] / $jumlah_minggu, 1) : 0; 
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabel Rekap Per Minggu -->
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-table mr-2"></i> Rekap Absensi Per Minggu - <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        <?php echo count($rekap_data); ?> minggu ditemukan
                    </p>
                </div>
                
                <div class="p-6">
                    <?php if (empty($rekap_data)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-calendar-times text-4xl mb-4"></i>
                        <h3 class="text-lg font-medium mb-2">Belum Ada Data Absensi</h3>
                        <p class="mb-4">
                            Tidak ada data absensi untuk <?php echo htmlspecialchars($siswa_detail['nama_lengkap'] ?? ''); ?> pada bulan <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>.
                        </p>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 max-w-md mx-auto">
                            <p class="text-yellow-800 text-sm">
                                <i class="fas fa-clock mr-2"></i>
                                Data absensi akan muncul setelah guru menginput kehadiran.
                            </p>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="table-container">
                        <table class="table-jadwal">
                            <thead>
                                <tr>
                                    <th>Minggu ke</th>
                                    <th>Periode</th>
                                    <th>Total Sesi</th>
                                    <th>Hadir</th>
                                    <th>Izin</th>
                                    <th>Sakit</th>
                                    <th>Alpha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rekap_data as $rekap): 
                                    $persentase = $rekap['persentase_hadir'];
                                    $status_class = $persentase >= 80 ? 'text-green-600' : ($persentase >= 60 ? 'text-yellow-600' : 'text-red-600');
                                    $status_text = $persentase >= 80 ? 'Baik' : ($persentase >= 60 ? 'Cukup' : 'Perlu Perhatian');
                                ?>
                                <tr>
                                    <td class="font-medium"><?php echo $rekap['minggu_ke']; ?></td>
                                    <td>
                                        <?php echo date('d', strtotime($rekap['minggu_mulai'])) . ' - ' . date('d M', strtotime($rekap['minggu_selesai'])); ?>
                                    </td>
                                    <td class="font-bold"><?php echo $rekap['total_sesi']; ?></td>
                                    <td>
                                        <span class="badge bg-green-100 text-green-800">
                                            <i class="fas fa-check mr-1"></i> <?php echo $rekap['total_hadir']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-yellow-100 text-yellow-800">
                                            <i class="fas fa-envelope mr-1"></i> <?php echo $rekap['total_izin']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-blue-100 text-blue-800">
                                            <i class="fas fa-thermometer mr-1"></i> <?php echo $rekap['total_sakit']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-red-100 text-red-800">
                                            <i class="fas fa-times mr-1"></i> <?php echo $rekap['total_alpha']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detail Absensi Harian -->
            <?php if (!empty($detail_absensi)): ?>
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-5 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-calendar-day mr-2"></i> Detail Absensi Harian
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        <?php echo count($detail_absensi); ?> catatan absensi ditemukan
                    </p>
                </div>
                
                <div class="p-6">
                    <div class="table-container">
                        <table class="table-jadwal">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Hari</th>
                                    <th>Mata Pelajaran</th>
                                    <th>Status</th>
                                    <th>Guru</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detail_absensi as $absensi): 
                                    $hari_indo = getHariIndonesia($absensi['hari_nama']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-medium text-gray-900">
                                            <?php echo date('d/m/Y', strtotime($absensi['tanggal_absensi'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Minggu ke-<?php echo $absensi['minggu_ke'] ?? '-'; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $hari_indo; ?></td>
                                    <td>
                                        <?php if (!empty($absensi['nama_pelajaran'])): ?>
                                        <div class="font-medium"><?php echo htmlspecialchars($absensi['nama_pelajaran']); ?></div>
                                        <?php else: ?>
                                        <div class="text-gray-500 italic">-</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getStatusClass($absensi['status']); ?>">
                                            <i class="fas <?php echo getStatusIcon($absensi['status']); ?> mr-1"></i>
                                            <?php echo ucfirst($absensi['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($absensi['nama_guru'])): ?>
                                        <div class="text-sm"><?php echo htmlspecialchars($absensi['nama_guru']); ?></div>
                                        <?php else: ?>
                                        <div class="text-gray-500 italic">-</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-sm text-gray-600 max-w-xs truncate">
                                            <?php echo !empty($absensi['keterangan']) ? htmlspecialchars($absensi['keterangan']) : '-'; ?>
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
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Rekap Absensi</p>
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
                                <?php echo count($siswa_list); ?> Anak
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
        
        // Animasi progress bar saat scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const progressBar = entry.target;
                        const width = progressBar.style.width;
                        progressBar.style.width = '0%';
                        
                        setTimeout(() => {
                            progressBar.style.width = width;
                        }, 300);
                    }
                });
            }, { threshold: 0.5 });
            
            // Observe semua progress bar
            document.querySelectorAll('.progress-fill').forEach(bar => {
                observer.observe(bar);
            });
        });
    </script>
</body>
</html>