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
$kelas_data = [];
$kelas_detail = null;
$kelas_edit = null;
$siswa_options = [];
$guru_options = [];
$siswa_kelas_data = [];
$guru_kelas_data = [];
$jadwal_edit = null;
$active_tab = 'info'; // Default tab

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
$filter_tingkat = isset($_GET['filter_tingkat']) ? $_GET['filter_tingkat'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$tahun_ajaran = isset($_GET['tahun_ajaran']) ? $_GET['tahun_ajaran'] : '';

// SET ACTIVE TAB UNTUK DETAIL
if (isset($_GET['tab'])) {
    $active_tab = $_GET['tab'];
}

// =============== FUNGSI BANTU ===============
function generateKodeKelas($tingkat) {
    $prefix = '';
    switch($tingkat) {
        case 'SD': $prefix = 'SD'; break;
        case 'SMP': $prefix = 'SMP'; break;
        case 'SMA': $prefix = 'SMA'; break;
        default: $prefix = 'KLAS';
    }
    return $prefix . date('YmdHis');
}

function getTahunAjaranList() {
    $current_year = date('Y');
    $tahun_ajaran_list = [];
    
    for ($i = -2; $i <= 2; $i++) {
        $year = $current_year + $i;
        $tahun_ajaran_list[] = ($year - 1) . '/' . $year;
        $tahun_ajaran_list[] = $year . '/' . ($year + 1);
    }
    
    return array_unique($tahun_ajaran_list);
}

// =============== DETAIL KELAS ===============
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $kelas_id = intval($_GET['id']);
    
    // Load data kelas
    $sql = "SELECT * FROM kelas WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt->bind_param("i", $kelas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $kelas_detail = $row;
        
        // Hitung jumlah siswa
        $siswa_sql = "SELECT COUNT(*) as total FROM kelas_siswa WHERE kelas_id = ? AND status = 'aktif'";
        $siswa_stmt = $conn->prepare($siswa_sql);
        if ($siswa_stmt) {
            $siswa_stmt->bind_param("i", $kelas_id);
            $siswa_stmt->execute();
            $siswa_result = $siswa_stmt->get_result();
            $siswa_count = $siswa_result->fetch_assoc()['total'];
            $siswa_stmt->close();
            
            $kelas_detail['jumlah_siswa'] = $siswa_count;
            $kelas_detail['kapasitas_persen'] = ($siswa_count / $kelas_detail['kapasitas']) * 100;
        }
        
        // Load data siswa di kelas ini
        $siswa_kelas_sql = "SELECT ks.*, s.nis, s.nama_lengkap, s.jenis_kelamin, s.kelas as tingkat_sekolah
                           FROM kelas_siswa ks
                           JOIN siswa s ON ks.siswa_id = s.id
                           WHERE ks.kelas_id = ? AND ks.status = 'aktif'
                           ORDER BY s.nama_lengkap";
        
        $siswa_kelas_stmt = $conn->prepare($siswa_kelas_sql);
        if ($siswa_kelas_stmt) {
            $siswa_kelas_stmt->bind_param("i", $kelas_id);
            $siswa_kelas_stmt->execute();
            $siswa_kelas_result = $siswa_kelas_stmt->get_result();
            
            $siswa_di_kelas = [];
            while ($siswa_row = $siswa_kelas_result->fetch_assoc()) {
                $siswa_di_kelas[] = $siswa_row;
            }
            $siswa_kelas_stmt->close();
            
            $kelas_detail['siswa'] = $siswa_di_kelas;
        }
        
        // Load data guru pengajar di kelas ini
        $guru_kelas_sql = "SELECT kg.*, g.nip, u.full_name as nama_guru, 
                                  g.bidang_keahlian
                           FROM kelas_guru kg
                           JOIN guru g ON kg.guru_id = g.id
                           JOIN users u ON g.user_id = u.id
                           WHERE kg.kelas_id = ?
                           ORDER BY FIELD(kg.hari_mengajar, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
                                    kg.jam_mulai";
        
        $guru_kelas_stmt = $conn->prepare($guru_kelas_sql);
        if ($guru_kelas_stmt) {
            $guru_kelas_stmt->bind_param("i", $kelas_id);
            $guru_kelas_stmt->execute();
            $guru_kelas_result = $guru_kelas_stmt->get_result();
            
            $guru_di_kelas = [];
            while ($guru_row = $guru_kelas_result->fetch_assoc()) {
                $guru_di_kelas[] = $guru_row;
            }
            $guru_kelas_stmt->close();
            
            $kelas_detail['guru'] = $guru_di_kelas;
        }
        
        // Load statistik nilai
        $nilai_sql = "SELECT 
                      COUNT(DISTINCT ps.siswa_id) as siswa_dinilai,
                      AVG(ps.total_score) as rata_nilai,
                      MAX(ps.total_score) as nilai_tertinggi,
                      MIN(ps.total_score) as nilai_terendah
                      FROM penilaian_siswa ps
                      WHERE ps.kelas_id = ?";
        
        $nilai_stmt = $conn->prepare($nilai_sql);
        if ($nilai_stmt) {
            $nilai_stmt->bind_param("i", $kelas_id);
            $nilai_stmt->execute();
            $nilai_result = $nilai_stmt->get_result();
            $statistik = $nilai_result->fetch_assoc();
            $nilai_stmt->close();
            
            $kelas_detail['statistik'] = $statistik;
        }
    }
    $stmt->close();
    
    // Load opsi siswa yang belum punya kelas untuk ditambahkan
    $siswa_options_sql = "SELECT s.* 
                         FROM siswa s
                         LEFT JOIN kelas_siswa ks ON s.id = ks.siswa_id AND ks.status = 'aktif'
                         WHERE ks.id IS NULL AND s.status = 'aktif'
                         ORDER BY s.nama_lengkap";
    
    $siswa_options_stmt = $conn->prepare($siswa_options_sql);
    if ($siswa_options_stmt) {
        $siswa_options_stmt->execute();
        $siswa_options_result = $siswa_options_stmt->get_result();
        
        while ($siswa_opt = $siswa_options_result->fetch_assoc()) {
            $siswa_options[] = $siswa_opt;
        }
        $siswa_options_stmt->close();
    }
    
    // Load opsi guru yang belum mengajar di kelas ini
    $guru_options_sql = "SELECT g.*, u.full_name as nama_guru
                        FROM guru g
                        JOIN users u ON g.user_id = u.id
                        WHERE g.status = 'aktif'
                        AND g.id NOT IN (
                            SELECT guru_id FROM kelas_guru WHERE kelas_id = ?
                        )
                        ORDER BY u.full_name";
    
    $guru_options_stmt = $conn->prepare($guru_options_sql);
    if ($guru_options_stmt) {
        $guru_options_stmt->bind_param("i", $kelas_id);
        $guru_options_stmt->execute();
        $guru_options_result = $guru_options_stmt->get_result();
        
        while ($guru_opt = $guru_options_result->fetch_assoc()) {
            $guru_options[] = $guru_opt;
        }
        $guru_options_stmt->close();
    }
}

