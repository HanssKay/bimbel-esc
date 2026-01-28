<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
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

$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Guru';
$currentPage = basename($_SERVER['PHP_SELF']);

// Inisialisasi variabel
$message = '';
$message_type = ''; // success, error, warning
$profile_data = null;
$guru_data = null;
$guru_id = 0;

// Ambil data profil dari database
if ($user_id > 0) {
    try {
        // Ambil data dari tabel users
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $profile_data = $result->fetch_assoc();
        
        // Ambil data spesifik guru berdasarkan user_id
        $sql = "SELECT * FROM guru WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $guru_data = $result->fetch_assoc();
        
        if ($guru_data) {
            $guru_id = $guru_data['id'];
            
            // Hitung statistik: total siswa aktif yang diajar oleh guru ini melalui siswa_pelajaran
            $sql = "SELECT COUNT(DISTINCT sp.siswa_id) as total_siswa_aktif
                    FROM siswa_pelajaran sp
                    JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                    WHERE sp.guru_id = ? 
                    AND ps.status = 'aktif'
                    AND sp.status = 'aktif'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $guru_data['total_siswa'] = $stats['total_siswa_aktif'] ?? 0;
            
            // Hitung total penilaian yang dibuat oleh guru ini
            $sql = "SELECT COUNT(*) as total_penilaian
                    FROM penilaian_siswa 
                    WHERE guru_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats2 = $result->fetch_assoc();
            $guru_data['total_penilaian'] = $stats2['total_penilaian'] ?? 0;
            
            // Ambil tambahan info: mata pelajaran yang diajar dari siswa_pelajaran
            $sql = "SELECT DISTINCT sp.nama_pelajaran
                    FROM siswa_pelajaran sp
                    WHERE sp.guru_id = ? 
                    AND sp.status = 'aktif'
                    LIMIT 3";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $mata_pelajaran = [];
            while ($row = $result->fetch_assoc()) {
                $mata_pelajaran[] = $row['nama_pelajaran'];
            }
            $guru_data['mata_pelajaran'] = !empty($mata_pelajaran) ? implode(', ', $mata_pelajaran) : '-';
            
            // Hitung total kelas yang diajar (berdasarkan siswa_pelajaran aktif)
            $sql = "SELECT COUNT(DISTINCT sp.pendaftaran_id) as total_kelas
                    FROM siswa_pelajaran sp
                    JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                    WHERE sp.guru_id = ? 
                    AND sp.status = 'aktif'
                    AND ps.status = 'aktif'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $guru_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats3 = $result->fetch_assoc();
            $guru_data['total_kelas'] = $stats3['total_kelas'] ?? 0;
        }
    } catch (Exception $e) {
        error_log("Error fetching profile data: " . $e->getMessage());
        $message = "Gagal memuat data profil";
        $message_type = "error";
    }
}

