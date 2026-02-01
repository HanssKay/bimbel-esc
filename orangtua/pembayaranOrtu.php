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

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Orang Tua';
$currentPage = basename($_SERVER['PHP_SELF']);

// Inisialisasi variabel
$success_msg = '';
$error_msg = '';

// AMBIL DATA ORANGTUA LENGKAP
$orangtua_data = [];
$email = '';
$nama_ortu = '';
$no_hp = '';
$ortu_db_id = 0;

try {
    $sql_ortu = "SELECT o.*, u.email 
                 FROM orangtua o 
                 JOIN users u ON o.user_id = u.id 
                 WHERE o.user_id = ?";
    $stmt_ortu = $conn->prepare($sql_ortu);
    $stmt_ortu->bind_param("i", $user_id);
    $stmt_ortu->execute();
    $ortu_result = $stmt_ortu->get_result();

    if ($ortu_result->num_rows > 0) {
        $orangtua_data = $ortu_result->fetch_assoc();
        $ortu_db_id = $orangtua_data['id'];
        $nama_ortu = $orangtua_data['nama_ortu'] ?? $full_name;
        $email = $orangtua_data['email'] ?? '';
        $no_hp = $orangtua_data['no_hp'] ?? '';
    } else {
        $error_msg = "Data orang tua tidak ditemukan!";
    }
    $stmt_ortu->close();
} catch (Exception $e) {
    error_log("Error fetching orangtua data: " . $e->getMessage());
    $error_msg = "Terjadi kesalahan saat mengambil data orang tua.";
}

if ($ortu_db_id == 0 && empty($error_msg)) {
    $error_msg = "Data orang tua tidak ditemukan!";
}

// AMBIL DATA ANAK-ANAK (SISWA) DARI ORANGTUA INI
$siswa_list = [];
$total_anak = 0;

if ($ortu_db_id > 0) {
    try {
        // Query untuk mendapatkan siswa yang terkait dengan orangtua ini
        // melalui tabel siswa_orangtua
        $sql_siswa = "SELECT DISTINCT s.* 
                      FROM siswa s
                      INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                      WHERE so.orangtua_id = ? 
                      AND s.status = 'aktif'
                      ORDER BY s.nama_lengkap ASC";

        $stmt_siswa = $conn->prepare($sql_siswa);
        $stmt_siswa->bind_param("i", $ortu_db_id);
        $stmt_siswa->execute();
        $siswa_result = $stmt_siswa->get_result();

        while ($row = $siswa_result->fetch_assoc()) {
            // Ambil pendaftaran aktif untuk setiap siswa
            $sql_pendaftaran = "SELECT id, jenis_kelas, tingkat, tahun_ajaran 
                               FROM pendaftaran_siswa 
                               WHERE siswa_id = ? 
                               AND status = 'aktif'
                               ORDER BY tanggal_mulai DESC
                               LIMIT 1";

            $stmt_pend = $conn->prepare($sql_pendaftaran);
            $stmt_pend->bind_param("i", $row['id']);
            $stmt_pend->execute();
            $pend_result = $stmt_pend->get_result();

            if ($pend_result->num_rows > 0) {
                $pend_data = $pend_result->fetch_assoc();
                $row['pendaftaran_id'] = $pend_data['id'];
                $row['jenis_kelas'] = $pend_data['jenis_kelas'];
                $row['tingkat'] = $pend_data['tingkat'];
                $row['tahun_ajaran'] = $pend_data['tahun_ajaran'];
            } else {
                $row['pendaftaran_id'] = null;
                $row['jenis_kelas'] = '-';
                $row['tingkat'] = '-';
                $row['tahun_ajaran'] = '-';
            }
            $stmt_pend->close();

            $siswa_list[] = $row;
        }
        $stmt_siswa->close();

        $total_anak = count($siswa_list);

    } catch (Exception $e) {
        error_log("Error fetching siswa data: " . $e->getMessage());
        $error_msg = "Terjadi kesalahan saat mengambil data siswa.";
    }
}

