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
            $siswa_list[] = $row;
        }
        $stmt_siswa->close();
        $total_anak = count($siswa_list);
    } catch (Exception $e) {
        error_log("Error fetching siswa data: " . $e->getMessage());
        $error_msg = "Terjadi kesalahan saat mengambil data siswa.";
    }
}

// Get filter
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$siswa_filter = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;
$filter_tipe = isset($_GET['filter_tipe']) ? $_GET['filter_tipe'] : 'bulan';
$selected_filter = $filter_tipe == 'bulan' ? $bulan_filter : date('Y', strtotime($bulan_filter));

// Query untuk data pembayaran tambahan
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

    // Ambil semua ID siswa dari orang tua ini
    $siswa_ids = [];
    foreach ($siswa_list as $siswa) {
        $siswa_ids[] = $siswa['id'];
    }

    if (!empty($siswa_ids)) {
        $placeholders = str_repeat('?,', count($siswa_ids) - 1) . '?';
        $where_conditions[] = "pk.siswa_id IN ($placeholders)";
        $params = array_merge($params, $siswa_ids);
        $types = str_repeat('i', count($siswa_ids));
    } else {
        $where_conditions[] = "1 = 0";
    }

    // Filter berdasarkan siswa tertentu
    if ($siswa_filter > 0) {
        $where_conditions[] = "pk.siswa_id = ?";
        $params[] = $siswa_filter;
        $types .= "i";
    }

    // Filter periode (bulan/tahun dari tanggal_bayar)
    if ($filter_tipe == 'bulan') {
        $where_conditions[] = "DATE_FORMAT(pk.tanggal_bayar, '%Y-%m') = ?";
        $params[] = $selected_filter;
        $types .= "s";
    } else {
        $where_conditions[] = "YEAR(pk.tanggal_bayar) = ?";
        $params[] = $selected_filter;
        $types .= "s";
    }

    $sql_where = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Hitung total data
    try {
        $sql_count = "SELECT COUNT(*) as total FROM pembayaran_kegiatan pk $sql_where";
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

    // Query utama
    try {
        $sql = "SELECT 
                    pk.*,
                    s.nama_lengkap as nama_siswa,
                    s.kelas
                FROM pembayaran_kegiatan pk
                INNER JOIN siswa s ON pk.siswa_id = s.id
                $sql_where
                ORDER BY pk.tanggal_bayar DESC, pk.status ASC";

        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $pembayaran_list[] = $row;

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
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pembayaran Lainnya - Bimbel Esc</title>
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
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #991b1b; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-purple { background-color: #e9d5ff; color: #7c3aed; }
        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
        .status-dot.success { background-color: #10b981; }
        .status-dot.danger { background-color: #ef4444; }
        .status-dot.info { background-color: #8b5cf6; }
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto; padding: 20px 0;
        }
        .modal-content {
            background-color: white; margin: 20px auto; padding: 0; border-radius: 12px;
            width: 95%; max-width: 500px; max-height: 90vh; overflow: hidden;
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: white; padding: 20px 24px; border-radius: 12px 12px 0 0;
        }
        .modal-body { padding: 24px; max-height: calc(90vh - 140px); overflow-y: auto; }
        .modal-footer { background-color: #f9fafb; padding: 16px 24px; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb; }
        .detail-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .detail-item:last-child { border-bottom: none; }
        .detail-label { color: #64748b; font-weight: 500; font-size: 0.875rem; }
        .detail-value { color: #1e293b; font-weight: 500; text-align: right; }
        .filter-select {
            width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db;
            border-radius: 8px; font-size: 0.875rem; transition: all 0.2s;
        }
        .filter-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1); }
        .dropdown-submenu { display: none; max-height: 500px; overflow: hidden; }
        .dropdown-submenu[style*="display: block"] { display: block; }
        .dropdown-toggle.open .arrow { transform: rotate(90deg); }
        .menu-item.active { background-color: rgba(255, 255, 255, 0.1); border-left: 4px solid #60A5FA; }
        #mobileMenu {
            position: fixed; top: 0; left: 0; width: 280px; height: 100%;
            z-index: 1100; transform: translateX(-100%); transition: transform 0.3s ease-in-out;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2); background-color: #1e40af;
        }
        #mobileMenu.menu-open { transform: translateX(0); }
        .menu-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1099; }
        .menu-overlay.active { display: block; }
        @media (min-width: 768px) {
            .desktop-sidebar { display: block; }
            .mobile-header { display: none; }
            #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }
        @media (max-width: 767px) {
            .desktop-sidebar { display: none; }
            .modal-content { width: 98%; margin: 10px auto; max-height: 95vh; }
            .modal-body { padding: 20px 16px; }
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
                    <p class="text-xs text-blue-200"><?php echo $total_anak; ?> Anak</p>
                </div>
            </div>
        </div>
        <nav class="mt-4"><?php echo renderMenu($currentPage, 'orangtua'); ?></nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 w-full fixed top-0 z-30 md:hidden shadow-lg">
        <div class="flex justify-between items-center">
            <button id="menuToggle" class="text-white"><i class="fas fa-bars text-xl"></i></button>
            <h1 class="text-lg font-bold">Bimbel Esc</h1>
            <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                <i class="fas fa-user"></i>
            </div>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div id="menuOverlay" class="menu-overlay"></div>
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="p-4 bg-blue-900 flex justify-between items-center">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <button id="menuClose" class="text-white"><i class="fas fa-times text-xl"></i></button>
        </div>
        <div class="p-4 border-b border-blue-700">
            <p class="font-medium"><?php echo htmlspecialchars($nama_ortu ?: $full_name); ?></p>
            <p class="text-sm text-blue-300">Orang Tua</p>
            <p class="text-xs text-blue-200 mt-1"><?php echo $total_anak; ?> Anak</p>
        </div>
        <nav class="py-4"><?php echo renderMenu($currentPage, 'orangtua'); ?></nav>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow px-4 py-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">
                        <i class="fas fa-receipt mr-2"></i>Pembayaran Lainnya
                    </h1>
                    <p class="text-gray-600 text-sm">Registrasi, Seragam, Modul, Try Out, dan lainnya</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i><?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <?php if ($success_msg): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    <i class="fas fa-check-circle mr-2"></i> <?= $success_msg ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= $error_msg ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="stat-card bg-white rounded-xl shadow p-4 border-l-4 border-green-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 text-green-600 rounded-lg mr-3">
                            <i class="fas fa-check-circle text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Total Dibayar</p>
                            <h3 class="text-xl font-bold text-gray-800">Rp <?= number_format($total_dibayar, 0, ',', '.') ?></h3>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-xl shadow p-4 border-l-4 border-red-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-red-100 text-red-600 rounded-lg mr-3">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Belum Lunas</p>
                            <h3 class="text-xl font-bold text-gray-800"><?= $belum_bayar_count ?></h3>
                        </div>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-xl shadow p-4 border-l-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 text-purple-600 rounded-lg mr-3">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Lunas + Dibebaskan</p>
                            <h3 class="text-xl font-bold text-gray-800"><?= $lunas_count + $dibebaskan_count ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="bg-white rounded-xl shadow p-4 mb-6">
                <form method="GET" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-child mr-1 text-blue-500"></i> Pilih Anak
                        </label>
                        <select name="siswa_id" onchange="this.form.submit()" class="filter-select">
                            <option value="">Semua Anak</option>
                            <?php foreach ($siswa_list as $siswa): ?>
                                <option value="<?= $siswa['id'] ?>" <?= $siswa_filter == $siswa['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($siswa['nama_lengkap']) ?>
                                    (<?= $siswa['kelas'] ?? '-' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-calendar-alt mr-1 text-blue-500"></i> Filter Periode
                        </label>
                        <div class="flex gap-2">
                            <select name="filter_tipe" onchange="this.form.submit()" class="filter-select w-32">
                                <option value="bulan" <?= $filter_tipe == 'bulan' ? 'selected' : '' ?>>Per Bulan</option>
                                <option value="tahun" <?= $filter_tipe == 'tahun' ? 'selected' : '' ?>>Per Tahun</option>
                            </select>
                            <?php if ($filter_tipe == 'bulan'): ?>
                                <input type="month" name="bulan" value="<?= $selected_filter ?>" onchange="this.form.submit()"
                                    class="filter-select flex-1">
                            <?php else: ?>
                                <select name="bulan" onchange="this.form.submit()" class="filter-select flex-1">
                                    <?php for ($y = date('Y') - 3; $y <= date('Y') + 3; $y++): ?>
                                        <option value="<?= $y ?>" <?= $selected_filter == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($siswa_filter) || $selected_filter != date('Y-m')): ?>
                        <div class="flex items-end">
                            <a href="?" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                                <i class="fas fa-times mr-1"></i> Reset
                            </a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <?php if (empty($pembayaran_list)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-receipt text-5xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">Belum ada data pembayaran lainnya</p>
                        <p class="text-sm text-gray-400 mt-1">Registrasi, seragam, modul, try out akan muncul di sini</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jenis Pembayaran</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nominal</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($pembayaran_list as $p): ?>
                                    <?php
                                    $status_class = '';
                                    $status_text = '';
                                    if ($p['status'] == 'lunas') {
                                        $status_class = 'badge-success';
                                        $status_text = 'Lunas';
                                    } elseif ($p['status'] == 'belum_bayar') {
                                        $status_class = 'badge-danger';
                                        $status_text = 'Belum Bayar';
                                    } else {
                                        $status_class = 'badge-purple';
                                        $status_text = 'Dibebaskan';
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= $p['tanggal_bayar'] ? date('d/m/Y', strtotime($p['tanggal_bayar'])) : '-' ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-child text-blue-600 text-sm"></i>
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($p['nama_siswa']) ?></div>
                                                    <div class="text-xs text-gray-500"><?= $p['kelas'] ?? '-' ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($p['nama_kegiatan']) ?></div>
                                            <?php if (!empty($p['deskripsi'])): ?>
                                                <div class="text-xs text-gray-500 truncate max-w-[200px]"><?= htmlspecialchars(substr($p['deskripsi'], 0, 60)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium">Rp <?= number_format($p['nominal_tagihan'], 0, ',', '.') ?></div>
                                            <div class="text-xs text-gray-500">Dibayar: Rp <?= number_format($p['nominal_dibayar'], 0, ',', '.') ?></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="badge <?= $status_class ?>"><?= $status_text ?></span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <button onclick="showDetail(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                    class="text-blue-600 hover:text-blue-800">
                                                <i class="fas fa-eye"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t mt-6 py-3 px-6 text-center text-sm text-gray-500">
            © <?= date('Y') ?> Bimbel Esc - Panel Orang Tua
        </footer>
    </div>

    <!-- Modal Detail -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-bold">Detail Pembayaran</h3>
                    <button onclick="closeDetail()" class="text-white hover:text-gray-200 text-xl">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="detailContent"></div>
            <div class="modal-footer flex justify-end">
                <button onclick="closeDetail()" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        // Mobile Menu
        document.getElementById('menuToggle')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.add('menu-open');
            document.getElementById('menuOverlay').classList.add('active');
        });
        document.getElementById('menuClose')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            document.getElementById('menuOverlay').classList.remove('active');
        });
        document.getElementById('menuOverlay')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            document.getElementById('menuOverlay').classList.remove('active');
        });

        // Detail Modal
        function showDetail(data) {
            const tanggalBayar = data.tanggal_bayar ? new Date(data.tanggal_bayar).toLocaleDateString('id-ID', { 
                day: 'numeric', month: 'long', year: 'numeric' 
            }) : '-';
            
            let statusHtml = '';
            if (data.status === 'lunas') {
                statusHtml = '<span class="badge badge-success"><i class="fas fa-check mr-1"></i> Lunas</span>';
            } else if (data.status === 'belum_bayar') {
                statusHtml = '<span class="badge badge-danger"><i class="fas fa-clock mr-1"></i> Belum Bayar</span>';
            } else {
                statusHtml = '<span class="badge badge-purple"><i class="fas fa-star mr-1"></i> Dibebaskan</span>';
            }

            let metodeText = '-';
            if (data.metode_bayar) {
                const labels = { cash: 'Cash', transfer: 'Transfer', qris: 'QRIS', debit: 'Debit', credit: 'Kredit', ewallet: 'E-Wallet' };
                metodeText = labels[data.metode_bayar] || data.metode_bayar;
            }

            const html = `
                <div class="space-y-3">
                    <div class="detail-item">
                        <span class="detail-label">Siswa</span>
                        <span class="detail-value">${data.nama_siswa}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Kelas</span>
                        <span class="detail-value">${data.kelas || '-'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Jenis Pembayaran</span>
                        <span class="detail-value font-semibold">${data.nama_kegiatan}</span>
                    </div>
                    ${data.deskripsi ? `
                    <div class="detail-item">
                        <span class="detail-label">Keterangan</span>
                        <span class="detail-value">${data.deskripsi}</span>
                    </div>
                    ` : ''}
                    <div class="detail-item">
                        <span class="detail-label">Nominal Tagihan</span>
                        <span class="detail-value font-bold">Rp ${new Intl.NumberFormat('id-ID').format(data.nominal_tagihan)}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Nominal Dibayar</span>
                        <span class="detail-value ${data.status === 'lunas' ? 'text-green-600 font-bold' : ''}">Rp ${new Intl.NumberFormat('id-ID').format(data.nominal_dibayar)}</span>
                    </div>
                    ${data.status === 'belum_bayar' ? `
                    <div class="detail-item">
                        <span class="detail-label">Kekurangan</span>
                        <span class="detail-value text-red-600 font-bold">Rp ${new Intl.NumberFormat('id-ID').format(data.nominal_tagihan - data.nominal_dibayar)}</span>
                    </div>
                    ` : ''}
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">${statusHtml}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Tanggal Bayar</span>
                        <span class="detail-value">${tanggalBayar}</span>
                    </div>
                    ${data.metode_bayar ? `
                    <div class="detail-item">
                        <span class="detail-label">Metode Bayar</span>
                        <span class="detail-value">${metodeText}</span>
                    </div>
                    ` : ''}
                    ${data.keterangan ? `
                    <div class="detail-item">
                        <span class="detail-label">Catatan</span>
                        <span class="detail-value">${data.keterangan}</span>
                    </div>
                    ` : ''}
                </div>
            `;
            document.getElementById('detailContent').innerHTML = html;
            document.getElementById('detailModal').style.display = 'block';
        }

        function closeDetail() {
            document.getElementById('detailModal').style.display = 'none';
        }

        window.onclick = (e) => { if (e.target === document.getElementById('detailModal')) closeDetail(); };
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDetail(); });

        // Dropdown menu
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const submenu = this.closest('.mb-1')?.querySelector('.dropdown-submenu');
                const arrow = this.querySelector('.arrow');
                if (submenu) {
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
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(sm => sm.style.display = 'none');
                document.querySelectorAll('.dropdown-toggle').forEach(t => {
                    t.classList.remove('open');
                    const a = t.querySelector('.arrow');
                    if (a) a.style.transform = 'rotate(0deg)';
                });
            }
        });
    </script>
</body>

</html>