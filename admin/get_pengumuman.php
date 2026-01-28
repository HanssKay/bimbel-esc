<?php
session_start();
error_reporting(0); // Nonaktifkan error reporting untuk AJAX

require_once '../includes/config.php';

// Cek session
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    http_response_code(403);
    echo '<div class="text-center py-8 text-red-600">Akses ditolak</div>';
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo '<div class="text-center py-8 text-red-600">ID tidak valid</div>';
    exit();
}

$id = intval($_GET['id']);

// Ambil data pengumuman
$sql = "SELECT * FROM pengumuman WHERE id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo '<div class="text-center py-8 text-red-600">Pengumuman tidak ditemukan</div>';
        exit();
    }
    
    $pengumuman = $result->fetch_assoc();
    $stmt->close();
    
    // Format tanggal untuk input
    $ditampilkan_dari = date('Y-m-d\TH:i', strtotime($pengumuman['ditampilkan_dari']));
    $ditampilkan_sampai = !empty($pengumuman['ditampilkan_sampai']) ? 
        date('Y-m-d\TH:i', strtotime($pengumuman['ditampilkan_sampai'])) : '';
?>

<form method="POST" action="" enctype="multipart/form-data" id="editPengumumanForm" class="space-y-4">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?php echo $pengumuman['id']; ?>">
    
    <!-- Judul -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
            Judul Pengumuman <span class="text-red-500">*</span>
        </label>
        <input type="text" name="judul" required
               value="<?php echo htmlspecialchars($pengumuman['judul']); ?>"
               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
    </div>
    
    <!-- Isi -->
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
            Isi Pengumuman <span class="text-red-500">*</span>
        </label>
        <textarea name="isi" rows="6" required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($pengumuman['isi']); ?></textarea>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Gambar -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Gambar
            </label>
            
            <?php if (!empty($pengumuman['gambar'])): ?>
            <div class="mb-3">
                <p class="text-sm text-gray-600 mb-2">Gambar saat ini:</p>
                <img src="../uploads/pengumuman/<?php echo htmlspecialchars($pengumuman['gambar']); ?>" 
                     class="image-preview mb-2">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="hapus_gambar" value="1" class="rounded border-gray-300">
                    <span class="ml-2 text-sm text-gray-600">Hapus gambar ini</span>
                </label>
            </div>
            <?php endif; ?>
            
            <div class="border border-gray-300 rounded-md p-4">
                <div class="text-center">
                    <label class="cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-blue-500 text-2xl mb-2"></i>
                        <p class="text-sm text-blue-600">Upload gambar baru</p>
                        <input type="file" name="gambar" class="sr-only" accept="image/*">
                        <p class="text-xs text-gray-500 mt-2">PNG, JPG, GIF maksimal 2MB</p>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Info -->
        <div class="space-y-4">
            <!-- Status -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Status
                </label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
                    <option value="draft" <?php echo $pengumuman['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="publik" <?php echo $pengumuman['status'] == 'publik' ? 'selected' : ''; ?>>Publik</option>
                </select>
            </div>
            
            <!-- Tanggal -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Tampilkan Dari <span class="text-red-500">*</span>
                </label>
                <input type="datetime-local" name="ditampilkan_dari" required
                       value="<?php echo $ditampilkan_dari; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Tampilkan Sampai (Opsional)
                </label>
                <input type="datetime-local" name="ditampilkan_sampai"
                       value="<?php echo $ditampilkan_sampai; ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm">
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
        <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50">
            Batal
        </button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            <i class="fas fa-save mr-2"></i> Simpan Perubahan
        </button>
    </div>
</form>

<?php
} else {
    echo '<div class="text-center py-8 text-red-600">Database error</div>';
}
?>