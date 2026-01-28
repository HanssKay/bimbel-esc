<?php
session_start();
require_once 'includes/config.php';

$error = '';
$success = '';

// CEK JIKA SUDAH LOGIN
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    $role = $_SESSION['user_role'];
    header("Location: ../$role/dashboard.php");
    exit();
}

// Tampilkan pesan sukses jika ada dari registrasi
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Registrasi berhasil! Silakan login dengan akun Anda.";
}

// Tampilkan pesan jika logout
if (isset($_GET['logout']) && $_GET['logout'] == 1) {
    $success = "Anda telah berhasil logout.";
}

// Tampilkan pesan jika session expired
if (isset($_GET['expired']) && $_GET['expired'] == 1) {
    $error = "Sesi Anda telah berakhir. Silakan login kembali.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Username dan password harus diisi!";
    } else {
        // PERBAIKAN QUERY: Ambil semua data yang diperlukan
        $sql = "SELECT u.*, 
                       CASE 
                           WHEN u.role = 'guru' THEN g.id 
                           WHEN u.role = 'orangtua' THEN o.id 
                           ELSE NULL 
                       END as role_id,
                       CASE
                           WHEN u.role = 'guru' THEN g.bidang_keahlian
                           WHEN u.role = 'orangtua' THEN o.nama_ortu
                           ELSE NULL
                       END as detail_info
                FROM users u
                LEFT JOIN guru g ON u.id = g.user_id AND u.role = 'guru'
                LEFT JOIN orangtua o ON u.id = o.user_id AND u.role = 'orangtua'
                WHERE u.username = ? AND u.is_active = 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Verifikasi password
            if (password_verify($password, $user['password'])) {
                // Set session dengan data yang lengkap
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                
                // SET SESUAI ROLE
                if ($user['role'] == 'orangtua') {
                    $_SESSION['orangtua_id'] = $user['role_id']; // Ini adalah ID dari tabel orangtua
                    $_SESSION['nama_ortu'] = $user['detail_info'] ?? $user['full_name'];
                } elseif ($user['role'] == 'guru') {
                    $_SESSION['guru_id'] = $user['role_id']; // Ini adalah ID dari tabel guru
                    $_SESSION['bidang_keahlian'] = $user['detail_info'] ?? '';
                }
                
                // Juga simpan role_id untuk kompatibilitas
                $_SESSION['role_id'] = $user['role_id'];

                // Update last login
                $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();

                // Redirect berdasarkan role
                switch ($user['role']) {
                    case 'admin':
                        header('Location: admin/dashboardAdmin.php');
                        break;
                    case 'guru':
                        header('Location: guru/dashboardGuru.php');
                        break;
                    case 'orangtua':
                        header('Location: orangtua/dashboardOrtu.php');
                        break;
                }
                exit();
            } else {
                $error = "Password salah!";
            }
        } else {
            $error = "Username tidak ditemukan!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        /* Responsive adjustments */
        @media (max-width: 640px) {
            .login-container {
                max-width: 100%;
                padding: 15px;
            }
            
            .login-box {
                padding: 1.5rem !important;
            }
            
            .login-box h1 {
                font-size: 1.75rem !important;
            }
            
            .login-box input {
                padding: 0.75rem !important;
            }
            
            .login-box button {
                padding: 0.75rem !important;
            }
        }
        
        @media (max-width: 400px) {
            .login-box {
                padding: 1rem !important;
            }
            
            .login-box h1 {
                font-size: 1.5rem !important;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-box bg-white rounded-xl shadow-2xl p-6 md:p-8">
            <!-- Logo -->
            <div class="text-center mb-6 md:mb-8">
                <div class="inline-flex items-center justify-center w-12 h-12 md:w-16 md:h-16 bg-blue-600 rounded-full mb-3 md:mb-4">
                    <i class="fas fa-graduation-cap text-white text-xl md:text-2xl"></i>
                </div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Bimbel Esc</h1>
                <p class="text-gray-600 mt-1 md:mt-2 text-sm md:text-base">Sistem Penilaian Perkembangan Siswa</p>
            </div>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="text-sm md:text-base"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span class="text-sm md:text-base"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="">
                <div class="mb-4 md:mb-6">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        <i class="fas fa-user mr-2"></i>Username
                    </label>
                    <input type="text" name="username"
                        class="w-full px-3 md:px-4 py-2 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition text-sm md:text-base"
                        placeholder="Masukkan username" required>
                </div>

                <div class="mb-6 md:mb-8">
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        <i class="fas fa-lock mr-2"></i>Password
                    </label>
                    <input type="password" name="password"
                        class="w-full px-3 md:px-4 py-2 md:py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition text-sm md:text-base"
                        placeholder="Masukkan password" required>
                </div>

                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 md:py-3 px-4 rounded-lg transition duration-300 text-sm md:text-base">
                    <i class="fas fa-sign-in-alt mr-2"></i>Masuk
                </button>
            </form>

            <!-- Informasi Login -->
            <div class="mt-6 pt-4 border-t border-gray-200">
                <div class="text-center text-gray-600 text-xs md:text-sm">
                    <p class="mb-2"><i class="fas fa-info-circle mr-1"></i>Gunakan username dan password yang diberikan</p>
                    <p><i class="fas fa-shield-alt mr-1"></i>Pastikan data login Anda aman</p>
                </div>
            </div>

            <!-- Link ke Beranda -->
            <!--<div class="mt-6 text-center">-->
            <!--    <a href="../index.php" class="text-blue-600 hover:text-blue-800 text-xs md:text-sm inline-flex items-center">-->
            <!--        <i class="fas fa-arrow-left mr-1 md:mr-2"></i>Kembali ke Beranda-->
            <!--    </a>-->
            <!--</div>-->

            <!-- Kontak Bantuan -->
            <div class="mt-4 text-center">
                <p class="text-gray-500 text-xs">Butuh bantuan? <a href="mailto:support@bimbelesc.com" class="text-blue-600 hover:text-blue-800">Hubungi Admin</a></p>
            </div>
        </div>
    </div>

    <script>
        // Auto focus on username field
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="username"]').focus();
        });

        // Show password toggle (optional feature)
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.querySelector('input[name="password"]');
            const form = document.querySelector('form');
            
            // Add show/hide password icon
            const passwordContainer = passwordInput.parentElement;
            passwordContainer.style.position = 'relative';
            
            const toggleIcon = document.createElement('span');
            toggleIcon.innerHTML = '<i class="fas fa-eye-slash">';
            toggleIcon.className = 'absolute right-3 top-7 md:top-[30px] text-gray-400 cursor-pointer';
            toggleIcon.style.transform = 'translateY(50%)';
            
            toggleIcon.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            });
            
            passwordContainer.appendChild(toggleIcon);
        });
    </script>
</body>

</html>