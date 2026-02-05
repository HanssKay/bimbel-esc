<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL
$success_message = '';
$error_message = '';
$siswa_data = [];
$siswa_detail = null;
$siswa_edit = null;

// LIST MATA PELAJARAN
$mata_pelajaran_list = [
    'Matematika',
    'Bahasa Inggris',
    'Bahasa Indonesia',
    'Pendidikan Pancasila',
    'IPA',
    'IPS',
    'IPAS',
    'Seni Budaya',
    'PJOK',
    'PAI',
    'Fisika',
    'Kimia',
    'Biologi',
    'Geografi',
    'Ekonomi',
    'Sosiologi',
    'Sejarah',
    'Bahasa Mandarin',
    'Bahasa Jepang',
    'Bahasa Korea',
    'Akuntansi',
    'Manajemen',
    'Lainnya'
];

// AMBIL PESAN DARI SESSION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// FILTER - Simpan filter untuk digunakan kembali
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_kelas = isset($_GET['filter_kelas']) ? $_GET['filter_kelas'] : '';
$filter_tingkat = isset($_GET['filter_tingkat']) ? $_GET['filter_tingkat'] : '';
$filter_jenis_kelamin = isset($_GET['filter_jenis_kelamin']) ? $_GET['filter_jenis_kelamin'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_program = isset($_GET['filter_program']) ? $_GET['filter_program'] : '';

// FUNGSI UNTUK AMBIL DAFTAR ORANGTUA
function getDaftarOrangtua($conn)
{
    $sql = "SELECT o.id, o.nama_ortu, o.email, o.no_hp,
                   COUNT(so.siswa_id) as jumlah_anak
            FROM orangtua o
            LEFT JOIN siswa_orangtua so ON o.id = so.orangtua_id
            GROUP BY o.id
            ORDER BY o.nama_ortu";

    $result = $conn->query($sql);
    $orangtua_list = [];
    while ($row = $result->fetch_assoc()) {
        $orangtua_list[] = $row;
    }
    return $orangtua_list;
}

// DETAIL SISWA - UPDATE QUERY UNTUK MULTIPLE ORANGTUA
if (isset($_GET['action']) && $_GET['action'] == 'detail' && isset($_GET['id'])) {
    $siswa_id = intval($_GET['id']);

    // Query baru untuk multiple orangtua
    $sql = "SELECT s.*, 
                   GROUP_CONCAT(DISTINCT o.nama_ortu SEPARATOR ', ') as nama_ortu_list,
                   GROUP_CONCAT(DISTINCT o.email SEPARATOR ', ') as email_ortu_list,
                   GROUP_CONCAT(DISTINCT o.no_hp SEPARATOR ', ') as no_hp_list,
                   GROUP_CONCAT(DISTINCT o.pekerjaan SEPARATOR ', ') as pekerjaan_list,
                   GROUP_CONCAT(DISTINCT o.perusahaan SEPARATOR ', ') as perusahaan_list,
                   GROUP_CONCAT(DISTINCT o.hubungan_dengan_siswa SEPARATOR ', ') as hubungan_list
            FROM siswa s
            LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
            LEFT JOIN orangtua o ON so.orangtua_id = o.id
            WHERE s.id = ?
            GROUP BY s.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Ambil data pendaftaran yang aktif
        $pendaftaran_sql = "SELECT ps.* 
                           FROM pendaftaran_siswa ps
                           WHERE ps.siswa_id = ? AND ps.status = 'aktif'
                           ORDER BY ps.created_at DESC";

        $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
        $pendaftaran_stmt->bind_param("i", $siswa_id);
        $pendaftaran_stmt->execute();
        $pendaftaran_result = $pendaftaran_stmt->get_result();

        $pendaftaran_list = [];
        while ($pendaftaran_row = $pendaftaran_result->fetch_assoc()) {
            // Ambil mata pelajaran untuk setiap pendaftaran
            $mata_pelajaran_sql = "SELECT sp.*, u.full_name as nama_guru
                                   FROM siswa_pelajaran sp
                                   LEFT JOIN guru g ON sp.guru_id = g.id
                                   LEFT JOIN users u ON g.user_id = u.id
                                   WHERE sp.pendaftaran_id = ? AND sp.status = 'aktif'";
            $mata_pelajaran_stmt = $conn->prepare($mata_pelajaran_sql);
            $mata_pelajaran_stmt->bind_param("i", $pendaftaran_row['id']);
            $mata_pelajaran_stmt->execute();
            $mata_pelajaran_result = $mata_pelajaran_stmt->get_result();

            $pendaftaran_row['mata_pelajaran_list'] = [];
            while ($mapel = $mata_pelajaran_result->fetch_assoc()) {
                $pendaftaran_row['mata_pelajaran_list'][] = $mapel;
            }
            $mata_pelajaran_stmt->close();

            $pendaftaran_list[] = $pendaftaran_row;
        }
        $pendaftaran_stmt->close();

        $row['pendaftaran_list'] = $pendaftaran_list;
        $siswa_detail = $row;
    }
    $stmt->close();
}

// EDIT SISWA - LOAD DATA DENGAN ORANGTUA MULTIPLE
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $siswa_id = intval($_GET['id']);

    // Ambil data siswa
    $sql = "SELECT s.* FROM siswa s WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Ambil semua orangtua untuk siswa ini
        $orangtua_sql = "SELECT o.id, o.nama_ortu, o.email, o.no_hp, o.pekerjaan, o.perusahaan, o.hubungan_dengan_siswa,
                         u.id as user_id
                        FROM siswa_orangtua so
                        JOIN orangtua o ON so.orangtua_id = o.id
                        LEFT JOIN users u ON o.user_id = u.id
                        WHERE so.siswa_id = ?";
        $orangtua_stmt = $conn->prepare($orangtua_sql);
        $orangtua_stmt->bind_param("i", $siswa_id);
        $orangtua_stmt->execute();
        $orangtua_result = $orangtua_stmt->get_result();

        $orangtua_list = [];
        while ($ortu = $orangtua_result->fetch_assoc()) {
            $orangtua_list[] = $ortu;
        }
        $orangtua_stmt->close();

        $row['orangtua_list'] = $orangtua_list;

        // Ambil data pendaftaran yang aktif
        $pendaftaran_sql = "SELECT * FROM pendaftaran_siswa 
                           WHERE siswa_id = ? AND status = 'aktif' LIMIT 1";
        $pendaftaran_stmt = $conn->prepare($pendaftaran_sql);
        $pendaftaran_stmt->bind_param("i", $siswa_id);
        $pendaftaran_stmt->execute();
        $pendaftaran_result = $pendaftaran_stmt->get_result();
        $current_pendaftaran = $pendaftaran_result->fetch_assoc();
        $pendaftaran_stmt->close();

        // Ambil mata pelajaran untuk pendaftaran ini
        if ($current_pendaftaran) {
            $mata_pelajaran_sql = "SELECT nama_pelajaran FROM siswa_pelajaran 
                                   WHERE pendaftaran_id = ? AND status = 'aktif'";
            $mata_pelajaran_stmt = $conn->prepare($mata_pelajaran_sql);
            $mata_pelajaran_stmt->bind_param("i", $current_pendaftaran['id']);
            $mata_pelajaran_stmt->execute();
            $mata_pelajaran_result = $mata_pelajaran_stmt->get_result();

            $selected_mata_pelajaran = [];
            while ($mapel = $mata_pelajaran_result->fetch_assoc()) {
                $selected_mata_pelajaran[] = $mapel['nama_pelajaran'];
            }
            $mata_pelajaran_stmt->close();

            $current_pendaftaran['selected_mata_pelajaran'] = $selected_mata_pelajaran;
        }

        $row['current_pendaftaran'] = $current_pendaftaran;
        $siswa_edit = $row;
    }
    $stmt->close();
}

