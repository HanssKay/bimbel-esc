<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE (untuk normal page)
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
$filter_hari = isset($_GET['hari']) && $_GET['hari'] !== '' ? $_GET['hari'] : '';
$filter_siswa = isset($_GET['siswa']) && $_GET['siswa'] !== '' ? $_GET['siswa'] : '';
$filter_params = [];

// AMBIL DAFTAR SEMUA SISWA YANG DIBIMBING OLEH GURU INI (untuk dropdown filter)
$semua_siswa_guru = [];
$sql_semua_siswa = "
    SELECT DISTINCT 
        s.id,
        s.nama_lengkap,
        s.kelas
    FROM siswa s
    JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
    WHERE sp.guru_id = ?
    AND sp.status = 'aktif'
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

// AMBIL JADWAL BELAJAR SISWA YANG DIBIMBING OLEH GURU INI
$jadwal_siswa = [];
$where_conditions = ["sp.guru_id = ?", "ps.status = 'aktif'", "sp.status = 'aktif'", "jb.status = 'aktif'"];
$filter_params[] = $guru_id;

if ($filter_hari !== '') {
    $where_conditions[] = "smg.hari = ?";
    $filter_params[] = $filter_hari;
}

if ($filter_siswa !== '') {
    $where_conditions[] = "s.id = ?";
    $filter_params[] = $filter_siswa;
}

$where_clause = implode(" AND ", $where_conditions);

