<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'orangtua') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;

if ($link_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID link tidak valid']);
    exit();
}

// Ambil orangtua_id dari tabel orangtua
$stmtOrtu = $conn->prepare("SELECT id FROM orangtua WHERE user_id = ? LIMIT 1");
$stmtOrtu->bind_param("i", $user_id);
$stmtOrtu->execute();
$rowOrtu = $stmtOrtu->get_result()->fetch_assoc();
$stmtOrtu->close();

if (!$rowOrtu) {
    echo json_encode(['success' => false, 'message' => 'Data orang tua tidak ditemukan']);
    exit();
}
$orangtua_id = (int) $rowOrtu['id'];

// Pastikan link ada dan aktif
$stmtCheck = $conn->prepare("SELECT id FROM shared_links WHERE id = ? AND status = 'aktif' LIMIT 1");
$stmtCheck->bind_param("i", $link_id);
$stmtCheck->execute();
$linkExists = $stmtCheck->get_result()->num_rows > 0;
$stmtCheck->close();

if (!$linkExists) {
    echo json_encode(['success' => false, 'message' => 'Link tidak aktif atau tidak ditemukan']);
    exit();
}

// Insert / update tracking klik
$sql = "INSERT INTO shared_link_clicks (link_id, orangtua_id, jumlah_klik, pertama_dibuka, terakhir_dibuka)
        VALUES (?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            jumlah_klik = jumlah_klik + 1,
            terakhir_dibuka = NOW()";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $link_id, $orangtua_id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $res = $conn->prepare("SELECT jumlah_klik, terakhir_dibuka FROM shared_link_clicks WHERE link_id = ? AND orangtua_id = ?");
    $res->bind_param("ii", $link_id, $orangtua_id);
    $res->execute();
    $clickRow = $res->get_result()->fetch_assoc();
    $res->close();

    echo json_encode([
        'success'         => true,
        'jumlah_klik'     => (int) ($clickRow['jumlah_klik'] ?? 1),
        'terakhir_dibuka' => $clickRow['terakhir_dibuka'] ?? date('Y-m-d H:i:s'),
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal mencatat klik']);
}
