<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$full_name = $_SESSION['full_name'] ?? 'User';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL
$success_message = '';
$error_message = '';
$penilaian_data = [];
$penilaian_detail = null;
$penilaian_edit = null;
$siswa_options = [];
$guru_options = [];
$pendaftaran_options = [];
$active_tab = 'list';

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER - SIMPAN DI SESSION AGAR TETAP TERPERTAHANKAN
if (isset($_GET['search'])) {
    $_SESSION['filter_search'] = trim($_GET['search']);
}
if (isset($_GET['filter_siswa']) && $_GET['filter_siswa'] != '') {
    $_SESSION['filter_siswa'] = intval($_GET['filter_siswa']);
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_siswa']);
}
if (isset($_GET['filter_guru']) && $_GET['filter_guru'] != '') {
    $_SESSION['filter_guru'] = intval($_GET['filter_guru']);
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_guru']);
}
if (isset($_GET['filter_tingkat']) && $_GET['filter_tingkat'] != '') {
    $_SESSION['filter_tingkat'] = $_GET['filter_tingkat'];
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_tingkat']);
}
if (isset($_GET['filter_bulan']) && $_GET['filter_bulan'] != '') {
    $_SESSION['filter_bulan'] = $_GET['filter_bulan'];
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_bulan']);
}
if (isset($_GET['filter_kategori']) && $_GET['filter_kategori'] != '') {
    $_SESSION['filter_kategori'] = $_GET['filter_kategori'];
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_kategori']);
}
if (isset($_GET['periode']) && $_GET['periode'] != '') {
    $_SESSION['filter_periode'] = $_GET['periode'];
} elseif (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_periode']);
}

// Gunakan filter dari session
$search = $_SESSION['filter_search'] ?? '';
$filter_siswa = $_SESSION['filter_siswa'] ?? '';
$filter_guru = $_SESSION['filter_guru'] ?? '';
$filter_tingkat = $_SESSION['filter_tingkat'] ?? '';
$filter_bulan = $_SESSION['filter_bulan'] ?? '';
$filter_kategori = $_SESSION['filter_kategori'] ?? '';
$periode = $_SESSION['filter_periode'] ?? '';

// SET ACTIVE TAB
if (isset($_GET['tab'])) {
    $active_tab = $_GET['tab'];
    $_SESSION['active_tab'] = $active_tab;
} elseif (isset($_SESSION['active_tab'])) {
    $active_tab = $_SESSION['active_tab'];
}

// =============== FUNGSI BANTU ===============
function getBulanList()
{
    return [
        '' => 'Semua Bulan',
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember'
    ];
}

function getTahunList()
{
    $current_year = date('Y');
    $years = [];
    
    // Tampilkan 5 tahun terakhir dan 2 tahun ke depan
    for ($i = -5; $i <= 2; $i++) {
        $years[] = $current_year + $i;
    }
    
    // Urutkan dari yang terbaru
    rsort($years);
    return $years;
}

function getKategoriByPersentase($persentase)
{
    if ($persentase === null || $persentase === 0) {
        return 'Belum Dinilai';
    }
    
    if ($persentase >= 80)
        return 'Sangat Baik';
    if ($persentase >= 60)
        return 'Baik';
    if ($persentase >= 40)
        return 'Cukup';
    return 'Kurang';
}

// =============== LOAD OPTIONS UNTUK FILTER/FORM ===============
// Load data siswa untuk filter/form
$siswa_sql = "SELECT s.id, s.nama_lengkap, s.kelas 
              FROM siswa s 
              WHERE s.status = 'aktif'
              ORDER BY s.nama_lengkap";
$siswa_stmt = $conn->prepare($siswa_sql);
$siswa_stmt->execute();
$siswa_result = $siswa_stmt->get_result();
$all_siswa_options = [];
while ($siswa = $siswa_result->fetch_assoc()) {
    $all_siswa_options[] = $siswa;
}
$siswa_stmt->close();

// Load data guru untuk filter/form
$guru_sql = "SELECT g.id, u.full_name as nama_guru, g.bidang_keahlian 
             FROM guru g 
             JOIN users u ON g.user_id = u.id 
             WHERE g.status = 'aktif' 
             ORDER BY u.full_name";
$guru_stmt = $conn->prepare($guru_sql);
$guru_stmt->execute();
$guru_result = $guru_stmt->get_result();
$all_guru_options = [];
while ($guru = $guru_result->fetch_assoc()) {
    $all_guru_options[] = $guru;
}
$guru_stmt->close();

// Load data pendaftaran aktif untuk form tambah (jika guru)
if ($user_role == 'guru') {
    // Cari guru_id berdasarkan user_id
    $guru_info_sql = "SELECT id FROM guru WHERE user_id = ?";
    $guru_info_stmt = $conn->prepare($guru_info_sql);
    $guru_info_stmt->bind_param("i", $user_id);
    $guru_info_stmt->execute();
    $guru_info_result = $guru_info_stmt->get_result();
    $guru_info = $guru_info_result->fetch_assoc();
    $guru_id = $guru_info['id'] ?? 0;
    $guru_info_stmt->close();
    
    // Ambil siswa_pelajaran yang diajar oleh guru ini
    $pendaftaran_sql = "SELECT sp.id as siswa_pelajaran_id, sp.pendaftaran_id, 
                               s.id as siswa_id, s.nama_lengkap, sp.nama_pelajaran,
                               ps.tingkat
                        FROM siswa_pelajaran sp
                        JOIN siswa s ON sp.siswa_id = s.id
                        JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                        WHERE sp.guru_id = ? 
                          AND sp.status = 'aktif'
                          AND ps.status = 'aktif'
                        ORDER BY s.nama_lengkap";
    $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
    $pendaftaran_stmt->bind_param("i", $guru_id);
    $pendaftaran_stmt->execute();
    $pendaftaran_result = $pendaftaran_stmt->get_result();
    $pendaftaran_options = [];
    while ($pendaftaran = $pendaftaran_result->fetch_assoc()) {
        $pendaftaran_options[] = $pendaftaran;
    }
    $pendaftaran_stmt->close();
}

