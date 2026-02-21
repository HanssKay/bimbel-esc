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

// Dapatkan guru_id dari session atau database
$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// Ambil guru_id dari tabel guru
$guru_id = 0;
try {
    $sql = "SELECT id FROM guru WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $guru_id = $row['id'];
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error getting guru_id: " . $e->getMessage());
}

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

// Fungsi untuk cek hak akses guru ke siswa (berdasarkan jadwal)
function guruBolehAksesSiswa($conn, $guru_id, $siswa_id)
{
    try {
        $sql = "SELECT 1 
                FROM jadwal_belajar jb
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                WHERE smg.guru_id = ? 
                AND ps.siswa_id = ?
                AND jb.status = 'aktif'
                AND ps.status = 'aktif'
                AND smg.status != 'tidak_aktif'
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        
        $stmt->bind_param("ii", $guru_id, $siswa_id);
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
            // QUERY 1: Ambil data dasar siswa
            $sql_main = "SELECT 
                        s.*,
                        ps.tingkat,
                        ps.jenis_kelas,
                        ps.tanggal_mulai,
                        ps.id as pendaftaran_id
                    FROM siswa s
                    INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                    INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                    WHERE s.id = ? 
                    AND smg.guru_id = ?
                    AND jb.status = 'aktif'
                    AND ps.status = 'aktif'
                    AND smg.status != 'tidak_aktif'
                    GROUP BY s.id
                    LIMIT 1";

            $stmt = $conn->prepare($sql_main);
            if ($stmt === false) {
                throw new Exception("Prepare failed for main query: " . $conn->error);
            }
            $stmt->bind_param("ii", $siswa_id, $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $siswa_detail = $row;

                // QUERY 2: Ambil data program/mata pelajaran yang diajar guru ini
                $sql_program_guru = "SELECT DISTINCT sp.nama_pelajaran
                                    FROM siswa_pelajaran sp
                                    INNER JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id
                                    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                                    WHERE sp.siswa_id = ?
                                    AND smg.guru_id = ?
                                    AND jb.status = 'aktif'
                                    AND sp.status = 'aktif'
                                    AND smg.status != 'tidak_aktif'
                                    GROUP BY sp.nama_pelajaran";

                $stmt2 = $conn->prepare($sql_program_guru);
                if ($stmt2) {
                    $stmt2->bind_param("ii", $siswa_id, $guru_id);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $program_guru = [];
                    while ($row2 = $result2->fetch_assoc()) {
                        $program_guru[] = $row2['nama_pelajaran'];
                    }
                    $siswa_detail['program_guru'] = implode(', ', $program_guru);
                    $stmt2->close();
                }

                // QUERY 3: Hitung total program yang diajar guru ini
                $sql_total_program = "SELECT COUNT(DISTINCT sp.nama_pelajaran) as total_program
                                     FROM siswa_pelajaran sp
                                     INNER JOIN jadwal_belajar jb ON sp.id = jb.siswa_pelajaran_id
                                     INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                                     WHERE sp.siswa_id = ?
                                     AND smg.guru_id = ?
                                     AND jb.status = 'aktif'
                                     AND sp.status = 'aktif'
                                     AND smg.status != 'tidak_aktif'";

                $stmt3 = $conn->prepare($sql_total_program);
                if ($stmt3) {
                    $stmt3->bind_param("ii", $siswa_id, $guru_id);
                    $stmt3->execute();
                    $result3 = $stmt3->get_result();
                    if ($row3 = $result3->fetch_assoc()) {
                        $siswa_detail['total_program_guru'] = $row3['total_program'];
                    }
                    $stmt3->close();
                }

                // QUERY 4: Hitung total jadwal per minggu
                $sql_total_jadwal = "SELECT COUNT(DISTINCT jb.id) as total_jadwal
                                    FROM jadwal_belajar jb
                                    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                                    INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                                    WHERE ps.siswa_id = ?
                                    AND smg.guru_id = ?
                                    AND jb.status = 'aktif'
                                    AND ps.status = 'aktif'
                                    AND smg.status != 'tidak_aktif'";

                $stmt4 = $conn->prepare($sql_total_jadwal);
                if ($stmt4) {
                    $stmt4->bind_param("ii", $siswa_id, $guru_id);
                    $stmt4->execute();
                    $result4 = $stmt4->get_result();
                    if ($row4 = $result4->fetch_assoc()) {
                        $siswa_detail['total_jadwal'] = $row4['total_jadwal'];
                    }
                    $stmt4->close();
                }

                // QUERY 5: Ambil data orangtua
                $sql_ortu = "SELECT 
                            o.id,
                            o.nama_ortu,
                            o.no_hp as no_hp_ortu,
                            o.email as email_ortu,
                            o.pekerjaan,
                            o.perusahaan,
                            o.hubungan_dengan_siswa
                        FROM siswa_orangtua so
                        INNER JOIN orangtua o ON so.orangtua_id = o.id
                        WHERE so.siswa_id = ?";

                $stmt5 = $conn->prepare($sql_ortu);
                if ($stmt5) {
                    $stmt5->bind_param("i", $siswa_id);
                    $stmt5->execute();
                    $ortu_result = $stmt5->get_result();
                    $orangtua_data = [];
                    while ($ortu_row = $ortu_result->fetch_assoc()) {
                        $orangtua_data[] = $ortu_row;
                    }
                    $siswa_detail['orangtua'] = $orangtua_data;
                    $stmt5->close();
                }

                // QUERY 6: Ambil saudara kandung
                if (!empty($orangtua_data)) {
                    $orangtua_ids = array_column($orangtua_data, 'id');
                    $placeholders = implode(',', array_fill(0, count($orangtua_ids), '?'));

                    $sql_saudara = "SELECT DISTINCT s2.nama_lengkap
                                    FROM siswa s2
                                    INNER JOIN siswa_orangtua so2 ON s2.id = so2.siswa_id
                                    WHERE so2.orangtua_id IN ($placeholders)
                                    AND s2.id != ?
                                    ORDER BY s2.nama_lengkap";

                    $stmt6 = $conn->prepare($sql_saudara);
                    if ($stmt6) {
                        $bind_types = str_repeat('i', count($orangtua_ids)) . 'i';
                        $bind_params = array_merge($orangtua_ids, [$siswa_id]);
                        $stmt6->bind_param($bind_types, ...$bind_params);
                        $stmt6->execute();
                        $saudara_result = $stmt6->get_result();
                        $saudara_list = [];
                        while ($saudara_row = $saudara_result->fetch_assoc()) {
                            $saudara_list[] = $saudara_row['nama_lengkap'];
                        }
                        $siswa_detail['saudara_kandung'] = implode(', ', $saudara_list);
                        $stmt6->close();
                    }
                }

                // QUERY 7: Ambil jadwal belajar detail
                $sql_jadwal = "SELECT DISTINCT 
                              smg.hari,
                              DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai,
                              DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai,
                              sp.nama_pelajaran,
                              smg.kapasitas_maks,
                              smg.kapasitas_terisi
                              FROM jadwal_belajar jb
                              INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                              LEFT JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                              INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                              WHERE ps.siswa_id = ?
                              AND smg.guru_id = ?
                              AND jb.status = 'aktif'
                              AND ps.status = 'aktif'
                              AND smg.status != 'tidak_aktif'
                              ORDER BY FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), 
                                       smg.jam_mulai";

                $stmt7 = $conn->prepare($sql_jadwal);
                if ($stmt7) {
                    $stmt7->bind_param("ii", $siswa_id, $guru_id);
                    $stmt7->execute();
                    $jadwal_result = $stmt7->get_result();
                    $jadwal_list = [];
                    while ($jadwal_row = $jadwal_result->fetch_assoc()) {
                        $jadwal_list[] = $jadwal_row;
                    }
                    $siswa_detail['jadwal_detail'] = $jadwal_list;
                    $stmt7->close();
                }

                // QUERY 8: Ambil riwayat penilaian
                $sql_penilaian = "SELECT ps.*, 
                                  DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tgl_format,
                                  ps.kategori
                                  FROM penilaian_siswa ps
                                  WHERE ps.siswa_id = ? 
                                  AND ps.guru_id = ?
                                  ORDER BY ps.tanggal_penilaian DESC
                                  LIMIT 5";

                $stmt8 = $conn->prepare($sql_penilaian);
                if ($stmt8) {
                    $stmt8->bind_param("ii", $siswa_id, $guru_id);
                    $stmt8->execute();
                    $penilaian_result = $stmt8->get_result();
                    $penilaian_list = [];
                    while ($penilaian_row = $penilaian_result->fetch_assoc()) {
                        $penilaian_list[] = $penilaian_row;
                    }
                    $siswa_detail['penilaian'] = $penilaian_list;
                    $stmt8->close();
                }
            }
            $stmt->close();

        } catch (Exception $e) {
            error_log("Error fetching detail siswa: " . $e->getMessage());
            $_SESSION['error_message'] = "Gagal mengambil data siswa: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Anda tidak memiliki akses untuk melihat siswa ini";
    }
}

