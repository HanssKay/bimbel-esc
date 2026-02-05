<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
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

// CEK JIKA ADA REQUEST AJAX
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'get_detail_anak' && isset($_GET['id'])) {
        getDetailAnak($conn, $_GET['id'], $orangtua_id);
        exit();
    }
    if ($_GET['action'] == 'get_detail_penilaian' && isset($_GET['id'])) {
        getDetailPenilaian($conn, $_GET['id'], $orangtua_id);
        exit();
    }
}

// AMBIL DATA ANAK-ANAK BERDASARKAN TABEL SISWA_ORANGTUA
$anak_data = [];
$total_anak = 0;

if ($orangtua_id > 0) {
    try {
        // Query untuk mengambil data anak melalui tabel siswa_orangtua
        $sql = "SELECT s.* 
                FROM siswa s
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                WHERE so.orangtua_id = ? AND s.status = 'aktif'
                ORDER BY s.nama_lengkap";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $anak_data[] = $row;
        }
        $total_anak = count($anak_data);
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching anak data: " . $e->getMessage());
    }
}

// AMBIL DATA PENDAFTARAN AKTIF UNTUK SETIAP ANAK
$pendaftaran_data = [];
if ($orangtua_id > 0 && !empty($anak_data)) {
    try {
        $siswa_ids = array_column($anak_data, 'id');
        $placeholders = implode(',', array_fill(0, count($siswa_ids), '?'));

        $sql = "SELECT ps.*, s.id as siswa_id, s.nama_lengkap
                FROM pendaftaran_siswa ps
                JOIN siswa s ON ps.siswa_id = s.id
                WHERE ps.siswa_id IN ($placeholders) 
                AND ps.status = 'aktif'";

        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($siswa_ids));
        $stmt->bind_param($types, ...$siswa_ids);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $pendaftaran_data[$row['siswa_id']] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching pendaftaran data: " . $e->getMessage());
    }
}

// AMBIL DATA PELAJARAN UNTUK SETIAP PENDAFTARAN
$pelajaran_data = [];
if (!empty($pendaftaran_data)) {
    try {
        $pendaftaran_ids = array_column($pendaftaran_data, 'id');
        $placeholders = implode(',', array_fill(0, count($pendaftaran_ids), '?'));

        $sql = "SELECT sp.*, ps.siswa_id, u.full_name as nama_guru
                FROM siswa_pelajaran sp
                JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                LEFT JOIN guru g ON sp.guru_id = g.id
                LEFT JOIN users u ON g.user_id = u.id
                WHERE sp.pendaftaran_id IN ($placeholders) 
                AND sp.status = 'aktif'";

        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($pendaftaran_ids));
        $stmt->bind_param($types, ...$pendaftaran_ids);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $pelajaran_data[$row['siswa_id']][] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching pelajaran data: " . $e->getMessage());
    }
}

