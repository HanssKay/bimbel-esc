<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL
$success_message = '';
$error_message = '';

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER DEFAULT
$periode_type = isset($_GET['periode_type']) ? $_GET['periode_type'] : 'bulan';
$bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
$tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$minggu = isset($_GET['minggu']) ? intval($_GET['minggu']) : 1; // Minggu ke-1 sampai 4
$kelas_filter = isset($_GET['kelas_filter']) ? $_GET['kelas_filter'] : '';
$guru_id = isset($_GET['guru_id']) ? intval($_GET['guru_id']) : '';

// =============== FUNGSI BANTU ===============
function getBulanList() {
    return [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
    ];
}

function getKategoriByPersentase($persentase) {
    if ($persentase >= 80) return 'Sangat Baik';
    if ($persentase >= 60) return 'Baik';
    if ($persentase >= 40) return 'Cukup';
    return 'Kurang';
}

function getRangeMingguDalamBulan($minggu, $bulan, $tahun) {
    // Hitung jumlah hari dalam bulan
    $jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
    
    // Bagi bulan menjadi 4 minggu (kurang lebih)
    $hari_per_minggu = ceil($jumlah_hari / 4);
    
    // Hitung tanggal mulai dan akhir untuk setiap minggu
    $start_day = (($minggu - 1) * $hari_per_minggu) + 1;
    $end_day = min($minggu * $hari_per_minggu, $jumlah_hari);
    
    // Format tanggal
    $tanggal_awal = sprintf('%04d-%02d-%02d', $tahun, $bulan, $start_day);
    $tanggal_akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $end_day);
    
    return [
        'awal' => $tanggal_awal,
        'akhir' => $tanggal_akhir,
        'label' => "Minggu ke-$minggu (" . date('d', strtotime($tanggal_awal)) . " - " . 
                  date('d M', strtotime($tanggal_akhir)) . ")"
    ];
}

function getMingguList() {
    return [
        1 => 'Minggu ke-1 (1-7)',
        2 => 'Minggu ke-2 (8-14)', 
        3 => 'Minggu ke-3 (15-21)',
        4 => 'Minggu ke-4 (22-akhir bulan)'
    ];
}

// =============== LOAD OPTIONS ===============
// Load data kelas untuk filter dari tabel siswa
$kelas_sql = "SELECT DISTINCT kelas 
              FROM siswa 
              WHERE status = 'aktif' 
              ORDER BY 
                CASE kelas 
                    WHEN 'Paud' THEN 1
                    WHEN 'TK' THEN 2
                    WHEN '1 SD' THEN 3
                    WHEN '2 SD' THEN 4
                    WHEN '3 SD' THEN 5
                    WHEN '4 SD' THEN 6
                    WHEN '5 SD' THEN 7
                    WHEN '6 SD' THEN 8
                    WHEN '7 SMP' THEN 9
                    WHEN '8 SMP' THEN 10
                    WHEN '9 SMP' THEN 11
                    WHEN '10 SMA' THEN 12
                    WHEN '11 SMA' THEN 13
                    WHEN '12 SMA' THEN 14
                    WHEN 'Alumni' THEN 15
                    WHEN 'Umum' THEN 16
                    ELSE 99
                END";
$kelas_stmt = $conn->prepare($kelas_sql);
$kelas_stmt->execute();
$kelas_result = $kelas_stmt->get_result();
$kelas_options = [];
while ($kelas = $kelas_result->fetch_assoc()) {
    $kelas_options[] = $kelas['kelas'];
}
$kelas_stmt->close();

// Load data guru untuk filter
$guru_sql = "SELECT g.id, u.full_name as nama_guru, g.bidang_keahlian 
             FROM guru g 
             JOIN users u ON g.user_id = u.id 
             WHERE g.status = 'aktif' 
             ORDER BY u.full_name";
$guru_stmt = $conn->prepare($guru_sql);
$guru_stmt->execute();
$guru_result = $guru_stmt->get_result();
$guru_options = [];
while ($guru = $guru_result->fetch_assoc()) {
    $guru_options[] = $guru;
}
$guru_stmt->close();

