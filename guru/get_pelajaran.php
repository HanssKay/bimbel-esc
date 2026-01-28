<?php
session_start();
error_reporting(0); // Matikan error reporting untuk produksi
ini_set('display_errors', 0);

// TIDAK ada whitespace sebelum <?php
// TIDAK ada echo/print sebelum ini

require_once '../includes/config.php';

// CEK LOGIN & ROLE untuk AJAX
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$guru_id = $_SESSION['role_id'] ?? 0;

// Jika role_id kosong, cari dari database
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
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Guru not found']);
        exit();
    }
}

if ($guru_id == 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Guru data not found']);
    exit();
}

// Dapatkan siswa_id dari GET
$siswa_id = isset($_GET['siswa_id']) ? intval($_GET['siswa_id']) : 0;

if ($siswa_id == 0) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Siswa ID tidak valid']);
    exit();
}

$mata_pelajaran = [];

try {
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
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $siswa_id, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // PERBAIKAN: Gunakan $result->fetch_assoc(), bukan $stmt->fetch_assoc()
    while ($row = $result->fetch_assoc()) {
        $pelajaran_id = $row['id'];
        $current_guru_id = $row['guru_id'];
        
        // CEK APAKAH SUDAH ADA JADWAL UNTUK MAPEL INI (dengan guru SIAPAPUN)
        $sql_cek_jadwal = "SELECT COUNT(*) as jumlah_jadwal 
                          FROM jadwal_belajar jb
                          WHERE jb.siswa_pelajaran_id = ? 
                          AND jb.status = 'aktif'";
        
        $stmt_cek = $conn->prepare($sql_cek_jadwal);
        if (!$stmt_cek) {
            throw new Exception("Prepare cek failed: " . $conn->error);
        }
        
        $stmt_cek->bind_param("i", $pelajaran_id);
        $stmt_cek->execute();
        $result_cek = $stmt_cek->get_result();
        $row_cek = $result_cek->fetch_assoc();
        $stmt_cek->close();
        
        // HANYA kirim yang BELUM ADA JADWAL
        if ($row_cek['jumlah_jadwal'] == 0) {
            $mata_pelajaran[] = [
                'id' => $pelajaran_id,
                'nama_pelajaran' => $row['nama_pelajaran'],
                'tingkat' => $row['tingkat'],
                'jenis_kelas' => $row['jenis_kelas'],
                'guru_id' => $current_guru_id
            ];
        }
    }
    $stmt->close();
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// Pastikan tidak ada output lain sebelum ini
header('Content-Type: application/json');
echo json_encode($mata_pelajaran);
exit();

// TIDAK ada ?> di akhir file untuk menghindari whitespace