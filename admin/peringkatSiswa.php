<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// =============== FUNGSI BANTU ===============
function formatPeriodeBulan($ym)
{
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $ym, $m)) {
        return (string) $ym;
    }
    $nama_bulan = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
    return ($nama_bulan[$m[2]] ?? $m[2]) . ' ' . $m[1];
}

function safeLower($text)
{
    $str = (string) ($text ?? '');
    return function_exists('mb_strtolower') ? mb_strtolower($str, 'UTF-8') : strtolower($str);
}

function getKategoriByPersentase($persentase)
{
    if ($persentase === null || $persentase === 0) {
        return 'Belum Dinilai';
    }
    if ($persentase >= 80) {
        return 'Sangat Baik';
    }
    if ($persentase >= 60) {
        return 'Baik';
    }
    if ($persentase >= 40) {
        return 'Cukup';
    }
    return 'Kurang';
}

// =============== FILTER PARAMETER (VPS-SAFE ALLOWLIST) ===============
$allowed_jenjang = ['TK', 'SD', 'SMP', 'SMA', 'Alumni', 'Umum'];

$rank_bulan_tahun = isset($_GET['bulan_tahun']) ? trim((string) $_GET['bulan_tahun']) : date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rank_bulan_tahun)) {
    $rank_bulan_tahun = date('Y-m');
}

$rank_jenjang = isset($_GET['jenjang']) && in_array($_GET['jenjang'], $allowed_jenjang, true) ? $_GET['jenjang'] : '';
$rank_min_sesi = isset($_GET['min_sesi']) && is_numeric($_GET['min_sesi']) ? (int) $_GET['min_sesi'] : 4;
if ($rank_min_sesi < 1) {
    $rank_min_sesi = 1;
}
if ($rank_min_sesi > 31) {
    $rank_min_sesi = 31;
}
$rank_cari = isset($_GET['cari_ranking']) ? trim((string) $_GET['cari_ranking']) : '';

// =============== AMBIL DATA PERINGKAT BULANAN (ONLY_FULL_GROUP_BY SAFE) ===============
$ranking_all_rows = [];
$ranking_qualified = [];
$ranking_unqualified = [];
$ranking_qualified_display = [];
$ranking_unqualified_display = [];
$juara_per_jenjang = [];
$pengumuman_per_jenjang = [];
$ranking_kpi = [
    'total_qualified' => 0,
    'total_unqualified' => 0,
    'avg_kelompok' => 0.0,
    'top_siswa' => null
];
$rank_periode_label = formatPeriodeBulan($rank_bulan_tahun);
$jenjang_display_order = ['TK', 'SD', 'SMP', 'SMA'];

$rank_start_date = $rank_bulan_tahun . '-01';
$rank_end_date = date('Y-m-d', strtotime($rank_start_date . ' +1 month'));

$rank_sql = "SELECT
                s.id AS siswa_id,
                s.nama_lengkap,
                s.kelas AS kelas_sekolah,
                CASE
                    WHEN s.kelas IN ('Paud', 'TK') THEN 'TK'
                    WHEN s.kelas IN ('1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD') THEN 'SD'
                    WHEN s.kelas IN ('7 SMP', '8 SMP', '9 SMP') THEN 'SMP'
                    WHEN s.kelas IN ('10 SMA', '11 SMA', '12 SMA') THEN 'SMA'
                    WHEN s.kelas IN ('Alumni', 'Umum') THEN s.kelas
                    ELSE MAX(pd.tingkat)
                END AS jenjang,
                GROUP_CONCAT(DISTINCT pd.jenis_kelas ORDER BY pd.jenis_kelas SEPARATOR ', ') AS jenis_kelas,
                COUNT(ps.id) AS total_sesi,
                ROUND(AVG(ps.total_score), 2) AS rata_score,
                ROUND(AVG(ps.persentase), 1) AS rata_persentase,
                ROUND(AVG(ps.willingness_learn), 1) AS avg_willingness,
                ROUND(AVG(ps.problem_solving), 1) AS avg_problem_solving,
                ROUND(AVG(ps.critical_thinking), 1) AS avg_critical_thinking,
                ROUND(AVG(ps.concentration), 1) AS avg_concentration,
                ROUND(AVG(ps.independence), 1) AS avg_independence,
                COALESCE(MAX(ab.total_hadir), 0) AS total_hadir
            FROM penilaian_siswa ps
            INNER JOIN siswa s ON ps.siswa_id = s.id
            INNER JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            LEFT JOIN (
                SELECT siswa_id, COUNT(*) AS total_hadir
                FROM absensi_siswa
                WHERE status = 'hadir'
                  AND tanggal_absensi >= ?
                  AND tanggal_absensi < ?
                GROUP BY siswa_id
            ) ab ON ab.siswa_id = s.id
            WHERE ps.tanggal_penilaian >= ?
              AND ps.tanggal_penilaian < ?
            GROUP BY s.id, s.nama_lengkap, s.kelas
            ORDER BY rata_score DESC, total_sesi DESC, total_hadir DESC, s.nama_lengkap ASC";

