<?php

/**
 * Regresión de endurecimiento de login: LoginThrottle, ADMIN_PASS_HASH, compatibilidad con
 * ADMIN_PASS en texto plano, falseo de IP y tools/hash_password.php.
 * Cada petición a public/auth.php corre en un proceso hijo aislado, igual que tests/triggers/auth.php.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use P8P\Core\EnvLoader;
use P8P\Core\LoginThrottle;

$root = dirname(__DIR__, 2);

// ---- modos hijo ----------------------------------------------------------------------------
if (($argv[1] ?? '') === 'login') {
    $cfg = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
    foreach ($cfg['env'] as $name => $value) {
        $_ENV[$name] = $value;
    }
    $_SERVER = $cfg['server'];
    $_POST = $cfg['post'];
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => json_decode($body, true)]);
    });
    require dirname(__DIR__, 2) . '/public/auth.php';
    exit;
}
if (($argv[1] ?? '') === 'record') {
    ini_set('error_log', '/dev/null');
    $throttle = new LoginThrottle($argv[2], 100000, 900);
    for ($i = 0; $i < (int) $argv[4]; $i++) {
        $throttle->recordFailure($argv[3], 5000);
    }
    exit;
}

// ---- utilidades ----------------------------------------------------------------------------
$assertions = 0;

function check(bool $condition, string $label): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

function run(array $args, ?string $stdin = null): array
{
    $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['out' => $out, 'err' => $err, 'code' => proc_close($p)];
}

$dir = sys_get_temp_dir() . '/p8p-auth-test-' . bin2hex(random_bytes(4));
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

$password = 'Correct-Horse-Battery-9';
$hash = password_hash($password, PASSWORD_DEFAULT);
$baseEnv = ['ADMIN_USER' => 'ed', 'ADMIN_SECRET_KEY' => str_repeat('k', 32), 'P8P_THROTTLE_DIR' => $dir . '/state'];

/** @return array{status: int, body: array} */
function login(array $env, ?string $ip, string $user, string $pass, array $extraServer = []): array
{
    $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $extraServer;
    if ($ip !== null) {
        $server['REMOTE_ADDR'] = $ip;
    }
    $r = run([PHP_BINARY, __FILE__, 'login', json_encode(['env' => $env, 'server' => $server, 'post' => ['username' => $user, 'password' => $pass]])]);
    $decoded = json_decode($r['out'], true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "salida inesperada del hijo: {$r['out']} {$r['err']}\n");
        exit(1);
    }
    return $decoded;
}

// ---- 1. LoginThrottle (unidad) -------------------------------------------------------------
ini_set('error_log', '/dev/null');
$t = new LoginThrottle($dir . '/unit', 3, 100);
check($t->retryAfter('ip1', 1000) === 0, 'sin fallos: permitido');
foreach ([1000, 1001, 1002] as $ts) {
    $t->recordFailure('ip1', $ts);
}
check($t->retryAfter('ip1', 1003) === 97, 'tras 3 fallos: espera hasta que salga el más antiguo (1000+100-1003)');
check($t->retryAfter('ip2', 1003) === 0, 'otra clave no se ve afectada');
check($t->retryAfter('ip1', 1101) === 0, 'el fallo más antiguo sale de la ventana: permitido');
$t->reset('ip1');
check($t->retryAfter('ip1', 1003) === 0, 'reset limpia los fallos');
file_put_contents($dir . '/unit/' . hash('sha256', 'corrupto') . '.json', '{no es json');
check($t->retryAfter('corrupto', 1003) === 0, 'estado corrupto se trata como vacío');
$broken = new LoginThrottle('/proc/p8p-no-existe/x', 1, 100);
$broken->recordFailure('x', 1000);
check($broken->retryAfter('x', 1001) === 0, 'directorio inutilizable: falla abierto sin lanzar excepción');

