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

$guru_id = $_SESSION['role_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// Set default periode (bulan ini)
$tahun = date('Y');
$bulan = date('m');
$periode = "$tahun-$bulan";

if (isset($_GET['periode']) && !empty($_GET['periode'])) {
    $periode = $_GET['periode'];
    list($tahun, $bulan) = explode('-', $periode);
}

// Filter tambahan
$filter_siswa = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;
$filter_hari = isset($_GET['hari']) ? $_GET['hari'] : '';
$filter_jam = isset($_GET['jam']) ? $_GET['jam'] : '';
$filter_mapel = isset($_GET['mata_pelajaran']) ? $_GET['mata_pelajaran'] : '';

// Filter tambahan untuk minggu
$filter_minggu = isset($_GET['minggu']) ? intval($_GET['minggu']) : 0; // 1, 2, 3, 4, atau 0 untuk semua

// Hitung rentang tanggal berdasarkan minggu
$tanggal_awal = '';
$tanggal_akhir = '';

if ($filter_minggu > 0 && $filter_minggu <= 4) {
    // Hitung tanggal awal dan akhir minggu dalam bulan yang dipilih
    $first_day_of_month = date('Y-m-01', strtotime($periode . '-01'));
    $last_day_of_month = date('Y-m-t', strtotime($periode . '-01'));
    
    // Tentukan rentang minggu
    $start_day = (($filter_minggu - 1) * 7) + 1;
    $end_day = min($filter_minggu * 7, date('t', strtotime($first_day_of_month)));
    
    $tanggal_awal = date('Y-m-d', strtotime($first_day_of_month . " +" . ($start_day - 1) . " days"));
    $tanggal_akhir = date('Y-m-d', strtotime($first_day_of_month . " +" . ($end_day - 1) . " days"));
} else {
    // Filter bulanan penuh
    $tanggal_awal = date('Y-m-01', strtotime($periode . '-01'));
    $tanggal_akhir = date('Y-m-t', strtotime($periode . '-01'));
}

// Ambil daftar siswa yang diajar oleh guru ini untuk dropdown filter
$siswa_options = [];
$hari_options = [];
$jam_options = [];
$mapel_options = [];

// **QUERY untuk mendapatkan semua siswa dan mata pelajaran - DIUBAH**
$sql_jadwal = "SELECT DISTINCT 
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah,
                sp.id as siswa_pelajaran_id,
                sp.nama_pelajaran
              FROM siswa_pelajaran sp
              JOIN siswa s ON sp.siswa_id = s.id
              JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
              WHERE sp.guru_id = ? 
              AND ps.status = 'aktif'
              AND sp.status = 'aktif'
              AND s.status = 'aktif'
              ORDER BY s.nama_lengkap ASC";

$stmt_jadwal = $conn->prepare($sql_jadwal);
$stmt_jadwal->bind_param("i", $guru_id);
$stmt_jadwal->execute();
$result_jadwal = $stmt_jadwal->get_result();

while ($row = $result_jadwal->fetch_assoc()) {
    // Koleksi opsi siswa
    if (!isset($siswa_options[$row['siswa_id']])) {
        $siswa_options[$row['siswa_id']] = [
            'id' => $row['siswa_id'],
            'nama_lengkap' => $row['nama_lengkap'],
            'kelas_sekolah' => $row['kelas_sekolah']
        ];
    }
    
    // Koleksi opsi mata pelajaran
    if (!empty($row['nama_pelajaran']) && !in_array($row['nama_pelajaran'], $mapel_options)) {
        $mapel_options[] = $row['nama_pelajaran'];
    }
}
$stmt_jadwal->close();

// **QUERY untuk mendapatkan hari dan jam yang tersedia - TAMBAHAN**
$sql_hari_jam = "SELECT DISTINCT 
                  smg.hari,
                  smg.jam_mulai,
                  smg.jam_selesai
                FROM sesi_mengajar_guru smg
                WHERE smg.guru_id = ?
                AND smg.status = 'tersedia'
                ORDER BY FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
                         smg.jam_mulai";

$stmt_hari_jam = $conn->prepare($sql_hari_jam);
$stmt_hari_jam->bind_param("i", $guru_id);
$stmt_hari_jam->execute();
$result_hari_jam = $stmt_hari_jam->get_result();

while ($row = $result_hari_jam->fetch_assoc()) {
    // Koleksi opsi hari
    if (!empty($row['hari']) && !in_array($row['hari'], $hari_options)) {
        $hari_options[] = $row['hari'];
    }
    
    // Koleksi opsi jam
    if (!empty($row['jam_mulai']) && !empty($row['jam_selesai'])) {
        $jam_key = $row['jam_mulai'] . '_' . $row['jam_selesai'];
        if (!isset($jam_options[$jam_key])) {
            $jam_options[$jam_key] = [
                'jam_mulai' => $row['jam_mulai'],
                'jam_selesai' => $row['jam_selesai']
            ];
        }
    }
}
$stmt_hari_jam->close();

// **QUERY UTAMA UNTUK DATA REKAP - DIUBAH**
$sql_rekap = "SELECT 
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah,
                sp.id as siswa_pelajaran_id,
                sp.nama_pelajaran,
                ps.tingkat as tingkat_bimbel,
                ps.id as pendaftaran_id,
                smg.hari,
                smg.jam_mulai,
                smg.jam_selesai
              FROM siswa_pelajaran sp
              JOIN siswa s ON sp.siswa_id = s.id
              JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
              LEFT JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id 
              LEFT JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
              WHERE sp.guru_id = ?
              AND ps.status = 'aktif'
              AND sp.status = 'aktif'
              AND s.status = 'aktif'
              AND (jb.status = 'aktif' OR jb.status IS NULL)";

// Tambahkan filter
$params = [$guru_id];
$param_types = "i";

if ($filter_siswa > 0) {
    $sql_rekap .= " AND s.id = ?";
    $params[] = $filter_siswa;
    $param_types .= "i";
}

if (!empty($filter_mapel)) {
    $sql_rekap .= " AND sp.nama_pelajaran LIKE ?";
    $params[] = '%' . $filter_mapel . '%';
    $param_types .= "s";
}

if (!empty($filter_hari)) {
    $sql_rekap .= " AND smg.hari = ?";
    $params[] = $filter_hari;
    $param_types .= "s";
}

if (!empty($filter_jam)) {
    list($jam_mulai, $jam_selesai) = explode('_', $filter_jam);
    $sql_rekap .= " AND smg.jam_mulai = ? AND smg.jam_selesai = ?";
    $params[] = $jam_mulai;
    $params[] = $jam_selesai;
    $param_types .= "ss";
}

$sql_rekap .= " ORDER BY s.nama_lengkap ASC, sp.nama_pelajaran ASC";

// Eksekusi query
$stmt_rekap = $conn->prepare($sql_rekap);
if ($params) {
    $stmt_rekap->bind_param($param_types, ...$params);
}
$stmt_rekap->execute();
$result_rekap = $stmt_rekap->get_result();

$grouped_data = []; // Untuk mengelompokkan data per siswa
$all_siswa_pelajaran_ids = []; // Untuk menyimpan semua siswa_pelajaran_id

// Statistik total
$total_siswa_rekap = 0;
$total_mata_pelajaran_rekap = 0;
$total_hadir_rekap = 0;
$total_izin_rekap = 0;
$total_sakit_rekap = 0;
$total_alpha_rekap = 0;
$total_belum_absen = 0;

while ($row = $result_rekap->fetch_assoc()) {
    $all_siswa_pelajaran_ids[] = $row['siswa_pelajaran_id'];
    
    // **AMBIL DATA ABSENSI UNTUK SISWA INI berdasarkan siswa_pelajaran_id**
    $sql_absensi = "SELECT 
                    COUNT(*) as total_sesi,
                    SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as total_hadir,
                    SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as total_izin,
                    SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as total_sakit,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as total_alpha
                  FROM absensi_siswa 
                  WHERE siswa_id = ? 
                  AND siswa_pelajaran_id = ?
                  AND guru_id = ?
                  AND tanggal_absensi BETWEEN ? AND ?";
    
    $stmt_absensi = $conn->prepare($sql_absensi);
    $stmt_absensi->bind_param("iiiss", 
        $row['siswa_id'], 
        $row['siswa_pelajaran_id'], 
        $guru_id,
        $tanggal_awal,
        $tanggal_akhir
    );
    $stmt_absensi->execute();
    $result_absensi = $stmt_absensi->get_result();
    $absensi = $result_absensi->fetch_assoc() ?? [
        'total_sesi' => 0,
        'total_hadir' => 0,
        'total_izin' => 0,
        'total_sakit' => 0,
        'total_alpha' => 0
    ];
    $stmt_absensi->close();
    
    // **HITUNG TOTAL SESI YANG SEHARUSNYA berdasarkan jadwal**
    // Pertama, cek apakah ada jadwal di sesi_mengajar_guru untuk siswa ini
    $total_sesi_jadwal = 0;
    
    if (!empty($row['hari']) && !empty($row['jam_mulai'])) {
        // Jika ada jadwal di sesi_mengajar_guru, hitung berapa sesi dalam periode
        $start_date = new DateTime($tanggal_awal);
        $end_date = new DateTime($tanggal_akhir);
        
        $hari_map = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu'
        ];
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));
        
        foreach ($date_range as $date) {
            $english_day = $date->format('l');
            if ($hari_map[$english_day] == $row['hari']) {
                $total_sesi_jadwal++;
            }
        }
    } else {
        // Jika tidak ada jadwal spesifik, anggap 4 sesi per bulan (1 per minggu)
        $total_sesi_jadwal = ($filter_minggu > 0) ? 1 : 4;
    }
    
    // Hitung yang belum diabsen
    $total_sudah_absen = $absensi['total_hadir'] + $absensi['total_izin'] + $absensi['total_sakit'] + $absensi['total_alpha'];
    $belum_absen = $total_sesi_jadwal - $total_sudah_absen;
    if ($belum_absen < 0) $belum_absen = 0;
    
    // Buat key unik untuk siswa + mata pelajaran
    $key = $row['siswa_id'] . '_' . $row['siswa_pelajaran_id'];
    
    if (!isset($grouped_data[$key])) {
        $grouped_data[$key] = [
            'siswa_id' => $row['siswa_id'],
            'nama_lengkap' => $row['nama_lengkap'],
            'kelas_sekolah' => $row['kelas_sekolah'],
            'siswa_pelajaran_id' => $row['siswa_pelajaran_id'],
            'nama_pelajaran' => $row['nama_pelajaran'],
            'tingkat_bimbel' => $row['tingkat_bimbel'],
            'total_sesi_jadwal' => $total_sesi_jadwal,
            'total_sesi' => $absensi['total_sesi'],
            'total_hadir' => $absensi['total_hadir'],
            'total_izin' => $absensi['total_izin'],
            'total_sakit' => $absensi['total_sakit'],
            'total_alpha' => $absensi['total_alpha'],
            'belum_absen' => $belum_absen,
            'jadwal_info' => []
        ];
        $total_mata_pelajaran_rekap++;
    }
    
    // Tambahkan info jadwal jika ada
    if (!empty($row['hari'])) {
        $jadwal_key = $row['hari'] . '_' . $row['jam_mulai'] . '_' . $row['jam_selesai'];
        if (!isset($grouped_data[$key]['jadwal_info'][$jadwal_key])) {
            $grouped_data[$key]['jadwal_info'][$jadwal_key] = [
                'hari' => $row['hari'],
                'jam_mulai' => $row['jam_mulai'],
                'jam_selesai' => $row['jam_selesai']
            ];
        }
    }
    
    // Update statistik
    $total_hadir_rekap += $absensi['total_hadir'];
    $total_izin_rekap += $absensi['total_izin'];
    $total_sakit_rekap += $absensi['total_sakit'];
    $total_alpha_rekap += $absensi['total_alpha'];
    $total_belum_absen += $belum_absen;
}
$stmt_rekap->close();

