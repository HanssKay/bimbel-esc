<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug: Tampilkan semua POST data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== POST DATA DI PEMBAYARAN ===");
    foreach ($_POST as $key => $value) {
        error_log("$key: $value");
    }
    error_log("==================");
}

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

// SETTING DEFAULT
$NOMINAL_DEFAULT = 500000;
$METODE_BAYAR = [
    'cash' => 'Cash',
    'transfer' => 'Transfer Bank',
    'qris' => 'QRIS',
    'debit' => 'Kartu Debit',
    'credit' => 'Kartu Kredit',
    'ewallet' => 'E-Wallet'
];

// STATUS PEMBAYARAN
$STATUS_PEMBAYARAN = [
    'belum_bayar' => 'Belum Bayar',
    'lunas' => 'Lunas',
    'dibebaskan' => 'Dibebaskan'
];

// Tangani actions
$current_month = date('Y-m');
$selected_month = $_GET['bulan'] ?? $current_month;
$success_msg = '';
$error_msg = '';

// ============================================
// ACTION: Input pembayaran baru
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_payment'])) {

    $siswa_id = intval($_POST['siswa_id']);
    $bulan = $_POST['bulan'];
    $nominal_tagihan = floatval(str_replace(['.', ','], ['', '.'], $_POST['nominal_tagihan'] ?? $NOMINAL_DEFAULT));
    $nominal_dibayar = floatval(str_replace(['.', ','], ['', '.'], $_POST['nominal_dibayar'] ?? 0));
    $metode_bayar = $_POST['metode_bayar'] ?? NULL;
    $keterangan = $_POST['keterangan'] ?? NULL;
    $status = $_POST['status'] ?? 'belum_bayar';

    // HANYA VALIDASI WAJIB
    $errors = [];
    if (!$siswa_id)
        $errors[] = "Harap pilih siswa!";
    if (empty($bulan))
        $errors[] = "Harap pilih bulan tagihan!";

    if (empty($errors)) {
        // Gunakan query biasa untuk menghindari sync error
        $check_pendaftaran = $conn->query("SELECT id FROM pendaftaran_siswa WHERE siswa_id = $siswa_id AND status = 'aktif' LIMIT 1");

        if ($check_pendaftaran->num_rows == 0) {
            $errors[] = "Siswa tidak memiliki pendaftaran aktif!";
        } else {
            $pendaftaran_row = $check_pendaftaran->fetch_assoc();
            $pendaftaran_id = $pendaftaran_row['id'];

            // Cek duplikat
            $check = $conn->query("SELECT p.id FROM pembayaran p 
                                 JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id 
                                 WHERE ps.siswa_id = $siswa_id AND p.bulan_tagihan = '$bulan'");

            if ($check->num_rows > 0) {
                $errors[] = "Sudah ada pembayaran untuk siswa ini di bulan tersebut";
            } else {
                // Tentukan tanggal bayar
                $tanggal_bayar = ($status == 'lunas' || ($status == 'belum_bayar' && $nominal_dibayar > 0))
                    ? date('Y-m-d')
                    : NULL;

                // Escape data untuk keamanan
                $metode_bayar_escaped = $metode_bayar ? "'" . $conn->real_escape_string($metode_bayar) . "'" : "NULL";
                $keterangan_escaped = $keterangan ? "'" . $conn->real_escape_string($keterangan) . "'" : "NULL";
                $status_escaped = $conn->real_escape_string($status);

                // INSERT langsung
                $sql = "INSERT INTO pembayaran 
                    (pendaftaran_id, bulan_tagihan, nominal_tagihan, status, 
                     nominal_dibayar, metode_bayar, tanggal_bayar, keterangan, dibuat_oleh) 
                    VALUES ($pendaftaran_id, '$bulan', $nominal_tagihan, '$status_escaped',
                            $nominal_dibayar, $metode_bayar_escaped, " .
                    ($tanggal_bayar ? "'$tanggal_bayar'" : "NULL") . ", 
                            $keterangan_escaped, {$_SESSION['user_id']})";

                if ($conn->query($sql)) {
                    $success_msg = "Pembayaran berhasil dicatat!";
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?bulan=' . $selected_month . '&success=1');
                    exit();
                } else {
                    $errors[] = "Gagal mencatat pembayaran: " . $conn->error;
                }
            }
        }
    }

    if (!empty($errors)) {
        $error_msg = implode(" ", $errors);
    }
}

// ============================================
// ACTION: Update pembayaran
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    error_log("=== UPDATE PAYMENT PROCESSING ===");

    $pembayaran_id = intval($_POST['pembayaran_id']);
    $status = $_POST['status'];
    $nominal_dibayar = $_POST['nominal_dibayar'] ?? 0;
    $metode_bayar = $_POST['metode_bayar'] ?? NULL;
    $keterangan = $_POST['keterangan'] ?? NULL;

    error_log("Update - Status: $status, ID: $pembayaran_id");

    // Konversi angka
    $nominal_dibayar = floatval(str_replace(['.', ','], ['', '.'], $nominal_dibayar));

    // Jika status dibebaskan
    if ($status == 'dibebaskan') {
        $nominal_dibayar = 0;
        $metode_bayar = NULL;
    }

    // Jika status lunas
    if ($status == 'lunas') {
        $tanggal_bayar = date('Y-m-d');
        if (empty($metode_bayar)) {
            $metode_bayar = 'cash';
        }
    } else {
        $tanggal_bayar = NULL;
    }

    // Validasi metode untuk ENUM
    if (!in_array($metode_bayar, array_keys($METODE_BAYAR)) && $metode_bayar !== NULL) {
        $metode_bayar = NULL;
    }

    // UPDATE
    $update = $conn->prepare("UPDATE pembayaran SET 
        status = ?,
        nominal_dibayar = ?,
        metode_bayar = ?,
        tanggal_bayar = ?,
        keterangan = ?,
        diperbarui_oleh = ?,
        diperbarui_pada = NOW()
        WHERE id = ?");

    if ($update) {
        $update->bind_param(
            "sdsssii",
            $status,
            $nominal_dibayar,
            $metode_bayar,
            $tanggal_bayar,
            $keterangan,
            $_SESSION['user_id'],
            $pembayaran_id
        );

        if ($update->execute()) {
            $success_msg = "Pembayaran berhasil diperbarui! Status: " . $STATUS_PEMBAYARAN[$status];
            header('Location: ' . $_SERVER['PHP_SELF'] . '?bulan=' . $selected_month . '&success=2');
            exit();
        } else {
            $error_msg = "Gagal update: " . $update->error;
            error_log("Update error: " . $update->error);
        }
    } else {
        $error_msg = "Error preparing update statement: " . $conn->error;
        error_log("Prepare error: " . $conn->error);
    }
}

