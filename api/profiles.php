<?php
/**
 * Profiles API
 * GET   /api/profiles.php?id=xxx           → single profile
 * GET   /api/profiles.php?ids=a,b,c        → multiple profiles by IDs
 * GET   /api/profiles.php?action=count     → profile count (admin stats)
 * PATCH /api/profiles.php?id=xxx           → update own profile
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'PATCH':
    case 'PUT':
        handlePatch();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGet(): void {
    $db = getDB();

    // Count (admin stats)
    if (($_GET['action'] ?? '') === 'count') {
        $count = $db->query("SELECT COUNT(*) FROM profiles")->fetchColumn();
        jsonResponse(['count' => (int) $count]);
        return;
    }

    // Multiple IDs
    if (isset($_GET['ids'])) {
        $ids = array_filter(explode(',', $_GET['ids']));
        if (empty($ids)) {
            jsonResponse([]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT p.*, u.email FROM profiles p LEFT JOIN users u ON p.id = u.id WHERE p.id IN ($placeholders)");
        $st->execute($ids);
        jsonResponse($st->fetchAll());
        return;
    }

    // Single
    if (isset($_GET['id'])) {
        $st = $db->prepare('SELECT p.*, u.email FROM profiles p LEFT JOIN users u ON p.id = u.id WHERE p.id = ? LIMIT 1');
        $st->execute([$_GET['id']]);
        $row = $st->fetch();
        jsonResponse($row ?: null);
        return;
    }

    jsonResponse(['error' => 'Missing id parameter'], 400);
}

function handlePatch(): void {
    $user = requireAuth();
    $id = $_GET['id'] ?? '';

    if (!$id || $id !== $user['sub']) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $body = jsonBody();
    $db = getDB();

    $allowed = ['full_name', 'instansi', 'bio', 'avatar_url'];
    $sets = [];
    $params = [];

    foreach ($body as $key => $val) {
        if (!in_array($key, $allowed)) continue;
        $sets[] = "`$key` = ?";
        $params[] = $val;
    }

    if (empty($sets)) {
        jsonResponse(['error' => 'Nothing to update'], 400);
    }

    // If updating avatar_url, delete old avatar file from disk
    if (array_key_exists('avatar_url', $body)) {
        $stOld = $db->prepare('SELECT avatar_url FROM profiles WHERE id = ? LIMIT 1');
        $stOld->execute([$id]);
        $oldRow = $stOld->fetch();
        if ($oldRow && !empty($oldRow['avatar_url']) && $oldRow['avatar_url'] !== $body['avatar_url']) {
            deleteUploadedFile($oldRow['avatar_url']);
        }
    }

    $params[] = $id;
    $sql = 'UPDATE profiles SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);

    jsonResponse(['ok' => true]);
}
