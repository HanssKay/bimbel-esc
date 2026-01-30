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

// AMBIL ID ORANGTUA DARI TABLE ORANGTUA - AMAN
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

// AMBIL DATA ANAK-ANAK DARI TABEL SISWA_ORANGTUA - AMAN
$anak_data = [];
$total_anak = 0;

if ($orangtua_id > 0) {
    try {
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

// TAHUN SEKARANG DAN TAHUN SEBELUMNYA UNTUK FILTER
$current_year = date('Y');
$years = [];
for ($i = $current_year; $i >= $current_year - 5; $i--) {
    $years[] = $i;
}

// BULAN-BULAN
$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// DEFAULT FILTER - SANITIZE INPUT
$selected_year = isset($_GET['tahun']) ? intval($_GET['tahun']) : $current_year;
$selected_month = isset($_GET['bulan']) ? intval($_GET['bulan']) : date('n');
$selected_week = isset($_GET['minggu']) ? preg_replace('/[^a-z0-9_]/', '', $_GET['minggu']) : '';
$selected_siswa = isset($_GET['siswa']) ? intval($_GET['siswa']) : '';

// HANDLE AJAX REQUEST UNTUK DATA MINGGUAN - AMAN
if (isset($_GET['action']) && $_GET['action'] != '') {
    $action = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['action']);
    
    if ($action == 'get_laporan_mingguan') {
        getLaporanMingguan($conn, $orangtua_id, $_GET);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// FUNGSI GET LAPORAN MINGGUAN YANG LEBIH AMAN
function getLaporanMingguan($conn, $orangtua_id, $params) {
    header('Content-Type: application/json');
    
    try {
        // Validasi dan sanitize semua parameter input
        $siswa_id = isset($params['siswa']) ? intval($params['siswa']) : 0;
        $tahun = isset($params['tahun']) ? intval($params['tahun']) : date('Y');
        $bulan = isset($params['bulan']) ? intval($params['bulan']) : 0;
        $minggu = isset($params['minggu']) ? preg_replace('/[^a-z0-9_]/', '', $params['minggu']) : '';
        
        // Validasi rentang input
        if ($tahun < 2000 || $tahun > 2100) $tahun = date('Y');
        if ($bulan < 0 || $bulan > 12) $bulan = 0;
        if ($siswa_id < 0) $siswa_id = 0;
        
        // VERIFIKASI HAK AKSES: Pastikan siswa ini milik orangtua
        if ($siswa_id > 0) {
            $sql_verify = "SELECT 1 
                          FROM siswa s 
                          INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                          WHERE s.id = ? 
                          AND so.orangtua_id = ?
                          AND s.status = 'aktif'
                          LIMIT 1";
            
            $stmt_verify = $conn->prepare($sql_verify);
            $stmt_verify->bind_param("ii", $siswa_id, $orangtua_id);
            $stmt_verify->execute();
            $result_verify = $stmt_verify->get_result();
            $is_verified = $result_verify->num_rows > 0;
            $stmt_verify->close();
            
            if (!$is_verified) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Siswa tidak ditemukan']);
                exit();
            }
        }
        
        // Bangun kondisi WHERE secara dinamis - AMAN
        $where_conditions = ["so.orangtua_id = ?", "s.status = 'aktif'"];
        $bind_types = "i";
        $bind_values = [$orangtua_id];
        
        // Filter siswa
        if ($siswa_id > 0) {
            $where_conditions[] = "s.id = ?";
            $bind_types .= "i";
            $bind_values[] = $siswa_id;
        }
        
        // Filter tahun
        if ($tahun > 0) {
            $where_conditions[] = "YEAR(ps.tanggal_penilaian) = ?";
            $bind_types .= "i";
            $bind_values[] = $tahun;
        }
        
        // Filter bulan
        if ($bulan > 0 && $bulan <= 12) {
            $where_conditions[] = "MONTH(ps.tanggal_penilaian) = ?";
            $bind_types .= "i";
            $bind_values[] = $bulan;
        }
        
        // Filter minggu - PERHITUNGAN YANG BENAR
        if ($minggu == 'last_4') {
            // 4 minggu terakhir dari tanggal saat ini
            $start_date = date('Y-m-d', strtotime('-4 weeks'));
            $where_conditions[] = "ps.tanggal_penilaian >= ?";
            $bind_types .= "s";
            $bind_values[] = $start_date;
        } elseif (is_numeric($minggu) && $minggu >= 1 && $minggu <= 5) {
            // Filter berdasarkan rentang tanggal dalam bulan
            if ($bulan > 0 && $tahun > 0) {
                // Hitung rentang tanggal untuk minggu yang dipilih
                $start_day = ($minggu - 1) * 7 + 1;
                $end_day = $minggu * 7;
                
                // Cek jumlah hari dalam bulan
                $days_in_month = date('t', strtotime("$tahun-$bulan-01"));
                $end_day = min($end_day, $days_in_month);
                
                if ($start_day <= $end_day) {
                    $where_conditions[] = "DAYOFMONTH(ps.tanggal_penilaian) BETWEEN ? AND ?";
                    $bind_types .= "ii";
                    $bind_values[] = $start_day;
                    $bind_values[] = $end_day;
                }
            }
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        // QUERY UTAMA YANG AMAN - GROUP BY berdasarkan minggu dalam bulan
        $sql_main = "
            SELECT 
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas,
                COALESCE(sp.id, 0) as siswa_pelajaran_id,
                COALESCE(sp.nama_pelajaran, 'Umum') as nama_pelajaran,
                YEAR(ps.tanggal_penilaian) as tahun,
                MONTH(ps.tanggal_penilaian) as bulan,
                -- Hitung minggu dalam bulan (1-5) berdasarkan tanggal
                CASE 
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 1 AND 7 THEN 1
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 8 AND 14 THEN 2
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 15 AND 21 THEN 3
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 22 AND 28 THEN 4
                    ELSE 5
                END as minggu_dalam_bulan,
                MIN(ps.tanggal_penilaian) as tanggal_mulai,
                MAX(ps.tanggal_penilaian) as tanggal_akhir,
                COUNT(DISTINCT ps.id) as jumlah_penilaian,
                AVG(ps.willingness_learn) as rata_willingness,
                AVG(ps.problem_solving) as rata_problem,
                AVG(ps.critical_thinking) as rata_critical,
                AVG(ps.concentration) as rata_concentration,
                AVG(ps.independence) as rata_independence,
                AVG(ps.total_score) as rata_total_score,
                AVG(ps.persentase) as rata_persentase,
                MAX(ps.total_score) as skor_tertinggi,
                MIN(ps.total_score) as skor_terendah
            FROM siswa s
            INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
            INNER JOIN pendaftaran_siswa pds ON s.id = pds.siswa_id AND pds.status = 'aktif'
            INNER JOIN penilaian_siswa ps ON ps.siswa_id = s.id AND ps.pendaftaran_id = pds.id
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            WHERE $where_clause
            GROUP BY 
                s.id, 
                COALESCE(sp.id, 0), 
                YEAR(ps.tanggal_penilaian), 
                MONTH(ps.tanggal_penilaian), 
                CASE 
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 1 AND 7 THEN 1
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 8 AND 14 THEN 2
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 15 AND 21 THEN 3
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 22 AND 28 THEN 4
                    ELSE 5
                END
            HAVING COUNT(DISTINCT ps.id) > 0
            ORDER BY 
                YEAR(ps.tanggal_penilaian) DESC, 
                MONTH(ps.tanggal_penilaian) DESC, 
                CASE 
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 1 AND 7 THEN 1
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 8 AND 14 THEN 2
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 15 AND 21 THEN 3
                    WHEN DAYOFMONTH(ps.tanggal_penilaian) BETWEEN 22 AND 28 THEN 4
                    ELSE 5
                END DESC, 
                s.nama_lengkap,
                COALESCE(sp.nama_pelajaran, 'Umum')
        ";
        
        $stmt = $conn->prepare($sql_main);
        if ($stmt) {
            if (!empty($bind_values)) {
                $stmt->bind_param($bind_types, ...$bind_values);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $laporan = [];
            while ($row = $result->fetch_assoc()) {
                // Format data untuk response
                $minggu_display = $row['minggu_dalam_bulan'] ?: 1;
                $tahun_penilaian = $row['tahun'];
                $bulan_penilaian = $row['bulan'];
                $siswa_pelajaran_id = $row['siswa_pelajaran_id'];
                
                // AMBIL CATATAN & REKOMENDASI UNTUK MINGGU INI - AMAN
                $catatan_guru = null;
                $rekomendasi = null;
                $nama_guru = null;
                
                if ($siswa_pelajaran_id > 0) {
                    $sql_catatan = "
                        SELECT 
                            ps.catatan_guru,
                            ps.rekomendasi,
                            u.full_name as nama_guru
                        FROM penilaian_siswa ps
                        JOIN guru g ON ps.guru_id = g.id
                        JOIN users u ON g.user_id = u.id
                        WHERE ps.siswa_id = ?
                        AND YEAR(ps.tanggal_penilaian) = ?
                        AND MONTH(ps.tanggal_penilaian) = ?
                        AND ps.siswa_pelajaran_id = ?
                        AND (
                            ps.catatan_guru IS NOT NULL 
                            OR ps.rekomendasi IS NOT NULL
                        )
                        ORDER BY ps.tanggal_penilaian DESC
                        LIMIT 1
                    ";
                    
                    $stmt_catatan = $conn->prepare($sql_catatan);
                    if ($stmt_catatan) {
                        $stmt_catatan->bind_param("iiii", 
                            $row['siswa_id'], 
                            $tahun_penilaian, 
                            $bulan_penilaian,
                            $siswa_pelajaran_id
                        );
                        $stmt_catatan->execute();
                        $result_catatan = $stmt_catatan->get_result();
                        
                        if ($catatan_row = $result_catatan->fetch_assoc()) {
                            $catatan_guru = $catatan_row['catatan_guru'];
                            $rekomendasi = $catatan_row['rekomendasi'];
                            $nama_guru = $catatan_row['nama_guru'];
                        }
                        $stmt_catatan->close();
                    }
                } else {
                    // Untuk pelajaran umum
                    $sql_catatan = "
                        SELECT 
                            ps.catatan_guru,
                            ps.rekomendasi,
                            u.full_name as nama_guru
                        FROM penilaian_siswa ps
                        JOIN guru g ON ps.guru_id = g.id
                        JOIN users u ON g.user_id = u.id
                        WHERE ps.siswa_id = ?
                        AND YEAR(ps.tanggal_penilaian) = ?
                        AND MONTH(ps.tanggal_penilaian) = ?
                        AND ps.siswa_pelajaran_id IS NULL
                        AND (
                            ps.catatan_guru IS NOT NULL 
                            OR ps.rekomendasi IS NOT NULL
                        )
                        ORDER BY ps.tanggal_penilaian DESC
                        LIMIT 1
                    ";
                    
                    $stmt_catatan = $conn->prepare($sql_catatan);
                    if ($stmt_catatan) {
                        $stmt_catatan->bind_param("iii", 
                            $row['siswa_id'], 
                            $tahun_penilaian, 
                            $bulan_penilaian
                        );
                        $stmt_catatan->execute();
                        $result_catatan = $stmt_catatan->get_result();
                        
                        if ($catatan_row = $result_catatan->fetch_assoc()) {
                            $catatan_guru = $catatan_row['catatan_guru'];
                            $rekomendasi = $catatan_row['rekomendasi'];
                            $nama_guru = $catatan_row['nama_guru'];
                        }
                        $stmt_catatan->close();
                    }
                }
                
                // Jika tidak ada catatan, cari guru yang memberikan penilaian
                if (!$nama_guru) {
                    $sql_guru = "
                        SELECT DISTINCT u.full_name as nama_guru
                        FROM penilaian_siswa ps
                        JOIN guru g ON ps.guru_id = g.id
                        JOIN users u ON g.user_id = u.id
                        WHERE ps.siswa_id = ?
                        AND YEAR(ps.tanggal_penilaian) = ?
                        AND MONTH(ps.tanggal_penilaian) = ?
                        " . ($siswa_pelajaran_id > 0 ? "AND ps.siswa_pelajaran_id = ?" : "AND ps.siswa_pelajaran_id IS NULL") . "
                        LIMIT 1
                    ";
                    
                    $stmt_guru = $conn->prepare($sql_guru);
                    if ($stmt_guru) {
                        if ($siswa_pelajaran_id > 0) {
                            $stmt_guru->bind_param("iii", 
                                $row['siswa_id'], 
                                $tahun_penilaian, 
                                $bulan_penilaian
                            );
                        } else {
                            $stmt_guru->bind_param("iii", 
                                $row['siswa_id'], 
                                $tahun_penilaian, 
                                $bulan_penilaian
                            );
                        }
                        $stmt_guru->execute();
                        $result_guru = $stmt_guru->get_result();
                        
                        if ($guru_row = $result_guru->fetch_assoc()) {
                            $nama_guru = $guru_row['nama_guru'];
                        }
                        $stmt_guru->close();
                    }
                }
                
                // Hitung kategori berdasarkan persentase
                $rata_persentase = floatval($row['rata_persentase'] ?? 0);
                if ($rata_persentase >= 85) {
                    $kategori = 'Sangat Baik';
                    $badge_class = 'badge-sangat-baik';
                } elseif ($rata_persentase >= 70) {
                    $kategori = 'Baik';
                    $badge_class = 'badge-baik';
                } elseif ($rata_persentase >= 55) {
                    $kategori = 'Cukup';
                    $badge_class = 'badge-cukup';
                } else {
                    $kategori = 'Kurang';
                    $badge_class = 'badge-kurang';
                }
                
                // Format tanggal untuk display
                $tanggal_mulai = !empty($row['tanggal_mulai']) ? 
                    date('d M', strtotime($row['tanggal_mulai'])) : '';
                $tanggal_akhir = !empty($row['tanggal_akhir']) ? 
                    date('d M Y', strtotime($row['tanggal_akhir'])) : '';
                
                $row['kategori'] = $kategori;
                $row['badge_class'] = $badge_class;
                $row['minggu'] = $minggu_display;
                $row['bulan_nama'] = date('F', mktime(0, 0, 0, $row['bulan'], 1));
                $row['periode'] = "Minggu {$minggu_display} ({$tanggal_mulai} - {$tanggal_akhir})";
                $row['catatan_guru'] = $catatan_guru;
                $row['rekomendasi'] = $rekomendasi;
                $row['nama_guru'] = $nama_guru ?? 'Guru';
                
                $laporan[] = $row;
            }
            
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'data' => $laporan,
                'total' => count($laporan),
                'tahun' => $tahun,
                'bulan' => $bulan > 0 ? date('F', mktime(0, 0, 0, $bulan, 1)) : 'Semua',
                'minggu' => $minggu
            ]);
        } else {
            error_log("Prepare statement failed: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error: Failed to prepare statement']);
        }
    } catch (Exception $e) {
        error_log("Error in getLaporanMingguan: " . $e->getMessage());
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
    <title>Laporan Penilaian Mingguan - Bimbel Esc</title>
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
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
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
        
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Loading animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-spinner {
            animation: spin 1s linear infinite;
        }
        
        /* Responsive styles */
        @media (max-width: 767px) {
            .filter-grid {
                grid-template-columns: 1fr !important;
            }
            
            .indicator-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            .mobile-stat-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        
        .pelajaran-badge {
            background-color: #e0f2fe;
            color: #0369a1;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid #bae6fd;
        }
        
        .pelajaran-umum-badge {
            background-color: #f3e8ff;
            color: #7c3aed;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid #ddd6fe;
        }
        
        /* Chart colors */
        .chart-willingness { background-color: #3B82F6; }
        .chart-problem { background-color: #10B981; }
        .chart-critical { background-color: #F59E0B; }
        .chart-concentration { background-color: #8B5CF6; }
        .chart-independence { background-color: #EC4899; }
        
        /* Mobile Menu Styling */
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
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
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
        <div class="bg-white shadow p-3 md:p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-lg md:text-2xl font-bold text-gray-800">
                        <i class="fas fa-chart-line mr-2"></i> Laporan Penilaian Mingguan
                    </h1>
                    <p class="text-gray-600 text-xs md:text-base">
                        Pantau perkembangan belajar anak per minggu
                    </p>
                </div>
                <div class="mt-1 md:mt-0 text-right">
                    <p class="text-xs md:text-sm text-gray-600"><?php echo date('l, d F Y'); ?></p>
                    <p class="text-xs md:text-sm text-blue-600">
                        <i class="fas fa-clock mr-1"></i> <span id="serverTime"><?php echo date('H:i'); ?></span> WIB
                    </p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-3 md:p-6">
            <!-- Filter Section -->
            <div class="card bg-white rounded-lg shadow mb-4 md:mb-6">
                <div class="p-3 md:p-6 border-b">
                    <h3 class="text-sm md:text-lg font-semibold text-gray-800">
                        <i class="fas fa-filter mr-2"></i> Filter Laporan
                    </h3>
                </div>
                <div class="p-3 md:p-6">
                    <form id="filterForm" class="grid grid-cols-1 md:grid-cols-5 gap-2 md:gap-4 filter-grid">
                        <!-- Filter Tahun -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">
                                <i class="fas fa-calendar mr-1"></i> Tahun
                            </label>
                            <select name="tahun" id="filterTahun" 
                                    class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Bulan -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">
                                <i class="fas fa-calendar-alt mr-1"></i> Bulan
                            </label>
                            <select name="bulan" id="filterBulan" 
                                    class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Bulan</option>
                                <?php foreach ($months as $num => $name): ?>
                                    <option value="<?php echo $num; ?>" <?php echo $selected_month == $num ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Minggu -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">
                                <i class="fas fa-calendar-week mr-1"></i> Minggu
                            </label>
                            <select name="minggu" id="filterMinggu" 
                                    class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Minggu</option>
                                <option value="last_4" <?php echo $selected_week == 'last_4' ? 'selected' : ''; ?>>
                                    4 Minggu Terakhir
                                </option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $selected_week == $i ? 'selected' : ''; ?>>
                                        Minggu <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Anak -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">
                                <i class="fas fa-user-graduate mr-1"></i> Anak
                            </label>
                            <select name="siswa" id="filterSiswa" 
                                    class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Anak</option>
                                <?php foreach ($anak_data as $anak): ?>
                                    <option value="<?php echo $anak['id']; ?>" <?php echo $selected_siswa == $anak['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($anak['nama_lengkap']); ?> (<?php echo htmlspecialchars($anak['kelas']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Tombol Filter -->
                        <div class="flex items-end">
                            <button type="button" id="btnFilter" 
                                    class="w-full px-2 py-1.5 md:px-4 md:py-2 bg-blue-600 text-white text-xs md:text-sm font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                <i class="fas fa-search mr-1 md:mr-2"></i> Tampilkan
                            </button>
                        </div>
                    </form>
                    
                    <!-- Info Filter Aktif -->
                    <div id="filterInfo" class="mt-3 md:mt-4 p-2 md:p-3 bg-blue-50 rounded-lg border border-blue-200 hidden">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-blue-600 mr-2 text-sm"></i>
                            <span id="filterInfoText" class="text-xs md:text-sm text-blue-800"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div id="loadingIndicator" class="hidden text-center py-6 md:py-8">
                <div class="inline-block loading-spinner rounded-full h-8 w-8 md:h-12 md:w-12 border-b-2 border-blue-600 mb-2 md:mb-4"></div>
                <p class="mt-2 md:mt-4 text-gray-600 text-sm md:text-lg">Memuat data laporan...</p>
                <p class="text-xs md:text-sm text-gray-500 mt-1">Harap tunggu sebentar</p>
            </div>

            <!-- Data Laporan -->
            <div id="laporanContainer">
                <div class="text-center py-6 md:py-12" id="initialLoading">
                    <div class="inline-block loading-spinner rounded-full h-8 w-8 md:h-12 md:w-12 border-b-2 border-blue-600 mb-3 md:mb-4"></div>
                    <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-2">Memuat Data Laporan...</h3>
                    <p class="text-gray-500 text-xs md:text-base">Sedang mengambil data penilaian mingguan.</p>
                    <p class="text-xs text-gray-400 mt-2">Pastikan koneksi internet Anda stabil</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-3 px-3 md:py-4 md:px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-xs md:text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Laporan Mingguan</p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            <i class="fas fa-sync-alt mr-1"></i> Data terupdate: <?php echo date('d F Y H:i'); ?>
                        </p>
                    </div>
                    <div class="mt-2 md:mt-0">
                        <div class="flex items-center space-x-3 md:space-x-4">
                            <span class="inline-flex items-center text-xs md:text-sm text-gray-500">
                                <i class="fas fa-users mr-1 text-xs"></i>
                                <?php echo $total_anak; ?> Anak
                            </span>
                            <span class="inline-flex items-center text-xs md:text-sm text-gray-500">
                                <i class="fas fa-clock mr-1 text-xs"></i>
                                <span id="serverTimeFooter"><?php echo date('H:i:s'); ?></span>
                            </span>
                        </div>
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

        // Close menu when clicking on menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });

        // Fungsi untuk memuat laporan dengan error handling
        function loadLaporan() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            
            const loading = document.getElementById('loadingIndicator');
            const container = document.getElementById('laporanContainer');
            const initialLoading = document.getElementById('initialLoading');
            const filterInfo = document.getElementById('filterInfo');
            const filterInfoText = document.getElementById('filterInfoText');
            
            // Update filter info
            const tahun = document.getElementById('filterTahun').value;
            const bulan = document.getElementById('filterBulan').value;
            const minggu = document.getElementById('filterMinggu').value;
            const siswa = document.getElementById('filterSiswa').value;
            
            let infoText = 'Filter: ';
            if (siswa) {
                const siswaSelect = document.getElementById('filterSiswa');
                const selectedOption = siswaSelect.options[siswaSelect.selectedIndex];
                infoText += `${selectedOption.text} | `;
            }
            infoText += `Tahun ${tahun} | `;
            if (bulan) {
                infoText += `Bulan ${bulan} | `;
            }
            if (minggu) {
                infoText += `Minggu ${minggu === 'last_4' ? '4 Terakhir' : minggu}`;
            }
            
            filterInfoText.textContent = infoText;
            filterInfo.classList.remove('hidden');
            
            // Tampilkan loading
            loading.classList.remove('hidden');
            if (initialLoading) initialLoading.style.display = 'none';
            container.innerHTML = '';
            
            // Tambahkan timestamp untuk cache busting
            params.append('_t', new Date().getTime());
            
            // Ambil data laporan
            fetch(`?action=get_laporan_mingguan&${params}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    loading.classList.add('hidden');
                    
                    if (data.success && data.data && data.data.length > 0) {
                        renderLaporan(data.data);
                    } else {
                        container.innerHTML = `
                            <div class="text-center py-8 md:py-12">
                                <i class="fas fa-inbox text-3xl md:text-4xl text-gray-300 mb-3 md:mb-4"></i>
                                <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-2">Tidak Ada Data</h3>
                                <p class="text-gray-500 text-xs md:text-base mb-4">
                                    Tidak ditemukan data penilaian untuk filter yang dipilih.
                                </p>
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 md:p-4 max-w-md mx-auto">
                                    <p class="text-gray-600 text-xs md:text-sm">
                                        <i class="fas fa-lightbulb mr-2"></i>
                                        Coba ubah filter atau pilih periode yang berbeda.
                                    </p>
                                </div>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading laporan:', error);
                    loading.classList.add('hidden');
                    container.innerHTML = `
                        <div class="text-center py-8 md:py-12">
                            <i class="fas fa-exclamation-triangle text-3xl md:text-4xl text-red-300 mb-3 md:mb-4"></i>
                            <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-2">Terjadi Kesalahan</h3>
                            <p class="text-gray-500 text-xs md:text-base mb-4">Gagal memuat data. Silakan coba lagi.</p>
                            <button onclick="loadLaporan()" 
                                    class="px-4 md:px-6 py-2 md:py-3 bg-blue-600 text-white text-sm md:text-base rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                                <i class="fas fa-redo mr-2"></i> Coba Lagi
                            </button>
                            <p class="text-xs text-gray-400 mt-3">Error: ${error.message}</p>
                        </div>
                    `;
                });
        }

        // Fungsi untuk render laporan
        function renderLaporan(data) {
            const container = document.getElementById('laporanContainer');
            const isMobile = window.innerWidth < 768;
            
            let html = `
                <div class="mb-4 md:mb-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 md:mb-6 p-3 md:p-4 bg-blue-50 rounded-lg border border-blue-200">
                        <div>
                            <h3 class="text-sm md:text-lg font-semibold text-gray-800 mb-1">
                                <i class="fas fa-chart-line mr-2"></i> Hasil Laporan
                            </h3>
                            <p class="text-xs md:text-sm text-gray-600">
                                Total ${data.length} data penilaian ditemukan
                            </p>
                        </div>
                        
                    </div>
            `;
            
            // Group data by siswa untuk tampilan yang lebih terorganisir
            const groupedData = {};
            data.forEach(item => {
                const key = `${item.siswa_id}-${item.nama_lengkap}`;
                if (!groupedData[key]) {
                    groupedData[key] = [];
                }
                groupedData[key].push(item);
            });
            
            Object.keys(groupedData).forEach(siswaKey => {
                const items = groupedData[siswaKey];
                const firstItem = items[0];
                
                html += `
                    <div class="card bg-white rounded-lg shadow mb-4 md:mb-6 overflow-hidden">
                        <!-- Header Siswa -->
                        <div class="bg-gray-50 p-3 md:p-4 border-b">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                        <span class="text-blue-800 font-bold text-sm md:text-base">
                                            ${firstItem.nama_lengkap ? firstItem.nama_lengkap.charAt(0) : '?'}
                                        </span>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-gray-900 text-sm md:text-lg">
                                            ${firstItem.nama_lengkap || 'N/A'}
                                        </h4>
                                        <p class="text-xs text-gray-600">
                                            Kelas: ${firstItem.kelas || '-'} | ${firstItem.bulan_nama || ''} ${firstItem.tahun || ''}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-xs text-blue-600 font-medium">
                                    ${items.length} Minggu
                                </div>
                            </div>
                        </div>
                        
                        <!-- Data per minggu -->
                        <div class="divide-y">
                `;
                
                items.forEach((item, index) => {
                    const badgeClass = item.badge_class || 'badge-cukup';
                    const minggu = item.minggu || 1;
                    const namaPelajaran = item.nama_pelajaran || 'Umum';
                    const isPelajaranUmum = namaPelajaran === 'Umum';
                    const badgePelajaranClass = isPelajaranUmum ? 'pelajaran-umum-badge' : 'pelajaran-badge';
                    const namaGuru = item.nama_guru || 'Guru';
                    
                    html += `
                        <div class="p-3 md:p-4 ${index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}">
                            <!-- Minggu Info -->
                            <div class="flex flex-col md:flex-row md:items-center justify-between mb-3 md:mb-4">
                                <div>
                                    <div class="flex items-center flex-wrap gap-1 md:gap-2 mb-1">
                                        <span class="${badgeClass} px-2 md:px-3 py-0.5 md:py-1 text-xs md:text-sm">
                                            <i class="fas fa-calendar-week mr-1"></i> Minggu ${minggu}
                                        </span>
                                        <span class="${badgePelajaranClass} text-xs">
                                            <i class="fas ${isPelajaranUmum ? 'fa-graduation-cap' : 'fa-book'} mr-1"></i> 
                                            ${namaPelajaran}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-600">
                                        ${item.periode || ''} | ${item.jumlah_penilaian || 0} penilaian
                                    </p>
                                </div>
                                <div class="mt-1 md:mt-0">
                                    <div class="text-xs md:text-sm text-gray-500">
                                        <i class="fas fa-user-tie mr-1"></i> ${namaGuru}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Indikator Nilai -->
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-2 md:gap-3 mb-3 md:mb-4 indicator-grid">
                                ${renderIndicator('Kemauan Belajar', item.rata_willingness, 'chart-willingness')}
                                ${renderIndicator('Pemecahan Masalah', item.rata_problem, 'chart-problem')}
                                ${renderIndicator('Berpikir Kritis', item.rata_critical, 'chart-critical')}
                                ${renderIndicator('Konsentrasi', item.rata_concentration, 'chart-concentration')}
                                ${renderIndicator('Kemandirian', item.rata_independence, 'chart-independence')}
                            </div>
                            
                            <!-- Total Score -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 md:gap-4 mb-3 md:mb-4">
                                <div class="bg-blue-50 p-2 md:p-3 rounded-lg border border-blue-200">
                                    <div class="flex items-center md:mt-1 justify-between">
                                        <span class="text-xs text-gray-600">Skor Total</span>
                                        <span class="text-sm md:text-lg font-bold text-blue-700">
                                            ${parseFloat(item.rata_total_score || 0).toFixed(isMobile ? 0 : 1)}/50
                                        </span>
                                    </div>
                                </div>
                                <div class="bg-green-50 p-2 md:p-3 rounded-lg border border-green-200">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs text-gray-600">Persentase</span>
                                        <span class="text-sm md:text-lg font-bold text-green-700">
                                            ${parseFloat(item.rata_persentase || 0).toFixed(1)}%
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        Kategori: ${item.kategori || 'Cukup'}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Catatan dan Rekomendasi -->
                            <div class="space-y-2 md:space-y-3">
                                ${item.catatan_guru ? renderNote('catatan', item.catatan_guru) : ''}
                                ${item.rekomendasi ? renderNote('rekomendasi', item.rekomendasi) : ''}
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            html += `</div>`;
            container.innerHTML = html;
        }

        // Helper function untuk render indikator
        function renderIndicator(label, value, colorClass) {
            const val = parseFloat(value || 0);
            const formattedValue = val.toFixed(1);
            
            return `
                <div class="text-center bg-white p-2 md:p-3 rounded-lg border">
                    <div class="text-xs text-gray-600 mb-1">${label}</div>
                    <div class="text-base md:text-xl font-bold text-gray-900">${formattedValue}</div>
                    <div class="h-1 w-full bg-gray-200 rounded-full mt-1 overflow-hidden">
                        <div class="h-full ${colorClass} rounded-full" 
                             style="width: ${Math.min(val * 10, 100)}%"></div>
                    </div>
                </div>
            `;
        }

        // Helper function untuk render catatan
        function renderNote(type, content) {
            const icon = type === 'catatan' ? 'fa-edit' : 'fa-lightbulb';
            const title = type === 'catatan' ? 'Catatan Tutor' : 'Rekomendasi Tutor';
            const bgColor = type === 'catatan' ? 'bg-yellow-50 border-yellow-200' : 'bg-blue-50 border-blue-200';
            const borderColor = type === 'catatan' ? 'border-l-yellow-400' : 'border-l-blue-400';
            
            return `
                <div class="p-2 md:p-3 ${bgColor} border-l-4 ${borderColor} rounded-r-lg">
                    <div class="flex items-start">
                        <i class="fas ${icon} mt-0.5 mr-2 text-sm ${type === 'catatan' ? 'text-yellow-600' : 'text-blue-600'}"></i>
                        <div class="flex-1">
                            <h5 class="font-semibold text-gray-800 text-xs md:text-sm mb-1">${title}:</h5>
                            <p class="text-gray-700 text-xs md:text-sm">${content}</p>
                        </div>
                    </div>
                </div>
            `;
        }

        // Fungsi untuk ekspor data
        function exportLaporan() {
            alert('Fitur ekspor data akan segera tersedia!');
            // Implementasi ekspor ke CSV/Excel bisa ditambahkan di sini
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Filter button
            const btnFilter = document.getElementById('btnFilter');
            if (btnFilter) {
                btnFilter.addEventListener('click', loadLaporan);
            }
            
            // Auto filter saat dropdown berubah
            ['filterTahun', 'filterBulan', 'filterMinggu', 'filterSiswa'].forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', loadLaporan);
                }
            });
            
            // Auto load data saat halaman dimuat
            setTimeout(function() {
                loadLaporan();
            }, 500);
            
            // Update server time
            function updateServerTime() {
                const now = new Date();
                const timeString = now.toLocaleTimeString('id-ID');
                document.querySelectorAll('#serverTime, #serverTimeFooter').forEach(el => {
                    el.textContent = timeString;
                });
            }
            
            setInterval(updateServerTime, 1000);
            updateServerTime();
            
            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (document.getElementById('laporanContainer').children.length > 1) {
                        // Reload data untuk responsive layout
                        loadLaporan();
                    }
                }, 250);
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
    </script>
</body>
</html>