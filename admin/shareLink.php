<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';
require_once '../config/menu.php';
require_once '../includes/menu_functions.php';

// CEK LOGIN & ROLE ADMIN
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);

$TINGKAT_LIST = ['TK', 'SD', 'SMP', 'SMA', 'Alumni', 'Umum'];
$KATEGORI_LIST = [
    'zoom'     => ['label' => 'Zoom / Kelas Online',   'icon' => 'fas fa-video',           'badgeBg' => 'bg-blue-100 text-blue-700',       'iconBg' => 'bg-blue-600'],
    'materi'   => ['label' => 'Materi / Google Drive', 'icon' => 'fas fa-folder-open',     'badgeBg' => 'bg-amber-100 text-amber-800',     'iconBg' => 'bg-amber-500'],
    'whatsapp' => ['label' => 'Grup WhatsApp',         'icon' => 'fab fa-whatsapp',        'badgeBg' => 'bg-emerald-100 text-emerald-700', 'iconBg' => 'bg-emerald-600'],
    'form'     => ['label' => 'Latihan Soal',       'icon' => 'fas fa-clipboard-check', 'badgeBg' => 'bg-purple-100 text-purple-700',   'iconBg' => 'bg-purple-600'],
    'video'    => ['label' => 'Video Pembelajaran',    'icon' => 'fab fa-youtube',         'badgeBg' => 'bg-rose-100 text-rose-700',       'iconBg' => 'bg-rose-600'],
    'lainnya'  => ['label' => 'Lainnya',               'icon' => 'fas fa-link',            'badgeBg' => 'bg-slate-100 text-slate-700',     'iconBg' => 'bg-slate-600'],
];

// ============================================================
// HELPER: AMBIL DETAIL PENERIMA UNTUK SUATU LINK
// ============================================================
function getLinkRecipientsData($conn, $linkId, $targetType) {
    $parentsMap = [];
    $unlinkedStudents = [];

    if ($targetType === 'tingkat') {
        $sql = "SELECT DISTINCT s.id AS siswa_id, s.nama_lengkap, s.kelas, ps.tingkat,
                       o.id AS orangtua_id, o.nama_ortu, o.email, o.no_hp,
                       slc.jumlah_klik, slc.terakhir_dibuka
                FROM shared_link_targets slt
                INNER JOIN pendaftaran_siswa ps ON ps.tingkat = slt.target_tingkat AND ps.status = 'aktif'
                INNER JOIN siswa s ON s.id = ps.siswa_id AND s.status = 'aktif'
                LEFT JOIN siswa_orangtua so ON so.siswa_id = s.id
                LEFT JOIN orangtua o ON o.id = so.orangtua_id
                LEFT JOIN shared_link_clicks slc ON slc.link_id = slt.link_id AND slc.orangtua_id = o.id
                WHERE slt.link_id = ? AND slt.target_tingkat IS NOT NULL
                ORDER BY o.nama_ortu ASC, s.nama_lengkap ASC";
    } else {
        $sql = "SELECT DISTINCT s.id AS siswa_id, s.nama_lengkap, s.kelas,
                       COALESCE(ps.tingkat, '-') AS tingkat,
                       o.id AS orangtua_id, o.nama_ortu, o.email, o.no_hp,
                       slc.jumlah_klik, slc.terakhir_dibuka
                FROM shared_link_targets slt
                INNER JOIN siswa s ON s.id = slt.siswa_id
                LEFT JOIN pendaftaran_siswa ps ON ps.siswa_id = s.id AND ps.status = 'aktif'
                LEFT JOIN siswa_orangtua so ON so.siswa_id = s.id
                LEFT JOIN orangtua o ON o.id = so.orangtua_id
                LEFT JOIN shared_link_clicks slc ON slc.link_id = slt.link_id AND slc.orangtua_id = o.id
                WHERE slt.link_id = ? AND slt.siswa_id IS NOT NULL
                ORDER BY o.nama_ortu ASC, s.nama_lengkap ASC";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $linkId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if (empty($row['orangtua_id'])) {
                $unlinkedStudents[$row['siswa_id']] = [
                    'siswa_id'     => (int) $row['siswa_id'],
                    'nama_lengkap' => $row['nama_lengkap'],
                    'tingkat'      => $row['tingkat'] ?: '-',
                    'kelas'        => $row['kelas'] ?: '-',
                ];
                continue;
            }
            $oid = (int) $row['orangtua_id'];
            if (!isset($parentsMap[$oid])) {
                $parentsMap[$oid] = [
                    'orangtua_id'     => $oid,
                    'nama_ortu'       => $row['nama_ortu'] ?: 'Orang Tua',
                    'email'           => $row['email'] ?: '-',
                    'no_hp'           => $row['no_hp'] ?: '',
                    'jumlah_klik'     => (int) ($row['jumlah_klik'] ?? 0),
                    'terakhir_dibuka' => $row['terakhir_dibuka'] ?: null,
                    'students'        => [],
                ];
            }
            $sid = (int) $row['siswa_id'];
            $parentsMap[$oid]['students'][$sid] = [
                'siswa_id'     => $sid,
                'nama_lengkap' => $row['nama_lengkap'],
                'tingkat'      => $row['tingkat'] ?: '-',
                'kelas'        => $row['kelas'] ?: '-',
            ];
        }
        $stmt->close();
    }

    foreach ($parentsMap as &$p) {
        $p['students'] = array_values($p['students']);
    }
    unset($p);

    return [
        'parents'           => array_values($parentsMap),
        'unlinked_students' => array_values($unlinkedStudents),
    ];
}

// ============================================================
// AJAX ENDPOINT: AMBIL DETAIL PENERIMA LINK
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'recipients' && isset($_GET['link_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $linkId = (int) $_GET['link_id'];

    $stmt = $conn->prepare("SELECT id, judul, url, target_type, kategori, status, berlaku_sampai FROM shared_links WHERE id = ?");
    $stmt->bind_param("i", $linkId);
    $stmt->execute();
    $linkRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linkRow) {
        echo json_encode(['success' => false, 'message' => 'Link tidak ditemukan']);
        exit();
    }

    $recipientsData = getLinkRecipientsData($conn, $linkId, $linkRow['target_type']);
    echo json_encode([
        'success'           => true,
        'link'              => $linkRow,
        'parents'           => $recipientsData['parents'],
        'unlinked_students' => $recipientsData['unlinked_students'],
    ]);
    exit();
}

