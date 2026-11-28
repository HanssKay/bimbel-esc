<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE ORANG TUA
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'orangtua') {
    header('Location: ../index.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Orang Tua';
$email = $_SESSION['email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL DATA ORANG TUA
$orangtua_id = 0;
$nama_ortu = $full_name;
$stmt_ortu = $conn->prepare("SELECT id, nama_ortu, email FROM orangtua WHERE user_id = ? LIMIT 1");
if ($stmt_ortu) {
    $stmt_ortu->bind_param("i", $user_id);
    $stmt_ortu->execute();
    if ($row_ortu = $stmt_ortu->get_result()->fetch_assoc()) {
        $orangtua_id = (int) $row_ortu['id'];
        $nama_ortu = $row_ortu['nama_ortu'] ?: $full_name;
        if (!empty($row_ortu['email'])) {
            $email = $row_ortu['email'];
        }
    }
    $stmt_ortu->close();
}

// AMBIL DAFTAR ANAK AKTIF (dukung relasi siswa_orangtua maupun siswa.orangtua_id)
$anak_data = [];
if ($orangtua_id > 0) {
    $sql_anak = "SELECT DISTINCT
                    s.id,
                    s.nama_lengkap,
                    s.kelas,
                    s.sekolah_asal,
                    COALESCE(MAX(ps.tingkat), '') AS tingkat,
                    COALESCE(MAX(ps.jenis_kelas), '') AS jenis_kelas
                 FROM siswa s
                 LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                 LEFT JOIN pendaftaran_siswa ps ON ps.siswa_id = s.id AND ps.status = 'aktif'
                 WHERE (so.orangtua_id = ? OR s.orangtua_id = ?)
                   AND s.status = 'aktif'
                 GROUP BY s.id, s.nama_lengkap, s.kelas, s.sekolah_asal
                 ORDER BY s.nama_lengkap ASC";
    $stmt_anak = $conn->prepare($sql_anak);
    if ($stmt_anak) {
        $stmt_anak->bind_param("ii", $orangtua_id, $orangtua_id);
        $stmt_anak->execute();
        $res_anak = $stmt_anak->get_result();
        while ($row = $res_anak->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $anak_data[] = $row;
        }
        $stmt_anak->close();
    }
}
$total_anak = count($anak_data);

// TENTUKAN ANAK TERPILIH & VERIFIKASI KEPEMILIKAN
$selected_anak_id = isset($_GET['anak_id']) ? (int) $_GET['anak_id'] : 0;
$selected_anak = null;
foreach ($anak_data as $a) {
    if ($a['id'] === $selected_anak_id) {
        $selected_anak = $a;
        break;
    }
}
if (!$selected_anak && !empty($anak_data)) {
    $selected_anak = $anak_data[0];
    $selected_anak_id = $selected_anak['id'];
}

// HELPER NAMA BULAN INDONESIA
function formatBulanSingkatIndo($ym) {
    $map = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Ags',
        '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'
    ];
    $parts = explode('-', $ym);
    if (count($parts) === 2 && isset($map[$parts[1]])) {
        return $map[$parts[1]] . ' ' . $parts[0];
    }
    return $ym;
}

// AMBIL DATA PENILAIAN, MAPEL, DAN ABSENSI UNTUK ANAK TERPILIH
$penilaian_list = [];
$mapel_map = [];
$absensi_by_month = [];

if ($selected_anak_id > 0 && $selected_anak !== null) {
    // 1. Ambil daftar mata pelajaran aktif anak (agar tetap muncul walau belum ada penilaian)
    $sql_mapel = "SELECT sp.id, sp.nama_pelajaran, COALESCE(u.full_name, 'Guru Bimbel') AS nama_guru
                  FROM siswa_pelajaran sp
                  LEFT JOIN guru g ON sp.guru_id = g.id
                  LEFT JOIN users u ON g.user_id = u.id
                  WHERE sp.siswa_id = ? AND sp.status = 'aktif'
                  ORDER BY sp.nama_pelajaran ASC";
    $stmt_mapel = $conn->prepare($sql_mapel);
    if ($stmt_mapel) {
        $stmt_mapel->bind_param("i", $selected_anak_id);
        $stmt_mapel->execute();
        $res_m = $stmt_mapel->get_result();
        while ($rm = $res_m->fetch_assoc()) {
            $mid = 'sp_' . (int) $rm['id'];
            $mapel_map[$mid] = [
                'id' => $mid,
                'nama' => $rm['nama_pelajaran'],
                'guru' => $rm['nama_guru']
            ];
        }
        $stmt_mapel->close();
    }

    // 2. Ambil seluruh data penilaian_siswa untuk anak ini (urut lama -> baru)
    $sql_nilai = "SELECT
                    pn.id,
                    pn.siswa_pelajaran_id,
                    pn.tanggal_penilaian,
                    pn.sesi_ke,
                    pn.periode_penilaian,
                    COALESCE(pn.willingness_learn, 0) AS willingness_learn,
                    COALESCE(pn.concentration, 0) AS concentration,
                    COALESCE(pn.critical_thinking, 0) AS critical_thinking,
                    COALESCE(pn.independence, 0) AS independence,
                    COALESCE(pn.problem_solving, 0) AS problem_solving,
                    COALESCE(pn.total_score, 0) AS total_score,
                    COALESCE(pn.persentase, 0) AS persentase,
                    pn.kategori,
                    COALESCE(pn.catatan_guru, '') AS catatan_guru,
                    COALESCE(pn.rekomendasi, '') AS rekomendasi,
                    COALESCE(sp.nama_pelajaran, 'Umum') AS nama_pelajaran,
                    COALESCE(u.full_name, 'Guru Bimbel') AS nama_guru
                  FROM penilaian_siswa pn
                  LEFT JOIN siswa_pelajaran sp ON pn.siswa_pelajaran_id = sp.id
                  LEFT JOIN guru g ON pn.guru_id = g.id
                  LEFT JOIN users u ON g.user_id = u.id
                  WHERE pn.siswa_id = ?
                  ORDER BY pn.tanggal_penilaian ASC, pn.sesi_ke ASC, pn.id ASC";
    $stmt_nilai = $conn->prepare($sql_nilai);
    if ($stmt_nilai) {
        $stmt_nilai->bind_param("i", $selected_anak_id);
        $stmt_nilai->execute();
        $res_n = $stmt_nilai->get_result();
        $sesi_fallback = 1;
        while ($rn = $res_n->fetch_assoc()) {
            $tgl = $rn['tanggal_penilaian'] ?: date('Y-m-d');
            $ym = date('Y-m', strtotime($tgl));
            $mid = !empty($rn['siswa_pelajaran_id']) ? ('sp_' . (int) $rn['siswa_pelajaran_id']) : 'sp_0';

            if (!isset($mapel_map[$mid])) {
                $mapel_map[$mid] = [
                    'id' => $mid,
                    'nama' => $rn['nama_pelajaran'],
                    'guru' => $rn['nama_guru']
                ];
            }

            $wl  = (int) $rn['willingness_learn'];
            $cn  = (int) $rn['concentration'];
            $ct  = (int) $rn['critical_thinking'];
            $ind = (int) $rn['independence'];
            $ps  = (int) $rn['problem_solving'];

            $total = (int) $rn['total_score'];
            if ($total <= 0) {
                $total = $wl + $cn + $ct + $ind + $ps;
            }
            $persen = (int) $rn['persentase'];
            if ($persen <= 0 && $total > 0) {
                $persen = (int) round(($total / 50) * 100);
            }

            $penilaian_list[] = [
                'id' => (int) $rn['id'],
                'bulan' => $ym,
                'bulanLabel' => formatBulanSingkatIndo($ym),
                'tanggal' => $tgl,
                'sesi' => !empty($rn['sesi_ke']) ? (int) $rn['sesi_ke'] : $sesi_fallback,
                'mapelId' => $mid,
                'mapel' => $rn['nama_pelajaran'],
                'guru' => $rn['nama_guru'],
                'wl' => $wl,
                'cn' => $cn,
                'ct' => $ct,
                'ind' => $ind,
                'ps' => $ps,
                'total' => $total,
                'persen' => $persen,
                'catatan' => trim($rn['catatan_guru']),
                'rekomendasi' => trim($rn['rekomendasi'])
            ];
            $sesi_fallback++;
        }
        $stmt_nilai->close();
    }

    // 3. Ambil rekap absensi per bulan untuk anak ini
    $sql_abs = "SELECT
                    DATE_FORMAT(tanggal_absensi, '%Y-%m') AS ym,
                    SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
                    SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) AS izin,
                    SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) AS sakit,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) AS alpha
                FROM absensi_siswa
                WHERE siswa_id = ?
                GROUP BY DATE_FORMAT(tanggal_absensi, '%Y-%m')
                ORDER BY ym ASC";
    $stmt_abs = $conn->prepare($sql_abs);
    if ($stmt_abs) {
        $stmt_abs->bind_param("i", $selected_anak_id);
        $stmt_abs->execute();
        $res_a = $stmt_abs->get_result();
        while ($ra = $res_a->fetch_assoc()) {
            $absensi_by_month[$ra['ym']] = [
                'hadir' => (int) $ra['hadir'],
                'izin'  => (int) $ra['izin'],
                'sakit' => (int) $ra['sakit'],
                'alpha' => (int) $ra['alpha']
            ];
        }
        $stmt_abs->close();
    }
}