// UPDATE SISWA - EDIT DATA SISWA DAN ORANGTUA YANG SUDAH ADA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_siswa'])) {
    $siswa_id = intval($_POST['siswa_id']);
    $nama_lengkap = trim($_POST['nama_lengkap']);
    $tempat_lahir = trim($_POST['tempat_lahir']);
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $agama = trim($_POST['agama'] ?? '');
    $alamat = trim($_POST['alamat']);
    $sekolah_asal = trim($_POST['sekolah_asal']);
    $kelas_sekolah = $_POST['kelas_sekolah'];
    $status_siswa = $_POST['status_siswa'] ?? 'aktif';
    
    // Data pendaftaran
    $jenis_kelas = $_POST['jenis_kelas'] ?? '';
    $mata_pelajaran = isset($_POST['mata_pelajaran']) ? $_POST['mata_pelajaran'] : [];
    $tingkat = $_POST['tingkat'] ?? 'SMP';
    $tahun_ajaran = $_POST['tahun_ajaran'] ?? '2025/2026';
    
    // Data orangtua yang sudah ada (diubah dari form)
    $orangtua_ids = $_POST['orangtua_id'] ?? [];
    $orangtua_nama = $_POST['orangtua_nama'] ?? [];
    $orangtua_no_hp = $_POST['orangtua_no_hp'] ?? [];
    $orangtua_email = $_POST['orangtua_email'] ?? [];
    $orangtua_pekerjaan = $_POST['orangtua_pekerjaan'] ?? [];
    $orangtua_perusahaan = $_POST['orangtua_perusahaan'] ?? [];
    $orangtua_hubungan = $_POST['orangtua_hubungan'] ?? [];
    $orangtua_passwords = $_POST['orangtua_password'] ?? [];
    
    // Data tambah orangtua lain (opsional)
    $tambah_orangtua_id = $_POST['tambah_orangtua_id'] ?? null;

    // Validasi
    $errors = [];
    if (empty($nama_lengkap))
        $errors[] = "Nama lengkap harus diisi!";
    
    if (!empty($jenis_kelas) && empty($mata_pelajaran))
        $errors[] = "Mata pelajaran harus dipilih minimal satu!";
    
    // Validasi data orangtua
    if (!empty($orangtua_ids)) {
        foreach ($orangtua_ids as $index => $ortu_id) {
            if (empty($orangtua_nama[$index]))
                $errors[] = "Nama orangtua ke-" . ($index + 1) . " harus diisi!";
            if (empty($orangtua_email[$index]))
                $errors[] = "Email orangtua ke-" . ($index + 1) . " harus diisi!";
            if (empty($orangtua_no_hp[$index]))
                $errors[] = "No. HP orangtua ke-" . ($index + 1) . " harus diisi!";
                
            // Validasi password jika diisi
            if (!empty($orangtua_passwords[$ortu_id]) && strlen($orangtua_passwords[$ortu_id]) < 6) {
                $errors[] = "Password orangtua ke-" . ($index + 1) . " minimal 6 karakter!";
            }
            
            // Validasi format email
            if (!empty($orangtua_email[$index]) && !filter_var($orangtua_email[$index], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Format email orangtua ke-" . ($index + 1) . " tidak valid!";
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION['error_message'] = "❌ " . implode(" ", $errors);
        
        $redirect_url = "dataSiswa.php?action=edit&id=" . $siswa_id;
        if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
            $redirect_url .= "&search=" . urlencode($search) .
                "&filter_kelas=" . urlencode($filter_kelas) .
                "&filter_tingkat=" . urlencode($filter_tingkat) .
                "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                "&filter_status=" . urlencode($filter_status) .
                "&filter_program=" . urlencode($filter_program);
        }
        header('Location: ' . $redirect_url);
        exit();
    }

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // 1. Update data siswa
        $update_sql = "UPDATE siswa SET 
                      nama_lengkap = ?,
                      tempat_lahir = ?,
                      tanggal_lahir = ?,
                      jenis_kelamin = ?,
                      agama = ?,
                      alamat = ?,
                      sekolah_asal = ?,
                      kelas = ?,
                      status = ?,
                      updated_at = NOW()
                      WHERE id = ?";

        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param(
            "sssssssssi",
            $nama_lengkap,
            $tempat_lahir,
            $tanggal_lahir,
            $jenis_kelamin,
            $agama,
            $alamat,
            $sekolah_asal,
            $kelas_sekolah,
            $status_siswa,
            $siswa_id
        );
        $stmt->execute();
        $stmt->close();

        // 2. Update data orangtua yang sudah ada
        if (!empty($orangtua_ids)) {
            foreach ($orangtua_ids as $index => $ortu_id) {
                if (!empty($ortu_id)) {
                    // Cek apakah email berubah dan sudah digunakan oleh user lain
                    $check_email_sql = "SELECT o.id FROM orangtua o 
                                       WHERE o.email = ? AND o.id != ?";
                    $check_email_stmt = $conn->prepare($check_email_sql);
                    $check_email_stmt->bind_param("si", $orangtua_email[$index], $ortu_id);
                    $check_email_stmt->execute();
                    
                    if ($check_email_stmt->get_result()->num_rows > 0) {
                        throw new Exception("Email '" . $orangtua_email[$index] . "' sudah digunakan oleh orangtua lain!");
                    }
                    $check_email_stmt->close();
                    
                    // Update data orangtua
                    $update_ortu_sql = "UPDATE orangtua SET 
                                       nama_ortu = ?,
                                       no_hp = ?,
                                       email = ?,
                                       pekerjaan = ?,
                                       perusahaan = ?,
                                       hubungan_dengan_siswa = ?
                                       WHERE id = ?";
                    $update_ortu_stmt = $conn->prepare($update_ortu_sql);
                    $update_ortu_stmt->bind_param(
                        "ssssssi",
                        $orangtua_nama[$index],
                        $orangtua_no_hp[$index],
                        $orangtua_email[$index],
                        $orangtua_pekerjaan[$index],
                        $orangtua_perusahaan[$index],
                        $orangtua_hubungan[$index],
                        $ortu_id
                    );
                    $update_ortu_stmt->execute();
                    $update_ortu_stmt->close();
                    
                    // Update data user (username, full_name, phone, email)
                    $update_user_sql = "UPDATE users u 
                                       JOIN orangtua o ON u.id = o.user_id
                                       SET u.username = ?,
                                           u.full_name = ?,
                                           u.phone = ?,
                                           u.email = ?,
                                           u.updated_at = NOW()
                                       WHERE o.id = ?";
                    $username = explode('@', $orangtua_email[$index])[0];
                    $update_user_stmt = $conn->prepare($update_user_sql);
                    $update_user_stmt->bind_param(
                        "ssssi",
                        $username,
                        $orangtua_nama[$index],
                        $orangtua_no_hp[$index],
                        $orangtua_email[$index],
                        $ortu_id
                    );
                    $update_user_stmt->execute();
                    $update_user_stmt->close();
                    
                    // Update password jika diisi
                    if (!empty($orangtua_passwords[$ortu_id])) {
                        $new_password = trim($orangtua_passwords[$ortu_id]);
                        if (strlen($new_password) >= 6) {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            
                            $update_password_sql = "UPDATE users u 
                                                   JOIN orangtua o ON u.id = o.user_id
                                                   SET u.password = ?, u.updated_at = NOW()
                                                   WHERE o.id = ?";
                            $update_password_stmt = $conn->prepare($update_password_sql);
                            $update_password_stmt->bind_param("si", $hashed_password, $ortu_id);
                            $update_password_stmt->execute();
                            $update_password_stmt->close();
                        }
                    }
                }
            }
        }
        
        // 3. Tambah orangtua lain jika dipilih (opsional)
        if (!empty($tambah_orangtua_id)) {
            // Cek apakah sudah terhubung dengan siswa ini
            $check_connection_sql = "SELECT COUNT(*) as count FROM siswa_orangtua 
                                   WHERE siswa_id = ? AND orangtua_id = ?";
            $check_connection_stmt = $conn->prepare($check_connection_sql);
            $check_connection_stmt->bind_param("ii", $siswa_id, $tambah_orangtua_id);
            $check_connection_stmt->execute();
            $check_connection_result = $check_connection_stmt->get_result();
            $is_connected = $check_connection_result->fetch_assoc()['count'] > 0;
            $check_connection_stmt->close();
            
            // Hanya tambah jika belum terhubung
            if (!$is_connected) {
                $insert_hubungan = "INSERT INTO siswa_orangtua (siswa_id, orangtua_id) VALUES (?, ?)";
                $insert_stmt = $conn->prepare($insert_hubungan);
                $insert_stmt->bind_param("ii", $siswa_id, $tambah_orangtua_id);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }

        // 4. Update atau tambah data pendaftaran
        if (!empty($jenis_kelas)) {
            // Cek apakah sudah ada pendaftaran aktif
            $check_pendaftaran_sql = "SELECT id FROM pendaftaran_siswa WHERE siswa_id = ? AND status = 'aktif' LIMIT 1";
            $check_pendaftaran_stmt = $conn->prepare($check_pendaftaran_sql);
            $check_pendaftaran_stmt->bind_param("i", $siswa_id);
            $check_pendaftaran_stmt->execute();
            $pendaftaran_result = $check_pendaftaran_stmt->get_result();
            $pendaftaran_row = $pendaftaran_result->fetch_assoc();
            $has_pendaftaran = ($pendaftaran_row != null);
            $check_pendaftaran_stmt->close();

            if ($has_pendaftaran) {
                // Update pendaftaran yang sudah ada
                $pendaftaran_id = $pendaftaran_row['id'];
                
                $update_pendaftaran_sql = "UPDATE pendaftaran_siswa SET 
                                          jenis_kelas = ?,
                                          tingkat = ?,
                                          tahun_ajaran = ?,
                                          updated_at = NOW()
                                          WHERE id = ?";
                $update_pendaftaran_stmt = $conn->prepare($update_pendaftaran_sql);
                $update_pendaftaran_stmt->bind_param("sssi", $jenis_kelas, $tingkat, $tahun_ajaran, $pendaftaran_id);
                $update_pendaftaran_stmt->execute();
                $update_pendaftaran_stmt->close();
                
                // Hapus mata pelajaran lama
                $delete_mata_pelajaran_sql = "DELETE FROM siswa_pelajaran WHERE pendaftaran_id = ?";
                $delete_mata_pelajaran_stmt = $conn->prepare($delete_mata_pelajaran_sql);
                $delete_mata_pelajaran_stmt->bind_param("i", $pendaftaran_id);
                $delete_mata_pelajaran_stmt->execute();
                $delete_mata_pelajaran_stmt->close();
                
            } else {
                // Tambah pendaftaran baru
                $insert_pendaftaran_sql = "INSERT INTO pendaftaran_siswa (siswa_id, jenis_kelas, tingkat, tahun_ajaran, status, tanggal_mulai, created_at)
                                         VALUES (?, ?, ?, ?, 'aktif', CURDATE(), NOW())";
                $insert_pendaftaran_stmt = $conn->prepare($insert_pendaftaran_sql);
                $insert_pendaftaran_stmt->bind_param("isss", $siswa_id, $jenis_kelas, $tingkat, $tahun_ajaran);
                $insert_pendaftaran_stmt->execute();
                $pendaftaran_id = $conn->insert_id;
                $insert_pendaftaran_stmt->close();
            }
            
            // Tambah mata pelajaran baru
            if (!empty($mata_pelajaran) && isset($pendaftaran_id)) {
                foreach ($mata_pelajaran as $mapel) {
                    if (!empty(trim($mapel))) {
                        $insert_mapel_sql = "INSERT INTO siswa_pelajaran (siswa_id, pendaftaran_id, nama_pelajaran, status, created_at)
                                            VALUES (?, ?, ?, 'aktif', NOW())";
                        $insert_mapel_stmt = $conn->prepare($insert_mapel_sql);
                        $insert_mapel_stmt->bind_param("iis", $siswa_id, $pendaftaran_id, $mapel);
                        $insert_mapel_stmt->execute();
                        $insert_mapel_stmt->close();
                    }
                }
            }
        }

        $conn->commit();
        
        // Pesan sukses berdasarkan perubahan
        $message = "✅ Data siswa berhasil diperbarui!";
        if (!empty($orangtua_passwords) && count(array_filter($orangtua_passwords)) > 0) {
            $message .= " Password orangtua juga telah diperbarui.";
        }
        if (!empty($tambah_orangtua_id)) {
            $message .= " Orangtua tambahan telah ditambahkan.";
        }
        
        $_SESSION['success_message'] = $message;
        
        // Redirect ke detail dengan filter yang ada
        $redirect_url = "dataSiswa.php?action=detail&id=" . $siswa_id;
        if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
            $redirect_url .= "&search=" . urlencode($search) .
                "&filter_kelas=" . urlencode($filter_kelas) .
                "&filter_tingkat=" . urlencode($filter_tingkat) .
                "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                "&filter_status=" . urlencode($filter_status) .
                "&filter_program=" . urlencode($filter_program);
        }
        header('Location: ' . $redirect_url);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal memperbarui data siswa: " . $e->getMessage();
        
        // Redirect dengan parameter filter yang ada
        $redirect_url = "dataSiswa.php?action=edit&id=" . $siswa_id;
        if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
            $redirect_url .= "&search=" . urlencode($search) .
                "&filter_kelas=" . urlencode($filter_kelas) .
                "&filter_tingkat=" . urlencode($filter_tingkat) .
                "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                "&filter_status=" . urlencode($filter_status) .
                "&filter_program=" . urlencode($filter_program);
        }
        header('Location: ' . $redirect_url);
        exit();
    }
}

