<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE ORANG TUA
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'orangtua') {
    header('Location: ../index.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Orang Tua';
$email = $_SESSION['email'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

$KATEGORI_LIST = [
    'zoom'     => ['label' => 'Zoom / Kelas Online',   'icon' => 'fas fa-video',           'badgeBg' => 'bg-blue-100 text-blue-700',       'iconBg' => 'bg-blue-600'],
    'materi'   => ['label' => 'Materi / Google Drive', 'icon' => 'fas fa-folder-open',     'badgeBg' => 'bg-amber-100 text-amber-800',     'iconBg' => 'bg-amber-500'],
    'whatsapp' => ['label' => 'Grup WhatsApp',         'icon' => 'fab fa-whatsapp',        'badgeBg' => 'bg-emerald-100 text-emerald-700', 'iconBg' => 'bg-emerald-600'],
    'form'     => ['label' => 'Formulir / Kuis',       'icon' => 'fas fa-clipboard-check', 'badgeBg' => 'bg-purple-100 text-purple-700',   'iconBg' => 'bg-purple-600'],
    'video'    => ['label' => 'Video Pembelajaran',    'icon' => 'fab fa-youtube',         'badgeBg' => 'bg-rose-100 text-rose-700',       'iconBg' => 'bg-rose-600'],
    'lainnya'  => ['label' => 'Lainnya',               'icon' => 'fas fa-link',            'badgeBg' => 'bg-slate-100 text-slate-700',     'iconBg' => 'bg-slate-600'],
];

// AMBIL DATA ORANG TUA
$orangtua_id = 0;
$nama_ortu = $full_name;
$stmtOrtu = $conn->prepare("SELECT id, nama_ortu, email FROM orangtua WHERE user_id = ? LIMIT 1");
if ($stmtOrtu) {
    $stmtOrtu->bind_param("i", $user_id);
    $stmtOrtu->execute();
    if ($rowOrtu = $stmtOrtu->get_result()->fetch_assoc()) {
        $orangtua_id = (int) $rowOrtu['id'];
        $nama_ortu = $rowOrtu['nama_ortu'] ?: $full_name;
        if (!empty($rowOrtu['email'])) {
            $email = $rowOrtu['email'];
        }
    }
    $stmtOrtu->close();
}

// AMBIL DAFTAR ANAK AKTIF BESERTA TINGKAT AKTIFNYA
$anak_data = [];
if ($orangtua_id > 0) {
    $sqlAnak = "SELECT s.id, s.nama_lengkap, s.kelas,
                       COALESCE(MAX(ps.tingkat), '') AS tingkat,
                       COALESCE(MAX(ps.jenis_kelas), '') AS jenis_kelas
                FROM siswa s
                INNER JOIN siswa_orangtua so ON s.id = so.siswa_id
                LEFT JOIN pendaftaran_siswa ps ON ps.siswa_id = s.id AND ps.status = 'aktif'
                WHERE so.orangtua_id = ? AND s.status = 'aktif'
                GROUP BY s.id, s.nama_lengkap, s.kelas
                ORDER BY s.nama_lengkap ASC";
    $stmtAnak = $conn->prepare($sqlAnak);
    if ($stmtAnak) {
        $stmtAnak->bind_param("i", $orangtua_id);
        $stmtAnak->execute();
        $resAnak = $stmtAnak->get_result();
        while ($row = $resAnak->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $anak_data[] = $row;
        }
        $stmtAnak->close();
    }
}
$total_anak = count($anak_data);

// AMBIL SHARED LINKS YANG TERHUBUNG DENGAN ANAK-ANAK ORANG TUA INI
$visibleLinks = [];
$unreadCount = 0;

if ($orangtua_id > 0 && $total_anak > 0) {
    $childIds = array_column($anak_data, 'id');
    $childTingkats = array_values(array_unique(array_filter(array_column($anak_data, 'tingkat'))));

    // Ambil semua link aktif yang belum kadaluarsa
    $sqlLinks = "SELECT sl.*, slc.jumlah_klik, slc.terakhir_dibuka
                 FROM shared_links sl
                 LEFT JOIN shared_link_clicks slc ON slc.link_id = sl.id AND slc.orangtua_id = ?
                 WHERE sl.status = 'aktif'
                   AND (sl.berlaku_sampai IS NULL OR sl.berlaku_sampai >= CURDATE())
                 ORDER BY sl.is_pinned DESC, sl.created_at DESC";
    $stmtLinks = $conn->prepare($sqlLinks);
    $stmtLinks->bind_param("i", $orangtua_id);
    $stmtLinks->execute();
    $resLinks = $stmtLinks->get_result();

    $targetStmt = $conn->prepare("SELECT target_tingkat, siswa_id FROM shared_link_targets WHERE link_id = ?");

    while ($link = $resLinks->fetch_assoc()) {
        $lid = (int) $link['id'];
        $targetStmt->bind_param("i", $lid);
        $targetStmt->execute();
        $tRes = $targetStmt->get_result();

        $targetTingkats = [];
        $targetSiswaIds = [];
        while ($tRow = $tRes->fetch_assoc()) {
            if (!empty($tRow['target_tingkat'])) $targetTingkats[] = $tRow['target_tingkat'];
            if (!empty($tRow['siswa_id'])) $targetSiswaIds[] = (int) $tRow['siswa_id'];
        }

        // Cocokkan dengan daftar anak orang tua ini
        $matchedChildren = [];
        foreach ($anak_data as $anak) {
            if ($link['target_type'] === 'tingkat' && $anak['tingkat'] !== '' && in_array($anak['tingkat'], $targetTingkats, true)) {
                $matchedChildren[] = $anak;
            } elseif ($link['target_type'] === 'specific' && in_array($anak['id'], $targetSiswaIds, true)) {
                $matchedChildren[] = $anak;
            }
        }

        if (!empty($matchedChildren)) {
            $link['id'] = $lid;
            $link['is_pinned'] = (int) $link['is_pinned'];
            $link['jumlah_klik'] = (int) ($link['jumlah_klik'] ?? 0);
            $link['matched_children'] = $matchedChildren;
            $link['matched_child_ids'] = array_column($matchedChildren, 'id');
            if ($link['jumlah_klik'] === 0) {
                $unreadCount++;
            }
            $visibleLinks[] = $link;
        }
    }

    $targetStmt->close();
    $stmtLinks->close();
}

function extractHost($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? preg_replace('/^www\./', '', $host) : $url;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../config/analytics.php'; ?>
    <title>Smart Quests - Dashboard Orang Tua Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dropdown-submenu { display: none; max-height: 500px; overflow: hidden; transition: max-height 0.3s ease; }
        .dropdown-submenu[style*="display: block"] { display: block; }
        .dropdown-toggle.open .arrow { transform: rotate(-90deg); }
        .menu-item.active { background-color: rgba(255, 255, 255, 0.12); border-left: 4px solid #60A5FA; }

        #mobileMenu {
            position: fixed; top: 0; left: 0; width: 280px; height: 100%; z-index: 1100;
            transform: translateX(-100%); transition: transform 0.3s ease-in-out;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.2); background-color: #1e40af;
        }
        #mobileMenu.menu-open { transform: translateX(0); }
        .menu-overlay {
            display: none; position: fixed; inset: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 1099;
        }
        .menu-overlay.active { display: block; }

        .desktop-sidebar { display: none; }
        @media (min-width: 768px) {
            .desktop-sidebar { display: block; }
            .mobile-header, #mobileMenu { display: none; }
            .menu-overlay { display: none !important; }
        }

        .modal-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
            z-index: 1200; align-items: center; justify-content: center; padding: 1rem;
            backdrop-filter: blur(2px);
        }
        .modal-backdrop.active { display: flex; }
        .truncate-2 {
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Desktop Sidebar -->
    <div class="desktop-sidebar w-64 bg-blue-800 text-white fixed h-full z-40 overflow-y-auto">
        <div class="p-4">
            <h1 class="text-xl font-bold">Bimbel Esc</h1>
            <p class="text-sm text-blue-200">Dashboard Orang Tua</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-user"></i>
                </div>
                <div class="ml-3 min-w-0">
                    <p class="font-medium truncate"><?php echo htmlspecialchars($nama_ortu); ?></p>
                    <p class="text-sm text-blue-300">Orang Tua</p>
                    <p class="text-xs text-blue-200 truncate"><?php echo htmlspecialchars($email); ?></p>
                </div>
            </div>
            <div class="mt-3 text-sm">
                <p><i class="fas fa-child mr-2"></i> <?php echo $total_anak; ?> Anak</p>
            </div>
        </div>
        <nav class="mt-4 pb-6">
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
                <h1 class="text-xl font-bold">Smart Quests</h1>
            </div>
            <div class="w-8 h-8 bg-white text-blue-800 rounded-full flex items-center justify-center">
                <i class="fas fa-user"></i>
            </div>
        </div>
    </div>

    <!-- Mobile Overlay & Drawer -->
    <div id="menuOverlay" class="menu-overlay"></div>
    <div id="mobileMenu" class="bg-blue-800 text-white md:hidden">
        <div class="h-full flex flex-col">
            <div class="p-4 bg-blue-900 flex items-center">
                <button id="menuClose" class="text-white mr-3">
                    <i class="fas fa-times text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Bimbel Esc</h1>
            </div>
            <div class="p-4 bg-blue-800">
                <div class="flex items-center">
                    <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="ml-3 min-w-0">
                        <p class="font-medium truncate"><?php echo htmlspecialchars($nama_ortu); ?></p>
                        <p class="text-sm text-blue-300">Orang Tua</p>
                    </div>
                </div>
                <div class="mt-2 text-xs text-blue-200">
                    <i class="fas fa-child mr-1.5"></i> <?php echo $total_anak; ?> Anak
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto pb-6">
                <?php echo renderMenu($currentPage, 'orangtua'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64 min-h-screen">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Header -->
        <div class="bg-white shadow-sm border-b border-gray-200 px-4 md:px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Smart Quests</h1>
                    <p class="text-sm text-gray-600 mt-0.5">
                        Akses latihan soal, kuis, dan tugas belajar dari Bimbel Esc sesuai tingkat dan kebutuhan anak Anda.
                    </p>
                </div>
                <div id="unreadHeaderBadge">
                    <?php if ($unreadCount > 0): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-rose-100 text-rose-700">
                            <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                            <span id="unreadHeaderText"><?php echo $unreadCount; ?> Tugas Baru Belum Dibuka</span>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">
                            <i class="fas fa-check-circle"></i> Semua Tugas Sudah Dibuka
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="p-4 md:p-6 max-w-6xl mx-auto space-y-5">
            <!-- Banner Akun Terhubung -->
            <div class="bg-gradient-to-r from-blue-700 to-indigo-700 text-white rounded-xl p-4 md:p-5 shadow-md">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-start gap-3.5">
                        <div class="w-11 h-11 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                            <i class="fas fa-list-check text-xl text-white"></i>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wider text-blue-200 font-semibold">Akun Orang Tua Terhubung</p>
                            <h2 class="text-lg font-bold"><?php echo htmlspecialchars($nama_ortu); ?></h2>
                            <div class="flex flex-wrap gap-1.5 mt-1">
                                <?php if ($total_anak > 0): ?>
                                    <?php foreach ($anak_data as $anak): ?>
                                        <span class="inline-flex items-center bg-white/15 px-2.5 py-0.5 rounded-md text-xs text-blue-50">
                                            <i class="fas fa-user-graduate mr-1.5 text-[11px]"></i>
                                            <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                            <?php if ($anak['tingkat'] !== ''): ?>
                                                (<?php echo htmlspecialchars($anak['tingkat']); ?> - <?php echo htmlspecialchars($anak['kelas']); ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-xs text-blue-200">Belum ada data anak aktif yang terhubung.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 self-start md:self-center">
                        <div class="bg-white/15 rounded-lg px-3.5 py-2 text-center">
                            <div class="text-xl font-bold leading-none"><?php echo count($visibleLinks); ?></div>
                            <div class="text-[11px] text-blue-100 mt-1">Total Tugas</div>
                        </div>
                        <div class="bg-white/15 rounded-lg px-3.5 py-2 text-center">
                            <div id="statUnreadCount" class="text-xl font-bold leading-none text-amber-300"><?php echo $unreadCount; ?></div>
                            <div class="text-[11px] text-blue-100 mt-1">Belum Dibuka</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($total_anak === 0): ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-4 rounded-r-xl">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-3 text-lg"></i>
                        <div>
                            <p class="font-bold text-sm">Belum ada data anak aktif</p>
                            <p class="text-xs mt-0.5">Hubungi Admin Bimbel Esc untuk menautkan data siswa ke akun Anda agar dapat menerima tautan belajar.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Filter Bar -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 space-y-3">
                    <?php if ($total_anak > 1): ?>
                        <div class="flex flex-wrap items-center gap-2 pb-3 border-b border-gray-100">
                            <span class="text-xs font-semibold text-gray-500 mr-1">Filter Anak:</span>
                            <button type="button" onclick="setChildFilter('all', this)"
                                class="child-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-600 text-white shadow-sm transition">
                                Semua Anak (<?php echo $total_anak; ?>)
                            </button>
                            <?php foreach ($anak_data as $anak): ?>
                                <button type="button" onclick="setChildFilter('<?php echo $anak['id']; ?>', this)"
                                    class="child-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                                    <?php echo htmlspecialchars($anak['nama_lengkap']); ?>
                                    <?php echo $anak['tingkat'] ? ' (' . htmlspecialchars($anak['tingkat']) . ')' : ''; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" onclick="setCategoryFilter('', this)" class="cat-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-blue-600 text-white transition">Semua</button>
                            <?php foreach ($KATEGORI_LIST as $kKey => $kMeta): ?>
                                <button type="button" onclick="setCategoryFilter('<?php echo $kKey; ?>', this)" class="cat-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 transition">
                                    <?php echo htmlspecialchars(explode(' / ', $kMeta['label'])[0]); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer select-none whitespace-nowrap">
                                <input type="checkbox" id="filterOnlyUnread" onchange="applyOrtuFilters()" class="rounded text-blue-600">
                                <span>Belum dibuka</span>
                            </label>
                            <div class="relative flex-1 md:w-56">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                <input type="text" id="filterSearchOrtu" oninput="applyOrtuFilters()"
                                    placeholder="Cari link..."
                                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($visibleLinks)): ?>
                    <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                        <i class="fas fa-inbox text-4xl text-gray-300 mb-3"></i>
                        <p class="font-semibold text-gray-600">Belum ada tautan dari Admin saat ini</p>
                        <p class="text-xs text-gray-400 mt-1">Tautan kelas online, materi, atau informasi dari Admin akan otomatis tampil di sini.</p>
                    </div>
                <?php else: ?>
                    <?php
                        $pinnedLinks = array_filter($visibleLinks, fn($l) => $l['is_pinned'] === 1);
                        $regularLinks = array_filter($visibleLinks, fn($l) => $l['is_pinned'] === 0);

                        $renderLinkCard = function($link) use ($KATEGORI_LIST, $total_anak) {
                            $catMeta = $KATEGORI_LIST[$link['kategori']] ?? $KATEGORI_LIST['lainnya'];
                            $isNew = ($link['jumlah_klik'] === 0);
                            $childIdsCsv = implode(',', $link['matched_child_ids']);
                            $searchStr = strtolower($link['judul'] . ' ' . ($link['deskripsi'] ?? '') . ' ' . $link['url']);
                            ?>
                            <div class="ortu-link-card bg-white rounded-xl shadow-sm border <?php echo $isNew ? 'border-blue-300 ring-1 ring-blue-100' : 'border-gray-200'; ?> p-4 md:p-5 flex flex-col justify-between hover:shadow-md transition"
                                 id="linkCard-<?php echo $link['id']; ?>"
                                 data-children="<?php echo htmlspecialchars($childIdsCsv); ?>"
                                 data-kategori="<?php echo htmlspecialchars($link['kategori']); ?>"
                                 data-unread="<?php echo $isNew ? '1' : '0'; ?>"
                                 data-search="<?php echo htmlspecialchars($searchStr); ?>">
                                <div>
                                    <div class="flex items-start justify-between gap-2 mb-2.5">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-10 h-10 rounded-xl <?php echo $catMeta['iconBg']; ?> text-white flex items-center justify-center shrink-0 shadow-sm">
                                                <i class="<?php echo $catMeta['icon']; ?>"></i>
                                            </div>
                                            <div>
                                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold <?php echo $catMeta['badgeBg']; ?>">
                                                    <?php echo htmlspecialchars($catMeta['label']); ?>
                                                </span>
                                                <p class="text-[11px] text-gray-400 mt-0.5"><?php echo htmlspecialchars(extractHost($link['url'])); ?></p>
                                            </div>
                                        </div>
                                        <span class="status-pill shrink-0">
                                            <?php if ($isNew): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500 text-white shadow-sm">BARU</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    <i class="fas fa-check mr-1"></i>Sudah Dibuka
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <h4 class="text-base font-bold text-gray-800 leading-snug"><?php echo htmlspecialchars($link['judul']); ?></h4>
                                    <p class="text-xs md:text-sm text-gray-600 mt-1.5 truncate-2">
                                        <?php echo htmlspecialchars($link['deskripsi'] ?: 'Tidak ada catatan tambahan dari admin.'); ?>
                                    </p>

                                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                                        <?php if ($total_anak > 1 && count($link['matched_children']) === $total_anak): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-indigo-50 text-indigo-700">
                                                <i class="fas fa-child mr-1"></i>Untuk Semua Anak
                                            </span>
                                        <?php else: ?>
                                            <?php foreach ($link['matched_children'] as $mc): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-indigo-50 text-indigo-700">
                                                    <i class="fas fa-user-graduate mr-1"></i>
                                                    <?php echo htmlspecialchars(explode(' ', $mc['nama_lengkap'])[0]); ?>
                                                    <?php echo $mc['tingkat'] ? ' (' . htmlspecialchars($mc['tingkat']) . ')' : ''; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($link['berlaku_sampai'])): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] bg-amber-50 text-amber-800">
                                                <i class="far fa-clock mr-1"></i>s/d <?php echo date('d M Y', strtotime($link['berlaku_sampai'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between gap-2">
                                    <span class="text-[11px] text-gray-400">
                                        <i class="far fa-calendar mr-1"></i><?php echo date('d M Y', strtotime($link['created_at'])); ?>
                                    </span>
                                    <div class="flex items-center gap-1.5">
                                        <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($link['url'], ENT_QUOTES); ?>')" title="Salin URL"
                                            class="px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 transition">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <button type="button" onclick="openAndTrackLink(<?php echo $link['id']; ?>)"
                                            class="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-sm transition flex items-center gap-1.5">
                                            <span>Buka Link</span>
                                            <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php
                        };
                    ?>

                    <?php if (!empty($pinnedLinks)): ?>
                        <div id="pinnedSection" class="space-y-3">
                            <div class="flex items-center gap-2 text-sm font-bold text-amber-700">
                                <i class="fas fa-thumbtack"></i>
                                <span>LINK PENTING / DISEMATKAN</span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($pinnedLinks as $pl) { $renderLinkCard($pl); } ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($regularLinks)): ?>
                        <div id="regularSection" class="space-y-3">
                            <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-folder-open text-blue-600"></i>
                                <span>Daftar Tautan Lainnya</span>
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($regularLinks as $rl) { $renderLinkCard($rl); } ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div id="emptyFilterOrtu" class="hidden bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                        <i class="fas fa-search text-4xl text-gray-300 mb-3"></i>
                        <p class="font-semibold text-gray-600">Tidak ada tautan yang sesuai filter</p>
                        <p class="text-xs text-gray-400 mt-1">Ubah filter anak, kategori, atau kata kunci pencarian.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Detail & Buka Link -->
    <div id="openLinkModal" class="modal-backdrop">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-blue-50">
                <div class="flex items-center gap-2.5">
                    <div id="modalIconBox" class="w-9 h-9 rounded-lg flex items-center justify-center text-white"></div>
                    <div>
                        <h3 class="text-base font-bold text-gray-800">Detail &amp; Buka Tautan</h3>
                        <p id="modalCatLabel" class="text-xs text-gray-500"></p>
                    </div>
                </div>
                <button type="button" onclick="closeOpenModal()" class="text-gray-400 hover:text-gray-600 p-1">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            <div id="modalBody" class="p-6 space-y-4"></div>
            <div class="px-6 py-3.5 border-t border-gray-200 bg-gray-50 flex flex-wrap justify-end gap-2">
                <button type="button" onclick="closeOpenModal()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100">
                    Tutup
                </button>
                <button type="button" id="btnCopyModal"
                    class="px-4 py-2 text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg flex items-center gap-1.5">
                    <i class="fas fa-copy"></i> Salin URL
                </button>
                <a id="btnLaunchUrl" href="#" target="_blank" rel="noopener noreferrer"
                    class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm flex items-center gap-1.5">
                    <i class="fas fa-external-link-alt"></i> Buka di Tab Baru
                </a>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="fixed bottom-5 right-5 z-[1300] flex flex-col gap-2 max-w-sm"></div>

    <script>
        const LINKS_MAP = <?php
            $jsMap = [];
            foreach ($visibleLinks as $l) {
                $jsMap[$l['id']] = [
                    'id'        => $l['id'],
                    'judul'     => $l['judul'],
                    'url'       => $l['url'],
                    'kategori'  => $l['kategori'],
                    'deskripsi' => $l['deskripsi'] ?: 'Tidak ada catatan tambahan dari admin.',
                ];
            }
            echo json_encode($jsMap, JSON_UNESCAPED_UNICODE);
        ?>;
        const KATEGORI_META = <?php echo json_encode($KATEGORI_LIST, JSON_UNESCAPED_UNICODE); ?>;

        let activeChildFilter = 'all';
        let activeCatFilter = '';
        let currentUnreadCount = <?php echo (int) $unreadCount; ?>;

        // Sidebar Mobile & Dropdown
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
        [menuClose, menuOverlay].forEach(el => {
            if (el) el.addEventListener('click', () => {
                mobileMenu.classList.remove('menu-open');
                menuOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const submenu = this.closest('.mb-1').querySelector('.dropdown-submenu');
                const isOpen = submenu.style.display === 'block';
                submenu.style.display = isOpen ? 'none' : 'block';
                this.classList.toggle('open', !isOpen);
            });
        });

        function setChildFilter(childId, btn) {
            activeChildFilter = childId;
            document.querySelectorAll('.child-filter-btn').forEach(b => {
                b.className = 'child-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200 transition';
            });
            btn.className = 'child-filter-btn px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-600 text-white shadow-sm transition';
            applyOrtuFilters();
        }

        function setCategoryFilter(cat, btn) {
            activeCatFilter = cat;
            document.querySelectorAll('.cat-filter-btn').forEach(b => {
                b.className = 'cat-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 transition';
            });
            btn.className = 'cat-filter-btn px-3 py-1 rounded-full text-xs font-medium bg-blue-600 text-white transition';
            applyOrtuFilters();
        }

        function applyOrtuFilters() {
            const q = (document.getElementById('filterSearchOrtu')?.value || '').trim().toLowerCase();
            const onlyUnread = document.getElementById('filterOnlyUnread')?.checked || false;
            const cards = document.querySelectorAll('.ortu-link-card');
            let visible = 0;

            cards.forEach(card => {
                const kids = (card.dataset.children || '').split(',');
                const matchChild = activeChildFilter === 'all' || kids.includes(String(activeChildFilter));
                const matchCat = !activeCatFilter || card.dataset.kategori === activeCatFilter;
                const matchUnread = !onlyUnread || card.dataset.unread === '1';
                const matchQ = !q || card.dataset.search.includes(q);
                const show = matchChild && matchCat && matchUnread && matchQ;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            ['pinnedSection', 'regularSection'].forEach(secId => {
                const sec = document.getElementById(secId);
                if (!sec) return;
                const anyVis = Array.from(sec.querySelectorAll('.ortu-link-card')).some(c => c.style.display !== 'none');
                sec.classList.toggle('hidden', !anyVis);
            });

            const emptyEl = document.getElementById('emptyFilterOrtu');
            if (emptyEl) emptyEl.classList.toggle('hidden', visible > 0);
        }

        function openAndTrackLink(linkId) {
            const link = LINKS_MAP[linkId];
            if (!link) return;
            const catMeta = KATEGORI_META[link.kategori] || KATEGORI_META.lainnya;

            // Catat klik ke server secara asinkron
            const formData = new FormData();
            formData.append('link_id', linkId);
            fetch('ajax_track_link.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const card = document.getElementById('linkCard-' + linkId);
                        if (card && card.dataset.unread === '1') {
                            card.dataset.unread = '0';
                            card.classList.remove('border-blue-300', 'ring-1', 'ring-blue-100');
                            card.classList.add('border-gray-200');
                            const pill = card.querySelector('.status-pill');
                            if (pill) {
                                pill.innerHTML = '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><i class="fas fa-check mr-1"></i>Sudah Dibuka</span>';
                            }
                            currentUnreadCount = Math.max(0, currentUnreadCount - 1);
                            const statEl = document.getElementById('statUnreadCount');
                            if (statEl) statEl.textContent = currentUnreadCount;
                            const headerBadge = document.getElementById('unreadHeaderBadge');
                            if (headerBadge) {
                                if (currentUnreadCount > 0) {
                                    const txt = document.getElementById('unreadHeaderText');
                                    if (txt) txt.textContent = `${currentUnreadCount} Tugas Baru Belum Dibuka`;
                                } else {
                                    headerBadge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700"><i class="fas fa-check-circle"></i> Semua Tugas Sudah Dibuka</span>';
                                }
                            }
                        }
                    }
                })
                .catch(() => {});

            // Tampilkan modal preview & tombol buka tab baru
            document.getElementById('modalIconBox').className = `w-9 h-9 rounded-lg flex items-center justify-center text-white ${catMeta.iconBg}`;
            document.getElementById('modalIconBox').innerHTML = `<i class="${catMeta.icon}"></i>`;
            document.getElementById('modalCatLabel').textContent = catMeta.label;
            document.getElementById('modalBody').innerHTML = `
                <div>
                    <h4 class="text-lg font-bold text-gray-800">${escapeHtml(link.judul)}</h4>
                    <p class="text-sm text-gray-600 mt-2 leading-relaxed">${escapeHtml(link.deskripsi)}</p>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <p class="text-[11px] font-semibold text-gray-500 uppercase mb-1">Alamat Tautan</p>
                    <p class="text-xs text-blue-700 break-all font-mono">${escapeHtml(link.url)}</p>
                </div>
            `;
            document.getElementById('btnCopyModal').onclick = () => copyToClipboard(link.url);
            document.getElementById('btnLaunchUrl').href = link.url;
            document.getElementById('openLinkModal').classList.add('active');
        }

        function closeOpenModal() {
            document.getElementById('openLinkModal').classList.remove('active');
        }
        document.getElementById('openLinkModal').addEventListener('click', e => {
            if (e.target.id === 'openLinkModal') closeOpenModal();
        });

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => showToast('URL berhasil disalin!'));
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast('URL berhasil disalin!');
            }
        }

        function showToast(msg) {
            const el = document.createElement('div');
            el.className = 'px-4 py-2.5 rounded-xl shadow-lg text-xs font-medium bg-emerald-600 text-white flex items-center gap-2';
            el.innerHTML = `<i class="fas fa-check-circle"></i><span>${escapeHtml(msg)}</span>`;
            document.getElementById('toastContainer').appendChild(el);
            setTimeout(() => el.remove(), 2800);
        }

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    </script>
</body>
</html>
