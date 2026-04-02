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

// AMBIL FILTER DARI GET (DIUBAH)
$filter_bulan_tahun = isset($_GET['bulan_tahun']) ? $_GET['bulan_tahun'] : date('Y-m');
$filter_siswa_id = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;
$filter_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_mata_pelajaran = isset($_GET['mata_pelajaran']) ? $_GET['mata_pelajaran'] : '';

// Parse bulan dan tahun dari filter_bulan_tahun
$filter_tahun = !empty($filter_bulan_tahun) ? substr($filter_bulan_tahun, 0, 4) : date('Y');
$filter_bulan = !empty($filter_bulan_tahun) ? (int) substr($filter_bulan_tahun, 5, 2) : 0;

// AMBIL DATA SISWA YANG DIAJAR GURU (berdasarkan jadwal, bukan siswa_pelajaran)
$siswa_list = [];
if ($guru_id > 0) {
    try {
        $sql_siswa = "SELECT DISTINCT
                         s.id,
                         s.nama_lengkap,
                         s.kelas as kelas_sekolah,
                         s.sekolah_asal
                      FROM siswa s
                      INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                      INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                      INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                      WHERE smg.guru_id = ? 
                        AND jb.status = 'aktif'
                        AND ps.status = 'aktif'
                        AND s.status = 'aktif'
                      ORDER BY s.nama_lengkap";

        $stmt = $conn->prepare($sql_siswa);
        $stmt->bind_param("i", $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $siswa_list[] = $row;
        }

        error_log("Siswa untuk guru ID $guru_id: " . count($siswa_list) . " siswa ditemukan");

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
            s.id as siswa_id,
            s.nama_lengkap as nama_siswa,
            s.kelas as kelas_sekolah,
            s.sekolah_asal,
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
        WHERE psn.guru_id = ?";  // Hanya filter guru_id dari penilaian

$params = [$guru_id];
$types = "i";

// FILTER TAHUN DAN BULAN (dari input month)
if (!empty($filter_tahun) && $filter_tahun != 'all') {
    $sql .= " AND YEAR(psn.tanggal_penilaian) = ?";
    $params[] = $filter_tahun;
    $types .= "i";
}

if (!empty($filter_bulan) && $filter_bulan > 0) {
    $sql .= " AND MONTH(psn.tanggal_penilaian) = ?";
    $params[] = $filter_bulan;
    $types .= "i";
}

// FILTER SISWA (menggunakan ID atau nama)
if ($filter_siswa_id > 0) {
    $sql .= " AND psn.siswa_id = ?";
    $params[] = $filter_siswa_id;
    $types .= "i";
} elseif (!empty($filter_nama_siswa)) {
    $sql .= " AND s.nama_lengkap LIKE ?";
    $params[] = "%" . $filter_nama_siswa . "%";
    $types .= "s";
}

// FILTER MATA PELAJARAN
if (!empty($filter_mata_pelajaran)) {
    $sql .= " AND sp.nama_pelajaran = ?";
    $params[] = $filter_mata_pelajaran;
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

// AJAX Handler untuk get detail penilaian (untuk edit)
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_penilaian_for_edit' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $penilaian_id = intval($_GET['id']);
    $penilaian_data = null;

    if ($penilaian_id > 0 && $guru_id > 0) {
        $sql = "SELECT 
                    psn.*,
                    s.nama_lengkap,
                    s.kelas,
                    s.sekolah_asal,
                    sp.nama_pelajaran,
                    ps.tingkat,
                    ps.jenis_kelas
                FROM penilaian_siswa psn
                JOIN siswa s ON psn.siswa_id = s.id
                JOIN siswa_pelajaran sp ON psn.siswa_pelajaran_id = sp.id
                JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                WHERE psn.id = ? AND psn.guru_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $penilaian_id, $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $penilaian_data = $row;
        }
        $stmt->close();
    }

    if ($penilaian_data) {
        echo json_encode(['success' => true, 'data' => $penilaian_data]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Data tidak ditemukan atau Anda tidak memiliki akses']);
    }
    exit();
}

// PROSES UPDATE PENILAIAN
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_penilaian'])) {
    $penilaian_id = intval($_POST['penilaian_id']);
    $tanggal_penilaian = $_POST['tanggal_penilaian'] ?? date('Y-m-d');
    $periode_penilaian = trim($_POST['periode_penilaian'] ?? '');
    $catatan_guru = trim($_POST['catatan_guru'] ?? '');
    $rekomendasi = trim($_POST['rekomendasi'] ?? '');

    // Ambil nilai indikator
    $nilai_fields = [
        'willingness_learn',
        'problem_solving',
        'critical_thinking',
        'concentration',
        'independence'
    ];

    $nilai_values = [];
    $total_score = 0;

    foreach ($nilai_fields as $field) {
        $value = intval($_POST[$field] ?? 1);
        if ($value < 1)
            $value = 1;
        if ($value > 10)
            $value = 10;
        $nilai_values[$field] = $value;
        $total_score += $value;
    }

    $persentase = round(($total_score / 50) * 100);

    if ($persentase >= 80) {
        $kategori = 'Sangat Baik';
    } elseif ($persentase >= 60) {
        $kategori = 'Baik';
    } elseif ($persentase >= 40) {
        $kategori = 'Cukup';
    } else {
        $kategori = 'Kurang';
    }

    // Update query
    $sql = "UPDATE penilaian_siswa SET 
                tanggal_penilaian = ?,
                periode_penilaian = ?,
                willingness_learn = ?,
                problem_solving = ?,
                critical_thinking = ?,
                concentration = ?,
                independence = ?,
                total_score = ?,
                persentase = ?,
                kategori = ?,
                catatan_guru = ?,
                rekomendasi = ?
            WHERE id = ? AND guru_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssiiiiiiisssii",
        $tanggal_penilaian,
        $periode_penilaian,
        $nilai_values['willingness_learn'],
        $nilai_values['problem_solving'],
        $nilai_values['critical_thinking'],
        $nilai_values['concentration'],
        $nilai_values['independence'],
        $total_score,
        $persentase,
        $kategori,
        $catatan_guru,
        $rekomendasi,
        $penilaian_id,
        $guru_id
    );

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "✅ Penilaian berhasil diperbarui!";
        echo json_encode(['success' => true, 'message' => 'Penilaian berhasil diperbarui']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Gagal memperbarui: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// AJAX Handler untuk autocomplete siswa (SAMA SEPERTI DI INPUTNILAI.PHP)
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_siswa_list_riwayat') {
    header('Content-Type: application/json');

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filtered_siswa = [];

    if ($guru_id > 0) {
        $sql_search = "SELECT DISTINCT
                            s.id,
                            s.nama_lengkap,
                            s.kelas as kelas_sekolah,
                            s.sekolah_asal
                       FROM siswa s
                       INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                       INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                       INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                       WHERE smg.guru_id = ? 
                         AND jb.status = 'aktif'
                         AND ps.status = 'aktif'
                         AND s.status = 'aktif'";

        if (!empty($search) && strlen($search) >= 2) {
            $sql_search .= " AND s.nama_lengkap LIKE ?";
            $search_param = "%" . $search . "%";

            $stmt = $conn->prepare($sql_search);
            $stmt->bind_param("is", $guru_id, $search_param);
        } else {
            $sql_search .= " ORDER BY s.nama_lengkap LIMIT 20";
            $stmt = $conn->prepare($sql_search);
            $stmt->bind_param("i", $guru_id);
        }

        if ($stmt) {
            $stmt->execute();
            $result_search = $stmt->get_result();
            while ($row = $result_search->fetch_assoc()) {
                $filtered_siswa[] = $row;
            }
            $stmt->close();
        }
    }

    echo json_encode($filtered_siswa);
    exit();
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        .rating-input-edit {
            width: 70px;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            text-align: center;
            font-size: 16px;
        }

        .rating-input-edit:focus {
            outline: none;
            border-color: #eab308;
            box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.1);
        }

        .indicator-box-edit {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s;
        }

        .indicator-box-edit:hover {
            border-color: #eab308;
            background-color: #fefce8;
        }

        /* Style untuk autocomplete filter siswa */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-input {
            width: 100%;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
            margin-top: 4px;
        }

        .autocomplete-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover {
            background-color: #f9fafb;
        }

        .autocomplete-item.active {
            background-color: #eff6ff;
        }

        .autocomplete-item .siswa-nama {
            font-weight: 600;
            color: #1f2937;
        }

        .autocomplete-item .siswa-info {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
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

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

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
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Guru</p>
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
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Riwayat Penilaian Siswa</h1>
                    <p class="text-gray-600">Lihat semua penilaian yang telah diinput</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <a href="inputNilai.php"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
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

            <!-- Form Filter (DIUBAH) -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-filter mr-2"></i> Filter Riwayat
                </h3>

                <form method="GET" action="" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-4">
                        <!-- Filter Search Siswa - HANYA MENAMPILKAN SISWA YANG DIAJAR GURU INI -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cari Siswa</label>
                            <div class="relative">
                                <input type="text" id="filterSearchSiswa"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 pl-8 text-sm focus:ring-2 focus:ring-blue-500"
                                    placeholder="Ketik nama siswa..." autocomplete="off"
                                    value="<?php echo htmlspecialchars($filter_nama_siswa); ?>">
                                <input type="hidden" name="siswa_id" id="filterSiswaId"
                                    value="<?php echo $filter_siswa_id; ?>">
                                <input type="hidden" name="nama_siswa" id="filterNamaSiswa"
                                    value="<?php echo htmlspecialchars($filter_nama_siswa); ?>">
                                <i
                                    class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                                <button type="button" id="clearFilterSearch"
                                    class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 <?php echo ($filter_siswa_id > 0 || !empty($filter_nama_siswa)) ? '' : 'hidden'; ?>">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div id="filterSiswaDropdown"
                                class="absolute z-50 w-full md:w-96 bg-white mt-1 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                            </div>
                            <?php if (count($siswa_list) > 0): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-users mr-1"></i>
                                    <?php echo count($siswa_list); ?> siswa yang Anda ajar
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Filter Bulan & Tahun (pakai input month / kalender) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Periode (Bulan/Tahun)</label>
                            <input type="month" name="bulan_tahun" value="<?php echo $filter_bulan_tahun; ?>"
                                onchange="this.form.submit()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
                        </div>
                    </div>

                    <div
                        class="flex flex-col md:flex-row justify-between items-start md:items-center pt-4 border-t gap-3 md:gap-0">
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
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tanggal</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Siswa</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Mata Pelajaran & Kelas</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Total & Kategori</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
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
                                                <div
                                                    class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
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
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="showDetail(<?php echo $penilaian['id']; ?>)"
                                                    class="text-blue-600 hover:text-blue-900" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="openEditPenilaianModal(<?php echo $penilaian['id']; ?>)"
                                                    class="text-yellow-600 hover:text-yellow-900" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button
                                                    onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')"
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
                                            <div
                                                class="flex-shrink-0 h-8 w-8 bg-blue-100 rounded-full flex items-center justify-center mr-2">
                                                <span class="text-blue-800 font-medium text-xs">
                                                    <?php echo strtoupper(substr($penilaian['nama_siswa'], 0, 1)); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <div><?php echo htmlspecialchars($penilaian['nama_siswa']); ?></div>
                                                <div class="text-xs text-gray-500">Kelas:
                                                    <?php echo $penilaian['kelas_sekolah']; ?>
                                                </div>
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
                                            <button onclick="openEditPenilaianModal(<?php echo $penilaian['id']; ?>)"
                                                class="flex-1 px-3 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 text-xs flex items-center justify-center">
                                                <i class="fas fa-edit mr-2"></i> Edit
                                            </button>
                                            <button
                                                onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')"
                                                class="flex-1 px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-xs flex items-center justify-center">
                                                <i class="fas fa-trash-alt mr-2"></i> Hapus
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

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

    <!-- MODAL EDIT PENILAIAN -->
    <div id="editPenilaianModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%);">
                <h2 class="text-xl font-bold"><i class="fas fa-edit mr-2"></i> Edit Penilaian</h2>
                <span class="close" onclick="closeEditPenilaianModal()">&times;</span>
            </div>
            <div class="modal-body" id="editModalContent">
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-yellow-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Memuat data penilaian...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEditPenilaianModal()"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    Batal
                </button>
                <button type="button" onclick="submitEditPenilaian()"
                    class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL PENILAIAN -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold"><i class="fas fa-eye mr-2"></i> Detail Penilaian</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalContent">
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

        // ==================== EDIT PENILAIAN ====================
        let currentEditPenilaianId = null;

        function openEditPenilaianModal(penilaianId) {
            currentEditPenilaianId = penilaianId;
            const modal = document.getElementById('editPenilaianModal');
            const content = document.getElementById('editModalContent');

            // Close mobile menu if open
            if (mobileMenu) mobileMenu.classList.remove('menu-open');
            if (menuOverlay) menuOverlay.classList.remove('active');

            content.innerHTML = `
        <div class="text-center py-8">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-yellow-600 mx-auto"></div>
            <p class="mt-4 text-gray-600">Memuat data penilaian...</p>
        </div>
    `;

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Fetch data penilaian
            fetch(`riwayat.php?ajax=get_penilaian_for_edit&id=${penilaianId}`)
                .then(response => response.json())
                .then(responseData => {
                    if (responseData.success) {
                        content.innerHTML = renderEditForm(responseData.data);
                        attachEditRatingEvents();
                        updateEditPreview();
                    } else {
                        content.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-5xl mb-4"></i>
                        <p class="text-lg font-medium mb-2">Gagal Memuat Data</p>
                        <p class="text-sm">${responseData.error || 'Terjadi kesalahan saat memuat data'}</p>
                        <button onclick="closeEditPenilaianModal()" 
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
                    <button onclick="closeEditPenilaianModal()" 
                        class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                        Tutup
                    </button>
                </div>
            `;
                });
        }

        function renderEditForm(data) {
            // Tentukan warna preview kategori
            let previewColor = '';
            let persentase = data.persentase || Math.round((data.total_score / 50) * 100);

            if (persentase >= 80) previewColor = 'text-green-600';
            else if (persentase >= 60) previewColor = 'text-blue-600';
            else if (persentase >= 40) previewColor = 'text-yellow-600';
            else previewColor = 'text-red-600';

            return `
        <form id="formEditPenilaian">
            <input type="hidden" name="penilaian_id" value="${data.id}">
            
            <!-- Informasi Siswa -->
            <div class="bg-blue-50 p-4 rounded-lg mb-4">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800">${escapeHtml(data.nama_lengkap)}</h3>
                        <p class="text-gray-600">Kelas: ${escapeHtml(data.kelas)}</p>
                        <p class="text-gray-600">Sekolah: ${escapeHtml(data.sekolah_asal || '-')}</p>
                    </div>
                    <div class="mt-2 md:mt-0">
                        <span class="px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-600">
                            ${escapeHtml(data.nama_pelajaran)} - ${data.tingkat} (${data.jenis_kelas})
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-1">Tanggal Penilaian *</label>
                    <input type="date" name="tanggal_penilaian" id="editTanggalPenilaian" 
                        value="${data.tanggal_penilaian}" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                </div>
                <div>
                    <label class="block text-gray-700 font-medium mb-1">Periode Penilaian</label>
                    <input type="text" name="periode_penilaian" id="editPeriodePenilaian"
                        value="${escapeHtml(data.periode_penilaian || '')}"
                        placeholder="Contoh: April 2026"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500">
                </div>
            </div>
            
            <!-- Indikator Penilaian -->
            <h4 class="text-md font-semibold text-gray-800 mb-3">Detail Indikator Penilaian</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                ${renderEditIndicator('Willingness to Learn', 'willingness_learn', data.willingness_learn)}
                ${renderEditIndicator('Problem Solving', 'problem_solving', data.problem_solving)}
                ${renderEditIndicator('Critical Thinking', 'critical_thinking', data.critical_thinking)}
                ${renderEditIndicator('Concentration', 'concentration', data.concentration)}
                ${renderEditIndicator('Independence', 'independence', data.independence)}
            </div>
            
            <!-- Preview Total Skor -->
            <div class="bg-gray-50 p-4 rounded-lg mb-4">
                <h4 class="text-md font-semibold text-gray-800 mb-3">Preview Penilaian</h4>
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <p class="text-sm text-gray-600">Total Skor</p>
                        <p id="editTotalSkorPreview" class="text-2xl font-bold text-blue-600">${data.total_score}</p>
                        <p class="text-xs text-gray-500">dari 50</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Persentase</p>
                        <p id="editPersentasePreview" class="text-2xl font-bold text-green-600">${persentase}%</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Kategori</p>
                        <p id="editKategoriPreview" class="text-xl font-bold ${previewColor}">${data.kategori || '-'}</p>
                    </div>
                </div>
            </div>
            
            <!-- Catatan dan Rekomendasi -->
            <div>
                <label class="block text-gray-700 font-medium mb-1">Catatan Guru</label>
                <textarea name="catatan_guru" id="editCatatanGuru" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500"
                    placeholder="Catatan khusus tentang perkembangan siswa...">${escapeHtml(data.catatan_guru || '')}</textarea>
            </div>
            
            <div class="mt-3">
                <label class="block text-gray-700 font-medium mb-1">Rekomendasi</label>
                <textarea name="rekomendasi" id="editRekomendasi" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500"
                    placeholder="Rekomendasi untuk perbaikan berikutnya...">${escapeHtml(data.rekomendasi || '')}</textarea>
            </div>
        </form>
    `;
        }

        function renderEditIndicator(label, fieldName, value) {
            const percentage = (value / 10) * 100;
            let color = '';
            if (value >= 9) color = 'bg-green-500';
            else if (value >= 7) color = 'bg-blue-500';
            else if (value >= 5) color = 'bg-yellow-500';
            else color = 'bg-red-500';

            return `
        <div class="indicator-box-edit">
            <div class="flex justify-between items-center mb-2">
                <label class="font-medium text-gray-700 text-sm">${label}</label>
                <input type="number" name="${fieldName}" min="1" max="10" step="1" value="${value}"
                    class="rating-input-edit" oninput="validateEditRating(this); updateEditPreview();">
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="${color} h-2 rounded-full" style="width: ${percentage}%"></div>
            </div>
            <p class="text-xs text-gray-500 mt-1">Skala 1-10</p>
        </div>
    `;
        }

        function validateEditRating(input) {
            let value = parseInt(input.value) || 5;
            if (value < 1) value = 1;
            if (value > 10) value = 10;
            input.value = value;
        }

        function attachEditRatingEvents() {
            document.querySelectorAll('.rating-input-edit').forEach(input => {
                input.addEventListener('input', function () {
                    validateEditRating(this);
                    updateEditPreview();
                });
            });

            // Also listen to date and periode changes if needed
            const tanggalInput = document.getElementById('editTanggalPenilaian');
            if (tanggalInput) {
                // No preview update needed for date
            }
        }

        function updateEditPreview() {
            const fields = ['willingness_learn', 'problem_solving', 'critical_thinking', 'concentration', 'independence'];
            let total = 0;

            fields.forEach(field => {
                const input = document.querySelector(`input[name="${field}"]`);
                if (input) {
                    total += parseInt(input.value) || 0;
                }
            });

            const persentase = Math.round((total / 50) * 100);
            let kategori = '-';
            let kategoriColor = 'text-gray-600';

            if (persentase >= 80) {
                kategori = 'Sangat Baik';
                kategoriColor = 'text-green-600';
            } else if (persentase >= 60) {
                kategori = 'Baik';
                kategoriColor = 'text-blue-600';
            } else if (persentase >= 40) {
                kategori = 'Cukup';
                kategoriColor = 'text-yellow-600';
            } else if (persentase > 0) {
                kategori = 'Kurang';
                kategoriColor = 'text-red-600';
            }

            const totalElement = document.getElementById('editTotalSkorPreview');
            const persenElement = document.getElementById('editPersentasePreview');
            const kategoriElement = document.getElementById('editKategoriPreview');

            if (totalElement) totalElement.textContent = total;
            if (persenElement) persenElement.textContent = persentase + '%';
            if (kategoriElement) {
                kategoriElement.textContent = kategori;
                kategoriElement.className = `text-xl font-bold ${kategoriColor}`;
            }
        }

        function submitEditPenilaian() {
            const form = document.getElementById('formEditPenilaian');
            if (!form) return;

            const formData = new FormData(form);
            formData.append('update_penilaian', '1');

            const submitBtn = document.querySelector('#editPenilaianModal .modal-footer button:last-child');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Menyimpan...';
            submitBtn.disabled = true;

            fetch('riwayat.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', data.message);
                        setTimeout(() => {
                            closeEditPenilaianModal();
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification('error', data.error || 'Gagal menyimpan perubahan');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    showNotification('error', 'Terjadi kesalahan: ' + error.message);
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        function closeEditPenilaianModal() {
            const modal = document.getElementById('editPenilaianModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            currentEditPenilaianId = null;
        }

        // ==================== FILTER SEARCH SISWA (SAMA SEPERTI DI INPUTNILAI.PHP) ====================
        let filterSearchTimeout;
        let filterSelectedIndex = -1;

        function initFilterSearch() {
            const searchInput = document.getElementById('filterSearchSiswa');
            const clearButton = document.getElementById('clearFilterSearch');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');

            if (!searchInput || !clearButton || !dropdown || !siswaIdInput || !namaSiswaInput) return;

            searchInput.addEventListener('input', function () {
                clearTimeout(filterSearchTimeout);
                const query = this.value.trim();

                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    clearButton.classList.add('hidden');
                    return;
                }

                filterSearchTimeout = setTimeout(() => {
                    searchSiswaViaAjax(query);
                }, 300);
                clearButton.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', function () {
                if (this.value.length >= 2) {
                    searchSiswaViaAjax(this.value);
                }
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                siswaIdInput.value = '';
                namaSiswaInput.value = '';
                clearButton.classList.add('hidden');
                dropdown.classList.add('hidden');
                // Submit form untuk reset filter
                document.querySelector('form[method="GET"]').submit();
            });

            // Keyboard navigation
            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.filter-siswa-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        filterSelectedIndex = Math.min(filterSelectedIndex + 1, items.length - 1);
                        updateFilterSelectedItem(items);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        filterSelectedIndex = Math.max(filterSelectedIndex - 1, -1);
                        updateFilterSelectedItem(items);
                        break;
                    case 'Enter':
                        e.preventDefault();
                        if (filterSelectedIndex >= 0 && items[filterSelectedIndex]) {
                            selectFilterSiswa(items[filterSelectedIndex].dataset);
                        }
                        break;
                    case 'Escape':
                        dropdown.classList.add('hidden');
                        filterSelectedIndex = -1;
                        break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        function searchSiswaViaAjax(query) {
            const dropdown = document.getElementById('filterSiswaDropdown');

            dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center"><span class="spinner"></span> Mencari...</div>';
            dropdown.classList.remove('hidden');

            $.ajax({
                url: window.location.href,
                type: 'GET',
                data: {
                    ajax: 'get_siswa_list_riwayat',
                    search: query
                },
                dataType: 'json',
                success: function (data) {
                    renderFilterDropdown(data);
                },
                error: function () {
                    dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Gagal memuat data</div>';
                }
            });
        }

        function renderFilterDropdown(data) {
            const dropdown = document.getElementById('filterSiswaDropdown');
            if (!dropdown) return;

            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Tidak ada siswa ditemukan</div>';
                dropdown.classList.remove('hidden');
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'filter-siswa-item px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                if (index === 0) item.classList.add('bg-blue-50');
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas_sekolah || '-';
                item.dataset.sekolah = siswa.sekolah_asal || '-';

                item.innerHTML = `
            <div class="font-medium text-gray-900">${escapeHtml(siswa.nama_lengkap)}</div>
            <div class="text-xs text-gray-600 mt-1">
                Kelas: ${escapeHtml(siswa.kelas_sekolah || '-')} | Sekolah: ${escapeHtml(siswa.sekolah_asal || '-')}
            </div>
        `;

                item.addEventListener('click', function () {
                    selectFilterSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.filter-siswa-item');
                    filterSelectedIndex = Array.from(items).indexOf(this);
                    updateFilterSelectedItem(items);
                });

                dropdown.appendChild(item);
            });

            dropdown.classList.remove('hidden');
            filterSelectedIndex = 0;
        }

        function updateFilterSelectedItem(items) {
            items.forEach((item, i) => {
                if (i === filterSelectedIndex) {
                    item.classList.add('bg-blue-50');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('bg-blue-50');
                }
            });
        }

        function selectFilterSiswa(data) {
            const searchInput = document.getElementById('filterSearchSiswa');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const clearButton = document.getElementById('clearFilterSearch');

            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            namaSiswaInput.value = data.nama;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');

            // Submit form untuk filter
            document.querySelector('form[method="GET"]').submit();
        }


        // ==================== DETAIL PENILAIAN ====================
        let currentPenilaianId = null;
        let penilaianToDelete = null;
        let studentNameToDelete = '';

        function showDetail(penilaianId) {
            currentPenilaianId = penilaianId;
            const modal = document.getElementById('detailModal');
            const content = document.getElementById('modalContent');

            if (mobileMenu) mobileMenu.classList.remove('menu-open');
            if (menuOverlay) menuOverlay.classList.remove('active');

            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Memuat data penilaian...</p>
                </div>
            `;

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            fetch(`get_penilaian_detail.php?id=${penilaianId}`)
                .then(response => response.json())
                .then(responseData => {
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
                            <button onclick="closeModal()" 
                                class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                Tutup
                            </button>
                        </div>
                    `;
                });
        }

        function renderDetailContent(data) {
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
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div>
                                <h3 class="text-xl font-bold text-gray-800">${escapeHtml(safeData.nama_siswa)}</h3>
                                <p class="text-gray-600">Kelas: ${escapeHtml(safeData.kelas_sekolah)}</p>
                                <p class="text-gray-600">${escapeHtml(safeData.tanggal_format)}</p>
                            </div>
                            <div class="mt-2 md:mt-0">
                                <span class="px-3 py-1 rounded-full text-sm font-medium ${kategoriBgClass} ${kategoriClass}">
                                    ${escapeHtml(safeData.kategori)}
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

                    <div class="text-center p-4 md:p-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg">
                        <h4 class="text-lg font-semibold text-gray-700 mb-2">Total Skor</h4>
                        <div class="text-4xl md:text-5xl font-bold text-blue-600">${safeData.total_score}/50</div>
                        <div class="mt-2 text-gray-600 text-sm md:text-base">Nilai rata-rata per indikator: ${(safeData.total_score / 5).toFixed(1)}/10</div>
                    </div>

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

                    ${safeData.catatan_guru ? `
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">Catatan Guru</h4>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                            <p class="text-gray-700">${escapeHtml(safeData.catatan_guru)}</p>
                        </div>
                    </div>
                    ` : ''}

                    ${safeData.rekomendasi ? `
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800 mb-2">Rekomendasi</h4>
                        <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded">
                            <p class="text-gray-700">${escapeHtml(safeData.rekomendasi)}</p>
                        </div>
                    </div>
                    ` : ''}

                    <div class="text-xs text-gray-400 border-t pt-2">
                        <p>ID Penilaian: ${data.id}</p>
                    </div>
                </div>
            `;
        }

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

        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return String(unsafe)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function closeModal() {
            const modal = document.getElementById('detailModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            currentPenilaianId = null;
        }

        // ==================== HAPUS PENILAIAN ====================
        function confirmDelete(penilaianId, studentName) {
            penilaianToDelete = penilaianId;
            studentNameToDelete = studentName;

            if (mobileMenu) mobileMenu.classList.remove('menu-open');
            if (menuOverlay) menuOverlay.classList.remove('active');

            const deleteStudentName = document.getElementById('deleteStudentName');
            if (deleteStudentName) {
                deleteStudentName.textContent = `Hapus Penilaian ${studentName}?`;
            }

            const modal = document.getElementById('deleteModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            penilaianToDelete = null;
            studentNameToDelete = '';
        }

        function deletePenilaian() {
            if (!penilaianToDelete) return;

            const deleteBtn = document.getElementById('deleteConfirmBtn');
            const originalText = deleteBtn.innerHTML;

            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Menghapus...';
            deleteBtn.disabled = true;

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
                        showNotification('success', data.message);
                        setTimeout(() => {
                            closeDeleteModal();
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification('error', data.message);
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

        function showNotification(type, message) {
            const oldNotif = document.getElementById('notification');
            if (oldNotif) oldNotif.remove();

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

            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
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

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modal = document.getElementById('detailModal');
            const deleteModal = document.getElementById('deleteModal');
            const editModal = document.getElementById('editPenilaianModal');

            if (event.target === modal) {
                closeModal();
            }

            if (event.target === deleteModal) {
                closeDeleteModal();
            }
            if (event.target === editModal) {
                closeEditPenilaianModal();
            }
        }

        // Initialize
        $(document).ready(function () {
            initFilterSearch();
        });
    </script>
</body>

</html>