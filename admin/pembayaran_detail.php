<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php'; 
require_once '../includes/menu_functions.php'; 

// CEK LOGIN & ROLE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header('Location: ../auth/login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

// Cek apakah ada parameter ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: pembayaran.php');
    exit();
}

$pembayaran_id = intval($_GET['id']);

// Query untuk mendapatkan detail pembayaran
$sql = "SELECT 
    p.*,
    s.nama_lengkap as nama_siswa,
    s.nis,
    s.jenis_kelamin,
    s.tanggal_lahir,
    s.alamat,
    o.nama_ortu,
    o.no_hp as hp_ortu,
    o.email as email_ortu,
    o.pekerjaan,
    k.nama_kelas,
    u1.username as dibuat_oleh_nama,
    u2.username as diperbarui_oleh_nama,
    
    -- Format tanggal
    DATE_FORMAT(p.tanggal_bayar, '%d/%m/%Y') as tgl_bayar_formatted,
    DATE_FORMAT(p.dibuat_pada, '%d/%m/%Y %H:%i:%s') as dibuat_formatted,
    DATE_FORMAT(p.diperbarui_pada, '%d/%m/%Y %H:%i:%s') as diperbarui_formatted,
    DATE_FORMAT(s.tanggal_lahir, '%d/%m/%Y') as tgl_lahir_formatted,
    
    -- Hitung sisa
    (p.nominal_tagihan - COALESCE(p.nominal_dibayar, 0)) as sisa_tagihan,
    
    -- Label status
    CASE p.status
        WHEN 'belum_bayar' THEN 'Belum Bayar'
        WHEN 'lunas' THEN 'Lunas'
        WHEN 'dibebaskan' THEN 'Dibebaskan'
    END as status_label,
    
    -- Label metode bayar
    CASE p.metode_bayar
        WHEN 'cash' THEN '💵 Cash'
        WHEN 'transfer' THEN '🏦 Transfer Bank'
        WHEN 'qris' THEN '📱 QRIS'
        WHEN 'debit' THEN '💳 Kartu Debit'
        WHEN 'credit' THEN '💳 Kartu Kredit'
        WHEN 'ewallet' THEN '📱 E-Wallet'
        ELSE '-'
    END as metode_label

FROM pembayaran p
JOIN siswa s ON p.siswa_id = s.id
JOIN orangtua o ON s.orangtua_id = o.id
LEFT JOIN kelas_siswa ks ON s.id = ks.siswa_id AND ks.status = 'aktif'
LEFT JOIN kelas k ON ks.kelas_id = k.id
LEFT JOIN users u1 ON p.dibuat_oleh = u1.id
LEFT JOIN users u2 ON p.diperbarui_oleh = u2.id
WHERE p.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $pembayaran_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: pembayaran.php');
    exit();
}

$pembayaran = $result->fetch_assoc();

// Format mata uang
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Warna badge berdasarkan status
function getStatusBadge($status) {
    switch($status) {
        case 'lunas': 
            return '<span class="badge badge-success">LUNAS</span>';
        case 'belum_bayar': 
            return '<span class="badge badge-danger">BELUM BAYAR</span>';
        case 'dibebaskan': 
            return '<span class="badge badge-warning">DIBEBASKAN</span>';
        default: 
            return '<span class="badge badge-secondary">-</span>';
    }
}

