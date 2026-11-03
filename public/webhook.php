<?php

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\{CredentialManager, Database, TriggerManager, TriggerRetryException};

ini_set('display_errors', '0');
header('Cache-Control: no-store');
if (!is_string($_GET['trigger_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $_GET['trigger_id'])) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Configura un receptor verificado desde Triggers; la invocación pública por workflow ID se ha retirado.']);
    exit;
}
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
        throw new LengthException();
    }
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new LengthException();
    }
    $headers = [];
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }
    $db = Database::getConnection(require __DIR__ . '/../config/database.php');
    $result = (new TriggerManager($db, new CredentialManager($db)))->receive($_GET['trigger_id'], [
        'method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers, 'query' => $_GET, 'raw_body' => $raw]);
    http_response_code($result['status'] ?? 200);
    header('Content-Type: ' . ($result['content_type'] ?? 'text/plain'));
    echo $result['body'] ?? '';
} catch (LengthException $e) {
    http_response_code(413);
} catch (InvalidArgumentException $e) {
    http_response_code(403);
} catch (TriggerRetryException $e) {
    http_response_code(503);
    header('Retry-After: ' . max(60, min(86400, $e->retryAfter)));
} catch (Throwable $e) {
    http_response_code(503);
}
