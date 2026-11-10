<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? $_POST;

if (empty($input['nodes'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No hay estructura de nodos para guardar']);
    exit;
}

$workflowId = !empty($input['id']) ? $input['id'] : \P8P\Core\Uuid::v4();

$name = $input['name'] ?? 'Mi Workflow P8P';
$description = $input['description'] ?? '';
$structure = json_encode([
    'nodes' => $input['nodes'],
    'edges' => $input['edges'] ?? []
]);

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);
    $db->beginTransaction();
    $old = $db->prepare('SELECT structure FROM workflows WHERE id=? FOR UPDATE');
    $old->execute([$workflowId]);
    $previousStructure = $old->fetchColumn();
    if ($previousStructure && \P8P\Core\WorkflowRuntime::hash(json_decode($previousStructure, true, 512, JSON_THROW_ON_ERROR)) !== \P8P\Core\WorkflowRuntime::hash(json_decode($structure, true, 512, JSON_THROW_ON_ERROR))) {
        // Before the additive migration there are no receivers to pause.
        try {
            $db->query('SELECT id FROM trigger_configs LIMIT 0');
            $pause = $db->prepare("UPDATE trigger_configs SET enabled=0,status='paused',version=version+1,lease_token=NULL,lease_until=NULL WHERE workflow_id=?");
            $pause->execute([$workflowId]);
        } catch (\PDOException $e) {
            if (!in_array($e->getCode(), ['42S02', '42P01'], true)) {
                throw $e;
            }
        }
    }

    $stmt = $db->prepare("
        INSERT INTO workflows (id, name, description, structure, is_active, updated_at)
        VALUES (:id, :name, :description, :structure, 1, NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            structure = VALUES(structure),
            updated_at = NOW()
    ");

    $stmt->execute([
        ':id' => $workflowId,
        ':name' => $name,
        ':description' => $description,
        ':structure' => $structure
    ]);
    $db->commit();

    echo json_encode([
        'success' => true,
        'workflow_id' => $workflowId,
        'message' => 'Workflow guardado correctamente en MariaDB'
    ]);
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el workflow. Comprueba la conexión y la estructura.']);
}
