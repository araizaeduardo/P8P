<?php

/**
 * Regresión SSRF: UrlGuard + HttpRequestNode.
 * DNS inyectado y respuestas simuladas (MockHandler); la última sección usa un servidor
 * local real en 127.0.0.1 para comprobar el vector que antes funcionaba de verdad.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use P8P\Core\UrlGuard;
use P8P\Nodes\Core\HttpRequestNode;

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

function throws(callable $fn, string $class, string $label): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, $label . ' (lanzó ' . get_class($e) . ': ' . $e->getMessage() . ')');
        return $e;
    }
    check(false, $label . ' (no lanzó nada)');
    return null;
}

$dns = [
    'public.example.org' => ['93.184.216.34'],
    'v6only.example.org' => ['2606:2800:220:1:248:1893:25c8:1946'],
    'rebind.example.org' => ['93.184.216.34', '10.0.0.5'],      // mezcla pública + privada
    'internal.example.org' => ['192.168.86.75'],
    'meta.example.org' => ['169.254.169.254'],
    'loop.example.org' => ['127.0.0.1'],
    'cgnat.example.org' => ['100.64.0.1'],
    'mapped.example.org' => ['::ffff:127.0.0.1'],
    'nat64.example.org' => ['64:ff9b::7f00:1'],
    'ula.example.org' => ['fd00::1'],
    'empty.example.org' => [],
];
$guard = new UrlGuard(static fn (string $host): array => $dns[$host] ?? []);

// ---- 1. URLs rechazadas por forma o por literal ---------------------------------------------
$rejected = [
    'http://127.0.0.1/', 'http://127.0.0.1:3306/', 'http://[::1]/', 'http://10.1.2.3/', 'http://172.16.0.9/',
    'http://192.168.86.1/', 'http://169.254.169.254/latest/meta-data/', 'http://100.64.0.1/', 'http://0.0.0.0/',
    'http://[::ffff:127.0.0.1]/', 'http://[fd00::1]/', 'http://[fe80::1]/',
    'http://2130706433/', 'http://0x7f.0.0.1/', 'http://127.1/', 'http://017700000001/',
    'http://localhost/', 'http://intranet/', 'http://printer.local/', 'http://x.internal/', 'http://nas.lan/', 'http://home.arpa/',
    'file:///etc/passwd', 'gopher://public.example.org/', 'ftp://public.example.org/', 'javascript:alert(1)',
    '//public.example.org/', '', ' http://public.example.org/', "http://public.example.org/\r\nHost: x",
    'http://user:pw@public.example.org/', 'http://public.example.org\\@127.0.0.1/',
    'http://public.example.org:0/', 'http://public.example.org:99999/',
    'http://' . str_repeat('a', 300) . '.org/', 'http://public.example.org/' . str_repeat('a', 2100),
];
foreach ($rejected as $url) {
    throws(fn () => $guard->check($url), InvalidArgumentException::class, 'rechaza ' . json_encode(substr($url, 0, 60)));
}

// ---- 2. Rechazo por lo que resuelve el DNS ----------------------------------------------------
foreach (['internal', 'meta', 'loop', 'cgnat', 'mapped', 'nat64', 'ula', 'rebind'] as $name) {
    throws(fn () => $guard->check("https://{$name}.example.org/"), InvalidArgumentException::class, "DNS privado: {$name}");
}
throws(fn () => $guard->check('https://empty.example.org/'), RuntimeException::class, 'sin registros DNS');
throws(fn () => $guard->check('https://unknown.example.org/'), RuntimeException::class, 'host que no resuelve');

// ---- 3. Aceptadas, con la IP fijada ----------------------------------------------------------
$t = $guard->check('http://public.example.org/path?x=1');
check($t['ip'] === '93.184.216.34' && $t['port'] === 80 && $t['resolve'] === 'public.example.org:80:93.184.216.34', 'http público fija IP y puerto 80');
$t = $guard->check('https://public.example.org:8443/x');
check($t['resolve'] === 'public.example.org:8443:93.184.216.34', 'puerto explícito en CURLOPT_RESOLVE');
$t = $guard->check('https://v6only.example.org/');
check($t['resolve'] === 'v6only.example.org:443:[2606:2800:220:1:248:1893:25c8:1946]', 'IPv6 entre corchetes');
$t = $guard->check('http://93.184.216.34/');
check($t['resolve'] === null && $t['ip'] === '93.184.216.34', 'literal IPv4 público sin resolve');
$t = $guard->check('http://[2606:4700:4700::1111]/');
check($t['resolve'] === null, 'literal IPv6 público aceptado');
$t = $guard->check('HTTPS://PUBLIC.EXAMPLE.ORG/');
check($t['host'] === 'public.example.org' && $t['scheme'] === 'https', 'esquema y host normalizados');

// ---- 4. HttpRequestNode con respuestas simuladas ---------------------------------------------
/** @return array{0: HttpRequestNode, 1: MockHandler} */
function node(array $queue, UrlGuard $guard): array
{
    $mock = new MockHandler($queue);
    return [new HttpRequestNode($guard, new Client(['handler' => HandlerStack::create($mock)])), $mock];
}