// =============== GENERATE LAPORAN ===============
$laporan_data = [];
$judul_laporan = '';
$periode_label = '';

// Query berdasarkan periode
if ($periode_type == 'hari') {
    // Laporan Harian
    $judul_laporan = "Laporan Harian";
    $periode_label = "Tanggal " . date('d F Y', strtotime($tanggal));
    
    $sql = "SELECT ps.*, 
                   s.nama_lengkap, s.kelas as tingkat_siswa,
                   u.full_name as nama_guru,
                   ps.kategori as kategori_nilai,
                   pd.mata_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            WHERE DATE(ps.tanggal_penilaian) = ?";
    
    $params = [$tanggal];
    $param_types = "s";
    
} elseif ($periode_type == 'minggu') {
    // Laporan Mingguan (dalam bulan)
    $range_minggu = getRangeMingguDalamBulan($minggu, $bulan, $tahun);
    $bulan_list = getBulanList();
    $bulan_nama = $bulan_list[$bulan] ?? 'Bulan';
    
    $judul_laporan = "Laporan Mingguan";
    $periode_label = "$bulan_nama $tahun - " . $range_minggu['label'];
    
    $sql = "SELECT ps.*, 
                   s.nama_lengkap, s.kelas as tingkat_siswa,
                   u.full_name as nama_guru,
                   ps.kategori as kategori_nilai,
                   pd.mata_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            WHERE DATE(ps.tanggal_penilaian) BETWEEN ? AND ?";
    
    $params = [$range_minggu['awal'], $range_minggu['akhir']];
    $param_types = "ss";
    
} elseif ($periode_type == 'bulan') {
    // Laporan Bulanan
    $bulan_list = getBulanList();
    $bulan_nama = $bulan_list[$bulan] ?? 'Bulan';
    $judul_laporan = "Laporan Bulanan";
    $periode_label = "Bulan $bulan_nama $tahun";
    
    $sql = "SELECT ps.*, 
                   s.nama_lengkap, s.kelas as tingkat_siswa,
                   u.full_name as nama_guru,
                   ps.kategori as kategori_nilai,
                   pd.mata_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            WHERE MONTH(ps.tanggal_penilaian) = ? AND YEAR(ps.tanggal_penilaian) = ?";
    
    $params = [$bulan, $tahun];
    $param_types = "ss";
    
} elseif ($periode_type == 'tahun') {
    // Laporan Tahunan
    $judul_laporan = "Laporan Tahunan";
    $periode_label = "Tahun $tahun";
    
    $sql = "SELECT ps.*, 
                   s.nama_lengkap, s.kelas as tingkat_siswa,
                   u.full_name as nama_guru,
                   ps.kategori as kategori_nilai,
                   pd.mata_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            WHERE YEAR(ps.tanggal_penilaian) = ?";
    
    $params = [$tahun];
    $param_types = "s";
    
} else {
    // Semua data
    $judul_laporan = "Laporan Keseluruhan";
    $periode_label = "Semua Periode";
    
    $sql = "SELECT ps.*, 
                   s.nama_lengkap, s.kelas as tingkat_siswa,
                   u.full_name as nama_guru,
                   ps.kategori as kategori_nilai,
                   pd.mata_pelajaran
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            WHERE 1=1";
    
    $params = [];
    $param_types = "";
}

// Tambah filter kelas jika dipilih
if (!empty($kelas_filter)) {
    $sql .= " AND s.kelas = ?";
    $params[] = $kelas_filter;
    $param_types .= "s";
}

// Tambah filter guru jika dipilih
if (!empty($guru_id)) {
    $sql .= " AND ps.guru_id = ?";
    $params[] = $guru_id;
    $param_types .= "i";
}

$sql .= " ORDER BY ps.tanggal_penilaian DESC";

// Eksekusi query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Gunakan kategori dari database jika ada, jika tidak hitung dari persentase
    $row['kategori_display'] = $row['kategori_nilai'] ?? getKategoriByPersentase($row['persentase']);
    $laporan_data[] = $row;
}

$stmt->close();

