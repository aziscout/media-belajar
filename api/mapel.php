<?php
/**
 * Mata Pelajaran API
 * GET    /api/mapel.php                → all mata pelajaran
 * GET    /api/mapel.php?jenjang=SD     → by jenjang
 * POST   /api/mapel.php               → create (admin only)
 * PATCH  /api/mapel.php?id=xxx        → update (admin only)
 * DELETE /api/mapel.php?id=xxx        → delete (admin only)
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
    $jenjang = $_GET['jenjang'] ?? null;

    if ($jenjang) {
        $stmt = $db->prepare("SELECT * FROM mata_pelajaran WHERE jenjang = :jenjang ORDER BY urutan ASC, nama ASC");
        $stmt->execute([':jenjang' => $jenjang]);
    } else {
        $stmt = $db->query("SELECT * FROM mata_pelajaran ORDER BY jenjang ASC, urutan ASC, nama ASC");
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['urutan'] = (int) $row['urutan'];
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
    if (!$data || empty($data['jenjang']) || empty($data['nama'])) {
        jsonResponse(['error' => 'jenjang and nama are required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO mata_pelajaran (jenjang, nama, urutan) VALUES (:jenjang, :nama, :urutan)");
    $stmt->execute([
        ':jenjang' => $data['jenjang'],
        ':nama' => $data['nama'],
        ':urutan' => $data['urutan'] ?? 0,
    ]);

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
    if (!$data) jsonResponse(['error' => 'No data'], 400);

    $db = getDB();
    $fields = [];
    $params = [':id' => $id];

    if (isset($data['nama'])) {
        $fields[] = "nama = :nama";
        $params[':nama'] = $data['nama'];
    }
    if (isset($data['jenjang'])) {
        $fields[] = "jenjang = :jenjang";
        $params[':jenjang'] = $data['jenjang'];
    }
    if (isset($data['urutan'])) {
        $fields[] = "urutan = :urutan";
        $params[':urutan'] = $data['urutan'];
    }

    if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);

    $sql = "UPDATE mata_pelajaran SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

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
    $stmt = $db->prepare("DELETE FROM mata_pelajaran WHERE id = :id");
    $stmt->execute([':id' => $id]);

    jsonResponse(['success' => true]);
}
