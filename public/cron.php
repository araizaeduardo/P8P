<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\AuthMiddleware;
use P8P\Core\CredentialManager;
use P8P\Core\Database;
use P8P\Core\Engine;
use P8P\Core\EnvLoader;
use P8P\Core\NodeRegistry;
use P8P\Core\QueueManager;
use P8P\Core\TriggerManager;
use P8P\Core\WorkflowWorker;

EnvLoader::load(__DIR__ . '/../.env', false);
if (PHP_SAPI !== 'cli') {
    AuthMiddleware::check();
    header('Content-Type: application/json');
}
try {
    $db = Database::getConnection(require __DIR__ . '/../config/database.php');
    $credentials = new CredentialManager($db);
    $queue = new QueueManager($db);
    $engine = new Engine();
    (new NodeRegistry($engine))->autoDiscover();
    $polling = (new TriggerManager($db, $credentials))->pollDue(5);
    $jobs = (new WorkflowWorker($db, $queue, $credentials, $engine))->run(5);
    $result = ['success' => true, 'polling' => $polling, 'jobs' => $jobs];
    echo json_encode($result, JSON_THROW_ON_ERROR) . (PHP_SAPI === 'cli' ? "\n" : '');
} catch (Throwable $e) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'error' => 'No se pudo completar el worker. Revisa configuración y migraciones.']);
    if (PHP_SAPI === 'cli') {
        echo "\n";
        exit(1);
    }
}
