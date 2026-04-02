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
$current_month = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$current_year = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');

// Untuk filter tampilan (bisa filter per bulan atau per tahun)
$filter_tipe = isset($_GET['filter_tipe']) ? $_GET['filter_tipe'] : 'bulan';
$selected_filter = $filter_tipe == 'bulan' ? $current_month : $current_year;

// Filter search by name - menggunakan parameter 'nama_siswa' seperti di jadwalSiswa
$search_nama = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_siswa_id = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;

$success_msg = '';
$error_msg = '';

// ============================================
// ACTION: Input pembayaran baru
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_payment'])) {

    $siswa_id = intval($_POST['siswa_id']);
    $tanggal_bayar_input = $_POST['tanggal_bayar'] ?? date('Y-m-d');
    $nominal_tagihan = floatval(str_replace(['.', ','], ['', '.'], $_POST['nominal_tagihan'] ?? $NOMINAL_DEFAULT));
    $nominal_dibayar = floatval(str_replace(['.', ','], ['', '.'], $_POST['nominal_dibayar'] ?? 0));
    $metode_bayar = $_POST['metode_bayar'] ?? NULL;
    $keterangan = $_POST['keterangan'] ?? NULL;
    $status = $_POST['status'] ?? 'belum_bayar';

    $bulan_tagihan = date('Y-m', strtotime($tanggal_bayar_input));

    $errors = [];
    if (!$siswa_id)
        $errors[] = "Harap pilih siswa!";
    if (empty($tanggal_bayar_input))
        $errors[] = "Harap pilih tanggal pembayaran!";

    if (empty($errors)) {
        $check_pendaftaran = $conn->query("SELECT id FROM pendaftaran_siswa WHERE siswa_id = $siswa_id AND status = 'aktif' LIMIT 1");

        if ($check_pendaftaran->num_rows == 0) {
            $errors[] = "Siswa tidak memiliki pendaftaran aktif!";
        } else {
            $pendaftaran_row = $check_pendaftaran->fetch_assoc();
            $pendaftaran_id = $pendaftaran_row['id'];

            $check = $conn->query("SELECT p.id FROM pembayaran p 
                                 JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id 
                                 WHERE ps.siswa_id = $siswa_id AND p.bulan_tagihan = '$bulan_tagihan'");

            if ($check->num_rows > 0) {
                $errors[] = "Sudah ada pembayaran untuk siswa ini di bulan " . date('F Y', strtotime($bulan_tagihan));
            } else {
                $tanggal_bayar_db = $tanggal_bayar_input;
                $metode_bayar_escaped = $metode_bayar ? "'" . $conn->real_escape_string($metode_bayar) . "'" : "NULL";
                $keterangan_escaped = $keterangan ? "'" . $conn->real_escape_string($keterangan) . "'" : "NULL";
                $status_escaped = $conn->real_escape_string($status);

                $sql = "INSERT INTO pembayaran 
                    (pendaftaran_id, bulan_tagihan, nominal_tagihan, status, 
                     nominal_dibayar, metode_bayar, tanggal_bayar, keterangan, dibuat_oleh) 
                    VALUES ($pendaftaran_id, '$bulan_tagihan', $nominal_tagihan, '$status_escaped',
                            $nominal_dibayar, $metode_bayar_escaped, '$tanggal_bayar_db', 
                            $keterangan_escaped, {$_SESSION['user_id']})";

                if ($conn->query($sql)) {
                    $success_msg = "Pembayaran berhasil dicatat!";
                    $redirect_url = $_SERVER['PHP_SELF'] . '?filter_tipe=' . $filter_tipe . '&' . ($filter_tipe == 'bulan' ? 'bulan' : 'tahun') . '=' . $selected_filter;
                    if (!empty($search_nama)) {
                        $redirect_url .= '&nama_siswa=' . urlencode($search_nama);
                    }
                    if ($filter_siswa_id > 0) {
                        $redirect_url .= '&siswa_id=' . $filter_siswa_id;
                    }
                    $redirect_url .= '&success=1';
                    header('Location: ' . $redirect_url);
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
// ACTION: Update pembayaran (EDIT)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    error_log("=== UPDATE PAYMENT PROCESSING ===");

    $pembayaran_id = intval($_POST['pembayaran_id']);
    $status = $_POST['status'];
    $nominal_dibayar = $_POST['nominal_dibayar'] ?? 0;
    $metode_bayar = $_POST['metode_bayar'] ?? NULL;
    $keterangan = $_POST['keterangan'] ?? NULL;
    $tanggal_bayar = $_POST['tanggal_bayar'] ?? date('Y-m-d');
    $nominal_tagihan = floatval(str_replace(['.', ','], ['', '.'], $_POST['nominal_tagihan'] ?? $NOMINAL_DEFAULT));

    error_log("Update - Status: $status, ID: $pembayaran_id, Tanggal: $tanggal_bayar");

    $nominal_dibayar = floatval(str_replace(['.', ','], ['', '.'], $nominal_dibayar));

    if ($status == 'dibebaskan') {
        $nominal_dibayar = 0;
        $metode_bayar = NULL;
    }

    if ($status == 'lunas' && empty($tanggal_bayar)) {
        $tanggal_bayar = date('Y-m-d');
    }

    if ($status == 'belum_bayar') {
        $tanggal_bayar = NULL;
    }

    if (!in_array($metode_bayar, array_keys($METODE_BAYAR)) && $metode_bayar !== NULL) {
        $metode_bayar = NULL;
    }

    $update = $conn->prepare("UPDATE pembayaran SET 
        status = ?,
        nominal_tagihan = ?,
        nominal_dibayar = ?,
        metode_bayar = ?,
        tanggal_bayar = ?,
        keterangan = ?,
        diperbarui_oleh = ?,
        diperbarui_pada = NOW()
        WHERE id = ?");

    if ($update) {
        $update->bind_param(
            "sddsssii",
            $status,
            $nominal_tagihan,
            $nominal_dibayar,
            $metode_bayar,
            $tanggal_bayar,
            $keterangan,
            $_SESSION['user_id'],
            $pembayaran_id
        );

        if ($update->execute()) {
            $success_msg = "Pembayaran berhasil diperbarui!";
            $redirect_url = $_SERVER['PHP_SELF'] . '?filter_tipe=' . $filter_tipe . '&' . ($filter_tipe == 'bulan' ? 'bulan' : 'tahun') . '=' . $selected_filter;
            if (!empty($search_nama)) {
                $redirect_url .= '&nama_siswa=' . urlencode($search_nama);
            }
            if ($filter_siswa_id > 0) {
                $redirect_url .= '&siswa_id=' . $filter_siswa_id;
            }
            $redirect_url .= '&success=2';
            header('Location: ' . $redirect_url);
            exit();
        } else {
            $error_msg = "Gagal update: " . $update->error;
        }
    } else {
        $error_msg = "Error preparing update statement: " . $conn->error;
    }
}

// ACTION: Delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $delete = $conn->prepare("DELETE FROM pembayaran WHERE id = ?");
    $delete->bind_param("i", $delete_id);

    if ($delete->execute()) {
        $success_msg = "Pembayaran berhasil dihapus!";
        $redirect_url = $_SERVER['PHP_SELF'] . '?filter_tipe=' . $filter_tipe . '&' . ($filter_tipe == 'bulan' ? 'bulan' : 'tahun') . '=' . $selected_filter;
        if (!empty($search_nama)) {
            $redirect_url .= '&nama_siswa=' . urlencode($search_nama);
        }
        if ($filter_siswa_id > 0) {
            $redirect_url .= '&siswa_id=' . $filter_siswa_id;
        }
        $redirect_url .= '&success=3';
        header('Location: ' . $redirect_url);
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

// QUERY data pembayaran dengan filter
$sql = "SELECT 
    p.*,
    s.id as siswa_id,
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
    
    (p.nominal_tagihan - COALESCE(p.nominal_dibayar, 0)) as sisa_tagihan,
    
    DATE_FORMAT(p.tanggal_bayar, '%d/%m/%Y') as tgl_bayar_formatted,
    DATE_FORMAT(p.dibuat_pada, '%d/%m/%Y %H:%i') as dibuat_formatted,
    DATE_FORMAT(p.diperbarui_pada, '%d/%m/%Y %H:%i') as diperbarui_formatted,
    
    (SELECT full_name FROM users WHERE id = p.dibuat_oleh) as dibuat_oleh_nama,
    (SELECT full_name FROM users WHERE id = p.diperbarui_oleh) as diperbarui_oleh_nama
    
FROM pembayaran p
JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id
JOIN siswa s ON ps.siswa_id = s.id
LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
LEFT JOIN orangtua o ON so.orangtua_id = o.id
WHERE 1=1";

$params = [];
$types = "";

// Filter periode
if ($filter_tipe == 'bulan') {
    $sql .= " AND (DATE_FORMAT(p.tanggal_bayar, '%Y-%m') = ? OR (p.tanggal_bayar IS NULL AND p.bulan_tagihan = ?))";
    $params[] = $selected_filter;
    $params[] = $selected_filter;
    $types .= "ss";
} else {
    $sql .= " AND (YEAR(p.tanggal_bayar) = ? OR (p.tanggal_bayar IS NULL AND YEAR(STR_TO_DATE(CONCAT(p.bulan_tagihan, '-01'), '%Y-%m-%d')) = ?))";
    $params[] = $selected_filter;
    $params[] = $selected_filter;
    $types .= "ss";
}

// Filter search by nama siswa (seperti di jadwalSiswa)
if ($filter_siswa_id > 0) {
    $sql .= " AND s.id = ?";
    $params[] = $filter_siswa_id;
    $types .= "i";
} elseif (!empty($search_nama)) {
    $sql .= " AND s.nama_lengkap LIKE ?";
    $params[] = "%" . $search_nama . "%";
    $types .= "s";
}

$sql .= " ORDER BY p.tanggal_bayar DESC, FIELD(p.status, 'belum_bayar', 'dibebaskan', 'lunas'), s.nama_lengkap";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
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

// Ambil semua siswa untuk autocomplete (sama seperti di jadwalSiswa)
$siswa_list = [];
$siswa_result = $conn->query("SELECT 
    s.id,
    s.nama_lengkap,
    s.kelas,
    s.sekolah_asal,
    s.status as status_siswa,
    GROUP_CONCAT(DISTINCT CONCAT(o.hubungan_dengan_siswa, ': ', o.nama_ortu) SEPARATOR '; ') as orangtua_info,
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

// AJAX Handler untuk search siswa (sama seperti di jadwalSiswa)
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    switch ($_GET['ajax']) {
        case 'search_siswa':
            $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
            if (empty($keyword) || strlen($keyword) < 2) {
                echo json_encode([]);
                break;
            }

            $sql = "SELECT 
                        s.id, 
                        s.nama_lengkap, 
                        s.kelas,
                        s.sekolah_asal,
                        s.status as status_siswa,
                        GROUP_CONCAT(DISTINCT CONCAT(o.hubungan_dengan_siswa, ': ', o.nama_ortu) SEPARATOR '; ') as orangtua_info,
                        COUNT(DISTINCT ps.id) as total_pendaftaran_aktif
                    FROM siswa s
                    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                    LEFT JOIN orangtua o ON so.orangtua_id = o.id
                    LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
                    WHERE s.status = 'aktif' AND s.nama_lengkap LIKE ?
                    GROUP BY s.id
                    ORDER BY s.nama_lengkap
                    LIMIT 10";

            $search_term = "%" . $keyword . "%";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $search_term);
            $stmt->execute();
            $result = $stmt->get_result();
            $results = [];
            while ($row = $result->fetch_assoc()) {
                $results[] = $row;
            }
            $stmt->close();
            echo json_encode($results);
            break;

        case 'get_payment_detail':
            $payment_id = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
            if ($payment_id > 0) {
                $sql_detail = "SELECT 
                    p.*,
                    s.nama_lengkap,
                    s.kelas,
                    s.sekolah_asal,
                    ps.jenis_kelas,
                    ps.tingkat,
                    o.nama_ortu,
                    o.no_hp
                FROM pembayaran p
                JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id
                JOIN siswa s ON ps.siswa_id = s.id
                LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                LEFT JOIN orangtua o ON so.orangtua_id = o.id
                WHERE p.id = ?";
                $stmt_detail = $conn->prepare($sql_detail);
                $stmt_detail->bind_param("i", $payment_id);
                $stmt_detail->execute();
                $result_detail = $stmt_detail->get_result();
                if ($result_detail->num_rows > 0) {
                    $data = $result_detail->fetch_assoc();
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Data tidak ditemukan']);
                }
                $stmt_detail->close();
            } else {
                echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
            }
            break;
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen Pembayaran - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* ==================== STYLE SAMA SEPERTI JADWALSISWA ==================== */
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

        /* Style untuk modal detail - SAMA SEPERTI SEBELUMNYA */
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

        @media (max-width: 767px) {
            .detail-value {
                max-width: 50%;
            }
        }

        /* ==================== DROPDOWN AUTOCOMPLETE FIX ==================== */
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

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 20%;
            right: auto;
            min-width: 100%;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
            margin-top: 4px;
        }

        .autocomplete-clear:hover {
            color: #6b7280;
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

        .selected-siswa-info {
            border-left: 4px solid #3B82F6;
            background-color: #EFF6FF;
            margin-top: 0.5rem;
            border-radius: 0.5rem;
        }

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

        .action-btn {
            padding: 8px;
            border-radius: 8px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #mobileMenu {
            position: fixed;
            left: -100%;
            top: 0;
            width: 85%;
            height: 100%;
            z-index: 1001;
            transition: left 0.3s ease;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
            background-color: #1e40af;
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

        @media (max-width: 767px) {
            .desktop-sidebar {
                display: none;
            }

            .mobile-header {
                display: block;
            }

            .modal-content {
                width: 95%;
                margin: 10px auto;
                max-height: 85vh;
            }
        }

        @media (min-width: 768px) {
            .mobile-header {
                display: none;
            }

            .desktop-sidebar {
                display: block;
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
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center"><i
                        class="fas fa-user-shield"></i></div>
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
                    class="text-white w-12 h-12 flex items-center justify-center rounded-full hover:bg-blue-700">
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
                    class="text-white w-10 h-10 flex items-center justify-center rounded-full hover:bg-blue-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-4 bg-blue-800 border-b border-blue-700">
                <div class="flex items-center">
                    <div class="flex-1">
                        <p class="font-medium text-lg"><?= htmlspecialchars($full_name) ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto py-4"><?= renderMenu($currentPage, 'admin') ?></nav>
        </div>
    </div>

    <!-- Menu Overlay -->
    <div id="menuOverlay" class="menu-overlay"></div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Page Header -->
        <div class="bg-white shadow px-4 py-3 md:p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">
                        <i class="fas fa-credit-card mr-2"></i>Informasi Pembayaran
                    </h1>
                    <p class="text-gray-600 text-sm md:text-base">Input, edit, dan kelola pembayaran siswa dengan
                        tanggal spesifik</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span
                        class="inline-flex items-center px-3 py-1.5 rounded-md text-xs md:text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i><?= date('d/m/Y') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-3 md:p-6">
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

            <!-- Filter Selector dengan Search By Name (SAMA SEPERTI JADWALSISWA) -->
            <div class="bg-white rounded-xl shadow p-4 md:p-5 mb-4 md:mb-6">
                <form method="GET" action="" class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-gray-700">Filter:</span>
                        <div class="flex bg-gray-100 rounded-lg p-1">
                            <button type="button" onclick="setFilter('bulan')"
                                class="px-3 py-1.5 text-sm rounded-md transition <?= $filter_tipe == 'bulan' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-200' ?>">
                                <i class="fas fa-calendar-alt mr-1"></i> Per Bulan
                            </button>
                            <button type="button" onclick="setFilter('tahun')"
                                class="px-3 py-1.5 text-sm rounded-md transition <?= $filter_tipe == 'tahun' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-200' ?>">
                                <i class="fas fa-calendar-year mr-1"></i> Per Tahun
                            </button>
                        </div>
                        <?php if ($filter_tipe == 'bulan'): ?>
                            <input type="month" name="bulan" value="<?= $selected_filter ?>" onchange="this.form.submit()"
                                class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                        <?php else: ?>
                            <select name="tahun" onchange="this.form.submit()"
                                class="border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
                                <?php for ($y = date('Y') - 5; $y <= date('Y') + 2; $y++): ?>
                                    <option value="<?= $y ?>" <?= $selected_filter == $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        <?php endif; ?>

                        <!-- Filter Search Siswa - SAMA SEPERTI JADWALSISWA -->
                        <div class="relative flex-1 min-w-[200px]">
                            <input type="text" id="filterSearchSiswa" name="nama_siswa"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 pl-8 text-sm focus:ring-2 focus:ring-blue-500"
                                placeholder="Ketik nama siswa..." autocomplete="off"
                                value="<?= htmlspecialchars($search_nama) ?>">
                            <input type="hidden" name="siswa_id" id="filterSiswaId" value="<?= $filter_siswa_id ?>">
                            <i
                                class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                            <button type="button" id="clearFilterSearch"
                                class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 <?= ($filter_siswa_id > 0 || !empty($search_nama)) ? '' : 'hidden' ?>">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div id="filterSiswaDropdown"
                            class="absolute top-[180px] left-[650px] z-50 w-90 bg-white mt-100 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                        </div>
                    </div>

                    <input type="hidden" name="filter_tipe" value="<?= $filter_tipe ?>">
                </form>

                <!-- Info hasil pencarian -->
                <div
                    class="mt-3 text-xs md:text-sm text-gray-600 bg-gray-50 px-3 md:px-4 py-2 rounded-lg flex justify-between items-center flex-wrap gap-2">
                    <div>
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                        Menampilkan <?= $total_pembayaran ?> pembayaran
                        <?php if ($filter_tipe == 'bulan'): ?>
                            untuk bulan <span
                                class="font-semibold"><?= date('F Y', strtotime($selected_filter . '-01')) ?></span>
                        <?php else: ?>
                            untuk tahun <span class="font-semibold"><?= $selected_filter ?></span>
                        <?php endif; ?>
                        <?php if (!empty($search_nama)): ?>
                            dengan kata kunci <span
                                class="font-semibold text-blue-600">"<?= htmlspecialchars($search_nama) ?>"</span>
                        <?php endif; ?>
                    </div>
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
                            <h3 class="text-lg md:text-2xl font-bold text-gray-800">
                                Rp <?= number_format($total_dibayar, 0, ',', '.') ?>
                            </h3>
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
                    <div class="flex gap-2">
                        <button onclick="openTambahModal()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center justify-center shadow-md text-sm">
                            <i class="fas fa-plus mr-2"></i> Input Pembayaran Baru
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-3 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Tanggal</th>
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
                                            <p class="text-base md:text-lg font-medium text-gray-400">Belum ada pembayaran
                                            </p>
                                            <?php if (!empty($search_nama)): ?>
                                                <p class="text-sm text-gray-400 mt-2">Tidak ditemukan siswa dengan nama
                                                    "<?= htmlspecialchars($search_nama) ?>"</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pembayaran_list as $p): ?>
                                    <?php
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
                                        <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= $p['tanggal_bayar'] ? date('d/m/Y', strtotime($p['tanggal_bayar'])) : '-' ?>
                                            </div>
                                            <div class="text-xs text-gray-500">Bulan:
                                                <?= date('M Y', strtotime($p['bulan_tagihan'] . '-01')) ?>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4">
                                            <div class="flex items-center">
                                                <div
                                                    class="flex-shrink-0 h-8 w-8 md:h-10 md:w-10 bg-gradient-to-r from-blue-100 to-blue-50 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-graduate text-blue-600 text-sm md:text-base"></i>
                                                </div>
                                                <div class="ml-2 md:ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($p['nama_lengkap']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?= $p['kelas'] ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4 hidden sm:table-cell">
                                            <div class="space-y-1">
                                                <div class="text-sm"><span class="text-gray-500">Tagihan:</span> <span
                                                        class="font-semibold ml-1">Rp
                                                        <?= number_format($p['nominal_tagihan'], 0, ',', '.') ?></span></div>
                                                <div class="text-sm"><span class="text-gray-500">Dibayar:</span> <span
                                                        class="font-semibold ml-1 text-green-600">Rp
                                                        <?= number_format($p['nominal_dibayar'], 0, ',', '.') ?></span></div>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4">
                                            <div class="space-y-1">
                                                <?= $status_badge ?>
                                                <?php if ($p['metode_bayar']): ?>
                                                    <div><?= $metode_badge ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-3 md:px-6 py-3 md:py-4 whitespace-nowrap">
                                            <div class="flex gap-1">
                                                <button onclick="openEditModal(<?= $p['id'] ?>)"
                                                    class="action-btn bg-yellow-50 text-yellow-600 hover:bg-yellow-100 p-2 rounded-lg"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="openDetailModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                    class="action-btn bg-blue-50 text-blue-600 hover:bg-blue-100 p-2 rounded-lg"
                                                    title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button
                                                    onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['nama_lengkap']) ?>')"
                                                    class="action-btn bg-red-50 text-red-600 hover:bg-red-100 p-2 rounded-lg"
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
            </div>
        </div>
    </div>

    <!-- Modal Tambah Pembayaran -->
    <div id="modalTambah" class="modal">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 md:px-6 py-3 md:py-4 rounded-t-lg">
                <h2 class="text-lg md:text-xl font-bold"><i class="fas fa-plus mr-2"></i> Input Pembayaran Baru</h2>
            </div>
            <form method="POST" class="p-4 md:p-6" id="formTambahPembayaran">
                <input type="hidden" name="create_payment" value="1">
                <input type="hidden" name="filter_tipe" value="<?= $filter_tipe ?>">
                <input type="hidden" name="<?= $filter_tipe == 'bulan' ? 'bulan' : 'tahun' ?>"
                    value="<?= $selected_filter ?>">
                <input type="hidden" name="nama_siswa" value="<?= htmlspecialchars($search_nama) ?>">
                <input type="hidden" name="siswa_id" value="<?= $filter_siswa_id ?>">

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Cari Siswa: <span
                            class="text-red-500">*</span></label>
                    <div class="autocomplete-container">
                        <input type="text" id="searchSiswa" class="autocomplete-input" placeholder="Ketik nama siswa..."
                            autocomplete="off">
                        <input type="hidden" name="siswa_id" id="selectedSiswaId">
                        <button type="button" id="clearSearch" class="autocomplete-clear"><i
                                class="fas fa-times"></i></button>
                        <div id="siswaDropdown" class="autocomplete-dropdown"></div>
                    </div>
                    <div id="selectedSiswaInfo" class="selected-siswa-info mt-2 p-3 hidden">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-medium text-gray-900" id="selectedSiswaName"></div>
                                <div class="text-sm text-gray-600">Kelas: <span id="selectedSiswaKelas"></span> |
                                    Sekolah: <span id="selectedSiswaSekolah"></span></div>
                                <div class="text-xs text-gray-500 mt-1" id="selectedSiswaOrangtua"></div>
                                <div class="text-xs text-blue-600 mt-1" id="selectedSiswaPendaftaran"></div>
                            </div>
                            <button type="button" onclick="clearSelectedSiswa()"
                                class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Tanggal Pembayaran: <span
                            class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_bayar" id="tanggalBayar" value="<?= date('Y-m-d') ?>" required
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Status Pembayaran: <span
                            class="text-red-500">*</span></label>
                    <select name="status" id="statusSelect" required onchange="handleStatusChange(this.value)"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($STATUS_PEMBAYARAN as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Nominal Tagihan: <span
                            class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><span
                                class="text-gray-500">Rp</span></div>
                        <input type="text" name="nominal_tagihan" id="nominalTagihan"
                            value="<?= number_format($NOMINAL_DEFAULT, 0, ',', '.') ?>" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Nominal Dibayar: <span
                            class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><span
                                class="text-gray-500">Rp</span></div>
                        <input type="text" name="nominal_dibayar" id="nominalDibayar"
                            value="<?= number_format($NOMINAL_DEFAULT, 0, ',', '.') ?>" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Metode Bayar:</label>
                    <select name="metode_bayar" id="metodeBayar"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-blue-500">
                        <option value="">- Pilih Metode Bayar -</option>
                        <?php foreach ($METODE_BAYAR as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Keterangan:</label>
                    <textarea name="keterangan" rows="2"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-blue-500"
                        placeholder="Catatan..."></textarea>
                </div>

                <div class="flex justify-end space-x-2 mt-4">
                    <button type="button" onclick="closeTambahModal()"
                        class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"><i
                            class="fas fa-save mr-2"></i> Simpan Pembayaran</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Pembayaran -->
    <div id="modalEdit" class="modal">
        <div class="modal-content">
            <div
                class="bg-gradient-to-r from-yellow-500 to-yellow-600 text-white px-4 md:px-6 py-3 md:py-4 rounded-t-lg">
                <h2 class="text-lg md:text-xl font-bold"><i class="fas fa-edit mr-2"></i> Edit Pembayaran</h2>
            </div>
            <form method="POST" class="p-4 md:p-6" id="formEditPembayaran">
                <input type="hidden" name="update_payment" value="1">
                <input type="hidden" name="pembayaran_id" id="editPembayaranId">
                <input type="hidden" name="filter_tipe" value="<?= $filter_tipe ?>">
                <input type="hidden" name="<?= $filter_tipe == 'bulan' ? 'bulan' : 'tahun' ?>"
                    value="<?= $selected_filter ?>">
                <input type="hidden" name="nama_siswa" value="<?= htmlspecialchars($search_nama) ?>">
                <input type="hidden" name="siswa_id" value="<?= $filter_siswa_id ?>">

                <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                    <label class="block text-gray-700 mb-1 font-medium">Informasi Siswa</label>
                    <div class="font-medium text-gray-900" id="editNamaSiswa">-</div>
                    <div class="text-sm text-gray-600" id="editInfoSiswa">-</div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Tanggal Pembayaran: <span
                            class="text-red-500">*</span></label>
                    <input type="date" name="tanggal_bayar" id="editTanggalBayar" required
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-yellow-500">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Status Pembayaran: <span
                            class="text-red-500">*</span></label>
                    <select name="status" id="editStatusSelect" required onchange="handleEditStatusChange(this.value)"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-yellow-500">
                        <?php foreach ($STATUS_PEMBAYARAN as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Nominal Tagihan: <span
                            class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><span
                                class="text-gray-500">Rp</span></div>
                        <input type="text" name="nominal_tagihan" id="editNominalTagihan" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-yellow-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Nominal Dibayar: <span
                            class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><span
                                class="text-gray-500">Rp</span></div>
                        <input type="text" name="nominal_dibayar" id="editNominalDibayar" required
                            oninput="formatCurrency(this)"
                            class="w-full border rounded-lg pl-10 pr-3 py-2.5 focus:ring-2 focus:ring-yellow-500">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Metode Bayar:</label>
                    <select name="metode_bayar" id="editMetodeBayar"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-yellow-500">
                        <option value="">- Pilih Metode Bayar -</option>
                        <?php foreach ($METODE_BAYAR as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 mb-1 font-medium">Keterangan:</label>
                    <textarea name="keterangan" id="editKeterangan" rows="2"
                        class="w-full border rounded-lg px-3 md:px-4 py-2.5 focus:ring-2 focus:ring-yellow-500"
                        placeholder="Catatan..."></textarea>
                </div>

                <div class="flex justify-end space-x-2 mt-4">
                    <button type="button" onclick="closeEditModal()"
                        class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Batal</button>
                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700"><i
                            class="fas fa-save mr-2"></i> Update Pembayaran</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Detail -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div
                class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-4 md:px-6 py-3 md:py-4 rounded-t-lg flex justify-between items-center">
                <h2 class="text-lg md:text-xl font-bold">
                    <i class="fas fa-info-circle mr-2"></i> Detail Pembayaran
                </h2>
                <button onclick="closeDetailModal()" class="text-white hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-4 md:p-6">
                <!-- Status & Tanggal -->
                <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-gray-600">Status:</span>
                        <span id="detailStatusBadge" class="font-semibold"></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-gray-600">Bulan Tagihan:</span>
                        <span id="detailBulan" class="font-medium"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Tanggal Bayar:</span>
                        <span id="detailTanggalBayar" class="font-medium"></span>
                    </div>
                </div>

                <!-- Informasi Siswa -->
                <div class="mb-4">
                    <h3 class="font-medium text-gray-700 mb-2 border-b pb-1">
                        <i class="fas fa-user-graduate mr-2 text-blue-500"></i>Informasi Siswa
                    </h3>
                    <div class="space-y-1">
                        <div class="detail-item">
                            <span class="detail-label">Nama Lengkap</span>
                            <span id="detailNamaLengkap" class="detail-value"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Kelas</span>
                            <span id="detailKelas" class="detail-value"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Sekolah Asal</span>
                            <span id="detailSekolah" class="detail-value"></span>
                        </div>
                    </div>
                </div>

                <!-- Informasi Pembayaran -->
                <div class="mb-4">
                    <h3 class="font-medium text-gray-700 mb-2 border-b pb-1">
                        <i class="fas fa-credit-card mr-2 text-purple-500"></i>Informasi Pembayaran
                    </h3>
                    <div class="space-y-1">
                        <div class="detail-item">
                            <span class="detail-label">Nominal Tagihan</span>
                            <span id="detailNominalTagihan" class="detail-value font-semibold"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Nominal Dibayar</span>
                            <span id="detailNominalDibayar" class="detail-value"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Sisa Tagihan</span>
                            <span id="detailSisaTagihan" class="detail-value"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Metode Bayar</span>
                            <span id="detailMetodeBayar" class="detail-value"></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Keterangan</span>
                            <span id="detailKeterangan" class="detail-value"></span>
                        </div>
                    </div>
                </div>

                <!-- Tombol Tutup -->
                <div class="flex justify-end mt-4 pt-3 border-t">
                    <button onclick="closeDetailModal()"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <i class="fas fa-times mr-2"></i>Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ==================== VARIABEL GLOBAL ====================
        let searchTimeout;
        let filterSearchTimeout;
        let filterSelectedIndex = -1;
        let semuaSiswa = [];

        // ==================== FUNGSI FILTER ====================
        function setFilter(tipe) {
            let url = 'pembayaran.php?filter_tipe=' + tipe;
            if (tipe === 'bulan') {
                url += '&bulan=<?= date('Y-m') ?>';
            } else {
                url += '&tahun=<?= date('Y') ?>';
            }
            window.location.href = url;
        }

        // ==================== FILTER SEARCH SISWA (SAMA SEPERTI JADWALSISWA) ====================
        function initFilterSearch() {
            const searchInput = document.getElementById('filterSearchSiswa');
            const clearButton = document.getElementById('clearFilterSearch');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const siswaIdInput = document.getElementById('filterSiswaId');

            if (!searchInput || !clearButton || !dropdown || !siswaIdInput) return;

            searchInput.addEventListener('input', function () {
                clearTimeout(filterSearchTimeout);
                const query = this.value.trim();

                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    clearButton.classList.add('hidden');
                    return;
                }

                filterSearchTimeout = setTimeout(() => {
                    searchSiswaList(query);
                }, 300);
                clearButton.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', function () {
                if (this.value.length >= 2) {
                    searchSiswaList(this.value);
                }
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                siswaIdInput.value = '';
                clearButton.classList.add('hidden');
                dropdown.classList.add('hidden');
                // Submit form untuk reset filter
                document.querySelector('form[method="GET"]').submit();
            });

            // Keyboard navigation
            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.filter-siswa-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        filterSelectedIndex = Math.min(filterSelectedIndex + 1, items.length - 1);
                        updateFilterSelectedItem(items);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        filterSelectedIndex = Math.max(filterSelectedIndex - 1, -1);
                        updateFilterSelectedItem(items);
                        break;
                    case 'Enter':
                        e.preventDefault();
                        if (filterSelectedIndex >= 0 && items[filterSelectedIndex]) {
                            selectFilterSiswa(items[filterSelectedIndex].dataset);
                        }
                        break;
                    case 'Escape':
                        dropdown.classList.add('hidden');
                        filterSelectedIndex = -1;
                        break;
                }
            });

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        function searchSiswaList(query) {
            $.ajax({
                url: 'pembayaran.php',
                type: 'GET',
                data: {
                    ajax: 'search_siswa',
                    keyword: query
                },
                dataType: 'json',
                success: function (data) {
                    renderFilterDropdown(data);
                },
                error: function () {
                    const dropdown = document.getElementById('filterSiswaDropdown');
                    dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Gagal memuat data</div>';
                    dropdown.classList.remove('hidden');
                }
            });
        }

        function renderFilterDropdown(data) {
            const dropdown = document.getElementById('filterSiswaDropdown');
            if (!dropdown) return;

            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500 text-center">Tidak ada siswa ditemukan</div>';
                dropdown.classList.remove('hidden');
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'filter-siswa-item px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';
                if (index === 0) item.classList.add('bg-blue-50');
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas || '-';
                item.dataset.sekolah = siswa.sekolah_asal || '-';
                item.dataset.orangtua = siswa.orangtua_info || '';

                item.innerHTML = `
                    <div class="font-medium text-gray-900">${siswa.nama_lengkap}</div>
                    <div class="text-xs text-gray-600 mt-1">
                        Kelas: ${siswa.kelas || '-'} | Sekolah: ${siswa.sekolah_asal || '-'}
                        ${siswa.orangtua_info ? '<br>Orang Tua: ' + siswa.orangtua_info : ''}
                    </div>
                `;

                item.addEventListener('click', function () {
                    selectFilterSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.filter-siswa-item');
                    filterSelectedIndex = Array.from(items).indexOf(this);
                    updateFilterSelectedItem(items);
                });

                dropdown.appendChild(item);
            });

            dropdown.classList.remove('hidden');
            filterSelectedIndex = 0;
        }

        function updateFilterSelectedItem(items) {
            items.forEach((item, i) => {
                if (i === filterSelectedIndex) {
                    item.classList.add('bg-blue-50');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('bg-blue-50');
                }
            });
        }

        function selectFilterSiswa(data) {
            const searchInput = document.getElementById('filterSearchSiswa');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const clearButton = document.getElementById('clearFilterSearch');

            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');

            // Submit form untuk filter
            document.querySelector('form[method="GET"]').submit();
        }

        // ==================== AUTOCOMPLETE UNTUK MODAL TAMBAH ====================
        function initAutocomplete() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSearch');
            const dropdown = document.getElementById('siswaDropdown');
            let selectedIndex = -1;

            if (!searchInput) return;

            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) filterSiswa(this.value);
            });

            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimeout);
                const query = this.value;

                if (query.length < 2) {
                    dropdown.style.display = 'none';
                    clearButton.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(() => filterSiswa(query), 300);
                clearButton.style.display = query.length > 0 ? 'block' : 'none';
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                clearButton.style.display = 'none';
                dropdown.style.display = 'none';
                clearSelectedSiswa();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelectedItem(dropdown, items, selectedIndex);
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem(dropdown, items, selectedIndex);
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

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');
            dropdown.innerHTML = '<div class="no-results"><span class="spinner"></span> Mencari...</div>';
            dropdown.style.display = 'block';

            $.ajax({
                url: 'pembayaran.php',
                type: 'GET',
                data: {
                    ajax: 'search_siswa',
                    keyword: query
                },
                dataType: 'json',
                success: function (data) {
                    renderSiswaDropdown(data);
                },
                error: function () {
                    dropdown.innerHTML = '<div class="no-results">Gagal memuat data</div>';
                }
            });
        }

        function renderSiswaDropdown(data) {
            const dropdown = document.getElementById('siswaDropdown');
            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="no-results">Tidak ditemukan siswa</div>';
                dropdown.style.display = 'block';
                return;
            }

            data.forEach(siswa => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;
                item.dataset.kelas = siswa.kelas;
                item.dataset.sekolah = siswa.sekolah_asal || '';
                item.dataset.orangtua = siswa.orangtua_info || '';
                item.dataset.totalPendaftaran = siswa.total_pendaftaran_aktif || '0';

                const statusBadge = siswa.status_siswa === 'aktif'
                    ? '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:5px;">Aktif</span>'
                    : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:5px;">Non-Aktif</span>';
                const pendaftaranWarning = parseInt(siswa.total_pendaftaran_aktif) === 0
                    ? '<div style="color:#dc2626;font-size:11px;margin-top:2px;"><i class="fas fa-exclamation-triangle"></i> Tidak ada pendaftaran aktif</div>'
                    : '';

                item.innerHTML = `
                    <div class="siswa-nama">${siswa.nama_lengkap} ${statusBadge}</div>
                    <div class="siswa-info">
                        Kelas: ${siswa.kelas} | Sekolah: ${siswa.sekolah_asal || '-'}
                        ${siswa.orangtua_info ? '<br>Orang Tua: ' + siswa.orangtua_info : ''}
                        ${pendaftaranWarning}
                    </div>
                `;
                item.addEventListener('click', () => selectSiswa(item.dataset));
                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
        }

        function updateSelectedItem(dropdown, items, index) {
            items.forEach((item, i) => {
                if (i === index) item.classList.add('active');
                else item.classList.remove('active');
            });
        }

        function selectSiswa(data) {
            document.getElementById('selectedSiswaId').value = data.id;
            document.getElementById('searchSiswa').value = data.nama;
            document.getElementById('siswaDropdown').style.display = 'none';
            document.getElementById('selectedSiswaName').textContent = data.nama;
            document.getElementById('selectedSiswaKelas').textContent = data.kelas;
            document.getElementById('selectedSiswaSekolah').textContent = data.sekolah || '-';
            if (data.orangtua) document.getElementById('selectedSiswaOrangtua').textContent = 'Orang Tua: ' + data.orangtua;
            if (parseInt(data.totalPendaftaran) === 0) {
                document.getElementById('selectedSiswaPendaftaran').innerHTML = '<span style="color:#dc2626;"><i class="fas fa-exclamation-triangle mr-1"></i>Tidak ada pendaftaran aktif!</span>';
            }
            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        function clearSelectedSiswa() {
            document.getElementById('searchSiswa').value = '';
            document.getElementById('selectedSiswaId').value = '';
            document.getElementById('selectedSiswaInfo').classList.add('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        // ==================== FUNGSI MODAL ====================
        function openTambahModal() {
            document.getElementById('modalTambah').style.display = 'block';
            document.body.style.overflow = 'hidden';
            clearSelectedSiswa();
            handleStatusChange('belum_bayar');
            document.getElementById('tanggalBayar').value = new Date().toISOString().split('T')[0];
            setTimeout(() => { document.getElementById('searchSiswa').focus(); }, 100);
        }

        function closeTambahModal() {
            document.getElementById('modalTambah').style.display = 'none';
            document.body.style.overflow = 'auto';
            document.getElementById('formTambahPembayaran').reset();
            clearSelectedSiswa();
        }

        function openEditModal(paymentId) {
            document.getElementById('modalEdit').style.display = 'block';
            document.body.style.overflow = 'hidden';

            $.ajax({
                url: 'pembayaran.php',
                type: 'GET',
                data: { ajax: 'get_payment_detail', payment_id: paymentId },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        const data = response.data;
                        document.getElementById('editPembayaranId').value = data.id;
                        document.getElementById('editNamaSiswa').textContent = data.nama_lengkap;
                        document.getElementById('editInfoSiswa').textContent = 'Kelas: ' + data.kelas + ' | Sekolah: ' + (data.sekolah_asal || '-');
                        document.getElementById('editTanggalBayar').value = data.tanggal_bayar || new Date().toISOString().split('T')[0];
                        document.getElementById('editStatusSelect').value = data.status;
                        document.getElementById('editNominalTagihan').value = formatNumberDisplay(data.nominal_tagihan);
                        document.getElementById('editNominalDibayar').value = formatNumberDisplay(data.nominal_dibayar);
                        document.getElementById('editMetodeBayar').value = data.metode_bayar || '';
                        document.getElementById('editKeterangan').value = data.keterangan || '';
                        handleEditStatusChange(data.status);
                    } else {
                        alert('Gagal mengambil data: ' + response.error);
                        closeEditModal();
                    }
                },
                error: function () {
                    alert('Terjadi kesalahan saat mengambil data');
                    closeEditModal();
                }
            });
        }

        function closeEditModal() {
            document.getElementById('modalEdit').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function openDetailModal(paymentData) {
            let statusBadge = '';
            switch (paymentData.status) {
                case 'lunas': statusBadge = '<span class="badge badge-success">LUNAS</span>'; break;
                case 'belum_bayar': statusBadge = '<span class="badge badge-danger">BELUM BAYAR</span>'; break;
                case 'dibebaskan': statusBadge = '<span class="badge badge-warning">DIBEBASKAN</span>'; break;
            }
            document.getElementById('detailStatusBadge').innerHTML = statusBadge;
            document.getElementById('detailBulan').textContent = formatMonth(paymentData.bulan_tagihan);
            document.getElementById('detailTanggalBayar').textContent = paymentData.tgl_bayar_formatted || '-';
            document.getElementById('detailNamaLengkap').textContent = paymentData.nama_lengkap;
            document.getElementById('detailKelas').textContent = paymentData.kelas;
            document.getElementById('detailSekolah').textContent = paymentData.sekolah_asal || '-';
            document.getElementById('detailNominalTagihan').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.nominal_tagihan);
            document.getElementById('detailNominalDibayar').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.nominal_dibayar);
            document.getElementById('detailSisaTagihan').textContent = 'Rp ' + new Intl.NumberFormat('id-ID').format(paymentData.sisa_tagihan);
            let metodeText = '-';
            if (paymentData.metode_bayar) {
                const metodeLabels = { 'cash': 'Cash', 'transfer': 'Transfer Bank', 'qris': 'QRIS', 'debit': 'Kartu Debit', 'credit': 'Kartu Kredit', 'ewallet': 'E-Wallet' };
                metodeText = metodeLabels[paymentData.metode_bayar] || paymentData.metode_bayar;
            }
            document.getElementById('detailMetodeBayar').textContent = metodeText;
            document.getElementById('detailKeterangan').textContent = paymentData.keterangan || '-';
            document.getElementById('detailModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeDetailModal() {
            document.getElementById('detailModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function confirmDelete(id, name) {
            if (confirm('Hapus pembayaran untuk ' + name + '? Tindakan ini tidak dapat dibatalkan!')) {
                let url = 'pembayaran.php?filter_tipe=<?= $filter_tipe ?>&<?= $filter_tipe == 'bulan' ? 'bulan' : 'tahun' ?>=<?= $selected_filter ?>&delete_id=' + id;
                <?php if (!empty($search_nama)): ?>
                    url += '&nama_siswa=<?= urlencode($search_nama) ?>';
                <?php endif; ?>
                <?php if ($filter_siswa_id > 0): ?>
                    url += '&siswa_id=<?= $filter_siswa_id ?>';
                <?php endif; ?>
                window.location.href = url;
            }
        }

        function handleEditStatusChange(status) {
            const nominalDibayar = document.getElementById('editNominalDibayar');
            const metodeBayar = document.getElementById('editMetodeBayar');
            const nominalTagihan = document.getElementById('editNominalTagihan');

            if (status === 'lunas') {
                const tagihanValue = nominalTagihan.value.replace(/\./g, '');
                nominalDibayar.value = formatNumberDisplay(tagihanValue);
                metodeBayar.required = true;
            } else if (status === 'dibebaskan') {
                nominalDibayar.value = '0';
                metodeBayar.value = '';
                metodeBayar.required = false;
            } else {
                metodeBayar.required = false;
            }
        }

        // ==================== FUNGSI BANTU ====================
        function formatCurrency(input) {
            let value = input.value.replace(/\D/g, '');
            if (value) value = parseInt(value, 10).toLocaleString('id-ID');
            input.value = value;
        }

        function formatNumberDisplay(num) {
            return new Intl.NumberFormat('id-ID').format(num);
        }

        function formatMonth(monthStr) {
            if (!monthStr) return '-';
            const date = new Date(monthStr + '-01');
            return date.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
        }

        function handleStatusChange(status) {
            const nominalTagihan = document.getElementById('nominalTagihan');
            const nominalDibayar = document.getElementById('nominalDibayar');
            const metodeBayar = document.getElementById('metodeBayar');

            if (status === 'lunas') {
                const tagihanValue = nominalTagihan.value.replace(/\./g, '');
                nominalDibayar.value = formatNumberDisplay(tagihanValue);
                metodeBayar.required = true;
            } else if (status === 'dibebaskan') {
                nominalDibayar.value = '0';
                metodeBayar.value = '';
                metodeBayar.required = false;
            } else {
                metodeBayar.required = false;
            }
        }

        // ==================== FORM VALIDATIONS ====================
        document.getElementById('formTambahPembayaran')?.addEventListener('submit', function (e) {
            if (!document.getElementById('selectedSiswaId').value) {
                e.preventDefault();
                alert('Harap pilih siswa terlebih dahulu!');
                document.getElementById('searchSiswa').focus();
                return;
            }
            const tagihan = this.querySelector('input[name="nominal_tagihan"]');
            const dibayar = this.querySelector('input[name="nominal_dibayar"]');
            if (tagihan && tagihan.value) tagihan.value = tagihan.value.replace(/\./g, '').replace(/,/g, '.');
            if (dibayar && dibayar.value) dibayar.value = dibayar.value.replace(/\./g, '').replace(/,/g, '.');
            if (document.getElementById('statusSelect').value === 'lunas' && !document.getElementById('metodeBayar').value) {
                e.preventDefault();
                alert('Untuk status LUNAS, metode bayar wajib diisi!');
                document.getElementById('metodeBayar').focus();
                return;
            }
        });

        document.getElementById('formEditPembayaran')?.addEventListener('submit', function (e) {
            const tagihan = this.querySelector('input[name="nominal_tagihan"]');
            const dibayar = this.querySelector('input[name="nominal_dibayar"]');
            if (tagihan && tagihan.value) tagihan.value = tagihan.value.replace(/\./g, '').replace(/,/g, '.');
            if (dibayar && dibayar.value) dibayar.value = dibayar.value.replace(/\./g, '').replace(/,/g, '.');
            if (document.getElementById('editStatusSelect').value === 'lunas' && !document.getElementById('editMetodeBayar').value) {
                e.preventDefault();
                alert('Untuk status LUNAS, metode bayar wajib diisi!');
                document.getElementById('editMetodeBayar').focus();
                return;
            }
        });

        // ==================== MOBILE MENU ====================
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

        // ==================== CLOSE MODAL ON ESCAPE ====================
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTambahModal();
                closeEditModal();
                closeDetailModal();
            }
        });

        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    if (this.id === 'modalTambah') closeTambahModal();
                    if (this.id === 'modalEdit') closeEditModal();
                    if (this.id === 'detailModal') closeDetailModal();
                }
            });
        });

        // ==================== DROPDOWN FUNCTIONALITY ====================
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const submenu = this.closest('.mb-1').querySelector('.dropdown-submenu');
                const arrow = this.querySelector('.arrow');
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => sm.style.display = 'none');
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

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(submenu => submenu.style.display = 'none');
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('open');
                    toggle.querySelector('.arrow').style.transform = 'rotate(0deg)';
                });
            }
        });

        // ==================== INITIALIZATION ====================
        $(document).ready(function () {
            initAutocomplete();
            initFilterSearch();
        });
    </script>
</body>

</html>