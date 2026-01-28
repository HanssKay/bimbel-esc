<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php';

// Helper function untuk time ago
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'baru saja';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' menit lalu';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' jam lalu';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' hari lalu';
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months . ' bulan lalu';
    } else {
        $years = floor($diff / 31536000);
        return $years . ' tahun lalu';
    }
}

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'orangtua') {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Orang Tua';
$email = $_SESSION['email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// VARIABEL UNTUK PESAN
$success_message = '';
$error_message = '';

// AMBIL DATA ORANGTUA BERDASARKAN user_id
$orangtua_data = [];
try {
    $sql_ortu = "SELECT o.*, u.full_name, u.email, u.phone, u.address 
                 FROM orangtua o 
                 JOIN users u ON o.user_id = u.id 
                 WHERE o.user_id = ?";
    $stmt_ortu = $conn->prepare($sql_ortu);
    if ($stmt_ortu) {
        $stmt_ortu->bind_param("i", $user_id);
        $stmt_ortu->execute();
        $result_ortu = $stmt_ortu->get_result();
        if ($row_ortu = $result_ortu->fetch_assoc()) {
            $orangtua_data = $row_ortu;
        }
        $stmt_ortu->close();
    }
} catch (Exception $e) {
    error_log("Error fetching orangtua data: " . $e->getMessage());
}

// AMBIL DATA ANAK-ANAK BERDASARKAN HUBUNGAN MANY-TO-MANY
$anak_data = [];
$total_anak = 0;

if (!empty($orangtua_data['id'])) {
    try {
        $sql_anak = "SELECT s.*, 
                            DATE_FORMAT(s.tanggal_lahir, '%d %M %Y') as tanggal_lahir_format,
                            TIMESTAMPDIFF(YEAR, s.tanggal_lahir, CURDATE()) as usia,
                            so.created_at as hubungan_dibuat
                     FROM siswa s 
                     JOIN siswa_orangtua so ON s.id = so.siswa_id
                     WHERE so.orangtua_id = ? 
                     ORDER BY s.nama_lengkap";
        
        $stmt_anak = $conn->prepare($sql_anak);
        if ($stmt_anak) {
            $stmt_anak->bind_param("i", $orangtua_data['id']);
            $stmt_anak->execute();
            $result_anak = $stmt_anak->get_result();
            while ($row_anak = $result_anak->fetch_assoc()) {
                $anak_data[] = $row_anak;
            }
            $total_anak = count($anak_data);
            $stmt_anak->close();
        }
    } catch (Exception $e) {
        error_log("Error fetching anak data: " . $e->getMessage());
    }
}

// PROSES UPDATE PROFIL
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $phone = trim($_POST['phone']);
        $nama_ortu = trim($_POST['nama_ortu'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');
        $pekerjaan = trim($_POST['pekerjaan'] ?? '');
        $perusahaan = trim($_POST['perusahaan'] ?? '');
        $hubungan_dengan_siswa = trim($_POST['hubungan_dengan_siswa'] ?? '');
        
        // Validasi
        if (empty($full_name)) {
            $error_message = "Nama lengkap tidak boleh kosong";
        } else {
            // Update data di tabel users
            $sql_update_user = "UPDATE users SET 
                               full_name = ?, 
                               phone = ?,
                               updated_at = NOW()
                               WHERE id = ?";
            
            $stmt_user = $conn->prepare($sql_update_user);
            if ($stmt_user) {
                $stmt_user->bind_param("ssi", $full_name, $phone, $user_id);
                
                if ($stmt_user->execute()) {
                    // Update data di tabel orangtua
                    $sql_update_ortu = "UPDATE orangtua SET 
                                       nama_ortu = ?,
                                       no_hp = ?,
                                       pekerjaan = ?,
                                       perusahaan = ?,
                                       hubungan_dengan_siswa = ?
                                       WHERE user_id = ?";
                    
                    $stmt_ortu = $conn->prepare($sql_update_ortu);
                    if ($stmt_ortu) {
                        $stmt_ortu->bind_param("sssssi", 
                            $nama_ortu, 
                            $no_hp, 
                            $pekerjaan, 
                            $perusahaan, 
                            $hubungan_dengan_siswa, 
                            $user_id
                        );
                        $stmt_ortu->execute();
                        $stmt_ortu->close();
                    }
                    
                    // Update session
                    $_SESSION['full_name'] = $full_name;
                    
                    $success_message = "Profil berhasil diperbarui!";
                    
                    // Refresh data
                    $sql_refresh = "SELECT o.*, u.full_name, u.email, u.phone, u.address 
                                   FROM orangtua o 
                                   JOIN users u ON o.user_id = u.id 
                                   WHERE o.user_id = ?";
                    $stmt_refresh = $conn->prepare($sql_refresh);
                    if ($stmt_refresh) {
                        $stmt_refresh->bind_param("i", $user_id);
                        $stmt_refresh->execute();
                        $result_refresh = $stmt_refresh->get_result();
                        if ($row_refresh = $result_refresh->fetch_assoc()) {
                            $orangtua_data = $row_refresh;
                        }
                        $stmt_refresh->close();
                    }
                    
                } else {
                    $error_message = "Gagal memperbarui profil: " . $conn->error;
                }
                $stmt_user->close();
            } else {
                $error_message = "Error dalam query: " . $conn->error;
            }
        }
    }
    
    // UPDATE PASSWORD
    elseif (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validasi
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "Semua field password harus diisi";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Password baru dan konfirmasi password tidak cocok";
        } elseif (strlen($new_password) < 6) {
            $error_message = "Password baru minimal 6 karakter";
        } else {
            // Cek password saat ini
            $sql_check = "SELECT password FROM users WHERE id = ?";
            $stmt_check = $conn->prepare($sql_check);
            if ($stmt_check) {
                $stmt_check->bind_param("i", $user_id);
                $stmt_check->execute();
                $stmt_check->bind_result($hashed_password);
                $stmt_check->fetch();
                $stmt_check->close();
                
                if (password_verify($current_password, $hashed_password)) {
                    // Update password
                    $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $sql_update_pass = "UPDATE users SET 
                                       password = ?,
                                       updated_at = NOW()
                                       WHERE id = ?";
                    
                    $stmt_update = $conn->prepare($sql_update_pass);
                    if ($stmt_update) {
                        $stmt_update->bind_param("si", $new_hashed_password, $user_id);
                        
                        if ($stmt_update->execute()) {
                            $success_message = "Password berhasil diperbarui!";
                        } else {
                            $error_message = "Gagal memperbarui password";
                        }
                        $stmt_update->close();
                    }
                } else {
                    $error_message = "Password saat ini salah";
                }
            }
        }
    }
}

// AMBIL RIWAYAT LOGIN
$login_history = [];
try {
    $sql_login = "SELECT * FROM login_history 
                  WHERE user_id = ? 
                  ORDER BY login_time DESC 
                  LIMIT 5";
    $stmt_login = $conn->prepare($sql_login);
    if ($stmt_login) {
        $stmt_login->bind_param("i", $user_id);
        $stmt_login->execute();
        $result_login = $stmt_login->get_result();
        while ($row_login = $result_login->fetch_assoc()) {
            $login_history[] = $row_login;
        }
        $stmt_login->close();
    }
} catch (Exception $e) {
    error_log("Error fetching login history: " . $e->getMessage());
}

// HITUNG TOTAL PENILAIAN BULAN INI UNTUK SEMUA ANAK
$total_penilaian = 0;
if (!empty($anak_data)) {
    $siswa_ids = array_column($anak_data, 'id');
    $placeholders = str_repeat('?,', count($siswa_ids) - 1) . '?';
    
    try {
        $current_month = date('Y-m');
        $sql_count = "SELECT COUNT(*) as total 
                     FROM penilaian_siswa ps
                     WHERE ps.siswa_id IN ($placeholders) 
                     AND DATE_FORMAT(ps.tanggal_penilaian, '%Y-%m') = ?";
        
        $stmt_count = $conn->prepare($sql_count);
        if ($stmt_count) {
            // Bind parameters
            $types = str_repeat('i', count($siswa_ids)) . 's';
            $params = array_merge($siswa_ids, [$current_month]);
            $stmt_count->bind_param($types, ...$params);
            $stmt_count->execute();
            $result_count = $stmt_count->get_result();
            if ($row_count = $result_count->fetch_assoc()) {
                $total_penilaian = $row_count['total'];
            }
            $stmt_count->close();
        }
    } catch (Exception $e) {
        error_log("Error counting penilaian: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .badge-primary { background-color: #3B82F6; color: white; }
        .badge-success { background-color: #10B981; color: white; }
        .badge-warning { background-color: #F59E0B; color: white; }
        .badge-info { background-color: #6366F1; color: white; }
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
        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalFadeIn 0.3s;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 16px 24px;
            color: white;
            border-radius: 8px 8px 0 0;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
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
        }
        .close:hover { opacity: 0.8; }
        .avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        /* Mobile Menu Styles */
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
        }

        /* Sidebar menu item active state */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
        }

        @media (max-width: 767px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .avatar {
                width: 100px;
                height: 100px;
            }
            
            .table-container {
                overflow-x: auto;
                font-size: 0.8rem;
            }
            
            .stat-card {
                padding: 1rem !important;
            }
            
            .stat-card .text-2xl {
                font-size: 1.5rem !important;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Orang Tua</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                </div>
            </div>
            <div class="mt-3 text-sm">
                <p><i class="fas fa-child mr-2"></i> <?php echo $total_anak; ?> Anak</p>
            </div>
        </div>
        <nav class="mt-4">
            <?php echo renderMenu($currentPage, 'orangtua'); ?>
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
                    <p class="text-xs text-blue-300">Orang Tua</p>
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
                        <p class="text-sm text-blue-300">Orang Tua</p>
                        <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                </div>
                <div class="mt-3 text-sm">
                    <p><i class="fas fa-child mr-2"></i> <?php echo $total_anak; ?> Anak</p>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto">
                <?php echo renderMenu($currentPage, 'orangtua'); ?>
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
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Profil Saya</h1>
                    <p class="text-gray-600 text-sm md:text-base">Kelola informasi akun dan data pribadi Anda</p>
                </div>
                <div class="mt-4 md:mt-0">
                    <span class="text-sm text-gray-500">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        <?php echo date('d F Y'); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Notifikasi -->
            <?php if ($success_message): ?>
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <div>
                        <p class="font-medium text-green-800">Berhasil!</p>
                        <p class="text-green-700"><?php echo $success_message; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <div>
                        <p class="font-medium text-red-800">Terjadi Kesalahan!</p>
                        <p class="text-red-700"><?php echo $error_message; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistik Ringkas -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-xl p-4 md:p-6 stat-card">
                    <div class="flex items-center">
                        <div class="p-3 bg-white/20 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-user-graduate text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Total Anak</p>
                            <p class="text-xl md:text-2xl font-bold"><?php echo $total_anak; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl p-4 md:p-6 stat-card">
                    <div class="flex items-center">
                        <div class="p-3 bg-white/20 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-clipboard-check text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Penilaian Bulan Ini</p>
                            <p class="text-xl md:text-2xl font-bold"><?php echo $total_penilaian; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-xl p-4 md:p-6 stat-card">
                    <div class="flex items-center">
                        <div class="p-3 bg-white/20 rounded-lg mr-3 md:mr-4">
                            <i class="fas fa-calendar-alt text-xl md:text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm opacity-90">Bergabung Sejak</p>
                            <p class="text-lg md:text-xl font-bold">
                                <?php 
                                $join_date = $orangtua_data['created_at'] ?? date('Y-m-d');
                                echo date('F Y', strtotime($join_date)); 
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profil Utama -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Kolom Kiri: Info Profil -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Card Info Profil -->
                    <div class="card bg-white rounded-xl shadow">
                        <div class="p-4 md:p-6 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-user-circle mr-2"></i> Informasi Profil
                            </h3>
                        </div>
                        <div class="p-4 md:p-6">
                            <form method="POST">
                                <div class="space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Nama Lengkap (Akun) <span class="text-red-500">*</span>
                                            </label>
                                            <input type="text" name="full_name" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['full_name'] ?? $full_name); ?>"
                                                   required
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Email
                                            </label>
                                            <input type="email" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['email'] ?? $email); ?>"
                                                   disabled
                                                   class="w-full border border-gray-300 bg-gray-50 rounded-lg px-3 py-2 text-gray-500">
                                            <p class="text-xs text-gray-500 mt-1">Email tidak dapat diubah</p>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Telepon (Akun)
                                            </label>
                                            <input type="tel" name="phone" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['phone'] ?? ''); ?>"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   placeholder="08xxxxxxxxxx">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                No. HP (Orang Tua)
                                            </label>
                                            <input type="tel" name="no_hp" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['no_hp'] ?? ''); ?>"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   placeholder="08xxxxxxxxxx">
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Nama Orang Tua
                                            </label>
                                            <input type="text" name="nama_ortu" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['nama_ortu'] ?? ''); ?>"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   placeholder="Nama lengkap orang tua">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Hubungan dengan Siswa
                                            </label>
                                            <select name="hubungan_dengan_siswa" 
                                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                <option value="">Pilih Hubungan</option>
                                                <option value="ayah" <?php echo ($orangtua_data['hubungan_dengan_siswa'] ?? '') == 'ayah' ? 'selected' : ''; ?>>Ayah</option>
                                                <option value="ibu" <?php echo ($orangtua_data['hubungan_dengan_siswa'] ?? '') == 'ibu' ? 'selected' : ''; ?>>Ibu</option>
                                                <option value="wali" <?php echo ($orangtua_data['hubungan_dengan_siswa'] ?? '') == 'wali' ? 'selected' : ''; ?>>Wali</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Pekerjaan
                                            </label>
                                            <input type="text" name="pekerjaan" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['pekerjaan'] ?? ''); ?>"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   placeholder="Pekerjaan">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Perusahaan
                                            </label>
                                            <input type="text" name="perusahaan" 
                                                   value="<?php echo htmlspecialchars($orangtua_data['perusahaan'] ?? ''); ?>"
                                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                                   placeholder="Nama perusahaan">
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Tanggal Bergabung
                                            </label>
                                            <input type="text" 
                                                   value="<?php echo date('d F Y', strtotime($orangtua_data['created_at'] ?? date('Y-m-d'))); ?>"
                                                   disabled
                                                   class="w-full border border-gray-300 bg-gray-50 rounded-lg px-3 py-2 text-gray-500">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                Terakhir Diupdate
                                            </label>
                                            <input type="text" 
                                                   value="<?php echo date('d F Y H:i', strtotime($orangtua_data['updated_at'] ?? $orangtua_data['created_at'] ?? date('Y-m-d H:i:s'))); ?>"
                                                   disabled
                                                   class="w-full border border-gray-300 bg-gray-50 rounded-lg px-3 py-2 text-gray-500">
                                        </div>
                                    </div>
                                    
                                    <div class="flex justify-end pt-4">
                                        <button type="submit" name="update_profile"
                                                class="px-4 md:px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-sm md:text-base">
                                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Card Data Anak -->
                    <?php if (!empty($anak_data)): ?>
                    <div class="card bg-white rounded-xl shadow">
                        <div class="p-4 md:p-6 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-child mr-2"></i> Data Anak
                                <span class="badge-primary ml-2 text-xs md:text-sm"><?php echo $total_anak; ?> Anak</span>
                            </h3>
                        </div>
                        <div class="p-4 md:p-6">
                            <div class="overflow-x-auto table-container">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama</th>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Tempat/Tgl Lahir</th>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Usia</th>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Sekolah</th>
                                            <th class="px-3 md:px-4 py-2 md:py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal dibuat</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach ($anak_data as $anak): 
                                            $tanggal_lahir = !empty($anak['tanggal_lahir']) ? date('d/m/Y', strtotime($anak['tanggal_lahir'])) : '-';
                                            $hubungan_sejak = !empty($anak['hubungan_dibuat']) ? date('d/m/Y', strtotime($anak['hubungan_dibuat'])) : '-';
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 md:px-4 py-2 md:py-3">
                                                <div class="font-medium text-gray-900 text-sm md:text-base"><?php echo htmlspecialchars($anak['nama_lengkap']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo $anak['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></div>
                                            </td>
                                            <td class="px-3 md:px-4 py-2 md:py-3 text-sm text-gray-700">
                                                <div><?php echo htmlspecialchars($anak['tempat_lahir'] ?? '-'); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo $tanggal_lahir; ?></div>
                                            </td>
                                            <td class="px-3 md:px-4 py-2 md:py-3">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-medium">
                                                    <?php echo $anak['kelas']; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 md:px-4 py-2 md:py-3 text-sm text-gray-700"><?php echo $anak['usia'] ?? '?'; ?> tahun</td>
                                            <td class="px-3 md:px-4 py-2 md:py-3 text-sm text-gray-700"><?php echo htmlspecialchars($anak['sekolah_asal'] ?? '-'); ?></td>
                                            <td class="px-3 md:px-4 py-2 md:py-3 text-sm text-gray-700"><?php echo $hubungan_sejak; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Kolom Kanan: Sidebar -->
                <div class="space-y-6">
                    <!-- Avatar & Status -->
                    <div class="card bg-white rounded-xl shadow">
                        <div class="p-4 md:p-6 text-center">
                            <div class="flex justify-center mb-4">
                                <div class="relative">
                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=3B82F6&color=fff&size=120&bold=true&font-size=0.5" 
                                         alt="Avatar" 
                                         class="avatar mx-auto">
                                    <span class="absolute bottom-2 right-2 w-3 h-3 md:w-4 md:h-4 bg-green-500 border-2 border-white rounded-full"></span>
                                </div>
                            </div>
                            <h3 class="text-lg md:text-xl font-bold text-gray-800 mb-1"><?php echo htmlspecialchars($full_name); ?></h3>
                            <p class="text-gray-600 mb-3 text-sm md:text-base">
                                <i class="fas fa-user-tie mr-1"></i> Orang Tua
                            </p>
                            <div class="space-y-2">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Status Akun:</span>
                                    <span class="font-medium text-green-600">Aktif</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Role:</span>
                                    <span class="font-medium text-blue-600">Orang Tua</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Hubungan:</span>
                                    <span class="font-medium text-purple-600"><?php echo htmlspecialchars(ucfirst($orangtua_data['hubungan_dengan_siswa'] ?? '-')); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card bg-white rounded-xl shadow">
                        <div class="p-4 md:p-6 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-bolt mr-2"></i> Akses Cepat
                            </h3>
                        </div>
                        <div class="p-4 space-y-2">
                            <button onclick="showPasswordModal()"
                                    class="w-full text-left px-4 py-3 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg flex items-center">
                                <i class="fas fa-key mr-3"></i>
                                <div class="flex-1">
                                    <div class="font-medium text-sm md:text-base">Ubah Password</div>
                                    <div class="text-xs text-red-600">Keamanan akun</div>
                                </div>
                            </button>
                            
                            <a href="dashboardOrtu.php"
                               class="block px-4 py-3 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg flex items-center">
                                <i class="fas fa-home mr-3"></i>
                                <div class="flex-1">
                                    <div class="font-medium text-sm md:text-base">Kembali ke Dashboard</div>
                                    <div class="text-xs text-blue-600">Dashboard utama</div>
                                </div>
                            </a>
                            
                            <button onclick="showHelpModal()"
                                    class="w-full text-left px-4 py-3 bg-green-50 hover:bg-green-100 text-green-700 rounded-lg flex items-center">
                                <i class="fas fa-question-circle mr-3"></i>
                                <div class="flex-1">
                                    <div class="font-medium text-sm md:text-base">Bantuan</div>
                                    <div class="text-xs text-green-600">Panduan penggunaan</div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Login History -->
                    <?php if (!empty($login_history)): ?>
                    <div class="card bg-white rounded-xl shadow">
                        <div class="p-4 md:p-6 border-b">
                            <h3 class="text-lg font-semibold text-gray-800">
                                <i class="fas fa-history mr-2"></i> Riwayat Login
                            </h3>
                        </div>
                        <div class="p-4 space-y-3">
                            <?php foreach ($login_history as $login): 
                                $time_ago = time_ago($login['login_time']);
                            ?>
                            <div class="flex items-start">
                                <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                    <i class="fas fa-sign-in-alt text-blue-600"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium text-gray-800 text-sm md:text-base">Login Berhasil</div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo date('d M Y H:i', strtotime($login['login_time'])); ?> 
                                        (<?php echo $time_ago; ?>)
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Profil Orang Tua</p>
                        <p class="mt-1 text-xs text-gray-400">
                            Terakhir login: <?php echo !empty($login_history) ? date('d F Y H:i', strtotime($login_history[0]['login_time'])) : '-'; ?>
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

    <!-- MODAL UBAH PASSWORD -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold"><i class="fas fa-key mr-2"></i> Ubah Password</h2>
                <span class="close" onclick="closeModal('passwordModal')">&times;</span>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Password Saat Ini <span class="text-red-500">*</span>
                            </label>
                            <input type="password" name="current_password" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Password Baru <span class="text-red-500">*</span>
                            </label>
                            <input type="password" name="new_password" required minlength="6"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="Minimal 6 karakter">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Konfirmasi Password Baru <span class="text-red-500">*</span>
                            </label>
                            <input type="password" name="confirm_password" required
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                            <div class="flex">
                                <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                                <div>
                                    <p class="text-sm font-medium text-yellow-800">Tips Keamanan Password</p>
                                    <ul class="text-xs text-yellow-700 mt-1 space-y-1">
                                        <li>• Gunakan minimal 6 karakter</li>
                                        <li>• Kombinasikan huruf besar, kecil, dan angka</li>
                                        <li>• Jangan gunakan password yang mudah ditebak</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('passwordModal')"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2">
                        Batal
                    </button>
                    <button type="submit" name="update_password"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-key mr-2"></i> Ubah Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL BANTUAN -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="text-xl font-bold"><i class="fas fa-question-circle mr-2"></i> Pusat Bantuan</h2>
                <span class="close" onclick="closeModal('helpModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="space-y-4">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h3 class="font-semibold text-blue-800 mb-2">
                            <i class="fas fa-info-circle mr-2"></i> Cara Menggunakan Profil
                        </h3>
                        <ul class="text-sm text-gray-700 space-y-2">
                            <li>• <strong>Update Profil:</strong> Isi formulir dan klik "Simpan Perubahan"</li>
                            <li>• <strong>Ubah Password:</strong> Klik tombol "Ubah Password" di akses cepat</li>
                            <li>• <strong>Data Anak:</strong> Semua data anak Anda akan tampil otomatis</li>
                            <li>• <strong>Riwayat Login:</strong> Pantau aktivitas login terakhir Anda</li>
                        </ul>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg">
                        <h3 class="font-semibold text-green-800 mb-2">
                            <i class="fas fa-phone-alt mr-2"></i> Kontak Bantuan
                        </h3>
                        <div class="text-sm text-gray-700">
                            <p class="mb-2">Jika mengalami kendala, hubungi:</p>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <i class="fas fa-envelope text-green-600 mr-2"></i>
                                    <span>support@bimbelesc.com</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-phone text-green-600 mr-2"></i>
                                    <span>021-12345678</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-clock text-green-600 mr-2"></i>
                                    <span>Senin - Jumat, 08:00 - 17:00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal('helpModal')"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-check mr-2"></i> Mengerti
                </button>
            </div>
        </div>
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

        // Fungsi untuk menampilkan modal password
        function showPasswordModal() {
            // Close mobile menu if open
            mobileMenu.classList.remove('menu-open');
            menuOverlay.classList.remove('active');
            document.body.style.overflow = 'hidden';
            
            document.getElementById('passwordModal').style.display = 'block';
        }

        // Fungsi untuk menampilkan modal bantuan
        function showHelpModal() {
            // Close mobile menu if open
            mobileMenu.classList.remove('menu-open');
            menuOverlay.classList.remove('active');
            document.body.style.overflow = 'hidden';
            
            document.getElementById('helpModal').style.display = 'block';
        }

        // Fungsi untuk menutup modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

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

        // Tutup modal saat klik di luar
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        };

        // Validasi form password
        const passwordForm = document.querySelector('form[action*="profile.php"]');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                if (e.submitter && e.submitter.name === 'update_password') {
                    const currentPass = document.querySelector('input[name="current_password"]');
                    const newPass = document.querySelector('input[name="new_password"]');
                    const confirmPass = document.querySelector('input[name="confirm_password"]');
                    
                    if (!currentPass || !newPass || !confirmPass) {
                        return;
                    }
                    
                    if (newPass.value !== confirmPass.value) {
                        e.preventDefault();
                        alert('Password baru dan konfirmasi password tidak cocok!');
                        return false;
                    }
                    
                    if (newPass.value.length < 6) {
                        e.preventDefault();
                        alert('Password baru minimal 6 karakter!');
                        return false;
                    }
                }
            });
        }
    </script>
</body>
</html>