// ============================================================
// HANDLE POST ACTIONS (CREATE, UPDATE, TOGGLE, DELETE)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create' || $action === 'update') {
        $link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;
        $judul = trim($_POST['judul'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $kategori = $_POST['kategori'] ?? 'lainnya';
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $target_type = $_POST['target_type'] ?? 'tingkat';
        $berlaku_sampai = trim($_POST['berlaku_sampai'] ?? '');
        $status = $_POST['status'] ?? 'aktif';
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;

        $target_tingkat = isset($_POST['target_tingkat']) && is_array($_POST['target_tingkat']) ? $_POST['target_tingkat'] : [];
        $target_siswa_ids = isset($_POST['target_siswa_ids']) && is_array($_POST['target_siswa_ids']) ? $_POST['target_siswa_ids'] : [];

        $errors = [];

        if ($judul === '') {
            $errors[] = 'Judul tautan wajib diisi.';
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            $errors[] = 'URL tautan tidak valid. Pastikan diawali dengan http:// atau https://';
        }
        if (!array_key_exists($kategori, $KATEGORI_LIST)) {
            $kategori = 'lainnya';
        }
        if (!in_array($target_type, ['tingkat', 'specific'], true)) {
            $errors[] = 'Jenis target tidak valid.';
        }
        if (!in_array($status, ['aktif', 'draft'], true)) {
            $status = 'aktif';
        }
        if ($berlaku_sampai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $berlaku_sampai)) {
            $errors[] = 'Format tanggal masa berlaku tidak valid.';
        }

        // Bersihkan nilai target
        $cleanTingkat = [];
        $cleanSiswaIds = [];
        if ($target_type === 'tingkat') {
            foreach ($target_tingkat as $t) {
                if (in_array($t, $TINGKAT_LIST, true)) {
                    $cleanTingkat[] = $t;
                }
            }
            $cleanTingkat = array_values(array_unique($cleanTingkat));
            if (empty($cleanTingkat)) {
                $errors[] = 'Pilih minimal satu tingkat pendidikan untuk target Per Tingkat.';
            }
        } else {
            foreach ($target_siswa_ids as $sid) {
                $idInt = (int) $sid;
                if ($idInt > 0) {
                    $cleanSiswaIds[] = $idInt;
                }
            }
            $cleanSiswaIds = array_values(array_unique($cleanSiswaIds));
            if (empty($cleanSiswaIds)) {
                $errors[] = 'Pilih minimal satu siswa untuk target Siswa Spesifik.';
            }
        }

        if (empty($errors)) {
            $berlakuVal = ($berlaku_sampai !== '') ? $berlaku_sampai : null;
            $conn->begin_transaction();
            try {
                if ($action === 'create') {
                    $sql = "INSERT INTO shared_links (judul, url, kategori, deskripsi, target_type, is_pinned, status, berlaku_sampai, dibuat_oleh)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssissi", $judul, $url, $kategori, $deskripsi, $target_type, $is_pinned, $status, $berlakuVal, $admin_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Gagal menyimpan link: " . $stmt->error);
                    }
                    $link_id = $stmt->insert_id;
                    $stmt->close();
                } else {
                    $sql = "UPDATE shared_links
                            SET judul = ?, url = ?, kategori = ?, deskripsi = ?, target_type = ?, is_pinned = ?, status = ?, berlaku_sampai = ?
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssissi", $judul, $url, $kategori, $deskripsi, $target_type, $is_pinned, $status, $berlakuVal, $link_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Gagal memperbarui link: " . $stmt->error);
                    }
                    $stmt->close();

                    $delStmt = $conn->prepare("DELETE FROM shared_link_targets WHERE link_id = ?");
                    $delStmt->bind_param("i", $link_id);
                    $delStmt->execute();
                    $delStmt->close();
                }

                // Insert target baru
                if ($target_type === 'tingkat') {
                    $insTarget = $conn->prepare("INSERT INTO shared_link_targets (link_id, target_tingkat) VALUES (?, ?)");
                    foreach ($cleanTingkat as $t) {
                        $insTarget->bind_param("is", $link_id, $t);
                        $insTarget->execute();
                    }
                    $insTarget->close();
                } else {
                    $insTarget = $conn->prepare("INSERT INTO shared_link_targets (link_id, siswa_id) VALUES (?, ?)");
                    foreach ($cleanSiswaIds as $sid) {
                        $insTarget->bind_param("ii", $link_id, $sid);
                        $insTarget->execute();
                    }
                    $insTarget->close();
                }

                $conn->commit();
                $_SESSION['flash_message'] = ($action === 'create')
                    ? 'Link berhasil ditambahkan dan dihubungkan ke target!'
                    : 'Link berhasil diperbarui!';
                $_SESSION['flash_message_type'] = 'success';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_message'] = $e->getMessage();
                $_SESSION['flash_message_type'] = 'error';
            }
        } else {
            $_SESSION['flash_message'] = implode(' ', $errors);
            $_SESSION['flash_message_type'] = 'error';
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($action === 'toggle_status' && isset($_POST['link_id'])) {
        $link_id = (int) $_POST['link_id'];
        $stmt = $conn->prepare("UPDATE shared_links SET status = IF(status = 'aktif', 'draft', 'aktif') WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = 'Status publikasi link berhasil diubah.';
            $_SESSION['flash_message_type'] = 'success';
        }
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    if ($action === 'delete' && isset($_POST['link_id'])) {
        $link_id = (int) $_POST['link_id'];
        $stmt = $conn->prepare("DELETE FROM shared_links WHERE id = ?");
        $stmt->bind_param("i", $link_id);
        if ($stmt->execute()) {
            $_SESSION['flash_message'] = 'Link berhasil dihapus.';
            $_SESSION['flash_message_type'] = 'success';
        }
        $stmt->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// AMBIL FLASH MESSAGE
$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_message_type'] ?? 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);

// ============================================================
// AMBIL DATA SISWA AKTIF + ORANG TUA (UNTUK MODAL PICKER & PREVIEW)
// ============================================================
$studentsList = [];
$sqlStudents = "SELECT s.id, s.nama_lengkap, s.kelas,
                       COALESCE(MAX(ps.tingkat), '-') AS tingkat,
                       COALESCE(MAX(ps.jenis_kelas), '-') AS jenis_kelas,
                       GROUP_CONCAT(DISTINCT o.id ORDER BY o.id SEPARATOR ',') AS orangtua_ids,
                       GROUP_CONCAT(DISTINCT o.nama_ortu ORDER BY o.id SEPARATOR '||') AS orangtua_names
                FROM siswa s
                LEFT JOIN pendaftaran_siswa ps ON ps.siswa_id = s.id AND ps.status = 'aktif'
                LEFT JOIN siswa_orangtua so ON so.siswa_id = s.id
                LEFT JOIN orangtua o ON o.id = so.orangtua_id
                WHERE s.status = 'aktif'
                GROUP BY s.id, s.nama_lengkap, s.kelas
                ORDER BY s.nama_lengkap ASC";
$resStudents = $conn->query($sqlStudents);
if ($resStudents) {
    while ($row = $resStudents->fetch_assoc()) {
        $ortuIds = !empty($row['orangtua_ids']) ? array_map('intval', explode(',', $row['orangtua_ids'])) : [];
        $ortuNames = !empty($row['orangtua_names']) ? explode('||', $row['orangtua_names']) : [];
        $parents = [];
        for ($i = 0; $i < count($ortuIds); $i++) {
            $parents[] = [
                'id'   => $ortuIds[$i],
                'nama' => $ortuNames[$i] ?? 'Orang Tua',
            ];
        }
        $studentsList[] = [
            'id'          => (int) $row['id'],
            'nama'        => $row['nama_lengkap'],
            'kelas'       => $row['kelas'],
            'tingkat'     => $row['tingkat'],
            'jenis_kelas' => $row['jenis_kelas'],
            'parents'     => $parents,
        ];
    }
}

// Hitung jumlah siswa aktif per tingkat untuk label di checkbox
$tingkatCounts = array_fill_keys($TINGKAT_LIST, 0);
foreach ($studentsList as $st) {
    if (isset($tingkatCounts[$st['tingkat']])) {
        $tingkatCounts[$st['tingkat']]++;
    }
}

// ============================================================
// AMBIL SEMUA SHARED LINKS + TARGET + STATISTIK
// ============================================================
$links = [];
$sqlLinks = "SELECT sl.*, u.full_name AS pembuat_nama
             FROM shared_links sl
             LEFT JOIN users u ON u.id = sl.dibuat_oleh
             ORDER BY sl.is_pinned DESC, sl.created_at DESC";
$resLinks = $conn->query($sqlLinks);

$todayDate = date('Y-m-d');
$statAktif = 0;
$statDraftExpired = 0;
$statTotalClicks = 0;
$uniqueActiveParentIds = [];

if ($resLinks) {
    while ($row = $resLinks->fetch_assoc()) {
        $lid = (int) $row['id'];
        $row['id'] = $lid;
        $row['is_pinned'] = (int) $row['is_pinned'];

        // Status efektif
        $isExpired = (!empty($row['berlaku_sampai']) && $row['berlaku_sampai'] < $todayDate);
        if ($row['status'] === 'draft') {
            $row['effective_status'] = 'draft';
        } elseif ($isExpired) {
            $row['effective_status'] = 'expired';
        } else {
            $row['effective_status'] = 'aktif';
        }

        // Ambil targets
        $row['target_tingkat'] = [];
        $row['target_siswa_ids'] = [];
        $row['target_siswa_names'] = [];

        $tStmt = $conn->prepare("SELECT slt.target_tingkat, slt.siswa_id, s.nama_lengkap
                                 FROM shared_link_targets slt
                                 LEFT JOIN siswa s ON s.id = slt.siswa_id
                                 WHERE slt.link_id = ?");
        $tStmt->bind_param("i", $lid);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        while ($tRow = $tRes->fetch_assoc()) {
            if (!empty($tRow['target_tingkat'])) {
                $row['target_tingkat'][] = $tRow['target_tingkat'];
            }
            if (!empty($tRow['siswa_id'])) {
                $row['target_siswa_ids'][] = (int) $tRow['siswa_id'];
                if (!empty($tRow['nama_lengkap'])) {
                    $row['target_siswa_names'][] = $tRow['nama_lengkap'];
                }
            }
        }
        $tStmt->close();

        // Hitung penerima terhubung & sudah dibuka
        $recData = getLinkRecipientsData($conn, $lid, $row['target_type']);
        $row['total_parents'] = count($recData['parents']);
        $row['unlinked_count'] = count($recData['unlinked_students']);
        $opened = 0;
        $clicksSum = 0;
        foreach ($recData['parents'] as $p) {
            if ($p['jumlah_klik'] > 0) {
                $opened++;
                $clicksSum += $p['jumlah_klik'];
            }
            if ($row['effective_status'] === 'aktif') {
                $uniqueActiveParentIds[$p['orangtua_id']] = true;
            }
        }
        $row['opened_parents'] = $opened;
        $statTotalClicks += $clicksSum;

        if ($row['effective_status'] === 'aktif') {
            $statAktif++;
        } else {
            $statDraftExpired++;
        }

        $links[] = $row;
    }
}

// Total seluruh akun orangtua di sistem
$totalAllParents = 0;
$resOrtuCount = $conn->query("SELECT COUNT(*) AS cnt FROM orangtua");
if ($resOrtuCount && $r = $resOrtuCount->fetch_assoc()) {
    $totalAllParents = (int) $r['cnt'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include '../config/analytics.php'; ?>
    <title>Manajemen Latihan Soal - Admin Bimbel Esc</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .stat-card { transition: transform 0.25s ease, box-shadow 0.25s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08); }

        /* Dropdown sidebar styles */
        .dropdown-submenu { display: none; max-height: 500px; overflow: hidden; transition: max-height 0.3s ease; }
        .dropdown-submenu[style*="display: block"] { display: block; }
        .dropdown-toggle.open .arrow { transform: rotate(-90deg); }
        .menu-item.active { background-color: rgba(255, 255, 255, 0.12); border-left: 4px solid #60A5FA; }

        /* Mobile menu styles */
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

        /* Modal styles */
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
            <p class="text-sm text-blue-200">Admin Dashboard</p>
        </div>
        <div class="p-4 bg-blue-900">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-white text-blue-800 rounded-full flex items-center justify-center shrink-0">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="ml-3 min-w-0">
                    <p class="font-medium truncate"><?php echo htmlspecialchars($full_name); ?></p>
                    <p class="text-sm text-blue-300">Administrator</p>
                </div>
            </div>
        </div>
        <nav class="mt-4 pb-6">
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
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="ml-3">
                        <p class="font-medium"><?php echo htmlspecialchars($full_name); ?></p>
                        <p class="text-sm text-blue-300">Administrator</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto pb-6">
                <?php echo renderMenu($currentPage, 'admin'); ?>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="md:ml-64 min-h-screen">
        <div class="mobile-header md:hidden" style="height: 64px;"></div>

        <!-- Page Header -->
        <div class="bg-white shadow-sm border-b border-gray-200 px-4 md:px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl md:text-2xl font-bold text-gray-800">Manajemen Latihan Soal</h1>
                    <p class="text-sm text-gray-600 mt-0.5">
                        Bagikan tautan kelas online, materi, dan tugas soal langsung ke akun orang tua berdasarkan tingkat atau siswa spesifik.                    </p>
                </div>
                <button onclick="openLinkModal()"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow-sm transition flex items-center justify-center gap-2 text-sm">
                    <i class="fas fa-plus-circle"></i>
                    <span>Tambah &amp; Bagikan Link</span>
                </button>
            </div>
        </div>

        <div class="p-4 md:p-6 max-w-7xl mx-auto space-y-6">
            <!-- Flash Message -->
            <?php if ($flash_message !== ''): ?>
                <div class="px-4 py-3 rounded-xl border flex items-center justify-between <?php echo $flash_type === 'error' ? 'bg-red-50 border-red-300 text-red-700' : 'bg-emerald-50 border-emerald-300 text-emerald-700'; ?>">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <i class="fas <?php echo $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                        <span><?php echo htmlspecialchars($flash_message); ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-current opacity-60 hover:opacity-100">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4">
                <div class="stat-card bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500">Link Aktif</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $statAktif; ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">dari <?php echo count($links); ?> total link</p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg shrink-0">
                        <i class="fas fa-list-check"></i>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500">Akun Ortu Terhubung</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo count($uniqueActiveParentIds); ?> <span class="text-sm font-normal text-gray-400">/ <?php echo $totalAllParents; ?></span></p>
                        <p class="text-[11px] text-gray-400 mt-0.5"><?php echo count($studentsList); ?> siswa aktif terdaftar</p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shrink-0">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500">Total Klik Orang Tua</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $statTotalClicks; ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Tercatat otomatis</p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center text-lg shrink-0">
                        <i class="fas fa-mouse-pointer"></i>
                    </div>
                </div>
                <div class="stat-card bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-gray-500">Draft / Kadaluarsa</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $statDraftExpired; ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Tidak tampil di sisi ortu</p>
                    </div>
                    <div class="w-11 h-11 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-lg shrink-0">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>

            <!-- Filter & Search -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                    <div class="md:col-span-5 relative">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="filterSearch" oninput="applyClientFilters()"
                            placeholder="Cari judul link, URL, atau deskripsi..."
                            class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div class="md:col-span-3">
                        <select id="filterKategori" onchange="applyClientFilters()"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($KATEGORI_LIST as $kKey => $kMeta): ?>
                                <option value="<?php echo $kKey; ?>"><?php echo htmlspecialchars($kMeta['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <select id="filterTarget" onchange="applyClientFilters()"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Target</option>
                            <option value="tingkat">Per Tingkat</option>
                            <option value="specific">Siswa Spesifik</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <select id="filterStatus" onchange="applyClientFilters()"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Status</option>
                            <option value="aktif">Aktif</option>
                            <option value="draft">Draft</option>
                            <option value="expired">Kadaluarsa</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Links List -->
            <div id="linksListContainer" class="space-y-4">
                <?php if (empty($links)): ?>
                    <div class="bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                        <i class="fas fa-list-check text-4xl text-gray-300 mb-3"></i>
                        <p class="font-semibold text-gray-600">Belum ada tautan yang dibagikan</p>
                        <p class="text-xs text-gray-400 mt-1">Klik tombol "Tambah &amp; Bagikan Link" di atas untuk mulai membagikan tautan ke akun orang tua.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($links as $link): ?>
                        <?php
                            $catMeta = $KATEGORI_LIST[$link['kategori']] ?? $KATEGORI_LIST['lainnya'];
                            $effStatus = $link['effective_status'];
                            $pct = $link['total_parents'] > 0 ? round(($link['opened_parents'] / $link['total_parents']) * 100) : 0;

                            // Label target
                            if ($link['target_type'] === 'tingkat') {
                                $isAllTingkat = count($link['target_tingkat']) >= count($TINGKAT_LIST);
                                $targetText = $isAllTingkat ? 'Semua Tingkat' : ('Tingkat: ' . implode(', ', $link['target_tingkat']));
                                $targetBadgeClass = 'bg-indigo-50 text-indigo-700 border-indigo-200';
                                $targetIcon = 'fas fa-graduation-cap';
                            } else {
                                $names = $link['target_siswa_names'];
                                $summary = count($names) > 2
                                    ? implode(', ', array_slice($names, 0, 2)) . ' +' . (count($names) - 2)
                                    : (implode(', ', $names) ?: '-');
                                $targetText = 'Spesifik: ' . $summary;
                                $targetBadgeClass = 'bg-amber-50 text-amber-800 border-amber-200';
                                $targetIcon = 'fas fa-user-check';
                            }

                            $searchHaystack = strtolower($link['judul'] . ' ' . $link['url'] . ' ' . ($link['deskripsi'] ?? '') . ' ' . $targetText);
                        ?>
                        <div class="link-card bg-white rounded-xl shadow-sm border <?php echo $link['is_pinned'] ? 'border-amber-300 ring-1 ring-amber-100' : 'border-gray-200'; ?> p-4 md:p-5 hover:shadow-md transition"
                             data-search="<?php echo htmlspecialchars($searchHaystack); ?>"
                             data-kategori="<?php echo htmlspecialchars($link['kategori']); ?>"
                             data-target="<?php echo htmlspecialchars($link['target_type']); ?>"
                             data-status="<?php echo htmlspecialchars($effStatus); ?>">
                            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                <!-- Left Info -->
                                <div class="flex items-start gap-3.5 flex-1 min-w-0">
                                    <div class="w-11 h-11 rounded-xl <?php echo $catMeta['iconBg']; ?> text-white flex items-center justify-center text-lg shrink-0 shadow-sm">
                                        <i class="<?php echo $catMeta['icon']; ?>"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2 mb-1">
                                            <?php if ($link['is_pinned']): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-amber-100 text-amber-800">
                                                    <i class="fas fa-thumbtack mr-1"></i>Penting
                                                </span>
                                            <?php endif; ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold <?php echo $catMeta['badgeBg']; ?>">
                                                <?php echo htmlspecialchars($catMeta['label']); ?>
                                            </span>
                                            <?php if ($effStatus === 'aktif'): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Aktif
                                                </span>
                                            <?php elseif ($effStatus === 'draft'): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>Draft
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>Kadaluarsa
                                                </span>
                                            <?php endif; ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?php echo $targetBadgeClass; ?>">
                                                <i class="<?php echo $targetIcon; ?> mr-1.5 text-[10px]"></i><?php echo htmlspecialchars($targetText); ?>
                                            </span>
                                        </div>

                                        <h3 class="text-base font-bold text-gray-800"><?php echo htmlspecialchars($link['judul']); ?></h3>
                                        <?php if (!empty($link['deskripsi'])): ?>
                                            <p class="text-sm text-gray-600 mt-1 truncate-2"><?php echo htmlspecialchars($link['deskripsi']); ?></p>
                                        <?php endif; ?>

                                        <!-- URL Box -->
                                        <div class="mt-2.5 inline-flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5 max-w-full">
                                            <i class="fas fa-globe text-gray-400 text-xs shrink-0"></i>
                                            <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener noreferrer"
                                               class="text-xs text-blue-600 hover:underline truncate max-w-[220px] sm:max-w-md font-medium">
                                                <?php echo htmlspecialchars($link['url']); ?>
                                            </a>
                                            <button type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($link['url'], ENT_QUOTES); ?>')" title="Salin URL"
                                                    class="text-gray-400 hover:text-blue-600 transition shrink-0">
                                                <i class="fas fa-copy text-xs"></i>
                                            </button>
                                        </div>

                                        <div class="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                            <span><i class="far fa-calendar-plus mr-1"></i>Dibuat: <?php echo date('d M Y H:i', strtotime($link['created_at'])); ?></span>
                                            <span><i class="far fa-hourglass mr-1"></i>Berlaku: <?php echo !empty($link['berlaku_sampai']) ? date('d M Y', strtotime($link['berlaku_sampai'])) : 'Tanpa batas waktu'; ?></span>
                                            <?php if ($link['unlinked_count'] > 0): ?>
                                                <span class="text-amber-600 font-medium"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo $link['unlinked_count']; ?> siswa belum punya akun ortu</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Tracking & Actions -->
                                <div class="lg:w-64 shrink-0 flex flex-col justify-between gap-3 border-t lg:border-t-0 lg:border-l border-gray-100 pt-3 lg:pt-0 lg:pl-4">
                                    <div>
                                        <div class="flex items-center justify-between text-xs mb-1">
                                            <span class="font-semibold text-gray-700">Status Akses Ortu</span>
                                            <span class="font-bold <?php echo ($link['opened_parents'] === $link['total_parents'] && $link['total_parents'] > 0) ? 'text-emerald-600' : 'text-blue-600'; ?>">
                                                <?php echo $link['opened_parents']; ?> / <?php echo $link['total_parents']; ?> dibuka
                                            </span>
                                        </div>
                                        <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                                            <div class="h-full <?php echo ($link['opened_parents'] === $link['total_parents'] && $link['total_parents'] > 0) ? 'bg-emerald-500' : 'bg-blue-500'; ?> rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                        <button type="button" onclick="openRecipientsModal(<?php echo $link['id']; ?>)"
                                            class="mt-2 w-full text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg py-1.5 transition flex items-center justify-center gap-1.5">
                                            <i class="fas fa-eye"></i> Lihat <?php echo $link['total_parents']; ?> Akun Terhubung
                                        </button>
                                    </div>

                                    <div class="flex items-center gap-1.5 justify-end">
                                        <form method="POST" class="flex-1">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                            <button type="submit"
                                                class="w-full px-2.5 py-1.5 text-xs font-medium rounded-lg border transition <?php echo $link['status'] === 'aktif' ? 'border-amber-200 text-amber-700 bg-amber-50 hover:bg-amber-100' : 'border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100'; ?>">
                                                <i class="fas <?php echo $link['status'] === 'aktif' ? 'fa-pause' : 'fa-play'; ?> mr-1"></i>
                                                <?php echo $link['status'] === 'aktif' ? 'Draft' : 'Aktifkan'; ?>
                                            </button>
                                        </form>
                                        <button type="button" onclick="openLinkModal(<?php echo $link['id']; ?>)" title="Edit Link"
                                            class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-100 transition">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus link ini?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="link_id" value="<?php echo $link['id']; ?>">
                                            <button type="submit" title="Hapus Link"
                                                class="px-2.5 py-1.5 text-xs font-medium rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="emptyFilterState" class="hidden bg-white rounded-xl border border-dashed border-gray-300 p-10 text-center">
                <i class="fas fa-search text-4xl text-gray-300 mb-3"></i>
                <p class="font-semibold text-gray-600">Tidak ada tautan yang cocok dengan filter</p>
                <p class="text-xs text-gray-400 mt-1">Coba ubah kata kunci atau filter pencarian di atas.</p>
            </div>
        </div>
    </div>

    <!-- ==================== MODAL TAMBAH / EDIT LINK ==================== -->
    <div id="linkFormModal" class="modal-backdrop">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                <div>
                    <h3 id="modalTitle" class="text-lg font-bold text-gray-800">Tambah &amp; Bagikan Link Baru</h3>
                    <p class="text-xs text-gray-500">Link aktif akan langsung tampil di halaman Link Bimbel akun orang tua yang sesuai target.</p>
                </div>
                <button type="button" onclick="closeModal('linkFormModal')" class="text-gray-400 hover:text-gray-600 p-1">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="linkForm" method="POST" onsubmit="return validateLinkForm()" class="overflow-y-auto p-6 space-y-5 flex-1">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="link_id" id="formLinkId" value="0">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Judul Tautan <span class="text-red-500">*</span></label>
                        <input type="text" name="judul" id="inputJudul" required
                            placeholder="Contoh: Link Zoom Kelas Tambahan Matematika SMP"
                            class="w-full px-3.5 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Kategori <span class="text-red-500">*</span></label>
                        <select name="kategori" id="inputKategori"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($KATEGORI_LIST as $kKey => $kMeta): ?>
                                <option value="<?php echo $kKey; ?>"><?php echo htmlspecialchars($kMeta['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">URL / Alamat Link <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <i class="fas fa-globe absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="url" name="url" id="inputUrl" required oninput="autoDetectCategory(this.value)"
                            placeholder="https://zoom.us/j/... atau https://drive.google.com/..."
                            class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <p id="autoDetectHint" class="text-[11px] text-blue-600 mt-1 hidden"></p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Deskripsi / Catatan untuk Orang Tua</label>
                    <textarea name="deskripsi" id="inputDeskripsi" rows="2"
                        placeholder="Tambahkan keterangan singkat, misalnya jadwal pelaksanaan, passcode Zoom, atau instruksi..."
                        class="w-full px-3.5 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>

                <!-- Target Penerima: 2 Opsi (Per Tingkat & Siswa Spesifik) -->
                <div class="border border-blue-100 bg-blue-50/40 rounded-xl p-4 space-y-3">
                    <label class="block text-sm font-bold text-gray-800">
                        <i class="fas fa-bullseye text-blue-600 mr-1.5"></i>Target Akun Siswa / Orang Tua Terdaftar <span class="text-red-500">*</span>
                    </label>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="cursor-pointer">
                            <input type="radio" name="target_type" value="tingkat" onchange="onTargetTypeChange()" class="peer sr-only" checked>
                            <div class="p-3 rounded-lg border border-gray-200 bg-white peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 text-center transition">
                                <i class="fas fa-graduation-cap text-lg mb-1 block"></i>
                                <span class="text-xs font-bold block">Per Tingkat</span>
                                <span class="text-[11px] text-gray-500 block mt-0.5">TK, SD, SMP, SMA, dll.</span>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="target_type" value="specific" onchange="onTargetTypeChange()" class="peer sr-only">
                            <div class="p-3 rounded-lg border border-gray-200 bg-white peer-checked:border-blue-600 peer-checked:bg-blue-50 peer-checked:text-blue-700 text-center transition">
                                <i class="fas fa-user-check text-lg mb-1 block"></i>
                                <span class="text-xs font-bold block">Siswa Spesifik</span>
                                <span class="text-[11px] text-gray-500 block mt-0.5">Pilih 1 atau beberapa siswa</span>
                            </div>
                        </label>
                    </div>

                    <!-- Panel Per Tingkat -->
                    <div id="panelTingkat" class="pt-1 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-600">Pilih tingkat pendidikan siswa aktif:</span>
                            <button type="button" onclick="toggleSelectAllTingkat()" id="btnSelectAllTingkat"
                                class="text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline">
                                Pilih Semua Tingkat
                            </button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($TINGKAT_LIST as $t): ?>
                                <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-xs font-medium cursor-pointer hover:border-blue-400">
                                    <input type="checkbox" name="target_tingkat[]" value="<?php echo $t; ?>" onchange="updateRecipientPreview()" class="tingkat-cb rounded text-blue-600">
                                    <span><?php echo $t; ?></span>
                                    <span class="text-[10px] text-gray-400">(<?php echo $tingkatCounts[$t]; ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Panel Siswa Spesifik -->
                    <div id="panelSpecific" class="hidden pt-1 space-y-2">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <div class="sm:col-span-2 relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                <input type="text" id="studentPickerSearch" oninput="renderStudentPicker()"
                                    placeholder="Cari nama siswa atau nama orang tua..."
                                    class="w-full pl-8 pr-3 py-1.5 text-xs bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            </div>
                            <select id="studentPickerTingkat" onchange="renderStudentPicker()"
                                class="px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Semua Tingkat</option>
                                <?php foreach ($TINGKAT_LIST as $t): ?>
                                    <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="studentPickerList" class="max-h-44 overflow-y-auto bg-white border border-gray-200 rounded-lg divide-y divide-gray-100"></div>
                        <!-- Hidden inputs container for selected student IDs -->
                        <div id="selectedStudentsInputs"></div>
                    </div>

                    <!-- Preview Akun Terhubung -->
                    <div class="bg-white rounded-lg border border-blue-200 p-3">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-gray-700">
                                <i class="fas fa-paper-plane text-blue-500 mr-1"></i>Preview Akun Orang Tua Terhubung:
                            </span>
                            <span id="recipientPreviewCount" class="text-xs font-bold text-blue-700">0 Akun Orang Tua</span>
                        </div>
                        <div id="recipientPreviewChips" class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto"></div>
                    </div>
                </div>

                <!-- Masa Berlaku, Status & Pin -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Berlaku Hingga <span class="text-xs font-normal text-gray-400">(Opsional)</span></label>
                        <input type="date" name="berlaku_sampai" id="inputExpiry"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Status Publikasi</label>
                        <select name="status" id="inputStatus"
                            class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500">
                            <option value="aktif">Aktif (Langsung Tampil)</option>
                            <option value="draft">Draft (Simpan Dulu)</option>
                        </select>
                    </div>
                    <div class="flex items-end pb-1.5">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="is_pinned" id="inputPinned" value="1" class="w-4 h-4 rounded text-blue-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-gray-700">
                                <i class="fas fa-thumbtack text-amber-500 mr-1"></i>Sematkan (Penting)
                            </span>
                        </label>
                    </div>
                </div>
            </form>

            <div class="px-6 py-3.5 border-t border-gray-200 bg-gray-50 flex justify-end gap-2">
                <button type="button" onclick="closeModal('linkFormModal')"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100">
                    Batal
                </button>
                <button type="submit" form="linkForm"
                    class="px-5 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm flex items-center gap-2">
                    <i class="fas fa-save"></i>
                    <span>Simpan &amp; Bagikan</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ==================== MODAL DETAIL PENERIMA ==================== -->
    <div id="recipientsModal" class="modal-backdrop">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gray-50">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Detail Akun Terhubung &amp; Status Akses</h3>
                    <p id="recipientsModalSubtitle" class="text-xs text-gray-500 mt-0.5">Memuat data...</p>
                </div>
                <button type="button" onclick="closeModal('recipientsModal')" class="text-gray-400 hover:text-gray-600 p-1">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="recipientsModalBody" class="p-6 overflow-y-auto flex-1 space-y-3"></div>
            <div class="px-6 py-3 border-t border-gray-200 bg-gray-50 flex justify-end">
                <button type="button" onclick="closeModal('recipientsModal')"
                    class="px-4 py-2 text-sm font-medium bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg">
                    Tutup
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toastContainer" class="fixed bottom-5 right-5 z-[1300] flex flex-col gap-2 max-w-sm"></div>

    <script>
        const STUDENTS_DATA = <?php echo json_encode($studentsList, JSON_UNESCAPED_UNICODE); ?>;
        const LINKS_DATA = <?php echo json_encode($links, JSON_UNESCAPED_UNICODE); ?>;
        const KATEGORI_LABELS = <?php echo json_encode(array_map(fn($v) => $v['label'], $KATEGORI_LIST), JSON_UNESCAPED_UNICODE); ?>;

        let selectedStudentIds = new Set();

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

        // Client-side Filter
        function applyClientFilters() {
            const q = (document.getElementById('filterSearch').value || '').trim().toLowerCase();
            const kat = document.getElementById('filterKategori').value;
            const tgt = document.getElementById('filterTarget').value;
            const st = document.getElementById('filterStatus').value;

            const cards = document.querySelectorAll('.link-card');
            let visibleCount = 0;
            cards.forEach(card => {
                const matchQ = !q || card.dataset.search.includes(q);
                const matchK = !kat || card.dataset.kategori === kat;
                const matchT = !tgt || card.dataset.target === tgt;
                const matchS = !st || card.dataset.status === st;
                const show = matchQ && matchK && matchT && matchS;
                card.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            const emptyEl = document.getElementById('emptyFilterState');
            if (cards.length > 0) {
                emptyEl.classList.toggle('hidden', visibleCount > 0);
            }
        }

        // Modal Helpers
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        document.querySelectorAll('.modal-backdrop').forEach(b => {
            b.addEventListener('click', e => { if (e.target === b) b.classList.remove('active'); });
        });

        function autoDetectCategory(urlVal) {
            const u = (urlVal || '').toLowerCase();
            const hint = document.getElementById('autoDetectHint');
            const select = document.getElementById('inputKategori');
            let detected = null;
            if (u.includes('zoom.us') || u.includes('meet.google.com')) detected = 'zoom';
            else if (u.includes('drive.google.com') || u.includes('docs.google.com')) detected = 'materi';
            else if (u.includes('chat.whatsapp.com') || u.includes('wa.me')) detected = 'whatsapp';
            else if (u.includes('forms.gle') || u.includes('docs.google.com/forms') || u.includes('quizizz')) detected = 'form';
            else if (u.includes('youtube.com') || u.includes('youtu.be')) detected = 'video';

            if (detected) {
                select.value = detected;
                hint.textContent = 'Kategori terdeteksi otomatis: ' + (KATEGORI_LABELS[detected] || detected);
                hint.classList.remove('hidden');
            } else {
                hint.classList.add('hidden');
            }
        }

        function onTargetTypeChange() {
            const val = document.querySelector('input[name="target_type"]:checked').value;
            document.getElementById('panelTingkat').classList.toggle('hidden', val !== 'tingkat');
            document.getElementById('panelSpecific').classList.toggle('hidden', val !== 'specific');
            if (val === 'specific') renderStudentPicker();
            updateRecipientPreview();
        }

        function toggleSelectAllTingkat() {
            const cbs = Array.from(document.querySelectorAll('.tingkat-cb'));
            const allChecked = cbs.every(cb => cb.checked);
            cbs.forEach(cb => cb.checked = !allChecked);
            document.getElementById('btnSelectAllTingkat').textContent = allChecked ? 'Pilih Semua Tingkat' : 'Batal Pilih Semua';
            updateRecipientPreview();
        }

        function renderStudentPicker() {
            const q = (document.getElementById('studentPickerSearch').value || '').trim().toLowerCase();
            const tFilter = document.getElementById('studentPickerTingkat').value;
            const listEl = document.getElementById('studentPickerList');

            const filtered = STUDENTS_DATA.filter(s => {
                if (tFilter && s.tingkat !== tFilter) return false;
                if (q) {
                    const pNames = s.parents.map(p => p.nama).join(' ');
                    const hay = `${s.nama} ${s.kelas} ${s.tingkat} ${pNames}`.toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            if (filtered.length === 0) {
                listEl.innerHTML = '<div class="p-3 text-xs text-gray-400 text-center">Siswa tidak ditemukan</div>';
                return;
            }

            listEl.innerHTML = filtered.map(s => {
                const checked = selectedStudentIds.has(s.id);
                const ortuLabel = s.parents.length > 0
                    ? s.parents.map(p => escapeHtml(p.nama)).join(', ')
                    : '<span class="text-amber-600 italic">Belum ada akun ortu</span>';
                return `
                    <label class="flex items-center justify-between px-3 py-2 hover:bg-blue-50/50 cursor-pointer text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <input type="checkbox" ${checked ? 'checked' : ''} onchange="toggleStudent(${s.id}, this.checked)" class="rounded text-blue-600 shrink-0">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-800 truncate">${escapeHtml(s.nama)} <span class="font-normal text-gray-500">(${escapeHtml(s.tingkat)} - ${escapeHtml(s.kelas)})</span></p>
                                <p class="text-[11px] text-gray-500 truncate"><i class="fas fa-user mr-1"></i>Ortu: ${ortuLabel}</p>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-600 text-[10px] font-medium shrink-0 ml-2">${escapeHtml(s.jenis_kelas)}</span>
                    </label>
                `;
            }).join('');
        }

        function toggleStudent(id, isChecked) {
            if (isChecked) selectedStudentIds.add(id);
            else selectedStudentIds.delete(id);
            syncSelectedStudentInputs();
            updateRecipientPreview();
        }

        function syncSelectedStudentInputs() {
            const wrap = document.getElementById('selectedStudentsInputs');
            wrap.innerHTML = Array.from(selectedStudentIds)
                .map(id => `<input type="hidden" name="target_siswa_ids[]" value="${id}">`)
                .join('');
        }

        function updateRecipientPreview() {
            const type = document.querySelector('input[name="target_type"]:checked').value;
            let matchedStudents = [];
            if (type === 'tingkat') {
                const selectedTingkats = Array.from(document.querySelectorAll('.tingkat-cb:checked')).map(cb => cb.value);
                matchedStudents = STUDENTS_DATA.filter(s => selectedTingkats.includes(s.tingkat));

                const allCbs = Array.from(document.querySelectorAll('.tingkat-cb'));
                document.getElementById('btnSelectAllTingkat').textContent =
                    (allCbs.length > 0 && allCbs.every(cb => cb.checked)) ? 'Batal Pilih Semua' : 'Pilih Semua Tingkat';
            } else {
                matchedStudents = STUDENTS_DATA.filter(s => selectedStudentIds.has(s.id));
            }

            const parentMap = new Map();
            let unlinked = 0;
            matchedStudents.forEach(s => {
                if (s.parents.length === 0) {
                    unlinked++;
                    return;
                }
                s.parents.forEach(p => {
                    if (!parentMap.has(p.id)) parentMap.set(p.id, { nama: p.nama, kids: [] });
                    parentMap.get(p.id).kids.push(s.nama.split(' ')[0]);
                });
            });

            const countEl = document.getElementById('recipientPreviewCount');
            const chipsEl = document.getElementById('recipientPreviewChips');
            const parentsArr = Array.from(parentMap.values());

            countEl.textContent = `${parentsArr.length} Akun Orang Tua (${matchedStudents.length} Siswa)`;
            if (parentsArr.length === 0) {
                chipsEl.innerHTML = `<span class="text-xs text-rose-500 italic">Belum ada akun orang tua terhubung — pilih tingkat atau siswa di atas.</span>`;
                return;
            }

            const maxChips = 18;
            let html = parentsArr.slice(0, maxChips).map(p => `
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-blue-50 border border-blue-200 text-[11px] text-blue-800">
                    <i class="fas fa-user-circle text-blue-500"></i>
                    <strong>${escapeHtml(p.nama)}</strong>
                    <span class="text-blue-600">(${escapeHtml(p.kids.join(', '))})</span>
                </span>
            `).join('');
            if (parentsArr.length > maxChips) {
                html += `<span class="px-2 py-0.5 rounded-md bg-gray-100 text-[11px] text-gray-600 font-semibold">+${parentsArr.length - maxChips} akun lainnya</span>`;
            }
            if (unlinked > 0) {
                html += `<span class="w-full text-[11px] text-amber-600 mt-1"><i class="fas fa-info-circle mr-1"></i>${unlinked} siswa terpilih belum memiliki akun orang tua terhubung.</span>`;
            }
            chipsEl.innerHTML = html;
        }

        function openLinkModal(editId = null) {
            document.getElementById('linkForm').reset();
            selectedStudentIds = new Set();
            document.getElementById('autoDetectHint').classList.add('hidden');
            document.getElementById('studentPickerSearch').value = '';
            document.getElementById('studentPickerTingkat').value = '';

            if (editId) {
                const link = LINKS_DATA.find(l => l.id === editId);
                if (!link) return;
                document.getElementById('modalTitle').textContent = 'Edit Share Link';
                document.getElementById('formAction').value = 'update';
                document.getElementById('formLinkId').value = link.id;
                document.getElementById('inputJudul').value = link.judul;
                document.getElementById('inputKategori').value = link.kategori;
                document.getElementById('inputUrl').value = link.url;
                document.getElementById('inputDeskripsi').value = link.deskripsi || '';
                document.getElementById('inputExpiry').value = link.berlaku_sampai || '';
                document.getElementById('inputStatus').value = link.status;
                document.getElementById('inputPinned').checked = !!link.is_pinned;

                document.querySelector(`input[name="target_type"][value="${link.target_type}"]`).checked = true;
                document.querySelectorAll('.tingkat-cb').forEach(cb => {
                    cb.checked = (link.target_tingkat || []).includes(cb.value);
                });
                (link.target_siswa_ids || []).forEach(id => selectedStudentIds.add(Number(id)));
            } else {
                document.getElementById('modalTitle').textContent = 'Tambah & Bagikan Link Baru';
                document.getElementById('formAction').value = 'create';
                document.getElementById('formLinkId').value = '0';
                document.querySelector('input[name="target_type"][value="tingkat"]').checked = true;
            }

            syncSelectedStudentInputs();
            onTargetTypeChange();
            openModal('linkFormModal');
        }

        function validateLinkForm() {
            const type = document.querySelector('input[name="target_type"]:checked').value;
            if (type === 'tingkat') {
                const checked = document.querySelectorAll('.tingkat-cb:checked').length;
                if (checked === 0) {
                    showToast('Pilih minimal satu tingkat pendidikan!', 'error');
                    return false;
                }
            } else {
                if (selectedStudentIds.size === 0) {
                    showToast('Pilih minimal satu siswa terdaftar!', 'error');
                    return false;
                }
            }
            return true;
        }

        async function openRecipientsModal(linkId) {
            document.getElementById('recipientsModalSubtitle').textContent = 'Memuat data penerima...';
            document.getElementById('recipientsModalBody').innerHTML = `
                <div class="py-10 text-center text-gray-500 text-sm">
                    <i class="fas fa-spinner fa-spin text-2xl text-blue-600 mb-2"></i>
                    <p>Mengambil data akun orang tua terhubung...</p>
                </div>
            `;
            openModal('recipientsModal');

            try {
                const res = await fetch(`?ajax=recipients&link_id=${linkId}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal memuat data');

                const parents = data.parents || [];
                const unlinked = data.unlinked_students || [];
                const openedCount = parents.filter(p => p.jumlah_klik > 0).length;

                document.getElementById('recipientsModalSubtitle').textContent =
                    `${data.link.judul} — ${openedCount} dari ${parents.length} akun orang tua sudah membuka`;

                let html = '';
                if (parents.length === 0) {
                    html += `<div class="p-6 text-center text-sm text-gray-500 bg-gray-50 rounded-xl border border-dashed border-gray-300">Belum ada akun orang tua yang terhubung dengan target link ini.</div>`;
                } else {
                    html += parents.map(p => {
                        const kidsHTML = p.students.map(s =>
                            `<span class="inline-block px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[11px] font-medium mr-1 mb-1">${escapeHtml(s.nama_lengkap)} (${escapeHtml(s.tingkat)})</span>`
                        ).join('');

                        let waBtn = '';
                        const cleanHp = (p.no_hp || '').replace(/[^0-9]/g, '');
                        if (cleanHp) {
                            const waNum = cleanHp.startsWith('0') ? ('62' + cleanHp.slice(1)) : cleanHp;
                            const msg = encodeURIComponent(`Halo ${p.nama_ortu}, Admin Bimbel Esc telah membagikan tautan "${data.link.judul}" di portal Orang Tua Bimbel Esc. Silakan dicek pada menu Link Bimbel atau akses langsung: ${data.link.url}`);
                            waBtn = `<a href="https://wa.me/${waNum}?text=${msg}" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 transition">
                                <i class="fab fa-whatsapp text-sm"></i> Ingatkan via WA
                            </a>`;
                        }

                        return `
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 border border-gray-200 rounded-xl bg-white">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-bold text-gray-800 text-sm">${escapeHtml(p.nama_ortu)}</p>
                                        ${p.jumlah_klik > 0
                                            ? `<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-700"><i class="fas fa-check-circle mr-1"></i>Sudah Dibuka (${p.jumlah_klik}x)</span>`
                                            : `<span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800"><i class="fas fa-clock mr-1"></i>Belum Dibuka</span>`
                                        }
                                    </div>
                                    <p class="text-xs text-gray-500 mt-0.5">${escapeHtml(p.email)}</p>
                                    <div class="mt-1.5">${kidsHTML}</div>
                                    ${p.terakhir_dibuka ? `<p class="text-[11px] text-gray-400 mt-1">Terakhir dibuka: ${escapeHtml(p.terakhir_dibuka)}</p>` : ''}
                                </div>
                                <div class="shrink-0">${waBtn}</div>
                            </div>
                        `;
                    }).join('');
                }

                if (unlinked.length > 0) {
                    html += `
                        <div class="mt-3 p-3.5 bg-amber-50 border border-amber-200 rounded-xl">
                            <p class="text-xs font-bold text-amber-800 mb-1.5"><i class="fas fa-exclamation-triangle mr-1"></i>Siswa Target yang Belum Punya Akun Orang Tua (${unlinked.length})</p>
                            <div class="flex flex-wrap gap-1.5">
                                ${unlinked.map(u => `<span class="px-2 py-0.5 rounded bg-white border border-amber-200 text-[11px] text-amber-900">${escapeHtml(u.nama_lengkap)} (${escapeHtml(u.tingkat)})</span>`).join('')}
                            </div>
                        </div>
                    `;
                }

                document.getElementById('recipientsModalBody').innerHTML = html;
            } catch (err) {
                document.getElementById('recipientsModalBody').innerHTML = `
                    <div class="p-4 bg-red-50 text-red-700 rounded-lg text-sm">Gagal memuat data: ${escapeHtml(err.message)}</div>
                `;
            }
        }

        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => showToast('URL berhasil disalin!', 'success'));
            } else {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                showToast('URL berhasil disalin!', 'success');
            }
        }

        function showToast(msg, type = 'success') {
            const colors = { success: 'bg-emerald-600 text-white', error: 'bg-rose-600 text-white', info: 'bg-slate-800 text-white' };
            const el = document.createElement('div');
            el.className = `px-4 py-2.5 rounded-xl shadow-lg text-xs font-medium flex items-center gap-2 ${colors[type] || colors.info}`;
            el.innerHTML = `<i class="fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}"></i><span>${escapeHtml(msg)}</span>`;
            document.getElementById('toastContainer').appendChild(el);
            setTimeout(() => el.remove(), 3000);
        }

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    </script>
</body>
</html>
