<?php
/**
 * Site Settings API
 * GET   /api/settings.php           → Get public settings (registration_enabled, etc.)
 * PATCH /api/settings.php          → Update settings (admin only)
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
    case 'PATCH':
    case 'PUT':
        handlePatch();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function ensureSettingsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert default registration_enabled = '1' if not set
    $stmt = $db->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'registration_enabled'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmtInsert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('registration_enabled', '1')");
        $stmtInsert->execute();
    }
}

function handleGet(): void {
    $db = getDB();
    ensureSettingsTable($db);

    $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'registration_enabled' => true,
        'turnstile_enabled'    => defined('TURNSTILE_ENABLED') ? TURNSTILE_ENABLED : true,
    ];

    foreach ($rows as $r) {
        if ($r['setting_key'] === 'registration_enabled') {
            $result['registration_enabled'] = ($r['setting_value'] === '1' || $r['setting_value'] === 'true');
        } else {
            $result[$r['setting_key']] = $r['setting_value'];
        }
    }

    jsonResponse($result);
}

function handlePatch(): void {
    $user = requireAuth();
    $uid = $user['sub'];
    if (!userHasRole($uid, 'admin')) {
        jsonResponse(['error' => 'Forbidden'], 403);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Invalid data'], 400);
    }

    $db = getDB();
    ensureSettingsTable($db);

    $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2");

    foreach ($data as $key => $val) {
        $stringVal = is_bool($val) ? ($val ? '1' : '0') : (string)$val;
        $stmt->execute([
            ':key' => $key,
            ':val' => $stringVal,
            ':val2' => $stringVal,
        ]);
    }

    handleGet();
}