// Get bulan dan tahun saat ini untuk filter default
$bulan_sekarang = date('Y-m');
$bulan_filter = $_GET['bulan'] ?? $bulan_sekarang;
$siswa_filter = $_GET['siswa'] ?? '';

// Query untuk data pembayaran
$pembayaran_list = [];
$total_data = 0;
$total_tagihan = 0;
$total_dibayar = 0;
$total_tunggakan = 0;
$belum_bayar_count = 0;
$lunas_count = 0;
$dibebaskan_count = 0;

if ($ortu_db_id > 0 && !empty($siswa_list)) {
    $where_conditions = [];
    $params = [];
    $types = "";

    // Buat array pendaftaran_id dari semua siswa
    $pendaftaran_ids = [];
    foreach ($siswa_list as $siswa) {
        if (!empty($siswa['pendaftaran_id'])) {
            $pendaftaran_ids[] = $siswa['pendaftaran_id'];
        }
    }

    // Filter berdasarkan siswa tertentu
    if (!empty($siswa_filter)) {
        // Cari pendaftaran_id untuk siswa yang dipilih
        $selected_pendaftaran_id = null;
        foreach ($siswa_list as $siswa) {
            if ($siswa['id'] == $siswa_filter && !empty($siswa['pendaftaran_id'])) {
                $selected_pendaftaran_id = $siswa['pendaftaran_id'];
                break;
            }
        }

        if ($selected_pendaftaran_id) {
            $where_conditions[] = "p.pendaftaran_id = ?";
            $params[] = $selected_pendaftaran_id;
            $types = "i";
        } else {
            // Jika siswa tidak punya pendaftaran aktif, tidak ada data pembayaran
            $where_conditions[] = "1 = 0"; // Selalu false
        }
    } else {
        // Semua siswa
        if (!empty($pendaftaran_ids)) {
            $placeholders = str_repeat('?,', count($pendaftaran_ids) - 1) . '?';
            $where_conditions[] = "p.pendaftaran_id IN ($placeholders)";
            $params = array_merge($params, $pendaftaran_ids);
            $types = str_repeat('i', count($pendaftaran_ids));
        } else {
            // Tidak ada pendaftaran aktif, tidak ada data pembayaran
            $where_conditions[] = "1 = 0"; // Selalu false
        }
    }

    // Filter berdasarkan bulan
    if (!empty($bulan_filter)) {
        $where_conditions[] = "p.bulan_tagihan = ?";
        $params[] = $bulan_filter;
        $types .= "s";
    }

    // Build query
    $sql_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Hitung total data
    try {
        $sql_count = "SELECT COUNT(*) as total FROM pembayaran p $sql_where";
        $count_stmt = $conn->prepare($sql_count);
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $total_data = $count_row['total'] ?? 0;
        $count_stmt->close();
    } catch (Exception $e) {
        error_log("Error counting pembayaran: " . $e->getMessage());
    }

    // Query utama untuk data pembayaran
    try {
        $sql = "SELECT 
                    p.*,
                    s.nama_lengkap as nama_siswa,
                    s.kelas,
                    ps.jenis_kelas,
                    ps.tingkat,
                    ps.tahun_ajaran,
                    s.id as siswa_id
                FROM pembayaran p
                INNER JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id
                INNER JOIN siswa s ON ps.siswa_id = s.id
                $sql_where
                ORDER BY p.bulan_tagihan DESC, s.nama_lengkap ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $pembayaran_list[] = $row;

            // Hitung statistik
            $total_tagihan += floatval($row['nominal_tagihan']);
            $total_dibayar += floatval($row['nominal_dibayar']);

            if ($row['status'] == 'lunas') {
                $lunas_count++;
            } elseif ($row['status'] == 'belum_bayar') {
                $belum_bayar_count++;
                $total_tunggakan += floatval($row['nominal_tagihan']) - floatval($row['nominal_dibayar']);
            } elseif ($row['status'] == 'dibebaskan') {
                $dibebaskan_count++;
            }
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching pembayaran: " . $e->getMessage());
        $error_msg = "Terjadi kesalahan saat mengambil data pembayaran.";
    }
}

