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

// VARIABEL UNTUK NOTIFIKASI
$success_message = '';
$error_message = '';

// VARIABEL FILTER
$filter_tingkat = isset($_GET['filter_tingkat']) ? $_GET['filter_tingkat'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// VARIABEL UNTUK MODAL DETAIL/EDIT
$siswa_detail = null;
$siswa_edit = null;

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fungsi untuk cek hak akses guru ke siswa
function guruBolehAksesSiswa($conn, $guru_id, $siswa_id)
{
    try {
        $sql = "SELECT 1 FROM siswa_pelajaran sp 
                INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                WHERE sp.siswa_id = ? 
                AND sp.guru_id = ? 
                AND sp.status = 'aktif'
                AND ps.status = 'aktif'
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $siswa_id, $guru_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $has_access = $result->num_rows > 0;
        $stmt->close();

        return $has_access;
    } catch (Exception $e) {
        error_log("Error in guruBolehAksesSiswa: " . $e->getMessage());
        return false;
    }
}

// PROSES DETAIL SISWA
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['siswa_id'])) {
    $siswa_id = intval($_GET['siswa_id']);

    if (guruBolehAksesSiswa($conn, $guru_id, $siswa_id)) {
        try {
            $sql = "SELECT 
                        s.*,
                        -- Ambil semua orangtua (many-to-many)
                        GROUP_CONCAT(DISTINCT CONCAT(o.nama_ortu, ' (', o.hubungan_dengan_siswa, ')') SEPARATOR ', ') as semua_ortu,
                        GROUP_CONCAT(DISTINCT o.no_hp SEPARATOR ', ') as semua_no_hp_ortu,
                        GROUP_CONCAT(DISTINCT o.email SEPARATOR ', ') as semua_email_ortu,
                        GROUP_CONCAT(DISTINCT CONCAT(o.pekerjaan, ' di ', o.perusahaan) SEPARATOR '; ') as semua_pekerjaan,
                        GROUP_CONCAT(DISTINCT o.hubungan_dengan_siswa SEPARATOR ', ') as semua_hubungan,
                        -- Ambil orangtua pertama untuk kompatibilitas
                        (SELECT o2.nama_ortu FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as nama_ortu,
                        (SELECT o2.no_hp FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as no_hp_ortu,
                        (SELECT o2.email FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as email_ortu,
                        (SELECT o2.pekerjaan FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as pekerjaan,
                        (SELECT o2.perusahaan FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as perusahaan,
                        (SELECT o2.hubungan_dengan_siswa FROM siswa_orangtua so2 INNER JOIN orangtua o2 ON so2.orangtua_id = o2.id WHERE so2.siswa_id = s.id ORDER BY o2.id ASC LIMIT 1) as hubungan_dengan_siswa,
                        ps.tingkat,
                        ps.jenis_kelas,
                        ps.tanggal_mulai,
                        GROUP_CONCAT(DISTINCT CONCAT(sp.nama_pelajaran, ' (', ps.tingkat, ' - ', ps.jenis_kelas, ')') SEPARATOR ', ') as program_bimbel,
                        GROUP_CONCAT(DISTINCT CONCAT(jb.hari, ' ', TIME_FORMAT(jb.jam_mulai, '%H:%i'), '-', TIME_FORMAT(jb.jam_selesai, '%H:%i'), ' (', sp.nama_pelajaran, ')') ORDER BY FIELD(jb.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), jb.jam_mulai SEPARATOR ', ') as jadwal_belajar,
                        COUNT(DISTINCT sp.id) as total_program,
                        COUNT(DISTINCT jb.id) as total_jadwal
                    FROM siswa s
                    INNER JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
                    INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                    LEFT JOIN orangtua o ON so.orangtua_id = o.id
                    LEFT JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id AND jb.status = 'aktif'
                    WHERE s.id = ? 
                    AND sp.guru_id = ? 
                    AND sp.status = 'aktif'
                    AND ps.status = 'aktif'
                    GROUP BY s.id";

            // NONAKTIFKAN ONLY_FULL_GROUP_BY untuk query detail
            $conn->query("SET SESSION sql_mode = ''");

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $siswa_id, $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $siswa_detail = $row;
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching detail siswa: " . $e->getMessage());
        }
    }
}

// PROSES EDIT SISWA
if (isset($_GET['action']) && $_GET['action'] == 'edit_form' && isset($_GET['siswa_id'])) {
    $siswa_id = intval($_GET['siswa_id']);

    if (guruBolehAksesSiswa($conn, $guru_id, $siswa_id)) {
        try {
            $sql = "SELECT s.* FROM siswa s
                    INNER JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
                    INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                    WHERE s.id = ? 
                    AND sp.guru_id = ? 
                    AND sp.status = 'aktif'
                    AND ps.status = 'aktif'
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $siswa_id, $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $siswa_edit = $row;
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching edit siswa: " . $e->getMessage());
        }
    }
}

// PROSES UPDATE SISWA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_siswa'])) {
    $siswa_id = intval($_POST['siswa_id'] ?? 0);
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
    $tanggal_lahir = $_POST['tanggal_lahir'] ?? '';
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
    $alamat = trim($_POST['alamat'] ?? '');
    $sekolah_asal = trim($_POST['sekolah_asal'] ?? '');
    $kelas = $_POST['kelas'] ?? '';
    $agama = $_POST['agama'] ?? '';

    if (empty($nama_lengkap)) {
        $_SESSION['error_message'] = "❌ Nama lengkap harus diisi!";
        header('Location: dataSiswa.php?action=edit_form&siswa_id=' . $siswa_id);
        exit();
    }

    try {
        // CEK HAK AKSES GURU
        if (!guruBolehAksesSiswa($conn, $guru_id, $siswa_id)) {
            throw new Exception("Anda tidak memiliki akses untuk mengedit siswa ini!");
        }

        // UPDATE DATA SISWA
        $update_sql = "UPDATE siswa SET 
                        nama_lengkap = ?,
                        tempat_lahir = ?,
                        tanggal_lahir = ?,
                        jenis_kelamin = ?,
                        agama = ?,
                        alamat = ?,
                        sekolah_asal = ?,
                        kelas = ?,
                        updated_at = NOW()
                        WHERE id = ?";

        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param(
            "ssssssssi",
            $nama_lengkap,
            $tempat_lahir,
            $tanggal_lahir,
            $jenis_kelamin,
            $agama,
            $alamat,
            $sekolah_asal,
            $kelas,
            $siswa_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Gagal memperbarui data siswa!");
        }
        $stmt->close();

        $_SESSION['success_message'] = "✅ Data siswa berhasil diperbarui!";
        header('Location: dataSiswa.php?action=edit_form&siswa_id=' . $siswa_id . '&success=1');
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = "❌ " . $e->getMessage();
        header('Location: dataSiswa.php?action=edit_form&siswa_id=' . $siswa_id);
        exit();
    }
}

// AMBIL DATA SISWA YANG DIAJAR - FIXED FOR MANY-TO-MANY ORANGTUA
$siswa_data = [];
if ($guru_id > 0) {
    try {
        // NONAKTIFKAN ONLY_FULL_GROUP_BY sementara
        $conn->query("SET SESSION sql_mode = ''");

        $sql = "SELECT 
                    s.id,
                    s.nama_lengkap,
                    s.jenis_kelamin,
                    s.kelas,
                    s.sekolah_asal,
                    s.alamat,
                    s.tempat_lahir,
                    s.tanggal_lahir,
                    s.agama,
                    -- Ambil data orangtua dari relasi many-to-many
                    COALESCE(
                        GROUP_CONCAT(DISTINCT o.nama_ortu ORDER BY o.nama_ortu SEPARATOR ', '),
                        '-'
                    ) as nama_ortu,
                    COALESCE(
                        GROUP_CONCAT(DISTINCT o.no_hp ORDER BY o.nama_ortu SEPARATOR ', '),
                        '-'
                    ) as no_hp_ortu,
                    ps.tingkat,
                    ps.jenis_kelas,
                    ps.tanggal_mulai,
                    GROUP_CONCAT(
                        DISTINCT sp.nama_pelajaran 
                        ORDER BY sp.nama_pelajaran 
                        SEPARATOR ', '
                    ) as program_bimbel
                FROM siswa_pelajaran sp
                INNER JOIN siswa s ON sp.siswa_id = s.id
                INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                LEFT JOIN orangtua o ON so.orangtua_id = o.id
                WHERE EXISTS (
                    SELECT 1 
                    FROM siswa_pelajaran sp2 
                    WHERE sp2.siswa_id = s.id 
                    AND sp2.guru_id = ?
                    AND sp2.status = 'aktif'
                )
                AND ps.status = 'aktif'
                AND sp.status = 'aktif'
                AND s.status = 'aktif'";

        $params = [$guru_id];
        $param_types = "i";

        if (!empty($filter_tingkat)) {
            $sql .= " AND ps.tingkat = ?";
            $params[] = $filter_tingkat;
            $param_types .= "s";
        }

        if (!empty($search)) {
            $sql .= " AND (s.nama_lengkap LIKE ? OR s.sekolah_asal LIKE ? OR o.nama_ortu LIKE ?)";
            $search_param = "%" . $search . "%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $param_types .= "sss";
        }

        $sql .= " GROUP BY s.id
                  ORDER BY s.nama_lengkap";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Format untuk tampilan
            $row['program_bimbel'] = $row['program_bimbel'] . ' (' . $row['tingkat'] . ' - ' . $row['jenis_kelas'] . ')';

            // Format no HP orangtua jika ada multiple
            if ($row['no_hp_ortu'] != '-' && strpos($row['no_hp_ortu'], ',') !== false) {
                $row['no_hp_ortu'] = substr($row['no_hp_ortu'], 0, strpos($row['no_hp_ortu'], ',')) . '...';
            }

            $siswa_data[] = $row;
        }

        $stmt->close();

    } catch (Exception $e) {
        error_log("Error fetching siswa: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Siswa - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ... (CSS styles tetap sama seperti sebelumnya) ... */
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

        .modal-sm {
            max-width: 500px;
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
        }

        .modal-header.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .modal-header.yellow {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
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
        }

        .close:hover {
            opacity: 0.8;
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

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .grid-2 {
                grid-template-columns: 1fr;
            }

            .form-input {
                padding: 8px 10px;
                font-size: 0.9rem;
            }
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
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
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
                    <h1 class="text-2xl font-bold text-gray-800">Data Siswa</h1>
                    <p class="text-gray-600">Total <?php echo count($siswa_data); ?> siswa aktif</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- NOTIFICATION MESSAGES -->
            <?php if ($success_message): ?>
                <div class="mb-4">
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span><?php echo htmlspecialchars($success_message); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-4">
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span><?php echo htmlspecialchars($error_message); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter & Search -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" action="dataSiswa.php" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <input type="text" name="search" placeholder="Cari nama siswa atau sekolah asal..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div>
                        <select name="filter_tingkat"
                            class="w-full md:w-auto px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Tingkat</option>
                            <option value="TK" <?php echo ($filter_tingkat == 'TK') ? 'selected' : ''; ?>>TK</option>
                            <option value="SD" <?php echo ($filter_tingkat == 'SD') ? 'selected' : ''; ?>>SD</option>
                            <option value="SMP" <?php echo ($filter_tingkat == 'SMP') ? 'selected' : ''; ?>>SMP</option>
                            <option value="SMA" <?php echo ($filter_tingkat == 'SMA') ? 'selected' : ''; ?>>SMA</option>
                            <option value="Alumni" <?php echo ($filter_tingkat == 'Alumni') ? 'selected' : ''; ?>>Alumni
                            </option>
                            <option value="Umum" <?php echo ($filter_tingkat == 'Umum') ? 'selected' : ''; ?>>Umum
                            </option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                            class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700">
                            <i class="fas fa-search mr-2"></i> Cari
                        </button>
                        <?php if (!empty($search) || !empty($filter_tingkat)): ?>
                            <a href="dataSiswa.php"
                                class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-gray-300 text-gray-700 hover:bg-gray-400">
                                <i class="fas fa-times mr-2"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Table Siswa -->
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <?php if (count($siswa_data) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Nama Siswa</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kelas Sekolah</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kelas Bimbel</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Orang Tua</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($siswa_data as $index => $siswa): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div
                                                    class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-graduate text-blue-600"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($siswa['sekolah_asal'] ?? '-'); ?>
                                                        |
                                                        <?php echo $siswa['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo htmlspecialchars($siswa['kelas']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (!empty($siswa['program_bimbel'])): ?>
                                                <div class="text-sm text-gray-700">
                                                    <?php echo htmlspecialchars($siswa['program_bimbel']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500 mt-1">
                                                    <?php if (!empty($siswa['tingkat'])): ?>
                                                        Tingkat: <?php echo htmlspecialchars($siswa['tingkat']); ?>
                                                        <?php if (!empty($siswa['jenis_kelas'])): ?>
                                                            (<?php echo htmlspecialchars($siswa['jenis_kelas']); ?>)
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if (!empty($siswa['nama_ortu'])): ?>
                                                <div>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['nama_ortu']); ?></div>
                                                    <?php if (!empty($siswa['no_hp_ortu'])): ?>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo htmlspecialchars($siswa['no_hp_ortu']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <a href="?action=detail&siswa_id=<?php echo $siswa['id']; ?>"
                                                onclick="showDetail(<?php echo $siswa['id']; ?>); return false;"
                                                class="inline-flex items-center px-3 py-1 rounded-md text-sm bg-blue-100 text-blue-700 hover:bg-blue-200 mr-2"
                                                title="Detail">
                                                <i class="fas fa-eye mr-1"></i> Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="px-6 py-12 text-center">
                        <div class="text-gray-500">
                            <i class="fas fa-users text-4xl mb-4 text-gray-400"></i>
                            <p class="text-lg font-medium text-gray-700 mb-2">
                                <?php if (!empty($search) || !empty($filter_tingkat)): ?>
                                    Tidak ditemukan siswa dengan kriteria pencarian
                                <?php else: ?>
                                    Belum ada data siswa yang Anda ajar
                                <?php endif; ?>
                            </p>
                            <p class="text-gray-600 mb-6">
                                <?php if (!empty($search) || !empty($filter_tingkat)): ?>
                                    Coba ubah kata kunci pencarian atau hapus filter
                                <?php else: ?>
                                    Siswa akan muncul di sini setelah didaftarkan ke jadwal Anda
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h3 class="font-semibold text-blue-800 mb-2">
                    <i class="fas fa-info-circle mr-2"></i> Informasi Data Siswa
                </h3>
                <ul class="text-blue-700 space-y-1 text-sm">
                    <li>• Total siswa bimbingan Anda: <strong><?php echo count($siswa_data); ?></strong> siswa</li>
                    <li>• Klik <strong class="text-blue-600">Detail</strong> untuk melihat informasi lengkap termasuk
                        jadwal belajar</li>
                    <li>• Data diambil dari pendaftaran siswa yang aktif di jadwal Anda</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Siswa</p>
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

    <!-- MODAL DETAIL SISWA -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header blue">
                <h2 class="text-xl font-bold"><i class="fas fa-eye mr-2"></i> Detail Siswa</h2>
                <span class="close" onclick="closeModal('detailModal')">&times;</span>
            </div>
            <div class="modal-body">
                <?php if ($siswa_detail): ?>
                    <div class="space-y-6">
                        <!-- Data Pribadi -->
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-blue-800 mb-4 flex items-center">
                                <i class="fas fa-user-graduate mr-2"></i> Data Pribadi
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <span class="block font-semibold text-gray-700">Nama Lengkap</span>
                                    <span
                                        class="text-gray-600"><?= htmlspecialchars($siswa_detail['nama_lengkap']) ?></span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-700">Jenis Kelamin</span>
                                    <span class="text-gray-600">
                                        <?= $siswa_detail['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-700">Tempat, Tanggal Lahir</span>
                                    <span class="text-gray-600">
                                        <?= htmlspecialchars($siswa_detail['tempat_lahir']) ?>,
                                        <?= $siswa_detail['tanggal_lahir'] ? date('d/m/Y', strtotime($siswa_detail['tanggal_lahir'])) : '-' ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-700">Agama</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['agama']) ?></span>
                                </div>
                                <div class="md:col-span-2">
                                    <span class="block font-semibold text-gray-700">Alamat</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['alamat']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Data Pendidikan -->
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-green-800 mb-4 flex items-center">
                                <i class="fas fa-school mr-2"></i> Data Pendidikan
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <span class="block font-semibold text-gray-700">Sekolah Asal</span>
                                    <span
                                        class="text-gray-600"><?= htmlspecialchars($siswa_detail['sekolah_asal']) ?></span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-700">Kelas Sekolah</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['kelas']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Data Bimbel -->
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-purple-800 mb-4 flex items-center">
                                <i class="fas fa-chalkboard-teacher mr-2"></i> Data Bimbingan Belajar
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="md:col-span-2">
                                    <span class="block font-semibold text-gray-700">Program Bimbel</span>
                                    <div class="mt-2 p-3 bg-white rounded border">
                                        <?php if (!empty($siswa_detail['program_bimbel'])): ?>
                                            <?php
                                            $program_list = explode(', ', $siswa_detail['program_bimbel']);
                                            foreach ($program_list as $program):
                                                ?>
                                                <div class="py-1">
                                                    <i class="fas fa-book text-blue-500 mr-2"></i>
                                                    <span class="text-gray-700"><?= htmlspecialchars(trim($program)) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (!empty($siswa_detail['tanggal_mulai'])): ?>
                                                <div class="mt-2 text-sm text-gray-500">
                                                    <i class="fas fa-calendar-day mr-1"></i>
                                                    Mulai: <?= date('d/m/Y', strtotime($siswa_detail['tanggal_mulai'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-500">Belum ada program bimbel</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- JADWAL BELAJAR -->
                                <?php if (!empty($siswa_detail['jadwal_belajar'])): ?>
                                    <div class="md:col-span-2 mt-4">
                                        <h4 class="font-bold text-gray-800 mb-3 flex items-center">
                                            <i class="fas fa-calendar-alt mr-2 text-blue-600"></i> Jadwal Belajar
                                        </h4>
                                        <div class="bg-white p-3 rounded border">
                                            <?php
                                            $jadwal_list = explode(', ', $siswa_detail['jadwal_belajar']);
                                            foreach ($jadwal_list as $jadwal):
                                                ?>
                                                <div class="flex items-center py-2 border-b last:border-b-0">
                                                    <i class="fas fa-clock text-gray-400 mr-3"></i>
                                                    <span class="text-gray-700"><?= htmlspecialchars(trim($jadwal)) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if ($siswa_detail['total_jadwal'] > 0): ?>
                                                <div class="mt-3 text-sm text-gray-500">
                                                    <i class="fas fa-info-circle mr-1"></i>
                                                    Total: <?= $siswa_detail['total_jadwal'] ?> sesi per minggu
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php elseif ($siswa_detail['total_program'] > 0): ?>
                                    <div class="md:col-span-2">
                                        <div class="p-3 bg-yellow-50 border border-yellow-200 rounded">
                                            <div class="flex items-center">
                                                <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
                                                <span class="text-yellow-700">Belum ada jadwal belajar yang ditetapkan</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Data Orang Tua -->
                        <?php if (!empty($siswa_detail['semua_ortu']) || !empty($siswa_detail['nama_ortu'])): ?>
                            <div class="bg-yellow-50 p-4 rounded-lg">
                                <h3 class="font-bold text-lg text-yellow-800 mb-4 flex items-center">
                                    <i class="fas fa-user-friends mr-2"></i> Data Orang Tua/Wali
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <span class="block font-semibold text-gray-700">Nama</span>
                                        <span class="text-gray-600">
                                            <?php
                                            if (!empty($siswa_detail['semua_ortu'])) {
                                                echo htmlspecialchars($siswa_detail['semua_ortu']);
                                            } else {
                                                echo htmlspecialchars($siswa_detail['nama_ortu']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="block font-semibold text-gray-700">Hubungan</span>
                                        <span class="text-gray-600">
                                            <?php
                                            if (!empty($siswa_detail['semua_hubungan'])) {
                                                echo htmlspecialchars($siswa_detail['semua_hubungan']);
                                            } elseif (!empty($siswa_detail['hubungan_dengan_siswa'])) {
                                                echo $siswa_detail['hubungan_dengan_siswa'] == 'ayah' ? 'Ayah' :
                                                    ($siswa_detail['hubungan_dengan_siswa'] == 'ibu' ? 'Ibu' : 'Wali');
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="block font-semibold text-gray-700">No. HP</span>
                                        <span class="text-gray-600">
                                            <?php
                                            if (!empty($siswa_detail['semua_no_hp_ortu'])) {
                                                echo htmlspecialchars($siswa_detail['semua_no_hp_ortu']);
                                            } elseif (!empty($siswa_detail['no_hp_ortu'])) {
                                                echo htmlspecialchars($siswa_detail['no_hp_ortu']);
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="block font-semibold text-gray-700">Email</span>
                                        <span class="text-gray-600">
                                            <?php
                                            if (!empty($siswa_detail['semua_email_ortu'])) {
                                                echo htmlspecialchars($siswa_detail['semua_email_ortu']);
                                            } elseif (!empty($siswa_detail['email_ortu'])) {
                                                echo htmlspecialchars($siswa_detail['email_ortu']);
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($siswa_detail['semua_pekerjaan'])): ?>
                                        <div class="md:col-span-2">
                                            <span class="block font-semibold text-gray-700">Pekerjaan</span>
                                            <span
                                                class="text-gray-600"><?= htmlspecialchars($siswa_detail['semua_pekerjaan']) ?></span>
                                        </div>
                                    <?php elseif (!empty($siswa_detail['pekerjaan'])): ?>
                                        <div>
                                            <span class="block font-semibold text-gray-700">Pekerjaan</span>
                                            <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['pekerjaan']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($siswa_detail['perusahaan'])): ?>
                                        <div>
                                            <span class="block font-semibold text-gray-700">Perusahaan</span>
                                            <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['perusahaan']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-2xl"></i>
                        <p class="mt-2">Data siswa tidak ditemukan</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('detailModal')"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT SISWA -->
    <!--<div id="editModal" class="modal">-->
    <!--    <div class="modal-content modal-sm">-->
    <!--        <div class="modal-header yellow">-->
    <!--            <h2 class="text-xl font-bold"><i class="fas fa-edit mr-2"></i> Edit Data Siswa</h2>-->
    <!--            <span class="close" onclick="closeModal('editModal')">&times;</span>-->
    <!--        </div>-->
    <!--        <?php if ($siswa_edit): ?>-->
        <!--            <form method="POST" action="dataSiswa.php">-->
        <!--                <input type="hidden" name="siswa_id" value="<?= $siswa_edit['id'] ?>">-->
        <!--                <input type="hidden" name="update_siswa" value="1">-->

        <!--                <div class="modal-body">-->
        <!--                    <div class="space-y-4">-->
        <!--                        <div class="form-group">-->
        <!--                            <label class="form-label">Nama Lengkap *</label>-->
        <!--                            <input type="text" name="nama_lengkap" value="<?= htmlspecialchars($siswa_edit['nama_lengkap']) ?>" class="form-input" required>-->
        <!--                        </div>-->

        <!--                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">-->
        <!--                            <div class="form-group">-->
        <!--                                <label class="form-label">Tempat Lahir</label>-->
        <!--                                <input type="text" name="tempat_lahir" value="<?= htmlspecialchars($siswa_edit['tempat_lahir']) ?>" class="form-input">-->
        <!--                            </div>-->

        <!--                            <div class="form-group">-->
        <!--                                <label class="form-label">Tanggal Lahir</label>-->
        <!--                                <input type="date" name="tanggal_lahir" value="<?= htmlspecialchars($siswa_edit['tanggal_lahir']) ?>" class="form-input">-->
        <!--                            </div>-->

        <!--                            <div class="form-group">-->
        <!--                                <label class="form-label">Jenis Kelamin</label>-->
        <!--                                <select name="jenis_kelamin" class="form-input">-->
        <!--                                    <option value="L" <?= $siswa_edit['jenis_kelamin'] == 'L' ? 'selected' : '' ?>>Laki-laki</option>-->
        <!--                                    <option value="P" <?= $siswa_edit['jenis_kelamin'] == 'P' ? 'selected' : '' ?>>Perempuan</option>-->
        <!--                                </select>-->
        <!--                            </div>-->

        <!--                            <div class="form-group">-->
        <!--                                <label class="form-label">Agama</label>-->
        <!--                                <select name="agama" class="form-input">-->
        <!--                                    <option value="">Pilih Agama</option>-->
        <!--                                    <option value="Islam" <?= $siswa_edit['agama'] == 'Islam' ? 'selected' : '' ?>>Islam</option>-->
        <!--                                    <option value="Kristen" <?= $siswa_edit['agama'] == 'Kristen' ? 'selected' : '' ?>>Kristen</option>-->
        <!--                                    <option value="Katolik" <?= $siswa_edit['agama'] == 'Katolik' ? 'selected' : '' ?>>Katolik</option>-->
        <!--                                    <option value="Hindu" <?= $siswa_edit['agama'] == 'Hindu' ? 'selected' : '' ?>>Hindu</option>-->
        <!--                                    <option value="Buddha" <?= $siswa_edit['agama'] == 'Buddha' ? 'selected' : '' ?>>Buddha</option>-->
        <!--                                    <option value="Konghucu" <?= $siswa_edit['agama'] == 'Konghucu' ? 'selected' : '' ?>>Konghucu</option>-->
        <!--                                </select>-->
        <!--                            </div>-->

        <!--                            <div class="form-group">-->
        <!--                                <label class="form-label">Kelas Sekolah</label>-->
        <!--                                <select name="kelas" class="form-input">-->
        <!--                                    <option value="">Pilih Kelas</option>-->
        <!--                                    <option value="Paud" <?= $siswa_edit['kelas'] == 'Paud' ? 'selected' : '' ?>>Paud</option>-->
        <!--                                    <option value="TK" <?= $siswa_edit['kelas'] == 'TK' ? 'selected' : '' ?>>TK</option>-->
        <!--                                    <option value="1 SD" <?= $siswa_edit['kelas'] == '1 SD' ? 'selected' : '' ?>>1 SD</option>-->
        <!--                                    <option value="2 SD" <?= $siswa_edit['kelas'] == '2 SD' ? 'selected' : '' ?>>2 SD</option>-->
        <!--                                    <option value="3 SD" <?= $siswa_edit['kelas'] == '3 SD' ? 'selected' : '' ?>>3 SD</option>-->
        <!--                                    <option value="4 SD" <?= $siswa_edit['kelas'] == '4 SD' ? 'selected' : '' ?>>4 SD</option>-->
        <!--                                    <option value="5 SD" <?= $siswa_edit['kelas'] == '5 SD' ? 'selected' : '' ?>>5 SD</option>-->
        <!--                                    <option value="6 SD" <?= $siswa_edit['kelas'] == '6 SD' ? 'selected' : '' ?>>6 SD</option>-->
        <!--                                    <option value="7 SMP" <?= $siswa_edit['kelas'] == '7 SMP' ? 'selected' : '' ?>>7 SMP</option>-->
        <!--                                    <option value="8 SMP" <?= $siswa_edit['kelas'] == '8 SMP' ? 'selected' : '' ?>>8 SMP</option>-->
        <!--                                    <option value="9 SMP" <?= $siswa_edit['kelas'] == '9 SMP' ? 'selected' : '' ?>>9 SMP</option>-->
        <!--                                    <option value="10 SMA" <?= $siswa_edit['kelas'] == '10 SMA' ? 'selected' : '' ?>>10 SMA</option>-->
        <!--                                    <option value="11 SMA" <?= $siswa_edit['kelas'] == '11 SMA' ? 'selected' : '' ?>>11 SMA</option>-->
        <!--                                    <option value="12 SMA" <?= $siswa_edit['kelas'] == '12 SMA' ? 'selected' : '' ?>>12 SMA</option>-->
        <!--                                    <option value="Alumni" <?= $siswa_edit['kelas'] == 'Alumni' ? 'selected' : '' ?>>Alumni</option>-->
        <!--                                    <option value="Umum" <?= $siswa_edit['kelas'] == 'Umum' ? 'selected' : '' ?>>Umum</option>-->
        <!--                                </select>-->
        <!--                            </div>-->
        <!--                        </div>-->

        <!--                        <div class="form-group">-->
        <!--                            <label class="form-label">Alamat</label>-->
        <!--                            <textarea name="alamat" class="form-input" rows="3"><?= htmlspecialchars($siswa_edit['alamat']) ?></textarea>-->
        <!--                        </div>-->

        <!--                        <div class="form-group">-->
        <!--                            <label class="form-label">Sekolah Asal</label>-->
        <!--                            <input type="text" name="sekolah_asal" value="<?= htmlspecialchars($siswa_edit['sekolah_asal']) ?>" class="form-input">-->
        <!--                        </div>-->
        <!--                    </div>-->
        <!--                </div>-->
        <!--                <div class="modal-footer">-->
        <!--                    <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">-->
        <!--                        Batal-->
        <!--                    </button>-->
        <!--                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 flex items-center">-->
        <!--                        <i class="fas fa-save mr-2"></i> Simpan Perubahan-->
        <!--                    </button>-->
        <!--                </div>-->
        <!--            </form>-->
        <!--        <?php else: ?>-->
        <!--            <div class="modal-body">-->
        <!--                <div class="text-center py-8 text-red-600">-->
        <!--                    <i class="fas fa-exclamation-triangle text-2xl"></i>-->
        <!--                    <p class="mt-2">Data siswa tidak ditemukan</p>-->
        <!--                    <button onclick="closeModal('editModal')" class="mt-4 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">-->
        <!--                        Tutup-->
        <!--                    </button>-->
        <!--                </div>-->
        <!--            </div>-->
        <!--        <?php endif; ?>-->
    <!--    </div>-->
    <!--</div>-->

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

        // Fungsi Modal
        function openModal(modalId) {
            // Close mobile menu if open
            mobileMenu.classList.remove('menu-open');
            menuOverlay.classList.remove('active');

            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';

            // Hapus parameter URL saat modal ditutup
            if (history.pushState) {
                let url = new URL(window.location);
                url.searchParams.delete('action');
                url.searchParams.delete('siswa_id');
                window.history.replaceState({}, '', url);
            }
        }

        // Fungsi untuk menampilkan detail
        function showDetail(siswaId) {
            // Update URL dengan parameter
            let url = new URL(window.location);
            url.searchParams.set('action', 'detail');
            url.searchParams.set('siswa_id', siswaId);
            window.history.pushState({}, '', url);

            // Reload halaman untuk memuat data dari PHP
            window.location.href = url;
        }

        // Fungsi untuk menampilkan edit
        function showEdit(siswaId) {
            // Update URL dengan parameter
            let url = new URL(window.location);
            url.searchParams.set('action', 'edit_form');
            url.searchParams.set('siswa_id', siswaId);
            window.history.pushState({}, '', url);

            // Reload halaman untuk memuat data dari PHP
            window.location.href = url;
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modals = ['detailModal', 'editModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        }

        // Auto open modals based on URL parameters
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (isset($_GET['action']) && $_GET['action'] == 'detail'): ?>
                openModal('detailModal');
            <?php endif; ?>

            <?php if (isset($_GET['action']) && $_GET['action'] == 'edit_form'): ?>
                openModal('editModal');
            <?php endif; ?>

            // Auto-focus pada input pertama di modal edit
            const editModal = document.getElementById('editModal');
            if (editModal && editModal.style.display === 'block') {
                const firstInput = editModal.querySelector('input[name="nama_lengkap"]');
                if (firstInput) {
                    setTimeout(() => firstInput.focus(), 100);
                }
            }

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
    </script>
</body>

</html>