$rank_stmt = $conn->prepare($rank_sql);
if ($rank_stmt) {
    $rank_stmt->bind_param("ssss", $rank_start_date, $rank_end_date, $rank_start_date, $rank_end_date);
    $rank_stmt->execute();
    $rank_res = $rank_stmt->get_result();

    while ($r = $rank_res->fetch_assoc()) {
        $r['siswa_id'] = (int) $r['siswa_id'];
        $r['total_sesi'] = (int) $r['total_sesi'];
        $r['total_hadir'] = (int) $r['total_hadir'];
        $r['rata_score'] = (float) $r['rata_score'];
        $r['rata_persentase'] = (float) $r['rata_persentase'];
        $r['avg_willingness'] = (float) ($r['avg_willingness'] ?? 0);
        $r['avg_problem_solving'] = (float) ($r['avg_problem_solving'] ?? 0);
        $r['avg_critical_thinking'] = (float) ($r['avg_critical_thinking'] ?? 0);
        $r['avg_concentration'] = (float) ($r['avg_concentration'] ?? 0);
        $r['avg_independence'] = (float) ($r['avg_independence'] ?? 0);
        $r['kategori_display'] = getKategoriByPersentase($r['rata_persentase']);
        $ranking_all_rows[] = $r;
    }
    $rank_stmt->close();
}

// Susun daftar juara & top 3 per jenjang (untuk kartu semua jenjang & modal ringkasan)
foreach ($jenjang_display_order as $j_key) {
    $juara_per_jenjang[$j_key] = null;
    $pengumuman_per_jenjang[$j_key] = [];
}
foreach ($ranking_all_rows as $row) {
    $j = $row['jenjang'] ?? '';
    if ($row['total_sesi'] >= $rank_min_sesi && $j !== '') {
        if (!array_key_exists($j, $pengumuman_per_jenjang)) {
            $pengumuman_per_jenjang[$j] = [];
        }
        if (count($pengumuman_per_jenjang[$j]) < 3) {
            $pengumuman_per_jenjang[$j][] = $row;
        }
        if (!isset($juara_per_jenjang[$j])) {
            $juara_per_jenjang[$j] = $row;
        }
    }
}

// Filter sesuai jenjang terpilih lalu pisahkan memenuhi syarat vs belum memenuhi syarat
foreach ($ranking_all_rows as $row) {
    if (!empty($rank_jenjang) && ($row['jenjang'] ?? '') !== $rank_jenjang) {
        continue;
    }
    if ($row['total_sesi'] >= $rank_min_sesi) {
        $ranking_qualified[] = $row;
    } else {
        $ranking_unqualified[] = $row;
    }
}

// Beri nomor peringkat asli SEBELUM filter pencarian nama
$sum_score = 0.0;
foreach ($ranking_qualified as $idx => &$qrow) {
    $qrow['rank'] = $idx + 1;
    $sum_score += $qrow['rata_score'];
}
unset($qrow);

$ranking_kpi['total_qualified'] = count($ranking_qualified);
$ranking_kpi['total_unqualified'] = count($ranking_unqualified);
$ranking_kpi['avg_kelompok'] = $ranking_kpi['total_qualified'] > 0
    ? round($sum_score / $ranking_kpi['total_qualified'], 1)
    : 0.0;
$ranking_kpi['top_siswa'] = $ranking_qualified[0] ?? null;

// Terapkan pencarian nama/kelas tanpa mengubah nomor rank asli
if ($rank_cari !== '') {
    $needle = safeLower($rank_cari);
    foreach ($ranking_qualified as $row) {
        $haystack = safeLower(($row['nama_lengkap'] ?? '') . ' ' . ($row['kelas_sekolah'] ?? ''));
        if (strpos($haystack, $needle) !== false) {
            $ranking_qualified_display[] = $row;
        }
    }
    foreach ($ranking_unqualified as $row) {
        $haystack = safeLower(($row['nama_lengkap'] ?? '') . ' ' . ($row['kelas_sekolah'] ?? ''));
        if (strpos($haystack, $needle) !== false) {
            $ranking_unqualified_display[] = $row;
        }
    }
} else {
    $ranking_qualified_display = $ranking_qualified;
    $ranking_unqualified_display = $ranking_unqualified;
}

