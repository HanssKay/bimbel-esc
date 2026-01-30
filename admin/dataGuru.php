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
$guru_data = [];
$guru_detail = null;
$guru_edit = null;

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_bidang = isset($_GET['filter_bidang']) ? $_GET['filter_bidang'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';

// DETAIL GURU
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $guru_id = intval($_GET['id']);

    // Query untuk data guru
    $sql = "SELECT g.*, u.username, u.email, u.full_name, u.phone, u.address, u.is_active, 
                   u.created_at as user_created_at
            FROM guru g
            JOIN users u ON g.user_id = u.id
            WHERE g.id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error preparing guru query: " . $conn->error);
        $_SESSION['error_message'] = "❌ Error dalam query database!";
        header('Location: dataGuru.php');
        exit();
    }
    
    $stmt->bind_param("i", $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Ambil data siswa yang diajar (melalui siswa_pelajaran)
        $siswa_sql = "SELECT DISTINCT s.id, s.nama_lengkap, s.kelas, sp.nama_pelajaran, ps.tingkat
                      FROM siswa_pelajaran sp
                      JOIN siswa s ON sp.siswa_id = s.id
                      JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                      WHERE sp.guru_id = ? AND sp.status = 'aktif' AND ps.status = 'aktif'
                      ORDER BY s.nama_lengkap";

        $siswa_stmt = $conn->prepare($siswa_sql);
        if ($siswa_stmt) {
            $siswa_stmt->bind_param("i", $guru_id);
            $siswa_stmt->execute();
            $siswa_result = $siswa_stmt->get_result();

            $siswa_mengajar = [];
            while ($siswa_row = $siswa_result->fetch_assoc()) {
                $siswa_mengajar[] = $siswa_row;
            }
            $siswa_stmt->close();
        } else {
            error_log("Error preparing siswa query: " . $conn->error);
            $siswa_mengajar = [];
        }

        // Ambil data penilaian yang diberikan
        $penilaian_sql = "SELECT ps.*, s.nama_lengkap as nama_siswa, sp.nama_pelajaran
                         FROM penilaian_siswa ps
                         JOIN siswa s ON ps.siswa_id = s.id
                         LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
                         WHERE ps.guru_id = ?
                         ORDER BY ps.tanggal_penilaian DESC LIMIT 5";

        $penilaian_stmt = $conn->prepare($penilaian_sql);
        if ($penilaian_stmt) {
            $penilaian_stmt->bind_param("i", $guru_id);
            $penilaian_stmt->execute();
            $penilaian_result = $penilaian_stmt->get_result();

            $penilaian_diberikan = [];
            while ($penilaian_row = $penilaian_result->fetch_assoc()) {
                $penilaian_diberikan[] = $penilaian_row;
            }
            $penilaian_stmt->close();
        } else {
            error_log("Error preparing penilaian query: " . $conn->error);
            $penilaian_diberikan = [];
        }

        // Ambil data jadwal mengajar - PERBAIKAN BESAR DI SINI
        $jadwal_sql = "SELECT 
                        jb.id as jadwal_id,
                        smg.hari,
                        smg.jam_mulai,
                        smg.jam_selesai,
                        s.nama_lengkap,
                        sp.nama_pelajaran,
                        ps.tingkat,
                        DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
                        DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format
                      FROM jadwal_belajar jb
                      JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                      JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                      JOIN siswa s ON sp.siswa_id = s.id
                      JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                      WHERE sp.guru_id = ? 
                        AND jb.status = 'aktif'
                        AND smg.guru_id = ?
                      ORDER BY 
                        FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
                        smg.jam_mulai";

        $jadwal_stmt = $conn->prepare($jadwal_sql);
        if ($jadwal_stmt) {
            $jadwal_stmt->bind_param("ii", $guru_id, $guru_id);
            $jadwal_stmt->execute();
            $jadwal_result = $jadwal_stmt->get_result();

            $jadwal_mengajar = [];
            while ($jadwal_row = $jadwal_result->fetch_assoc()) {
                $jadwal_mengajar[] = $jadwal_row;
            }
            $jadwal_stmt->close();
        } else {
            error_log("Error preparing jadwal query: " . $conn->error);
            $jadwal_mengajar = [];
        }

        $row['siswa_mengajar'] = $siswa_mengajar;
        $row['penilaian_diberikan'] = $penilaian_diberikan;
        $row['jadwal_mengajar'] = $jadwal_mengajar;
        $guru_detail = $row;
    }
    $stmt->close();
}