// ACTION: Delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $delete = $conn->prepare("DELETE FROM pembayaran WHERE id = ?");
    $delete->bind_param("i", $delete_id);

    if ($delete->execute()) {
        $success_msg = "Pembayaran berhasil dihapus!";
        header('Location: ' . $_SERVER['PHP_SELF'] . '?bulan=' . $selected_month . '&success=3');
        exit();
    }
}

// Check success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case '1':
            $success_msg = "Pembayaran berhasil dicatat!";
            break;
        case '2':
            $success_msg = "Pembayaran berhasil diperbarui!";
            break;
        case '3':
            $success_msg = "Pembayaran berhasil dihapus!";
            break;
    }
}

// QUERY data pembayaran dengan info lengkap untuk detail
$sql = "SELECT 
    p.*,
    s.nama_lengkap,
    s.kelas,
    s.sekolah_asal,
    s.tempat_lahir,
    s.tanggal_lahir,
    s.jenis_kelamin,
    s.agama,
    s.alamat,
    s.foto_siswa,
    ps.jenis_kelas,
    ps.tingkat,
    ps.tahun_ajaran,
    ps.tanggal_mulai,
    ps.tanggal_selesai,
    o.nama_ortu,
    o.no_hp,
    o.email as email_ortu,
    o.pekerjaan,
    o.perusahaan,
    o.hubungan_dengan_siswa,
    
    -- Hitung sisa
    (p.nominal_tagihan - COALESCE(p.nominal_dibayar, 0)) as sisa_tagihan,
    
    -- Format tanggal
    DATE_FORMAT(p.tanggal_bayar, '%d/%m/%Y') as tgl_bayar_formatted,
    DATE_FORMAT(p.dibuat_pada, '%d/%m/%Y %H:%i') as dibuat_formatted,
    DATE_FORMAT(p.diperbarui_pada, '%d/%m/%Y %H:%i') as diperbarui_formatted,
    
    -- Nama admin
    (SELECT full_name FROM users WHERE id = p.dibuat_oleh) as dibuat_oleh_nama,
    (SELECT full_name FROM users WHERE id = p.diperbarui_oleh) as diperbarui_oleh_nama
    
FROM pembayaran p
JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id
JOIN siswa s ON ps.siswa_id = s.id
LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
LEFT JOIN orangtua o ON so.orangtua_id = o.id
WHERE p.bulan_tagihan = ?
ORDER BY 
    FIELD(p.status, 'belum_bayar', 'dibebaskan', 'lunas'),
    s.nama_lengkap";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $selected_month);
$stmt->execute();
$result = $stmt->get_result();
$pembayaran_list = $result->fetch_all(MYSQLI_ASSOC);

// Hitung statistik
$total_tagihan = 0;
$total_dibayar = 0;
$total_pembayaran = count($pembayaran_list);
$lunas_count = 0;
$belum_count = 0;
$bebas_count = 0;

foreach ($pembayaran_list as $p) {
    $total_tagihan += $p['nominal_tagihan'];
    $total_dibayar += $p['nominal_dibayar'];

    if ($p['status'] == 'lunas')
        $lunas_count++;
    if ($p['status'] == 'belum_bayar')
        $belum_count++;
    if ($p['status'] == 'dibebaskan')
        $bebas_count++;
}

