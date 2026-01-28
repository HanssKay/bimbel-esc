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

// AMBIL DATA ANAK-ANAK DARI TABEL SISWA_ORANGTUA
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

// DEFAULT FILTER
$selected_year = isset($_GET['tahun']) ? intval($_GET['tahun']) : $current_year;
$selected_month = isset($_GET['bulan']) ? intval($_GET['bulan']) : date('n');
$selected_week = $_GET['minggu'] ?? '';
$selected_siswa = isset($_GET['siswa']) ? intval($_GET['siswa']) : '';

// HANDLE AJAX REQUEST UNTUK DATA MINGGUAN
if (isset($_GET['action']) && $_GET['action'] != '') {
    header('Content-Type: application/json');
    
    if ($_GET['action'] == 'get_laporan_mingguan') {
        getLaporanMingguan($conn, $orangtua_id, $_GET);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// FUNGSI GET LAPORAN MINGGUAN (DENGAN CATATAN & REKOMENDASI)
function getLaporanMingguan($conn, $orangtua_id, $params) {
    header('Content-Type: application/json');
    
    try {
        $siswa_id = isset($params['siswa']) ? intval($params['siswa']) : 0;
        $tahun = isset($params['tahun']) ? intval($params['tahun']) : date('Y');
        $bulan = isset($params['bulan']) ? intval($params['bulan']) : 0;
        $minggu = isset($params['minggu']) ? $params['minggu'] : '';
        
        $where_conditions = ["so.orangtua_id = ?", "s.status = 'aktif'"];
        $bind_types = "i";
        $bind_values = [$orangtua_id];
        
        if ($siswa_id > 0) {
            $where_conditions[] = "s.id = ?";
            $bind_types .= "i";
            $bind_values[] = $siswa_id;
        }
        
        if ($tahun > 0) {
            $where_conditions[] = "YEAR(ps.tanggal_penilaian) = ?";
            $bind_types .= "i";
            $bind_values[] = $tahun;
        }
        
        if ($bulan > 0 && $bulan <= 12) {
            $where_conditions[] = "MONTH(ps.tanggal_penilaian) = ?";
            $bind_types .= "i";
            $bind_values[] = $bulan;
        }
        
        // Filter minggu 1-4 atau last_4
        if ($minggu == 'last_4') {
            // 4 minggu terakhir
            $current_week = date('W');
            $start_week = $current_week - 3;
            $where_conditions[] = "WEEK(ps.tanggal_penilaian, 1) BETWEEN ? AND ?";
            $bind_types .= "ii";
            $bind_values[] = $start_week;
            $bind_values[] = $current_week;
        } elseif (is_numeric($minggu) && $minggu >= 1 && $minggu <= 4) {
            // Untuk minggu 1-4, kita cari minggu ke-N dalam bulan
            if ($bulan > 0 && $tahun > 0) {
                $first_day = date('Y-m-01', strtotime("$tahun-$bulan-01"));
                $first_week = date('W', strtotime($first_day));
                $target_week = $first_week + ($minggu - 1);
                
                $where_conditions[] = "WEEK(ps.tanggal_penilaian, 1) = ?";
                $bind_types .= "i";
                $bind_values[] = $target_week;
            }
        }
        
        $where_clause = implode(" AND ", $where_conditions);
        
        // QUERY UTAMA: Ambil data statistik per minggu PER PELAJARAN YANG DINILAI
        $sql_main = "
            SELECT 
                s.id as siswa_id,
                s.nama_lengkap,
                s.kelas,
                COALESCE(sp.id, 0) as siswa_pelajaran_id,
                COALESCE(sp.nama_pelajaran, 'Umum') as nama_pelajaran,
                YEAR(ps.tanggal_penilaian) as tahun,
                MONTH(ps.tanggal_penilaian) as bulan,
                WEEK(ps.tanggal_penilaian, 1) as minggu_dalam_tahun,
                -- Hitung minggu dalam bulan
                CASE 
                    WHEN WEEK(ps.tanggal_penilaian, 1) - WEEK(DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m-01'), 1) + 1 BETWEEN 1 AND 4 
                    THEN WEEK(ps.tanggal_penilaian, 1) - WEEK(DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m-01'), 1) + 1
                    ELSE 1
                END as minggu_dalam_bulan,
                DATE_FORMAT(MIN(ps.tanggal_penilaian), '%d %M') as tanggal_mulai,
                DATE_FORMAT(MAX(ps.tanggal_penilaian), '%d %M %Y') as tanggal_akhir,
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
            INNER JOIN penilaian_siswa ps ON (
                ps.siswa_id = s.id AND ps.pendaftaran_id = pds.id
            )
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            WHERE $where_clause
            GROUP BY s.id, COALESCE(sp.id, 0), YEAR(ps.tanggal_penilaian), MONTH(ps.tanggal_penilaian), WEEK(ps.tanggal_penilaian, 1)
            HAVING COUNT(DISTINCT ps.id) > 0
            ORDER BY YEAR(ps.tanggal_penilaian) DESC, 
                     MONTH(ps.tanggal_penilaian) DESC, 
                     WEEK(ps.tanggal_penilaian, 1) DESC, 
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
                // Gunakan minggu dalam bulan untuk display
                $minggu_display = $row['minggu_dalam_bulan'] ?: 1;
                $tahun_penilaian = $row['tahun'];
                $bulan_penilaian = $row['bulan'];
                $minggu_tahun = $row['minggu_dalam_tahun'];
                $siswa_pelajaran_id = $row['siswa_pelajaran_id'];
                
                // SEKARANG AMBIL CATATAN & REKOMENDASI KHUSUS UNTUK MINGGU INI
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
                    AND WEEK(ps.tanggal_penilaian, 1) = ?
                    AND (
                        (? > 0 AND ps.siswa_pelajaran_id = ?) OR 
                        (? = 0 AND ps.siswa_pelajaran_id IS NULL)
                    )
                    AND (ps.catatan_guru IS NOT NULL OR ps.rekomendasi IS NOT NULL)
                    ORDER BY ps.tanggal_penilaian DESC
                    LIMIT 1
                ";
                
                $stmt_catatan = $conn->prepare($sql_catatan);
                $catatan_guru = null;
                $rekomendasi = null;
                $nama_guru = null;
                
                if ($stmt_catatan) {
                    $stmt_catatan->bind_param("iiiiii", 
                        $row['siswa_id'], 
                        $tahun_penilaian, 
                        $minggu_tahun,
                        $siswa_pelajaran_id,
                        $siswa_pelajaran_id,
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
                
                // Jika tidak ada catatan untuk minggu ini, coba ambil dari penilaian dalam minggu ini
                if (!$catatan_guru && !$rekomendasi) {
                    $sql_alternatif = "
                        SELECT 
                            GROUP_CONCAT(DISTINCT ps.catatan_guru SEPARATOR ' | ') as catatan_gabungan,
                            GROUP_CONCAT(DISTINCT ps.rekomendasi SEPARATOR ' | ') as rekomendasi_gabungan,
                            u.full_name as nama_guru
                        FROM penilaian_siswa ps
                        JOIN guru g ON ps.guru_id = g.id
                        JOIN users u ON g.user_id = u.id
                        WHERE ps.siswa_id = ?
                        AND YEAR(ps.tanggal_penilaian) = ?
                        AND WEEK(ps.tanggal_penilaian, 1) = ?
                        AND (
                            (? > 0 AND ps.siswa_pelajaran_id = ?) OR 
                            (? = 0 AND ps.siswa_pelajaran_id IS NULL)
                        )
                        GROUP BY u.full_name
                        ORDER BY ps.tanggal_penilaian DESC
                        LIMIT 1
                    ";
                    
                    $stmt_alt = $conn->prepare($sql_alternatif);
                    if ($stmt_alt) {
                        $stmt_alt->bind_param("iiiiii", 
                            $row['siswa_id'], 
                            $tahun_penilaian, 
                            $minggu_tahun,
                            $siswa_pelajaran_id,
                            $siswa_pelajaran_id,
                            $siswa_pelajaran_id
                        );
                        $stmt_alt->execute();
                        $result_alt = $stmt_alt->get_result();
                        
                        if ($alt_row = $result_alt->fetch_assoc()) {
                            $catatan_guru = $alt_row['catatan_gabungan'];
                            $rekomendasi = $alt_row['rekomendasi_gabungan'];
                            $nama_guru = $alt_row['nama_guru'];
                        }
                        $stmt_alt->close();
                    }
                }
                
                // Jika masih tidak ada, cari guru yang memberikan penilaian dalam minggu ini untuk pelajaran ini
                if (!$nama_guru) {
                    $sql_guru = "
                        SELECT DISTINCT u.full_name as nama_guru
                        FROM penilaian_siswa ps
                        JOIN guru g ON ps.guru_id = g.id
                        JOIN users u ON g.user_id = u.id
                        WHERE ps.siswa_id = ?
                        AND YEAR(ps.tanggal_penilaian) = ?
                        AND WEEK(ps.tanggal_penilaian, 1) = ?
                        AND (
                            (? > 0 AND ps.siswa_pelajaran_id = ?) OR 
                            (? = 0 AND ps.siswa_pelajaran_id IS NULL)
                        )
                        LIMIT 1
                    ";
                    
                    $stmt_guru = $conn->prepare($sql_guru);
                    if ($stmt_guru) {
                        $stmt_guru->bind_param("iiiiii", 
                            $row['siswa_id'], 
                            $tahun_penilaian, 
                            $minggu_tahun,
                            $siswa_pelajaran_id,
                            $siswa_pelajaran_id,
                            $siswa_pelajaran_id
                        );
                        $stmt_guru->execute();
                        $result_guru = $stmt_guru->get_result();
                        
                        if ($guru_row = $result_guru->fetch_assoc()) {
                            $nama_guru = $guru_row['nama_guru'];
                        }
                        $stmt_guru->close();
                    }
                }
                
                // Hitung kategori berdasarkan persentase
                $rata_persentase = floatval($row['rata_persentase']);
                if ($rata_persentase >= 85) {
                    $kategori = 'Sangat Baik';
                    $badge_color = 'success';
                    $badge_class = 'badge-sangat-baik';
                } elseif ($rata_persentase >= 70) {
                    $kategori = 'Baik';
                    $badge_color = 'primary';
                    $badge_class = 'badge-baik';
                } elseif ($rata_persentase >= 55) {
                    $kategori = 'Cukup';
                    $badge_color = 'warning';
                    $badge_class = 'badge-cukup';
                } else {
                    $kategori = 'Kurang';
                    $badge_color = 'danger';
                    $badge_class = 'badge-kurang';
                }
                
                $row['kategori'] = $kategori;
                $row['badge_color'] = $badge_color;
                $row['badge_class'] = $badge_class;
                $row['minggu'] = $minggu_display;
                $row['bulan_nama'] = date('F', mktime(0, 0, 0, $row['bulan'], 1));
                $row['periode'] = "Minggu {$minggu_display} ({$row['tanggal_mulai']} - {$row['tanggal_akhir']})";
                
                // Tambahkan catatan dan rekomendasi spesifik minggu
                $row['catatan_guru'] = $catatan_guru;
                $row['rekomendasi'] = $rekomendasi;
                $row['nama_guru'] = $nama_guru;
                $row['tahun_penilaian'] = $tahun_penilaian;
                $row['minggu_tahun'] = $minggu_tahun;
                
                $laporan[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $laporan,
                'total' => count($laporan)
            ]);
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        /* Responsive styles - IMPROVED MOBILE STYLING */
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
            
            /* Improved mobile spacing */
            .mobile-p-3 {
                padding: 0.75rem !important;
            }
            
            .mobile-text-sm {
                font-size: 0.875rem !important;
            }
            
            .mobile-text-xs {
                font-size: 0.75rem !important;
            }
            
            .mobile-mb-2 {
                margin-bottom: 0.5rem !important;
            }
            
            .mobile-p-2 {
                padding: 0.5rem !important;
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
        
        /* Mobile Menu Styling (Sama dengan dashboard) */
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

        <!-- Header - IMPROVED MOBILE STYLING -->
        <div class="bg-white shadow p-3 md:p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-lg md:text-2xl font-bold text-gray-800">Laporan Penilaian Mingguan</h1>
                    <p class="text-gray-600 text-xs md:text-base">Pantau perkembangan anak per minggu</p>
                </div>
                <div class="mt-1 md:mt-0 text-right">
                    <p class="text-xs md:text-sm text-gray-600"><?php echo date('l, d F Y'); ?></p>
                    <p class="text-xs md:text-sm text-blue-600"><span id="serverTime"><?php echo date('H:i'); ?></span> WIB</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-3 md:p-6">
            <!-- Filter Section - IMPROVED FOR MOBILE -->
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
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">Tahun</label>
                            <select name="tahun" id="filterTahun" class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Bulan -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">Bulan</label>
                            <select name="bulan" id="filterBulan" class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">Minggu</label>
                            <select name="minggu" id="filterMinggu" class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Minggu</option>
                                <option value="last_4" <?php echo $selected_week == 'last_4' ? 'selected' : ''; ?>>4 Minggu Terakhir</option>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $selected_week == $i ? 'selected' : ''; ?>>
                                        Minggu <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- Filter Anak -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1 md:mb-2">Anak</label>
                            <select name="siswa" id="filterSiswa" class="w-full px-2 py-1.5 text-xs md:text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                            <button type="button" id="btnFilter" class="w-full px-2 py-1.5 md:px-4 md:py-2 bg-blue-600 text-white text-xs md:text-sm font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <i class="fas fa-search mr-1 md:mr-2"></i> Tampilkan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Loading Indicator -->
            <div id="loadingIndicator" class="hidden text-center py-6 md:py-8">
                <div class="inline-block animate-spin rounded-full h-8 w-8 md:h-12 md:w-12 border-b-2 border-blue-600"></div>
                <p class="mt-2 md:mt-4 text-gray-600 text-sm md:text-lg">Memuat data laporan...</p>
            </div>

            <!-- Data Laporan -->
            <div id="laporanContainer">
                <div class="text-center py-6 md:py-12" id="initialLoading">
                    <div class="inline-block animate-spin rounded-full h-6 w-6 md:h-12 md:w-12 border-b-2 border-blue-600 mb-2 md:mb-4"></div>
                    <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-1">Memuat Data Laporan...</h3>
                    <p class="text-gray-500 text-xs md:text-base">Sedang mengambil data penilaian mingguan.</p>
                </div>
            </div>
        </div>

        <!-- Footer - IMPROVED FOR MOBILE -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-3 px-3 md:py-4 md:px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-xs md:text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Laporan Mingguan</p>
                        <p class="mt-0.5 text-xs text-gray-400">
                            Data terupdate: <?php echo date('d F Y H:i'); ?>
                        </p>
                    </div>
                    <div class="mt-2 md:mt-0">
                        <div class="flex items-center space-x-3 md:space-x-4">
                            <span class="inline-flex items-center text-xs md:text-sm text-gray-500">
                                <i class="fas fa-clock mr-1 text-xs"></i>
                                <span id="serverTime"><?php echo date('H:i:s'); ?></span>
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

        // Fungsi untuk memuat laporan
        function loadLaporan() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            
            const loading = document.getElementById('loadingIndicator');
            const container = document.getElementById('laporanContainer');
            const initialLoading = document.getElementById('initialLoading');
            
            // Tampilkan loading
            loading.classList.remove('hidden');
            if (initialLoading) initialLoading.style.display = 'none';
            container.innerHTML = '';
            
            // Ambil data laporan - PERBAIKI URL INI
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
                            <div class="text-center py-6 md:py-12">
                                <i class="fas fa-inbox text-2xl md:text-4xl text-gray-300 mb-2 md:mb-4"></i>
                                <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-1 md:mb-2">Tidak Ada Data</h3>
                                <p class="text-gray-500 text-xs md:text-base">
                                    Tidak ditemukan data penilaian untuk filter yang dipilih.
                                </p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading laporan:', error);
                    loading.classList.add('hidden');
                    container.innerHTML = `
                        <div class="text-center py-6 md:py-12">
                            <i class="fas fa-exclamation-triangle text-2xl md:text-4xl text-red-300 mb-2 md:mb-4"></i>
                            <h3 class="text-sm md:text-lg font-medium text-gray-700 mb-1 md:mb-2">Terjadi Kesalahan</h3>
                            <p class="text-gray-500 text-xs md:text-base">Gagal memuat data. Silakan coba lagi.</p>
                            <button onclick="loadLaporan()" 
                                    class="mt-3 md:mt-4 px-3 md:px-4 py-1.5 md:py-2 bg-blue-600 text-white text-xs md:text-base rounded-lg hover:bg-blue-700">
                                <i class="fas fa-redo mr-1 md:mr-2"></i> Coba Lagi
                            </button>
                        </div>
                    `;
                });
        }

        // Fungsi untuk render laporan - IMPROVED MOBILE VIEW
        function renderLaporan(data) {
            const container = document.getElementById('laporanContainer');
            const isMobile = window.innerWidth < 768;
            
            let html = `
                <div class="mb-4 md:mb-6">
                    <div class="flex justify-between items-center mb-3 md:mb-4">
                        <h3 class="text-sm md:text-lg font-semibold text-gray-800">
                            <i class="fas fa-list mr-1 md:mr-2"></i> Hasil Laporan (${data.length} data)
                        </h3>
                        <div class="text-xs md:text-sm text-gray-500">
                            Total: ${data.length} minggu
                        </div>
                    </div>
            `;
            
            data.forEach(item => {
                const badgeClass = item.badge_class || 'badge-cukup';
                const bulanNama = getBulanName(item.bulan) || item.bulan_nama || 'Unknown';
                const tahun = item.tahun || new Date().getFullYear();
                const minggu = item.minggu || 1;
                
                // Format catatan dan rekomendasi
                const catatanGuru = item.catatan_guru ? item.catatan_guru.trim() : null;
                const rekomendasi = item.rekomendasi ? item.rekomendasi.trim() : null;
                const namaGuru = item.nama_guru || 'Guru';
                const namaPelajaran = item.nama_pelajaran || 'Umum';
                const isPelajaranUmum = namaPelajaran === 'Umum';
                const badgePelajaranClass = isPelajaranUmum ? 'pelajaran-umum-badge' : 'pelajaran-badge';
                
                // Format nilai indikator untuk mobile/desktop
                const formatNilai = (nilai) => parseFloat(nilai || 0).toFixed(isMobile ? 0 : 1);
                
                // Warna untuk indikator mobile
                const getIndicatorColor = (value) => {
                    const num = parseFloat(value || 0);
                    if (num >= 8) return 'text-green-600';
                    if (num >= 6) return 'text-blue-600';
                    if (num >= 4) return 'text-yellow-600';
                    return 'text-red-600';
                };
                
                html += `
                    <div class="card bg-white rounded-lg shadow mb-3 md:mb-6 hover:shadow-md transition">
                        <div class="p-3 md:p-6">
                            <!-- Header: Info Siswa - IMPROVED FOR MOBILE -->
                            <div class="flex flex-col md:flex-row md:items-center justify-between mb-3 md:mb-6">
                                <div class="mb-2 md:mb-0">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center mr-2 md:mr-4">
                                            <span class="text-blue-800 font-bold text-xs md:text-base">
                                                ${item.nama_lengkap ? item.nama_lengkap.charAt(0) : '?'}
                                            </span>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-900 text-sm md:text-lg">
                                                ${item.nama_lengkap || 'Nama tidak tersedia'}
                                            </h4>
                                            <div class="text-xs text-gray-600">
                                                Kelas: ${item.kelas || '-'} | ${bulanNama} ${tahun}
                                            </div>
                                            <div class="flex flex-wrap items-center mt-0.5 md:mt-1 gap-1">
                                                <span class="${badgePelajaranClass} text-xs">
                                                    <i class="fas ${isPelajaranUmum ? 'fa-graduation-cap' : 'fa-book'} mr-0.5 md:mr-1"></i> 
                                                    ${namaPelajaran}
                                                </span>
                                                <span class="text-xs text-blue-600">
                                                    Minggu ${minggu} • ${item.jumlah_penilaian || 0} penilaian
                                                </span>
                                            </div>
                                            ${isMobile ? `
                                            <div class="text-xs text-gray-500 mt-0.5">
                                                <i class="fas fa-user-tie mr-1"></i> ${namaGuru}
                                            </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-1 md:space-x-3 mt-1 md:mt-0">
                                    <span class="badge ${badgeClass} px-2 md:px-4 py-0.5 md:py-2 text-xs md:text-sm">
                                        ${item.kategori || 'Cukup'}
                                    </span>
                                    ${!isMobile ? `<span class="text-xs text-gray-500 hidden md:inline">${item.periode || ''}</span>` : ''}
                                </div>
                            </div>
                            
                            <!-- Grid Indikator - IMPROVED FOR MOBILE -->
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-1.5 md:gap-3 mb-3 md:mb-6 indicator-grid">
                                <div class="text-center bg-gray-50 p-1.5 md:p-3 rounded-lg border">
                                    <div class="text-xs text-gray-600 mb-0.5 md:mb-1">Kemauan Belajar</div>
                                    <div class="text-base md:text-xl font-bold ${isMobile ? getIndicatorColor(item.rata_willingness) : 'text-gray-900'}">
                                        ${formatNilai(item.rata_willingness)}
                                    </div>
                                </div>
                                <div class="text-center bg-gray-50 p-1.5 md:p-3 rounded-lg border">
                                    <div class="text-xs text-gray-600 mb-0.5 md:mb-1">Pemecahan Masalah</div>
                                    <div class="text-base md:text-xl font-bold ${isMobile ? getIndicatorColor(item.rata_problem) : 'text-gray-900'}">
                                        ${formatNilai(item.rata_problem)}
                                    </div>
                                </div>
                                <div class="text-center bg-gray-50 p-1.5 md:p-3 rounded-lg border">
                                    <div class="text-xs text-gray-600 mb-0.5 md:mb-1">Berpikir Kritis</div>
                                    <div class="text-base md:text-xl font-bold ${isMobile ? getIndicatorColor(item.rata_critical) : 'text-gray-900'}">
                                        ${formatNilai(item.rata_critical)}
                                    </div>
                                </div>
                                <div class="text-center bg-gray-50 p-1.5 md:p-3 rounded-lg border">
                                    <div class="text-xs text-gray-600 mb-0.5 md:mb-1">Konsentrasi</div>
                                    <div class="text-base md:text-xl font-bold ${isMobile ? getIndicatorColor(item.rata_concentration) : 'text-gray-900'}">
                                        ${formatNilai(item.rata_concentration)}
                                    </div>
                                </div>
                                <div class="text-center bg-gray-50 p-1.5 md:p-3 rounded-lg border">
                                    <div class="text-xs text-gray-600 mb-0.5 md:mb-1">Kemandirian</div>
                                    <div class="text-base md:text-xl font-bold ${isMobile ? getIndicatorColor(item.rata_independence) : 'text-gray-900'}">
                                        ${formatNilai(item.rata_independence)}
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Total Score Section - IMPROVED FOR MOBILE -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 md:gap-4 mb-3 md:mb-6">
                                <div class="bg-blue-50 p-2 md:p-4 rounded-lg border border-blue-200">
                                    <div class="flex items-center mb-1 md:mb-2">
                                        <i class="fas fa-star text-blue-600 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                        <div class="text-xs text-gray-600">Total Skor</div>
                                    </div>
                                    <div class="text-xl md:text-3xl font-bold text-blue-700">
                                        ${formatNilai(item.rata_total_score)}/50
                                    </div>
                                </div>
                                <div class="bg-green-50 p-2 md:p-4 rounded-lg border border-green-200">
                                    <div class="flex items-center mb-1 md:mb-2">
                                        <i class="fas fa-percentage text-green-600 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                        <div class="text-xs text-gray-600">Persentase</div>
                                    </div>
                                    <div class="text-xl md:text-3xl font-bold text-green-700">
                                        ${formatNilai(item.rata_persentase)}%
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Catatan Guru - IMPROVED FOR MOBILE -->
                            ${catatanGuru ? `
                            <div class="mb-2 md:mb-4 p-2 md:p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-r-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-edit text-yellow-600 mt-0.5 mr-1 md:mr-3 text-xs md:text-sm"></i>
                                    <div class="flex-1">
                                        <h5 class="font-semibold text-gray-800 text-xs md:text-base mb-0.5 md:mb-1">
                                            Catatan dari Tutor ${namaGuru}:
                                        </h5>
                                        <p class="text-gray-700 text-xs md:text-sm">${catatanGuru}</p>
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                            
                            <!-- Rekomendasi - IMPROVED FOR MOBILE -->
                            ${rekomendasi ? `
                            <div class="mb-3 md:mb-6 p-2 md:p-4 bg-blue-50 border-l-4 border-blue-400 rounded-r-lg">
                                <div class="flex items-start">
                                    <i class="fas fa-lightbulb text-blue-600 mt-0.5 mr-1 md:mr-3 text-xs md:text-sm"></i>
                                    <div class="flex-1">
                                        <h5 class="font-semibold text-gray-800 text-xs md:text-base mb-0.5 md:mb-1">
                                            Rekomendasi dari Tutor ${namaGuru}:
                                        </h5>
                                        <p class="text-gray-700 text-xs md:text-sm">${rekomendasi}</p>
                                    </div>
                                </div>
                            </div>
                            ` : ''}
                            
                            <!-- Desktop only info -->
                            ${!isMobile ? `
                            <div class="text-xs text-gray-500 mt-2 border-t pt-2">
                                <i class="fas fa-user-tie mr-1"></i> ${namaGuru} | 
                                <i class="fas fa-calendar-alt ml-2 mr-1"></i> ${item.periode || ''}
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });
            
            html += `</div>`;
            container.innerHTML = html;
        }

        // Helper function untuk nama bulan
        function getBulanName(bulanNumber) {
            const bulan = [
                'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
            ];
            return bulan[bulanNumber - 1] || '';
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
                const timeElement = document.getElementById('serverTime');
                if (timeElement) {
                    timeElement.textContent = timeString;
                }
            }
            
            setInterval(updateServerTime, 1000);
            updateServerTime();
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