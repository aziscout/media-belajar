<?php
/**
 * Admin Users Management API
 * GET    /api/users.php         → list all users (admin)
 * POST   /api/users.php         → create user (admin)
 * PATCH  /api/users.php?id=xxx  → update user profile & role (admin)
 * DELETE /api/users.php?id=xxx  → delete user (admin)
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

function checkAdmin(): array {
    $user = requireAuth();
    if (!userHasRole($user['sub'], 'admin')) {
        jsonResponse(['error' => 'Forbidden: Admin access required'], 403);
    }
    return $user;
}

function handleGet(): void {
    checkAdmin();
    $db = getDB();

    $sql = "
        SELECT 
            u.id, 
            u.email, 
            u.created_at, 
            p.full_name, 
            p.instansi, 
            p.bio, 
            p.avatar_url,
            (SELECT GROUP_CONCAT(role) FROM user_roles WHERE user_id = u.id) AS roles_csv
        FROM users u
        LEFT JOIN profiles p ON u.id = p.id
        ORDER BY COALESCE(NULLIF(p.full_name, ''), u.email) ASC
    ";

    $st = $db->query($sql);
    $rows = $st->fetchAll();

    $result = array_map(function ($r) {
        $rolesStr = $r['roles_csv'] ?? 'pending';
        $roles = array_values(array_filter(array_map('trim', explode(',', $rolesStr))));
        if (empty($roles)) $roles = ['pending'];

        return [
            'id'         => $r['id'],
            'email'      => $r['email'],
            'full_name'  => $r['full_name'] ?? $r['email'],
            'instansi'   => $r['instansi'] ?? '',
            'bio'        => $r['bio'] ?? '',
            'avatar_url' => $r['avatar_url'] ?? null,
            'roles'      => $roles,
            'role'       => $roles[0], // primary role
            'created_at' => $r['created_at'],
        ];
    }, $rows);

    jsonResponse($result);
}

function handlePost(): void {
    checkAdmin();
    $body = jsonBody();
    $db = getDB();
    $validRoles = ['admin', 'reviewer', 'kontributor', 'user'];

    // Handle batch import if users array is provided
    if (isset($body['users']) && is_array($body['users'])) {
        $imported = 0;
        $failed = 0;
        $results = [];

        foreach ($body['users'] as $u) {
            $email    = trim($u['email'] ?? '');
            $password = $u['password'] ?? '';
            $fullName = trim($u['full_name'] ?? '');
            $instansi = trim($u['instansi'] ?? '');
            $role     = strtolower(trim($u['role'] ?? 'kontributor'));

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed++;
                $results[] = ['email' => $email ?: 'kosong', 'ok' => false, 'error' => 'Email tidak valid'];
                continue;
            }
            if (strlen($password) < 6) {
                $failed++;
                $results[] = ['email' => $email, 'ok' => false, 'error' => 'Password minimal 6 karakter'];
                continue;
            }
            if (!in_array($role, $validRoles)) {
                $role = 'kontributor';
            }

            // Check duplicate email
            $st = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            if ($st->fetch()) {
                $failed++;
                $results[] = ['email' => $email, 'ok' => false, 'error' => 'Email sudah terdaftar'];
                continue;
            }

            $userId = uuid4();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $now = date('Y-m-d H:i:s');

            $db->beginTransaction();
            try {
                $st = $db->prepare('INSERT INTO users (id, email, encrypted_password, full_name, email_confirmed_at) VALUES (?, ?, ?, ?, ?)');
                $st->execute([$userId, $email, $hash, $fullName ?: $email, $now]);

                $st = $db->prepare('INSERT INTO profiles (id, full_name, instansi) VALUES (?, ?, ?)');
                $st->execute([$userId, $fullName ?: $email, $instansi]);

                $st = $db->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)');
                $st->execute([uuid4(), $userId, $role]);

                $db->commit();
                $imported++;
                $results[] = ['email' => $email, 'ok' => true];
            } catch (Exception $e) {
                $db->rollBack();
                $failed++;
                $results[] = ['email' => $email, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        jsonResponse([
            'ok' => true,
            'imported' => $imported,
            'failed' => $failed,
            'results' => $results
        ]);
        return;
    }

    // Single user creation
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $fullName = trim($body['full_name'] ?? '');
    $instansi = trim($body['instansi'] ?? '');
    $role     = strtolower(trim($body['role'] ?? 'kontributor'));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email tidak valid'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Password minimal 6 karakter'], 400);
    }
    if (!in_array($role, $validRoles)) {
        $role = 'kontributor';
    }

    // Check duplicate email
    $st = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        jsonResponse(['error' => 'Email sudah terdaftar'], 400);
    }

    $userId = uuid4();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $now = date('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        // Insert users table
        $st = $db->prepare('INSERT INTO users (id, email, encrypted_password, full_name, email_confirmed_at) VALUES (?, ?, ?, ?, ?)');
        $st->execute([$userId, $email, $hash, $fullName ?: $email, $now]);

        // Insert profiles table
        $st = $db->prepare('INSERT INTO profiles (id, full_name, instansi) VALUES (?, ?, ?)');
        $st->execute([$userId, $fullName ?: $email, $instansi]);

        // Insert user_roles table
        $st = $db->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)');
        $st->execute([uuid4(), $userId, $role]);

        $db->commit();
        jsonResponse(['ok' => true, 'id' => $userId]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Gagal membuat pengguna: ' . $e->getMessage()], 500);
    }
}

function handlePatch(): void {
    checkAdmin();
    $body = jsonBody();
    $db = getDB();

    // Check if bulk role assign
    if (!empty($body['ids']) && is_array($body['ids']) && !empty($body['role'])) {
        $ids = array_filter(array_map('trim', $body['ids']));
        $newRole = strtolower(trim($body['role']));
        $validRoles = ['admin', 'reviewer', 'kontributor', 'user'];

        if (!in_array($newRole, $validRoles)) {
            jsonResponse(['error' => 'Role tidak valid'], 400);
        }

        if (empty($ids)) {
            jsonResponse(['error' => 'Tidak ada pengguna yang dipilih'], 400);
        }

        $inClause = implode(',', array_fill(0, count($ids), '?'));

        $db->beginTransaction();
        try {
            // Delete existing roles for selected users
            $stDel = $db->prepare("DELETE FROM user_roles WHERE user_id IN ($inClause)");
            $stDel->execute($ids);

            // Insert new role for each user
            $stIns = $db->prepare("INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)");
            foreach ($ids as $uId) {
                $stIns->execute([uuid4(), $uId, $newRole]);
            }

            $db->commit();
            jsonResponse(['ok' => true, 'count' => count($ids)]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Gagal mengubah role massal: ' . $e->getMessage()], 500);
        }
        return;
    }

    $id = $_GET['id'] ?? '';
    if (!$id) {
        jsonResponse(['error' => 'Missing id parameter'], 400);
    }

    $db->beginTransaction();
    try {
        // Update user email/password if provided
        if (!empty($body['password'])) {
            $hash = password_hash($body['password'], PASSWORD_BCRYPT);
            $st = $db->prepare('UPDATE users SET encrypted_password = ? WHERE id = ?');
            $st->execute([$hash, $id]);
        }
        if (!empty($body['email'])) {
            $st = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
            $st->execute([$body['email'], $id]);
        }

        // Update profile
        $allowedProfile = ['full_name', 'instansi', 'bio', 'avatar_url'];
        $sets = [];
        $params = [];
        foreach ($body as $k => $v) {
            if (in_array($k, $allowedProfile)) {
                $sets[] = "`$k` = ?";
                $params[] = $v;
            }
        }
        if (!empty($sets)) {
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
        }

        // Update role if provided
        if (!empty($body['role'])) {
            $newRole = trim($body['role']);
            $validRoles = ['admin', 'reviewer', 'kontributor', 'user'];
            if (in_array($newRole, $validRoles)) {
                // Delete existing roles and set new primary role
                $db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
                $db->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')->execute([uuid4(), $id, $newRole]);
            }
        }

        $db->commit();
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Gagal memperbarui pengguna: ' . $e->getMessage()], 500);
    }
}

function handleDelete(): void {
    checkAdmin();
    $db = getDB();
    $body = jsonBody();

    $ids = [];
    if (!empty($body['ids']) && is_array($body['ids'])) {
        $ids = array_filter(array_map('trim', $body['ids']));
    } else if (!empty($_GET['id'])) {
        $ids = [trim($_GET['id'])];
    }

    if (empty($ids)) {
        jsonResponse(['error' => 'Missing id or ids parameter'], 400);
    }

    $inClause = implode(',', array_fill(0, count($ids), '?'));

    $db->beginTransaction();
    try {
        // 1. Delete user avatar file from disk if uploaded
        $stProfile = $db->prepare("SELECT avatar_url FROM profiles WHERE id IN ($inClause)");
        $stProfile->execute($ids);
        foreach ($stProfile->fetchAll() as $pRow) {
            deleteUploadedFile($pRow['avatar_url'] ?? null);
        }

        // 2. Delete all thumbnails uploaded by these users for their media
        $stMedia = $db->prepare("SELECT thumbnail FROM media WHERE contributor_id IN ($inClause)");
        $stMedia->execute($ids);
        foreach ($stMedia->fetchAll() as $mRow) {
            deleteUploadedFile($mRow['thumbnail'] ?? null);
        }

        // 3. Cascade Delete: Delete media uploaded by these users
        $stDelMedia = $db->prepare("DELETE FROM media WHERE contributor_id IN ($inClause)");
        $stDelMedia->execute($ids);

        // 4. Delete profiles
        $stDelProf = $db->prepare("DELETE FROM profiles WHERE id IN ($inClause)");
        $stDelProf->execute($ids);

        // 5. Delete roles
        $stDelRoles = $db->prepare("DELETE FROM user_roles WHERE user_id IN ($inClause)");
        $stDelRoles->execute($ids);

        // 6. Delete users
        $stDelUsers = $db->prepare("DELETE FROM users WHERE id IN ($inClause)");
        $stDelUsers->execute($ids);

        $db->commit();
        jsonResponse(['ok' => true, 'count' => count($ids)]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Gagal menghapus pengguna: ' . $e->getMessage()], 500);
    }
}
