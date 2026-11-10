<?php

ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\AuthMiddleware;
use P8P\Core\EnvLoader;
use P8P\Core\LoginThrottle;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
        exit;
    }
    $rawInput = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($rawInput) || strlen($rawInput) > 16384 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Solicitud de autenticación demasiado grande.']);
        exit;
    }
    if (str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        try {
            $data = json_decode($rawInput, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $data = null;
        }
    } else {
        $data = $_POST;
    }
    if (!is_array($data) || !is_string($data['username'] ?? null) || !is_string($data['password'] ?? null)
        || trim($data['username']) === '' || $data['password'] === '' || strlen($data['username']) > 255 || strlen($data['password']) > 4096) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuario y contraseña son requeridos.']);
        exit;
    }

    EnvLoader::load(__DIR__ . '/../.env', false);
    $envUser = $_ENV['ADMIN_USER'] ?? getenv('ADMIN_USER');
    $envPass = $_ENV['ADMIN_PASS'] ?? getenv('ADMIN_PASS');
    // ADMIN_PASS_HASH (password_hash) tiene prioridad sobre ADMIN_PASS en texto plano.
    $envHash = $_ENV['ADMIN_PASS_HASH'] ?? getenv('ADMIN_PASS_HASH');
    $secret = $_ENV['ADMIN_SECRET_KEY'] ?? getenv('ADMIN_SECRET_KEY');
    $hasHash = is_string($envHash) && $envHash !== '';
    $hasPlain = is_string($envPass) && $envPass !== '';
    if (!is_string($envUser) || trim($envUser) === '' || (!$hasHash && !$hasPlain)
        || !is_string($secret) || trim($secret) === '') {
        throw new \RuntimeException('Autenticación sin configurar.');
    }

    // Límite de intentos fallidos por IP. Sin REMOTE_ADDR (CLI, pruebas) no se aplica.
    // TRUSTED_IP_HEADER (p. ej. CF-Connecting-IP) solo debe definirse si PHP queda detrás de un
    // proxy propio que fija ese encabezado; de lo contrario cualquiera podría falsearlo.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $trustedHeader = $_ENV['TRUSTED_IP_HEADER'] ?? getenv('TRUSTED_IP_HEADER');
    if (is_string($trustedHeader) && preg_match('/^[A-Za-z0-9-]{1,64}$/D', $trustedHeader)) {
        $forwarded = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $trustedHeader))] ?? null;
        $first = is_string($forwarded) ? trim(explode(',', $forwarded)[0]) : '';
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            $clientIp = $first;
        }
    }
    $throttle = is_string($clientIp) && $clientIp !== '' ? new LoginThrottle(LoginThrottle::defaultDir()) : null;
    if ($throttle !== null) {
        $wait = $throttle->retryAfter($clientIp);
        if ($wait > 0) {
            http_response_code(429);
            header('Retry-After: ' . $wait);
            echo json_encode(['success' => false, 'error' => 'Demasiados intentos fallidos. Intenta de nuevo más tarde.', 'retry_after' => $wait]);
            exit;
        }
    }

    $username = trim($data['username']);
    // Ambas comprobaciones se evalúan siempre, para no revelar por tiempo cuál falló.
    $userOk = hash_equals($envUser, $username);
    $passOk = $hasHash ? password_verify($data['password'], $envHash) : hash_equals($envPass, $data['password']);
    if (!($userOk && $passOk)) {
        $throttle?->recordFailure($clientIp);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales de administrador inválidas.']);
        exit;
    }
    $throttle?->reset($clientIp);

    echo json_encode([
        'success' => true,
        'token' => AuthMiddleware::issueToken($username),
        'user' => ['username' => $username],
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'La autenticación administrativa no está disponible.']);
}
