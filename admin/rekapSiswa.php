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

if ($_SESSION['user_role'] != 'admin') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// Set default periode (bulan ini)
$tahun = date('Y');
$bulan = date('m');
$periode = "$tahun-$bulan";

if (isset($_GET['periode']) && !empty($_GET['periode'])) {
    $periode = $_GET['periode'];
    list($tahun, $bulan) = explode('-', $periode);
}

// Filter guru
$filter_guru = isset($_GET['guru_id']) ? intval($_GET['guru_id']) : 0;

// Filter siswa
$filter_siswa = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;

// Filter minggu (1-4)
$filter_minggu = isset($_GET['minggu']) ? intval($_GET['minggu']) : 0;

// Ambil daftar guru untuk dropdown
$guru_options = [];
$sql_guru = "SELECT g.id, u.full_name as nama_guru 
             FROM guru g
             JOIN users u ON g.user_id = u.id
             WHERE g.status = 'aktif'
             ORDER BY u.full_name";
$stmt_guru = $conn->prepare($sql_guru);
$stmt_guru->execute();
$result_guru = $stmt_guru->get_result();

while ($row = $result_guru->fetch_assoc()) {
    $guru_options[$row['id']] = $row;
}
$stmt_guru->close();

// Ambil daftar siswa untuk dropdown
$siswa_options = [];
$sql_siswa = "SELECT s.id, s.nama_lengkap, s.kelas
              FROM siswa s
              WHERE s.status = 'aktif'
              ORDER BY s.nama_lengkap";
$stmt_siswa = $conn->prepare($sql_siswa);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();

while ($row = $result_siswa->fetch_assoc()) {
    $siswa_options[$row['id']] = $row;
}
$stmt_siswa->close();

// Hitung rentang tanggal berdasarkan minggu
$start_date = null;
$end_date = null;
if ($filter_minggu > 0) {
    $first_day = date("Y-m-01", strtotime($periode . "-01"));
    $week_start = ($filter_minggu - 1) * 7 + 1;
    $start_date = date("Y-m-d", strtotime($first_day . " +" . ($week_start - 1) . " days"));
    $end_date = date("Y-m-d", strtotime($start_date . " +6 days"));
    
    // Pastikan tidak melebihi akhir bulan
    $last_day = date("Y-m-t", strtotime($periode . "-01"));
    if ($end_date > $last_day) {
        $end_date = $last_day;
    }
}

// **QUERY UTAMA: Ambil data jadwal belajar yang aktif - DIAMANKAN**
$sql_rekap = "SELECT 
                g.id as guru_id,
                u.full_name as nama_guru,
                smg.id as sesi_guru_id,
                smg.hari,
                smg.jam_mulai,
                smg.jam_selesai,
                ps.id as pendaftaran_id,
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah,
                sp.id as siswa_pelajaran_id,
                sp.nama_pelajaran,
                ps.tingkat as tingkat_bimbel,
                jb.id as jadwal_id
              FROM jadwal_belajar jb
              JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
              JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
              JOIN siswa s ON sp.siswa_id = s.id
              JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
              LEFT JOIN guru g ON smg.guru_id = g.id
              LEFT JOIN users u ON g.user_id = u.id
              WHERE jb.status = 'aktif'
              AND sp.status = 'aktif'
              AND ps.status = 'aktif'
              AND s.status = 'aktif'";

// Tambahkan filter dengan cara yang aman
$filter_conditions = [];
$filter_params = [];
$filter_types = "";

if ($filter_guru > 0) {
    $filter_conditions[] = "smg.guru_id = ?";
    $filter_params[] = $filter_guru;
    $filter_types .= "i";
}

if ($filter_siswa > 0) {
    $filter_conditions[] = "s.id = ?";
    $filter_params[] = $filter_siswa;
    $filter_types .= "i";
}

if (!empty($filter_conditions)) {
    $sql_rekap .= " AND " . implode(" AND ", $filter_conditions);
}

$sql_rekap .= " ORDER BY u.full_name, 
                  FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
                  smg.jam_mulai,
                  s.nama_lengkap";

// Eksekusi query utama
$stmt_rekap = $conn->prepare($sql_rekap);
if (!empty($filter_params)) {
    $stmt_rekap->bind_param($filter_types, ...$filter_params);
}

$stmt_rekap->execute();
$result_rekap = $stmt_rekap->get_result();