// EDIT GURU - LOAD DATA
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $guru_id = intval($_GET['id']);

    // Load data guru
    $sql = "SELECT g.*, u.username, u.email, u.full_name, u.phone, u.address
            FROM guru g
            JOIN users u ON g.user_id = u.id
            WHERE g.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $guru_edit = $row;
    }
    $stmt->close();
}

// UPDATE GURU
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_guru'])) {
    $guru_id = intval($_POST['guru_id']);
    $full_name_input = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $bidang_keahlian = trim($_POST['bidang_keahlian']);
    $pendidikan_terakhir = $_POST['pendidikan_terakhir'];
    $pengalaman_tahun = intval($_POST['pengalaman_tahun']);
    $status = $_POST['status'];
    $new_password = $_POST['new_password'] ?? '';

    // Validasi email unik (kecuali untuk guru ini)
    $check_email_sql = "SELECT u.id 
                       FROM users u 
                       JOIN guru g ON u.id = g.user_id 
                       WHERE u.email = ? AND g.id != ?";
    $check_email_stmt = $conn->prepare($check_email_sql);
    $check_email_stmt->bind_param("si", $email, $guru_id);
    $check_email_stmt->execute();

    if ($check_email_stmt->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "❌ Email '$email' sudah digunakan oleh guru lain!";
        header('Location: dataGuru.php?action=edit&id=' . $guru_id);
        exit();
    }
    $check_email_stmt->close();

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // Ambil user_id dari guru
        $get_user_sql = "SELECT user_id FROM guru WHERE id = ?";
        $get_user_stmt = $conn->prepare($get_user_sql);
        $get_user_stmt->bind_param("i", $guru_id);
        $get_user_stmt->execute();
        $result = $get_user_stmt->get_result();
        $guru_data = $result->fetch_assoc();
        $user_id = $guru_data['user_id'];
        $get_user_stmt->close();

        // Update data users
        $update_user_sql = "UPDATE users SET 
                           email = ?,
                           full_name = ?,
                           phone = ?,
                           address = ?,
                           updated_at = NOW()";

        $params = [$email, $full_name_input, $phone, $address];
        $param_types = "ssss";

        if (!empty($new_password)) {
            if (strlen($new_password) < 6) {
                throw new Exception("Password minimal 6 karakter!");
            }
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_user_sql .= ", password = ?";
            $params[] = $hashed_password;
            $param_types .= "s";
        }

        $update_user_sql .= " WHERE id = ?";
        $params[] = $user_id;
        $param_types .= "i";

        $update_user_stmt = $conn->prepare($update_user_sql);
        $update_user_stmt->bind_param($param_types, ...$params);

        if (!$update_user_stmt->execute()) {
            throw new Exception("Gagal memperbarui data user!");
        }
        $update_user_stmt->close();

        // Update data guru
        $update_guru_sql = "UPDATE guru SET 
                           bidang_keahlian = ?,
                           pendidikan_terakhir = ?,
                           pengalaman_tahun = ?,
                           status = ?
                           WHERE id = ?";

        $update_guru_stmt = $conn->prepare($update_guru_sql);
        $update_guru_stmt->bind_param(
            "ssisi",
            $bidang_keahlian,
            $pendidikan_terakhir,
            $pengalaman_tahun,
            $status,
            $guru_id
        );

        if (!$update_guru_stmt->execute()) {
            throw new Exception("Gagal memperbarui data guru!");
        }
        $update_guru_stmt->close();

        $conn->commit();

        $pesan = "✅ Data guru berhasil diperbarui!";
        if (!empty($new_password)) {
            $pesan .= " Password telah diubah.";
        }

        $_SESSION['success_message'] = $pesan;
        header('Location: dataGuru.php?action=detail&id=' . $guru_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal memperbarui data guru: " . $e->getMessage();
        header('Location: dataGuru.php?action=edit&id=' . $guru_id);
        exit();
    }
}