// =============== DETAIL PENILAIAN ===============
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $penilaian_id = intval($_GET['id']);

    $sql = "SELECT ps.*, 
                   s.nama_lengkap as nama_siswa, s.kelas as kelas_sekolah,
                   u.full_name as nama_guru, g.bidang_keahlian,
                   sp.nama_pelajaran, pd.tingkat as tingkat_bimbel,
                   pd.jenis_kelas as jenis_kelas_bimbel,
                   sp.id as siswa_pelajaran_id
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            WHERE ps.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $penilaian_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Hitung manual untuk 5 indikator (maks 50)
        $row['total_manual'] = ($row['willingness_learn'] ?? 0) + 
                               ($row['problem_solving'] ?? 0) + 
                               ($row['critical_thinking'] ?? 0) + 
                               ($row['concentration'] ?? 0) + 
                               ($row['independence'] ?? 0);

        $row['persentase_manual'] = $row['total_manual'] > 0 ? round(($row['total_manual'] / 50) * 100) : 0;
        $row['kategori_manual'] = getKategoriByPersentase($row['persentase_manual']);

        // Load riwayat penilaian siswa ini untuk grafik
        $riwayat_sql = "SELECT ps.*, DATE_FORMAT(ps.tanggal_penilaian, '%b %Y') as bulan
                       FROM penilaian_siswa ps
                       WHERE ps.siswa_id = ?
                       ORDER BY ps.tanggal_penilaian DESC
                       LIMIT 6";

        $riwayat_stmt = $conn->prepare($riwayat_sql);
        $riwayat_stmt->bind_param("i", $row['siswa_id']);
        $riwayat_stmt->execute();
        $riwayat_result = $riwayat_stmt->get_result();

        $riwayat_penilaian = [];
        while ($riwayat = $riwayat_result->fetch_assoc()) {
            $riwayat_penilaian[] = $riwayat;
        }
        $riwayat_stmt->close();

        $row['riwayat'] = $riwayat_penilaian;
        $penilaian_detail = $row;
    }
    $stmt->close();
}

// =============== HAPUS PENILAIAN ===============
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $penilaian_id = intval($_GET['id']);

    $delete_sql = "DELETE FROM penilaian_siswa WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $penilaian_id);

    if ($delete_stmt->execute()) {
        $_SESSION['success_message'] = "✅ Penilaian berhasil dihapus!";
    } else {
        $_SESSION['error_message'] = "❌ Gagal menghapus penilaian!";
    }

    header('Location: dataNilai.php?tab=list');
    exit();
}

// =============== EDIT PENILAIAN ===============
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $penilaian_id = intval($_GET['id']);

    $sql = "SELECT ps.*, 
                   s.nama_lengkap as nama_siswa, s.kelas as kelas_sekolah,
                   u.full_name as nama_guru, g.bidang_keahlian,
                   sp.nama_pelajaran, pd.tingkat as tingkat_bimbel,
                   pd.jenis_kelas as jenis_kelas_bimbel,
                   sp.id as siswa_pelajaran_id, pd.id as pendaftaran_id,
                   g.id as guru_id, s.id as siswa_id
            FROM penilaian_siswa ps
            JOIN siswa s ON ps.siswa_id = s.id
            JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
            LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
            JOIN guru g ON ps.guru_id = g.id
            JOIN users u ON g.user_id = u.id
            WHERE ps.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $penilaian_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $penilaian_edit = $row;
    }
    $stmt->close();
}

// =============== UPDATE PENILAIAN ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_penilaian'])) {
    $penilaian_id = intval($_POST['penilaian_id']);
    $siswa_pelajaran_id = !empty($_POST['siswa_pelajaran_id']) ? intval($_POST['siswa_pelajaran_id']) : null;
    $tanggal_penilaian = $_POST['tanggal_penilaian'];
    $willingness_learn = intval($_POST['willingness_learn']);
    $problem_solving = intval($_POST['problem_solving']);
    $critical_thinking = intval($_POST['critical_thinking']);
    $concentration = intval($_POST['concentration']);
    $independence = intval($_POST['independence']);
    $catatan_guru = trim($_POST['catatan_guru']);
    $rekomendasi = trim($_POST['rekomendasi']);

    // Validasi input
    if ($willingness_learn < 1 || $willingness_learn > 10 ||
        $problem_solving < 1 || $problem_solving > 10 ||
        $critical_thinking < 1 || $critical_thinking > 10 ||
        $concentration < 1 || $concentration > 10 ||
        $independence < 1 || $independence > 10) {
        
        $_SESSION['error_message'] = "❌ Nilai indikator harus antara 1-10!";
        header('Location: dataNilai.php?action=edit&id=' . $penilaian_id . '&tab=list');
        exit();
    }

    // Hitung total score
    $total_score = $willingness_learn + $problem_solving + $critical_thinking + 
                   $concentration + $independence;
    $persentase = round(($total_score / 50) * 100);
    $kategori = getKategoriByPersentase($persentase);
    $periode_penilaian = date('Y-m', strtotime($tanggal_penilaian));

    // Update data
    $update_sql = "UPDATE penilaian_siswa SET 
                   siswa_pelajaran_id = ?,
                   tanggal_penilaian = ?,
                   willingness_learn = ?,
                   problem_solving = ?,
                   critical_thinking = ?,
                   concentration = ?,
                   independence = ?,
                   total_score = ?,
                   persentase = ?,
                   kategori = ?,
                   periode_penilaian = ?,
                   catatan_guru = ?,
                   rekomendasi = ?,
                   created_at = NOW()
                   WHERE id = ?";

    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param(
        "isiiiiiiisssi",
        $siswa_pelajaran_id,
        $tanggal_penilaian,
        $willingness_learn,
        $problem_solving,
        $critical_thinking,
        $concentration,
        $independence,
        $total_score,
        $persentase,
        $kategori,
        $periode_penilaian,
        $catatan_guru,
        $rekomendasi,
        $penilaian_id
    );

    if ($update_stmt->execute()) {
        $_SESSION['success_message'] = "✅ Penilaian berhasil diperbarui!";
        header('Location: dataNilai.php?action=detail&id=' . $penilaian_id . '&tab=list');
    } else {
        $_SESSION['error_message'] = "❌ Gagal memperbarui penilaian!";
        header('Location: dataNilai.php?action=edit&id=' . $penilaian_id . '&tab=list');
    }
    exit();
}