$sql_jadwal_siswa = "
    SELECT 
        jb.*,
        jb.id as jadwal_id,
        s.id as siswa_id,
        s.nama_lengkap,
        s.kelas as kelas_sekolah,
        sp.nama_pelajaran,
        sp.nama_pelajaran as mata_pelajaran,
        ps.tingkat,
        ps.jenis_kelas,
        g.id as guru_id,
        u.full_name as nama_guru,
        smg.id as sesi_id,
        smg.hari,
        smg.jam_mulai,
        smg.jam_selesai,
        smg.kapasitas_maks,
        smg.kapasitas_terisi,
        DATE_FORMAT(smg.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(smg.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, smg.jam_mulai, smg.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
    JOIN siswa s ON ps.siswa_id = s.id
    LEFT JOIN guru g ON sp.guru_id = g.id
    LEFT JOIN users u ON g.user_id = u.id
    JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
    WHERE {$where_clause}
    ORDER BY 
        FIELD(smg.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
        smg.jam_mulai,
        s.nama_lengkap
";

try {
    $stmt = $conn->prepare($sql_jadwal_siswa);

    if ($filter_params) {
        $types = str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$filter_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $jadwal_siswa[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching jadwal siswa: " . $e->getMessage());
    $error_message = "Gagal memuat data jadwal: " . $e->getMessage();
}

// AMBIL SISWA YANG MEMILIKI MATA PELAJARAN TANPA JADWAL SAMA SEKALI
$siswa_dan_mapel_tanpa_jadwal = [];

// Ambil semua mata pelajaran siswa YANG BISA DIAJAR oleh guru ini (guru_id = guru ini ATAU NULL)
$sql_siswa_mapel = "
    SELECT DISTINCT
        s.id as siswa_id,
        s.nama_lengkap,
        s.kelas,
        s.status,
        sp.id as siswa_pelajaran_id,
        sp.nama_pelajaran,
        ps.tingkat,
        ps.jenis_kelas,
        sp.guru_id
    FROM siswa s
    JOIN siswa_pelajaran sp ON s.id = sp.siswa_id
    JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
    WHERE (sp.guru_id = ? OR sp.guru_id IS NULL)
    AND sp.status = 'aktif'
    AND ps.status = 'aktif'
    AND s.status = 'aktif'
    ORDER BY s.nama_lengkap, sp.nama_pelajaran
";

$stmt_mapel = $conn->prepare($sql_siswa_mapel);
$stmt_mapel->bind_param("i", $guru_id);
$stmt_mapel->execute();
$result_mapel = $stmt_mapel->get_result();

// Group by siswa
while ($row = $result_mapel->fetch_assoc()) {
    $siswa_id = $row['siswa_id'];
    $pelajaran_id = $row['siswa_pelajaran_id'];

    // CEK: Apakah mata pelajaran ini SUDAH ADA JADWALNYA (dengan guru SIAPAPUN)?
    $sql_cek_jadwal = "SELECT COUNT(*) as jumlah_jadwal 
                      FROM jadwal_belajar jb
                      WHERE jb.siswa_pelajaran_id = ? 
                      AND jb.status = 'aktif'";

    $stmt_cek = $conn->prepare($sql_cek_jadwal);
    $stmt_cek->bind_param("i", $pelajaran_id);
    $stmt_cek->execute();
    $result_cek = $stmt_cek->get_result();
    $row_cek = $result_cek->fetch_assoc();
    $stmt_cek->close();

    // Hanya tambahkan jika BELUM ADA JADWAL SAMA SEKALI
    if ($row_cek['jumlah_jadwal'] == 0) {
        if (!isset($siswa_dan_mapel_tanpa_jadwal[$siswa_id])) {
            $siswa_dan_mapel_tanpa_jadwal[$siswa_id] = [
                'nama_lengkap' => $row['nama_lengkap'],
                'kelas' => $row['kelas'],
                'mata_pelajaran' => []
            ];
        }

        $siswa_dan_mapel_tanpa_jadwal[$siswa_id]['mata_pelajaran'][] = [
            'siswa_pelajaran_id' => $pelajaran_id,
            'nama_mapel' => $row['nama_pelajaran'],
            'tingkat' => $row['tingkat'],
            'jenis_kelas' => $row['jenis_kelas'],
            'guru_id' => $row['guru_id']
        ];
    }
}
$stmt_mapel->close();

// FUNGSI UNTUK MENDAPATKAN MATA PELAJARAN TANPA JADWAL
function getMataPelajaranTanpaJadwal($conn, $siswa_id, $guru_id)
{
    $mata_pelajaran = [];

    // Ambil mata pelajaran siswa YANG BISA DIAJAR oleh guru ini (guru_id = guru ini ATAU NULL)
    $sql = "SELECT sp.id, sp.nama_pelajaran, ps.tingkat, ps.jenis_kelas, sp.guru_id
            FROM siswa_pelajaran sp
            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
            WHERE sp.siswa_id = ? 
            AND (sp.guru_id = ? OR sp.guru_id IS NULL)
            AND sp.status = 'aktif'
            AND ps.status = 'aktif'
            ORDER BY sp.nama_pelajaran";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $siswa_id, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $stmt->fetch_assoc()) {
        $pelajaran_id = $row['id'];

        // CEK APAKAH SUDAH ADA JADWAL UNTUK MAPEL INI (dengan guru SIAPAPUN)
        $sql_cek_jadwal = "SELECT COUNT(*) as jumlah_jadwal 
                          FROM jadwal_belajar jb
                          WHERE jb.siswa_pelajaran_id = ? 
                          AND jb.status = 'aktif'";

        $stmt_cek = $conn->prepare($sql_cek_jadwal);
        $stmt_cek->bind_param("i", $pelajaran_id);
        $stmt_cek->execute();
        $result_cek = $stmt_cek->get_result();
        $row_cek = $result_cek->fetch_assoc();
        $stmt_cek->close();

        // Hanya tambahkan jika BELUM ADA JADWAL SAMA SEKALI
        if ($row_cek['jumlah_jadwal'] == 0) {
            $mata_pelajaran[] = [
                'id' => $pelajaran_id,
                'nama_pelajaran' => $row['nama_pelajaran'],
                'nama_mapel' => $row['nama_pelajaran'],
                'tingkat' => $row['tingkat'],
                'jenis_kelas' => $row['jenis_kelas'],
                'guru_id' => $row['guru_id'],
                'sudah_ada_jadwal' => false
            ];
        } else {
            // Bisa ditambahkan dengan flag untuk info (opsional)
            $mata_pelajaran[] = [
                'id' => $pelajaran_id,
                'nama_pelajaran' => $row['nama_pelajaran'],
                'nama_mapel' => $row['nama_pelajaran'],
                'tingkat' => $row['tingkat'],
                'jenis_kelas' => $row['jenis_kelas'],
                'guru_id' => $row['guru_id'],
                'sudah_ada_jadwal' => true
            ];
        }
    }
    $stmt->close();

    return $mata_pelajaran;
}

// FUNGSI: Cari atau buat sesi guru
function findOrCreateSesiGuru($conn, $guru_id, $hari, $jam_mulai, $jam_selesai)
{
    // Cek apakah sudah ada sesi dengan waktu yang sama
    $sql_cek_sesi = "SELECT id, kapasitas_maks, kapasitas_terisi, status 
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
        return $sesi; // Kembalikan sesi yang sudah ada
    }
    $stmt_cek->close();

    // Buat sesi baru jika tidak ada
    $kapasitas_maks = 10; // Default capacity
    $sql_buat_sesi = "INSERT INTO sesi_mengajar_guru 
                     (guru_id, hari, jam_mulai, jam_selesai, kapasitas_maks, kapasitas_terisi, status) 
                     VALUES (?, ?, ?, ?, ?, 0, 'tersedia')";

    $stmt_buat = $conn->prepare($sql_buat_sesi);
    $stmt_buat->bind_param("isssi", $guru_id, $hari, $jam_mulai, $jam_selesai, $kapasitas_maks);

    if ($stmt_buat->execute()) {
        $sesi_id = $stmt_buat->insert_id;
        $stmt_buat->close();

        // Ambil data sesi yang baru dibuat
        $sql_get_sesi = "SELECT id, kapasitas_maks, kapasitas_terisi, status 
                        FROM sesi_mengajar_guru 
                        WHERE id = ?";
        $stmt_get = $conn->prepare($sql_get_sesi);
        $stmt_get->bind_param("i", $sesi_id);
        $stmt_get->execute();
        $result_get = $stmt_get->get_result();
        $sesi = $result_get->fetch_assoc();
        $stmt_get->close();

        return $sesi;
    } else {
        $stmt_buat->close();
        return false;
    }
}

// PROSES TAMBAH JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_jadwal'])) {
    $siswa_pelajaran_id = $_POST['siswa_pelajaran_id'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $jam_mulai = $_POST['jam_mulai'] ?? '';
    $jam_selesai = $_POST['jam_selesai'] ?? '';

    if ($siswa_pelajaran_id && $hari && $jam_mulai && $jam_selesai) {
        try {
            // Ambil data siswa dari siswa_pelajaran
            $sql_get_data = "SELECT sp.siswa_id, sp.guru_id, sp.pendaftaran_id, ps.tingkat
                            FROM siswa_pelajaran sp
                            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                            WHERE sp.id = ? AND (sp.guru_id = ? OR sp.guru_id IS NULL)";
            $stmt_get = $conn->prepare($sql_get_data);
            $stmt_get->bind_param("ii", $siswa_pelajaran_id, $guru_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();

            if ($result_get->num_rows === 0) {
                $_SESSION['error_message'] = "Data tidak ditemukan atau Anda tidak memiliki akses!";
                $stmt_get->close();
            } else {
                $data_siswa = $result_get->fetch_assoc();
                $stmt_get->close();

                $siswa_id = $data_siswa['siswa_id'];
                $current_guru_id = $data_siswa['guru_id'];
                $pendaftaran_id = $data_siswa['pendaftaran_id'];
                $tingkat = $data_siswa['tingkat'];

                // Jika guru_id NULL, update dengan guru yang sedang login
                if (!$current_guru_id || $current_guru_id == 0) {
                    $sql_update_guru = "UPDATE siswa_pelajaran SET guru_id = ? WHERE id = ?";
                    $stmt_update = $conn->prepare($sql_update_guru);
                    $stmt_update->bind_param("ii", $guru_id, $siswa_pelajaran_id);
                    if ($stmt_update->execute()) {
                        $current_guru_id = $guru_id; // Update variabel
                    }
                    $stmt_update->close();
                }

                // Pastikan guru_id sesuai
                if ($current_guru_id != $guru_id) {
                    $_SESSION['error_message'] = "Anda tidak memiliki akses untuk siswa ini!";
                } else {
                    // Cek apakah siswa sudah memiliki jadwal pada hari dan jam yang sama (untuk mata pelajaran APAPUN)
                    $sql_cek_siswa = "SELECT jb.id 
                                     FROM jadwal_belajar jb
                                     JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                                     JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                                     JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                                     WHERE ps.siswa_id = ? 
                                     AND smg.hari = ? 
                                     AND jb.status = 'aktif'
                                     AND jb.siswa_pelajaran_id != ?
                                     AND smg.jam_mulai = ? 
                                     AND smg.jam_selesai = ?";
                    $stmt_cek = $conn->prepare($sql_cek_siswa);
                    $stmt_cek->bind_param(
                        "issss",
                        $siswa_id,
                        $hari,
                        $siswa_pelajaran_id,
                        $jam_mulai,
                        $jam_selesai
                    );
                    $stmt_cek->execute();
                    $result_cek = $stmt_cek->get_result();

                    if ($result_cek->num_rows > 0) {
                        $_SESSION['error_message'] = "Siswa sudah memiliki jadwal pada hari dan jam yang sama!";
                        $stmt_cek->close();
                    } else {
                        $stmt_cek->close();

                        // Cek apakah mata pelajaran ini sudah ada jadwal di sesi lain
                        $sql_cek_mapel = "SELECT COUNT(*) as jumlah_jadwal 
                                        FROM jadwal_belajar jb
                                        WHERE jb.siswa_pelajaran_id = ? 
                                        AND jb.status = 'aktif'";
                        $stmt_cek_mapel = $conn->prepare($sql_cek_mapel);
                        $stmt_cek_mapel->bind_param("i", $siswa_pelajaran_id);
                        $stmt_cek_mapel->execute();
                        $result_cek_mapel = $stmt_cek_mapel->get_result();
                        $row_cek_mapel = $result_cek_mapel->fetch_assoc();
                        $stmt_cek_mapel->close();

                        if ($row_cek_mapel['jumlah_jadwal'] > 0) {
                            $_SESSION['error_message'] = "Mata pelajaran ini sudah memiliki jadwal!";
                        } else {
                            // Cari sesi yang sudah ada dengan hari dan jam yang sama
                            $sql_cek_sesi = "SELECT id, kapasitas_maks, kapasitas_terisi, status 
                                           FROM sesi_mengajar_guru 
                                           WHERE guru_id = ? 
                                           AND hari = ? 
                                           AND jam_mulai = ? 
                                           AND jam_selesai = ?";
                            $stmt_cek_sesi = $conn->prepare($sql_cek_sesi);
                            $stmt_cek_sesi->bind_param("isss", $guru_id, $hari, $jam_mulai, $jam_selesai);
                            $stmt_cek_sesi->execute();
                            $result_cek_sesi = $stmt_cek_sesi->get_result();

                            if ($result_cek_sesi->num_rows > 0) {
                                // Gunakan sesi yang sudah ada
                                $sesi_data = $result_cek_sesi->fetch_assoc();
                                $stmt_cek_sesi->close();

                                if ($sesi_data['kapasitas_terisi'] >= $sesi_data['kapasitas_maks']) {
                                    $_SESSION['error_message'] = "Kapasitas sesi mengajar sudah penuh!";
                                } else if ($sesi_data['status'] != 'tersedia') {
                                    $_SESSION['error_message'] = "Sesi mengajar tidak tersedia!";
                                } else {
                                    // Tambah jadwal ke sesi yang sudah ada
                                    $sql_tambah = "INSERT INTO jadwal_belajar 
                                                  (pendaftaran_id, siswa_pelajaran_id, sesi_guru_id, status) 
                                                  VALUES (?, ?, ?, 'aktif')";
                                    $stmt_tambah = $conn->prepare($sql_tambah);
                                    $stmt_tambah->bind_param("iii", $pendaftaran_id, $siswa_pelajaran_id, $sesi_data['id']);

                                    if ($stmt_tambah->execute()) {
                                        // Update kapasitas terisi di sesi mengajar
                                        $sql_update_kapasitas = "UPDATE sesi_mengajar_guru 
                                                                SET kapasitas_terisi = kapasitas_terisi + 1 
                                                                WHERE id = ?";
                                        $stmt_update_kap = $conn->prepare($sql_update_kapasitas);
                                        $stmt_update_kap->bind_param("i", $sesi_data['id']);
                                        $stmt_update_kap->execute();
                                        $stmt_update_kap->close();

                                        $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
                                        $stmt_tambah->close();
                                        header("Location: jadwalSiswa.php");
                                        exit();
                                    } else {
                                        $_SESSION['error_message'] = "Gagal menambahkan jadwal! Error: " . $stmt_tambah->error;
                                        $stmt_tambah->close();
                                    }
                                }
                            } else {
                                $stmt_cek_sesi->close();

                                // Buat sesi baru (handle error trigger dengan try-catch)
                                try {
                                    $kapasitas_maks = 5; // Sesuai data di tabel guru
                                    $sql_buat_sesi = "INSERT INTO sesi_mengajar_guru 
                                                     (guru_id, hari, jam_mulai, jam_selesai, kapasitas_maks, kapasitas_terisi, status) 
                                                     VALUES (?, ?, ?, ?, ?, 0, 'tersedia')";

                                    $stmt_buat = $conn->prepare($sql_buat_sesi);
                                    $stmt_buat->bind_param("isssi", $guru_id, $hari, $jam_mulai, $jam_selesai, $kapasitas_maks);

                                    if ($stmt_buat->execute()) {
                                        $sesi_id = $stmt_buat->insert_id;
                                        $stmt_buat->close();

                                        // Tambah jadwal baru
                                        $sql_tambah = "INSERT INTO jadwal_belajar 
                                                      (pendaftaran_id, siswa_pelajaran_id, sesi_guru_id, status) 
                                                      VALUES (?, ?, ?, 'aktif')";
                                        $stmt_tambah = $conn->prepare($sql_tambah);
                                        $stmt_tambah->bind_param("iii", $pendaftaran_id, $siswa_pelajaran_id, $sesi_id);

                                        if ($stmt_tambah->execute()) {
                                            // Update kapasitas terisi di sesi mengajar
                                            $sql_update_kapasitas = "UPDATE sesi_mengajar_guru 
                                                                    SET kapasitas_terisi = kapasitas_terisi + 1 
                                                                    WHERE id = ?";
                                            $stmt_update_kap = $conn->prepare($sql_update_kapasitas);
                                            $stmt_update_kap->bind_param("i", $sesi_id);
                                            $stmt_update_kap->execute();
                                            $stmt_update_kap->close();

                                            $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
                                            $stmt_tambah->close();
                                            header("Location: jadwalSiswa.php");
                                            exit();
                                        } else {
                                            $_SESSION['error_message'] = "Gagal menambahkan jadwal! Error: " . $stmt_tambah->error;
                                            $stmt_tambah->close();
                                        }
                                    } else {
                                        $_SESSION['error_message'] = "Gagal membuat sesi mengajar! Error: " . $stmt_buat->error;
                                        $stmt_buat->close();
                                    }
                                } catch (Exception $e) {
                                    // Jika trigger mencegah pembuatan sesi karena konflik waktu
                                    $_SESSION['error_message'] = "Anda sudah memiliki sesi mengajar dengan waktu yang bertabrakan!";
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "Semua field harus diisi!";
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
            $sql_get = "SELECT jb.siswa_pelajaran_id, jb.sesi_guru_id, sp.siswa_id, sp.guru_id, jb.pendaftaran_id
                       FROM jadwal_belajar jb
                       JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                       WHERE jb.id = ? AND sp.guru_id = ?";
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->bind_param("ii", $jadwal_id, $guru_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            $old_data = $result_get->fetch_assoc();
            $stmt_get->close();

            if (!$old_data) {
                $_SESSION['error_message'] = "Anda tidak memiliki akses untuk mengedit jadwal ini!";
                header("Location: jadwalSiswa.php");
                exit();
            }

            $siswa_pelajaran_id = $old_data['siswa_pelajaran_id'] ?? 0;
            $siswa_id = $old_data['siswa_id'] ?? 0;
            $current_guru_id = $old_data['guru_id'] ?? 0;
            $pendaftaran_id = $old_data['pendaftaran_id'] ?? 0;
            $old_sesi_id = $old_data['sesi_guru_id'] ?? 0;

            if ($siswa_pelajaran_id && $siswa_id && $current_guru_id && $pendaftaran_id) {
                // Cek apakah siswa sudah memiliki jadwal pada hari dan jam yang sama (untuk mata pelajaran LAIN)
                $sql_cek_siswa = "SELECT jb.id 
                               FROM jadwal_belajar jb
                               JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                               JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                               JOIN sesi_mengajar_guru smg ON jb.sesi_guru_id = smg.id
                               WHERE ps.siswa_id = ? 
                               AND smg.hari = ? 
                               AND jb.id != ?
                               AND jb.status = 'aktif'
                               AND smg.jam_mulai = ? 
                               AND smg.jam_selesai = ?";
                $stmt_cek_siswa = $conn->prepare($sql_cek_siswa);
                $stmt_cek_siswa->bind_param(
                    "issss",
                    $siswa_id,
                    $hari,
                    $jadwal_id,
                    $jam_mulai,
                    $jam_selesai
                );
                $stmt_cek_siswa->execute();
                $result_cek_siswa = $stmt_cek_siswa->get_result(); // PERBAIKAN: $stmt_cek_siswa, bukan $stmt_cek_sisiwa

                if ($result_cek_siswa->num_rows > 0) {
                    $_SESSION['error_message'] = "Siswa sudah memiliki jadwal lain pada hari dan jam ini!";
                    $stmt_cek_siswa->close();
                } else {
                    $stmt_cek_siswa->close();

                    // Cari sesi yang sudah ada dengan hari dan jam yang sama
                    $sql_cek_sesi = "SELECT id, kapasitas_maks, kapasitas_terisi, status 
                                   FROM sesi_mengajar_guru 
                                   WHERE guru_id = ? 
                                   AND hari = ? 
                                   AND jam_mulai = ? 
                                   AND jam_selesai = ?";
                    $stmt_cek_sesi = $conn->prepare($sql_cek_sesi);
                    $stmt_cek_sesi->bind_param("isss", $guru_id, $hari, $jam_mulai, $jam_selesai);
                    $stmt_cek_sesi->execute();
                    $result_cek_sesi = $stmt_cek_sesi->get_result();

                    if ($result_cek_sesi->num_rows > 0) {
                        // Gunakan sesi yang sudah ada
                        $new_sesi_data = $result_cek_sesi->fetch_assoc();
                        $stmt_cek_sesi->close();

                        if ($new_sesi_data['kapasitas_terisi'] >= $new_sesi_data['kapasitas_maks']) {
                            $_SESSION['error_message'] = "Kapasitas sesi mengajar sudah penuh!";
                        } else if ($new_sesi_data['status'] != 'tersedia') {
                            $_SESSION['error_message'] = "Sesi mengajar tidak tersedia!";
                        } else if ($new_sesi_data['id'] == $old_sesi_id) {
                            // Sesi tidak berubah, tidak perlu update
                            $_SESSION['success_message'] = "Tidak ada perubahan pada jadwal.";
                            header("Location: jadwalSiswa.php");
                            exit();
                        } else {
                            // Update jadwal dengan sesi yang baru
                            $sql_update = "UPDATE jadwal_belajar 
                                          SET sesi_guru_id = ?, updated_at = NOW()
                                          WHERE id = ?";
                            $stmt_update = $conn->prepare($sql_update);
                            $stmt_update->bind_param("ii", $new_sesi_data['id'], $jadwal_id);

                            if ($stmt_update->execute()) {
                                // Update kapasitas: kurangi dari sesi lama, tambah ke sesi baru

                                // Kurangi kapasitas sesi lama
                                $sql_update_old = "UPDATE sesi_mengajar_guru 
                                                  SET kapasitas_terisi = GREATEST(0, kapasitas_terisi - 1) 
                                                  WHERE id = ?";
                                $stmt_update_old = $conn->prepare($sql_update_old);
                                $stmt_update_old->bind_param("i", $old_sesi_id);
                                $stmt_update_old->execute();
                                $stmt_update_old->close();

                                // Tambah kapasitas sesi baru
                                $sql_update_new = "UPDATE sesi_mengajar_guru 
                                                  SET kapasitas_terisi = kapasitas_terisi + 1 
                                                  WHERE id = ?";
                                $stmt_update_new = $conn->prepare($sql_update_new);
                                $stmt_update_new->bind_param("i", $new_sesi_data['id']);
                                $stmt_update_new->execute();
                                $stmt_update_new->close();

                                $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
                                $stmt_update->close();
                                header("Location: jadwalSiswa.php");
                                exit();
                            } else {
                                $_SESSION['error_message'] = "Gagal memperbarui jadwal! Error: " . $stmt_update->error;
                                $stmt_update->close();
                            }
                        }
                    } else {
                        $stmt_cek_sesi->close();

                        // Buat sesi baru (handle error trigger dengan try-catch)
                        try {
                            $kapasitas_maks = 5; // Sesuai data di tabel guru
                            $sql_buat_sesi = "INSERT INTO sesi_mengajar_guru 
                                             (guru_id, hari, jam_mulai, jam_selesai, kapasitas_maks, kapasitas_terisi, status) 
                                             VALUES (?, ?, ?, ?, ?, 0, 'tersedia')";

                            $stmt_buat = $conn->prepare($sql_buat_sesi);
                            $stmt_buat->bind_param("isssi", $guru_id, $hari, $jam_mulai, $jam_selesai, $kapasitas_maks);

                            if ($stmt_buat->execute()) {
                                $new_sesi_id = $stmt_buat->insert_id;
                                $stmt_buat->close();

                                // Update jadwal dengan sesi yang baru
                                $sql_update = "UPDATE jadwal_belajar 
                                              SET sesi_guru_id = ?, updated_at = NOW()
                                              WHERE id = ?";
                                $stmt_update = $conn->prepare($sql_update);
                                $stmt_update->bind_param("ii", $new_sesi_id, $jadwal_id);

                                if ($stmt_update->execute()) {
                                    // Update kapasitas: kurangi dari sesi lama, tambah ke sesi baru

                                    // Kurangi kapasitas sesi lama
                                    $sql_update_old = "UPDATE sesi_mengajar_guru 
                                                      SET kapasitas_terisi = GREATEST(0, kapasitas_terisi - 1) 
                                                      WHERE id = ?";
                                    $stmt_update_old = $conn->prepare($sql_update_old);
                                    $stmt_update_old->bind_param("i", $old_sesi_id);
                                    $stmt_update_old->execute();
                                    $stmt_update_old->close();

                                    // Tambah kapasitas sesi baru
                                    $sql_update_new = "UPDATE sesi_mengajar_guru 
                                                      SET kapasitas_terisi = kapasitas_terisi + 1 
                                                      WHERE id = ?";
                                    $stmt_update_new = $conn->prepare($sql_update_new);
                                    $stmt_update_new->bind_param("i", $new_sesi_id);
                                    $stmt_update_new->execute();
                                    $stmt_update_new->close();

                                    $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
                                    $stmt_update->close();
                                    header("Location: jadwalSiswa.php");
                                    exit();
                                } else {
                                    $_SESSION['error_message'] = "Gagal memperbarui jadwal! Error: " . $stmt_update->error;
                                    $stmt_update->close();
                                }
                            } else {
                                $_SESSION['error_message'] = "Gagal membuat sesi mengajar! Error: " . $stmt_buat->error;
                                $stmt_buat->close();
                            }
                        } catch (Exception $e) {
                            // Jika trigger mencegah pembuatan sesi karena konflik waktu
                            $_SESSION['error_message'] = "Anda sudah memiliki sesi mengajar dengan waktu yang bertabrakan!";
                        }
                    }
                }
            } else {
                $_SESSION['error_message'] = "Data jadwal tidak lengkap!";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }
}

// PROSES HAPUS JADWAL
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['hapus_jadwal'])) {
    $jadwal_id = $_POST['jadwal_id'] ?? '';

    if ($jadwal_id) {
        try {
            // Cek apakah jadwal ini milik guru ini (melalui siswa_pelajaran)
            $sql_cek_akses = "SELECT jb.id, jb.sesi_guru_id, sp.guru_id
                            FROM jadwal_belajar jb
                            JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                            WHERE jb.id = ? AND sp.guru_id = ?";
            $stmt_cek_akses = $conn->prepare($sql_cek_akses);
            $stmt_cek_akses->bind_param("ii", $jadwal_id, $guru_id);
            $stmt_cek_akses->execute();
            $result_cek_akses = $stmt_cek_akses->get_result();

            if ($result_cek_akses->num_rows == 0) {
                $_SESSION['error_message'] = "Anda tidak memiliki akses untuk menghapus jadwal ini!";
                header("Location: jadwalSiswa.php");
                exit();
            }

            $akses_data = $result_cek_akses->fetch_assoc();
            $sesi_guru_id = $akses_data['sesi_guru_id'];
            $stmt_cek_akses->close();

            // Hard delete
            $sql_hapus = "DELETE FROM jadwal_belajar WHERE id = ?";
            $stmt_hapus = $conn->prepare($sql_hapus);
            $stmt_hapus->bind_param("i", $jadwal_id);

            if ($stmt_hapus->execute()) {
                $affected_rows = $stmt_hapus->affected_rows;
                $stmt_hapus->close();

                if ($affected_rows > 0) {
                    // Update kapasitas terisi di sesi mengajar
                    $sql_update_kapasitas = "UPDATE sesi_mengajar_guru 
                                            SET kapasitas_terisi = GREATEST(0, kapasitas_terisi - 1) 
                                            WHERE id = ?";
                    $stmt_update_kap = $conn->prepare($sql_update_kapasitas);
                    $stmt_update_kap->bind_param("i", $sesi_guru_id);
                    $stmt_update_kap->execute();
                    $stmt_update_kap->close();

                    $_SESSION['success_message'] = "Jadwal berhasil dihapus!";
                } else {
                    $_SESSION['error_message'] = "Jadwal tidak ditemukan!";
                }
                header("Location: jadwalSiswa.php");
                exit();
            } else {
                $_SESSION['error_message'] = "Gagal menghapus jadwal!";
            }
            $stmt_hapus->close();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
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

// TAMBAHKAN DEBUG INFO (opsional)
error_log("=== DEBUG INFO JADWAL SISWA ===");
error_log("Guru ID: " . $guru_id);
error_log("Total siswa tanpa jadwal: " . count($siswa_dan_mapel_tanpa_jadwal));
foreach ($siswa_dan_mapel_tanpa_jadwal as $siswa_id => $siswa_data) {
    error_log("Siswa: " . $siswa_data['nama_lengkap'] . " (" . count($siswa_data['mata_pelajaran']) . " mapel)");
}

// AJAX: Ambil daftar siswa untuk autocomplete (GURU)
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_siswa_list_guru') {
    $guru_id_ajax = $guru_id; // Dari session

    $siswa_list_ajax = [];

    // PERBAIKAN QUERY: JOIN dengan pendaftaran_siswa
    $sql_siswa_ajax = "SELECT 
                        s.id, 
                        s.nama_lengkap, 
                        s.kelas,
                        s.sekolah_asal,
                        COUNT(DISTINCT sp.id) as total_mapel,
                        GROUP_CONCAT(DISTINCT sp.nama_pelajaran SEPARATOR ', ') as daftar_mapel
                    FROM siswa s
                    JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id
                    JOIN siswa_pelajaran sp ON ps.id = sp.pendaftaran_id
                    WHERE (sp.guru_id = ? OR sp.guru_id IS NULL)
                    AND sp.status = 'aktif'
                    AND ps.status = 'aktif'
                    AND s.status = 'aktif'
                    GROUP BY s.id
                    ORDER BY s.nama_lengkap";

    $stmt_siswa_ajax = $conn->prepare($sql_siswa_ajax);
    $stmt_siswa_ajax->bind_param("i", $guru_id_ajax);
    $stmt_siswa_ajax->execute();
    $result_siswa_ajax = $stmt_siswa_ajax->get_result();

    while ($row = $result_siswa_ajax->fetch_assoc()) {
        $siswa_list_ajax[] = $row;
    }
    $stmt_siswa_ajax->close();

    header('Content-Type: application/json');
    echo json_encode($siswa_list_ajax);
    exit();
}

// AJAX: Ambil mata pelajaran berdasarkan siswa (GURU)
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_pelajaran_guru' && isset($_GET['siswa_id'])) {
    $siswa_id = $_GET['siswa_id'];

    // Ambil mata pelajaran siswa YANG BISA DIAJAR oleh guru ini (guru_id = guru ini ATAU NULL)
    // dan BELUM ADA JADWAL SAMA SEKALI
    $mata_pelajaran = [];

    // PERBAIKAN QUERY: Ambil melalui pendaftaran_siswa
    $sql = "SELECT sp.id, sp.nama_pelajaran, ps.tingkat, ps.jenis_kelas, sp.guru_id
            FROM pendaftaran_siswa ps
            JOIN siswa_pelajaran sp ON ps.id = sp.pendaftaran_id
            WHERE ps.siswa_id = ? 
            AND (sp.guru_id = ? OR sp.guru_id IS NULL)
            AND sp.status = 'aktif'
            AND ps.status = 'aktif'
            ORDER BY sp.nama_pelajaran";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $siswa_id, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $pelajaran_id = $row['id'];

        // CEK APAKAH SUDAH ADA JADWAL UNTUK MAPEL INI (dengan guru SIAPAPUN)
        $sql_cek_jadwal = "SELECT COUNT(*) as jumlah_jadwal 
                          FROM jadwal_belajar jb
                          WHERE jb.siswa_pelajaran_id = ? 
                          AND jb.status = 'aktif'";

        $stmt_cek = $conn->prepare($sql_cek_jadwal);
        $stmt_cek->bind_param("i", $pelajaran_id);
        $stmt_cek->execute();
        $result_cek = $stmt_cek->get_result();
        $row_cek = $result_cek->fetch_assoc();
        $stmt_cek->close();

        // Hanya tambahkan jika BELUM ADA JADWAL SAMA SEKALI
        if ($row_cek['jumlah_jadwal'] == 0) {
            $mata_pelajaran[] = [
                'id' => $pelajaran_id,
                'nama_pelajaran' => $row['nama_pelajaran'],
                'tingkat' => $row['tingkat'],
                'jenis_kelas' => $row['jenis_kelas'],
                'guru_id' => $row['guru_id'],
                'guru_info' => $row['guru_id'] ? 'Sudah ditugaskan' : 'Belum ditugaskan'
            ];
        }
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($mata_pelajaran);
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

        /* Info box untuk selected siswa */
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
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
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

                        <!-- Filter Siswa -->
                        <div>
                            <label class="block text-sm font-medium text-gray-900 mb-1">
                                Filter Berdasarkan Siswa
                            </label>
                            <select name="siswa"
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Siswa</option>
                                <?php foreach ($semua_siswa_guru as $siswa): ?>
                                    <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa == $siswa['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> (Kelas:
                                        <?php echo $siswa['kelas']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex space-x-2 pt-1">
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                        <?php if ($filter_hari || $filter_siswa): ?>
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
                        <?php if ($filter_hari): ?>
                            <span class="text-sm font-normal text-gray-500 ml-2">(Filter:
                                <?php echo $filter_hari; ?>)</span>
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
                                        <th>Mata Pelajaran</th>
                                        <th>Tingkat Bimbel</th>
                                        <th>Hari</th>
                                        <th>Jam Mulai</th>
                                        <th>Jam Selesai</th>
                                        <th>Kapasitas</th>
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
                                            <td>
                                                <div class="font-medium"><?php echo $jadwal['mata_pelajaran']; ?></div>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = '';
                                                switch ($jadwal['tingkat']) {
                                                    case 'SD':
                                                        $badge_class = 'badge-warning';
                                                        break;
                                                    case 'SMP':
                                                        $badge_class = 'badge-success';
                                                        break;
                                                    case 'SMA':
                                                        $badge_class = 'badge-primary';
                                                        break;
                                                    default:
                                                        $badge_class = 'badge-danger';
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $jadwal['tingkat']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary"><?php echo $jadwal['hari']; ?></span>
                                            </td>
                                            <td class="font-medium"><?php echo $jadwal['jam_mulai_format']; ?></td>
                                            <td class="font-medium"><?php echo $jadwal['jam_selesai_format']; ?></td>
                                            <td>
                                                <span class="text-sm">
                                                    <?php echo $jadwal['kapasitas_terisi']; ?>/<?php echo $jadwal['kapasitas_maks']; ?>
                                                </span>
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
                                                '<?php echo htmlspecialchars($jadwal['nama_lengkap']); ?>', 
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
                                <?php if ($filter_hari): ?>
                                    Tidak ada jadwal untuk hari <?php echo $filter_hari; ?>
                                <?php else: ?>
                                    Belum ada jadwal belajar yang ditambahkan
                                <?php endif; ?>
                            </p>
                            <?php if (!$filter_hari && count($siswa_dan_mapel_tanpa_jadwal) > 0): ?>
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

    <!-- Modal Tambah Jadwal - DENGAN SEARCH/AUTOCOMPLETE -->
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
                        <!-- Cari Siswa dengan Search/Autocomplete -->
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
                                        <div class="text-xs text-gray-500 mt-1" id="selectedSiswaMapel"></div>
                                    </div>
                                    <button type="button" onclick="clearSelectedSiswa()"
                                        class="text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
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

        // Close menu when clicking on menu items (mobile)
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    mobileMenu.classList.remove('menu-open');
                    if (menuOverlay) {
                        menuOverlay.classList.remove('active');
                    }
                    document.body.style.overflow = 'auto';
                }
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
        function openHapusModal(jadwalId, namaSiswa, hari, waktu) {
            jadwalIdToDelete = jadwalId;

            // Set informasi di modal
            document.getElementById('hapusNamaSiswa').textContent = namaSiswa;
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

        // ==================== AUTOSEARCH SISWA (GURU) ====================
        // Fungsi untuk load data siswa untuk guru
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
                    console.log('Data siswa untuk guru loaded:', siswaData.length, 'records');
                    // Inisialisasi autocomplete setelah data loaded
                    initAutocompleteGuru();
                },
                error: function (xhr, status, error) {
                    console.error('Failed to load siswa data:', error);
                    console.error('Response:', xhr.responseText);
                }
            });
        }

        // Inisialisasi autocomplete untuk guru
        function initAutocompleteGuru() {
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
                item.dataset.mapel = siswa.daftar_mapel || '';
                item.dataset.total_mapel = siswa.total_mapel || '0';

                item.innerHTML = `
                <div class="siswa-nama">${siswa.nama_lengkap}</div>
                <div class="siswa-info">
                    Kelas: ${siswa.kelas} | 
                    Sekolah: ${siswa.sekolah_asal || '-'}
                    ${siswa.total_mapel ? '<br>Total Mapel: ' + siswa.total_mapel : ''}
                </div>
            `;

                item.addEventListener('click', function () {
                    selectSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const dropdown = document.getElementById('siswaDropdown');
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
            console.log("Siswa selected:", data);

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

            if (data.mapel) {
                document.getElementById('selectedSiswaMapel').textContent = 'Mata Pelajaran: ' + data.mapel;
            }

            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';

            console.log("Calling loadMataPelajaranGuru with siswaId:", data.id);
            // Load mata pelajaran untuk siswa ini
            loadMataPelajaranGuru(data.id);
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

        // ==================== LOAD MATA PELAJARAN (GURU) ====================
        function loadMataPelajaranGuru(siswaId = null) {
            const siswaIdVal = siswaId || document.getElementById('selectedSiswaId').value;
            const pelajaranSelect = document.getElementById('tambahMataPelajaran');

            console.log("loadMataPelajaranGuru called with siswaId:", siswaIdVal);

            if (!siswaIdVal) {
                console.log("No siswa selected");
                pelajaranSelect.innerHTML = '<option value="">-- Pilih Siswa terlebih dahulu --</option>';
                pelajaranSelect.disabled = true;
                return;
            }

            // Tampilkan loading
            pelajaranSelect.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
            pelajaranSelect.disabled = true;

            // AJAX request khusus untuk guru
            $.ajax({
                url: 'jadwalSiswa.php',
                type: 'GET',
                data: {
                    ajax: 'get_pelajaran_guru',
                    siswa_id: siswaIdVal
                },
                dataType: 'json',
                success: function (data) {
                    console.log("AJAX Response mata pelajaran:", data);

                    if (Array.isArray(data)) {
                        if (data.length > 0) {
                            let options = '<option value="">-- Pilih Mata Pelajaran --</option>';
                            data.forEach(function (pelajaran) {
                                const guruStatus = pelajaran.guru_id ? ' (Sudah Terjadwal)' : ' (Belum Terjadwal)';
                                options += `<option value="${pelajaran.id}">${pelajaran.nama_pelajaran} - ${pelajaran.tingkat}${guruStatus}</option>`;
                            });
                            pelajaranSelect.innerHTML = options;
                        } else {
                            pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran yang tersedia</option>';
                        }
                    } else {
                        console.error("Invalid response format:", data);
                        pelajaranSelect.innerHTML = '<option value="">Format response tidak valid</option>';
                    }
                    pelajaranSelect.disabled = false;
                },
                error: function (xhr, status, error) {
                    console.error("AJAX Error mata pelajaran:", status, error);
                    console.error("Response text:", xhr.responseText);
                    pelajaranSelect.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
                    pelajaranSelect.disabled = false;
                }
            });
        }

        // ==================== VALIDASI FORM ====================
        // Validasi form tambah: Jam selesai harus > jam mulai
        document.getElementById('formTambahJadwal').addEventListener('submit', function (e) {
            const jamMulai = document.querySelector('#formTambahJadwal input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('#formTambahJadwal input[name="jam_selesai"]').value;
            const siswaId = document.getElementById('selectedSiswaId').value;
            const siswaPelajaran = document.getElementById('tambahMataPelajaran').value;
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

        // ==================== EVENT LISTENERS ====================
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
        // Load siswa data saat page load
        $(document).ready(function () {
            // Load data siswa untuk autocomplete (GURU)
            loadSiswaDataGuru();
        });
    </script>
</body>

</html>