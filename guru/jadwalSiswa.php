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

// AMBIL DATA GURU
if ($guru_id == 0) {
    try {
        $sql_guru = "SELECT id FROM guru WHERE user_id = ?";
        $stmt_guru = $conn->prepare($sql_guru);
        $stmt_guru->bind_param("i", $_SESSION['user_id']);
        $stmt_guru->execute();
        $result_guru = $stmt_guru->get_result();
        if ($row_guru = $result_guru->fetch_assoc()) {
            $guru_id = $row_guru['id'];
            $_SESSION['role_id'] = $guru_id;
        }
        $stmt_guru->close();
    } catch (Exception $e) {
        error_log("Error fetching guru data: " . $e->getMessage());
    }
}

if ($guru_id == 0) {
    die("Data guru tidak ditemukan");
}

// FILTER
$filter_hari = isset($_GET['hari']) && in_array($_GET['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']) ? $_GET['hari'] : '';
$filter_siswa = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;
$filter_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_tingkat = isset($_GET['tingkat']) && in_array($_GET['tingkat'], ['TK', 'SD', 'SMP', 'SMA', 'Alumni', 'Umum']) ? $_GET['tingkat'] : '';

// AMBIL DAFTAR SISWA UNTUK FILTER
$semua_siswa_guru = [];
$sql_semua_siswa = "
    SELECT DISTINCT 
        s.id,
        s.nama_lengkap,
        s.kelas,
        s.sekolah_asal
    FROM siswa s
    JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
    JOIN jadwal_belajar jb ON ps.id = jb.pendaftaran_id
    JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
    WHERE smg.guru_id = ?
    AND jb.status = 'aktif'
    AND ps.status = 'aktif'
    AND s.status = 'aktif'
    ORDER BY s.nama_lengkap
";

try {
    $stmt_siswa = $conn->prepare($sql_semua_siswa);
    $stmt_siswa->bind_param("i", $guru_id);
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();

    while ($row = $result_siswa->fetch_assoc()) {
        $semua_siswa_guru[] = $row;
    }
    $stmt_siswa->close();
} catch (Exception $e) {
    error_log("Error fetching semua siswa: " . $e->getMessage());
}

// QUERY JADWAL SISWA - SESUAI STRUKTUR ADMIN
$jadwal_siswa = [];
$where_conditions = ["smg.guru_id = ?", "jb.status = 'aktif'", "ps.status = 'aktif'", "s.status = 'aktif'"];
$filter_params = [$guru_id];
$types = "i";

if ($filter_hari !== '') {
    $where_conditions[] = "smg.hari = ?";
    $filter_params[] = $filter_hari;
    $types .= "s";
}

if ($filter_tingkat !== '') {
    $where_conditions[] = "ps.tingkat = ?";
    $filter_params[] = $filter_tingkat;
    $types .= "s";
}

if ($filter_siswa > 0) {
    $where_conditions[] = "ps.siswa_id = ?";
    $filter_params[] = $filter_siswa;
    $types .= "i";
} elseif (!empty($filter_nama_siswa)) {
    $where_conditions[] = "s.nama_lengkap LIKE ?";
    $filter_params[] = "%" . $filter_nama_siswa . "%";
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

$sql_jadwal_siswa = "
    SELECT 
        jb.id as jadwal_id,
        jb.created_at,
        jb.updated_at,
        jb.pendaftaran_id,
        jb.status as status_jadwal,
        
        smg.id as sesi_id,
        smg.hari,
        smg.jam_mulai,
        smg.jam_selesai,
        smg.status as status_sesi,
        
        ps.id as pendaftaran_id,
        ps.jenis_kelas,
        ps.tingkat,
        ps.tanggal_mulai,
        
        s.id as siswa_id,
        s.nama_lengkap,
        s.kelas as kelas_sekolah,
        s.sekolah_asal,
        
        COALESCE(sp.nama_pelajaran, 'Bimbingan Umum') as mata_pelajaran,
        sp.id as pelajaran_id,
        
        DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, smg.jam_mulai, smg.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
    INNER JOIN siswa s ON ps.siswa_id = s.id
    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
    LEFT JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    WHERE {$where_clause}
    ORDER BY 
        FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
        smg.jam_mulai,
        s.nama_lengkap
";

try {
    $stmt = $conn->prepare($sql_jadwal_siswa);
    $stmt->bind_param($types, ...$filter_params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $jadwal_siswa[] = $row;
    }
    $stmt->close();

    error_log("Jadwal ditemukan untuk guru ID $guru_id: " . count($jadwal_siswa) . " records");

} catch (Exception $e) {
    error_log("Error fetching jadwal siswa: " . $e->getMessage());
    $_SESSION['error_message'] = "Gagal memuat data jadwal: " . $e->getMessage();
}

// FUNGSI: Cari atau buat sesi guru
function findOrCreateSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai)
{
    // Cek apakah sudah ada sesi dengan waktu yang sama
    $sql_cek_sesi = "SELECT id, status 
                     FROM sesi_mengajar_guru 
                     WHERE guru_id = ? 
                     AND hari = ? 
                     AND jam_mulai = ? 
                     AND jam_selesai = ?";

    $stmt_cek = $conn->prepare($sql_cek_sesi);
    $stmt_cek->bind_param("isss", $guru_id, $hari, $jam_mulai, $jam_selesai);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();

    if ($result_cek->num_rows > 0) {
        $sesi = $result_cek->fetch_assoc();
        $stmt_cek->close();
        return $sesi;
    }
    $stmt_cek->close();

    // Buat sesi baru jika tidak ada
    $sql_buat_sesi = "INSERT INTO sesi_mengajar_guru 
                     (guru_id, hari, jam_mulai, jam_selesai, status, created_at) 
                     VALUES (?, ?, ?, ?, 'aktif', NOW())";

    $stmt_buat = $conn->prepare($sql_buat_sesi);
    $stmt_buat->bind_param("isss", $guru_id, $hari, $jam_mulai, $jam_selesai);

    if ($stmt_buat->execute()) {
        $sesi_id = $stmt_buat->insert_id;
        $stmt_buat->close();
        return ['id' => $sesi_id, 'status' => 'aktif'];
    } else {
        $stmt_buat->close();
        return false;
    }
}

