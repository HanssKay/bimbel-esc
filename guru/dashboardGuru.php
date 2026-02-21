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

$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// Get guru_id from guru table based on user_id
$guru_id = 0;
$guru_detail = [];
try {
    $sql = "SELECT g.id, g.bidang_keahlian, g.pendidikan_terakhir, g.pengalaman_tahun, 
                   g.status, g.tanggal_bergabung, g.kapasitas_per_sesi
            FROM guru g 
            WHERE g.user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $guru_detail = $result->fetch_assoc();
        $guru_id = $guru_detail['id'] ?? 0;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error getting guru_id: " . $e->getMessage());
}

// Hitung statistik dengan struktur database baru
$statistics = [
    'total_siswa' => 0,
    'siswa_dengan_jadwal' => 0,
    'total_jadwal_sesi' => 0,
    'total_penilaian' => 0,
    'penilaian_bulan_ini' => 0,
    'rata_nilai' => '0.0',
    'total_sesi_mengajar' => 0,
    'kapasitas_terisi' => 0,
    'kapasitas_total' => 0,
    'siswa_terbaik' => ['nama_lengkap' => '-', 'total_score' => 0]
];

$penilaian_terbaru = [];
$siswa_belum_dinilai = [];
$jadwal_hari_ini = [];

try {
    if ($guru_id > 0) {
        // 1. TOTAL SISWA UNIK YANG DIAJAR (dari jadwal_belajar)
        $sql = "SELECT COUNT(DISTINCT ps.siswa_id) as total 
                FROM sesi_mengajar_guru smg
                INNER JOIN jadwal_belajar jb ON smg.id = jb.sesi_guru_id AND jb.status = 'aktif'
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id AND ps.status = 'aktif'
                INNER JOIN siswa s ON ps.siswa_id = s.id AND s.status = 'aktif'
                WHERE smg.guru_id = ? AND smg.status != 'tidak_aktif'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_siswa'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 2. SISWA DENGAN JADWAL AKTIF (sama dengan total siswa sekarang)
        $statistics['siswa_dengan_jadwal'] = $statistics['total_siswa'];

        // 3. TOTAL JADWAL BELAJAR (jumlah pertemuan)
        $sql = "SELECT COUNT(*) as total 
                FROM jadwal_belajar jb
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                WHERE smg.guru_id = ? AND jb.status = 'aktif'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_jadwal_sesi'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 4. TOTAL SESI MENGAJAR GURU
        $sql = "SELECT COUNT(*) as total,
                       SUM(kapasitas_terisi) as total_terisi,
                       SUM(kapasitas_maks) as total_maks
                FROM sesi_mengajar_guru 
                WHERE guru_id = ? AND status != 'tidak_aktif'";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_sesi_mengajar'] = (int)$row['total'];
                    $statistics['kapasitas_terisi'] = (int)$row['total_terisi'];
                    $statistics['kapasitas_total'] = (int)$row['total_maks'];
                }
            }
            $stmt->close();
        }

        // 5. JADWAL HARI INI
        $hari_ini = date('l');
        // Konversi hari ke Bahasa Indonesia
        $hari_map = [
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
            'Sunday' => 'Minggu'
        ];
        $hari_ini_id = $hari_map[$hari_ini] ?? $hari_ini;

        $sql = "SELECT 
                    smg.hari,
                    DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai,
                    DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai,
                    smg.kapasitas_terisi,
                    smg.kapasitas_maks,
                    COUNT(jb.id) as total_siswa_terjadwal,
                    GROUP_CONCAT(DISTINCT CONCAT(s.nama_lengkap, ' (', ps.tingkat, ')') SEPARATOR '; ') as daftar_siswa
                FROM sesi_mengajar_guru smg
                LEFT JOIN jadwal_belajar jb ON smg.id = jb.sesi_guru_id AND jb.status = 'aktif'
                LEFT JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id AND ps.status = 'aktif'
                LEFT JOIN siswa s ON ps.siswa_id = s.id AND s.status = 'aktif'
                WHERE smg.guru_id = ? 
                    AND smg.hari = ? 
                    AND smg.status != 'tidak_aktif'
                GROUP BY smg.id
                ORDER BY smg.jam_mulai";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("is", $guru_id, $hari_ini_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $jadwal_hari_ini[] = $row;
                    }
                }
            }
            $stmt->close();
        }

        // 6. TOTAL PENILAIAN
        $sql = "SELECT COUNT(*) as total FROM penilaian_siswa WHERE guru_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['total_penilaian'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 7. PENILAIAN BULAN INI
        $bulan_ini = date('Y-m');
        $sql = "SELECT COUNT(*) as total 
                FROM penilaian_siswa 
                WHERE guru_id = ? AND DATE_FORMAT(tanggal_penilaian, '%Y-%m') = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("is", $guru_id, $bulan_ini);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['penilaian_bulan_ini'] = (int)$row['total'];
                }
            }
            $stmt->close();
        }

        // 8. RATA-RATA NILAI
        $sql = "SELECT COALESCE(AVG(total_score), 0) as rata_nilai 
                FROM penilaian_siswa 
                WHERE guru_id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['rata_nilai'] = number_format((float)$row['rata_nilai'], 1);
                }
            }
            $stmt->close();
        }

        // 9. SISWA TERBAIK
        $sql = "SELECT s.nama_lengkap, ps.total_score
                FROM penilaian_siswa ps 
                INNER JOIN siswa s ON ps.siswa_id = s.id 
                WHERE ps.guru_id = ? 
                ORDER BY ps.total_score DESC 
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result && $row = $result->fetch_assoc()) {
                    $statistics['siswa_terbaik'] = [
                        'nama_lengkap' => $row['nama_lengkap'] ?? '-',
                        'total_score' => (int)($row['total_score'] ?? 0)
                    ];
                }
            }
            $stmt->close();
        }

        // 10. PENILAIAN TERBARU
        $sql = "SELECT ps.*, s.nama_lengkap as nama_siswa, s.kelas, 
                       ps.tingkat_bimbel,
                       DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tgl_format
                FROM penilaian_siswa ps
                INNER JOIN siswa s ON ps.siswa_id = s.id
                WHERE ps.guru_id = ?
                ORDER BY ps.tanggal_penilaian DESC 
                LIMIT 5";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $penilaian_terbaru[] = $row;
                    }
                }
            }
            $stmt->close();
        }

        // 11. SISWA YANG BELUM DINILAI BULAN INI
        $sql = "SELECT DISTINCT s.id, s.nama_lengkap, s.kelas,
                       ps.tingkat as tingkat_bimbel
                FROM jadwal_belajar jb
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                INNER JOIN siswa s ON ps.siswa_id = s.id
                WHERE smg.guru_id = ? 
                    AND jb.status = 'aktif'
                    AND ps.status = 'aktif'
                    AND s.status = 'aktif'
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM penilaian_siswa pn 
                        WHERE pn.siswa_id = s.id 
                            AND pn.guru_id = ?
                            AND DATE_FORMAT(pn.tanggal_penilaian, '%Y-%m') = ?
                    )
                ORDER BY s.nama_lengkap
                LIMIT 5";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iis", $guru_id, $guru_id, $bulan_ini);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $siswa_belum_dinilai[] = $row;
                    }
                }
            }
            $stmt->close();
        }
    }

} catch (Exception $e) {
    error_log("Error in dashboardGuru.php: " . $e->getMessage());
}

