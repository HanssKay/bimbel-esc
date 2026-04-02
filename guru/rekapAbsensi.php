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
$bulan = date('m');
$tahun = date('Y');
$periode = "$tahun-$bulan";

if (isset($_GET['periode']) && !empty($_GET['periode'])) {
    $periode = $_GET['periode'];
    list($tahun, $bulan) = explode('-', $periode);
}

// Filter pencarian
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Hitung tanggal awal dan akhir bulan
$tanggal_awal = date('Y-m-01', strtotime("$tahun-$bulan-01"));
$tanggal_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));

// ============================================
// AMBIL DATA REKAP ABSENSI - DENGAN SEARCH
// ============================================
$rekap_data = [];
$statistik = [
    'total_siswa' => 0,
    'total_mapel' => 0,
    'total_sesi' => 0,
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

// Ambil semua siswa yang diajar guru ini (dengan filter search)
$sql_siswa = "SELECT DISTINCT 
                s.id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah
              FROM siswa s
              INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
              WHERE ps.status = 'aktif'
              AND s.status = 'aktif'
              AND EXISTS (
                  SELECT 1 
                  FROM jadwal_belajar jb
                  INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                  WHERE jb.pendaftaran_id = ps.id
                  AND jb.status = 'aktif'
                  AND smg.guru_id = ?
              )";

$params = [$guru_id];
$types = "i";

// Tambahkan filter search jika ada
if (!empty($search_query)) {
    $sql_siswa .= " AND s.nama_lengkap LIKE ?";
    $params[] = "%{$search_query}%";
    $types .= "s";
}

$sql_siswa .= " ORDER BY s.nama_lengkap";

$stmt_siswa = $conn->prepare($sql_siswa);
$stmt_siswa->bind_param($types, ...$params);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();

$siswa_ids = [];
while ($row = $result_siswa->fetch_assoc()) {
    $siswa_ids[] = $row['id'];
    $rekap_data[$row['id']] = [
        'id' => $row['id'],
        'nama_lengkap' => $row['nama_lengkap'],
        'kelas_sekolah' => $row['kelas_sekolah'],
        'mapel_list' => [],
        'total_hadir' => 0,
        'total_izin' => 0,
        'total_sakit' => 0,
        'total_alpha' => 0,
        'total_sesi' => 0
    ];
}
$stmt_siswa->close();

$statistik['total_siswa'] = count($siswa_ids);

// Jika ada siswa, ambil data absensi dan mapel
if (!empty($siswa_ids)) {
    // Ambil semua mapel siswa
    $placeholders = implode(',', array_fill(0, count($siswa_ids), '?'));
    $types = str_repeat('i', count($siswa_ids));

    $sql_mapel = "SELECT 
                    sp.id,
                    sp.siswa_id,
                    sp.nama_pelajaran
                  FROM siswa_pelajaran sp
                  WHERE sp.siswa_id IN ($placeholders)
                  AND sp.status = 'aktif'
                  ORDER BY sp.nama_pelajaran";

    $stmt_mapel = $conn->prepare($sql_mapel);
    $stmt_mapel->bind_param($types, ...$siswa_ids);
    $stmt_mapel->execute();
    $result_mapel = $stmt_mapel->get_result();

    $mapel_per_siswa = [];
    while ($row = $result_mapel->fetch_assoc()) {
        $siswa_id = $row['siswa_id'];
        if (!isset($mapel_per_siswa[$siswa_id])) {
            $mapel_per_siswa[$siswa_id] = [];
        }
        $mapel_per_siswa[$siswa_id][] = $row['nama_pelajaran'];

        // Update rekap_data dengan mapel
        $rekap_data[$siswa_id]['mapel_list'] = $mapel_per_siswa[$siswa_id];
        $statistik['total_mapel']++;
    }
    $stmt_mapel->close();

    // Ambil data absensi untuk periode ini
    $sql_absensi = "SELECT 
                      a.siswa_id,
                      a.status,
                      COUNT(*) as jumlah
                    FROM absensi_siswa a
                    WHERE a.siswa_id IN ($placeholders)
                    AND a.guru_id = ?
                    AND a.tanggal_absensi BETWEEN ? AND ?
                    GROUP BY a.siswa_id, a.status";

    $param_types = $types . "iss";
    $params = array_merge($siswa_ids, [$guru_id, $tanggal_awal, $tanggal_akhir]);

    $stmt_absensi = $conn->prepare($sql_absensi);
    $stmt_absensi->bind_param($param_types, ...$params);
    $stmt_absensi->execute();
    $result_absensi = $stmt_absensi->get_result();

    while ($row = $result_absensi->fetch_assoc()) {
        $siswa_id = $row['siswa_id'];
        $status = $row['status'];
        $jumlah = $row['jumlah'];

        if (isset($rekap_data[$siswa_id])) {
            $rekap_data[$siswa_id]['total_' . $status] = $jumlah;
            $rekap_data[$siswa_id]['total_sesi'] += $jumlah;
            $statistik[$status] += $jumlah;
            $statistik['total_sesi'] += $jumlah;
        }
    }
    $stmt_absensi->close();
}

// Urutkan berdasarkan nama
usort($rekap_data, function ($a, $b) {
    return strcmp($a['nama_lengkap'], $b['nama_lengkap']);
});
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
                min-width: 900px;
            }
        }

        /* Search input styles */
        .search-container {
            position: relative;
            flex: 1;
        }

        .search-input {
            width: 100%;
            padding: 0.5rem 2.5rem 0.5rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            outline: none;
        }

        .search-input:focus {
            border-color: #3b82f6;
            ring: 2px solid #3b82f6;
        }

        .search-icon {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .clear-search {
            position: absolute;
            right: 2rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            text-decoration: none;
        }

        .clear-search:hover {
            color: #6b7280;
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

            .filter-grid {
                grid-template-columns: 1fr !important;
            }
        }

        .stat-card {
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
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
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            animation: slideIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            background-color: rgba(255,255,255,0.2);
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

        .detail-card.hadir { border-left-color: #10b981; }
        .detail-card.izin { border-left-color: #f59e0b; }
        .detail-card.sakit { border-left-color: #3b82f6; }
        .detail-card.alpha { border-left-color: #ef4444; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-hadir { background: #d1fae5; color: #065f46; }
        .status-izin { background: #fed7aa; color: #92400e; }
        .status-sakit { background: #dbeafe; color: #1e40af; }
        .status-alpha { background: #fee2e2; color: #991b1b; }

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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi
                    </h1>
                    <p class="text-gray-600">Rekapitulasi absensi siswa per bulan - Klik Detail untuk melihat tanggal</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <a href="absensiSiswa.php"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800 hover:bg-blue-200">
                        <i class="fas fa-calendar-check mr-2"></i> Input Absensi
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Periode dan Search -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" id="filterForm" class="flex flex-col md:flex-row gap-4 items-end">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Pilih Periode
                        </label>
                        <input type="month" name="periode" value="<?php echo $periode; ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-search mr-1"></i> Cari Nama Siswa
                        </label>
                        <div class="search-container">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                                placeholder="Ketik nama siswa..." class="search-input" id="searchInput">
                            <?php if (!empty($search_query)): ?>
                                <a href="rekapAbsensi.php?periode=<?php echo $periode; ?>" class="clear-search">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                            <i class="fas fa-search search-icon"></i>
                        </div>
                    </div>
                    <div>
                        <button type="submit"
                            class="w-full md:w-auto px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            <i class="fas fa-search mr-2"></i> Tampilkan
                        </button>
                    </div>
                </form>

                <?php if (!empty($search_query)): ?>
                    <div class="mt-3 text-sm text-blue-600">
                        <i class="fas fa-filter mr-1"></i> Filter aktif: Pencarian
                        "<?php echo htmlspecialchars($search_query); ?>"
                    </div>
                <?php endif; ?>

                <p class="text-xs text-gray-500 mt-2">
                    <i class="fas fa-info-circle"></i> Menampilkan rekap absensi bulan
                    <?php echo date('F Y', strtotime("$tahun-$bulan-01")); ?>
                </p>
            </div>

            <!-- Statistik Cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4 stat-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg mr-3">
                            <i class="fas fa-users text-blue-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Siswa</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $statistik['total_siswa']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 stat-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-green-100 rounded-lg mr-3">
                            <i class="fas fa-check text-green-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Hadir</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $statistik['hadir']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 stat-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                            <i class="fas fa-envelope text-yellow-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Izin</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo $statistik['izin']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 stat-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg mr-3">
                            <i class="fas fa-thermometer text-blue-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Sakit</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $statistik['sakit']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4 stat-card">
                    <div class="flex items-center">
                        <div class="p-2 bg-red-100 rounded-lg mr-3">
                            <i class="fas fa-times text-red-600"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Alpha</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo $statistik['alpha']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Info Hasil Pencarian -->
            <?php if (!empty($search_query) && !empty($rekap_data)): ?>
                <div class="mb-4 text-sm text-gray-600">
                    <i class="fas fa-info-circle mr-1"></i>
                    Ditemukan <?php echo count($rekap_data); ?> siswa dengan nama mengandung
                    "<?php echo htmlspecialchars($search_query); ?>"
                </div>
            <?php endif; ?>

            <!-- Tabel Rekap -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-table mr-2"></i> Rekap Absensi Per Siswa
                    </h3>
                </div>

                <?php if (empty($rekap_data)): ?>
                    <div class="p-8 text-center text-gray-500">
                        <i class="fas fa-database text-4xl mb-3"></i>
                        <p class="text-lg">Tidak ada data absensi</p>
                        <p class="text-sm mt-2">
                            <?php if (!empty($search_query)): ?>
                                Tidak ditemukan siswa dengan nama "<?php echo htmlspecialchars($search_query); ?>" untuk periode
                                ini
                            <?php else: ?>
                                Belum ada siswa atau data absensi untuk periode ini
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($search_query)): ?>
                            <a href="rekapAbsensi.php?periode=<?php echo $periode; ?>"
                                class="inline-flex items-center px-4 py-2 mt-4 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                                <i class="fas fa-times mr-2"></i> Hapus Pencarian
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Siswa
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mata
                                        Pelajaran</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hadir</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Izin</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sakit</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Alpha</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($rekap_data as $index => $siswa): ?>
                                    <tr class="hover:bg-gray-50">
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
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php
                                            if (!empty($siswa['mapel_list'])) {
                                                echo htmlspecialchars(implode(', ', $siswa['mapel_list']));
                                            } else {
                                                echo '<span class="text-gray-400">-</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                            <?php echo $siswa['total_hadir']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-yellow-600">
                                            <?php echo $siswa['total_izin']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                                            <?php echo $siswa['total_sakit']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-600">
                                            <?php echo $siswa['total_alpha']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo $siswa['total_sesi']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button type="button" 
                                                    class="btn-detail"
                                                    data-siswa-id="<?php echo $siswa['id']; ?>"
                                                    data-siswa-nama="<?php echo htmlspecialchars($siswa['nama_lengkap']); ?>"
                                                    data-guru-id="<?php echo $guru_id; ?>"
                                                    data-guru-nama="<?php echo htmlspecialchars($full_name); ?>"
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
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-6">
            <div class="container mx-auto py-4 px-4 text-center text-sm text-gray-500">
                © <?php echo date('Y'); ?> Bimbel Esc - Rekap Absensi
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
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
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

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const dropdownGroup = this.closest('.mb-1');
                if (!dropdownGroup) return;
                
                const submenu = dropdownGroup.querySelector('.dropdown-submenu');
                if (!submenu) return;
                
                const arrow = this.querySelector('.arrow');
                const isOpen = submenu.style.display === 'block';

                // Tutup semua dropdown lain
                document.querySelectorAll('.dropdown-submenu').forEach(sm => {
                    sm.style.display = 'none';
                });
                document.querySelectorAll('.dropdown-toggle').forEach(t => {
                    t.classList.remove('open');
                    const tArrow = t.querySelector('.arrow');
                    if (tArrow) tArrow.style.transform = 'rotate(0deg)';
                });

                // Buka/tutup dropdown yang diklik
                if (!isOpen) {
                    submenu.style.display = 'block';
                    this.classList.add('open');
                    if (arrow) arrow.style.transform = 'rotate(-90deg)';
                }
            });
        });

        // ==================== MODAL FUNCTIONS ====================
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
                url: '../guru/ajax_get_absensi_detail_guru.php',
                type: 'GET',
                data: {
                    siswa_id: siswaId,
                    guru_id: guruId,
                    periode: periode
                },
                dataType: 'json',
                success: function(response) {
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
                error: function(xhr, status, error) {
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
            const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

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

            data.data.forEach(function(item) {
                const statusClass = `detail-card ${item.status}`;
                const statusBadgeClass = `status-badge status-${item.status}`;
                let statusIcon = '';
                let statusText = '';
                
                switch(item.status) {
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
                            <div class="flex items-center">
                                <i class="fas ${statusIcon} mr-2 text-lg"></i>
                                <div>
                                    <div class="font-medium">${tanggalFormatted}</div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i> Input: ${item.waktu_input || '-'}
                                    </div>
                                </div>
                            </div>
                            <div>
                                <span class="${statusBadgeClass}">${statusText}</span>
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
        window.onclick = function(event) {
            const modal = document.getElementById('absensiModal');
            if (event.target === modal) {
                closeModal();
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>

</html>