<?php
/**
 * Auth API — Register, Login, Session, Logout
 * POST /api/auth.php?action=register
 * POST /api/auth.php?action=login
 * GET  /api/auth.php?action=session
 * POST /api/auth.php?action=signout  (placeholder)
 */
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'session':
        handleSession();
        break;
    case 'change_password':
        handleChangePassword();
        break;
    case 'signout':
        // Client-side only (remove token from localStorage)
        jsonResponse(['ok' => true]);
        break;
    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}

function handleRegister(): void {
    $body = jsonBody();
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';
    $fullName = trim($body['full_name'] ?? '');
    $instansi = trim($body['instansi'] ?? '');

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email dan password wajib diisi'], 400);
    }
    if (strlen($password) < 8) {
        jsonResponse(['error' => 'Password minimal 8 karakter'], 400);
    }

    $db = getDB();

    // Check duplicate
    $st = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        jsonResponse(['error' => 'Email sudah terdaftar'], 409);
    }

    $userId = uuid4();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $name = $fullName ?: explode('@', $email)[0];

    $db->beginTransaction();
    try {
        // Insert user
        $db->prepare('INSERT INTO users (id, email, encrypted_password, full_name, email_confirmed_at) VALUES (?, ?, ?, ?, NOW())')
           ->execute([$userId, $email, $hash, $name]);

        // Insert profile
        $db->prepare('INSERT INTO profiles (id, full_name, instansi) VALUES (?, ?, ?)')
           ->execute([$userId, $name, $instansi ?: null]);

        // Default role: pending (requires Admin approval in Pengguna Review)
        $db->prepare('INSERT INTO user_roles (id, user_id, role) VALUES (?, ?, ?)')
           ->execute([uuid4(), $userId, 'pending']);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Gagal mendaftarkan akun: ' . $e->getMessage()], 500);
    }

    // Do NOT issue JWT token automatically for pending registrations
    jsonResponse([
        'ok'      => true,
        'pending' => true,
        'message' => 'Pendaftaran akun berhasil dikirim! Akun Anda saat ini sedang dalam proses peninjauan oleh Admin BTIKP Sultra.',
    ]);
}

function handleLogin(): void {
    $body = jsonBody();
    $email    = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email dan password wajib diisi'], 400);
    }

    $db = getDB();
    $st = $db->prepare('SELECT id, email, encrypted_password, full_name FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['encrypted_password'])) {
        jsonResponse(['error' => 'Email atau password salah'], 401);
    }

    // Check if account is still pending admin approval
    $roles = getUserRoles($user['id']);
    $approvedRoles = ['admin', 'reviewer', 'kontributor', 'user'];
    $hasApprovedRole = false;
    foreach ($roles as $r) {
        if (in_array($r, $approvedRoles)) {
            $hasApprovedRole = true;
            break;
        }
    }

    if (!$hasApprovedRole) {
        jsonResponse([
            'error' => 'Akun Anda masih dalam proses peninjauan oleh Admin BTIKP Sulawesi Tenggara. Silakan tunggu hingga akun Anda disetujui.',
            'pending' => true,
        ], 403);
    }

    $token = jwt_encode([
        'sub'   => $user['id'],
        'email' => $user['email'],
    ]);

    jsonResponse([
        'session' => [
            'access_token' => $token,
            'user' => [
                'id'    => $user['id'],
                'email' => $user['email'],
            ],
        ],
        'user' => [
            'id'    => $user['id'],
            'email' => $user['email'],
        ],
    ]);
}

function handleSession(): void {
    $payload = getCurrentUser();
    if (!$payload) {
        jsonResponse(['session' => null, 'user' => null]);
        return;
    }

    $db = getDB();
    $st = $db->prepare('SELECT id, email, full_name FROM users WHERE id = ? LIMIT 1');
    $st->execute([$payload['sub']]);
    $user = $st->fetch();

    if (!$user) {
        jsonResponse(['session' => null, 'user' => null]);
        return;
    }

    jsonResponse([
        'session' => ['user' => $user],
        'user'    => $user,
    ]);
}

function handleChangePassword(): void {
    $user = requireAuth();
    $uid = $user['sub'];
    $body = jsonBody();

    $newPassword = $body['password'] ?? '';
    if (!$newPassword || strlen($newPassword) < 8) {
        jsonResponse(['error' => 'Kata sandi baru minimal 8 karakter'], 400);
    }

    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $db = getDB();
    $stmt = $db->prepare('UPDATE users SET encrypted_password = :hash WHERE id = :id');
    $stmt->execute([':hash' => $hash, ':id' => $uid]);

    jsonResponse(['success' => true]);
}
