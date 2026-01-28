<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit();
}

// CEK PARAMETER ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid ID parameter'
    ]);
    exit();
}

$penilaian_id = intval($_GET['id']);
$guru_id = $_SESSION['role_id'] ?? 0;

// QUERY UNTUK MENGAMBIL DETAIL PENILAIAN
try {
    $sql = "SELECT 
                psn.*,
                s.nama_lengkap,
                s.kelas as kelas_sekolah,
                sp.nama_pelajaran,
                ps.tingkat,
                ps.jenis_kelas,
                DATE_FORMAT(psn.tanggal_penilaian, '%d %M %Y') as tanggal_format,
                DATE_FORMAT(psn.tanggal_penilaian, '%W') as hari_penilaian
            FROM penilaian_siswa psn
            JOIN siswa s ON psn.siswa_id = s.id
            JOIN siswa_pelajaran sp ON psn.siswa_pelajaran_id = sp.id
            JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
            WHERE psn.id = ?
            AND psn.guru_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $penilaian_id, $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Data penilaian tidak ditemukan'
        ]);
        exit();
    }
    
    $data = $result->fetch_assoc();
    
    // Hitung persentase jika belum ada di database
    if (!isset($data['persentase']) || empty($data['persentase'])) {
        $data['persentase'] = round(($data['total_score'] / 50) * 100);
    }
    
    // Pastikan semua field yang diperlukan ada
    $required_fields = [
        'willingness_learn' => 0,
        'problem_solving' => 0,
        'critical_thinking' => 0,
        'concentration' => 0,
        'independence' => 0,
        'total_score' => 0,
        'persentase' => 0,
        'kategori' => 'Belum Dinilai',
        'catatan_guru' => '',
        'rekomendasi' => ''
    ];
    
    foreach ($required_fields as $field => $default) {
        if (!isset($data[$field]) || $data[$field] === null) {
            $data[$field] = $default;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_penilaian_detail.php: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}