$total_siswa_rekap = count($grouped_data);

// **QUERY untuk statistik total yang lebih akurat - DIUBAH**
$sql_stats_total = "SELECT 
                    COUNT(DISTINCT s.id) as total_siswa,
                    COUNT(DISTINCT sp.id) as total_mata_pelajaran
                  FROM siswa_pelajaran sp
                  JOIN siswa s ON sp.siswa_id = s.id
                  JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                  WHERE sp.guru_id = ?
                  AND ps.status = 'aktif'
                  AND sp.status = 'aktif'
                  AND s.status = 'aktif'";

$stmt_stats = $conn->prepare($sql_stats_total);
$stmt_stats->bind_param("i", $guru_id);
$stmt_stats->execute();
$result_stats = $stmt_stats->get_result();
$stats_total = $result_stats->fetch_assoc() ?? [
    'total_siswa' => 0,
    'total_mata_pelajaran' => 0
];
$stmt_stats->close();

// Hitung total sesi seharusnya berdasarkan filter
if (count($all_siswa_pelajaran_ids) > 0) {
    // Hitung total sesi berdasarkan filter minggu
    if ($filter_minggu > 0) {
        $total_sesi_seharusnya = count($all_siswa_pelajaran_ids) * 1; // 1 sesi per minggu
    } else {
        // Default 4 sesi per bulan per mata pelajaran
        $total_sesi_seharusnya = count($all_siswa_pelajaran_ids) * 4;
    }
    
    $stats_total['total_sesi_seharusnya'] = $total_sesi_seharusnya;
} else {
    $stats_total['total_sesi_seharusnya'] = 0;
}

