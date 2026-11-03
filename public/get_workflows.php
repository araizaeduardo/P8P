<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;
use P8P\Core\AuthMiddleware;

// Interceptar y proteger el endpoint
AuthMiddleware::check();

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    $workflowId = $_GET['id'] ?? null;

    if ($workflowId) {
        // Consultar un workflow específico
        $stmt = $db->prepare("SELECT id, name, description, structure, is_active, created_at, updated_at FROM workflows WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $workflowId]);
        $workflow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($workflow) {
            $workflow['structure'] = json_decode($workflow['structure'], true);
            echo json_encode([
                'success' => true,
                'workflow' => $workflow
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Workflow no encontrado']);
        }
    } else {
        // Listar los últimos 50 workflows creados
        $stmt = $db->query("SELECT id, name, description, is_active, created_at, updated_at FROM workflows ORDER BY updated_at DESC LIMIT 50");
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'workflows' => $workflows
        ]);
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en base de datos: ' . $e->getMessage()]);
}
