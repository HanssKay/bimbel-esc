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
    <style>
        .table-responsive {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 800px;
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
                    <p class="text-gray-600">Rekapitulasi absensi siswa per bulan</p>
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
                <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
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
                                placeholder="Ketik nama siswa..." class="search-input">
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
    </script>
</body>

</html>