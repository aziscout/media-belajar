<?php
/**
 * Media API
 * GET    /api/media.php                        → all approved
 * GET    /api/media.php?slug=xxx               → single by slug
 * GET    /api/media.php?status=pending          → pending (reviewer+)
 * GET    /api/media.php?contributor_id=xxx      → my media
 * GET    /api/media.php?action=stats            → admin stats (counts)
 * POST   /api/media.php                        → create media
 * PATCH  /api/media.php?id=xxx                 → update media (review, etc)
 * PATCH  /api/media.php?id=xxx&action=view     → increment views
 * DELETE /api/media.php?id=xxx                 → delete media
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    case 'PATCH':
    case 'PUT':
        handlePatch();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function formatMediaRow(array $row): array {
    // Parse JSON fields
    $row['tags']     = json_decode($row['tags'] ?? '[]', true) ?: [];
    $row['jenjangs'] = json_decode($row['jenjangs'] ?? '[]', true) ?: [];
    $row['embeddable'] = (bool) $row['embeddable'];
    $row['views']    = (int) $row['views'];
    $row['kelas']    = (int) $row['kelas'];
    $row['rating']   = (float) $row['rating'];
    $row['kompetensi_keahlian'] = $row['kompetensi_keahlian'] ?? null;
    $row['reviewer_name'] = $row['reviewer_name'] ?? null;

    // Normalisasi thumbnail lama agar selalu menggunakan path relatif
    if (!empty($row['thumbnail']) && str_contains($row['thumbnail'], 'media-belajar.btikpsultra.com')) {
        $row['thumbnail'] = preg_replace('#^https?://[^/]+#', '', $row['thumbnail']);
    }

    return $row;
}

function handleGet(): void {
    try {
        $db = getDB();

        // Public landing stats (real-time DB counts)
        if (($_GET['action'] ?? '') === 'public_stats') {
        $totalMedia   = (int) $db->query("SELECT COUNT(*) FROM media WHERE status='approved'")->fetchColumn();
        $kontributor  = (int) $db->query("
            SELECT COUNT(DISTINCT COALESCE(NULLIF(m.contributor_id, ''), m.contributor_name))
            FROM media m
            WHERE m.status = 'approved'
              AND (m.contributor_id IS NOT NULL OR m.contributor_name IS NOT NULL)
        ")->fetchColumn();
        $video        = (int) $db->query("SELECT COUNT(*) FROM media WHERE status='approved' AND jenis='Video'")->fetchColumn();
        $gim          = (int) $db->query("SELECT COUNT(*) FROM media WHERE status='approved' AND jenis='Gim'")->fetchColumn();
        $interaktif   = (int) $db->query("SELECT COUNT(*) FROM media WHERE status='approved' AND jenis='Interaktif'")->fetchColumn();
        $totalViews   = (int) $db->query("SELECT COALESCE(SUM(views), 0) FROM media WHERE status='approved'")->fetchColumn();

        jsonResponse([
            'media'       => $totalMedia,
            'kontributor' => $kontributor,
            'video'       => $video,
            'gim'         => $gim,
            'interaktif'  => $interaktif,
            'pengunjung'  => $totalViews,
        ]);
        return;
    }

    // Admin stats
    if (($_GET['action'] ?? '') === 'stats') {
        $user = requireAuth();
        $uid = $user['sub'];
        if (!userHasRole($uid, 'admin')) {
            jsonResponse(['error' => 'Forbidden'], 403);
        }

        $media    = $db->query("SELECT COUNT(*) FROM media")->fetchColumn();
        $pending  = $db->query("SELECT COUNT(*) FROM media WHERE status='pending'")->fetchColumn();
        $approved = $db->query("SELECT COUNT(*) FROM media WHERE status='approved'")->fetchColumn();
        $users    = $db->query("SELECT COUNT(*) FROM profiles")->fetchColumn();
        $views    = $db->query("SELECT COALESCE(SUM(views),0) FROM media")->fetchColumn();

        jsonResponse([
            'media'    => (int)$media,
            'pending'  => (int)$pending,
            'approved' => (int)$approved,
            'users'    => (int)$users,
            'views'    => (int)$views,
        ]);
        return;
    }

    $baseSql = "
        SELECT m.*, COALESCE(NULLIF(p.full_name, ''), NULLIF(u.full_name, ''), u.email) AS reviewer_name
        FROM media m
        LEFT JOIN users u ON m.reviewed_by = u.id
        LEFT JOIN profiles p ON m.reviewed_by = p.id
    ";

    // Single by slug
    if (isset($_GET['slug'])) {
        $st = $db->prepare($baseSql . ' WHERE m.slug = ? LIMIT 1');
        $st->execute([$_GET['slug']]);
        $row = $st->fetch();
        jsonResponse($row ? formatMediaRow($row) : null);
        return;
    }

    // By contributor (my media)
    if (isset($_GET['contributor_id'])) {
        $st = $db->prepare($baseSql . ' WHERE m.contributor_id = ? ORDER BY m.created_at DESC');
        $st->execute([$_GET['contributor_id']]);
        $rows = array_map('formatMediaRow', $st->fetchAll());
        jsonResponse($rows);
        return;
    }

    // By status (pending for reviewer, all for admin)
    if (isset($_GET['status'])) {
        $status = $_GET['status'];
        if ($status === 'all') {
            $st = $db->query($baseSql . ' ORDER BY m.created_at DESC');
        } else {
            $st = $db->prepare($baseSql . ' WHERE m.status = ? ORDER BY m.created_at DESC');
            $st->execute([$status]);
        }
        $rows = array_map('formatMediaRow', $st->fetchAll());
        jsonResponse($rows);
        return;
    }

    // Default: all approved
    $st = $db->query($baseSql . ' WHERE m.status = \'approved\' ORDER BY m.created_at DESC');
    $rows = array_map('formatMediaRow', $st->fetchAll());
    jsonResponse($rows);
    } catch (\PDOException $e) {
        logDbError("Query error di handleGet (api/media.php): " . $e->getMessage(), ['get' => $_GET]);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'error'   => 'Gagal menjalankan query database di api/media.php.',
            'message' => $e->getMessage(),
            'hint'    => 'Jika error menyebutkan "Base table or view not found", artinya Anda belum meng-import file u798354071_media_belajar.sql ke phpMyAdmin di hosting Anda.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Exception $e) {
        logDbError("General error di handleGet (api/media.php): " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'error'   => $e->getMessage()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function handlePost(): void {
    $user = requireAuth();
    $body = jsonBody();

    $required = ['judul', 'deskripsi', 'jenis', 'jenjang', 'link'];
    foreach ($required as $f) {
        if (empty($body[$f]) && $body[$f] !== 0) {
            jsonResponse(['error' => "Field '$f' wajib diisi"], 400);
        }
    }

    $db = getDB();
    $id = uuid4();

    // Auto-generate slug if empty
    $slug = trim($body['slug'] ?? '');
    if (!$slug) {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $body['judul']), '-')) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    $tags     = json_encode($body['tags'] ?? [], JSON_UNESCAPED_UNICODE);
    $jenjangs = json_encode($body['jenjangs'] ?? [$body['jenjang']], JSON_UNESCAPED_UNICODE);

    $kelas = (isset($body['kelas']) && $body['kelas'] !== '' && $body['kelas'] !== 0 && $body['kelas'] !== null) ? (int) $body['kelas'] : null;
    if ($kelas !== null && ($kelas < 1 || $kelas > 12)) {
        $kelas = null;
    }

    try {
        $st = $db->prepare('INSERT INTO media (id, slug, judul, deskripsi, thumbnail, jenis, jenjang, jenjangs, kelas, mapel, kompetensi_keahlian, link, embeddable, tags, status, contributor_id, contributor_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $id,
            $slug,
            $body['judul'],
            $body['deskripsi'],
            $body['thumbnail'] ?? null,
            $body['jenis'],
            $body['jenjang'],
            $jenjangs,
            $kelas,
            $body['mapel'] ?? null,
            $body['kompetensi_keahlian'] ?? null,
            $body['link'],
            $body['embeddable'] ?? 1,
            $tags,
            $body['status'] ?? 'pending',
            $body['contributor_id'] ?? $user['sub'],
            $body['contributor_name'] ?? null,
        ]);

        jsonResponse(['id' => $id], 201);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'Gagal menyimpan media: ' . $e->getMessage()], 500);
    }
}

function handlePatch(): void {
    $id = $_GET['id'] ?? '';
    if (!$id) {
        jsonResponse(['error' => 'Missing id'], 400);
    }

    // Increment views (no auth needed)
    if (($_GET['action'] ?? '') === 'view') {
        $db = getDB();
        $db->prepare('UPDATE media SET views = views + 1 WHERE id = ?')->execute([$id]);
        jsonResponse(['ok' => true]);
        return;
    }

    $user = requireAuth();
    $body = jsonBody();
    $db = getDB();

    // Build dynamic UPDATE
    $allowed = ['status', 'reviewed_by', 'reviewed_at', 'rejection_note', 'judul', 'deskripsi', 'jenis', 'jenjang', 'jenjangs', 'kelas', 'mapel', 'kompetensi_keahlian', 'link', 'embeddable', 'tags', 'thumbnail', 'views'];
    $sets = [];
    $params = [];

    foreach ($body as $key => $val) {
        if (!in_array($key, $allowed)) continue;
        // JSON fields
        if (in_array($key, ['tags', 'jenjangs']) && is_array($val)) {
            $val = json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        if ($key === 'kelas') {
            $val = ($val !== '' && $val !== 0 && $val !== null) ? (int) $val : null;
            if ($val !== null && ($val < 1 || $val > 12)) $val = null;
        }
        $sets[] = "`$key` = ?";
        $params[] = $val;
    }

    if (empty($sets)) {
        jsonResponse(['error' => 'Nothing to update'], 400);
    }

    // If thumbnail is updated, delete old thumbnail file from disk
    if (array_key_exists('thumbnail', $body)) {
        $stOld = $db->prepare('SELECT thumbnail FROM media WHERE id = ? LIMIT 1');
        $stOld->execute([$id]);
        $oldRow = $stOld->fetch();
        if ($oldRow && !empty($oldRow['thumbnail']) && $oldRow['thumbnail'] !== $body['thumbnail']) {
            deleteUploadedFile($oldRow['thumbnail']);
        }
    }

    $params[] = $id;
    $sql = 'UPDATE media SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);

    jsonResponse(['ok' => true]);
}

function handleDelete(): void {
    $user = requireAuth();
    $id = $_GET['id'] ?? '';
    if (!$id) {
        jsonResponse(['error' => 'Missing id'], 400);
    }

    $db = getDB();

    // Fetch old thumbnail before deleting
    $stOld = $db->prepare('SELECT thumbnail FROM media WHERE id = ? AND (contributor_id = ? OR ? IN (SELECT user_id FROM user_roles WHERE role = "admin")) LIMIT 1');
    $stOld->execute([$id, $user['sub'], $user['sub']]);
    $oldRow = $stOld->fetch();

    if ($oldRow) {
        deleteUploadedFile($oldRow['thumbnail'] ?? null);
        $db->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    }

    jsonResponse(['ok' => true]);
}