// Hitung total absensi aktual - CARA SEDERHANA
$stats_total['total_absensi_aktual'] = 0;
if (count($all_siswa_pelajaran_ids) > 0) {
    // Cara sederhana tanpa binding dinamis
    $ids_string = implode(',', array_map('intval', $all_siswa_pelajaran_ids));
    $sql_absensi_simple = "SELECT COUNT(*) as total_absensi_aktual
                          FROM absensi_siswa 
                          WHERE guru_id = $guru_id
                          AND siswa_pelajaran_id IN ($ids_string)
                          AND tanggal_absensi BETWEEN '$tanggal_awal' AND '$tanggal_akhir'";
    
    $result_simple = $conn->query($sql_absensi_simple);
    if ($result_simple) {
        $row_simple = $result_simple->fetch_assoc();
        $stats_total['total_absensi_aktual'] = $row_simple ? $row_simple['total_absensi_aktual'] : 0;
    }
}

// Perbaiki perhitungan total sudah absen
$total_sudah_absen = $total_hadir_rekap + $total_izin_rekap + $total_sakit_rekap + $total_alpha_rekap;

// Hitung belum absen berdasarkan sesi seharusnya dan sudah absen
$stats_total['belum_absen'] = $stats_total['total_sesi_seharusnya'] - $total_sudah_absen;
if ($stats_total['belum_absen'] < 0) {
    $stats_total['belum_absen'] = 0;
}