// AMBIL PENILAIAN TERBARU untuk semua anak (satu per anak)
$penilaian_terbaru = [];
if ($orangtua_id > 0 && !empty($anak_data)) {
    try {
        // Query untuk mengambil penilaian terbaru setiap anak
        $sql_penilaian = "SELECT pn.*, 
                                 s.nama_lengkap as nama_siswa,
                                 u.full_name as nama_guru,
                                 sp.nama_pelajaran,
                                 DATE_FORMAT(pn.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                                 pn.persentase,
                                 pn.kategori
                          FROM penilaian_siswa pn
                          JOIN siswa s ON pn.siswa_id = s.id
                          JOIN siswa_orangtua so ON s.id = so.siswa_id
                          JOIN pendaftaran_siswa ps ON pn.pendaftaran_id = ps.id
                          JOIN siswa_pelajaran sp ON pn.siswa_pelajaran_id = sp.id
                          JOIN guru g ON pn.guru_id = g.id
                          JOIN users u ON g.user_id = u.id
                          WHERE so.orangtua_id = ?
                          AND pn.id IN (
                              SELECT MAX(id) 
                              FROM penilaian_siswa 
                              WHERE siswa_id = pn.siswa_id 
                              GROUP BY siswa_id
                          )
                          AND ps.status = 'aktif'
                          AND s.status = 'aktif'
                          ORDER BY pn.tanggal_penilaian DESC";

        $stmt = $conn->prepare($sql_penilaian);
        $stmt->bind_param("i", $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $penilaian_terbaru[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching penilaian terbaru: " . $e->getMessage());
    }
}

// STATISTIK
$statistik = [
    'total_penilaian' => 0,
    'rata_total_skor' => 0,
    'kategori_terbaik' => '',
    'kategori_terburuk' => ''
];

// Ambil penilaian dengan tanggal TERBARU dari array $penilaian_terbaru
if (!empty($penilaian_terbaru)) {
    // Urutkan berdasarkan tanggal descending
    usort($penilaian_terbaru, function ($a, $b) {
        return strtotime($b['tanggal_penilaian']) - strtotime($a['tanggal_penilaian']);
    });

    // Ambil yang pertama (terbaru)
    $penilaian_paling_baru = $penilaian_terbaru[0];

    $statistik['skor_terbaru'] = $penilaian_paling_baru['total_score'];
    $statistik['kategori_terbaru'] = $penilaian_paling_baru['kategori'];
    $statistik['tanggal_terbaru'] = $penilaian_paling_baru['tanggal_format'];
    $statistik['pelajaran_terbaru'] = $penilaian_paling_baru['nama_pelajaran'];
} else {
    $statistik['skor_terbaru'] = 0;
    $statistik['kategori_terbaru'] = '-';
}

// AMBIL DATA DARI VIEW UNTUK DASHBOARD
$dashboard_data = [];
if ($orangtua_id > 0) {
    try {
        $sql_dashboard = "SELECT * FROM view_dashboard_orangtua_new WHERE orangtua_id = ?";
        $stmt = $conn->prepare($sql_dashboard);
        $stmt->bind_param("i", $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dashboard_data[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching dashboard data: " . $e->getMessage());
    }
}

// FUNGSI UNTUK HANDLE AJAX REQUEST
function getDetailAnak($conn, $anak_id, $orangtua_id)
{
    header('Content-Type: application/json');

    try {
        // Update query untuk include siswa_orangtua
        $sql = "SELECT s.*, 
                       DATE_FORMAT(s.tanggal_lahir, '%d %M %Y') as tgl_lahir_format
                FROM siswa s 
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                WHERE s.id = ? AND so.orangtua_id = ? AND s.status = 'aktif'";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $anak_id, $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();

            // Ambil data pendaftaran aktif
            $sql_pendaftaran = "SELECT ps.*
                         FROM pendaftaran_siswa ps
                         WHERE ps.siswa_id = ? AND ps.status = 'aktif'
                         LIMIT 1";

            $stmt_pendaftaran = $conn->prepare($sql_pendaftaran);
            $stmt_pendaftaran->bind_param("i", $anak_id);
            $stmt_pendaftaran->execute();
            $result_pendaftaran = $stmt_pendaftaran->get_result();
            if ($row_pendaftaran = $result_pendaftaran->fetch_assoc()) {
                $data['pendaftaran'] = $row_pendaftaran;

                // Ambil data pelajaran untuk pendaftaran ini
                $sql_pelajaran = "SELECT sp.*, u.full_name as nama_guru
                                 FROM siswa_pelajaran sp
                                 LEFT JOIN guru g ON sp.guru_id = g.id
                                 LEFT JOIN users u ON g.user_id = u.id
                                 WHERE sp.pendaftaran_id = ? AND sp.status = 'aktif'";

                $stmt_pelajaran = $conn->prepare($sql_pelajaran);
                $stmt_pelajaran->bind_param("i", $row_pendaftaran['id']);
                $stmt_pelajaran->execute();
                $result_pelajaran = $stmt_pelajaran->get_result();
                $pelajaran_list = [];
                while ($row_pelajaran = $result_pelajaran->fetch_assoc()) {
                    $pelajaran_list[] = $row_pelajaran;
                }
                $data['pelajaran'] = $pelajaran_list;
                $stmt_pelajaran->close();
            }
            $stmt_pendaftaran->close();

            // Ambil statistik penilaian
            $sql_stat = "SELECT 
                        COUNT(*) as total_penilaian,
                        AVG(total_score) as rata_skor,
                        AVG(persentase) as rata_persentase,
                        (SELECT kategori FROM penilaian_siswa WHERE siswa_id = ? ORDER BY tanggal_penilaian DESC LIMIT 1) as kategori_terakhir
                        FROM penilaian_siswa 
                        WHERE siswa_id = ?";

            $stmt_stat = $conn->prepare($sql_stat);
            $stmt_stat->bind_param("ii", $anak_id, $anak_id);
            $stmt_stat->execute();
            $result_stat = $stmt_stat->get_result();
            if ($row_stat = $result_stat->fetch_assoc()) {
                $data['statistik'] = $row_stat;
            } else {
                $data['statistik'] = [
                    'total_penilaian' => 0,
                    'rata_skor' => 0,
                    'rata_persentase' => 0,
                    'kategori_terakhir' => 'Belum ada penilaian'
                ];
            }
            $stmt_stat->close();

            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error in getDetailAnak: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

function getDetailPenilaian($conn, $penilaian_id, $orangtua_id)
{
    header('Content-Type: application/json');

    try {
        // Update query untuk include siswa_orangtua
        $sql = "SELECT pn.*, 
                       s.nama_lengkap as nama_siswa,
                       u.full_name as nama_guru,
                       sp.nama_pelajaran,
                       ps.jenis_kelas as kelas_bimbel,
                       ps.tingkat as tingkat_bimbel,
                       DATE_FORMAT(pn.tanggal_penilaian, '%d %M %Y') as tanggal_format
                FROM penilaian_siswa pn
                JOIN siswa s ON pn.siswa_id = s.id
                JOIN siswa_orangtua so ON s.id = so.siswa_id
                JOIN pendaftaran_siswa ps ON pn.pendaftaran_id = ps.id
                JOIN siswa_pelajaran sp ON pn.siswa_pelajaran_id = sp.id
                JOIN guru g ON pn.guru_id = g.id
                JOIN users u ON g.user_id = u.id
                WHERE pn.id = ? AND so.orangtua_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $penilaian_id, $orangtua_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();

            // Set NIS default karena kolom tidak ada
            $data['nis'] = '-';

            // Hitung persentase jika belum ada
            if (!isset($data['persentase']) || $data['persentase'] === null) {
                $data['persentase'] = round(($data['total_score'] / 50) * 100);
            }

            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Data penilaian tidak ditemukan']);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error in getDetailPenilaian: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Orang Tua - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ... (CSS tetap sama seperti sebelumnya) ... */
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

        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
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
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background-color: #f9fafb;
            border-radius: 0 0 8px 8px;
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

        .desktop-sidebar {
            display: none;
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
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .grid {
                grid-template-columns: 1fr !important;
            }

            .stat-cards {
                grid-template-columns: repeat(2, 1fr) !important;
            }

            .info-cards {
                grid-template-columns: 1fr !important;
            }
        }

        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }

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
                        <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                </div>
                <div class="mt-3 text-sm">
                    <p><i class="fas fa-child mr-2"></i> <?php echo $total_anak; ?> Anak</p>
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
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Dashboard Orang Tua</h1>
                    <p class="text-gray-600 text-sm md:text-base">Selamat datang! Pantau perkembangan anak Anda</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <p class="text-sm text-gray-600"><?php echo date('l, d F Y'); ?></p>
                    <p class="text-sm text-blue-600"><span id="serverTime"><?php echo date('H:i'); ?></span> WIB</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Statistik Ringkas -->
            <div class="grid stat-cards grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6">
                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-child text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Total Anak</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $total_anak; ?></p>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-clipboard-check text-green-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Penilaian Terbaru </p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $statistik['total_penilaian']; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <!-- GANTI kode card skor ini: -->
                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-purple-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-star text-purple-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600"> Skor Terbaru </p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $statistik['skor_terbaru']; ?>/50
                            </p>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-trophy text-yellow-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Kategori Nilai</p>
                            <p class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $statistik['kategori_terbaru'] ?: '-'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Anak & Penilaian -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6 mb-6">
                <!-- Daftar Anak -->
                <div class="card bg-white rounded-lg shadow">
                    <div class="p-4 md:p-6 border-b">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800">
                            <i class="fas fa-users mr-2"></i> Data Anak Anda
                        </h3>
                    </div>
                    <div class="p-3 md:p-6">
                        <?php if ($total_anak == 0): ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-child text-4xl md:text-5xl text-gray-400 mb-4"></i>
                                <h4 class="text-base md:text-lg font-medium text-gray-700 mb-2">Belum ada data anak</h4>
                                <p class="text-gray-500 text-sm md:text-base">Hubungi admin untuk mendaftarkan anak Anda.
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($anak_data as $anak):
                                    $anak_dashboard = null;
                                    foreach ($dashboard_data as $data) {
                                        if ($data['siswa_id'] == $anak['id']) {
                                            $anak_dashboard = $data;
                                            break;
                                        }
                                    }

                                    $pendaftaran = $pendaftaran_data[$anak['id']] ?? null;
                                    $pelajaran_list = $pelajaran_data[$anak['id']] ?? [];
                                    ?>
                                    <div class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-blue-50 transition">
                                        <div
                                            class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                            <span class="text-blue-800 font-bold text-sm md:text-base">
                                                <?php echo strtoupper(substr($anak['nama_lengkap'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-3 flex-1 min-w-0">
                                            <h4 class="font-medium text-gray-900 text-sm md:text-base truncate">
                                                <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                            </h4>
                                            <div class="text-xs text-gray-600 flex flex-wrap gap-1 md:gap-2">
                                                <span class="inline-block">
                                                    <i class="fas fa-school mr-1"></i>
                                                    <?php echo htmlspecialchars($anak['sekolah_asal'] ?: '-'); ?>
                                                </span>
                                                <span class="inline-block">
                                                    <i class="fas fa-graduation-cap mr-1"></i>
                                                    <?php echo htmlspecialchars($anak['kelas']); ?>
                                                </span>
                                            </div>
                                            <?php if ($pendaftaran): ?>
                                                <div class="text-xs text-blue-600 mt-1 truncate">
                                                    <i class="fas fa-chalkboard-teacher mr-1"></i>
                                                    <?php echo htmlspecialchars($pendaftaran['jenis_kelas']); ?>
                                                    (<?php echo htmlspecialchars($pendaftaran['tingkat']); ?>)
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($pelajaran_list)): ?>
                                                <div class="text-xs text-green-600 mt-1 truncate">
                                                    <i class="fas fa-book mr-1"></i>
                                                    <?php
                                                    $pelajaran_names = array_map(function ($p) {
                                                        return htmlspecialchars($p['nama_pelajaran']);
                                                    }, $pelajaran_list);
                                                    echo implode(', ', $pelajaran_names);
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($anak_dashboard && $anak_dashboard['kategori_terkini']):
                                                $badge_class = '';
                                                if ($anak_dashboard['kategori_terkini'] == 'Sangat Baik')
                                                    $badge_class = 'badge-sangat-baik';
                                                elseif ($anak_dashboard['kategori_terkini'] == 'Baik')
                                                    $badge_class = 'badge-baik';
                                                elseif ($anak_dashboard['kategori_terkini'] == 'Cukup')
                                                    $badge_class = 'badge-cukup';
                                                else
                                                    $badge_class = 'badge-kurang';
                                                ?>
                                                <div class="mt-1 flex items-center gap-2">
                                                    <span class="text-xs px-2 py-1 rounded-full <?php echo $badge_class; ?>">
                                                        <?php echo htmlspecialchars($anak_dashboard['kategori_terkini']); ?>
                                                    </span>
                                                    <span class="text-xs text-gray-500">
                                                        <?php echo $anak_dashboard['aktif_pendaftaran'] ?? 0; ?> program aktif
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <button onclick="showDetailAnak(<?php echo $anak['id']; ?>)"
                                            class="text-blue-600 hover:text-blue-800 p-1 md:p-2 flex-shrink-0">
                                            <i class="fas fa-eye text-sm md:text-base"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Penilaian Terbaru -->
                <div class="card bg-white rounded-lg shadow">
                    <div class="p-4 md:p-6 border-b">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800">
                            <i class="fas fa-chart-bar mr-2"></i> Penilaian Terbaru
                        </h3>
                    </div>
                    <div class="p-3 md:p-6">
                        <?php if (empty($penilaian_terbaru)): ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-clipboard-list text-4xl md:text-5xl text-gray-400 mb-4"></i>
                                <h4 class="text-base md:text-lg font-medium text-gray-700 mb-2">Belum ada penilaian</h4>
                                <p class="text-gray-500 text-sm md:text-base">Belum ada penilaian untuk anak Anda.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($penilaian_terbaru as $penilaian):
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
                                    <div class="border border-gray-200 rounded-lg p-3 hover:border-blue-300 transition">
                                        <div class="flex justify-between items-start mb-2">
                                            <h4 class="font-medium text-gray-900 text-sm md:text-base">
                                                <?php echo htmlspecialchars($penilaian['nama_siswa']); ?>
                                            </h4>
                                            <span class="badge <?php echo $badge_class; ?> text-xs">
                                                <?php echo htmlspecialchars($penilaian['kategori']); ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-600 mb-2">
                                            <span>
                                                <i class="fas fa-book mr-1"></i>
                                                <?php echo htmlspecialchars($penilaian['nama_pelajaran']); ?>
                                            </span>
                                            <span class="mx-2">•</span>
                                            <span>
                                                <i class="fas fa-calendar-alt mr-1"></i>
                                                <?php echo htmlspecialchars($penilaian['tanggal_format']); ?>
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-600 mb-2">
                                            <span>
                                                <i class="fas fa-user-tie mr-1"></i>
                                                <?php echo htmlspecialchars($penilaian['nama_guru']); ?>
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <div class="text-lg md:text-2xl font-bold text-gray-800">
                                                <?php echo $penilaian['total_score']; ?>/50
                                                <span class="text-xs md:text-sm font-normal text-gray-500">
                                                    (<?php echo $penilaian['persentase']; ?>%)
                                                </span>
                                            </div>
                                            <button onclick="showDetailPenilaian(<?php echo $penilaian['id']; ?>)"
                                                class="text-blue-600 hover:text-blue-800 text-xs md:text-sm font-medium">
                                                Detail <i class="fas fa-arrow-right ml-1 hidden md:inline"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Grafik Perkembangan -->
            <!--<?php if (!empty($penilaian_terbaru)): ?>-->
                <!--    <div class="card bg-white rounded-lg shadow p-4 md:p-6 mb-6">-->
                <!--        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">-->
                <!--            <i class="fas fa-chart-line mr-2"></i> Grafik Perkembangan-->
                <!--        </h3>-->
                <!--        <div class="h-48 md:h-64">-->
                <!--            <canvas id="perkembanganChart"></canvas>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--<?php endif; ?>-->



            <!-- Informasi Cards -->
            <div class="grid info-cards grid-cols-1 md:grid-cols-3 gap-3 md:gap-6">
                <div class="card bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center mb-3 md:mb-4">
                        <div class="p-2 bg-blue-100 rounded-lg mr-2 md:mr-3">
                            <i class="fas fa-lightbulb text-blue-600"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 text-sm md:text-base">Tips Belajar</h4>
                    </div>
                    <ul class="space-y-1 md:space-y-2 text-xs md:text-sm text-gray-700">
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                            <span>Buat jadwal belajar rutin</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                            <span>Lingkungan belajar yang nyaman</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                            <span>Istirahat cukup di sela belajar</span>
                        </li>
                    </ul>
                </div>
                <div class="card bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center mb-3 md:mb-4">
                        <div class="p-2 bg-green-100 rounded-lg mr-2 md:mr-3">
                            <i class="fas fa-info-circle text-green-600"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 text-sm md:text-base">Informasi Bimbel</h4>
                    </div>
                    <div class="space-y-2 md:space-y-3 text-xs md:text-sm text-gray-700">
                        <div>
                            <p class="font-medium">Jam Operasional:</p>
                            <p>Senin-Jumat: 08:00-18:00</p>
                            <p>Sabtu: 08:00-13:00</p>
                        </div>
                        <div>
                            <p class="font-medium">Kontak:</p>
                            <p><i class="fas fa-phone mr-1 md:mr-2 text-xs md:text-sm"></i> 08xxxxx</p>
                            <p><i class="fas fa-envelope mr-1 md:mr-2 text-xs md:text-sm"></i> info@bimbelesc.com</p>
                        </div>
                    </div>
                </div>
                <div class="card bg-gradient-to-r from-purple-50 to-violet-50 rounded-lg shadow p-3 md:p-6">
                    <div class="flex items-center mb-3 md:mb-4">
                        <div class="p-2 bg-purple-100 rounded-lg mr-2 md:mr-3">
                            <i class="fas fa-chart-pie text-purple-600"></i>
                        </div>
                        <h4 class="font-semibold text-gray-800 text-sm md:text-base">Statistik Singkat</h4>
                    </div>
                    <div class="space-y-2 md:space-y-3">
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Penilaian Baru</p>
                            <p class="text-lg md:text-xl font-bold text-gray-800">
                                <?php echo $statistik['total_penilaian']; ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Saran</p>
                            <p class="text-xs md:text-sm text-gray-700">
                                <?php
                                if ($statistik['rata_total_skor'] >= 40) {
                                    echo "Pertahankan prestasi anak Anda!";
                                } elseif ($statistik['rata_total_skor'] >= 30) {
                                    echo "Tingkatkan fokus pada indikator yang rendah.";
                                } else {
                                    echo "Komunikasikan dengan guru untuk bimbingan khusus.";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Dashboard Orang Tua</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Login sebagai: <?php echo htmlspecialchars($nama_ortu ?: $full_name); ?>
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

    <!-- MODAL DETAIL ANAK -->
    <div id="detailAnakModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-lg md:text-xl font-bold"><i class="fas fa-child mr-2"></i> Detail Anak</h2>
                <span class="close" onclick="closeModal('detailAnakModal')">&times;</span>
            </div>
            <div class="modal-body" id="detailAnakContent"></div>
            <div class="modal-footer">
                <button onclick="closeModal('detailAnakModal')"
                    class="px-3 md:px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm md:text-base">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL PENILAIAN -->
    <div id="detailPenilaianModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-lg md:text-xl font-bold"><i class="fas fa-chart-bar mr-2"></i> Detail Penilaian</h2>
                <span class="close" onclick="closeModal('detailPenilaianModal')">&times;</span>
            </div>
            <div class="modal-body" id="detailPenilaianContent"></div>
            <div class="modal-footer">
                <button onclick="closeModal('detailPenilaianModal')"
                    class="px-3 md:px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm md:text-base">
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

        // Close menu when clicking on menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });

        // Chart.js
        <?php if (!empty($penilaian_terbaru)): ?>
            document.addEventListener('DOMContentLoaded', function () {
                const ctx = document.getElementById('perkembanganChart').getContext('2d');
                const labels = [];
                const dataScores = [];
                const backgroundColors = [];

                <?php foreach ($penilaian_terbaru as $penilaian): ?>
                    labels.push("<?php echo htmlspecialchars($penilaian['nama_siswa']); ?>");
                    dataScores.push(<?php echo $penilaian['total_score']; ?>);

                    <?php
                    $color = '';
                    if ($penilaian['kategori'] == 'Sangat Baik')
                        $color = '#10B981';
                    elseif ($penilaian['kategori'] == 'Baik')
                        $color = '#3B82F6';
                    elseif ($penilaian['kategori'] == 'Cukup')
                        $color = '#F59E0B';
                    else
                        $color = '#EF4444';
                    ?>
                    backgroundColors.push("<?php echo $color; ?>");
                <?php endforeach; ?>

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Total Skor',
                            data: dataScores,
                            backgroundColor: backgroundColors,
                            borderWidth: 1,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 50,
                                title: {
                                    display: true,
                                    text: 'Total Skor (max 50)'
                                }
                            }
                        },
                        plugins: {
                            legend: { display: false }
                        }
                    }
                });
            });
        <?php endif; ?>

        // FUNGSI UTAMA UNTUK DETAIL ANAK
        function showDetailAnak(anakId) {
            const modal = document.getElementById('detailAnakModal');
            const content = document.getElementById('detailAnakContent');

            // Close mobile menu if open
            if (mobileMenu) {
                mobileMenu.classList.remove('menu-open');
            }
            if (menuOverlay) {
                menuOverlay.classList.remove('active');
            }
            document.body.style.overflow = 'hidden';

            // Tampilkan loading
            content.innerHTML = `
                <div class="text-center py-8 md:py-12">
                    <div class="animate-spin rounded-full h-12 w-12 md:h-16 md:w-16 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-3 md:mt-4 text-gray-600 text-sm md:text-lg">Memuat data anak...</p>
                </div>
            `;

            modal.style.display = 'block';

            // Gunakan URL yang sama dengan halaman saat ini
            const url = window.location.href.split('?')[0] + `?action=get_detail_anak&id=${anakId}`;

            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.data) {
                        displayAnakData(data.data);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-exclamation-circle text-3xl md:text-4xl text-yellow-500 mb-3 md:mb-4"></i>
                                <h3 class="text-lg md:text-xl font-bold text-gray-800 mb-2">Data Tidak Ditemukan</h3>
                                <p class="text-gray-600 text-sm md:text-base">${data.message || 'Data anak tidak tersedia'}</p>
                                <button onclick="closeModal('detailAnakModal')" 
                                        class="mt-3 md:mt-4 px-3 md:px-4 py-2 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700">
                                    Tutup
                                </button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    content.innerHTML = `
                        <div class="text-center py-6 md:py-8">
                            <i class="fas fa-exclamation-triangle text-3xl md:text-4xl text-red-500 mb-3 md:mb-4"></i>
                            <h3 class="text-lg md:text-xl font-bold text-gray-800 mb-2">Kesalahan</h3>
                            <p class="text-gray-600 text-sm md:text-base">Gagal memuat data: ${error.message}</p>
                            <div class="mt-3 md:mt-4 space-x-2">
                                <button onclick="showDetailAnak(${anakId})" 
                                        class="px-3 md:px-4 py-2 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-redo mr-1 md:mr-2"></i> Coba Lagi
                                </button>
                                <button onclick="closeModal('detailAnakModal')" 
                                        class="px-3 md:px-4 py-2 border border-gray-300 text-gray-700 text-sm md:text-base rounded-lg hover:bg-gray-50">
                                    Tutup
                                </button>
                            </div>
                        </div>
                    `;
                });

            // Fungsi untuk menampilkan data anak
            function displayAnakData(d) {
                const stat = d.statistik || {};
                const kategori = stat.kategori_terakhir || 'Belum dinilai';
                const pendaftaran = d.pendaftaran || {};
                const pelajaran = d.pelajaran || [];

                // Tentukan warna badge
                let badgeClass = 'bg-gray-500';
                if (kategori.includes('Sangat Baik')) badgeClass = 'bg-green-500';
                else if (kategori.includes('Baik')) badgeClass = 'bg-blue-500';
                else if (kategori.includes('Cukup')) badgeClass = 'bg-yellow-500';
                else if (kategori.includes('Kurang')) badgeClass = 'bg-red-500';

                content.innerHTML = `
                    <div class="space-y-4 md:space-y-6">
                        <!-- Header -->
                        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white p-4 md:p-6 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-16 h-16 md:w-20 md:h-20 bg-white/20 rounded-full flex items-center justify-center mr-3 md:mr-4">
                                    <span class="text-xl md:text-2xl font-bold">${d.nama_lengkap?.charAt(0) || '?'}</span>
                                </div>
                                <div>
                                    <h2 class="text-xl md:text-2xl font-bold">${d.nama_lengkap || 'Nama tidak tersedia'}</h2>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Info Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                            <!-- Data Pribadi -->
                            <div class="bg-white border border-gray-200 rounded-lg p-4 md:p-5 shadow-sm">
                                <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4 pb-2 border-b">
                                    <i class="fas fa-user mr-2"></i> Data Pribadi
                                </h3>
                                <div class="space-y-2 md:space-y-3">
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Tempat Lahir</label>
                                        <p class="font-medium text-sm md:text-base">${d.tempat_lahir || '-'}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Tanggal Lahir</label>
                                        <p class="font-medium text-sm md:text-base">${d.tgl_lahir_format || '-'}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Jenis Kelamin</label>
                                        <p class="font-medium text-sm md:text-base">${d.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan'}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Alamat</label>
                                        <p class="font-medium text-sm md:text-base">${d.alamat || '-'}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Data Pendidikan -->
                            <div class="bg-white border border-gray-200 rounded-lg p-4 md:p-5 shadow-sm">
                                <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4 pb-2 border-b">
                                    <i class="fas fa-graduation-cap mr-2"></i> Data Pendidikan
                                </h3>
                                <div class="space-y-2 md:space-y-3">
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Sekolah Asal</label>
                                        <p class="font-medium text-sm md:text-base">${d.sekolah_asal || '-'}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Kelas Sekolah</label>
                                        <p class="font-medium text-sm md:text-base">${d.kelas || '-'}</p>
                                    </div>
                                    ${pendaftaran.jenis_kelas ? `
                                    <div>
                                        <label class="block text-xs md:text-sm text-gray-500">Ruang Bimbel</label>
                                        <p class="font-medium text-sm md:text-base">${pendaftaran.jenis_kelas} (${pendaftaran.tingkat || ''})</p>
                                        <p class="text-xs text-gray-500">Mulai: ${pendaftaran.tanggal_mulai || '-'}</p>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Mata Pelajaran -->
                        ${pelajaran.length > 0 ? `
                        <div class="bg-white border border-gray-200 rounded-lg p-4 md:p-5 shadow-sm">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4 pb-2 border-b">
                                <i class="fas fa-book mr-2"></i> Mata Pelajaran
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                                ${pelajaran.map(p => `
                                <div class="border border-gray-200 rounded-lg p-3 md:p-4">
                                    <div class="font-medium text-gray-800 mb-1">${p.nama_pelajaran}</div>
                                    ${p.nama_guru ? `
                                    <div class="text-xs text-gray-600">
                                        <i class="fas fa-user-tie mr-1"></i> ${p.nama_guru}
                                    </div>
                                    ` : ''}
                                </div>
                                `).join('')}
                            </div>
                        </div>
                        ` : ''}
                        
                        <!-- Statistik -->
                        <div class="bg-gradient-to-r from-gray-50 to-white border border-gray-200 rounded-lg p-4 md:p-5 shadow-sm">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4 pb-2 border-b">
                                <i class="fas fa-chart-bar mr-2"></i> Statistik Penilaian
                            </h3>
                            
                            ${stat.total_penilaian > 0 ? `
                            <div class="space-y-3 md:space-y-4">
                                <!-- Stats Cards -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4">
                                    <div class="bg-white rounded-lg p-3 md:p-4 text-center border">
                                        <div class="text-2xl md:text-3xl font-bold text-blue-600">${stat.total_penilaian}</div>
                                        <div class="text-xs md:text-sm text-gray-600 mt-1">Total Penilaian</div>
                                    </div>
                                    <div class="bg-white rounded-lg p-3 md:p-4 text-center border">
                                        <div class="text-2xl md:text-3xl font-bold text-green-600">${Math.round(stat.rata_skor || 0)}/50</div>
                                        <div class="text-xs md:text-sm text-gray-600 mt-1">Rata-rata Skor</div>
                                    </div>
                                    <div class="bg-white rounded-lg p-3 md:p-4 text-center border">
                                        <div class="text-2xl md:text-3xl font-bold ${badgeClass} text-white p-2 md:p-2 rounded">${kategori}</div>
                                        <div class="text-xs md:text-sm text-gray-600 mt-1">Kategori Terakhir</div>
                                    </div>
                                </div>
                                
                                <!-- Progress Bar -->
                                <div class="mt-4 md:mt-6">
                                    <div class="flex justify-between mb-1 md:mb-2">
                                        <span class="text-xs md:text-sm font-medium text-gray-700">Rata-rata Persentase</span>
                                        <span class="text-xs md:text-sm font-medium text-gray-700">${Math.round(stat.rata_persentase || 0)}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 md:h-4">
                                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 md:h-4 rounded-full" 
                                            style="width: ${stat.rata_persentase || 0}%">
                                        </div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-500 mt-1">
                                        <span>0%</span>
                                        <span>50%</span>
                                        <span>100%</span>
                                    </div>
                                </div>
                            </div>
                            ` : `
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-clipboard-list text-3xl md:text-4xl text-gray-300 mb-3"></i>
                                <p class="text-gray-600 text-base md:text-lg">Belum ada data penilaian</p>
                                <p class="text-gray-500 text-sm md:text-base mt-1">Anak ini belum mendapatkan penilaian dari guru</p>
                            </div>
                            `}
                        </div>
                    </div>
                `;
            }
        }

        // FUNGSI UTAMA UNTUK DETAIL PENILAIAN
        function showDetailPenilaian(penilaianId) {
            const modal = document.getElementById('detailPenilaianModal');
            const content = document.getElementById('detailPenilaianContent');

            // Close mobile menu if open
            if (mobileMenu) {
                mobileMenu.classList.remove('menu-open');
            }
            if (menuOverlay) {
                menuOverlay.classList.remove('active');
            }
            document.body.style.overflow = 'hidden';

            content.innerHTML = `
                <div class="text-center py-8 md:py-12">
                    <div class="animate-spin rounded-full h-12 w-12 md:h-16 md:w-16 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-3 md:mt-4 text-gray-600 text-sm md:text-lg">Memuat detail penilaian...</p>
                </div>
            `;

            modal.style.display = 'block';

            const url = window.location.href.split('?')[0] + `?action=get_detail_penilaian&id=${penilaianId}`;

            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.data) {
                        displayPenilaianData(data.data);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-exclamation-circle text-3xl md:text-4xl text-yellow-500 mb-3 md:mb-4"></i>
                                <h3 class="text-lg md:text-xl font-bold text-gray-800 mb-2">Data Tidak Ditemukan</h3>
                                <p class="text-gray-600 text-sm md:text-base">${data.message || 'Detail penilaian tidak tersedia'}</p>
                                <button onclick="closeModal('detailPenilaianModal')" 
                                        class="mt-3 md:mt-4 px-3 md:px-4 py-2 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700">
                                    Tutup
                                </button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch penilaian error:', error);
                    content.innerHTML = `
                        <div class="text-center py-6 md:py-8">
                            <i class="fas fa-exclamation-triangle text-3xl md:text-4xl text-red-500 mb-3 md:mb-4"></i>
                            <h3 class="text-lg md:text-xl font-bold text-gray-800 mb-2">Kesalahan</h3>
                            <p class="text-gray-600 text-sm md:text-base">${error.message}</p>
                            <div class="mt-3 md:mt-4 space-x-2">
                                <button onclick="showDetailPenilaian(${penilaianId})" 
                                        class="px-3 md:px-4 py-2 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-redo mr-1 md:mr-2"></i> Coba Lagi
                                </button>
                                <button onclick="closeModal('detailPenilaianModal')" 
                                        class="px-3 md:px-4 py-2 border border-gray-300 text-gray-700 text-sm md:text-base rounded-lg hover:bg-gray-50">
                                    Tutup
                                </button>
                            </div>
                        </div>
                    `;
                });

            // Fungsi untuk menampilkan data penilaian
            function displayPenilaianData(d) {
                const kategori = d.kategori || 'Belum dinilai';
                let kategoriClass = 'bg-gray-500';
                if (kategori.includes('Sangat Baik')) kategoriClass = 'bg-green-500';
                else if (kategori.includes('Baik')) kategoriClass = 'bg-blue-500';
                else if (kategori.includes('Cukup')) kategoriClass = 'bg-yellow-500';
                else if (kategori.includes('Kurang')) kategoriClass = 'bg-red-500';

                // List indikator (hanya 5 indikator)
                const indicators = [
                    { name: 'Kemauan Belajar', key: 'willingness_learn', value: d.willingness_learn || 0 },
                    { name: 'Konsentrasi', key: 'concentration', value: d.concentration || 0 },
                    { name: 'Berpikir Kritis', key: 'critical_thinking', value: d.critical_thinking || 0 },
                    { name: 'Kemandirian', key: 'independence', value: d.independence || 0 },
                    { name: 'Pemecahan Masalah', key: 'problem_solving', value: d.problem_solving || 0 }
                ];

                content.innerHTML = `
                    <div class="space-y-4 md:space-y-6">
                        <!-- Header -->
                        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white p-4 md:p-6 rounded-lg">
                            <h2 class="text-xl md:text-2xl font-bold mb-2">Detail Penilaian</h2>
                            <div class="space-y-1 md:space-y-2">
                                <p class="text-sm md:text-base"><i class="fas fa-user-graduate mr-2 md:mr-3"></i> ${d.nama_siswa || 'Nama tidak tersedia'}</p>
                                <p class="text-sm md:text-base"><i class="fas fa-calendar-alt mr-2 md:mr-3"></i> ${d.tanggal_format || 'Tanggal tidak tersedia'}</p>
                                <p class="text-sm md:text-base"><i class="fas fa-user-tie mr-2 md:mr-3"></i> ${d.nama_guru || 'Guru tidak tersedia'}</p>
                                ${d.nama_pelajaran ? `<p class="text-sm md:text-base"><i class="fas fa-book mr-2 md:mr-3"></i> ${d.nama_pelajaran}</p>` : ''}
                                ${d.kelas_bimbel ? `<p class="text-sm md:text-base"><i class="fas fa-chalkboard-teacher mr-2 md:mr-3"></i> ${d.kelas_bimbel}</p>` : ''}
                            </div>
                        </div>
                        
                        <!-- Total Score -->
                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg p-4 md:p-6 text-center">
                            <h3 class="text-lg md:text-xl font-semibold text-gray-800 mb-3 md:mb-4">Total Skor</h3>
                            <div class="text-4xl md:text-6xl font-bold text-green-600 mb-2">${d.total_score || 0}/50</div>
                            <div class="inline-block px-4 md:px-6 py-1 md:py-2 rounded-full text-white font-bold ${kategoriClass} text-base md:text-lg">
                                ${kategori} (${d.persentase || 0}%)
                            </div>
                        </div>
                        
                        <!-- Indicators -->
                        <div>
                            <h3 class="text-lg md:text-xl font-semibold text-gray-800 mb-3 md:mb-4">Detail Indikator</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
                                ${indicators.map(ind => {
                    const value = ind.value || 0;
                    const percentage = (value / 10) * 100;
                    let barColor = 'bg-green-500';
                    if (percentage < 40) barColor = 'bg-red-500';
                    else if (percentage < 70) barColor = 'bg-yellow-500';

                    return `
                                        <div class="bg-white border border-gray-200 rounded-lg p-3 md:p-4 hover:shadow-md transition">
                                            <div class="flex justify-between items-center mb-1 md:mb-2">
                                                <span class="font-medium text-gray-700 text-sm md:text-base">${ind.name}</span>
                                                <span class="font-bold text-gray-900 text-sm md:text-base">${value}/10</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2 md:h-2.5">
                                                <div class="h-2 md:h-2.5 rounded-full ${barColor}" style="width: ${percentage}%"></div>
                                            </div>
                                        </div>
                                        `;
                }).join('')}
                            </div>
                        </div>
                        
                        <!-- Notes -->
                        <div class="space-y-3 md:space-y-4">
                            ${d.catatan_guru ? `
                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-3 md:p-4 rounded">
                                <div class="flex items-center mb-1 md:mb-2">
                                    <i class="fas fa-edit text-yellow-600 mr-2"></i>
                                    <h4 class="font-semibold text-gray-800 text-sm md:text-base">Catatan Guru</h4>
                                </div>
                                <p class="text-gray-700 text-sm md:text-base">${d.catatan_guru}</p>
                            </div>
                            ` : ''}
                            
                            ${d.rekomendasi ? `
                            <div class="bg-blue-50 border-l-4 border-blue-400 p-3 md:p-4 rounded">
                                <div class="flex items-center mb-1 md:mb-2">
                                    <i class="fas fa-lightbulb text-blue-600 mr-2"></i>
                                    <h4 class="font-semibold text-gray-800 text-sm md:text-base">Rekomendasi</h4>
                                </div>
                                <p class="text-gray-700 text-sm md:text-base">${d.rekomendasi}</p>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            }
        }

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
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

        // Fungsi untuk menutup modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Tutup modal saat klik di luar
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };

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