<?php

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\{Database, QueueManager, WorkflowRuntime};

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new InvalidArgumentException('Petición demasiado grande.');
    }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $workflowId = $data['workflow_id'] ?? '';
    $session = $data['session_id'] ?? 'guest_' . bin2hex(random_bytes(16));
    $message = $data['message'] ?? $data['user_message'] ?? '';
    if (!is_string($workflowId) || strlen($workflowId) > 255 || !is_string($session) || $session === '' || strlen($session) > 255 || !is_string($message) || trim($message) === '' || strlen($message) > 16384) {
        throw new InvalidArgumentException('Workflow, sesión o mensaje inválido.');
    }
    $db = Database::getConnection(require __DIR__ . '/../config/database.php');
    $db->beginTransaction();
    $statement = $db->prepare('SELECT structure FROM workflows WHERE id=? AND is_active=1 FOR UPDATE');
    $statement->execute([$workflowId]);
    $structure = $statement->fetchColumn();
    if (!$structure || WorkflowRuntime::start(json_decode($structure, true, 512, JSON_THROW_ON_ERROR))['type'] !== 'chat_trigger') {
        throw new InvalidArgumentException('El workflow no es un chat público activo.');
    }
    $job = (new QueueManager($db))->enqueue($workflowId, ['session_id' => $session, 'user_message' => $message]);
    $db->commit();
    http_response_code(202);
    echo json_encode(['success' => true, 'job_id' => $job, 'status' => 'pending']);
} catch (InvalidArgumentException | JsonException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se pudo admitir el mensaje: comprueba el chat y los campos.']);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'La cola no está disponible.']);
}
