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

// AJAX endpoints untuk detail dan perbandingan penilaian
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'get_multiple_penilaian' && isset($_GET['ids'])) {
        $ids = explode(',', $_GET['ids']);
        $results = [];
        
        if (count($ids) > 2) {
            echo json_encode(['success' => false, 'message' => 'Maksimal 2 penilaian dapat dibandingkan']);
            exit();
        }
        
        foreach ($ids as $penilaian_id) {
            $penilaian_id = intval($penilaian_id);
            
            $sql = "SELECT ps.*, 
                   DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                   sp.nama_pelajaran,
                   u.full_name as nama_guru
            FROM penilaian_siswa ps
            JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN siswa_orangtua so ON s.id = so.siswa_id
            JOIN orangtua o ON so.orangtua_id = o.id
            WHERE ps.id = ? 
              AND o.user_id = ?";
              
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("ii", $penilaian_id, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $data = $result->fetch_assoc();
                    
                    // Hitung persentase jika tidak ada
                    if (!isset($data['persentase']) || empty($data['persentase'])) {
                        $data['persentase'] = round(($data['total_score'] / 50) * 100);
                    }
                    
                    $results[] = $data;
                }
                $stmt->close();
            }
        }
        
        if (count($results) > 0) {
            echo json_encode(['success' => true, 'data' => $results]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Data penilaian tidak ditemukan']);
        }
        exit();
    }
    
    if ($_GET['action'] == 'get_detail_penilaian' && isset($_GET['id'])) {
        $penilaian_id = intval($_GET['id']);
        
        $sql = "SELECT ps.*, 
                       DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                       sp.nama_pelajaran,
                       u.full_name as nama_guru
                FROM penilaian_siswa ps
                JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
                JOIN guru g ON ps.guru_id = g.id
                JOIN users u ON g.user_id = u.id
                JOIN siswa s ON ps.siswa_id = s.id
                JOIN siswa_orangtua so ON s.id = so.siswa_id
                JOIN orangtua o ON so.orangtua_id = o.id
                WHERE ps.id = ? 
                  AND o.user_id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $penilaian_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $data = $result->fetch_assoc();
                
                // Hitung persentase jika tidak ada
                if (!isset($data['persentase']) || empty($data['persentase'])) {
                    $data['persentase'] = round(($data['total_score'] / 50) * 100);
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Data penilaian tidak ditemukan']);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit();
    }
}

// AMBIL DATA ANAK-ANAK
$anak_data = [];
$total_anak = 0;

if ($orangtua_id > 0) {
    try {
        // PERBAIKAN: Menggunakan tabel siswa_orangtua untuk relasi many-to-many
        $sql = "SELECT s.id, s.nama_lengkap, s.kelas, s.sekolah_asal 
                FROM siswa s 
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                WHERE so.orangtua_id = ? 
                ORDER BY s.nama_lengkap";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $orangtua_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $anak_data[] = $row;
            }
            $total_anak = count($anak_data);
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error fetching anak data: " . $e->getMessage());
    }
}

// TENTUKAN ANAK YANG DIPILIH
$selected_anak_id = isset($_GET['anak_id']) ? intval($_GET['anak_id']) : 0;
if ($selected_anak_id == 0 && !empty($anak_data)) {
    $selected_anak_id = $anak_data[0]['id'];
}

// PARAMETER FILTER
$filter_guru = isset($_GET['guru']) ? $_GET['guru'] : '';
$filter_kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$filter_bulan = isset($_GET['bulan']) ? $_GET['bulan'] : '';
$search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';

// PAGINATION
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// AMBIL DATA RIWAYAT PENILAIAN
$riwayat_data = [];
$total_records = 0;
$selected_anak_name = '';

// Data untuk filter dropdown
$guru_list = [];
$kategori_list = ['Sangat Baik', 'Baik', 'Cukup', 'Kurang'];