// =============== EDIT KELAS ===============
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $kelas_id = intval($_GET['id']);
    
    $sql = "SELECT * FROM kelas WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $kelas_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $kelas_edit = $row;
            
            // Hitung jumlah siswa aktif
            $siswa_count_sql = "SELECT COUNT(*) as total FROM kelas_siswa WHERE kelas_id = ? AND status = 'aktif'";
            $siswa_count_stmt = $conn->prepare($siswa_count_sql);
            if ($siswa_count_stmt) {
                $siswa_count_stmt->bind_param("i", $kelas_id);
                $siswa_count_stmt->execute();
                $siswa_count_result = $siswa_count_stmt->get_result();
                $kelas_edit['jumlah_siswa'] = $siswa_count_result->fetch_assoc()['total'];
                $siswa_count_stmt->close();
            }
        }
        $stmt->close();
    }
    
    // Load data siswa di kelas ini
    $siswa_kelas_sql = "SELECT ks.*, s.nis, s.nama_lengkap, s.jenis_kelamin, s.kelas as tingkat_sekolah
                       FROM kelas_siswa ks
                       JOIN siswa s ON ks.siswa_id = s.id
                       WHERE ks.kelas_id = ? AND ks.status = 'aktif'
                       ORDER BY s.nama_lengkap";
    
    $siswa_kelas_stmt = $conn->prepare($siswa_kelas_sql);
    if ($siswa_kelas_stmt) {
        $siswa_kelas_stmt->bind_param("i", $kelas_id);
        $siswa_kelas_stmt->execute();
        $siswa_kelas_result = $siswa_kelas_stmt->get_result();
        
        while ($siswa_row = $siswa_kelas_result->fetch_assoc()) {
            $siswa_kelas_data[] = $siswa_row;
        }
        $siswa_kelas_stmt->close();
    }
    
    // Load data guru pengajar di kelas ini
    $guru_kelas_sql = "SELECT kg.*, g.nip, u.full_name as nama_guru, 
                              g.bidang_keahlian, k.nama_kelas, k.kode_kelas
                       FROM kelas_guru kg
                       JOIN guru g ON kg.guru_id = g.id
                       JOIN users u ON g.user_id = u.id
                       JOIN kelas k ON kg.kelas_id = k.id
                       WHERE kg.kelas_id = ?
                       ORDER BY kg.hari_mengajar, kg.jam_mulai";
    
    $guru_kelas_stmt = $conn->prepare($guru_kelas_sql);
    if ($guru_kelas_stmt) {
        $guru_kelas_stmt->bind_param("i", $kelas_id);
        $guru_kelas_stmt->execute();
        $guru_kelas_result = $guru_kelas_stmt->get_result();
        
        while ($guru_row = $guru_kelas_result->fetch_assoc()) {
            $guru_kelas_data[] = $guru_row;
        }
        $guru_kelas_stmt->close();
    }
    
    // Load data untuk edit jadwal spesifik
    if (isset($_GET['edit_jadwal']) && isset($_GET['kg_id'])) {
        $kg_id = intval($_GET['kg_id']);
        
        $edit_jadwal_sql = "SELECT kg.*, g.nip, u.full_name as nama_guru, k.nama_kelas, k.kode_kelas
                            FROM kelas_guru kg
                            JOIN guru g ON kg.guru_id = g.id
                            JOIN users u ON g.user_id = u.id
                            JOIN kelas k ON kg.kelas_id = k.id
                            WHERE kg.id = ? AND kg.kelas_id = ?";
        
        $edit_jadwal_stmt = $conn->prepare($edit_jadwal_sql);
        if ($edit_jadwal_stmt) {
            $edit_jadwal_stmt->bind_param("ii", $kg_id, $kelas_id);
            $edit_jadwal_stmt->execute();
            $edit_result = $edit_jadwal_stmt->get_result();
            
            if ($edit_row = $edit_result->fetch_assoc()) {
                $jadwal_edit = $edit_row;
            }
            $edit_jadwal_stmt->close();
        }
    }
}

// =============== UPDATE KELAS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_kelas'])) {
    $kelas_id = intval($_POST['kelas_id']);
    $nama_kelas = trim($_POST['nama_kelas']);
    $tingkat = $_POST['tingkat'];
    $tahun_ajaran = trim($_POST['tahun_ajaran']);
    $kapasitas = intval($_POST['kapasitas']);
    $status = $_POST['status'];
    
    // Validasi
    if (empty($nama_kelas) || empty($tahun_ajaran) || $kapasitas <= 0) {
        $_SESSION['error_message'] = "❌ Data kelas tidak valid!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
    
    // Cek jika kapasitas kurang dari jumlah siswa saat ini
    $check_siswa_sql = "SELECT COUNT(*) as total FROM kelas_siswa WHERE kelas_id = ? AND status = 'aktif'";
    $check_siswa_stmt = $conn->prepare($check_siswa_sql);
    if ($check_siswa_stmt) {
        $check_siswa_stmt->bind_param("i", $kelas_id);
        $check_siswa_stmt->execute();
        $result = $check_siswa_stmt->get_result();
        $current_siswa = $result->fetch_assoc()['total'];
        $check_siswa_stmt->close();
    }
    
    if ($kapasitas < $current_siswa) {
        $_SESSION['error_message'] = "❌ Kapasitas tidak boleh kurang dari jumlah siswa saat ini ($current_siswa siswa)!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
    
    // Update data
    $update_sql = "UPDATE kelas SET 
                   nama_kelas = ?,
                   tingkat = ?,
                   tahun_ajaran = ?,
                   kapasitas = ?,
                   status = ?
                   WHERE id = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("sssisi", $nama_kelas, $tingkat, $tahun_ajaran, 
                                $kapasitas, $status, $kelas_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Data kelas berhasil diperbarui!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal memperbarui data kelas!";
        }
        
        header('Location: dataKelas.php?action=detail&id=' . $kelas_id);
        exit();
    }
}

// =============== FITUR BARU: EDIT JADWAL MENGAJAR DI KELAS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_jadwal_kelas'])) {
    $kelas_guru_id = intval($_POST['kelas_guru_id']);
    $kelas_id = intval($_POST['kelas_id']);
    $mata_pelajaran = trim($_POST['mata_pelajaran']);
    $hari_mengajar = $_POST['hari_mengajar'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    
    // Validasi input
    if (empty($mata_pelajaran) || empty($hari_mengajar) || empty($jam_mulai) || empty($jam_selesai)) {
        $_SESSION['error_message'] = "❌ Semua field jadwal harus diisi!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id . '&edit_jadwal=1&kg_id=' . $kelas_guru_id);
        exit();
    }
    
    // Validasi jam (jam selesai harus setelah jam mulai)
    if (strtotime($jam_mulai) >= strtotime($jam_selesai)) {
        $_SESSION['error_message'] = "❌ Jam selesai harus setelah jam mulai!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id . '&edit_jadwal=1&kg_id=' . $kelas_guru_id);
        exit();
    }
    
    // Update data jadwal
    $update_sql = "UPDATE kelas_guru SET 
                   mata_pelajaran = ?,
                   hari_mengajar = ?,
                   jam_mulai = ?,
                   jam_selesai = ?
                   WHERE id = ? AND kelas_id = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("ssssii", $mata_pelajaran, $hari_mengajar, 
                                $jam_mulai, $jam_selesai, $kelas_guru_id, $kelas_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Jadwal mengajar berhasil diperbarui!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal memperbarui jadwal mengajar!";
        }
        
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
}

// =============== TAMBAH KELAS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_kelas'])) {
    $nama_kelas = trim($_POST['nama_kelas']);
    $tingkat = $_POST['tingkat'];
    $tahun_ajaran = trim($_POST['tahun_ajaran']);
    $kapasitas = intval($_POST['kapasitas']);
    $status = $_POST['status'];
    
    // Validasi
    if (empty($nama_kelas) || empty($tahun_ajaran) || $kapasitas <= 0) {
        $_SESSION['error_message'] = "❌ Data kelas tidak valid!";
        header('Location: dataKelas.php?action=tambah');
        exit();
    }
    
    // Generate kode kelas
    $kode_kelas = generateKodeKelas($tingkat);
    
    // Insert data
    $insert_sql = "INSERT INTO kelas (kode_kelas, nama_kelas, tingkat, 
                   tahun_ajaran, kapasitas, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $insert_stmt = $conn->prepare($insert_sql);
    if ($insert_stmt) {
        $insert_stmt->bind_param("ssssis", $kode_kelas, $nama_kelas, $tingkat, 
                                $tahun_ajaran, $kapasitas, $status);
        
        if ($insert_stmt->execute()) {
            $kelas_id = $conn->insert_id;
            $_SESSION['success_message'] = "✅ Kelas baru berhasil ditambahkan!";
            header('Location: dataKelas.php?action=detail&id=' . $kelas_id);
            exit();
        } else {
            $_SESSION['error_message'] = "❌ Gagal menambahkan kelas baru!";
            header('Location: dataKelas.php?action=tambah');
            exit();
        }
    }
}

