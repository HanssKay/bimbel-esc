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
// GET MAX SESI DARI DATABASE
// ============================================
function getMaxSesi($conn, $tanggal, $guru_id) {
    $sql = "SELECT MAX(sesi_ke) as max_sesi FROM absensi_siswa WHERE tanggal_absensi = ? AND guru_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $tanggal, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['max_sesi'] ?? 0;
}

// ============================================
// HANDLE COPY ABSENSI DARI SESI LAIN
// ============================================
if (isset($_GET['ajax_copy_absensi']) && isset($_GET['tanggal']) && isset($_GET['guru_id'])) {
    header('Content-Type: application/json');
    
    $tanggal = $_GET['tanggal'];
    $guru_id = intval($_GET['guru_id']);
    $source_sesi = isset($_GET['source_sesi']) ? intval($_GET['source_sesi']) : 1;
    $target_sesi = isset($_GET['target_sesi']) ? intval($_GET['target_sesi']) : 2;
    
    try {
        $conn->begin_transaction();
        
        // Ambil data absensi dari source sesi
        $sql_select = "SELECT siswa_id, pendaftaran_id, siswa_pelajaran_id, status, keterangan 
                       FROM absensi_siswa 
                       WHERE tanggal_absensi = ? AND guru_id = ? AND sesi_ke = ?";
        $stmt = $conn->prepare($sql_select);
        $stmt->bind_param("sii", $tanggal, $guru_id, $source_sesi);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $insert_count = 0;
        while ($row = $result->fetch_assoc()) {
            // Cek apakah sudah ada di target sesi
            $sql_check = "SELECT id FROM absensi_siswa 
                         WHERE siswa_id = ? AND guru_id = ? AND tanggal_absensi = ? AND sesi_ke = ?";
            $check_stmt = $conn->prepare($sql_check);
            $check_stmt->bind_param("iisi", $row['siswa_id'], $guru_id, $tanggal, $target_sesi);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                // Insert ke target sesi
                $sql_insert = "INSERT INTO absensi_siswa 
                              (siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, tanggal_absensi, sesi_ke, status, keterangan, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($sql_insert);
                $insert_stmt->bind_param("iiiisiss", 
                    $row['siswa_id'], 
                    $row['pendaftaran_id'], 
                    $row['siswa_pelajaran_id'], 
                    $guru_id, 
                    $tanggal, 
                    $target_sesi, 
                    $row['status'], 
                    $row['keterangan']
                );
                $insert_stmt->execute();
                $insert_count++;
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        $stmt->close();
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Berhasil menyalin $insert_count data absensi dari Sesi $source_sesi ke Sesi $target_sesi"]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// HANDLE DELETE SESI
// ============================================
if (isset($_GET['ajax_delete_sesi']) && isset($_GET['tanggal']) && isset($_GET['guru_id']) && isset($_GET['sesi'])) {
    header('Content-Type: application/json');
    
    $tanggal = $_GET['tanggal'];
    $guru_id = intval($_GET['guru_id']);
    $sesi_hapus = intval($_GET['sesi']);
    
    try {
        $sql_delete = "DELETE FROM absensi_siswa WHERE tanggal_absensi = ? AND guru_id = ? AND sesi_ke = ?";
        $stmt = $conn->prepare($sql_delete);
        $stmt->bind_param("sii", $tanggal, $guru_id, $sesi_hapus);
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        $stmt->close();
        
        echo json_encode(['success' => true, 'message' => "Berhasil menghapus $deleted_count data absensi dari Sesi $sesi_hapus"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

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
            AND s.nama_lengkap LIKE ?
            ORDER BY s.nama_lengkap
            LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $search_term = "%{$query}%";
        $stmt->bind_param("s", $search_term);
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
// MAIN PAGE LOGIC
// ============================================

// Tanggal default hari ini
$tanggal = date('Y-m-d');
if (isset($_GET['tanggal']) && !empty($_GET['tanggal'])) {
    $tanggal = $_GET['tanggal'];
}

// Sesi (default 1, tidak ada batasan maksimal)
$sesi = isset($_GET['sesi']) ? intval($_GET['sesi']) : 1;
if ($sesi < 1) $sesi = 1;

// Filter pencarian
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Filter kelas
$filter_kelas = isset($_GET['kelas']) ? trim($_GET['kelas']) : '';

// Cek apakah ada parameter sukses
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = "Absensi berhasil disimpan!";
}

// ============================================
// PROSES SIMPAN ABSENSI
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_absensi'])) {
    try {
        $conn->begin_transaction();
        
        $tanggal_absensi = $_POST['tanggal'] ?? date('Y-m-d');
        $sesi_absensi = isset($_POST['sesi']) ? intval($_POST['sesi']) : 1;
        $siswa_data = $_POST['siswa'] ?? [];
        
        foreach ($siswa_data as $siswa_id => $data) {
            $status = $data['status'] ?? '';
            $keterangan = $data['keterangan'] ?? '';
            $siswa_pelajaran_id = !empty($data['siswa_pelajaran_id']) ? intval($data['siswa_pelajaran_id']) : null;
            
            if (empty($status)) {
                continue;
            }
            
            // Cek apakah sudah ada absensi untuk sesi ini
            $check_sql = "SELECT id FROM absensi_siswa 
                         WHERE siswa_id = ? 
                         AND guru_id = ?
                         AND tanggal_absensi = ?
                         AND sesi_ke = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iisi", $siswa_id, $guru_id, $tanggal_absensi, $sesi_absensi);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            // Ambil pendaftaran_id
            $pendaftaran_sql = "SELECT id as pendaftaran_id 
                               FROM pendaftaran_siswa 
                               WHERE siswa_id = ? 
                               AND status = 'aktif'
                               LIMIT 1";
            $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
            $pendaftaran_stmt->bind_param("i", $siswa_id);
            $pendaftaran_stmt->execute();
            $pendaftaran_result = $pendaftaran_stmt->get_result();
            $pendaftaran_data = $pendaftaran_result->fetch_assoc();
            $pendaftaran_id = $pendaftaran_data['pendaftaran_id'] ?? 0;
            $pendaftaran_stmt->close();
            
            if ($pendaftaran_id == 0) {
                $check_stmt->close();
                continue;
            }
            
            if ($check_result->num_rows > 0) {
                // Update existing
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
                // Insert new
                $insert_sql = "INSERT INTO absensi_siswa 
                              (siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, tanggal_absensi, sesi_ke, status, keterangan, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiiisiss",
                    $siswa_id,
                    $pendaftaran_id,
                    $siswa_pelajaran_id,
                    $guru_id,
                    $tanggal_absensi,
                    $sesi_absensi,
                    $status,
                    $keterangan
                );
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        
        $conn->commit();
        
        $params = ["tanggal=" . urlencode($tanggal_absensi), "sesi=" . $sesi_absensi, "success=1"];
        if (!empty($search_query)) {
            $params[] = "search=" . urlencode($search_query);
        }
        if (!empty($filter_kelas)) {
            $params[] = "kelas=" . urlencode($filter_kelas);
        }
        
        header("Location: absensiSiswa.php?" . implode('&', $params));
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
}

// ============================================
// AMBIL DAFTAR SEMUA SISWA AKTIF
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

// Ambil daftar kelas unik untuk filter
$kelas_list = [];
$sql_kelas = "SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif' ORDER BY kelas";
$result_kelas = $conn->query($sql_kelas);
while ($row = $result_kelas->fetch_assoc()) {
    $kelas_list[] = $row['kelas'];
}

// Query ambil semua siswa aktif
$sql_siswa = "SELECT 
                s.id,
                s.nama_lengkap,
                s.kelas as kelas_sekolah
             FROM siswa s
             INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
             WHERE ps.status = 'aktif'
             AND s.status = 'aktif'";

$params = [];
$types = "";

if (!empty($search_query)) {
    $sql_siswa .= " AND s.nama_lengkap LIKE ?";
    $params[] = "%{$search_query}%";
    $types .= "s";
}

if (!empty($filter_kelas)) {
    $sql_siswa .= " AND s.kelas = ?";
    $params[] = $filter_kelas;
    $types .= "s";
}

$sql_siswa .= " ORDER BY s.nama_lengkap";

$stmt_siswa = $conn->prepare($sql_siswa);
if ($stmt_siswa) {
    if (!empty($params)) {
        $stmt_siswa->bind_param($types, ...$params);
    }
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();
    
    while ($row = $result_siswa->fetch_assoc()) {
        $daftar_siswa[] = $row;
    }
    $stmt_siswa->close();
}

$statistik['total_siswa'] = count($daftar_siswa);

// Ambil data absensi dan mapel untuk setiap siswa
if (!empty($daftar_siswa)) {
    foreach ($daftar_siswa as $key => $siswa) {
        // Ambil absensi untuk tanggal dan sesi tertentu
        $sql_absensi = "SELECT status, keterangan, siswa_pelajaran_id 
                       FROM absensi_siswa 
                       WHERE siswa_id = ? 
                       AND guru_id = ?
                       AND tanggal_absensi = ?
                       AND sesi_ke = ?
                       LIMIT 1";
        $stmt_abs = $conn->prepare($sql_absensi);
        $stmt_abs->bind_param("iisi", $siswa['id'], $guru_id, $tanggal, $sesi);
        $stmt_abs->execute();
        $absensi = $stmt_abs->get_result()->fetch_assoc();
        $stmt_abs->close();
        
        // Update array dengan data absensi
        $daftar_siswa[$key]['status'] = $absensi['status'] ?? '';
        $daftar_siswa[$key]['keterangan'] = $absensi['keterangan'] ?? '';
        $daftar_siswa[$key]['selected_mapel_id'] = $absensi['siswa_pelajaran_id'] ?? '';
        
        // Hitung statistik
        $status = $absensi['status'] ?? '';
        if (!empty($status) && isset($statistik[$status])) {
            $statistik[$status]++;
        }
        
        // Ambil daftar mata pelajaran siswa
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

// Ambil semua sesi yang sudah ada untuk tanggal ini
$existing_sesi = [];
$sql_existing_sesi = "SELECT DISTINCT sesi_ke FROM absensi_siswa WHERE tanggal_absensi = ? AND guru_id = ? ORDER BY sesi_ke";
$stmt_existing = $conn->prepare($sql_existing_sesi);
$stmt_existing->bind_param("si", $tanggal, $guru_id);
$stmt_existing->execute();
$result_existing = $stmt_existing->get_result();
while ($row = $result_existing->fetch_assoc()) {
    $existing_sesi[] = $row['sesi_ke'];
}
$stmt_existing->close();

// ========== FIXED 3 SESI SAJA ==========
$MAX_SESI = 3; // Hanya 3 sesi

// Cek jika sesi yang diakses melebihi 3
if ($sesi > $MAX_SESI) {
    header("Location: absensiSiswa.php?tanggal=" . urlencode($tanggal) . "&sesi=" . $MAX_SESI . "&error=max_sesi");
    exit();
}

// Tampilkan hanya 3 sesi
$max_display_sesi = $MAX_SESI;

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Harian Multi Sesi - Bimbel Esc</title>
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

        /* Sesi Tab Styles */
        .sesi-tab {
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        
        .sesi-tab.active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        
        .sesi-tab.active:hover {
            background-color: #2563eb;
        }
        
        .sesi-tab:not(.active):hover {
            background-color: #f3f4f6;
        }
        
        .sesi-badge {
            transition: all 0.2s ease;
        }
        
        .sesi-badge.completed {
            background-color: #10b981;
            color: white;
        }
        
        .sesi-badge.pending {
            background-color: #f59e0b;
            color: white;
        }

        /* Scrollable tabs container */
        .tabs-container {
            overflow-x: auto;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }
        
        .tabs-container::-webkit-scrollbar {
            height: 4px;
        }
        
        .tabs-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .tabs-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
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

        /* Loading indicator */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0,0,0,.1);
            border-radius: 50%;
            border-top-color: #3b82f6;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Sticky header */
        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Info badge */
        .info-badge {
            background-color: #eff6ff;
            border-left: 4px solid #3b82f6;
        }
        
        /* Dropdown menu for copy */
        .copy-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .copy-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 20;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .copy-dropdown-content a {
            color: black;
            padding: 8px 16px;
            text-decoration: none;
            display: block;
            font-size: 14px;
        }
        
        .copy-dropdown-content a:hover {
            background-color: #f3f4f6;
        }
        
        .copy-dropdown:hover .copy-dropdown-content {
            display: block;
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
                        <i class="fas fa-calendar-check mr-2"></i> Absensi Harian Siswa
                    </h1>
                    <p class="text-gray-600">Input absensi siswa </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
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

            <!-- Info Box -->
            <!-- <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-blue-700 text-sm flex items-start">
                <i class="fas fa-info-circle mr-2 mt-0.5"></i>
                <div>
                    <strong>Informasi:</strong> Anda dapat melakukan absensi <strong>banyak sesi</strong> dalam 1 hari untuk setiap siswa.
                    Gunakan tab sesi di bawah untuk berpindah antar sesi. Setiap sesi dapat memilih mata pelajaran yang berbeda.
                    Sistem otomatis akan menampilkan sesi baru saat Anda menyimpan data pada sesi berikutnya.
                </div>
            </div> -->

            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" id="filterForm" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Tanggal Absensi
                        </label>
                        <input type="date" name="tanggal" value="<?php echo $tanggal; ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               id="tanggalInput"
                               onchange="submitForm()">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-layer-group mr-1"></i> Filter Kelas
                        </label>
                        <select name="kelas" id="kelasSelect" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" onchange="submitForm()">
                            <option value="">Semua Kelas</option>
                            <?php foreach ($kelas_list as $kelas): ?>
                                <option value="<?php echo htmlspecialchars($kelas); ?>" 
                                    <?php echo $filter_kelas == $kelas ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($kelas); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-search mr-1"></i> Cari Nama Siswa
                        </label>
                        <div class="search-container">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                                   placeholder="Ketik nama siswa..." 
                                   class="search-input"
                                   id="searchInput"
                                   autocomplete="off"
                                   onkeyup="handleSearch(event)">
                            <?php if (!empty($search_query)): ?>
                                <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>&sesi=<?php echo $sesi; ?><?php echo !empty($filter_kelas) ? '&kelas=' . urlencode($filter_kelas) : ''; ?>" class="clear-search">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                            <i class="fas fa-search search-icon"></i>
                        </div>
                    </div>
                    <input type="hidden" name="sesi" value="<?php echo $sesi; ?>">
                    <div class="flex items-end">
                        <button type="submit" id="submitBtn"
                                class="w-full md:w-auto px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            <i class="fas fa-search mr-2"></i> Tampilkan
                        </button>
                    </div>
                </form>
                
                <?php if (!empty($search_query) || !empty($filter_kelas)): ?>
                    <div class="mt-3 text-sm text-blue-600">
                        <i class="fas fa-filter mr-1"></i> Filter aktif: 
                        <?php if (!empty($search_query)): ?>
                            Pencarian "<?php echo htmlspecialchars($search_query); ?>"
                        <?php endif; ?>
                        <?php if (!empty($filter_kelas)): ?>
                            <?php if (!empty($search_query)): ?> | <?php endif; ?>
                            Kelas "<?php echo htmlspecialchars($filter_kelas); ?>"
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sesi Tabs (Dynamic) -->
            <div class="bg-white shadow rounded-lg mb-6">
                <div class="border-b border-gray-200">
                    <div class="tabs-container">
                        <div class="flex">
                            <?php for ($s = 1; $s <= $max_display_sesi; $s++): 
                                $has_absensi = in_array($s, $existing_sesi);
                                $is_active = ($sesi == $s);
                            ?>
                                <a href="?tanggal=<?php echo urlencode($tanggal); ?>&sesi=<?php echo $s; ?><?php echo !empty($filter_kelas) ? '&kelas=' . urlencode($filter_kelas) : ''; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>"
                                   class="sesi-tab flex-none px-4 py-3 text-center font-medium text-sm rounded-t-lg transition-all <?php echo $is_active ? 'active bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                    <i class="fas fa-clock mr-2"></i> Sesi <?php echo $s; ?>
                                    <?php if ($has_absensi && !$is_active): ?>
                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-200 text-green-800">
                                            <i class="fas fa-check-circle mr-1"></i> Selesai
                                        </span>
                                    <?php endif; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <!-- Tombol untuk refresh daftar sesi -->
                            <button type="button" onclick="refreshSesiList()" 
                                    class="flex-none px-3 py-3 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-t-lg"
                                    title="Refresh daftar sesi">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <?php if (!empty($daftar_siswa)): ?>
                <div class="mb-6 bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-calendar-alt mr-2"></i>
                            Absensi Tanggal: <?php echo date('d F Y', strtotime($tanggal)); ?> - Sesi <?php echo $sesi; ?>
                        </h3>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-users mr-1"></i> <?php echo $statistik['total_siswa']; ?> Siswa
                            </span>
                            <!-- <?php if ($sesi > 1): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                                    <i class="fas fa-copy mr-1"></i> 
                                    <div class="copy-dropdown">
                                        <span class="cursor-pointer">Copy dari sesi lain ▼</span>
                                        <div class="copy-dropdown-content">
                                            <?php for ($s = 1; $s < $sesi; $s++): ?>
                                                <a href="#" onclick="copyFromSesi(<?php echo $s; ?>); return false;">
                                                    Copy dari Sesi <?php echo $s; ?>
                                                </a>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </span>
                            <?php endif; ?> -->
                            <?php if (in_array($sesi, $existing_sesi)): ?>
                                <button type="button" onclick="deleteSesi(<?php echo $sesi; ?>)" 
                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800 hover:bg-red-200">
                                    <i class="fas fa-trash mr-1"></i> Hapus Sesi <?php echo $sesi; ?>
                                </button>
                            <?php endif; ?>
                        </div>
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
                    <div class="px-6 py-4 border-b border-gray-200 sticky-header bg-white rounded-t-lg">
                        <div class="flex justify-between items-center flex-wrap gap-2">
                            <h3 class="text-lg font-medium text-gray-900">
                                <i class="fas fa-list mr-2"></i> Daftar Siswa - Sesi <?php echo $sesi; ?>
                            </h3>
                            <div class="flex items-center space-x-2 flex-wrap gap-2">
                                <button type="button" onclick="setAllStatus('hadir')" 
                                        class="px-3 py-1 bg-green-100 text-green-800 rounded-md hover:bg-green-200 text-sm">
                                    <i class="fas fa-check mr-1"></i>Semua Hadir
                                </button>
                                <button type="button" onclick="setAllStatus('izin')" 
                                        class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-md hover:bg-yellow-200 text-sm">
                                    <i class="fas fa-phone-alt mr-1"></i>Semua Izin
                                </button>
                                <button type="button" onclick="saveAbsensi()" 
                                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm">
                                    <i class="fas fa-save mr-1"></i>Simpan Absensi Sesi <?php echo $sesi; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <form id="formAbsensi" method="POST" style="display: none;">
                        <input type="hidden" name="simpan_absensi" value="1">
                        <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                        <input type="hidden" name="sesi" value="<?php echo $sesi; ?>">
                    </form>
                
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50 sticky-header">
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
                                <?php if (empty($daftar_siswa)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-user-graduate text-4xl mb-2 block"></i>
                                            Tidak ada siswa yang ditemukan
                                        </td>
                                    </tr>
                                <?php else: ?>
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
                                                       placeholder="Alasan (jika izin/sakit)"
                                                       value="<?php echo htmlspecialchars($siswa['keterangan']); ?>"
                                                       data-siswa-id="<?php echo $siswa['id']; ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white shadow rounded-lg p-8 text-center">
                    <div class="mb-4">
                        <i class="fas fa-users text-4xl text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Tidak Ada Siswa</h3>
                    <p class="text-gray-600 mb-4">
                        <?php if (!empty($search_query) || !empty($filter_kelas)): ?>
                            Tidak ditemukan siswa dengan kriteria pencarian
                        <?php else: ?>
                            Belum ada siswa yang terdaftar
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search_query) || !empty($filter_kelas)): ?>
                        <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>&sesi=<?php echo $sesi; ?>" 
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
                    © <?php echo date('Y'); ?> Bimbel Esc - Absensi Harian Multi Sesi
                </p>
            </div>
        </footer>
    </div>

    <script>
        // Data storage untuk absensi
        let absensiData = {};
        let searchTimeout = null;
        
        // Inisialisasi data dari PHP
        <?php foreach ($daftar_siswa as $siswa): ?>
            absensiData[<?php echo $siswa['id']; ?>] = {
                status: '<?php echo $siswa['status']; ?>',
                keterangan: '<?php echo addslashes($siswa['keterangan']); ?>',
                mapel_id: '<?php echo $siswa['selected_mapel_id']; ?>'
            };
        <?php endforeach; ?>

        // Fungsi untuk submit form
        function submitForm() {
            document.getElementById('filterForm').submit();
        }

        // Handle pencarian dengan delay
        function handleSearch(event) {
            if (event.key === 'Enter') {
                submitForm();
                return;
            }
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            searchTimeout = setTimeout(function() {
                submitForm();
            }, 500);
        }

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
            
            Object.values(statusClasses).forEach(cls => {
                select.classList.remove(cls);
            });
            
            select.classList.add(statusClasses[status] || 'status-default');
            
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

        // Copy dari sesi tertentu
        function copyFromSesi(sourceSesi) {
            const currentSesi = <?php echo $sesi; ?>;
            
            if (sourceSesi >= currentSesi) {
                alert('Sesi sumber harus lebih kecil dari sesi saat ini!');
                return;
            }
            
            if (confirm(`Salin data absensi dari Sesi ${sourceSesi} ke Sesi ${currentSesi}? Data yang sudah ada pada sesi ${currentSesi} TIDAK akan ditimpa (hanya data kosong yang akan diisi).`)) {
                // Tampilkan loading
                const btn = event.target;
                const originalText = btn.innerText;
                btn.innerText = 'Loading...';
                btn.disabled = true;
                
                fetch(window.location.href.split('?')[0] + '?ajax_copy_absensi=1&tanggal=<?php echo $tanggal; ?>&guru_id=<?php echo $guru_id; ?>&source_sesi=' + sourceSesi + '&target_sesi=' + currentSesi)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            window.location.reload();
                        } else {
                            alert('Gagal menyalin data: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Terjadi kesalahan saat menyalin data');
                    })
                    .finally(() => {
                        btn.innerText = originalText;
                        btn.disabled = false;
                    });
            }
        }

        // Hapus sesi
        function deleteSesi(sesiHapus) {
            if (confirm(`Apakah Anda yakin ingin menghapus semua data absensi untuk Sesi ${sesiHapus}? Data yang dihapus tidak dapat dikembalikan!`)) {
                const btn = event.target;
                const originalText = btn.innerText;
                btn.innerText = 'Loading...';
                btn.disabled = true;
                
                fetch(window.location.href.split('?')[0] + '?ajax_delete_sesi=1&tanggal=<?php echo $tanggal; ?>&guru_id=<?php echo $guru_id; ?>&sesi=' + sesiHapus)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            window.location.reload();
                        } else {
                            alert('Gagal menghapus data: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Terjadi kesalahan saat menghapus data');
                    })
                    .finally(() => {
                        btn.innerText = originalText;
                        btn.disabled = false;
                    });
            }
        }

        // Refresh daftar sesi
        function refreshSesiList() {
            window.location.reload();
        }

        // Simpan absensi
        function saveAbsensi() {
            const form = document.getElementById('formAbsensi');
            
            while (form.firstChild) {
                form.removeChild(form.firstChild);
            }
            
            const inputs = [
                { name: 'simpan_absensi', value: '1' },
                { name: 'tanggal', value: '<?php echo $tanggal; ?>' },
                { name: 'sesi', value: '<?php echo $sesi; ?>' }
            ];
            
            inputs.forEach(data => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = data.name;
                input.value = data.value;
                form.appendChild(input);
            });
            
            let hasData = false;
            document.querySelectorAll('.status-select').forEach(select => {
                const siswaId = select.getAttribute('data-siswa-id');
                const status = select.value;
                
                if (status) {
                    hasData = true;
                    
                    const statusInput = document.createElement('input');
                    statusInput.type = 'hidden';
                    statusInput.name = `siswa[${siswaId}][status]`;
                    statusInput.value = status;
                    form.appendChild(statusInput);
                    
                    const ketInput = document.createElement('input');
                    ketInput.type = 'hidden';
                    ketInput.name = `siswa[${siswaId}][keterangan]`;
                    ketInput.value = absensiData[siswaId]?.keterangan || '';
                    form.appendChild(ketInput);
                    
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
                        const tArrow = t.querySelector('.arrow');
                        if (tArrow) tArrow.style.transform = 'rotate(0deg)';
                    });
                    
                    submenu.style.display = 'block';
                    if (arrow) arrow.style.transform = 'rotate(-90deg)';
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
        
        // Auto submit when kelas changes
        document.getElementById('kelasSelect')?.addEventListener('change', function() {
            submitForm();
        });
    </script>
</body>

</html>