// Tambahkan statistik dari perhitungan sebelumnya
$stats_total['total_hadir'] = $total_hadir_rekap;
$stats_total['total_izin'] = $total_izin_rekap;
$stats_total['total_sakit'] = $total_sakit_rekap;
$stats_total['total_alpha'] = $total_alpha_rekap;

// Tampilkan label periode
if ($filter_minggu > 0) {
    $periode_label = "Minggu ke-$filter_minggu " . date('F Y', strtotime($periode . '-01')) . 
                    " (" . date('d/m', strtotime($tanggal_awal)) . " - " . date('d/m', strtotime($tanggal_akhir)) . ")";
} else {
    $periode_label = date('F Y', strtotime($periode . '-01'));
}

// Konversi grouped_data ke array untuk ditampilkan
$final_rekap_siswa = array_values($grouped_data);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .status-hadir { background-color: #d1fae5; color: #065f46; }
        .status-izin { background-color: #fef3c7; color: #92400e; }
        .status-sakit { background-color: #dbeafe; color: #1e40af; }
        .status-alpha { background-color: #fee2e2; color: #991b1b; }
        .status-belum-absen { background-color: #f3f4f6; color: #6b7280; }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 1000px;
            }
        }
        
        .progress-bar {
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
            background-color: #e5e7eb;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
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
        
        .badge-belum-absen {
            background-color: #f3f4f6;
            color: #6b7280;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Rekap Absensi</p>
        </div>
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
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi Siswa
                    </h1>
                    <p class="text-gray-600">Rekapitulasi absensi siswa yang Anda ajar</p>
                </div>
                <div class="mt-2 md:mt-0 flex items-center space-x-2">
                    <a href="absensiSiswa.php" class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800 hover:bg-blue-200">
                        <i class="fas fa-calendar-check mr-2"></i> Input Absensi
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Rekap</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Periode (Bulan-Tahun)
                        </label>
                        <input type="month" name="periode" value="<?php echo $periode; ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-week mr-1"></i> Minggu ke-
                        </label>
                        <select name="minggu" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Minggu</option>
                            <option value="1" <?php echo ($filter_minggu == 1) ? 'selected' : ''; ?>>Minggu 1 (1-7)</option>
                            <option value="2" <?php echo ($filter_minggu == 2) ? 'selected' : ''; ?>>Minggu 2 (8-14)</option>
                            <option value="3" <?php echo ($filter_minggu == 3) ? 'selected' : ''; ?>>Minggu 3 (15-21)</option>
                            <option value="4" <?php echo ($filter_minggu == 4) ? 'selected' : ''; ?>>Minggu 4 (22-akhir)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user mr-1"></i> Siswa
                        </label>
                        <select name="siswa_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Siswa</option>
                            <?php foreach ($siswa_options as $siswa): ?>
                            <option value="<?php echo $siswa['id']; ?>" 
                                <?php echo ($filter_siswa == $siswa['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> (<?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-book mr-1"></i> Mata Pelajaran
                        </label>
                        <select name="mata_pelajaran" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Mapel</option>
                            <?php foreach ($mapel_options as $mapel): ?>
                            <option value="<?php echo htmlspecialchars($mapel); ?>" 
                                <?php echo ($filter_mapel == $mapel) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mapel); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-clock mr-1"></i> Jam Sesi
                        </label>
                        <select name="jam" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Jam</option>
                            <?php foreach ($jam_options as $jam_key => $jam): ?>
                            <option value="<?php echo $jam_key; ?>" 
                                <?php echo ($filter_jam == $jam_key) ? 'selected' : ''; ?>>
                                <?php echo date('H:i', strtotime($jam['jam_mulai'])); ?> - <?php echo date('H:i', strtotime($jam['jam_selesai'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-day mr-1"></i> Hari
                        </label>
                        <select name="hari" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Hari</option>
                            <?php foreach ($hari_options as $hari): ?>
                            <option value="<?php echo $hari; ?>" 
                                <?php echo ($filter_hari == $hari) ? 'selected' : ''; ?>>
                                <?php echo $hari; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-5 flex justify-end space-x-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                        <a href="rekapAbsensi.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Statistik Total -->
            <div class="mb-6 bg-white rounded-lg shadow p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-chart-pie mr-2"></i>
                            Statistik Total - <?php echo $periode_label; ?>
                        </h3>
                        <p class="text-gray-600">
                            Total <?php echo $stats_total['total_siswa']; ?> siswa, 
                            <?php echo $stats_total['total_mata_pelajaran']; ?> mata pelajaran,
                            <?php echo $stats_total['total_sesi_seharusnya']; ?> sesi seharusnya
                        </p>
                    </div>
                    
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center md:mt-5">
                            <div class="p-2 bg-gray-100 rounded-lg mr-3">
                                <i class="fas fa-users text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Siswa</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_siswa']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-calendar-alt text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Sesi</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_sesi_seharusnya']; ?></p>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            Sesi yang seharusnya ada
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center md:mt-5">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-check text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Hadir</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_hadir']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center md:mt-5">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-envelope text-yellow-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Izin</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_izin']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center md:mt-5">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-thermometer text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Sakit</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_sakit']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex mt-3 items-center md:mt-5">
                            <div class="p-2 bg-red-100 rounded-lg mr-3">
                                <i class="fas fa-times text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Alpha</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_alpha']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Baris untuk statistik belum absen -->
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-lg p-4 shadow border-2 border-gray-300">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-200 rounded-lg mr-3">
                                <i class="fas fa-question-circle text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Belum Diabsensi</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['belum_absen']; ?></p>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            Sesi yang belum diinput absensinya
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-purple-100 rounded-lg mr-3">
                                <i class="fas fa-clipboard-check text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Sudah Diabsensi</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $stats_total['total_absensi_aktual']; ?></p>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-500">
                            Sesi yang sudah diinput
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rekap Per Siswa -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-table mr-2"></i> 
                            Rekap Absensi Per Siswa & Mata Pelajaran - <?php echo $periode_label; ?>
                            <?php if (!empty($final_rekap_siswa)): ?>
                                <span class="text-sm text-gray-600 font-normal">
                                    (<?php echo count($final_rekap_siswa); ?> data ditemukan)
                                </span>
                            <?php endif; ?>
                        </h3>
                        <div class="text-sm text-gray-500">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo date('H:i:s'); ?>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <?php if (empty($final_rekap_siswa)): ?>
                    <div class="p-8 text-center text-gray-500">
                        <i class="fas fa-database text-3xl mb-3"></i>
                        <p class="text-lg">Tidak ada data rekap absensi</p>
                        <p class="text-sm mt-2">Coba sesuaikan filter atau periode yang dipilih</p>
                    </div>
                    <?php else: ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mata Pelajaran</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tingkat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sesi</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hadir</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Izin</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sakit</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alpha</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($final_rekap_siswa as $index => $siswa): 
                                    $total_absensi = $siswa['total_hadir'] + $siswa['total_izin'] + $siswa['total_sakit'] + $siswa['total_alpha'];
                                ?>
                                <tr class="hover:bg-gray-50 <?php echo $siswa['total_sesi_jadwal'] == 0 ? 'bg-gray-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $index + 1; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['nama_pelajaran'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['tingkat_bimbel'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                        <?php echo $siswa['total_sesi_jadwal']; ?>
                                        <?php if ($siswa['total_sesi_jadwal'] == 0): ?>
                                        <span class="text-xs text-gray-400 ml-1">(tidak ada jadwal)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">
                                        <?php echo $siswa['total_hadir']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                        <?php echo $siswa['total_izin']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 font-medium">
                                        <?php echo $siswa['total_sakit']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                        <?php echo $siswa['total_alpha']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Rekap Absensi</p>
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
    </script>
</body>
</html>