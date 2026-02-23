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

// ============================================
// HANDLE AJAX SEARCH REQUEST
// ============================================
if (isset($_GET['ajax_search']) && isset($_GET['query'])) {
    $query = trim($_GET['query']);

    if (strlen($query) < 2) {
        echo json_encode([]);
        exit();
    }

    $sql = "SELECT 
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
            )
            AND s.nama_lengkap LIKE ?
            ORDER BY s.nama_lengkap
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $search_term = "%{$query}%";
        $stmt->bind_param("is", $guru_id, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();

        $siswa_list = [];
        while ($row = $result->fetch_assoc()) {
            $siswa_list[] = [
                'id' => $row['id'],
                'nama_lengkap' => htmlspecialchars($row['nama_lengkap']),
                'kelas_sekolah' => htmlspecialchars($row['kelas_sekolah'])
            ];
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($siswa_list ?? []);
    exit();
}

// ============================================
// HANDLE GET MATA PELAJARAN PER SISWA
// ============================================
if (isset($_GET['get_mapel']) && isset($_GET['siswa_id'])) {
    $siswa_id = intval($_GET['siswa_id']);

    $sql = "SELECT sp.id, sp.nama_pelajaran
            FROM siswa_pelajaran sp
            WHERE sp.siswa_id = ?
            AND sp.status = 'aktif'
            ORDER BY sp.nama_pelajaran";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $mapel_list = [];
        while ($row = $result->fetch_assoc()) {
            $mapel_list[] = [
                'id' => $row['id'],
                'nama' => htmlspecialchars($row['nama_pelajaran'])
            ];
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($mapel_list ?? []);
    exit();
}

// ============================================
// HANDLE GET SISWA BY TANGGAL (untuk AJAX)
// ============================================
if (isset($_GET['get_siswa_by_tanggal']) && isset($_GET['tanggal'])) {
    $tanggal_filter = $_GET['tanggal'];

    $sql = "SELECT 
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
            )
            ORDER BY s.nama_lengkap";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $siswa_list = [];
        while ($row = $result->fetch_assoc()) {
            $sql_absensi = "SELECT status, keterangan, siswa_pelajaran_id 
                           FROM absensi_siswa 
                           WHERE siswa_id = ? 
                           AND guru_id = ?
                           AND tanggal_absensi = ?
                           LIMIT 1";

            $stmt_abs = $conn->prepare($sql_absensi);
            $stmt_abs->bind_param("iis", $row['id'], $guru_id, $tanggal_filter);
            $stmt_abs->execute();
            $absensi = $stmt_abs->get_result()->fetch_assoc();
            $stmt_abs->close();
            
            $sql_mapel = "SELECT sp.id, sp.nama_pelajaran
                         FROM siswa_pelajaran sp
                         WHERE sp.siswa_id = ?
                         AND sp.status = 'aktif'
                         ORDER BY sp.nama_pelajaran";
            
            $stmt_mapel = $conn->prepare($sql_mapel);
            $stmt_mapel->bind_param("i", $row['id']);
            $stmt_mapel->execute();
            $mapel_result = $stmt_mapel->get_result();
            
            $mapel_list = [];
            while ($mapel = $mapel_result->fetch_assoc()) {
                $mapel_list[] = [
                    'id' => $mapel['id'],
                    'nama' => $mapel['nama_pelajaran']
                ];
            }
            $stmt_mapel->close();

            $siswa_list[] = [
                'id' => $row['id'],
                'nama_lengkap' => htmlspecialchars($row['nama_lengkap']),
                'kelas_sekolah' => htmlspecialchars($row['kelas_sekolah']),
                'mapel_list' => $mapel_list,
                'selected_mapel_id' => $absensi['siswa_pelajaran_id'] ?? '',
                'status' => $absensi['status'] ?? '',
                'keterangan' => $absensi['keterangan'] ?? ''
            ];
        }
        $stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($siswa_list ?? []);
    exit();
}

// ============================================
// MAIN PAGE LOGIC
// ============================================

// Tanggal default hari ini
$tanggal = date('Y-m-d');
if (isset($_GET['tanggal']) && !empty($_GET['tanggal'])) {
    $tanggal = $_GET['tanggal'];
}

// Filter pencarian
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Cek apakah ada parameter sukses
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = "Absensi berhasil disimpan!";
}