// =============== TAMBAH PENILAIAN ===============
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_penilaian'])) {
    $siswa_pelajaran_id = !empty($_POST['siswa_pelajaran_id']) ? intval($_POST['siswa_pelajaran_id']) : null;
    $tanggal_penilaian = $_POST['tanggal_penilaian'];
    $willingness_learn = intval($_POST['willingness_learn']);
    $problem_solving = intval($_POST['problem_solving']);
    $critical_thinking = intval($_POST['critical_thinking']);
    $concentration = intval($_POST['concentration']);
    $independence = intval($_POST['independence']);
    $catatan_guru = trim($_POST['catatan_guru']);
    $rekomendasi = trim($_POST['rekomendasi']);

    // Validasi input
    $errors = [];
    if ($willingness_learn < 1 || $willingness_learn > 10)
        $errors[] = "Kemauan belajar harus 1-10";
    if ($problem_solving < 1 || $problem_solving > 10)
        $errors[] = "Pemecahan masalah harus 1-10";
    if ($critical_thinking < 1 || $critical_thinking > 10)
        $errors[] = "Berpikir kritis harus 1-10";
    if ($concentration < 1 || $concentration > 10)
        $errors[] = "Konsentrasi harus 1-10";
    if ($independence < 1 || $independence > 10)
        $errors[] = "Kemandirian harus 1-10";
    if (empty($catatan_guru))
        $errors[] = "Catatan guru harus diisi";
    if (!$siswa_pelajaran_id)
        $errors[] = "Siswa dan pelajaran harus dipilih";

    if (!empty($errors)) {
        $_SESSION['error_message'] = "❌ " . implode(", ", $errors);
        header('Location: dataNilai.php?action=tambah&tab=list');
        exit();
    }

    // Ambil data siswa_pelajaran untuk mendapatkan siswa_id, guru_id, dan pendaftaran_id
    $siswa_pelajaran_sql = "SELECT sp.siswa_id, sp.guru_id, sp.pendaftaran_id 
                            FROM siswa_pelajaran sp 
                            WHERE sp.id = ?";
    $siswa_pelajaran_stmt = $conn->prepare($siswa_pelajaran_sql);
    $siswa_pelajaran_stmt->bind_param("i", $siswa_pelajaran_id);
    $siswa_pelajaran_stmt->execute();
    $siswa_pelajaran_result = $siswa_pelajaran_stmt->get_result();
    $siswa_pelajaran_data = $siswa_pelajaran_result->fetch_assoc();
    $siswa_pelajaran_stmt->close();

    if (!$siswa_pelajaran_data) {
        $_SESSION['error_message'] = "❌ Data siswa dan pelajaran tidak ditemukan!";
        header('Location: dataNilai.php?action=tambah&tab=list');
        exit();
    }

    $siswa_id = $siswa_pelajaran_data['siswa_id'];
    $guru_id = $siswa_pelajaran_data['guru_id'];
    $pendaftaran_id = $siswa_pelajaran_data['pendaftaran_id'];

    // Hitung total score
    $total_score = $willingness_learn + $problem_solving + $critical_thinking + 
                   $concentration + $independence;
    $persentase = round(($total_score / 50) * 100);
    $kategori = getKategoriByPersentase($persentase);
    $periode_penilaian = date('Y-m', strtotime($tanggal_penilaian));

    // Insert data
    $insert_sql = "INSERT INTO penilaian_siswa 
                   (siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, 
                    tanggal_penilaian, willingness_learn, problem_solving, 
                    critical_thinking, concentration, independence, total_score, 
                    persentase, kategori, periode_penilaian, catatan_guru, 
                    rekomendasi, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param(
        "iiisiiiiiiissss",
        $siswa_id,
        $pendaftaran_id,
        $siswa_pelajaran_id,
        $guru_id,
        $tanggal_penilaian,
        $willingness_learn,
        $problem_solving,
        $critical_thinking,
        $concentration,
        $independence,
        $total_score,
        $persentase,
        $kategori,
        $periode_penilaian,
        $catatan_guru,
        $rekomendasi
    );

    if ($insert_stmt->execute()) {
        $penilaian_id = $conn->insert_id;
        $_SESSION['success_message'] = "✅ Penilaian berhasil ditambahkan!";
        header('Location: dataNilai.php?action=detail&id=' . $penilaian_id . '&tab=list');
    } else {
        $_SESSION['error_message'] = "❌ Gagal menambahkan penilaian! " . $conn->error;
        header('Location: dataNilai.php?action=tambah&tab=list');
    }
    exit();
}

// =============== AMBIL DATA PENILAIAN DENGAN FILTER ===============
$sql = "SELECT ps.*, 
               s.nama_lengkap as nama_siswa, s.kelas as kelas_sekolah,
               u.full_name as nama_guru, g.bidang_keahlian,
               sp.nama_pelajaran, pd.tingkat as tingkat_bimbel,
               g.id as guru_pendaftaran_id
        FROM penilaian_siswa ps
        JOIN siswa s ON ps.siswa_id = s.id
        JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
        LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
        JOIN guru g ON ps.guru_id = g.id
        JOIN users u ON g.user_id = u.id
        WHERE 1=1";

$params = [];
$param_types = "";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(s.nama_lengkap LIKE ? OR u.full_name LIKE ? OR sp.nama_pelajaran LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "sss";
}

if (!empty($filter_siswa)) {
    $conditions[] = "ps.siswa_id = ?";
    $params[] = $filter_siswa;
    $param_types .= "i";
}

if (!empty($filter_guru)) {
    $conditions[] = "ps.guru_id = ?";
    $params[] = $filter_guru;
    $param_types .= "i";
}

if (!empty($filter_tingkat)) {
    $conditions[] = "pd.tingkat LIKE ?";
    $params[] = "%" . $filter_tingkat . "%";
    $param_types .= "s";
}

if (!empty($filter_bulan) && $filter_bulan != '') {
    $conditions[] = "MONTH(ps.tanggal_penilaian) = ?";
    $params[] = $filter_bulan;
    $param_types .= "s";
}

if (!empty($periode)) {
    $conditions[] = "YEAR(ps.tanggal_penilaian) = ?";
    $params[] = $periode;
    $param_types .= "s";
}

