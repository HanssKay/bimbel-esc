<?php
session_start();
require_once '../includes/config.php';

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$siswa_id = $_GET['siswa_id'] ?? 0;
$guru_id = $_SESSION['role_id'];

if ($siswa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID siswa tidak valid']);
    exit();
}

// Query untuk mengambil riwayat penilaian
$sql = "SELECT 
            id,
            siswa_id,
            DATE_FORMAT(tanggal_penilaian, '%d %M %Y') as tanggal,
            total_score,
            persentase,
            kategori,
            willingness_learn,
            concentration,
            critical_thinking,
            independence,
            problem_solving
        FROM penilaian_siswa 
        WHERE siswa_id = ? AND guru_id = ?
        ORDER BY tanggal_penilaian DESC 
        LIMIT 20";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $siswa_id, $guru_id);
$stmt->execute();
$result = $stmt->get_result();

$riwayat_data = [];
while ($row = $result->fetch_assoc()) {
    $riwayat_data[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $riwayat_data
]);
?>