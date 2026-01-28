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

// Ambil data siswa yang dimiliki oleh orang tua ini menggunakan tabel siswa_orangtua
$siswa_list = [];
if ($orangtua_id > 0) {
    $sql_siswa = "SELECT s.id, s.nama_lengkap, s.kelas 
                  FROM siswa s 
                  INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                  WHERE so.orangtua_id = ? 
                  AND s.status = 'aktif'
                  ORDER BY s.nama_lengkap";
    $stmt_siswa = $conn->prepare($sql_siswa);
    if ($stmt_siswa) {
        $stmt_siswa->bind_param("i", $orangtua_id);
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

// Filter minggu (1-4)
$minggu_filter = isset($_GET['minggu']) ? intval($_GET['minggu']) : 0; // 0 = semua minggu

// TANGGAL AWAL DAN AKHIR BERDASARKAN FILTER
$start_date = '';
$end_date = '';

if ($minggu_filter > 0) {
    // Hitung tanggal awal dan akhir berdasarkan minggu yang dipilih
    $bulan_tahun = $bulan_filter . '-01';
    $first_day_of_month = date('Y-m-01', strtotime($bulan_tahun));
    $last_day_of_month = date('Y-m-t', strtotime($bulan_tahun));
    
    // Tentukan rentang minggu
    $minggu_ranges = getWeekRangesInMonth($bulan_filter);
    
    if (isset($minggu_ranges[$minggu_filter - 1])) {
        $minggu_range = $minggu_ranges[$minggu_filter - 1];
        $start_date = $minggu_range['start'];
        $end_date = $minggu_range['end'];
    }
}

// FUNGSI UNTUK MENDAPATKAN RENTANG MINGGU DALAM BULAN
function getWeekRangesInMonth($month) {
    $ranges = [];
    
    // Get first and last day of month
    $first_day = date('Y-m-01', strtotime($month . '-01'));
    $last_day = date('Y-m-t', strtotime($month . '-01'));
    
    // Get day of week for first day (0=Sunday, 1=Monday, ...)
    $first_day_of_week = date('w', strtotime($first_day));
    $current_date = $first_day;
    
    $week_num = 1;
    $week_start = $current_date;
    
    while (strtotime($current_date) <= strtotime($last_day)) {
        $day_of_week = date('w', strtotime($current_date));
        
        // Jika hari Sabtu (6) atau sudah tanggal terakhir
        if ($day_of_week == 6 || $current_date == $last_day) {
            $week_end = $current_date;
            $ranges[] = [
                'week' => $week_num,
                'start' => $week_start,
                'end' => $week_end,
                'label' => "Minggu ke-$week_num (" . date('d', strtotime($week_start)) . " - " . date('d', strtotime($week_end)) . " " . date('M Y', strtotime($week_start)) . ")"
            ];
            
            $week_num++;
            $next_day = date('Y-m-d', strtotime($current_date . ' +1 day'));
            $week_start = $next_day;
        }
        
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }
    
    return $ranges;
}

// FUNGSI HELPER UNTUK STATUS
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

// Inisialisasi variabel
$absensi_data = [];
$absensi_detail = [];
$statistik = [
    'total_hadir' => 0,
    'total_izin' => 0,
    'total_sakit' => 0,
    'total_alpha' => 0,
    'total_sesi' => 0,
    'persentase_hadir' => 0
];
$siswa_detail = [];

if ($selected_siswa_id > 0 && $orangtua_id > 0) {
    // Ambil data detail siswa dengan verifikasi hak akses melalui tabel siswa_orangtua
    $sql_detail = "SELECT 
                    s.*,
                    ps.tingkat as tingkat_bimbel,
                    ps.jenis_kelas,
                    ps.tahun_ajaran
                   FROM siswa s 
                   INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                   LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
                   WHERE s.id = ? 
                   AND so.orangtua_id = ?
                   AND s.status = 'aktif'";
    
    $stmt_detail = $conn->prepare($sql_detail);
    if ($stmt_detail) {
        $stmt_detail->bind_param("ii", $selected_siswa_id, $orangtua_id);
        $stmt_detail->execute();
        $result_detail = $stmt_detail->get_result();
        $siswa_detail = $result_detail->fetch_assoc() ?? [];
        $stmt_detail->close();
    }
    
    // AMBIL DATA ABSENSI DETAIL BERDASARKAN FILTER
    if (!empty($siswa_detail)) {
        // Build query berdasarkan filter
        $query_conditions = "a.siswa_id = ?";
        $params = [$selected_siswa_id];
        $param_types = "i";
        
        // Filter berdasarkan bulan
        if (!empty($bulan_filter)) {
            $query_conditions .= " AND DATE_FORMAT(a.tanggal_absensi, '%Y-%m') = ?";
            $params[] = $bulan_filter;
            $param_types .= "s";
        }
        
        // Filter berdasarkan minggu jika dipilih
        if ($minggu_filter > 0 && !empty($start_date) && !empty($end_date)) {
            $query_conditions .= " AND a.tanggal_absensi BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
            $param_types .= "ss";
        }
        
        // QUERY YANG DIPERBAIKI: Ambil data absensi dengan pelajaran DAN WAKTU ABSEN
        $sql_detail_absensi = "SELECT 
                                a.*,
                                -- Waktu absen (HANYA JAM:MM dari created_at)
                                DATE_FORMAT(a.created_at, '%H:%i') as waktu_absen,
                                
                                -- Mata pelajaran
                                COALESCE(
                                    sp.nama_pelajaran,
                                    -- Jika tidak ada pelajaran spesifik, cari berdasarkan guru
                                    (SELECT sp2.nama_pelajaran 
                                     FROM siswa_pelajaran sp2 
                                     WHERE sp2.siswa_id = a.siswa_id 
                                     AND sp2.guru_id = a.guru_id
                                     AND sp2.pendaftaran_id = a.pendaftaran_id
                                     AND sp2.status = 'aktif'
                                     LIMIT 1),
                                    'Pelajaran Umum'
                                ) as nama_pelajaran,
                                
                                -- Data lainnya
                                DATE_FORMAT(a.tanggal_absensi, '%W') as hari,
                                DATE_FORMAT(a.tanggal_absensi, '%d') as tanggal,
                                u.full_name as nama_guru,
                                
                                -- Jadwal (jika ada)
                                jb.jam_mulai,
                                jb.jam_selesai,
                                jb.hari as jadwal_hari,
                                ps.tingkat
                            FROM absensi_siswa a
                            LEFT JOIN siswa_pelajaran sp ON a.siswa_pelajaran_id = sp.id
                            LEFT JOIN guru g ON a.guru_id = g.id
                            LEFT JOIN users u ON g.user_id = u.id
                            LEFT JOIN jadwal_belajar jb ON a.jadwal_id = jb.id
                            LEFT JOIN pendaftaran_siswa ps ON a.pendaftaran_id = ps.id
                            WHERE $query_conditions
                            ORDER BY a.tanggal_absensi DESC, a.created_at DESC";
        
        $stmt_detail = $conn->prepare($sql_detail_absensi);
        if ($stmt_detail) {
            // Bind parameters
            $bind_params = array_merge([$param_types], $params);
            $refs = [];
            foreach($bind_params as $key => $value) {
                $refs[$key] = &$bind_params[$key];
            }
            
            call_user_func_array([$stmt_detail, 'bind_param'], $refs);
            $stmt_detail->execute();
            $result_detail = $stmt_detail->get_result();
            
            while ($row = $result_detail->fetch_assoc()) {
                $absensi_detail[] = $row;
            }
            $stmt_detail->close();
            
            // Jika masih ada yang tidak punya nama pelajaran, cari alternatif
            foreach ($absensi_detail as &$absensi) {
                if (empty($absensi['nama_pelajaran']) || $absensi['nama_pelajaran'] == 'Pelajaran Umum') {
                    // Coba cari pelajaran berdasarkan jadwal jika ada
                    if (!empty($absensi['jadwal_id'])) {
                        $sql_pelajaran_jadwal = "SELECT sp.nama_pelajaran 
                                                 FROM jadwal_belajar jb
                                                 JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                                                 WHERE jb.id = ?";
                        $stmt_pj = $conn->prepare($sql_pelajaran_jadwal);
                        $stmt_pj->bind_param("i", $absensi['jadwal_id']);
                        $stmt_pj->execute();
                        $result_pj = $stmt_pj->get_result();
                        if ($row_pj = $result_pj->fetch_assoc()) {
                            $absensi['nama_pelajaran'] = $row_pj['nama_pelajaran'];
                        }
                        $stmt_pj->close();
                    }
                    
                    // Jika masih kosong, ambil pelajaran pertama dari pendaftaran
                    if (empty($absensi['nama_pelajaran']) || $absensi['nama_pelajaran'] == 'Pelajaran Umum') {
                        if (!empty($absensi['pendaftaran_id'])) {
                            $sql_pelajaran_pertama = "SELECT nama_pelajaran 
                                                      FROM siswa_pelajaran 
                                                      WHERE pendaftaran_id = ? 
                                                      AND status = 'aktif' 
                                                      LIMIT 1";
                            $stmt_pp = $conn->prepare($sql_pelajaran_pertama);
                            $stmt_pp->bind_param("i", $absensi['pendaftaran_id']);
                            $stmt_pp->execute();
                            $result_pp = $stmt_pp->get_result();
                            if ($row_pp = $result_pp->fetch_assoc()) {
                                $absensi['nama_pelajaran'] = $row_pp['nama_pelajaran'] . ' (diperkirakan)';
                            }
                            $stmt_pp->close();
                        }
                    }
                }
            }
            unset($absensi); // Hapus reference
            
            // Hitung statistik
            if (count($absensi_detail) > 0) {
                $statistik['total_sesi'] = count($absensi_detail);
                $statistik['total_hadir'] = count(array_filter($absensi_detail, function($item) {
                    return $item['status'] == 'hadir';
                }));
                $statistik['total_izin'] = count(array_filter($absensi_detail, function($item) {
                    return $item['status'] == 'izin';
                }));
                $statistik['total_sakit'] = count(array_filter($absensi_detail, function($item) {
                    return $item['status'] == 'sakit';
                }));
                $statistik['total_alpha'] = count(array_filter($absensi_detail, function($item) {
                    return $item['status'] == 'alpha';
                }));
                
                if ($statistik['total_sesi'] > 0) {
                    $statistik['persentase_hadir'] = round(($statistik['total_hadir'] / $statistik['total_sesi']) * 100, 2);
                }
            }
        }
        
        // AMBIL DATA REKAP UNTUK BULAN YANG DIPILIH (untuk tabel rekap)
        $sql_absensi_rekap = "SELECT 
                            DATE_FORMAT(a.tanggal_absensi, '%Y-%m') as bulan,
                            COUNT(*) as total_sesi,
                            SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) as total_hadir,
                            SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) as total_izin,
                            SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) as total_sakit,
                            SUM(CASE WHEN a.status = 'alpha' THEN 1 ELSE 0 END) as total_alpha
                        FROM absensi_siswa a
                        WHERE a.siswa_id = ?
                        AND DATE_FORMAT(a.tanggal_absensi, '%Y-%m') = ?
                        GROUP BY DATE_FORMAT(a.tanggal_absensi, '%Y-%m')
                        ORDER BY bulan DESC";
        
        $stmt_absensi = $conn->prepare($sql_absensi_rekap);
        if ($stmt_absensi) {
            $stmt_absensi->bind_param("is", $selected_siswa_id, $bulan_filter);
            $stmt_absensi->execute();
            $result_absensi = $stmt_absensi->get_result();
            
            while ($row = $result_absensi->fetch_assoc()) {
                $absensi_data[] = $row;
            }
            $stmt_absensi->close();
        }
        
        // Ambil daftar pelajaran yang diambil siswa BESERTA GURUNYA
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
        if ($stmt_pelajaran) {
            $stmt_pelajaran->bind_param("i", $selected_siswa_id);
            $stmt_pelajaran->execute();
            $result_pelajaran = $stmt_pelajaran->get_result();
            
            while ($row = $result_pelajaran->fetch_assoc()) {
                $pelajaran_list[] = $row;
            }
            $stmt_pelajaran->close();
        }
    }
}

