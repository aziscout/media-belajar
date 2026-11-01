<?php
/**
 * Kompetensi Keahlian SMK API
 * GET    /api/kompetensi.php              → all kompetensi
 * GET    /api/kompetensi.php?q=teknik     → search by keyword
 * POST   /api/kompetensi.php             → create (admin only)
 * PATCH  /api/kompetensi.php?id=xxx      → update (admin only)
 * DELETE /api/kompetensi.php?id=xxx      → delete (admin only)
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

function handleGet(): void {
    $db = getDB();
    $q = $_GET['q'] ?? null;

    if ($q && strlen($q) >= 1) {
        $stmt = $db->prepare("SELECT * FROM kompetensi_keahlian WHERE nama LIKE :q ORDER BY nama ASC LIMIT 20");
        $stmt->execute([':q' => '%' . $q . '%']);
    } else {
        $stmt = $db->query("SELECT * FROM kompetensi_keahlian ORDER BY nama ASC");
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    jsonResponse($rows);
}

function handlePost(): void {
    $user = requireAuth();
    $uid = $user['sub'];
    if (!userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['nama'])) {
        jsonResponse(['error' => 'nama is required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO kompetensi_keahlian (nama) VALUES (:nama)");
    $stmt->execute([':nama' => $data['nama']]);

    jsonResponse(['id' => (int) $db->lastInsertId(), 'success' => true], 201);
}

function handlePatch(): void {
    $user = requireAuth();
    $uid = $user['sub'];
    if (!userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $id = $_GET['id'] ?? null;
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || empty($data['nama'])) {
        jsonResponse(['error' => 'nama is required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("UPDATE kompetensi_keahlian SET nama = :nama WHERE id = :id");
    $stmt->execute([':nama' => $data['nama'], ':id' => $id]);

    jsonResponse(['success' => true]);
}

function handleDelete(): void {
    $user = requireAuth();
    $uid = $user['sub'];
    if (!userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $id = $_GET['id'] ?? null;
    if (!$id) jsonResponse(['error' => 'id required'], 400);

    $db = getDB();
    $stmt = $db->prepare("DELETE FROM kompetensi_keahlian WHERE id = :id");
    $stmt->execute([':id' => $id]);

    jsonResponse(['success' => true]);
}
