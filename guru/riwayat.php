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

// AMBIL FILTER DARI GET
$filter_tahun = $_GET['tahun'] ?? date('Y');
$filter_bulan = $_GET['bulan'] ?? '';
$filter_minggu = $_GET['minggu'] ?? '';
$filter_pendaftaran_id = $_GET['pendaftaran_id'] ?? 0;
$filter_siswa_id = $_GET['siswa_id'] ?? 0;
$filter_mata_pelajaran = $_GET['mata_pelajaran'] ?? '';

// AMBIL DATA SISWA YANG DIAJAR GURU (dari siswa_pelajaran)
$siswa_options = [];
if ($guru_id > 0) {
    try {
        $sql_siswa = "SELECT DISTINCT
                         s.id,
                         s.nama_lengkap,
                         s.kelas as kelas_sekolah
                      FROM siswa s
                      JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
                      JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                      WHERE sp.guru_id = ? 
                        AND ps.status = 'aktif'
                        AND sp.status = 'aktif'
                      ORDER BY s.nama_lengkap";

        $stmt = $conn->prepare($sql_siswa);
        $stmt->bind_param("i", $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $siswa_options[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error fetching siswa options: " . $e->getMessage());
    }
}

// AMBIL DATA MATA PELAJARAN YANG DIAJAR GURU
$mata_pelajaran_options = [];
if ($guru_id > 0) {
    try {
        $sql_mapel = "SELECT DISTINCT
                         sp.nama_pelajaran
                      FROM siswa_pelajaran sp
                      WHERE sp.guru_id = ?
                        AND sp.status = 'aktif'
                      ORDER BY sp.nama_pelajaran";

        $stmt = $conn->prepare($sql_mapel);
        $stmt->bind_param("i", $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $mata_pelajaran_options[] = $row['nama_pelajaran'];
        }
    } catch (Exception $e) {
        error_log("Error fetching mata pelajaran options: " . $e->getMessage());
    }
}

// BANGUN QUERY DENGAN FILTER DINAMIS
$sql = "SELECT 
            psn.*,
            s.nama_lengkap as nama_siswa,
            s.kelas as kelas_sekolah,
            sp.nama_pelajaran,
            ps.tingkat,
            ps.jenis_kelas,
            DATE_FORMAT(psn.tanggal_penilaian, '%W') as hari_penilaian,
            DATE_FORMAT(psn.tanggal_penilaian, '%d %M %Y') as tanggal_format,
            WEEK(psn.tanggal_penilaian, 1) as minggu_angka,
            MONTH(psn.tanggal_penilaian) as bulan_angka,
            YEAR(psn.tanggal_penilaian) as tahun_angka
        FROM penilaian_siswa psn
        JOIN siswa s ON psn.siswa_id = s.id
        JOIN siswa_pelajaran sp ON psn.siswa_pelajaran_id = sp.id
        JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
        WHERE psn.guru_id = ?";

$params = [$guru_id];
$types = "i";

// FILTER TAHUN
if (!empty($filter_tahun) && $filter_tahun != 'all') {
    $sql .= " AND YEAR(psn.tanggal_penilaian) = ?";
    $params[] = $filter_tahun;
    $types .= "i";
}

// FILTER BULAN
if (!empty($filter_bulan)) {
    $sql .= " AND MONTH(psn.tanggal_penilaian) = ?";
    $params[] = $filter_bulan;
    $types .= "i";
}

// FILTER MINGGU
if (!empty($filter_minggu)) {
    $sql .= " AND WEEK(psn.tanggal_penilaian, 1) = ?";
    $params[] = $filter_minggu;
    $types .= "i";
}

// FILTER SISWA
if ($filter_siswa_id > 0) {
    $sql .= " AND psn.siswa_id = ?";
    $params[] = $filter_siswa_id;
    $types .= "i";
}

// FILTER MATA PELAJARAN
if (!empty($filter_mata_pelajaran)) {
    $sql .= " AND sp.nama_pelajaran LIKE ?";
    $params[] = '%' . $filter_mata_pelajaran . '%';
    $types .= "s";
}

// ORDER BY
$sql .= " ORDER BY psn.tanggal_penilaian DESC, s.nama_lengkap";

// EKSEKUSI QUERY
$riwayat_penilaian = [];
$total_data = 0;
$rata_total_skor = 0;

if (!empty($params)) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Hitung persentase jika belum ada di database
            if (!isset($row['persentase']) || empty($row['persentase'])) {
                $row['persentase'] = round(($row['total_score'] / 50) * 100);
            }
            $riwayat_penilaian[] = $row;
        }

        $total_data = count($riwayat_penilaian);

        // Hitung rata-rata total skor
        if ($total_data > 0) {
            $total_skor = array_sum(array_column($riwayat_penilaian, 'total_score'));
            $rata_total_skor = round($total_skor / $total_data, 1);
        }
    } catch (Exception $e) {
        error_log("Error fetching riwayat penilaian: " . $e->getMessage());
    }
}

