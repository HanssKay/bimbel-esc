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

// AMBIL DATA SISWA YANG DIAJAR OLEH GURU INI (untuk autocomplete)
if ($guru_id > 0) {
    try {
        // Query untuk mengambil data siswa yang diajar guru ini (hanya nama)
        $sql_siswa_list = "SELECT DISTINCT 
                                s.id, 
                                s.nama_lengkap
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
    } catch (Exception $e) {
        $error_message = "❌ Error mengambil data siswa: " . $e->getMessage();
    }
}

// AJAX Handler untuk autocomplete siswa
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_siswa_list') {
    header('Content-Type: application/json');
    
    $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
    $filtered_siswa = [];
    
    if (!empty($search) && $guru_id > 0) {
        $sql_search = "SELECT DISTINCT 
                            s.id, 
                            s.nama_lengkap
                      FROM siswa s
                      INNER JOIN siswa_pelajaran sp ON s.id = sp.siswa_id 
                      INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                      WHERE sp.guru_id = $guru_id 
                        AND sp.status = 'aktif'
                        AND ps.status = 'aktif'
                        AND s.status = 'aktif'
                        AND (s.nama_lengkap LIKE '%$search%')
                      ORDER BY s.nama_lengkap
                      LIMIT 20";
        
        $result_search = $conn->query($sql_search);
        if ($result_search) {
            $filtered_siswa = $result_search->fetch_all(MYSQLI_ASSOC);
        }
    } else {
        $filtered_siswa = $siswa_options;
    }
    
    echo json_encode($filtered_siswa);
    exit();
}

