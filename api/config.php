<?php
/**
 * Database Configuration & Helpers
 * Media Belajar BTIKP Sultra — PHP REST API
 */

// ── Helper to get env variable safely (supports $_ENV, $_SERVER, and getenv for Hostinger PHP-FPM) ──
function getEnvVar(string $name, string $default = ''): string {
    if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
        return $_ENV[$name];
    }
    if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
        return $_SERVER[$name];
    }
    $val = @getenv($name);
    if ($val !== false && $val !== '') {
        return (string) $val;
    }
    return $default;
}

// ── Auto Load .env File ───────────────────────────────────────────────
function loadEnvFile(string $filePath): void {
    if (!file_exists($filePath) || !is_readable($filePath)) return;
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
                $value = $m[1];
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            @putenv("$name=$value");
        }
    }
}

// Load .env file from root directory or api directory
loadEnvFile(dirname(__DIR__) . '/.env');
loadEnvFile(__DIR__ . '/.env');

// ── Database credentials (Murni dimuat dari berkas .env) ─────────────
define('DB_HOST', getEnvVar('DB_HOST', 'localhost'));
define('DB_PORT', getEnvVar('DB_PORT', '3306'));
define('DB_NAME', getEnvVar('DB_NAME', ''));
define('DB_USER', getEnvVar('DB_USER', ''));
define('DB_PASS', getEnvVar('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ── JWT secret (Murni dimuat dari berkas .env) ───────────────────────
define('JWT_SECRET', getEnvVar('JWT_SECRET', 'change-this-secret-in-env'));
define('JWT_EXPIRY', 60 * 60 * 24 * 7); // 7 days

// ── Turnstile Setting (true/false) ──────────────────────────────────
$turnstileRaw = strtolower(trim(getEnvVar('TURNSTILE_ENABLED', getEnvVar('VITE_TURNSTILE_ENABLED', 'true'))));
define('TURNSTILE_ENABLED', !in_array($turnstileRaw, ['false', '0', 'off', 'no'], true));

// ── CORS ─────────────────────────────────────────────────────────────
// ── Ensure Clean JSON Output & Error Handling ────────────────────────
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status'  => 'error',
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Logger Helper for Database Errors ────────────────────────────────
function logDbError(string $message, array $context = []): void {
    $logFile = __DIR__ . '/db_error.log';
    $time = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $uri = $_SERVER['REQUEST_URI'] ?? 'CLI';
    
    $ctxStr = !empty($context) ? ' | Data: ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $logLine = "[$time] [IP: $ip] [URI: $uri] $message$ctxStr" . PHP_EOL;
    
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    error_log("MEDIA_BELAJAR_DB_ERROR: $message$ctxStr");
}

// ── PDO Connection ───────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = DB_HOST;
        $port = DB_PORT;
        $dbname = DB_NAME;
        $user = DB_USER;
        $pass = DB_PASS;
        $charset = DB_CHARSET;

        if (empty($dbname) || empty($user)) {
            $msg = "DB_NAME atau DB_USER masih kosong di berkas api/.env! (DB_NAME: '$dbname', DB_USER: '$user')";
            logDbError($msg);
            
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'error'   => 'Konfigurasi database belum diatur di server hosting.',
                'detail'  => $msg,
                'log'     => 'Catatan error telah ditulis ke api/db_error.log',
                'current' => [
                    'host' => $host,
                    'database' => $dbname,
                    'user' => $user
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            $errText = "Koneksi PDO Gagal: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
            logDbError($errText, [
                'host' => $host,
                'port' => $port,
                'database' => $dbname,
                'user' => $user
            ]);

            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'error'   => 'Gagal terhubung ke database MySQL di server hosting.',
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'log'     => 'Catatan error telah ditulis ke berkas: api/db_error.log',
                'hint'    => 'Silakan periksa berkas api/.env di File Manager Hostinger. Pastikan DB_HOST, DB_NAME, DB_USER, dan DB_PASS sesuai dengan database yang Anda buat di hPanel.',
                'current' => [
                    'host' => $host,
                    'database' => $dbname,
                    'user' => $user
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}

// ── UUID v4 Generator ────────────────────────────────────────────────
function uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Simple JWT (HMAC-SHA256, no library needed) ──────────────────────
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode(array $payload): string {
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;
    $body = base64url_encode(json_encode($payload));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

// ── Get current user from Authorization header ──────────────────────
function getCurrentUser(): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return jwt_decode($m[1]);
    }
    return null;
}

function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    return $user;
}

// ── Check role helper ────────────────────────────────────────────────
function userHasRole(string $userId, string $role): bool {
    $db = getDB();
    $st = $db->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role = ? LIMIT 1');
    $st->execute([$userId, $role]);
    return (bool) $st->fetchColumn();
}

function getUserRoles(string $userId): array {
    $db = getDB();
    $st = $db->prepare('SELECT role FROM user_roles WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// ── JSON body helper ─────────────────────────────────────────────────
function jsonBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── JSON response helper ─────────────────────────────────────────────
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── File Storage Helper ─────────────────────────────────────────────
function deleteUploadedFile(?string $url): bool {
    if (!$url) return false;
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path || !preg_match('#^/?uploads/(avatars|media-thumbnails)/#', $path)) {
        return false;
    }

    $relative = ltrim($path, '/');
    $fullPath = dirname(__DIR__) . '/' . $relative;

    if (file_exists($fullPath) && is_file($fullPath)) {
        return @unlink($fullPath);
    }
    return false;
}