// Ambil list siswa untuk autocomplete - QUERY YANG DIPERBAIKI
$siswa_list = [];
$siswa_result = $conn->query("SELECT 
    s.id,
    s.nama_lengkap,
    s.kelas,
    s.sekolah_asal,
    s.status as status_siswa,
    GROUP_CONCAT(DISTINCT CONCAT(o.hubungan_dengan_siswa, ': ', o.nama_ortu) SEPARATOR '; ') as orangtua_info,
    GROUP_CONCAT(DISTINCT CONCAT('Kelas: ', ps.jenis_kelas, ' (', ps.tingkat, ')') SEPARATOR ', ') as pendaftaran_aktif,
    COUNT(DISTINCT ps.id) as total_pendaftaran_aktif
FROM siswa s
LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
LEFT JOIN orangtua o ON so.orangtua_id = o.id
LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
WHERE s.status = 'aktif'
GROUP BY s.id
ORDER BY s.nama_lengkap");

if ($siswa_result) {
    $siswa_list = $siswa_result->fetch_all(MYSQLI_ASSOC);
}

// AJAX Handler untuk autocomplete
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_siswa_list') {
    header('Content-Type: application/json');
    
    $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
    $filtered_siswa = [];
    
    if (!empty($search)) {
        $sql_search = "SELECT 
            s.id,
            s.nama_lengkap,
            s.kelas,
            s.sekolah_asal,
            s.status as status_siswa,
            GROUP_CONCAT(DISTINCT CONCAT(o.hubungan_dengan_siswa, ': ', o.nama_ortu) SEPARATOR '; ') as orangtua_info,
            GROUP_CONCAT(DISTINCT CONCAT('Kelas: ', ps.jenis_kelas, ' (', ps.tingkat, ')') SEPARATOR ', ') as pendaftaran_aktif,
            COUNT(DISTINCT ps.id) as total_pendaftaran_aktif
        FROM siswa s
        LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
        LEFT JOIN orangtua o ON so.orangtua_id = o.id
        LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
        WHERE s.status = 'aktif' 
        AND (s.nama_lengkap LIKE '%$search%' 
             OR s.kelas LIKE '%$search%'
             OR s.sekolah_asal LIKE '%$search%'
             OR o.nama_ortu LIKE '%$search%')
        GROUP BY s.id
        ORDER BY s.nama_lengkap
        LIMIT 20";
        
        $result_search = $conn->query($sql_search);
        if ($result_search) {
            $filtered_siswa = $result_search->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        $filtered_siswa = $siswa_list;
    }
    
    echo json_encode($filtered_siswa);
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen Pembayaran - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        .badge-cash {
            background-color: #10B981;
            color: white;
        }

        .badge-transfer {
            background-color: #3B82F6;
            color: white;
        }

        .badge-qris {
            background-color: #8B5CF6;
            color: white;
        }

        .badge-debit {
            background-color: #F59E0B;
            color: white;
        }

        .badge-credit {
            background-color: #EC4899;
            color: white;
        }

        .badge-ewallet {
            background-color: #6366F1;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
            overflow-y: auto;
            padding: 20px 0;
        }

        .modal-content {
            background-color: white;
            margin: 30px auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
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

        /* CSS untuk autocomplete */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-input {
            width: 100%;
            padding: 0.75rem 2.5rem 0.75rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .autocomplete-clear {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            display: none;
        }

        .autocomplete-clear:hover {
            color: #6b7280;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .autocomplete-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover {
            background-color: #f9fafb;
        }

        .autocomplete-item.active {
            background-color: #eff6ff;
        }

        .autocomplete-item .siswa-nama {
            font-weight: 600;
            color: #1f2937;
        }

        .autocomplete-item .siswa-info {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .no-results {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }

        /* Info siswa yang dipilih */
        .selected-siswa-info {
            border-left: 4px solid #3B82F6;
            background-color: #EFF6FF;
            margin-top: 0.5rem;
            border-radius: 0.5rem;
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Status indicator */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-lunas {
            background-color: #10B981;
        }

        .status-belum {
            background-color: #EF4444;
        }

        .status-dibebaskan {
            background-color: #F59E0B;
        }

        /* Form hints */
        .form-hint {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        .hint-info {
            background-color: #DBEAFE;
            color: #1E40AF;
        }

        .hint-success {
            background-color: #D1FAE5;
            color: #065F46;
        }

        .hint-warning {
            background-color: #FEF3C7;
            color: #92400E;
        }

        /* Detail item */
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .detail-label {
            color: #6b7280;
            font-size: 14px;
        }

        .detail-value {
            color: #111827;
            font-weight: 500;
            text-align: right;
            max-width: 60%;
        }

        /* Action buttons */
        .action-btn {
            padding: 8px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .mobile-action-buttons {
            display: flex;
            flex-wrap: nowrap;
            gap: 4px;
        }

        /* Mobile menu */
        #mobileMenu {
            position: fixed;
            left: -100%;
            top: 0;
            width: 85%;
            height: 100%;
            z-index: 1001;
            transition: left 0.3s ease;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
        }

        #mobileMenu.menu-open {
            left: 0;
        }

        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: none;
        }

        .menu-overlay.active {
            display: block;
        }

        /* Responsive */
        @media (max-width: 767px) {
            .desktop-sidebar {
                display: none;
            }

            .modal-content {
                width: 95%;
                margin: 10px auto;
                max-height: 85vh;
            }

            .autocomplete-dropdown {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 90%;
                max-height: 80vh;
                z-index: 1002;
            }

            .mobile-action-buttons .action-btn {
                padding: 6px;
                min-width: 36px;
            }

            .detail-value {
                max-width: 50%;
            }
        }

        @media (min-width: 768px) {
            .mobile-header {
                display: none;
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40 overflow-y-auto">
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
                    <p class="font-medium"><?= htmlspecialchars($full_name) ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
                </div>
            </div>
        </div>
        <nav class="mt-4"><?= renderMenu($currentPage, 'admin') ?></nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white w-full fixed top-0 z-30 md:hidden shadow-lg">
        <div class="flex justify-between items-center p-3">
            <div class="flex items-center">
                <button id="menuToggle"
                    class="text-white touch-target w-12 h-12 flex items-center justify-center rounded-full hover:bg-blue-700 active:bg-blue-900 transition-colors">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div class="ml-2">
                    <h1 class="text-lg font-bold">Bimbel Esc</h1>
                    <p class="text-xs text-blue-300">Manajemen Pembayaran</p>
                </div>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-2 hidden sm:block">
                    <p class="text-sm font-medium"><?= htmlspecialchars($full_name) ?></p>
                    <p class="text-xs text-blue-300">Admin</p>
                </div>
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Sidebar -->
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="h-full flex flex-col">
            <!-- Mobile Menu Header -->
            <div class="p-4 bg-blue-900 flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <h1 class="text-lg font-bold">Bimbel Esc</h1>
                        <p class="text-xs text-blue-300">Admin Dashboard</p>
                    </div>
                </div>
                <button id="menuClose"
                    class="text-white touch-target w-10 h-10 flex items-center justify-center rounded-full hover:bg-blue-700 active:bg-blue-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- User Profile -->
            <div class="p-4 bg-blue-800 border-b border-blue-700">
                <div class="flex items-center">
                    <div class="flex-1">
                        <p class="font-medium text-lg"><?= htmlspecialchars($full_name) ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-blue-200">ID: <?= $admin_id ?></p>
                    </div>
                </div>
            </div>

            <!-- Menu Items -->
            <nav class="flex-1 overflow-y-auto py-4">
                <?= renderMenu($currentPage, 'admin') ?>
            </nav>
        </div>
    </div>

    <!-- Menu Overlay -->
    <div id="menuOverlay" class="menu-overlay"></div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <!-- Spacer for mobile header -->
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Page Header -->
        <div class="bg-white shadow px-4 py-3 md:p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">
                        <i class="fas fa-credit-card mr-2"></i>Informasi Pembayaran
                    </h1>
                    <p class="text-gray-600 text-sm md:text-base">Input dan kelola pembayaran siswa per bulan</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span
                        class="inline-flex items-center px-3 py-1.5 md:px-3 md:py-2 rounded-md text-xs md:text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i><?= date('d/m/Y') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-3 md:p-6">
            <!-- Messages -->
            <?php if ($success_msg): ?>
                <div
                    class="mb-4 p-3 md:p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3 text-lg"></i>
                    <div>
                        <p class="font-medium">Sukses!</p>
                        <p class="text-sm"><?= $success_msg ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="mb-4 p-3 md:p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3 text-lg"></i>
                    <div>
                        <p class="font-medium">Terjadi Kesalahan!</p>
                        <p class="text-sm"><?= $error_msg ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Month Selector -->
            <div class="bg-white rounded-xl shadow p-4 md:p-5 mb-4 md:mb-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div class="flex flex-col md:flex-row md:items-center space-y-3 md:space-y-0 mb-3 md:mb-0">
                        <div class="flex items-center space-x-2">
                            <i class="fas fa-calendar-alt text-blue-600"></i>
                            <label class="font-medium text-gray-700 text-sm md:text-base">Bulan:</label>
                        </div>
                        <div class="md:ml-4">
                            <select id="monthSelect" onchange="changeMonth(this.value)"
                                class="w-full md:w-auto border rounded-lg px-3 md:px-4 py-2.5 bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                                <?php for ($i = 0; $i < 6; $i++):
                                    $date = date('Y-m', strtotime("-$i months"));
                                    $selected = ($date == $selected_month) ? 'selected' : ''; ?>
                                    <option value="<?= $date ?>" <?= $selected ?>>
                                        <?= date('F Y', strtotime($date . '-01')) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="w-full md:w-auto">
                        <button onclick="openTambahModal()"
                            class="w-full md:w-auto px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center justify-center shadow-md text-sm md:text-base">
                            <i class="fas fa-plus mr-2"></i> Input Pembayaran Baru
                        </button>
                    </div>
                </div>

                <div class="mt-3 text-xs md:text-sm text-gray-600 bg-gray-50 px-3 md:px-4 py-2 rounded-lg">
                    <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                    Menampilkan <?= $total_pembayaran ?> pembayaran untuk <span
                        class="font-semibold"><?= date('F Y', strtotime($selected_month . '-01')) ?></span>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3 md:gap-6 mb-6 md:mb-8">
                <div class="stat-card bg-white rounded-xl shadow p-3 md:p-5 border-l-4 border-green-500">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-green-100 text-green-600 rounded-lg mr-2 md:mr-4">
                            <i class="fas fa-money-check-alt text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Terkumpul</p>
                            <h3 class="text-lg md:text-2xl font-bold text-gray-800">Rp
                                <?= number_format($total_dibayar, 0, ',', '.') ?></h3>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-xl shadow p-3 md:p-5 border-l-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-purple-100 text-purple-600 rounded-lg mr-2 md:mr-4">
                            <i class="fas fa-list text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Total Pembayaran</p>
                            <h3 class="text-lg md:text-2xl font-bold text-gray-800"><?= $total_pembayaran ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden mb-6 md:mb-8">
                <div
                    class="px-4 md:px-6 py-3 md:py-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center">
                    <h2 class="text-base md:text-lg font-semibold text-gray-800 mb-2 sm:mb-0">
                        <i class="fas fa-list mr-2"></i> Daftar Pembayaran
                    </h2>
                    <div class="text-xs md:text-sm text-gray-500">Terakhir update: <?= date('d/m/Y H:i') ?></div>
                </div>

                <div class="mobile-table-container overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Siswa</th>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">
                                    Tagihan</th>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status & Metode</th>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">
                                    Orang Tua</th>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($pembayaran_list)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-inbox text-3xl md:text-4xl text-gray-300 mb-3 md:mb-4"></i>
                                            <p class="text-base md:text-lg font-medium text-gray-400 mb-1 md:mb-2">Belum ada
                                                pembayaran untuk bulan ini</p>
                                            <p class="text-xs md:text-sm text-gray-400 max-w-xs">Gunakan tombol "Input
                                                Pembayaran Baru" untuk mencatat pembayaran</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pembayaran_list as $p): ?>
                                    <?php
                                    // Status badge
                                    $status_badge = '';
                                    switch ($p['status']) {
                                        case 'lunas':
                                            $status_badge = '<span class="badge badge-success text-xs flex items-center"><span class="status-indicator status-lunas"></span>LUNAS</span>';
                                            break;
                                        case 'belum_bayar':
                                            $status_badge = '<span class="badge badge-danger text-xs flex items-center"><span class="status-indicator status-belum"></span>BELUM</span>';
                                            break;
                                        case 'dibebaskan':
                                            $status_badge = '<span class="badge badge-warning text-xs flex items-center"><span class="status-indicator status-dibebaskan"></span>BEBAS</span>';
                                            break;
                                    }

                                    // Metode badge
                                    $metode_badge = '';
                                    if ($p['metode_bayar']) {
                                        switch ($p['metode_bayar']) {
                                            case 'cash':
                                                $metode_badge = '<span class="badge badge-cash text-xs">Cash</span>';
                                                break;
                                            case 'transfer':
                                                $metode_badge = '<span class="badge badge-transfer text-xs">Transfer</span>';
                                                break;
                                            case 'qris':
                                                $metode_badge = '<span class="badge badge-qris text-xs">QRIS</span>';
                                                break;
                                            case 'debit':
                                                $metode_badge = '<span class="badge badge-debit text-xs">Debit</span>';
                                                break;
                                            case 'credit':
                                                $metode_badge = '<span class="badge badge-credit text-xs">Kredit</span>';
                                                break;
                                            case 'ewallet':
                                                $metode_badge = '<span class="badge badge-ewallet text-xs">E-Wallet</span>';
                                                break;
                                        }
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 md:px-6 py-3 md:py-4">
                                            <div class="flex items-center">
                                                <div
                                                    class="flex-shrink-0 h-8 w-8 md:h-10 md:w-10 bg-gradient-to-r from-blue-100 to-blue-50 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-graduate text-blue-600 text-sm md:text-base"></i>
                                                </div>
                                                <div class="ml-2 md:ml-4">
                                                    <div
                                                        class="text-sm font-medium text-gray-900 truncate max-w-[120px] md:max-w-none">
                                                        <?= htmlspecialchars($p['nama_lengkap']) ?></div>
                                                    <div class="text-xs text-gray-500"><?= $p['kelas'] ?></div>
                                                    <div class="text-xs text-gray-500 mt-0.5 hidden sm:block">
                                                        <?= $p['jenis_kelas'] ?> (<?= $p['tingkat'] ?>)</div>
                                                    <!-- Mobile tagihan info -->
                                                    <div class="text-xs text-gray-700 mt-1 sm:hidden">
                                                        <div>Tagihan: <span class="font-semibold">Rp
                                                                <?= number_format($p['nominal_tagihan'], 0, ',', '.') ?></span>
                                                        </div>
                                                        <div>Dibayar: <span class="font-semibold text-green-600">Rp
                                                                <?= number_format($p['nominal_dibayar'], 0, ',', '.') ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4 hidden sm:table-cell">
                                            <div class="space-y-1 md:space-y-2">
                                                <div class="text-sm"><span class="text-gray-500">Tagihan:</span> <span
                                                        class="font-semibold ml-1">Rp
                                                        <?= number_format($p['nominal_tagihan'], 0, ',', '.') ?></span></div>
                                                <div class="text-sm"><span class="text-gray-500">Dibayar:</span> <span
                                                        class="font-semibold ml-1 text-green-600">Rp
                                                        <?= number_format($p['nominal_dibayar'], 0, ',', '.') ?></span></div>
                                                <div
                                                    class="text-xs px-2 py-1 rounded-full inline-block <?= $p['sisa_tagihan'] > 0 ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>">
                                                    <i
                                                        class="fas fa-<?= $p['sisa_tagihan'] > 0 ? 'exclamation-circle' : 'check-circle' ?> mr-1"></i>
                                                    Sisa: Rp <?= number_format($p['sisa_tagihan'], 0, ',', '.') ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4">
                                            <div class="space-y-1 md:space-y-2">
                                                <div><?= $status_badge ?></div>
                                                <?php if ($p['metode_bayar']): ?>
                                                    <div class="text-xs text-gray-500 hidden md:block"><?= $metode_badge ?></div>
                                                <?php endif; ?>
                                                <?php if ($p['status'] == 'lunas' && $p['tgl_bayar_formatted']): ?>
                                                    <div class="text-xs text-gray-500 hidden md:flex items-center"><i
                                                            class="far fa-calendar mr-1"></i><?= $p['tgl_bayar_formatted'] ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4 hidden md:table-cell">
                                            <div class="space-y-1">
                                                <div class="text-sm font-medium text-gray-900 truncate max-w-[150px]">
                                                    <?= htmlspecialchars($p['nama_ortu'] ?? 'Tidak ada data') ?></div>
                                                <div class="text-xs text-gray-500"><i
                                                        class="fas fa-phone mr-1"></i><?= $p['no_hp'] ?? '-' ?></div>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                            <div class="mobile-action-buttons gap-1 flex">
                                                <!-- Tombol Detail -->
                                                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                    class="action-btn bg-blue-50 text-blue-600 hover:bg-blue-100 touch-target"
                                                    title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>

                                                <!-- Tombol Hapus -->
                                                <button
                                                    onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nama_lengkap']) ?>')"
                                                    class="action-btn bg-red-50 text-red-600 hover:bg-red-100 touch-target"
                                                    title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($pembayaran_list)): ?>
                    <div
                        class="px-4 md:px-6 py-3 md:py-4 border-t border-gray-200 bg-gray-50 flex flex-col sm:flex-row justify-between items-start sm:items-center text-xs md:text-sm text-gray-500">
                        <div class="mb-2 sm:mb-0">Menampilkan <span
                                class="font-semibold"><?= count($pembayaran_list) ?></span> pembayaran</div>
                        <div class="flex items-center space-x-3 md:space-x-4">
                            <div class="flex items-center space-x-1 md:space-x-2">
                                <div class="w-2 h-2 md:w-3 md:h-3 bg-green-500 rounded-full"></div>
                                <span>Lunas: <?= $lunas_count ?></span>
                            </div>
                            <div class="flex items-center space-x-1 md:space-x-2">
                                <div class="w-2 h-2 md:w-3 md:h-3 bg-red-500 rounded-full"></div>
                                <span>Belum: <?= $belum_count ?></span>
                            </div>
                            <div class="hidden md:flex items-center space-x-2">
                                <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                                <span>Bebas: <?= $bebas_count ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ================= MODALS ================= -->

    <!-- Modal Tambah Pembayaran -->
    <div id="modalTambah" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 md:px-6 py-3 md:py-4 rounded-t-lg">
                <h2 class="text-lg md:text-xl font-bold flex items-center"><i class="fas fa-plus mr-2 md:mr-3"></i>
                    Input Pembayaran Baru</h2>
            </div>
            <form method="POST" class="p-4 md:p-6" id="formTambahPembayaran">
                <input type="hidden" name="create_payment" value="1">

                <!-- Search Siswa dengan Autocomplete -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Cari Siswa: <span class="text-red-500">*</span>
                        <span class="text-xs text-gray-500">(ketik nama siswa)</span>
                    </label>

                    <div class="autocomplete-container">
                        <input type="text" id="searchSiswa" class="autocomplete-input"
                            placeholder="Ketik nama siswa..." autocomplete="off">
                        <input type="hidden" name="siswa_id" id="selectedSiswaId">
                        <button type="button" id="clearSearch" class="autocomplete-clear">
                            <i class="fas fa-times"></i>
                        </button>
                        <div id="siswaDropdown" class="autocomplete-dropdown"></div>
                    </div>

                    <!-- Info siswa yang dipilih -->
                    <div id="selectedSiswaInfo" class="selected-siswa-info mt-2 p-3 hidden">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-medium text-gray-900" id="selectedSiswaName"></div>
                                <div class="text-sm text-gray-600">
                                    Kelas: <span id="selectedSiswaKelas"></span> |
                                    Sekolah: <span id="selectedSiswaSekolah"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1" id="selectedSiswaOrangtua"></div>
                                <div class="text-xs text-blue-600 mt-1" id="selectedSiswaPendaftaran"></div>
                            </div>
                            <button type="button" onclick="clearSelectedSiswa()"
                                class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bulan Tagihan -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Bulan Tagihan: <span class="text-red-500">*</span>
                    </label>
                    <select name="bulan" required
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                        <?php for ($i = 0; $i < 6; $i++):
                            $date = date('Y-m', strtotime("+$i months")); ?>
                            <option value="<?= $date ?>" <?= ($date == $current_month) ? 'selected' : '' ?>>
                                <?= date('F Y', strtotime($date . '-01')) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Status Pembayaran -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Status Pembayaran: <span class="text-red-500">*</span>
                    </label>
                    <select name="status" id="statusSelect" required onchange="handleStatusChange(this.value)"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                        <?php foreach ($STATUS_PEMBAYARAN as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="statusHint" class="form-hint hint-info mt-1">
                        <i class="fas fa-info-circle mr-1"></i> Pilih status sesuai kondisi pembayaran
                    </div>
                </div>

                <!-- Nominal Tagihan -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Nominal Tagihan: <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-gray-500">Rp</span>
                        </div>
                        <input type="text" name="nominal_tagihan" id="nominalTagihan"
                            value="<?= number_format($NOMINAL_DEFAULT, 0, ',', '.') ?>" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                    </div>
                </div>

                <!-- Nominal Dibayar -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Nominal Dibayar: <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-gray-500">Rp</span>
                        </div>
                        <input type="text" name="nominal_dibayar" id="nominalDibayar"
                            value="<?= number_format($NOMINAL_DEFAULT, 0, ',', '.') ?>" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                    </div>
                    <div id="nominalHint" class="form-hint hint-info mt-1">
                        <i class="fas fa-info-circle mr-1"></i> Sesuaikan dengan status yang dipilih
                    </div>
                </div>

                <!-- Metode Bayar -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Metode Bayar:
                    </label>
                    <select name="metode_bayar" id="metodeBayar"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base">
                        <option value="">- Pilih Metode Bayar -</option>
                        <?php foreach ($METODE_BAYAR as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="metodeHint" class="form-hint hint-info mt-1">
                        <i class="fas fa-info-circle mr-1"></i> Wajib diisi jika status "Lunas"
                    </div>
                </div>

                <!-- Keterangan -->
                <div class="mb-4 md:mb-5">
                    <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                        Keterangan:
                    </label>
                    <textarea name="keterangan" rows="2"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 md:py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm md:text-base"
                        placeholder="Catatan..."></textarea>
                </div>

                <div class="flex justify-end space-x-2 md:space-x-3 mt-4 md:mt-6">
                    <button type="button" onclick="closeTambahModal()"
                        class="px-4 md:px-5 py-2 md:py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50 font-medium text-sm md:text-base">
                        Batal
                    </button>
                    <button type="submit"
                        class="px-4 md:px-5 py-2 md:py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium flex items-center text-sm md:text-base">
                        <i class="fas fa-save mr-2"></i> Simpan Pembayaran
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Detail -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 md:px-6 py-3 md:py-4 rounded-t-lg">
                <h2 class="text-lg md:text-xl font-bold flex items-center"><i
                        class="fas fa-info-circle mr-2 md:mr-3"></i> Detail Pembayaran</h2>
            </div>
            <div class="p-4 md:p-6">
                <!-- Info Status -->
                <div class="mb-3 md:mb-4 p-3 bg-gray-50 rounded-lg">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-gray-600 text-sm md:text-base">Status:</span>
                        <span id="detailStatusBadge" class="text-xs md:text-sm font-semibold"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600 text-sm md:text-base">Bulan Tagihan:</span>
                        <span id="detailBulan" class="font-medium text-sm md:text-base"></span>
                    </div>
                </div>

                <!-- Info Siswa -->
                <div class="mb-4 md:mb-6">
                    <h3 class="font-medium text-gray-700 mb-2 md:mb-3 flex items-center text-sm md:text-base">
                        <i class="fas fa-user-graduate mr-2 text-blue-500"></i>Informasi Siswa
                    </h3>
                    <div class="space-y-2 md:space-y-3">
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Nama Lengkap</span>
                            <span id="detailNamaLengkap" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Kelas</span>
                            <span id="detailKelas" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Sekolah Asal</span>
                            <span id="detailSekolah" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Tempat/Tgl Lahir</span>
                            <span id="detailTtl" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Jenis Kelamin</span>
                            <span id="detailJenisKelamin" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Alamat</span>
                            <span id="detailAlamat" class="detail-value text-xs md:text-sm"></span>
                        </div>
                    </div>
                </div>

                <!-- Info Program -->
                <div class="mb-4 md:mb-6">
                    <h3 class="font-medium text-gray-700 mb-2 md:mb-3 flex items-center text-sm md:text-base">
                        <i class="fas fa-book-open mr-2 text-green-500"></i>Informasi Program
                    </h3>
                    <div class="space-y-2 md:space-y-3">
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Jenis Kelas</span>
                            <span id="detailJenisKelas" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Tingkat</span>
                            <span id="detailTingkat" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Tahun Ajaran</span>
                            <span id="detailTahunAjaran" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Tanggal Mulai</span>
                            <span id="detailTanggalMulai" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <?php if (!empty($p['tanggal_selesai'])): ?>
                            <div class="detail-item">
                                <span class="detail-label text-xs md:text-sm">Tanggal Selesai</span>
                                <span id="detailTanggalSelesai" class="detail-value text-xs md:text-sm"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Pembayaran -->
                <div class="mb-4 md:mb-6">
                    <h3 class="font-medium text-gray-700 mb-2 md:mb-3 flex items-center text-sm md:text-base">
                        <i class="fas fa-credit-card mr-2 text-purple-500"></i>Informasi Pembayaran
                    </h3>
                    <div class="space-y-2 md:space-y-3">
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Nominal Tagihan</span>
                            <span id="detailNominalTagihan"
                                class="detail-value text-xs md:text-sm font-semibold"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Nominal Dibayar</span>
                            <span id="detailNominalDibayar" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Sisa Tagihan</span>
                            <span id="detailSisaTagihan" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Metode Bayar</span>
                            <span id="detailMetodeBayar" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Tanggal Bayar</span>
                            <span id="detailTanggalBayar" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Keterangan</span>
                            <span id="detailKeterangan" class="detail-value text-xs md:text-sm"></span>
                        </div>
                    </div>
                </div>

                <!-- Info Orang Tua -->
                <div class="mb-4 md:mb-6">
                    <h3 class="font-medium text-gray-700 mb-2 md:mb-3 flex items-center text-sm md:text-base">
                        <i class="fas fa-users mr-2 text-orange-500"></i>Informasi Orang Tua
                    </h3>
                    <div class="space-y-2 md:space-y-3">
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Nama Orang Tua</span>
                            <span id="detailNamaOrtu" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Hubungan</span>
                            <span id="detailHubungan" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">No. HP</span>
                            <span id="detailNoHP" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Email</span>
                            <span id="detailEmail" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Pekerjaan</span>
                            <span id="detailPekerjaan" class="detail-value text-xs md:text-sm"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label text-xs md:text-sm">Perusahaan</span>
                            <span id="detailPerusahaan" class="detail-value text-xs md:text-sm"></span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" onclick="closeDetailModal()"
                        class="px-4 md:px-5 py-2 md:py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium transition-colors duration-150 text-sm md:text-base">
                        <i class="fas fa-times mr-2"></i>Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ==================== VARIABEL GLOBAL ====================
        let searchTimeout;
        let searchCache = {};

        // ==================== FUNGSI MODAL ====================
        function openTambahModal() {
            document.getElementById('modalTambah').style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Reset form
            clearSelectedSiswa();
            handleStatusChange('belum_bayar');

            // Focus ke search input
            setTimeout(() => {
                document.getElementById('searchSiswa').focus();
            }, 100);
        }

        function closeTambahModal() {
            document.getElementById('modalTambah').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('formTambahPembayaran').reset();
            clearSelectedSiswa();
            searchCache = {};
        }

        function openDetailModal(paymentData) {
            console.log('Payment Data:', paymentData);
            
            // Status badge
            let statusBadge = '';
            switch (paymentData.status) {
                case 'lunas':
                    statusBadge = '<span class="badge badge-success flex items-center"><span class="status-indicator status-lunas"></span>LUNAS</span>';
                    break;
                case 'belum_bayar':
                    statusBadge = '<span class="badge badge-danger flex items-center"><span class="status-indicator status-belum"></span>BELUM BAYAR</span>';
                    break;
                case 'dibebaskan':
                    statusBadge = '<span class="badge badge-warning flex items-center"><span class="status-indicator status-dibebaskan"></span>DIBEBASKAN</span>';
                    break;
            }
            document.getElementById('detailStatusBadge').innerHTML = statusBadge;

            // Format bulan
            document.getElementById('detailBulan').textContent = formatMonth(paymentData.bulan_tagihan);

            // Siswa info
            document.getElementById('detailNamaLengkap').textContent = paymentData.nama_lengkap;
            document.getElementById('detailKelas').textContent = paymentData.kelas;
            document.getElementById('detailSekolah').textContent = paymentData.sekolah_asal || '-';

            // TTL
            const tempatLahir = paymentData.tempat_lahir || '';
            const tglLahir = paymentData.tanggal_lahir ? formatDate(paymentData.tanggal_lahir) : '';
            document.getElementById('detailTtl').textContent = tempatLahir + (tempatLahir && tglLahir ? ', ' : '') + tglLahir || '-';

            document.getElementById('detailJenisKelamin').textContent = paymentData.jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan';
            document.getElementById('detailAlamat').textContent = paymentData.alamat || '-';

            // Program info
            document.getElementById('detailJenisKelas').textContent = paymentData.jenis_kelas;
            document.getElementById('detailTingkat').textContent = paymentData.tingkat;
            document.getElementById('detailTahunAjaran').textContent = paymentData.tahun_ajaran;
            document.getElementById('detailTanggalMulai').textContent = formatDate(paymentData.tanggal_mulai);

            // Pembayaran info
            document.getElementById('detailNominalTagihan').textContent =
                'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.nominal_tagihan);
            document.getElementById('detailNominalDibayar').textContent =
                'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.nominal_dibayar);
            document.getElementById('detailSisaTagihan').textContent =
                'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.sisa_tagihan);

            // Metode bayar
            let metodeText = '-';
            if (paymentData.metode_bayar) {
                const metodeLabels = {
                    'cash': 'Cash',
                    'transfer': 'Transfer Bank',
                    'qris': 'QRIS',
                    'debit': 'Kartu Debit',
                    'credit': 'Kartu Kredit',
                    'ewallet': 'E-Wallet'
                };
                metodeText = metodeLabels[paymentData.metode_bayar] || paymentData.metode_bayar;
            }
            document.getElementById('detailMetodeBayar').textContent = metodeText;

            // Tanggal dan keterangan
            document.getElementById('detailTanggalBayar').textContent = paymentData.tgl_bayar_formatted || '-';
            document.getElementById('detailKeterangan').textContent = paymentData.keterangan || '-';

            // Orang tua info
            document.getElementById('detailNamaOrtu').textContent = paymentData.nama_ortu || '-';
            document.getElementById('detailHubungan').textContent = paymentData.hubungan_dengan_siswa || '-';
            document.getElementById('detailNoHP').textContent = paymentData.no_hp || '-';
            document.getElementById('detailEmail').textContent = paymentData.email_ortu || '-';
            document.getElementById('detailPekerjaan').textContent = paymentData.pekerjaan || '-';
            document.getElementById('detailPerusahaan').textContent = paymentData.perusahaan || '-';

            // Tampilkan modal
            document.getElementById('detailModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeDetailModal() {
            document.getElementById('detailModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // ==================== AUTOSEARCH SISWA ====================
        // Inisialisasi autocomplete saat DOM ready
        $(document).ready(function () {
            initAutocomplete();
        });

        function initAutocomplete() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSearch');
            const dropdown = document.getElementById('siswaDropdown');

            let selectedIndex = -1;

            // Tampilkan dropdown saat fokus
            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) {
                    filterSiswa(this.value);
                }
            });

            // Filter siswa saat mengetik dengan debounce
            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimeout);
                const query = this.value;

                if (query.length < 2) {
                    dropdown.style.display = 'none';
                    clearButton.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(() => {
                    filterSiswa(query);
                }, 300);

                clearButton.style.display = query.length > 0 ? 'block' : 'none';
            });

            // Clear search
            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                clearButton.style.display = 'none';
                dropdown.style.display = 'none';
                clearSelectedSiswa();
            });

            // Navigasi dengan keyboard
            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelectedItem();
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem();
                        break;

                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0 && items[selectedIndex]) {
                            selectSiswa(items[selectedIndex].dataset);
                        }
                        break;

                    case 'Escape':
                        dropdown.style.display = 'none';
                        selectedIndex = -1;
                        break;
                }
            });

            // Close dropdown saat klik di luar
            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        // Fungsi filter siswa dengan AJAX
        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');
            
            // Tampilkan loading
            dropdown.innerHTML = '<div class="no-results"><span class="spinner"></span> Mencari...</div>';
            dropdown.style.display = 'block';

            $.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: {
                    ajax: 'get_siswa_list',
                    search: query
                },
                dataType: 'json',
                success: function (data) {
                    renderDropdown(data);
                },
                error: function () {
                    dropdown.innerHTML = '<div class="no-results">Gagal memuat data</div>';
                }
            });
        }

        // Render dropdown
        function renderDropdown(data) {
            const dropdown = document.getElementById('siswaDropdown');
            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="no-results">Tidak ditemukan siswa</div>';
                dropdown.style.display = 'block';
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas;
                item.dataset.sekolah = siswa.sekolah_asal || '';
                item.dataset.orangtua = siswa.orangtua_info || '';
                item.dataset.pendaftaran = siswa.pendaftaran_aktif || '';
                item.dataset.totalPendaftaran = siswa.total_pendaftaran_aktif || '0';

                // Status badge
                const statusBadge = siswa.status_siswa === 'aktif' ?
                    '<span style="background-color:#d1fae5;color:#065f46;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:5px;">Aktif</span>' :
                    '<span style="background-color:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:5px;">Non-Aktif</span>';

                // Warning jika tidak ada pendaftaran aktif
                const pendaftaranWarning = parseInt(siswa.total_pendaftaran_aktif) === 0 ?
                    '<div style="color:#dc2626;font-size:11px;margin-top:2px;"><i class="fas fa-exclamation-triangle"></i> Tidak ada pendaftaran aktif</div>' :
                    '';

                item.innerHTML = `
                    <div class="siswa-nama">${siswa.nama_lengkap} ${statusBadge}</div>
                    <div class="siswa-info">
                        Kelas: ${siswa.kelas} | Sekolah: ${siswa.sekolah_asal || '-'}
                        ${siswa.orangtua_info ? '<br>Orang Tua: ' + siswa.orangtua_info : ''}
                        ${siswa.pendaftaran_aktif ? '<br>Pendaftaran: ' + siswa.pendaftaran_aktif : ''}
                        ${pendaftaranWarning}
                    </div>
                `;

                item.addEventListener('click', function () {
                    selectSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.autocomplete-item');
                    selectedIndex = Array.from(items).indexOf(this);
                    updateSelectedItem();
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
            selectedIndex = -1;
        }

        // Update selected item style
        function updateSelectedItem() {
            const dropdown = document.getElementById('siswaDropdown');
            const items = dropdown.querySelectorAll('.autocomplete-item');

            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('active');
                    // Scroll ke item yang dipilih
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        // Fungsi pilih siswa
        function selectSiswa(data) {
            const selectedSiswaId = document.getElementById('selectedSiswaId');
            const searchInput = document.getElementById('searchSiswa');
            const dropdown = document.getElementById('siswaDropdown');

            selectedSiswaId.value = data.id;
            searchInput.value = data.nama;
            dropdown.style.display = 'none';

            // Tampilkan info siswa yang dipilih
            document.getElementById('selectedSiswaName').textContent = data.nama;
            document.getElementById('selectedSiswaKelas').textContent = data.kelas;
            document.getElementById('selectedSiswaSekolah').textContent = data.sekolah || '-';

            if (data.orangtua) {
                document.getElementById('selectedSiswaOrangtua').textContent = 'Orang Tua: ' + data.orangtua;
            }

            if (data.pendaftaran) {
                document.getElementById('selectedSiswaPendaftaran').textContent = 'Pendaftaran Aktif: ' + data.pendaftaran;
            }

            // Tampilkan warning jika tidak ada pendaftaran aktif
            const totalPendaftaran = parseInt(data.totalPendaftaran);
            if (totalPendaftaran === 0) {
                document.getElementById('selectedSiswaPendaftaran').innerHTML = 
                    '<span style="color:#dc2626;"><i class="fas fa-exclamation-triangle mr-1"></i>Tidak ada pendaftaran aktif!</span>';
            }

            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        // Fungsi clear selected siswa
        function clearSelectedSiswa() {
            document.getElementById('searchSiswa').value = '';
            document.getElementById('selectedSiswaId').value = '';
            document.getElementById('selectedSiswaInfo').classList.add('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        // ==================== FUNGSI BANTU ====================
        function changeMonth(month) {
            window.location.href = '?bulan=' + month;
        }

        function formatCurrency(input) {
            let value = input.value.replace(/\D/g, '');
            if (value) {
                value = parseInt(value, 10).toLocaleString('id-ID');
            }
            input.value = value;
        }

        function handleStatusChange(status) {
            const nominalTagihan = document.getElementById('nominalTagihan');
            const nominalDibayar = document.getElementById('nominalDibayar');
            const metodeBayar = document.getElementById('metodeBayar');
            const statusHint = document.getElementById('statusHint');
            const nominalHint = document.getElementById('nominalHint');
            const metodeHint = document.getElementById('metodeHint');

            // Reset semua hints
            statusHint.className = 'form-hint hint-info mt-1';
            nominalHint.className = 'form-hint hint-info mt-1';
            metodeHint.className = 'form-hint hint-info mt-1';

            // Reset required
            metodeBayar.required = false;

            switch (status) {
                case 'lunas':
                    // Jika lunas, set nominal dibayar = tagihan
                    const tagihanValue = nominalTagihan.value.replace(/\./g, '');
                    nominalDibayar.value = formatNumber(tagihanValue);
                    metodeBayar.required = true;

                    statusHint.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Status: <span class="font-medium">LUNAS</span> - Pembayaran sudah lunas';
                    statusHint.className = 'form-hint hint-success mt-1';

                    nominalHint.innerHTML = '<i class="fas fa-info-circle mr-1"></i> Nominal dibayar otomatis disesuaikan dengan tagihan';

                    metodeHint.innerHTML = '<i class="fas fa-exclamation-circle mr-1"></i> <span class="font-medium">Wajib</span> pilih metode pembayaran';
                    metodeHint.className = 'form-hint hint-warning mt-1';
                    break;

                case 'dibebaskan':
                    // Jika dibebaskan, set nominal dibayar = 0 dan kosongkan metode
                    nominalDibayar.value = '0';
                    metodeBayar.value = '';
                    metodeBayar.required = false;

                    statusHint.innerHTML = '<i class="fas fa-hand-holding-heart mr-1"></i> Status: <span class="font-medium">DIBEBASKAN</span> - Pembayaran gratis';
                    statusHint.className = 'form-hint hint-warning mt-1';

                    nominalHint.innerHTML = '<i class="fas fa-info-circle mr-1"></i> Nominal dibayar otomatis 0';

                    metodeHint.innerHTML = '<i class="fas fa-info-circle mr-1"></i> Metode bayar tidak diperlukan';
                    break;

                case 'belum_bayar':
                default:
                    // Reset ke default
                    const defaultTagihan = '<?= number_format($NOMINAL_DEFAULT, 0, ",", ".") ?>';
                    if (nominalDibayar.value === '0') {
                        nominalDibayar.value = defaultTagihan;
                    }
                    metodeBayar.required = false;

                    statusHint.innerHTML = '<i class="fas fa-clock mr-1"></i> Status: <span class="font-medium">BELUM BAYAR</span> - Menunggu pembayaran';

                    nominalHint.innerHTML = '<i class="fas fa-info-circle mr-1"></i> Bisa diisi nominal dibayar sebagian';

                    metodeHint.innerHTML = '<i class="fas fa-info-circle mr-1"></i> Opsional, bisa diisi jika sudah ada pembayaran sebagian';
                    break;
            }
        }

        function formatNumber(num) {
            const parsed = parseInt(num, 10);
            return isNaN(parsed) ? '0' : parsed.toLocaleString('id-ID');
        }

        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            });
        }

        function formatMonth(monthStr) {
            if (!monthStr) return '-';
            const date = new Date(monthStr + '-01');
            return date.toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric'
            });
        }

        function confirmDelete(id, name) {
            if (confirm('Hapus pembayaran untuk ' + name + '?')) {
                window.location.href = '?bulan=<?= $selected_month ?>&delete_id=' + id;
            }
        }

        // Close modal on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTambahModal();
                closeDetailModal();
            }
        });

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    if (this.id === 'modalTambah') closeTambahModal();
                    if (this.id === 'detailModal') closeDetailModal();
                }
            });
        });

        // Mobile menu functions
        document.getElementById('menuToggle')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.add('menu-open');
            document.getElementById('menuOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('menuClose')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            document.getElementById('menuOverlay').classList.remove('active');
            document.body.style.overflow = 'auto';
        });

        document.getElementById('menuOverlay')?.addEventListener('click', () => {
            document.getElementById('mobileMenu').classList.remove('menu-open');
            document.getElementById('menuOverlay').classList.remove('active');
            document.body.style.overflow = 'auto';
        });

        // Validasi form sebelum submit
        document.getElementById('formTambahPembayaran').addEventListener('submit', function (e) {
            const siswaId = document.getElementById('selectedSiswaId').value;
            const nominalTagihan = this.querySelector('input[name="nominal_tagihan"]');
            const nominalDibayar = this.querySelector('input[name="nominal_dibayar"]');
            const status = document.getElementById('statusSelect').value;
            const metodeBayar = document.getElementById('metodeBayar').value;

            if (!siswaId) {
                e.preventDefault();
                alert('Harap pilih siswa terlebih dahulu!');
                document.getElementById('searchSiswa').focus();
                return;
            }

            // Format nominal untuk database
            if (nominalTagihan && nominalTagihan.value) {
                nominalTagihan.value = nominalTagihan.value.replace(/\./g, '').replace(/,/g, '.');
            }
            if (nominalDibayar && nominalDibayar.value) {
                nominalDibayar.value = nominalDibayar.value.replace(/\./g, '').replace(/,/g, '.');
            }

            // Validasi metode untuk status lunas
            if (status === 'lunas' && !metodeBayar) {
                e.preventDefault();
                alert('Untuk status LUNAS, metode bayar wajib diisi!');
                document.getElementById('metodeBayar').focus();
                return;
            }

            // Validasi total pendaftaran aktif
            const totalPendaftaran = document.getElementById('selectedSiswaPendaftaran')?.textContent || '';
            if (totalPendaftaran.includes('Tidak ada pendaftaran aktif')) {
                e.preventDefault();
                alert('Siswa tidak memiliki pendaftaran aktif! Tidak bisa mencatat pembayaran.');
                return;
            }
        });

        // Auto format saat input pembayaran baru dibuka
        document.querySelector('button[onclick*="openTambahModal"]')?.addEventListener('click', function () {
            setTimeout(() => {
                handleStatusChange('belum_bayar');
            }, 100);
        });

        // Close mobile menu ketika klik link
        document.querySelectorAll('#mobileMenu a').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('mobileMenu').classList.remove('menu-open');
                document.getElementById('menuOverlay').classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });
    </script>
</body>

</html>