<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SESSION['user_role'] != 'admin') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Administrator';
$currentPage = basename($_SERVER['PHP_SELF']);

// FILTER PARAMETER
$filter_hari = $_GET['hari'] ?? '';
$filter_tingkat = $_GET['tingkat'] ?? '';
$filter_siswa = $_GET['siswa_id'] ?? '';
$filter_guru = $_GET['guru_id'] ?? '';
$filter_mata_pelajaran = $_GET['mata_pelajaran'] ?? '';

// QUERY JADWAL SISWA (Disesuaikan dengan struktur baru)
$jadwal_siswa = [];
$where_conditions = ["jb.status = 'aktif'", "ps.status = 'aktif'", "sp.status = 'aktif'"];
$filter_params = [];
$types = "";

if ($filter_hari !== '') {
    $where_conditions[] = "jb.hari = ?";
    $filter_params[] = $filter_hari;
    $types .= "s";
}

if ($filter_tingkat !== '') {
    $where_conditions[] = "ps.tingkat = ?";
    $filter_params[] = $filter_tingkat;
    $types .= "s";
}

if ($filter_siswa !== '') {
    $where_conditions[] = "ps.siswa_id = ?";
    $filter_params[] = $filter_siswa;
    $types .= "i";
}

if ($filter_guru !== '') {
    $where_conditions[] = "sp.guru_id = ?";
    $filter_params[] = $filter_guru;
    $types .= "i";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$sql_jadwal = "
    SELECT 
        jb.*,
        ps.id as pendaftaran_id,
        ps.jenis_kelas,
        ps.tingkat,
        ps.status as status_pendaftaran,
        s.id as siswa_id,
        s.nama_lengkap as nama_siswa,
        s.kelas as kelas_sekolah,
        s.sekolah_asal,
        sp.id as siswa_pelajaran_id,
        sp.nama_pelajaran,
        sp.status as status_pelajaran,
        g.id as guru_id,
        u.full_name as nama_guru,
        g.bidang_keahlian,
        o.nama_ortu,
        o.no_hp as hp_ortu,
        DATE_FORMAT(jb.jam_mulai, '%H:%i') as jam_mulai_format,
        DATE_FORMAT(jb.jam_selesai, '%H:%i') as jam_selesai_format,
        TIMESTAMPDIFF(MINUTE, jb.jam_mulai, jb.jam_selesai) as durasi_menit
    FROM jadwal_belajar jb
    JOIN siswa_pelajaran sp ON jb.siswa_pelajaran_id = sp.id
    JOIN pendaftaran_siswa ps ON jb.pendaftaran_id = ps.id
    JOIN siswa s ON ps.siswa_id = s.id
    LEFT JOIN guru g ON sp.guru_id = g.id
    LEFT JOIN users u ON g.user_id = u.id
    LEFT JOIN orangtua o ON s.orangtua_id = o.id
    $where_clause
    ORDER BY 
        FIELD(jb.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'),
        jb.jam_mulai,
        s.nama_lengkap
";

try {
    $stmt = $conn->prepare($sql_jadwal);
    
    if (!empty($filter_params)) {
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
}

// AMBIL DATA UNTUK FILTER DROPDOWN

// Daftar siswa aktif
$siswa_list = [];
$sql_siswa = "SELECT id, nama_lengkap, kelas FROM siswa WHERE status = 'aktif' ORDER BY nama_lengkap";
$result_siswa = $conn->query($sql_siswa);
while ($row = $result_siswa->fetch_assoc()) {
    $siswa_list[] = $row;
}

// Daftar guru aktif
$guru_list = [];
$sql_guru = "SELECT g.id, u.full_name, g.bidang_keahlian FROM guru g 
            JOIN users u ON g.user_id = u.id 
            WHERE g.status = 'aktif' 
            ORDER BY u.full_name";
$result_guru = $conn->query($sql_guru);
while ($row = $result_guru->fetch_assoc()) {
    $guru_list[] = $row;
}

// HITUNG STATISTIK
$total_jadwal = count($jadwal_siswa);
$total_siswa = count(array_unique(array_column($jadwal_siswa, 'siswa_id')));
$total_guru = count(array_unique(array_column($jadwal_siswa, 'guru_id')));

// Group by hari untuk statistik
$jadwal_by_hari = [];
$jadwal_by_tingkat = [];
$jadwal_by_pelajaran = [];

foreach ($jadwal_siswa as $jadwal) {
    $hari = $jadwal['hari'];
    $tingkat = $jadwal['tingkat'];
    $pelajaran = $jadwal['nama_pelajaran'] ?? 'Tidak diketahui';
    
    if (!isset($jadwal_by_hari[$hari])) {
        $jadwal_by_hari[$hari] = 0;
    }
    $jadwal_by_hari[$hari]++;
    
    if (!isset($jadwal_by_tingkat[$tingkat])) {
        $jadwal_by_tingkat[$tingkat] = 0;
    }
    $jadwal_by_tingkat[$tingkat]++;
    
    if (!isset($jadwal_by_pelajaran[$pelajaran])) {
        $jadwal_by_pelajaran[$pelajaran] = 0;
    }
    $jadwal_by_pelajaran[$pelajaran]++;
}

// FUNGSI UNTUK MENDAPATKAN MATA PELAJARAN SISWA YANG BELUM ADA JADWAL
function getMataPelajaranTanpaJadwal($conn, $siswa_id, $guru_id = null) {
    $mata_pelajaran = [];
    
    // Query untuk mendapatkan semua mata pelajaran aktif siswa
    $sql = "SELECT sp.id, sp.nama_pelajaran, ps.tingkat
            FROM siswa_pelajaran sp
            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
            WHERE ps.siswa_id = ? 
            AND sp.status = 'aktif'
            AND ps.status = 'aktif'";
    
    $params = [$siswa_id];
    $types = "i";
    
    // Jika guru_id diberikan, filter berdasarkan guru yang dipilih
    if ($guru_id) {
        $sql .= " AND (sp.guru_id IS NULL OR sp.guru_id = ?)";
        $params[] = $guru_id;
        $types .= "i";
    }
    
    $sql .= " ORDER BY sp.nama_pelajaran";
    
    $stmt = $conn->prepare($sql);
    if ($guru_id) {
        $stmt->bind_param($types, $siswa_id, $guru_id);
    } else {
        $stmt->bind_param($types, $siswa_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $pelajaran_id = $row['id'];
        $nama_pelajaran = $row['nama_pelajaran'];
        $tingkat = $row['tingkat'];
        
        // Cek apakah mata pelajaran ini sudah memiliki jadwal aktif
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
        
        // Jika belum ada jadwal, tambahkan ke daftar
        if ($row_cek['jumlah_jadwal'] == 0) {
            $mata_pelajaran[] = [
                'id' => $pelajaran_id,
                'nama_pelajaran' => $nama_pelajaran,
                'tingkat' => $tingkat
            ];
        }
    }
    $stmt->close();
    
    return $mata_pelajaran;
}

// PROSES TAMBAH JADWAL (Admin bisa tambah untuk siapa saja)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_jadwal'])) {
    $siswa_pelajaran_id = $_POST['siswa_pelajaran_id'] ?? '';
    $guru_id = $_POST['guru_id'] ?? '';
    $hari = $_POST['hari'] ?? '';
    $jam_mulai = $_POST['jam_mulai'] ?? '';
    $jam_selesai = $_POST['jam_selesai'] ?? '';
    
    if ($siswa_pelajaran_id && $hari && $jam_mulai && $jam_selesai) {
        try {
            // Ambil data siswa dari siswa_pelajaran termasuk pendaftaran_id
            $sql_get_data = "SELECT sp.siswa_id, sp.guru_id, sp.pendaftaran_id, ps.tingkat
                            FROM siswa_pelajaran sp
                            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                            WHERE sp.id = ?";
            $stmt_get = $conn->prepare($sql_get_data);
            $stmt_get->bind_param("i", $siswa_pelajaran_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            
            if ($result_get->num_rows === 0) {
                $_SESSION['error_message'] = "Data siswa tidak ditemukan!";
                $stmt_get->close();
            } else {
                $data_siswa = $result_get->fetch_assoc();
                $stmt_get->close();
                
                $siswa_id = $data_siswa['siswa_id'];
                $current_guru_id = $data_siswa['guru_id'];
                $pendaftaran_id = $data_siswa['pendaftaran_id'];
                $tingkat = $data_siswa['tingkat'];
                
                // Update guru jika berbeda dan guru_id dipilih
                if ($guru_id && $current_guru_id != $guru_id) {
                    $sql_update_guru = "UPDATE siswa_pelajaran SET guru_id = ? WHERE id = ?";
                    $stmt_update = $conn->prepare($sql_update_guru);
                    $stmt_update->bind_param("ii", $guru_id, $siswa_pelajaran_id);
                    if ($stmt_update->execute()) {
                        $current_guru_id = $guru_id; // Update variabel
                    }
                    $stmt_update->close();
                } else {
                    // Jika guru tidak dipilih di form, gunakan guru yang sudah ada
                    $guru_id = $current_guru_id;
                }
                
                // Pastikan guru_id tidak null
                if (!$guru_id) {
                    $_SESSION['error_message'] = "Guru harus dipilih untuk mata pelajaran ini!";
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
                            $_SESSION['error_message'] = "Guru sudah memiliki jadwal dengan siswa lain pada waktu yang sama!";
                            $stmt_cek_guru->close();
                        } else {
                            $stmt_cek_guru->close();
                            
                            // Tambah jadwal baru - INI YANG DIPERBAIKI
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
                       WHERE jb.id = ?";
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->bind_param("i", $jadwal_id);
            $stmt_get->execute();
            $result_get = $stmt_get->get_result();
            $old_data = $result_get->fetch_assoc();
            $stmt_get->close();
            
            $siswa_pelajaran_id = $old_data['siswa_pelajaran_id'] ?? 0;
            $siswa_id = $old_data['siswa_id'] ?? 0;
            $guru_id = $old_data['guru_id'] ?? 0;
            $pendaftaran_id = $old_data['pendaftaran_id'] ?? 0;
            
            if ($siswa_pelajaran_id && $siswa_id && $guru_id && $pendaftaran_id) {
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
                        $_SESSION['error_message'] = "Guru sudah memiliki jadwal lain pada waktu yang sama!";
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

// AJAX: Ambil mata pelajaran berdasarkan siswa dan guru
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_pelajaran' && isset($_GET['siswa_id'])) {
    $siswa_id = $_GET['siswa_id'];
    $guru_id = $_GET['guru_id'] ?? null;
    
    $mata_pelajaran = getMataPelajaranTanpaJadwal($conn, $siswa_id, $guru_id);
    
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
    <title>Kelola Jadwal Siswa - Bimbel Esc</title>
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
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
                            <select name="hari" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                        
                        <!-- Filter Tingkat -->
                        <div>
                            <select name="tingkat" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Tingkat</option>
                                <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD</option>
                                <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                <option value="TK" <?php echo $filter_tingkat == 'TK' ? 'selected' : ''; ?>>TK</option>
                                <option value="Alumni" <?php echo $filter_tingkat == 'Alumni' ? 'selected' : ''; ?>>Alumni</option>
                                <option value="Umum" <?php echo $filter_tingkat == 'Umum' ? 'selected' : ''; ?>>Umum</option>
                            </select>
                        </div>
                        
                        <!-- Filter Siswa -->
                        <?php if (count($siswa_list) > 0): ?>
                        <div>
                            <select name="siswa_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Siswa</option>
                                <?php foreach ($siswa_list as $siswa): ?>
                                <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa == $siswa['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> (<?php echo $siswa['kelas']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Filter Guru -->
                        <?php if (count($guru_list) > 0): ?>
                        <div>
                            <select name="guru_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                        <?php if ($filter_hari || $filter_tingkat || $filter_siswa || $filter_guru || $filter_mata_pelajaran): ?>
                        <a href="jadwalSiswa.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm flex items-center">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                        <?php endif; ?>
                        
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm flex items-center">
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
                            if ($filter_hari) $filters[] = "Hari: $filter_hari";
                            if ($filter_tingkat) $filters[] = "Tingkat: $filter_tingkat";
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
                                <?php $no = 1; ?>
                                <?php foreach ($jadwal_siswa as $jadwal): 
                                    $durasi = $jadwal['durasi_menit'] / 60; // dalam jam
                                ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($jadwal['nama_siswa']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $jadwal['kelas_sekolah']; ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $jadwal['sekolah_asal']; ?></div>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_pelajaran']); ?></div>
                                        <span class="badge <?php echo $jadwal['jenis_kelas'] == 'Excellent' ? 'badge-success' : 'badge-warning'; ?>">
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
                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_guru']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $jadwal['bidang_keahlian']; ?></div>
                                        <?php else: ?>
                                        <span class="text-sm text-gray-500">Belum ditugaskan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-purple"><?php echo $jadwal['hari']; ?></span>
                                    </td>
                                    <td>
                                        <div class="font-medium">
                                            <?php echo $jadwal['jam_mulai_format']; ?> - <?php echo $jadwal['jam_selesai_format']; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo number_format($durasi, 1); ?> jam
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($jadwal['nama_ortu'])): ?>
                                        <div class="font-medium"><?php echo htmlspecialchars($jadwal['nama_ortu']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $jadwal['hp_ortu']; ?></div>
                                        <?php else: ?>
                                        <span class="text-sm text-gray-500">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(
                                                <?php echo $jadwal['id']; ?>,
                                                '<?php echo $jadwal['hari']; ?>',
                                                '<?php echo $jadwal['jam_mulai']; ?>',
                                                '<?php echo $jadwal['jam_selesai']; ?>'
                                            )" class="px-3 py-1 bg-yellow-500 text-white rounded text-sm hover:bg-yellow-600">
                                                <i class="fas fa-edit mr-1"></i> Edit
                                            </button>
                                            <button onclick="openHapusModal(
                                                <?php echo $jadwal['id']; ?>, 
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
                        <div class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-blue-50 border-blue-300' : 'border-gray-200'; ?>">
                            <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $day; ?></div>
                            <div class="text-2xl font-bold <?php echo $count > 0 ? 'text-blue-600' : 'text-gray-400'; ?>">
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
                        <div class="text-center p-4 border rounded-lg <?php echo $is_filtered ? 'bg-green-50 border-green-300' : 'border-gray-200'; ?>">
                            <div class="text-sm font-medium text-gray-900 mb-1"><?php echo $tingkat; ?></div>
                            <div class="text-2xl font-bold <?php echo $count > 0 ? 'text-green-600' : 'text-gray-400'; ?>">
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
                    <div class="space-y-4">
                        <!-- Pilih Siswa -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pilih Siswa
                            </label>
                            <select name="siswa_id" id="tambahSiswa" required
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Pilih Siswa --</option>
                                <?php foreach ($siswa_list as $siswa): ?>
                                <option value="<?php echo $siswa['id']; ?>">
                                    <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> 
                                    (<?php echo $siswa['kelas']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
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
                                <option value="">-- Pilih Mata Pelajaran --</option>
                                <!-- Data akan diisi via AJAX -->
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
            document.getElementById('tambahMataPelajaran').innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
        }

        function closeTambahModal() {
            document.getElementById('modalTambah').classList.remove('active');
            document.body.style.overflow = 'auto';
            document.getElementById('formTambahJadwal').reset();
            document.getElementById('tambahMataPelajaran').innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
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

        // Variabel global untuk menyimpan ID jadwal yang akan dihapus
        let jadwalIdToDelete = null;

        // Fungsi untuk membuka modal hapus
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

        // AJAX: Ambil mata pelajaran berdasarkan siswa dan guru
        function loadMataPelajaran() {
            const siswaId = document.getElementById('tambahSiswa').value;
            const guruId = document.getElementById('tambahGuru').value;
            const pelajaranSelect = document.getElementById('tambahMataPelajaran');
            
            if (!siswaId || !guruId) {
                pelajaranSelect.innerHTML = '<option value="">-- Pilih Mata Pelajaran --</option>';
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
                    siswa_id: siswaId,
                    guru_id: guruId
                },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        let options = '<option value="">-- Pilih Mata Pelajaran --</option>';
                        data.forEach(function(pelajaran) {
                            options += `<option value="${pelajaran.id}">${pelajaran.nama_pelajaran} (${pelajaran.tingkat})</option>`;
                        });
                        pelajaranSelect.innerHTML = options;
                    } else {
                        pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran yang tersedia</option>';
                    }
                    pelajaranSelect.disabled = false;
                },
                error: function() {
                    pelajaranSelect.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
                    pelajaranSelect.disabled = false;
                }
            });
        }

        // Event listeners untuk form tambah
        document.getElementById('tambahSiswa').addEventListener('change', loadMataPelajaran);
        document.getElementById('tambahGuru').addEventListener('change', loadMataPelajaran);

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

        // Validasi form tambah: Jam selesai harus > jam mulai
        document.getElementById('formTambahJadwal').addEventListener('submit', function(e) {
            const jamMulai = document.querySelector('#formTambahJadwal input[name="jam_mulai"]').value;
            const jamSelesai = document.querySelector('#formTambahJadwal input[name="jam_selesai"]').value;
            const siswaPelajaran = document.getElementById('tambahMataPelajaran').value;
            
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
        document.getElementById('formEditJadwal').addEventListener('submit', function(e) {
            const jamMulai = document.getElementById('editJamMulai').value;
            const jamSelesai = document.getElementById('editJamSelesai').value;
            
            if (jamSelesai <= jamMulai) {
                e.preventDefault();
                alert('Jam selesai harus setelah jam mulai!');
            }
        });
    </script>
</body>
</html>