// STATISTIK PER KATEGORI (sesuai dengan enum di database)
$statistik_kategori = [
    'Sangat Baik' => 0,
    'Baik' => 0,
    'Cukup' => 0,
    'Kurang' => 0
];

foreach ($riwayat_penilaian as $penilaian) {
    if (isset($statistik_kategori[$penilaian['kategori']])) {
        $statistik_kategori[$penilaian['kategori']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Penilaian - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
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
            display: flex;
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
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            /* Mobile table view */
            .mobile-table-row {
                display: flex;
                flex-direction: column;
                padding: 1rem;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .mobile-table-cell {
                display: flex;
                flex-direction: column;
                margin-bottom: 0.5rem;
            }
            
            .mobile-table-label {
                font-weight: 600;
                color: #6b7280;
                font-size: 0.75rem;
                text-transform: uppercase;
            }
            
            .mobile-table-value {
                font-size: 0.875rem;
                color: #111827;
            }
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
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Riwayat Penilaian Siswa</h1>
                    <p class="text-gray-600">Lihat semua penilaian yang telah diinput</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <a href="inputNilai.php" class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i> Input Baru
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Card Statistik Ringkas -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-4">
                            <i class="fas fa-clipboard-check text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Penilaian</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $total_data; ?></p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg mr-4">
                            <i class="fas fa-chart-line text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Rata-rata Skor</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $rata_total_skor; ?>/50</p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 rounded-lg mr-4">
                            <i class="fas fa-star text-purple-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Sangat Baik</p>
                            <p class="text-2xl font-bold text-gray-800">
                                <?php echo $statistik_kategori['Sangat Baik']; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-4">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Perlu Perhatian</p>
                            <p class="text-2xl font-bold text-gray-800">
                                <?php echo $statistik_kategori['Kurang'] + $statistik_kategori['Cukup']; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Filter -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-filter mr-2"></i> Filter Riwayat
                </h3>

                <form method="GET" action="" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Filter Siswa -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Siswa</label>
                            <select name="siswa_id"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="0">Semua Siswa</option>
                                <?php foreach ($siswa_options as $siswa): ?>
                                    <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa_id == $siswa['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($siswa['nama_lengkap'] . ' - ' . $siswa['kelas_sekolah']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter Mata Pelajaran -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mata Pelajaran</label>
                            <select name="mata_pelajaran"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Mata Pelajaran</option>
                                <?php foreach ($mata_pelajaran_options as $mapel): ?>
                                    <option value="<?php echo htmlspecialchars($mapel); ?>" 
                                        <?php echo ($filter_mata_pelajaran == $mapel) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mapel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Filter Tahun -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tahun</label>
                            <select name="tahun"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Tahun</option>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bulan</label>
                            <select name="bulan"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Bulan</option>
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
                    </div>
                    
                    <!-- Filter Minggu -->
                    <div class="w-full md:w-1/3">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Minggu ke</label>
                        <select name="minggu"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Minggu</option>
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($filter_minggu == $i) ? 'selected' : ''; ?>>
                                    Minggu <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center pt-4 border-t gap-3 md:gap-0">
                        <div class="text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            Menampilkan <?php echo $total_data; ?> hasil
                        </div>
                        <div class="flex space-x-3">
                            <a href="riwayat.php"
                                class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-gray-300 text-gray-700 hover:bg-gray-400">
                                Reset Filter
                            </a>
                            <button type="submit"
                                class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                                <i class="fas fa-filter mr-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tabel Riwayat -->
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <?php if ($total_data == 0): ?>
                    <div class="p-8 text-center">
                        <div class="text-gray-400 mb-4">
                            <i class="fas fa-clipboard-list text-5xl"></i>
                        </div>
                        <h3 class="text-xl font-medium text-gray-700 mb-2">Belum ada data penilaian</h3>
                        <p class="text-gray-500 mb-4">Mulai dengan menginput penilaian baru untuk siswa Anda.</p>
                        <a href="inputNilai.php"
                            class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i> Input Penilaian Baru
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Desktop View -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Siswa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mata Pelajaran & Kelas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total & Kategori</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($riwayat_penilaian as $penilaian):
                                    // Tentukan warna badge berdasarkan kategori
                                    $badge_class = '';
                                    if ($penilaian['kategori'] == 'Sangat Baik')
                                        $badge_class = 'badge-sangat-baik';
                                    elseif ($penilaian['kategori'] == 'Baik')
                                        $badge_class = 'badge-baik';
                                    elseif ($penilaian['kategori'] == 'Cukup')
                                        $badge_class = 'badge-cukup';
                                    else
                                        $badge_class = 'badge-kurang';
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo date('d/m/Y', strtotime($penilaian['tanggal_penilaian'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $penilaian['hari_penilaian']; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <span class="text-blue-800 font-medium">
                                                        <?php echo strtoupper(substr($penilaian['nama_siswa'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($penilaian['nama_siswa']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        Kelas: <?php echo $penilaian['kelas_sekolah']; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($penilaian['nama_pelajaran']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $penilaian['tingkat']; ?> - <?php echo $penilaian['jenis_kelas']; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-lg font-bold text-gray-900">
                                                <?php echo $penilaian['total_score']; ?>/50
                                            </div>
                                            <div class="text-sm">
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $penilaian['kategori']; ?>
                                                    (<?php echo $penilaian['persentase']; ?>%)
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="showDetail(<?php echo $penilaian['id']; ?>)"
                                                    class="text-blue-600 hover:text-blue-900" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <!-- Tombol Hapus -->
                                                <button onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')"
                                                    class="text-red-600 hover:text-red-900" title="Hapus">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile View -->
                    <div class="md:hidden">
                        <?php foreach ($riwayat_penilaian as $penilaian):
                            $badge_class = '';
                            if ($penilaian['kategori'] == 'Sangat Baik')
                                $badge_class = 'badge-sangat-baik';
                            elseif ($penilaian['kategori'] == 'Baik')
                                $badge_class = 'badge-baik';
                            elseif ($penilaian['kategori'] == 'Cukup')
                                $badge_class = 'badge-cukup';
                            else
                                $badge_class = 'badge-kurang';
                        ?>
                        <div class="mobile-table-row">
                            <div class="mobile-table-cell">
                                <div class="mobile-table-label">Tanggal</div>
                                <div class="mobile-table-value">
                                    <?php echo date('d/m/Y', strtotime($penilaian['tanggal_penilaian'])); ?> 
                                    (<?php echo $penilaian['hari_penilaian']; ?>)
                                </div>
                            </div>
                            
                            <div class="mobile-table-cell">
                                <div class="mobile-table-label">Siswa</div>
                                <div class="mobile-table-value">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 bg-blue-100 rounded-full flex items-center justify-center mr-2">
                                            <span class="text-blue-800 font-medium text-xs">
                                                <?php echo strtoupper(substr($penilaian['nama_siswa'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <div><?php echo htmlspecialchars($penilaian['nama_siswa']); ?></div>
                                            <div class="text-xs text-gray-500">Kelas: <?php echo $penilaian['kelas_sekolah']; ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mobile-table-cell">
                                <div class="mobile-table-label">Mata Pelajaran</div>
                                <div class="mobile-table-value">
                                    <?php echo htmlspecialchars($penilaian['nama_pelajaran']); ?><br>
                                    <span class="text-xs text-gray-500">
                                        <?php echo $penilaian['tingkat']; ?> - <?php echo $penilaian['jenis_kelas']; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mobile-table-cell">
                                <div class="mobile-table-label">Total Skor</div>
                                <div class="mobile-table-value">
                                    <div class="text-lg font-bold text-gray-900">
                                        <?php echo $penilaian['total_score']; ?>/50
                                    </div>
                                    <span class="badge <?php echo $badge_class; ?> text-xs">
                                        <?php echo $penilaian['kategori']; ?> (<?php echo $penilaian['persentase']; ?>%)
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mobile-table-cell">
                                <div class="mobile-table-label">Aksi</div>
                                <div class="mobile-table-value">
                                    <div class="flex space-x-3 mt-2">
                                        <button onclick="showDetail(<?php echo $penilaian['id']; ?>)"
                                            class="flex-1 px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-xs flex items-center justify-center">
                                            <i class="fas fa-eye mr-2"></i> Detail
                                        </button>
                                        <button onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')"
                                            class="flex-1 px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-xs flex items-center justify-center">
                                            <i class="fas fa-trash-alt mr-2"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <div class="bg-white px-4 py-3 border-t border-gray-200">
                        <div class="flex flex-col md:flex-row justify-between items-center">
                            <div class="mb-3 md:mb-0">
                                <p class="text-sm text-gray-700">
                                    Menampilkan <span class="font-medium"><?php echo $total_data; ?></span> hasil
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Grafik Distribusi Kategori -->
            <?php if ($total_data > 0): ?>
                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Distribusi Kategori</h3>
                        <div class="space-y-3">
                            <?php foreach ($statistik_kategori as $kategori => $jumlah):
                                if ($jumlah > 0):
                                    $persentase = round(($jumlah / $total_data) * 100, 1);
                                    // Tentukan warna berdasarkan kategori
                                    $color_class = '';
                                    $bg_class = '';
                                    if ($kategori == 'Sangat Baik') {
                                        $color_class = 'text-green-600';
                                        $bg_class = 'bg-green-100';
                                    } elseif ($kategori == 'Baik') {
                                        $color_class = 'text-blue-600';
                                        $bg_class = 'bg-blue-100';
                                    } elseif ($kategori == 'Cukup') {
                                        $color_class = 'text-yellow-600';
                                        $bg_class = 'bg-yellow-100';
                                    } else {
                                        $color_class = 'text-red-600';
                                        $bg_class = 'bg-red-100';
                                    }
                                    ?>
                                    <div>
                                        <div class="flex justify-between mb-1">
                                            <span
                                                class="text-sm font-medium <?php echo $color_class; ?>"><?php echo $kategori; ?></span>
                                            <span class="text-sm font-medium text-gray-700"><?php echo $jumlah; ?>
                                                (<?php echo $persentase; ?>%)</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="<?php echo $bg_class; ?> h-2 rounded-full"
                                                style="width: <?php echo $persentase; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endif; endforeach; ?>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Info Sistem</h3>
                        <ul class="space-y-2 text-sm text-gray-600">
                            <li class="flex items-start">
                                <i class="fas fa-info-circle text-blue-500 mt-1 mr-2"></i>
                                <span>Filter dapat dikombinasikan untuk mendapatkan hasil yang lebih spesifik</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-calendar-alt text-green-500 mt-1 mr-2"></i>
                                <span>Sistem menghitung minggu secara otomatis berdasarkan tanggal penilaian</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-chart-bar text-purple-500 mt=1 mr-2"></i>
                                <span>Total skor dihitung dari 5 indikator dengan skala 1-10 (maksimal 50)</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-trash-alt text-red-500 mt-1 mr-2"></i>
                                <span>Hati-hati saat menghapus data, tindakan ini tidak dapat dibatalkan</span>
                            </li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Riwayat Penilaian</p>
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
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Memuat data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal()"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL KONFIRMASI HAPUS -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                <h2 class="text-xl font-bold"><i class="fas fa-exclamation-triangle mr-2"></i> Konfirmasi Hapus</h2>
                <span class="close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="text-center py-4">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                        <i class="fas fa-trash-alt text-red-600 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2" id="deleteStudentName">Hapus Penilaian?</h3>
                    <p class="text-gray-500 mb-4">
                        Apakah Anda yakin ingin menghapus penilaian ini? 
                        <span class="font-semibold text-red-600">Tindakan ini tidak dapat dibatalkan!</span>
                    </p>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 rounded text-left">
                        <p class="text-sm text-yellow-700">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            Semua data penilaian akan dihapus permanen dari sistem.
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeDeleteModal()"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                    Batal
                </button>
                <button type="button" onclick="deletePenilaian()"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center"
                    id="deleteConfirmBtn">
                    <i class="fas fa-trash-alt mr-2"></i> Ya, Hapus Data
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

    let currentPenilaianId = null;
    let penilaianToDelete = null;
    let studentNameToDelete = '';

    // Fungsi untuk menampilkan detail penilaian
    function showDetail(penilaianId) {
        currentPenilaianId = penilaianId;
        const modal = document.getElementById('detailModal');
        const content = document.getElementById('modalContent');

        // Close mobile menu if open
        if (mobileMenu) mobileMenu.classList.remove('menu-open');
        if (menuOverlay) menuOverlay.classList.remove('active');

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
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(responseData => {
                console.log('Response dari server:', responseData);

                if (responseData.success) {
                    content.innerHTML = renderDetailContent(responseData.data);
                } else {
                    content.innerHTML = `
                        <div class="text-center py-8 text-red-600">
                            <i class="fas fa-exclamation-triangle text-5xl mb-4"></i>
                            <p class="text-lg font-medium mb-2">Gagal Memuat Data</p>
                            <p class="text-sm">${responseData.message || 'Terjadi kesalahan saat memuat data'}</p>
                            <button onclick="closeModal()" 
                                class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Tutup
                            </button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                content.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-5xl mb-4"></i>
                        <p class="text-lg font-medium mb-2">Terjadi Kesalahan</p>
                        <p class="text-sm">${error.message}</p>
                        <p class="text-xs mt-2 text-gray-500">Silakan refresh halaman dan coba lagi</p>
                        <button onclick="closeModal()" 
                            class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                            Tutup
                        </button>
                    </div>
                `;
            });
    }

    // Render konten detail (PERBAIKAN DI SINI)
    function renderDetailContent(data) {
        // Tentukan warna kategori
        let kategoriClass = '';
        let kategoriBgClass = '';
        switch (data.kategori) {
            case 'Sangat Baik':
                kategoriClass = 'text-green-600';
                kategoriBgClass = 'bg-green-100';
                break;
            case 'Baik':
                kategoriClass = 'text-blue-600';
                kategoriBgClass = 'bg-blue-100';
                break;
            case 'Cukup':
                kategoriClass = 'text-yellow-600';
                kategoriBgClass = 'bg-yellow-100';
                break;
            case 'Kurang':
                kategoriClass = 'text-red-600';
                kategoriBgClass = 'bg-red-100';
                break;
            default:
                kategoriClass = 'text-gray-600';
                kategoriBgClass = 'bg-gray-100';
        }

        // PERBAIKAN: Data dari server menggunakan nama_lengkap, bukan nama_siswa
        // Gunakan data.nama_lengkap karena dari query di get_penilaian_detail.php
        const safeData = {
            nama_siswa: data.nama_lengkap || data.nama_siswa || 'Tidak diketahui',
            kelas_sekolah: data.kelas_sekolah || '-',
            tanggal_format: data.tanggal_format || new Date().toLocaleDateString('id-ID'),
            nama_pelajaran: data.nama_pelajaran || '-',
            tingkat: data.tingkat || '-',
            jenis_kelas: data.jenis_kelas || '-',
            total_score: data.total_score || 0,
            kategori: data.kategori || 'Belum Dinilai',
            persentase: data.persentase || 0,
            willingness_learn: data.willingness_learn || 0,
            problem_solving: data.problem_solving || 0,
            critical_thinking: data.critical_thinking || 0,
            concentration: data.concentration || 0,
            independence: data.independence || 0,
            catatan_guru: data.catatan_guru || '',
            rekomendasi: data.rekomendasi || ''
        };

        return `
            <div class="space-y-6">
                <!-- Header Info -->
                <div class="bg-blue-50 p-4 rounded-lg">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                        <div>
                            <h3 class="text-xl font-bold text-gray-800">${escapeHtml(safeData.nama_siswa)}</h3>
                            <p class="text-gray-600">Kelas: ${escapeHtml(safeData.kelas_sekolah)}</p>
                            <p class="text-gray-600">${escapeHtml(safeData.tanggal_format)}</p>
                        </div>
                        <div class="mt-2 md:mt-0">
                            <span class="px-3 py-1 rounded-full text-sm font-medium ${kategoriBgClass} ${kategoriClass}">
                                ${escapeHtml(safeData.kategori)} (${safeData.persentase}%)
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">Mata Pelajaran:</span> ${escapeHtml(safeData.nama_pelajaran)}
                        </p>
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">Tingkat:</span> ${escapeHtml(safeData.tingkat)}
                        </p>
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">Jenis Kelas:</span> ${escapeHtml(safeData.jenis_kelas)}
                        </p>
                    </div>
                </div>

                <!-- Total Score -->
                <div class="text-center p-4 md:p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg">
                    <h4 class="text-lg font-semibold text-gray-700 mb-2">Total Skor</h4>
                    <div class="text-4xl md:text-5xl font-bold text-blue-600">${safeData.total_score}/50</div>
                    <div class="mt-2 text-gray-600 text-sm md:text-base">Nilai rata-rata per indikator: ${(safeData.total_score / 5).toFixed(1)}/10</div>
                </div>

                <!-- Indikator Nilai -->
                <div>
                    <h4 class="text-lg font-semibold text-gray-800 mb-4">Detail Indikator</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                        ${renderIndicator('Willingness to Learn', safeData.willingness_learn)}
                        ${renderIndicator('Problem Solving', safeData.problem_solving)}
                        ${renderIndicator('Critical Thinking', safeData.critical_thinking)}
                        ${renderIndicator('Concentration', safeData.concentration)}
                        ${renderIndicator('Independence', safeData.independence)}
                    </div>
                </div>

                <!-- Catatan Guru -->
                ${safeData.catatan_guru ? `
                <div>
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">Catatan Guru</h4>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                        <p class="text-gray-700">${escapeHtml(safeData.catatan_guru)}</p>
                    </div>
                </div>
                ` : ''}

                <!-- Rekomendasi -->
                ${safeData.rekomendasi ? `
                <div>
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">Rekomendasi</h4>
                    <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded">
                        <p class="text-gray-700">${escapeHtml(safeData.rekomendasi)}</p>
                    </div>
                </div>
                ` : ''}

                <!-- Info Tambahan -->
                <div class="text-xs text-gray-400 border-t pt-2">
                    <p>ID Penilaian: ${data.id}</p>
                </div>
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

    // Helper untuk escape HTML
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return String(unsafe)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Tutup modal detail
    function closeModal() {
        const modal = document.getElementById('detailModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        currentPenilaianId = null;
    }

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

    // Fungsi konfirmasi hapus
    function confirmDelete(penilaianId, studentName) {
        penilaianToDelete = penilaianId;
        studentNameToDelete = studentName;

        // Close mobile menu if open
        if (mobileMenu) mobileMenu.classList.remove('menu-open');
        if (menuOverlay) menuOverlay.classList.remove('active');

        // Update modal content
        const deleteStudentName = document.getElementById('deleteStudentName');
        if (deleteStudentName) {
            deleteStudentName.textContent = `Hapus Penilaian ${studentName}?`;
        }

        // Show modal
        const modal = document.getElementById('deleteModal');
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    // Fungsi tutup modal hapus
    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        penilaianToDelete = null;
        studentNameToDelete = '';
    }

    // Fungsi hapus penilaian
    function deletePenilaian() {
        if (!penilaianToDelete) return;

        const deleteBtn = document.getElementById('deleteConfirmBtn');
        const originalText = deleteBtn.innerHTML;

        // Tampilkan loading
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Menghapus...';
        deleteBtn.disabled = true;

        // Kirim request hapus
        fetch('delete_penilaian.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + penilaianToDelete
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Tampilkan pesan sukses
                    showNotification('success', data.message);

                    // Tutup modal setelah 1 detik
                    setTimeout(() => {
                        closeDeleteModal();
                        // Reload halaman untuk update data
                        location.reload();
                    }, 1000);
                } else {
                    // Tampilkan pesan error
                    showNotification('error', data.message);

                    // Reset button
                    deleteBtn.innerHTML = originalText;
                    deleteBtn.disabled = false;
                }
            })
            .catch(error => {
                showNotification('error', 'Terjadi kesalahan: ' + error.message);
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            });
    }

    // Fungsi tampilkan notifikasi
    function showNotification(type, message) {
        // Hapus notifikasi sebelumnya
        const oldNotif = document.getElementById('notification');
        if (oldNotif) oldNotif.remove();

        // Buat notifikasi baru
        const notification = document.createElement('div');
        notification.id = 'notification';
        notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg flex items-center ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'}`;

        notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-3 text-lg"></i>
                <div>
                    <p class="font-medium">${message}</p>
                </div>
                <button onclick="this.parentElement.remove()" class="ml-4 text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            `;

        document.body.appendChild(notification);

        // Auto-hide setelah 5 detik
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }

    // Tutup modal saat klik di luar
    window.onclick = function(event) {
        const modal = document.getElementById('detailModal');
        const deleteModal = document.getElementById('deleteModal');

        if (event.target === modal) {
            closeModal();
        }

        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    }

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

    // FUNGSI DEBUGGING (opsional, untuk testing dari console)
    window.testDetail = function(id) {
        fetch(`get_penilaian_detail.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                console.log('Data penilaian:', data);
                if (data.success) {
                    alert('Data berhasil diambil. Lihat di console untuk detail.');
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error: ' + error.message);
            });
    };

    window.checkResponse = function(id) {
        fetch(`get_penilaian_detail.php?id=${id}`)
            .then(response => response.text())
            .then(text => {
                console.log('Raw response:', text);
                try {
                    const json = JSON.parse(text);
                    console.log('Parsed JSON:', json);
                } catch (e) {
                    console.error('Bukan JSON valid:', e);
                }
            });
    };
</script>
</body>
</html>