// 4a. Un destino bloqueado no genera NINGUNA petición.
foreach (['http://127.0.0.1/install.php', 'http://169.254.169.254/', 'https://internal.example.org/'] as $url) {
    [$n, $mock] = node([new Response(200, [], 'no debería leerse')], $guard);
    throws(fn () => $n->execute(['url' => $url, 'method' => 'GET'], []), InvalidArgumentException::class, "nodo bloquea {$url}");
    check($mock->count() === 1, "no se envió petición a {$url}");
}
[$n, $mock] = node([new Response(200)], $guard);
throws(fn () => $n->execute(['url' => 'https://public.example.org/', 'method' => 'TRACE'], []), InvalidArgumentException::class, 'método no permitido');
throws(fn () => $n->execute(['url' => ['x'], 'method' => 'GET'], []), InvalidArgumentException::class, 'URL no es string');

// 4b. Destino público: opciones de seguridad presentes en la petición real.
[$n, $mock] = node([new Response(200, ['Content-Type' => 'application/json'], '{"a":1}')], $guard);
$r = $n->execute(['url' => 'https://public.example.org/api?x=1', 'method' => 'GET'], []);
check($r['success'] === true && $r['status_code'] === 200 && $r['data'] === ['a' => 1], 'respuesta JSON decodificada');
$o = $mock->getLastOptions();
check(($o['curl'][CURLOPT_RESOLVE] ?? null) === ['public.example.org:443:93.184.216.34'], 'conexión fijada a la IP validada');
check(($o['curl'][CURLOPT_PROTOCOLS] ?? 0) === (CURLPROTO_HTTP | CURLPROTO_HTTPS), 'curl limitado a http/https');
check($o['allow_redirects'] === false && $o['proxy'] === '' && $o['verify'] === true, 'sin redirects, sin proxy, TLS verificado');

// 4c. Un 302 hacia metadata NO se sigue (antes se seguía).
[$n, $mock] = node([new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/'], '')], $guard);
$r = $n->execute(['url' => 'https://public.example.org/r', 'method' => 'GET'], []);
check($r['status_code'] === 302 && $r['location'] === 'http://169.254.169.254/latest/meta-data/' && $mock->count() === 0, 'redirect devuelto, no seguido');

// 4d. Errores HTTP: cuerpo acotado.
[$n, $mock] = node([new Response(404, [], str_repeat('x', 5000))], $guard);
$r = $n->execute(['url' => 'https://public.example.org/missing', 'method' => 'GET'], []);
check($r['success'] === false && $r['status_code'] === 404 && strlen($r['error']) === 1024, 'cuerpo de error acotado a 1 KiB');

// 4e. Límite de tamaño de respuesta.
[$n] = node([new Response(200, [], str_repeat('a', 1048576))], $guard);
$r = $n->execute(['url' => 'https://public.example.org/exacto', 'method' => 'GET'], []);
check($r['success'] === true && strlen($r['data']) === 1048576, 'respuesta de exactamente 1 MiB aceptada');
[$n] = node([new Response(200, [], str_repeat('a', 1048577))], $guard);
$e = throws(fn () => $n->execute(['url' => 'https://public.example.org/grande', 'method' => 'GET'], []), RuntimeException::class, 'respuesta > 1 MiB rechazada');
check(str_contains($e->getMessage(), 'límite'), 'mensaje de límite');

// 4f. Fallo de transporte: el mensaje no filtra la URL ni el token de la query.
[$n] = node([new ConnectException('cURL error 6: https://public.example.org/?token=SECRET', new Request('GET', 'https://public.example.org/?token=SECRET'))], $guard);
$e = throws(fn () => $n->execute(['url' => 'https://public.example.org/?token=SECRET', 'method' => 'GET'], []), RuntimeException::class, 'fallo de transporte');
check(!str_contains($e->getMessage(), 'SECRET') && str_contains($e->getMessage(), 'ConnectException'), 'sin secretos en el mensaje de error');

// 4g. Compatibilidad: headers y body JSON siguen funcionando.
[$n, $mock] = node([new Response(201, [], '{"ok":true}')], $guard);
$r = $n->execute([
    'url' => 'https://public.example.org/crear', 'method' => 'POST',
    'headers' => '{"X-Test":"valor"}', 'body' => '{"k":"v"}',
], []);
$req = $mock->getLastRequest();
check($r['success'] === true && $r['status_code'] === 201 && $r['data'] === ['ok' => true], 'POST 201 correcto');
check((string) $req->getBody() === '{"k":"v"}' && $req->getHeaderLine('X-Test') === 'valor', 'headers y body JSON se envían');

// ---- 5. Extremo a extremo REAL contra un servidor en 127.0.0.1 ------------------------------
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$work = sys_get_temp_dir() . '/p8p-ssrf-' . bin2hex(random_bytes(4));
mkdir($work);
file_put_contents($work . '/router.php', '<?php file_put_contents(' . var_export($work . '/hit', true) . ', "1", FILE_APPEND); echo "SECRETO-INTERNO";');
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $work . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(50000);
}
$real = new HttpRequestNode(); // guard real, cliente real (curl), sin inyecciones
foreach (["http://127.0.0.1:{$port}/", "http://localhost:{$port}/", "http://[::1]:{$port}/"] as $url) {
    throws(fn () => $real->execute(['url' => $url, 'method' => 'GET'], []), Throwable::class, "real: bloquea {$url}");
}
check(!file_exists($work . '/hit'), 'el servidor interno NO recibió ninguna petición');
check(@file_get_contents("http://127.0.0.1:{$port}/") === 'SECRETO-INTERNO' && file_exists($work . '/hit'), 'control positivo: el servidor sí era alcanzable');
proc_terminate($server);
proc_close($server);
array_map('unlink', glob($work . '/*'));
rmdir($work);

echo "PASS ssrf: {$assertions} assertions (guard, nodo con mocks, y servidor local real)\n";