// AJAX Handler untuk mengambil mata pelajaran berdasarkan siswa_id
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_pelajaran_by_siswa' && isset($_GET['siswa_id'])) {
    header('Content-Type: application/json');
    
    $siswa_id = intval($_GET['siswa_id']);
    $pelajaran_data = [];
    
    if ($siswa_id > 0 && $guru_id > 0) {
        $sql_pelajaran = "SELECT 
                            sp.id as siswa_pelajaran_id,
                            sp.nama_pelajaran,
                            ps.tingkat,
                            ps.jenis_kelas,
                            s.nama_lengkap
                          FROM siswa_pelajaran sp
                          INNER JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                          INNER JOIN siswa s ON sp.siswa_id = s.id
                          WHERE sp.siswa_id = ? 
                            AND sp.guru_id = ?
                            AND sp.status = 'aktif'
                            AND ps.status = 'aktif'
                          ORDER BY sp.nama_pelajaran";
        
        $stmt = $conn->prepare($sql_pelajaran);
        if ($stmt) {
            $stmt->bind_param("ii", $siswa_id, $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $pelajaran_data[] = $row;
            }
            $stmt->close();
        }
    }
    
    echo json_encode($pelajaran_data);
    exit();
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
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        /* CSS untuk autocomplete */
        .autocomplete-container {
            position: relative;
            width: 100%;
        }

        .autocomplete-input {
            width: 100%;
            padding: 0.75rem 2.5rem 0.75rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .autocomplete-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .autocomplete-clear {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            display: none;
        }

        .autocomplete-clear:hover {
            color: #6b7280;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: none;
        }

        .autocomplete-item {
            padding: 0.75rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .autocomplete-item:last-child {
            border-bottom: none;
        }

        .autocomplete-item:hover {
            background-color: #f9fafb;
        }

        .autocomplete-item.active {
            background-color: #eff6ff;
        }

        .autocomplete-item .siswa-nama {
            font-weight: 600;
            color: #1f2937;
            padding: 0.5rem;
        }

        .autocomplete-item .siswa-info {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }

        .no-results {
            padding: 1rem;
            text-align: center;
            color: #6b7280;
            font-style: italic;
        }

        /* Info siswa yang dipilih */
        .selected-siswa-info {
            border-left: 4px solid #3B82F6;
            background-color: #EFF6FF;
            margin-top: 0.5rem;
            border-radius: 0.5rem;
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
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

            .autocomplete-dropdown {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 90%;
                max-height: 80vh;
                z-index: 1002;
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
                    <p class="text-gray-600">Isi form penilaian untuk siswa bimbel</p>
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
                    <!-- Pilih Siswa dengan Autocomplete -->
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">
                            Cari Siswa <span class="text-red-500">*</span>
                            <span class="text-xs text-gray-500">(ketik nama siswa)</span>
                        </label>

                        <div class="autocomplete-container">
                            <input type="text" id="searchSiswa" class="autocomplete-input"
                                placeholder="Ketik nama siswa..." autocomplete="off">
                            <input type="hidden" name="siswa_id" id="selectedSiswaId">
                            <button type="button" id="clearSearch" class="autocomplete-clear">
                                <i class="fas fa-times"></i>
                            </button>
                            <div id="siswaDropdown" class="autocomplete-dropdown"></div>
                        </div>

                        <!-- Info siswa yang dipilih -->
                        <div id="selectedSiswaInfo" class="selected-siswa-info mt-2 p-3 hidden">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-medium text-gray-900" id="selectedSiswaName"></div>
                                </div>
                                <button type="button" onclick="clearSelectedSiswa()"
                                    class="text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pilih Mata Pelajaran -->
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Pilih Mata Pelajaran *</label>
                        <select name="siswa_pelajaran_id" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            id="selectPelajaran">
                            <option value="">-- Pilih siswa terlebih dahulu --</option>
                        </select>
                        <div id="loadingPelajaran" class="hidden text-sm text-gray-500 mt-1">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Memuat mata pelajaran...
                        </div>
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
        // ==================== VARIABEL GLOBAL ====================
        let searchTimeout;
        let searchCache = {};

        // ==================== FUNGSI AUTOSEARCH ====================
        // Inisialisasi autocomplete saat DOM ready
        $(document).ready(function () {
            initAutocomplete();
            
            // Jika ada siswa_id di URL, load data siswa
            const urlParams = new URLSearchParams(window.location.search);
            const siswaId = urlParams.get('siswa_id');
            if (siswaId) {
                // Cari data siswa dari options
                const siswaOptions = <?php echo json_encode($siswa_options); ?>;
                const siswa = siswaOptions.find(s => s.id == siswaId);
                if (siswa) {
                    setTimeout(() => {
                        selectSiswa({
                            id: siswa.id,
                            nama: siswa.nama_lengkap
                        });
                    }, 500);
                }
            }
            
            // Update preview saat halaman dimuat
            updatePreview();
        });

        function initAutocomplete() {
            const searchInput = document.getElementById('searchSiswa');
            const clearButton = document.getElementById('clearSearch');
            const dropdown = document.getElementById('siswaDropdown');

            let selectedIndex = -1;

            // Tampilkan dropdown saat fokus
            searchInput.addEventListener('focus', function () {
                if (this.value.length > 0) {
                    filterSiswa(this.value);
                }
            });

            // Filter siswa saat mengetik dengan debounce
            searchInput.addEventListener('input', function (e) {
                clearTimeout(searchTimeout);
                const query = this.value;

                if (query.length < 2) {
                    dropdown.style.display = 'none';
                    clearButton.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(() => {
                    filterSiswa(query);
                }, 300);

                clearButton.style.display = query.length > 0 ? 'block' : 'none';
            });

            // Clear search
            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                clearButton.style.display = 'none';
                dropdown.style.display = 'none';
                clearSelectedSiswa();
            });

            // Navigasi dengan keyboard
            searchInput.addEventListener('keydown', function (e) {
                const items = dropdown.querySelectorAll('.autocomplete-item');

                switch (e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelectedItem();
                        break;

                    case 'ArrowUp':
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, -1);
                        updateSelectedItem();
                        break;

                    case 'Enter':
                        e.preventDefault();
                        if (selectedIndex >= 0 && items[selectedIndex]) {
                            selectSiswa(items[selectedIndex].dataset);
                        }
                        break;

                    case 'Escape':
                        dropdown.style.display = 'none';
                        selectedIndex = -1;
                        break;
                }
            });

            // Close dropdown saat klik di luar
            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.style.display = 'none';
                }
            });
        }

        // Fungsi filter siswa dengan AJAX
        function filterSiswa(query) {
            const dropdown = document.getElementById('siswaDropdown');
            
            // Tampilkan loading
            dropdown.innerHTML = '<div class="no-results"><span class="spinner"></span> Mencari...</div>';
            dropdown.style.display = 'block';

            $.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: {
                    ajax: 'get_siswa_list',
                    search: query
                },
                dataType: 'json',
                success: function (data) {
                    renderDropdown(data);
                },
                error: function () {
                    dropdown.innerHTML = '<div class="no-results">Gagal memuat data</div>';
                }
            });
        }

        // Render dropdown - hanya nama
        function renderDropdown(data) {
            const dropdown = document.getElementById('siswaDropdown');
            dropdown.innerHTML = '';

            if (data.length === 0) {
                dropdown.innerHTML = '<div class="no-results">Tidak ditemukan siswa</div>';
                dropdown.style.display = 'block';
                return;
            }

            data.forEach((siswa, index) => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.dataset.id = siswa.id;
                item.dataset.nama = siswa.nama_lengkap;

                item.innerHTML = `
                    <div class="siswa-nama">${siswa.nama_lengkap}</div>
                `;

                item.addEventListener('click', function () {
                    selectSiswa(this.dataset);
                });

                item.addEventListener('mouseenter', function () {
                    const items = dropdown.querySelectorAll('.autocomplete-item');
                    selectedIndex = Array.from(items).indexOf(this);
                    updateSelectedItem();
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
            selectedIndex = -1;
        }

        // Update selected item style
        function updateSelectedItem() {
            const dropdown = document.getElementById('siswaDropdown');
            const items = dropdown.querySelectorAll('.autocomplete-item');

            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.classList.add('active');
                    // Scroll ke item yang dipilih
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('active');
                }
            });
        }

        // Fungsi untuk load mata pelajaran berdasarkan siswa_id
        function loadMataPelajaran(siswaId) {
            const pelajaranSelect = document.getElementById('selectPelajaran');
            const loadingElement = document.getElementById('loadingPelajaran');
            
            // Tampilkan loading
            pelajaranSelect.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
            pelajaranSelect.disabled = true;
            loadingElement.classList.remove('hidden');
            
            $.ajax({
                url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                type: 'GET',
                data: {
                    ajax: 'get_pelajaran_by_siswa',
                    siswa_id: siswaId
                },
                dataType: 'json',
                success: function (data) {
                    pelajaranSelect.innerHTML = '<option value="">Pilih Mata Pelajaran</option>';
                    
                    if (data.length === 0) {
                        pelajaranSelect.innerHTML = '<option value="">Tidak ada mata pelajaran untuk siswa ini</option>';
                    } else {
                        data.forEach(function(pelajaran) {
                            const option = document.createElement('option');
                            option.value = pelajaran.siswa_pelajaran_id;
                            option.textContent = pelajaran.nama_pelajaran + 
                                                ' - ' + pelajaran.tingkat + 
                                                ' (' + pelajaran.jenis_kelas + ')';
                            pelajaranSelect.appendChild(option);
                        });
                    }
                    
                    pelajaranSelect.disabled = false;
                    loadingElement.classList.add('hidden');
                },
                error: function () {
                    pelajaranSelect.innerHTML = '<option value="">Gagal memuat mata pelajaran</option>';
                    pelajaranSelect.disabled = false;
                    loadingElement.classList.add('hidden');
                }
            });
        }

        // Fungsi pilih siswa
        function selectSiswa(data) {
            const selectedSiswaId = document.getElementById('selectedSiswaId');
            const searchInput = document.getElementById('searchSiswa');
            const dropdown = document.getElementById('siswaDropdown');
            const pelajaranSelect = document.getElementById('selectPelajaran');

            selectedSiswaId.value = data.id;
            searchInput.value = data.nama;
            dropdown.style.display = 'none';

            // Tampilkan info siswa yang dipilih
            document.getElementById('selectedSiswaName').textContent = data.nama;
            document.getElementById('selectedSiswaInfo').classList.remove('hidden');
            document.getElementById('clearSearch').style.display = 'none';

            // Load mata pelajaran via AJAX
            loadMataPelajaran(data.id);
        }

        // Fungsi clear selected siswa
        function clearSelectedSiswa() {
            document.getElementById('searchSiswa').value = '';
            document.getElementById('selectedSiswaId').value = '';
            document.getElementById('selectedSiswaInfo').classList.add('hidden');
            document.getElementById('clearSearch').style.display = 'none';
            
            // Reset mata pelajaran
            const pelajaranSelect = document.getElementById('selectPelajaran');
            pelajaranSelect.innerHTML = '<option value="">-- Pilih siswa terlebih dahulu --</option>';
            pelajaranSelect.disabled = false;
        }

        // ==================== FUNGSI PENILAIAN ====================
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

        // Inisialisasi event listeners untuk input nilai
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

        // Reset button
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

        // ==================== MOBILE MENU ====================
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

        // ==================== FUNGSI BANTU ====================
        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('serverTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Validasi form sebelum submit
        document.getElementById('penilaianForm').addEventListener('submit', function(e) {
            const siswaId = document.getElementById('selectedSiswaId').value;
            const pelajaranId = document.getElementById('selectPelajaran').value;

            if (!siswaId) {
                e.preventDefault();
                alert('Harap pilih siswa terlebih dahulu!');
                document.getElementById('searchSiswa').focus();
                return;
            }

            if (!pelajaranId) {
                e.preventDefault();
                alert('Harap pilih mata pelajaran terlebih dahulu!');
                document.getElementById('selectPelajaran').focus();
                return;
            }
        });

        // Inisialisasi waktu server
        setInterval(updateServerTime, 1000);

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
    </script>
</body>
</html>