// HAPUS SISWA - UPDATE UNTUK HAPUS HUBUNGAN
if (isset($_GET['action']) && $_GET['action'] == 'hapus' && isset($_GET['id'])) {
    $siswa_id = intval($_GET['id']);

    // Cek apakah siswa memiliki penilaian
    $check_sql = "SELECT COUNT(*) as total FROM penilaian_siswa WHERE siswa_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $siswa_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $has_penilaian = $result->fetch_assoc()['total'] > 0;
    $check_stmt->close();

    if ($has_penilaian) {
        $_SESSION['error_message'] = "❌ Tidak dapat menghapus siswa yang sudah memiliki data penilaian!";
        header('Location: dataSiswa.php');
        exit();
    }

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // 1. Hapus dari siswa_orangtua
        $sql_hubungan = "DELETE FROM siswa_orangtua WHERE siswa_id = ?";
        $stmt_hubungan = $conn->prepare($sql_hubungan);
        $stmt_hubungan->bind_param("i", $siswa_id);
        $stmt_hubungan->execute();
        $stmt_hubungan->close();

        // 2. Hapus mata pelajaran siswa
        $sql_mata_pelajaran = "DELETE FROM siswa_pelajaran WHERE siswa_id = ?";
        $stmt_mata_pelajaran = $conn->prepare($sql_mata_pelajaran);
        $stmt_mata_pelajaran->bind_param("i", $siswa_id);
        $stmt_mata_pelajaran->execute();
        $stmt_mata_pelajaran->close();

        // 3. Hapus dari pendaftaran_siswa
        $sql_pendaftaran = "DELETE FROM pendaftaran_siswa WHERE siswa_id = ?";
        $stmt_pendaftaran = $conn->prepare($sql_pendaftaran);
        $stmt_pendaftaran->bind_param("i", $siswa_id);
        $stmt_pendaftaran->execute();
        $stmt_pendaftaran->close();

        // 4. Hapus dari absensi_siswa
        $sql_absensi = "DELETE FROM absensi_siswa WHERE siswa_id = ?";
        $stmt_absensi = $conn->prepare($sql_absensi);
        $stmt_absensi->bind_param("i", $siswa_id);
        $stmt_absensi->execute();
        $stmt_absensi->close();

        // 5. Hapus dari pembayaran
        $sql_pembayaran = "DELETE p FROM pembayaran p 
                          INNER JOIN pendaftaran_siswa ps ON p.pendaftaran_id = ps.id 
                          WHERE ps.siswa_id = ?";
        $stmt_pembayaran = $conn->prepare($sql_pembayaran);
        $stmt_pembayaran->bind_param("i", $siswa_id);
        $stmt_pembayaran->execute();
        $stmt_pembayaran->close();

        // 6. Hapus siswa
        $sql_siswa = "DELETE FROM siswa WHERE id = ?";
        $stmt_siswa = $conn->prepare($sql_siswa);
        $stmt_siswa->bind_param("i", $siswa_id);
        $stmt_siswa->execute();
        $stmt_siswa->close();

        $conn->commit();
        $_SESSION['success_message'] = "✅ Data siswa berhasil dihapus!";

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal menghapus data siswa: " . $e->getMessage();
    }

    header('Location: dataSiswa.php');
    exit();
}

// TAMBAH SISWA BARU - UPDATE UNTUK MULTIPLE ORANGTUA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah_siswa'])) {
    $nama_lengkap = trim($_POST['nama_lengkap']);
    $tempat_lahir = trim($_POST['tempat_lahir']);
    $tanggal_lahir = $_POST['tanggal_lahir'];
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $agama = trim($_POST['agama'] ?? '');
    $alamat = trim($_POST['alamat']);
    $sekolah_asal = trim($_POST['sekolah_asal']);
    $kelas_sekolah = $_POST['kelas_sekolah'];
    $status_siswa = $_POST['status_siswa'] ?? 'aktif';

    // Data pendaftaran
    $jenis_kelas = $_POST['jenis_kelas'] ?? '';
    $mata_pelajaran = isset($_POST['mata_pelajaran']) ? $_POST['mata_pelajaran'] : [];
    $tingkat = $_POST['tingkat'] ?? 'SMP';
    $tahun_ajaran = $_POST['tahun_ajaran'] ?? '2025/2026';

    // Data orangtua - mode
    $mode_ortu = $_POST['mode_ortu'] ?? 'existing'; // 'baru' atau 'existing'
    $orangtua_existing_ids = isset($_POST['orangtua_existing_id']) ? $_POST['orangtua_existing_id'] : [];
    if (!is_array($orangtua_existing_ids)) {
        $orangtua_existing_ids = [$orangtua_existing_ids];
    }

    // Data orangtua baru (jika mode baru)
    $nama_ortu = trim($_POST['nama_ortu'] ?? '');
    $no_hp_ortu = trim($_POST['no_hp_ortu'] ?? '');
    $email_ortu = trim($_POST['email_ortu'] ?? '');
    $password_ortu = $_POST['password_ortu'] ?? '';
    $pekerjaan_ortu = trim($_POST['pekerjaan_ortu'] ?? '');
    $perusahaan_ortu = trim($_POST['perusahaan_ortu'] ?? '');
    $hubungan_ortu = $_POST['hubungan_ortu'] ?? 'wali';

    // Validasi
    $errors = [];
    if (empty($nama_lengkap))
        $errors[] = "Nama lengkap harus diisi!";

    if (empty($jenis_kelas))
        $errors[] = "Jenis kelas (Excellent/Champion) harus dipilih!";

    if (empty($mata_pelajaran))
        $errors[] = "Mata pelajaran harus dipilih minimal satu!";

    if ($mode_ortu == 'baru') {
        if (empty($nama_ortu))
            $errors[] = "Nama orangtua harus diisi untuk orangtua baru!";
        if (empty($email_ortu))
            $errors[] = "Email orangtua harus diisi untuk orangtua baru!";
        if (empty($password_ortu))
            $errors[] = "Password harus diisi untuk orangtua baru!";
        if (!empty($password_ortu) && strlen($password_ortu) < 6) {
            $errors[] = "Password minimal 6 karakter!";
        }
    } elseif ($mode_ortu == 'existing' && (empty($orangtua_existing_ids) || empty(array_filter($orangtua_existing_ids)))) {
        $errors[] = "Pilih minimal satu orangtua yang sudah terdaftar!";
    }

    if (!empty($errors)) {
        $_SESSION['error_message'] = "❌ " . implode(" ", $errors);
        header('Location: dataSiswa.php?action=tambah');
        exit();
    }

    // Mulai transaksi
    $conn->begin_transaction();

    try {
        // 1. Insert siswa (tanpa orangtua_id di tabel siswa)
        $insert_sql = "INSERT INTO siswa (nama_lengkap, tempat_lahir, tanggal_lahir, 
                      jenis_kelamin, agama, alamat, sekolah_asal, kelas, 
                      status, created_at, updated_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param(
            "sssssssss",
            $nama_lengkap,
            $tempat_lahir,
            $tanggal_lahir,
            $jenis_kelamin,
            $agama,
            $alamat,
            $sekolah_asal,
            $kelas_sekolah,
            $status_siswa
        );
        $stmt->execute();
        $siswa_id = $conn->insert_id;
        $stmt->close();

        // 2. Handle orangtua berdasarkan mode
        if ($mode_ortu == 'existing' && !empty(array_filter($orangtua_existing_ids))) {
            // 2A. Pakai orangtua yang sudah ada
            foreach ($orangtua_existing_ids as $orangtua_id) {
                if (!empty($orangtua_id)) {
                    $insert_hubungan = "INSERT INTO siswa_orangtua (siswa_id, orangtua_id) VALUES (?, ?)";
                    $stmt_hubungan = $conn->prepare($insert_hubungan);
                    $stmt_hubungan->bind_param("ii", $siswa_id, $orangtua_id);
                    $stmt_hubungan->execute();
                    $stmt_hubungan->close();
                }
            }

        } elseif ($mode_ortu == 'baru' && !empty($nama_ortu) && !empty($email_ortu) && !empty($password_ortu)) {
            // 2B. Buat orangtua baru
            // Cek email sudah terdaftar
            $check_email = "SELECT id FROM users WHERE email = ?";
            $stmt_check = $conn->prepare($check_email);
            $stmt_check->bind_param("s", $email_ortu);
            $stmt_check->execute();

            if ($stmt_check->get_result()->num_rows > 0) {
                throw new Exception("Email '$email_ortu' sudah terdaftar!");
            }
            $stmt_check->close();

            // Buat user untuk orangtua
            $username = explode('@', $email_ortu)[0];
            $hashed_password = password_hash($password_ortu, PASSWORD_DEFAULT);

            $user_sql = "INSERT INTO users (username, password, email, role, full_name, phone, is_active, created_at)
                       VALUES (?, ?, ?, 'orangtua', ?, ?, 1, NOW())";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("sssss", $username, $hashed_password, $email_ortu, $nama_ortu, $no_hp_ortu);
            $user_stmt->execute();
            $user_id = $conn->insert_id;
            $user_stmt->close();

            // Buat data orangtua
            $ortu_sql = "INSERT INTO orangtua (user_id, nama_ortu, no_hp, email, pekerjaan, perusahaan, hubungan_dengan_siswa, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $ortu_stmt = $conn->prepare($ortu_sql);
            $ortu_stmt->bind_param("issssss", $user_id, $nama_ortu, $no_hp_ortu, $email_ortu, $pekerjaan_ortu, $perusahaan_ortu, $hubungan_ortu);
            $ortu_stmt->execute();
            $orangtua_id = $conn->insert_id;
            $ortu_stmt->close();

            // Tambah hubungan di tabel baru
            $insert_hubungan = "INSERT INTO siswa_orangtua (siswa_id, orangtua_id) VALUES (?, ?)";
            $stmt_hubungan = $conn->prepare($insert_hubungan);
            $stmt_hubungan->bind_param("ii", $siswa_id, $orangtua_id);
            $stmt_hubungan->execute();
            $stmt_hubungan->close();
        }

        // 3. Tambah data pendaftaran dan mata pelajaran
        if (!empty($jenis_kelas)) {
            // Tambah data pendaftaran
            $insert_pendaftaran_sql = "INSERT INTO pendaftaran_siswa (siswa_id, jenis_kelas, tingkat, tahun_ajaran, status, tanggal_mulai, created_at)
                                     VALUES (?, ?, ?, ?, 'aktif', CURDATE(), NOW())";
            $insert_pendaftaran_stmt = $conn->prepare($insert_pendaftaran_sql);
            $insert_pendaftaran_stmt->bind_param("isss", $siswa_id, $jenis_kelas, $tingkat, $tahun_ajaran);
            $insert_pendaftaran_stmt->execute();
            $pendaftaran_id = $conn->insert_id;
            $insert_pendaftaran_stmt->close();

            // Tambah mata pelajaran
            foreach ($mata_pelajaran as $mapel) {
                $insert_mapel_sql = "INSERT INTO siswa_pelajaran (siswa_id, pendaftaran_id, nama_pelajaran, status, created_at)
                                    VALUES (?, ?, ?, 'aktif', NOW())";
                $insert_mapel_stmt = $conn->prepare($insert_mapel_sql);
                $insert_mapel_stmt->bind_param("iis", $siswa_id, $pendaftaran_id, $mapel);
                $insert_mapel_stmt->execute();
                $insert_mapel_stmt->close();
            }
        }

        $conn->commit();

        $pesan_sukses = "✅ Data siswa berhasil ditambahkan!";
        if ($mode_ortu == 'baru' && !empty($nama_ortu)) {
            $pesan_sukses .= " Akun orangtua juga telah dibuat.";
        }

        $_SESSION['success_message'] = $pesan_sukses;

        // Redirect ke detail dengan filter yang ada
        $redirect_url = "dataSiswa.php?action=detail&id=" . $siswa_id;
        if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
            $redirect_url .= "&search=" . urlencode($search) .
                "&filter_kelas=" . urlencode($filter_kelas) .
                "&filter_tingkat=" . urlencode($filter_tingkat) .
                "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                "&filter_status=" . urlencode($filter_status) .
                "&filter_program=" . urlencode($filter_program);
        }
        header('Location: ' . $redirect_url);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "❌ Gagal menambahkan data siswa: " . $e->getMessage();
        header('Location: dataSiswa.php?action=tambah');
        exit();
    }
}

