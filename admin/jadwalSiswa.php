<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Matikan display error di VPS
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log'); // Pastikan folder logs writable

require_once '../includes/config.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['user_role'] != 'admin') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$admin_id = (int) $_SESSION['user_id'];
$full_name = htmlspecialchars($_SESSION['full_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');
$currentPage = basename($_SERVER['PHP_SELF']);

// FUNGSI LOGGING
function writeLog($message, $type = 'INFO')
{
    $log_message = date('Y-m-d H:i:s') . " [$type] " . $message . PHP_EOL;
    error_log($log_message);
}

// FUNGSI HELPER UNTUK DATABASE DENGAN ERROR HANDLING LEBIH BAIK
function executeQuery($conn, $sql, $params = [], $types = "")
{
    try {
        if (!$conn) {
            throw new Exception("Koneksi database tidak tersedia");
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Query preparation failed: " . $conn->error . " - SQL: " . $sql);
        }

        if (!empty($params)) {
            if (strlen($types) !== count($params)) {
                throw new Exception("Parameter count mismatch. Types: {$types}, Params: " . count($params));
            }
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            throw new Exception("Query execution failed: " . $stmt->error);
        }

        return $stmt;

    } catch (Exception $e) {
        writeLog($e->getMessage(), 'ERROR');
        throw $e;
    }
}

// FUNGSI UNTUK MENCARI ATAU MEMBUAT SESI GURU
function cariAtauBuatSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai)
{
    try {
        if (!is_numeric($guru_id) || $guru_id <= 0) {
            return ['success' => false, 'error' => 'Guru ID tidak valid'];
        }

        if (!in_array($hari, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])) {
            return ['success' => false, 'error' => 'Hari tidak valid'];
        }

        // Validasi format jam
        if (
            !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $jam_mulai) ||
            !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $jam_selesai)
        ) {
            return ['success' => false, 'error' => 'Format jam tidak valid'];
        }

        // Cek apakah sudah ada sesi
        $sql_cek = "SELECT id
                    FROM sesi_mengajar_guru 
                    WHERE guru_id = ? 
                    AND hari = ? 
                    AND jam_mulai = ? 
                    AND jam_selesai = ?
                    AND status != 'tidak_aktif'";

        $stmt_cek = executeQuery($conn, $sql_cek, [$guru_id, $hari, $jam_mulai, $jam_selesai], "isss");
        $result_cek = $stmt_cek->get_result();

        if ($result_cek->num_rows > 0) {
            $sesi_data = $result_cek->fetch_assoc();
            $stmt_cek->close();
            writeLog("Sesi ditemukan untuk guru $guru_id di $hari $jam_mulai-$jam_selesai", 'INFO');
            return [
                'success' => true,
                'sesi_id' => $sesi_data['id'],
                'is_new' => false
            ];
        }
        $stmt_cek->close();

        // Buat sesi baru - tanpa kapasitas_maks dan kapasitas_terisi
        $sql_insert = "INSERT INTO sesi_mengajar_guru 
              (guru_id, hari, jam_mulai, jam_selesai, status, created_at) 
              VALUES (?, ?, ?, ?, 'tersedia', NOW())";

        $stmt_insert = executeQuery($conn, $sql_insert, [$guru_id, $hari, $jam_mulai, $jam_selesai], "isss");
        $sesi_id = $stmt_insert->insert_id;
        $stmt_insert->close();

        writeLog("Sesi baru dibuat untuk guru $guru_id di $hari $jam_mulai-$jam_selesai (ID: $sesi_id)", 'INFO');

        return [
            'success' => true,
            'sesi_id' => $sesi_id,
            'is_new' => true
        ];

    } catch (Exception $e) {
        writeLog("Error cariAtauBuatSesiGuru: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// FILTER PARAMETER DENGAN VALIDASI
$filter_hari = isset($_GET['hari']) && in_array($_GET['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])
    ? $_GET['hari']
    : '';

$filter_tingkat = isset($_GET['tingkat']) && in_array($_GET['tingkat'], ['TK', 'SD', 'SMP', 'SMA', 'Alumni', 'Umum'])
    ? $_GET['tingkat']
    : '';

$filter_siswa = isset($_GET['siswa_id']) && is_numeric($_GET['siswa_id']) ? (int) $_GET['siswa_id'] : 0;
$filter_nama_siswa = isset($_GET['nama_siswa']) ? trim($_GET['nama_siswa']) : '';
$filter_guru = isset($_GET['guru_id']) && is_numeric($_GET['guru_id']) ? (int) $_GET['guru_id'] : 0;

// AMBIL DATA UNTUK FILTER DROPDOWN
$siswa_list = [];
$guru_list = [];

try {
    // Daftar siswa aktif
    $sql_siswa = "SELECT s.id, s.nama_lengkap, s.kelas, s.sekolah_asal,
                         GROUP_CONCAT(DISTINCT CONCAT(o.nama_ortu, ' (', o.hubungan_dengan_siswa, ')') SEPARATOR ', ') as info_ortu
                  FROM siswa s
                  LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                  LEFT JOIN orangtua o ON so.orangtua_id = o.id
                  WHERE s.status = 'aktif' 
                  GROUP BY s.id
                  ORDER BY s.nama_lengkap";

    $result_siswa = $conn->query($sql_siswa);
    if ($result_siswa) {
        while ($row = $result_siswa->fetch_assoc()) {
            $siswa_list[] = $row;
        }
    }

    // Daftar guru aktif
    $sql_guru = "SELECT g.id, u.full_name, g.bidang_keahlian
                FROM guru g 
                INNER JOIN users u ON g.user_id = u.id 
                WHERE g.status = 'aktif' 
                ORDER BY u.full_name";
    $result_guru = $conn->query($sql_guru);
    if ($result_guru) {
        while ($row = $result_guru->fetch_assoc()) {
            $guru_list[] = $row;
        }
    }

    writeLog("Data filter loaded: " . count($siswa_list) . " siswa, " . count($guru_list) . " guru", 'INFO');

} catch (Exception $e) {
    writeLog("Error fetching filter data: " . $e->getMessage(), 'ERROR');
}

// QUERY JADWAL SISWA
$jadwal_siswa = [];
$where_conditions = ["jb.status = 'aktif'", "ps.status = 'aktif'"];
$filter_params = [];
$types = "";

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

if ($filter_guru > 0) {
    $where_conditions[] = "smg.guru_id = ?";
    $filter_params[] = $filter_guru;
    $types .= "i";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$sql_jadwal = "
    SELECT 
        jb.id as jadwal_id,
        jb.created_at,
        jb.updated_at,
        jb.siswa_pelajaran_id,
        smg.id as sesi_id,
        smg.hari,
        smg.jam_mulai,
        smg.jam_selesai,
        smg.status as status_sesi,
        ps.id as pendaftaran_id,
        ps.jenis_kelas,
        ps.tingkat,
        s.id as siswa_id,
        s.nama_lengkap as nama_siswa,
        s.kelas as kelas_sekolah,
        s.sekolah_asal,
        COALESCE(sp.nama_pelajaran, 'Bimbingan Umum') as nama_pelajaran,
        sp.id as pelajaran_id,
        sp.guru_id as pelajaran_guru_id,
        g.id as guru_id,
        u.full_name as nama_guru,
        g.bidang_keahlian,
        GROUP_CONCAT(DISTINCT CONCAT(o.nama_ortu, ' (', o.hubungan_dengan_siswa, ')') SEPARATOR '; ') as daftar_ortu,
        GROUP_CONCAT(DISTINCT o.no_hp SEPARATOR '; ') as daftar_hp_ortu,
        DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, smg.jam_mulai, smg.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
    INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
    INNER JOIN siswa s ON ps.siswa_id = s.id
    LEFT JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    LEFT JOIN guru g ON smg.guru_id = g.id
    LEFT JOIN users u ON g.user_id = u.id
    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
    LEFT JOIN orangtua o ON so.orangtua_id = o.id
    $where_clause
    GROUP BY jb.id, smg.id, ps.id, s.id, g.id, u.id, sp.id
    ORDER BY 
        FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
        smg.jam_mulai,
        s.nama_lengkap
";

try {
    $stmt = executeQuery($conn, $sql_jadwal, $filter_params, $types);
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $jadwal_siswa[] = $row;
    }
    $stmt->close();

    writeLog("Jadwal siswa ditemukan: " . count($jadwal_siswa) . " records", 'INFO');

} catch (Exception $e) {
    writeLog("Error fetching jadwal siswa: " . $e->getMessage(), 'ERROR');
    $_SESSION['error_message'] = "Terjadi kesalahan saat memuat data jadwal";
    $jadwal_siswa = [];
}

// HITUNG STATISTIK
$total_jadwal = count($jadwal_siswa);
$total_siswa = count(array_unique(array_column($jadwal_siswa, 'siswa_id')));
$total_guru = count(array_unique(array_column($jadwal_siswa, 'guru_id')));

// HITUNG DISTRIBUSI PER HARI
$jadwal_by_hari = [
    'Senin' => 0,
    'Selasa' => 0,
    'Rabu' => 0,
    'Kamis' => 0,
    'Jumat' => 0,
    'Sabtu' => 0,
    'Minggu' => 0
];

// HITUNG DISTRIBUSI PER TINGKAT
$jadwal_by_tingkat = [
    'TK' => 0,
    'SD' => 0,
    'SMP' => 0,
    'SMA' => 0,
    'Alumni' => 0,
    'Umum' => 0
];

foreach ($jadwal_siswa as $jadwal) {
    if (isset($jadwal['hari']) && array_key_exists($jadwal['hari'], $jadwal_by_hari)) {
        $jadwal_by_hari[$jadwal['hari']]++;
    }

    if (isset($jadwal['tingkat']) && array_key_exists($jadwal['tingkat'], $jadwal_by_tingkat)) {
        $jadwal_by_tingkat[$jadwal['tingkat']]++;
    }
}

// PROSES TAMBAH JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_jadwal'])) {

    // Validasi CSRF sederhana
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid CSRF token";
        header("Location: jadwalSiswa.php");
        exit();
    }

    // Validasi input
    $siswa_id = isset($_POST['siswa_id']) && is_numeric($_POST['siswa_id'])
        ? (int) $_POST['siswa_id']
        : 0;

    $guru_id = isset($_POST['guru_id']) && is_numeric($_POST['guru_id'])
        ? (int) $_POST['guru_id']
        : 0;

    $hari = isset($_POST['hari']) && in_array($_POST['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])
        ? $_POST['hari']
        : '';

    $jam_mulai = isset($_POST['jam_mulai']) ? trim($_POST['jam_mulai']) : '';
    $jam_selesai = isset($_POST['jam_selesai']) ? trim($_POST['jam_selesai']) : '';

    // Validasi
    $errors = [];
    if ($siswa_id <= 0)
        $errors[] = "Siswa harus dipilih";
    if ($guru_id <= 0)
        $errors[] = "Guru harus dipilih";
    if (empty($hari))
        $errors[] = "Hari harus dipilih";
    if (empty($jam_mulai))
        $errors[] = "Jam mulai harus diisi";
    if (empty($jam_selesai))
        $errors[] = "Jam selesai harus diisi";

    if ($jam_mulai && $jam_selesai && $jam_selesai <= $jam_mulai) {
        $errors[] = "Jam selesai harus setelah jam mulai";
    }

    if (!empty($errors)) {
        $_SESSION['error_message'] = implode(", ", $errors);
        writeLog("Tambah jadwal gagal validasi: " . implode(", ", $errors), 'WARNING');
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            writeLog("Memulai proses tambah jadwal - Siswa ID: $siswa_id, Guru ID: $guru_id, Hari: $hari", 'INFO');

            // 1. Cek apakah siswa memiliki pendaftaran aktif
            $sql_cek_pendaftaran = "SELECT id, jenis_kelas, tingkat 
                                   FROM pendaftaran_siswa 
                                   WHERE siswa_id = ? AND status = 'aktif'
                                   LIMIT 1";

            $stmt_cek = executeQuery($conn, $sql_cek_pendaftaran, [$siswa_id], "i");
            $result_cek = $stmt_cek->get_result();

            if ($result_cek->num_rows === 0) {
                throw new Exception("Siswa tidak memiliki pendaftaran aktif");
            }

            $pendaftaran = $result_cek->fetch_assoc();
            $pendaftaran_id = (int) $pendaftaran['id'];
            $stmt_cek->close();

            // 2. Cari atau buat sesi guru
            $sesi_result = cariAtauBuatSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai);

            if (!$sesi_result['success']) {
                throw new Exception($sesi_result['error']);
            }

            $sesi_guru_id = $sesi_result['sesi_id'];

            // 3. Cek konflik jadwal siswa
            $sql_cek_konflik = "SELECT COUNT(*) as jumlah 
                               FROM jadwal_belajar jb
                               INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               WHERE jb.pendaftaran_id = ? 
                               AND smg.hari = ? 
                               AND jb.status = 'aktif'
                               AND (
                                   (smg.jam_mulai < ? AND smg.jam_selesai > ?) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai)
                               )";

            $stmt_konflik = executeQuery($conn, $sql_cek_konflik, [
                $pendaftaran_id,
                $hari,
                $jam_selesai,
                $jam_mulai,
                $jam_mulai,
                $jam_selesai
            ], "isssss");

            $result_konflik = $stmt_konflik->get_result();
            $data_konflik = $result_konflik->fetch_assoc();
            $stmt_konflik->close();

            if ($data_konflik['jumlah'] > 0) {
                throw new Exception("Siswa sudah memiliki jadwal lain pada waktu tersebut");
            }

            // 4. Insert jadwal baru
            $sql_tambah = "INSERT INTO jadwal_belajar 
              (pendaftaran_id, siswa_pelajaran_id, sesi_guru_id, status, created_at) 
              VALUES (?, NULL, ?, 'aktif', NOW())";

            $stmt_tambah = executeQuery($conn, $sql_tambah, [$pendaftaran_id, $sesi_guru_id], "ii");
            $jadwal_baru_id = $stmt_tambah->insert_id;
            $stmt_tambah->close();

            $conn->commit();

            writeLog("Jadwal berhasil ditambahkan - ID: $jadwal_baru_id, Sesi: $sesi_guru_id", 'INFO');

            $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            writeLog("Error tambah jadwal: " . $e->getMessage(), 'ERROR');
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// PROSES EDIT JADWAL - VERSI DENGAN GANTI GURU
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_jadwal'])) {

    // Validasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid CSRF token";
        header("Location: jadwalSiswa.php");
        exit();
    }

    $jadwal_id = isset($_POST['jadwal_id']) && is_numeric($_POST['jadwal_id']) ? (int) $_POST['jadwal_id'] : 0;
    $guru_id_baru = isset($_POST['guru_id']) && is_numeric($_POST['guru_id']) ? (int) $_POST['guru_id'] : 0;
    $hari = isset($_POST['hari']) && in_array($_POST['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']) ? $_POST['hari'] : '';
    $jam_mulai = isset($_POST['jam_mulai']) ? trim($_POST['jam_mulai']) : '';
    $jam_selesai = isset($_POST['jam_selesai']) ? trim($_POST['jam_selesai']) : '';

    if ($jadwal_id <= 0 || $guru_id_baru <= 0 || empty($hari) || empty($jam_mulai) || empty($jam_selesai)) {
        $_SESSION['error_message'] = "Data tidak lengkap!";
    } elseif ($jam_selesai <= $jam_mulai) {
        $_SESSION['error_message'] = "Jam selesai harus setelah jam mulai!";
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            writeLog("Memulai proses edit jadwal ID: $jadwal_id dengan guru baru ID: $guru_id_baru", 'INFO');

            // 1. Ambil data jadwal lama
            $sql_get = "SELECT jb.pendaftaran_id, smg.guru_id as guru_id_lama, 
                               jb.sesi_guru_id as sesi_lama
                       FROM jadwal_belajar jb
                       INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                       WHERE jb.id = ? AND jb.status = 'aktif'";

            $stmt_get = executeQuery($conn, $sql_get, [$jadwal_id], "i");
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                throw new Exception("Jadwal tidak ditemukan atau sudah tidak aktif");
            }

            $old_data = $result_get->fetch_assoc();
            $stmt_get->close();

            $pendaftaran_id = (int) $old_data['pendaftaran_id'];
            $guru_id_lama = (int) $old_data['guru_id_lama'];
            $sesi_lama = (int) $old_data['sesi_lama'];

            // 2. Cari atau buat sesi baru dengan guru yang baru dipilih
            $sesi_result = cariAtauBuatSesiGuru($conn, $guru_id_baru, $hari, $jam_mulai, $jam_selesai);

            if (!$sesi_result['success']) {
                throw new Exception($sesi_result['error']);
            }

            $sesi_baru_id = $sesi_result['sesi_id'];

            // 3. Cek apakah sesi baru sama dengan sesi lama
            if ($sesi_baru_id == $sesi_lama) {
                // Sesi sama, tidak ada perubahan
                $conn->rollback();
                $_SESSION['success_message'] = "Tidak ada perubahan pada jadwal.";
                header("Location: jadwalSiswa.php");
                exit();
            }

            // 4. Cek konflik jadwal siswa (termasuk dengan guru yang berbeda)
            $sql_cek_konflik = "SELECT COUNT(*) as jumlah 
                               FROM jadwal_belajar jb
                               INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               WHERE jb.pendaftaran_id = ? 
                               AND smg.hari = ? 
                               AND jb.id != ?
                               AND jb.status = 'aktif'
                               AND (
                                   (smg.jam_mulai < ? AND smg.jam_selesai > ?) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai)
                               )";

            $stmt_konflik = executeQuery($conn, $sql_cek_konflik, [
                $pendaftaran_id,
                $hari,
                $jadwal_id,
                $jam_selesai,
                $jam_mulai,
                $jam_mulai,
                $jam_selesai
            ], "isiisss");

            $result_konflik = $stmt_konflik->get_result();
            $data_konflik = $result_konflik->fetch_assoc();
            $stmt_konflik->close();

            if ($data_konflik['jumlah'] > 0) {
                throw new Exception("Siswa sudah memiliki jadwal lain pada waktu tersebut");
            }

            // 5. Update jadwal dengan sesi baru
            $sql_update = "UPDATE jadwal_belajar 
                          SET sesi_guru_id = ?, updated_at = NOW() 
                          WHERE id = ?";

            $stmt_update = executeQuery($conn, $sql_update, [$sesi_baru_id, $jadwal_id], "ii");
            $stmt_update->close();

            $conn->commit();

            writeLog("Jadwal ID $jadwal_id berhasil diupdate: sesi lama $sesi_lama -> sesi baru $sesi_baru_id (guru: $guru_id_lama -> $guru_id_baru)", 'INFO');

            $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            writeLog("Error edit jadwal: " . $e->getMessage(), 'ERROR');
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// PROSES HAPUS JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hapus_jadwal'])) {

    // Validasi CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid CSRF token";
        header("Location: jadwalSiswa.php");
        exit();
    }

    $jadwal_id = isset($_POST['jadwal_id']) && is_numeric($_POST['jadwal_id']) ? (int) $_POST['jadwal_id'] : 0;

    if ($jadwal_id <= 0) {
        $_SESSION['error_message'] = "ID jadwal tidak valid!";
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            writeLog("Memulai proses hapus jadwal ID: $jadwal_id", 'INFO');

            // 1. Ambil data sesi
            $sql_get_sesi = "SELECT sesi_guru_id FROM jadwal_belajar WHERE id = ? AND status = 'aktif'";
            $stmt_get = executeQuery($conn, $sql_get_sesi, [$jadwal_id], "i");
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                throw new Exception("Jadwal tidak ditemukan atau sudah tidak aktif");
            }

            $data = $result_get->fetch_assoc();
            $sesi_guru_id = (int) $data['sesi_guru_id'];
            $stmt_get->close();

            // 2. Hapus jadwal (soft delete)
            $sql_hapus = "UPDATE jadwal_belajar SET status = 'non-aktif' WHERE id = ?";
            $stmt_hapus = executeQuery($conn, $sql_hapus, [$jadwal_id], "i");
            $affected_rows = $stmt_hapus->affected_rows;
            $stmt_hapus->close();

            if ($affected_rows === 0) {
                throw new Exception("Gagal menghapus jadwal");
            }

            $conn->commit();

            writeLog("Jadwal ID $jadwal_id berhasil dihapus (dinonaktifkan)", 'INFO');

            $_SESSION['success_message'] = "Jadwal berhasil dihapus!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            writeLog("Error hapus jadwal: " . $e->getMessage(), 'ERROR');
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// AJAX HANDLERS - LENGKAP
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['ajax']) {
            case 'get_siswa_list':
                // ISI KODE INI
                $siswa_list_ajax = [];
                $sql = "SELECT 
                        s.id, 
                        s.nama_lengkap, 
                        s.kelas,
                        s.sekolah_asal,
                        GROUP_CONCAT(DISTINCT CONCAT(o.nama_ortu, ' (', o.hubungan_dengan_siswa, ')') SEPARATOR '; ') as orangtua_info
                    FROM siswa s
                    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                    LEFT JOIN orangtua o ON so.orangtua_id = o.id
                    WHERE s.status = 'aktif' 
                    GROUP BY s.id
                    ORDER BY s.nama_lengkap";
                $result = $conn->query($sql);
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $siswa_list_ajax[] = $row;
                    }
                }
                echo json_encode($siswa_list_ajax);
                break;

            case 'get_jadwal_detail':
                // ISI KODE INI
                if (!isset($_GET['jadwal_id']) || !is_numeric($_GET['jadwal_id'])) {
                    echo json_encode(['success' => false, 'error' => 'ID jadwal tidak valid']);
                    break;
                }
                $jadwal_id = (int) $_GET['jadwal_id'];
                $sql = "SELECT 
                        jb.id,
                        jb.pendaftaran_id,
                        s.id as siswa_id,
                        s.nama_lengkap as siswa_nama,
                        s.kelas as siswa_kelas,
                        s.sekolah_asal as siswa_sekolah,
                        smg.guru_id,
                        u.full_name as guru_nama,
                        smg.hari,
                        smg.jam_mulai,
                        smg.jam_selesai
                        FROM jadwal_belajar jb
                        INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
                        INNER JOIN siswa s ON ps.siswa_id = s.id
                        INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                        LEFT JOIN guru g ON smg.guru_id = g.id
                        LEFT JOIN users u ON g.user_id = u.id
                        WHERE jb.id = ? AND jb.status = 'aktif'";
                $stmt = executeQuery($conn, $sql, [$jadwal_id], "i");
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
                        'guru_nama' => $data['guru_nama'],
                        'hari' => $data['hari'],
                        'jam_mulai' => $data['jam_mulai'],
                        'jam_selesai' => $data['jam_selesai']
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Jadwal tidak ditemukan']);
                }
                $stmt->close();
                break;

            case 'search_siswa_by_name':
                // KODE YANG SUDAH ADA (sudah benar)
                $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
                if (empty($keyword) || strlen($keyword) < 2) {
                    echo json_encode([]);
                    break;
                }
                $sql = "SELECT s.id, s.nama_lengkap, s.kelas, s.sekolah_asal,
                               GROUP_CONCAT(DISTINCT CONCAT(o.nama_ortu, ' (', o.hubungan_dengan_siswa, ')') SEPARATOR '; ') as orangtua_info
                        FROM siswa s
                        LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
                        LEFT JOIN orangtua o ON so.orangtua_id = o.id
                        WHERE s.status = 'aktif' AND s.nama_lengkap LIKE ?
                        GROUP BY s.id
                        ORDER BY s.nama_lengkap
                        LIMIT 10";
                $search_term = "%" . $keyword . "%";
                $stmt = executeQuery($conn, $sql, [$search_term], "s");
                $result = $stmt->get_result();
                $results = [];
                while ($row = $result->fetch_assoc()) {
                    $results[] = $row;
                }
                $stmt->close();
                echo json_encode($results);
                break;

            default:
                echo json_encode(['error' => 'Action not found']);
        }
        exit();
    } catch (Exception $e) {
        writeLog("Error AJAX handler: " . $e->getMessage(), 'ERROR');
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Jadwal Siswa - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Semua CSS tetap sama */
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
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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
            border-radius: 6px;
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

        .badge-orange {
            background-color: #ffedd5;
            color: #ea580c;
        }

        .badge-info {
            background-color: #d1f5ff;
            color: #0369a1;
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

            .modal-konfirmasi-content {
                width: 95%;
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Admin</p>
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
                        <i class="fas fa-calendar-alt mr-2"></i>Jadwal Siswa
                    </h1>
                    <p class="text-gray-600">
                        Kelola jadwal belajar semua siswa
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
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

                <!-- Total Guru -->
                <div class="stat-card bg-white p-5 rounded-xl shadow">
                    <div class="flex items-center">
                        <div class="p-3 bg-purple-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-chalkboard-teacher text-purple-600 text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-gray-600 text-sm md:text-base">Total Guru</p>
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_guru); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Horizontal Sederhana -->
            <div class="bg-white overflow-hidden shadow rounded-lg p-5 mb-6">
                <form method="GET" action="" class="space-y-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-medium text-gray-900">
                            <i class="fas fa-filter mr-2"></i>Filter Jadwal
                        </h3>
                        <span class="text-xs text-gray-500">
                            <?php echo $total_jadwal; ?> data
                        </span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                        <!-- Filter Hari -->
                        <div>
                            <select name="hari"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                            <select name="tingkat"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Tingkat</option>
                                <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD</option>
                                <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                <option value="TK" <?php echo $filter_tingkat == 'TK' ? 'selected' : ''; ?>>TK</option>
                                <option value="Alumni" <?php echo $filter_tingkat == 'Alumni' ? 'selected' : ''; ?>>Alumni
                                </option>
                                <option value="Umum" <?php echo $filter_tingkat == 'Umum' ? 'selected' : ''; ?>>Umum
                                </option>
                            </select>
                        </div>

                        <!-- Filter Siswa dengan Search -->
                        <div>
                            <!-- <label class="block text-xs text-gray-600 mb-1">Cari Siswa</label> -->
                            <div class="relative">
                                <input type="text" id="filterSearchSiswa"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                            <div id="filterSiswaDropdown"
                                class="absolute z-999 w-20 bg-white mt-1 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                            </div>
                        </div>

                        <!-- Filter Guru -->
                        <?php if (count($guru_list) > 0): ?>
                            <div>
                                <select name="guru_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Semua Guru</option>
                                    <?php foreach ($guru_list as $guru): ?>
                                        <option value="<?php echo $guru['id']; ?>" <?php echo $filter_guru == $guru['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($guru['full_name']); ?>
                                            <?php if ($guru['bidang_keahlian']): ?>(<?php echo $guru['bidang_keahlian']; ?>)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>



                    <div class="flex justify-end space-x-2 pt-2">
                        <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru): ?>
                            <a href="jadwalSiswa.php"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm flex items-center">
                                <i class="fas fa-redo mr-2"></i> Reset
                            </a>
                        <?php endif; ?>

                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm flex items-center">
                            <i class="fas fa-search mr-2"></i> Cari
                        </button>
                    </div>
                </form>

            </div>

            <!-- Tabel Jadwal Siswa -->
            <div class="bg-white shadow rounded-lg mb-8">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg font-medium leading-6 text-gray-900">
                        <i class="fas fa-table mr-2"></i> Daftar Jadwal Bimbingan Siswa
                        <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru): ?>
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                (Filter:
                                <?php
                                $filters = [];
                                if ($filter_hari)
                                    $filters[] = "Hari: $filter_hari";
                                if ($filter_tingkat)
                                    $filters[] = "Tingkat: $filter_tingkat";
                                if ($filter_siswa) {
                                    foreach ($siswa_list as $siswa) {
                                        if ($siswa['id'] == $filter_siswa) {
                                            $filters[] = "Siswa: " . $siswa['nama_lengkap'];
                                            break;
                                        }
                                    }
                                }
                                if ($filter_guru) {
                                    foreach ($guru_list as $guru) {
                                        if ($guru['id'] == $filter_guru) {
                                            $filters[] = "Guru: " . $guru['full_name'];
                                            break;
                                        }
                                    }
                                }
                                echo implode(' | ', $filters);
                                ?>
                                )
                            </span>
                        <?php endif; ?>
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Total <?php echo $total_jadwal; ?> jadwal ditemukan
                    </p>
                </div>
                <div class="px-4 py-2 sm:p-6">
                    <!-- Tabel Jadwal Siswa -->
                    <?php if ($total_jadwal > 0): ?>
                        <div class="table-container">
                            <table class="table-jadwal">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Siswa</th>
                                        <!-- <th>Keterangan</th> -->
                                        <th>Tingkat</th>
                                        <th>Guru</th>
                                        <th>Hari</th>
                                        <th>Jam</th>
                                        <!-- <th>Durasi</th> -->
                                        <th>Orang Tua</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = 1;
                                    foreach ($jadwal_siswa as $jadwal):
                                        $durasi = $jadwal['durasi_menit'] / 60;
                                        ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($jadwal['nama_siswa']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">Kelas:
                                                    <?php echo $jadwal['kelas_sekolah']; ?>
                                                </div>
                                                <div class="text-xs text-gray-500"><?php echo $jadwal['sekolah_asal']; ?></div>
                                            </td>
                                            <!-- <td>
                                                <div class="font-medium">
                                                    <?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?>
                                                </div>
                                                <span
                                                    class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php echo $jadwal['jenis_kelas']; ?>
                                                </span>
                                                <?php if (empty($jadwal['siswa_pelajaran_id'])): ?>
                                                    <span class="badge badge-info ml-1">Fleksibel</span>
                                                <?php endif; ?>
                                            </td> -->
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo $jadwal['tingkat']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($jadwal['nama_guru'])): ?>
                                                    <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_guru']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo $jadwal['bidang_keahlian'] ?? '-'; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-500">Belum ditugaskan</span>
                                                <?php endif; ?>
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
                                            <!-- <td>
                                                <span class="badge badge-info">
                                                    <?php echo number_format($durasi, 1); ?> jam
                                                </span>
                                            </td> -->
                                            <td>
                                                <?php if (!empty($jadwal['daftar_ortu'])): ?>
                                                    <div class="text-sm">
                                                        <div><?php echo htmlspecialchars($jadwal['daftar_ortu']); ?></div>
                                                        <?php if (!empty($jadwal['daftar_hp_ortu'])): ?>
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                <i
                                                                    class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($jadwal['daftar_hp_ortu']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-500">-</span>
                                                <?php endif; ?>
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
                                                        '<?php echo htmlspecialchars(addslashes($jadwal['nama_siswa'])); ?>', 
                                                        '<?php echo htmlspecialchars(addslashes($jadwal['nama_guru'] ?? 'Belum ditugaskan')); ?>', 
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
                        <div class="text-center py-12">
                            <div class="text-gray-400 mb-4">
                                <i class="fas fa-calendar-times text-6xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Tidak Ada Jadwal</h3>
                            <p class="text-gray-500 mb-4">
                                <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru): ?>
                                    Tidak ada jadwal yang sesuai dengan filter yang dipilih.
                                    <br>Coba atur ulang filter atau pilih kriteria lain.
                                <?php else: ?>
                                    Belum ada jadwal siswa yang ditambahkan.
                                    <br>Klik tombol "Tambah Jadwal" untuk membuat jadwal baru.
                                <?php endif; ?>
                            </p>
                            <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru): ?>
                                <a href="jadwalSiswa.php"
                                    class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                                    <i class="fas fa-redo mr-2"></i> Reset Filter
                                </a>
                            <?php else: ?>
                                <button onclick="openTambahModal()"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-plus mr-2"></i> Tambah Jadwal Baru
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ringkasan Statistik -->
            <?php if ($total_jadwal > 0): ?>
                <div class="grid grid-cols-1 gap-6 mb-8">
                    <!-- Distribusi per Hari -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                            <i class="fas fa-chart-bar mr-2"></i> Distribusi Jadwal per Hari
                        </h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
                            <?php
                            $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
                            foreach ($days as $day):
                                $count = $jadwal_by_hari[$day] ?? 0;
                                $is_filtered = $filter_hari == $day;
                                $percentage = $total_jadwal > 0 ? round(($count / $total_jadwal) * 100) : 0;
                                ?>
                                <div
                                    class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-blue-50 border-blue-300' : 'border-gray-200'; ?>">
                                    <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $day; ?></div>
                                    <div
                                        class="text-2xl font-bold <?php echo $count > 0 ? 'text-blue-600' : 'text-gray-400'; ?>">
                                        <?php echo $count; ?>
                                    </div>
                                    <!-- <div class="text-xs text-gray-500"><?php echo $percentage; ?>%</div> -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Distribusi per Tingkat -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                            <i class="fas fa-graduation-cap mr-2"></i> Distribusi per Tingkat Pendidikan
                        </h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                            <?php
                            $tingkats = ['TK', 'SD', 'SMP', 'SMA', 'Alumni', 'Umum'];
                            foreach ($tingkats as $tingkat):
                                $count = $jadwal_by_tingkat[$tingkat] ?? 0;
                                $is_filtered = $filter_tingkat == $tingkat;
                                $percentage = $total_jadwal > 0 ? round(($count / $total_jadwal) * 100) : 0;
                                ?>
                                <div
                                    class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-green-50 border-green-300' : 'border-gray-200'; ?>">
                                    <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $tingkat; ?></div>
                                    <div
                                        class="text-2xl font-bold <?php echo $count > 0 ? 'text-green-600' : 'text-gray-400'; ?>">
                                        <?php echo $count; ?>
                                    </div>
                                    <!-- <div class="text-xs text-gray-500"><?php echo $percentage; ?>%</div> -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Kelola Jadwal Siswa</p>
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
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <i class="fas fa-calendar-alt mr-1"></i>
                                <?php echo $total_jadwal; ?> Jadwal
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Tambah Jadwal -->
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
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="space-y-4">
                        <!-- Pilih Siswa dengan Search/Autocomplete -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Cari Siswa <span class="text-red-500">*</span>
                                <span class="text-xs text-gray-500">(ketik nama siswa)</span>
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
                                        <div class="text-xs text-gray-500 mt-1" id="selectedSiswaOrangtua"></div>
                                    </div>
                                    <button type="button" onclick="clearSelectedSiswa()"
                                        class="text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Pilih Guru -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pilih Guru <span class="text-red-500">*</span>
                            </label>
                            <select name="guru_id" id="tambahGuru" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Guru --</option>
                                <?php foreach ($guru_list as $guru): ?>
                                    <option value="<?php echo $guru['id']; ?>">
                                        <?php echo htmlspecialchars($guru['full_name']); ?>
                                        <?php if ($guru['bidang_keahlian']): ?>(<?php echo $guru['bidang_keahlian']; ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Pilih Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hari <span class="text-red-500">*</span>
                            </label>
                            <select name="hari" id="tambahHari" required
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
                                <input type="time" name="jam_mulai" id="tambahJamMulai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Selesai <span class="text-red-500">*</span>
                                </label>
                                <input type="time" name="jam_selesai" id="tambahJamSelesai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="text-xs text-gray-500 bg-gray-50 p-3 rounded-lg">
                            <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                            Jadwal akan dibuat tanpa mengaitkan dengan mata pelajaran tertentu (fleksibel).
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
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="jadwal_id" id="editJadwalId">
                    <input type="hidden" name="pendaftaran_id" id="editPendaftaranId">

                    <div class="space-y-4">
                        <!-- Informasi Siswa (Readonly) -->
                        <div class="bg-blue-50 p-3 rounded-lg">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Siswa</label>
                            <div class="font-medium" id="editNamaSiswa"></div>
                            <div class="text-sm text-gray-600" id="editInfoSiswa"></div>
                        </div>

                        <!-- Pilih Guru (BARU) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pilih Guru <span class="text-red-500">*</span>
                            </label>
                            <select name="guru_id" id="editGuru" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Guru --</option>
                                <?php foreach ($guru_list as $guru): ?>
                                    <option value="<?php echo $guru['id']; ?>">
                                        <?php echo htmlspecialchars($guru['full_name']); ?>
                                        <?php if ($guru['bidang_keahlian']): ?>(<?php echo $guru['bidang_keahlian']; ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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

                        <!-- Hapus Info Kapasitas -->
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
                                <p><span class="font-medium">Guru:</span> <span id="hapusNamaGuru"></span></p>
                                <p><span class="font-medium">Hari:</span> <span id="hapusHari"></span></p>
                                <p><span class="font-medium">Waktu:</span> <span id="hapusWaktu"></span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">
                    Tindakan ini akan menonaktifkan jadwal.
                </p>
            </div>

            <div class="modal-konfirmasi-footer">
                <button type="button" onclick="closeHapusModal()"
                    class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium transition-colors duration-200">
                    <i class="fas fa-times mr-2"></i> Batal
                </button>
                <form id="formHapusJadwal" method="POST" action="" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
        let semuaSiswa = [];

        // ==================== FUNGSI MODAL ====================
        function openTambahModal() {
            document.getElementById('modalTambah').classList.add('active');
            document.body.style.overflow = 'hidden';

            // Reset form
            clearSelectedSiswa();
            document.getElementById('tambahGuru').value = '';
            document.getElementById('tambahHari').value = '';
            document.getElementById('tambahJamMulai').value = '';
            document.getElementById('tambahJamSelesai').value = '';

            // Focus ke search input
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

        function closeEditModal() {
            document.getElementById('modalEdit').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function openHapusModal(jadwalId, namaSiswa, namaGuru, hari, waktu) {
            jadwalIdToDelete = jadwalId;

            document.getElementById('hapusNamaSiswa').textContent = namaSiswa;
            document.getElementById('hapusNamaGuru').textContent = namaGuru;
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

        // ==================== FUNGSI EDIT MODAL ====================
        function openEditModal(jadwalId, hari, jamMulai, jamSelesai) {
            document.getElementById('editJadwalId').value = jadwalId;
            document.getElementById('editHari').value = hari;
            document.getElementById('editJamMulai').value = jamMulai;
            document.getElementById('editJamSelesai').value = jamSelesai;

            document.getElementById('editGuru').value = '';

            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'get_jadwal_detail',
                    jadwal_id: jadwalId
                },
                dataType: 'json',
                success: function (data) {
                    if (data.success) {
                        document.getElementById('editNamaSiswa').textContent = data.siswa_nama;
                        document.getElementById('editInfoSiswa').textContent =
                            'Kelas: ' + data.siswa_kelas + ' | Sekolah: ' + data.siswa_sekolah;
                        document.getElementById('editPendaftaranId').value = data.pendaftaran_id;

                        if (data.guru_id) {
                            document.getElementById('editGuru').value = data.guru_id;
                        }
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Gagal mengambil detail jadwal:', error);
                }
            });

            document.getElementById('modalEdit').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // ==================== AUTOCOMPLETE UNTUK MODAL TAMBAH ====================
        function loadSiswaData() {
            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'get_siswa_list'
                },
                dataType: 'json',
                success: function (data) {
                    siswaData = data;
                    semuaSiswa = data;
                    console.log('Data siswa loaded:', siswaData.length, 'records');
                    initAutocomplete();

                    // TAMBAHKAN INI - inisialisasi filter setelah data masuk
                    initFilterSearch();
                },
                error: function (xhr, status, error) {
                    console.error('Failed to load siswa data:', error);
                }
            });
        }

        function initAutocomplete() {
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

            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');
            if (!dropdown) return;

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
            if (!dropdown) return;

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

                item.innerHTML = `
                <div class="siswa-nama">${siswa.nama_lengkap}</div>
                <div class="siswa-info">
                    Kelas: ${siswa.kelas} | 
                    Sekolah: ${siswa.sekolah_asal || '-'}
                    ${siswa.orangtua_info ? '<br>Orang Tua: ' + siswa.orangtua_info : ''}
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

        function updateSelectedItem() {
            const dropdown = document.getElementById('siswaDropdown');
            if (!dropdown) return;

            const items = dropdown.querySelectorAll('.autocomplete-item');

            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('active');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        function selectSiswa(data) {
            const selectedSiswaId = document.getElementById('selectedSiswaId');
            const searchInput = document.getElementById('searchSiswa');
            const dropdown = document.getElementById('siswaDropdown');

            if (!selectedSiswaId || !searchInput || !dropdown) return;

            selectedSiswaId.value = data.id;
            searchInput.value = data.nama;
            dropdown.style.display = 'none';

            document.getElementById('selectedSiswaName').textContent = data.nama;
            document.getElementById('selectedSiswaKelas').textContent = data.kelas;
            document.getElementById('selectedSiswaSekolah').textContent = data.sekolah || '-';

            if (data.orangtua) {
                document.getElementById('selectedSiswaOrangtua').textContent = 'Orang Tua: ' + data.orangtua;
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

        // ==================== FILTER SEARCH SISWA (UNTUK FORM FILTER) ====================
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

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        function filterSiswaList(query) {
            const filtered = semuaSiswa.filter(siswa =>
                siswa.nama_lengkap.toLowerCase().includes(query.toLowerCase()) ||
                (siswa.kelas && siswa.kelas.toLowerCase().includes(query.toLowerCase()))
            );

            renderFilterDropdown(filtered);
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
            const namaSiswaInput = document.getElementById('filterNamaSiswa');
            const dropdown = document.getElementById('filterSiswaDropdown');
            const clearButton = document.getElementById('clearFilterSearch');

            searchInput.value = data.nama;
            siswaIdInput.value = data.id;
            namaSiswaInput.value = data.nama;
            dropdown.classList.add('hidden');
            clearButton.classList.remove('hidden');

            // Submit form untuk filter
            document.querySelector('form[method="GET"]').submit();
        }

        // ==================== VALIDASI FORM ====================
        document.getElementById('formTambahJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.getElementById('tambahJamMulai').value;
            const jamSelesai = document.getElementById('tambahJamSelesai').value;
            const siswaId = document.getElementById('selectedSiswaId').value;
            const hari = document.getElementById('tambahHari').value;
            const guruId = document.getElementById('tambahGuru').value;

            if (!siswaId) {
                e.preventDefault();
                alert('Silakan pilih siswa terlebih dahulu!');
                document.getElementById('searchSiswa').focus();
                return;
            }

            if (!guruId) {
                e.preventDefault();
                alert('Silakan pilih guru terlebih dahulu!');
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

        // Close modal on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeTambahModal();
                closeEditModal();
                closeHapusModal();
            }
        });

        // Close modal when clicking outside
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
            loadSiswaData();
        });
    </script>
</body>

</html>