// PROSES TAMBAH JADWAL - SESUAI STRUKTUR
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_jadwal'])) {
    $siswa_id = $_POST['siswa_id'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $jam_mulai = $_POST['jam_mulai'] ?? '';
    $jam_selesai = $_POST['jam_selesai'] ?? '';

    if ($siswa_id && $hari && $jam_mulai && $jam_selesai) {
        try {
            // Cek apakah siswa ini memiliki pendaftaran aktif
            $sql_cek_siswa = "
                SELECT ps.id as pendaftaran_id
                FROM pendaftaran_siswa ps
                WHERE ps.siswa_id = ? 
                AND ps.status = 'aktif'
                LIMIT 1
            ";

            $stmt_cek = $conn->prepare($sql_cek_siswa);
            $stmt_cek->bind_param("i", $siswa_id);
            $stmt_cek->execute();
            $result_cek = $stmt_cek->get_result();

            if ($result_cek->num_rows === 0) {
                throw new Exception("Siswa tidak memiliki pendaftaran aktif!");
            }

            $data_siswa = $result_cek->fetch_assoc();
            $pendaftaran_id = $data_siswa['pendaftaran_id'];
            $stmt_cek->close();

            // Cek apakah siswa sudah memiliki jadwal di hari dan jam yang sama
            $sql_cek_konflik = "
                SELECT jb.id 
                FROM jadwal_belajar jb
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                WHERE ps.siswa_id = ?
                AND smg.hari = ?
                AND jb.status = 'aktif'
                AND (
                    (smg.jam_mulai <= ? AND smg.jam_selesai > ?) OR
                    (smg.jam_mulai < ? AND smg.jam_selesai >= ?) OR
                    (smg.jam_mulai >= ? AND smg.jam_selesai <= ?)
                )";

            $stmt_konflik = $conn->prepare($sql_cek_konflik);
            $stmt_konflik->bind_param(
                "isssssss",
                $siswa_id,
                $hari,
                $jam_mulai,
                $jam_mulai,
                $jam_selesai,
                $jam_selesai,
                $jam_mulai,
                $jam_selesai
            );
            $stmt_konflik->execute();
            $result_konflik = $stmt_konflik->get_result();

            if ($result_konflik->num_rows > 0) {
                throw new Exception("Siswa sudah memiliki jadwal di waktu yang sama!");
            }
            $stmt_konflik->close();

            // Cari atau buat sesi guru
            $sesi = findOrCreateSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai);
            if (!$sesi) {
                throw new Exception("Gagal membuat sesi mengajar!");
            }

            // Insert jadwal baru
            $sql_insert = "INSERT INTO jadwal_belajar 
                          (pendaftaran_id, siswa_pelajaran_id, sesi_guru_id, status, created_at) 
                          VALUES (?, NULL, ?, 'aktif', NOW())";

            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("ii", $pendaftaran_id, $sesi['id']);

            if ($stmt_insert->execute()) {
                $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
                $stmt_insert->close();
                header("Location: jadwalSiswa.php");
                exit();
            } else {
                throw new Exception("Gagal menambahkan jadwal: " . $stmt_insert->error);
            }

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
            header("Location: jadwalSiswa.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Semua field harus diisi!";
        header("Location: jadwalSiswa.php");
        exit();
    }
}

// PROSES EDIT JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_jadwal'])) {
    $jadwal_id = $_POST['jadwal_id'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $jam_mulai = $_POST['jam_mulai'] ?? '';
    $jam_selesai = $_POST['jam_selesai'] ?? '';

    if ($jadwal_id && $hari && $jam_mulai && $jam_selesai) {
        try {
            // Ambil data jadwal lama
            $sql_get = "
                SELECT jb.*, ps.siswa_id, jb.sesi_guru_id as sesi_lama_id
                FROM jadwal_belajar jb
                JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                WHERE jb.id = ?
            ";
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->bind_param("i", $jadwal_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            $old_data = $result_get->fetch_assoc();
            $stmt_get->close();

            if (!$old_data) {
                throw new Exception("Jadwal tidak ditemukan!");
            }

            // Cek konflik jadwal siswa
            $sql_cek_konflik = "
                SELECT jb.id 
                FROM jadwal_belajar jb
                INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                WHERE ps.siswa_id = ?
                AND smg.hari = ?
                AND jb.id != ?
                AND jb.status = 'aktif'
                AND (
                    (smg.jam_mulai <= ? AND smg.jam_selesai > ?) OR
                    (smg.jam_mulai < ? AND smg.jam_selesai >= ?) OR
                    (smg.jam_mulai >= ? AND smg.jam_selesai <= ?)
                )";

            $stmt_konflik = $conn->prepare($sql_cek_konflik);
            $stmt_konflik->bind_param(
                "issssssss",
                $old_data['siswa_id'],
                $hari,
                $jadwal_id,
                $jam_mulai,
                $jam_mulai,
                $jam_selesai,
                $jam_selesai,
                $jam_mulai,
                $jam_selesai
            );
            $stmt_konflik->execute();
            $result_konflik = $stmt_konflik->get_result();

            if ($result_konflik->num_rows > 0) {
                throw new Exception("Siswa sudah memiliki jadwal lain di waktu ini!");
            }
            $stmt_konflik->close();

            // Cari atau buat sesi guru baru
            $sesi_baru = findOrCreateSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai);
            if (!$sesi_baru) {
                throw new Exception("Gagal membuat sesi mengajar!");
            }

            // Update jadwal
            $sql_update = "UPDATE jadwal_belajar 
                          SET sesi_guru_id = ?, updated_at = NOW() 
                          WHERE id = ?";

            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ii", $sesi_baru['id'], $jadwal_id);

            if ($stmt_update->execute()) {
                $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
                $stmt_update->close();
                header("Location: jadwalSiswa.php");
                exit();
            } else {
                throw new Exception("Gagal memperbarui jadwal!");
            }

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
            header("Location: jadwalSiswa.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Semua field harus diisi!";
        header("Location: jadwalSiswa.php");
        exit();
    }
}

// PROSES HAPUS JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hapus_jadwal'])) {
    $jadwal_id = $_POST['jadwal_id'] ?? '';

    if ($jadwal_id) {
        try {
            // Soft delete - update status
            $sql_hapus = "UPDATE jadwal_belajar SET status = 'non-aktif' WHERE id = ?";
            $stmt_hapus = $conn->prepare($sql_hapus);
            $stmt_hapus->bind_param("i", $jadwal_id);

            if ($stmt_hapus->execute()) {
                $_SESSION['success_message'] = "Jadwal berhasil dihapus!";
                $stmt_hapus->close();
                header("Location: jadwalSiswa.php");
                exit();
            } else {
                throw new Exception("Gagal menghapus jadwal!");
            }

        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
            header("Location: jadwalSiswa.php");
            exit();
        }
    }
}

// HITUNG STATISTIK
$total_jadwal = count($jadwal_siswa);
$total_siswa = count(array_unique(array_column($jadwal_siswa, 'siswa_id')));

// Group by hari untuk statistik
$jadwal_by_hari = [];
foreach ($jadwal_siswa as $jadwal) {
    $hari = $jadwal['hari'];
    if (!isset($jadwal_by_hari[$hari])) {
        $jadwal_by_hari[$hari] = 0;
    }
    $jadwal_by_hari[$hari]++;
}

// AMBIL SISWA TANPA JADWAL SAMA SEKALI
$siswa_tanpa_jadwal = [];

$sql_siswa_tanpa_jadwal = "
    SELECT DISTINCT
        s.id,
        s.nama_lengkap,
        s.kelas,
        s.sekolah_asal
    FROM siswa s
    INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
    WHERE ps.status = 'aktif'
    AND s.status = 'aktif'
    AND NOT EXISTS (
        SELECT 1 
        FROM jadwal_belajar jb
        JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
        WHERE jb.pendaftaran_id = ps.id
        AND jb.status = 'aktif'
        AND smg.guru_id = ?
    )
    ORDER BY s.nama_lengkap
";

$stmt_tanpa_jadwal = $conn->prepare($sql_siswa_tanpa_jadwal);
$stmt_tanpa_jadwal->bind_param("i", $guru_id);
$stmt_tanpa_jadwal->execute();
$result_tanpa_jadwal = $stmt_tanpa_jadwal->get_result();

while ($row = $result_tanpa_jadwal->fetch_assoc()) {
    $siswa_tanpa_jadwal[] = $row;
}
$stmt_tanpa_jadwal->close();

// AJAX: Ambil daftar siswa untuk autocomplete
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_siswa_list_guru') {
    header('Content-Type: application/json');

    $siswa_list_ajax = [];
    $sql_siswa_ajax = "
        SELECT DISTINCT
            s.id, 
            s.nama_lengkap, 
            s.kelas,
            s.sekolah_asal
        FROM siswa s
        INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
        WHERE ps.status = 'aktif'
        AND s.status = 'aktif'
        ORDER BY s.nama_lengkap
    ";

    $stmt_siswa_ajax = $conn->prepare($sql_siswa_ajax);
    $stmt_siswa_ajax->execute();
    $result_siswa_ajax = $stmt_siswa_ajax->get_result();

    while ($row = $result_siswa_ajax->fetch_assoc()) {
        $siswa_list_ajax[] = $row;
    }
    $stmt_siswa_ajax->close();

    echo json_encode($siswa_list_ajax);
    exit();
}

// AJAX: Search siswa by name untuk filter
if (isset($_GET['ajax']) && $_GET['ajax'] == 'search_siswa_by_name' && isset($_GET['keyword'])) {
    header('Content-Type: application/json');

    $keyword = trim($_GET['keyword']);

    if (strlen($keyword) < 2) {
        echo json_encode([]);
        exit();
    }

    $sql = "
        SELECT DISTINCT
            s.id, 
            s.nama_lengkap, 
            s.kelas,
            s.sekolah_asal
        FROM siswa s
        INNER JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
        WHERE ps.status = 'aktif'
        AND s.status = 'aktif'
        AND s.nama_lengkap LIKE ?
        ORDER BY s.nama_lengkap
        LIMIT 10
    ";

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
    exit();
}

// AJAX: Ambil detail jadwal untuk edit
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_jadwal_detail' && isset($_GET['jadwal_id'])) {
    header('Content-Type: application/json');

    $jadwal_id = (int) $_GET['jadwal_id'];

    $sql = "
        SELECT 
            jb.id,
            jb.pendaftaran_id,
            s.id as siswa_id,
            s.nama_lengkap as siswa_nama,
            s.kelas as siswa_kelas,
            s.sekolah_asal as siswa_sekolah,
            smg.guru_id,
            smg.hari,
            smg.jam_mulai,
            smg.jam_selesai
        FROM jadwal_belajar jb
        INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
        INNER JOIN siswa s ON ps.siswa_id = s.id
        INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
        WHERE jb.id = ? AND smg.guru_id = ? AND jb.status = 'aktif'
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $jadwal_id, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'jadwal_id' => $data['id'],
            'pendaftaran_id' => $data['pendaftaran_id'],
            'siswa_id' => $data['siswa_id'],
            'siswa_nama' => $data['siswa_nama'],
            'siswa_kelas' => $data['siswa_kelas'],
            'siswa_sekolah' => $data['siswa_sekolah'],
            'guru_id' => $data['guru_id'],
            'hari' => $data['hari'],
            'jam_mulai' => $data['jam_mulai'],
            'jam_selesai' => $data['jam_selesai']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Jadwal tidak ditemukan']);
    }
    $stmt->close();
    exit();
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Siswa - Bimbel Esc</title>
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

        .selected-info {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-konfirmasi {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1300;
            align-items: center;
            justify-content: center;
        }

        .modal-konfirmasi.active {
            display: flex;
        }

        .modal-konfirmasi-content {
            background-color: white;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: modalFadeIn 0.3s ease-out;
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

        .modal-konfirmasi-header {
            padding: 24px 24px 0 24px;
            text-align: center;
        }

        .modal-konfirmasi-icon {
            width: 60px;
            height: 60px;
            background-color: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .modal-konfirmasi-icon i {
            font-size: 28px;
            color: #dc2626;
        }

        .modal-konfirmasi-body {
            padding: 20px 24px;
            text-align: center;
        }

        .modal-konfirmasi-footer {
            padding: 20px 24px 24px;
            display: flex;
            justify-content: center;
            gap: 12px;
            border-top: 1px solid #e5e7eb;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1200;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background-color: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .table-container {
            overflow-x: auto;
        }

        .table-jadwal {
            width: 100%;
            border-collapse: collapse;
        }

        .table-jadwal th {
            background-color: #f3f4f6;
            font-weight: 600;
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid #e5e7eb;
        }

        .table-jadwal td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .table-jadwal tr:hover {
            background-color: #f9fafb;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-primary {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .badge-purple {
            background-color: #e9d5ff;
            color: #7c3aed;
        }

        .filter-dropdown {
            position: absolute;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            width: 20%;
            margin-top: 2px;
        }

        .filter-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .filter-item:last-child {
            border-bottom: none;
        }

        .filter-item:hover {
            background-color: #f9fafb;
        }

        .filter-item.active {
            background-color: #eff6ff;
        }

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

            .modal-content {
                width: 95%;
                margin: 10px;
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
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-calendar-alt mr-2"></i> Jadwal Siswa
                    </h1>
                    <p class="text-gray-600">
                        Kelola jadwal belajar siswa anda di sini.
                    </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span
                        class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <button onclick="openTambahModal()"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm md:text-base">
                        <i class="fas fa-plus mr-2"></i> Tambah Jadwal
                    </button>
                </div>
            </div>
        </div>

        <!-- Notifikasi -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="container mx-auto p-4">
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="container mx-auto p-4">
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']); ?></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Total Jadwal -->
                <div class="stat-card bg-white rounded-xl p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-calendar-alt text-blue-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Jadwal</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_jadwal); ?>
                            </h3>
                        </div>
                    </div>
                </div>

                <!-- Total Siswa -->
                <div class="stat-card bg-white p-5 rounded-xl shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-users text-green-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Siswa</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_siswa); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter -->
            <div class="bg-white overflow-hidden shadow rounded-lg p-5 mb-5">
                <form method="GET" action="" class="space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <!-- Filter Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-900 mb-1">
                                Filter Berdasarkan Hari
                            </label>
                            <select name="hari"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Hari</option>
                                <option value="Senin" <?php echo $filter_hari == 'Senin' ? 'selected' : ''; ?>>Senin
                                </option>
                                <option value="Selasa" <?php echo $filter_hari == 'Selasa' ? 'selected' : ''; ?>>Selasa
                                </option>
                                <option value="Rabu" <?php echo $filter_hari == 'Rabu' ? 'selected' : ''; ?>>Rabu</option>
                                <option value="Kamis" <?php echo $filter_hari == 'Kamis' ? 'selected' : ''; ?>>Kamis
                                </option>
                                <option value="Jumat" <?php echo $filter_hari == 'Jumat' ? 'selected' : ''; ?>>Jumat
                                </option>
                                <option value="Sabtu" <?php echo $filter_hari == 'Sabtu' ? 'selected' : ''; ?>>Sabtu
                                </option>
                                <option value="Minggu" <?php echo $filter_hari == 'Minggu' ? 'selected' : ''; ?>>Minggu
                                </option>
                            </select>
                        </div>

                        <!-- Filter Tingkat -->
                        <div>
                            <label class="block text-sm font-medium text-gray-900 mb-1">
                                Filter Tingkat
                            </label>
                            <select name="tingkat"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Tingkat</option>
                                <option value="TK" <?php echo $filter_tingkat == 'TK' ? 'selected' : ''; ?>>TK</option>
                                <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD</option>
                                <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                <option value="Alumni" <?php echo $filter_tingkat == 'Alumni' ? 'selected' : ''; ?>>Alumni
                                </option>
                                <option value="Umum" <?php echo $filter_tingkat == 'Umum' ? 'selected' : ''; ?>>Umum
                                </option>
                            </select>
                        </div>

                        <!-- Filter Siswa dengan Search -->
                        <div>
                            <label class="block text-sm font-medium text-gray-900 mb-1">
                                Filter Berdasarkan Siswa
                            </label>
                            <div class="relative">
                                <input type="text" id="filterSearchSiswa"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="Ketik nama siswa..." autocomplete="off"
                                    value="<?php echo isset($_GET['nama_siswa']) ? htmlspecialchars($_GET['nama_siswa']) : ''; ?>">
                                <input type="hidden" name="siswa_id" id="filterSiswaId"
                                    value="<?php echo $filter_siswa; ?>">
                                <input type="hidden" name="nama_siswa" id="filterNamaSiswa"
                                    value="<?php echo isset($_GET['nama_siswa']) ? htmlspecialchars($_GET['nama_siswa']) : ''; ?>">
                                <button type="button" id="clearFilterSearch"
                                    class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 <?php echo $filter_siswa > 0 ? '' : 'hidden'; ?>">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div id="filterSiswaDropdown" class="filter-dropdown hidden"></div>
                        </div>
                    </div>

                    <div class="flex space-x-2 pt-1">
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                        <?php if ($filter_hari || $filter_siswa || $filter_tingkat): ?>
                            <a href="jadwalSiswa.php"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 flex items-center">
                                <i class="fas fa-times mr-2"></i> Reset Filter
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Tabel Jadwal Siswa -->
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-table mr-2"></i> Daftar Jadwal Siswa
                        <?php if ($filter_hari || $filter_siswa || $filter_tingkat): ?>
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                (Filter:
                                <?php
                                $filters = [];
                                if ($filter_hari)
                                    $filters[] = "Hari: $filter_hari";
                                if ($filter_tingkat)
                                    $filters[] = "Tingkat: $filter_tingkat";
                                if ($filter_siswa) {
                                    foreach ($semua_siswa_guru as $siswa) {
                                        if ($siswa['id'] == $filter_siswa) {
                                            $filters[] = "Siswa: " . $siswa['nama_lengkap'];
                                            break;
                                        }
                                    }
                                } elseif (!empty($filter_nama_siswa)) {
                                    $filters[] = "Siswa: $filter_nama_siswa";
                                }
                                echo implode(' | ', $filters);
                                ?>
                                )
                            </span>
                        <?php endif; ?>
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Total <?php echo $total_jadwal; ?> jadwal untuk <?php echo $total_siswa; ?> siswa
                    </p>
                </div>
                <div class="px-4 py-2 sm:p-6">
                    <?php if ($total_jadwal > 0): ?>
                        <div class="table-container">
                            <table class="table-jadwal">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Siswa</th>
                                        <th>Kelas Sekolah</th>
                                        <!-- <th>Sekolah Asal</th> -->
                                        <!-- <th>Mata Pelajaran</th> -->
                                        <th>Tingkat</th>
                                        <th>Jenis Kelas</th>
                                        <th>Hari</th>
                                        <th>Jam</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no = 1; ?>
                                    <?php foreach ($jadwal_siswa as $jadwal): ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($jadwal['nama_lengkap']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-medium"><?php echo $jadwal['kelas_sekolah']; ?></div>
                                            </td>
                                            <!-- <td>
                                                <div class="text-sm"><?php echo $jadwal['sekolah_asal'] ?? '-'; ?></div>
                                            </td> -->
                                            <!-- <td>
                                                <div class="font-medium"><?php echo $jadwal['mata_pelajaran']; ?></div>
                                            </td> -->
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo $jadwal['tingkat']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php echo $jadwal['jenis_kelas']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-purple"><?php echo $jadwal['hari']; ?></span>
                                            </td>
                                            <td>
                                                <div class="font-medium">
                                                    <?php echo $jadwal['jam_mulai_format']; ?> -
                                                    <?php echo $jadwal['jam_selesai_format']; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="flex space-x-2">
                                                    <button onclick="openEditModal(
                                                        <?php echo $jadwal['jadwal_id']; ?>,
                                                        '<?php echo $jadwal['hari']; ?>',
                                                        '<?php echo $jadwal['jam_mulai']; ?>',
                                                        '<?php echo $jadwal['jam_selesai']; ?>'
                                                    )"
                                                        class="px-3 py-1 bg-yellow-500 text-white rounded text-sm hover:bg-yellow-600">
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>
                                                    <button onclick="openHapusModal(
                                                        <?php echo $jadwal['jadwal_id']; ?>, 
                                                        '<?php echo htmlspecialchars(addslashes($jadwal['nama_lengkap'])); ?>', 
                                                        '<?php echo $jadwal['hari']; ?>', 
                                                        '<?php echo $jadwal['jam_mulai_format']; ?> - <?php echo $jadwal['jam_selesai_format']; ?>'
                                                    )"
                                                        class="px-3 py-1 bg-red-500 text-white rounded text-sm hover:bg-red-600">
                                                        <i class="fas fa-trash mr-1"></i> Hapus
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-times text-4xl mb-4"></i>
                            <h3 class="text-lg font-medium mb-2">Tidak ada jadwal ditemukan</h3>
                            <p class="mb-4">
                                <?php if ($filter_hari || $filter_siswa || $filter_tingkat): ?>
                                    Tidak ada jadwal yang sesuai dengan filter yang dipilih.
                                <?php else: ?>
                                    Belum ada jadwal belajar yang ditambahkan
                                <?php endif; ?>
                            </p>
                            <?php if (!$filter_hari && !$filter_siswa && !$filter_tingkat && count($siswa_tanpa_jadwal) > 0): ?>
                                <button onclick="openTambahModal()"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-plus mr-2"></i> Tambah Jadwal Pertama
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ringkasan Per Hari -->
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                    <i class="fas fa-chart-bar mr-2"></i> Distribusi Jadwal per Hari
                </h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
                    <?php
                    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
                    foreach ($days as $day):
                        $count = $jadwal_by_hari[$day] ?? 0;
                        ?>
                        <div
                            class="text-center p-4 border rounded-lg <?php echo $filter_hari == $day ? 'bg-blue-50 border-blue-300' : 'border-gray-200'; ?>">
                            <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $day; ?></div>
                            <div class="text-2xl font-bold <?php echo $count > 0 ? 'text-blue-600' : 'text-gray-400'; ?>">
                                <?php echo $count; ?>
                            </div>
                            <div class="text-xs text-gray-500">jadwal</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Siswa Tanpa Jadwal -->
            <?php if (count($siswa_tanpa_jadwal) > 0 && !$filter_hari && !$filter_siswa && !$filter_tingkat): ?>
                <div class="bg-white shadow rounded-lg p-6 mt-6">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i> Siswa Belum Terjadwal
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($siswa_tanpa_jadwal as $siswa): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                </div>
                                <div class="text-sm text-gray-600 mb-2">
                                    Kelas: <?php echo $siswa['kelas']; ?> | <?php echo $siswa['sekolah_asal'] ?? '-'; ?>
                                </div>
                                <button onclick="quickAddJadwal(<?php echo $siswa['id']; ?>)"
                                    class="mt-2 w-full px-3 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 text-sm transition-colors">
                                    <i class="fas fa-plus-circle mr-1"></i> Buat Jadwal
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Jadwal Siswa</p>
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

    <!-- Modal Tambah Jadwal - LANGSUNG PILIH SISWA -->
    <div id="modalTambah" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-plus-circle mr-2"></i> Tambah Jadwal Baru
                    </h3>
                    <button onclick="closeTambahModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form id="formTambahJadwal" method="POST" action="">
                    <div class="space-y-4">
                        <!-- Cari Siswa -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pilih Siswa <span class="text-red-500">*</span>
                            </label>
                            <div class="autocomplete-container">
                                <input type="text" id="searchSiswa" class="autocomplete-input"
                                    placeholder="Ketik nama siswa..." autocomplete="off" required>
                                <input type="hidden" name="siswa_id" id="selectedSiswaId">
                                <button type="button" id="clearSearch" class="autocomplete-clear">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div id="siswaDropdown" class="autocomplete-dropdown"></div>
                            </div>

                            <!-- Info siswa yang dipilih -->
                            <div id="selectedSiswaInfo" class="mt-2 p-3 bg-blue-50 rounded-lg hidden">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="font-medium text-gray-900" id="selectedSiswaName"></div>
                                        <div class="text-sm text-gray-600">
                                            Kelas: <span id="selectedSiswaKelas"></span> |
                                            Sekolah: <span id="selectedSiswaSekolah"></span>
                                        </div>
                                    </div>
                                    <button type="button" onclick="clearSelectedSiswa()"
                                        class="text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Pilih Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hari <span class="text-red-500">*</span>
                            </label>
                            <select name="hari" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Hari --</option>
                                <option value="Senin">Senin</option>
                                <option value="Selasa">Selasa</option>
                                <option value="Rabu">Rabu</option>
                                <option value="Kamis">Kamis</option>
                                <option value="Jumat">Jumat</option>
                                <option value="Sabtu">Sabtu</option>
                                <option value="Minggu">Minggu</option>
                            </select>
                        </div>

                        <!-- Jam Mulai & Selesai -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Mulai <span class="text-red-500">*</span>
                                </label>
                                <input type="time" name="jam_mulai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Selesai <span class="text-red-500">*</span>
                                </label>
                                <input type="time" name="jam_selesai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="bg-blue-50 p-3 rounded-lg text-sm text-blue-700">
                            <i class="fas fa-info-circle mr-1"></i>
                            Jadwal akan dibuat untuk pendaftaran aktif siswa ini.
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeTambahModal()"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                            Batal
                        </button>
                        <button type="submit" name="tambah_jadwal"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Simpan Jadwal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit Jadwal -->
    <div id="modalEdit" class="modal">
        <div class="modal-content">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-edit mr-2"></i> Edit Jadwal
                    </h3>
                    <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form id="formEditJadwal" method="POST" action="">
                    <input type="hidden" name="jadwal_id" id="editJadwalId">

                    <div class="space-y-4">
                        <!-- Informasi -->
                        <div class="bg-blue-50 p-3 rounded-lg">
                            <p class="text-sm text-gray-600 mb-1">
                                <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                                Edit jadwal hanya untuk mengubah hari dan jam.
                            </p>
                        </div>

                        <!-- Pilih Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hari <span class="text-red-500">*</span>
                            </label>
                            <select name="hari" id="editHari" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Hari --</option>
                                <option value="Senin">Senin</option>
                                <option value="Selasa">Selasa</option>
                                <option value="Rabu">Rabu</option>
                                <option value="Kamis">Kamis</option>
                                <option value="Jumat">Jumat</option>
                                <option value="Sabtu">Sabtu</option>
                                <option value="Minggu">Minggu</option>
                            </select>
                        </div>

                        <!-- Jam Mulai & Selesai -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Mulai <span class="text-red-500">*</span>
                                </label>
                                <input type="time" name="jam_mulai" id="editJamMulai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Selesai <span class="text-red-500">*</span>
                                </label>
                                <input type="time" name="jam_selesai" id="editJamSelesai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeEditModal()"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                            Batal
                        </button>
                        <button type="submit" name="edit_jadwal"
                            class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                            Update Jadwal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Konfirmasi Hapus -->
    <div id="modalHapus" class="modal-konfirmasi">
        <div class="modal-konfirmasi-content">
            <div class="modal-konfirmasi-header">
                <div class="modal-konfirmasi-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-1">Konfirmasi Hapus</h3>
                <p class="text-sm text-gray-600">Apakah Anda yakin ingin menghapus jadwal ini?</p>
            </div>

            <div class="modal-konfirmasi-body">
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-red-500 mt-0.5 mr-3"></i>
                        <div class="text-left">
                            <p class="text-sm font-medium text-red-800">Informasi Jadwal</p>
                            <div class="mt-1 text-sm text-red-700">
                                <p><span class="font-medium">Siswa:</span> <span id="hapusNamaSiswa"></span></p>
                                <p><span class="font-medium">Hari:</span> <span id="hapusHari"></span></p>
                                <p><span class="font-medium">Waktu:</span> <span id="hapusWaktu"></span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">
                    Tindakan ini tidak dapat dibatalkan. Jadwal yang dihapus akan dinonaktifkan dari sistem.
                </p>
            </div>

            <div class="modal-konfirmasi-footer">
                <button type="button" onclick="closeHapusModal()"
                    class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium transition-colors duration-200">
                    <i class="fas fa-times mr-2"></i> Batal
                </button>
                <form id="formHapusJadwal" method="POST" action="" style="display: inline;">
                    <input type="hidden" name="jadwal_id" id="hapusJadwalId">
                    <input type="hidden" name="hapus_jadwal" value="1">
                    <button type="submit"
                        class="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium transition-colors duration-200">
                        <i class="fas fa-trash mr-2"></i> Ya, Hapus
                    </button>
                </form>
            </div>
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

        // ==================== VARIABEL GLOBAL ====================
        let jadwalIdToDelete = null;
        let siswaData = [];
        let searchTimeout;
        let searchCache = {};

        // VARIABEL UNTUK FILTER SEARCH
        let filterSearchTimeout;
        let filterSelectedIndex = -1;

        // ==================== FUNGSI MODAL ====================
        function openTambahModal() {
            document.getElementById('modalTambah').classList.add('active');
            document.body.style.overflow = 'hidden';
            clearSelectedSiswa();
            setTimeout(() => {
                document.getElementById('searchSiswa').focus();
            }, 100);
        }

        function closeTambahModal() {
            document.getElementById('modalTambah').classList.remove('active');
            document.body.style.overflow = 'auto';
            document.getElementById('formTambahJadwal').reset();
            clearSelectedSiswa();
            searchCache = {};
        }

        function openEditModal(jadwalId, hari, jamMulai, jamSelesai) {
            document.getElementById('editJadwalId').value = jadwalId;
            document.getElementById('editHari').value = hari;
            document.getElementById('editJamMulai').value = jamMulai;
            document.getElementById('editJamSelesai').value = jamSelesai;

            document.getElementById('modalEdit').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('modalEdit').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function openHapusModal(jadwalId, namaSiswa, hari, waktu) {
            jadwalIdToDelete = jadwalId;

            document.getElementById('hapusNamaSiswa').textContent = namaSiswa;
            document.getElementById('hapusHari').textContent = hari;
            document.getElementById('hapusWaktu').textContent = waktu;
            document.getElementById('hapusJadwalId').value = jadwalId;

            document.getElementById('modalHapus').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeHapusModal() {
            document.getElementById('modalHapus').classList.remove('active');
            document.body.style.overflow = 'auto';
            jadwalIdToDelete = null;
        }

        // Quick add jadwal dari list siswa tanpa jadwal
        function quickAddJadwal(siswaId) {
            // Cari data siswa dari list
            const siswa = siswaData.find(s => s.id == siswaId);
            if (siswa) {
                selectSiswa({
                    id: siswa.id,
                    nama: siswa.nama_lengkap,
                    kelas: siswa.kelas,
                    sekolah: siswa.sekolah_asal
                });
                openTambahModal();
            }
        }

        // ==================== AUTOCOMPLETE UNTUK MODAL TAMBAH ====================
        function loadSiswaDataGuru() {
            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'get_siswa_list_guru'
                },
                dataType: 'json',
                success: function (data) {
                    siswaData = data;
                    console.log('Data siswa loaded:', siswaData.length, 'records');
                    initAutocompleteGuru();
                },
                error: function (xhr, status, error) {
                    console.error('Failed to load siswa data:', error);
                }
            });
        }

        function initAutocompleteGuru() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSearch');
            const dropdown = document.getElementById('siswaDropdown');
            const selectedSiswaId = document.getElementById('selectedSiswaId');

            if (!searchInput || !clearButton || !dropdown || !selectedSiswaId) return;

            let selectedIndex = -1;

            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) {
                    filterSiswa(this.value);
                }
            });

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
                        updateSelectedItem(items, selectedIndex);
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem(items, selectedIndex);
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

        function updateSelectedItem(items, selectedIndex) {
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');
            const cacheKey = query.toLowerCase();

            if (searchCache[cacheKey]) {
                renderDropdown(searchCache[cacheKey]);
                return;
            }

            const filtered = siswaData.filter(siswa =>
                siswa.nama_lengkap.toLowerCase().includes(cacheKey) ||
                siswa.kelas.toLowerCase().includes(cacheKey) ||
                (siswa.sekolah_asal && siswa.sekolah_asal.toLowerCase().includes(cacheKey))
            );

            searchCache[cacheKey] = filtered;
            renderDropdown(filtered);
        }

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

                item.innerHTML = `
                <div class="siswa-nama">${siswa.nama_lengkap}</div>
                <div class="siswa-info">
                    Kelas: ${siswa.kelas} | 
                    Sekolah: ${siswa.sekolah_asal || '-'}
                </div>
            `;

                item.addEventListener('click', function () {
                    selectSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.autocomplete-item');
                    const idx = Array.from(items).indexOf(this);
                    updateSelectedItem(items, idx);
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
        }

        function selectSiswa(data) {
            const selectedSiswaId = document.getElementById('selectedSiswaId');
            const searchInput = document.getElementById('searchSiswa');
            const dropdown = document.getElementById('siswaDropdown');

            selectedSiswaId.value = data.id;
            searchInput.value = data.nama;
            dropdown.style.display = 'none';

            document.getElementById('selectedSiswaName').textContent = data.nama;
            document.getElementById('selectedSiswaKelas').textContent = data.kelas;
            document.getElementById('selectedSiswaSekolah').textContent = data.sekolah || '-';

            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        function clearSelectedSiswa() {
            document.getElementById('searchSiswa').value = '';
            document.getElementById('selectedSiswaId').value = '';
            document.getElementById('selectedSiswaInfo').classList.add('hidden');
            document.getElementById('clearSearch').style.display = 'none';
        }

        // ==================== FILTER SEARCH SISWA ====================
        function initFilterSearch() {
            const searchInput = document.getElementById('filterSearchSiswa');
            const clearButton = document.getElementById('clearFilterSearch');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');

            if (!searchInput || !clearButton || !dropdown || !siswaIdInput || !namaSiswaInput) return;

            searchInput.addEventListener('input', function () {
                clearTimeout(filterSearchTimeout);
                const query = this.value.trim();

                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    clearButton.classList.add('hidden');
                    return;
                }

                filterSearchTimeout = setTimeout(() => {
                    filterSiswaList(query);
                }, 300);

                clearButton.classList.remove('hidden');
            });

            searchInput.addEventListener('focus', function () {
                if (this.value.length >= 2) {
                    filterSiswaList(this.value);
                }
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                siswaIdInput.value = '';
                namaSiswaInput.value = '';
                clearButton.classList.add('hidden');
                dropdown.classList.add('hidden');
                document.querySelector('form[method="GET"]').submit();
            });

            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.filter-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        filterSelectedIndex = Math.min(filterSelectedIndex + 1, items.length - 1);
                        updateFilterSelectedItem(items, filterSelectedIndex);
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        filterSelectedIndex = Math.max(filterSelectedIndex - 1, -1);
                        updateFilterSelectedItem(items, filterSelectedIndex);
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

        function updateFilterSelectedItem(items, selectedIndex) {
            items.forEach((item, i) => {
                if (i === selectedIndex) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        function filterSiswaList(query) {
            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'search_siswa_by_name',
                    keyword: query
                },
                dataType: 'json',
                success: function (data) {
                    renderFilterDropdown(data);
                },
                error: function (xhr, status, error) {
                    console.error('Error searching siswa:', error);
                }
            });
        }

        function renderFilterDropdown(data) {
            const dropdown = document.getElementById('filterSiswaDropdown');
            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="filter-item text-center text-gray-500">Tidak ada siswa ditemukan</div>';
                dropdown.classList.remove('hidden');
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'filter-item';
                if (index === 0) item.classList.add('active');
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;

                item.innerHTML = `
                <div class="font-medium">${siswa.nama_lengkap}</div>
                <div class="text-xs text-gray-600">
                    Kelas: ${siswa.kelas || '-'} | Sekolah: ${siswa.sekolah_asal || '-'}
                </div>
            `;

                item.addEventListener('click', function () {
                    selectFilterSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.filter-item');
                    const idx = Array.from(items).indexOf(this);
                    updateFilterSelectedItem(items, idx);
                });

                dropdown.appendChild(item);
            });

            dropdown.classList.remove('hidden');
            filterSelectedIndex = 0;
        }

        function selectFilterSiswa(data) {
            const searchInput = document.getElementById('filterSearchSiswa');
            const siswaIdInput = document.getElementById('filterSiswaId');
            const namaSiswaInput = document.getElementById('filterNamaSiswa');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const clearButton = document.getElementById('clearFilterSearch');

            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            namaSiswaInput.value = data.nama;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');

            document.querySelector('form[method="GET"]').submit();
        }

        // ==================== VALIDASI FORM ====================
        document.getElementById('formTambahJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.querySelector('#formTambahJadwal input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('#formTambahJadwal input[name="jam_selesai"]').value;
            const siswaId = document.getElementById('selectedSiswaId').value;
            const hari = document.querySelector('#formTambahJadwal select[name="hari"]').value;

            if (!siswaId) {
                e.preventDefault();
                alert('Silakan pilih siswa terlebih dahulu!');
                document.getElementById('searchSiswa').focus();
                return;
            }

            if (!hari) {
                e.preventDefault();
                alert('Silakan pilih hari terlebih dahulu!');
                return;
            }

            if (!jamMulai || !jamSelesai) {
                e.preventDefault();
                alert('Jam mulai dan selesai harus diisi!');
                return;
            }

            if (jamSelesai <= jamMulai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai!');
            }
        });

        document.getElementById('formEditJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.getElementById('editJamMulai').value;
            const jamSelesai = document.getElementById('editJamSelesai').value;
            const hari = document.getElementById('editHari').value;

            if (!hari) {
                e.preventDefault();
                alert('Silakan pilih hari terlebih dahulu!');
                return;
            }

            if (!jamMulai || !jamSelesai) {
                e.preventDefault();
                alert('Jam mulai dan selesai harus diisi!');
                return;
            }

            if (jamSelesai <= jamMulai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai!');
            }
        });

        // ==================== CLOSE MODAL ON ESCAPE ====================
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTambahModal();
                closeEditModal();
                closeHapusModal();
            }
        });

        // ==================== CLOSE MODAL ON CLICK OUTSIDE ====================
        document.querySelectorAll('.modal, .modal-konfirmasi').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    if (this.id === 'modalTambah') closeTambahModal();
                    if (this.id === 'modalEdit') closeEditModal();
                    if (this.id === 'modalHapus') closeHapusModal();
                }
            });
        });

        // ==================== INITIALIZATION ====================
        $(document).ready(function () {
            loadSiswaDataGuru();
            initFilterSearch();
        });
    </script>
</body>

</html>