// =============== TAMBAH SISWA KE KELAS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_siswa_kelas'])) {
    $kelas_id = intval($_POST['kelas_id']);
    $siswa_id = intval($_POST['siswa_id']);
    $tanggal_gabung = $_POST['tanggal_gabung'];
    
    // Cek apakah siswa sudah di kelas ini
    $check_sql = "SELECT id FROM kelas_siswa WHERE kelas_id = ? AND siswa_id = ? AND status = 'aktif'";
    $check_stmt = $conn->prepare($check_sql);
    if ($check_stmt) {
        $check_stmt->bind_param("ii", $kelas_id, $siswa_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $_SESSION['error_message'] = "❌ Siswa sudah terdaftar di kelas ini!";
            header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
            exit();
        }
        $check_stmt->close();
    }
    
    // Cek kapasitas kelas
    $kapasitas_sql = "SELECT kapasitas, 
                      (SELECT COUNT(*) FROM kelas_siswa WHERE kelas_id = ? AND status = 'aktif') as terisi
                      FROM kelas WHERE id = ?";
    
    $kapasitas_stmt = $conn->prepare($kapasitas_sql);
    if ($kapasitas_stmt) {
        $kapasitas_stmt->bind_param("ii", $kelas_id, $kelas_id);
        $kapasitas_stmt->execute();
        $kapasitas_result = $kapasitas_stmt->get_result();
        $kapasitas_data = $kapasitas_result->fetch_assoc();
        $kapasitas_stmt->close();
    }
    
    if ($kapasitas_data['terisi'] >= $kapasitas_data['kapasitas']) {
        $_SESSION['error_message'] = "❌ Kelas sudah penuh! Kapasitas: " . $kapasitas_data['kapasitas'];
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
    
    // Insert siswa ke kelas
    $insert_sql = "INSERT INTO kelas_siswa (kelas_id, siswa_id, tanggal_gabung, status)
                   VALUES (?, ?, ?, 'aktif')";
    
    $insert_stmt = $conn->prepare($insert_sql);
    if ($insert_stmt) {
        $insert_stmt->bind_param("iis", $kelas_id, $siswa_id, $tanggal_gabung);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Siswa berhasil ditambahkan ke kelas!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal menambahkan siswa ke kelas!";
        }
        
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
}

// =============== KELUARKAN SISWA DARI KELAS ===============
if (isset($_GET['action']) && $_GET['action'] == 'keluarkan_siswa' && isset($_GET['ks_id'])) {
    $kelas_siswa_id = intval($_GET['ks_id']);
    $kelas_id = intval($_GET['kelas_id']);
    
    // Update status siswa di kelas
    $update_sql = "UPDATE kelas_siswa SET status = 'keluar' WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param("i", $kelas_siswa_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Siswa berhasil dikeluarkan dari kelas!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal mengeluarkan siswa dari kelas!";
        }
        
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
}

// =============== FITUR BARU: TAMBAH GURU KE KELAS ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_guru_kelas'])) {
    $kelas_id = intval($_POST['kelas_id']);
    $guru_id = intval($_POST['guru_id']);
    $mata_pelajaran = trim($_POST['mata_pelajaran']);
    $hari_mengajar = $_POST['hari_mengajar'];
    $jam_mulai = $_POST['jam_mulai'];
    $jam_selesai = $_POST['jam_selesai'];
    
    // Validasi
    if (empty($mata_pelajaran) || empty($hari_mengajar) || empty($jam_mulai) || empty($jam_selesai)) {
        $_SESSION['error_message'] = "❌ Semua field jadwal harus diisi!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
    
    // Validasi jam
    if (strtotime($jam_mulai) >= strtotime($jam_selesai)) {
        $_SESSION['error_message'] = "❌ Jam selesai harus setelah jam mulai!";
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
    
    // Cek apakah guru sudah mengajar di kelas ini
    $check_sql = "SELECT id FROM kelas_guru WHERE kelas_id = ? AND guru_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    if ($check_stmt) {
        $check_stmt->bind_param("ii", $kelas_id, $guru_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $_SESSION['error_message'] = "❌ Guru sudah mengajar di kelas ini!";
            header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
            exit();
        }
        $check_stmt->close();
    }
    
    // Validasi: cek apakah ada jadwal bentrok di hari yang sama
    $check_jadwal_sql = "SELECT k.nama_kelas 
                         FROM kelas_guru kg 
                         JOIN kelas k ON kg.kelas_id = k.id
                         WHERE kg.guru_id = ? 
                         AND kg.hari_mengajar = ? 
                         AND kg.kelas_id != ?
                         AND ((kg.jam_mulai <= ? AND kg.jam_selesai >= ?) 
                              OR (kg.jam_mulai <= ? AND kg.jam_selesai >= ?))";
    
    $check_jadwal_stmt = $conn->prepare($check_jadwal_sql);
    if ($check_jadwal_stmt) {
        $check_jadwal_stmt->bind_param("isssss", $guru_id, $hari_mengajar, $kelas_id,
                                       $jam_mulai, $jam_mulai, $jam_selesai, $jam_selesai);
        $check_jadwal_stmt->execute();
        $jadwal_result = $check_jadwal_stmt->get_result();
        
        if ($jadwal_result->num_rows > 0) {
            $jadwal_bentrok = $jadwal_result->fetch_assoc();
            $_SESSION['error_message'] = "❌ Jadwal bentrok dengan kelas lain di hari {$hari_mengajar}!";
            header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
            exit();
        }
        $check_jadwal_stmt->close();
    }
    
    // Insert guru ke kelas
    $insert_sql = "INSERT INTO kelas_guru (kelas_id, guru_id, mata_pelajaran, 
                   hari_mengajar, jam_mulai, jam_selesai)
                   VALUES (?, ?, ?, ?, ?, ?)";
    
    $insert_stmt = $conn->prepare($insert_sql);
    if ($insert_stmt) {
        $insert_stmt->bind_param("iissss", $kelas_id, $guru_id, $mata_pelajaran, 
                                $hari_mengajar, $jam_mulai, $jam_selesai);
        
        if ($insert_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Guru berhasil ditambahkan ke kelas!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal menambahkan guru ke kelas!";
        }
        
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
}

// =============== FITUR BARU: HAPUS GURU DARI KELAS ===============
if (isset($_GET['action']) && $_GET['action'] == 'hapus_guru_kelas' && isset($_GET['kg_id'])) {
    $kelas_guru_id = intval($_GET['kg_id']);
    $kelas_id = intval($_GET['kelas_id']);
    
    // Hapus guru dari kelas
    $delete_sql = "DELETE FROM kelas_guru WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    if ($delete_stmt) {
        $delete_stmt->bind_param("i", $kelas_guru_id);
        
        if ($delete_stmt->execute()) {
            $_SESSION['success_message'] = "✅ Guru berhasil dihapus dari kelas!";
        } else {
            $_SESSION['error_message'] = "❌ Gagal menghapus guru dari kelas!";
        }
        
        header('Location: dataKelas.php?action=edit&id=' . $kelas_id);
        exit();
    }
}

