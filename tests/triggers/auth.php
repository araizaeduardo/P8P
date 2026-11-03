<?php

/** Offline signature/clock/header/endpoint regression tests; no real credentials or HTTP server. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use P8P\Core\AuthMiddleware;

if (($argv[1] ?? '') === 'fixture') {
    $fixture = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
    $_ENV['ADMIN_USER'] = 'fixture_admin';
    $_ENV['ADMIN_PASS'] = 'FIXTURE_PASSWORD';
    $_ENV['ADMIN_SECRET_KEY'] = $fixture['secret'] ?? 'FIXTURE_HMAC_SECRET_32_CHARACTERS';
    $_SERVER = $fixture['server'] ?? [];
    $_POST = $fixture['post'] ?? [];
    if (isset($fixture['headers'])) {
        $GLOBALS['fixture_headers'] = $fixture['headers'];
        // Guzzle already installs a global getallheaders polyfill in CLI. Shadow it only
        // in the middleware namespace to exercise actual case/duplicate-header handling.
        eval('namespace P8P\\Core; function getallheaders(): array { return $GLOBALS["fixture_headers"]; }');
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body]);
    });
    if (($fixture['kind'] ?? '') === 'login') {
        require dirname(__DIR__, 2) . '/public/auth.php';
    } else {
        AuthMiddleware::check();
        echo json_encode(['allowed' => true]);
    }
    exit;
}

$assertions = 0;
$now = strtotime('2026-10-25T12:00:00Z');
$_ENV['ADMIN_USER'] = 'fixture_admin';
$_ENV['ADMIN_PASS'] = 'FIXTURE_PASSWORD';
$_ENV['ADMIN_SECRET_KEY'] = 'FIXTURE_HMAC_SECRET_32_CHARACTERS';

function check(bool $condition, string $label): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function signed(array $payload): string
{
    $encode = static fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $body = 'p8p1.' . $encode(json_encode($payload, JSON_THROW_ON_ERROR));
    return $body . '.' . $encode(hash_hmac('sha256', $body, $_ENV['ADMIN_SECRET_KEY'], true));
}

function fixture(array $config): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'fixture', json_encode($config, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot create isolated CLI fixture');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    check($code === 0 && $error === '', 'Fixture clean execution: ' . $error);
    $result = json_decode($out, true, 16, JSON_THROW_ON_ERROR);
    check(!str_contains($result['body'], 'FIXTURE_HMAC_SECRET') && !str_contains($result['body'], 'FIXTURE_PASSWORD'), 'No secrets in public output');
    return $result;
}

$token = AuthMiddleware::issueToken('fixture_admin', $now);
check(AuthMiddleware::validateToken($token, $now), 'Issued token valid at issuance');
check(AuthMiddleware::validateToken($token, $now + 28799), 'Valid before eight hours');
check(!AuthMiddleware::validateToken($token, $now + 28800), 'Expires exactly at eight hours');
check(!AuthMiddleware::validateToken($token, $now - 1), 'Reject future issue time');
check($token !== AuthMiddleware::issueToken('fixture_admin', $now), 'Random session identity');
check(!str_contains($token, $_ENV['ADMIN_SECRET_KEY']) && !str_contains($token, $_ENV['ADMIN_PASS']), 'Token does not contain secrets');
foreach (['arbitrary-bearer', str_repeat('a', 64), '', 'p8p1.bad.bad', $token . '.suffix', 'Bearer ' . $token, str_repeat('a', 4097), substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A')] as $bad) {
    check(!AuthMiddleware::validateToken($bad, $now), 'Reject malformed or tampered bearer');
}
$valid = ['sub' => 'fixture_admin', 'iat' => $now, 'exp' => $now + 28800, 'jti' => str_repeat('a', 32)];
foreach ([['sub' => 'other_admin'], ['sub' => 7], ['iat' => (string) $now], ['iat' => $now + 1], ['exp' => $now], ['exp' => $now + 28801], ['exp' => (float) ($now + 28800)], ['jti' => ''], ['jti' => str_repeat('Z', 32)], ['extra' => 'unsupported']] as $mutation) {
    // Preserve float type in JSON for the explicit float case.
    $payload = array_replace($valid, $mutation);
    if (isset($mutation['exp']) && is_float($mutation['exp'])) {
        $payload['exp'] = $now + 28800.5;
    }
    check(!AuthMiddleware::validateToken(signed($payload), $now), 'Reject signed invalid claims');
}
foreach (['sub', 'iat', 'exp', 'jti'] as $missing) {
    $payload = $valid;
    unset($payload[$missing]);
    check(!AuthMiddleware::validateToken(signed($payload), $now), 'Reject absent claim ' . $missing);
}
$_ENV['ADMIN_SECRET_KEY'] = 'ROTATED_FIXTURE_KEY';
check(!AuthMiddleware::validateToken($token, $now), 'Key rotation invalidates old tokens');
$_ENV['ADMIN_SECRET_KEY'] = '';
check(!AuthMiddleware::validateToken($token, $now), 'No empty key fallback');
try {
    AuthMiddleware::issueToken('fixture_admin', $now);
    throw new RuntimeException('Expected empty key failure');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'La autenticación administrativa no está configurada.', 'Issue fails closed on missing key');
}
$_ENV['ADMIN_SECRET_KEY'] = 'FIXTURE_HMAC_SECRET_32_CHARACTERS';
$_ENV['ADMIN_USER'] = '';
check(!AuthMiddleware::validateToken($token, $now), 'No default administrator fallback');
$_ENV['ADMIN_USER'] = 'fixture_admin';
putenv('ADMIN_SECRET_KEY=DIFFERENT_PROCESS_VALUE');
check(AuthMiddleware::validateToken($token, $now), 'Existing $_ENV takes priority, EnvLoader does not overwrite');
putenv('ADMIN_SECRET_KEY');

$liveToken = AuthMiddleware::issueToken('fixture_admin');
foreach ([['server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $liveToken]], ['server' => ['REDIRECT_HTTP_AUTHORIZATION' => 'bearer ' . $liveToken]], ['headers' => ['aUtHoRiZaTiOn' => 'BEARER ' . $liveToken]]] as $configuration) {
    $result = fixture($configuration);
    check($result['status'] === 200 && json_decode($result['body'], true)['allowed'] === true, 'Accept signed Bearer in supported header variants: ' . implode(',', array_keys($configuration)) . json_encode($result));
}
foreach ([[], ['server' => ['HTTP_AUTHORIZATION' => 'Bearer arbitrary']], ['server' => ['HTTP_AUTHORIZATION' => 'Prefix Bearer ' . $liveToken]], ['server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $liveToken . ', duplicate']], ['server' => ['HTTP_AUTHORIZATION' => "Bearer " . $liveToken . "\n"]], ['headers' => ['Authorization' => 'Bearer ' . $liveToken, 'authorization' => 'Bearer ' . $liveToken]], ['headers' => ['Authorization' => 'Bearer ' . $liveToken], 'server' => ['HTTP_AUTHORIZATION' => 'Bearer different']], ['server' => ['HTTP_AUTHORIZATION' => 'Bearer ' . $liveToken], 'secret' => '']] as $configuration) {
    $result = fixture($configuration);
    check($result['status'] === 401 && json_decode($result['body'], true)['success'] === false, 'Reject missing, malformed, ambiguous, or invalid session');
}

$login = ['kind' => 'login', 'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'post' => ['username' => 'fixture_admin', 'password' => 'FIXTURE_PASSWORD']];
$result = fixture($login);
$body = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
check($result['status'] === 200 && $body['success'] && $body['user']['username'] === 'fixture_admin', 'Login contract preserved');
check(AuthMiddleware::validateToken($body['token']), 'Login returns usable signed expiring token');
$badLogin = $login;
$badLogin['post']['password'] = 'wrong';
$result = fixture($badLogin);
check($result['status'] === 401 && json_decode($result['body'], true)['success'] === false, 'Wrong login credentials return 401');
$badLogin['post']['password'] = [];
$result = fixture($badLogin);
check($result['status'] === 400, 'Array password rejected safely');
$badLogin = $login;
$badLogin['secret'] = '';
$result = fixture($badLogin);
check($result['status'] === 503 && json_decode($result['body'], true)['success'] === false, 'Unconfigured signing secret fails closed');
$badLogin = $login;
$badLogin['server']['REQUEST_METHOD'] = 'GET';
check(fixture($badLogin)['status'] === 405, 'Only POST authenticates');
$badLogin['server']['REQUEST_METHOD'] = 'OPTIONS';
check(fixture($badLogin)['status'] === 200, 'OPTIONS preflight supported');
$badLogin = $login;
$badLogin['server']['CONTENT_LENGTH'] = '20000';
check(fixture($badLogin)['status'] === 413, 'Oversize credentials request rejected');
$badLogin = $login;
$badLogin['server']['CONTENT_TYPE'] = 'application/json';
check(fixture($badLogin)['status'] === 400, 'Malformed JSON does not fall back to form credentials');

echo "Administrator auth: {$assertions} assertions passed (offline signatures, clock, isolated CLI endpoint fixtures).\n";