// Proses simpan absensi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_absensi'])) {
    try {
        $conn->begin_transaction();

        $tanggal_absensi = $_POST['tanggal'] ?? date('Y-m-d');
        $siswa_data = $_POST['siswa'] ?? [];

        foreach ($siswa_data as $siswa_id => $data) {
            $status = $data['status'] ?? '';
            $keterangan = $data['keterangan'] ?? '';
            $siswa_pelajaran_id = !empty($data['siswa_pelajaran_id']) ? intval($data['siswa_pelajaran_id']) : null;

            if (empty($status)) {
                continue;
            }

            // Cek apakah sudah ada absensi
            $check_sql = "SELECT id FROM absensi_siswa 
                         WHERE siswa_id = ? 
                         AND guru_id = ?
                         AND tanggal_absensi = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iis", $siswa_id, $guru_id, $tanggal_absensi);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            // Ambil pendaftaran_id
            $pendaftaran_sql = "SELECT DISTINCT ps.id as pendaftaran_id 
                               FROM pendaftaran_siswa ps
                               INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                               INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               WHERE ps.siswa_id = ? 
                               AND smg.guru_id = ?
                               AND ps.status = 'aktif'
                               AND jb.status = 'aktif'
                               LIMIT 1";
            $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
            $pendaftaran_stmt->bind_param("ii", $siswa_id, $guru_id);
            $pendaftaran_stmt->execute();
            $pendaftaran_result = $pendaftaran_stmt->get_result();
            $pendaftaran_data = $pendaftaran_result->fetch_assoc();
            $pendaftaran_id = $pendaftaran_data['pendaftaran_id'] ?? 0;
            $pendaftaran_stmt->close();

            if ($pendaftaran_id == 0) {
                $pendaftaran_sql2 = "SELECT id as pendaftaran_id 
                                    FROM pendaftaran_siswa 
                                    WHERE siswa_id = ? 
                                    AND status = 'aktif'
                                    LIMIT 1";
                $pendaftaran_stmt2 = $conn->prepare($pendaftaran_sql2);
                $pendaftaran_stmt2->bind_param("i", $siswa_id);
                $pendaftaran_stmt2->execute();
                $pendaftaran_result2 = $pendaftaran_stmt2->get_result();
                $pendaftaran_data2 = $pendaftaran_result2->fetch_assoc();
                $pendaftaran_id = $pendaftaran_data2['pendaftaran_id'] ?? 0;
                $pendaftaran_stmt2->close();
            }

            if ($check_result->num_rows > 0) {
                // Update
                $row = $check_result->fetch_assoc();
                $update_sql = "UPDATE absensi_siswa 
                              SET status = ?, 
                                  keterangan = ?,
                                  siswa_pelajaran_id = ?,
                                  updated_at = NOW()
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssii", $status, $keterangan, $siswa_pelajaran_id, $row['id']);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Insert
                $insert_sql = "INSERT INTO absensi_siswa 
                              (siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, tanggal_absensi, status, keterangan, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiiisss",
                    $siswa_id,
                    $pendaftaran_id,
                    $siswa_pelajaran_id,
                    $guru_id,
                    $tanggal_absensi,
                    $status,
                    $keterangan
                );
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            $check_stmt->close();
        }

        $conn->commit();

        $params = ["tanggal=" . urlencode($tanggal_absensi), "success=1"];
        if (!empty($search_query)) {
            $params[] = "search=" . urlencode($search_query);
        }
        
        header("Location: absensiSiswa.php?" . implode('&', $params));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
}

// ============================================
// AMBIL DAFTAR SISWA (FIXED - TANPA DUPLIKAT)
// ============================================
$daftar_siswa = [];
$statistik = [
    'total_siswa' => 0,
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0,
    'belum_absen' => 0
];

if (!empty($tanggal)) {
    // Ambil siswa UNIQUE
    $sql_siswa = "SELECT 
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

    if (!empty($search_query)) {
        $sql_siswa .= " AND s.nama_lengkap LIKE ?";
        $params[] = "%{$search_query}%";
        $types .= "s";
    }

    $sql_siswa .= " ORDER BY s.nama_lengkap";

    $stmt_siswa = $conn->prepare($sql_siswa);
    if ($stmt_siswa) {
        $stmt_siswa->bind_param($types, ...$params);
        $stmt_siswa->execute();
        $result_siswa = $stmt_siswa->get_result();

        while ($row = $result_siswa->fetch_assoc()) {
            $daftar_siswa[] = $row;
        }
        $stmt_siswa->close();
    }

    $statistik['total_siswa'] = count($daftar_siswa);

    // Ambil data absensi dan mapel
    if (!empty($daftar_siswa)) {
        foreach ($daftar_siswa as $key => $siswa) {
            // Ambil absensi
            $sql_absensi = "SELECT status, keterangan, siswa_pelajaran_id 
                           FROM absensi_siswa 
                           WHERE siswa_id = ? 
                           AND guru_id = ?
                           AND tanggal_absensi = ?
                           LIMIT 1";
            $stmt_abs = $conn->prepare($sql_absensi);
            $stmt_abs->bind_param("iis", $siswa['id'], $guru_id, $tanggal);
            $stmt_abs->execute();
            $absensi = $stmt_abs->get_result()->fetch_assoc();
            $stmt_abs->close();

            // Update array
            $daftar_siswa[$key]['status'] = $absensi['status'] ?? '';
            $daftar_siswa[$key]['keterangan'] = $absensi['keterangan'] ?? '';
            $daftar_siswa[$key]['selected_mapel_id'] = $absensi['siswa_pelajaran_id'] ?? '';

            // Hitung statistik
            $status = $absensi['status'] ?? '';
            if (!empty($status) && isset($statistik[$status])) {
                $statistik[$status]++;
            }

            // Ambil mapel
            $sql_mapel = "SELECT sp.id, sp.nama_pelajaran
                         FROM siswa_pelajaran sp
                         WHERE sp.siswa_id = ?
                         AND sp.status = 'aktif'
                         ORDER BY sp.nama_pelajaran";
            
            $stmt_mapel = $conn->prepare($sql_mapel);
            $stmt_mapel->bind_param("i", $siswa['id']);
            $stmt_mapel->execute();
            $mapel_result = $stmt_mapel->get_result();

            $daftar_siswa[$key]['mapel_list'] = [];
            while ($mapel = $mapel_result->fetch_assoc()) {
                $daftar_siswa[$key]['mapel_list'][] = $mapel;
            }
            $stmt_mapel->close();
        }
    }

    // Hitung belum_absen
    $statistik['belum_absen'] = $statistik['total_siswa'] -
        ($statistik['hadir'] + $statistik['izin'] + $statistik['sakit'] + $statistik['alpha']);
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Harian - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .status-hadir {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-izin {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-sakit {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-alpha {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background-color: #f3f4f6;
            color: #6b7280;
        }

        .table-responsive {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 900px;
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

            .filter-grid {
                grid-template-columns: 1fr !important;
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

        .mapel-select {
            min-width: 150px;
            max-width: 200px;
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Absensi Harian</p>
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
                        <i class="fas fa-calendar-check mr-2"></i> Absensi Harian
                    </h1>
                    <p class="text-gray-600">Input absensi siswa - Lengkap dengan mata pelajaran</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <a href="rekapAbsensi.php"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-green-100 text-green-800 hover:bg-green-200">
                        <i class="fas fa-chart-bar mr-2"></i> Rekap Absensi
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <?php if (isset($success_message)): ?>
                <div id="successMessage" class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo $success_message; ?></span>
                        <button onclick="this.parentElement.parentElement.style.display='none'"
                            class="ml-auto text-green-700 hover:text-green-900">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $error_message; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Tanggal Absensi
                        </label>
                        <input type="date" name="tanggal" value="<?php echo $tanggal; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-search mr-1"></i> Cari Nama Siswa
                        </label>
                        <div class="search-container">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                                   placeholder="Ketik nama siswa..." 
                                   class="search-input">
                            <?php if (!empty($search_query)): ?>
                                <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>" class="clear-search">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                            <i class="fas fa-search search-icon"></i>
                        </div>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" 
                                class="w-full md:w-auto px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            <i class="fas fa-search mr-2"></i> Tampilkan
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($search_query)): ?>
                    <div class="mt-3 text-sm text-blue-600">
                        <i class="fas fa-filter mr-1"></i> Filter aktif: Pencarian "<?php echo htmlspecialchars($search_query); ?>"
                    </div>
                <?php endif; ?>
            </div>

            <!-- Stats Cards -->
            <?php if (!empty($daftar_siswa)): ?>
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-calendar-alt mr-2"></i>
                            Absensi Tanggal: <?php echo date('d F Y', strtotime($tanggal)); ?>
                        </h3>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-users mr-1"></i> <?php echo $statistik['total_siswa']; ?> Siswa
                        </span>
                    </div>
                
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm text-gray-600">Total Siswa</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo $statistik['total_siswa']; ?></p>
                        </div>
                        <div class="bg-green-50 rounded-lg p-4">
                            <p class="text-sm text-green-600">Hadir</p>
                            <p class="text-2xl font-bold text-green-800"><?php echo $statistik['hadir']; ?></p>
                        </div>
                        <div class="bg-yellow-50 rounded-lg p-4">
                            <p class="text-sm text-yellow-600">Izin</p>
                            <p class="text-2xl font-bold text-yellow-800"><?php echo $statistik['izin']; ?></p>
                        </div>
                        <div class="bg-blue-50 rounded-lg p-4">
                            <p class="text-sm text-blue-600">Sakit</p>
                            <p class="text-2xl font-bold text-blue-800"><?php echo $statistik['sakit']; ?></p>
                        </div>
                        <div class="bg-red-50 rounded-lg p-4">
                            <p class="text-sm text-red-600">Alpha</p>
                            <p class="text-2xl font-bold text-red-800"><?php echo $statistik['alpha']; ?></p>
                        </div>
                        <div class="bg-orange-50 rounded-lg p-4">
                            <p class="text-sm text-orange-600">Belum Absen</p>
                            <p class="text-2xl font-bold text-orange-800"><?php echo $statistik['belum_absen']; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Form Absensi -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-medium text-gray-900">
                                <i class="fas fa-list mr-2"></i> Daftar Siswa
                            </h3>
                            <div class="flex items-center space-x-2">
                                <button type="button" onclick="setAllStatus('hadir')" 
                                        class="px-3 py-1 bg-green-100 text-green-800 rounded-md hover:bg-green-200 text-sm">
                                    <i class="fas fa-check mr-1"></i>Semua Hadir
                                </button>
                                <button type="button" onclick="saveAbsensi()" 
                                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                                    <i class="fas fa-save mr-1"></i>Simpan Absensi
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <form id="formAbsensi" method="POST" style="display: none;">
                        <input type="hidden" name="simpan_absensi" value="1">
                        <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                    </form>
                
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Siswa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mata Pelajaran</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($daftar_siswa as $index => $siswa): ?>
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
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <select class="mapel-select px-2 py-1 border border-gray-300 rounded-md text-sm"
                                                    data-siswa-id="<?php echo $siswa['id']; ?>">
                                                <option value="">Pilih Mapel</option>
                                                <?php foreach ($siswa['mapel_list'] as $mapel): ?>
                                                    <option value="<?php echo $mapel['id']; ?>" 
                                                        <?php echo ($siswa['selected_mapel_id'] == $mapel['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($mapel['nama_pelajaran']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select onchange="updateRowStatus(this)" 
                                                    class="status-select w-32 px-2 py-1 border rounded-md text-sm
                                                           <?php echo $siswa['status'] ? 'status-' . $siswa['status'] : 'status-default'; ?>"
                                                    data-siswa-id="<?php echo $siswa['id']; ?>">
                                                <option value="" <?php echo empty($siswa['status']) ? 'selected' : ''; ?>>Pilih</option>
                                                <option value="hadir" <?php echo $siswa['status'] == 'hadir' ? 'selected' : ''; ?>>Hadir</option>
                                                <option value="izin" <?php echo $siswa['status'] == 'izin' ? 'selected' : ''; ?>>Izin</option>
                                                <option value="sakit" <?php echo $siswa['status'] == 'sakit' ? 'selected' : ''; ?>>Sakit</option>
                                                <option value="alpha" <?php echo $siswa['status'] == 'alpha' ? 'selected' : ''; ?>>Alpha</option>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="text" 
                                                   class="keterangan-input w-40 px-2 py-1 border border-gray-300 rounded-md text-sm"
                                                   placeholder="Alasan"
                                                   value="<?php echo htmlspecialchars($siswa['keterangan']); ?>"
                                                   data-siswa-id="<?php echo $siswa['id']; ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif (!empty($tanggal)): ?>
                <div class="bg-white shadow rounded-lg p-8 text-center">
                    <div class="mb-4">
                        <i class="fas fa-users text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Tidak Ada Siswa</h3>
                    <p class="text-gray-600 mb-4">
                        <?php if (!empty($search_query)): ?>
                            Tidak ditemukan siswa dengan nama "<?php echo htmlspecialchars($search_query); ?>"
                        <?php else: ?>
                            Anda belum memiliki siswa yang terdaftar
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search_query)): ?>
                        <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            <i class="fas fa-times mr-2"></i> Hapus Filter
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-6">
            <div class="container mx-auto py-4 px-4">
                <p class="text-sm text-gray-500 text-center">
                    © <?php echo date('Y'); ?> Bimbel Esc - Absensi Harian
                </p>
            </div>
        </footer>
    </div>

    <script>
        // Data storage untuk absensi
        let absensiData = {};
        
        // Inisialisasi data dari PHP
        <?php foreach ($daftar_siswa as $siswa): ?>
            absensiData[<?php echo $siswa['id']; ?>] = {
                status: '<?php echo $siswa['status']; ?>',
                keterangan: '<?php echo addslashes($siswa['keterangan']); ?>',
                mapel_id: '<?php echo $siswa['selected_mapel_id']; ?>'
            };
        <?php endforeach; ?>

        // Update class saat status berubah
        function updateRowStatus(select) {
            const status = select.value;
            const statusClasses = {
                'hadir': 'status-hadir',
                'izin': 'status-izin',
                'sakit': 'status-sakit',
                'alpha': 'status-alpha',
                '': 'status-default'
            };
            
            // Reset class
            Object.values(statusClasses).forEach(cls => {
                select.classList.remove(cls);
            });
            
            // Tambah class baru
            select.classList.add(statusClasses[status] || 'status-default');
            
            // Simpan data
            const siswaId = select.getAttribute('data-siswa-id');
            if (!absensiData[siswaId]) absensiData[siswaId] = {};
            absensiData[siswaId].status = status;
        }

        // Update keterangan
        document.querySelectorAll('.keterangan-input').forEach(input => {
            input.addEventListener('input', function() {
                const siswaId = this.getAttribute('data-siswa-id');
                if (!absensiData[siswaId]) absensiData[siswaId] = {};
                absensiData[siswaId].keterangan = this.value;
            });
        });

        // Update mapel
        document.querySelectorAll('.mapel-select').forEach(select => {
            select.addEventListener('change', function() {
                const siswaId = this.getAttribute('data-siswa-id');
                const mapelId = this.value;
                if (!absensiData[siswaId]) absensiData[siswaId] = {};
                absensiData[siswaId].mapel_id = mapelId;
            });
        });

        // Set semua status
        function setAllStatus(status) {
            const selects = document.querySelectorAll('.status-select');
            selects.forEach(select => {
                select.value = status;
                updateRowStatus(select);
            });
        }

        // Simpan absensi
        function saveAbsensi() {
            const form = document.getElementById('formAbsensi');
            
            // Hapus input lama
            while (form.firstChild) {
                form.removeChild(form.firstChild);
            }
            
            // Tambah input hidden dasar
            const inputs = [
                { name: 'simpan_absensi', value: '1' },
                { name: 'tanggal', value: '<?php echo $tanggal; ?>' }
            ];
            
            inputs.forEach(data => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = data.name;
                input.value = data.value;
                form.appendChild(input);
            });
            
            // Tambah data siswa
            let hasData = false;
            document.querySelectorAll('.status-select').forEach(select => {
                const siswaId = select.getAttribute('data-siswa-id');
                const status = select.value;
                
                if (status) {
                    hasData = true;
                    
                    // Status
                    const statusInput = document.createElement('input');
                    statusInput.type = 'hidden';
                    statusInput.name = `siswa[${siswaId}][status]`;
                    statusInput.value = status;
                    form.appendChild(statusInput);
                    
                    // Keterangan
                    const ketInput = document.createElement('input');
                    ketInput.type = 'hidden';
                    ketInput.name = `siswa[${siswaId}][keterangan]`;
                    ketInput.value = absensiData[siswaId]?.keterangan || '';
                    form.appendChild(ketInput);
                    
                    // Mapel
                    const mapelInput = document.createElement('input');
                    mapelInput.type = 'hidden';
                    mapelInput.name = `siswa[${siswaId}][siswa_pelajaran_id]`;
                    mapelInput.value = absensiData[siswaId]?.mapel_id || '';
                    form.appendChild(mapelInput);
                }
            });
            
            if (!hasData) {
                if (!confirm('Tidak ada data status yang dipilih. Lanjutkan?')) {
                    return;
                }
            }
            
            form.submit();
        }

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

        // Auto hide success message
        setTimeout(function() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);
    </script>
</body>
</html>