// Carrera: 8 procesos x 5 fallos simultáneos; con flock no se pierde ninguna escritura.
$procs = [];
for ($i = 0; $i < 8; $i++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, 'record', $dir . '/race', 'carrera', '5'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
foreach ($procs as $p) {
    proc_close($p);
}
$stored = json_decode((string) file_get_contents($dir . '/race/' . hash('sha256', 'carrera') . '.json'), true);
check(is_array($stored) && count($stored) === 40, 'concurrencia: 40 de 40 fallos registrados (' . (is_array($stored) ? count($stored) : 'n/a') . ')');

// ---- 2. auth.php con ADMIN_PASS_HASH --------------------------------------------------------
$envHash = $baseEnv + ['ADMIN_PASS_HASH' => $hash];
$r = login($envHash, null, 'ed', $password);
check($r['status'] === 200 && $r['body']['success'] === true && str_starts_with($r['body']['token'], 'p8p1.'), 'hash: login correcto emite token');
check(login($envHash, null, 'ed', 'otra-clave-larga-1')['status'] === 401, 'hash: contraseña incorrecta = 401');
check(login($envHash, null, 'admin', $password)['status'] === 401, 'hash: usuario incorrecto = 401');
$both = $envHash + ['ADMIN_PASS' => 'clave-vieja-en-claro'];
check(login($both, null, 'ed', 'clave-vieja-en-claro')['status'] === 401, 'hash + ADMIN_PASS: gana el hash, la clave en claro ya no entra');
check(login($both, null, 'ed', $password)['status'] === 200, 'hash + ADMIN_PASS: entra con la del hash');

// ---- 3. Compatibilidad: solo ADMIN_PASS en texto plano --------------------------------------
$envPlain = $baseEnv + ['ADMIN_PASS' => 'plain-pass-12345'];
check(login($envPlain, null, 'ed', 'plain-pass-12345')['status'] === 200, 'compat: texto plano sigue funcionando');
check(login($envPlain, null, 'ed', 'incorrecta-123456')['status'] === 401, 'compat: texto plano incorrecto = 401');
check(login($baseEnv, null, 'ed', 'lo-que-sea-12345')['status'] === 503, 'sin ADMIN_PASS ni hash: 503 (sin configurar)');

// ---- 4. Límite de intentos por IP -----------------------------------------------------------
$ipA = '203.0.113.50';
for ($i = 1; $i <= 8; $i++) {
    check(login($envHash, $ipA, 'ed', "mala-{$i}-clave-larga")['status'] === 401, "intento fallido {$i}/8 = 401");
}
$blocked = login($envHash, $ipA, 'ed', $password);
check($blocked['status'] === 429, 'intento 9 con la contraseña CORRECTA también se bloquea (429)');
check(($blocked['body']['retry_after'] ?? 0) > 0 && $blocked['body']['retry_after'] <= 900, 'retry_after dentro de la ventana de 15 min');
check(login($envHash, '203.0.113.51', 'ed', $password)['status'] === 200, 'otra IP no se ve afectada');

$ipB = '203.0.113.60';
for ($i = 1; $i <= 5; $i++) {
    login($envHash, $ipB, 'ed', "mala-{$i}-clave-larga");
}
check(login($envHash, $ipB, 'ed', $password)['status'] === 200, 'login correcto antes del límite');
for ($i = 1; $i <= 7; $i++) {
    check(login($envHash, $ipB, 'ed', "otra-{$i}-clave-larga")['status'] === 401, "tras un éxito el contador se reinicia ({$i}/7 sigue en 401)");
}

for ($i = 1; $i <= 12; $i++) {
    check(login($envHash, null, 'ed', "cli-{$i}-clave-larga")['status'] === 401, "sin REMOTE_ADDR no se aplica límite ({$i}/12)");
}

// ---- 5. Falseo de IP ------------------------------------------------------------------------
$ipC = '203.0.113.70';
for ($i = 1; $i <= 8; $i++) {
    login($envHash, $ipC, 'ed', "spoof-{$i}-clave-larga", ['HTTP_X_FORWARDED_FOR' => "198.51.100.{$i}"]);
}
check(login($envHash, $ipC, 'ed', $password, ['HTTP_X_FORWARDED_FOR' => '198.51.100.99'])['status'] === 429,
    'sin TRUSTED_IP_HEADER, rotar X-Forwarded-For NO evade el límite (se usa REMOTE_ADDR)');

$envProxy = $envHash + ['TRUSTED_IP_HEADER' => 'X-Forwarded-For'];
$proxyIp = '10.9.9.9';
for ($i = 1; $i <= 8; $i++) {
    login($envProxy, $proxyIp, 'ed', "proxy-{$i}-clave-larga", ['HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1']);
}
check(login($envProxy, $proxyIp, 'ed', $password, ['HTTP_X_FORWARDED_FOR' => '198.51.100.7'])['status'] === 429, 'con TRUSTED_IP_HEADER se limita al cliente real tras el proxy');
check(login($envProxy, $proxyIp, 'ed', $password, ['HTTP_X_FORWARDED_FOR' => '198.51.100.8'])['status'] === 200, 'otro cliente tras el mismo proxy no se bloquea');
check(login($envProxy, $proxyIp, 'ed', $password, ['HTTP_X_FORWARDED_FOR' => 'basura-no-ip'])['status'] === 200, 'encabezado no válido: cae a REMOTE_ADDR sin romperse');

// ---- 6. tools/hash_password.php -------------------------------------------------------------
$r = run([PHP_BINARY, $root . '/tools/hash_password.php'], "{$password}\n");
check($r['code'] === 0 && str_starts_with($r['out'], 'ADMIN_PASS_HASH='), 'la herramienta genera la línea del .env');
$line = trim($r['out']);
$generated = substr($line, strlen('ADMIN_PASS_HASH='));
check(password_verify($password, $generated), 'el hash generado verifica la contraseña');
file_put_contents($dir . '/.env', "ADMIN_USER=ed\n{$line}\n");
unset($_ENV['ADMIN_PASS_HASH']);
putenv('ADMIN_PASS_HASH');
EnvLoader::load($dir . '/.env', true);
check(($_ENV['ADMIN_PASS_HASH'] ?? '') === $generated && password_verify($password, $_ENV['ADMIN_PASS_HASH']), 'el hash sobrevive el ciclo por EnvLoader (los $ no se mutilan)');
check(run([PHP_BINARY, $root . '/tools/hash_password.php'], "corta\n")['code'] !== 0, 'la herramienta rechaza contraseñas de menos de 12 caracteres');

echo "PASS auth_hardening: {$assertions} assertions (throttle + concurrencia, hash, compat texto plano, IP falseada, herramienta)\n";