// HAPUS GURU
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $guru_id = intval($_GET['id']);

    // Cek apakah guru memiliki siswa yang diajar (melalui siswa_pelajaran)
    $check_sql = "SELECT COUNT(*) as total FROM siswa_pelajaran WHERE guru_id = ? AND status = 'aktif'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $guru_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $has_siswa = $result->fetch_assoc()['total'] > 0;
    $check_stmt->close();

    if ($has_siswa) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus guru yang masih mengajar siswa aktif!";
        header('Location: dataGuru.php');
        exit();
    }

    // Cek apakah guru memiliki penilaian
    $check_penilaian_sql = "SELECT COUNT(*) as total FROM penilaian_siswa WHERE guru_id = ?";
    $check_penilaian_stmt = $conn->prepare($check_penilaian_sql);
    $check_penilaian_stmt->bind_param("i", $guru_id);
    $check_penilaian_stmt->execute();
    $result = $check_penilaian_stmt->get_result();
    $has_penilaian = $result->fetch_assoc()['total'] > 0;
    $check_penilaian_stmt->close();

    if ($has_penilaian) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus guru yang sudah memberikan penilaian!";
        header('Location: dataGuru.php');
        exit();
    }

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // Ambil user_id dari guru
        $get_sql = "SELECT user_id FROM guru WHERE id = ?";
        $get_stmt = $conn->prepare($get_sql);
        $get_stmt->bind_param("i", $guru_id);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $guru_data = $result->fetch_assoc();
        $user_id = $guru_data['user_id'];
        $get_stmt->close();

        // Hapus guru
        $sql1 = "DELETE FROM guru WHERE id = ?";
        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param("i", $guru_id);
        $stmt1->execute();
        $stmt1->close();

        // Hapus user
        $sql2 = "DELETE FROM users WHERE id = ? AND role = 'guru'";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $stmt2->close();

        $conn->commit();
        $_SESSION['success_message'] = "✅ Data guru berhasil dihapus!";

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal menghapus data guru: " . $e->getMessage();
    }

    header('Location: dataGuru.php');
    exit();
}

// TAMBAH GURU BARU
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_guru'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $full_name_input = trim($_POST['full_name']);
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = trim($_POST['password']);
    $bidang_keahlian = trim($_POST['bidang_keahlian']);
    $pendidikan_terakhir = $_POST['pendidikan_terakhir'];
    $pengalaman_tahun = intval($_POST['pengalaman_tahun'] ?? 0);
    $status = $_POST['status'];
    $tanggal_bergabung = $_POST['tanggal_bergabung'] ?? date('Y-m-d');

    // Validasi
    $errors = [];
    if (empty($username)) $errors[] = "Username harus diisi!";
    if (empty($email)) $errors[] = "Email harus diisi!";
    if (empty($full_name_input)) $errors[] = "Nama lengkap harus diisi!";
    if (empty($password)) $errors[] = "Password harus diisi!";
    if (strlen($password) < 6) $errors[] = "Password minimal 6 karakter!";
    if (empty($bidang_keahlian)) $errors[] = "Bidang keahlian harus diisi!";

    if (!empty($errors)) {
        $_SESSION['error_message'] = "❌ " . implode(" ", $errors);
        header('Location: dataGuru.php?action=tambah');
        exit();
    }

    // Validasi username unik
    $check_username_sql = "SELECT id FROM users WHERE username = ?";
    $check_username_stmt = $conn->prepare($check_username_sql);
    $check_username_stmt->bind_param("s", $username);
    $check_username_stmt->execute();

    if ($check_username_stmt->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "❌ Username '$username' sudah terdaftar!";
        header('Location: dataGuru.php?action=tambah');
        exit();
    }
    $check_username_stmt->close();

    // Validasi email unik
    $check_email_sql = "SELECT id FROM users WHERE email = ?";
    $check_email_stmt = $conn->prepare($check_email_sql);
    $check_email_stmt->bind_param("s", $email);
    $check_email_stmt->execute();

    if ($check_email_stmt->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "❌ Email '$email' sudah terdaftar!";
        header('Location: dataGuru.php?action=tambah');
        exit();
    }
    $check_email_stmt->close();

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert user
        $user_sql = "INSERT INTO users (username, password, email, role, full_name, phone, address, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, 'guru', ?, ?, ?, 1, NOW(), NOW())";

        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("ssssss", $username, $hashed_password, $email, $full_name_input, $phone, $address);

        if (!$user_stmt->execute()) {
            throw new Exception("Gagal membuat user guru!");
        }

        $user_id = $conn->insert_id;
        $user_stmt->close();

        // Insert guru
        $guru_sql = "INSERT INTO guru (user_id, bidang_keahlian, pendidikan_terakhir, 
                      pengalaman_tahun, status, tanggal_bergabung)
                    VALUES (?, ?, ?, ?, ?, ?)";

        $guru_stmt = $conn->prepare($guru_sql);
        $guru_stmt->bind_param(
            "ississ",
            $user_id,
            $bidang_keahlian,
            $pendidikan_terakhir,
            $pengalaman_tahun,
            $status,
            $tanggal_bergabung
        );

        if (!$guru_stmt->execute()) {
            throw new Exception("Gagal membuat data guru!");
        }

        $guru_id = $conn->insert_id;
        $guru_stmt->close();

        $conn->commit();

        $_SESSION['success_message'] = "✅ Data guru berhasil ditambahkan!";
        header('Location: dataGuru.php?action=detail&id=' . $guru_id);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal menambahkan data guru: " . $e->getMessage();
        header('Location: dataGuru.php?action=tambah');
        exit();
    }
}

