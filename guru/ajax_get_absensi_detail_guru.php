<?php
session_start();
require_once '../includes/config.php';

header('Content-Type: application/json');

// Cek login - Izinkan admin DAN guru
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Izinkan role admin atau guru
if ($_SESSION['user_role'] != 'admin' && $_SESSION['user_role'] != 'guru') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Invalid role']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$siswa_id = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 0;
$guru_id = isset($_GET['guru_id']) ? (int)$_GET['guru_id'] : 0;
$periode = isset($_GET['periode']) ? $_GET['periode'] : '';

if (!$siswa_id || !$guru_id || !$periode) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// Validasi tambahan untuk role guru: pastikan guru_id sesuai dengan session
if ($_SESSION['user_role'] == 'guru') {
    $session_guru_id = $_SESSION['role_id'] ?? 0;
    if ($guru_id != $session_guru_id) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized - You can only view your own students']);
        exit();
    }
}

list($tahun, $bulan) = explode('-', $periode);
$tanggal_awal = date('Y-m-01', strtotime("$tahun-$bulan-01"));
$tanggal_akhir = date('Y-m-t', strtotime("$tahun-$bulan-01"));

try {
    $sql = "SELECT 
                a.id,
                a.tanggal_absensi,
                a.status,
                a.keterangan,
                a.bukti_izin,
                a.created_at,
                DAYNAME(a.tanggal_absensi) as hari,
                DAY(a.tanggal_absensi) as tanggal,
                MONTH(a.tanggal_absensi) as bulan,
                YEAR(a.tanggal_absensi) as tahun,
                DATE_FORMAT(a.created_at, '%H:%i:%s') as waktu_input
            FROM absensi_siswa a
            WHERE a.siswa_id = ? 
                AND a.guru_id = ?
                AND a.tanggal_absensi BETWEEN ? AND ?
            ORDER BY a.tanggal_absensi DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $siswa_id, $guru_id, $tanggal_awal, $tanggal_akhir);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $absensi_detail = [];
    $status_counts = [
        'hadir' => 0,
        'izin' => 0,
        'sakit' => 0,
        'alpha' => 0
    ];
    
    while ($row = $result->fetch_assoc()) {
        $status_counts[$row['status']]++;
        $absensi_detail[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $absensi_detail,
        'summary' => $status_counts,
        'total' => count($absensi_detail)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>