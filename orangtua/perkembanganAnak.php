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

// AMBIL DATA ANAK-ANAK (HANYA YANG AKTIF)
$anak_data = [];
$total_anak = 0;

if ($orangtua_id > 0) {
    try {
        // PERBAIKAN: Query lebih sederhana untuk VPS
        $sql = "SELECT s.id, s.nama_lengkap, s.kelas, s.sekolah_asal, s.status
                FROM siswa s 
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                WHERE so.orangtua_id = ? AND s.status = 'aktif'
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

// AMBIL DATA PERKEMBANGAN UNTUK ANAK TERPILIH
$trend_data = [];
$radar_data = [];
$insights = [];
$selected_anak_name = '';

if ($selected_anak_id > 0 && $orangtua_id > 0) {
    // 1. AMBIL DATA ANAK TERPILIH (dengan verifikasi hak akses orangtua)
    $sql_anak = "SELECT s.* 
                FROM siswa s 
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                WHERE s.id = ? AND so.orangtua_id = ? AND s.status = 'aktif'";
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
    
    // 2. DATA TREND 6 BULAN TERAKHIR - PERBAIKAN QUERY UNTUK VPS
    // Ambil semua data dulu, lalu proses di PHP
    $sql_trend_raw = "SELECT 
                        tanggal_penilaian,
                        total_score,
                        persentase,
                        willingness_learn,
                        concentration,
                        critical_thinking,
                        independence,
                        problem_solving
                      FROM penilaian_siswa 
                      WHERE siswa_id = ? 
                      AND tanggal_penilaian >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                      ORDER BY tanggal_penilaian DESC";
    
    $stmt_trend = $conn->prepare($sql_trend_raw);
    if ($stmt_trend) {
        $stmt_trend->bind_param("i", $selected_anak_id);
        $stmt_trend->execute();
        $result_trend = $stmt_trend->get_result();
        
        // Group data per bulan di PHP (lebih aman untuk VPS)
        $raw_data_by_month = [];
        while ($row = $result_trend->fetch_assoc()) {
            $bulan_key = date('Y-m', strtotime($row['tanggal_penilaian']));
            $bulan_format = date('F Y', strtotime($row['tanggal_penilaian']));
            
            if (!isset($raw_data_by_month[$bulan_key])) {
                $raw_data_by_month[$bulan_key] = [
                    'bulan' => $bulan_key,
                    'bulan_format' => $bulan_format,
                    'scores' => [],
                    'percentages' => [],
                    'willingness' => [],
                    'concentration' => [],
                    'critical' => [],
                    'independence' => [],
                    'problem' => []
                ];
            }
            
            $raw_data_by_month[$bulan_key]['scores'][] = $row['total_score'];
            $raw_data_by_month[$bulan_key]['percentages'][] = $row['persentase'];
            $raw_data_by_month[$bulan_key]['willingness'][] = $row['willingness_learn'];
            $raw_data_by_month[$bulan_key]['concentration'][] = $row['concentration'];
            $raw_data_by_month[$bulan_key]['critical'][] = $row['critical_thinking'];
            $raw_data_by_month[$bulan_key]['independence'][] = $row['independence'];
            $raw_data_by_month[$bulan_key]['problem'][] = $row['problem_solving'];
        }
        $stmt_trend->close();
        
        // Hitung rata-rata per bulan
        foreach ($raw_data_by_month as $month_key => $month_data) {
            $trend_data[] = [
                'bulan' => $month_data['bulan'],
                'bulan_format' => $month_data['bulan_format'],
                'jumlah_penilaian' => count($month_data['scores']),
                'rata_skor' => count($month_data['scores']) > 0 ? 
                    array_sum($month_data['scores']) / count($month_data['scores']) : 0,
                'rata_persen' => count($month_data['percentages']) > 0 ? 
                    array_sum($month_data['percentages']) / count($month_data['percentages']) : 0,
                'min_skor' => count($month_data['scores']) > 0 ? 
                    min($month_data['scores']) : 0,
                'max_skor' => count($month_data['scores']) > 0 ? 
                    max($month_data['scores']) : 0
            ];
        }
        
        // Urutkan dari terbaru ke terlama (DESC)
        usort($trend_data, function($a, $b) {
            return strtotime($b['bulan']) - strtotime($a['bulan']);
        });
        
        // Batasi 6 bulan terakhir
        $trend_data = array_slice($trend_data, 0, 6);
    }
    
    // 3. DATA RATA-RATA INDIKATOR (UNTUK RADAR CHART) - QUERY LEBIH SEDERHANA
    $sql_radar = "SELECT 
                    AVG(COALESCE(willingness_learn, 0)) as w_learn,
                    AVG(COALESCE(concentration, 0)) as concentration,
                    AVG(COALESCE(critical_thinking, 0)) as c_thinking,
                    AVG(COALESCE(independence, 0)) as independence,
                    AVG(COALESCE(problem_solving, 0)) as p_solving
                  FROM penilaian_siswa 
                  WHERE siswa_id = ?";
    
    $stmt_radar = $conn->prepare($sql_radar);
    if ($stmt_radar) {
        $stmt_radar->bind_param("i", $selected_anak_id);
        $stmt_radar->execute();
        $result_radar = $stmt_radar->get_result();
        if ($row_radar = $result_radar->fetch_assoc()) {
            // Pastikan tidak ada null values
            $radar_data = [
                'w_learn' => floatval($row_radar['w_learn'] ?? 0),
                'concentration' => floatval($row_radar['concentration'] ?? 0),
                'c_thinking' => floatval($row_radar['c_thinking'] ?? 0),
                'independence' => floatval($row_radar['independence'] ?? 0),
                'p_solving' => floatval($row_radar['p_solving'] ?? 0)
            ];
        }
        $stmt_radar->close();
    }
    
    // 4. ANALISIS & INSIGHTS
    if (!empty($trend_data)) {
        $trend_data_reverse = array_reverse($trend_data); // Urut dari lama ke baru
        
        // Hitung perkembangan
        if (count($trend_data_reverse) >= 2) {
            $first_month = $trend_data_reverse[0];
            $last_month = end($trend_data_reverse);
            
            $score_change = $last_month['rata_skor'] - $first_month['rata_skor'];
            $percent_change = $first_month['rata_skor'] > 0 ? 
                ($score_change / $first_month['rata_skor']) * 100 : 0;
            
            // Tentukan trend
            if ($score_change > 3) {
                $trend_icon = '↗';
                $trend_text = 'Meningkat signifikan';
                $trend_color = 'text-green-600';
            } elseif ($score_change > 0) {
                $trend_icon = '→';
                $trend_text = 'Stabil cenderung naik';
                $trend_color = 'text-blue-600';
            } elseif ($score_change < -3) {
                $trend_icon = '↘';
                $trend_text = 'Menurun perlu perhatian';
                $trend_color = 'text-red-600';
            } else {
                $trend_icon = '→';
                $trend_text = 'Stabil';
                $trend_color = 'text-gray-600';
            }
            
            $insights['trend'] = [
                'icon' => $trend_icon,
                'text' => $trend_text,
                'color' => $trend_color,
                'score_change' => round($score_change, 1),
                'percent_change' => round($percent_change, 1)
            ];
        }
        
        // Cari area terkuat dan terlemah dari radar data
        if (!empty($radar_data)) {
            $indicators = [
                'w_learn' => 'Kemauan Belajar',
                'concentration' => 'Konsentrasi',
                'c_thinking' => 'Berpikir Kritis',
                'independence' => 'Kemandirian',
                'p_solving' => 'Pemecahan Masalah'
            ];
            
            // Cari 2 terbaik dan 2 terlemah
            $sorted_data = $radar_data;
            arsort($sorted_data); // Urut dari tinggi ke rendah
            $top_2 = array_slice($sorted_data, 0, 2, true);
            $bottom_2 = array_slice($sorted_data, -2, 2, true);
            
            $insights['strengths'] = [];
            $insights['weaknesses'] = [];
            
            foreach ($top_2 as $key => $value) {
                $insights['strengths'][] = [
                    'name' => $indicators[$key] ?? $key,
                    'score' => round($value, 1)
                ];
            }
            
            foreach ($bottom_2 as $key => $value) {
                $insights['weaknesses'][] = [
                    'name' => $indicators[$key] ?? $key,
                    'score' => round($value, 1)
                ];
            }
        }
        
        // Prediksi bulan depan
        if (count($trend_data) >= 3) {
            $recent_scores = array_column($trend_data, 'rata_skor');
            $recent_scores = array_slice($recent_scores, 0, 3); // Ambil 3 terbaru
            
            if (count($recent_scores) >= 3) {
                $avg_growth = ($recent_scores[0] - $recent_scores[2]) / 2;
                $predicted_score = $recent_scores[0] + $avg_growth;
                
                // Pastikan skor dalam rentang 0-50
                $predicted_score = max(0, min(50, $predicted_score));
                
                $insights['prediction'] = [
                    'score' => round($predicted_score, 1),
                    'confidence' => abs($avg_growth) > 2 ? 'Tinggi' : 
                                    (abs($avg_growth) > 1 ? 'Sedang' : 'Rendah')
                ];
            }
        }
    }
}

// Data untuk chart - PERBAIKAN: Pastikan data tersedia
$chart_labels = [];
$chart_scores = [];
$chart_percentages = [];

if (!empty($trend_data)) {
    $trend_data_reverse = array_reverse($trend_data);
    foreach ($trend_data_reverse as $data) {
        $chart_labels[] = $data['bulan_format'];
        $chart_scores[] = round($data['rata_skor'], 1);
        $chart_percentages[] = round($data['rata_persen'], 1);
    }
}

// Data untuk radar chart
$radar_labels = ['Kemauan Belajar', 'Konsentrasi', 'Berpikir Kritis', 'Kemandirian', 'Pemecahan Masalah'];
$radar_values = [
    round($radar_data['w_learn'] ?? 0, 1),
    round($radar_data['concentration'] ?? 0, 1),
    round($radar_data['c_thinking'] ?? 0, 1),
    round($radar_data['independence'] ?? 0, 1),
    round($radar_data['p_solving'] ?? 0, 1)
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perkembangan Anak - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .progress-bar {
            transition: width 0.5s ease-in-out;
        }
        .trend-up { color: #10B981; }
        .trend-down { color: #EF4444; }
        .trend-stable { color: #6B7280; }
        
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
        }

        /* Sidebar menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        /* Mobile Menu Styles */
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

        /* Chart responsive */
        @media (max-width: 767px) {
            .chart-container {
                height: 250px !important;
            }
            
            .stat-cards {
                grid-template-columns: repeat(2, 1fr) !important;
            }
            
            .insights-grid {
                grid-template-columns: 1fr !important;
            }
            
            .detail-table {
                font-size: 0.8rem;
            }
            
            .detail-table th,
            .detail-table td {
                padding: 0.5rem !important;
            }
        }

        @media (max-width: 640px) {
            .chart-grid {
                grid-template-columns: 1fr !important;
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
                    <h1 class="text-2xl font-bold text-gray-800">Perkembangan Anak</h1>
                    <p class="text-gray-600 text-sm md:text-base">Analisis trend dan pola perkembangan belajar anak</p>
                </div>
                <div class="mt-4 md:mt-0 w-full md:w-auto">
                    <!-- Pilih Anak Dropdown -->
                    <?php if (!empty($anak_data)): ?>
                    <div class="relative">
                        <form method="GET" class="flex items-center">
                            <select name="anak_id" onchange="this.form.submit()" 
                                    class="w-full md:w-auto border border-gray-300 rounded-lg px-4 py-2 bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <?php foreach ($anak_data as $anak): ?>
                                <option value="<?php echo $anak['id']; ?>" 
                                        <?php echo $selected_anak_id == $anak['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?> 
                                    (<?php echo $anak['kelas']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="ml-2 px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i class="fas fa-sync-alt"></i>
                            </button>
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
                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-sm text-yellow-700">
                            <strong>Debug Info:</strong><br>
                            Orangtua ID: <?php echo $orangtua_id; ?><br>
                            User ID: <?php echo $user_id; ?><br>
                            Nama Orangtua: <?php echo htmlspecialchars($nama_ortu); ?>
                        </p>
                    </div>
                    <a href="dashboardOrtu.php" class="mt-4 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm md:text-base">
                        <i class="fas fa-arrow-left mr-2"></i> Kembali ke Dashboard
                    </a>
                </div>
            
            <?php elseif ($selected_anak_id == 0): ?>
                <!-- Pilih Anak Terlebih Dahulu -->
                <div class="text-center py-12 md:py-16">
                    <i class="fas fa-user-graduate text-5xl md:text-6xl text-gray-400 mb-4"></i>
                    <h3 class="text-xl md:text-2xl font-bold text-gray-700 mb-2">Pilih anak terlebih dahulu</h3>
                    <p class="text-gray-600 text-sm md:text-base">Silakan pilih salah satu anak untuk melihat perkembangan mereka.</p>
                </div>
            
            <?php elseif (empty($trend_data)): ?>
                <!-- Belum Ada Data Penilaian -->
                <div class="text-center py-12 md:py-16">
                    <i class="fas fa-chart-line text-5xl md:text-6xl text-gray-400 mb-4"></i>
                    <h3 class="text-xl md:text-2xl font-bold text-gray-700 mb-2">Belum ada data perkembangan</h3>
                    <p class="text-gray-600 mb-4 text-sm md:text-base">Anak <strong><?php echo htmlspecialchars($selected_anak_name); ?></strong> belum memiliki data penilaian.</p>
                    <p class="text-gray-500 text-sm md:text-base">Data perkembangan akan muncul setelah anak mendapatkan penilaian dari guru.</p>
                </div>
            
            <?php else: ?>
                <!-- ADA DATA - TAMPILKAN DASHBOARD PERKEMBANGAN -->
                
                <!-- Header Anak -->
                <div class="mb-6 md:mb-8">
                    <div class="bg-gradient-to-r from-blue-600 to-blue-700 rounded-xl p-4 md:p-6 text-white shadow-lg">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                            <div class="flex-1">
                                <h2 class="text-xl md:text-2xl lg:text-3xl font-bold mb-2">
                                    <?php echo htmlspecialchars($selected_anak_name); ?>
                                </h2>
                                <div class="flex flex-wrap gap-2 md:gap-4">
                                    <span class="bg-white/20 px-2 py-1 md:px-3 md:py-1 rounded-full text-xs md:text-sm">
                                        <i class="fas fa-graduation-cap mr-1"></i>
                                        <?php 
                                        $selected_anak = array_filter($anak_data, fn($a) => $a['id'] == $selected_anak_id);
                                        $selected_anak = reset($selected_anak);
                                        echo $selected_anak['kelas'] ?? '-';
                                        ?>
                                    </span>
                                    <span class="bg-white/20 px-2 py-1 md:px-3 md:py-1 rounded-full text-xs md:text-sm">
                                        <i class="fas fa-school mr-1"></i>
                                        <?php echo $selected_anak['sekolah_asal'] ?? '-'; ?>
                                    </span>
                                    <span class="bg-white/20 px-2 py-1 md:px-3 md:py-1 rounded-full text-xs md:text-sm">
                                        <i class="fas fa-clipboard-check mr-1"></i>
                                        <?php echo count($trend_data); ?> Periode
                                    </span>
                                </div>
                            </div>
                            <?php if (isset($insights['trend'])): ?>
                            <div class="mt-4 md:mt-0 text-center md:text-right">
                                <div class="text-3xl md:text-4xl lg:text-5xl font-bold <?php echo $insights['trend']['color']; ?>">
                                    <?php echo $insights['trend']['icon']; ?>
                                </div>
                                <p class="text-xs md:text-sm opacity-90"><?php echo $insights['trend']['text']; ?></p>
                                <p class="text-xs opacity-75">
                                    <?php echo $insights['trend']['score_change'] > 0 ? '+' : ''; ?>
                                    <?php echo $insights['trend']['score_change']; ?> poin
                                    (<?php echo $insights['trend']['percent_change'] > 0 ? '+' : ''; ?>
                                    <?php echo $insights['trend']['percent_change']; ?>%)
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Statistik Ringkas -->
                <div class="grid stat-cards grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                    <div class="card bg-white rounded-xl shadow p-4">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 bg-blue-100 rounded-lg mr-2 md:mr-4">
                                <i class="fas fa-chart-line text-blue-600 text-base md:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Rata-rata Skor</p>
                                <p class="text-lg md:text-2xl font-bold text-gray-800">
                                    <?php echo round(end($trend_data)['rata_skor'] ?? 0, 1); ?>/50
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="flex justify-between text-xs md:text-sm text-gray-600 mb-1">
                                <span>Min: <?php echo min(array_column($trend_data, 'min_skor')); ?></span>
                                <span>Max: <?php echo max(array_column($trend_data, 'max_skor')); ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 md:h-2">
                                <?php 
                                $current_score = end($trend_data)['rata_skor'] ?? 0;
                                $percentage = ($current_score / 50) * 100;
                                $color = $percentage >= 80 ? 'bg-green-500' : ($percentage >= 60 ? 'bg-yellow-500' : 'bg-red-500');
                                ?>
                                <div class="h-1.5 md:h-2 rounded-full <?php echo $color; ?>" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-white rounded-xl shadow p-4">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 bg-green-100 rounded-lg mr-2 md:mr-4">
                                <i class="fas fa-percentage text-green-600 text-base md:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Rata-rata %</p>
                                <p class="text-lg md:text-2xl font-bold text-gray-800">
                                    <?php echo round(end($trend_data)['rata_persen'] ?? 0, 1); ?>%
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="flex justify-between text-xs md:text-sm text-gray-600 mb-1">
                                <span>Kategori</span>
                                <span class="font-medium">
                                    <?php 
                                    $avg_percent = end($trend_data)['rata_persen'] ?? 0;
                                    if ($avg_percent >= 80) echo 'Sangat Baik';
                                    elseif ($avg_percent >= 60) echo 'Baik';
                                    elseif ($avg_percent >= 40) echo 'Cukup';
                                    else echo 'Kurang';
                                    ?>
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 md:h-2">
                                <div class="h-1.5 md:h-2 rounded-full bg-gradient-to-r from-green-400 to-green-600" 
                                     style="width: <?php echo $avg_percent; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-2 md:col-span-1 card bg-white rounded-xl shadow p-4">
                        <div class="flex items-center">
                            <div class="p-2 md:p-3 bg-purple-100 rounded-lg mr-2 md:mr-4">
                                <i class="fas fa-calendar-alt text-purple-600 text-base md:text-xl"></i>
                            </div>
                            <div>
                                <p class="text-xs md:text-sm text-gray-600">Penilaian/Bulan</p>
                                <p class="text-lg md:text-2xl font-bold text-gray-800">
                                    <?php echo round(array_sum(array_column($trend_data, 'jumlah_penilaian')) / count($trend_data), 1); ?>
                                </p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <p class="text-xs md:text-sm text-gray-600">Periode: <?php echo count($trend_data); ?> bulan</p>
                            <p class="text-xs text-gray-500">Total: <?php echo array_sum(array_column($trend_data, 'jumlah_penilaian')); ?> penilaian</p>
                        </div>
                    </div>
                </div>

                <!-- Grafik Utama -->
                <div class="grid chart-grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Line Chart - Trend Perkembangan -->
                    <div class="card bg-white rounded-xl shadow p-4 md:p-6">
                        <div class="flex justify-between items-center mb-4 md:mb-6">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800">
                                <i class="fas fa-chart-line mr-2"></i> Trend Perkembangan
                            </h3>
                            <span class="text-xs md:text-sm text-gray-500">6 Bulan Terakhir</span>
                        </div>
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                        <div class="mt-3 md:mt-4 text-xs md:text-sm text-gray-600">
                            <p>Grafik perkembangan skor rata-rata per bulan.</p>
                        </div>
                    </div>

                    <!-- Radar Chart - Profil Kompetensi -->
                    <div class="card bg-white rounded-xl shadow p-4 md:p-6">
                        <div class="flex justify-between items-center mb-4 md:mb-6">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800">
                                <i class="fas fa-bullseye mr-2"></i> Profil Kompetensi
                            </h3>
                            <span class="text-xs md:text-sm text-gray-500">Rata-rata 5 Indikator</span>
                        </div>
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="radarChart"></canvas>
                        </div>
                        <div class="mt-3 md:mt-4 text-xs md:text-sm text-gray-600">
                            <p>Radar chart menunjukkan kekuatan dan area perbaikan.</p>
                        </div>
                    </div>
                </div>

                <!-- Insights & Rekomendasi -->
                <div class="grid insights-grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Area Terkuat -->
                    <div class="card bg-white rounded-xl shadow p-4 md:p-6">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">
                            <i class="fas fa-trophy text-green-600 mr-2"></i> Area Terkuat
                        </h3>
                        <?php if (isset($insights['strengths']) && !empty($insights['strengths'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($insights['strengths'] as $strength): ?>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                                    <div class="flex justify-between items-center mb-1 md:mb-2">
                                        <span class="font-medium text-green-800 text-sm md:text-base"><?php echo $strength['name']; ?></span>
                                        <span class="font-bold text-green-600 text-sm md:text-base"><?php echo $strength['score']; ?>/10</span>
                                    </div>
                                    <div class="w-full bg-green-100 rounded-full h-1.5 md:h-2">
                                        <div class="bg-green-500 h-1.5 md:h-2 rounded-full" 
                                             style="width: <?php echo ($strength['score'] / 10) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-3 md:py-4 text-sm md:text-base">Belum ada data yang cukup</p>
                        <?php endif; ?>
                    </div>

                    <!-- Area Perbaikan -->
                    <div class="card bg-white rounded-xl shadow p-4 md:p-6">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">
                            <i class="fas fa-tools text-yellow-600 mr-2"></i> Area Perbaikan
                        </h3>
                        <?php if (isset($insights['weaknesses']) && !empty($insights['weaknesses'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($insights['weaknesses'] as $weakness): ?>
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                    <div class="flex justify-between items-center mb-1 md:mb-2">
                                        <span class="font-medium text-yellow-800 text-sm md:text-base"><?php echo $weakness['name']; ?></span>
                                        <span class="font-bold text-yellow-600 text-sm md:text-base"><?php echo $weakness['score']; ?>/10</span>
                                    </div>
                                    <div class="w-full bg-yellow-100 rounded-full h-1.5 md:h-2">
                                        <div class="bg-yellow-500 h-1.5 md:h-2 rounded-full" 
                                             style="width: <?php echo ($weakness['score'] / 10) * 100; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-3 md:py-4 text-sm md:text-base">Belum ada data yang cukup</p>
                        <?php endif; ?>
                    </div>

                    <!-- Prediksi & Target -->
                    <div class="card bg-white rounded-xl shadow p-4 md:p-6">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">
                            <i class="fas fa-bullseye text-blue-600 mr-2"></i> Prediksi & Target
                        </h3>
                        <div class="space-y-4 md:space-y-6">
                            <?php if (isset($insights['prediction'])): ?>
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 md:p-4">
                                <h4 class="font-medium text-blue-800 mb-1 md:mb-2 text-sm md:text-base">Prediksi Bulan Depan</h4>
                                <div class="text-xl md:text-3xl font-bold text-blue-600 mb-1">
                                    <?php echo $insights['prediction']['score']; ?>/50
                                </div>
                                <p class="text-xs md:text-sm text-blue-600">
                                    Tingkat Kepercayaan: <?php echo $insights['prediction']['confidence']; ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 md:p-4">
                                <h4 class="font-medium text-purple-800 mb-2 text-sm md:text-base">Rekomendasi</h4>
                                <ul class="space-y-1 md:space-y-2 text-xs md:text-sm text-purple-700">
                                    <?php if (isset($insights['trend']) && $insights['trend']['score_change'] > 0): ?>
                                    <li class="flex items-start">
                                        <i class="fas fa-check-circle text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                        <span>Pertahankan trend positif dengan konsisten belajar</span>
                                    </li>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($insights['weaknesses'])): ?>
                                    <li class="flex items-start">
                                        <i class="fas fa-lightbulb text-yellow-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                        <span>Fokus pada area perbaikan dengan latihan khusus</span>
                                    </li>
                                    <?php endif; ?>
                                    
                                    <li class="flex items-start">
                                        <i class="fas fa-calendar-check text-blue-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                        <span>Target: <?php echo round(end($trend_data)['rata_skor'] + 1, 1); ?>/50</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detail Bulanan -->
                <div class="card bg-white rounded-xl shadow mb-6 md:mb-8">
                    <div class="p-4 md:p-6 border-b">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800">
                            <i class="fas fa-calendar-alt mr-2"></i> Detail Per Bulan
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 detail-table">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Bulan
                                    </th>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Jumlah
                                    </th>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Skor
                                    </th>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        %
                                    </th>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Kategori
                                    </th>
                                    <th class="px-3 md:px-6 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Range
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($trend_data as $data): 
                                    $kategori = '';
                                    if ($data['rata_persen'] >= 80) $kategori = 'Sangat Baik';
                                    elseif ($data['rata_persen'] >= 60) $kategori = 'Baik';
                                    elseif ($data['rata_persen'] >= 40) $kategori = 'Cukup';
                                    else $kategori = 'Kurang';
                                    
                                    $badge_class = '';
                                    if ($kategori == 'Sangat Baik') $badge_class = 'bg-green-100 text-green-800';
                                    elseif ($kategori == 'Baik') $badge_class = 'bg-blue-100 text-blue-800';
                                    elseif ($kategori == 'Cukup') $badge_class = 'bg-yellow-100 text-yellow-800';
                                    else $badge_class = 'bg-red-100 text-red-800';
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap">
                                        <div class="font-medium text-gray-900 text-xs md:text-sm"><?php echo $data['bulan_format']; ?></div>
                                    </td>
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap">
                                        <span class="px-2 md:px-3 py-0.5 md:py-1 bg-gray-100 text-gray-800 rounded-full text-xs">
                                            <?php echo $data['jumlah_penilaian']; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap">
                                        <div class="font-bold text-gray-900 text-sm md:text-base">
                                            <?php echo round($data['rata_skor'], 1); ?>
                                        </div>
                                    </td>
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-12 md:w-16 bg-gray-200 rounded-full h-1.5 md:h-2.5 mr-2">
                                                <div class="bg-blue-600 h-1.5 md:h-2.5 rounded-full" 
                                                     style="width: <?php echo $data['rata_persen']; ?>%"></div>
                                            </div>
                                            <span class="font-medium text-xs md:text-sm"><?php echo round($data['rata_persen'], 1); ?>%</span>
                                        </div>
                                    </td>
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap">
                                        <span class="px-2 md:px-3 py-0.5 md:py-1 rounded-full text-xs font-medium <?php echo $badge_class; ?>">
                                            <?php echo $kategori; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 md:px-6 py-2 md:py-4 whitespace-nowrap text-xs text-gray-500">
                                        <?php echo $data['min_skor']; ?>-<?php echo $data['max_skor']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tips & Saran -->
                <div class="card bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl shadow p-4 md:p-6">
                    <h3 class="text-base md:text-lg font-semibold text-gray-800 mb-3 md:mb-4">
                        <i class="fas fa-lightbulb text-yellow-600 mr-2"></i> Tips untuk Orang Tua
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div>
                            <h4 class="font-medium text-gray-700 mb-1 md:mb-2 text-sm md:text-base">Berdasarkan Data:</h4>
                            <ul class="space-y-1 md:space-y-2">
                                <?php if (isset($insights['trend']) && $insights['trend']['score_change'] > 0): ?>
                                <li class="flex items-start">
                                    <i class="fas fa-check text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Berikan apresiasi untuk perkembangan positif</span>
                                </li>
                                <?php endif; ?>
                                
                                <?php if (isset($insights['weaknesses'])): ?>
                                <li class="flex items-start">
                                    <i class="fas fa-exclamation-triangle text-yellow-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Diskusikan area perbaikan dengan guru bimbel</span>
                                </li>
                                <?php endif; ?>
                                
                                <li class="flex items-start">
                                    <i class="fas fa-clock text-blue-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Pantau perkembangan bulanan secara konsisten</span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-gray-700 mb-1 md:mb-2 text-sm md:text-base">Strategi Belajar:</h4>
                            <ul class="space-y-1 md:space-y-2">
                                <li class="flex items-start">
                                    <i class="fas fa-book text-purple-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Buat jadwal belajar rutin di rumah</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-gamepad text-green-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Gunakan metode belajar yang menyenangkan</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-comments text-red-500 mt-0.5 md:mt-1 mr-1 md:mr-2 text-xs md:text-sm"></i>
                                    <span class="text-xs md:text-sm">Komunikasi intens dengan guru bimbel</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Perkembangan Anak</p>
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

        // Charts
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($chart_labels) && !empty($chart_scores)): ?>
            // Trend Chart
            const trendCtx = document.getElementById('trendChart').getContext('2d');
            window.trendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Rata-rata Skor',
                        data: <?php echo json_encode($chart_scores); ?>,
                        borderColor: '#3B82F6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Rata-rata %',
                        data: <?php echo json_encode($chart_percentages); ?>,
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 1,
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Skor (max 50)'
                            },
                            min: 0,
                            max: 50
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Persentase %'
                            },
                            min: 0,
                            max: 100,
                            grid: {
                                drawOnChartArea: false,
                            },
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            bodyFont: {
                                size: window.innerWidth < 640 ? 10 : 12
                            },
                            titleFont: {
                                size: window.innerWidth < 640 ? 10 : 12
                            }
                        }
                    }
                }
            });

            // Radar Chart
            const radarCtx = document.getElementById('radarChart').getContext('2d');
            window.radarChart = new Chart(radarCtx, {
                type: 'radar',
                data: {
                    labels: <?php echo json_encode($radar_labels); ?>,
                    datasets: [{
                        label: 'Rata-rata Skor',
                        data: <?php echo json_encode($radar_values); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderColor: '#3B82F6',
                        borderWidth: 1,
                        pointBackgroundColor: '#3B82F6',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#3B82F6'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            angleLines: {
                                display: true
                            },
                            suggestedMin: 0,
                            suggestedMax: 10,
                            ticks: {
                                stepSize: 2,
                                font: {
                                    size: window.innerWidth < 640 ? 8 : 10
                                }
                            },
                            pointLabels: {
                                font: {
                                    size: window.innerWidth < 640 ? 8 : 10
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
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
        
        // Handle window resize for charts
        window.addEventListener('resize', function() {
            <?php if (!empty($chart_labels) && !empty($chart_scores)): ?>
            // Update chart font sizes on resize
            if (window.trendChart && window.radarChart) {
                const fontSize = window.innerWidth < 640 ? 10 : 12;
                const pointLabelSize = window.innerWidth < 640 ? 8 : 10;
                
                window.trendChart.options.plugins.legend.labels.font.size = fontSize;
                window.trendChart.options.plugins.tooltip.bodyFont.size = fontSize;
                window.trendChart.options.plugins.tooltip.titleFont.size = fontSize;
                window.trendChart.update();
                
                window.radarChart.options.scales.r.ticks.font.size = pointLabelSize;
                window.radarChart.options.scales.r.pointLabels.font.size = pointLabelSize;
                window.radarChart.options.plugins.legend.labels.font.size = fontSize;
                window.radarChart.update();
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>