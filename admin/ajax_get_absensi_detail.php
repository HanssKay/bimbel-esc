<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Ambil parameter
$siswa_id = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 0;
$guru_id = isset($_GET['guru_id']) ? (int)$_GET['guru_id'] : 0;
$periode = isset($_GET['periode']) ? $_GET['periode'] : '';

if (!$siswa_id || !$guru_id || !$periode) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit();
}

// Parse periode
list($tahun, $bulan) = explode('-', $periode);
$tanggal_awal = date('Y-m-01', strtotime("$tahun-$bulan-01"));
$tanggal_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));

try {
    $sql = "SELECT 
                a.id,
                a.tanggal_absensi,
                a.sesi_ke,
                a.status,
                a.keterangan,
                a.bukti_izin,
                a.created_at as waktu_input,
                sp.nama_pelajaran
            FROM absensi_siswa a
            LEFT JOIN siswa_pelajaran sp ON a.siswa_pelajaran_id = sp.id
            WHERE a.siswa_id = ? 
                AND a.guru_id = ?
                AND a.tanggal_absensi BETWEEN ? AND ?
            ORDER BY a.created_at DESC";  // <-- URUTAN TERBARU DI ATAS
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $siswa_id, $guru_id, $tanggal_awal, $tanggal_akhir);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $absensi_data = [];
    $summary = [
        'hadir' => 0,
        'izin' => 0,
        'sakit' => 0,
        'alpha' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $summary[$row['status']]++;
        
        // Ambil JAM SAJA dari created_at (HH:MM:SS)
        $jam_input = '';
        if ($row['waktu_input']) {
            $jam_input = date('H:i:s', strtotime($row['waktu_input']));
        }
        
        $absensi_data[] = [
            'id' => $row['id'],
            'tanggal_absensi' => $row['tanggal_absensi'],
            'sesi_ke' => $row['sesi_ke'],
            'jam_input' => $jam_input,
            'nama_pelajaran' => $row['nama_pelajaran'] ?? 'Tidak diketahui',
            'status' => $row['status'],
            'keterangan' => $row['keterangan'],
            'bukti_izin' => $row['bukti_izin']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $absensi_data,
        'summary' => $summary,
        'total' => count($absensi_data)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>