if ($selected_anak_id > 0 && $orangtua_id > 0) {
    // AMBIL NAMA ANAK TERPILIH
    // PERBAIKAN: Menggunakan tabel siswa_orangtua untuk verifikasi akses
    $sql_anak = "SELECT s.* 
                 FROM siswa s 
                 INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                 WHERE s.id = ? AND so.orangtua_id = ?";
    $stmt_anak = $conn->prepare($sql_anak);
    if ($stmt_anak) {
        $stmt_anak->bind_param("ii", $selected_anak_id, $orangtua_id);
        $stmt_anak->execute();
        $result_anak = $stmt_anak->get_result();
        if ($row_anak = $result_anak->fetch_assoc()) {
            $selected_anak_name = $row_anak['nama_lengkap'];
        }
        $stmt_anak->close();
    }
    
    // BANGUN QUERY DASAR
    $base_query = "SELECT 
                ps.*,
                DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                DATE_FORMAT(ps.tanggal_penilaian, '%H:%i') as jam_format,
                DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m') as bulan_tahun,
                sp.nama_pelajaran,
                u.full_name as nama_guru
               FROM penilaian_siswa ps
               JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
               JOIN guru g ON ps.guru_id = g.id
               JOIN users u ON g.user_id = u.id
               JOIN siswa s ON ps.siswa_id = s.id
               JOIN siswa_orangtua so ON s.id = so.siswa_id
               WHERE ps.siswa_id = ? AND so.orangtua_id = ?";
    
    $count_query = "SELECT COUNT(*) as total 
                    FROM penilaian_siswa ps
                    JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
                    JOIN guru g ON ps.guru_id = g.id
                    JOIN users u ON g.user_id = u.id
                    JOIN siswa s ON ps.siswa_id = s.id
                    JOIN siswa_orangtua so ON s.id = so.siswa_id
                    WHERE ps.siswa_id = ? AND so.orangtua_id = ?";
    
    $params = array($selected_anak_id, $orangtua_id);
    $param_types = "ii";
    $count_params = array($selected_anak_id, $orangtua_id);
    $count_param_types = "ii";
    
    // TAMBAH FILTER GURU
    if (!empty($filter_guru)) {
        $base_query .= " AND u.full_name LIKE ?";
        $count_query .= " AND u.full_name LIKE ?";
        $params[] = "%$filter_guru%";
        $count_params[] = "%$filter_guru%";
        $param_types .= "s";
        $count_param_types .= "s";
    }
    
    // TAMBAH FILTER KATEGORI
    if (!empty($filter_kategori)) {
        $base_query .= " AND ps.kategori = ?";
        $count_query .= " AND ps.kategori = ?";
        $params[] = $filter_kategori;
        $count_params[] = $filter_kategori;
        $param_types .= "s";
        $count_param_types .= "s";
    }
    
    // TAMBAH FILTER BULAN
    if (!empty($filter_bulan)) {
        $base_query .= " AND DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m') = ?";
        $count_query .= " AND DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m') = ?";
        $params[] = $filter_bulan;
        $count_params[] = $filter_bulan;
        $param_types .= "s";
        $count_param_types .= "s";
    }
    
    // TAMBAH SEARCH
    if (!empty($search_keyword)) {
        $base_query .= " AND (ps.catatan_guru LIKE ? OR ps.rekomendasi LIKE ? OR sp.nama_pelajaran LIKE ?)";
        $count_query .= " AND (ps.catatan_guru LIKE ? OR ps.rekomendasi LIKE ? OR sp.nama_pelajaran LIKE ?)";
        $search_param = "%$search_keyword%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $param_types .= "sss";
        $count_param_types .= "sss";
    }
    
    // ORDER BY
    $base_query .= " ORDER BY ps.tanggal_penilaian DESC, ps.id DESC";
    
    // QUERY UNTUK TOTAL RECORDS
    $stmt_count = $conn->prepare($count_query);
    if ($stmt_count) {
        $bind_params = array($count_param_types);
        foreach ($count_params as $key => $value) {
            $bind_params[] = &$count_params[$key];
        }
        
        call_user_func_array([$stmt_count, 'bind_param'], $bind_params);
        $stmt_count->execute();
        $result_count = $stmt_count->get_result();
        if ($row_count = $result_count->fetch_assoc()) {
            $total_records = $row_count['total'];
        }
        $stmt_count->close();
    }
    
    // TAMBAH LIMIT UNTUK PAGINATION
    $base_query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= "ii";
    
    // EKSEKUSI QUERY UTAMA
    $stmt = $conn->prepare($base_query);
    if ($stmt) {
        $bind_params = array($param_types);
        foreach ($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        
        call_user_func_array([$stmt, 'bind_param'], $bind_params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Hitung persentase jika tidak ada
            if (!isset($row['persentase']) || empty($row['persentase'])) {
                $row['persentase'] = round(($row['total_score'] / 50) * 100);
            }
            $riwayat_data[] = $row;
        }
        $stmt->close();
    }
    
    // AMBIL LIST GURU UNTUK FILTER DROPDOWN
    $sql_guru = "SELECT DISTINCT u.full_name as nama_guru
                 FROM penilaian_siswa ps
                 JOIN guru g ON ps.guru_id = g.id
                 JOIN users u ON g.user_id = u.id
                 WHERE ps.siswa_id = ?
                 ORDER BY u.full_name";
    
    $stmt_guru = $conn->prepare($sql_guru);
    if ($stmt_guru) {
        $stmt_guru->bind_param("i", $selected_anak_id);
        $stmt_guru->execute();
        $result_guru = $stmt_guru->get_result();
        while ($row_guru = $result_guru->fetch_assoc()) {
            $guru_list[] = $row_guru['nama_guru'];
        }
        $stmt_guru->close();
    }
}

// HITUNG PAGINATION
$total_pages = ceil($total_records / $limit);
$start_record = ($page - 1) * $limit + 1;
$end_record = min($page * $limit, $total_records);

// STATISTIK RIWAYAT
$stats = [
    'total' => $total_records,
    'sangat_baik' => 0,
    'baik' => 0,
    'cukup' => 0,
    'kurang' => 0,
    'rata_skor' => 0
];

if (!empty($riwayat_data)) {
    $total_skor = 0;
    foreach ($riwayat_data as $data) {
        $total_skor += $data['total_score'];
        $stats[strtolower(str_replace(' ', '_', $data['kategori']))]++;
    }
    $stats['rata_skor'] = $total_records > 0 ? round($total_skor / $total_records, 1) : 0;
}

// GENERATE OPTIONS BULAN
$bulan_options = [];
if ($selected_anak_id > 0) {
    $sql_bulan = "SELECT DISTINCT DATE_FORMAT(tanggal_penilaian, '%Y-%m') as bulan_tahun,
                          DATE_FORMAT(tanggal_penilaian, '%M %Y') as bulan_format
                   FROM penilaian_siswa 
                   WHERE siswa_id = ?
                   ORDER BY bulan_tahun DESC";
    
    $stmt_bulan = $conn->prepare($sql_bulan);
    if ($stmt_bulan) {
        $stmt_bulan->bind_param("i", $selected_anak_id);
        $stmt_bulan->execute();
        $result_bulan = $stmt_bulan->get_result();
        while ($row_bulan = $result_bulan->fetch_assoc()) {
            $bulan_options[$row_bulan['bulan_tahun']] = $row_bulan['bulan_format'];
        }
        $stmt_bulan->close();
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* (CSS styles tetap sama seperti sebelumnya) */
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .badge-sangat-baik { background-color: #10B981; color: white; }
        .badge-baik { background-color: #3B82F6; color: white; }
        .badge-cukup { background-color: #F59E0B; color: white; }
        .badge-kurang { background-color: #EF4444; color: white; }
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
        
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
            white-space: pre-line;
            max-height: 4.5em;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
            white-space: pre-line;
            max-height: 3em;
        }

        .truncate-multiline {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
        }
        
        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
        .modal-footer {
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
        .close:hover { opacity: 0.8; }
        
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
            transform: rotate(-90deg);
        }
        
        /* Desktop sidebar tetap terlihat */
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
            
            /* Tampilkan tabel di desktop */
            .desktop-table {
                display: block !important;
            }
            
            .mobile-card {
                display: none !important;
            }
        }

        /* Mobile Menu Styles */
        #mobileMenu {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100%;
            z-index: 1200;
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
            z-index: 1199;
        }

        .menu-overlay.active {
            display: block;
        }

        /* Sidebar menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        /* Mobile View Styles */
        @media (max-width: 767px) {
            /* Sembunyikan tabel di mobile */
            .desktop-table {
                display: none !important;
            }
            
            /* Tampilkan card layout di mobile */
            .mobile-card {
                display: block !important;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .filter-grid {
                grid-template-columns: 1fr !important;
                gap: 0.5rem !important;
            }
            
            .stat-cards {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.5rem !important;
            }
            
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-container table {
                min-width: 800px;
            }
            
            .table-container th,
            .table-container td {
                padding: 0.5rem !important;
            }
            
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .action-buttons button {
                padding: 0.25rem 0.5rem !important;
                font-size: 0.75rem;
            }
        }

        @media (max-width: 640px) {
            .mobile-card-item {
                padding: 1rem;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .mobile-card-item:last-child {
                border-bottom: none;
            }
            
            .mobile-card-label {
                font-weight: 600;
                color: #6B7280;
                font-size: 0.75rem;
                margin-bottom: 0.25rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            
            .mobile-card-value {
                font-size: 0.875rem;
                margin-bottom: 0.75rem;
            }
            
            .mobile-card-actions {
                margin-top: 0.5rem;
            }
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
        <div class="bg-white shadow p-4 md:p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Riwayat Penilaian</h1>
                    <p class="text-gray-600 text-sm md:text-base">Detail lengkap semua penilaian anak Anda</p>
                </div>
                <div class="mt-4 md:mt-0 w-full md:w-auto">
                    <?php if (!empty($anak_data)): ?>
                    <div class="relative">
                        <form method="GET" class="flex items-center">
                            <input type="hidden" name="page" value="1">
                            <input type="hidden" name="guru" value="<?php echo htmlspecialchars($filter_guru); ?>">
                            <input type="hidden" name="kategori" value="<?php echo htmlspecialchars($filter_kategori); ?>">
                            <input type="hidden" name="bulan" value="<?php echo htmlspecialchars($filter_bulan); ?>">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_keyword); ?>">
                            
                            <select name="anak_id" onchange="this.form.submit()" 
                                    class="w-full md:w-auto border border-gray-300 rounded-lg px-3 py-2 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <?php foreach ($anak_data as $anak): ?>
                                <option value="<?php echo $anak['id']; ?>" 
                                        <?php echo $selected_anak_id == $anak['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?> 
                                    (<?php echo $anak['kelas']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <?php if (empty($anak_data)): ?>
                <!-- Tidak Ada Data Anak -->
                <div class="text-center py-12 md:py-16">
                    <i class="fas fa-child text-5xl md:text-6xl text-gray-400 mb-4"></i>
                    <h3 class="text-xl md:text-2xl font-bold text-gray-700 mb-2">Belum ada data anak</h3>
                    <p class="text-gray-600 mb-6 text-sm md:text-base">Hubungi admin untuk mendaftarkan anak Anda ke bimbel.</p>
                    <a href="dashboardOrtu.php" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm md:text-base">
                        <i class="fas fa-arrow-left mr-2"></i> Kembali ke Dashboard
                    </a>
                </div>
            
            <?php elseif ($selected_anak_id == 0): ?>
                <!-- Pilih Anak Terlebih Dahulu -->
                <div class="text-center py-12 md:py-16">
                    <i class="fas fa-user-graduate text-5xl md:text-6xl text-gray-400 mb-4"></i>
                    <h3 class="text-xl md:text-2xl font-bold text-gray-700 mb-2">Pilih anak terlebih dahulu</h3>
                    <p class="text-gray-600 text-sm md:text-base">Silakan pilih salah satu anak untuk melihat riwayat penilaian mereka.</p>
                </div>
            
            <?php else: ?>
                <!-- ADA DATA - TAMPILKAN DASHBOARD RIWAYAT -->
                
                <!-- Header Anak -->
                <div class="mb-6 md:mb-8">
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 rounded-xl p-4 md:p-6 text-white shadow-lg">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="flex-1">
                                <h2 class="text-xl md:text-2xl lg:text-3xl font-bold mb-2">
                                    <?php echo htmlspecialchars($selected_anak_name); ?>
                                </h2>
                                <div class="flex flex-wrap gap-2 md:gap-3">
                                    <span class="bg-white/20 px-2 py-1 md:px-3 md:py-1 rounded-full text-xs md:text-sm">
                                        <i class="fas fa-clipboard-list mr-1"></i>
                                        Total: <?php echo $stats['total']; ?> Penilaian
                                    </span>
                                    <span class="bg-white/20 px-2 py-1 md:px-3 md:py-1 rounded-full text-xs md:text-sm">
                                        <i class="fas fa-star mr-1"></i>
                                        Rata-rata: <?php echo $stats['rata_skor']; ?>/50
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="card bg-white rounded-xl shadow p-4 md:p-6 mb-6">
                    <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">
                        <i class="fas fa-filter mr-2"></i> Filter & Pencarian
                    </h3>
                    
                    <form method="GET" class="space-y-3 md:space-y-4">
                        <input type="hidden" name="anak_id" value="<?php echo $selected_anak_id; ?>">
                        <input type="hidden" name="page" value="1">
                        
                        <div class="grid filter-grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4">
                            <!-- Filter Guru -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filter Guru</label>
                                <select name="guru" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                    <option value="">Semua Guru</option>
                                    <?php foreach ($guru_list as $guru): ?>
                                    <option value="<?php echo htmlspecialchars($guru); ?>"
                                            <?php echo $filter_guru == $guru ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($guru); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filter Kategori -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filter Kategori</label>
                                <select name="kategori" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                    <option value="">Semua Kategori</option>
                                    <?php foreach ($kategori_list as $kategori): ?>
                                    <option value="<?php echo $kategori; ?>"
                                            <?php echo $filter_kategori == $kategori ? 'selected' : ''; ?>>
                                        <?php echo $kategori; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filter Bulan -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filter Bulan</label>
                                <select name="bulan" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                    <option value="">Semua Bulan</option>
                                    <?php foreach ($bulan_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"
                                            <?php echo $filter_bulan == $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                            <div class="text-sm text-gray-600">
                                Menampilkan <?php echo $start_record; ?>-<?php echo $end_record; ?> dari <?php echo $total_records; ?> penilaian
                            </div>
                            <div class="flex space-x-2">
                                <button type="submit" 
                                        class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm md:text-base">
                                    <i class="fas fa-filter mr-1 md:mr-2"></i> <span class="hidden md:inline">Terapkan</span>
                                </button>
                                <a href="riwayatNilai.php?anak_id=<?php echo $selected_anak_id; ?>" 
                                   class="px-3 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm md:text-base">
                                    <i class="fas fa-redo mr-1 md:mr-2"></i> <span class="hidden md:inline">Reset</span>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Statistik Cards -->
                <div class="grid stat-cards grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-6">
                    <div class="bg-gradient-to-r from-green-50 to-green-100 border border-green-200 rounded-lg p-3 md:p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-green-100 rounded-lg mr-2 md:mr-3">
                                <i class="fas fa-trophy text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Sangat Baik</p>
                                <p class="text-lg md:text-xl font-bold text-gray-800"><?php echo $stats['sangat_baik']; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-3 md:p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-blue-100 rounded-lg mr-2 md:mr-3">
                                <i class="fas fa-thumbs-up text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Baik</p>
                                <p class="text-lg md:text-xl font-bold text-gray-800"><?php echo $stats['baik']; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 border border-yellow-200 rounded-lg p-3 md:p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-2 md:mr-3">
                                <i class="fas fa-balance-scale text-yellow-600"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Cukup</p>
                                <p class="text-lg md:text-xl font-bold text-gray-800"><?php echo $stats['cukup']; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-red-50 to-red-100 border border-red-200 rounded-lg p-3 md:p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-red-100 rounded-lg mr-2 md:mr-3">
                                <i class="fas fa-exclamation-triangle text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Kurang</p>
                                <p class="text-lg md:text-xl font-bold text-gray-800"><?php echo $stats['kurang']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Daftar Penilaian -->
                <div class="card bg-white rounded-xl shadow mb-6 md:mb-8">
                    <div class="p-4 md:p-6 border-b flex flex-col md:flex-row justify-between items-start md:items-center">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800">
                            <i class="fas fa-list-alt mr-2"></i> Daftar Penilaian
                        </h3>
                        <div class="text-sm text-gray-500 mt-2 md:mt-0">
                            <?php echo $total_records; ?> data ditemukan
                        </div>
                    </div>
                    
                    <?php if (empty($riwayat_data)): ?>
                        <!-- Tidak Ada Data -->
                        <div class="p-6 md:p-8 text-center">
                            <i class="fas fa-clipboard-list text-4xl text-gray-400 mb-3"></i>
                            <h4 class="text-lg font-medium text-gray-700 mb-2">Tidak ada data penilaian</h4>
                            <p class="text-gray-500 text-sm md:text-base">
                                <?php echo !empty($filter_guru) || !empty($filter_kategori) || !empty($filter_bulan) || !empty($search_keyword) 
                                    ? 'Coba ubah filter atau kata kunci pencarian' 
                                    : 'Belum ada penilaian untuk anak ini'; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Desktop View: Tabel -->
                        <div class="desktop-table">
                            <div class="table-container">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Tanggal
                                            </th>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Guru & Pelajaran
                                            </th>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Total Skor
                                            </th>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Kategori
                                            </th>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Catatan
                                            </th>
                                            <th class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Aksi
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($riwayat_data as $data): 
                                            $badge_class = '';
                                            if ($data['kategori'] == 'Sangat Baik') $badge_class = 'badge-sangat-baik';
                                            elseif ($data['kategori'] == 'Baik') $badge_class = 'badge-baik';
                                            elseif ($data['kategori'] == 'Cukup') $badge_class = 'badge-cukup';
                                            else $badge_class = 'badge-kurang';
                                            
                                            $persentase = $data['persentase'] ?? round(($data['total_score'] / 50) * 100);
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900"><?php echo $data['tanggal_format']; ?></div>
                                                <!--<div class="text-xs text-gray-500"><?php echo $data['jam_format']; ?></div>-->
                                            </td>
                                            <td class="px-4 md:px-6 py-3 md:py-4">
                                                <div class="text-sm font-medium text-gray-900"><?php echo $data['nama_guru']; ?></div>
                                                <div class="text-xs text-gray-500"><?php echo $data['nama_pelajaran'] ?? '-'; ?></div>
                                            </td>
                                            <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                                <div class="text-lg font-bold text-gray-900"><?php echo $data['total_score']; ?>/50</div>
                                                <div class="text-xs text-gray-500"><?php echo $persentase; ?>%</div>
                                            </td>
                                            <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $data['kategori']; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 md:px-6 py-3 md:py-4">
                                                <div class="text-sm text-gray-900 max-w-xs truncate-multiline">
                                                    <?php 
                                                    $catatan = $data['catatan_guru'] ?? 'Tidak ada catatan';
                                                    echo strlen($catatan) > 100 ? substr($catatan, 0, 100) . '...' : $catatan;
                                                    ?>
                                                </div>
                                            </td>
                                            <td class="px-4 md:px-6 py-3 md:py-4 whitespace-nowrap text-sm font-medium">
                                                <button onclick="showDetailPenilaian(<?php echo $data['id']; ?>)"
                                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                                    <i class="fas fa-eye"></i> Detail
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Mobile View: Card Layout -->
                        <div class="mobile-card">
                            <div class="divide-y divide-gray-200">
                                <?php foreach ($riwayat_data as $data): 
                                    $badge_class = '';
                                    if ($data['kategori'] == 'Sangat Baik') $badge_class = 'badge-sangat-baik';
                                    elseif ($data['kategori'] == 'Baik') $badge_class = 'badge-baik';
                                    elseif ($data['kategori'] == 'Cukup') $badge_class = 'badge-cukup';
                                    else $badge_class = 'badge-kurang';
                                    
                                    $persentase = $data['persentase'] ?? round(($data['total_score'] / 50) * 100);
                                ?>
                                <div class="mobile-card-item">
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <div class="mobile-card-label">Tanggal</div>
                                            <div class="mobile-card-value font-medium">
                                                <?php echo $data['tanggal_format']; ?>
                                            </div>
                                            <div class="text-xs text-gray-500"><?php echo $data['jam_format']; ?></div>
                                        </div>
                                        <div>
                                            <span class="badge <?php echo $badge_class; ?> text-xs">
                                                <?php echo $data['kategori']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="mobile-card-label">Guru & Pelajaran</div>
                                        <div class="mobile-card-value">
                                            <div class="font-medium"><?php echo $data['nama_guru']; ?></div>
                                            <div class="text-sm text-gray-600"><?php echo $data['nama_pelajaran'] ?? '-'; ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="mobile-card-label">Total Skor</div>
                                        <div class="mobile-card-value">
                                            <div class="text-lg font-bold text-gray-900"><?php echo $data['total_score']; ?>/50</div>
                                            <div class="text-sm text-gray-600"><?php echo $persentase; ?>%</div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="mobile-card-label">Catatan</div>
                                        <div class="mobile-card-value text-sm text-gray-600 line-clamp-2">
                                            <?php 
                                            $catatan = $data['catatan_guru'] ?? 'Tidak ada catatan';
                                            echo strlen($catatan) > 80 ? substr($catatan, 0, 80) . '...' : $catatan;
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mobile-card-actions">
                                        <button onclick="showDetailPenilaian(<?php echo $data['id']; ?>)"
                                                class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                            <i class="fas fa-eye mr-2"></i> Lihat Detail
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                            <div class="flex flex-col md:flex-row justify-between items-center">
                                <div class="text-sm text-gray-700 mb-4 md:mb-0">
                                    Menampilkan <?php echo $start_record; ?>-<?php echo $end_record; ?> dari <?php echo $total_records; ?> penilaian
                                </div>
                                <div class="flex items-center space-x-1">
                                    <?php if ($page > 1): ?>
                                    <a href="?<?php 
                                        $params = $_GET;
                                        $params['page'] = $page - 1;
                                        echo http_build_query($params);
                                    ?>" class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-50">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <a href="?<?php 
                                            $params = $_GET;
                                            $params['page'] = 1;
                                            echo http_build_query($params);
                                        ?>" class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-50">1</a>
                                        <?php if ($start_page > 2): ?>
                                            <span class="px-3 py-1">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="?<?php 
                                            $params = $_GET;
                                            $params['page'] = $i;
                                            echo http_build_query($params);
                                        ?>" class="px-3 py-1 border rounded-lg <?php echo $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <span class="px-3 py-1">...</span>
                                        <?php endif; ?>
                                        <a href="?<?php 
                                            $params = $_GET;
                                            $params['page'] = $total_pages;
                                            echo http_build_query($params);
                                        ?>" class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-50">
                                            <?php echo $total_pages; ?>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                    <a href="?<?php 
                                        $params = $_GET;
                                        $params['page'] = $page + 1;
                                        echo http_build_query($params);
                                    ?>" class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-50">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
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
                            Data terupdate: <?php echo date('d F Y H:i'); ?>
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

    <!-- Detail Modal -->
    <div id="detailPenilaianModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold"><i class="fas fa-chart-bar mr-2"></i> Detail Penilaian</h2>
                <span class="close" onclick="closeModal('detailPenilaianModal')">&times;</span>
            </div>
            <div class="modal-body" id="detailPenilaianContent"></div>
            <div class="modal-footer">
                <button onclick="closeModal('detailPenilaianModal')"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
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

        // Functionality for penilaian detail
        function showDetailPenilaian(penilaianId) {
            const modal = document.getElementById('detailPenilaianModal');
            const content = document.getElementById('detailPenilaianContent');
            
            // Ensure mobile menu is closed
            mobileMenu.classList.remove('menu-open');
            menuOverlay.classList.remove('active');
            document.body.style.overflow = 'hidden';
            
            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Memuat detail penilaian...</p>
                </div>
            `;
            
            modal.style.display = 'block';
            
            fetch(`riwayatNilai.php?action=get_detail_penilaian&id=${penilaianId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.data) {
                        displayDetailPenilaian(data.data);
                    } else {
                        content.innerHTML = `
                            <div class="text-center py-8">
                                <i class="fas fa-exclamation-circle text-4xl text-yellow-500 mb-4"></i>
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Gagal Memuat Data</h3>
                                <p class="text-gray-600">${data.message || 'Data tidak ditemukan'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    content.innerHTML = `
                        <div class="text-center py-8">
                            <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Kesalahan</h3>
                            <p class="text-gray-600">${error.message}</p>
                            <p class="text-sm text-gray-500 mt-2">Pastikan Anda masih login</p>
                        </div>
                    `;
                });
                    
            function displayDetailPenilaian(d) {
                const kategoriClass = d.kategori === 'Sangat Baik' ? 'bg-green-500' :
                                    d.kategori === 'Baik' ? 'bg-blue-500' :
                                    d.kategori === 'Cukup' ? 'bg-yellow-500' : 'bg-red-500';
                
                const indicators = [
                    { name: 'Willingness to Learn', value: d.willingness_learn },
                    { name: 'Problem Solving', value: d.problem_solving },
                    { name: 'Critical Thinking', value: d.critical_thinking },
                    { name: 'Konsentrasi', value: d.concentration },
                    { name: 'Independensi', value: d.independence }
                ];
                
                content.innerHTML = `
                    <div class="space-y-6">
                        <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white p-6 rounded-lg">
                            <h3 class="text-xl font-bold">Detail Penilaian</h3>
                            <div class="mt-2 space-y-2">
                                <p><i class="fas fa-calendar-alt mr-2"></i> ${d.tanggal_format}</p>
                                <p><i class="fas fa-user-tie mr-2"></i> ${d.nama_guru}</p>
                                ${d.nama_pelajaran ? `<p><i class="fas fa-book mr-2"></i> ${d.nama_pelajaran}</p>` : ''}
                            </div>
                        </div>
                        
                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg p-6 text-center">
                            <h4 class="text-lg font-semibold text-gray-800 mb-2">Total Skor</h4>
                            <div class="text-4xl md:text-5xl font-bold text-green-600 mb-2">${d.total_score}/50</div>
                            <div class="mt-4">
                                <span class="px-4 py-2 rounded-full text-white font-bold ${kategoriClass} text-base md:text-lg">
                                    ${d.kategori} (${d.persentase || Math.round((d.total_score/50)*100)}%)
                                </span>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="text-lg font-semibold text-gray-800 mb-4">Detail Indikator</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                ${indicators.map(ind => {
                                    const percentage = (ind.value / 10) * 100;
                                    let barColor = 'bg-green-500';
                                    if (percentage < 40) barColor = 'bg-red-500';
                                    else if (percentage < 70) barColor = 'bg-yellow-500';
                                    
                                    return `
                                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="font-medium text-gray-700 text-sm md:text-base">${ind.name}</span>
                                            <span class="font-bold text-gray-900 text-sm md:text-base">${ind.value}/10</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                                            <div class="h-2.5 rounded-full ${barColor}" style="width: ${percentage}%"></div>
                                        </div>
                                    </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                        
                        ${d.catatan_guru || d.rekomendasi ? `
                        <div class="space-y-4">
                            ${d.catatan_guru ? `
                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                                <h4 class="font-semibold text-gray-800 mb-2 text-sm md:text-base">Catatan Guru</h4>
                                <p class="text-gray-700 text-sm md:text-base">${d.catatan_guru}</p>
                            </div>
                            ` : ''}
                            
                            ${d.rekomendasi ? `
                            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
                                <h4 class="font-semibold text-gray-800 mb-2 text-sm md:text-base">Rekomendasi</h4>
                                <p class="text-gray-700 text-sm md:text-base">${d.rekomendasi}</p>
                            </div>
                            ` : ''}
                        </div>
                        ` : ''}
                    </div>
                `;
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
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
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };
    </script>
</body>
</html>