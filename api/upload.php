<?php
/**
 * Upload API — Thumbnail upload via multipart form
 * POST /api/upload.php  (multipart/form-data, field: "file")
 * Returns: { url: "/uploads/media-thumbnails/{userId}/{filename}" }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $user = requireAuth();
    $userId = $user['sub'];

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? 'NO_FILE';
        jsonResponse(['error' => "File upload gagal (code: $errCode)"], 400);
    }

    $file = $_FILES['file'];
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        jsonResponse(['error' => 'Tipe file tidak diizinkan. Gunakan JPG, PNG, WebP, atau GIF.'], 400);
    }

    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(['error' => 'Ukuran file melebihi 5MB'], 400);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;

    $type = ($_GET['type'] ?? $_POST['type'] ?? 'media-thumbnails') === 'avatar' ? 'avatars' : 'media-thumbnails';
    $dir = dirname(__DIR__) . '/uploads/' . $type . '/' . $userId;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            jsonResponse(['error' => "Gagal membuat folder upload di server (/uploads/$type)"], 500);
        }
    }

    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Gagal menyimpan file ke server. Cek permission folder uploads.'], 500);
    }

    // Return relative URL
    $url = '/uploads/' . $type . '/' . $userId . '/' . $filename;
    jsonResponse(['url' => $url, 'publicUrl' => $url]);
} catch (\Exception $e) {
    jsonResponse(['error' => 'Upload error: ' . $e->getMessage()], 500);
}