// AMBIL DATA SISWA DENGAN FILTER - UPDATE QUERY UNTUK MULTIPLE ORANGTUA
$sql = "SELECT s.*, 
               GROUP_CONCAT(DISTINCT o.nama_ortu ORDER BY o.nama_ortu) as nama_ortu_list,
               GROUP_CONCAT(DISTINCT o.id) as orangtua_ids,
               COUNT(DISTINCT ps.id) as jumlah_program,
               COUNT(DISTINCT pns.id) as jumlah_penilaian,
               GROUP_CONCAT(DISTINCT ps.tingkat) as tingkat_bimbel,
               GROUP_CONCAT(DISTINCT ps.jenis_kelas) as jenis_kelas
        FROM siswa s
        LEFT JOIN siswa_orangtua so ON s.id = so.siswa_id
        LEFT JOIN orangtua o ON so.orangtua_id = o.id
        LEFT JOIN pendaftaran_siswa ps ON s.id = ps.siswa_id AND ps.status = 'aktif'
        LEFT JOIN penilaian_siswa pns ON s.id = pns.siswa_id
        WHERE 1=1";

$params = [];
$param_types = "";
$conditions = [];

if (!empty($search)) {
    $conditions[] = "(s.nama_lengkap LIKE ? OR o.nama_ortu LIKE ? OR s.agama LIKE ? OR s.sekolah_asal LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ssss";
}

if (!empty($filter_kelas)) {
    $conditions[] = "s.kelas = ?";
    $params[] = $filter_kelas;
    $param_types .= "s";
}

if (!empty($filter_tingkat)) {
    $conditions[] = "ps.tingkat = ?";
    $params[] = $filter_tingkat;
    $param_types .= "s";
}

if (!empty($filter_jenis_kelamin)) {
    $conditions[] = "s.jenis_kelamin = ?";
    $params[] = $filter_jenis_kelamin;
    $param_types .= "s";
}

if (!empty($filter_status)) {
    $conditions[] = "s.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

if (!empty($filter_program)) {
    $conditions[] = "ps.jenis_kelas = ?";
    $params[] = $filter_program;
    $param_types .= "s";
}

if (count($conditions) > 0) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " GROUP BY s.id ORDER BY s.nama_lengkap";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $siswa_data[] = $row;
    }
    $stmt->close();
}

// Hitung statistik siswa
$stats_sql = "SELECT 
              COUNT(*) as total_siswa,
              SUM(CASE WHEN jenis_kelamin = 'L' THEN 1 ELSE 0 END) as laki_laki,
              SUM(CASE WHEN jenis_kelamin = 'P' THEN 1 ELSE 0 END) as perempuan,
              SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif,
              SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif,
              SUM(CASE WHEN status = 'alumni' THEN 1 ELSE 0 END) as alumni
              FROM siswa";
$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $statistik = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