/* Export Excel dinonaktifkan sementara
if (isset($_GET['export_ranking']) && $_GET['export_ranking'] === 'excel') {
    $jenjang_slug = !empty($rank_jenjang) ? strtolower($rank_jenjang) : 'semua_jenjang';
    $filename = 'peringkat_bulanan_' . $rank_bulan_tahun . '_' . $jenjang_slug . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<table border='1'>";
    $judul_excel = 'Peringkat Murid Terbaik - ' . $rank_periode_label . ' (' . (!empty($rank_jenjang) ? 'Jenjang ' . $rank_jenjang : 'Semua Jenjang') . ', Min. ' . $rank_min_sesi . ' Sesi)';
    echo "<tr><th colspan='13' style='background:#1e40af;color:#ffffff;font-size:14px;padding:8px;'>" . htmlspecialchars($judul_excel, ENT_QUOTES, 'UTF-8') . "</th></tr>";
    echo "<tr style='background:#dbeafe;'>
            <th>Peringkat</th>
            <th>Nama Siswa</th>
            <th>Kelas</th>
            <th>Jenjang</th>
            <th>Program</th>
            <th>Sesi Dinilai</th>
            <th>Total Hadir</th>
            <th>Rata-rata Skor (/50)</th>
            <th>Rata-rata (%)</th>
            <th>Kategori</th>
            <th>Kemauan Belajar</th>
            <th>Pemecahan Masalah</th>
            <th>Berpikir Kritis / Konsentrasi / Kemandirian</th>
          </tr>";
    foreach ($ranking_qualified_display as $row) {
        echo "<tr>";
        echo "<td>#" . (int) $row['rank'] . "</td>";
        echo "<td>" . htmlspecialchars($row['nama_lengkap'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['jenjang'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['jenis_kelas'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . (int) $row['total_sesi'] . "</td>";
        echo "<td>" . (int) $row['total_hadir'] . "</td>";
        echo "<td>" . number_format($row['rata_score'], 2) . "</td>";
        echo "<td>" . number_format($row['rata_persentase'], 1) . "%</td>";
        echo "<td>" . htmlspecialchars($row['kategori_display'] ?? '-', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . number_format($row['avg_willingness'], 1) . "</td>";
        echo "<td>" . number_format($row['avg_problem_solving'], 1) . "</td>";
        echo "<td>" . number_format($row['avg_critical_thinking'], 1) . " / " . number_format($row['avg_concentration'], 1) . " / " . number_format($row['avg_independence'], 1) . "</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
    $conn->close();
    exit();
}
*/
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../config/analytics.php'; ?>
    <title>Peringkat Siswa - Admin Bimbel Esc</title>
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

        .badge.sangat-baik {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge.baik {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge.cukup {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge.kurang {
            background-color: #fee2e2;
            color: #991b1b;
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
        }

        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }

        .podium-1 {
            border-top: 4px solid #eab308;
        }

        .podium-2 {
            border-top: 4px solid #9ca3af;
        }

        .podium-3 {
            border-top: 4px solid #d97706;
        }

        /* Mobile menu */
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

            .mobile-header {
                display: block;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .table-responsive {
                overflow-x: auto;
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

        @media print {
            body * {
                visibility: hidden;
            }

            #printArea,
            #printArea * {
                visibility: visible;
            }

            #printArea {
                position: fixed;
                left: 0;
                top: 0;
                width: 100%;
                background: #ffffff;
                padding: 20px;
                box-shadow: none !important;
            }

            .no-print {
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
            <p class="text-sm text-blue-200">Admin Dashboard</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
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
                <button id="menuToggle" class="text-white mr-3"><i class="fas fa-bars text-xl"></i></button>
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-3">
                    <p class="text-sm"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-xs text-blue-300">Admin</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
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
                    <button id="menuClose" class="text-white mr-3"><i class="fas fa-times text-xl"></i></button>
                    <h1 class="text-xl font-bold">Bimbel Esc</h1>
                </div>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-shield text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'admin'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64 min-h-screen flex flex-col">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-trophy text-yellow-500 mr-2"></i> Peringkat Siswa
                    </h1>
                    <p class="text-gray-600 text-sm">Peringkat murid terbaik bulanan berdasarkan rata-rata penilaian seluruh sesi harian per jenjang</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openPrintModal()"
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-indigo-600 text-white hover:bg-indigo-700 transition">
                        <i class="fas fa-eye mr-2"></i> Lihat Ringkasan
                    </button>
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i> <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6 flex-1">
            <?php
            /* Export Excel dinonaktifkan sementara
            $base_rank_query = [
                'bulan_tahun' => $rank_bulan_tahun,
                'jenjang' => $rank_jenjang,
                'min_sesi' => $rank_min_sesi,
                'cari_ranking' => $rank_cari
            ];
            $export_excel_url = 'peringkatSiswa.php?' . http_build_query(array_merge($base_rank_query, ['export_ranking' => 'excel']));
            */
            ?>

            <!-- Filter Peringkat Bulanan -->
            <div class="bg-white rounded-lg shadow p-5 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                    <h3 class="text-base font-semibold text-gray-800">
                        <i class="fas fa-filter text-blue-600 mr-2"></i> Filter Peringkat Bulanan
                    </h3>
                    <div class="flex items-center gap-2">
                        <?php /* Tombol Export Excel dinonaktifkan sementara
                        <a href="<?php echo htmlspecialchars($export_excel_url, ENT_QUOTES, 'UTF-8'); ?>"
                            class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-medium bg-green-600 text-white hover:bg-green-700 transition">
                            <i class="fas fa-file-excel mr-1.5"></i> Export Excel
                        </a>
                        */ ?>
                        <a href="peringkatSiswa.php?bulan_tahun=<?php echo urlencode(date('Y-m')); ?>"
                            class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                            <i class="fas fa-redo mr-1.5"></i> Reset Filter
                        </a>
                    </div>
                </div>

                <form method="GET" action="peringkatSiswa.php" id="rankingFilterForm">
                    <input type="hidden" name="jenjang" value="<?php echo htmlspecialchars($rank_jenjang, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Periode (Bulan/Tahun)</label>
                            <input type="month" name="bulan_tahun"
                                value="<?php echo htmlspecialchars($rank_bulan_tahun, ENT_QUOTES, 'UTF-8'); ?>"
                                onchange="this.form.submit()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                        </div>

                        <?php /* Filter dropdown Jenjang dinonaktifkan (gunakan Pintasan Jenjang di bawah)
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Jenjang</label>
                            <select name="jenjang" onchange="this.form.submit()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Jenjang (TK / SD / SMP / SMA)</option>
                                <?php foreach ($allowed_jenjang as $j_opt): ?>
                                    <option value="<?php echo $j_opt; ?>" <?php echo $rank_jenjang === $j_opt ? 'selected' : ''; ?>>
                                        <?php echo $j_opt === 'TK' ? 'TK / PAUD' : $j_opt; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        */ ?>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Syarat Minimal Sesi Dinilai</label>
                            <select name="min_sesi" onchange="this.form.submit()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                <?php foreach ([1 => 'Minimal 1 Sesi (Tampilkan semua)', 2 => 'Minimal 2 Sesi / bulan', 3 => 'Minimal 3 Sesi / bulan', 4 => 'Minimal 4 Sesi / bulan', 6 => 'Minimal 6 Sesi / bulan', 8 => 'Minimal 8 Sesi / bulan'] as $ms_val => $ms_label): ?>
                                    <option value="<?php echo $ms_val; ?>" <?php echo $rank_min_sesi === $ms_val ? 'selected' : ''; ?>>
                                        <?php echo $ms_label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Cari Siswa</label>
                            <div class="flex items-center gap-2">
                                <input type="text" name="cari_ranking"
                                    value="<?php echo htmlspecialchars($rank_cari, ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Cari nama siswa / kelas..."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                <button type="submit"
                                    class="px-3.5 py-2 rounded-lg text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 shrink-0"
                                    title="Cari">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pintasan Jenjang -->
                    <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-gray-100">
                        <span class="text-xs text-gray-500 mr-1">Pintasan Jenjang:</span>
                        <?php
                        $pill_items = ['' => 'Semua', 'TK' => 'TK', 'SD' => 'SD', 'SMP' => 'SMP', 'SMA' => 'SMA'];
                        foreach ($pill_items as $p_val => $p_label):
                            $pill_url = 'peringkatSiswa.php?' . http_build_query([
                                'bulan_tahun' => $rank_bulan_tahun,
                                'jenjang' => $p_val,
                                'min_sesi' => $rank_min_sesi
                            ]);
                            $is_pill_active = ($rank_jenjang === $p_val);
                            ?>
                            <a href="<?php echo htmlspecialchars($pill_url, ENT_QUOTES, 'UTF-8'); ?>"
                                class="px-3 py-1 rounded-full text-xs font-medium transition <?php echo $is_pill_active ? 'bg-blue-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?>">
                                <?php echo $p_label; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>

            <!-- 4 KPI Cards Peringkat -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="text-xs font-medium text-gray-500">Siswa Memenuhi Syarat</div>
                    <div class="text-2xl font-bold text-gray-900 mt-1">
                        <?php echo number_format($ranking_kpi['total_qualified']); ?>
                        <span class="text-xs font-normal text-gray-500">siswa</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">Min. <?php echo $rank_min_sesi; ?> sesi di <?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>

                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="text-xs font-medium text-gray-500">Rata-rata Skor Kelompok</div>
                    <div class="text-2xl font-bold text-blue-600 mt-1">
                        <?php echo number_format($ranking_kpi['avg_kelompok'], 1); ?><span class="text-sm font-normal text-gray-400">/50</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1"><?php echo !empty($rank_jenjang) ? 'Jenjang ' . htmlspecialchars($rank_jenjang, ENT_QUOTES, 'UTF-8') : 'Semua Jenjang'; ?></div>
                </div>

                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="text-xs font-medium text-gray-500">Peringkat #1 Saat Ini</div>
                    <div class="text-base font-bold text-gray-900 mt-1 truncate" title="<?php echo htmlspecialchars($ranking_kpi['top_siswa']['nama_lengkap'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($ranking_kpi['top_siswa']['nama_lengkap'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="text-xs text-green-600 font-medium mt-1">
                        <?php if ($ranking_kpi['top_siswa']): ?>
                            <?php echo number_format($ranking_kpi['top_siswa']['rata_score'], 2); ?>/50 (<?php echo (int) $ranking_kpi['top_siswa']['total_sesi']; ?> sesi)
                        <?php else: ?>
                            Belum ada data memenuhi syarat
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card bg-white border border-gray-200 rounded-lg p-4">
                    <div class="text-xs font-medium text-gray-500">Belum Penuhi Min. Sesi</div>
                    <div class="text-2xl font-bold text-amber-600 mt-1">
                        <?php echo number_format($ranking_kpi['total_unqualified']); ?>
                        <span class="text-xs font-normal text-gray-500">siswa</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">Ditampilkan terpisah di bawah</div>
                </div>
            </div>

            <!-- Highlight Juara: Kartu Per Jenjang (Semua) ATAU Tingkat Top 3 (1 Jenjang) -->
            <?php if (empty($rank_jenjang)): ?>
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-3">
                        <h2 class="text-base font-bold text-gray-800">
                            <i class="fas fa-award text-yellow-500 mr-2"></i> Murid Terbaik Per Jenjang — <?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                        <span class="text-xs text-gray-500">Klik kartu untuk melihat Tingkat Top 3 jenjang tersebut</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <?php foreach ($jenjang_display_order as $j_card): ?>
                            <?php
                            $winner = $juara_per_jenjang[$j_card] ?? null;
                            $card_url = 'peringkatSiswa.php?' . http_build_query([
                                'bulan_tahun' => $rank_bulan_tahun,
                                'jenjang' => $j_card,
                                'min_sesi' => $rank_min_sesi
                            ]);
                            ?>
                            <?php if ($winner): ?>
                                <a href="<?php echo htmlspecialchars($card_url, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="block bg-white border border-gray-200 podium-1 rounded-lg shadow-sm p-4 hover:shadow-md hover:border-blue-400 transition">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">Juara #1 <?php echo $j_card; ?></span>
                                        <span class="text-xs font-semibold text-yellow-600"><i class="fas fa-star mr-1"></i>Terbaik</span>
                                    </div>
                                    <div class="font-bold text-gray-900 text-sm truncate"><?php echo htmlspecialchars($winner['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        <?php echo htmlspecialchars($winner['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($winner['jenis_kelas'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="mt-3 pt-3 border-t border-gray-100 flex items-end justify-between">
                                        <div>
                                            <div class="text-xl font-bold text-green-600 leading-none">
                                                <?php echo number_format($winner['rata_score'], 2); ?><span class="text-xs font-normal text-gray-400">/50</span>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo number_format($winner['rata_persentase'], 1); ?>% &bull; <?php echo (int) $winner['total_sesi']; ?> sesi
                                            </div>
                                        </div>
                                        <span class="text-xs text-blue-600 font-medium">Lihat <?php echo $j_card; ?> &rarr;</span>
                                    </div>
                                </a>
                            <?php else: ?>
                                <div class="bg-white border border-gray-200 rounded-lg p-4 opacity-75">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">Jenjang <?php echo $j_card; ?></span>
                                        <span class="text-xs text-gray-400">-</span>
                                    </div>
                                    <p class="text-xs text-gray-500 py-3">Belum ada siswa memenuhi minimal <?php echo $rank_min_sesi; ?> sesi.</p>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php $top3 = array_slice($ranking_qualified, 0, 3); ?>
                <div class="mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-3">
                        <h2 class="text-base font-bold text-gray-800">
                            <i class="fas fa-trophy text-yellow-500 mr-2"></i> Tingkat Top 3 Jenjang <?php echo htmlspecialchars($rank_jenjang, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                        <a href="peringkatSiswa.php?<?php echo http_build_query(['bulan_tahun' => $rank_bulan_tahun, 'min_sesi' => $rank_min_sesi]); ?>"
                            class="text-xs text-blue-600 hover:underline">&larr; Lihat Semua Jenjang</a>
                    </div>
                    <?php if (count($top3) > 0): ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <?php
                            $podium_classes = ['podium-1', 'podium-2', 'podium-3'];
                            $podium_badges = [
                                'bg-yellow-100 text-yellow-800',
                                'bg-gray-100 text-gray-700',
                                'bg-amber-100 text-amber-800'
                            ];
                            foreach ($top3 as $p_idx => $p_item):
                                $audit_url = 'dataNilai.php?' . http_build_query([
                                    'bulan_tahun' => $rank_bulan_tahun,
                                    'siswa_id' => $p_item['siswa_id'],
                                    'nama_siswa' => $p_item['nama_lengkap'],
                                    'from_ranking' => 1,
                                    'jenjang' => $rank_jenjang,
                                    'min_sesi' => $rank_min_sesi
                                ]);
                                ?>
                                <div class="bg-white border border-gray-200 <?php echo $podium_classes[$p_idx]; ?> rounded-lg shadow-sm p-4 flex flex-col justify-between">
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $podium_badges[$p_idx]; ?>">
                                                Juara #<?php echo $p_idx + 1; ?> &bull; <?php echo htmlspecialchars($p_item['jenjang'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="text-xs text-gray-500"><?php echo htmlspecialchars($p_item['jenis_kelas'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <div class="font-bold text-gray-900 text-base"><?php echo htmlspecialchars($p_item['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            Kelas <?php echo htmlspecialchars($p_item['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8'); ?> &bull;
                                            <?php echo (int) $p_item['total_sesi']; ?> sesi dinilai (<?php echo (int) $p_item['total_hadir']; ?> hadir)
                                        </div>
                                    </div>
                                    <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
                                        <div>
                                            <div class="text-2xl font-bold text-blue-600 leading-none">
                                                <?php echo number_format($p_item['rata_score'], 2); ?><span class="text-xs font-normal text-gray-400">/50</span>
                                            </div>
                                            <div class="text-xs text-green-600 font-medium mt-1">
                                                Rata-rata <?php echo number_format($p_item['rata_persentase'], 1); ?>% (<?php echo htmlspecialchars($p_item['kategori_display'], ENT_QUOTES, 'UTF-8'); ?>)
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end gap-1">
                                            <button type="button" onclick="openIndikatorModal(<?php echo (int) $p_item['siswa_id']; ?>)"
                                                class="text-xs text-blue-600 hover:underline font-medium">5 Indikator</button>
                                            <a href="<?php echo htmlspecialchars($audit_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="text-xs text-gray-500 hover:text-gray-800">Lihat Sesi &rarr;</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-white border border-gray-200 rounded-lg p-6 text-center text-sm text-gray-500">
                            Belum ada siswa jenjang <strong><?php echo htmlspecialchars($rank_jenjang, ENT_QUOTES, 'UTF-8'); ?></strong> yang memenuhi syarat minimal <?php echo $rank_min_sesi; ?> sesi pada <?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?>.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Tabel Peringkat Lengkap -->
            <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                <div class="px-4 py-3 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-bold text-gray-800">
                            Daftar Peringkat Lengkap — <?php echo !empty($rank_jenjang) ? 'Jenjang ' . htmlspecialchars($rank_jenjang, ENT_QUOTES, 'UTF-8') : 'Semua Jenjang'; ?> (<?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?>)
                        </h3>
                        <p class="text-xs text-gray-500">Urutan: Rata-rata Skor tertinggi &rarr; Jumlah Sesi dinilai terbanyak &rarr; Kehadiran tertinggi</p>
                    </div>
                    <span class="text-xs text-gray-500">Menampilkan <?php echo count($ranking_qualified_display); ?> siswa</span>
                </div>

                <?php if (count($ranking_qualified_display) > 0): ?>
                    <div class="table-responsive overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jenjang &amp; Program</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Sesi Dinilai</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rata-rata Nilai</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kategori</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200 text-sm">
                                <?php foreach ($ranking_qualified_display as $r_item): ?>
                                    <?php
                                    $r_pct = $r_item['rata_persentase'];
                                    $r_kat_class = $r_pct >= 80 ? 'sangat-baik' : ($r_pct >= 60 ? 'baik' : ($r_pct >= 40 ? 'cukup' : 'kurang'));
                                    $r_rank_badge = $r_item['rank'] === 1
                                        ? 'bg-yellow-100 text-yellow-800'
                                        : ($r_item['rank'] <= 3 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700');
                                    $r_audit_url = 'dataNilai.php?' . http_build_query([
                                        'bulan_tahun' => $rank_bulan_tahun,
                                        'siswa_id' => $r_item['siswa_id'],
                                        'nama_siswa' => $r_item['nama_lengkap'],
                                        'from_ranking' => 1,
                                        'jenjang' => $rank_jenjang,
                                        'min_sesi' => $rank_min_sesi
                                    ]);
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-xs font-bold <?php echo $r_rank_badge; ?>">
                                                #<?php echo (int) $r_item['rank']; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($r_item['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-xs text-gray-500">Kelas: <?php echo htmlspecialchars($r_item['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 mr-1">
                                                <?php echo htmlspecialchars($r_item['jenjang'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                            <span class="text-xs text-gray-600"><?php echo htmlspecialchars($r_item['jenis_kelas'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-center">
                                            <div class="font-semibold text-gray-900"><?php echo (int) $r_item['total_sesi']; ?> sesi</div>
                                            <div class="text-xs text-gray-500"><?php echo (int) $r_item['total_hadir']; ?> hadir</div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-bold text-gray-900"><?php echo number_format($r_item['rata_score'], 2); ?></span>
                                                <span class="text-xs text-gray-500">/50 (<?php echo number_format($r_pct, 1); ?>%)</span>
                                            </div>
                                            <div class="w-28 bg-gray-200 rounded-full h-2 mt-1">
                                                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo min(100, max(0, $r_pct)); ?>%"></div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="badge <?php echo $r_kat_class; ?>"><?php echo htmlspecialchars($r_item['kategori_display'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-xs font-medium">
                                            <button type="button" onclick="openIndikatorModal(<?php echo (int) $r_item['siswa_id']; ?>)"
                                                class="px-2.5 py-1.5 rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200 mr-1">
                                                <i class="fas fa-chart-pie mr-1"></i> Indikator
                                            </button>
                                            <a href="<?php echo htmlspecialchars($r_audit_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="px-2.5 py-1.5 rounded-md bg-blue-50 text-blue-700 hover:bg-blue-100">
                                                <i class="fas fa-list mr-1"></i> Lihat Sesi
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-trophy text-gray-300 text-5xl mb-3"></i>
                        <h3 class="text-base font-medium text-gray-900 mb-1">Belum ada data peringkat</h3>
                        <p class="text-sm text-gray-500">Coba ubah periode bulan, jenjang, atau turunkan syarat minimal sesi.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Kotak Transparansi: Belum Memenuhi Minimal Sesi -->
            <?php if (count($ranking_unqualified_display) > 0): ?>
                <div class="bg-white border border-amber-200 rounded-lg shadow-sm overflow-hidden mb-6">
                    <div class="px-4 py-3 bg-amber-50 border-b border-amber-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                        <div class="text-xs font-semibold text-amber-800 flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2 text-amber-600"></i>
                            Belum Memenuhi Syarat Minimal <?php echo $rank_min_sesi; ?> Sesi (<?php echo count($ranking_unqualified_display); ?> siswa)
                        </div>
                        <span class="text-xs text-amber-700">Tidak diikutkan dalam peringkat juara bulanan</span>
                    </div>
                    <div class="divide-y divide-gray-100 text-xs">
                        <?php foreach ($ranking_unqualified_display as $u_item): ?>
                            <?php
                            $u_audit_url = 'dataNilai.php?' . http_build_query([
                                'bulan_tahun' => $rank_bulan_tahun,
                                'siswa_id' => $u_item['siswa_id'],
                                'nama_siswa' => $u_item['nama_lengkap'],
                                'from_ranking' => 1,
                                'jenjang' => $rank_jenjang,
                                'min_sesi' => $rank_min_sesi
                            ]);
                            ?>
                            <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div>
                                    <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($u_item['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="text-gray-500 ml-2">
                                        <?php echo htmlspecialchars($u_item['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                        (<?php echo htmlspecialchars($u_item['jenjang'] ?? '-', ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($u_item['jenis_kelas'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>)
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 font-medium">
                                        Baru <?php echo (int) $u_item['total_sesi']; ?> dari min. <?php echo $rank_min_sesi; ?> sesi
                                    </span>
                                    <span class="font-semibold text-gray-800">Rata-rata: <?php echo number_format($u_item['rata_score'], 2); ?>/50</span>
                                    <div class="flex items-center gap-1.5 text-xs font-medium">
                                        <button type="button" onclick="openIndikatorModal(<?php echo (int) $u_item['siswa_id']; ?>)"
                                            class="px-2.5 py-1.5 rounded-md bg-gray-100 text-gray-700 hover:bg-gray-200">
                                            <i class="fas fa-chart-pie mr-1"></i> Indikator
                                        </button>
                                        <a href="<?php echo htmlspecialchars($u_audit_url, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="px-2.5 py-1.5 rounded-md bg-blue-50 text-blue-700 hover:bg-blue-100">
                                            <i class="fas fa-list mr-1"></i> Lihat Sesi
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>&copy; <?php echo date('Y'); ?> Bimbel Esc - Peringkat Siswa</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Rata-rata 5 Indikator Siswa -->
    <div id="indikatorModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeIndikatorModal()">&times;</span>
                <h2 id="indikatorModalTitle" class="text-lg font-bold">Rata-rata 5 Indikator Bulanan</h2>
                <p id="indikatorModalSubtitle" class="text-xs text-blue-100 mt-0.5"></p>
            </div>
            <div id="indikatorModalBody" class="modal-body"></div>
            <div class="modal-footer flex justify-end gap-2">
                <a id="indikatorModalAuditLink" href="#"
                    class="px-4 py-2 text-sm font-medium rounded-md bg-blue-600 text-white hover:bg-blue-700">
                    <i class="fas fa-list mr-1"></i> Lihat Semua Sesi Harian
                </a>
                <button type="button" onclick="closeIndikatorModal()"
                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Ringkasan Murid Terbaik -->
    <div id="printAnnouncementModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 820px;">
            <div class="modal-header no-print">
                <span class="close" onclick="closePrintModal()">&times;</span>
                <h2 class="text-lg font-bold"><i class="fas fa-eye mr-2"></i> Ringkasan Murid Terbaik</h2>
                <p class="text-xs text-blue-100 mt-0.5">Ringkasan peringkat 3 besar per jenjang untuk periode terpilih</p>
            </div>
            <div class="modal-body" id="printArea">
                <div class="text-center pb-4 mb-4 border-b border-gray-200">
                    <div class="text-xl font-bold text-blue-900">BIMBEL ESC</div>
                    <div class="text-base font-bold text-gray-800 mt-1">RINGKASAN MURID TERBAIK BULANAN</div>
                    <div class="text-xs text-gray-500 mt-1">
                        Periode: <strong><?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?></strong>
                        &bull; Syarat Minimal: <strong><?php echo $rank_min_sesi; ?> Sesi</strong>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ($jenjang_display_order as $pj_key): ?>
                        <?php $pj_list = $pengumuman_per_jenjang[$pj_key] ?? []; ?>
                        <div class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                            <div class="font-bold text-sm text-blue-800 mb-2 pb-1 border-b border-gray-200">
                                Jenjang <?php echo $pj_key === 'TK' ? 'TK / PAUD' : $pj_key; ?>
                            </div>
                            <?php if (count($pj_list) > 0): ?>
                                <div class="space-y-2 text-xs">
                                    <?php foreach ($pj_list as $pj_idx => $pj_row): ?>
                                        <div class="flex items-center justify-between gap-2">
                                            <div>
                                                <span class="font-bold text-gray-900">#<?php echo $pj_idx + 1; ?></span>
                                                <span class="font-semibold text-gray-800 ml-1"><?php echo htmlspecialchars($pj_row['nama_lengkap'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="text-gray-500">(<?php echo htmlspecialchars($pj_row['kelas_sekolah'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>)</span>
                                            </div>
                                            <div class="font-semibold text-blue-700 whitespace-nowrap">
                                                <?php echo number_format($pj_row['rata_score'], 2); ?>/50
                                                <span class="text-gray-500 font-normal">(<?php echo (int) $pj_row['total_sesi']; ?> sesi)</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-xs text-gray-400 italic py-2">Belum ada siswa memenuhi minimal <?php echo $rank_min_sesi; ?> sesi.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-200 text-xs text-gray-500 text-center">
                    Dihitung berdasarkan rata-rata penilaian seluruh sesi harian pada bulan <?php echo htmlspecialchars($rank_periode_label, ENT_QUOTES, 'UTF-8'); ?>.
                </div>
            </div>
            <div class="modal-footer flex justify-end gap-2 no-print">
                <?php /* Tombol Cetak / Simpan PDF dinonaktifkan sementara
                <button type="button" onclick="window.print()"
                    class="px-4 py-2 text-sm font-medium rounded-md bg-blue-600 text-white hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i> Cetak / Simpan PDF
                </button>
                */ ?>
                <button type="button" onclick="closePrintModal()"
                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
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

        // Dropdown sidebar menu
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const dropdownGroup = this.closest('.mb-1');
                const submenu = dropdownGroup.querySelector('.dropdown-submenu');
                const arrow = this.querySelector('.arrow');
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    if (arrow) arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => sm.style.display = 'none');
                    document.querySelectorAll('.dropdown-toggle').forEach(t => {
                        t.classList.remove('open');
                        const a = t.querySelector('.arrow');
                        if (a) a.style.transform = 'rotate(0deg)';
                    });
                    submenu.style.display = 'block';
                    if (arrow) arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(submenu => submenu.style.display = 'none');
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('open');
                    const a = toggle.querySelector('.arrow');
                    if (a) a.style.transform = 'rotate(0deg)';
                });
            }
        });

        // Data modal indikator
        const rankingRowsData = <?php echo json_encode(
            array_merge($ranking_qualified, $ranking_unqualified),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
        const rankingMeta = <?php echo json_encode([
            'bulan_tahun' => $rank_bulan_tahun,
            'periode_label' => $rank_periode_label,
            'jenjang' => $rank_jenjang,
            'min_sesi' => $rank_min_sesi
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, function(m) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
            });
        }

        function openIndikatorModal(siswaId) {
            const item = rankingRowsData.find(r => Number(r.siswa_id) === Number(siswaId));
            const modal = document.getElementById('indikatorModal');
            if (!item || !modal) return;

            document.getElementById('indikatorModalTitle').textContent = 'Rata-rata 5 Indikator — ' + item.nama_lengkap;
            document.getElementById('indikatorModalSubtitle').textContent =
                (item.kelas_sekolah || '-') + ' (' + (item.jenjang || '-') + ' • ' + (item.jenis_kelas || '-') + ') • ' +
                rankingMeta.periode_label + ' • ' + item.total_sesi + ' Sesi';

            const indicators = [
                { label: 'Kemauan Belajar (Willingness to Learn)', val: Number(item.avg_willingness || 0) },
                { label: 'Pemecahan Masalah (Problem Solving)', val: Number(item.avg_problem_solving || 0) },
                { label: 'Berpikir Kritis (Critical Thinking)', val: Number(item.avg_critical_thinking || 0) },
                { label: 'Konsentrasi (Concentration)', val: Number(item.avg_concentration || 0) },
                { label: 'Kemandirian (Independence)', val: Number(item.avg_independence || 0) }
            ];

            let html = `
                <div class="grid grid-cols-3 gap-3 text-center mb-5">
                    <div class="bg-blue-50 rounded-lg p-3">
                        <div class="text-xs text-blue-600">Rata-rata Skor</div>
                        <div class="text-xl font-bold text-blue-700 mt-0.5">${Number(item.rata_score).toFixed(2)}/50</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3">
                        <div class="text-xs text-green-600">Persentase</div>
                        <div class="text-xl font-bold text-green-700 mt-0.5">${Number(item.rata_persentase).toFixed(1)}%</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-3">
                        <div class="text-xs text-purple-600">Sesi / Hadir</div>
                        <div class="text-xl font-bold text-purple-700 mt-0.5">${item.total_sesi} <span class="text-xs font-normal">/ ${item.total_hadir}</span></div>
                    </div>
                </div>
                <div class="space-y-3">
            `;

            indicators.forEach(ind => {
                const pct = Math.min(100, Math.max(0, ind.val * 10));
                const color = ind.val >= 8 ? 'bg-green-500' : (ind.val >= 6 ? 'bg-blue-500' : (ind.val >= 4 ? 'bg-yellow-500' : 'bg-red-500'));
                html += `
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-medium text-gray-700">${escapeHtml(ind.label)}</span>
                            <span class="font-bold text-gray-900">${ind.val.toFixed(1)} / 10</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="h-2.5 rounded-full ${color}" style="width: ${pct}%"></div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            document.getElementById('indikatorModalBody').innerHTML = html;

            const auditParams = new URLSearchParams({
                bulan_tahun: rankingMeta.bulan_tahun,
                siswa_id: item.siswa_id,
                nama_siswa: item.nama_lengkap,
                from_ranking: '1',
                jenjang: rankingMeta.jenjang,
                min_sesi: rankingMeta.min_sesi
            });
            document.getElementById('indikatorModalAuditLink').href = 'dataNilai.php?' + auditParams.toString();

            modal.style.display = 'block';
        }

        function closeIndikatorModal() {
            const modal = document.getElementById('indikatorModal');
            if (modal) modal.style.display = 'none';
        }

        function openPrintModal() {
            const modal = document.getElementById('printAnnouncementModal');
            if (modal) modal.style.display = 'block';
        }

        function closePrintModal() {
            const modal = document.getElementById('printAnnouncementModal');
            if (modal) modal.style.display = 'none';
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeIndikatorModal();
                closePrintModal();
            }
        });

        window.addEventListener('click', function(event) {
            const indModal = document.getElementById('indikatorModal');
            const prtModal = document.getElementById('printAnnouncementModal');
            if (event.target === indModal) closeIndikatorModal();
            if (event.target === prtModal) closePrintModal();
        });
    </script>
</body>

</html>
<?php $conn->close(); ?>
