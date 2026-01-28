<?php
session_start();
require_once '../includes/config.php';

// CEK AJAX REQUEST
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'orangtua') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// VALIDASI INPUT
if (!isset($_GET['id']) || !isset($_GET['anak_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$penilaian_id = intval($_GET['id']);
$anak_id = intval($_GET['anak_id']);
$user_id = $_SESSION['user_id'];

// AMBIL ID ORANGTUA
$orangtua_id = 0;
$sql_ortu = "SELECT id FROM orangtua WHERE user_id = ?";
$stmt_ortu = $conn->prepare($sql_ortu);
$stmt_ortu->bind_param("i", $user_id);
$stmt_ortu->execute();
$result_ortu = $stmt_ortu->get_result();
if ($row_ortu = $result_ortu->fetch_assoc()) {
    $orangtua_id = $row_ortu['id'];
}
$stmt_ortu->close();

if ($orangtua_id == 0) {
    echo json_encode(['success' => false, 'message' => 'Orang tua tidak ditemukan']);
    exit();
}

// AMBIL DETAIL PENILAIAN
$sql = "SELECT 
            ps.*,
            DATE_FORMAT(ps.tanggal_penilaian, '%d %M %Y') as tanggal_format,
            u.full_name as nama_guru,
            k.nama_kelas as kelas_bimbel,
            ps.persentase
        FROM penilaian_siswa ps
        JOIN guru g ON ps.guru_id = g.id
        JOIN users u ON g.user_id = u.id
        LEFT JOIN kelas k ON ps.kelas_id = k.id
        JOIN siswa s ON ps.siswa_id = s.id
        WHERE ps.id = ? AND s.id = ? AND s.orangtua_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $penilaian_id, $anak_id, $orangtua_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    
    // Hitung persentase jika belum ada
    if (!isset($data['persentase']) || empty($data['persentase'])) {
        $data['persentase'] = round(($data['total_score'] / 110) * 100);
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
} else {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
}

$stmt->close();
?>