// =============== HAPUS KELAS ===============
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $kelas_id = intval($_GET['id']);
    
    // Cek apakah kelas memiliki siswa aktif
    $check_siswa_sql = "SELECT COUNT(*) as total FROM kelas_siswa WHERE kelas_id = ? AND status = 'aktif'";
    $check_siswa_stmt = $conn->prepare($check_siswa_sql);
    if ($check_siswa_stmt) {
        $check_siswa_stmt->bind_param("i", $kelas_id);
        $check_siswa_stmt->execute();
        $siswa_result = $check_siswa_stmt->get_result();
        $has_siswa = $siswa_result->fetch_assoc()['total'] > 0;
        $check_siswa_stmt->close();
    }
    
    if ($has_siswa) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus kelas yang masih memiliki siswa aktif!";
        header('Location: dataKelas.php');
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        // Hapus relasi guru
        $sql1 = "DELETE FROM kelas_guru WHERE kelas_id = ?";
        $stmt1 = $conn->prepare($sql1);
        if ($stmt1) {
            $stmt1->bind_param("i", $kelas_id);
            $stmt1->execute();
            $stmt1->close();
        }
        
        // Hapus data penilaian yang terkait
        $sql2 = "DELETE FROM penilaian_siswa WHERE kelas_id = ?";
        $stmt2 = $conn->prepare($sql2);
        if ($stmt2) {
            $stmt2->bind_param("i", $kelas_id);
            $stmt2->execute();
            $stmt2->close();
        }
        
        // Hapus data siswa yang sudah keluar (status keluar)
        $sql3 = "DELETE FROM kelas_siswa WHERE kelas_id = ?";
        $stmt3 = $conn->prepare($sql3);
        if ($stmt3) {
            $stmt3->bind_param("i", $kelas_id);
            $stmt3->execute();
            $stmt3->close();
        }
        
        // Hapus kelas
        $sql4 = "DELETE FROM kelas WHERE id = ?";
        $stmt4 = $conn->prepare($sql4);
        if ($stmt4) {
            $stmt4->bind_param("i", $kelas_id);
            $stmt4->execute();
            $stmt4->close();
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "✅ Kelas berhasil dihapus!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal menghapus kelas: " . $e->getMessage();
    }
    
    header('Location: dataKelas.php');
    exit();
}

// =============== AMBIL DATA KELAS DENGAN FILTER ===============
$sql = "SELECT k.*, 
               (SELECT COUNT(*) FROM kelas_siswa ks WHERE ks.kelas_id = k.id AND ks.status = 'aktif') as jumlah_siswa,
               (SELECT COUNT(*) FROM kelas_guru kg WHERE kg.kelas_id = k.id) as jumlah_guru
        FROM kelas k
        WHERE 1=1";

$params = [];
$param_types = "";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(k.nama_kelas LIKE ? OR k.kode_kelas LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

if (!empty($filter_tingkat)) {
    $conditions[] = "k.tingkat = ?";
    $params[] = $filter_tingkat;
    $param_types .= "s";
}

if (!empty($filter_status)) {
    $conditions[] = "k.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

if (!empty($tahun_ajaran)) {
    $conditions[] = "k.tahun_ajaran = ?";
    $params[] = $tahun_ajaran;
    $param_types .= "s";
}

if (count($conditions) > 0) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY k.tingkat, k.nama_kelas";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['kapasitas_persen'] = ($row['jumlah_siswa'] / $row['kapasitas']) * 100;
        $kelas_data[] = $row;
    }
    $stmt->close();
} else {
    // Debug: tampilkan error
    error_log("Error preparing SQL: " . $conn->error);
}

// Hitung statistik
$stats_sql = "SELECT 
              COUNT(*) as total_kelas,
              SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
              SUM(CASE WHEN status = 'non-aktif' THEN 1 ELSE 0 END) as non_aktif,
              SUM(CASE WHEN tingkat = 'SD' THEN 1 ELSE 0 END) as sd,
              SUM(CASE WHEN tingkat = 'SMP' THEN 1 ELSE 0 END) as smp,
              SUM(CASE WHEN tingkat = 'SMA' THEN 1 ELSE 0 END) as sma,
              (SELECT COUNT(DISTINCT ks.siswa_id) FROM kelas_siswa ks WHERE ks.status = 'aktif') as total_siswa,
              (SELECT COUNT(*) FROM kelas_guru) as total_guru_kelas
              FROM kelas";
$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $statistik = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