// Tangani update profil
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'update_profile') {
        // Update data umum
        $full_name = $_POST['full_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        
        if (!empty($full_name)) {
            try {
                $sql = "UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $full_name, $phone, $address, $user_id);
                
                if ($stmt->execute()) {
                    $_SESSION['full_name'] = $full_name;
                    $message = "Profil berhasil diperbarui!";
                    $message_type = "success";
                    
                    // Refresh data
                    $sql = "SELECT * FROM users WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $profile_data = $result->fetch_assoc();
                } else {
                    $message = "Gagal memperbarui profil: " . $conn->error;
                    $message_type = "error";
                }
            } catch (Exception $e) {
                error_log("Error updating profile: " . $e->getMessage());
                $message = "Terjadi kesalahan saat memperbarui profil";
                $message_type = "error";
            }
        } else {
            $message = "Nama lengkap harus diisi!";
            $message_type = "error";
        }
    } 
    elseif ($action == 'update_password') {
        // Update password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = "Semua field password harus diisi!";
            $message_type = "error";
        } elseif ($new_password != $confirm_password) {
            $message = "Password baru tidak cocok!";
            $message_type = "error";
        } elseif (strlen($new_password) < 6) {
            $message = "Password minimal 6 karakter!";
            $message_type = "error";
        } else {
            try {
                // Verifikasi password lama
                $sql = "SELECT password FROM users WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                
                if ($user && password_verify($current_password, $user['password'])) {
                    // Update password baru
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("si", $hashed_password, $user_id);
                    
                    if ($stmt->execute()) {
                        $message = "Password berhasil diubah!";
                        $message_type = "success";
                    } else {
                        $message = "Gagal mengubah password: " . $conn->error;
                        $message_type = "error";
                    }
                } else {
                    $message = "Password lama salah!";
                    $message_type = "error";
                }
            } catch (Exception $e) {
                error_log("Error updating password: " . $e->getMessage());
                $message = "Terjadi kesalahan saat mengubah password";
                $message_type = "error";
            }
        }
    } 
    elseif ($action == 'update_guru_info') {
        // Update informasi spesifik guru
        if ($guru_id > 0) {
            $bidang_keahlian = $_POST['bidang_keahlian'] ?? '';
            $pendidikan_terakhir = $_POST['pendidikan_terakhir'] ?? '';
            $pengalaman_tahun = $_POST['pengalaman_tahun'] ?? 0;
            
            try {
                // Cek apakah data guru sudah ada
                $sql = "SELECT id FROM guru WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    // Update data yang sudah ada
                    $sql = "UPDATE guru SET 
                            bidang_keahlian = ?, 
                            pendidikan_terakhir = ?, 
                            pengalaman_tahun = ? 
                            WHERE user_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssii", $bidang_keahlian, $pendidikan_terakhir, $pengalaman_tahun, $user_id);
                } else {
                    // Insert data baru (jika belum ada)
                    $sql = "INSERT INTO guru (user_id, bidang_keahlian, pendidikan_terakhir, pengalaman_tahun, status, tanggal_bergabung) 
                            VALUES (?, ?, ?, ?, 'aktif', CURDATE())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("issi", $user_id, $bidang_keahlian, $pendidikan_terakhir, $pengalaman_tahun);
                }
                
                if ($stmt->execute()) {
                    $message = "Informasi guru berhasil diperbarui!";
                    $message_type = "success";
                    
                    // Refresh data guru
                    $sql = "SELECT * FROM guru WHERE user_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $guru_data = $result->fetch_assoc();
                    
                    if ($guru_data) {
                        $guru_id = $guru_data['id'];
                        // Refresh statistik setelah update
                        $sql = "SELECT COUNT(DISTINCT sp.siswa_id) as total_siswa_aktif
                                FROM siswa_pelajaran sp
                                JOIN pendaftaran_siswa ps ON sp.pendaftaran_id = ps.id
                                WHERE sp.guru_id = ? 
                                AND sp.status = 'aktif'
                                AND ps.status = 'aktif'";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $guru_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $stats = $result->fetch_assoc();
                        $guru_data['total_siswa'] = $stats['total_siswa_aktif'] ?? 0;
                    }
                } else {
                    $message = "Gagal memperbarui informasi guru: " . $conn->error;
                    $message_type = "error";
                }
            } catch (Exception $e) {
                error_log("Error updating guru info: " . $e->getMessage());
                $message = "Terjadi kesalahan saat memperbarui informasi guru";
                $message_type = "error";
            }
        } else {
            // Jika belum ada data guru, buat baru
            $bidang_keahlian = $_POST['bidang_keahlian'] ?? '';
            $pendidikan_terakhir = $_POST['pendidikan_terakhir'] ?? '';
            $pengalaman_tahun = $_POST['pengalaman_tahun'] ?? 0;
            
            try {
                $sql = "INSERT INTO guru (user_id, bidang_keahlian, pendidikan_terakhir, pengalaman_tahun, status, tanggal_bergabung) 
                        VALUES (?, ?, ?, ?, 'aktif', CURDATE())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issi", $user_id, $bidang_keahlian, $pendidikan_terakhir, $pengalaman_tahun);
                
                if ($stmt->execute()) {
                    $guru_id = $stmt->insert_id;
                    $message = "Informasi guru berhasil disimpan!";
                    $message_type = "success";
                    
                    // Refresh data guru
                    $sql = "SELECT * FROM guru WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $guru_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $guru_data = $result->fetch_assoc();
                } else {
                    $message = "Gagal menyimpan informasi guru: " . $conn->error;
                    $message_type = "error";
                }
            } catch (Exception $e) {
                error_log("Error inserting guru info: " . $e->getMessage());
                $message = "Terjadi kesalahan saat menyimpan informasi guru";
                $message_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Guru - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS yang sama seperti sebelumnya */
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .tab-active {
            border-bottom: 3px solid #3b82f6;
            color: #3b82f6;
            font-weight: 600;
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


        /* Mobile Menu Overlay Style */
        #mobileMenu {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100%;
            z-index: 1200;
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
            z-index: 1199;
        }

        .menu-overlay.active {
            display: block;
        }

        /* Desktop sidebar tetap terlihat */
        .desktop-sidebar {
            display: none;
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

        /* Mobile specific styles */
        @media (max-width: 767px) {
            .stat-card {
                padding: 1rem !important;
            }

            .stat-card .text-2xl {
                font-size: 1.5rem !important;
            }
            
            .tab-button {
                padding: 0.5rem !important;
                font-size: 0.875rem !important;
            }
        }

        /* Sidebar menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }
        
        .avatar-container {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: white;
            margin: 0 auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        /* Stat card styling */
        .stat-card {
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Loading animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        /* Progress bar */
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            background-color: #e5e7eb;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
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

        <!-- User Info -->
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

        <!-- Navigation -->
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
        <div class="bg-white shadow p-4 md:p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Profil Guru</h1>
                    <p class="text-gray-600 text-sm md:text-base">Kelola informasi akun Anda</p>
                </div>
                <div class="mt-2 md:mt-0">
                    <span class="text-sm text-gray-500">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Message Alert -->
            <?php if ($message): ?>
            <div id="messageAlert" class="mb-4 p-4 rounded-lg <?php echo $message_type == 'success' ? 'bg-green-100 text-green-800 border border-green-300' : 'bg-red-100 text-red-800 border border-red-300'; ?>">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                        <span><?php echo htmlspecialchars($message); ?></span>
                    </div>
                    <button type="button" onclick="document.getElementById('messageAlert').style.display='none'" 
                            class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabs Navigation -->
            <div class="mb-6 border-b border-gray-200">
                <nav class="flex flex-wrap -mb-px">
                    <button id="tab-profile" class="mr-4 py-2 px-1 text-sm font-medium text-blue-600 border-b-2 border-blue-600 tab-button tab-active" data-tab="profile">
                        <i class="fas fa-user-circle mr-2"></i> Informasi Profil
                    </button>
                    <button id="tab-password" class="mr-4 py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 tab-button" data-tab="password">
                        <i class="fas fa-key mr-2"></i> Ubah Password
                    </button>
                    <button id="tab-info" class="py-2 px-1 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300 tab-button" data-tab="info">
                        <i class="fas fa-info-circle mr-2"></i> Informasi Guru
                    </button>
                </nav>
            </div>

            <!-- Profile Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <!-- Stat Card 1: Total Siswa -->
                <div class="stat-card bg-white rounded-xl p-4 md:p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-blue-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-users text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Total Siswa Aktif</p>
                            <h3 class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $guru_data['total_siswa'] ?? 0; ?>
                            </h3>
                            <!--<?php if (isset($guru_data['mata_pelajaran']) && $guru_data['mata_pelajaran'] != '-'): ?>-->
                            <!--<p class="text-xs text-gray-500 mt-1 truncate" title="<?php echo htmlspecialchars($guru_data['mata_pelajaran']); ?>">-->
                            <!--    <i class="fas fa-book mr-1"></i>-->
                            <!--    <?php echo htmlspecialchars(substr($guru_data['mata_pelajaran'], 0, 50)); ?>-->
                            <!--    <?php if (strlen($guru_data['mata_pelajaran']) > 50): ?>...<?php endif; ?>-->
                            <!--</p>-->
                            <!--<?php endif; ?>-->
                        </div>
                    </div>
                </div>

                <!-- Stat Card 2: Total Kelas -->
                <div class="stat-card bg-white rounded-xl p-4 md:p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-green-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-chalkboard-teacher text-green-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Total Kelas</p>
                            <h3 class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo $guru_data['total_kelas'] ?? 0; ?>
                            </h3>
                            <!--<p class="text-xs text-gray-500 mt-1">-->
                            <!--    <i class="fas fa-calendar-check mr-1"></i>-->
                            <!--    Kelas Aktif-->
                            <!--</p>-->
                        </div>
                    </div>
                </div>

                <!-- Stat Card 3: Pengalaman -->
                <div class="stat-card bg-white rounded-xl p-4 md:p-5 shadow">
                    <div class="flex items-center">
                        <div class="p-2 md:p-3 bg-yellow-100 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-briefcase text-yellow-600 text-lg md:text-xl"></i>
                        </div>
                        <div>
                            <p class="text-xs md:text-sm text-gray-600">Pengalaman</p>
                            <h3 class="text-xl md:text-2xl font-bold text-gray-800">
                                <?php echo ($guru_data && $guru_data['pengalaman_tahun']) ? $guru_data['pengalaman_tahun'] . ' Tahun' : '-'; ?>
                            </h3>
                            <p class="text-xs text-gray-500 mt-1">
                                <i class="fas fa-graduation-cap mr-1"></i>
                                <?php echo $guru_data['pendidikan_terakhir'] ?? '-'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Profile Information -->
            <div id="tab-content-profile" class="tab-content">
                <div class="card bg-white rounded-xl shadow mb-6">
                    <div class="p-4 md:p-6 border-b">
                        <h2 class="text-base md:text-xl font-bold text-gray-800">
                            <i class="fas fa-user-edit mr-2"></i> Informasi Profil
                        </h2>
                    </div>
                    <div class="p-4 md:p-6">
                        <div class="flex flex-col items-center mb-6">
                            <div class="avatar-container mb-4">
                                <i class="fas fa-user"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($profile_data['full_name'] ?? ''); ?></h3>
                            <p class="text-gray-600">Guru Bimbel Esc</p>
                            <div class="mt-2 text-xs text-gray-500">
                                <i class="fas fa-calendar-alt mr-1"></i>
                                Bergabung sejak <?php echo $guru_data && $guru_data['tanggal_bergabung'] ? date('d F Y', strtotime($guru_data['tanggal_bergabung'])) : date('d F Y', strtotime($profile_data['created_at'] ?? 'now')); ?>
                            </div>
                        </div>

                        <form method="POST" action="" id="profileForm">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4 mb-3 md:mb-4">
                                <div>
                                    <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="username">
                                        Username
                                    </label>
                                    <input type="text" id="username" 
                                           value="<?php echo htmlspecialchars($profile_data['username'] ?? ''); ?>" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md bg-gray-100" 
                                           readonly>
                                    <p class="text-xs text-gray-500 mt-1">Username tidak dapat diubah</p>
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="email">
                                        Email
                                    </label>
                                    <input type="email" id="email" 
                                           value="<?php echo htmlspecialchars($profile_data['email'] ?? ''); ?>" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md bg-gray-100" 
                                           readonly>
                                    <p class="text-xs text-gray-500 mt-1">Email tidak dapat diubah</p>
                                </div>
                            </div>
                            
                            <div class="mb-3 md:mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="full_name">
                                    Nama Lengkap <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($profile_data['full_name'] ?? ''); ?>" 
                                       class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                       required>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4 mb-3 md:mb-4">
                                <div>
                                    <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="phone">
                                        Nomor Telepon
                                    </label>
                                    <input type="text" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($profile_data['phone'] ?? ''); ?>" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                           pattern="[0-9+]{10,15}"
                                           title="Format: 081234567890 atau +6281234567890">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="role">
                                        Role
                                    </label>
                                    <input type="text" id="role" 
                                           value="Guru" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md bg-gray-100" 
                                           readonly>
                                </div>
                            </div>
                            
                            <div class="mb-4 md:mb-6">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="address">
                                    Alamat
                                </label>
                                <textarea id="address" name="address" rows="3" 
                                          class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($profile_data['address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="px-4 md:px-6 py-2 bg-blue-600 text-white text-xs md:text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Change Password -->
            <div id="tab-content-password" class="tab-content hidden">
                <div class="card bg-white rounded-xl shadow mb-6">
                    <div class="p-4 md:p-6 border-b">
                        <h2 class="text-base md:text-xl font-bold text-gray-800">
                            <i class="fas fa-key mr-2"></i> Ubah Password
                        </h2>
                    </div>
                    <div class="p-4 md:p-6">
                        <form method="POST" action="" id="passwordForm">
                            <input type="hidden" name="action" value="update_password">
                            
                            <div class="mb-3 md:mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="current_password">
                                    Password Saat Ini <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="current_password" name="current_password" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" 
                                           required>
                                    <button type="button" class="absolute right-3 top-2 text-gray-500 toggle-password" data-target="current_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3 md:mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="new_password">
                                    Password Baru <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="new_password" name="new_password" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" 
                                           minlength="6" required>
                                    <button type="button" class="absolute right-3 top-2 text-gray-500 toggle-password" data-target="new_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="mt-2">
                                    <div class="progress-bar mb-1">
                                        <div id="passwordStrength" class="progress-fill bg-gray-300" style="width: 0%"></div>
                                    </div>
                                    <p id="passwordStrengthText" class="text-xs text-gray-500"></p>
                                </div>
                            </div>
                            
                            <div class="mb-4 md:mb-6">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="confirm_password">
                                    Konfirmasi Password Baru <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input type="password" id="confirm_password" name="confirm_password" 
                                           class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 pr-10" 
                                           minlength="6" required>
                                    <button type="button" class="absolute right-3 top-2 text-gray-500 toggle-password" data-target="confirm_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <p id="passwordMatch" class="text-xs mt-1 hidden"></p>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" id="submitPasswordBtn"
                                        class="px-4 md:px-6 py-2 bg-green-600 text-white text-xs md:text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-key mr-2"></i> Ubah Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Guru Information -->
            <div id="tab-content-info" class="tab-content hidden">
                <div class="card bg-white rounded-xl shadow mb-6">
                    <div class="p-4 md:p-6 border-b">
                        <h2 class="text-base md:text-xl font-bold text-gray-800">
                            <i class="fas fa-chalkboard-teacher mr-2"></i> Informasi Guru
                        </h2>
                    </div>
                    <div class="p-4 md:p-6">
                        <form method="POST" action="" id="guruInfoForm">
                            <input type="hidden" name="action" value="update_guru_info">
                            
                            <div class="mb-3 md:mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="bidang_keahlian">
                                    Bidang Keahlian <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="bidang_keahlian" name="bidang_keahlian" 
                                       value="<?php echo htmlspecialchars($guru_data['bidang_keahlian'] ?? ''); ?>" 
                                       class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                       placeholder="Contoh: Matematika, Fisika, Bahasa Inggris"
                                       required>
                            </div>
                            
                            <div class="mb-3 md:mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="pendidikan_terakhir">
                                    Pendidikan Terakhir <span class="text-red-500">*</span>
                                </label>
                                <select id="pendidikan_terakhir" name="pendidikan_terakhir" 
                                        class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                        required>
                                    <option value="">Pilih Pendidikan</option>
                                    <option value="SMA" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'SMA' ? 'selected' : ''; ?>>SMA</option>
                                    <option value="D1" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'D1' ? 'selected' : ''; ?>>D1</option>
                                    <option value="D2" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'D2' ? 'selected' : ''; ?>>D2</option>
                                    <option value="D3" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'D3' ? 'selected' : ''; ?>>D3</option>
                                    <option value="D4" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'D4' ? 'selected' : ''; ?>>D4</option>
                                    <option value="S1" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'S1' ? 'selected' : ''; ?>>S1</option>
                                    <option value="S2" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'S2' ? 'selected' : ''; ?>>S2</option>
                                    <option value="S3" <?php echo ($guru_data['pendidikan_terakhir'] ?? '') == 'S3' ? 'selected' : ''; ?>>S3</option>
                                </select>
                            </div>
                            
                            <div class="mb-4 md:mb-6">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2" for="pengalaman_tahun">
                                    Pengalaman Mengajar (Tahun) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="pengalaman_tahun" name="pengalaman_tahun" 
                                       value="<?php echo htmlspecialchars($guru_data['pengalaman_tahun'] ?? '0'); ?>" 
                                       min="0" max="50"
                                       class="w-full px-3 py-2 text-xs md:text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                       required>
                                <p class="text-xs text-gray-500 mt-1">Masukkan jumlah tahun pengalaman mengajar</p>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 text-xs md:text-sm font-bold mb-1 md:mb-2">
                                    Status
                                </label>
                                <div class="flex items-center">
                                    <span class="px-2 md:px-3 py-1 text-xs rounded-full <?php echo ($guru_data['status'] ?? '') == 'aktif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <i class="fas fa-circle mr-1 text-xs"></i>
                                        <?php echo ucfirst($guru_data['status'] ?? 'aktif'); ?>
                                    </span>
                                    <p class="text-xs text-gray-500 ml-2">Status diatur oleh Admin</p>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" 
                                        class="px-4 md:px-6 py-2 bg-blue-600 text-white text-xs md:text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Simpan Informasi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Profil Guru</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Akun dibuat: <?php echo $profile_data && $profile_data['created_at'] ? date('d F Y', strtotime($profile_data['created_at'])) : '-'; ?>
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

        // Close menu when clicking on menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });

        // Tab Switching
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', () => {
                const tab = button.getAttribute('data-tab');
                
                // Update active tab button
                document.querySelectorAll('.tab-button').forEach(btn => {
                    btn.classList.remove('tab-active');
                    btn.classList.add('text-gray-500', 'border-transparent');
                    btn.classList.remove('text-blue-600', 'border-blue-600');
                });
                
                button.classList.add('tab-active');
                button.classList.remove('text-gray-500', 'border-transparent');
                button.classList.add('text-blue-600', 'border-blue-600');
                
                // Show active tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.add('hidden');
                });
                
                document.getElementById(`tab-content-${tab}`).classList.remove('hidden');
            });
        });

        // Toggle Password Visibility
        document.querySelectorAll('.toggle-password').forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-target');
                const passwordInput = document.getElementById(targetId);
                const icon = button.querySelector('i');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // Password strength checker
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordStrength = document.getElementById('passwordStrength');
        const passwordStrengthText = document.getElementById('passwordStrengthText');
        const passwordMatch = document.getElementById('passwordMatch');
        const submitPasswordBtn = document.getElementById('submitPasswordBtn');

        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let color = '';
                let text = '';
                
                // Check password strength
                if (password.length >= 6) strength++;
                if (password.length >= 8) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;
                
                // Set color and text based on strength
                switch(strength) {
                    case 0:
                    case 1:
                        color = 'bg-red-500';
                        text = 'Sangat Lemah';
                        break;
                    case 2:
                        color = 'bg-orange-500';
                        text = 'Lemah';
                        break;
                    case 3:
                        color = 'bg-yellow-500';
                        text = 'Cukup';
                        break;
                    case 4:
                        color = 'bg-blue-500';
                        text = 'Kuat';
                        break;
                    case 5:
                        color = 'bg-green-500';
                        text = 'Sangat Kuat';
                        break;
                }
                
                const percentage = (strength / 5) * 100;
                passwordStrength.className = `progress-fill ${color}`;
                passwordStrength.style.width = `${percentage}%`;
                passwordStrengthText.textContent = `Kekuatan password: ${text}`;
                passwordStrengthText.className = strength >= 3 ? 'text-xs text-green-600' : 'text-xs text-red-600';
            });
        }
        
        // Password match checker
        if (newPasswordInput && confirmPasswordInput) {
            const checkPasswordMatch = () => {
                if (newPasswordInput.value && confirmPasswordInput.value) {
                    if (newPasswordInput.value === confirmPasswordInput.value) {
                        passwordMatch.textContent = '✓ Password cocok';
                        passwordMatch.className = 'text-xs text-green-600';
                        passwordMatch.classList.remove('hidden');
                        if (submitPasswordBtn) submitPasswordBtn.disabled = false;
                    } else {
                        passwordMatch.textContent = '✗ Password tidak cocok';
                        passwordMatch.className = 'text-xs text-red-600';
                        passwordMatch.classList.remove('hidden');
                        if (submitPasswordBtn) submitPasswordBtn.disabled = true;
                    }
                } else {
                    passwordMatch.classList.add('hidden');
                    if (submitPasswordBtn) submitPasswordBtn.disabled = false;
                }
            };
            
            newPasswordInput.addEventListener('input', checkPasswordMatch);
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        }

        // Form validation for phone number
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9+]/g, '');
            });
        }

        // Auto-hide message alert after 5 seconds
        const messageAlert = document.getElementById('messageAlert');
        if (messageAlert) {
            setTimeout(() => {
                messageAlert.style.opacity = '0';
                messageAlert.style.transition = 'opacity 0.5s';
                setTimeout(() => {
                    messageAlert.style.display = 'none';
                }, 500);
            }, 5000);
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

        // Update server time
        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('serverTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        setInterval(updateServerTime, 1000);
        updateServerTime();
    </script>
</body>
</html>