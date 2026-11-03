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
    $secret = $_ENV['ADMIN_SECRET_KEY'] ?? getenv('ADMIN_SECRET_KEY');
    if (!is_string($envUser) || trim($envUser) === '' || !is_string($envPass) || $envPass === ''
        || !is_string($secret) || trim($secret) === '') {
        throw new \RuntimeException('Autenticación sin configurar.');
    }
    $username = trim($data['username']);
    if (!hash_equals($envUser, $username) || !hash_equals($envPass, $data['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Credenciales de administrador inválidas.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'token' => AuthMiddleware::issueToken($username),
        'user' => ['username' => $username],
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'La autenticación administrativa no está disponible.']);
}