// Hitung usia
function hitungUsia($tanggal_lahir) {
    if (empty($tanggal_lahir)) return '-';
    
    $birthDate = new DateTime($tanggal_lahir);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pembayaran - Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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

        /* Active menu item */
        .menu-item.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #60A5FA;
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

        /* Custom Styles for Detail Page */
        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #991b1b; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-secondary { background-color: #e5e7eb; color: #374151; }
        
        .info-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .detail-item {
            border-bottom: 1px solid #f3f4f6;
            padding: 16px 0;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .payment-status {
            font-size: 1.1rem;
            font-weight: 600;
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
        <div class="bg-white shadow">
            <div class="container mx-auto px-4 py-4">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                    <div>
                        <div class="flex items-center mb-2">
                            <a href="pembayaran.php" class="text-blue-600 hover:text-blue-800 mr-3">
                                <i class="fas fa-arrow-left"></i>
                            </a>
                            <h1 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-file-invoice-dollar mr-2"></i>Detail Pembayaran
                            </h1>
                        </div>
                        <p class="text-gray-600">Informasi lengkap tagihan pembayaran</p>
                    </div>
                    
                    <div class="mt-4 md:mt-0 flex space-x-2">
                        <a href="pembayaran.php?bulan=<?= date('Y-m', strtotime($pembayaran['bulan_tagihan'] . '-01')) ?>" 
                           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 flex items-center">
                            <i class="fas fa-list mr-2"></i> Kembali ke Daftar
                        </a>
                        
                        <?php if ($pembayaran['status'] == 'belum_bayar'): ?>
                        <button onclick="window.location.href='pembayaran.php?edit=<?= $pembayaran['id'] ?>'"
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-edit mr-2"></i> Edit Status
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto p-4 md:p-6">
            <!-- Status & Info Card -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Status Card -->
                <div class="info-card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Status Pembayaran</h2>
                        <?= getStatusBadge($pembayaran['status']) ?>
                    </div>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Bulan Tagihan:</span>
                            <span class="font-semibold"><?= date('F Y', strtotime($pembayaran['bulan_tagihan'] . '-01')) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Metode Bayar:</span>
                            <span class="font-medium"><?= $pembayaran['metode_label'] ?></span>
                        </div>
                        
                        <?php if ($pembayaran['tanggal_bayar']): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Tanggal Bayar:</span>
                            <span class="font-medium"><?= $pembayaran['tgl_bayar_formatted'] ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($pembayaran['diperbarui_oleh_nama']): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Diupdate oleh:</span>
                            <span class="font-medium"><?= $pembayaran['diperbarui_oleh_nama'] ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Terakhir update:</span>
                            <span class="font-medium text-sm"><?= $pembayaran['diperbarui_formatted'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Amount Card -->
                <div class="info-card p-5">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Informasi Tagihan</h2>
                    
                    <div class="space-y-4">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Nominal Tagihan</p>
                            <p class="text-2xl font-bold text-gray-800"><?= formatRupiah($pembayaran['nominal_tagihan']) ?></p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Nominal Dibayar</p>
                            <p class="text-2xl font-bold <?= $pembayaran['nominal_dibayar'] > 0 ? 'text-green-600' : 'text-gray-600' ?>">
                                <?= formatRupiah($pembayaran['nominal_dibayar']) ?>
                            </p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Sisa Tagihan</p>
                            <p class="text-xl font-bold <?= $pembayaran['sisa_tagihan'] > 0 ? 'text-red-600' : 'text-green-600' ?>">
                                <?= formatRupiah($pembayaran['sisa_tagihan']) ?>
                            </p>
                        </div>
                        
                        <?php if ($pembayaran['sisa_tagihan'] > 0): ?>
                        <div class="mt-4 p-3 bg-yellow-50 rounded-lg">
                            <p class="text-sm text-yellow-700">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Belum Lunas:</strong> Masih ada sisa tagihan sebesar <?= formatRupiah($pembayaran['sisa_tagihan']) ?>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="mt-4 p-3 bg-green-50 rounded-lg">
                            <p class="text-sm text-green-700">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Lunas:</strong> Tagihan sudah dibayar penuh
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Student Info Card -->
                <div class="info-card p-5">
                    <div class="flex items-center mb-4">
                        <div class="h-12 w-12 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-user-graduate text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">Informasi Siswa</h2>
                            <p class="text-sm text-gray-500">Data siswa terkait</p>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Nama:</span>
                            <span class="font-semibold"><?= htmlspecialchars($pembayaran['nama_siswa']) ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">NIS:</span>
                            <span class="font-medium"><?= $pembayaran['nis'] ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Kelas:</span>
                            <span class="font-medium"><?= $pembayaran['nama_kelas'] ?? '-' ?></span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Jenis Kelamin:</span>
                            <span class="font-medium"><?= $pembayaran['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan' ?></span>
                        </div>
                        
                        <?php if ($pembayaran['tanggal_lahir']): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Usia:</span>
                            <span class="font-medium"><?= hitungUsia($pembayaran['tanggal_lahir']) ?> tahun</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Detail Information -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Parent Information -->
                <div class="info-card p-5">
                    <div class="flex items-center mb-4">
                        <div class="h-10 w-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-user-friends text-green-600"></i>
                        </div>
                        <h2 class="text-lg font-semibold text-gray-800">Informasi Orang Tua</h2>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Nama Orang Tua</p>
                            <p class="font-medium"><?= htmlspecialchars($pembayaran['nama_ortu']) ?></p>
                        </div>
                        
                        <?php if (!empty($pembayaran['hp_ortu'])): ?>
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">No. HP</p>
                            <p class="font-medium"><?= $pembayaran['hp_ortu'] ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pembayaran['email_ortu'])): ?>
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Email</p>
                            <p class="font-medium"><?= htmlspecialchars($pembayaran['email_ortu']) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pembayaran['pekerjaan'])): ?>
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Pekerjaan</p>
                            <p class="font-medium"><?= htmlspecialchars($pembayaran['pekerjaan']) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pembayaran['alamat'])): ?>
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Alamat</p>
                            <p class="font-medium"><?= htmlspecialchars($pembayaran['alamat']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Timeline -->
                <div class="info-card p-5">
                    <div class="flex items-center mb-4">
                        <div class="h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-history text-purple-600"></i>
                        </div>
                        <h2 class="text-lg font-semibold text-gray-800">Riwayat Pembayaran</h2>
                    </div>
                    
                    <div class="space-y-4">
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">ID Tagihan</p>
                            <p class="font-mono font-medium">#<?= str_pad($pembayaran['id'], 6, '0', STR_PAD_LEFT) ?></p>
                        </div>
                        
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Dibuat Pada</p>
                            <p class="font-medium"><?= $pembayaran['dibuat_formatted'] ?></p>
                            <?php if (!empty($pembayaran['dibuat_oleh_nama'])): ?>
                            <p class="text-xs text-gray-500">Oleh: <?= $pembayaran['dibuat_oleh_nama'] ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <!--<?php if ($pembayaran['diperbarui_pada'] != $pembayaran['dibuat_pada']): ?>-->
                        <!--<div class="detail-item">-->
                        <!--    <p class="text-sm text-gray-500 mb-1">Diperbarui Pada</p>-->
                        <!--    <p class="font-medium"><?= $pembayaran['diperbarui_formatted'] ?></p>-->
                        <!--    <?php if (!empty($pembayaran['diperbarui_oleh_nama'])): ?>-->
                        <!--    <p class="text-xs text-gray-500">Oleh: <?= $pembayaran['diperbarui_oleh_nama'] ?></p>-->
                        <!--    <?php endif; ?>-->
                        <!--</div>-->
                        <!--<?php endif; ?>-->
                        
                        <?php if (!empty($pembayaran['keterangan'])): ?>
                        <div class="detail-item">
                            <p class="text-sm text-gray-500 mb-1">Keterangan</p>
                            <p class="font-medium text-sm"><?= nl2br(htmlspecialchars($pembayaran['keterangan'])) ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                            <h3 class="font-semibold text-blue-800 mb-2">Status Pembayaran</h3>
                            <p class="text-sm text-blue-700">
                                <?php if ($pembayaran['status'] == 'lunas'): ?>
                                <i class="fas fa-check-circle mr-2"></i>
                                Pembayaran telah dilunasi pada <?= $pembayaran['tgl_bayar_formatted'] ?> via <?= $pembayaran['metode_label'] ?>
                                <?php elseif ($pembayaran['status'] == 'dibebaskan'): ?>
                                <i class="fas fa-gift mr-2"></i>
                                Tagihan dibebaskan dari pembayaran
                                <?php else: ?>
                                <i class="fas fa-clock mr-2"></i>
                                Menunggu pembayaran
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-4 sm:space-y-0">
                <div class="text-sm text-gray-500">
                    <p>ID: <?= $pembayaran['id'] ?> | Siswa ID: <?= $pembayaran['siswa_id'] ?> | Bulan: <?= $pembayaran['bulan_tagihan'] ?></p>
                </div>
                
                <div class="flex space-x-2">
                    <a href="pembayaran.php" 
                       class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i> Kembali
                    </a>
                    
                    <?php if ($pembayaran['status'] == 'belum_bayar'): ?>
                    <a href="pembayaran.php?edit=<?= $pembayaran['id'] ?>" 
                       class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                        <i class="fas fa-edit mr-2"></i> Edit Status
                    </a>
                    <?php endif; ?>
                    
                    <button onclick="window.print()" 
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center">
                        <i class="fas fa-print mr-2"></i> Cetak
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-8">
            <div class="container mx-auto py-4 px-4">
                <div class="md:flex md:items-center md:justify-between">
                    <div class="text-sm text-gray-500">
                        <p>© <?php echo date('Y'); ?> Bimbel Esc - Detail Pembayaran</p>
                        <p class="mt-1 text-xs text-gray-400">
                            <i class="fas fa-file-invoice mr-1"></i>
                            Tagihan <?= date('F Y', strtotime($pembayaran['bulan_tagihan'] . '-01')) ?>
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

        // Update server time
        function updateServerTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID');
            const timeElement = document.getElementById('serverTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }

        // Print styles
        const style = document.createElement('style');
        style.innerHTML = `
            @media print {
                .desktop-sidebar,
                .mobile-header,
                .menu-overlay,
                #mobileMenu,
                footer,
                button,
                a {
                    display: none !important;
                }
                
                body {
                    margin: 0;
                    padding: 20px;
                    background: white;
                }
                
                .md\\:ml-64 {
                    margin-left: 0 !important;
                }
                
                .info-card {
                    box-shadow: none;
                    border: 1px solid #ddd;
                    margin-bottom: 20px;
                    page-break-inside: avoid;
                }
                
                .grid {
                    display: block;
                }
                
                .container {
                    max-width: 100%;
                    padding: 0;
                }
                
                h1, h2, h3 {
                    color: black !important;
                }
                
                .badge {
                    border: 1px solid #666;
                    color: black !important;
                    background: white !important;
                }
                
                .payment-status {
                    font-size: 24px;
                    margin: 20px 0;
                }
            }
        `;
        document.head.appendChild(style);

        setInterval(updateServerTime, 1000);
        updateServerTime();
    </script>
</body>
</html>