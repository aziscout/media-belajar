<?php
/**
 * User Roles API
 * GET /api/roles.php?user_id=xxx  → get user roles
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = requireAuth();
$userId = $_GET['user_id'] ?? $user['sub'];

$roles = getUserRoles($userId);
jsonResponse($roles);
