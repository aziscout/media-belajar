<?php
/**
 * Ratings API
 * GET  /api/ratings.php?media_id=xxx                       → stats (avg + count)
 * GET  /api/ratings.php?media_id=xxx&user_id=yyy           → my rating
 * POST /api/ratings.php  {user_id, media_id, score}        → upsert rating
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
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGet(): void {
    $mediaId = $_GET['media_id'] ?? '';
    if (!$mediaId) {
        jsonResponse(['error' => 'Missing media_id'], 400);
    }

    $db = getDB();

    // Single user's rating
    if (isset($_GET['user_id'])) {
        $st = $db->prepare('SELECT score FROM ratings WHERE user_id = ? AND media_id = ? LIMIT 1');
        $st->execute([$_GET['user_id'], $mediaId]);
        $score = $st->fetchColumn();
        jsonResponse(['score' => $score !== false ? (int)$score : 0]);
        return;
    }

    // Stats: avg + count
    $st = $db->prepare('SELECT COUNT(*) as count, COALESCE(AVG(score), 0) as avg FROM ratings WHERE media_id = ?');
    $st->execute([$mediaId]);
    $row = $st->fetch();
    jsonResponse([
        'count' => (int) $row['count'],
        'avg'   => round((float) $row['avg'], 2),
    ]);
}

function handlePost(): void {
    $user = requireAuth();
    $body = jsonBody();
    $userId  = $body['user_id']  ?? $user['sub'];
    $mediaId = $body['media_id'] ?? '';
    $score   = (int)($body['score'] ?? 0);

    if (!$mediaId || $score < 1 || $score > 5) {
        jsonResponse(['error' => 'media_id wajib dan score harus 1-5'], 400);
    }

    $db = getDB();

    // Upsert: update if exists, insert if not
    $st = $db->prepare('SELECT id FROM ratings WHERE user_id = ? AND media_id = ? LIMIT 1');
    $st->execute([$userId, $mediaId]);
    $existing = $st->fetchColumn();

    if ($existing) {
        $db->prepare('UPDATE ratings SET score = ? WHERE id = ?')->execute([$score, $existing]);
    } else {
        $db->prepare('INSERT INTO ratings (id, user_id, media_id, score) VALUES (?, ?, ?, ?)')
           ->execute([uuid4(), $userId, $mediaId, $score]);
    }

    jsonResponse(['ok' => true]);
}