if (!empty($filter_kategori)) {
    $conditions[] = "ps.kategori = ?";
    $params[] = $filter_kategori;
    $param_types .= "s";
}

if (count($conditions) > 0) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY ps.tanggal_penilaian DESC, ps.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $row['kategori_display'] = getKategoriByPersentase($row['persentase'] ?? 0);
    $penilaian_data[] = $row;
}
$stmt->close();

// Hitung statistik dengan filter yang sama
$stats_sql = "SELECT 
              COUNT(*) as total_penilaian,
              AVG(total_score) as rata_nilai,
              AVG(persentase) as rata_persentase,
              MIN(total_score) as nilai_terendah,
              MAX(total_score) as nilai_tertinggi,
              COUNT(DISTINCT ps.siswa_id) as total_siswa_dinilai,
              COUNT(DISTINCT ps.guru_id) as total_guru_menilai
              FROM penilaian_siswa ps
              JOIN siswa s ON ps.siswa_id = s.id
              JOIN pendaftaran_siswa pd ON ps.pendaftaran_id = pd.id
              LEFT JOIN siswa_pelajaran sp ON ps.siswa_pelajaran_id = sp.id
              JOIN guru g ON ps.guru_id = g.id
              JOIN users u ON g.user_id = u.id";

$stats_params = [];
$stats_param_types = "";
$stats_conditions = [];

if (!empty($search)) {
    $stats_conditions[] = "(s.nama_lengkap LIKE ? OR u.full_name LIKE ? OR sp.nama_pelajaran LIKE ?)";
    $stats_params[] = $search_param;
    $stats_params[] = $search_param;
    $stats_params[] = $search_param;
    $stats_param_types .= "sss";
}

if (!empty($filter_siswa)) {
    $stats_conditions[] = "ps.siswa_id = ?";
    $stats_params[] = $filter_siswa;
    $stats_param_types .= "i";
}

if (!empty($filter_guru)) {
    $stats_conditions[] = "ps.guru_id = ?";
    $stats_params[] = $filter_guru;
    $stats_param_types .= "i";
}

if (!empty($filter_tingkat)) {
    $stats_conditions[] = "pd.tingkat LIKE ?";
    $stats_params[] = "%" . $filter_tingkat . "%";
    $stats_param_types .= "s";
}

if (!empty($filter_bulan) && $filter_bulan != '') {
    $stats_conditions[] = "MONTH(ps.tanggal_penilaian) = ?";
    $stats_params[] = $filter_bulan;
    $stats_param_types .= "s";
}

if (!empty($periode)) {
    $stats_conditions[] = "YEAR(ps.tanggal_penilaian) = ?";
    $stats_params[] = $periode;
    $stats_param_types .= "s";
}

if (!empty($filter_kategori)) {
    $stats_conditions[] = "ps.kategori = ?";
    $stats_params[] = $filter_kategori;
    $stats_param_types .= "s";
}

if (count($stats_conditions) > 0) {
    $stats_sql .= " WHERE " . implode(" AND ", $stats_conditions);
}

