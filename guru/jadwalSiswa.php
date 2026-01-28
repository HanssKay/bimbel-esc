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
    $where_conditions[] = "jb.hari = ?";
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
        DATE_FORMAT(jb.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(jb.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, jb.jam_mulai, jb.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
    JOIN siswa s ON ps.siswa_id = s.id
    LEFT JOIN guru g ON sp.guru_id = g.id
    LEFT JOIN users u ON g.user_id = u.id
    WHERE {$where_clause}
    ORDER BY 
        FIELD(jb.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
        jb.jam_mulai,
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
function getMataPelajaranTanpaJadwal($conn, $siswa_id, $guru_id) {
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
                                     WHERE ps.siswa_id = ? 
                                     AND jb.hari = ? 
                                     AND jb.status = 'aktif'
                                     AND jb.siswa_pelajaran_id != ?
                                     AND (
                                         (jb.jam_mulai < ? AND jb.jam_selesai > ?) OR
                                         (? BETWEEN jb.jam_mulai AND jb.jam_selesai)
                                     )";
                    $stmt_cek = $conn->prepare($sql_cek_siswa);
                    $stmt_cek->bind_param("isisss", 
                        $siswa_id, 
                        $hari, 
                        $siswa_pelajaran_id,
                        $jam_selesai, $jam_mulai,
                        $jam_mulai
                    );
                    $stmt_cek->execute();
                    $result_cek = $stmt_cek->get_result();
                    
                    if ($result_cek->num_rows > 0) {
                        $_SESSION['error_message'] = "Siswa sudah memiliki jadwal pada hari dan jam yang sama!";
                        $stmt_cek->close();
                    } else {
                        $stmt_cek->close();
                        
                        // Cek apakah guru sudah memiliki jadwal dengan siswa lain pada waktu yang sama
                        $sql_cek_guru = "SELECT jb.id 
                                       FROM jadwal_belajar jb
                                       JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                                       WHERE sp.guru_id = ?
                                       AND jb.hari = ? 
                                       AND jb.status = 'aktif'
                                       AND jb.siswa_pelajaran_id != ?
                                       AND (
                                           (jb.jam_mulai < ? AND jb.jam_selesai > ?) OR
                                           (? BETWEEN jb.jam_mulai AND jb.jam_selesai)
                                       )";
                        $stmt_cek_guru = $conn->prepare($sql_cek_guru);
                        $stmt_cek_guru->bind_param("isisss", 
                            $guru_id, 
                            $hari, 
                            $siswa_pelajaran_id,
                            $jam_selesai, $jam_mulai,
                            $jam_mulai
                        );
                        $stmt_cek_guru->execute();
                        $result_cek_guru = $stmt_cek_guru->get_result();
                        
                        if ($result_cek_guru->num_rows > 0) {
                            $_SESSION['error_message'] = "Anda sudah memiliki jadwal dengan siswa lain pada waktu yang sama!";
                            $stmt_cek_guru->close();
                        } else {
                            $stmt_cek_guru->close();
                            
                            // Tambah jadwal baru
                            $sql_tambah = "INSERT INTO jadwal_belajar 
                                          (pendaftaran_id, siswa_pelajaran_id, hari, jam_mulai, jam_selesai, status) 
                                          VALUES (?, ?, ?, ?, ?, 'aktif')";
                            $stmt_tambah = $conn->prepare($sql_tambah);
                            $stmt_tambah->bind_param("iisss", $pendaftaran_id, $siswa_pelajaran_id, $hari, $jam_mulai, $jam_selesai);
                            
                            if ($stmt_tambah->execute()) {
                                $_SESSION['success_message'] = "Jadwal berhasil ditambahkan!";
                                $stmt_tambah->close();
                                header("Location: jadwalSiswa.php");
                                exit();
                            } else {
                                $_SESSION['error_message'] = "Gagal menambahkan jadwal! Error: " . $stmt_tambah->error;
                                $stmt_tambah->close();
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
            $sql_get = "SELECT jb.siswa_pelajaran_id, sp.siswa_id, sp.guru_id, jb.pendaftaran_id
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
            
            if ($siswa_pelajaran_id && $siswa_id && $current_guru_id && $pendaftaran_id) {
                // Cek bentrok untuk siswa
                $sql_cek_siswa = "SELECT jb.id 
                               FROM jadwal_belajar jb
                               JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                               JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                               WHERE ps.siswa_id = ? 
                               AND jb.hari = ? 
                               AND jb.id != ?
                               AND jb.status = 'aktif'
                               AND (
                                   (jb.jam_mulai < ? AND jb.jam_selesai > ?) OR
                                   (? BETWEEN jb.jam_mulai AND jb.jam_selesai)
                               )";
                $stmt_cek_siswa = $conn->prepare($sql_cek_siswa);
                $stmt_cek_siswa->bind_param("isisss", 
                    $siswa_id, 
                    $hari, 
                    $jadwal_id,
                    $jam_selesai, $jam_mulai,
                    $jam_mulai
                );
                $stmt_cek_siswa->execute();
                $result_cek_siswa = $stmt_cek_siswa->get_result();
                
                if ($result_cek_siswa->num_rows > 0) {
                    $_SESSION['error_message'] = "Siswa sudah memiliki jadwal lain pada waktu ini!";
                    $stmt_cek_siswa->close();
                } else {
                    $stmt_cek_siswa->close();
                    
                    // Cek bentrok untuk guru
                    $sql_cek_guru = "SELECT jb.id 
                                   FROM jadwal_belajar jb
                                   JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
                                   WHERE sp.guru_id = ?
                                   AND jb.hari = ? 
                                   AND jb.id != ?
                                   AND jb.status = 'aktif'
                                   AND (
                                       (jb.jam_mulai < ? AND jb.jam_selesai > ?) OR
                                       (? BETWEEN jb.jam_mulai AND jb.jam_selesai)
                                   )";
                    $stmt_cek_guru = $conn->prepare($sql_cek_guru);
                    $stmt_cek_guru->bind_param("isisss", 
                        $guru_id, 
                        $hari, 
                        $jadwal_id,
                        $jam_selesai, $jam_mulai,
                        $jam_mulai
                    );
                    $stmt_cek_guru->execute();
                    $result_cek_guru = $stmt_cek_guru->get_result();
                    
                    if ($result_cek_guru->num_rows > 0) {
                        $_SESSION['error_message'] = "Anda sudah memiliki jadwal lain pada waktu yang sama!";
                        $stmt_cek_guru->close();
                    } else {
                        $stmt_cek_guru->close();
                        
                        // Update jadwal
                        $sql_update = "UPDATE jadwal_belajar 
                                      SET hari = ?, jam_mulai = ?, jam_selesai = ?, updated_at = NOW()
                                      WHERE id = ?";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->bind_param("sssi", $hari, $jam_mulai, $jam_selesai, $jadwal_id);
                        
                        if ($stmt_update->execute()) {
                            $_SESSION['success_message'] = "Jadwal berhasil diperbarui!";
                            $stmt_update->close();
                            header("Location: jadwalSiswa.php");
                            exit();
                        } else {
                            $_SESSION['error_message'] = "Gagal memperbarui jadwal! Error: " . $stmt_update->error;
                            $stmt_update->close();
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
            $sql_cek_akses = "SELECT jb.id 
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
            $stmt_cek_akses->close();
            
            // Hard delete
            $sql_hapus = "DELETE FROM jadwal_belajar WHERE id = ?";
            $stmt_hapus = $conn->prepare($sql_hapus);
            $stmt_hapus->bind_param("i", $jadwal_id);
            
            if ($stmt_hapus->execute()) {
                $affected_rows = $stmt_hapus->affected_rows;
                $stmt_hapus->close();
                
                if ($affected_rows > 0) {
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

// HAPUS AJAX HANDLER YANG DI BAWAH (karena sudah dipindah ke atas)
// JANGAN ada AJAX handler lagi di sini!

// TAMBAHKAN DEBUG INFO (opsional)
error_log("=== DEBUG INFO JADWAL SISWA ===");
error_log("Guru ID: " . $guru_id);
error_log("Total siswa tanpa jadwal: " . count($siswa_dan_mapel_tanpa_jadwal));
foreach ($siswa_dan_mapel_tanpa_jadwal as $siswa_id => $siswa_data) {
    error_log("Siswa: " . $siswa_data['nama_lengkap'] . " (" . count($siswa_data['mata_pelajaran']) . " mapel)");
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
                        Kelola jadwal belajar siswa yang Anda bimbing
                    </p>
                </div>
                <div class="mt-2 md:mt-0 flex space-x-2">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                    <button onclick="openTambahModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm md:text-base">
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
                    <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="container mx-auto p-4">
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
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
                            <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($total_jadwal); ?></h3>
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
                            <select name="hari" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Hari</option>
                                <option value="Senin" <?php echo $filter_hari == 'Senin' ? 'selected' : ''; ?>>Senin</option>
                                <option value="Selasa" <?php echo $filter_hari == 'Selasa' ? 'selected' : ''; ?>>Selasa</option>
                                <option value="Rabu" <?php echo $filter_hari == 'Rabu' ? 'selected' : ''; ?>>Rabu</option>
                                <option value="Kamis" <?php echo $filter_hari == 'Kamis' ? 'selected' : ''; ?>>Kamis</option>
                                <option value="Jumat" <?php echo $filter_hari == 'Jumat' ? 'selected' : ''; ?>>Jumat</option>
                                <option value="Sabtu" <?php echo $filter_hari == 'Sabtu' ? 'selected' : ''; ?>>Sabtu</option>
                                <option value="Minggu" <?php echo $filter_hari == 'Minggu' ? 'selected' : ''; ?>>Minggu</option>
                            </select>
                        </div>
                        
                        <!-- Filter Siswa -->
                        <div>
                            <label class="block text-sm font-medium text-gray-900 mb-1">
                                Filter Berdasarkan Siswa
                            </label>
                            <select name="siswa" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Siswa</option>
                                <?php foreach ($semua_siswa_guru as $siswa): ?>
                                <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa == $siswa['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> (Kelas: <?php echo $siswa['kelas']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex space-x-2 pt-1">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-filter mr-2"></i> Terapkan Filter
                        </button>
                        <?php if ($filter_hari || $filter_siswa): ?>
                        <a href="jadwalSiswa.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 flex items-center">
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
                        <span class="text-sm font-normal text-gray-500 ml-2">(Filter: <?php echo $filter_hari; ?>)</span>
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
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($jadwal_siswa as $jadwal): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($jadwal['nama_lengkap']); ?></div>
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
                                        switch($jadwal['tingkat']) {
                                            case 'SD': $badge_class = 'badge-warning'; break;
                                            case 'SMP': $badge_class = 'badge-success'; break;
                                            case 'SMA': $badge_class = 'badge-primary'; break;
                                            default: $badge_class = 'badge-danger';
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
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(
                                                <?php echo $jadwal['jadwal_id']; ?>,
                                                '<?php echo $jadwal['hari']; ?>',
                                                '<?php echo $jadwal['jam_mulai']; ?>',
                                                '<?php echo $jadwal['jam_selesai']; ?>'
                                            )" class="px-3 py-1 bg-yellow-500 text-white rounded text-sm hover:bg-yellow-600">
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
                        <button onclick="openTambahModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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
                    <div class="text-center p-4 border rounded-lg <?php echo $filter_hari == $day ? 'bg-blue-50 border-blue-300' : 'border-gray-200'; ?>">
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

    <!-- Modal Tambah Jadwal - HANYA TAMPILKAN SISWA YANG PUNYA MAPEL TANPA JADWAL -->
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
            
            <?php if (count($siswa_dan_mapel_tanpa_jadwal) > 0): ?>
            <form id="formTambahJadwal" method="POST" action="">
                <div class="space-y-4">
                    <!-- Pilih Siswa -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Pilih Siswa
                        </label>
                        <select name="siswa_id" id="tambahSiswa" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($siswa_dan_mapel_tanpa_jadwal as $siswa_id => $siswa): ?>
                            <option value="<?php echo $siswa_id; ?>">
                                <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> 
                                (<?php echo $siswa['kelas']; ?>)
                                - <?php echo count($siswa['mata_pelajaran']); ?> mapel tersedia
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
                            <option value="">-- Pilih Mata Pelajaran --</option>
                            <!-- Data akan diisi via AJAX -->
                        </select>
                        <!--<p class="text-xs text-gray-500 mt-1">-->
                        <!--    * Hanya menampilkan mata pelajaran yang BELUM ADA JADWAL sama sekali-->
                        <!--</p>-->
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
            <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-calendar-check text-4xl mb-4"></i>
                <h3 class="text-lg font-medium mb-2">Semua siswa sudah terjadwal</h3>
                <p>Tidak ada siswa yang memiliki mata pelajaran tanpa jadwal.</p>
            </div>
            <?php endif; ?>
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

        // Modal Functions
        function openTambahModal() {
            document.getElementById('modalTambah').classList.add('active');
            document.body.style.overflow = 'hidden';
            // Reset form
            resetTambahForm();
        }

        function closeTambahModal() {
            document.getElementById('modalTambah').classList.remove('active');
            document.body.style.overflow = 'auto';
            resetTambahForm();
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

        function resetTambahForm() {
            document.getElementById('formTambahJadwal').reset();
            document.getElementById('tambahMataPelajaran').innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
        }

     function loadMataPelajaran() {
    const siswaId = document.getElementById('tambahSiswa').value;
    const pelajaranSelect = document.getElementById('tambahMataPelajaran');
    
    if (!siswaId) {
        pelajaranSelect.innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
        return;
    }
    
    // Tampilkan loading
    pelajaranSelect.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
    pelajaranSelect.disabled = true;
    
    // AJAX request ke FILE TERPISAH
    $.ajax({
        url: 'get_pelajaran.php',
        type: 'GET',
        data: {
            siswa_id: siswaId
        },
        dataType: 'json',
        success: function(data) {
            console.log("AJAX Response:", data);
            
            if (Array.isArray(data)) {
                if (data.length > 0) {
                    let options = '<option value="">-- Pilih Mata Pelajaran --</option>';
                    data.forEach(function(pelajaran) {
                        // Skip jika ada error property
                        if (pelajaran.error) {
                            console.error("Error in response:", pelajaran.error);
                            return;
                        }
                        // Tandai jika guru_id NULL
                        const guruInfo = pelajaran.guru_id ? '' : ' (Belum ada guru)';
                        options += `<option value="${pelajaran.id}">${pelajaran.nama_pelajaran} (${pelajaran.tingkat} - ${pelajaran.jenis_kelas})${guruInfo}</option>`;
                    });
                    pelajaranSelect.innerHTML = options;
                } else {
                    pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran yang tersedia</option>';
                }
            } else if (data && data.error) {
                console.error("Server error:", data.error);
                pelajaranSelect.innerHTML = '<option value="">Error: ' + data.error + '</option>';
            } else {
                console.error("Invalid response format:", data);
                pelajaranSelect.innerHTML = '<option value="">Format response tidak valid</option>';
            }
            pelajaranSelect.disabled = false;
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", status, error);
            console.error("Response text:", xhr.responseText);
            pelajaranSelect.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
            pelajaranSelect.disabled = false;
        }
    });
}

        // Event listeners untuk form tambah
        document.getElementById('tambahSiswa').addEventListener('change', loadMataPelajaran);

        // Variabel global untuk menyimpan ID jadwal yang akan dihapus
        let jadwalIdToDelete = null;

        // Fungsi untuk membuka modal hapus
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

        // Fungsi untuk menutup modal hapus
        function closeHapusModal() {
            document.getElementById('modalHapus').classList.remove('active');
            document.body.style.overflow = 'auto';
            jadwalIdToDelete = null;
        }

        // Fungsi untuk memproses hapus
        function prosesHapus() {
            if (jadwalIdToDelete) {
                document.getElementById('hapusJadwalId').value = jadwalIdToDelete;
                document.getElementById('formHapusJadwal').submit();
            }
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTambahModal();
                closeEditModal();
                closeHapusModal();
            }
        });

        // Close modal when clicking outside
        document.querySelectorAll('.modal, .modal-konfirmasi').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (this.id === 'modalTambah') closeTambahModal();
                    if (this.id === 'modalEdit') closeEditModal();
                    if (this.id === 'modalHapus') closeHapusModal();
                }
            });
        });
    </script>
</body>
</html>