$data_rekap = [];
$grouped_data = [];

// Statistik
$total_siswa_rekap = 0;
$total_hadir_rekap = 0;
$total_izin_rekap = 0;
$total_sakit_rekap = 0;
$total_alpha_rekap = 0;
$total_belum_absen = 0;

// Array untuk menyimpan data untuk query absensi batch
$absensi_data_batch = [];

while ($row = $result_rekap->fetch_assoc()) {
    // Simpan data untuk query absensi batch
    $absensi_data_batch[] = [
        'row_data' => $row,
        'siswa_id' => $row['siswa_id'],
        'siswa_pelajaran_id' => $row['siswa_pelajaran_id'],
        'sesi_guru_id' => $row['sesi_guru_id']
    ];
    
    // Buat key unik untuk grouping
    $key = $row['guru_id'] . '_' . $row['hari'] . '_' . $row['jam_mulai'] . '_' . $row['jam_selesai'] . '_' . $row['nama_pelajaran'];
    
    if (!isset($grouped_data[$key])) {
        $grouped_data[$key] = [
            'guru_id' => $row['guru_id'],
            'nama_guru' => $row['nama_guru'] ?? 'Belum ditentukan',
            'hari' => $row['hari'],
            'jam_mulai' => $row['jam_mulai'],
            'jam_selesai' => $row['jam_selesai'],
            'nama_pelajaran' => $row['nama_pelajaran'],
            'tingkat_bimbel' => $row['tingkat_bimbel'],
            'jadwal_id' => $row['jadwal_id'],
            'siswa' => []
        ];
    }
}
$stmt_rekap->close();

// **AMBIL DATA ABSENSI DALAM BATCH - LEBIH EFISIEN**
if (!empty($absensi_data_batch)) {
    // Buat array untuk menyimpan hasil absensi
    $absensi_results = [];
    
    // Query absensi untuk semua data sekaligus
    $absensi_ids = [];
    foreach ($absensi_data_batch as $item) {
        $absensi_ids[] = $item['siswa_id'] . '-' . $item['siswa_pelajaran_id'] . '-' . $item['sesi_guru_id'];
    }
    
    // Karena kita perlu filter tanggal, kita buat query terpisah
    foreach ($absensi_data_batch as $item) {
        $row = $item['row_data'];
        
        // Query absensi dengan prepared statement yang aman
        $sql_absensi = "SELECT 
                        COUNT(*) as total_sesi,
                        SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as total_hadir,
                        SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as total_izin,
                        SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as total_sakit,
                        SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as total_alpha
                      FROM absensi_siswa 
                      WHERE siswa_id = ? 
                      AND siswa_pelajaran_id = ? 
                      AND sesi_guru_id = ?";
        
        // Tambahkan filter tanggal
        if ($filter_minggu > 0) {
            $sql_absensi .= " AND tanggal_absensi BETWEEN ? AND ?";
            $stmt_absensi = $conn->prepare($sql_absensi);
            if ($stmt_absensi) {
                $stmt_absensi->bind_param("iiiss", 
                    $row['siswa_id'], 
                    $row['siswa_pelajaran_id'], 
                    $row['sesi_guru_id'],
                    $start_date,
                    $end_date
                );
            }
        } else {
            $sql_absensi .= " AND DATE_FORMAT(tanggal_absensi, '%Y-%m') = ?";
            $stmt_absensi = $conn->prepare($sql_absensi);
            if ($stmt_absensi) {
                $stmt_absensi->bind_param("iiis", 
                    $row['siswa_id'], 
                    $row['siswa_pelajaran_id'], 
                    $row['sesi_guru_id'],
                    $periode
                );
            }
        }
        
        if ($stmt_absensi) {
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
        } else {
            $absensi = [
                'total_sesi' => 0,
                'total_hadir' => 0,
                'total_izin' => 0,
                'total_sakit' => 0,
                'total_alpha' => 0
            ];
        }
        
        // Hitung total sesi yang seharusnya
        $total_sesi_seharusnya = 4; // Default 4 sesi per bulan
        
        // Jika filter minggu aktif, maka hanya 1 sesi
        if ($filter_minggu > 0) {
            $total_sesi_seharusnya = 1;
        }
        
        // Hitung yang belum diabsen
        $total_sudah_absen = $absensi['total_hadir'] + $absensi['total_izin'] + $absensi['total_sakit'] + $absensi['total_alpha'];
        $belum_absen = $total_sesi_seharusnya - $total_sudah_absen;
        if ($belum_absen < 0) $belum_absen = 0;
        
        // Tambahkan data siswa ke grouped data
        $key = $row['guru_id'] . '_' . $row['hari'] . '_' . $row['jam_mulai'] . '_' . $row['jam_selesai'] . '_' . $row['nama_pelajaran'];
        
        $siswa_data = [
            'siswa_id' => $row['siswa_id'],
            'nama_lengkap' => $row['nama_lengkap'],
            'kelas_sekolah' => $row['kelas_sekolah'],
            'nama_pelajaran' => $row['nama_pelajaran'],
            'total_sesi' => $absensi['total_sesi'],
            'total_hadir' => $absensi['total_hadir'],
            'total_izin' => $absensi['total_izin'],
            'total_sakit' => $absensi['total_sakit'],
            'total_alpha' => $absensi['total_alpha'],
            'belum_absen' => $belum_absen,
            'total_sesi_seharusnya' => $total_sesi_seharusnya
        ];
        
        $grouped_data[$key]['siswa'][] = $siswa_data;
        
        // Update statistik
        $total_siswa_rekap++;
        $total_hadir_rekap += $absensi['total_hadir'];
        $total_izin_rekap += $absensi['total_izin'];
        $total_sakit_rekap += $absensi['total_sakit'];
        $total_alpha_rekap += $absensi['total_alpha'];
        $total_belum_absen += $belum_absen;
    }
}

