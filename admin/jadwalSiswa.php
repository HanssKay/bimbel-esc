<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// FUNGSI HELPER UNTUK DATABASE
function executeQuery($conn, $sql, $params = [], $types = "")
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Query preparation failed: " . $conn->error);
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
}

// FUNGSI UPDATE KAPASITAS DAN STATUS SESI (Mengganti trigger)
function updateKapasitasDanStatusSesi($conn, $sesi_id)
{
    try {
        // Ambil data kapasitas saat ini
        $sql_get = "SELECT kapasitas_terisi, kapasitas_maks FROM sesi_mengajar_guru WHERE id = ?";
        $stmt_get = executeQuery($conn, $sql_get, [$sesi_id], "i");
        $result = $stmt_get->get_result();

        if ($result->num_rows === 0) {
            $stmt_get->close();
            return false;
        }

        $data = $result->fetch_assoc();
        $stmt_get->close();

        // Tentukan status baru
        $status_baru = ($data['kapasitas_terisi'] >= $data['kapasitas_maks']) ? 'penuh' : 'tersedia';

        // Update status
        $sql_update = "UPDATE sesi_mengajar_guru SET status = ?, updated_at = NOW() WHERE id = ?";
        $stmt_update = executeQuery($conn, $sql_update, [$status_baru, $sesi_id], "si");
        $stmt_update->close();

        return true;

    } catch (Exception $e) {
        error_log("Error updateKapasitasDanStatusSesi: " . $e->getMessage());
        return false;
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
$filter_guru = isset($_GET['guru_id']) && is_numeric($_GET['guru_id']) ? (int) $_GET['guru_id'] : 0;

// QUERY JADWAL SISWA
$jadwal_siswa = [];
$where_conditions = ["jb.status = 'aktif'", "ps.status = 'aktif'", "sp.status = 'aktif'"];
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
        smg.id as sesi_id,
        smg.hari,
        smg.jam_mulai,
        smg.jam_selesai,
        smg.kapasitas_maks,
        smg.kapasitas_terisi,
        smg.status as status_sesi,
        ps.id as pendaftaran_id,
        ps.jenis_kelas,
        ps.tingkat,
        s.id as siswa_id,
        s.nama_lengkap as nama_siswa,
        s.kelas as kelas_sekolah,
        s.sekolah_asal,
        sp.id as siswa_pelajaran_id,
        sp.nama_pelajaran,
        g.id as guru_id,
        u.full_name as nama_guru,
        g.bidang_keahlian,
        o.id as orangtua_id,
        o.nama_ortu,
        o.no_hp as hp_ortu,
        o.hubungan_dengan_siswa,
        DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, smg.jam_mulai, smg.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
    INNER JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    INNER JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
    INNER JOIN siswa s ON ps.siswa_id = s.id
    LEFT JOIN guru g ON smg.guru_id = g.id
    LEFT JOIN users u ON g.user_id = u.id
    LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
    LEFT JOIN orangtua o ON so.orangtua_id = o.id
    $where_clause
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
} catch (Exception $e) {
    error_log("Error fetching jadwal siswa: " . $e->getMessage());
    $jadwal_siswa = [];
}

// AMBIL DATA UNTUK FILTER DROPDOWN
$siswa_list = [];
$guru_list = [];

try {
    // Daftar siswa aktif
    $sql_siswa = "SELECT id, nama_lengkap, kelas FROM siswa WHERE status = 'aktif' ORDER BY nama_lengkap";
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
} catch (Exception $e) {
    error_log("Error fetching filter data: " . $e->getMessage());
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

// Hitung distribusi berdasarkan data jadwal siswa
foreach ($jadwal_siswa as $jadwal) {
    if (isset($jadwal['hari']) && array_key_exists($jadwal['hari'], $jadwal_by_hari)) {
        $jadwal_by_hari[$jadwal['hari']]++;
    }
    
    if (isset($jadwal['tingkat']) && array_key_exists($jadwal['tingkat'], $jadwal_by_tingkat)) {
        $jadwal_by_tingkat[$jadwal['tingkat']]++;
    }
}

// FUNGSI UNTUK MENCARI ATAU MEMBUAT SESI GURU
function cariAtauBuatSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai)
{
    if (!is_numeric($guru_id) || $guru_id <= 0) {
        return ['success' => false, 'error' => 'Guru ID tidak valid'];
    }

    if (!in_array($hari, ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'])) {
        return ['success' => false, 'error' => 'Hari tidak valid'];
    }

    try {
        // Cek apakah sudah ada sesi
        $sql_cek = "SELECT id, kapasitas_maks, kapasitas_terisi 
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
            return [
                'success' => true,
                'sesi_id' => $sesi_data['id'],
                'kapasitas_maks' => $sesi_data['kapasitas_maks'],
                'kapasitas_terisi' => $sesi_data['kapasitas_terisi'],
                'is_new' => false
            ];
        }
        $stmt_cek->close();

        // Ambil kapasitas default dari guru
        $sql_guru = "SELECT kapasitas_per_sesi FROM guru WHERE id = ?";
        $stmt_guru = executeQuery($conn, $sql_guru, [$guru_id], "i");
        $result_guru = $stmt_guru->get_result();

        $kapasitas_default = 10;
        if ($result_guru->num_rows > 0) {
            $guru_data = $result_guru->fetch_assoc();
            $kapasitas_default = $guru_data['kapasitas_per_sesi'] ?: 10;
        }
        $stmt_guru->close();

        // Buat sesi baru (trigger cegah_konflik_sesi_guru akan validasi konflik)
        $sql_insert = "INSERT INTO sesi_mengajar_guru 
                      (guru_id, hari, jam_mulai, jam_selesai, kapasitas_maks, kapasitas_terisi, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, 0, 'tersedia', NOW())";

        $stmt_insert = executeQuery($conn, $sql_insert, [$guru_id, $hari, $jam_mulai, $jam_selesai, $kapasitas_default], "isssi");
        $sesi_id = $stmt_insert->insert_id;
        $stmt_insert->close();

        return [
            'success' => true,
            'sesi_id' => $sesi_id,
            'kapasitas_maks' => $kapasitas_default,
            'kapasitas_terisi' => 0,
            'is_new' => true
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// PROSES TAMBAH JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_jadwal'])) {
    // Validasi input
    $siswa_pelajaran_id = isset($_POST['siswa_pelajaran_id']) && is_numeric($_POST['siswa_pelajaran_id'])
        ? (int) $_POST['siswa_pelajaran_id']
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
    if ($siswa_pelajaran_id <= 0)
        $errors[] = "Mata pelajaran harus dipilih";
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
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            // 1. Ambil data siswa
            $sql_get_data = "SELECT sp.siswa_id, sp.guru_id as current_guru_id, sp.pendaftaran_id, s.nama_lengkap
                            FROM siswa_pelajaran sp
                            INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                            INNER JOIN siswa s ON ps.siswa_id = s.id
                            WHERE sp.id = ? AND sp.status = 'aktif' AND ps.status = 'aktif'";

            $stmt_get = executeQuery($conn, $sql_get_data, [$siswa_pelajaran_id], "i");
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                throw new Exception("Data siswa tidak ditemukan");
            }

            $data_siswa = $result_get->fetch_assoc();
            $stmt_get->close();

            $siswa_id = (int) $data_siswa['siswa_id'];
            $current_guru_id = (int) $data_siswa['current_guru_id'];
            $pendaftaran_id = (int) $data_siswa['pendaftaran_id'];
            $nama_siswa = $data_siswa['nama_lengkap'];

            // 2. Update guru jika berbeda
            if ($current_guru_id != $guru_id) {
                $sql_update_guru = "UPDATE siswa_pelajaran SET guru_id = ?, updated_at = NOW() WHERE id = ?";
                $stmt_update = executeQuery($conn, $sql_update_guru, [$guru_id, $siswa_pelajaran_id], "ii");
                $stmt_update->close();
            }

            // 3. Cari atau buat sesi
            $sesi_result = cariAtauBuatSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai);

            if (!$sesi_result['success']) {
                throw new Exception($sesi_result['error']);
            }

            $sesi_guru_id = $sesi_result['sesi_id'];
            $kapasitas_maks = $sesi_result['kapasitas_maks'];
            $kapasitas_terisi = $sesi_result['kapasitas_terisi'];

            // 4. Cek kapasitas (trigger check_kapasitas_guru akan validasi juga)
            if ($kapasitas_terisi >= $kapasitas_maks) {
                throw new Exception("Kapasitas sesi sudah penuh");
            }

            // 5. Cek konflik jadwal siswa
            $sql_cek_konflik = "SELECT COUNT(*) as jumlah 
                               FROM jadwal_belajar jb
                               INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               INNER JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                               INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                               WHERE ps.siswa_id = ? 
                               AND smg.hari = ? 
                               AND jb.status = 'aktif'
                               AND jb.siswa_pelajaran_id != ?
                               AND (
                                   (smg.jam_mulai < ? AND smg.jam_selesai > ?) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai)
                               )";

            $stmt_konflik = executeQuery($conn, $sql_cek_konflik, [
                $siswa_id,
                $hari,
                $siswa_pelajaran_id,
                $jam_selesai,
                $jam_mulai,
                $jam_mulai
            ], "isisss");

            $result_konflik = $stmt_konflik->get_result();
            $data_konflik = $result_konflik->fetch_assoc();
            $stmt_konflik->close();

            if ($data_konflik['jumlah'] > 0) {
                throw new Exception("Siswa sudah memiliki jadwal lain pada waktu tersebut");
            }

            // 6. Tambah kapasitas terisi (mengganti fungsi trigger)
            $sql_update_kapasitas = "UPDATE sesi_mengajar_guru 
                                    SET kapasitas_terisi = kapasitas_terisi + 1 
                                    WHERE id = ?";
            $stmt_update_kap = executeQuery($conn, $sql_update_kapasitas, [$sesi_guru_id], "i");
            $stmt_update_kap->close();

            // 7. Update status sesi
            updateKapasitasDanStatusSesi($conn, $sesi_guru_id);

            // 8. Insert jadwal baru (trigger check_kapasitas_guru akan validasi)
            $sql_tambah = "INSERT INTO jadwal_belajar 
                          (pendaftaran_id, siswa_pelajaran_id, sesi_guru_id, status, created_at, updated_at) 
                          VALUES (?, ?, ?, 'aktif', NOW(), NOW())";

            $stmt_tambah = executeQuery($conn, $sql_tambah, [$pendaftaran_id, $siswa_pelajaran_id, $sesi_guru_id], "iii");
            $stmt_tambah->close();

            $conn->commit();

            $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// PROSES EDIT JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_jadwal'])) {
    $jadwal_id = isset($_POST['jadwal_id']) && is_numeric($_POST['jadwal_id']) ? (int) $_POST['jadwal_id'] : 0;
    $hari = isset($_POST['hari']) && in_array($_POST['hari'], ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']) ? $_POST['hari'] : '';
    $jam_mulai = isset($_POST['jam_mulai']) ? trim($_POST['jam_mulai']) : '';
    $jam_selesai = isset($_POST['jam_selesai']) ? trim($_POST['jam_selesai']) : '';

    if ($jadwal_id <= 0 || empty($hari) || empty($jam_mulai) || empty($jam_selesai)) {
        $_SESSION['error_message'] = "Data tidak lengkap!";
    } elseif ($jam_selesai <= $jam_mulai) {
        $_SESSION['error_message'] = "Jam selesai harus setelah jam mulai!";
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            // 1. Ambil data jadwal lama
            $sql_get = "SELECT jb.siswa_pelajaran_id, sp.siswa_id, smg.guru_id, 
                               jb.sesi_guru_id as sesi_lama, s.nama_lengkap
                       FROM jadwal_belajar jb
                       INNER JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                       INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                       INNER JOIN siswa s ON sp.siswa_id = s.id
                       WHERE jb.id = ?";

            $stmt_get = executeQuery($conn, $sql_get, [$jadwal_id], "i");
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                throw new Exception("Jadwal tidak ditemukan");
            }

            $old_data = $result_get->fetch_assoc();
            $stmt_get->close();

            $siswa_pelajaran_id = (int) $old_data['siswa_pelajaran_id'];
            $siswa_id = (int) $old_data['siswa_id'];
            $guru_id = (int) $old_data['guru_id'];
            $sesi_lama = (int) $old_data['sesi_lama'];
            $nama_siswa = $old_data['nama_lengkap'];

            // 2. Cari atau buat sesi baru
            $sesi_result = cariAtauBuatSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai);

            if (!$sesi_result['success']) {
                throw new Exception($sesi_result['error']);
            }

            $sesi_baru_id = $sesi_result['sesi_id'];

            if ($sesi_baru_id == $sesi_lama) {
                $conn->rollback();
                $_SESSION['success_message'] = "Tidak ada perubahan pada jadwal.";
                header("Location: jadwalSiswa.php");
                exit();
            }

            // 3. Cek kapasitas sesi baru
            if ($sesi_result['kapasitas_terisi'] >= $sesi_result['kapasitas_maks']) {
                throw new Exception("Kapasitas sesi baru sudah penuh!");
            }

            // 4. Cek konflik jadwal siswa
            $sql_cek_konflik = "SELECT COUNT(*) as jumlah 
                               FROM jadwal_belajar jb
                               INNER JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               INNER JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                               INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                               WHERE ps.siswa_id = ? 
                               AND smg.hari = ? 
                               AND jb.id != ?
                               AND jb.status = 'aktif'
                               AND (
                                   (smg.jam_mulai < ? AND smg.jam_selesai > ?) OR
                                   (? BETWEEN smg.jam_mulai AND smg.jam_selesai)
                               )";

            $stmt_konflik = executeQuery($conn, $sql_cek_konflik, [
                $siswa_id,
                $hari,
                $jadwal_id,
                $jam_selesai,
                $jam_mulai,
                $jam_mulai
            ], "isiiss");

            $result_konflik = $stmt_konflik->get_result();
            $data_konflik = $result_konflik->fetch_assoc();
            $stmt_konflik->close();

            if ($data_konflik['jumlah'] > 0) {
                throw new Exception("Siswa sudah memiliki jadwal lain pada waktu tersebut");
            }

            // 5. Update kapasitas: tambah ke sesi baru, kurangi dari sesi lama
            // Update sesi baru
            $sql_update_baru = "UPDATE sesi_mengajar_guru 
                               SET kapasitas_terisi = kapasitas_terisi + 1 
                               WHERE id = ?";
            $stmt_baru = executeQuery($conn, $sql_update_baru, [$sesi_baru_id], "i");
            $stmt_baru->close();

            // Update sesi lama
            $sql_update_lama = "UPDATE sesi_mengajar_guru 
                               SET kapasitas_terisi = GREATEST(kapasitas_terisi - 1, 0) 
                               WHERE id = ?";
            $stmt_lama = executeQuery($conn, $sql_update_lama, [$sesi_lama], "i");
            $stmt_lama->close();

            // 6. Update status kedua sesi
            updateKapasitasDanStatusSesi($conn, $sesi_baru_id);
            updateKapasitasDanStatusSesi($conn, $sesi_lama);

            // 7. Update jadwal
            $sql_update = "UPDATE jadwal_belajar 
                          SET sesi_guru_id = ?, updated_at = NOW() 
                          WHERE id = ?";

            $stmt_update = executeQuery($conn, $sql_update, [$sesi_baru_id, $jadwal_id], "ii");
            $stmt_update->close();

            $conn->commit();

            $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// PROSES HAPUS JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hapus_jadwal'])) {
    $jadwal_id = isset($_POST['jadwal_id']) && is_numeric($_POST['jadwal_id']) ? (int) $_POST['jadwal_id'] : 0;

    if ($jadwal_id <= 0) {
        $_SESSION['error_message'] = "ID jadwal tidak valid!";
    } else {
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            // 1. Ambil data sesi
            $sql_get_sesi = "SELECT sesi_guru_id FROM jadwal_belajar WHERE id = ?";
            $stmt_get = executeQuery($conn, $sql_get_sesi, [$jadwal_id], "i");
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                throw new Exception("Jadwal tidak ditemukan");
            }

            $data = $result_get->fetch_assoc();
            $sesi_guru_id = (int) $data['sesi_guru_id'];
            $stmt_get->close();

            // 2. Hapus jadwal
            $sql_hapus = "DELETE FROM jadwal_belajar WHERE id = ?";
            $stmt_hapus = executeQuery($conn, $sql_hapus, [$jadwal_id], "i");
            $affected_rows = $stmt_hapus->affected_rows;
            $stmt_hapus->close();

            if ($affected_rows === 0) {
                throw new Exception("Gagal menghapus jadwal");
            }

            // 3. Update kapasitas sesi (kurangi 1)
            $sql_update_kap = "UPDATE sesi_mengajar_guru 
                              SET kapasitas_terisi = GREATEST(kapasitas_terisi - 1, 0) 
                              WHERE id = ?";
            $stmt_update = executeQuery($conn, $sql_update_kap, [$sesi_guru_id], "i");
            $stmt_update->close();

            // 4. Update status sesi
            updateKapasitasDanStatusSesi($conn, $sesi_guru_id);

            $conn->commit();

            $_SESSION['success_message'] = "Jadwal berhasil dihapus!";
            header("Location: jadwalSiswa.php");
            exit();

        } catch (Exception $e) {
            if ($in_transaction) {
                $conn->rollback();
            }
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// AJAX HANDLERS
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    try {
        switch ($_GET['ajax']) {
            case 'get_siswa_list':
                $siswa_list_ajax = [];

                $sql = "SELECT 
                        s.id, 
                        s.nama_lengkap, 
                        s.kelas,
                        s.sekolah_asal,
                        GROUP_CONCAT(DISTINCT CONCAT(o.hubungan_dengan_siswa, ': ', o.nama_ortu) SEPARATOR '; ') as orangtua_info
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

            case 'get_pelajaran':
                if (!isset($_GET['siswa_id']) || !is_numeric($_GET['siswa_id'])) {
                    echo json_encode([]);
                    break;
                }

                $siswa_id = (int) $_GET['siswa_id'];
                $guru_id = isset($_GET['guru_id']) && is_numeric($_GET['guru_id']) ? (int) $_GET['guru_id'] : null;

                $mata_pelajaran = [];

                $sql = "SELECT sp.id, sp.nama_pelajaran, ps.tingkat, sp.guru_id
                        FROM siswa_pelajaran sp
                        INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                        WHERE ps.siswa_id = ? 
                        AND sp.status = 'aktif'
                        AND ps.status = 'aktif'";

                $params = [$siswa_id];
                $types = "i";

                if ($guru_id) {
                    $sql .= " AND (sp.guru_id IS NULL OR sp.guru_id = ?)";
                    $params[] = $guru_id;
                    $types .= "i";
                }

                $sql .= " ORDER BY sp.nama_pelajaran";

                $stmt = executeQuery($conn, $sql, $params, $types);
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $pelajaran_id = $row['id'];

                    // Cek apakah sudah ada jadwal
                    $sql_cek = "SELECT COUNT(*) as jumlah FROM jadwal_belajar WHERE siswa_pelajaran_id = ? AND status = 'aktif'";
                    $stmt_cek = executeQuery($conn, $sql_cek, [$pelajaran_id], "i");
                    $result_cek = $stmt_cek->get_result();
                    $data_cek = $result_cek->fetch_assoc();
                    $stmt_cek->close();

                    if ($data_cek['jumlah'] == 0) {
                        $mata_pelajaran[] = [
                            'id' => $pelajaran_id,
                            'nama_pelajaran' => $row['nama_pelajaran'],
                            'tingkat' => $row['tingkat'],
                            'guru_id' => $row['guru_id']
                        ];
                    }
                }
                $stmt->close();

                echo json_encode($mata_pelajaran);
                break;

            default:
                echo json_encode(['error' => 'Action not found']);
        }

        exit();

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
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
        /* Semua CSS tetap sama, tidak diubah */
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        /* CSS tambahan untuk autocomplete */
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

        /* Modal styles */
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

        /* Table styles */
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

        /* Badge styles */
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

        /* Modal konfirmasi styles */
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
                        Kelola jadwal bimbingan belajar semua siswa
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

                        <!-- Filter Siswa -->
                        <?php if (count($siswa_list) > 0): ?>
                            <div>
                                <select name="siswa_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Semua Siswa</option>
                                    <?php foreach ($siswa_list as $siswa): ?>
                                        <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa == $siswa['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                            (<?php echo $siswa['kelas']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <!-- Filter Guru -->
                        <?php if (count($guru_list) > 0): ?>
                            <div>
                                <select name="guru_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Semua Guru</option>
                                    <?php foreach ($guru_list as $guru): ?>
                                        <option value="<?php echo $guru['id']; ?>" <?php echo $filter_guru == $guru['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($guru['full_name']); ?>
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
                                        <th>Mata Pelajaran</th>
                                        <th>Tingkat</th>
                                        <th>Guru</th>
                                        <th>Hari</th>
                                        <th>Jam</th>
                                        <th>Durasi</th>
                                        <th>Orang Tua</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = 1;
                                    $current_siswa_id = 0;
                                    $orangtua_data = []; // Untuk mengelompokkan data orang tua per siswa
                                    ?>

                                    <?php foreach ($jadwal_siswa as $jadwal):
                                        $durasi = $jadwal['durasi_menit'] / 60;

                                        // Kelompokkan data orang tua per siswa
                                        if ($jadwal['siswa_id'] != $current_siswa_id) {
                                            $current_siswa_id = $jadwal['siswa_id'];
                                            $orangtua_data[$jadwal['siswa_id']] = [];
                                        }

                                        if (!empty($jadwal['nama_ortu']) && !in_array($jadwal['nama_ortu'], $orangtua_data[$jadwal['siswa_id']])) {
                                            $orangtua_data[$jadwal['siswa_id']][] = [
                                                'nama' => $jadwal['nama_ortu'],
                                                'hubungan' => $jadwal['hubungan_dengan_siswa'],
                                                'hp' => $jadwal['hp_ortu']
                                            ];
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo $no++; ?></td>
                                            <td>
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($jadwal['nama_siswa']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500"><?php echo $jadwal['kelas_sekolah']; ?></div>
                                                <div class="text-xs text-gray-500"><?php echo $jadwal['sekolah_asal']; ?></div>
                                            </td>
                                            <td>
                                                <div class="font-medium">
                                                    <?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?>
                                                </div>
                                                <span
                                                    class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php echo $jadwal['jenis_kelas']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary">
                                                    <?php echo $jadwal['tingkat']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($jadwal['nama_guru'])): ?>
                                                    <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_guru']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?php echo $jadwal['bidang_keahlian']; ?>
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
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo number_format($durasi, 1); ?> jam
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                // Tampilkan data orang tua untuk siswa ini
                                                $siswa_orangtua = isset($orangtua_data[$jadwal['siswa_id']]) ? $orangtua_data[$jadwal['siswa_id']] : [];
                                                ?>

                                                <?php if (!empty($siswa_orangtua)): ?>
                                                    <div class="space-y-1">
                                                        <?php foreach ($siswa_orangtua as $index => $ortu): ?>
                                                            <div class="flex items-start">
                                                                <div class="ml-2">
                                                                    <div class="text-sm font-medium text-gray-900">
                                                                        <?php echo htmlspecialchars($ortu['nama']); ?>
                                                                    </div>
                                                                    <?php if (!empty($ortu['hp'])): ?>
                                                                        <div class="text-xs text-gray-500">
                                                                            <?php echo htmlspecialchars($ortu['hp']); ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>

                                                            <?php if ($index == 0 && count($siswa_orangtua) > 1): ?>
                                                                <div class="text-xs text-gray-400 italic pl-6">
                                                                    + <?php echo count($siswa_orangtua) - 1; ?> orang tua lainnya
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-500">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="flex space-x-2">
                                                    <!-- Tombol Edit - PERBAIKI PARAMETER -->
                                                    <button onclick="openEditModal(
            <?php echo $jadwal['jadwal_id']; ?>,
            '<?php echo $jadwal['hari']; ?>',
            '<?php echo $jadwal['jam_mulai']; ?>',
            '<?php echo $jadwal['jam_selesai']; ?>'
        )" class="px-3 py-1 bg-yellow-500 text-white rounded text-sm hover:bg-yellow-600">
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>

                                                    <!-- Tombol Hapus - PERBAIKI PARAMETER -->
                                                    <button onclick="openHapusModal(
            <?php echo $jadwal['jadwal_id']; ?>, 
            '<?php echo htmlspecialchars($jadwal['nama_siswa']); ?>', 
            '<?php echo htmlspecialchars($jadwal['nama_guru'] ?? 'Belum ditugaskan'); ?>', 
            '<?php echo $jadwal['hari']; ?>', 
            '<?php echo $jadwal['jam_mulai_format']; ?> - <?php echo $jadwal['jam_selesai_format']; ?>'
        )" class="px-3 py-1 bg-red-500 text-white rounded text-sm hover:bg-red-600">
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
                                <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru): ?>
                                    Tidak ada jadwal yang sesuai dengan filter yang dipilih
                                <?php else: ?>
                                    Belum ada jadwal siswa yang ditambahkan
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ringkasan Statistik -->
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
                            ?>
                            <div
                                class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-blue-50 border-blue-300' : 'border-gray-200'; ?>">
                                <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $day; ?></div>
                                <div
                                    class="text-2xl font-bold <?php echo $count > 0 ? 'text-blue-600' : 'text-gray-400'; ?>">
                                    <?php echo $count; ?>
                                </div>
                                <div class="text-xs text-gray-500">jadwal</div>
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
                            ?>
                            <div
                                class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-green-50 border-green-300' : 'border-gray-200'; ?>">
                                <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $tingkat; ?></div>
                                <div
                                    class="text-2xl font-bold <?php echo $count > 0 ? 'text-green-600' : 'text-gray-400'; ?>">
                                    <?php echo $count; ?>
                                </div>
                                <div class="text-xs text-gray-500">jadwal</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
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

    <!-- Modal Tambah Jadwal - KEMBALI KE VERSI LAMA -->
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
                        <!-- Pilih Siswa dengan Search/Autocomplete -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Cari Siswa
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
                                Pilih Guru
                            </label>
                            <select name="guru_id" id="tambahGuru" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Guru --</option>
                                <?php foreach ($guru_list as $guru): ?>
                                    <option value="<?php echo $guru['id']; ?>">
                                        <?php echo htmlspecialchars($guru['full_name']); ?>
                                        (<?php echo $guru['bidang_keahlian']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Mata Pelajaran -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Mata Pelajaran
                            </label>
                            <select name="siswa_pelajaran_id" id="tambahMataPelajaran" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Siswa terlebih dahulu --</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">
                                * Hanya menampilkan mata pelajaran yang belum memiliki jadwal
                            </p>
                        </div>

                        <!-- Pilih Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hari
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
                                    Jam Mulai
                                </label>
                                <input type="time" name="jam_mulai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Selesai
                                </label>
                                <input type="time" name="jam_selesai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
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

    <!-- Modal Edit Jadwal - KEMBALI KE VERSI LAMA -->
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
                        <!-- Pilih Hari -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hari
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
                                    Jam Mulai
                                </label>
                                <input type="time" name="jam_mulai" id="editJamMulai" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jam Selesai
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
                                <p><span class="font-medium">Guru:</span> <span id="hapusNamaGuru"></span></p>
                                <p><span class="font-medium">Hari:</span> <span id="hapusHari"></span></p>
                                <p><span class="font-medium">Waktu:</span> <span id="hapusWaktu"></span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-gray-600 text-sm">
                    Tindakan ini tidak dapat dibatalkan. Jadwal akan dihapus permanen dari sistem.
                </p>
            </div>

            <div class="modal-konfirmasi-footer">
                <button type="button" onclick="closeHapusModal()"
                    class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium transition-colors duration-200">
                    <i class="fas fa-times mr-2"></i> Batal
                </button>
                <button type="button" onclick="prosesHapus()"
                    class="px-5 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium transition-colors duration-200">
                    <i class="fas fa-trash mr-2"></i> Ya, Hapus
                </button>
            </div>
        </div>
    </div>

    <!-- Form Hapus Jadwal (hidden) -->
    <form id="formHapusJadwal" method="POST" action="" style="display: none;">
        <input type="hidden" name="jadwal_id" id="hapusJadwalId">
        <input type="hidden" name="hapus_jadwal" value="1">
    </form>

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

        // ==================== FUNGSI MODAL ====================
        function openTambahModal() {
            document.getElementById('modalTambah').classList.add('active');
            document.body.style.overflow = 'hidden';

            // Reset form
            clearSelectedSiswa();
            document.getElementById('tambahGuru').value = '';
            document.getElementById('tambahMataPelajaran').innerHTML = '<option value="">-- Pilih Siswa terlebih dahulu --</option>';
            document.getElementById('tambahMataPelajaran').disabled = true;

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
            searchCache = {}; // Clear cache
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

        // ==================== MODAL HAPUS ====================
        function openHapusModal(jadwalId, namaSiswa, namaGuru, hari, waktu) {
            jadwalIdToDelete = jadwalId;

            // Set informasi di modal
            document.getElementById('hapusNamaSiswa').textContent = namaSiswa;
            document.getElementById('hapusNamaGuru').textContent = namaGuru;
            document.getElementById('hapusHari').textContent = hari;
            document.getElementById('hapusWaktu').textContent = waktu;

            // Tampilkan modal
            document.getElementById('modalHapus').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeHapusModal() {
            document.getElementById('modalHapus').classList.remove('active');
            document.body.style.overflow = 'auto';
            jadwalIdToDelete = null;
        }

        function prosesHapus() {
            if (jadwalIdToDelete) {
                document.getElementById('hapusJadwalId').value = jadwalIdToDelete;
                document.getElementById('formHapusJadwal').submit();
            }
        }

        // ==================== AUTOSEARCH SISWA ====================
        // Fungsi untuk load data siswa
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
                    console.log('Data siswa loaded:', siswaData.length, 'records');
                    // Inisialisasi autocomplete setelah data loaded
                    initAutocomplete();
                },
                error: function () {
                    console.error('Failed to load siswa data');
                }
            });
        }

        // Inisialisasi autocomplete
        function initAutocomplete() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSearch');
            const dropdown = document.getElementById('siswaDropdown');
            const selectedSiswaId = document.getElementById('selectedSiswaId');

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

        // Fungsi filter siswa dengan caching
        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');

            // Cek cache
            const cacheKey = query.toLowerCase();
            if (searchCache[cacheKey]) {
                renderDropdown(searchCache[cacheKey]);
                return;
            }

            // Filter dari data yang sudah di-load
            const filtered = siswaData.filter(siswa =>
                siswa.nama_lengkap.toLowerCase().includes(cacheKey) ||
                siswa.kelas.toLowerCase().includes(cacheKey) ||
                (siswa.sekolah_asal && siswa.sekolah_asal.toLowerCase().includes(cacheKey))
            );

            // Simpan ke cache
            searchCache[cacheKey] = filtered;
            renderDropdown(filtered);
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

            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';

            // Load mata pelajaran untuk siswa ini
            loadMataPelajaran(data.id);
        }

        // Fungsi clear selected siswa
        function clearSelectedSiswa() {
            document.getElementById('searchSiswa').value = '';
            document.getElementById('selectedSiswaId').value = '';
            document.getElementById('selectedSiswaInfo').classList.add('hidden');
            document.getElementById('tambahMataPelajaran').innerHTML = '<option value="">-- Pilih Siswa terlebih dahulu --</option>';
            document.getElementById('tambahMataPelajaran').disabled = true;
            document.getElementById('clearSearch').style.display = 'none';
        }

        // ==================== LOAD MATA PELAJARAN ====================
        function loadMataPelajaran(siswaId = null) {
            const siswaIdVal = siswaId || document.getElementById('selectedSiswaId').value;
            const guruId = document.getElementById('tambahGuru').value;
            const pelajaranSelect = document.getElementById('tambahMataPelajaran');

            if (!siswaIdVal) {
                pelajaranSelect.innerHTML = '<option value="">-- Pilih Siswa terlebih dahulu --</option>';
                pelajaranSelect.disabled = true;
                return;
            }

            if (!guruId) {
                pelajaranSelect.innerHTML = '<option value="">-- Pilih Guru terlebih dahulu --</option>';
                pelajaranSelect.disabled = true;
                return;
            }

            // Tampilkan loading
            pelajaranSelect.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
            pelajaranSelect.disabled = true;

            // AJAX request
            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'get_pelajaran',
                    siswa_id: siswaIdVal,
                    guru_id: guruId
                },
                dataType: 'json',
                success: function (data) {
                    if (data.length > 0) {
                        let options = '<option value="">-- Pilih Mata Pelajaran --</option>';
                        data.forEach(function (pelajaran) {
                            options += `<option value="${pelajaran.id}">${pelajaran.nama_pelajaran} (${pelajaran.tingkat})</option>`;
                        });
                        pelajaranSelect.innerHTML = options;
                    } else {
                        pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran yang tersedia</option>';
                    }
                    pelajaranSelect.disabled = false;
                },
                error: function () {
                    pelajaranSelect.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
                    pelajaranSelect.disabled = false;
                }
            });
        }

        // ==================== EVENT LISTENERS ====================
        // Event listeners untuk form tambah
        document.getElementById('tambahGuru').addEventListener('change', function () {
            if (document.getElementById('selectedSiswaId').value) {
                loadMataPelajaran();
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

        // ==================== VALIDASI FORM ====================
        // Validasi form tambah: Jam selesai harus > jam mulai
        document.getElementById('formTambahJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.querySelector('#formTambahJadwal input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('#formTambahJadwal input[name="jam_selesai"]').value;
            const siswaId = document.getElementById('selectedSiswaId').value;
            const siswaPelajaran = document.getElementById('tambahMataPelajaran').value;
            const hari = document.querySelector('#formTambahJadwal select[name="hari"]').value;
            const guruId = document.querySelector('#formTambahJadwal select[name="guru_id"]').value;

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

            if (!siswaPelajaran) {
                e.preventDefault();
                alert('Silakan pilih mata pelajaran!');
                return;
            }

            if (jamSelesai <= jamMulai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai!');
            }
        });

        // Validasi form edit: Jam selesai harus > jam mulai
        document.getElementById('formEditJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.getElementById('editJamMulai').value;
            const jamSelesai = document.getElementById('editJamSelesai').value;
            const hari = document.getElementById('editHari').value;

            if (!hari) {
                e.preventDefault();
                alert('Silakan pilih hari terlebih dahulu!');
                return;
            }

            if (jamSelesai <= jamMulai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai!');
            }
        });

        // ==================== INITIALIZATION ====================
        // Load siswa data saat page load
        $(document).ready(function () {
            // Load data siswa untuk autocomplete
            loadSiswaData();

            // Inisialisasi autocomplete jika data sudah tersedia
            if (siswaData.length > 0) {
                initAutocomplete();
            }
        });
    </script>
</body>

</html>