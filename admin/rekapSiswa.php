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

// Hitung tanggal awal dan akhir bulan
$tanggal_awal = date('Y-m-01', strtotime("$tahun-$bulan-01"));
$tanggal_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));

// ============================================
// AMBIL DATA REKAP ABSENSI - TANPA KOLOM MAPEL
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

// Ambil semua guru yang mengajar
$guru_ids = [];
if ($filter_guru > 0) {
    $guru_ids[] = $filter_guru;
} else {
    $sql_guru_all = "SELECT id FROM guru WHERE status = 'aktif'";
    $result_guru_all = $conn->query($sql_guru_all);
    while ($row = $result_guru_all->fetch_assoc()) {
        $guru_ids[] = $row['id'];
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

    // Ambil semua siswa yang diajar guru ini (UNIQUE)
    $sql_siswa = "SELECT DISTINCT 
                    s.id,
                    s.nama_lengkap,
                    s.kelas as kelas_sekolah
                  FROM siswa s
                  INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                  INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                  INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                  WHERE smg.guru_id = ?
                  AND jb.status = 'aktif'
                  AND ps.status = 'aktif'
                  AND s.status = 'aktif'";

    $params = [$guru_id];
    $types = "i";

    if ($filter_siswa > 0) {
        $sql_siswa .= " AND s.id = ?";
        $params[] = $filter_siswa;
        $types .= "i";
    }

    $sql_siswa .= " ORDER BY s.nama_lengkap";

    $stmt_siswa = $conn->prepare($sql_siswa);
    $stmt_siswa->bind_param($types, ...$params);
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

    // Jika ada siswa untuk guru ini
    if (!empty($siswa_data)) {
        $siswa_ids = array_keys($siswa_data);
        $placeholders = implode(',', array_fill(0, count($siswa_ids), '?'));

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

        // Hanya tampilkan siswa yang memiliki data absensi
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
                    <p class="text-gray-600 md:text-md text-sm">Rekapitulasi absensi per siswa (tanpa detail mapel)</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Filter Section -->
            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Rekap</h3>
                <form method="GET" class="filter-form grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Periode (Bulan-Tahun)
                        </label>
                        <input type="month" name="periode" value="<?php echo $periode; ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-chalkboard-teacher mr-1"></i> Guru
                        </label>
                        <select name="guru_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Guru</option>
                            <?php foreach ($guru_options as $guru): ?>
                                <option value="<?php echo $guru['id']; ?>" <?php echo ($filter_guru == $guru['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-graduate mr-1"></i> Siswa
                        </label>
                        <select name="siswa_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="0">Semua Siswa</option>
                            <?php foreach ($siswa_options as $siswa): ?>
                                <option value="<?php echo $siswa['id']; ?>" <?php echo ($filter_siswa == $siswa['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($siswa['nama_lengkap'] . ' - ' . $siswa['kelas']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                            <?php if ($filter_guru > 0 && isset($guru_options[$filter_guru])): ?>
                                Guru: <?php echo htmlspecialchars($guru_options[$filter_guru]['nama_guru']); ?>
                            <?php endif; ?>
                            <?php if ($filter_siswa > 0 && isset($siswa_options[$filter_siswa])): ?>
                                <?php echo ($filter_guru > 0) ? ' | ' : ''; ?>
                                Siswa:
                                <?php echo htmlspecialchars($siswa_options[$filter_siswa]['nama_lengkap'] . ' (' . $siswa_options[$filter_siswa]['kelas'] . ')'); ?>
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

                                <!-- Tabel Siswa (TANPA KOLOM MAPEL) -->
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
                        t.querySelector('.arrow').style.transform = 'rotate(0deg)';
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
    </script>
</body>

</html>