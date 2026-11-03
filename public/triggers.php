<?php

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\{AuthMiddleware, CredentialManager, Database, TriggerManager};

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}
AuthMiddleware::check();
try {
    $db = Database::getConnection(require __DIR__ . '/../config/database.php');
    $manager = new TriggerManager($db, new CredentialManager($db));
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $workflowId = $_GET['workflow_id'] ?? '';
        $triggers = $manager->list($workflowId);
        $statement = $db->prepare('SELECT is_active FROM workflows WHERE id=?');
        $statement->execute([$workflowId]);
        echo json_encode(['success' => true, 'catalog' => $manager->catalog(), 'triggers' => $triggers, 'workflow_active' => (bool)$statement->fetchColumn()], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new InvalidArgumentException('Petición demasiado grande.');
    }
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Petición inválida.');
    }
    $id = $input['id'] ?? '';
    switch ($input['action'] ?? '') {
        case 'save': $id = $manager->save($input); break;
        case 'activate': $manager->activate($id); break;
        case 'pause': $manager->pause($id); break;
        case 'delete': $manager->delete($id); break;
        case 'setup_secret':
            echo json_encode(['success' => true, 'setup_secret' => $manager->setupSecret($id)], JSON_THROW_ON_ERROR);
            exit;
        case 'workflow_state':
            if (!is_bool($input['enabled'] ?? null)) {
                throw new InvalidArgumentException('Estado inválido.');
            }
            $manager->workflowState($input['workflow_id'] ?? '', $input['enabled']);
            break;
        default: throw new InvalidArgumentException('Operación no permitida.');
    }
    echo json_encode(['success' => true, 'id' => $id]);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof JsonException ? 'JSON inválido.' : $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo administrar el trigger. Comprueba la migración de triggers y la configuración del servidor.']);
}
