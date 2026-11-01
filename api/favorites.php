<?php
/**
 * Favorites API
 * GET    /api/favorites.php?user_id=xxx                    → list favorite media_ids
 * GET    /api/favorites.php?user_id=xxx&expand=1           → list favorite media (full)
 * POST   /api/favorites.php  {user_id, media_id}           → add favorite
 * DELETE /api/favorites.php?user_id=xxx&media_id=yyy       → remove favorite
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
    case 'DELETE':
        handleDelete();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGet(): void {
    $user = requireAuth();
    $userId = $_GET['user_id'] ?? $user['sub'];

    $db = getDB();

    // Expand = return full media objects
    if (!empty($_GET['expand'])) {
        $st = $db->prepare("
            SELECT m.* FROM favorites f
            JOIN media m ON m.id = f.media_id
            WHERE f.user_id = ? AND m.status = 'approved'
            ORDER BY f.created_at DESC
        ");
        $st->execute([$userId]);
        $rows = $st->fetchAll();
        // Format JSON fields
        foreach ($rows as &$row) {
            $row['tags']     = json_decode($row['tags'] ?? '[]', true) ?: [];
            $row['jenjangs'] = json_decode($row['jenjangs'] ?? '[]', true) ?: [];
            $row['embeddable'] = (bool) $row['embeddable'];
            $row['views']    = (int) $row['views'];
            $row['kelas']    = (int) $row['kelas'];
            $row['rating']   = (float) $row['rating'];
        }
        jsonResponse($rows);
        return;
    }

    // Default: just media_ids
    $st = $db->prepare('SELECT media_id FROM favorites WHERE user_id = ?');
    $st->execute([$userId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse($ids);
}

function handlePost(): void {
    $user = requireAuth();
    $body = jsonBody();
    $userId  = $body['user_id']  ?? $user['sub'];
    $mediaId = $body['media_id'] ?? '';

    if (!$mediaId) {
        jsonResponse(['error' => 'Missing media_id'], 400);
    }

    $db = getDB();
    try {
        $db->prepare('INSERT INTO favorites (id, user_id, media_id) VALUES (?, ?, ?)')
           ->execute([uuid4(), $userId, $mediaId]);
    } catch (\PDOException $e) {
        // Duplicate key = already favorited, that's fine
        if ($e->getCode() != 23000) {
            throw $e;
        }
    }

    jsonResponse(['ok' => true], 201);
}

function handleDelete(): void {
    $user = requireAuth();
    $userId  = $_GET['user_id']  ?? $user['sub'];
    $mediaId = $_GET['media_id'] ?? '';

    if (!$mediaId) {
        jsonResponse(['error' => 'Missing media_id'], 400);
    }

    $db = getDB();
    $db->prepare('DELETE FROM favorites WHERE user_id = ? AND media_id = ?')
       ->execute([$userId, $mediaId]);

    jsonResponse(['ok' => true]);
}