// Generate list tahun
$tahun_list = [];
$current_year = date('Y');
for ($i = $current_year; $i >= $current_year - 5; $i--) {
    $tahun_list[] = $i;
}

// Dapatkan daftar minggu untuk bulan yang dipilih
$minggu_options = [];
if (!empty($bulan_filter)) {
    $minggu_ranges = getWeekRangesInMonth($bulan_filter);
    foreach ($minggu_ranges as $week) {
        $minggu_options[$week['week']] = $week['label'];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Siswa - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .status-hadir { background-color: #d1fae5; color: #065f46; }
        .status-izin { background-color: #fef3c7; color: #92400e; }
        .status-sakit { background-color: #dbeafe; color: #1e40af; }
        .status-alpha { background-color: #fee2e2; color: #991b1b; }
        .status-default { background-color: #f3f4f6; color: #6b7280; }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 600px;
            }
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
            
            .filter-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.5s ease;
        }
        
        .info-card {
            transition: transform 0.3s ease;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
        }
        
        /* Badge untuk filter aktif */
        .filter-active {
            background-color: #3b82f6 !important;
            color: white !important;
        }
        
        /* Highlight untuk pelajaran yang diabsen */
        .highlight-pelajaran {
            background-color: #e0f2fe !important;
            border-left: 3px solid #0ea5e9 !important;
        }
        
        /* Style untuk waktu absen */
        .waktu-absen {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #1f2937;
            background-color: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            min-width: 45px;
            text-align: center;
        }
        
        /* Indikator waktu */
        .waktu-pagi { color: #1e40af; }
        .waktu-siang { color: #ca8a04; }
        .waktu-sore { color: #ea580c; }
        .waktu-malam { color: #6b7280; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Absensi Siswa</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                </div>
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
                    <p class="text-sm"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
                    <p class="text-xs text-blue-300">Orang Tua</p>
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
                    </div>
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
                        <i class="fas fa-calendar-check mr-2"></i> Absensi Siswa
                    </h1>
                    <p class="text-gray-600">Pantau kehadiran anak di bimbel</p>
                </div>
                <div class="mt-2 md:mt-0 flex items-center space-x-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Data Absensi</h3>
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-user-graduate mr-1"></i> Siswa
                            </label>
                            <select name="siswa_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <option value="">Pilih Siswa</option>
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
                            <input type="month" name="bulan" value="<?php echo $bulan_filter; ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   onchange="this.form.submit()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-calendar-week mr-1"></i> Minggu
                            </label>
                            <select name="minggu" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <option value="0">Semua Minggu</option>
                                <?php foreach ($minggu_options as $week_num => $week_label): ?>
                                <option value="<?php echo $week_num; ?>" 
                                    <?php echo ($minggu_filter == $week_num) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($week_label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full md:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-search mr-2"></i> Tampilkan
                            </button>
                        </div>
                    </div>
                    
                    <!-- Info Filter Aktif -->
                    <?php if ($minggu_filter > 0): ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                            <span class="text-sm text-blue-800">
                                Menampilkan data untuk <?php echo $minggu_options[$minggu_filter]; ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($selected_siswa_id > 0 && !empty($siswa_detail)): ?>
            <!-- Info Siswa -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                    <div class="mb-4 md:mb-0">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-user-graduate mr-2"></i>
                            <?php echo htmlspecialchars($siswa_detail['nama_lengkap']); ?>
                        </h3>
                        <div class="mt-2 grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Kelas Sekolah</p>
                                <p class="font-medium"><?php echo htmlspecialchars($siswa_detail['kelas']); ?></p>
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
                </div>
                
                <!-- Daftar Pelajaran dengan Guru -->
                <?php if (!empty($pelajaran_list)): ?>
                <div class="mt-4">
                    <p class="text-sm text-gray-600 mb-2">Mata Pelajaran dan Guru Pengajar:</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($pelajaran_list as $pelajaran): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-book mr-1"></i>
                            <?php echo htmlspecialchars($pelajaran['nama_pelajaran']); ?>
                            <?php if (!empty($pelajaran['nama_guru'])): ?>
                            <span class="ml-1 text-xs">(Guru: <?php echo htmlspecialchars($pelajaran['nama_guru']); ?>)</span>
                            <?php else: ?>
                            <span class="ml-1 text-xs">(Belum ada guru)</span>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg p-4 shadow info-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-green-100 rounded-lg mr-3">
                            <i class="fas fa-check text-green-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Hadir</p>
                            <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_hadir']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg p-4 shadow info-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                            <i class="fas fa-envelope text-yellow-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Izin</p>
                            <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_izin']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg p-4 shadow info-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg mr-3">
                            <i class="fas fa-thermometer text-blue-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Sakit</p>
                            <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_sakit']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg p-4 shadow info-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-red-100 rounded-lg mr-3">
                            <i class="fas fa-times text-red-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Alpha</p>
                            <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_alpha']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

           
            <!-- Detail Absensi -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-calendar-day mr-2"></i> 
                            <?php if ($minggu_filter > 0): ?>
                                Absensi <?php echo $minggu_options[$minggu_filter]; ?>
                            <?php else: ?>
                                Absensi Bulan <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>
                            <?php endif; ?>
                        </h3>
                        <div class="text-sm text-gray-500">
                            Total: <?php echo $statistik['total_sesi']; ?> sesi
                        </div>
                    </div>
                </div>
                <div class="p-6">
                    <?php if (empty($absensi_detail)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-calendar-times text-3xl mb-3"></i>
                        <p class="text-lg">Tidak ada data absensi</p>
                        <p class="text-sm mt-2">
                            <?php if ($minggu_filter > 0): ?>
                                Tidak ada absensi untuk <?php echo $minggu_options[$minggu_filter]; ?>
                            <?php else: ?>
                                Tidak ada absensi untuk bulan <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hari</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mata Pelajaran</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keterangan</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guru</th>
                                    <!--<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Absen</th>-->
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($absensi_detail as $absensi): 
                                    // Konversi hari ke bahasa Indonesia
                                    $hari_indonesia = '';
                                    switch($absensi['hari']) {
                                        case 'Monday': $hari_indonesia = 'Senin'; break;
                                        case 'Tuesday': $hari_indonesia = 'Selasa'; break;
                                        case 'Wednesday': $hari_indonesia = 'Rabu'; break;
                                        case 'Thursday': $hari_indonesia = 'Kamis'; break;
                                        case 'Friday': $hari_indonesia = 'Jumat'; break;
                                        case 'Saturday': $hari_indonesia = 'Sabtu'; break;
                                        case 'Sunday': $hari_indonesia = 'Minggu'; break;
                                        default: 
                                            if (!empty($absensi['jadwal_hari'])) {
                                                $hari_indonesia = $absensi['jadwal_hari'];
                                            } else {
                                                $hari_indonesia = '-';
                                            }
                                            break;
                                    }
                                    
                                    // Waktu absen dan indikator
                                    $waktu_absen = '-';
                                    $waktu_class = '';
                                    if (!empty($absensi['waktu_absen'])) {
                                        $waktu_absen = htmlspecialchars($absensi['waktu_absen']);
                                        $jam = (int)substr($absensi['waktu_absen'], 0, 2);
                                        if ($jam < 12) {
                                            $waktu_class = 'waktu-pagi';
                                        } elseif ($jam < 15) {
                                            $waktu_class = 'waktu-siang';
                                        } elseif ($jam < 18) {
                                            $waktu_class = 'waktu-sore';
                                        } else {
                                            $waktu_class = 'waktu-malam';
                                        }
                                    } elseif (!empty($absensi['created_at'])) {
                                        // Fallback ke created_at
                                        $waktu_absen = date('H:i', strtotime($absensi['created_at']));
                                    }
                                    
                                    // Highlight jika pelajaran diperkirakan
                                    $row_class = '';
                                    $pelajaran_info = '';
                                    if (strpos($absensi['nama_pelajaran'] ?? '', '(diperkirakan)') !== false) {
                                        $row_class = 'highlight-pelajaran';
                                        $pelajaran_info = '<span class="text-xs text-gray-500 ml-1">(diperkirakan)</span>';
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 <?php echo $row_class; ?>">
                                    <!-- Tanggal -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo date('d F Y', strtotime($absensi['tanggal_absensi'])); ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Hari -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo $hari_indonesia; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Mata Pelajaran -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo !empty($absensi['nama_pelajaran']) ? htmlspecialchars($absensi['nama_pelajaran']) : '-'; ?>
                                            <?php echo $pelajaran_info; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatusClass($absensi['status']); ?>">
                                            <i class="fas <?php echo getStatusIcon($absensi['status']); ?> mr-1"></i>
                                            <?php echo ucfirst($absensi['status']); ?>
                                        </span>
                                    </td>
                                    
                                    <!-- Keterangan -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 max-w-xs truncate">
                                            <?php echo !empty($absensi['keterangan']) ? htmlspecialchars($absensi['keterangan']) : '-'; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- Guru -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo !empty($absensi['nama_guru']) ? htmlspecialchars($absensi['nama_guru']) : '-'; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- WAKTU ABSEN -->
                                    <!--<td class="px-6 py-4 text-blue-600">-->
                                    <!--    <div class="text-sm <?php echo $waktu_class; ?>">-->
                                    <!--        <span class="waktu-absen">-->
                                    <!--            <?php echo $waktu_absen; ?>-->
                                    <!--        </span>-->
                                    <!--    </div>-->
                                    <!--</td>-->
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Catatan Penting -->
                    <?php if (!empty($absensi_detail) && strpos(json_encode($absensi_detail), '(diperkirakan)') !== false): ?>
                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                            <div>
                                <p class="text-sm font-medium text-yellow-800">Catatan:</p>
                                <p class="text-sm text-yellow-700 mt-1">
                                    Beberapa data absensi tidak memiliki informasi mata pelajaran spesifik karena sistem tidak merekam pelajaran mana yang diabsen. 
                                    Data yang ditampilkan dengan label "(diperkirakan)" adalah perkiraan berdasarkan data pendaftaran siswa.
                                </p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rekap Absensi Per Bulan (hanya ditampilkan jika tidak sedang filter per minggu) -->
            <?php if ($minggu_filter == 0): ?>
            <div class="bg-white shadow rounded-lg mt-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi Bulan <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?>
                    </h3>
                </div>
                <div class="p-6">
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bulan</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sesi</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hadir</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Izin</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sakit</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alpha</th>
                                    <!--<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kehadiran</th>-->
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($absensi_data)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                        <i class="fas fa-calendar-times text-3xl mb-3"></i>
                                        <p class="text-lg">Belum ada data absensi untuk bulan <?php echo date('F Y', strtotime($bulan_filter . '-01')); ?></p>
                                        <p class="text-sm mt-2">Absensi akan muncul setelah guru menginput data</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($absensi_data as $data): 
                                        $persentase = $data['total_sesi'] > 0 ? 
                                            round(($data['total_hadir'] / $data['total_sesi']) * 100, 2) : 0;
                                        $bulan_nama = date('F Y', strtotime($data['bulan'] . '-01'));
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo $bulan_nama; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $data['total_sesi']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">
                                            <i class="fas fa-check mr-1"></i>
                                            <?php echo $data['total_hadir']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                            <i class="fas fa-envelope mr-1"></i>
                                            <?php echo $data['total_izin']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 font-medium">
                                            <i class="fas fa-thermometer mr-1"></i>
                                            <?php echo $data['total_sakit']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 font-medium">
                                            <i class="fas fa-times mr-1"></i>
                                            <?php echo $data['total_alpha']; ?>
                                        </td>
                                        <!--<td class="px-6 py-4 whitespace-nowrap">-->
                                        <!--    <div class="flex items-center">-->
                                        <!--        <div class="w-32 bg-gray-200 rounded-full h-2 mr-3">-->
                                        <!--            <div class="bg-blue-600 h-2 rounded-full transition-all duration-500" -->
                                        <!--                 style="width: <?php echo $persentase; ?>%"></div>-->
                                        <!--        </div>-->
                                        <!--        <span class="text-sm text-gray-700 font-medium">-->
                                        <!--            <?php echo $persentase; ?>%-->
                                        <!--        </span>-->
                                        <!--    </div>-->
                                        <!--</td>-->
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php elseif (empty($siswa_list)): ?>
            <!-- Tidak ada siswa -->
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-user-graduate text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Belum Terdaftar Sebagai Orang Tua</h3>
                <p class="text-gray-600 mb-4">Akun Anda belum terdaftar sebagai orang tua dari siswa manapun</p>
                <div class="text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Hubungi administrator untuk mendaftarkan anak Anda
                </div>
            </div>
            <?php else: ?>
            <!-- Pilih siswa dulu -->
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-user-graduate text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Pilih Siswa Terlebih Dahulu</h3>
                <p class="text-gray-600 mb-4">Silakan pilih siswa dari filter di atas untuk melihat data absensi</p>
                <?php if (!empty($siswa_list)): ?>
                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($siswa_list as $siswa): ?>
                    <a href="?siswa_id=<?php echo $siswa['id']; ?>&bulan=<?php echo date('Y-m'); ?>&minggu=0" 
                       class="bg-white border border-gray-200 rounded-lg p-4 text-left hover:bg-gray-50 transition-colors">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($siswa['nama_lengkap']); ?></h4>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($siswa['kelas']); ?></p>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-8">
            <div class="md:ml-64">
                <div class="container mx-auto py-4 px-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-500">
                            <p>© <?php echo date('Y'); ?> Bimbel Esc - Absensi Siswa</p>
                            <p class="mt-1 text-xs text-gray-400">Dashboard Orang Tua</p>
                        </div>
                        <div class="mt-3 md:mt-0">
                            <span class="text-sm text-gray-500">
                                <i class="fas fa-clock mr-1"></i>
                                <?php echo date('H:i:s'); ?>
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
        
        // Animasi progress bar saat scroll
        document.addEventListener('DOMContentLoaded', function() {
            const progressBars = document.querySelectorAll('.bg-blue-600.h-4, .bg-blue-600.h-2');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const width = entry.target.style.width;
                        entry.target.style.width = '0%';
                        setTimeout(() => {
                            entry.target.style.width = width;
                        }, 100);
                    }
                });
            }, { threshold: 0.5 });
            
            progressBars.forEach(bar => observer.observe(bar));
        });
    </script>
</body>
</html>