// Generate pilihan bulan (12 bulan terakhir)
$bulan_options = [];
for ($i = 0; $i < 12; $i++) {
    $date = date('Y-m', strtotime("-$i months"));
    $bulan_options[] = [
        'value' => $date,
        'label' => date('F Y', strtotime($date))
    ];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran - Bimbel Esc</title>
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

        /* Custom Styles for Payments Page */
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-purple {
            background-color: #e9d5ff;
            color: #7c3aed;
        }

        .table-row:hover {
            background-color: #f9fafb;
        }

        .progress-bar {
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            background-color: #e5e7eb;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-fill.success {
            background-color: #10b981;
        }

        .progress-fill.warning {
            background-color: #f59e0b;
        }

        .progress-fill.danger {
            background-color: #ef4444;
        }

        .progress-fill.info {
            background-color: #8b5cf6;
        }

        .amount-card {
            border-left: 4px solid;
        }

        .amount-card.total {
            border-left-color: #3b82f6;
        }

        .amount-card.paid {
            border-left-color: #10b981;
        }

        .amount-card.due {
            border-left-color: #ef4444;
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
        }

        /* Status dot */
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-dot.success {
            background-color: #10b981;
        }

        .status-dot.danger {
            background-color: #ef4444;
        }

        .status-dot.info {
            background-color: #8b5cf6;
        }

        .status-dot.warning {
            background-color: #f59e0b;
        }

        /* Modal styles - PERBAIKAN */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
            padding: 20px 0;
        }

        .modal-content {
            background-color: white;
            margin: 20px auto;
            padding: 0;
            border-radius: 12px;
            width: 95%;
            max-width: 700px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: modalFadeIn 0.3s;
        }

        @media (max-width: 640px) {
            .modal-content {
                margin: 10px auto;
                width: 98%;
                max-height: 95vh;
            }
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Modal header */
        .modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white;
            padding: 20px 24px;
            border-radius: 12px 12px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Modal body */
        .modal-body {
            padding: 24px;
            max-height: calc(90vh - 140px);
            overflow-y: auto;
        }

        @media (max-width: 640px) {
            .modal-body {
                padding: 20px 16px;
                max-height: calc(95vh - 140px);
            }
        }

        /* Modal footer */
        .modal-footer {
            background-color: #f9fafb;
            padding: 16px 24px;
            border-radius: 0 0 12px 12px;
            border-top: 1px solid #e5e7eb;
            position: sticky;
            bottom: 0;
        }

        /* Detail item styles */
        .detail-item {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f1f1f1;
            align-items: center;
        }

        @media (max-width: 640px) {
            .detail-item {
                grid-template-columns: 120px 1fr;
                gap: 8px;
                padding: 10px 0;
            }
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #6b7280;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .detail-value {
            color: #374151;
            word-break: break-word;
            font-size: 0.95rem;
        }

        .detail-value.font-bold {
            font-weight: 600;
        }

        /* Scrollbar styling */
        .modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }

        /* Progress bar in modal */
        .progress-container {
            margin-top: 4px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 4px;
        }

        /* Filter styles */
        .filter-active {
            background-color: #3b82f6 !important;
            color: white !important;
            border-color: #3b82f6 !important;
        }

        .filter-active:hover {
            background-color: #2563eb !important;
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

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <?php if (!empty($email)): ?>
                        <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3 text-sm">
                <p class="flex items-center">
                    <i class="fas fa-child mr-2"></i>
                    <span><?php echo $total_anak; ?> Anak</span>
                </p>
            </div>
        </div>

        <!-- Navigation -->
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
                    <p class="text-sm"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-blue-300">Orang Tua</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-friends"></i>
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
                        <?php if (!empty($email)): ?>
                            <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-3 text-sm">
                    <p class="flex items-center">
                        <i class="fas fa-child mr-2"></i>
                        <span><?php echo $total_anak; ?> Anak</span>
                    </p>
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
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-credit-card mr-2"></i>Informasi Pembayaran
                    </h1>
                    <p class="text-gray-600">Pantau status pembayaran anak Anda</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Messages -->
            <?php if ($success_msg): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3 text-lg"></i>
                    <div>
                        <p class="font-medium">Sukses!</p>
                        <p><?= $success_msg ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3 text-lg"></i>
                    <div>
                        <p class="font-medium">Terjadi Kesalahan!</p>
                        <p><?= $error_msg ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6 mb-8">
                <!--<div class="stat-card amount-card total bg-white rounded-xl shadow p-5">-->
                <!--    <div class="flex items-center">-->
                <!--        <div class="p-3 bg-blue-100 text-blue-600 rounded-lg mr-4">-->
                <!--            <i class="fas fa-file-invoice-dollar text-xl"></i>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <p class="text-sm text-gray-600">Total Tagihan</p>-->
                <!--            <h3 class="text-2xl font-bold text-gray-800">Rp <?= number_format($total_tagihan, 0, ',', '.') ?></h3>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--</div>-->

                <div class="stat-card amount-card paid bg-white rounded-xl shadow p-5">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 text-green-600 rounded-lg mr-4">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Total Dibayar</p>
                            <h3 class="text-2xl font-bold text-gray-800">Rp
                                <?= number_format($total_dibayar, 0, ',', '.') ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!--<div class="stat-card amount-card due bg-white rounded-xl shadow p-5">-->
                <!--    <div class="flex items-center">-->
                <!--        <div class="p-3 bg-red-100 text-red-600 rounded-lg mr-4">-->
                <!--            <i class="fas fa-exclamation-triangle text-xl"></i>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <p class="text-sm text-gray-600">Tunggakan</p>-->
                <!--            <h3 class="text-2xl font-bold text-gray-800">Rp <?= number_format($total_tunggakan, 0, ',', '.') ?></h3>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--</div>-->

                <div class="stat-card bg-white rounded-xl shadow p-5 border-l-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 text-purple-600 rounded-lg mr-4">
                            <i class="fas fa-chart-pie text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600">Status Pembayaran</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?= $lunas_count + $dibebaskan_count ?> Lunas
                            </h3>
                            <p class="text-xs text-gray-500"><?= $belum_bayar_count ?> Belum Bayar</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="bg-white rounded-xl shadow p-5 mb-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div class="mb-4 md:mb-0">
                        <h2 class="text-lg font-semibold text-gray-800 mb-2">Riwayat Pembayaran</h2>
                        <p class="text-sm text-gray-600">Total <?= number_format($total_data) ?> data ditemukan</p>
                    </div>

                    <div class="flex flex-col md:flex-row gap-3 w-full md:w-auto">
                        <!-- Filter Form -->
                        <form method="GET" class="flex flex-col md:flex-row gap-3">
                            <div class="flex gap-2">
                                <!-- Filter Bulan -->
                                <div>
                                    <select name="bulan" onchange="this.form.submit()"
                                        class="border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                        <?php foreach ($bulan_options as $bulan): ?>
                                            <option value="<?= $bulan['value'] ?>" <?= $bulan_filter == $bulan['value'] ? 'selected' : '' ?>>
                                                <?= $bulan['label'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Filter Siswa -->
                                <?php if (count($siswa_list) > 1): ?>
                                    <div>
                                        <select name="siswa" onchange="this.form.submit()"
                                            class="border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <option value="">Semua Anak</option>
                                            <?php foreach ($siswa_list as $siswa): ?>
                                                <option value="<?= $siswa['id'] ?>" <?= $siswa_filter == $siswa['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($siswa['nama_lengkap']) ?>
                                                    <?php if (!empty($siswa['jenis_kelas']) && $siswa['jenis_kelas'] != '-'): ?>
                                                        (<?= $siswa['jenis_kelas'] ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Reset Filter -->
                            <?php if (!empty($bulan_filter) && $bulan_filter != $bulan_sekarang || !empty($siswa_filter)): ?>
                                <a href="?"
                                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                                    <i class="fas fa-times mr-2"></i>Reset
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Info Anak -->
                <div class="mt-6">
                    <h3 class="font-medium text-gray-700 mb-3">Anak yang terdaftar:</h3>
                    <div class="flex flex-wrap gap-3">
                        <?php if (empty($siswa_list)): ?>
                            <div class="px-4 py-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                Belum ada data anak terdaftar
                            </div>
                        <?php else: ?>
                            <?php foreach ($siswa_list as $siswa): ?>
                                <a href="?siswa=<?= $siswa['id'] ?><?= !empty($bulan_filter) && $bulan_filter != $bulan_sekarang ? '&bulan=' . $bulan_filter : '' ?>"
                                    class="px-4 py-3 border rounded-lg flex items-center transition-all duration-200 <?= $siswa_filter == $siswa['id'] ? 'bg-blue-50 border-blue-200 text-blue-800 filter-active' : 'bg-gray-50 border-gray-200 text-gray-800 hover:bg-gray-100' ?>">
                                    <i class="fas fa-child mr-2"></i>
                                    <div>
                                        <span class="font-medium"><?= htmlspecialchars($siswa['nama_lengkap']) ?></span>
                                        <?php if (!empty($siswa['pendaftaran_id'])): ?>
                                            <span class="text-sm ml-2">
                                                (Kelas: <?= htmlspecialchars($siswa['kelas'] ?? '-') ?>,
                                                <?= $siswa['jenis_kelas'] ?> - <?= $siswa['tingkat'] ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-sm ml-2 text-red-500">
                                                <i class="fas fa-exclamation-circle mr-1"></i>Belum ada pendaftaran aktif
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
                <?php if (empty($pembayaran_list)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-file-invoice-dollar text-5xl text-gray-300 mb-4"></i>
                        <h3 class="text-xl font-medium text-gray-400 mb-2">
                            <?php if (!empty($siswa_filter)): ?>
                                Belum ada data pembayaran untuk anak ini
                            <?php else: ?>
                                Belum ada data pembayaran
                            <?php endif; ?>
                        </h3>
                        <p class="text-gray-500">
                            <?php if (!empty($siswa_filter)): ?>
                                Data pembayaran akan muncul setelah tagihan dibuat oleh admin untuk anak ini
                            <?php else: ?>
                                Data pembayaran akan muncul setelah tagihan dibuat oleh admin
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center  font-medium text-gray-500 uppercase tracking-wider">
                                        Bulan Tagihan
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center font-medium text-gray-500 uppercase tracking-wider">
                                        Anak
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center font-medium text-gray-500 uppercase tracking-wider">
                                        Detail Pembayaran
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center font-medium text-gray-500 uppercase tracking-wider">
                                        Metode Pembayaran
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs text-center font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($pembayaran_list as $pembayaran): ?>
                                    <?php
                                    $persentase = 0;
                                    if (floatval($pembayaran['nominal_tagihan']) > 0) {
                                        $persentase = (floatval($pembayaran['nominal_dibayar']) / floatval($pembayaran['nominal_tagihan'])) * 100;
                                    }

                                    $status_color = 'danger';
                                    $status_text = 'Belum Bayar';

                                    if ($pembayaran['status'] == 'lunas') {
                                        $status_color = 'success';
                                        $status_text = 'Lunas';
                                    } elseif ($pembayaran['status'] == 'dibebaskan') {
                                        $status_color = 'info';
                                        $status_text = 'Dibebaskan';
                                    }
                                    ?>
                                    <tr class="table-row hover:bg-gray-50 transition-colors duration-150">
                                        <td class="px-6 py-4">
                                            <div class="text-center">
                                                <div class="text-2xl font-bold text-gray-800">
                                                    <?= date('M', strtotime($pembayaran['bulan_tagihan'] . '-01')) ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= date('Y', strtotime($pembayaran['bulan_tagihan'] . '-01')) ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div
                                                    class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-blue-100 to-blue-50 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-child text-blue-600"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($pembayaran['nama_siswa']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= htmlspecialchars($pembayaran['kelas'] ?? '-') ?> |
                                                        <?= $pembayaran['jenis_kelas'] ?> - <?= $pembayaran['tingkat'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="px-6 py-4">
                                            <div class="space-y-2 ">
                                                <div class="grid md:grid-cols-2 text-sm">
                                                    <span class="text-gray-600">Tagihan:</span>
                                                    <span class="font-medium text-gray-900">
                                                        Rp <?= number_format($pembayaran['nominal_tagihan'], 0, ',', '.') ?>
                                                    </span>
                                                </div>

                                                <div class="grid md:grid-cols-2 text-sm">
                                                    <span class="text-gray-600">Dibayar:</span>
                                                    <span
                                                        class="font-medium <?= $pembayaran['status'] == 'lunas' ? 'text-green-600' : 'text-gray-900' ?>">
                                                        Rp <?= number_format($pembayaran['nominal_dibayar'], 0, ',', '.') ?>
                                                    </span>
                                                </div>

                                                <?php if ($pembayaran['status'] != 'lunas' && $pembayaran['status'] != 'dibebaskan'): ?>
                                                    <div class="grid md:grid-cols-2 text-sm">
                                                        <span class="text-gray-600">Kekurangan:</span>
                                                        <span class="font-medium text-red-600">
                                                            Rp
                                                            <?= number_format(floatval($pembayaran['nominal_tagihan']) - floatval($pembayaran['nominal_dibayar']), 0, ',', '.') ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($pembayaran['tanggal_bayar'])): ?>
                                                    <div class="grid md:grid-cols-2 text-sm">
                                                        <span class="text-gray-600">Tanggal Bayar:</span>
                                                        <span class="font-medium text-gray-900">
                                                            <?= date('d/m/Y', strtotime($pembayaran['tanggal_bayar'])) ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>

                                            </div>
                                        </td>

                                        <td class="px-6 py-4">
                                            <?php if (!empty($pembayaran['metode_bayar'])): ?>
                                                <div class="flex justify-center text-sm">
                                                    <span class="text-gray-600">Metode:</span>
                                                    <span class="font-medium ms-2 text-blue-600">
                                                        <?= ucfirst($pembayaran['metode_bayar']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <span class="status-dot <?= $status_color ?>"></span>
                                                <span class="text-sm font-medium text-gray-900">
                                                    <?= $status_text ?>
                                                </span>
                                            </div>

                                            <?php if ($pembayaran['status'] == 'lunas'): ?>
                                                <div class="mt-2">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        <i class="fas fa-check mr-1"></i>Lunas
                                                    </span>
                                                </div>
                                            <?php elseif ($pembayaran['status'] == 'belum_bayar'): ?>
                                                <div class="mt-2">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        <i class="fas fa-clock mr-1"></i>Menunggu Pembayaran
                                                    </span>
                                                </div>
                                            <?php elseif ($pembayaran['status'] == 'dibebaskan'): ?>
                                                <div class="mt-2">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                        <i class="fas fa-star mr-1"></i>Dibebaskan
                                                    </span>
                                                </div>
                                            <?php endif; ?>

                                            <!-- <?php if (!empty($pembayaran['keterangan'])): ?>
                                    <div class="mt-2 text-xs text-gray-500">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <?= htmlspecialchars($pembayaran['keterangan']) ?>
                                    </div>
                                    <?php endif; ?> -->
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col space-y-2">
                                                <button onclick="showDetail(<?= htmlspecialchars(json_encode($pembayaran)) ?>)"
                                                    class="px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 flex items-center justify-center transition-colors duration-150">
                                                    <i class="fas fa-eye mr-2"></i>Detail
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Summary -->
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                    <div class="flex flex-col md:flex-row justify-between items-center">
                        <div class="text-sm text-gray-600 mb-2 md:mb-0">
                            Menampilkan <?= count($pembayaran_list) ?> dari <?= $total_data ?> pembayaran
                            <?php if (!empty($siswa_filter)): ?>
                                untuk
                                <?= htmlspecialchars($siswa_list[array_search($siswa_filter, array_column($siswa_list, 'id'))]['nama_lengkap'] ?? 'anak terpilih') ?>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="flex items-center">
                                <span class="status-dot success mr-2"></span>
                                <span class="text-sm text-gray-600">Lunas</span>
                            </div>
                            <div class="flex items-center">
                                <span class="status-dot danger mr-2"></span>
                                <span class="text-sm text-gray-600">Belum Bayar</span>
                            </div>
                            <div class="flex items-center">
                                <span class="status-dot info mr-2"></span>
                                <span class="text-sm text-gray-600">Dibebaskan</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detail Modal -->
            <div id="detailModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="flex justify-between items-center">
                            <div>
                                <h3 class="text-xl font-bold">Detail Pembayaran</h3>
                                <p class="text-blue-200 text-sm mt-1">Informasi lengkap pembayaran</p>
                            </div>
                            <button onclick="closeDetail()"
                                class="text-white hover:text-blue-200 text-xl bg-transparent border-none cursor-pointer">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div class="modal-body" id="detailContent">
                        <!-- Detail akan diisi oleh JavaScript -->
                    </div>

                    <div class="modal-footer flex justify-end">
                        <button onclick="closeDetail()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                            <i class="fas fa-times mr-2"></i>Tutup
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- FOOTER -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Pembayaran</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Login sebagai: <?php echo htmlspecialchars($full_name); ?>
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
        document.getElementById('menuToggle').addEventListener('click', function () {
            document.getElementById('mobileMenu').classList.add('menu-open');
            document.getElementById('menuOverlay').classList.add('active');
        });

        document.getElementById('menuClose').addEventListener('click', function () {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            document.getElementById('menuOverlay').classList.remove('active');
        });

        document.getElementById('menuOverlay').addEventListener('click', function () {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            this.classList.remove('active');
        });

        // Detail Modal Functions
        function showDetail(paymentData) {
            let statusBadge = '';
            let statusClass = '';
            let statusColor = '';

            switch (paymentData.status) {
                case 'lunas':
                    statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800"><i class="fas fa-check mr-2"></i>Lunas</span>';
                    statusClass = 'text-green-600';
                    statusColor = 'success';
                    break;
                case 'belum_bayar':
                    statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800"><i class="fas fa-clock mr-2"></i>Belum Bayar</span>';
                    statusClass = 'text-red-600';
                    statusColor = 'danger';
                    break;
                case 'dibebaskan':
                    statusBadge = '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800"><i class="fas fa-star mr-2"></i>Dibebaskan</span>';
                    statusClass = 'text-purple-600';
                    statusColor = 'info';
                    break;
            }

            const formattedDate = (dateStr) => {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                return date.toLocaleDateString('id-ID', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            };

            const formatTime = (dateStr) => {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                return date.toLocaleTimeString('id-ID', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };

            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 0
                }).format(amount);
            };

            const bulanTagihan = new Date(paymentData.bulan_tagihan + '-01');
            const bulanFormatted = bulanTagihan.toLocaleDateString('id-ID', {
                year: 'numeric',
                month: 'long'
            });

            // Hitung persentase pembayaran
            const persentase = paymentData.nominal_tagihan > 0
                ? (paymentData.nominal_dibayar / paymentData.nominal_tagihan) * 100
                : 0;

            // Hitung kekurangan
            const kekurangan = paymentData.nominal_tagihan - paymentData.nominal_dibayar;

            let html = `
                <!-- Info Siswa -->
                <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-white text-blue-600 rounded-full flex items-center justify-center shadow-sm">
                            <i class="fas fa-child text-lg"></i>
                        </div>
                        <div class="ml-4">
                            <h4 class="font-bold text-gray-800">${paymentData.nama_siswa}</h4>
                            <div class="flex flex-wrap gap-2 mt-1">
                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">${paymentData.kelas || '-'}</span>
                                <span class="text-xs bg-indigo-100 text-indigo-800 px-2 py-1 rounded">${paymentData.jenis_kelas}</span>
                                <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded">${paymentData.tingkat}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Status dan Bulan -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Bulan Tagihan</p>
                        <p class="text-xl font-bold text-gray-800">${bulanFormatted}</p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600 mb-1">Status Pembayaran</p>
                        <div class="flex items-center">
                            <span class="status-dot ${statusColor} mr-2"></span>
                            ${statusBadge}
                        </div>
                    </div>
                </div>
                
                <!-- Detail Pembayaran -->
                <div class="mb-6">
                    <h4 class="font-bold text-gray-800 mb-4 pb-2 border-b border-gray-200">Detail Pembayaran</h4>
                    <div class="space-y-2">
                        <div class="detail-item">
                            <span class="detail-label">Nominal Tagihan</span>
                            <span class="detail-value font-bold ${statusClass}">${formatCurrency(paymentData.nominal_tagihan)}</span>
                        </div>
                        
                        <div class="detail-item">
                            <span class="detail-label">Nominal Dibayar</span>
                            <span class="detail-value ${paymentData.status === 'lunas' ? 'text-green-600 font-bold' : 'font-medium'}">${formatCurrency(paymentData.nominal_dibayar)}</span>
                        </div>
                        
                        ${paymentData.status !== 'lunas' && paymentData.status !== 'dibebaskan' ? `
                        <div class="detail-item">
                            <span class="detail-label">Kekurangan</span>
                            <span class="detail-value text-red-600 font-bold">${formatCurrency(kekurangan)}</span>
                        </div>
                        ` : ''}
                        
                        ${paymentData.metode_bayar ? `
                        <div class="detail-item">
                            <span class="detail-label">Metode Bayar</span>
                            <span class="detail-value font-medium text-blue-600">${paymentData.metode_bayar.charAt(0).toUpperCase() + paymentData.metode_bayar.slice(1)}</span>
                        </div>
                        ` : ''}
                        
                        ${paymentData.tanggal_bayar ? `
                        <div class="detail-item">
                            <span class="detail-label">Tanggal Bayar</span>
                            <span class="detail-value">${formattedDate(paymentData.tanggal_bayar)}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                <!-- Informasi Program -->
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <h4 class="font-bold text-gray-800 mb-3">Informasi Program</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-600 mb-1">Jenis Kelas</p>
                            <p class="font-medium">${paymentData.jenis_kelas}</p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-600 mb-1">Tahun Ajaran</p>
                            <p class="font-medium">${paymentData.tahun_ajaran || '-'}</p>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('detailContent').innerHTML = html;
            document.getElementById('detailModal').style.display = 'block';

            // Scroll ke atas modal
            const modalBody = document.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        }

        function closeDetail() {
            document.getElementById('detailModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const detailModal = document.getElementById('detailModal');
            if (event.target == detailModal) {
                closeDetail();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDetail();
            }
        });

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
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
        document.addEventListener('click', function (e) {
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

        // Auto-hide success/error messages after 5 seconds
        setTimeout(function () {
            const messages = document.querySelectorAll('.bg-green-50, .bg-red-50');
            messages.forEach(msg => {
                msg.style.display = 'none';
            });
        }, 5000);

        // Update server time
        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('serverTime').textContent = timeString;
        }

        // Update time every second
        setInterval(updateServerTime, 1000);
    </script>
</body>

</html>