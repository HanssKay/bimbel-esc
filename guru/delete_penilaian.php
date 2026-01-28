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

// CEK METHOD POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit();
}

// CEK PARAMETER ID
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid ID parameter'
    ]);
    exit();
}

$penilaian_id = intval($_POST['id']);
$guru_id = $_SESSION['role_id'] ?? 0;

try {
    // CEK APAKAH DATA MILIK GURU YANG SEDANG LOGIN
    $sql_check = "SELECT id FROM penilaian_siswa WHERE id = ? AND guru_id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("ii", $penilaian_id, $guru_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows === 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Data tidak ditemukan atau Anda tidak memiliki akses'
        ]);
        exit();
    }
    
    // HAPUS DATA
    $sql_delete = "DELETE FROM penilaian_siswa WHERE id = ?";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bind_param("i", $penilaian_id);
    
    if ($stmt_delete->execute()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Data penilaian berhasil dihapus'
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menghapus data'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in delete_penilaian.php: " . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan server: ' . $e->getMessage()
    ]);
}