// Konversi ke array untuk ditampilkan
foreach ($grouped_data as $key => $group) {
    $data_rekap[] = $group;
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
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 1000px;
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
                    <p class="text-gray-600 md:text-md text-sm">Rekapitulasi absensi berdasarkan guru dan jadwal</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Rekap</h3>
                <form method="GET" class="filter-form grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Periode (Bulan-Tahun)
                        </label>
                        <input type="month" name="periode" value="<?php echo $periode; ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-week mr-1"></i> Minggu
                        </label>
                        <select name="minggu" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Minggu</option>
                            <option value="1" <?php echo ($filter_minggu == 1) ? 'selected' : ''; ?>>Minggu 1</option>
                            <option value="2" <?php echo ($filter_minggu == 2) ? 'selected' : ''; ?>>Minggu 2</option>
                            <option value="3" <?php echo ($filter_minggu == 3) ? 'selected' : ''; ?>>Minggu 3</option>
                            <option value="4" <?php echo ($filter_minggu == 4) ? 'selected' : ''; ?>>Minggu 4</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> Guru
                        </label>
                        <select name="guru_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Guru</option>
                            <?php foreach ($guru_options as $guru): ?>
                            <option value="<?php echo $guru['id']; ?>" 
                                <?php echo ($filter_guru == $guru['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($guru['nama_guru']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-graduate mr-1"></i> Siswa
                        </label>
                        <select name="siswa_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Siswa</option>
                            <?php foreach ($siswa_options as $siswa): ?>
                            <option value="<?php echo $siswa['id']; ?>" 
                                <?php echo ($filter_siswa == $siswa['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($siswa['nama_lengkap'] . ' - ' . $siswa['kelas']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <div class="flex space-x-2 w-full">
                            <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                            <a href="rekapSiswa.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>
                <?php if ($filter_minggu > 0 && $start_date && $end_date): ?>
                <div class="mt-4 p-3 bg-blue-50 rounded-md">
                    <p class="text-sm text-blue-800">
                        <i class="fas fa-info-circle mr-2"></i>
                        Menampilkan data untuk Minggu <?php echo $filter_minggu; ?>: 
                        <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Statistik Total -->
            <div class="mb-6 bg-white rounded-lg shadow p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-chart-pie mr-2"></i>
                            Statistik Rekap - 
                            <?php if ($filter_minggu > 0): ?>
                                Minggu <?php echo $filter_minggu; ?> 
                            <?php endif; ?>
                            <?php echo date('F Y', strtotime($periode . '-01')); ?>
                        </h3>
                        <p class="text-gray-600">
                            <?php if ($filter_guru > 0 && isset($guru_options[$filter_guru])): ?>
                                Guru: <?php echo htmlspecialchars($guru_options[$filter_guru]['nama_guru']); ?>
                            <?php endif; ?>
                            <?php if ($filter_siswa > 0 && isset($siswa_options[$filter_siswa])): ?>
                                <?php echo ($filter_guru > 0) ? ' | ' : ''; ?>
                                Siswa: <?php echo htmlspecialchars($siswa_options[$filter_siswa]['nama_lengkap'] . ' (' . $siswa_options[$filter_siswa]['kelas'] . ')'); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-100 rounded-lg mr-3">
                                <i class="fas fa-users text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Siswa</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_siswa_rekap; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-check text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Hadir</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_hadir_rekap; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-envelope text-yellow-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Izin</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_izin_rekap; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-thermometer text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Sakit</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_sakit_rekap; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-red-100 rounded-lg mr-3">
                                <i class="fas fa-times text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Alpha</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_alpha_rekap; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Statistik tambahan -->
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-purple-100 rounded-lg mr-3">
                                <i class="fas fa-calendar-check text-purple-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Sesi Diabsen</p>
                                <p class="text-xl font-bold text-gray-800">
                                    <?php echo $total_hadir_rekap + $total_izin_rekap + $total_sakit_rekap + $total_alpha_rekap; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-orange-100 rounded-lg mr-3">
                                <i class="fas fa-question-circle text-orange-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Belum Diabsen</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $total_belum_absen; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rekap Per Jadwal -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-table mr-2"></i> 
                            Rekap Absensi Per Jadwal - 
                            <?php if ($filter_minggu > 0): ?>
                                Minggu <?php echo $filter_minggu; ?> 
                            <?php endif; ?>
                            <?php echo date('F Y', strtotime($periode . '-01')); ?>
                            <?php if (!empty($data_rekap)): ?>
                                <span class="text-sm text-gray-600 font-normal">
                                    (<?php echo count($data_rekap); ?> jadwal ditemukan)
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
                    <?php if (empty($data_rekap)): ?>
                    <div class="p-8 text-center text-gray-500">
                        <i class="fas fa-database text-3xl mb-3"></i>
                        <p class="text-lg">Tidak ada data rekap absensi</p>
                        <p class="text-sm mt-2">Coba sesuaikan filter atau periode yang dipilih</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($data_rekap as $index => $jadwal): ?>
                        <div class="border-b border-gray-200">
                            <!-- Header Jadwal -->
                            <div class="bg-gray-50 px-6 py-4">
                                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                                    <div>
                                        <h4 class="font-medium text-gray-900">
                                            <i class="fas fa-chalkboard-teacher mr-2"></i>
                                            <?php echo htmlspecialchars($jadwal['nama_guru']); ?>
                                        </h4>
                                        <div class="mt-2 flex flex-wrap gap-3">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-calendar-day mr-1"></i>
                                                <?php echo htmlspecialchars($jadwal['hari']); ?>
                                            </span>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-clock mr-1"></i>
                                                <?php echo date('H:i', strtotime($jadwal['jam_mulai'])); ?> - <?php echo date('H:i', strtotime($jadwal['jam_selesai'])); ?>
                                            </span>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                                <i class="fas fa-book mr-1"></i>
                                                <?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?>
                                            </span>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                                                <i class="fas fa-graduation-cap mr-1"></i>
                                                Tingkat: <?php echo htmlspecialchars($jadwal['tingkat_bimbel']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-2 md:mt-0">
                                        <span class="text-sm text-gray-500">
                                            <?php echo count($jadwal['siswa']); ?> siswa
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Tabel Siswa -->
                            <div class="px-6 py-4">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas Sekolah</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sesi</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hadir</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Izin</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sakit</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alpha</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Belum Absen</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($jadwal['siswa'] as $idx => $siswa): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo $idx + 1; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($siswa['nama_pelajaran']); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium">
                                                <?php echo $siswa['total_sesi']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-green-600 font-medium">
                                                <?php echo $siswa['total_hadir']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-yellow-600 font-medium">
                                                <?php echo $siswa['total_izin']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-blue-600 font-medium">
                                                <?php echo $siswa['total_sakit']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-red-600 font-medium">
                                                <?php echo $siswa['total_alpha']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium <?php echo $siswa['belum_absen'] > 0 ? 'text-orange-600' : 'text-gray-400'; ?>">
                                                <?php echo $siswa['belum_absen']; ?>
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