// Hitung persentase kapasitas
$kapasitas_persen = 0;
if ($statistics['kapasitas_total'] > 0) {
    $kapasitas_persen = round(($statistics['kapasitas_terisi'] / $statistics['kapasitas_total']) * 100);
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Guru - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .progress-bar {
            transition: width 0.5s ease-in-out;
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
            .desktop-sidebar { display: block; }
            .mobile-header { display: none; }
            #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }
        @media (max-width: 767px) {
            .desktop-sidebar { display: none; }
            .stat-card { padding: 1rem !important; }
            .quick-action-btn { padding: 0.75rem !important; }
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

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
                    <?php if (!empty($guru_detail['bidang_keahlian'])): ?>
                        <p class="text-xs text-blue-200"><?php echo htmlspecialchars($guru_detail['bidang_keahlian']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Navigation -->
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
                    <i class="fas fa-chalkboard-teacher"></i>
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
                        <i class="fas fa-chalkboard-teacher text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Guru</p>
                        <?php if (!empty($guru_detail['bidang_keahlian'])): ?>
                            <p class="text-xs text-blue-200"><?php echo htmlspecialchars($guru_detail['bidang_keahlian']); ?></p>
                        <?php endif; ?>
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
                    <h1 class="text-2xl font-bold text-gray-800">Dashboard Guru</h1>
                    <p class="text-gray-600">Selamat datang, <?php echo htmlspecialchars($full_name); ?>!</p>
                    <?php if (!empty($guru_detail['tanggal_bergabung'])): ?>
                        <p class="text-sm text-gray-500 mt-1">
                            Bergabung sejak: <?php echo date('d F Y', strtotime($guru_detail['tanggal_bergabung'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Total Siswa Diajar -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-4">
                            <i class="fas fa-users text-blue-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Siswa Diajar</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_siswa']); ?>
                            </h3>
                            <p class="text-xs text-gray-500">Siswa</p>
                        </div>
                    </div>
                </div>

                <!-- Total Pertemuan -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 rounded-lg mr-4">
                            <i class="fas fa-calendar-check text-purple-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Jadwal</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_jadwal_sesi']); ?>
                            </h3>
                            <p class="text-xs text-gray-500">Jadwal terisi</p>
                        </div>
                    </div>
                </div>

                <!-- Total Penilaian -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-yellow-100 rounded-lg mr-4">
                            <i class="fas fa-clipboard-check text-yellow-600 text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm">Total Penilaian</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($statistics['total_penilaian']); ?>
                            </h3>
                            <p class="text-xs text-gray-500"><?php echo $statistics['penilaian_bulan_ini']; ?> bulan ini</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Kapasitas & Rata-rata -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Kapasitas Mengajar -->
                <!-- <div class="bg-white rounded-xl p-5 shadow">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">
                        <i class="fas fa-chart-pie mr-2 text-blue-600"></i>Kapasitas Mengajar
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>Total Kapasitas</span>
                                <span class="font-semibold"><?php echo $statistics['kapasitas_total']; ?> siswa</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="bg-blue-600 h-2.5 rounded-full progress-bar" style="width: <?php echo $kapasitas_persen; ?>%"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Terisi: <?php echo $statistics['kapasitas_terisi']; ?> dari <?php echo $statistics['kapasitas_total']; ?> siswa
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-2 pt-2">
                            <div class="text-center p-2 bg-blue-50 rounded">
                                <span class="text-xs text-gray-600">Rata-rata Nilai</span>
                                <p class="text-xl font-bold text-blue-600"><?php echo $statistics['rata_nilai']; ?></p>
                            </div>
                            <div class="text-center p-2 bg-green-50 rounded">
                                <span class="text-xs text-gray-600">Siswa Terbaik</span>
                                <p class="text-sm font-semibold text-green-600 truncate"><?php echo htmlspecialchars($statistics['siswa_terbaik']['nama_lengkap']); ?></p>
                                <p class="text-xs text-gray-500">Score: <?php echo $statistics['siswa_terbaik']['total_score']; ?>/50</p>
                            </div>
                        </div>
                    </div>
                </div> -->

                <!-- Jadwal Hari Ini
                <div class="bg-white rounded-xl p-5 shadow">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">
                        <i class="fas fa-calendar-day mr-2 text-green-600"></i>Jadwal Hari Ini (<?php echo $hari_ini_id; ?>)
                    </h3>
                    <?php if (count($jadwal_hari_ini) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($jadwal_hari_ini as $jadwal): ?>
                                <div class="border-l-4 <?php echo ($jadwal['kapasitas_terisi'] >= $jadwal['kapasitas_maks']) ? 'border-red-500' : 'border-green-500'; ?> pl-3 py-1">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="font-medium text-gray-900">
                                                <?php echo $jadwal['jam_mulai']; ?> - <?php echo $jadwal['jam_selesai']; ?>
                                            </p>
                                            <p class="text-sm text-gray-600">
                                                <?php echo $jadwal['total_siswa_terjadwal']; ?> siswa terjadwal
                                            </p>
                                            <?php if (!empty($jadwal['daftar_siswa'])): ?>
                                                <p class="text-xs text-gray-500 truncate max-w-xs" title="<?php echo htmlspecialchars($jadwal['daftar_siswa']); ?>">
                                                    <?php echo htmlspecialchars(substr($jadwal['daftar_siswa'], 0, 50)) . '...'; ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-xs px-2 py-1 rounded-full <?php echo ($jadwal['kapasitas_terisi'] >= $jadwal['kapasitas_maks']) ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                            <?php echo $jadwal['kapasitas_terisi']; ?>/<?php echo $jadwal['kapasitas_maks']; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times text-gray-300 text-3xl mb-2"></i>
                            <p class="text-gray-500">Tidak ada jadwal hari ini</p>
                        </div>
                    <?php endif; ?>
                </div> -->
            </div>

            <!-- Recent Data -->
            <div class="grid grid-cols-1 gap-6 mb-8">
                <!-- Penilaian Terbaru -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">
                            <i class="fas fa-history mr-2"></i> Penilaian Terbaru
                        </h3>
                    </div>
                    <div class="px-4 py-2 sm:p-6">
                        <div class="flow-root">
                            <ul class="divide-y divide-gray-200">
                                <?php if (count($penilaian_terbaru) > 0): ?>
                                    <?php foreach ($penilaian_terbaru as $penilaian): ?>
                                        <li class="py-3 hover:bg-gray-50">
                                            <div class="flex items-center space-x-4">
                                                <div class="flex-shrink-0">
                                                    <div class="h-10 w-10 rounded-full 
                                                        <?php
                                                        $score = $penilaian['total_score'] ?? 0;
                                                        if ($score >= 40) echo 'bg-green-100 text-green-800';
                                                        elseif ($score >= 30) echo 'bg-blue-100 text-blue-800';
                                                        elseif ($score >= 20) echo 'bg-yellow-100 text-yellow-800';
                                                        else echo 'bg-red-100 text-red-800';
                                                        ?> flex items-center justify-center">
                                                        <span class="text-xs font-bold"><?php echo $score; ?></span>
                                                    </div>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 truncate">
                                                        <?php echo htmlspecialchars($penilaian['nama_siswa'] ?? 'N/A'); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500 truncate">
                                                        Kelas: <?php echo htmlspecialchars($penilaian['kelas'] ?? 'N/A'); ?> | 
                                                        Tingkat: <?php echo htmlspecialchars($penilaian['tingkat_bimbel'] ?? 'N/A'); ?>
                                                    </p>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-sm font-semibold text-gray-900">
                                                        <?php echo $penilaian['total_score'] ?? 0; ?>/50
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo isset($penilaian['tanggal_penilaian']) ? date('d M Y', strtotime($penilaian['tanggal_penilaian'])) : '-'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="py-4 text-center text-gray-500">
                                        <i class="fas fa-clipboard-list text-2xl mb-2"></i>
                                        <p>Belum ada data penilaian</p>
                                        <p class="text-xs text-gray-400 mt-1">Silakan input penilaian baru</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="riwayat.php" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-900">
                                <i class="fas fa-eye mr-1"></i> Lihat semua penilaian
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Siswa Belum Dinilai Bulan Ini -->
                <!-- <div class="bg-white shadow rounded-lg">
                    <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">
                            <i class="fas fa-exclamation-triangle mr-2 text-yellow-500"></i> Siswa Belum Dinilai (<?php echo date('F Y'); ?>)
                        </h3>
                    </div>
                    <div class="px-4 py-2 sm:p-6">
                        <div class="flow-root">
                            <ul class="divide-y divide-gray-200">
                                <?php if (count($siswa_belum_dinilai) > 0): ?>
                                    <?php foreach ($siswa_belum_dinilai as $siswa): ?>
                                        <li class="py-3 hover:bg-gray-50">
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 bg-yellow-100 rounded-full flex items-center justify-center">
                                                        <i class="fas fa-user-graduate text-yellow-600 text-xs"></i>
                                                    </div>
                                                    <div class="ml-3">
                                                        <p class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                        </p>
                                                        <p class="text-xs text-gray-500">
                                                            Kelas: <?php echo htmlspecialchars($siswa['kelas']); ?> | 
                                                            Tingkat: <?php echo htmlspecialchars($siswa['tingkat_bimbel']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <a href="inputNilai.php?siswa_id=<?php echo $siswa['id']; ?>" 
                                                   class="inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                                    <i class="fas fa-plus mr-1"></i> Nilai
                                                </a>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="py-4 text-center text-gray-500">
                                        <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                                        <p>Semua siswa sudah dinilai bulan ini</p>
                                        <p class="text-xs text-gray-400 mt-1">Good job! 🎉</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="inputNilai.php" class="inline-flex items-center text-sm text-blue-600 hover:text-blue-900">
                                <i class="fas fa-plus-circle mr-1"></i> Input nilai baru
                            </a>
                        </div>
                    </div>
                </div> -->
            </div>

            <!-- Quick Actions -->
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                    <i class="fas fa-bolt mr-2"></i> Aksi Cepat
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <a href="inputNilai.php" class="flex flex-col items-center justify-center p-6 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-blue-600 flex items-center justify-center mb-3">
                            <i class="fas fa-plus-circle text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Input Nilai</span>
                        <span class="text-xs text-gray-500 mt-1">Penilaian baru</span>
                    </a>

                    <a href="dataSiswa.php" class="flex flex-col items-center justify-center p-6 bg-green-50 hover:bg-green-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-green-600 flex items-center justify-center mb-3">
                            <i class="fas fa-users text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Data Siswa</span>
                        <span class="text-xs text-gray-500 mt-1">Lihat siswa</span>
                    </a>

                    <a href="absensiGuru.php" class="flex flex-col items-center justify-center p-6 bg-orange-50 hover:bg-orange-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-orange-600 flex items-center justify-center mb-3">
                            <i class="fas fa-calendar-check text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Absensi</span>
                        <span class="text-xs text-gray-500 mt-1">Input kehadiran</span>
                    </a>

                    <a href="riwayat.php" class="flex flex-col items-center justify-center p-6 bg-purple-50 hover:bg-purple-100 rounded-lg transition duration-300">
                        <div class="h-12 w-12 rounded-full bg-purple-600 flex items-center justify-center mb-3">
                            <i class="fas fa-history text-white text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900">Riwayat</span>
                        <span class="text-xs text-gray-500 mt-1">Histori penilaian</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Dashboard Guru</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Login terakhir: <?php echo date('d F Y H:i'); ?>
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

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    mobileMenu.classList.remove('menu-open');
                    if (menuOverlay) {
                        menuOverlay.classList.remove('active');
                    }
                    document.body.style.overflow = 'auto';
                }
            });
        });
        
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

        // Debug info di console
        console.log('Guru ID:', <?php echo $guru_id; ?>);
        console.log('Statistik:', <?php echo json_encode($statistics); ?>);
        console.log('Jadwal Hari Ini:', <?php echo json_encode($jadwal_hari_ini); ?>);
    </script>
</body>
</html>