// Hitung statistik sederhana
$total_data = count($laporan_data);
$total_nilai = 0;
$kategori_counts = ['Sangat Baik' => 0, 'Baik' => 0, 'Cukup' => 0, 'Kurang' => 0];

foreach ($laporan_data as $data) {
    $total_nilai += $data['total_score'];
    $kategori = $data['kategori_display'];
    if (isset($kategori_counts[$kategori])) {
        $kategori_counts[$kategori]++;
    } else {
        // Jika kategori tidak sesuai dengan yang diharapkan, hitung dari persentase
        if ($data['persentase'] >= 80) $kategori_counts['Sangat Baik']++;
        elseif ($data['persentase'] >= 60) $kategori_counts['Baik']++;
        elseif ($data['persentase'] >= 40) $kategori_counts['Cukup']++;
        else $kategori_counts['Kurang']++;
    }
}

$rata_nilai = $total_data > 0 ? round($total_nilai / $total_data, 1) : 0;

// Daftar untuk filter
$bulan_list_all = getBulanList();
$minggu_list_all = getMingguList();
$tahun_list = [];
$current_year = date('Y');
for ($i = $current_year - 2; $i <= $current_year + 1; $i++) {
    $tahun_list[] = $i;
}

// =============== EXPORT LAPORAN ===============
if (isset($_GET['export'])) {
    $export_format = $_GET['export'];
    
    if ($export_format == 'pdf') {
        // Simpan data ke session untuk print
        $_SESSION['print_data'] = [
            'data' => $laporan_data,
            'judul' => $judul_laporan,
            'periode' => $periode_label,
            'total' => $total_data,
            'rata' => $rata_nilai,
            'kategori' => $kategori_counts
        ];
        header('Location: print_laporan.php');
        exit();
    } elseif ($export_format == 'excel') {
        exportToExcel($laporan_data, $judul_laporan, $periode_label);
    }
}

