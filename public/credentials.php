<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

// Encabezados CORS completos (permite la cabecera Authorization para el Token Bearer)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Manejo de Preflight OPTIONS (Responde antes de la validación del middleware)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\CredentialManager;
use P8P\Core\AuthMiddleware;

// Interceptar y proteger el endpoint con el Token Bearer del Admin
AuthMiddleware::check();

try {
    $cm = new CredentialManager();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'credentials' => $cm->getCredentials()]);
        exit;
    }

    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?? [];

        $name = trim($input['name'] ?? '');
        $type = trim($input['type'] ?? 'gemini_api');
        $data = $input['data'] ?? [];
        $id = $input['id'] ?? null;

        if (empty($name) || empty($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nombre y datos de credencial son requeridos']);
            exit;
        }

        $credentialId = $cm->saveCredential($name, $type, $data, $id);
        echo json_encode([
            'success' => true,
            'id' => $credentialId,
            'message' => 'Credencial guardada y encriptada con éxito'
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID de credencial requerido']);
            exit;
        }

        $cm->deleteCredential($id);
        echo json_encode(['success' => true, 'message' => 'Credencial eliminada correctamente']);
        exit;
    }
} catch (\Throwable $e) {
    // El detalle (rutas, SQL, descifrado) va al log del servidor, no al cliente.
    error_log('P8P credentials.php: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación con credenciales.']);
}
