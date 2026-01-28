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

// Tanggal default hari ini
$tanggal = date('Y-m-d');
if (isset($_GET['tanggal']) && !empty($_GET['tanggal'])) {
    $tanggal = $_GET['tanggal'];
}

// Dapatkan hari dari tanggal yang dipilih
$hari = date('l', strtotime($tanggal));
$hari_indonesia = '';
switch($hari) {
    case 'Monday': $hari_indonesia = 'Senin'; break;
    case 'Tuesday': $hari_indonesia = 'Selasa'; break;
    case 'Wednesday': $hari_indonesia = 'Rabu'; break;
    case 'Thursday': $hari_indonesia = 'Kamis'; break;
    case 'Friday': $hari_indonesia = 'Jumat'; break;
    case 'Saturday': $hari_indonesia = 'Sabtu'; break;
    case 'Sunday': $hari_indonesia = 'Minggu'; break;
}

// Cek apakah ada parameter sukses dari redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = "Absensi berhasil disimpan!";
}

// Proses simpan absensi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_absensi'])) {
    try {
        $conn->begin_transaction();
        
        // Simpan filter yang aktif untuk validasi
        $filter_hari = $_POST['filter_hari'] ?? '';
        $filter_jam = $_POST['filter_jam'] ?? '';
        
        foreach ($_POST['siswa'] as $siswa_id => $data) {
            // Dapatkan siswa_pelajaran_id dan jadwal_id dari POST
            $siswa_pelajaran_id = $data['siswa_pelajaran_id'];
            $jadwal_id = $data['jadwal_id'] ?? null;
            $pendaftaran_id = $data['pendaftaran_id']; // Untuk referensi tambahan
            
            // Validasi: Jika ada filter hari/jam, pastikan jadwal sesuai
            $skip_this = false;
            if (!empty($filter_hari) || !empty($filter_jam)) {
                // Ambil info jadwal untuk validasi
                $validate_sql = "SELECT jb.hari, jb.jam_mulai, jb.jam_selesai 
                               FROM jadwal_belajar jb 
                               WHERE jb.id = ?";
                $validate_stmt = $conn->prepare($validate_sql);
                $validate_stmt->bind_param("i", $jadwal_id);
                $validate_stmt->execute();
                $validate_result = $validate_stmt->get_result();
                
                if ($jadwal_row = $validate_result->fetch_assoc()) {
                    // Validasi hari
                    if (!empty($filter_hari) && $jadwal_row['hari'] != $filter_hari) {
                        $skip_this = true;
                    }
                    
                    // Validasi jam
                    if (!empty($filter_jam) && !$skip_this) {
                        list($jam_mulai_filter, $jam_selesai_filter) = explode('-', $filter_jam);
                        $jam_mulai_db = date('H:i', strtotime($jadwal_row['jam_mulai']));
                        $jam_selesai_db = date('H:i', strtotime($jadwal_row['jam_selesai']));
                        
                        if ($jam_mulai_db != $jam_mulai_filter || $jam_selesai_db != $jam_selesai_filter) {
                            $skip_this = true;
                        }
                    }
                } else {
                    $skip_this = true;
                }
                $validate_stmt->close();
            }
            
            if ($skip_this) {
                continue;
            }
            
            // Cek apakah sudah ada absensi untuk siswa ini hari ini pada siswa_pelajaran dan jadwal ini
            $check_sql = "SELECT id FROM absensi_siswa 
                         WHERE siswa_id = ? 
                         AND siswa_pelajaran_id = ?
                         AND jadwal_id = ?
                         AND tanggal_absensi = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("iiis", $siswa_id, $siswa_pelajaran_id, $jadwal_id, $tanggal);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update absensi yang sudah ada
                $row = $check_result->fetch_assoc();
                $update_sql = "UPDATE absensi_siswa 
                              SET status = ?, keterangan = ?, updated_at = NOW()
                              WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssi", $data['status'], $data['keterangan'], $row['id']);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Insert absensi baru
                if ($jadwal_id !== null) {
                    $insert_sql = "INSERT INTO absensi_siswa 
                                  (siswa_id, pendaftaran_id, siswa_pelajaran_id, jadwal_id, guru_id, 
                                   tanggal_absensi, status, keterangan, created_at, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iiiissss", 
                        $siswa_id, 
                        $pendaftaran_id,
                        $siswa_pelajaran_id,
                        $jadwal_id,
                        $guru_id, 
                        $tanggal, 
                        $data['status'], 
                        $data['keterangan']
                    );
                } else {
                    $insert_sql = "INSERT INTO absensi_siswa 
                                  (siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, 
                                   tanggal_absensi, status, keterangan, created_at, updated_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param("iiissss", 
                        $siswa_id, 
                        $pendaftaran_id,
                        $siswa_pelajaran_id,
                        $guru_id, 
                        $tanggal, 
                        $data['status'], 
                        $data['keterangan']
                    );
                }
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            $check_stmt->close();
        }
        
        $conn->commit();
        
        // Redirect untuk menghindari resubmit dengan parameter lengkap
        $redirect_params = [];
        if (!empty($_GET['siswa_id'])) $redirect_params[] = "siswa_id=" . urlencode($_GET['siswa_id']);
        if (!empty($_GET['hari'])) $redirect_params[] = "hari=" . urlencode($_GET['hari']);
        if (!empty($_GET['jam'])) $redirect_params[] = "jam=" . urlencode($_GET['jam']);
        if (!empty($tanggal)) $redirect_params[] = "tanggal=" . urlencode($tanggal);
        $redirect_params[] = "success=1";
        
        header("Location: absensiSiswa.php?" . implode('&', $redirect_params));
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
}

// Ambil parameter filter dari GET
$filter_siswa_id = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;
$filter_hari = isset($_GET['hari']) ? $_GET['hari'] : '';
$filter_jam = isset($_GET['jam']) ? $_GET['jam'] : '';

// Ambil daftar siswa yang diajar oleh guru ini (berdasarkan siswa_pelajaran)
$sql_siswa_list = "SELECT DISTINCT 
                    s.id as siswa_id,
                    s.nama_lengkap as nama_siswa,
                    s.kelas as kelas_sekolah,
                    sp.nama_pelajaran
                   FROM siswa s
                   JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                   JOIN siswa_pelajaran sp ON ps.id = sp.pendaftaran_id
                   WHERE sp.guru_id = ? 
                   AND ps.status = 'aktif'
                   AND sp.status = 'aktif'
                   AND s.status = 'aktif'
                   ORDER BY s.nama_lengkap";

$stmt_siswa_list = $conn->prepare($sql_siswa_list);
$stmt_siswa_list->bind_param("i", $guru_id);
$stmt_siswa_list->execute();
$result_siswa_list = $stmt_siswa_list->get_result();
$siswa_list = [];
while ($row = $result_siswa_list->fetch_assoc()) {
    $siswa_list[$row['siswa_id']] = $row;
}
$stmt_siswa_list->close();

// Ambil daftar mata pelajaran yang diajar oleh guru ini
$sql_pelajaran_list = "SELECT DISTINCT sp.nama_pelajaran
                       FROM siswa_pelajaran sp
                       WHERE sp.guru_id = ?
                       AND sp.status = 'aktif'
                       ORDER BY sp.nama_pelajaran";

$stmt_pelajaran_list = $conn->prepare($sql_pelajaran_list);
$stmt_pelajaran_list->bind_param("i", $guru_id);
$stmt_pelajaran_list->execute();
$result_pelajaran_list = $stmt_pelajaran_list->get_result();
$pelajaran_list = [];
while ($row = $result_pelajaran_list->fetch_assoc()) {
    $pelajaran_list[] = $row['nama_pelajaran'];
}
$stmt_pelajaran_list->close();

// Ambil daftar hari yang tersedia untuk guru ini (berdasarkan jadwal_belajar)
$sql_hari_list = "SELECT DISTINCT jb.hari
                  FROM jadwal_belajar jb
                  JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                  WHERE sp.guru_id = ?
                  AND jb.status = 'aktif'
                  AND sp.status = 'aktif'
                  ORDER BY FIELD(jb.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu')";

$stmt_hari_list = $conn->prepare($sql_hari_list);
$stmt_hari_list->bind_param("i", $guru_id);
$stmt_hari_list->execute();
$result_hari_list = $stmt_hari_list->get_result();
$hari_list = [];
while ($row = $result_hari_list->fetch_assoc()) {
    $hari_list[] = $row['hari'];
}
$stmt_hari_list->close();

// list jam sesi yang tersedia untuk guru ini
$sql_jam_list = "SELECT 
                  TIME_FORMAT(jb.jam_mulai, '%H:%i') as jam_mulai,
                  TIME_FORMAT(jb.jam_selesai, '%H:%i') as jam_selesai,
                  CONCAT(TIME_FORMAT(jb.jam_mulai, '%H:%i'), '-', TIME_FORMAT(jb.jam_selesai, '%H:%i')) as jam_range
                 FROM jadwal_belajar jb
                 JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                 WHERE sp.guru_id = ?
                 AND jb.status = 'aktif'
                 AND sp.status = 'aktif'
                 GROUP BY TIME_FORMAT(jb.jam_mulai, '%H:%i'), TIME_FORMAT(jb.jam_selesai, '%H:%i'), 
                          CONCAT(TIME_FORMAT(jb.jam_mulai, '%H:%i'), '-', TIME_FORMAT(jb.jam_selesai, '%H:%i'))
                 ORDER BY TIME_FORMAT(jb.jam_mulai, '%H:%i')";

$stmt_jam_list = $conn->prepare($sql_jam_list);
$stmt_jam_list->bind_param("i", $guru_id);
$stmt_jam_list->execute();
$result_jam_list = $stmt_jam_list->get_result();
$jam_list = [];
while ($row = $result_jam_list->fetch_assoc()) {
    $jam_list[] = $row;
}
$stmt_jam_list->close();

// Ambil daftar siswa berdasarkan filter
$daftar_siswa = [];
$absensi_hari_ini = [];
$jadwal_info = [];

if (!empty($filter_siswa_id) || !empty($filter_hari) || !empty($filter_jam)) {
    // Build query berdasarkan filter
    $sql_siswa = "SELECT 
                    s.id, 
                    s.nama_lengkap, 
                    s.kelas as kelas_sekolah,
                    ps.id as pendaftaran_id,
                    ps.tingkat,
                    sp.id as siswa_pelajaran_id,
                    sp.nama_pelajaran,
                    jb.id as jadwal_id,
                    jb.hari,
                    jb.jam_mulai,
                    jb.jam_selesai,
                    u.full_name as nama_guru
                 FROM siswa s
                 JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                 JOIN siswa_pelajaran sp ON ps.id = sp.pendaftaran_id
                 LEFT JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id AND jb.status = 'aktif'
                 JOIN guru g ON sp.guru_id = g.id
                 JOIN users u ON g.user_id = u.id
                 WHERE sp.guru_id = ?
                 AND ps.status = 'aktif'
                 AND sp.status = 'aktif'
                 AND s.status = 'aktif'";
    
    $params = [$guru_id];
    $types = "i";
    
    // Tambahkan filter siswa jika ada
    if (!empty($filter_siswa_id)) {
        $sql_siswa .= " AND s.id = ?";
        $params[] = $filter_siswa_id;
        $types .= "i";
    }
    
    // Tambahkan filter hari jika ada
    if (!empty($filter_hari)) {
        $sql_siswa .= " AND jb.hari = ?";
        $params[] = $filter_hari;
        $types .= "s";
    }
    
    // Tambahkan filter jam jika ada
    if (!empty($filter_jam)) {
        list($jam_mulai_filter, $jam_selesai_filter) = explode('-', $filter_jam);
        $sql_siswa .= " AND jb.jam_mulai = ? AND jb.jam_selesai = ?";
        $params[] = $jam_mulai_filter;
        $params[] = $jam_selesai_filter;
        $types .= "ss";
    }
    
    $sql_siswa .= " ORDER BY jb.hari, jb.jam_mulai, s.nama_lengkap";
    
    $stmt_siswa = $conn->prepare($sql_siswa);
    if ($params) {
        $stmt_siswa->bind_param($types, ...$params);
    }
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();
    
    while ($row = $result_siswa->fetch_assoc()) {
        $daftar_siswa[] = $row;
        // Simpan info jadwal untuk statistik
        $jadwal_info = [
            'hari' => $row['hari'],
            'jam_mulai' => $row['jam_mulai'],
            'jam_selesai' => $row['jam_selesai'],
            'nama_guru' => $row['nama_guru']
        ];
    }
    $stmt_siswa->close();
    
    // Ambil data absensi hari ini untuk siswa yang difilter
    if (!empty($daftar_siswa)) {
        foreach ($daftar_siswa as $siswa) {
            if (!empty($siswa['jadwal_id'])) {
                $sql_absensi = "SELECT siswa_id, status, keterangan 
                               FROM absensi_siswa 
                               WHERE siswa_id = ? 
                               AND siswa_pelajaran_id = ?
                               AND jadwal_id = ?
                               AND tanggal_absensi = ?";
                
                $stmt_absensi = $conn->prepare($sql_absensi);
                $stmt_absensi->bind_param("iiis", 
                    $siswa['id'], 
                    $siswa['siswa_pelajaran_id'], 
                    $siswa['jadwal_id'],
                    $tanggal
                );
                $stmt_absensi->execute();
                $result_absensi = $stmt_absensi->get_result();
                
                if ($row = $result_absensi->fetch_assoc()) {
                    $absensi_hari_ini[$siswa['id'] . '_' . $siswa['jadwal_id']] = $row;
                }
                $stmt_absensi->close();
            }
        }
    }
}

// Jika filter kosong, pilih hari ini secara default
if (empty($filter_hari) && in_array($hari_indonesia, $hari_list)) {
    $filter_hari = $hari_indonesia;
}

// Hitung statistik absensi hari ini
$statistik = [
    'total_siswa' => count($daftar_siswa),
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

foreach ($absensi_hari_ini as $absensi) {
    $status = $absensi['status'];
    if (isset($statistik[$status])) {
        $statistik[$status]++;
    }
}
$statistik['belum_absen'] = $statistik['total_siswa'] - 
                           ($statistik['hadir'] + $statistik['izin'] + $statistik['sakit'] + $statistik['alpha']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Harian - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .status-hadir { background-color: #d1fae5; color: #065f46; }
        .status-izin { background-color: #fef3c7; color: #92400e; }
        .status-sakit { background-color: #dbeafe; color: #1e40af; }
        .status-alpha { background-color: #fee2e2; color: #991b1b; }
        .status-default { background-color: #f3f4f6; color: #6b7280; }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .btn-hadir:hover { background-color: #10b981; }
        .btn-izin:hover { background-color: #f59e0b; }
        .btn-sakit:hover { background-color: #3b82f6; }
        .btn-alpha:hover { background-color: #ef4444; }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        @media (max-width: 768px) {
            .table-responsive table {
                min-width: 800px;
            }
        }
        
        .jadwal-option {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .jadwal-time {
            font-size: 12px;
            color: #6b7280;
            background-color: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
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
            
            .stat-card {
                padding: 1rem !important;
            }
            
            .quick-action-btn {
                padding: 0.75rem !important;
            }
            
            .filter-grid {
                grid-template-columns: 1fr !important;
            }
        }
        
        .row-disabled {
            background-color: #f9fafb !important;
        }
        
        .row-disabled select,
        .row-disabled input {
            background-color: #f3f4f6 !important;
            color: #9ca3af !important;
            cursor: not-allowed;
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
                        <i class="fas fa-calendar-check mr-2"></i> Absensi Harian
                    </h1>
                    <p class="text-gray-600">Input absensi siswa berdasarkan jadwal mengajar</p>
                </div>
                <div class="mt-2 md:mt-0 flex items-center space-x-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y', strtotime($tanggal)); ?>
                        <span class="ml-2 bg-blue-200 px-2 py-1 rounded text-xs">
                            <?php echo $hari_indonesia; ?>
                        </span>
                    </span>
                    <a href="rekapAbsensi.php" class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-green-100 text-green-800 hover:bg-green-200">
                        <i class="fas fa-chart-bar mr-2"></i> Rekap
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
                    <button onclick="hideSuccessMessage()" class="ml-auto text-green-700 hover:text-green-900">
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

            <!-- Filter Section -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Filter Absensi</h3>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 filter-grid">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar mr-1"></i> Tanggal
                        </label>
                        <input type="date" name="tanggal" value="<?php echo $tanggal; ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user-graduate mr-1"></i> Siswa
                        </label>
                        <select name="siswa_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Siswa</option>
                            <?php foreach ($siswa_list as $id => $siswa): ?>
                            <option value="<?php echo $id; ?>" 
                                <?php echo ($filter_siswa_id == $id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($siswa['nama_siswa'] . ' - ' . $siswa['kelas_sekolah']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-day mr-1"></i> Hari
                        </label>
                        <select name="hari" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Hari</option>
                            <?php foreach ($hari_list as $hari_opt): ?>
                            <option value="<?php echo $hari_opt; ?>" 
                                <?php echo ($filter_hari == $hari_opt) ? 'selected' : ''; ?>>
                                <?php echo $hari_opt; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-clock mr-1"></i> Jam Sesi
                        </label>
                        <select name="jam" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Jam</option>
                            <?php foreach ($jam_list as $jam): ?>
                            <option value="<?php echo htmlspecialchars($jam['jam_range']); ?>" 
                                <?php echo ($filter_jam == $jam['jam_range']) ? 'selected' : ''; ?>>
                                <?php echo $jam['jam_range']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-4 flex items-end space-x-2">
                        <button type="submit" class="w-full md:w-auto px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <i class="fas fa-search mr-2"></i> Tampilkan
                        </button>
                        <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>" 
                           class="w-full md:w-auto px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                    </div>
                </form>
                
                <?php if (!empty($filter_siswa_id) || !empty($filter_hari) || !empty($filter_jam)): ?>
                <div class="mt-4 p-3 bg-blue-50 rounded-md">
                    <p class="text-sm text-blue-700">
                        <i class="fas fa-info-circle mr-1"></i>
                        Filter aktif: 
                        <?php 
                        $filters_active = [];
                        if (!empty($filter_siswa_id) && isset($siswa_list[$filter_siswa_id])) {
                            $filters_active[] = "Siswa: " . $siswa_list[$filter_siswa_id]['nama_siswa'];
                        }
                        if (!empty($filter_hari)) {
                            $filters_active[] = "Hari: " . $filter_hari;
                        }
                        if (!empty($filter_jam)) {
                            $filters_active[] = "Jam: " . $filter_jam;
                        }
                        echo implode(' | ', $filters_active);
                        ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stats Cards -->
            <?php if (!empty($daftar_siswa)): ?>
            <div class="mb-6 bg-white rounded-lg shadow p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-calendar-alt mr-2"></i>
                            <?php if (!empty($jadwal_info['hari']) && !empty($jadwal_info['jam_mulai'])): ?>
                            Jadwal: <span class="text-blue-600">
                                <?php echo htmlspecialchars($jadwal_info['hari'] ?? ''); ?>
                                <?php echo date('H:i', strtotime($jadwal_info['jam_mulai'] ?? '')); ?> - 
                                <?php echo date('H:i', strtotime($jadwal_info['jam_selesai'] ?? '')); ?>
                            </span>
                            <?php else: ?>
                            Hasil Filter
                            <?php endif; ?>
                        </h3>
                        <p class="text-gray-600">
                            Total <?php echo $statistik['total_siswa']; ?> siswa ditemukan
                            <?php if (!empty($jadwal_info['nama_guru'])): ?>
                            | Guru: <span class="font-medium"><?php echo htmlspecialchars($jadwal_info['nama_guru']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="mt-2 md:mt-0">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-users mr-1"></i>
                            <?php echo $statistik['total_siswa']; ?> Siswa
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="p-2 bg-gray-100 rounded-lg mr-3">
                                <i class="fas fa-users text-gray-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Siswa</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['total_siswa']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-check text-green-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Hadir</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['hadir']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-envelope text-yellow-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Izin</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['izin']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-thermometer text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Sakit</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['sakit']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg p-4 shadow">
                        <div class="flex items-center">
                            <div class="p-2 bg-red-100 rounded-lg mr-3">
                                <i class="fas fa-times text-red-600"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Alpha</p>
                                <p class="text-xl font-bold text-gray-800"><?php echo $statistik['alpha']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Absensi -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">
                            <i class="fas fa-list mr-2"></i> 
                            Daftar Siswa
                            <?php if (!empty($jadwal_info['jam_mulai'])): ?>
                            - Sesi <?php echo date('H:i', strtotime($jadwal_info['jam_mulai'])); ?>
                            <?php endif; ?>
                            
                            <?php if (!empty($filter_hari) || !empty($filter_jam)): ?>
                            <span class="text-sm text-blue-600 ml-2">
                                (<?php 
                                if (!empty($filter_hari)) echo $filter_hari . ' ';
                                if (!empty($filter_jam)) echo $filter_jam;
                                ?>)
                            </span>
                            <?php endif; ?>
                        </h3>
                        <div class="text-sm <?php echo $statistik['belum_absen'] > 0 ? 'text-orange-500' : 'text-green-500'; ?>">
                            <?php if ($statistik['belum_absen'] > 0): ?>
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <?php echo $statistik['belum_absen']; ?> siswa belum diabsensi
                            <?php else: ?>
                                <i class="fas fa-check-circle mr-1"></i>
                                Semua siswa sudah diabsensi
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <form method="POST" id="formAbsensi">
                    <input type="hidden" name="simpan_absensi" value="1">
                    <input type="hidden" name="tanggal" value="<?php echo $tanggal; ?>">
                    <!-- Simpan filter aktif untuk validasi -->
                    <input type="hidden" name="filter_hari" value="<?php echo htmlspecialchars($filter_hari); ?>">
                    <input type="hidden" name="filter_jam" value="<?php echo htmlspecialchars($filter_jam); ?>">
                    
                    <?php if (empty($filter_hari) && empty($filter_jam)): ?>
                    <div class="px-6 py-3 bg-yellow-50 border-y border-yellow-200">
                        <div class="flex items-center text-yellow-700">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <div>
                                <p class="font-medium">Perhatian!</p>
                                <p class="text-sm">Anda tidak memfilter hari/jam spesifik. Pastikan Anda hanya mengabsensi jadwal yang sesuai.</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas Sekolah</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mata Pelajaran</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tingkat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hari & Jam</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($daftar_siswa as $index => $siswa): 
                                    $absensi_key = $siswa['id'] . '_' . $siswa['jadwal_id'];
                                    $current_status = $absensi_hari_ini[$absensi_key]['status'] ?? '';
                                    $current_keterangan = $absensi_hari_ini[$absensi_key]['keterangan'] ?? '';
                                    
                                    // Cek apakah jadwal ini sesuai dengan filter aktif
                                    $is_filtered_jadwal = true;
                                    if (!empty($filter_hari) && $siswa['hari'] != $filter_hari) {
                                        $is_filtered_jadwal = false;
                                    }
                                    if (!empty($filter_jam)) {
                                        list($jam_mulai_filter, $jam_selesai_filter) = explode('-', $filter_jam);
                                        $jam_mulai_db = date('H:i', strtotime($siswa['jam_mulai']));
                                        $jam_selesai_db = date('H:i', strtotime($siswa['jam_selesai']));
                                        
                                        if ($jam_mulai_db != $jam_mulai_filter || $jam_selesai_db != $jam_selesai_filter) {
                                            $is_filtered_jadwal = false;
                                        }
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 <?php echo !$is_filtered_jadwal ? 'row-disabled' : ''; ?>" id="row-<?php echo $siswa['id']; ?>-<?php echo $siswa['jadwal_id']; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $index + 1; ?>
                                        <?php if (!$is_filtered_jadwal): ?>
                                        <span class="text-xs text-gray-500 ml-1" title="Jadwal ini tidak sesuai filter aktif">
                                            <i class="fas fa-info-circle"></i>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['nama_pelajaran']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($siswa['tingkat']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div class="font-medium"><?php echo htmlspecialchars($siswa['hari']); ?></div>
                                        <div class="text-xs text-gray-400">
                                            <?php echo date('H:i', strtotime($siswa['jam_mulai'])); ?> - 
                                            <?php echo date('H:i', strtotime($siswa['jam_selesai'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="hidden" name="siswa[<?php echo $siswa['id']; ?>][pendaftaran_id]" value="<?php echo $siswa['pendaftaran_id']; ?>">
                                        <input type="hidden" name="siswa[<?php echo $siswa['id']; ?>][siswa_pelajaran_id]" value="<?php echo $siswa['siswa_pelajaran_id']; ?>">
                                        <input type="hidden" name="siswa[<?php echo $siswa['id']; ?>][jadwal_id]" value="<?php echo $siswa['jadwal_id']; ?>">
                                        <select name="siswa[<?php echo $siswa['id']; ?>][status]" 
                                                class="status-select w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500
                                                       <?php echo $current_status ? 'status-' . $current_status : 'status-default'; ?>"
                                                onchange="updateRowStatus(this)"
                                                <?php echo !$is_filtered_jadwal ? 'disabled' : ''; ?>
                                                data-jadwal-id="<?php echo $siswa['jadwal_id']; ?>"
                                                title="<?php echo !$is_filtered_jadwal ? 'Jadwal tidak sesuai filter aktif' : ''; ?>">
                                            <option value="">Pilih Status</option>
                                            <option value="hadir" <?php echo ($current_status == 'hadir') ? 'selected' : ''; ?>>Hadir</option>
                                            <option value="izin" <?php echo ($current_status == 'izin') ? 'selected' : ''; ?>>Izin</option>
                                            <option value="sakit" <?php echo ($current_status == 'sakit') ? 'selected' : ''; ?>>Sakit</option>
                                            <option value="alpha" <?php echo ($current_status == 'alpha') ? 'selected' : ''; ?>>Alpha</option>
                                        </select>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="text" 
                                               name="siswa[<?php echo $siswa['id']; ?>][keterangan]" 
                                               value="<?php echo htmlspecialchars($current_keterangan); ?>"
                                               placeholder="Alasan jika tidak hadir"
                                               class="keterangan-input w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                               <?php echo !$is_filtered_jadwal ? 'disabled' : ''; ?>
                                               data-jadwal-id="<?php echo $siswa['jadwal_id']; ?>"
                                               title="<?php echo !$is_filtered_jadwal ? 'Jadwal tidak sesuai filter aktif' : ''; ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?php if (!empty($filter_hari) || !empty($filter_jam)): ?>
                                Hanya jadwal sesuai filter yang akan disimpan
                                <?php else: ?>
                                Semua jadwal akan disimpan
                                <?php endif; ?>
                            </div>
                            <div class="space-x-3">
                                <button type="button" onclick="setAllStatus('hadir')" 
                                        class="px-4 py-2 bg-green-100 text-green-800 rounded-md hover:bg-green-200">
                                    <i class="fas fa-check mr-2"></i>Semua Hadir
                                </button>
                                <button type="submit" 
                                        class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <i class="fas fa-save mr-2"></i>Simpan Absensi
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <?php elseif (!empty($filter_siswa_id) || !empty($filter_hari) || !empty($filter_jam)): ?>
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-search text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Tidak Ada Data</h3>
                <p class="text-gray-600 mb-4">Tidak ditemukan siswa dengan filter yang dipilih</p>
                <a href="absensiSiswa.php?tanggal=<?php echo $tanggal; ?>" 
                   class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <i class="fas fa-redo mr-2"></i> Reset Filter
                </a>
            </div>
            <?php else: ?>
            <div class="bg-white shadow rounded-lg p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-filter text-4xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Gunakan Filter</h3>
                <p class="text-gray-600 mb-4">Silakan pilih filter di atas untuk menampilkan daftar siswa</p>
                <div class="text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>
                    Sistem akan otomatis memfilter hari berdasarkan tanggal yang dipilih
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Absensi Harian</p>
                    </div>
                    <div class="mt-3 md:mt-0">
                        <span class="text-sm text-gray-500">
                            <i class="fas fa-clock mr-1"></i>
                            <?php echo date('H:i:s'); ?>
                        </span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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

        // Close menu when clicking on menu items (mobile)
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

        // Update kelas CSS saat status berubah
        function updateRowStatus(selectElement) {
            const status = selectElement.value;
            const statusClasses = {
                'hadir': 'status-hadir',
                'izin': 'status-izin',
                'sakit': 'status-sakit',
                'alpha': 'status-alpha',
                '': 'status-default'
            };
            
            // Reset semua kelas status
            Object.values(statusClasses).forEach(cls => {
                selectElement.classList.remove(cls);
            });
            
            // Tambah kelas baru
            selectElement.classList.add(statusClasses[status] || 'status-default');
        }

        // Set semua siswa ke status tertentu (hanya yang aktif/enabled)
        function setAllStatus(status) {
            const selectElements = document.querySelectorAll('.status-select:not(:disabled)');
            selectElements.forEach(select => {
                select.value = status;
                updateRowStatus(select);
            });
        }

        // Inisialisasi semua select
        document.querySelectorAll('.status-select').forEach(select => {
            updateRowStatus(select);
        });

        // Konfirmasi sebelum submit
        document.getElementById('formAbsensi')?.addEventListener('submit', function(e) {
            const aktifElements = document.querySelectorAll('.status-select:not(:disabled)');
            const belumDipilih = Array.from(aktifElements).filter(select => !select.value).length;
            const disabledCount = document.querySelectorAll('.status-select:disabled').length;
            
            if (aktifElements.length === 0) {
                alert('Tidak ada jadwal yang sesuai filter untuk disimpan!');
                e.preventDefault();
                return;
            }
            
            if (belumDipilih > 0) {
                if (!confirm(`Masih ada ${belumDipilih} siswa yang belum dipilih statusnya. Lanjutkan simpan?`)) {
                    e.preventDefault();
                }
            }
            
            if (disabledCount > 0) {
                const filterHari = document.querySelector('input[name="filter_hari"]').value;
                const filterJam = document.querySelector('input[name="filter_jam"]').value;
                
                if (filterHari || filterJam) {
                    if (!confirm(`Ada ${disabledCount} jadwal yang tidak sesuai filter (${filterHari} ${filterJam}) dan tidak akan disimpan. Lanjutkan?`)) {
                        e.preventDefault();
                    }
                }
            }
        });

        // Sembunyikan notifikasi sukses setelah 5 detik
        setTimeout(function() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }, 5000);

        // Fungsi untuk menyembunyikan notifikasi sukses
        function hideSuccessMessage() {
            const successMessage = document.getElementById('successMessage');
            if (successMessage) {
                successMessage.style.display = 'none';
            }
        }

        // Auto-fill hari berdasarkan tanggal
        document.querySelector('input[name="tanggal"]').addEventListener('change', function() {
            const date = new Date(this.value);
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const hariIndo = days[date.getDay()];
            
            // Cari option hari yang sesuai
            const hariSelect = document.querySelector('select[name="hari"]');
            const options = hariSelect.options;
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === hariIndo) {
                    hariSelect.value = hariIndo;
                    break;
                }
            }
        });
    </script>
</body>
</html>