$stats_stmt = $conn->prepare($stats_sql);
if (!empty($stats_params)) {
    $stats_stmt->bind_param($stats_param_types, ...$stats_params);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$statistik = $stats_result->fetch_assoc();
$stats_stmt->close();

// Handle NULL values
$statistik['rata_nilai'] = $statistik['rata_nilai'] !== null ? round($statistik['rata_nilai'], 1) : 0;
$statistik['rata_persentase'] = $statistik['rata_persentase'] !== null ? round($statistik['rata_persentase'], 1) : 0;
$statistik['nilai_terendah'] = $statistik['nilai_terendah'] !== null ? $statistik['nilai_terendah'] : 0;
$statistik['nilai_tertinggi'] = $statistik['nilai_tertinggi'] !== null ? $statistik['nilai_tertinggi'] : 0;
$statistik['total_penilaian'] = $statistik['total_penilaian'] !== null ? $statistik['total_penilaian'] : 0;
$statistik['total_siswa_dinilai'] = $statistik['total_siswa_dinilai'] !== null ? $statistik['total_siswa_dinilai'] : 0;
$statistik['total_guru_menilai'] = $statistik['total_guru_menilai'] !== null ? $statistik['total_guru_menilai'] : 0;

// Daftar bulan dan tahun untuk filter
$bulan_list = getBulanList();
$tahun_list = getTahunList();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penilaian - <?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?> Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1100;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }
        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes modalFadeOut {
            from { opacity: 1; transform: translateY(0); }
            to { opacity: 0; transform: translateY(-20px); }
        }
        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
        }
        .modal-header.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .modal-header.yellow {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .modal-header.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .modal-header.purple {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        }
        .modal-body {
            padding: 24px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            background-color: #f9fafb;
            border-radius: 0 0 8px 8px;
        }
        .close {
            color: #fff;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }
        .close:hover {
            color: #f0f0f0;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        
        /* Status badge */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge.sangat-baik {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge.baik {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .badge.cukup {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge.kurang {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .badge.belum-dinilai {
            background-color: #f3f4f6;
            color: #6b7280;
        }

        /* Indicator Card */
        .indicator-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            background: white;
        }

        .indicator-card .score {
            font-size: 20px;
            font-weight: bold;
            color: #3b82f6;
        }

        .indicator-card .label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        /* Dropdown styles untuk menu dinamis */
        .dropdown-submenu {
            display: none;
            max-height: 500px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .dropdown-submenu[style*="display: block"] {
            display: block;
        }
        
        .dropdown-toggle.open .arrow {
            transform: rotate(180deg);
        }
        
        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }
        
        /* Sidebar menu hover */
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Logout button khusus */
        .logout-btn {
            margin-top: 2rem !important;
            color: #fca5a5 !important;
        }
        
        .logout-btn:hover {
            background-color: rgba(254, 226, 226, 0.9) !important;
            color: #b91c1c !important;
        }
        
        /* Mobile menu styles */
        #mobileMenu {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100%;
            z-index: 1100;
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2);
            background-color: #1e40af;
        }
        
        #mobileMenu.menu-open {
            transform: translateX(0);
        }
        
        /* Overlay for mobile menu */
        .menu-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1099;
        }
        
        .menu-overlay.active {
            display: block;
        }
        
        /* Responsive untuk sidebar */
        @media (min-width: 768px) {
            .desktop-sidebar {
                display: block;
            }
            
            .mobile-header {
                display: none;
            }
            
            #mobileMenu {
                display: none;
            }
            
            .menu-overlay {
                display: none !important;
            }
        }
        
        @media (max-width: 767px) {
            .desktop-sidebar {
                display: none;
            }
            
            .mobile-header {
                display: block;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .modal-body {
                padding: 16px;
                max-height: 80vh;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            /* Table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-responsive table {
                min-width: 640px;
            }
        }

        /* Table styles */
        .data-table {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .data-table th {
            background-color: #f8fafc;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background-color: #f9fafb;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Action buttons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        
        .action-btn.edit {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .action-btn.edit:hover {
            background-color: #bfdbfe;
        }
        
        .action-btn.delete {
            background-color: #fee2e2;
            color: #dc2626;
        }
        
        .action-btn.delete:hover {
            background-color: #fecaca;
        }
        
        .action-btn.view {
            background-color: #f0f9ff;
            color: #0369a1;
        }
        
        .action-btn.view:hover {
            background-color: #e0f2fe;
        }
        
        .action-btn i {
            margin-right: 4px;
            font-size: 12px;
        }
        
        /* Alert messages */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 48px 16px;
            color: #6b7280;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Tab styling */
        .tab-button {
            padding: 10px 16px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tab-button:hover {
            color: #374151;
        }

        .tab-button.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
            font-weight: 600;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
        
        /* Filter info badge */
        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            background-color: #e0f2fe;
            color: #0369a1;
            border-radius: 9999px;
            font-size: 12px;
            margin: 2px;
        }
        
        .filter-badge i {
            margin-right: 4px;
            font-size: 10px;
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200"><?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?> Dashboard</p>
        </div>

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <?php if ($user_role == 'admin'): ?>
                        <i class="fas fa-user-shield"></i>
                    <?php else: ?>
                        <i class="fas fa-chalkboard-teacher"></i>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">
                        <?php echo $user_role == 'admin' ? 'Administrator' : 'Guru'; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, $user_role); ?>
        </nav>
    </div>

    <!-- Mobile Header -->
    <div class="mobile-header bg-blue-800 text-white p-4 w-full fixed top-0 z-30 md:hidden">
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="text-white mr-3">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="flex items-center">
                <div class="text-right mr-3">
                    <p class="text-sm"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-xs text-blue-300"><?php echo $user_role == 'admin' ? 'Admin' : 'Guru'; ?></p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <?php if ($user_role == 'admin'): ?>
                        <i class="fas fa-user-shield"></i>
                    <?php else: ?>
                        <i class="fas fa-chalkboard-teacher"></i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Overlay -->
    <div id="menuOverlay" class="menu-overlay"></div>

    <!-- Mobile Menu Sidebar -->
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="h-full flex flex-col">
            <div class="p-4 bg-blue-900">
                <div class="flex items-center">
                    <button id="menuClose" class="text-white mr-3">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                    <h1 class="text-xl font-bold">Bimbel Esc</h1>
                </div>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <?php if ($user_role == 'admin'): ?>
                            <i class="fas fa-user-shield text-lg"></i>
                        <?php else: ?>
                            <i class="fas fa-chalkboard-teacher text-lg"></i>
                        <?php endif; ?>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">
                            <?php echo $user_role == 'admin' ? 'Administrator' : 'Guru'; ?>
                        </p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, $user_role); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64">
        <!-- Mobile header spacer -->
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow p-4">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-clipboard-list mr-2"></i> Laporan Penilaian
                    </h1>
                    <p class="text-gray-600">Kelola data penilaian perkembangan siswa. Total: <?php echo number_format($statistik['total_penilaian']); ?> penilaian</p>
                    
                    <!-- Tampilkan filter aktif -->
                    <?php if (!empty($search) || !empty($filter_siswa) || !empty($filter_guru) || !empty($filter_tingkat) || !empty($filter_bulan) || !empty($periode) || !empty($filter_kategori)): ?>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span class="text-sm text-gray-600 mr-2">Filter aktif:</span>
                            <?php if (!empty($search)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-search"></i> <?php echo htmlspecialchars($search); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_siswa)): ?>
                                <?php 
                                $siswa_nama = '';
                                foreach ($all_siswa_options as $siswa) {
                                    if ($siswa['id'] == $filter_siswa) {
                                        $siswa_nama = $siswa['nama_lengkap'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="filter-badge">
                                    <i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($siswa_nama); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_guru)): ?>
                                <?php 
                                $guru_nama = '';
                                foreach ($all_guru_options as $guru) {
                                    if ($guru['id'] == $filter_guru) {
                                        $guru_nama = $guru['nama_guru'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="filter-badge">
                                    <i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($guru_nama); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_tingkat)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-layer-group"></i> <?php echo $filter_tingkat; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_bulan)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-calendar"></i> <?php echo $bulan_list[$filter_bulan]; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($periode)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-calendar-alt"></i> <?php echo $periode; ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($filter_kategori)): ?>
                                <span class="filter-badge">
                                    <i class="fas fa-star"></i> <?php echo $filter_kategori; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <span class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Notifications -->
            <?php if ($success_message): ?>
                <div class="mb-4 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="mb-4 bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-red-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="mb-6 border-b border-gray-200">
                <nav class="-mb-px flex space-x-8 overflow-x-auto">
                    <button onclick="setActiveTab('list')"
                        class="tab-button <?php echo $active_tab == 'list' ? 'active' : ''; ?> whitespace-nowrap">
                        <i class="fas fa-list mr-2"></i> Daftar Penilaian
                    </button>
                </nav>
            </div>

            <!-- TAB 1: Daftar Penilaian -->
            <div id="tab-list" class="tab-content <?php echo $active_tab == 'list' ? 'active' : ''; ?>">
                <!-- Statistik -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-blue-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Total Penilaian</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo number_format($statistik['total_penilaian']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-chart-line text-green-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Rata-rata Nilai</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['rata_nilai']; ?>/50</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-users text-yellow-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Siswa Dinilai</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo number_format($statistik['total_siswa_dinilai']); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-chalkboard-teacher text-purple-600"></i>
                                </div>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-600">Guru Menilai</p>
                                <p class="text-lg font-semibold text-gray-900"><?php echo number_format($statistik['total_guru_menilai']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                    <div class="px-4 py-5 sm:p-6">
                        <form method="GET" action="dataNilai.php" class="space-y-4">
                            <input type="hidden" name="tab" value="list">
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Search -->
                                <div>
                                    <label for="search" class="block text-sm font-medium text-gray-700">Pencarian</label>
                                    <div class="mt-1 relative rounded-md shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                            <i class="fas fa-search text-gray-400"></i>
                                        </div>
                                        <input type="text" name="search" id="search" 
                                               class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md" 
                                               placeholder="Nama siswa/guru, mata pelajaran..."
                                               value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Filter Siswa -->
                                <div>
                                    <label for="filter_siswa" class="block text-sm font-medium text-gray-700">Siswa</label>
                                    <select id="filter_siswa" name="filter_siswa" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Siswa</option>
                                        <?php foreach ($all_siswa_options as $siswa): ?>
                                            <option value="<?php echo $siswa['id']; ?>" <?php echo $filter_siswa == $siswa['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($siswa['nama_lengkap']); ?> (<?php echo $siswa['kelas']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Filter Guru -->
                                <div>
                                    <label for="filter_guru" class="block text-sm font-medium text-gray-700">Guru</label>
                                    <select id="filter_guru" name="filter_guru" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Guru</option>
                                        <?php foreach ($all_guru_options as $guru): ?>
                                            <option value="<?php echo $guru['id']; ?>" <?php echo $filter_guru == $guru['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($guru['nama_guru']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Filter Tingkat -->
                                <div>
                                    <label for="filter_tingkat" class="block text-sm font-medium text-gray-700">Tingkat</label>
                                    <select id="filter_tingkat" name="filter_tingkat" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Tingkat</option>
                                        <option value="TK" <?php echo $filter_tingkat == 'TK' ? 'selected' : ''; ?>>TK</option>
                                        <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD</option>
                                        <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                        <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                        <option value="Alumni" <?php echo $filter_tingkat == 'Alumni' ? 'selected' : ''; ?>>Alumni</option>
                                        <option value="Umum" <?php echo $filter_tingkat == 'Umum' ? 'selected' : ''; ?>>Umum</option>
                                    </select>
                                </div>

                                <!-- Filter Bulan -->
                                <div>
                                    <label for="filter_bulan" class="block text-sm font-medium text-gray-700">Bulan</label>
                                    <select id="filter_bulan" name="filter_bulan" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <?php foreach ($bulan_list as $key => $nama): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $filter_bulan == $key ? 'selected' : ''; ?>>
                                                <?php echo $nama; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Filter Tahun -->
                                <div>
                                    <label for="periode" class="block text-sm font-medium text-gray-700">Tahun</label>
                                    <select id="periode" name="periode" 
                                            class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <option value="">Semua Tahun</option>
                                        <?php foreach ($tahun_list as $tahun): ?>
                                            <option value="<?php echo $tahun; ?>" <?php echo $periode == $tahun ? 'selected' : ''; ?>>
                                                <?php echo $tahun; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Filter Kategori -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Kategori</label>
                                <div class="flex flex-wrap gap-2">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="filter_kategori" value="" class="mr-1"
                                            <?php echo empty($filter_kategori) ? 'checked' : ''; ?>>
                                        <span class="text-sm">Semua</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="filter_kategori" value="Sangat Baik"
                                            class="mr-1 text-green-600" <?php echo $filter_kategori == 'Sangat Baik' ? 'checked' : ''; ?>>
                                        <span class="text-sm">Sangat Baik</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="filter_kategori" value="Baik"
                                            class="mr-1 text-blue-600" <?php echo $filter_kategori == 'Baik' ? 'checked' : ''; ?>>
                                        <span class="text-sm">Baik</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="filter_kategori" value="Cukup"
                                            class="mr-1 text-yellow-600" <?php echo $filter_kategori == 'Cukup' ? 'checked' : ''; ?>>
                                        <span class="text-sm">Cukup</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="filter_kategori" value="Kurang"
                                            class="mr-1 text-red-600" <?php echo $filter_kategori == 'Kurang' ? 'checked' : ''; ?>>
                                        <span class="text-sm">Kurang</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex justify-between">
                                <button type="submit" 
                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-filter mr-2"></i> Filter Data
                                </button>
                                
                                <?php if (!empty($search) || !empty($filter_siswa) || !empty($filter_guru) || !empty($filter_tingkat) || !empty($filter_bulan) || !empty($periode) || !empty($filter_kategori)): ?>
                                    <a href="dataNilai.php?tab=list&clear_filter=1" 
                                       class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Reset Filter
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($user_role == 'guru'): ?>
                                    <a href="?action=tambah&tab=list" 
                                       class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                        <i class="fas fa-plus mr-2"></i> Tambah Penilaian
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabel Penilaian -->
                <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                    <?php if (count($penilaian_data) > 0): ?>
                        <div class="table-responsive">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            No
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Siswa
                                        </th>
                                        <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Guru
                                        </th>
                                        <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Mata Pelajaran
                                        </th>
                                        <th scope="col" class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Tanggal
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Nilai
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Kategori
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($penilaian_data as $index => $penilaian): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $index + 1; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8">
                                                        <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                            <i class="fas fa-user-graduate text-blue-600 text-sm"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ml-2">
                                                        <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                            <?php echo htmlspecialchars($penilaian['nama_siswa']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500 truncate max-w-[120px]">
                                                            Kelas: <?php echo $penilaian['kelas_sekolah']; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm text-gray-900 truncate max-w-[120px]">
                                                    <?php echo htmlspecialchars($penilaian['nama_guru']); ?>
                                                </div>
                                            </td>
                                            <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($penilaian['nama_pelajaran'] ?? '-'); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo $penilaian['tingkat_bimbel']; ?>
                                                </div>
                                            </td>
                                            <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('d/m/Y', strtotime($penilaian['tanggal_penilaian'])); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo $penilaian['total_score'] ?? 0; ?>/50
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo $penilaian['persentase'] ?? 0; ?>%
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php
                                                $persentase = $penilaian['persentase'] ?? 0;
                                                $kategori_class = '';
                                                if ($persentase >= 80)
                                                    $kategori_class = 'sangat-baik';
                                                elseif ($persentase >= 60)
                                                    $kategori_class = 'baik';
                                                elseif ($persentase >= 40)
                                                    $kategori_class = 'cukup';
                                                elseif ($persentase > 0)
                                                    $kategori_class = 'kurang';
                                                else
                                                    $kategori_class = 'belum-dinilai';
                                                ?>
                                                <span class="badge <?php echo $kategori_class; ?>">
                                                    <?php echo getKategoriByPersentase($persentase); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="?action=detail&id=<?php echo $penilaian['id']; ?>&tab=list" 
                                                       class="text-blue-600 hover:text-blue-900 p-1" 
                                                       title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($user_role == 'admin'): ?>
                                                    <a href="#" 
                                                       onclick="confirmDelete(<?php echo $penilaian['id']; ?>, '<?php echo htmlspecialchars(addslashes($penilaian['nama_siswa'])); ?>')"
                                                       class="text-red-600 hover:text-red-900 p-1" 
                                                       title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Menampilkan <span class="font-medium"><?php echo count($penilaian_data); ?></span> dari
                                    <span class="font-medium"><?php echo $statistik['total_penilaian']; ?></span> hasil
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-clipboard-list text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">
                                Data penilaian tidak ditemukan
                            </h3>
                            <p class="text-gray-500 mb-4">
                                <?php if (!empty($search) || !empty($filter_siswa) || !empty($filter_guru) || !empty($filter_tingkat) || !empty($filter_bulan) || !empty($periode) || !empty($filter_kategori)): ?>
                                    Coba ubah filter pencarian atau
                                <?php endif; ?>
                                <?php if ($user_role == 'guru'): ?>
                                    tambahkan penilaian baru.
                                <?php endif; ?>
                            </p>
                            <?php if ($user_role == 'guru'): ?>
                                <a href="?action=tambah&tab=list" 
                                   class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-plus mr-2"></i> Tambah Penilaian
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Penilaian</p>
                    <p class="mt-1 text-xs text-gray-400">
                        <i class="fas fa-database mr-1"></i>
                        Total penilaian: <?php echo number_format($statistik['total_penilaian']); ?>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- Modal Detail Penilaian -->
    <?php if ($penilaian_detail): ?>
        <div id="detailModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-clipboard-list mr-2"></i> Detail Penilaian
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <!-- Informasi Siswa & Guru -->
                    <div class="grid-2 mb-6">
                        <div class="border rounded-lg p-4">
                            <h4 class="font-medium text-gray-900 mb-3 flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-2"></i> Informasi Siswa
                            </h4>
                            <div class="space-y-2">
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Nama</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($penilaian_detail['nama_siswa']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Kelas Sekolah</span>
                                    <span><?php echo $penilaian_detail['kelas_sekolah']; ?></span>
                                </div>
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Jenis Kelas Bimbel</span>
                                    <span><?php echo $penilaian_detail['jenis_kelas_bimbel']; ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="border rounded-lg p-4">
                            <h4 class="font-medium text-gray-900 mb-3 flex items-center">
                                <i class="fas fa-chalkboard-teacher text-green-500 mr-2"></i> Informasi Guru
                            </h4>
                            <div class="space-y-2">
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Nama Guru</span>
                                    <span class="font-medium"><?php echo htmlspecialchars($penilaian_detail['nama_guru']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Bidang Keahlian</span>
                                    <span><?php echo $penilaian_detail['bidang_keahlian'] ?? '-'; ?></span>
                                </div>
                                <div class="flex">
                                    <span class="w-32 text-gray-500 text-sm">Mata Pelajaran</span>
                                    <span><?php echo htmlspecialchars($penilaian_detail['nama_pelajaran']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ringkasan Nilai -->
                    <div class="mb-6">
                        <h4 class="font-medium text-gray-900 mb-4">Ringkasan Penilaian</h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-blue-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-blue-600">
                                    <?php echo $penilaian_detail['total_score'] ?? 0; ?>/50</div>
                                <div class="text-sm text-blue-500 mt-1">Total Skor</div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-green-600">
                                    <?php echo $penilaian_detail['persentase'] ?? 0; ?>%</div>
                                <div class="text-sm text-green-500 mt-1">Persentase</div>
                            </div>
                            <div class="bg-purple-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-purple-600">
                                    <?php echo $penilaian_detail['kategori_manual']; ?></div>
                                <div class="text-sm text-purple-500 mt-1">Kategori</div>
                            </div>
                            <div class="bg-yellow-50 rounded-lg p-4 text-center">
                                <div class="text-2xl font-bold text-yellow-600">
                                    <?php echo date('d M Y', strtotime($penilaian_detail['tanggal_penilaian'])); ?>
                                </div>
                                <div class="text-sm text-yellow-500 mt-1">Tanggal</div>
                            </div>
                        </div>
                    </div>

                    <!-- Detail Indikator -->
                    <div class="mb-6">
                        <h4 class="font-medium text-gray-900 mb-4">Nilai Per Indikator</h4>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <?php 
                            $indicators = [
                                'willingness_learn' => 'Kemauan Belajar',
                                'problem_solving' => 'Pemecahan Masalah',
                                'critical_thinking' => 'Berpikir Kritis',
                                'concentration' => 'Konsentrasi',
                                'independence' => 'Kemandirian'
                            ];
                            
                            foreach ($indicators as $key => $label):
                                $score = $penilaian_detail[$key] ?? 0;
                                $percentage = ($score / 10) * 100;
                                $color = $score >= 8 ? 'bg-green-500' : ($score >= 6 ? 'bg-blue-500' : ($score >= 4 ? 'bg-yellow-500' : ($score > 0 ? 'bg-red-500' : 'bg-gray-300')));
                            ?>
                                <div class="indicator-card">
                                    <div class="score"><?php echo $score; ?>/10</div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5 mt-2">
                                        <div class="h-2.5 rounded-full <?php echo $color; ?>"
                                            style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                    <div class="label"><?php echo $label; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Catatan dan Rekomendasi -->
                    <div class="mb-6">
                        <h4 class="font-medium text-gray-900 mb-4">Catatan & Rekomendasi</h4>
                        <div class="grid-2 gap-6">
                            <div>
                                <h5 class="text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-sticky-note text-yellow-500 mr-2"></i> Catatan Guru
                                </h5>
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm">
                                    <?php echo nl2br(htmlspecialchars($penilaian_detail['catatan_guru'] ?: 'Tidak ada catatan')); ?>
                                </div>
                            </div>
                            <div>
                                <h5 class="text-sm font-medium text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-lightbulb text-green-500 mr-2"></i> Rekomendasi
                                </h5>
                                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm">
                                    <?php echo nl2br(htmlspecialchars($penilaian_detail['rekomendasi'] ?: 'Tidak ada rekomendasi')); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeModal()"
                            class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            Tutup
                        </button>
                        <?php if ($user_role == 'admin' || ($user_role == 'guru' && $user_id == $penilaian_detail['guru_id'])): ?>
                        <a href="?action=edit&id=<?php echo $penilaian_detail['id']; ?>&tab=list"
                            class="px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700">
                            <i class="fas fa-edit mr-2"></i> Edit Data
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Mobile Menu Toggle
        const menuToggle = document.getElementById('menuToggle');
        const menuClose = document.getElementById('menuClose');
        const mobileMenu = document.getElementById('mobileMenu');
        const menuOverlay = document.getElementById('menuOverlay');

        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                mobileMenu.classList.add('menu-open');
                menuOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }

        if (menuClose) {
            menuClose.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        }

        if (menuOverlay) {
            menuOverlay.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        }

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownGroup = this.closest('.mb-1');
                const submenu = dropdownGroup.querySelector('.dropdown-submenu');
                const arrow = this.querySelector('.arrow');
                
                // Toggle current dropdown
                if (submenu.style.display === 'block') {
                    submenu.style.display = 'none';
                    arrow.style.transform = 'rotate(0deg)';
                    this.classList.remove('open');
                } else {
                    // Close other dropdowns
                    document.querySelectorAll('.dropdown-submenu').forEach(sm => {
                        sm.style.display = 'none';
                    });
                    document.querySelectorAll('.dropdown-toggle').forEach(t => {
                        t.classList.remove('open');
                        t.querySelector('.arrow').style.transform = 'rotate(0deg)';
                    });
                    
                    // Open this dropdown
                    submenu.style.display = 'block';
                    arrow.style.transform = 'rotate(-90deg)';
                    this.classList.add('open');
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mb-1')) {
                document.querySelectorAll('.dropdown-submenu').forEach(submenu => {
                    submenu.style.display = 'none';
                });
                document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
                    toggle.classList.remove('open');
                    toggle.querySelector('.arrow').style.transform = 'rotate(0deg)';
                });
            }
        });

        // Close menu when clicking on menu items (mobile)
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    mobileMenu.classList.remove('menu-open');
                    if (menuOverlay) {
                        menuOverlay.classList.remove('active');
                    }
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Fungsi untuk menutup modal
        function closeModal() {
            // Hilangkan parameter action dan id dari URL tanpa reload
            const url = new URL(window.location.href);
            url.searchParams.delete('action');
            url.searchParams.delete('id');
            url.searchParams.set('tab', 'list');

            // Update URL tanpa reload halaman
            window.history.replaceState({}, '', url.toString());

            // Sembunyikan modal dengan efek
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.animation = 'modalFadeOut 0.3s';
                setTimeout(() => {
                    modal.style.display = 'none';
                    // Reset animation
                    setTimeout(() => {
                        modal.style.animation = '';
                    }, 300);
                }, 250);
            });
        }

        // Tambahkan event listener untuk klik di luar modal
        document.addEventListener('DOMContentLoaded', function() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            });
            
            // Auto focus pada input pertama di modal
            <?php if (isset($_GET['action']) && ($_GET['action'] == 'tambah' || $_GET['action'] == 'edit' || $_GET['action'] == 'detail')): ?>
                const firstInput = document.querySelector('.modal input:not([type="hidden"]), .modal select');
                if (firstInput) {
                    firstInput.focus();
                }
            <?php endif; ?>
        });

        // Konfirmasi Hapus
        function confirmDelete(id, name) {
            if (confirm(`Apakah Anda yakin ingin menghapus penilaian untuk "${name}"?\n\nAksi ini tidak dapat dibatalkan!`)) {
                window.location.href = `dataNilai.php?action=hapus&id=${id}&tab=list`;
            }
        }

        // Auto-close modals on ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    closeModal();
                }
            }
        });

        // Tab Navigation
        function setActiveTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            const tabContent = document.getElementById('tab-' + tabName);
            if (tabContent) {
                tabContent.classList.add('active');
            }
            
            // Activate selected tab button
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                if (tabName === 'list' && button.textContent.includes('Daftar')) {
                    button.classList.add('active');
                } else if (tabName === 'stats' && button.textContent.includes('Statistik')) {
                    button.classList.add('active');
                }
            });
            
            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';

                            // Add error message
                            if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
                                const errorMsg = document.createElement('p');
                                errorMsg.className = 'error-message text-red-500 text-xs mt-1';
                                errorMsg.textContent = 'Field ini wajib diisi';
                                field.parentNode.appendChild(errorMsg);
                            }
                        } else {
                            field.style.borderColor = '#d1d5db';

                            // Remove error message
                            const errorMsg = field.parentNode.querySelector('.error-message');
                            if (errorMsg) {
                                errorMsg.remove();
                            }
                        }
                    });

                    // Validasi nilai 1-10
                    const numberFields = form.querySelectorAll('input[type="number"][min="1"][max="10"]');
                    numberFields.forEach(field => {
                        const value = parseInt(field.value);
                        if (value < 1 || value > 10) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                            
                            if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('error-message')) {
                                const errorMsg = document.createElement('p');
                                errorMsg.className = 'error-message text-red-500 text-xs mt-1';
                                errorMsg.textContent = 'Nilai harus antara 1-10';
                                field.parentNode.appendChild(errorMsg);
                            }
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Harap lengkapi semua field yang wajib diisi dengan benar!');
                    }
                });
            });
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>