// Fungsi Export ke Excel
function exportToExcel($data, $judul, $periode) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_' . date('Ymd') . '.xls"');
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "</head>";
    echo "<body>";
    echo "<table border='1'>";
    echo "<tr><th colspan='9' style='background:#4F81BD;color:white;'>$judul - $periode</th></tr>";
    echo "<tr style='background:#D9E1F2;'>
            <th>No</th>
            <th>Tanggal</th>
            <th>Nama Siswa</th>
            <th>Kelas</th>
            <th>Mata Pelajaran</th>
            <th>Guru</th>
            <th>Total Nilai</th>
            <th>Persentase</th>
            <th>Kategori</th>
          </tr>";
    
    $no = 1;
    foreach ($data as $row) {
        echo "<tr>";
        echo "<td>$no</td>";
        echo "<td>" . date('d/m/Y', strtotime($row['tanggal_penilaian'])) . "</td>";
        echo "<td>" . htmlspecialchars($row['nama_lengkap']) . "</td>";
        echo "<td>" . htmlspecialchars($row['tingkat_siswa']) . "</td>";
        echo "<td>" . htmlspecialchars($row['mata_pelajaran'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($row['nama_guru']) . "</td>";
        echo "<td>" . $row['total_score'] . "/50</td>";
        echo "<td>" . $row['persentase'] . "%</td>";
        echo "<td>" . $row['kategori_display'] . "</td>";
        echo "</tr>";
        $no++;
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Modal Styles */
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
            max-width: 900px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes modalFadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
        }
        .modal-header.blue {
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
            line-height: 1;
            transition: color 0.2s;
        }
        .close:hover {
            color: #f0f0f0;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        
        /* Badge Styling */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
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

        /* ===== STYLE UNTUK MENU DINAMIS ===== */
        /* Dropdown styles untuk menu dinamis */
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
            transform: rotate(180deg);
        }
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }
        
        /* Sidebar menu hover */
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Logout button khusus */
        .logout-btn {
            margin-top: 2rem !important;
            color: #fca5a5 !important;
        }
        
        .logout-btn:hover {
            background-color: rgba(254, 226, 226, 0.9) !important;
            color: #b91c1c !important;
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
        
        /* Overlay for mobile menu */
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
        
        /* Responsive untuk sidebar */
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
            
            .modal-body {
                padding: 16px;
                max-height: 80vh;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            /* Table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-responsive table {
                min-width: 640px;
            }
        }

        /* ===== STYLE UNTUK DATA TABEL ===== */
        /* Table styles */
        .data-table {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .data-table th {
            background-color: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background-color: #f9fafb;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                font-size: 12px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                border: 1px solid #000;
                padding: 4px;
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

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'admin'); ?>
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
                    <button id="menuClose" class="text-white mr-3">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                    <h1 class="text-xl font-bold">Bimbel Esc</h1>
                </div>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-shield text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
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
    <div class="md:ml-64">
        <!-- Mobile header spacer -->
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-file-alt mr-2"></i> Laporan Penilaian
                    </h1>
                    <p class="text-gray-600">Generate laporan penilaian berdasarkan periode. Total: <?php echo number_format($total_data); ?> data</p>
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
            <!-- Notifications -->
            <?php if ($success_message): ?>
                <div class="mb-4 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-4 bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                <div class="px-4 py-5 sm:p-6">
                    <form method="GET" action="laporan.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <!-- Jenis Periode -->
                            <div>
                                <label for="periode_type" class="block text-sm font-medium text-gray-700">Jenis Periode</label>
                                <select id="periode_type" name="periode_type" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="hari" <?php echo $periode_type == 'hari' ? 'selected' : ''; ?>>Harian</option>
                                    <option value="minggu" <?php echo $periode_type == 'minggu' ? 'selected' : ''; ?>>Mingguan</option>
                                    <option value="bulan" <?php echo $periode_type == 'bulan' ? 'selected' : ''; ?>>Bulanan</option>
                                    <option value="tahun" <?php echo $periode_type == 'tahun' ? 'selected' : ''; ?>>Tahunan</option>
                                    <option value="all" <?php echo $periode_type == 'all' ? 'selected' : ''; ?>>Semua Data</option>
                                </select>
                            </div>
                            
                            <!-- Field Harian -->
                            <div id="hariField" class="<?php echo $periode_type == 'hari' ? '' : 'hidden'; ?>">
                                <label for="tanggal" class="block text-sm font-medium text-gray-700">Tanggal</label>
                                <input type="date" id="tanggal" name="tanggal" 
                                       class="mt-1 focus:ring-blue-500 focus:border-blue-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       value="<?php echo $tanggal; ?>">
                            </div>
                            
                            <!-- Field Mingguan -->
                            <div id="mingguField" class="<?php echo $periode_type == 'minggu' ? '' : 'hidden'; ?>">
                                <label for="minggu" class="block text-sm font-medium text-gray-700">Minggu ke-</label>
                                <select id="minggu" name="minggu" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <?php foreach ($minggu_list_all as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $minggu == $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Field Bulanan -->
                            <div id="bulanField" class="<?php echo in_array($periode_type, ['minggu', 'bulan']) ? '' : 'hidden'; ?>">
                                <label for="bulan" class="block text-sm font-medium text-gray-700">Bulan</label>
                                <select id="bulan" name="bulan" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <?php foreach ($bulan_list_all as $key => $nama): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $bulan == $key ? 'selected' : ''; ?>>
                                        <?php echo $nama; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Field Tahun -->
                            <div id="tahunField" class="<?php echo in_array($periode_type, ['minggu', 'bulan', 'tahun']) ? '' : 'hidden'; ?>">
                                <label for="tahun" class="block text-sm font-medium text-gray-700">Tahun</label>
                                <select id="tahun" name="tahun" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <?php foreach ($tahun_list as $tahun_opt): ?>
                                    <option value="<?php echo $tahun_opt; ?>" <?php echo $tahun == $tahun_opt ? 'selected' : ''; ?>>
                                        <?php echo $tahun_opt; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filter Kelas -->
                            <div>
                                <label for="kelas_filter" class="block text-sm font-medium text-gray-700">Filter Kelas</label>
                                <select id="kelas_filter" name="kelas_filter" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Kelas</option>
                                    <?php foreach ($kelas_options as $kelas): ?>
                                    <option value="<?php echo htmlspecialchars($kelas); ?>" <?php echo $kelas_filter == $kelas ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($kelas); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Filter Guru -->
                            <div>
                                <label for="guru_id" class="block text-sm font-medium text-gray-700">Filter Guru</label>
                                <select id="guru_id" name="guru_id" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Guru</option>
                                    <?php foreach ($guru_options as $guru): ?>
                                    <option value="<?php echo $guru['id']; ?>" <?php echo $guru_id == $guru['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-between">
                            <button type="submit" 
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-search mr-2"></i> Tampilkan Laporan
                            </button>
                            
                            <?php if (!empty($_GET)): ?>
                                <a href="laporan.php" 
                                   class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-times mr-2"></i> Reset Filter
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-database text-blue-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Data</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo number_format($total_data); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-green-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Rata-rata Nilai</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $rata_nilai; ?>/50</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-trophy text-yellow-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Sangat Baik</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $kategori_counts['Sangat Baik']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-pie text-purple-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Kategori</p>
                            <p class="text-lg font-semibold text-gray-900">
                                <?php
                                $kategori_terbanyak = 'Tidak ada';
                                $max_count = 0;
                                foreach ($kategori_counts as $kat => $count) {
                                    if ($count > $max_count) {
                                        $max_count = $count;
                                        $kategori_terbanyak = $kat;
                                    }
                                }
                                echo $kategori_terbanyak;
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Options -->
            <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">
                                <?php echo $judul_laporan; ?> - <?php echo $periode_label; ?>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Periode: <?php echo $periode_label; ?> | 
                                Total Data: <?php echo $total_data; ?>
                            </p>
                        </div>
                        <div class="mt-3 sm:mt-0 flex space-x-2">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" 
                               class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                <i class="fas fa-file-excel mr-2"></i> Export Excel
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" 
                               class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700">
                                <i class="fas fa-file-pdf mr-2"></i> Export PDF
                            </a>
                            <button onclick="window.print()" 
                                    class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-print mr-2"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <?php if ($total_data > 0): ?>
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tanggal
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Siswa
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Guru
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kelas & Mapel
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Nilai
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kategori
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Indikator
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($laporan_data as $index => $data): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('d/m/Y', strtotime($data['tanggal_penilaian'])); ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8">
                                                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-user-graduate text-blue-600 text-sm"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-2">
                                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                        <?php echo htmlspecialchars($data['nama_lengkap']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500 truncate max-w-[120px]">
                                                        Kelas: <?php echo htmlspecialchars($data['tingkat_siswa']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 truncate max-w-[120px]">
                                                <?php echo htmlspecialchars($data['nama_guru']); ?>
                                            </div>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($data['mata_pelajaran'] ?? '-'); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($data['tingkat_siswa']); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo $data['total_score']; ?>/50
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $data['persentase']; ?>%
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php
                                            $kategori_class = '';
                                            if ($data['persentase'] >= 80)
                                                $kategori_class = 'sangat-baik';
                                            elseif ($data['persentase'] >= 60)
                                                $kategori_class = 'baik';
                                            elseif ($data['persentase'] >= 40)
                                                $kategori_class = 'cukup';
                                            else
                                                $kategori_class = 'kurang';
                                            ?>
                                            <span class="badge <?php echo $kategori_class; ?>">
                                                <?php echo $data['kategori_display']; ?>
                                            </span>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex flex-col space-y-1">
                                                <span class="text-xs">KB: <?php echo $data['willingness_learn'] ?? '0'; ?>/10</span>
                                                <span class="text-xs">PM: <?php echo $data['problem_solving'] ?? '0'; ?>/10</span>
                                                <span class="text-xs">BK: <?php echo $data['critical_thinking'] ?? '0'; ?>/10</span>
                                                <span class="text-xs">KT: <?php echo $data['concentration'] ?? '0'; ?>/10</span>
                                                <span class="text-xs">KM: <?php echo $data['independence'] ?? '0'; ?>/10</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Footer Summary -->
                    <div class="bg-gray-50 px-4 py-4 border-t border-gray-200">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                            <div class="text-sm text-gray-700 mb-2 md:mb-0">
                                <strong>Ringkasan Laporan:</strong> 
                                Total <?php echo $total_data; ?> data penilaian | 
                                Rata-rata: <?php echo $rata_nilai; ?>/50
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <span class="badge sangat-baik">Sangat Baik (<?php echo $kategori_counts['Sangat Baik']; ?>)</span>
                                <span class="badge baik">Baik (<?php echo $kategori_counts['Baik']; ?>)</span>
                                <span class="badge cukup">Cukup (<?php echo $kategori_counts['Cukup']; ?>)</span>
                                <span class="badge kurang">Kurang (<?php echo $kategori_counts['Kurang']; ?>)</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-file-alt text-gray-300 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            Tidak ada data laporan
                        </h3>
                        <p class="text-gray-500 mb-4">
                            <?php if (!empty($_GET)): ?>
                                Coba ubah filter periode atau parameter pencarian.
                            <?php else: ?>
                                Belum ada data penilaian untuk ditampilkan.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Laporan Penilaian</p>
                    <p class="mt-1 text-xs text-gray-400">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Periode: <?php echo $periode_label; ?>
                    </p>
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

        // Toggle field berdasarkan jenis periode
        const periodeTypeSelect = document.getElementById('periode_type');
        if (periodeTypeSelect) {
            periodeTypeSelect.addEventListener('change', function() {
                const periodeType = this.value;
                
                // Sembunyikan semua field
                document.getElementById('hariField')?.classList.add('hidden');
                document.getElementById('mingguField')?.classList.add('hidden');
                document.getElementById('bulanField')?.classList.add('hidden');
                document.getElementById('tahunField')?.classList.add('hidden');
                
                // Tampilkan field yang sesuai
                if (periodeType === 'hari') {
                    document.getElementById('hariField')?.classList.remove('hidden');
                } else if (periodeType === 'minggu') {
                    document.getElementById('mingguField')?.classList.remove('hidden');
                    document.getElementById('bulanField')?.classList.remove('hidden');
                    document.getElementById('tahunField')?.classList.remove('hidden');
                } else if (periodeType === 'bulan') {
                    document.getElementById('bulanField')?.classList.remove('hidden');
                    document.getElementById('tahunField')?.classList.remove('hidden');
                } else if (periodeType === 'tahun') {
                    document.getElementById('tahunField')?.classList.remove('hidden');
                }
            });
        }

        // Print functionality
        function printLaporan() {
            window.print();
        }

        // Copy summary to clipboard
        function copySummary() {
            const summary = `Laporan Penilaian Bimbel Esc\n` +
                          `Periode: ${document.querySelector('h3.text-lg')?.textContent || ''}\n` +
                          `Total Data: <?php echo $total_data; ?>\n` +
                          `Rata-rata Nilai: <?php echo $rata_nilai; ?>/50\n` +
                          `Kategori: Sangat Baik (<?php echo $kategori_counts['Sangat Baik']; ?>), ` +
                          `Baik (<?php echo $kategori_counts['Baik']; ?>), ` +
                          `Cukup (<?php echo $kategori_counts['Cukup']; ?>), ` +
                          `Kurang (<?php echo $kategori_counts['Kurang']; ?>)`;
            
            navigator.clipboard.writeText(summary)
                .then(() => {
                    alert('Ringkasan laporan berhasil disalin ke clipboard!');
                })
                .catch(err => {
                    console.error('Gagal menyalin: ', err);
                });
        }

        // Auto-close modals on ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    modals.forEach(modal => {
                        modal.style.display = 'none';
                    });
                }
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto focus on first input if filter is active
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('periode_type') || urlParams.has('bulan') || 
                urlParams.has('tahun') || urlParams.has('tanggal') || 
                urlParams.has('minggu') || urlParams.has('kelas_filter') || 
                urlParams.has('guru_id')) {
                
                const firstFilter = document.querySelector('select[name="periode_type"]');
                if (firstFilter) {
                    firstFilter.focus();
                }
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>