// PROSES EDIT SISWA
if (isset($_GET['action']) && $_GET['action'] == 'edit_form' && isset($_GET['siswa_id'])) {
    $siswa_id = intval($_GET['siswa_id']);

    if (guruBolehAksesSiswa($conn, $guru_id, $siswa_id)) {
        try {
            $sql = "SELECT s.* FROM siswa s
                    INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                    INNER JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
                    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                    WHERE s.id = ? 
                    AND smg.guru_id = ?
                    AND jb.status = 'aktif'
                    AND ps.status = 'aktif'
                    AND smg.status != 'tidak_aktif'
                    GROUP BY s.id
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
        header('Location: dataSiswa.php?action=detail&siswa_id=' . $siswa_id);
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = "❌ " . $e->getMessage();
        header('Location: dataSiswa.php?action=edit_form&siswa_id=' . $siswa_id);
        exit();
    }
}

// AMBIL DATA SISWA YANG DIAJAR - VERSI BARU
$siswa_data = [];
if ($guru_id > 0) {
    try {
        // Query dasar dengan struktur baru
        $sql = "SELECT DISTINCT 
                    s.id,
                    s.nama_lengkap,
                    s.jenis_kelamin,
                    s.kelas as kelas_sekolah,
                    s.sekolah_asal,
                    s.alamat,
                    s.tempat_lahir,
                    s.tanggal_lahir,
                    s.agama,
                    s.status as status_siswa,
                    ps.tingkat as tingkat_bimbel,
                    ps.jenis_kelas,
                    ps.tanggal_mulai,
                    ps.status as status_pendaftaran,
                    COUNT(DISTINCT jb.id) as total_jadwal,
                    COUNT(DISTINCT smg.id) as total_sesi,
                    GROUP_CONCAT(DISTINCT sp.nama_pelajaran ORDER BY sp.nama_pelajaran SEPARATOR ', ') as mata_pelajaran
                FROM jadwal_belajar jb
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                INNER JOIN siswa s ON ps.siswa_id = s.id
                LEFT JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                WHERE smg.guru_id = ?
                AND jb.status = 'aktif'
                AND ps.status = 'aktif'
                AND s.status = 'aktif'
                AND smg.status != 'tidak_aktif'";

        $params = array($guru_id);
        $types = "i";

        // Tambahkan kondisi pencarian
        if (!empty($search)) {
            $sql .= " AND (s.nama_lengkap LIKE ? 
                    OR s.sekolah_asal LIKE ? 
                    OR sp.nama_pelajaran LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $types .= "sss";
        }

        // Tambahkan filter tingkat
        if (!empty($filter_tingkat)) {
            $sql .= " AND ps.tingkat = ?";
            $params[] = $filter_tingkat;
            $types .= "s";
        }

        $sql .= " GROUP BY s.id, ps.id
                  ORDER BY s.nama_lengkap ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Format program bimbel
            if (!empty($row['mata_pelajaran'])) {
                $row['program_bimbel'] = $row['mata_pelajaran'] . ' (' . $row['tingkat_bimbel'] . ')';
            } else {
                $row['program_bimbel'] = $row['tingkat_bimbel'] . ' - ' . $row['jenis_kelas'];
            }
            
            // Tambahkan info jadwal
            $row['info_jadwal'] = $row['total_jadwal'] . ' jadwal di ' . $row['total_sesi'] . ' sesi';
            
            $siswa_data[] = $row;
        }
        $stmt->close();

        // Ambil data orangtua untuk setiap siswa (optional)
        foreach ($siswa_data as &$siswa) {
            $sql_ortu = "SELECT o.nama_ortu, o.no_hp
                        FROM siswa_orangtua so
                        INNER JOIN orangtua o ON so.orangtua_id = o.id
                        WHERE so.siswa_id = ?
                        LIMIT 1";
            
            $stmt_ortu = $conn->prepare($sql_ortu);
            if ($stmt_ortu) {
                $stmt_ortu->bind_param("i", $siswa['id']);
                $stmt_ortu->execute();
                $ortu_result = $stmt_ortu->get_result();
                if ($ortu_row = $ortu_result->fetch_assoc()) {
                    $siswa['nama_ortu'] = $ortu_row['nama_ortu'];
                    $siswa['no_hp_ortu'] = $ortu_row['no_hp'];
                }
                $stmt_ortu->close();
            }
        }

    } catch (Exception $e) {
        error_log("Error fetching siswa: " . $e->getMessage());
        $error_message = "Terjadi kesalahan saat mengambil data siswa: " . $e->getMessage();
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
        /* CSS styles tetap sama seperti sebelumnya */
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
            max-width: 800px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }

        .modal-sm {
            max-width: 500px;
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

        .jadwal-card {
            transition: all 0.2s;
        }
        
        .jadwal-card:hover {
            transform: translateX(5px);
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

        @media (min-width: 768px) {
            .desktop-sidebar { display: block; }
            .mobile-header { display: none; }
            #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }

        @media (max-width: 767px) {
            .desktop-sidebar { display: none; }
            .modal-content { width: 95%; margin: 5% auto; }
            .grid-2 { grid-template-columns: 1fr; }
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
                        <i class="fas fa-users mr-2"></i> Data Siswa
                    </h1>
                    <p class="text-gray-600">Total <?php echo count($siswa_data); ?> siswa aktif dalam jadwal Anda</p>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- NOTIFICATION MESSAGES -->
            <?php if ($success_message): ?>
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Search dan Filter -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" action="dataSiswa.php" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-1">
                        <input type="text" name="search"
                            placeholder="Cari nama siswa, sekolah asal, atau mata pelajaran..."
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div>
                        <select name="filter_tingkat"
                            class="w-full md:w-auto px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">Semua Tingkat</option>
                            <option value="TK" <?php echo ($filter_tingkat == 'TK') ? 'selected' : ''; ?>>TK</option>
                            <option value="SD" <?php echo ($filter_tingkat == 'SD') ? 'selected' : ''; ?>>SD</option>
                            <option value="SMP" <?php echo ($filter_tingkat == 'SMP') ? 'selected' : ''; ?>>SMP</option>
                            <option value="SMA" <?php echo ($filter_tingkat == 'SMA') ? 'selected' : ''; ?>>SMA</option>
                            <option value="Alumni" <?php echo ($filter_tingkat == 'Alumni') ? 'selected' : ''; ?>>Alumni</option>
                            <option value="Umum" <?php echo ($filter_tingkat == 'Umum') ? 'selected' : ''; ?>>Umum</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium bg-blue-600 text-white hover:bg-blue-700 transition duration-200">
                            <i class="fas fa-search mr-2"></i> Cari
                        </button>
                        <?php if (!empty($search) || !empty($filter_tingkat)): ?>
                            <a href="dataSiswa.php"
                                class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition duration-200">
                                <i class="fas fa-times mr-2"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (!empty($search)): ?>
                    <div class="mt-3 text-sm text-gray-600">
                        <i class="fas fa-info-circle mr-1"></i>
                        Menampilkan hasil pencarian untuk: "<strong><?php echo htmlspecialchars($search); ?></strong>"
                        <?php if (!empty($filter_tingkat)): ?>
                            dengan filter tingkat: <strong><?php echo htmlspecialchars($filter_tingkat); ?></strong>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Statistik Singkat -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-full mr-4">
                            <i class="fas fa-users text-blue-600"></i>
                        </div>
                        <div class="mt-1">
                            <p class="text-gray-600 text-sm">Total Siswa</p>
                            <p class="text-2xl font-bold"><?php echo count($siswa_data); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php
                $total_jadwal = array_sum(array_column($siswa_data, 'total_jadwal'));
                $total_sesi = array_sum(array_column($siswa_data, 'total_sesi'));
                ?>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-full mr-4">
                            <i class="fas fa-calendar-alt text-green-600"></i>
                        </div>
                        <div class="mt-1">
                            <p class="text-gray-600 text-sm">Total Jadwal</p>
                            <p class="text-2xl font-bold"><?php echo $total_jadwal; ?></p>
                            <!-- <p class="text-xs text-gray-500"> Jadwal</p> -->
                        </div>
                    </div>
                </div>
                
            </div>

            <!-- Table Siswa -->
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <?php if (count($siswa_data) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Program</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jadwal</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Orang Tua</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
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
                                                <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-graduate text-blue-600"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($siswa['sekolah_asal'] ?? '-'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo htmlspecialchars($siswa['kelas_sekolah']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($siswa['program_bimbel']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($siswa['tingkat_bimbel']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo $siswa['total_jadwal']; ?> jadwal
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $siswa['total_sesi']; ?> sesi
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if (!empty($siswa['nama_ortu'])): ?>
                                                <div class="text-gray-900"><?php echo htmlspecialchars($siswa['nama_ortu']); ?></div>
                                                <?php if (!empty($siswa['no_hp_ortu'])): ?>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($siswa['no_hp_ortu']); ?></div>
                                                <?php endif; ?>
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
                            <i class="fas fa-users text-5xl mb-4 text-gray-300"></i>
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
                                    Siswa akan muncul setelah Anda memiliki jadwal mengajar
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
                    <li>• Total siswa dengan jadwal aktif: <strong><?php echo count($siswa_data); ?></strong> siswa</li>
                    <li>• Total jadwal mengajar: <strong><?php echo $total_jadwal; ?></strong> pertemuan per minggu</li>
                    <li>• Klik <strong class="text-blue-600">Detail</strong> untuk melihat informasi lengkap siswa</li>
                    <li>• Data diambil dari jadwal mengajar Anda yang aktif</li>
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
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['nama_lengkap']) ?></span>
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
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['sekolah_asal']) ?></span>
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
                                <div>
                                    <span class="block font-semibold text-gray-700">Tingkat Bimbel</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['tingkat']) ?></span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-700">Jenis Kelas</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['jenis_kelas']) ?></span>
                                </div>
                                <?php if (!empty($siswa_detail['program_guru'])): ?>
                                <div class="md:col-span-2">
                                    <span class="block font-semibold text-gray-700">Program yang Anda Ajar</span>
                                    <div class="mt-2 p-3 bg-white rounded border">
                                        <?php 
                                        $program_list = explode(', ', $siswa_detail['program_guru']);
                                        foreach ($program_list as $program):
                                        ?>
                                            <div class="py-1">
                                                <i class="fas fa-book text-purple-500 mr-2"></i>
                                                <span class="text-gray-700"><?= htmlspecialchars(trim($program)) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (!empty($siswa_detail['total_program_guru'])): ?>
                                            <div class="mt-2 text-xs text-gray-500">
                                                Total: <?= $siswa_detail['total_program_guru'] ?> program
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Jadwal Belajar -->
                        <?php if (!empty($siswa_detail['jadwal_detail'])): ?>
                        <div class="bg-orange-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-orange-800 mb-4 flex items-center">
                                <i class="fas fa-calendar-alt mr-2"></i> Jadwal Belajar
                            </h3>
                            <div class="space-y-3">
                                <?php foreach ($siswa_detail['jadwal_detail'] as $jadwal): ?>
                                    <div class="bg-white p-3 rounded-lg border-l-4 border-orange-400 shadow-sm jadwal-card">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <div class="font-medium text-gray-900">
                                                    <?= htmlspecialchars($jadwal['hari']) ?>, 
                                                    <?= $jadwal['jam_mulai'] ?> - <?= $jadwal['jam_selesai'] ?>
                                                </div>
                                                <!-- <div class="text-sm text-gray-600 mt-1">
                                                    <i class="fas fa-book mr-1 text-orange-500"></i>
                                                    <?= htmlspecialchars($jadwal['nama_pelajaran'] ?? 'Belum ada mata pelajaran') ?>
                                                </div> -->
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($siswa_detail['total_jadwal'] > 0): ?>
                                    <div class="text-sm text-gray-500 mt-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Total: <?= $siswa_detail['total_jadwal'] ?> jadwal per minggu
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Data Orang Tua -->
                        <?php if (!empty($siswa_detail['orangtua'])): ?>
                        <div class="bg-yellow-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-yellow-800 mb-4 flex items-center">
                                <i class="fas fa-user-friends mr-2"></i> Data Orang Tua/Wali
                            </h3>
                            <?php foreach ($siswa_detail['orangtua'] as $index => $ortu): ?>
                                <div class="<?php echo $index < count($siswa_detail['orangtua']) - 1 ? 'mb-4 pb-4 border-b border-yellow-200' : ''; ?>">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <span class="block font-semibold text-gray-700">Nama</span>
                                            <span class="text-gray-600"><?= htmlspecialchars($ortu['nama_ortu']) ?></span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-700">Hubungan</span>
                                            <span class="text-gray-600">
                                                <?php
                                                $hubungan = $ortu['hubungan_dengan_siswa'] ?? '';
                                                echo $hubungan == 'ayah' ? 'Ayah' : ($hubungan == 'ibu' ? 'Ibu' : ($hubungan == 'wali' ? 'Wali' : '-'));
                                                ?>
                                            </span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-700">No. HP</span>
                                            <span class="text-gray-600"><?= htmlspecialchars($ortu['no_hp_ortu'] ?? '-') ?></span>
                                        </div>
                                        <div>
                                            <span class="block font-semibold text-gray-700">Email</span>
                                            <span class="text-gray-600"><?= htmlspecialchars($ortu['email_ortu'] ?? '-') ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!empty($siswa_detail['saudara_kandung'])): ?>
                                <div class="mt-4 pt-4 border-t border-yellow-200">
                                    <span class="block font-semibold text-gray-700">Saudara Kandung di Bimbel</span>
                                    <span class="text-gray-600"><?= htmlspecialchars($siswa_detail['saudara_kandung']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Riwayat Penilaian -->
                        <?php if (!empty($siswa_detail['penilaian'])): ?>
                        <div class="bg-indigo-50 p-4 rounded-lg">
                            <h3 class="font-bold text-lg text-indigo-800 mb-4 flex items-center">
                                <i class="fas fa-clipboard-list mr-2"></i> Riwayat Penilaian
                            </h3>
                            <div class="space-y-3">
                                <?php foreach ($siswa_detail['penilaian'] as $penilaian): ?>
                                    <div class="bg-white p-3 rounded-lg border">
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <div class="font-medium text-gray-900">
                                                    <?= date('d M Y', strtotime($penilaian['tanggal_penilaian'])) ?>
                                                </div>
                                                <div class="text-sm text-gray-600">
                                                    Total Score: <?= $penilaian['total_score'] ?>/50
                                                </div>
                                            </div>
                                            <div>
                                                <span class="px-2 py-1 text-xs rounded-full 
                                                    <?php
                                                    $kategori = $penilaian['kategori'] ?? '';
                                                    if ($kategori == 'Sangat Baik') echo 'bg-green-100 text-green-800';
                                                    elseif ($kategori == 'Baik') echo 'bg-blue-100 text-blue-800';
                                                    elseif ($kategori == 'Cukup') echo 'bg-yellow-100 text-yellow-800';
                                                    else echo 'bg-red-100 text-red-800';
                                                    ?>">
                                                    <?= $kategori ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php if (!empty($penilaian['catatan_guru'])): ?>
                                            <div class="mt-2 text-sm text-gray-600 border-t pt-2">
                                                <i class="fas fa-quote-left text-gray-400 mr-1"></i>
                                                <?= htmlspecialchars($penilaian['catatan_guru']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <div class="text-center mt-3">
                                    <a href="riwayat.php?siswa_id=<?= $siswa_detail['id'] ?>" 
                                       class="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800">
                                        <i class="fas fa-history mr-1"></i> Lihat semua penilaian
                                    </a>
                                </div>
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
                <?php if ($siswa_detail): ?>
                <a href="inputNilai.php?siswa_id=<?= $siswa_detail['id'] ?>"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 ml-2">
                    <i class="fas fa-plus-circle mr-2"></i> Input Nilai
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL EDIT SISWA -->
    <div id="editModal" class="modal">
        <div class="modal-content modal-sm">
            <div class="modal-header yellow">
                <h2 class="text-xl font-bold"><i class="fas fa-edit mr-2"></i> Edit Data Siswa</h2>
                <span class="close" onclick="closeModal('editModal')">&times;</span>
            </div>
            <?php if ($siswa_edit): ?>
                <form method="POST" action="dataSiswa.php">
                    <input type="hidden" name="siswa_id" value="<?= $siswa_edit['id'] ?>">
                    <input type="hidden" name="update_siswa" value="1">

                    <div class="modal-body">
                        <div class="space-y-4">
                            <div class="form-group">
                                <label class="form-label">Nama Lengkap *</label>
                                <input type="text" name="nama_lengkap"
                                    value="<?= htmlspecialchars($siswa_edit['nama_lengkap']) ?>" class="form-input" required>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-group">
                                    <label class="form-label">Tempat Lahir</label>
                                    <input type="text" name="tempat_lahir"
                                        value="<?= htmlspecialchars($siswa_edit['tempat_lahir']) ?>" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tanggal Lahir</label>
                                    <input type="date" name="tanggal_lahir"
                                        value="<?= htmlspecialchars($siswa_edit['tanggal_lahir']) ?>" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" class="form-input">
                                        <option value="L" <?= $siswa_edit['jenis_kelamin'] == 'L' ? 'selected' : '' ?>>Laki-laki</option>
                                        <option value="P" <?= $siswa_edit['jenis_kelamin'] == 'P' ? 'selected' : '' ?>>Perempuan</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Agama</label>
                                    <select name="agama" class="form-input">
                                        <option value="">Pilih Agama</option>
                                        <option value="Islam" <?= $siswa_edit['agama'] == 'Islam' ? 'selected' : '' ?>>Islam</option>
                                        <option value="Kristen" <?= $siswa_edit['agama'] == 'Kristen' ? 'selected' : '' ?>>Kristen</option>
                                        <option value="Katolik" <?= $siswa_edit['agama'] == 'Katolik' ? 'selected' : '' ?>>Katolik</option>
                                        <option value="Hindu" <?= $siswa_edit['agama'] == 'Hindu' ? 'selected' : '' ?>>Hindu</option>
                                        <option value="Buddha" <?= $siswa_edit['agama'] == 'Buddha' ? 'selected' : '' ?>>Buddha</option>
                                    </select>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">Kelas Sekolah</label>
                                    <select name="kelas" class="form-input">
                                        <option value="">Pilih Kelas</option>
                                        <option value="Paud" <?= $siswa_edit['kelas'] == 'Paud' ? 'selected' : '' ?>>Paud</option>
                                        <option value="TK" <?= $siswa_edit['kelas'] == 'TK' ? 'selected' : '' ?>>TK</option>
                                        <option value="1 SD" <?= $siswa_edit['kelas'] == '1 SD' ? 'selected' : '' ?>>1 SD</option>
                                        <option value="2 SD" <?= $siswa_edit['kelas'] == '2 SD' ? 'selected' : '' ?>>2 SD</option>
                                        <option value="3 SD" <?= $siswa_edit['kelas'] == '3 SD' ? 'selected' : '' ?>>3 SD</option>
                                        <option value="4 SD" <?= $siswa_edit['kelas'] == '4 SD' ? 'selected' : '' ?>>4 SD</option>
                                        <option value="5 SD" <?= $siswa_edit['kelas'] == '5 SD' ? 'selected' : '' ?>>5 SD</option>
                                        <option value="6 SD" <?= $siswa_edit['kelas'] == '6 SD' ? 'selected' : '' ?>>6 SD</option>
                                        <option value="7 SMP" <?= $siswa_edit['kelas'] == '7 SMP' ? 'selected' : '' ?>>7 SMP</option>
                                        <option value="8 SMP" <?= $siswa_edit['kelas'] == '8 SMP' ? 'selected' : '' ?>>8 SMP</option>
                                        <option value="9 SMP" <?= $siswa_edit['kelas'] == '9 SMP' ? 'selected' : '' ?>>9 SMP</option>
                                        <option value="10 SMA" <?= $siswa_edit['kelas'] == '10 SMA' ? 'selected' : '' ?>>10 SMA</option>
                                        <option value="11 SMA" <?= $siswa_edit['kelas'] == '11 SMA' ? 'selected' : '' ?>>11 SMA</option>
                                        <option value="12 SMA" <?= $siswa_edit['kelas'] == '12 SMA' ? 'selected' : '' ?>>12 SMA</option>
                                        <option value="Alumni" <?= $siswa_edit['kelas'] == 'Alumni' ? 'selected' : '' ?>>Alumni</option>
                                        <option value="Umum" <?= $siswa_edit['kelas'] == 'Umum' ? 'selected' : '' ?>>Umum</option>
                                    </select>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" class="form-input" rows="3"><?= htmlspecialchars($siswa_edit['alamat']) ?></textarea>
                                </div>
                                <div class="form-group md:col-span-2">
                                    <label class="form-label">Sekolah Asal</label>
                                    <input type="text" name="sekolah_asal"
                                        value="<?= htmlspecialchars($siswa_edit['sekolah_asal']) ?>" class="form-input">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" onclick="closeModal('editModal')"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                            Batal
                        </button>
                        <button type="submit"
                            class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 flex items-center">
                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="modal-body">
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-2xl"></i>
                        <p class="mt-2">Data siswa tidak ditemukan</p>
                        <button onclick="closeModal('editModal')"
                            class="mt-4 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                            Tutup
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
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

        // Close menu when clicking on menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    mobileMenu.classList.remove('menu-open');
                    menuOverlay.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Search dengan debounce
        const searchInput = document.querySelector('input[name="search"]');
        let searchTimeout;

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length === 0 || this.value.length >= 2) {
                        this.form.submit();
                    }
                }, 500);
            });
        }

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
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
            mobileMenu.classList.remove('menu-open');
            menuOverlay.classList.remove('active');
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';

            if (history.pushState) {
                let url = new URL(window.location);
                url.searchParams.delete('action');
                url.searchParams.delete('siswa_id');
                window.history.replaceState({}, '', url);
            }
        }

        function showDetail(siswaId) {
            let url = new URL(window.location);
            url.searchParams.set('action', 'detail');
            url.searchParams.set('siswa_id', siswaId);
            window.history.pushState({}, '', url);
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
<?php $conn->close(); ?>