$payload = [
    'anak' => $selected_anak ? [
        'id' => $selected_anak['id'],
        'nama' => $selected_anak['nama_lengkap'],
        'kelas' => $selected_anak['kelas'] ?: '-',
        'sekolah' => $selected_anak['sekolah_asal'] ?: '-',
        'jenis_kelas' => $selected_anak['jenis_kelas'] ?: ''
    ] : null,
    'mapelList' => array_values($mapel_map),
    'penilaian' => $penilaian_list,
    'absensiByMonth' => $absensi_by_month
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../config/analytics.php'; ?>
    <title>Perkembangan Anak - Dashboard Orang Tua Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
        .card-hover { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08); }

        .dropdown-submenu { display: none; max-height: 500px; overflow: hidden; transition: max-height 0.3s ease; }
        .dropdown-submenu[style*="display: block"] { display: block; }
        .dropdown-toggle.open .arrow { transform: rotate(-90deg); }
        .menu-item.active { background-color: rgba(255, 255, 255, 0.14); border-left: 4px solid #60A5FA; }

        #mobileMenu {
            position: fixed; top: 0; left: 0; width: 280px; height: 100%; z-index: 1100;
            transform: translateX(-100%); transition: transform 0.3s ease-in-out;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2); background-color: #1e40af;
        }
        #mobileMenu.menu-open { transform: translateX(0); }
        .menu-overlay {
            display: none; position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 1099;
        }
        .menu-overlay.active { display: block; }

        .desktop-sidebar { display: none; }
        @media (min-width: 768px) {
            .desktop-sidebar { display: block; }
            .mobile-header, #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }

        .modal-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
            z-index: 1200; align-items: center; justify-content: center; padding: 1rem;
            backdrop-filter: blur(2px);
        }
        .modal-backdrop.active { display: flex; }

        @media print {
            .no-print, .desktop-sidebar, .mobile-header, #mobileMenu, .menu-overlay { display: none !important; }
            body { background: #fff !important; }
            .print-full { margin-left: 0 !important; padding-top: 0 !important; }
            .card-hover { box-shadow: none !important; border: 1px solid #e2e8f0 !important; break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40 overflow-y-auto">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Orang Tua</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3 min-w-0">
                    <p class="font-medium truncate"><?php echo htmlspecialchars($nama_ortu); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                </div>
            </div>
            <div class="mt-3 text-xs text-blue-200">
                <i class="fas fa-child mr-1.5"></i> <?php echo $total_anak; ?> Anak Aktif
            </div>
        </div>
        <nav class="mt-2">
            <?php echo renderMenu($currentPage, 'orangtua'); ?>
        </nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 fixed top-0 left-0 right-0 z-50 shadow-md">
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="text-white mr-3">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-lg font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-2">
                    <p class="text-xs font-medium"><?php echo htmlspecialchars($nama_ortu); ?></p>
                    <p class="text-[11px] text-blue-300">Orang Tua</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user text-sm"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Overlay & Drawer -->
    <div id="menuOverlay" class="menu-overlay"></div>
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="h-full flex flex-col">
            <div class="p-4 bg-blue-900 flex items-center justify-between">
                <h1 class="text-lg font-bold">Bimbel Esc</h1>
                <button id="menuClose" class="text-white"><i class="fas fa-times text-xl"></i></button>
            </div>
            <div class="p-4 bg-blue-800 border-b border-blue-700">
                <p class="font-medium text-sm truncate"><?php echo htmlspecialchars($nama_ortu); ?></p>
                <p class="text-xs text-blue-200"><?php echo $total_anak; ?> Anak Aktif</p>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'orangtua'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="print-full md:ml-64 min-h-screen">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Top Page Header -->
        <div class="bg-white border-b border-gray-200 px-4 md:px-6 py-4">
            <div class="max-w-7xl mx-auto flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-900">Perkembangan Anak</h1>
                    <p class="text-xs md:text-sm text-gray-500 mt-0.5">
                        Pantau progres belajar anak per sesi maupun per bulan, perbandingan 5 indikator, dan catatan guru.
                    </p>
                </div>

                <?php if (!empty($anak_data)): ?>
                <div class="no-print flex flex-wrap items-center gap-2">
                    <form method="GET" class="flex items-center bg-gray-50 border border-gray-300 rounded-lg px-2.5 py-1.5">
                        <i class="fas fa-child text-blue-600 mr-2 text-sm"></i>
                        <select name="anak_id" onchange="this.form.submit()"
                            class="bg-transparent text-sm font-medium text-gray-800 focus:outline-none">
                            <?php foreach ($anak_data as $anak): ?>
                                <option value="<?php echo $anak['id']; ?>" <?php echo $selected_anak_id === $anak['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?> (<?php echo htmlspecialchars($anak['kelas']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php if (!empty($penilaian_list)): ?>
                    <!-- Filter Mapel & Tombol Cetak dinonaktifkan sementara (pilihan mapel tersedia di kartu bawah)
                    <div class="flex items-center bg-gray-50 border border-gray-300 rounded-lg px-2.5 py-1.5">
                        <i class="fas fa-book text-indigo-600 mr-2 text-sm"></i>
                        <select id="selectMapel" onchange="onFilterChange()"
                            class="bg-transparent text-sm font-medium text-gray-800 focus:outline-none">
                        </select>
                    </div>

                    <button onclick="window.print()"
                        class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium transition flex items-center gap-1.5">
                        <i class="fas fa-print"></i>
                        <span class="hidden sm:inline">Cetak</span>
                    </button>
                    -->
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="max-w-7xl mx-auto p-4 md:p-6">
            <?php if (empty($anak_data)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                    <i class="fas fa-child text-5xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">Belum ada data anak aktif</h3>
                    <p class="text-gray-500 text-sm mb-5">Hubungi admin bimbel untuk menghubungkan data anak Anda.</p>
                    <a href="dashboardOrtu.php" class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700">
                        <i class="fas fa-arrow-left mr-2"></i> Kembali ke Dashboard
                    </a>
                </div>

            <?php elseif (empty($penilaian_list)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
                    <i class="fas fa-chart-column text-5xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">Belum ada data penilaian</h3>
                    <p class="text-gray-500 text-sm mb-2">
                        Anak <strong><?php echo htmlspecialchars($selected_anak['nama_lengkap']); ?></strong> belum memiliki riwayat penilaian dari guru.
                    </p>
                    <p class="text-gray-400 text-xs">Grafik perkembangan per bulan dan per sesi akan muncul otomatis setelah penilaian pertama diinput.</p>
                </div>

            <?php else: ?>
                <div class="space-y-6">

                    <!-- Hero Banner -->
                    <div class="rounded-2xl bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-600 text-white p-5 md:p-6 shadow-md">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                    <h2 id="heroNama" class="text-xl md:text-2xl font-bold">-</h2>
                                    <span id="heroKelas" class="bg-white/20 px-2.5 py-0.5 rounded-full text-xs font-medium">-</span>
                                    <span id="heroSekolah" class="bg-white/15 px-2.5 py-0.5 rounded-full text-xs">-</span>
                                </div>
                                <p id="heroRingkasan" class="text-sm text-blue-100 max-w-2xl">-</p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <div id="heroTrendBadge" class="px-4 py-2.5 rounded-xl bg-white text-gray-800 shadow-sm flex items-center gap-3"></div>
                            </div>
                        </div>
                    </div>

                    <!-- 4 KPI Cards -->
                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
                        <div class="card-hover bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <span id="kpiSkorLabel" class="text-xs font-medium text-gray-500">Rata-rata Bulan Terakhir</span>
                                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                    <i class="fas fa-chart-column text-sm"></i>
                                </span>
                            </div>
                            <div class="flex items-baseline gap-1.5">
                                <span id="kpiSkor" class="text-2xl font-bold text-gray-900">-</span>
                                <span class="text-xs text-gray-400">/ 50</span>
                            </div>
                            <div id="kpiDelta" class="mt-2 text-xs font-medium">-</div>
                        </div>

                        <div class="card-hover bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-gray-500">Persentase & Kategori</span>
                                <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                    <i class="fas fa-award text-sm"></i>
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span id="kpiPersen" class="text-2xl font-bold text-gray-900">-</span>
                                <span id="kpiKategoriBadge" class="px-2 py-0.5 rounded-full text-xs font-semibold">-</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-1.5 mt-3">
                                <div id="kpiPersenBar" class="h-1.5 rounded-full transition-all duration-500" style="width: 0%"></div>
                            </div>
                        </div>

                        <div class="card-hover bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <span id="kpiHadirLabel" class="text-xs font-medium text-gray-500">Kehadiran</span>
                                <span class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center">
                                    <i class="fas fa-calendar-check text-sm"></i>
                                </span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span id="kpiHadirPersen" class="text-2xl font-bold text-gray-900">-</span>
                                <span id="kpiHadirRasio" class="text-xs text-gray-500">-</span>
                            </div>
                            <p id="kpiHadirDetail" class="mt-2 text-xs text-gray-500">-</p>
                        </div>

                        <div class="card-hover bg-white rounded-xl p-4 shadow-sm border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-medium text-gray-500">Total Sesi Dinilai</span>
                                <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-sm"></i>
                                </span>
                            </div>
                            <div class="flex items-baseline gap-1.5">
                                <span id="kpiTotalSesi" class="text-2xl font-bold text-gray-900">-</span>
                                <span class="text-xs text-gray-500">sesi</span>
                            </div>
                            <p id="kpiSesiInfo" class="mt-2 text-xs text-gray-500">-</p>
                        </div>
                    </div>

                    <!-- DIAGRAM BATANG UTAMA (PER BULAN / PER SESI) -->
                    <section class="card-hover bg-white rounded-2xl shadow-sm border border-gray-100 p-4 md:p-6">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-gray-100">
                            <div>
                                <h3 class="text-base md:text-lg font-bold text-gray-900 flex items-center gap-2">
                                    <i class="fas fa-chart-bar text-blue-600"></i>
                                    <span id="mainChartTitle">Diagram Batang Perkembangan Per Bulan</span>
                                </h3>
                                <p id="mainChartSubtitle" class="text-xs md:text-sm text-gray-500 mt-0.5">-</p>
                            </div>

                            <div class="no-print flex flex-wrap items-center gap-2">
                                <div class="inline-flex rounded-xl bg-gray-100 p-1 border border-gray-200">
                                    <button id="btnModeBulan" onclick="setViewMode('bulan')"
                                        class="px-3 py-1.5 rounded-lg text-xs md:text-sm font-semibold transition flex items-center gap-1.5">
                                        <i class="fas fa-calendar-alt"></i> Per Bulan
                                    </button>
                                    <button id="btnModeSesi" onclick="setViewMode('sesi')"
                                        class="px-3 py-1.5 rounded-lg text-xs md:text-sm font-semibold transition flex items-center gap-1.5">
                                        <i class="fas fa-layer-group"></i> Per Sesi
                                    </button>
                                </div>

                                <div class="flex items-center border border-gray-300 rounded-lg px-2.5 py-1.5 bg-white">
                                    <i class="fas fa-calendar text-blue-600 mr-1.5 text-xs"></i>
                                    <select id="selectTahun" onchange="onTahunChange()"
                                        class="bg-transparent text-xs md:text-sm text-gray-700 font-semibold focus:outline-none">
                                    </select>
                                </div>

                                <div id="wrapBulanSesi" class="hidden flex items-center border border-gray-300 rounded-lg px-2.5 py-1.5 bg-white">
                                    <i class="fas fa-calendar-day text-indigo-600 mr-1.5 text-xs"></i>
                                    <input type="month" id="inputBulanSesi" onchange="onBulanSesiChange()"
                                        class="bg-transparent text-xs md:text-sm text-gray-700 font-semibold focus:outline-none">
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 mt-4 mb-3">
                            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-500 inline-block"></span> Sangat Baik (≥80%)</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-blue-500 inline-block"></span> Baik (60–79%)</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-400 inline-block"></span> Cukup (40–59%)</span>
                                <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-rose-500 inline-block"></span> Kurang (&lt;40%)</span>
                            </div>
                            <span id="chartInteractiveHint" class="no-print text-xs text-blue-600 font-medium">
                                <i class="fas fa-hand-pointer mr-1"></i> Klik batang untuk detail
                            </span>
                        </div>

                        <div class="relative w-full" style="height: 310px;">
                            <canvas id="mainBarChart"></canvas>
                        </div>
                    </section>

                    <!-- BARIS 2: 5 INDIKATOR + RINGKASAN MAPEL -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        <section class="lg:col-span-7 card-hover bg-white rounded-2xl shadow-sm border border-gray-100 p-4 md:p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                                <div>
                                    <h3 class="text-base md:text-lg font-bold text-gray-900 flex items-center gap-2">
                                        <i class="fas fa-sliders text-indigo-600"></i>
                                        Perbandingan 5 Indikator Belajar
                                    </h3>
                                    <p class="text-xs text-gray-500">Rata-rata indikator (skala 1–10): Bulan Terakhir vs Bulan Sebelumnya</p>
                                </div>
                                <span id="indicatorPeriodLabel" class="text-xs font-semibold px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 self-start">-</span>
                            </div>

                            <div class="relative w-full" style="height: 255px;">
                                <canvas id="indicatorBarChart"></canvas>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5 pt-4 border-t border-gray-100">
                                <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3">
                                    <p class="text-xs font-bold text-emerald-800 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-trophy mr-1"></i> Keunggulan Utama
                                    </p>
                                    <div id="boxKekuatan" class="text-sm text-emerald-900 space-y-1"></div>
                                </div>
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-3">
                                    <p class="text-xs font-bold text-amber-800 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-bullseye mr-1"></i> Perlu Pendampingan di Rumah
                                    </p>
                                    <div id="boxPerbaikan" class="text-sm text-amber-900 space-y-1"></div>
                                </div>
                            </div>
                        </section>

                        <section class="lg:col-span-5 card-hover bg-white rounded-2xl shadow-sm border border-gray-100 p-4 md:p-6 flex flex-col">
                            <div class="mb-4 flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-base md:text-lg font-bold text-gray-900 flex items-center gap-2">
                                        <i class="fas fa-book-open text-blue-600"></i>
                                        Ringkasan Per Mata Pelajaran
                                    </h3>
                                    <p class="text-xs text-gray-500">Klik kartu mapel untuk memfilter grafik khusus mapel tersebut.</p>
                                </div>
                                <button id="btnResetMapel" onclick="filterByMapelCard('all')"
                                    class="hidden shrink-0 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 px-2.5 py-1 rounded-lg transition">
                                    Semua Mapel
                                </button>
                            </div>
                            <div id="mapelCardsContainer" class="space-y-3 flex-1"></div>
                            <div class="no-print mt-4 pt-3 border-t border-gray-100">
                                <a href="riwayatNilai.php?anak_id=<?php echo $selected_anak_id; ?>"
                                    class="w-full py-2.5 px-4 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 text-sm font-semibold transition flex items-center justify-center gap-2">
                                    <i class="fas fa-list-ul"></i> Lihat Semua Riwayat Nilai
                                </a>
                            </div>
                        </section>
                    </div>

                    <!-- BARIS 3: CATATAN GURU + TABEL REKAP -->
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        <section class="lg:col-span-5 card-hover bg-white rounded-2xl shadow-sm border border-gray-100 p-4 md:p-6">
                            <div class="mb-4">
                                <h3 class="text-base md:text-lg font-bold text-gray-900 flex items-center gap-2">
                                    <i class="fas fa-comment-dots text-amber-500"></i>
                                    Catatan & Rekomendasi Guru
                                </h3>
                                <p class="text-xs text-gray-500">Sorotan dari penilaian sesi terbaru</p>
                            </div>
                            <div id="catatanGuruList" class="space-y-3"></div>
                        </section>

                        <section class="lg:col-span-7 card-hover bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="p-4 md:p-6 border-b border-gray-100">
                                <h3 id="rekapTableTitle" class="text-base md:text-lg font-bold text-gray-900 flex items-center gap-2">
                                    <i class="fas fa-table text-blue-600"></i> Rekap Detail Per Bulan
                                </h3>
                                <p id="rekapTableSubtitle" class="text-xs text-gray-500 mt-0.5">-</p>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead id="rekapTableHead" class="bg-gray-50 text-xs text-gray-500 uppercase"></thead>
                                    <tbody id="rekapTableBody" class="bg-white divide-y divide-gray-100"></tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Detail Sesi -->
    <div id="sessionModal" class="no-print modal-backdrop" onclick="if(event.target===this) closeSessionModal()">
        <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="bg-blue-700 text-white px-5 py-4 flex items-center justify-between">
                <div>
                    <p id="modalSesiBadge" class="text-xs text-blue-200 font-medium">Sesi #</p>
                    <h4 id="modalSesiTitle" class="text-lg font-bold">Detail Penilaian Sesi</h4>
                </div>
                <button onclick="closeSessionModal()" class="text-blue-200 hover:text-white text-lg">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modalSesiBody" class="p-5 space-y-4 max-h-[75vh] overflow-y-auto"></div>
            <div class="px-5 py-3 bg-gray-50 border-t flex justify-end">
                <button onclick="closeSessionModal()" class="px-4 py-2 rounded-lg bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-semibold">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <script>
        // Sidebar & Mobile Menu Controls
        document.querySelectorAll('.dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', function () {
                const sub = this.nextElementSibling;
                const isOpen = sub.style.display === 'block';
                sub.style.display = isOpen ? 'none' : 'block';
                this.classList.toggle('open', !isOpen);
            });
        });

        const menuToggle = document.getElementById('menuToggle');
        const menuClose = document.getElementById('menuClose');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuOverlay = document.getElementById('menuOverlay');
        function setMobileMenu(open) {
            if (!mobileMenu || !menuOverlay) return;
            mobileMenu.classList.toggle('menu-open', open);
            menuOverlay.classList.toggle('active', open);
        }
        if (menuToggle) menuToggle.addEventListener('click', () => setMobileMenu(true));
        if (menuClose) menuClose.addEventListener('click', () => setMobileMenu(false));
        if (menuOverlay) menuOverlay.addEventListener('click', () => setMobileMenu(false));

        // Data dari Database PHP
        const APP_DATA = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE); ?>;

        const INDIKATOR_META = [
            { key: 'wl',  label: 'Kemauan Belajar',  tips: 'Bangun motivasi dengan target kecil harian dan beri apresiasi tiap selesai belajar.' },
            { key: 'cn',  label: 'Konsentrasi',       tips: 'Sediakan ruang belajar bebas gadget/TV dengan metode belajar 25 menit fokus + 5 menit istirahat.' },
            { key: 'ct',  label: 'Berpikir Kritis',   tips: 'Ajak anak menjelaskan ulang alasan di balik jawabannya, bukan sekadar menghafal hasil.' },
            { key: 'ind', label: 'Kemandirian',       tips: 'Biarkan anak mencoba mengerjakan 2-3 soal sendiri terlebih dahulu sebelum dibantu.' },
            { key: 'ps',  label: 'Pemecahan Masalah', tips: 'Biasakan memecah soal cerita menjadi langkah kecil: diketahui, ditanya, lalu cara hitung.' }
        ];

        const state = {
            mapelId: 'all',
            viewMode: 'bulan', // 'bulan' | 'sesi'
            tahun: 'all',      // '2026' | '2025' | 'all'
            selectedBulan: 'latest8'
        };

        let mainChartInstance = null;
        let indicatorChartInstance = null;

        function escHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }
        function avg(arr) {
            return arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0;
        }
        function getKategori(persen) {
            if (persen >= 80) return { label: 'Sangat Baik', color: '#10B981', badge: 'bg-emerald-100 text-emerald-800', bar: 'bg-emerald-500' };
            if (persen >= 60) return { label: 'Baik',        color: '#3B82F6', badge: 'bg-blue-100 text-blue-800',       bar: 'bg-blue-500' };
            if (persen >= 40) return { label: 'Cukup',       color: '#F59E0B', badge: 'bg-amber-100 text-amber-800',     bar: 'bg-amber-400' };
            return                   { label: 'Kurang',      color: '#EF4444', badge: 'bg-rose-100 text-rose-800',       bar: 'bg-rose-500' };
        }
        function formatTglIndo(iso) {
            if (!iso) return '-';
            const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
            const d = new Date(iso + 'T00:00:00');
            if (isNaN(d.getTime())) return iso;
            return `${d.getDate()} ${bulan[d.getMonth()]} ${d.getFullYear()}`;
        }

        function getDataByYear() {
            if (state.tahun === 'all') return APP_DATA.penilaian;
            return APP_DATA.penilaian.filter(p => p.bulan.startsWith(state.tahun + '-'));
        }

        function initDashboard() {
            if (!APP_DATA.anak || !APP_DATA.penilaian || !APP_DATA.penilaian.length) return;

            // Populate Mapel Dropdown (jika aktif)
            const selMapel = document.getElementById('selectMapel');
            if (selMapel) {
                selMapel.innerHTML = `<option value="all">Semua Mapel (${APP_DATA.mapelList.length})</option>` +
                    APP_DATA.mapelList.map(m => `<option value="${escHtml(m.id)}">${escHtml(m.nama)}</option>`).join('');
            }

            // Populate Filter Tahun Kalender (urut terbaru -> terlama)
            const years = Array.from(new Set(APP_DATA.penilaian.map(p => p.bulan.slice(0, 4))))
                .sort((a, b) => b.localeCompare(a));
            const selTahun = document.getElementById('selectTahun');
            selTahun.innerHTML = years.map(y => `<option value="${escHtml(y)}">Tahun ${escHtml(y)}</option>`).join('') +
                `<option value="all">Semua Tahun</option>`;
            state.tahun = years[0] || 'all';
            selTahun.value = state.tahun;

            syncBulanSesiInput(getDataByYear());
            renderAll();
        }

        function formatBulanLabel(ym) {
            if (!ym || !ym.includes('-')) return ym || '-';
            const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
            const [y, m] = ym.split('-');
            const idx = parseInt(m, 10) - 1;
            return (idx >= 0 && idx < 12) ? `${bulan[idx]} ${y}` : ym;
        }

        function syncBulanSesiInput(list) {
            const input = document.getElementById('inputBulanSesi');
            if (!input) return;
            const months = Array.from(new Set(list.map(p => p.bulan))).sort();
            const latest = months[months.length - 1] || new Date().toISOString().slice(0, 7);
            state.selectedBulan = latest;
            input.value = latest;
        }

        function setViewMode(mode) {
            state.viewMode = mode;
            if (mode === 'sesi') {
                const input = document.getElementById('inputBulanSesi');
                if (input && !input.value) {
                    syncBulanSesiInput(getDataByYear());
                }
            }
            renderAll();
        }
        function onTahunChange() {
            state.tahun = document.getElementById('selectTahun').value;
            syncBulanSesiInput(getDataByYear());
            renderAll();
        }
        function onBulanSesiChange() {
            const input = document.getElementById('inputBulanSesi');
            if (!input || !input.value) return;
            state.selectedBulan = input.value;

            // Sinkronkan filter tahun jika bulan yang dipilih berada di tahun lain
            const pickedYear = input.value.slice(0, 4);
            const selTahun = document.getElementById('selectTahun');
            if (selTahun && state.tahun !== 'all' && state.tahun !== pickedYear) {
                const hasYearOpt = Array.from(selTahun.options).some(o => o.value === pickedYear);
                state.tahun = hasYearOpt ? pickedYear : 'all';
                selTahun.value = state.tahun;
            }
            renderAll();
        }
        function onFilterChange() {
            const selMapel = document.getElementById('selectMapel');
            if (selMapel) state.mapelId = selMapel.value;
            renderAll();
        }
        function filterByMapelCard(mapelId) {
            state.mapelId = (mapelId === 'all' || state.mapelId === mapelId) ? 'all' : mapelId;
            const selMapel = document.getElementById('selectMapel');
            if (selMapel) selMapel.value = state.mapelId;
            renderAll();
        }

        function renderAll() {
            const anak = APP_DATA.anak;
            const yearData = getDataByYear();
            const filtered = state.mapelId === 'all'
                ? yearData
                : yearData.filter(p => p.mapelId === state.mapelId);

            updateControlButtons();

            const btnReset = document.getElementById('btnResetMapel');
            if (btnReset) btnReset.classList.toggle('hidden', state.mapelId === 'all');

            if (!filtered.length) {
                if (mainChartInstance) mainChartInstance.destroy();
                if (indicatorChartInstance) indicatorChartInstance.destroy();
                document.getElementById('rekapTableBody').innerHTML =
                    '<tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Belum ada penilaian untuk filter ini.</td></tr>';
                document.getElementById('catatanGuruList').innerHTML =
                    '<p class="text-xs text-gray-400 text-center py-4">Belum ada catatan guru untuk filter ini.</p>';
                renderMapelSummary(yearData);
                return;
            }

            // Group per bulan (urut lama -> baru)
            const monthMap = new Map();
            filtered.forEach(p => {
                if (!monthMap.has(p.bulan)) {
                    monthMap.set(p.bulan, { key: p.bulan, label: p.bulanLabel, items: [] });
                }
                monthMap.get(p.bulan).items.push(p);
            });
            const monthlyAll = Array.from(monthMap.values())
                .sort((a, b) => a.key.localeCompare(b.key))
                .map(m => {
                    const totals = m.items.map(i => i.total);
                    const avgSkor = Number(avg(totals).toFixed(1));
                    const avgPersen = Math.round((avgSkor / 50) * 100);
                    return {
                        ...m,
                        jumlah: m.items.length,
                        avgSkor,
                        avgPersen,
                        minSkor: Math.min(...totals),
                        maxSkor: Math.max(...totals),
                        ind: {
                            wl: Number(avg(m.items.map(i => i.wl)).toFixed(1)),
                            cn: Number(avg(m.items.map(i => i.cn)).toFixed(1)),
                            ct: Number(avg(m.items.map(i => i.ct)).toFixed(1)),
                            ind: Number(avg(m.items.map(i => i.ind)).toFixed(1)),
                            ps: Number(avg(m.items.map(i => i.ps)).toFixed(1))
                        }
                    };
                });

            const currMonth = monthlyAll[monthlyAll.length - 1];
            const prevMonth = monthlyAll.length >= 2 ? monthlyAll[monthlyAll.length - 2] : null;

            renderHeroAndKPI(anak, filtered, currMonth, prevMonth);
            renderMainBarChart(monthlyAll, filtered);
            renderIndicatorComparison(currMonth, prevMonth);
            renderMapelSummary(yearData);
            renderCatatanGuru(filtered);
            renderRekapTable(monthlyAll, filtered);
        }

        function updateControlButtons() {
            const isBulan = state.viewMode === 'bulan';
            document.getElementById('btnModeBulan').className = isBulan
                ? 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-semibold bg-blue-600 text-white shadow-sm transition flex items-center gap-1.5'
                : 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-medium text-gray-600 hover:text-gray-900 transition flex items-center gap-1.5';
            document.getElementById('btnModeSesi').className = !isBulan
                ? 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-semibold bg-blue-600 text-white shadow-sm transition flex items-center gap-1.5'
                : 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-medium text-gray-600 hover:text-gray-900 transition flex items-center gap-1.5';

            document.getElementById('wrapBulanSesi').classList.toggle('hidden', isBulan);
        }

        function renderHeroAndKPI(anak, filtered, currMonth, prevMonth) {
            document.getElementById('heroNama').textContent = anak.nama;
            document.getElementById('heroKelas').textContent = anak.kelas;
            document.getElementById('heroSekolah').textContent = anak.sekolah;

            const delta = prevMonth ? Number((currMonth.avgSkor - prevMonth.avgSkor).toFixed(1)) : 0;
            const kat = getKategori(currMonth.avgPersen);

            let trendText = 'Periode Pertama', trendIcon = 'fa-star', trendColor = 'text-blue-600', trendBg = 'bg-blue-50';
            if (prevMonth) {
                if (delta >= 2) {
                    trendText = 'Meningkat Signifikan'; trendIcon = 'fa-arrow-trend-up'; trendColor = 'text-emerald-600'; trendBg = 'bg-emerald-50';
                } else if (delta > 0) {
                    trendText = 'Meningkat'; trendIcon = 'fa-arrow-up'; trendColor = 'text-blue-600'; trendBg = 'bg-blue-50';
                } else if (delta === 0) {
                    trendText = 'Stabil'; trendIcon = 'fa-minus'; trendColor = 'text-gray-600'; trendBg = 'bg-gray-100';
                } else if (delta <= -2) {
                    trendText = 'Perlu Perhatian'; trendIcon = 'fa-arrow-trend-down'; trendColor = 'text-rose-600'; trendBg = 'bg-rose-50';
                } else {
                    trendText = 'Sedikit Menurun'; trendIcon = 'fa-arrow-down'; trendColor = 'text-amber-600'; trendBg = 'bg-amber-50';
                }
            }

            const mapelObj = APP_DATA.mapelList.find(m => m.id === state.mapelId);
            const mapelLabel = state.mapelId === 'all' ? 'seluruh mata pelajaran' : ('mata pelajaran ' + (mapelObj?.nama || ''));

            document.getElementById('heroRingkasan').textContent =
                `Pada ${currMonth.label}, rata-rata capaian ${anak.nama} untuk ${mapelLabel} berada di kategori "${kat.label}" (${currMonth.avgSkor}/50 atau ${currMonth.avgPersen}%).`;

            document.getElementById('heroTrendBadge').innerHTML = `
                <div class="w-10 h-10 rounded-lg ${trendBg} ${trendColor} flex items-center justify-center text-lg">
                    <i class="fas ${trendIcon}"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Tren Capaian</p>
                    <p class="text-sm font-bold ${trendColor}">${trendText}</p>
                    <p class="text-xs text-gray-500">${prevMonth ? `${delta > 0 ? '+' : ''}${delta} poin (${prevMonth.label} → ${currMonth.label})` : `Data aktif: ${currMonth.label}`}</p>
                </div>`;

            // KPI 1
            document.getElementById('kpiSkorLabel').textContent = `Rata-rata (${currMonth.label})`;
            document.getElementById('kpiSkor').textContent = currMonth.avgSkor;
            document.getElementById('kpiDelta').innerHTML = !prevMonth
                ? `<span class="text-gray-500">Belum ada bulan pembanding</span>`
                : (delta >= 0
                    ? `<span class="text-emerald-600"><i class="fas fa-caret-up mr-1"></i>+${delta} poin dari ${prevMonth.label}</span>`
                    : `<span class="text-rose-600"><i class="fas fa-caret-down mr-1"></i>${delta} poin dari ${prevMonth.label}</span>`);

            // KPI 2
            document.getElementById('kpiPersen').textContent = `${currMonth.avgPersen}%`;
            const katBadge = document.getElementById('kpiKategoriBadge');
            katBadge.textContent = kat.label;
            katBadge.className = `px-2 py-0.5 rounded-full text-xs font-semibold ${kat.badge}`;
            const bar = document.getElementById('kpiPersenBar');
            bar.style.width = `${currMonth.avgPersen}%`;
            bar.className = `h-1.5 rounded-full transition-all duration-500 ${kat.bar}`;

            // KPI 3 (Kehadiran pada bulan aktif terakhir, atau fallback bulan terakhir di absensi)
            const absMap = APP_DATA.absensiByMonth || {};
            let absMonthKey = currMonth.key;
            let abs = absMap[absMonthKey];
            if (!abs) {
                const absKeys = Object.keys(absMap).sort();
                if (absKeys.length) {
                    absMonthKey = absKeys[absKeys.length - 1];
                    abs = absMap[absMonthKey];
                }
            }
            if (abs) {
                const totalAbs = abs.hadir + abs.izin + abs.sakit + abs.alpha;
                const persenHadir = totalAbs > 0 ? Math.round((abs.hadir / totalAbs) * 100) : 0;
                document.getElementById('kpiHadirLabel').textContent = `Kehadiran (${absMonthKey === currMonth.key ? currMonth.label : absMonthKey})`;
                document.getElementById('kpiHadirPersen').textContent = `${persenHadir}%`;
                document.getElementById('kpiHadirRasio').textContent = `(${abs.hadir}/${totalAbs} sesi)`;
                document.getElementById('kpiHadirDetail').innerHTML =
                    `Izin: <b>${abs.izin}</b> · Sakit: <b>${abs.sakit}</b> · Alpha: <b class="${abs.alpha > 0 ? 'text-rose-600' : ''}">${abs.alpha}</b>`;
            } else {
                document.getElementById('kpiHadirLabel').textContent = 'Kehadiran';
                document.getElementById('kpiHadirPersen').textContent = '-';
                document.getElementById('kpiHadirRasio').textContent = '';
                document.getElementById('kpiHadirDetail').textContent = 'Belum ada data absensi tercatat';
            }

            // KPI 4
            document.getElementById('kpiTotalSesi').textContent = filtered.length;
            document.getElementById('kpiSesiInfo').textContent = `${currMonth.jumlah} sesi dinilai di ${currMonth.label}`;
        }

        function renderMainBarChart(monthlyAll, filtered) {
            const ctx = document.getElementById('mainBarChart').getContext('2d');
            if (mainChartInstance) mainChartInstance.destroy();

            const isBulan = state.viewMode === 'bulan';
            const tahunLabel = state.tahun === 'all' ? 'Semua Tahun' : `Tahun ${state.tahun}`;

            let labels = [], values = [], colors = [], metaItems = [];

            if (isBulan) {
                document.getElementById('mainChartTitle').textContent = `Diagram Batang Perkembangan Per Bulan (${tahunLabel})`;
                document.getElementById('mainChartSubtitle').textContent = 'Menampilkan rata-rata skor bulanan (skala 0–50). Klik salah satu batang bulan untuk masuk ke detail Per Sesi.';
                document.getElementById('chartInteractiveHint').innerHTML = '<i class="fas fa-hand-pointer mr-1"></i> Klik batang bulan untuk lihat Per Sesi';

                labels = monthlyAll.map(m => m.label);
                values = monthlyAll.map(m => m.avgSkor);
                colors = monthlyAll.map(m => getKategori(m.avgPersen).color);
                metaItems = monthlyAll;
            } else {
                const sessions = filtered.filter(p => p.bulan === state.selectedBulan);
                const periodeLbl = formatBulanLabel(state.selectedBulan);

                document.getElementById('mainChartTitle').textContent = `Diagram Batang Perkembangan Per Sesi (${periodeLbl})`;
                document.getElementById('mainChartSubtitle').textContent = sessions.length
                    ? 'Menampilkan skor setiap pertemuan (skala 0–50). Klik salah satu batang sesi untuk melihat rincian 5 indikator & catatan guru.'
                    : `Belum ada data penilaian pada bulan ${periodeLbl}. Silakan pilih bulan lain pada kalender.`;
                document.getElementById('chartInteractiveHint').innerHTML = sessions.length
                    ? '<i class="fas fa-hand-pointer mr-1"></i> Klik batang sesi untuk buka detail'
                    : '<i class="fas fa-info-circle mr-1"></i> Tidak ada sesi di bulan ini';

                labels = sessions.map(s => `Sesi ${s.sesi} (${s.mapel.split(' ')[0]})`);
                values = sessions.map(s => s.total);
                colors = sessions.map(s => getKategori(s.persen).color);
                metaItems = sessions;
            }

            mainChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Skor (dari 50)',
                        data: values,
                        backgroundColor: colors,
                        borderRadius: { topLeft: 8, topRight: 8 },
                        borderSkipped: 'bottom',
                        maxBarThickness: 54
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onHover: (evt, el) => { evt.native.target.style.cursor = el.length ? 'pointer' : 'default'; },
                    onClick: (evt, elements) => {
                        if (!elements.length) return;
                        const idx = elements[0].index;
                        if (isBulan) {
                            const clickedMonth = metaItems[idx];
                            state.viewMode = 'sesi';
                            state.selectedBulan = clickedMonth.key;
                            const inputBulan = document.getElementById('inputBulanSesi');
                            if (inputBulan) inputBulan.value = clickedMonth.key;
                            renderAll();
                        } else {
                            openSessionModal(metaItems[idx]);
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0F172A',
                            padding: 12,
                            cornerRadius: 10,
                            callbacks: {
                                title: (items) => {
                                    const idx = items[0].dataIndex;
                                    if (isBulan) return `Bulan ${metaItems[idx].label}`;
                                    const s = metaItems[idx];
                                    return `Sesi ${s.sesi} · ${s.mapel} (${formatTglIndo(s.tanggal)})`;
                                },
                                label: (item) => {
                                    const idx = item.dataIndex;
                                    if (isBulan) {
                                        const m = metaItems[idx];
                                        return [
                                            `Rata-rata: ${m.avgSkor}/50 (${m.avgPersen}%)`,
                                            `Kategori: ${getKategori(m.avgPersen).label}`,
                                            `Jumlah Sesi: ${m.jumlah} sesi (Min ${m.minSkor} - Max ${m.maxSkor})`
                                        ];
                                    } else {
                                        const s = metaItems[idx];
                                        return [
                                            `Skor: ${s.total}/50 (${s.persen}%) — ${getKategori(s.persen).label}`,
                                            `Guru: ${s.guru}`,
                                            `(Klik batang untuk detail)`
                                        ];
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 50,
                            grid: { color: '#F1F5F9' },
                            ticks: { stepSize: 10 }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11, weight: '600' } }
                        }
                    }
                }
            });
        }

        function renderIndicatorComparison(currMonth, prevMonth) {
            const ctx = document.getElementById('indicatorBarChart').getContext('2d');
            if (indicatorChartInstance) indicatorChartInstance.destroy();

            document.getElementById('indicatorPeriodLabel').textContent = prevMonth
                ? `${currMonth.label} vs ${prevMonth.label}`
                : currMonth.label;

            const labels = INDIKATOR_META.map(i => i.label);
            const currVals = INDIKATOR_META.map(i => currMonth.ind[i.key]);
            const prevVals = prevMonth ? INDIKATOR_META.map(i => prevMonth.ind[i.key]) : null;

            const datasets = [];
            if (prevVals) {
                datasets.push({
                    label: prevMonth.label,
                    data: prevVals,
                    backgroundColor: '#CBD5E1',
                    borderRadius: 6,
                    maxBarThickness: 26
                });
            }
            datasets.push({
                label: currMonth.label,
                data: currVals,
                backgroundColor: '#2563EB',
                borderRadius: 6,
                maxBarThickness: 26
            });

            indicatorChartInstance = new Chart(ctx, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', align: 'end', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: { backgroundColor: '#0F172A', padding: 10, cornerRadius: 8 }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 10, ticks: { stepSize: 2 }, grid: { color: '#F1F5F9' } },
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                    }
                }
            });

            const scored = INDIKATOR_META.map(m => ({
                ...m,
                score: currMonth.ind[m.key],
                diff: prevMonth ? Number((currMonth.ind[m.key] - prevMonth.ind[m.key]).toFixed(1)) : null
            })).sort((a, b) => b.score - a.score);

            const best = scored[0];
            const lowest = scored[scored.length - 1];

            document.getElementById('boxKekuatan').innerHTML = `
                <div class="flex items-center justify-between font-semibold">
                    <span>${best.label}</span>
                    <span>${best.score}/10 ${best.diff !== null ? (best.diff >= 0 ? `(+${best.diff})` : `(${best.diff})`) : ''}</span>
                </div>
                <p class="text-xs text-emerald-700">Pertahankan dengan apresiasi positif atas usaha anak di aspek ini.</p>`;

            document.getElementById('boxPerbaikan').innerHTML = `
                <div class="flex items-center justify-between font-semibold">
                    <span>${lowest.label}</span>
                    <span>${lowest.score}/10 ${lowest.diff !== null ? (lowest.diff >= 0 ? `(+${lowest.diff})` : `(${lowest.diff})`) : ''}</span>
                </div>
                <p class="text-xs text-amber-800">${lowest.tips}</p>`;
        }

        function renderMapelSummary(yearData) {
            const container = document.getElementById('mapelCardsContainer');
            if (!APP_DATA.mapelList.length) {
                container.innerHTML = '<p class="text-xs text-gray-400 text-center py-4">Belum ada data mata pelajaran.</p>';
                return;
            }
            const source = yearData || getDataByYear();
            container.innerHTML = APP_DATA.mapelList.map(m => {
                const items = source.filter(p => p.mapelId === m.id);
                const latest = items[items.length - 1];
                const prev = items.length >= 2 ? items[items.length - 2] : null;
                const latestScore = latest ? latest.total : 0;
                const prevScore = prev ? prev.total : latestScore;
                const diff = latestScore - prevScore;
                const pct = latest ? latest.persen : 0;
                const kat = getKategori(pct);
                const isSelected = state.mapelId === m.id;

                return `
                    <div onclick="filterByMapelCard('${escHtml(m.id)}')"
                        class="cursor-pointer rounded-xl border p-3.5 transition ${isSelected ? 'border-blue-500 bg-blue-50/60 ring-2 ring-blue-200' : 'border-gray-200 hover:border-blue-300 hover:bg-gray-50'}">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-gray-900 text-sm truncate">${escHtml(m.nama)}</span>
                                    ${isSelected ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-600 text-white font-semibold shrink-0">Aktif</span>' : ''}
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5 truncate"><i class="fas fa-chalkboard-user mr-1"></i>${escHtml(m.guru)} · ${items.length} sesi</p>
                            </div>
                            <div class="text-right shrink-0">
                                ${latest ? `
                                    <p class="text-base font-bold text-gray-900">${latestScore}<span class="text-xs text-gray-400 font-normal">/50</span></p>
                                    <span class="text-xs font-semibold ${diff >= 0 ? 'text-emerald-600' : 'text-rose-600'}">
                                        ${diff > 0 ? '▲ +' + diff : (diff < 0 ? '▼ ' + diff : '• Stabil')}
                                    </span>` : `<span class="text-xs text-gray-400">Belum dinilai</span>`}
                            </div>
                        </div>
                        ${latest ? `
                        <div class="mt-2.5 flex items-center gap-2">
                            <div class="flex-1 bg-gray-200 rounded-full h-1.5">
                                <div class="${kat.bar} h-1.5 rounded-full" style="width: ${pct}%"></div>
                            </div>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full ${kat.badge}">${kat.label}</span>
                        </div>` : ''}
                    </div>`;
            }).join('');
        }

        function renderCatatanGuru(filtered) {
            const latest3 = filtered.slice(-3).reverse();
            const container = document.getElementById('catatanGuruList');
            container.innerHTML = latest3.map(s => {
                const kat = getKategori(s.persen);
                const catatanText = s.catatan ? escHtml(s.catatan) : '<span class="italic text-gray-400">Tidak ada catatan tambahan.</span>';
                const rekText = s.rekomendasi ? escHtml(s.rekomendasi) : '<span class="italic text-gray-400">Belum ada rekomendasi khusus.</span>';
                return `
                    <div class="rounded-xl border border-gray-200 p-3.5 hover:border-blue-300 transition bg-gray-50/50">
                        <div class="flex items-center justify-between gap-2 mb-1.5">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded">${escHtml(s.mapel)}</span>
                                <span class="text-xs text-gray-500">Sesi ${s.sesi} · ${formatTglIndo(s.tanggal)}</span>
                            </div>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full ${kat.badge}">${s.total}/50</span>
                        </div>
                        <p class="text-xs text-gray-700 leading-relaxed"><b>Catatan:</b> ${catatanText}</p>
                        <p class="text-xs text-indigo-700 mt-1.5 bg-indigo-50/70 rounded-lg px-2.5 py-1.5">
                            <i class="fas fa-lightbulb text-amber-500 mr-1"></i> <b>Saran Guru:</b> ${rekText}
                        </p>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-gray-400">
                            <span>Oleh ${escHtml(s.guru)}</span>
                            <button onclick="openSessionModalById(${s.id})" class="no-print text-blue-600 hover:underline font-medium">Lihat 5 Indikator →</button>
                        </div>
                    </div>`;
            }).join('');
        }

        function renderRekapTable(monthlyAll, filtered) {
            const thead = document.getElementById('rekapTableHead');
            const tbody = document.getElementById('rekapTableBody');

            if (state.viewMode === 'bulan') {
                const rows = monthlyAll.slice().reverse();
                document.getElementById('rekapTableTitle').innerHTML = '<i class="fas fa-table text-blue-600"></i> Rekap Detail Per Bulan';
                document.getElementById('rekapTableSubtitle').textContent = 'Ringkasan rata-rata bulanan (klik "Lihat Sesi" untuk detail pertemuan)';

                thead.innerHTML = `
                    <tr>
                        <th class="px-4 py-3 text-left">Bulan</th>
                        <th class="px-4 py-3 text-center">Sesi</th>
                        <th class="px-4 py-3 text-left">Rata-rata</th>
                        <th class="px-4 py-3 text-left">Kategori</th>
                        <th class="px-4 py-3 text-left">Min–Max</th>
                        <th class="no-print px-4 py-3 text-right">Aksi</th>
                    </tr>`;

                tbody.innerHTML = rows.map(m => {
                    const kat = getKategori(m.avgPersen);
                    return `
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">${escHtml(m.label)}</td>
                            <td class="px-4 py-3 text-center"><span class="px-2 py-0.5 rounded-full bg-gray-100 text-xs font-semibold">${m.jumlah}</span></td>
                            <td class="px-4 py-3 whitespace-nowrap"><span class="font-bold text-gray-900">${m.avgSkor}/50</span> <span class="text-xs text-gray-500">(${m.avgPersen}%)</span></td>
                            <td class="px-4 py-3 whitespace-nowrap"><span class="px-2.5 py-0.5 rounded-full text-xs font-semibold ${kat.badge}">${kat.label}</span></td>
                            <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">${m.minSkor} – ${m.maxSkor}</td>
                            <td class="no-print px-4 py-3 text-right whitespace-nowrap">
                                <button onclick="drillToMonth('${escHtml(m.key)}')" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                                    Lihat Sesi <i class="fas fa-arrow-right ml-0.5"></i>
                                </button>
                            </td>
                        </tr>`;
                }).join('');
            } else {
                const sessions = filtered.filter(p => p.bulan === state.selectedBulan).slice().reverse();
                const periodeLbl = formatBulanLabel(state.selectedBulan);

                document.getElementById('rekapTableTitle').innerHTML = `<i class="fas fa-table text-blue-600"></i> Rekap Detail Per Sesi (${periodeLbl})`;
                document.getElementById('rekapTableSubtitle').textContent = 'Rincian setiap sesi penilaian yang tampil di diagram batang';

                thead.innerHTML = `
                    <tr>
                        <th class="px-4 py-3 text-left">Sesi & Tanggal</th>
                        <th class="px-4 py-3 text-left">Mapel</th>
                        <th class="px-4 py-3 text-left">Skor</th>
                        <th class="px-4 py-3 text-left">Kategori</th>
                        <th class="no-print px-4 py-3 text-right">Aksi</th>
                    </tr>`;

                if (!sessions.length) {
                    tbody.innerHTML = `<tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Belum ada penilaian pada bulan ${escHtml(periodeLbl)}.</td></tr>`;
                } else {
                    tbody.innerHTML = sessions.map(s => {
                        const kat = getKategori(s.persen);
                        return `
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-semibold text-gray-900">Sesi ${s.sesi}</div>
                                    <div class="text-xs text-gray-500">${formatTglIndo(s.tanggal)}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="font-medium text-gray-800">${escHtml(s.mapel)}</div>
                                    <div class="text-xs text-gray-400">${escHtml(s.guru)}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap font-bold text-gray-900">${s.total}/50 <span class="text-xs font-normal text-gray-500">(${s.persen}%)</span></td>
                                <td class="px-4 py-3 whitespace-nowrap"><span class="px-2.5 py-0.5 rounded-full text-xs font-semibold ${kat.badge}">${kat.label}</span></td>
                                <td class="no-print px-4 py-3 text-right whitespace-nowrap">
                                    <button onclick="openSessionModalById(${s.id})" class="text-xs font-semibold text-blue-600 hover:text-blue-800">
                                        Detail <i class="fas fa-eye ml-0.5"></i>
                                    </button>
                                </td>
                            </tr>`;
                    }).join('');
                }
            }
        }

        function drillToMonth(monthKey) {
            state.viewMode = 'sesi';
            state.selectedBulan = monthKey;
            const inputBulan = document.getElementById('inputBulanSesi');
            if (inputBulan) inputBulan.value = monthKey;
            renderAll();
            window.scrollTo({ top: 320, behavior: 'smooth' });
        }

        function openSessionModalById(id) {
            const s = APP_DATA.penilaian.find(p => p.id === id);
            if (s) openSessionModal(s);
        }

        function openSessionModal(s) {
            const kat = getKategori(s.persen);
            document.getElementById('modalSesiBadge').textContent = `Sesi ${s.sesi} · ${formatTglIndo(s.tanggal)}`;
            document.getElementById('modalSesiTitle').textContent = `${s.mapel} — ${s.guru}`;

            const barsHtml = INDIKATOR_META.map(m => {
                const val = s[m.key];
                const barColor = val >= 8 ? 'bg-emerald-500' : (val >= 6 ? 'bg-blue-500' : 'bg-amber-500');
                return `
                    <div>
                        <div class="flex justify-between text-xs font-medium mb-1">
                            <span class="text-gray-700">${m.label}</span>
                            <span class="font-bold text-gray-900">${val}/10</span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2">
                            <div class="${barColor} h-2 rounded-full" style="width: ${val * 10}%"></div>
                        </div>
                    </div>`;
            }).join('');

            document.getElementById('modalSesiBody').innerHTML = `
                <div class="flex items-center justify-between bg-gray-50 rounded-xl p-3.5 border border-gray-200">
                    <div>
                        <p class="text-xs text-gray-500">Total Skor Sesi</p>
                        <p class="text-2xl font-bold text-gray-900">${s.total}/50 <span class="text-sm font-medium text-gray-500">(${s.persen}%)</span></p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-bold ${kat.badge}">${kat.label}</span>
                </div>
                <div class="space-y-2.5">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Rincian 5 Indikator</p>
                    ${barsHtml}
                </div>
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-3.5 text-sm text-gray-700 space-y-2">
                    <p><b class="text-blue-900">Catatan Guru:</b><br>${s.catatan ? escHtml(s.catatan) : '<span class="italic text-gray-400">Tidak ada catatan tambahan.</span>'}</p>
                    <p><b class="text-blue-900">Rekomendasi:</b><br>${s.rekomendasi ? escHtml(s.rekomendasi) : '<span class="italic text-gray-400">Belum ada rekomendasi khusus.</span>'}</p>
                </div>`;

            document.getElementById('sessionModal').classList.add('active');
        }

        function closeSessionModal() {
            document.getElementById('sessionModal').classList.remove('active');
        }

        document.addEventListener('DOMContentLoaded', initDashboard);
    </script>
</body>
</html>