// AMBIL DATA GURU DENGAN FILTER
$sql = "SELECT g.*, u.username, u.email, u.full_name, u.phone, u.is_active,
               (SELECT COUNT(DISTINCT sp.siswa_id) 
                FROM siswa_pelajaran sp 
                WHERE sp.guru_id = g.id AND sp.status = 'aktif') as jumlah_siswa,
               (SELECT COUNT(*) 
                FROM penilaian_siswa ps 
                WHERE ps.guru_id = g.id) as jumlah_penilaian
        FROM guru g
        JOIN users u ON g.user_id = u.id
        WHERE 1=1";

$params = [];
$param_types = "";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(u.full_name LIKE ? OR u.email LIKE ? OR g.bidang_keahlian LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "sss";
}

if (!empty($filter_bidang)) {
    $conditions[] = "g.bidang_keahlian LIKE ?";
    $bidang_param = "%" . $filter_bidang . "%";
    $params[] = $bidang_param;
    $param_types .= "s";
}

if (!empty($filter_status)) {
    $conditions[] = "g.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

if (count($conditions) > 0) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY u.full_name";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $guru_data[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Guru - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    
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
        .modal-header.yellow {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .modal-header.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
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
        
        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
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
            
            .grid-2 {
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
        
        /* Filter Section Styles */
        .filter-section {
            transition: all 0.3s ease;
        }
        
        .filter-hidden {
            display: none;
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
                        <i class="fas fa-chalkboard-teacher mr-2"></i> Data Guru
                    </h1>
                    <p class="text-gray-600">Kelola data guru bimbingan belajar. Total: <?php echo count($guru_data); ?> guru</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <a href="?action=tambah" 
                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                        <i class="fas fa-user-plus mr-2"></i> Tambah Guru Baru
                    </a>
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
            
            <div class="container mx-auto">
                <!-- Statistik -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-chalkboard-teacher text-blue-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Total Guru</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo count($guru_data); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php
                    // Hitung statistik
                    $aktif = 0;
                    $non_aktif = 0;
                    $punya_siswa = 0;
                    foreach ($guru_data as $guru) {
                        if ($guru['status'] == 'aktif') $aktif++;
                        else $non_aktif++;
                        if ($guru['jumlah_siswa'] > 0) $punya_siswa++;
                    }
                    ?>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user-check text-green-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Aktif</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo $aktif; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user-times text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Non-Aktif</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo $non_aktif; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-users text-purple-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Mengajar</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo $punya_siswa; ?> guru</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section - SELALU TAMPIL -->
            <div id="filterSection" class="mb-6 bg-white shadow overflow-hidden sm:rounded-md filter-section <?php echo (isset($_GET['action']) && in_array($_GET['action'], ['detail', 'edit', 'tambah'])) ? 'filter-hidden' : ''; ?>">
                <div class="px-4 py-5 sm:p-6">
                    <form method="GET" action="dataGuru.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Search -->
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700">Pencarian</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-search text-gray-400"></i>
                                    </div>
                                    <input type="text" name="search" id="search" 
                                           class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md" 
                                           placeholder="Nama, email, atau bidang..."
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>

                            <!-- Filter Bidang Keahlian -->
                            <div>
                                <label for="filter_bidang" class="block text-sm font-medium text-gray-700">Bidang Keahlian</label>
                                <input type="text" name="filter_bidang" id="filter_bidang" 
                                       class="mt-1 focus:ring-blue-500 focus:border-blue-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md" 
                                       placeholder="Contoh: Matematika"
                                       value="<?php echo htmlspecialchars($filter_bidang); ?>">
                            </div>

                            <!-- Filter Status -->
                            <div>
                                <label for="filter_status" class="block text-sm font-medium text-gray-700">Status</label>
                                <select id="filter_status" name="filter_status" 
                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Status</option>
                                    <option value="aktif" <?php echo $filter_status == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="non-aktif" <?php echo $filter_status == 'non-aktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-between">
                            <button type="submit" 
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-filter mr-2"></i> Filter Data
                            </button>
                            
                            <?php if (!empty($search) || !empty($filter_bidang) || !empty($filter_status)): ?>
                                <a href="dataGuru.php" 
                                   class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-times mr-2"></i> Reset Filter
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Guru -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <?php if (count($guru_data) > 0): ?>
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Nama Guru
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Bidang Keahlian
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Data
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($guru_data as $index => $guru): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8">
                                                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-chalkboard-teacher text-blue-600 text-sm"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-2">
                                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                        <?php echo htmlspecialchars($guru['full_name']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500 truncate max-w-[120px]">
                                                        <?php echo htmlspecialchars($guru['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($guru['bidang_keahlian']); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="status-badge <?php echo $guru['status'] == 'aktif' ? 'status-active' : 'status-inactive'; ?>">
                                                <?php echo ucfirst($guru['status']); ?>
                                            </span>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <div class="space-y-1">
                                                <div class="flex items-center">
                                                    <i class="fas fa-users mr-2 text-gray-400 text-xs"></i>
                                                    <span><?php echo $guru['jumlah_siswa']; ?> siswa</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="?action=detail&id=<?php echo $guru['id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 p-1" 
                                                   title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $guru['id']; ?>" 
                                                   class="text-yellow-600 hover:text-yellow-900 p-1" 
                                                   title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="#" 
                                                   onclick="confirmDelete(<?php echo $guru['id']; ?>, '<?php echo htmlspecialchars(addslashes($guru['full_name'])); ?>')"
                                                   class="text-red-600 hover:text-red-900 p-1" 
                                                   title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-chalkboard-teacher text-gray-300 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            Data guru tidak ditemukan
                        </h3>
                        <p class="text-gray-500 mb-4">
                            <?php if (!empty($search) || !empty($filter_bidang) || !empty($filter_status)): ?>
                                Coba ubah filter pencarian atau
                            <?php endif; ?>
                            Tambahkan guru baru untuk memulai.
                        </p>
                        <a href="?action=tambah" 
                           class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <i class="fas fa-user-plus mr-2"></i> Tambah Guru
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Guru</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Guru -->
    <?php if ($guru_detail): ?>
        <div id="detailModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-chalkboard-teacher mr-2"></i> Detail Guru
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Info Guru -->
                    <div class="mb-8">
                        <div class="flex items-center mb-6">
                            <div class="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-blue-600 text-2xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-bold text-gray-900">
                                    <?php echo htmlspecialchars($guru_detail['full_name']); ?></h3>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($guru_detail['email']); ?></p>
                            </div>
                            <div class="ml-auto">
                                <span class="px-3 py-1 text-sm font-semibold rounded-full 
                                    <?php echo $guru_detail['status'] == 'aktif'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst($guru_detail['status']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Bidang Keahlian</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo htmlspecialchars($guru_detail['bidang_keahlian']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pendidikan Terakhir</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo htmlspecialchars($guru_detail['pendidikan_terakhir']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pengalaman</label>
                                <div class="p-2 bg-gray-50 rounded"><?php echo $guru_detail['pengalaman_tahun']; ?> tahun
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Telepon</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo htmlspecialchars($guru_detail['phone'] ?? '-'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Alamat</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo htmlspecialchars($guru_detail['address'] ?? '-'); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo htmlspecialchars($guru_detail['username']); ?></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Tanggal Bergabung</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo date('d/m/Y', strtotime($guru_detail['tanggal_bergabung'])); ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status Akun</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $guru_detail['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $guru_detail['is_active'] ? 'Aktif' : 'Non-Aktif'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Akun Dibuat</label>
                                <div class="p-2 bg-gray-50 rounded">
                                    <?php echo date('d/m/Y H:i', strtotime($guru_detail['user_created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Siswa yang Diajar -->
                    <?php if (!empty($guru_detail['siswa_mengajar'])): ?>
                        <div class="mb-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-users mr-2 text-blue-600"></i> Siswa yang Diajar (<?php echo count($guru_detail['siswa_mengajar']); ?> siswa)
                            </h4>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mata Pelajaran</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tingkat</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($guru_detail['siswa_mengajar'] as $index => $siswa): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo $index + 1; ?></td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['kelas']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['nama_pelajaran']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['tingkat']); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-8 text-center py-6 border border-gray-200 rounded-lg">
                            <i class="fas fa-users text-gray-300 text-4xl mb-3"></i>
                            <p class="text-gray-600">Guru ini belum mengajar siswa apapun.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Jadwal Mengajar -->
                    <!--<?php if (!empty($guru_detail['jadwal_mengajar'])): ?>-->
                    <!--    <div class="mb-8">-->
                    <!--        <h4 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">-->
                    <!--            <i class="fas fa-calendar-alt mr-2 text-blue-600"></i> Jadwal Mengajar-->
                    <!--        </h4>-->
                    <!--        <div class="bg-gray-50 rounded-lg p-4">-->
                    <!--            <div class="overflow-x-auto">-->
                    <!--                <table class="min-w-full divide-y divide-gray-200">-->
                    <!--                    <thead>-->
                    <!--                        <tr>-->
                    <!--                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hari</th>-->
                    <!--                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Jam</th>-->
                    <!--                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>-->
                    <!--                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mata Pelajaran</th>-->
                    <!--                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tingkat</th>-->
                    <!--                        </tr>-->
                    <!--                    </thead>-->
                    <!--                    <tbody class="divide-y divide-gray-200">-->
                    <!--                        <?php foreach ($guru_detail['jadwal_mengajar'] as $jadwal): ?>-->
                    <!--                            <tr>-->
                    <!--                                <td class="px-4 py-3 text-sm font-medium text-gray-900">-->
                    <!--                                    <?php echo htmlspecialchars($jadwal['hari']); ?>-->
                    <!--                                </td>-->
                    <!--                                <td class="px-4 py-3 text-sm text-gray-900">-->
                    <!--                                    <?php echo date('H:i', strtotime($jadwal['jam_mulai'])); ?> - <?php echo date('H:i', strtotime($jadwal['jam_selesai'])); ?>-->
                    <!--                                </td>-->
                    <!--                                <td class="px-4 py-3 text-sm">-->
                    <!--                                    <div class="font-medium text-gray-900">-->
                    <!--                                        <?php echo htmlspecialchars($jadwal['nama_lengkap']); ?>-->
                    <!--                                    </div>-->
                    <!--                                </td>-->
                    <!--                                <td class="px-4 py-3 text-sm text-gray-900">-->
                    <!--                                    <?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?>-->
                    <!--                                </td>-->
                    <!--                                <td class="px-4 py-3 text-sm text-gray-900">-->
                    <!--                                    <?php echo htmlspecialchars($jadwal['tingkat']); ?>-->
                    <!--                                </td>-->
                    <!--                            </tr>-->
                    <!--                        <?php endforeach; ?>-->
                    <!--                    </tbody>-->
                    <!--                </table>-->
                    <!--            </div>-->
                    <!--        </div>-->
                    <!--    </div>-->
                    <!--<?php endif; ?>-->
                </div>
                <div class="modal-footer">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeModal()"
                            class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            Tutup
                        </button>
                        <a href="?action=edit&id=<?php echo $guru_detail['id']; ?>"
                            class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700">
                            <i class="fas fa-edit mr-2"></i> Edit Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Edit Guru -->
    <?php if ($guru_edit): ?>
        <div id="editModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header yellow">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-edit mr-2"></i> Edit Data Guru
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Form Edit Data Guru -->
                    <form method="POST" action="dataGuru.php">
                        <input type="hidden" name="update_guru" value="1">
                        <input type="hidden" name="guru_id" value="<?php echo $guru_edit['id']; ?>">

                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Akun</h3>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="full_name">Nama Lengkap *</label>
                                    <input type="text" id="full_name" name="full_name" class="form-input"
                                        value="<?php echo htmlspecialchars($guru_edit['full_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="email">Email *</label>
                                    <input type="email" id="email" name="email" class="form-input"
                                        value="<?php echo htmlspecialchars($guru_edit['email']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="phone">Telepon</label>
                                    <input type="text" id="phone" name="phone" class="form-input"
                                        value="<?php echo htmlspecialchars($guru_edit['phone'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="address">Alamat</label>
                                    <textarea id="address" name="address" class="form-input"
                                        rows="2"><?php echo htmlspecialchars($guru_edit['address'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="new_password">Password Baru</label>
                                    <input type="password" id="new_password" name="new_password" class="form-input"
                                        placeholder="Kosongkan jika tidak ingin mengubah">
                                    <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter</p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Guru</h3>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="bidang_keahlian">Bidang Keahlian *</label>
                                    <input type="text" id="bidang_keahlian" name="bidang_keahlian" class="form-input"
                                        value="<?php echo htmlspecialchars($guru_edit['bidang_keahlian']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="pendidikan_terakhir">Pendidikan Terakhir</label>
                                    <select id="pendidikan_terakhir" name="pendidikan_terakhir" class="form-input" required>
                                        <option value="SMA" <?php echo $guru_edit['pendidikan_terakhir'] == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                        <option value="D3" <?php echo $guru_edit['pendidikan_terakhir'] == 'D3' ? 'selected' : ''; ?>>D3</option>
                                        <option value="S1" <?php echo $guru_edit['pendidikan_terakhir'] == 'S1' ? 'selected' : ''; ?>>S1</option>
                                        <option value="S2" <?php echo $guru_edit['pendidikan_terakhir'] == 'S2' ? 'selected' : ''; ?>>S2</option>
                                        <option value="S3" <?php echo $guru_edit['pendidikan_terakhir'] == 'S3' ? 'selected' : ''; ?>>S3</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="pengalaman_tahun">Pengalaman (tahun)</label>
                                    <input type="number" id="pengalaman_tahun" name="pengalaman_tahun" class="form-input"
                                        value="<?php echo $guru_edit['pengalaman_tahun']; ?>" min="0" max="50">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select id="status" name="status" class="form-input" required>
                                        <option value="aktif" <?php echo $guru_edit['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="non-aktif" <?php echo $guru_edit['status'] == 'non-aktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between border-t pt-4">
                            <button type="button" onclick="closeModal()"
                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Batal
                            </button>
                            <div class="space-x-3">
                                <a href="?action=detail&id=<?php echo $guru_edit['id']; ?>"
                                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Kembali ke Detail
                                </a>
                                <button type="submit"
                                    class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Tambah Guru -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'tambah'): ?>
        <div id="tambahModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-user-plus mr-2"></i> Tambah Guru Baru
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <form method="POST" action="dataGuru.php">
                    <input type="hidden" name="tambah_guru" value="1">

                    <div class="modal-body">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Akun</h3>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="username">Username *</label>
                                    <input type="text" id="username" name="username" class="form-input" required>
                                    <p class="text-xs text-gray-500 mt-1">Untuk login sistem</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="email">Email *</label>
                                    <input type="email" id="email" name="email" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="full_name">Nama Lengkap *</label>
                                    <input type="text" id="full_name" name="full_name" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="password">Password *</label>
                                    <input type="password" id="password" name="password" class="form-input" required>
                                    <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="phone">Telepon</label>
                                    <input type="text" id="phone" name="phone" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="address">Alamat</label>
                                    <textarea id="address" name="address" class="form-input" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Guru</h3>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="bidang_keahlian">Bidang Keahlian *</label>
                                    <input type="text" id="bidang_keahlian" name="bidang_keahlian" class="form-input"
                                        required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="pendidikan_terakhir">Pendidikan Terakhir</label>
                                    <select id="pendidikan_terakhir" name="pendidikan_terakhir" class="form-input" required>
                                        <option value="SMA">SMA</option>
                                        <option value="D3">D3</option>
                                        <option value="S1" selected>S1</option>
                                        <option value="S2">S2</option>
                                        <option value="S3">S3</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="pengalaman_tahun">Pengalaman (tahun)</label>
                                    <input type="number" id="pengalaman_tahun" name="pengalaman_tahun" class="form-input"
                                        value="0" min="0" max="50">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select id="status" name="status" class="form-input" required>
                                        <option value="aktif" selected>Aktif</option>
                                        <option value="non-aktif">Non-Aktif</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="tanggal_bergabung">Tanggal Bergabung</label>
                                    <input type="date" id="tanggal_bergabung" name="tanggal_bergabung" class="form-input"
                                        value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="flex justify-between">
                            <button type="button" onclick="closeModal()"
                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit"
                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i class="fas fa-save mr-2"></i> Simpan Data Guru
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

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

        // Fungsi untuk menutup modal
        function closeModal() {
            // Hilangkan parameter action dan id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('id');

            // Update URL tanpa reload halaman
            window.history.replaceState({}, '', url.toString());

            // Sembunyikan modal dengan efek
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.animation = 'modalFadeOut 0.3s';
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 250);
            });

            // Tampilkan kembali filter section
            const filterSection = document.getElementById('filterSection');
            if (filterSection) {
                filterSection.classList.remove('filter-hidden');
            }
        }

        // Fungsi untuk membuka modal
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                modal.style.animation = 'modalFadeIn 0.3s';
                
                // Sembunyikan filter section ketika modal terbuka
                const filterSection = document.getElementById('filterSection');
                if (filterSection) {
                    filterSection.classList.add('filter-hidden');
                }
            }
        }

        // Tambahkan event listener untuk klik di luar modal
        document.addEventListener('DOMContentLoaded', function() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            });
            
            // Auto focus pada input pertama di modal
            <?php if (isset($_GET['action']) && ($_GET['action'] == 'tambah' || $_GET['action'] == 'edit' || $_GET['action'] == 'detail')): ?>
                const firstInput = document.querySelector('.modal input:not([type="hidden"]), .modal select');
                if (firstInput) {
                    firstInput.focus();
                }
                
                // Sembunyikan filter section jika modal sudah terbuka
                const filterSection = document.getElementById('filterSection');
                if (filterSection) {
                    filterSection.classList.add('filter-hidden');
                }
            <?php endif; ?>
            
            // Event listener untuk link aksi (detail, edit)
            document.querySelectorAll('a[href*="action=detail"], a[href*="action=edit"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Jika ini adalah link modal, biarkan default behavior
                    // Hanya tangani jika ada parameter action
                    if (this.href.includes('action=')) {
                        // Sembunyikan filter section
                        const filterSection = document.getElementById('filterSection');
                        if (filterSection) {
                            filterSection.classList.add('filter-hidden');
                        }
                    }
                });
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

        // Konfirmasi Hapus
        function confirmDelete(id, name) {
            if (confirm(`Apakah Anda yakin ingin menghapus guru "${name}"?\n\nAksi ini tidak dapat dibatalkan!`)) {
                window.location.href = `dataGuru.php?action=hapus&id=${id}`;
            }
        }

        // Auto-close modals on ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    closeModal();
                }
            }
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                        } else {
                            field.style.borderColor = '#d1d5db';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Harap lengkapi semua field yang wajib diisi!');
                    }
                });
            });
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>