// Daftar tahun ajaran untuk filter
$tahun_ajaran_list = getTahunAjaranList();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Kelas - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .modal-header.purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
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
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
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

        /* ===== STYLE UNTUK MENU DINAMIS ===== */
        /* Dropdown styles untuk menu dinamis */
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
            transform: rotate(180deg);
        }
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }
        
        /* Sidebar menu hover */
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Logout button khusus */
        .logout-btn {
            margin-top: 2rem !important;
            color: #fca5a5 !important;
        }
        
        .logout-btn:hover {
            background-color: rgba(254, 226, 226, 0.9) !important;
            color: #b91c1b !important;
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
            
            .grid-2, .grid-3 {
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

        /* ===== STYLE UNTUK DATA TABEL ===== */
        /* Table styles */
        .data-table {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .data-table th {
            background-color: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background-color: #f9fafb;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Progress bar */
        .progress-bar {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background-color: #10b981;
            transition: width 0.3s ease;
        }

        /* Tab styles for detail modal */
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }
        .tab-button {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .tab-button:hover {
            color: #374151;
        }
        .tab-button.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 640px) {
            .tab-buttons {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .tab-button {
                padding: 10px 16px;
                font-size: 14px;
            }
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
                        <i class="fas fa-school mr-2"></i> Data Kelas
                    </h1>
                    <p class="text-gray-600">Kelola data kelas bimbingan belajar</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Statistik -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-school text-blue-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Kelas</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['total_kelas']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-green-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Siswa</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['total_siswa']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-yellow-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Guru</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['total_guru_kelas']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-bar text-purple-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Kelas Aktif</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['aktif']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

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

            <!-- Filter Section -->
            <?php if (!isset($kelas_detail) && !isset($kelas_edit) && !isset($_GET['action'])): ?>
                <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                    <div class="px-4 py-5 sm:p-6">
                        <form method="GET" action="dataKelas.php" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <!-- Search -->
                                <div>
                                    <label for="search" class="block text-sm font-medium text-gray-700">Pencarian</label>
                                    <div class="mt-1 relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <i class="fas fa-search text-gray-400"></i>
                                        </div>
                                        <input type="text" name="search" id="search" 
                                               class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md" 
                                               placeholder="Nama atau kode kelas..."
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Filter Tingkat -->
                                <div>
                                    <label for="filter_tingkat" class="block text-sm font-medium text-gray-700">Jenjang</label>
                                    <select id="filter_tingkat" name="filter_tingkat" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Jenjang</option>
                                        <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD</option>
                                        <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                        <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                    </select>
                                </div>

                                <!-- Filter Tahun Ajaran -->
                                <div>
                                    <label for="tahun_ajaran" class="block text-sm font-medium text-gray-700">Tahun Ajaran</label>
                                    <select id="tahun_ajaran" name="tahun_ajaran" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Tahun</option>
                                        <?php foreach ($tahun_ajaran_list as $tahun): ?>
                                            <option value="<?php echo $tahun; ?>" <?php echo $tahun_ajaran == $tahun ? 'selected' : ''; ?>>
                                                <?php echo $tahun; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
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
                                
                                <?php if (!empty($search) || !empty($filter_tingkat) || !empty($tahun_ajaran) || !empty($filter_status)): ?>
                                    <a href="dataKelas.php" 
                                       class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Reset Filter
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tabel Kelas -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <?php if (count($kelas_data) > 0): ?>
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kelas
                                    </th>
                                    <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Siswa
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($kelas_data as $index => $kelas): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8">
                                                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-school text-blue-600 text-sm"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-2">
                                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                        <?php echo htmlspecialchars($kelas['nama_kelas']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500 truncate max-w-[120px]">
                                                        <?php echo $kelas['tingkat']; ?> | <?php echo htmlspecialchars($kelas['kode_kelas']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo htmlspecialchars($kelas['tahun_ajaran']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo $kelas['jumlah_siswa']; ?>/<?php echo $kelas['kapasitas']; ?>
                                            </div>
                                            <div class="progress-bar mt-1">
                                                <div class="progress-fill" style="width: <?php echo min($kelas['kapasitas_persen'], 100); ?>%"></div>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">
                                                <?php echo number_format($kelas['kapasitas_persen'], 1); ?>%
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php echo $kelas['status'] == 'aktif' 
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst($kelas['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="?action=detail&id=<?php echo $kelas['id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900 p-1" 
                                                   title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $kelas['id']; ?>" 
                                                   class="text-yellow-600 hover:text-yellow-900 p-1" 
                                                   title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="#" 
                                                   onclick="confirmDelete(<?php echo $kelas['id']; ?>, '<?php echo htmlspecialchars(addslashes($kelas['nama_kelas'])); ?>')"
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
                        <i class="fas fa-school text-gray-300 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">
                            Data kelas tidak ditemukan
                        </h3>
                        <p class="text-gray-500 mb-4">
                            <?php if (!empty($search) || !empty($filter_tingkat) || !empty($tahun_ajaran) || !empty($filter_status)): ?>
                                Coba ubah filter pencarian atau
                            <?php endif; ?>
                            Tambahkan kelas baru untuk memulai.
                        </p>
                        <a href="?action=tambah" 
                           class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            <i class="fas fa-plus-circle mr-2"></i> Tambah Kelas
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Kelas</p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Kelas -->
    <?php if ($kelas_detail): ?>
        <div id="detailModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-school mr-2"></i> Detail Kelas
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Header Info -->
                    <div class="mb-6">
                        <div class="flex items-center mb-4">
                            <div class="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-school text-blue-600 text-2xl"></i>
                            </div>
                            <div class="ml-4 flex-1">
                                <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($kelas_detail['nama_kelas']); ?></h3>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <span class="text-sm text-gray-600">
                                        <i class="fas fa-hashtag mr-1"></i><?php echo htmlspecialchars($kelas_detail['kode_kelas']); ?>
                                    </span>
                                    <span class="text-sm text-gray-600">
                                        <i class="fas fa-graduation-cap mr-1"></i><?php echo $kelas_detail['tingkat']; ?>
                                    </span>
                                    <span class="text-sm text-gray-600">
                                        <i class="fas fa-calendar-alt mr-1"></i><?php echo htmlspecialchars($kelas_detail['tahun_ajaran']); ?>
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span class="px-3 py-1 text-sm font-semibold rounded-full 
                                    <?php echo $kelas_detail['status'] == 'aktif' 
                                        ? 'bg-green-100 text-green-800' 
                                        : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst($kelas_detail['status']); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Tabs Navigation -->
                        <div class="tab-buttons">
                            <button class="tab-button <?php echo $active_tab == 'info' ? 'active' : ''; ?>" 
                                    onclick="switchTab('info')">
                                <i class="fas fa-info-circle mr-2"></i>Info
                            </button>
                            <button class="tab-button <?php echo $active_tab == 'siswa' ? 'active' : ''; ?>" 
                                    onclick="switchTab('siswa')">
                                <i class="fas fa-users mr-2"></i>Siswa (<?php echo $kelas_detail['jumlah_siswa']; ?>)
                            </button>
                            <button class="tab-button <?php echo $active_tab == 'guru' ? 'active' : ''; ?>" 
                                    onclick="switchTab('guru')">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>Guru (<?php echo count($kelas_detail['guru']); ?>)
                            </button>
                            <button class="tab-button <?php echo $active_tab == 'statistik' ? 'active' : ''; ?>" 
                                    onclick="switchTab('statistik')">
                                <i class="fas fa-chart-bar mr-2"></i>Statistik
                            </button>
                        </div>

                        <!-- Tab Content: Informasi -->
                        <div id="tab-info" class="tab-content <?php echo $active_tab == 'info' ? 'active' : ''; ?>">
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label">Kode Kelas</label>
                                    <div class="p-2 bg-gray-50 rounded font-mono text-sm"><?php echo htmlspecialchars($kelas_detail['kode_kelas']); ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nama Kelas</label>
                                    <div class="p-2 bg-gray-50 rounded"><?php echo htmlspecialchars($kelas_detail['nama_kelas']); ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Jenjang</label>
                                    <div class="p-2 bg-gray-50 rounded"><?php echo $kelas_detail['tingkat']; ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tahun Ajaran</label>
                                    <div class="p-2 bg-gray-50 rounded"><?php echo htmlspecialchars($kelas_detail['tahun_ajaran']); ?></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Kapasitas</label>
                                    <div class="p-2 bg-gray-50 rounded"><?php echo $kelas_detail['kapasitas']; ?> siswa</div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="p-2 bg-gray-50 rounded">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            <?php echo $kelas_detail['status'] == 'aktif' 
                                                ? 'bg-green-100 text-green-800' 
                                                : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($kelas_detail['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tanggal Dibuat</label>
                                    <div class="p-2 bg-gray-50 rounded text-sm">
                                        <?php echo date('d/m/Y H:i', strtotime($kelas_detail['created_at'])); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Kapasitas Terisi</label>
                                    <div class="p-2 bg-gray-50 rounded">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-sm"><?php echo $kelas_detail['jumlah_siswa']; ?>/<?php echo $kelas_detail['kapasitas']; ?></span>
                                            <span class="text-sm"><?php echo number_format($kelas_detail['kapasitas_persen'], 1); ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min($kelas_detail['kapasitas_persen'], 100); ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Content: Siswa -->
                        <div id="tab-siswa" class="tab-content <?php echo $active_tab == 'siswa' ? 'active' : ''; ?>">
                            <?php if (!empty($kelas_detail['siswa'])): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($kelas_detail['siswa'] as $index => $siswa): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm"><?php echo $index + 1; ?></td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($siswa['nama_lengkap']); ?></div>
                                                        <div class="text-xs text-gray-500">NIS: <?php echo htmlspecialchars($siswa['nis']); ?></div>
                                                        <div class="text-xs text-gray-500"><?php echo $siswa['jenis_kelamin']; ?> | <?php echo $siswa['tingkat_sekolah']; ?></div>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <?php echo date('d/m/Y', strtotime($siswa['tanggal_gabung'])); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                            <?php echo $siswa['status'] == 'aktif' 
                                                                ? 'bg-green-100 text-green-800' 
                                                                : 'bg-red-100 text-red-800'; ?>">
                                                            <?php echo ucfirst($siswa['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-data text-center py-8">
                                    <i class="fas fa-users text-gray-300 text-4xl mb-3"></i>
                                    <p class="text-gray-600">Belum ada siswa di kelas ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tab Content: Guru -->
                        <div id="tab-guru" class="tab-content <?php echo $active_tab == 'guru' ? 'active' : ''; ?>">
                            <?php if (!empty($kelas_detail['guru'])): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Guru</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mata Pelajaran</th>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jadwal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach ($kelas_detail['guru'] as $index => $guru): ?>
                                                <tr>
                                                    <td class="px-4 py-3 text-sm"><?php echo $index + 1; ?></td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($guru['nama_guru']); ?></div>
                                                        <div class="text-xs text-gray-500">NIP: <?php echo htmlspecialchars($guru['nip']); ?></div>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm"><?php echo htmlspecialchars($guru['mata_pelajaran']); ?></td>
                                                    <td class="px-4 py-3 text-sm">
                                                        <div class="text-xs"><?php echo htmlspecialchars($guru['hari_mengajar']); ?></div>
                                                        <div class="text-xs">
                                                            <?php echo date('H:i', strtotime($guru['jam_mulai'])); ?> - 
                                                            <?php echo date('H:i', strtotime($guru['jam_selesai'])); ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="no-data text-center py-8">
                                    <i class="fas fa-chalkboard-teacher text-gray-300 text-4xl mb-3"></i>
                                    <p class="text-gray-600">Belum ada guru pengajar di kelas ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tab Content: Statistik -->
                        <div id="tab-statistik" class="tab-content <?php echo $active_tab == 'statistik' ? 'active' : ''; ?>">
                            <div class="grid-2 mb-6">
                                <div class="form-group">
                                    <label class="form-label">Siswa Terdaftar</label>
                                    <div class="p-3 bg-blue-50 rounded-lg">
                                        <div class="text-2xl font-bold text-blue-700"><?php echo $kelas_detail['jumlah_siswa']; ?></div>
                                        <p class="text-sm text-blue-600">dari <?php echo $kelas_detail['kapasitas']; ?> kapasitas</p>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Guru Pengajar</label>
                                    <div class="p-3 bg-green-50 rounded-lg">
                                        <div class="text-2xl font-bold text-green-700"><?php echo count($kelas_detail['guru']); ?></div>
                                        <p class="text-sm text-green-600">orang</p>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Rata-rata Nilai</label>
                                    <div class="p-3 bg-yellow-50 rounded-lg">
                                        <div class="text-2xl font-bold text-yellow-700">
                                            <?php echo $kelas_detail['statistik']['rata_nilai'] ? number_format($kelas_detail['statistik']['rata_nilai'], 1) : '-'; ?>
                                        </div>
                                        <p class="text-sm text-yellow-600">
                                            <?php echo $kelas_detail['statistik']['siswa_dinilai'] ? $kelas_detail['statistik']['siswa_dinilai'] . ' siswa' : 'Belum ada nilai'; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Status Kelas</label>
                                    <div class="p-3 bg-purple-50 rounded-lg">
                                        <div class="text-xl font-bold text-purple-700">
                                            <span class="px-2 py-1 text-sm font-semibold rounded-full 
                                                <?php echo $kelas_detail['status'] == 'aktif' 
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst($kelas_detail['status']); ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-purple-600">
                                            <?php echo number_format($kelas_detail['kapasitas_persen'], 1); ?>% terisi
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeModal()" 
                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            Tutup
                        </button>
                        <a href="?action=edit&id=<?php echo $kelas_detail['id']; ?>" 
                           class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700">
                            <i class="fas fa-edit mr-2"></i> Edit Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Edit Kelas -->
    <?php if ($kelas_edit): ?>
        <div id="editModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header yellow">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-edit mr-2"></i> Edit Data Kelas
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Form Edit Data Kelas -->
                    <form method="POST" action="dataKelas.php" class="mb-8">
                        <input type="hidden" name="update_kelas" value="1">
                        <input type="hidden" name="kelas_id" value="<?php echo $kelas_edit['id']; ?>">
                        
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informasi Kelas</h3>
                            <div class="grid-2">
                                <div class="form-group">
                                    <label class="form-label" for="nama_kelas">Nama Kelas *</label>
                                    <input type="text" id="nama_kelas" name="nama_kelas" 
                                           class="form-input" 
                                           value="<?php echo htmlspecialchars($kelas_edit['nama_kelas']); ?>" 
                                           required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="tingkat">Jenjang *</label>
                                    <select id="tingkat" name="tingkat" class="form-input" required>
                                        <option value="SD" <?php echo $kelas_edit['tingkat'] == 'SD' ? 'selected' : ''; ?>>SD</option>
                                        <option value="SMP" <?php echo $kelas_edit['tingkat'] == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                        <option value="SMA" <?php echo $kelas_edit['tingkat'] == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="tahun_ajaran">Tahun Ajaran *</label>
                                    <input type="text" id="tahun_ajaran" name="tahun_ajaran" 
                                           class="form-input" 
                                           placeholder="Contoh: 2025/2026"
                                           value="<?php echo htmlspecialchars($kelas_edit['tahun_ajaran']); ?>" 
                                           required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="kapasitas">Kapasitas *</label>
                                    <input type="number" id="kapasitas" name="kapasitas" 
                                           class="form-input" 
                                           value="<?php echo $kelas_edit['kapasitas']; ?>" 
                                           min="<?php echo $kelas_edit['jumlah_siswa']; ?>" max="50" required>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Minimal <?php echo $kelas_edit['jumlah_siswa']; ?> siswa (jumlah siswa saat ini)
                                    </p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="status">Status</label>
                                    <select id="status" name="status" class="form-input" required>
                                        <option value="aktif" <?php echo $kelas_edit['status'] == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="non-aktif" <?php echo $kelas_edit['status'] == 'non-aktif' ? 'selected' : ''; ?>>Non-Aktif</option>
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
                                <a href="?action=detail&id=<?php echo $kelas_edit['id']; ?>" 
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

                    <!-- Kelola Siswa di Kelas -->
                    <div class="border-t pt-6 mb-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                                <i class="fas fa-users mr-2 text-green-600"></i> Data Siswa di Kelas (<?php echo $kelas_edit['jumlah_siswa']; ?>)
                            </h3>
                            <?php if (count($siswa_options) > 0): ?>
                                <button onclick="toggleTambahSiswa()" 
                                        class="px-3 py-1.5 text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                                    <i class="fas fa-plus mr-1"></i> Tambah Siswa
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Form Tambah Siswa -->
                        <?php if (count($siswa_options) > 0): ?>
                            <div id="formTambahSiswa" class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6" style="display: none;">
                                <h4 class="font-medium text-gray-900 mb-3">Tambah Siswa ke Kelas</h4>
                                <form method="POST" action="dataKelas.php">
                                    <input type="hidden" name="tambah_siswa_kelas" value="1">
                                    <input type="hidden" name="kelas_id" value="<?php echo $kelas_edit['id']; ?>">
                                    
                                    <div class="grid-2">
                                        <div class="form-group">
                                            <label class="form-label" for="siswa_id">Pilih Siswa *</label>
                                            <select id="siswa_id" name="siswa_id" class="form-input" required>
                                                <option value="">Pilih Siswa</option>
                                                <?php foreach ($siswa_options as $siswa): ?>
                                                    <option value="<?php echo $siswa['id']; ?>">
                                                        <?php echo htmlspecialchars($siswa['nis']); ?> - 
                                                        <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="tanggal_gabung">Tanggal Gabung *</label>
                                            <input type="date" id="tanggal_gabung" name="tanggal_gabung" 
                                                   class="form-input" 
                                                   value="<?php echo date('Y-m-d'); ?>"
                                                   required>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-end space-x-3 mt-4">
                                        <button type="button" onclick="toggleTambahSiswa()" 
                                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Batal
                                        </button>
                                        <button type="submit" 
                                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                                            <i class="fas fa-save mr-2"></i> Tambah Siswa
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Daftar Siswa -->
                        <?php if (!empty($siswa_kelas_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Siswa</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($siswa_kelas_data as $index => $siswa): ?>
                                            <tr>
                                                <td class="px-4 py-3 text-sm"><?php echo $index + 1; ?></td>
                                                <td class="px-4 py-3 text-sm">
                                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($siswa['nama_lengkap']); ?></div>
                                                    <div class="text-xs text-gray-500">NIS: <?php echo htmlspecialchars($siswa['nis']); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo $siswa['jenis_kelamin']; ?> | <?php echo $siswa['tingkat_sekolah']; ?></div>
                                                </td>
                                                <td class="px-4 py-3 text-sm">
                                                    <?php echo date('d/m/Y', strtotime($siswa['tanggal_gabung'])); ?>
                                                </td>
                                                <td class="px-4 py-3 text-sm">
                                                    <a href="?action=keluarkan_siswa&ks_id=<?php echo $siswa['id']; ?>&kelas_id=<?php echo $kelas_edit['id']; ?>" 
                                                       onclick="return confirm('Keluarkan siswa <?php echo htmlspecialchars(addslashes($siswa['nama_lengkap'])); ?> dari kelas?')"
                                                       class="text-red-600 hover:text-red-900 p-1" 
                                                       title="Keluarkan">
                                                        <i class="fas fa-user-minus"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data text-center py-8">
                                <i class="fas fa-users text-gray-300 text-4xl mb-3"></i>
                                <p class="text-gray-600">Belum ada siswa di kelas ini.</p>
                                <?php if (count($siswa_options) > 0): ?>
                                    <p class="text-sm text-gray-500 mt-2">Klik "Tambah Siswa" untuk menambahkan siswa ke kelas ini.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Kelola Guru di Kelas -->
                    <div class="border-t pt-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                                <i class="fas fa-chalkboard-teacher mr-2 text-purple-600"></i> Jadwal Guru Pengajar (<?php echo count($guru_kelas_data); ?>)
                            </h3>
                            <?php if (count($guru_options) > 0): ?>
                                <button onclick="toggleTambahGuru()" 
                                        class="px-3 py-1.5 text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                    <i class="fas fa-plus mr-1"></i> Tambah Guru
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Form Tambah Guru -->
                        <?php if (count($guru_options) > 0): ?>
                            <div id="formTambahGuru" class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-6" style="display: none;">
                                <h4 class="font-medium text-gray-900 mb-3">Tambah Guru Pengajar</h4>
                                <form method="POST" action="dataKelas.php">
                                    <input type="hidden" name="tambah_guru_kelas" value="1">
                                    <input type="hidden" name="kelas_id" value="<?php echo $kelas_edit['id']; ?>">
                                    
                                    <div class="grid-3">
                                        <div class="form-group">
                                            <label class="form-label" for="guru_id">Pilih Guru *</label>
                                            <select id="guru_id" name="guru_id" class="form-input" required>
                                                <option value="">Pilih Guru</option>
                                                <?php foreach ($guru_options as $guru): ?>
                                                    <option value="<?php echo $guru['id']; ?>">
                                                        <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="mata_pelajaran">Mata Pelajaran *</label>
                                            <input type="text" id="mata_pelajaran" name="mata_pelajaran" 
                                                   class="form-input" 
                                                   placeholder="Contoh: Matematika"
                                                   required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="hari_mengajar">Hari Mengajar *</label>
                                            <select id="hari_mengajar" name="hari_mengajar" class="form-input" required>
                                                <option value="">Pilih Hari</option>
                                                <option value="Senin">Senin</option>
                                                <option value="Selasa">Selasa</option>
                                                <option value="Rabu">Rabu</option>
                                                <option value="Kamis">Kamis</option>
                                                <option value="Jumat">Jumat</option>
                                                <option value="Sabtu">Sabtu</option>
                                                <option value="Minggu">Minggu</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="jam_mulai">Jam Mulai *</label>
                                            <input type="time" id="jam_mulai" name="jam_mulai" 
                                                   class="form-input" 
                                                   required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="jam_selesai">Jam Selesai *</label>
                                            <input type="time" id="jam_selesai" name="jam_selesai" 
                                                   class="form-input" 
                                                   required>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-end space-x-3 mt-4">
                                        <button type="button" onclick="toggleTambahGuru()" 
                                                class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Batal
                                        </button>
                                        <button type="submit" 
                                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                            <i class="fas fa-save mr-2"></i> Tambah Guru
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Form Edit Jadwal Spesifik -->
                        <?php if ($jadwal_edit): ?>
                            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-6">
                                <h4 class="font-medium text-gray-900 mb-3">Edit Jadwal Mengajar</h4>
                                <form method="POST" action="dataKelas.php">
                                    <input type="hidden" name="edit_jadwal_kelas" value="1">
                                    <input type="hidden" name="kelas_guru_id" value="<?php echo $jadwal_edit['id']; ?>">
                                    <input type="hidden" name="kelas_id" value="<?php echo $kelas_edit['id']; ?>">
                                    
                                    <div class="mb-4">
                                        <p class="text-gray-700">
                                            <i class="fas fa-chalkboard-teacher text-purple-500 mr-2"></i>
                                            <span class="font-medium"><?php echo htmlspecialchars($jadwal_edit['nama_guru']); ?></span>
                                            (NIP: <?php echo htmlspecialchars($jadwal_edit['nip']); ?>)
                                        </p>
                                    </div>
                                    
                                    <div class="grid-3">
                                        <div class="form-group">
                                            <label class="form-label" for="edit_mata_pelajaran">Mata Pelajaran *</label>
                                            <input type="text" id="edit_mata_pelajaran" name="mata_pelajaran" 
                                                   class="form-input" 
                                                   value="<?php echo htmlspecialchars($jadwal_edit['mata_pelajaran']); ?>"
                                                   required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="edit_hari_mengajar">Hari Mengajar *</label>
                                            <select id="edit_hari_mengajar" name="hari_mengajar" class="form-input" required>
                                                <option value="">Pilih Hari</option>
                                                <option value="Senin" <?php echo $jadwal_edit['hari_mengajar'] == 'Senin' ? 'selected' : ''; ?>>Senin</option>
                                                <option value="Selasa" <?php echo $jadwal_edit['hari_mengajar'] == 'Selasa' ? 'selected' : ''; ?>>Selasa</option>
                                                <option value="Rabu" <?php echo $jadwal_edit['hari_mengajar'] == 'Rabu' ? 'selected' : ''; ?>>Rabu</option>
                                                <option value="Kamis" <?php echo $jadwal_edit['hari_mengajar'] == 'Kamis' ? 'selected' : ''; ?>>Kamis</option>
                                                <option value="Jumat" <?php echo $jadwal_edit['hari_mengajar'] == 'Jumat' ? 'selected' : ''; ?>>Jumat</option>
                                                <option value="Sabtu" <?php echo $jadwal_edit['hari_mengajar'] == 'Sabtu' ? 'selected' : ''; ?>>Sabtu</option>
                                                <option value="Minggu" <?php echo $jadwal_edit['hari_mengajar'] == 'Minggu' ? 'selected' : ''; ?>>Minggu</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="edit_jam_mulai">Jam Mulai *</label>
                                            <input type="time" id="edit_jam_mulai" name="jam_mulai" 
                                                   class="form-input" 
                                                   value="<?php echo $jadwal_edit['jam_mulai'] ? date('H:i', strtotime($jadwal_edit['jam_mulai'])) : ''; ?>"
                                                   required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="edit_jam_selesai">Jam Selesai *</label>
                                            <input type="time" id="edit_jam_selesai" name="jam_selesai" 
                                                   class="form-input" 
                                                   value="<?php echo $jadwal_edit['jam_selesai'] ? date('H:i', strtotime($jadwal_edit['jam_selesai'])) : ''; ?>"
                                                   required>
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-end space-x-3 mt-4">
                                        <a href="?action=edit&id=<?php echo $kelas_edit['id']; ?>" 
                                           class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                            Batal Edit
                                        </a>
                                        <button type="submit" 
                                                class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700">
                                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Daftar Guru Pengajar -->
                        <?php if (!empty($guru_kelas_data)): ?>
                            <div class="space-y-3">
                                <?php foreach ($guru_kelas_data as $jadwal): ?>
                                    <div class="jadwal-item bg-gray-50 border border-gray-200 rounded-lg p-4">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-1">
                                                <div class="flex items-center mb-2">
                                                    <i class="fas fa-chalkboard-teacher text-purple-500 mr-2"></i>
                                                    <span class="font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($jadwal['nama_guru']); ?>
                                                    </span>
                                                </div>
                                                
                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                    <div>
                                                        <span class="block text-sm font-medium text-gray-700">Mata Pelajaran:</span>
                                                        <p class="text-gray-800"><?php echo htmlspecialchars($jadwal['mata_pelajaran']); ?></p>
                                                    </div>
                                                    <div>
                                                        <span class="block text-sm font-medium text-gray-700">Hari:</span>
                                                        <p class="text-gray-800">
                                                            <?php echo $jadwal['hari_mengajar'] ? htmlspecialchars($jadwal['hari_mengajar']) : '<span class="text-gray-400 italic">Belum diatur</span>'; ?>
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span class="block text-sm font-medium text-gray-700">Jam:</span>
                                                        <p class="text-gray-800">
                                                            <?php echo $jadwal['jam_mulai'] ? date('H:i', strtotime($jadwal['jam_mulai'])) : '-'; ?> 
                                                            - 
                                                            <?php echo $jadwal['jam_selesai'] ? date('H:i', strtotime($jadwal['jam_selesai'])) : '-'; ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="flex gap-2">
                                                <a href="?action=edit&id=<?php echo $kelas_edit['id']; ?>&edit_jadwal=1&kg_id=<?php echo $jadwal['id']; ?>" 
                                                   class="text-purple-600 hover:text-purple-900 p-1" 
                                                   title="Edit Jadwal">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=hapus_guru_kelas&kg_id=<?php echo $jadwal['id']; ?>&kelas_id=<?php echo $kelas_edit['id']; ?>" 
                                                   onclick="return confirm('Hapus guru <?php echo htmlspecialchars(addslashes($jadwal['nama_guru'])); ?> dari kelas?')"
                                                   class="text-red-600 hover:text-red-900 p-1" 
                                                   title="Hapus Jadwal">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-data text-center py-8">
                                <i class="fas fa-chalkboard-teacher text-gray-300 text-4xl mb-3"></i>
                                <p class="text-gray-600">Belum ada guru pengajar di kelas ini.</p>
                                <?php if (count($guru_options) > 0): ?>
                                    <p class="text-sm text-gray-500 mt-2">Klik "Tambah Guru" untuk menambahkan guru pengajar.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Tambah Kelas -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'tambah'): ?>
        <div id="tambahModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header green">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-plus-circle mr-2"></i> Tambah Kelas Baru
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <form method="POST" action="dataKelas.php">
                    <input type="hidden" name="tambah_kelas" value="1">
                    
                    <div class="modal-body">
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="nama_kelas">Nama Kelas *</label>
                                <input type="text" id="nama_kelas" name="nama_kelas" 
                                       class="form-input" 
                                       placeholder="Contoh: Kelas SD - Matematika"
                                       required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tingkat">Jenjang *</label>
                                <select id="tingkat" name="tingkat" class="form-input" required>
                                    <option value="">Pilih Jenjang</option>
                                    <option value="SD">SD</option>
                                    <option value="SMP">SMP</option>
                                    <option value="SMA">SMA</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tahun_ajaran">Tahun Ajaran *</label>
                                <input type="text" id="tahun_ajaran" name="tahun_ajaran" 
                                       class="form-input" 
                                       placeholder="Contoh: 2025/2026"
                                       required>
                                <p class="text-xs text-gray-500 mt-1">Format: Tahun/Tahun</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="kapasitas">Kapasitas *</label>
                                <input type="number" id="kapasitas" name="kapasitas" 
                                       class="form-input" 
                                       value="20" min="1" max="50" required>
                                <p class="text-xs text-gray-500 mt-1">Maksimal 50 siswa per kelas</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="status">Status</label>
                                <select id="status" name="status" class="form-input" required>
                                    <option value="aktif" selected>Aktif</option>
                                    <option value="non-aktif">Non-Aktif</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                            <h4 class="font-medium text-blue-900 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Informasi
                            </h4>
                            <ul class="text-sm text-blue-800 space-y-1">
                                <li>• Kode kelas akan digenerate otomatis</li>
                                <li>• Anda dapat menambahkan siswa dan guru setelah kelas dibuat</li>
                                <li>• Kapasitas dapat diubah kapan saja</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="flex justify-between">
                            <button type="button" onclick="closeModal()" 
                                    class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Batal
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
                                <i class="fas fa-save mr-2"></i> Simpan Kelas Baru
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

        // Dropdown functionality
        function initDropdowns() {
            const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
            
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const dropdownGroup = this.closest('.mb-1');
                    const submenu = dropdownGroup.querySelector('.dropdown-submenu');
                    const arrow = this.querySelector('.arrow');
                    
                    // Close all other dropdowns first
                    document.querySelectorAll('.dropdown-submenu').forEach(otherMenu => {
                        if (otherMenu !== submenu) {
                            otherMenu.style.display = 'none';
                        }
                    });
                    
                    document.querySelectorAll('.dropdown-toggle').forEach(otherToggle => {
                        if (otherToggle !== this) {
                            otherToggle.classList.remove('open');
                            const otherArrow = otherToggle.querySelector('.arrow');
                            if (otherArrow) otherArrow.style.transform = 'rotate(0deg)';
                        }
                    });
                    
                    // Toggle current dropdown
                    if (submenu.style.display === 'block') {
                        submenu.style.display = 'none';
                        this.classList.remove('open');
                        if (arrow) arrow.style.transform = 'rotate(0deg)';
                    } else {
                        submenu.style.display = 'block';
                        this.classList.add('open');
                        if (arrow) arrow.style.transform = 'rotate(-90deg)';
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
                        const arrow = toggle.querySelector('.arrow');
                        if (arrow) arrow.style.transform = 'rotate(0deg)';
                    });
                }
            });
            
            // Close dropdowns with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dropdown-submenu').forEach(submenu => {
                        submenu.style.display = 'none';
                    });
                    document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                        toggle.classList.remove('open');
                        const arrow = toggle.querySelector('.arrow');
                        if (arrow) arrow.style.transform = 'rotate(0deg)';
                    });
                }
            });
        }

        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', initDropdowns);

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

        // Fungsi untuk menutup modal
        function closeModal() {
            // Hilangkan parameter action dan id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('id');
            url.searchParams.delete('tab');
            url.searchParams.delete('edit_jadwal');
            url.searchParams.delete('kg_id');
            
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
            <?php endif; ?>
        });

        // Konfirmasi Hapus
        function confirmDelete(id, name) {
            if (confirm(`Apakah Anda yakin ingin menghapus kelas "${name}"?\n\nPerhatian: Hanya kelas kosong yang bisa dihapus!`)) {
                window.location.href = `dataKelas.php?action=hapus&id=${id}`;
            }
        }

        // Switch Tab di Detail Modal
        function switchTab(tabName) {
            // Update URL dengan parameter tab
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
            
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Update active tab button
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Find and activate the clicked tab button
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                if (button.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                    button.classList.add('active');
                }
            });
        }

        // Toggle form tambah siswa
        function toggleTambahSiswa() {
            const form = document.getElementById('formTambahSiswa');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                window.scrollTo({
                    top: form.offsetTop - 20,
                    behavior: 'smooth'
                });
            } else {
                form.style.display = 'none';
            }
        }

        // Toggle form tambah guru
        function toggleTambahGuru() {
            const form = document.getElementById('formTambahGuru');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                window.scrollTo({
                    top: form.offsetTop - 20,
                    behavior: 'smooth'
                });
            } else {
                form.style.display = 'none';
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
                    
                    // Validasi khusus untuk jam
                    const jamMulai = form.querySelector('input[name="jam_mulai"]');
                    const jamSelesai = form.querySelector('input[name="jam_selesai"]');
                    
                    if (jamMulai && jamSelesai && jamMulai.value && jamSelesai.value) {
                        if (jamMulai.value >= jamSelesai.value) {
                            isValid = false;
                            jamSelesai.style.borderColor = '#ef4444';
                            alert('Jam selesai harus setelah jam mulai!');
                        }
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        if (!jamMulai || !jamSelesai || jamMulai.value < jamSelesai.value) {
                            alert('Harap lengkapi semua field yang wajib diisi!');
                        }
                    }
                });
            });
        });

        // Inisialisasi tab saat halaman dimuat
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($active_tab && $kelas_detail): ?>
                // Aktifkan tab yang dipilih
                switchTab('<?php echo $active_tab; ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>