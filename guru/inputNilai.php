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

if ($_SESSION['user_role'] != 'guru') {
    header('Location: ../auth/login.php?error=unauthorized');
    exit();
}

// AMBIL guru_id dari database
$user_id = $_SESSION['user_id'];
$guru_id = 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// AMBIL GURU ID DENGAN ERROR HANDLING
try {
    $sql_guru = "SELECT g.id FROM guru g WHERE g.user_id = ? AND g.status = 'aktif' LIMIT 1";
    $stmt = $conn->prepare($sql_guru);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $guru_id = $row['id'];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error fetching guru_id: " . $e->getMessage());
}

// VARIABEL
$success_message = '';
$error_message = '';
$siswa_id = $_GET['siswa_id'] ?? 0;
$siswa_data = null;
$siswa_pelajaran_options = [];
$siswa_options = [];

// CEK SESSION NOTIFICATION
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// AMBIL DATA SISWA YANG DIAJAR OLEH GURU INI
if ($guru_id > 0) {
    try {
        // Query untuk mengambil data siswa yang diajar guru ini
        $sql_siswa_list = "SELECT DISTINCT 
                                s.id, 
                                s.nama_lengkap, 
                                s.kelas as kelas_sekolah
                          FROM siswa s
                          INNER JOIN siswa_pelajaran sp ON s.id = sp.siswa_id 
                          INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                          WHERE sp.guru_id = ? 
                            AND sp.status = 'aktif'
                            AND ps.status = 'aktif'
                            AND s.status = 'aktif'
                          ORDER BY s.nama_lengkap";

        $stmt = $conn->prepare($sql_siswa_list);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $siswa_options[] = $row;
            }
            $stmt->close();
        }
        
        // Query untuk mengambil data mata pelajaran yang diajar oleh guru ini
        $sql_pelajaran = "SELECT 
                            sp.id as siswa_pelajaran_id,
                            sp.nama_pelajaran,
                            sp.siswa_id,
                            ps.tingkat,
                            ps.jenis_kelas,
                            s.nama_lengkap
                          FROM siswa_pelajaran sp
                          INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                          INNER JOIN siswa s ON sp.siswa_id = s.id
                          WHERE sp.guru_id = ? 
                            AND sp.status = 'aktif'
                            AND ps.status = 'aktif'
                          ORDER BY s.nama_lengkap, sp.nama_pelajaran";

        $stmt = $conn->prepare($sql_pelajaran);
        if ($stmt) {
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $siswa_pelajaran_options[] = $row;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        $error_message = "❌ Error mengambil data siswa: " . $e->getMessage();
    }
}

