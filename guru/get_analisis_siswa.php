<?php
session_start();
require_once '../includes/config.php';

// Cek login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'guru') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$siswa_id = $_GET['siswa_id'] ?? 0;
$jenis = $_GET['jenis'] ?? 'harian';
$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('n');
$minggu = $_GET['minggu'] ?? date('W');
$guru_id = $_SESSION['role_id'];

if ($siswa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID siswa tidak valid']);
    exit();
}

// Query untuk analisis siswa - hanya 5 indikator
$sql = "SELECT 
            AVG(total_score) as rata_total,
            AVG(persentase) as rata_persentase,
            COUNT(*) as total_penilaian,
            MAX(kategori) as kategori_terakhir,
            AVG(willingness_learn) as rata_willingness,
            AVG(concentration) as rata_concentration,
            AVG(critical_thinking) as rata_critical,
            AVG(independence) as rata_independence,
            AVG(problem_solving) as rata_problem
        FROM penilaian_siswa 
        WHERE siswa_id = ? AND guru_id = ?";

// Tambahkan filter periode
$params = [$siswa_id, $guru_id];
$types = "ii";

if ($jenis == 'mingguan') {
    $sql .= " AND YEAR(tanggal_penilaian) = ? AND WEEK(tanggal_penilaian, 1) = ?";
    $params[] = $tahun;
    $params[] = $minggu;
    $types .= "ii";
} elseif ($jenis == 'bulanan') {
    $sql .= " AND YEAR(tanggal_penilaian) = ? AND MONTH(tanggal_penilaian) = ?";
    $params[] = $tahun;
    $params[] = $bulan;
    $types .= "ii";
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Format periode
    $periode = '';
    if ($jenis == 'mingguan') {
        $periode = "Minggu ke-$minggu Tahun $tahun";
    } elseif ($jenis == 'bulanan') {
        $bulan_nama = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $periode = $bulan_nama[$bulan] . ' ' . $tahun;
    } else {
        $periode = 'Harian';
    }
    
    // Data rata-rata per indikator (hanya 5 indikator)
    $rata_indikator = [
        'willingness_learn' => round($row['rata_willingness'], 1),
        'concentration' => round($row['rata_concentration'], 1),
        'critical_thinking' => round($row['rata_critical'], 1),
        'independence' => round($row['rata_independence'], 1),
        'problem_solving' => round($row['rata_problem'], 1)
    ];
    
    // Tentukan trend
    $trend = 'stabil';
    if ($row['total_penilaian'] > 1) {
        // Ambil 2 penilaian terakhir untuk cek trend
        $sql_trend = "SELECT total_score 
                     FROM penilaian_siswa 
                     WHERE siswa_id = ? AND guru_id = ? 
                     ORDER BY tanggal_penilaian DESC 
                     LIMIT 2";
        $stmt_trend = $conn->prepare($sql_trend);
        $stmt_trend->bind_param("ii", $siswa_id, $guru_id);
        $stmt_trend->execute();
        $result_trend = $stmt_trend->get_result();
        
        if ($result_trend->num_rows == 2) {
            $scores = $result_trend->fetch_all(MYSQLI_ASSOC);
            if ($scores[0]['total_score'] > $scores[1]['total_score']) {
                $trend = 'naik';
            } elseif ($scores[0]['total_score'] < $scores[1]['total_score']) {
                $trend = 'turun';
            }
        }
    }
    
    // Berikan rekomendasi berdasarkan analisis
    $rekomendasi = '';
    $rata_total = round($row['rata_total'], 1);
    
    if ($rata_total >= 90) {
        $rekomendasi = 'Prestasi sangat baik, pertahankan dan berikan tantangan lebih untuk mengasah kemampuan.';
    } elseif ($rata_total >= 70) {
        $rekomendasi = 'Prestasi baik, fokuskan pada indikator yang masih perlu peningkatan.';
    } elseif ($rata_total >= 50) {
        $rekomendasi = 'Perlu peningkatan, fokus pada latihan soal dan pemahaman konsep dasar.';
    } else {
        $rekomendasi = 'Perlu bimbingan intensif, identifikasi kesulitan belajar dan berikan pendekatan khusus.';
    }
    
    // Tambahkan rekomendasi spesifik berdasarkan indikator terendah
    if ($rata_total < 90) { // Hanya jika belum sempurna
        $indikator_terendah = array_keys($rata_indikator, min($rata_indikator))[0];
        $indikator_nama = [
            'willingness_learn' => 'Kemauan Belajar',
            'concentration' => 'Konsentrasi',
            'critical_thinking' => 'Berpikir Kritis',
            'independence' => 'Kemandirian',
            'problem_solving' => 'Pemecahan Masalah'
        ];
        
        $rekomendasi_spesifik = [
            'willingness_learn' => 'Tingkatkan motivasi belajar dengan memberikan pujian dan penguatan positif.',
            'concentration' => 'Latih fokus dengan memberikan tugas yang bertahap dan lingkungan belajar yang kondusif.',
            'critical_thinking' => 'Kembangkan kemampuan analisis dengan soal-soal HOTS (Higher Order Thinking Skills).',
            'independence' => 'Berikan kesempatan untuk bekerja mandiri dan mengambil inisiatif.',
            'problem_solving' => 'Latih dengan studi kasus dan problem-based learning.'
        ];
        
        $rekomendasi .= ' Fokus utama pada peningkatan ' . $indikator_nama[$indikator_terendah] . 
                       ': ' . $rekomendasi_spesifik[$indikator_terendah];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'rata_total' => $rata_total,
            'rata_persentase' => round($row['rata_persentase'], 1),
            'total_penilaian' => $row['total_penilaian'],
            'kategori_terakhir' => $row['kategori_terakhir'],
            'rata_indikator' => $rata_indikator,
            'trend' => $trend,
            'rekomendasi' => $rekomendasi,
            'periode' => $periode
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
}
?>