// Ambil daftar orangtua untuk dropdown
$orangtua_list = getDaftarOrangtua($conn);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Siswa - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            animation: fadeIn 0.3s;
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
            position: relative;
            z-index: 1101;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        /* Dropdown styles */
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
            transform: rotate(90deg);
        }

        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
        }

        .modal-header.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .modal-header.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .modal-header.yellow {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .modal-body {
            padding: 24px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            display: flex;
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

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-nonaktif {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-alumni {
            background-color: #dbeafe;
            color: #1e40af;
        }

        /* Program badge */
        .program-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }

        .program-excellent {
            background-color: #fef3c7;
            color: #92400e;
        }

        .program-champion {
            background-color: #dbeafe;
            color: #1e40af;
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

        /* Program option buttons */
        .program-option-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .program-option-btn {
            flex: 1;
            padding: 10px;
            text-align: center;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }

        .program-option-btn:hover {
            border-color: #3b82f6;
            background-color: #f0f9ff;
        }

        .program-option-btn.active {
            border-color: #3b82f6;
            background-color: #3b82f6;
            color: white;
        }

        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        /* Responsive */
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

            .grid-2 {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .table-responsive table {
                min-width: 640px;
            }
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Admin Dashboard</p>
        </div>

        <!-- User Info -->
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'admin'); ?>
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
                    <p class="text-xs text-blue-300">Admin</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-shield"></i>
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
                        <i class="fas fa-user-shield text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'admin'); ?>
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
                        <i class="fas fa-users mr-2"></i> Data Siswa
                    </h1>
                    <p class="text-gray-600">Kelola data siswa bimbingan belajar</p>
                </div>
                <div>
                    <a href="dataSiswa.php?action=tambah<?php
                    echo !empty($search) ? '&search=' . urlencode($search) : '';
                    echo !empty($filter_kelas) ? '&filter_kelas=' . urlencode($filter_kelas) : '';
                    echo !empty($filter_tingkat) ? '&filter_tingkat=' . urlencode($filter_tingkat) : '';
                    echo !empty($filter_jenis_kelamin) ? '&filter_jenis_kelamin=' . urlencode($filter_jenis_kelamin) : '';
                    echo !empty($filter_status) ? '&filter_status=' . urlencode($filter_status) : '';
                    echo !empty($filter_program) ? '&filter_program=' . urlencode($filter_program) : '';
                    ?>"
                        class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-user-plus mr-2"></i> Tambah Siswa Baru
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Statistik -->
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-blue-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Total Siswa</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['total_siswa']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-check text-green-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Aktif</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['aktif']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-gray-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-times text-gray-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Non-Aktif</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['nonaktif']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-indigo-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-graduation-cap text-indigo-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Alumni</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['alumni']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-yellow-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-male text-yellow-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Laki-laki</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['laki_laki']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="h-10 w-10 bg-pink-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-female text-pink-600"></i>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-600">Perempuan</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $statistik['perempuan']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

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

            <!-- Filter Section -->
            <div class="mb-6 bg-white shadow overflow-hidden sm:rounded-md">
                <div class="px-4 py-5 sm:p-6">
                    <form method="GET" action="dataSiswa.php" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <!-- Search -->
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700">Pencarian</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-search text-gray-400"></i>
                                    </div>
                                    <input type="text" name="search" id="search"
                                        class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-10 sm:text-sm border-gray-300 rounded-md"
                                        placeholder="Nama, orangtua, sekolah..."
                                        value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>

                            <!-- Filter Kelas Sekolah -->
                            <div>
                                <label for="filter_kelas" class="block text-sm font-medium text-gray-700">Kelas
                                    Sekolah</label>
                                <select id="filter_kelas" name="filter_kelas"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Kelas</option>
                                    <option value="Paud" <?php echo $filter_kelas == 'Paud' ? 'selected' : ''; ?>>Paud
                                    </option>
                                    <option value="TK" <?php echo $filter_kelas == 'TK' ? 'selected' : ''; ?>>TK</option>
                                    <option value="1 SD" <?php echo $filter_kelas == '1 SD' ? 'selected' : ''; ?>>Kelas 1
                                        SD</option>
                                    <option value="2 SD" <?php echo $filter_kelas == '2 SD' ? 'selected' : ''; ?>>Kelas 2
                                        SD</option>
                                    <option value="3 SD" <?php echo $filter_kelas == '3 SD' ? 'selected' : ''; ?>>Kelas 3
                                        SD</option>
                                    <option value="4 SD" <?php echo $filter_kelas == '4 SD' ? 'selected' : ''; ?>>Kelas 4
                                        SD</option>
                                    <option value="5 SD" <?php echo $filter_kelas == '5 SD' ? 'selected' : ''; ?>>Kelas 5
                                        SD</option>
                                    <option value="6 SD" <?php echo $filter_kelas == '6 SD' ? 'selected' : ''; ?>>Kelas 6
                                        SD</option>
                                    <option value="7 SMP" <?php echo $filter_kelas == '7 SMP' ? 'selected' : ''; ?>>Kelas
                                        7 SMP</option>
                                    <option value="8 SMP" <?php echo $filter_kelas == '8 SMP' ? 'selected' : ''; ?>>Kelas
                                        8 SMP</option>
                                    <option value="9 SMP" <?php echo $filter_kelas == '9 SMP' ? 'selected' : ''; ?>>Kelas
                                        9 SMP</option>
                                    <option value="10 SMA" <?php echo $filter_kelas == '10 SMA' ? 'selected' : ''; ?>>
                                        Kelas 10 SMA</option>
                                    <option value="11 SMA" <?php echo $filter_kelas == '11 SMA' ? 'selected' : ''; ?>>
                                        Kelas 11 SMA</option>
                                    <option value="12 SMA" <?php echo $filter_kelas == '12 SMA' ? 'selected' : ''; ?>>
                                        Kelas 12 SMA</option>
                                    <option value="Alumni" <?php echo $filter_kelas == 'Alumni' ? 'selected' : ''; ?>>
                                        Alumni</option>
                                    <option value="Umum" <?php echo $filter_kelas == 'Umum' ? 'selected' : ''; ?>>Umum
                                    </option>
                                </select>
                            </div>

                            <!-- Filter Tingkat Bimbel -->
                            <div>
                                <label for="filter_tingkat" class="block text-sm font-medium text-gray-700">Tingkat
                                    Bimbel</label>
                                <select id="filter_tingkat" name="filter_tingkat"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Tingkat</option>
                                    <option value="TK" <?php echo $filter_tingkat == 'TK' ? 'selected' : ''; ?>>TK
                                    </option>
                                    <option value="SD" <?php echo $filter_tingkat == 'SD' ? 'selected' : ''; ?>>SD
                                    </option>
                                    <option value="SMP" <?php echo $filter_tingkat == 'SMP' ? 'selected' : ''; ?>>SMP
                                    </option>
                                    <option value="SMA" <?php echo $filter_tingkat == 'SMA' ? 'selected' : ''; ?>>SMA
                                    </option>
                                    <option value="Alumni" <?php echo $filter_tingkat == 'Alumni' ? 'selected' : ''; ?>>
                                        Alumni</option>
                                    <option value="Umum" <?php echo $filter_tingkat == 'Umum' ? 'selected' : ''; ?>>Umum
                                    </option>
                                </select>
                            </div>

                            <!-- Filter Jenis Kelamin -->
                            <div>
                                <label for="filter_jenis_kelamin" class="block text-sm font-medium text-gray-700">Jenis
                                    Kelamin</label>
                                <select id="filter_jenis_kelamin" name="filter_jenis_kelamin"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua</option>
                                    <option value="L" <?php echo $filter_jenis_kelamin == 'L' ? 'selected' : ''; ?>>
                                        Laki-laki</option>
                                    <option value="P" <?php echo $filter_jenis_kelamin == 'P' ? 'selected' : ''; ?>>
                                        Perempuan</option>
                                </select>
                            </div>

                            <!-- Filter Program Bimbel (Jenis Kelas) -->
                            <div>
                                <label for="filter_program" class="block text-sm font-medium text-gray-700">Ruang
                                    Bimbel</label>
                                <select id="filter_program" name="filter_program"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Ruang</option>
                                    <option value="Excellent" <?php echo $filter_program == 'Excellent' ? 'selected' : ''; ?>>Excellent</option>
                                    <option value="Champion" <?php echo $filter_program == 'Champion' ? 'selected' : ''; ?>>Champion</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <!-- Filter Status -->
                            <div>
                                <label for="filter_status" class="block text-sm font-medium text-gray-700">Status
                                    Siswa</label>
                                <select id="filter_status" name="filter_status"
                                    class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">Semua Status</option>
                                    <option value="aktif" <?php echo $filter_status == 'aktif' ? 'selected' : ''; ?>>Aktif
                                    </option>
                                    <option value="nonaktif" <?php echo $filter_status == 'nonaktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                    <option value="alumni" <?php echo $filter_status == 'alumni' ? 'selected' : ''; ?>>
                                        Alumni</option>
                                </select>
                            </div>

                            <div class="md:col-span-2 flex justify-end items-end space-x-3">
                                <?php if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)): ?>
                                    <a href="dataSiswa.php"
                                        class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <i class="fas fa-times mr-2"></i> Reset Filter
                                    </a>
                                <?php endif; ?>

                                <button type="submit"
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-filter mr-2"></i> Filter Data
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabel Siswa -->
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <?php if (count($siswa_data) > 0): ?>
                    <div class="table-responsive">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No
                                    </th>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Nama Siswa
                                    </th>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Kelas Sekolah
                                    </th>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Ruang Bimbel
                                    </th>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col"
                                        class="hidden md:table-cell px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Data
                                    </th>
                                    <th scope="col"
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($siswa_data as $index => $siswa):
                                    // Buat URL dengan filter
                                    $detail_url = "dataSiswa.php?action=detail&id=" . $siswa['id'];
                                    $edit_url = "dataSiswa.php?action=edit&id=" . $siswa['id'];

                                    if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
                                        $detail_url .= "&search=" . urlencode($search) .
                                            "&filter_kelas=" . urlencode($filter_kelas) .
                                            "&filter_tingkat=" . urlencode($filter_tingkat) .
                                            "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                                            "&filter_status=" . urlencode($filter_status) .
                                            "&filter_program=" . urlencode($filter_program);
                                        $edit_url .= "&search=" . urlencode($search) .
                                            "&filter_kelas=" . urlencode($filter_kelas) .
                                            "&filter_tingkat=" . urlencode($filter_tingkat) .
                                            "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                                            "&filter_status=" . urlencode($filter_status) .
                                            "&filter_program=" . urlencode($filter_program);
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $index + 1; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8">
                                                    <div
                                                        class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-user text-blue-600 text-sm"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-2">
                                                    <div class="text-sm font-medium text-gray-900 truncate max-w-[120px]">
                                                        <?php echo htmlspecialchars($siswa['nama_lengkap']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo $siswa['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php
                                            // Tentukan badge color berdasarkan kategori
                                            $badge_color = 'bg-green-100 text-green-800'; // default
                                            if (in_array($siswa['kelas'], ['Paud', 'TK'])) {
                                                $badge_color = 'bg-yellow-100 text-yellow-800';
                                            } elseif (in_array($siswa['kelas'], ['1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD'])) {
                                                $badge_color = 'bg-blue-100 text-blue-800';
                                            } elseif (in_array($siswa['kelas'], ['7 SMP', '8 SMP', '9 SMP'])) {
                                                $badge_color = 'bg-indigo-100 text-indigo-800';
                                            } elseif (in_array($siswa['kelas'], ['10 SMA', '11 SMA', '12 SMA'])) {
                                                $badge_color = 'bg-purple-100 text-purple-800';
                                            } elseif (in_array($siswa['kelas'], ['Alumni', 'Umum'])) {
                                                $badge_color = 'bg-gray-100 text-gray-800';
                                            }
                                            ?>
                                            <span
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $badge_color; ?>">
                                                <?php echo htmlspecialchars($siswa['kelas']); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php if (!empty($siswa['jenis_kelas'])):
                                                $program_list = explode(',', $siswa['jenis_kelas']);
                                                $program_badges = '';
                                                foreach ($program_list as $program) {
                                                    $program = trim($program);
                                                    $badge_class = $program == 'Excellent' ? 'program-excellent' : 'program-champion';
                                                    $program_badges .= '<span class="program-badge ' . $badge_class . ' mr-1 mb-1">' . htmlspecialchars($program) . '</span>';
                                                }
                                                echo $program_badges;
                                            else: ?>
                                                <span class="text-xs text-gray-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php
                                            $status_color = 'status-active';
                                            $status_text = 'Aktif';
                                            if ($siswa['status'] == 'nonaktif') {
                                                $status_color = 'status-nonaktif';
                                                $status_text = 'Non-Aktif';
                                            } elseif ($siswa['status'] == 'alumni') {
                                                $status_color = 'status-alumni';
                                                $status_text = 'Alumni';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_color; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="hidden md:table-cell px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <div class="space-y-1">
                                                <?php if (!empty($siswa['nama_ortu_list'])): ?>
                                                    <div class="flex items-center">
                                                        <i class="fas fa-user-friends mr-2 text-gray-400 text-xs"></i>
                                                        <span><?php echo htmlspecialchars($siswa['nama_ortu_list']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex items-center">
                                                    <i class="fas fa-chart-line mr-2 text-gray-400 text-xs"></i>
                                                    <span><?php echo $siswa['jumlah_penilaian']; ?> penilaian</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="<?php echo $detail_url; ?>"
                                                    class="text-blue-600 hover:text-blue-900 p-1" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo $edit_url; ?>"
                                                    class="text-yellow-600 hover:text-yellow-900 p-1" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="#"
                                                    onclick="confirmDelete(<?php echo $siswa['id']; ?>, '<?php echo htmlspecialchars(addslashes($siswa['nama_lengkap'])); ?>')"
                                                    class="text-red-600 hover:text-red-900 p-1" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-users text-4xl text-gray-400 mb-4"></i>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">Tidak ada data siswa</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            <?php if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)): ?>
                                Tidak ditemukan siswa dengan kriteria pencarian.
                            <?php else: ?>
                                Belum ada data siswa. Mulai dengan menambahkan siswa baru.
                            <?php endif; ?>
                        </p>
                        <div class="mt-6">
                            <?php
                            $tambah_url = "dataSiswa.php?action=tambah";
                            if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)) {
                                $tambah_url .= "&search=" . urlencode($search) .
                                    "&filter_kelas=" . urlencode($filter_kelas) .
                                    "&filter_tingkat=" . urlencode($filter_tingkat) .
                                    "&filter_jenis_kelamin=" . urlencode($filter_jenis_kelamin) .
                                    "&filter_status=" . urlencode($filter_status) .
                                    "&filter_program=" . urlencode($filter_program);
                            }
                            ?>
                            <a href="<?php echo $tambah_url; ?>"
                                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-user-plus mr-2"></i> Tambah Siswa Baru
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="text-center text-sm text-gray-500">
                    <p>© <?php echo date('Y'); ?> Bimbel Esc - Data Siswa</p>
                    <p class="mt-1 text-xs text-gray-400">
                        Total: <?php echo count($siswa_data); ?> siswa |
                        Terakhir diupdate: <?php echo date('H:i:s'); ?>
                    </p>
                </div>
            </div>
        </footer>
    </div>

    <!-- MODAL TAMBAH SISWA -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'tambah'): ?>
        <div id="formModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-user-plus mr-2"></i> Tambah Siswa Baru
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <form method="POST" action="dataSiswa.php">
                    <!-- Tambahkan parameter filter ke form action -->
                    <?php if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="filter_kelas" value="<?php echo htmlspecialchars($filter_kelas); ?>">
                        <input type="hidden" name="filter_tingkat" value="<?php echo htmlspecialchars($filter_tingkat); ?>">
                        <input type="hidden" name="filter_jenis_kelamin"
                            value="<?php echo htmlspecialchars($filter_jenis_kelamin); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <input type="hidden" name="filter_program" value="<?php echo htmlspecialchars($filter_program); ?>">
                    <?php endif; ?>

                    <input type="hidden" name="tambah_siswa" value="1">

                    <div class="modal-body">
                        <div class="grid-2">
                            <!-- Kolom 1: Data Pribadi -->
                            <div>
                                <div class="form-group">
                                    <label class="form-label">Nama Lengkap *</label>
                                    <input type="text" name="nama_lengkap" class="form-input" required
                                        placeholder="Nama lengkap siswa">
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="form-group">
                                        <label class="form-label">Tempat Lahir</label>
                                        <input type="text" name="tempat_lahir" class="form-input" placeholder="Kota lahir">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Tanggal Lahir</label>
                                        <input type="date" name="tanggal_lahir" class="form-input">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Jenis Kelamin *</label>
                                    <select name="jenis_kelamin" class="form-input" required>
                                        <option value="">Pilih Jenis Kelamin</option>
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Agama</label>
                                    <select name="agama" class="form-input">
                                        <option value="">Pilih Agama</option>
                                        <option value="Islam">Islam</option>
                                        <option value="Kristen">Kristen</option>
                                        <option value="Katolik">Katolik</option>
                                        <option value="Hindu">Hindu</option>
                                        <option value="Buddha">Buddha</option>
                                        <option value="Konghucu">Konghucu</option>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Alamat</label>
                                    <textarea name="alamat" class="form-input" rows="2"
                                        placeholder="Alamat lengkap"></textarea>
                                </div>
                            </div>

                            <!-- Kolom 2: Data Pendidikan & Pendaftaran -->
                            <div>
                                <div class="form-group">
                                    <label class="form-label">Sekolah Asal/Instansi</label>
                                    <input type="text" name="sekolah_asal" class="form-input"
                                        placeholder="Nama sekolah, kampus, atau perusahaan">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Kelas Sekolah *</label>
                                    <select name="kelas_sekolah" class="form-input" required>
                                        <option value="">Pilih Kelas Sekolah</option>
                                        <option value="Paud">Paud</option>
                                        <option value="TK">TK</option>
                                        <option value="1 SD">Kelas 1 SD</option>
                                        <option value="2 SD">Kelas 2 SD</option>
                                        <option value="3 SD">Kelas 3 SD</option>
                                        <option value="4 SD">Kelas 4 SD</option>
                                        <option value="5 SD">Kelas 5 SD</option>
                                        <option value="6 SD">Kelas 6 SD</option>
                                        <option value="7 SMP">Kelas 7 SMP</option>
                                        <option value="8 SMP">Kelas 8 SMP</option>
                                        <option value="9 SMP">Kelas 9 SMP</option>
                                        <option value="10 SMA">Kelas 10 SMA</option>
                                        <option value="11 SMA">Kelas 11 SMA</option>
                                        <option value="12 SMA">Kelas 12 SMA</option>
                                        <option value="Alumni">Alumni</option>
                                        <option value="Umum">Umum</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status Siswa *</label>
                                    <select name="status_siswa" class="form-input" required>
                                        <option value="aktif">Aktif</option>
                                        <option value="nonaktif">Non-Aktif</option>
                                        <option value="alumni">Alumni</option>
                                    </select>
                                </div>

                                <!-- Data Pendaftaran -->
                                <div class="mt-4 pt-4 border-t">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Data Pendaftaran *</h3>

                                    <!-- Pilihan Jenis Kelas -->
                                    <div class="program-option-buttons">
                                        <button type="button" class="program-option-btn" data-program-type="Excellent">
                                            <i class="fas fa-star mr-2"></i> Excellent
                                        </button>
                                        <button type="button" class="program-option-btn" data-program-type="Champion">
                                            <i class="fas fa-trophy mr-2"></i> Champion
                                        </button>
                                    </div>

                                    <input type="hidden" name="jenis_kelas" id="jenis_kelas" value="" required>

                                    <div class="form-group">
                                        <label class="form-label">Mata Pelajaran *</label>
                                        <div class="border border-gray-300 rounded-md p-3 max-h-60 overflow-y-auto">
                                            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                                <?php foreach ($mata_pelajaran_list as $mapel): ?>
                                                    <label
                                                        class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                                                        <input type="checkbox" name="mata_pelajaran[]"
                                                            value="<?php echo htmlspecialchars($mapel); ?>"
                                                            class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2">
                                                        <span class="text-sm"><?php echo htmlspecialchars($mapel); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <small class="text-gray-500 text-xs">Pilih minimal satu mata pelajaran</small>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="form-group">
                                            <label class="form-label">Tingkat *</label>
                                            <select name="tingkat" class="form-input" required>
                                                <option value="TK">TK</option>
                                                <option value="SD">SD</option>
                                                <option value="SMP" selected>SMP</option>
                                                <option value="SMA">SMA</option>
                                                <option value="Alumni">Alumni</option>
                                                <option value="Umum">Umum</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Tahun Ajaran *</label>
                                            <select name="tahun_ajaran" class="form-input" required>
                                                <option value="2024/2025">2024/2025</option>
                                                <option value="2025/2026" selected>2025/2026</option>
                                                <option value="2026/2027">2026/2027</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Data Orang Tua -->
                                <div class="mt-4 pt-4 border-t">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Data Orang Tua *</h3>

                                    <div class="mb-4">
                                        <div class="flex items-center mb-4">
                                            <div class="mr-4">
                                                <input type="radio" name="mode_ortu" value="existing" id="mode_existing"
                                                    checked onchange="toggleOrangtuaForm()"
                                                    class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2">
                                                <label for="mode_existing" class="text-sm font-medium text-gray-700">Pilih
                                                    yang sudah ada</label>
                                            </div>

                                            <div>
                                                <input type="radio" name="mode_ortu" value="baru" id="mode_baru"
                                                    onchange="toggleOrangtuaForm()"
                                                    class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2">
                                                <label for="mode_baru" class="text-sm font-medium text-gray-700">Orangtua
                                                    Baru</label>
                                            </div>
                                        </div>

                                        <!-- Form pilih orangtua existing (default show) -->
                                        <div id="pilih_ortu_existing">
                                            <div class="form-group">
                                                <label class="form-label">Pilih Orangtua *</label>
                                                <div class="border border-gray-300 rounded-md p-3 max-h-60 overflow-y-auto">
                                                    <?php if (!empty($orangtua_list)): ?>
                                                        <div class="space-y-2">
                                                            <?php foreach ($orangtua_list as $ortu): ?>
                                                                <label
                                                                    class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                                                                    <input type="checkbox" name="orangtua_existing_id[]"
                                                                        value="<?php echo $ortu['id']; ?>"
                                                                        class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2">
                                                                    <div>
                                                                        <span
                                                                            class="text-sm font-medium"><?php echo htmlspecialchars($ortu['nama_ortu']); ?></span>
                                                                        <div class="text-xs text-gray-500">
                                                                            <?php echo htmlspecialchars($ortu['email']); ?> |
                                                                            <?php echo htmlspecialchars($ortu['no_hp']); ?> |
                                                                            <?php echo $ortu['jumlah_anak']; ?> anak
                                                                        </div>
                                                                    </div>
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <p class="text-sm text-gray-500 text-center py-4">Belum ada data
                                                            orangtua terdaftar</p>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-gray-500 text-xs">Pilih minimal satu orangtua. Sistem
                                                    mendukung multiple orangtua.</small>
                                            </div>
                                        </div>

                                        <!-- Form orangtua baru (default hidden) -->
                                        <div id="form_ortu_baru" class="hidden">
                                            <div class="form-group">
                                                <label class="form-label">Nama Orang Tua/Wali *</label>
                                                <input type="text" name="nama_ortu" class="form-input"
                                                    placeholder="Nama orang tua/wali">
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">No. HP Orang Tua</label>
                                                <input type="tel" name="no_hp_ortu" class="form-input"
                                                    placeholder="08xxxxxxxxxx">
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">Email Orang Tua *</label>
                                                <input type="email" name="email_ortu" class="form-input"
                                                    placeholder="email@contoh.com">
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">Password untuk Orang Tua *</label>
                                                <div class="relative">
                                                    <input type="password" name="password_ortu" id="password_ortu"
                                                        class="form-input pr-10" placeholder="Minimal 6 karakter"
                                                        minlength="6">
                                                    <button type="button" onclick="togglePassword('password_ortu')"
                                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                                                        <i class="fas fa-eye" id="password_ortu-icon"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 gap-3">
                                                <div class="form-group">
                                                    <label class="form-label">Pekerjaan</label>
                                                    <input type="text" name="pekerjaan_ortu" class="form-input"
                                                        placeholder="Pekerjaan orang tua">
                                                </div>
                                                <div class="form-group">
                                                    <label class="form-label">Perusahaan</label>
                                                    <input type="text" name="perusahaan_ortu" class="form-input"
                                                        placeholder="Nama perusahaan">
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">Hubungan dengan Siswa</label>
                                                <select name="hubungan_ortu" class="form-input">
                                                    <option value="ayah">Ayah</option>
                                                    <option value="ibu">Ibu</option>
                                                    <option value="wali" selected>Wali</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <p class="text-sm text-blue-700">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>Note:</strong>
                                1. Pilih mode orangtua: "Pilih yang sudah ada" atau "Orangtua Baru".<br>
                                2. Sistem mendukung multiple orangtua untuk satu siswa.<br>
                                3. Password hanya untuk akun orangtua baru.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" onclick="closeModal()"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                            Batal
                        </button>
                        <button type="submit" name="tambah_siswa"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-save mr-2"></i> Simpan Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- MODAL DETAIL SISWA -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'detail' && $siswa_detail): ?>
        <div id="detailModal" class="modal" style="display: block;">
            <div class="modal-content">
                <div class="modal-header blue">
                    <h2 class="text-xl font-bold">
                        <i class="fas fa-user-circle mr-2"></i> Detail Data Siswa
                    </h2>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="grid-2">
                        <!-- Kolom 1: Data Pribadi -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Data Pribadi</h3>

                            <div class="space-y-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Nama Lengkap</label>
                                    <p class="mt-1 text-gray-900 font-medium">
                                        <?php echo htmlspecialchars($siswa_detail['nama_lengkap']); ?>
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-600">Tempat Lahir</label>
                                        <p class="mt-1 text-gray-900">
                                            <?php echo htmlspecialchars($siswa_detail['tempat_lahir'] ?? '-'); ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-600">Tanggal Lahir</label>
                                        <p class="mt-1 text-gray-900">
                                            <?php echo !empty($siswa_detail['tanggal_lahir']) ? date('d F Y', strtotime($siswa_detail['tanggal_lahir'])) : '-'; ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-sm font-medium text-gray-600">Jenis Kelamin</label>
                                        <p class="mt-1 text-gray-900">
                                            <?php echo $siswa_detail['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <label class="text-sm font-medium text-gray-600">Agama</label>
                                        <p class="mt-1 text-gray-900">
                                            <?php echo htmlspecialchars($siswa_detail['agama'] ?? '-'); ?>
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label class="text-sm font-medium text-gray-600">Alamat</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php echo htmlspecialchars($siswa_detail['alamat'] ?? '-'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Kolom 2: Data Pendidikan & Orang Tua -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Data Pendidikan</h3>

                            <div class="space-y-4 mb-6">
                                <div>
                                    <label class="text-sm font-medium text-gray-600">Sekolah Asal</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php echo htmlspecialchars($siswa_detail['sekolah_asal'] ?? '-'); ?>
                                    </p>
                                </div>

                                <div>
                                    <label class="text-sm font-medium text-gray-600">Kelas Sekolah</label>
                                    <p class="mt-1 text-gray-900">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                            <?php
                                            $kelas = $siswa_detail['kelas'] ?? '';
                                            $badge_color = 'bg-green-100 text-green-800';
                                            if (in_array($kelas, ['Paud', 'TK'])) {
                                                $badge_color = 'bg-yellow-100 text-yellow-800';
                                            } elseif (strpos($kelas, 'SD') !== false) {
                                                $badge_color = 'bg-blue-100 text-blue-800';
                                            } elseif (strpos($kelas, 'SMP') !== false) {
                                                $badge_color = 'bg-indigo-100 text-indigo-800';
                                            } elseif (strpos($kelas, 'SMA') !== false) {
                                                $badge_color = 'bg-purple-100 text-purple-800';
                                            } elseif (in_array($kelas, ['Alumni', 'Umum'])) {
                                                $badge_color = 'bg-gray-100 text-gray-800';
                                            }
                                            echo $badge_color;
                                            ?>">
                                            <?php echo htmlspecialchars($kelas); ?>
                                        </span>
                                    </p>
                                </div>

                                <div>
                                    <label class="text-sm font-medium text-gray-600">Status Siswa</label>
                                    <p class="mt-1">
                                        <?php
                                        $status_color = 'status-active';
                                        $status_text = 'Aktif';
                                        if ($siswa_detail['status'] == 'nonaktif') {
                                            $status_color = 'status-nonaktif';
                                            $status_text = 'Non-Aktif';
                                        } elseif ($siswa_detail['status'] == 'alumni') {
                                            $status_color = 'status-alumni';
                                            $status_text = 'Alumni';
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $status_color; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <!-- Data Pendaftaran Bimbel -->
                            <?php if (!empty($siswa_detail['pendaftaran_list'])): ?>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Program Bimbel</h3>
                                <div class="space-y-4">
                                    <?php foreach ($siswa_detail['pendaftaran_list'] as $pendaftaran): ?>
                                        <div class="bg-gray-50 p-4 rounded-lg">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <span
                                                        class="program-badge <?php echo $pendaftaran['jenis_kelas'] == 'Excellent' ? 'program-excellent' : 'program-champion'; ?>">
                                                        <?php echo htmlspecialchars($pendaftaran['jenis_kelas']); ?>
                                                    </span>
                                                    <span
                                                        class="ml-2 text-sm text-gray-600"><?php echo htmlspecialchars($pendaftaran['tingkat']); ?></span>
                                                </div>
                                                <span
                                                    class="text-sm text-gray-500"><?php echo htmlspecialchars($pendaftaran['tahun_ajaran']); ?></span>
                                            </div>

                                            <?php if (!empty($pendaftaran['mata_pelajaran_list'])): ?>
                                                <div class="mt-2">
                                                    <label class="text-sm font-medium text-gray-600">Mata Pelajaran</label>
                                                    <div class="mt-1 space-y-1">
                                                        <?php foreach ($pendaftaran['mata_pelajaran_list'] as $mapel): ?>
                                                            <div class="flex justify-between items-center">
                                                                <span
                                                                    class="text-gray-900"><?php echo htmlspecialchars($mapel['nama_pelajaran']); ?></span>
                                                                <?php if (!empty($mapel['nama_guru'])): ?>
                                                                    <span
                                                                        class="text-sm text-gray-500">(<?php echo htmlspecialchars($mapel['nama_guru']); ?>)</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div class="mt-2 text-sm text-gray-500">
                                                <i class="fas fa-calendar-alt mr-1"></i>
                                                Mulai: <?php echo date('d F Y', strtotime($pendaftaran['tanggal_mulai'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Data Orang Tua -->
                            <?php if (!empty($siswa_detail['nama_ortu_list'])): ?>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2 mt-6">Data Orang Tua</h3>
                                <div class="space-y-3">
                                    <div>
                                        <label class="text-sm font-medium text-gray-600">Nama Orang Tua/Wali</label>
                                        <p class="mt-1 text-gray-900">
                                            <?php echo htmlspecialchars($siswa_detail['nama_ortu_list']); ?>
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="text-sm font-medium text-gray-600">No. HP</label>
                                            <p class="mt-1 text-gray-900">
                                                <?php echo htmlspecialchars($siswa_detail['no_hp_list'] ?? '-'); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <label class="text-sm font-medium text-gray-600">Email</label>
                                            <p class="mt-1 text-gray-900">
                                                <?php echo htmlspecialchars($siswa_detail['email_ortu_list'] ?? '-'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <?php if (!empty($siswa_detail['pekerjaan_list'])): ?>
                                        <div>
                                            <label class="text-sm font-medium text-gray-600">Pekerjaan</label>
                                            <p class="mt-1 text-gray-900">
                                                <?php echo htmlspecialchars($siswa_detail['pekerjaan_list']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($siswa_detail['hubungan_list'])): ?>
                                        <div>
                                            <label class="text-sm font-medium text-gray-600">Hubungan</label>
                                            <p class="mt-1 text-gray-900">
                                                <?php echo htmlspecialchars($siswa_detail['hubungan_list']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($siswa_detail['perusahaan_list'])): ?>
                                        <div>
                                            <label class="text-sm font-medium text-gray-600">Perusahaan</label>
                                            <p class="mt-1 text-gray-900">
                                                <?php echo htmlspecialchars($siswa_detail['perusahaan_list']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Info Tambahan -->
                    <div class="mt-6 pt-6 border-t">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="text-center">
                                <div class="text-sm text-gray-600">Tanggal Daftar</div>
                                <div class="text-lg font-semibold text-gray-900">
                                    <?php echo !empty($siswa_detail['created_at']) ? date('d M Y', strtotime($siswa_detail['created_at'])) : '-'; ?>
                                </div>
                            </div>
                            <div class="text-center">
                                <div class="text-sm text-gray-600">Terakhir Update</div>
                                <div class="text-lg font-semibold text-gray-900">
                                    <?php echo !empty($siswa_detail['updated_at']) ? date('d M Y', strtotime($siswa_detail['updated_at'])) : '-'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                        <i class="fas fa-times mr-2"></i> Tutup
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MODAL EDIT SISWA - DENGAN FITUR GANTI PASSWORD ORANGTUA -->
    <!-- MODAL EDIT SISWA - HANYA EDIT ORANGTUA YANG SUDAH ADA -->
    <?php if (isset($_GET['action']) && $_GET['action'] == 'edit' && $siswa_edit): ?>
            <div id="formModal" class="modal" style="display: block;">
                <div class="modal-content">
                    <div class="modal-header yellow">
                        <h2 class="text-xl font-bold">
                            <i class="fas fa-edit mr-2"></i> Edit Data Siswa
                        </h2>
                        <span class="close" onclick="closeModal()">&times;</span>
                    </div>
                    <form method="POST" action="dataSiswa.php">
                        <!-- Tambahkan parameter filter ke form action -->
                        <?php if (!empty($search) || !empty($filter_kelas) || !empty($filter_tingkat) || !empty($filter_jenis_kelamin) || !empty($filter_status) || !empty($filter_program)): ?>
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="filter_kelas" value="<?php echo htmlspecialchars($filter_kelas); ?>">
                                <input type="hidden" name="filter_tingkat" value="<?php echo htmlspecialchars($filter_tingkat); ?>">
                                <input type="hidden" name="filter_jenis_kelamin"
                                    value="<?php echo htmlspecialchars($filter_jenis_kelamin); ?>">
                                <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                                <input type="hidden" name="filter_program" value="<?php echo htmlspecialchars($filter_program); ?>">
                        <?php endif; ?>

                        <input type="hidden" name="update_siswa" value="1">
                        <input type="hidden" name="siswa_id" value="<?php echo $siswa_edit['id']; ?>">

                        <div class="modal-body">
                            <div class="grid-2">
                                <!-- Kolom 1: Data Pribadi Siswa -->
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Data Pribadi Siswa</h3>

                                    <div class="form-group">
                                        <label class="form-label">Nama Lengkap *</label>
                                        <input type="text" name="nama_lengkap" class="form-input" required
                                            value="<?php echo htmlspecialchars($siswa_edit['nama_lengkap']); ?>">
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="form-group">
                                            <label class="form-label">Tempat Lahir</label>
                                            <input type="text" name="tempat_lahir" class="form-input"
                                                value="<?php echo htmlspecialchars($siswa_edit['tempat_lahir'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Tanggal Lahir</label>
                                            <input type="date" name="tanggal_lahir" class="form-input"
                                                value="<?php echo htmlspecialchars($siswa_edit['tanggal_lahir'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Jenis Kelamin *</label>
                                        <select name="jenis_kelamin" class="form-input" required>
                                            <option value="L" <?php echo $siswa_edit['jenis_kelamin'] == 'L' ? 'selected' : ''; ?>>Laki-laki</option>
                                            <option value="P" <?php echo $siswa_edit['jenis_kelamin'] == 'P' ? 'selected' : ''; ?>>Perempuan</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Agama</label>
                                        <select name="agama" class="form-input">
                                            <option value="">Pilih Agama</option>
                                            <option value="Islam" <?php echo ($siswa_edit['agama'] ?? '') == 'Islam' ? 'selected' : ''; ?>>Islam</option>
                                            <option value="Kristen" <?php echo ($siswa_edit['agama'] ?? '') == 'Kristen' ? 'selected' : ''; ?>>Kristen</option>
                                            <option value="Katolik" <?php echo ($siswa_edit['agama'] ?? '') == 'Katolik' ? 'selected' : ''; ?>>Katolik</option>
                                            <option value="Hindu" <?php echo ($siswa_edit['agama'] ?? '') == 'Hindu' ? 'selected' : ''; ?>>Hindu</option>
                                            <option value="Buddha" <?php echo ($siswa_edit['agama'] ?? '') == 'Buddha' ? 'selected' : ''; ?>>Buddha</option>
                                            <option value="Konghucu" <?php echo ($siswa_edit['agama'] ?? '') == 'Konghucu' ? 'selected' : ''; ?>>Konghucu</option>
                                            <option value="Lainnya" <?php echo ($siswa_edit['agama'] ?? '') == 'Lainnya' ? 'selected' : ''; ?>>Lainnya</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Alamat</label>
                                        <textarea name="alamat" class="form-input"
                                            rows="2"><?php echo htmlspecialchars($siswa_edit['alamat'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Status Siswa *</label>
                                        <select name="status_siswa" class="form-input" required>
                                            <option value="aktif" <?php echo ($siswa_edit['status'] ?? '') == 'aktif' ? 'selected' : ''; ?>>Aktif</option>
                                            <option value="nonaktif" <?php echo ($siswa_edit['status'] ?? '') == 'nonaktif' ? 'selected' : ''; ?>>Non-Aktif</option>
                                            <option value="alumni" <?php echo ($siswa_edit['status'] ?? '') == 'alumni' ? 'selected' : ''; ?>>Alumni</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Kolom 2: Data Pendidikan & Orangtua -->
                                <div>
                                    <div class="form-group">
                                        <label class="form-label">Sekolah Asal/Instansi</label>
                                        <input type="text" name="sekolah_asal" class="form-input"
                                            value="<?php echo htmlspecialchars($siswa_edit['sekolah_asal'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Kelas Sekolah *</label>
                                        <select name="kelas_sekolah" class="form-input" required>
                                            <option value="">Pilih Kelas Sekolah</option>
                                            <?php
                                            $kelas_options = [
                                                'Paud',
                                                'TK',
                                                '1 SD',
                                                '2 SD',
                                                '3 SD',
                                                '4 SD',
                                                '5 SD',
                                                '6 SD',
                                                '7 SMP',
                                                '8 SMP',
                                                '9 SMP',
                                                '10 SMA',
                                                '11 SMA',
                                                '12 SMA',
                                                'Alumni',
                                                'Umum'
                                            ];
                                            foreach ($kelas_options as $kelas_option): ?>
                                                    <option value="<?php echo $kelas_option; ?>" <?php echo $siswa_edit['kelas'] == $kelas_option ? 'selected' : ''; ?>>
                                                        <?php echo $kelas_option; ?>
                                                    </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Data Pendaftaran -->
                                    <?php
                                    $selected_mata_pelajaran = $siswa_edit['current_pendaftaran']['selected_mata_pelajaran'] ?? [];
                                    $current_jenis_kelas = $siswa_edit['current_pendaftaran']['jenis_kelas'] ?? '';
                                    ?>

                                    <div class="mt-4 pt-4 border-t">
                                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Data Pendaftaran Bimbel</h3>

                                        <!-- Pilihan Jenis Kelas -->
                                        <div class="program-option-buttons">
                                            <button type="button"
                                                class="program-option-btn <?php echo $current_jenis_kelas == 'Excellent' ? 'active' : ''; ?>"
                                                data-program-type="Excellent">
                                                <i class="fas fa-star mr-2"></i> Excellent
                                            </button>
                                            <button type="button"
                                                class="program-option-btn <?php echo $current_jenis_kelas == 'Champion' ? 'active' : ''; ?>"
                                                data-program-type="Champion">
                                                <i class="fas fa-trophy mr-2"></i> Champion
                                            </button>
                                        </div>

                                        <input type="hidden" name="jenis_kelas" id="jenis_kelas"
                                            value="<?php echo $current_jenis_kelas; ?>">

                                        <div class="form-group mt-4">
                                            <label class="form-label">Mata Pelajaran *</label>
                                            <div class="border border-gray-300 rounded-md p-3 max-h-60 overflow-y-auto">
                                                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                                    <?php foreach ($mata_pelajaran_list as $mapel): ?>
                                                            <label
                                                                class="flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer">
                                                                <input type="checkbox" name="mata_pelajaran[]"
                                                                    value="<?php echo htmlspecialchars($mapel); ?>"
                                                                    class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-2"
                                                                    <?php echo in_array($mapel, $selected_mata_pelajaran) ? 'checked' : ''; ?>>
                                                                <span class="text-sm"><?php echo htmlspecialchars($mapel); ?></span>
                                                            </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <small class="text-gray-500 text-xs">Pilih minimal satu mata pelajaran</small>
                                        </div>

                                        <div class="grid grid-cols-2 gap-3">
                                            <div class="form-group">
                                                <label class="form-label">Tingkat *</label>
                                                <select name="tingkat" class="form-input" required>
                                                    <option value="TK" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'TK' ? 'selected' : ''; ?>>TK</option>
                                                    <option value="SD" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'SD' ? 'selected' : ''; ?>>SD</option>
                                                    <option value="SMP" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'SMP' ? 'selected' : ''; ?> selected>SMP</option>
                                                    <option value="SMA" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                                    <option value="Alumni" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'Alumni' ? 'selected' : ''; ?>>Alumni</option>
                                                    <option value="Umum" <?php echo ($siswa_edit['current_pendaftaran']['tingkat'] ?? '') == 'Umum' ? 'selected' : ''; ?>>Umum</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Tahun Ajaran *</label>
                                                <select name="tahun_ajaran" class="form-input" required>
                                                    <option value="2024/2025" <?php echo ($siswa_edit['current_pendaftaran']['tahun_ajaran'] ?? '') == '2024/2025' ? 'selected' : ''; ?>>2024/2025</option>
                                                    <option value="2025/2026" <?php echo ($siswa_edit['current_pendaftaran']['tahun_ajaran'] ?? '') == '2025/2026' ? 'selected' : ''; ?> selected>2025/2026</option>
                                                    <option value="2026/2027" <?php echo ($siswa_edit['current_pendaftaran']['tahun_ajaran'] ?? '') == '2026/2027' ? 'selected' : ''; ?>>2026/2027</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Data Orang Tua (HANYA YANG SUDAH TERHUBUNG) -->
                                    <div class="mt-6 pt-4 border-t">
                                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Data Orang Tua</h3>

                                        <?php
                                        // Ambil data orangtua yang terhubung dengan siswa ini
                                        $orangtua_list_siswa = $siswa_edit['orangtua_list'] ?? [];
                                        ?>

                                        <?php if (!empty($orangtua_list_siswa)): ?>
                                                <div class="space-y-4">
                                                    <?php foreach ($orangtua_list_siswa as $index => $ortu): ?>
                                                            <div class="p-4 border border-gray-200 rounded-lg bg-gray-50">
                                                                <h4 class="font-medium text-gray-800 mb-3">
                                                                    Orang Tua <?php echo $index + 1; ?>
                                                                    <span class="text-sm font-normal text-gray-600">
                                                                        (<?php echo ucfirst($ortu['hubungan_dengan_siswa']); ?>)
                                                                    </span>
                                                                </h4>

                                                                <!-- Input hidden untuk id orangtua -->
                                                                <input type="hidden" name="orangtua_id[]" value="<?php echo $ortu['id']; ?>">

                                                                <div class="grid grid-cols-2 gap-3">
                                                                    <div class="form-group">
                                                                        <label class="form-label">Nama *</label>
                                                                        <input type="text" name="orangtua_nama[]" class="form-input"
                                                                            value="<?php echo htmlspecialchars($ortu['nama_ortu']); ?>"
                                                                            required>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label class="form-label">No. HP *</label>
                                                                        <input type="tel" name="orangtua_no_hp[]" class="form-input"
                                                                            value="<?php echo htmlspecialchars($ortu['no_hp']); ?>" required>
                                                                    </div>
                                                                </div>

                                                                <div class="form-group">
                                                                    <label class="form-label">Email *</label>
                                                                    <input type="email" name="orangtua_email[]" class="form-input"
                                                                        value="<?php echo htmlspecialchars($ortu['email']); ?>" required>
                                                                </div>

                                                                <div class="grid grid-cols-2 gap-3">
                                                                    <div class="form-group">
                                                                        <label class="form-label">Pekerjaan</label>
                                                                        <input type="text" name="orangtua_pekerjaan[]" class="form-input"
                                                                            value="<?php echo htmlspecialchars($ortu['pekerjaan'] ?? ''); ?>">
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label class="form-label">Perusahaan</label>
                                                                        <input type="text" name="orangtua_perusahaan[]" class="form-input"
                                                                            value="<?php echo htmlspecialchars($ortu['perusahaan'] ?? ''); ?>">
                                                                    </div>
                                                                </div>

                                                                <div class="form-group">
                                                                    <label class="form-label">Hubungan dengan Siswa</label>
                                                                    <select name="orangtua_hubungan[]" class="form-input">
                                                                        <option value="ayah" <?php echo ($ortu['hubungan_dengan_siswa'] ?? '') == 'ayah' ? 'selected' : ''; ?>>Ayah</option>
                                                                        <option value="ibu" <?php echo ($ortu['hubungan_dengan_siswa'] ?? '') == 'ibu' ? 'selected' : ''; ?>>Ibu</option>
                                                                        <option value="wali" <?php echo ($ortu['hubungan_dengan_siswa'] ?? '') == 'wali' ? 'selected' : ''; ?>>Wali</option>
                                                                    </select>
                                                                </div>

                                                                <!-- FORM GANTI PASSWORD -->
                                                                <div class="mt-3 pt-3 border-t">
                                                                    <div class="form-group">
                                                                        <label class="form-label">Ganti Password</label>
                                                                        <div class="flex items-center space-x-2">
                                                                            <div class="flex-1 relative">
                                                                                <input type="password"
                                                                                    name="orangtua_password[<?php echo $ortu['id']; ?>]"
                                                                                    id="password_ortu_<?php echo $ortu['id']; ?>"
                                                                                    class="form-input pr-10"
                                                                                    placeholder="Kosongkan jika tidak ingin ganti password"
                                                                                    minlength="6">
                                                                                <button type="button"
                                                                                    onclick="togglePassword('password_ortu_<?php echo $ortu['id']; ?>')"
                                                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500">
                                                                                    <i class="fas fa-eye"
                                                                                        id="password_ortu_<?php echo $ortu['id']; ?>-icon"></i>
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                        <small class="text-gray-500 text-xs">
                                                                            Minimal 6 karakter. Kosongkan jika tidak ingin mengganti password.
                                                                        </small>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                    <?php endforeach; ?>
                                                </div>
                                        <?php else: ?>
                                                <div class="p-4 border border-dashed border-gray-300 rounded-lg text-center">
                                                    <i class="fas fa-user-friends text-3xl text-gray-400 mb-2"></i>
                                                    <p class="text-gray-600">Belum ada data orang tua terhubung</p>
                                                    <p class="text-sm text-gray-500 mt-1">
                                                        Tambah orang tua melalui menu "Tambah Siswa Baru" atau hubungi admin.
                                                    </p>
                                                </div>
                                        <?php endif; ?>

                                        <!-- Tombol untuk tambah orangtua tambahan (opsional) -->
                                        <?php if (!empty($orangtua_list_siswa)): ?>
                                                <div class="mt-4">
                                                    <button type="button" onclick="tambahOrangtuaLain()"
                                                        class="px-3 py-2 border border-dashed border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 w-full">
                                                        <i class="fas fa-plus mr-2"></i> Tambah Orang Tua Lain yang Sudah Terdaftar
                                                    </button>
                                                </div>

                                                <!-- Form untuk tambah orangtua lain (hidden by default) -->
                                                <div id="form-tambah-orangtua" class="mt-4 hidden">
                                                    <div class="p-4 border border-gray-200 rounded-lg bg-blue-50">
                                                        <h4 class="font-medium text-gray-800 mb-3">Tambah Orang Tua Lain</h4>

                                                        <div class="form-group">
                                                            <label class="form-label">Pilih Orang Tua yang Sudah Terdaftar</label>
                                                            <select name="tambah_orangtua_id" class="form-input">
                                                                <option value="">-- Pilih Orang Tua --</option>
                                                                <?php foreach ($orangtua_list as $ortu):
                                                                    // Skip yang sudah terhubung
                                                                    $already_connected = false;
                                                                    foreach ($orangtua_list_siswa as $ortu_siswa) {
                                                                        if ($ortu_siswa['id'] == $ortu['id']) {
                                                                            $already_connected = true;
                                                                            break;
                                                                        }
                                                                    }

                                                                    if (!$already_connected): ?>
                                                                                <option value="<?php echo $ortu['id']; ?>">
                                                                                    <?php echo htmlspecialchars($ortu['nama_ortu']); ?>
                                                                                    (<?php echo $ortu['email']; ?>)
                                                                                </option>
                                                                        <?php endif;
                                                                endforeach; ?>
                                                            </select>
                                                            <small class="text-gray-500 text-xs">Pilih orang tua yang sudah terdaftar di
                                                                sistem</small>
                                                        </div>
                                                    </div>
                                                </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Info -->
                            <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                <p class="text-sm text-blue-700">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <strong>Catatan:</strong><br>
                                    1. Edit data orang tua yang sudah terhubung dengan siswa.<br>
                                    2. Untuk ganti password, isi field password di masing-masing orang tua.<br>
                                    3. Kosongkan field password jika tidak ingin mengganti password.
                                </p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" onclick="closeModal()"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                                <i class="fas fa-times mr-2"></i> Batal
                            </button>
                            <button type="submit"
                                class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 flex items-center">
                                <i class="fas fa-save mr-2"></i> Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
    <?php endif; ?>


    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>

        // Dropdown functionality
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
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
        document.addEventListener('click', function (e) {
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

        // Fungsi untuk menutup modal DAN mempertahankan filter
        function closeModal() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.animation = 'fadeOut 0.3s';
                setTimeout(() => {
                    modal.style.display = 'none';

                    // Hapus parameter dari URL tanpa reload
                    const url = new URL(window.location.href);
                    const params = new URLSearchParams(url.search);

                    // Hapus hanya action dan id, tapi pertahankan filter
                    params.delete('action');
                    params.delete('id');

                    // Update URL tanpa reload halaman
                    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                    window.history.replaceState({}, '', newUrl);

                }, 250);
            });
        }

        // Event listener untuk klik di luar modal
        document.addEventListener('click', function (event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (modal.style.display === 'block' && event.target === modal) {
                    closeModal();
                }
            });
        });

        // Event listener untuk tombol ESC
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style="display: block;"]');
                if (modals.length > 0) {
                    closeModal();
                }
            }
        });

        // Program Option Buttons
        document.addEventListener('DOMContentLoaded', function () {
            // Program option buttons
            const programOptionBtns = document.querySelectorAll('.program-option-btn');
            const jenisKelasInput = document.getElementById('jenis_kelas');

            programOptionBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    // Remove active class from all buttons
                    programOptionBtns.forEach(b => b.classList.remove('active'));

                    // Add active class to clicked button
                    this.classList.add('active');

                    // Set the program type value
                    const programType = this.getAttribute('data-program-type');
                    jenisKelasInput.value = programType;
                });
            });

            // Initialize existing program type
            const currentProgram = jenisKelasInput.value;
            if (currentProgram) {
                document.querySelectorAll('.program-option-btn').forEach(btn => {
                    if (btn.getAttribute('data-program-type') === currentProgram) {
                        btn.classList.add('active');
                    }
                });
            }
        });

        // Fungsi untuk toggle form orangtua
        function toggleOrangtuaForm() {
            var modeBaru = document.getElementById('mode_baru').checked;

            if (modeBaru) {
                document.getElementById('form_ortu_baru').style.display = 'block';
                if (document.getElementById('pilih_ortu_existing')) {
                    document.getElementById('pilih_ortu_existing').style.display = 'none';
                }
            } else {
                document.getElementById('form_ortu_baru').style.display = 'none';
                if (document.getElementById('pilih_ortu_existing')) {
                    document.getElementById('pilih_ortu_existing').style.display = 'block';
                }
            }
        }

        // Fungsi untuk toggle show/hide password
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '-icon');

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Confirm Delete
        function confirmDelete(siswaId, siswaName) {
            if (confirm(`Apakah Anda yakin ingin menghapus siswa "${siswaName}"?\n\nPerhatian: Data yang dihapus tidak dapat dikembalikan.`)) {
                window.location.href = `dataSiswa.php?action=hapus&id=${siswaId}`;
            }
        }
    </script>
</body>

</html>
<?php
$conn->close();
?>