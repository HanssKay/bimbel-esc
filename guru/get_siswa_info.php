<?php
// get_siswa_info.php
session_start();
require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit();
}

$siswa_id = $_GET['id'] ?? 0;
$guru_id = $_SESSION['role_id'];

$sql = "SELECT s.nis, s.nama_lengkap, kg.mata_pelajaran 
        FROM siswa s
        JOIN kelas_siswa ks ON s.id = ks.siswa_id
        JOIN kelas_guru kg ON ks.kelas_id = kg.kelas_id
        WHERE s.id = ? AND kg.guru_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $siswa_id, $guru_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'nis' => $row['nis'],
        'nama_lengkap' => $row['nama_lengkap'],
        'mata_pelajaran' => $row['mata_pelajaran']
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
}
?>