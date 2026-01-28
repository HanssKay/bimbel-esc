<?php
session_start();
require_once '../includes/config.php';

$error = '';
$success = '';

// Jika sudah login, redirect ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ../' . $_SESSION['user_role'] . '/dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ambil data dari form
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = trim($_POST['phone']);
    
    // Data guru
    $nip = trim($_POST['nip']);
    $bidang_keahlian = trim($_POST['bidang_keahlian']);
    $pendidikan_terakhir = trim($_POST['pendidikan_terakhir']);
    $pengalaman_tahun = $_POST['pengalaman_tahun'];

    // Validasi
    if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        $error = "Semua field wajib diisi!";
    } elseif ($password !== $confirm_password) {
        $error = "Password dan konfirmasi password tidak sama!";
    } elseif (strlen($password) < 6) {
        $error = "Password minimal 6 karakter!";
    } else {
        // Cek apakah username sudah ada
        $check_sql = "SELECT id FROM users WHERE username = ? OR email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Username atau email sudah terdaftar!";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Mulai transaction
            $conn->begin_transaction();
            
            try {
                // 1. Insert ke tabel users
                $sql_user = "INSERT INTO users (username, password, email, role, full_name, phone, is_active) 
                            VALUES (?, ?, ?, 'guru', ?, ?, 1)";
                $stmt_user = $conn->prepare($sql_user);
                $stmt_user->bind_param("sssss", $username, $hashed_password, $email, $full_name, $phone);
                
                if (!$stmt_user->execute()) {
                    throw new Exception("Gagal menyimpan data user: " . $conn->error);
                }
                
                $user_id = $stmt_user->insert_id;
                
                // 2. Insert ke tabel guru
                $sql_guru = "INSERT INTO guru (user_id, nip, bidang_keahlian, pendidikan_terakhir, pengalaman_tahun, status, tanggal_bergabung) 
                            VALUES (?, ?, ?, ?, ?, 'aktif', CURDATE())";
                $stmt_guru = $conn->prepare($sql_guru);
                $stmt_guru->bind_param("isssi", $user_id, $nip, $bidang_keahlian, $pendidikan_terakhir, $pengalaman_tahun);
                
                if (!$stmt_guru->execute()) {
                    throw new Exception("Gagal menyimpan data guru: " . $conn->error);
                }
                
                // Commit transaction
                $conn->commit();
                
                $success = "Registrasi berhasil! Anda bisa login sekarang.";
                
            } catch (Exception $e) {
                // Rollback jika ada error
                $conn->rollback();
                $error = "Terjadi kesalahan: " . $e->getMessage();
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
    <title>Registrasi Guru - Bimbel Excellence</title>
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
    </style>
</head>
<body>
    <div class="max-w-2xl w-full bg-white rounded-xl shadow-2xl p-8">
        <!-- Logo & Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-full mb-4">
                <i class="fas fa-chalkboard-teacher text-white text-2xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Registrasi Guru</h1>
            <p class="text-gray-600 mt-2">Daftarkan akun guru baru</p>
        </div>

        <!-- Messages -->
        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <br>
                <a href="login.php" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">
                    <i class="fas fa-sign-in-alt mr-1"></i>Login Sekarang
                </a>
            </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Kolom 1: Data Pribadi -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">
                    <i class="fas fa-user mr-2"></i>Data Pribadi
                </h3>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Nama Lengkap *
                    </label>
                    <input type="text" name="full_name" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Budi Santoso, S.Pd">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Username *
                    </label>
                    <input type="text" name="username" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="guru_budi">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Email *
                    </label>
                    <input type="email" name="email" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="budi@bimbel.com">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        No. Telepon
                    </label>
                    <input type="tel" name="phone"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="081234567890">
                </div>
            </div>

            <!-- Kolom 2: Password & Data Guru -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-gray-800 border-b pb-2">
                    <i class="fas fa-lock mr-2"></i>Password & Data Guru
                </h3>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Password *
                    </label>
                    <input type="password" name="password" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Minimal 6 karakter">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Konfirmasi Password *
                    </label>
                    <input type="password" name="confirm_password" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Ulangi password">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        NIP
                    </label>
                    <input type="text" name="nip"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="198703122005011001">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Bidang Keahlian
                    </label>
                    <input type="text" name="bidang_keahlian"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                        placeholder="Matematika, IPA, Bahasa Inggris">
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Pendidikan Terakhir
                    </label>
                    <select name="pendidikan_terakhir"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                        <option value="">Pilih Pendidikan</option>
                        <option value="SMA">SMA</option>
                        <option value="D3">D3</option>
                        <option value="S1">S1</option>
                        <option value="S2">S2</option>
                        <option value="S3">S3</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2">
                        Pengalaman (tahun)
                    </label>
                    <input type="number" name="pengalaman_tahun" min="0" max="50" value="0"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                </div>
            </div>

            <!-- Submit Button (Full width) -->
            <div class="md:col-span-2">
                <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg transition duration-300">
                    <i class="fas fa-user-plus mr-2"></i>Daftar Sekarang
                </button>
            </div>
        </form>

        <!-- Links -->
        <div class="mt-8 pt-6 border-t border-gray-200 text-center">
            <p class="text-gray-600">
                Sudah punya akun?
                <a href="login.php" class="text-blue-600 hover:text-blue-800 font-semibold ml-1">
                    <i class="fas fa-sign-in-alt mr-1"></i>Login di sini
                </a>
            </p>
            <p class="mt-2">
                <a href="../index.php" class="text-gray-600 hover:text-gray-800 text-sm">
                    <i class="fas fa-arrow-left mr-1"></i>Kembali ke Beranda
                </a>
            </p>
        </div>
    </div>
</body>
</html>