// AMBIL DATA SISWA DETAIL JIKA ADA siswa_id DI URL
if ($siswa_id > 0) {
    try {
        $sql_siswa = "SELECT s.*, 
                             sp.id as siswa_pelajaran_id,
                             sp.nama_pelajaran,
                             ps.tingkat,
                             ps.jenis_kelas
                      FROM siswa s
                      LEFT JOIN siswa_pelajaran sp ON s.id = sp.siswa_id AND sp.status = 'aktif'
                      LEFT JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id AND ps.status = 'aktif'
                      WHERE s.id = ? 
                      LIMIT 1";

        $stmt = $conn->prepare($sql_siswa);
        $stmt->bind_param("i", $siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $siswa_data = $result->fetch_assoc();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error fetching siswa data: " . $e->getMessage());
    }
}

// PROSES SIMPAN PENILAIAN
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_penilaian'])) {
    
    $siswa_id_post = intval($_POST['siswa_id'] ?? 0);
    $siswa_pelajaran_id = intval($_POST['siswa_pelajaran_id'] ?? 0);
    
    // VALIDASI
    if ($siswa_id_post == 0 || $siswa_pelajaran_id == 0) {
        $_SESSION['error_message'] = "❌ Pilih siswa dan mata pelajaran terlebih dahulu!";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } elseif ($guru_id == 0) {
        $_SESSION['error_message'] = "❌ Data guru tidak valid. Hubungi administrator.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } else {
        try {
            // Ambil pendaftaran_id dari siswa_pelajaran
            $sql_get_pendaftaran = "SELECT pendaftaran_id FROM siswa_pelajaran WHERE id = ? AND siswa_id = ? AND guru_id = ?";
            $stmt = $conn->prepare($sql_get_pendaftaran);
            $stmt->bind_param("iii", $siswa_pelajaran_id, $siswa_id_post, $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $pendaftaran_id = $row['pendaftaran_id'];
            } else {
                throw new Exception("Data mata pelajaran tidak valid untuk siswa ini.");
            }
            $stmt->close();
            
            // Lanjutkan dengan penyimpanan penilaian
            $nilai_fields = [
                'willingness_learn',
                'problem_solving',
                'critical_thinking',
                'concentration',
                'independence'
            ];

            $nilai_values = [];
            $total_score = 0;

            foreach ($nilai_fields as $field) {
                $value = intval($_POST[$field] ?? 1);
                if ($value < 1) $value = 1;
                if ($value > 10) $value = 10;
                $nilai_values[$field] = $value;
                $total_score += $value;
            }

            $persentase = round(($total_score / 50) * 100);

            if ($persentase >= 80) {
                $kategori = 'Sangat Baik';
            } elseif ($persentase >= 60) {
                $kategori = 'Baik';
            } elseif ($persentase >= 40) {
                $kategori = 'Cukup';
            } else {
                $kategori = 'Kurang';
            }

            $tanggal = $_POST['tanggal_penilaian'] ?? date('Y-m-d');
            $periode = trim($_POST['periode_penilaian'] ?? '');
            $catatan = trim($_POST['catatan_guru'] ?? '');
            $rekomendasi = trim($_POST['rekomendasi'] ?? '');

            // Update query untuk menambahkan siswa_pelajaran_id
            $sql = "INSERT INTO penilaian_siswa (
                        siswa_id, pendaftaran_id, siswa_pelajaran_id, guru_id, tanggal_penilaian, periode_penilaian,
                        willingness_learn, problem_solving, critical_thinking, 
                        concentration, independence, total_score, persentase, kategori,
                        catatan_guru, rekomendasi, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param(
                    "iiiissiiiiiiisss",
                    $siswa_id_post,
                    $pendaftaran_id,
                    $siswa_pelajaran_id,
                    $guru_id,
                    $tanggal,
                    $periode,
                    $nilai_values['willingness_learn'],
                    $nilai_values['problem_solving'],
                    $nilai_values['critical_thinking'],
                    $nilai_values['concentration'],
                    $nilai_values['independence'],
                    $total_score,
                    $persentase,
                    $kategori,
                    $catatan,
                    $rekomendasi
                );

                if ($stmt->execute()) {
                    // SIMPAN NOTIFIKASI DI SESSION
                    $_SESSION['success_message'] = "✅ Penilaian berhasil disimpan!";
                    
                    // Redirect ke halaman SAMA (clear POST data)
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?siswa_id=' . $siswa_id_post);
                    exit();
                } else {
                    throw new Exception("Database error: " . $stmt->error);
                }
                
                $stmt->close();
            } else {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }

        } catch (Exception $e) {
            $_SESSION['error_message'] = "❌ " . $e->getMessage();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Penilaian - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .rating-input {
            width: 70px;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            text-align: center;
            font-size: 16px;
        }

        .rating-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .indicator-box {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s;
        }

        .indicator-box:hover {
            border-color: #93c5fd;
            background-color: #f8fafc;
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

        .indicator-label {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .indicator-desc {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 12px;
        }
        
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        .rating-input::-webkit-inner-spin-button,
        .rating-input::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .rating-input {
            -moz-appearance: textfield;
        }

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
            
            .rating-input {
                width: 60px !important;
                padding: 6px !important;
            }
            
            .indicator-box {
                padding: 12px !important;
                margin-bottom: 12px !important;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        .rating-input.error {
            border-color: #ef4444;
            background-color: #fef2f2;
        }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Guru</p>
        </div>

        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Guru</p>
                </div>
            </div>
        </div>

        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'guru'); ?>
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
                    <p class="text-xs text-blue-300">Guru</p>
                </div>
                <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
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
                        <i class="fas fa-user text-lg"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Guru</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'guru'); ?>
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
                    <h1 class="text-2xl font-bold text-gray-800">Input Penilaian Siswa</h1>
                    <p class="text-gray-600">Isi form penilaian untuk siswa bimbingan</p>
                </div>
                <div class="mt-2 md:mt-0 text-right">
                    <a href="riwayat.php" class="inline-flex items-center px-3 py-2 rounded-md text-sm font-medium bg-blue-100 text-blue-800 hover:bg-blue-200">
                        <i class="fas fa-history mr-2"></i> Lihat Riwayat
                    </a>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- NOTIFICATION SECTION -->
            <?php if ($success_message): ?>
                <div class="animate-fade-in mb-4">
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span><?php echo $success_message; ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="animate-fade-in mb-4">
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span><?php echo $error_message; ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Form Penilaian -->
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" 
                  class="bg-white rounded-xl shadow-lg p-6" id="penilaianForm">
                
                <!-- Pilih Siswa & Mata Pelajaran -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 bg-blue-50 rounded-lg">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Pilih Siswa *</label>
                        <select name="siswa_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            id="selectSiswa">
                            <option value="">Pilih Siswa</option>
                            <?php foreach ($siswa_options as $siswa): 
                                $selected = ($siswa_id == $siswa['id']) ? 'selected' : '';
                                ?>
                                <option value="<?php echo $siswa['id']; ?>"
                                    <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars(
                                        $siswa['nama_lengkap'] . 
                                        ' (' . $siswa['kelas_sekolah'] . ')'
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($siswa_options) && $guru_id > 0): ?>
                            <p class="text-red-500 text-sm mt-1">
                                ❌ Tidak ada siswa yang diajar. Periksa jadwal mengajar Anda.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Pilih Mata Pelajaran *</label>
                        <select name="siswa_pelajaran_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            id="selectPelajaran">
                            <option value="">-- Pilih siswa terlebih dahulu --</option>
                            <?php if (!empty($siswa_pelajaran_options)): ?>
                                <?php foreach ($siswa_pelajaran_options as $pelajaran):
                                    $selected = (isset($siswa_data['siswa_pelajaran_id']) && $siswa_data['siswa_pelajaran_id'] == $pelajaran['siswa_pelajaran_id']) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $pelajaran['siswa_pelajaran_id']; ?>" 
                                            data-siswa-id="<?php echo $pelajaran['siswa_id']; ?>"
                                            <?php echo $selected; ?>
                                            class="pelajaran-option hidden">
                                        <?php echo htmlspecialchars(
                                            $pelajaran['nama_pelajaran'] . 
                                            ' - ' . $pelajaran['tingkat'] . 
                                            ' (' . $pelajaran['jenis_kelas'] . ') - ' .
                                            $pelajaran['nama_lengkap']
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <?php if (empty($siswa_pelajaran_options) && $guru_id > 0): ?>
                            <p class="text-red-500 text-sm mt-1">
                                ❌ Tidak ada mata pelajaran yang diajar.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Tanggal Penilaian *</label>
                        <input type="date" name="tanggal_penilaian" required value="<?php echo date('Y-m-d'); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Periode Penilaian</label>
                        <input type="text" name="periode_penilaian" value="<?php echo date('F Y'); ?>"
                            placeholder="Contoh: Bulan Januari 2024"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <!-- Info Siswa -->
                <?php if ($siswa_data): ?>
                    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Data Siswa</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Nama</p>
                                <p class="font-medium">
                                    <?php echo htmlspecialchars($siswa_data['nama_lengkap']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Kelas Sekolah</p>
                                <p class="font-medium">
                                    <?php echo htmlspecialchars($siswa_data['kelas']); ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Mata Pelajaran</p>
                                <p class="font-medium">
                                    <?php echo htmlspecialchars($siswa_data['nama_pelajaran'] ?? '-'); ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Tingkat & Jenis</p>
                                <p class="font-medium">
                                    <?php 
                                    if (isset($siswa_data['tingkat']) && isset($siswa_data['jenis_kelas'])) {
                                        echo htmlspecialchars($siswa_data['tingkat'] . ' - ' . $siswa_data['jenis_kelas']);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Skala Penilaian Info -->
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Skala Penilaian</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-red-500 rounded mr-2"></div>
                            <span>1-3: Kurang</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-yellow-500 rounded mr-2"></div>
                            <span>4-5: Cukup</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-blue-500 rounded mr-2"></div>
                            <span>6-7: Baik</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-green-500 rounded mr-2"></div>
                            <span>8-9: Sangat Baik</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-4 h-4 bg-purple-500 rounded mr-2"></div>
                            <span>10: Luar Biasa</span>
                        </div>
                    </div>
                </div>

                <!-- Indikator Penilaian -->
                <div class="space-y-6">
                    <h2 class="text-xl font-bold text-gray-800 border-b pb-2">Indikator Penilaian</h2>

                    <div>
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Soft Skills</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Willingness to Learn -->
                            <div class="indicator-box">
                                <div class="indicator-label">Willingness to Learn</div>
                                <div class="indicator-desc">Kemauan dan antusiasme untuk belajar hal baru</div>
                                <div class="flex items-center justify-between">
                                    <input type="number" name="willingness_learn" min="1" max="10" step="1" value="1"
                                        class="rating-input" required oninput="validateInput(this)">
                                    <div class="text-sm text-gray-500">Skala 1-10</div>
                                </div>
                            </div>

                            <!-- Problem Solving -->
                            <div class="indicator-box">
                                <div class="indicator-label">Problem Solving</div>
                                <div class="indicator-desc">Kemampuan menganalisis dan memecahkan masalah</div>
                                <div class="flex items-center justify-between">
                                    <input type="number" name="problem_solving" min="1" max="10" step="1" value="1"
                                        class="rating-input" required oninput="validateInput(this)">
                                    <div class="text-sm text-gray-500">Skala 1-10</div>
                                </div>
                            </div>

                            <!-- Critical Thinking -->
                            <div class="indicator-box">
                                <div class="indicator-label">Critical Thinking</div>
                                <div class="indicator-desc">Berpikir kritis, logis, dan analitis</div>
                                <div class="flex items-center justify-between">
                                    <input type="number" name="critical_thinking" min="1" max="10" step="1" value="1"
                                        class="rating-input" required oninput="validateInput(this)">
                                    <div class="text-sm text-gray-500">Skala 1-10</div>
                                </div>
                            </div>

                            <!-- Concentration -->
                            <div class="indicator-box">
                                <div class="indicator-label">Concentration</div>
                                <div class="indicator-desc">Fokus dan konsentrasi selama pembelajaran</div>
                                <div class="flex items-center justify-between">
                                    <input type="number" name="concentration" min="1" max="10" step="1" value="1"
                                        class="rating-input" required oninput="validateInput(this)">
                                    <div class="text-sm text-gray-500">Skala 1-10</div>
                                </div>
                            </div>
                            
                        </div>
                        <!-- Independence -->
                            <div class="indicator-box">
                                <div class="indicator-label">Independence</div>
                                <div class="indicator-desc">Kemandirian dalam belajar dan menyelesaikan tugas</div>
                                <div class="flex items-center justify-between">
                                    <input type="number" name="independence" min="1" max="10" step="1" value="1"
                                        class="rating-input" required oninput="validateInput(this)">
                                    <div class="text-sm text-gray-500">Skala 1-10</div>
                                </div>
                            </div>
                    </div>

                    <!-- Catatan dan Rekomendasi -->
                    <div class="mt-8 space-y-4">
                        <h2 class="text-xl font-bold text-gray-800 border-b pb-2">Catatan dan Rekomendasi</h2>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Catatan Guru</label>
                            <textarea name="catatan_guru" rows="3"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Catatan khusus tentang perkembangan siswa..."></textarea>
                        </div>

                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rekomendasi</label>
                            <textarea name="rekomendasi" rows="3"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Rekomendasi untuk perbaikan berikutnya..."></textarea>
                        </div>
                    </div>

                    <!-- Preview Total Skor -->
                    <div class="mt-8 p-6 bg-gray-50 border border-gray-200 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Preview Penilaian</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="text-center">
                                <p class="text-sm text-gray-600">Total Skor</p>
                                <p id="totalSkorPreview" class="text-3xl font-bold text-blue-600">25</p>
                                <p class="text-sm text-gray-500">dari 50</p>
                            </div>
                            <div class="text-center">
                                <p class="text-sm text-gray-600">Persentase</p>
                                <p id="persentasePreview" class="text-3xl font-bold text-green-600">50%</p>
                                <p class="text-sm text-gray-500">Nilai keseluruhan</p>
                            </div>
                            <div class="text-center">
                                <p class="text-sm text-gray-600">Kategori</p>
                                <p id="kategoriPreview" class="text-2xl font-bold text-yellow-600">Cukup</p>
                                <p class="text-sm text-gray-500">Hasil penilaian</p>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol Aksi -->
                    <div class="mt-8 flex flex-col md:flex-row justify-end space-y-4 md:space-y-0 md:space-x-4 pt-6 border-t">
                        <button type="button" id="resetButton"
                            class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Reset Form
                        </button>
                        
                        <!-- Tombol submit utama -->
                        <button type="submit" name="simpan_penilaian" id="submitButton"
                            class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-300 flex items-center justify-center">
                            <i class="fas fa-save mr-2"></i> Simpan Penilaian
                        </button>
                    </div>
                </div>
            </form>

            <!-- Info Sistem -->
            <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h3 class="text-lg font-semibold text-blue-800 mb-2">
                    <i class="fas fa-info-circle mr-2"></i> Informasi Sistem
                </h3>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li>• Sistem mengambil data siswa dari <strong>mata pelajaran</strong> yang Anda ajar</li>
                    <li>• Total maksimal skor: <strong>50</strong> (5 indikator × 10)</li>
                    <li>• Kategori: &lt;40% (Kurang), 40-59% (Cukup), 60-79% (Baik), ≥80% (Sangat Baik)</li>
                    <li>• Data penilaian dapat dilihat di menu "Riwayat"</li>
                </ul>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Input Penilaian</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Last update: <?php echo date('d F Y H:i'); ?>
                        </p>
                    </div>
                    <div class="mt-3 md:mt-0">
                        <div class="flex items-center space-x-4">
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <i class="fas fa-clock mr-1"></i>
                                <span id="serverTime"><?php echo date('H:i:s'); ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script>
        // MOBILE MENU
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

        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });

        // FUNGSI PENILAIAN
        function validateInput(input) {
            let value = parseInt(input.value) || 5;

            if (value < 1) {
                value = 1;
            } else if (value > 10) {
                value = 10;
                input.classList.add('error');
                setTimeout(() => input.classList.remove('error'), 500);
            }

            input.value = value;
            updatePreview();
        }

        function hitungTotalSkor() {
            const fields = [
                'willingness_learn',
                'problem_solving',
                'critical_thinking',
                'concentration',
                'independence'
            ];

            let total = 0;
            fields.forEach(field => {
                const input = document.querySelector(`[name="${field}"]`);
                if (input) {
                    const value = parseInt(input.value) || 0;
                    total += value;
                }
            });

            return total;
        }

        function updatePreview() {
            const total = hitungTotalSkor();
            const persentase = Math.round((total / 50) * 100);

            let kategori = '-';
            let kategoriColor = 'text-gray-600';

            if (persentase >= 80) {
                kategori = 'Sangat Baik';
                kategoriColor = 'text-green-600';
            } else if (persentase >= 60) {
                kategori = 'Baik';
                kategoriColor = 'text-blue-600';
            } else if (persentase >= 40) {
                kategori = 'Cukup';
                kategoriColor = 'text-yellow-600';
            } else if (persentase > 0) {
                kategori = 'Kurang';
                kategoriColor = 'text-red-600';
            }

            document.getElementById('totalSkorPreview').textContent = total;
            document.getElementById('persentasePreview').textContent = persentase + '%';

            const kategoriElement = document.getElementById('kategoriPreview');
            kategoriElement.textContent = kategori;
            kategoriElement.className = `text-2xl font-bold ${kategoriColor}`;
        }

        document.querySelectorAll('.rating-input').forEach(input => {
            input.addEventListener('input', function() {
                validateInput(this);
            });

            input.addEventListener('keydown', function(e) {
                if (['e', 'E', '+', '-'].includes(e.key)) {
                    e.preventDefault();
                }
            });
        });

        document.getElementById('resetButton').addEventListener('click', function() {
            if (confirm('Apakah Anda yakin ingin mereset form? Semua data yang telah diisi akan hilang.')) {
                document.querySelectorAll('.rating-input').forEach(input => {
                    input.value = 5;
                });

                document.querySelectorAll('textarea').forEach(textarea => {
                    textarea.value = '';
                });

                document.querySelector('input[name="tanggal_penilaian"]').value = 
                    new Date().toISOString().split('T')[0];

                const now = new Date();
                const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                document.querySelector('input[name="periode_penilaian"]').value = 
                    `${monthNames[now.getMonth()]} ${now.getFullYear()}`;

                updatePreview();
            }
        });

        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('serverTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        // FILTER MATA PELAJARAN BERDASARKAN SISWA YANG DIPILIH
        document.getElementById('selectSiswa').addEventListener('change', function() {
            const siswaId = this.value;
            const pelajaranSelect = document.getElementById('selectPelajaran');
            const pelajaranOptions = pelajaranSelect.querySelectorAll('.pelajaran-option');
            
            if (siswaId) {
                // Reset dan sembunyikan semua option
                pelajaranSelect.innerHTML = '<option value="">Pilih Mata Pelajaran</option>';
                
                // Tampilkan hanya option yang sesuai dengan siswa_id
                pelajaranOptions.forEach(option => {
                    if (option.getAttribute('data-siswa-id') === siswaId) {
                        const newOption = document.createElement('option');
                        newOption.value = option.value;
                        newOption.textContent = option.textContent;
                        pelajaranSelect.appendChild(newOption);
                    }
                });
                
                // Jika tidak ada mata pelajaran untuk siswa ini
                if (pelajaranSelect.options.length === 1) {
                    pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran untuk siswa ini</option>';
                }
            } else {
                pelajaranSelect.innerHTML = '<option value="">-- Pilih siswa terlebih dahulu --</option>';
            }
        });

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

        // Inisialisasi saat halaman dimuat
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
            setInterval(updateServerTime, 1000);
            
            // Jika ada siswa_id di URL, trigger change event
            const selectSiswa = document.getElementById('selectSiswa');
            if (selectSiswa.value) {
                selectSiswa.dispatchEvent(new Event('change'));
            }
        });
    </script>
</body>
</html>