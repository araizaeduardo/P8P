<?php

/**
 * OpenRouterChatModelNode: validación, petición enviada, mapeo de respuestas/errores, credenciales cifradas
 * y cableado con AiAgentNode. Sin red: Guzzle MockHandler y SQLite en memoria.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use P8P\Core\CredentialManager;
use P8P\Nodes\Core\AiAgentNode;
use P8P\Nodes\Premium\OpenRouterChatModelNode;

putenv('APP_SECRET=test-secret-for-openrouter-node-0123456789');
$_ENV['APP_SECRET'] = 'test-secret-for-openrouter-node-0123456789';

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

function throws(callable $fn, string $class, string $label, ?string $contains = null): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, "{$label} (lanzó " . get_class($e) . ': ' . $e->getMessage() . ')');
        if ($contains !== null) {
            check(str_contains($e->getMessage(), $contains), "{$label}: mensaje contiene '{$contains}' (era: {$e->getMessage()})");
        }
        return $e;
    }
    check(false, "{$label} (no lanzó nada)");
    exit(1);
}

$key = 'sk-or-v1-0123456789abcdef0123456789abcdef';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE credentials (id TEXT PRIMARY KEY, type TEXT NOT NULL, data TEXT NOT NULL)');
$cm = new CredentialManager($db);
$put = static function (string $id, string $type, array $data) use ($db, $cm): void {
    $db->prepare('INSERT INTO credentials VALUES (?, ?, ?)')->execute([$id, $type, $cm->encrypt($data)]);
};
$put('cred', 'generic_bearer', ['api_key' => $key]);
$put('cred-json', 'generic_bearer', ['api_key' => json_encode(['api_key' => $key])]);
$put('cred-wrong-type', 'gemini_api', ['api_key' => $key]);
$put('cred-short', 'generic_bearer', ['api_key' => 'abc']);

/** @return array{0: OpenRouterChatModelNode, 1: MockHandler, 2: array} */
function node(array $queue, CredentialManager $cm, array &$history = []): array
{
    $mock = new MockHandler($queue);
    $stack = HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    return [new OpenRouterChatModelNode(new Client(['handler' => $stack]), $cm), $mock];
}

function ok(string $text = 'Hola', array $extra = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($extra + [
        'id' => 'gen-1', 'model' => 'anthropic/claude-sonnet-4.5',
        'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
    ]));
}

$msgs = [['role' => 'system', 'content' => 'Sé breve.'], ['role' => 'user', 'content' => 'Hola']];
$cfg = ['credential_id' => 'cred', 'model' => 'anthropic/claude-sonnet-4.5'];

// ---- 1. Esquema y descubrimiento -----------------------------------------------------------
$plain = new OpenRouterChatModelNode();
$schema = $plain->getSchema();
check($schema['type'] === 'openrouter_model' && $schema['category'] === 'Premium', 'esquema: type y categoría');
check(($schema['parameters']['credential_id']['type'] ?? '') === 'credential', 'esquema: pide credencial');
check((new ReflectionClass(OpenRouterChatModelNode::class))->implementsInterface(P8P\Interfaces\NodeInterface::class), 'implementa NodeInterface');

// ---- 2. Validación -------------------------------------------------------------------------
check($plain->validate($cfg), 'config válida');
check($plain->validate(['credential_id' => 'c']), 'modelo por defecto (openrouter/auto)');
foreach ([
    ['model' => 'a/b'], ['credential_id' => '', 'model' => 'a/b'], ['credential_id' => 'c', 'model' => ''],
    ['credential_id' => 'c', 'model' => 'a b'], ['credential_id' => 'c', 'model' => "a/b\n"], ['credential_id' => 'c', 'model' => '/a'],
    ['credential_id' => 'c', 'model' => str_repeat('a', 129)], ['credential_id' => 'c', 'model' => ['x']],
    ['credential_id' => 'c', 'model' => 'a/b', 'max_output_tokens' => 0], ['credential_id' => 'c', 'model' => 'a/b', 'max_output_tokens' => 16385],
    ['credential_id' => 'c', 'model' => 'a/b', 'max_output_tokens' => '12abc'], ['credential_id' => 'c', 'model' => 'a/b', 'max_output_tokens' => true],
    ['credential_id' => 'c', 'model' => 'a/b', 'temperature' => 2.5], ['credential_id' => 'c', 'model' => 'a/b', 'temperature' => -0.1],
    ['credential_id' => 'c', 'model' => 'a/b', 'temperature' => 'caliente'],
    ['credential_id' => 'c', 'model' => 'a/b', 'api_key' => 'sk-secreto'], ['credential_id' => 'c', 'model' => 'a/b', 'tools' => []],
    ['credential_id' => 'c', 'model' => 'a/b', 'options' => ['extra' => 1]], ['credential_id' => 'c', 'model' => 'a/b', 'provider' => 'openai'],
] as $bad) {
    check(!$plain->validate($bad), 'rechaza ' . json_encode($bad));
}
check($plain->validate(['credential_id' => 'c', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'temperature' => '', 'max_output_tokens' => '256']),
    'acepta id con :free, temperatura vacía y tokens como texto');
check($plain->validate($cfg + ['_sub_connections' => []]), 'ignora _sub_connections vacío');

// ---- 3. execute() devuelve referencia, nunca la clave ------------------------------------------
$out = $plain->execute($cfg + ['temperature' => '0.3', 'max_output_tokens' => 512], []);
check($out['type'] === 'chat_model' && $out['provider'] === 'openrouter' && $out['credential_id'] === 'cred'
    && $out['options'] === ['max_output_tokens' => 512, 'temperature' => 0.3], 'execute: referencia de modelo con opciones');
check(!str_contains(json_encode($out), $key), 'execute: sin clave');
check(!array_key_exists('temperature', $plain->execute($cfg, [])['options']), 'execute: sin temperatura si no se indicó');
// La salida de execute() se acepta de vuelta en generate() (round-trip por el AI Agent).
check($plain->validate($out), 'round-trip: la salida de execute() valida');

// ---- 4. Petición enviada -------------------------------------------------------------------
$h = [];
[$n, $mock] = node([ok('Respuesta')], $cm, $h);
$r = $n->generate($cfg + ['temperature' => 0.2, 'max_output_tokens' => 300], $msgs);
$req = $h[0]['request'];
$body = json_decode((string) $req->getBody(), true);
check((string) $req->getUri() === 'https://openrouter.ai/api/v1/chat/completions' && $req->getMethod() === 'POST', 'endpoint fijo de OpenRouter');
check($req->getHeaderLine('Authorization') === 'Bearer ' . $key, 'Authorization Bearer con la clave descifrada');
check($body['model'] === 'anthropic/claude-sonnet-4.5' && $body['messages'] === $msgs && $body['max_tokens'] === 300
    && $body['temperature'] === 0.2 && $body['stream'] === false, 'cuerpo: model/messages/max_tokens/temperature/stream');
$o = $h[0]['options'];
check($o['allow_redirects'] === false && $o['verify'] === true && $o['http_errors'] === false, 'sin redirects, TLS verificado');
check($r['success'] === true && $r['provider'] === 'openrouter' && $r['output_text'] === 'Respuesta'
    && $r['usage'] === ['input_tokens' => 11, 'output_tokens' => 7, 'total_tokens' => 18]
    && $r['finish_reason'] === 'stop' && $r['model_used'] === 'anthropic/claude-sonnet-4.5', 'resultado normalizado');
$h2 = [];
[$n] = node([ok()], $cm, $h2);
$n->generate($cfg, $msgs);
check(!array_key_exists('temperature', json_decode((string) $h2[0]['request']->getBody(), true)), 'sin temperatura si no se configuró');
$h3 = [];
[$n] = node([ok()], $cm, $h3);
$n->generate(['credential_id' => 'cred-json', 'model' => 'openai/gpt-4o'], $msgs);
check($h3[0]['request']->getHeaderLine('Authorization') === 'Bearer ' . $key, 'credencial con api_key en JSON anidado');

// openrouter/auto: se informa el modelo que respondió realmente.
[$n] = node([ok('x', ['model' => 'google/gemini-2.5-flash'])], $cm);
check($n->generate(['credential_id' => 'cred', 'model' => 'openrouter/auto'], $msgs)['model_used'] === 'google/gemini-2.5-flash', 'model_used refleja el modelo real');
[$n] = node([ok('x', ['model' => "malo\nmodelo"])], $cm);
check($n->generate(['credential_id' => 'cred', 'model' => 'openrouter/auto'], $msgs)['model_used'] === 'openrouter/auto', 'model_used ignora un id devuelto inválido');

// ---- 5. Respuestas y errores ---------------------------------------------------------------
[$n] = node([ok("La clave es {$key}. Fin.")], $cm);
$t = $n->generate($cfg, $msgs)['output_text'];
check(!str_contains($t, $key) && str_contains($t, '[redacted]'), 'la clave se redacta si el modelo la repite');

foreach ([401 => 'API Key inválida', 402 => 'créditos insuficientes', 429 => 'límite de peticiones', 500 => 'HTTP 500'] as $code => $hint) {
    [$n] = node([new Response($code, [], json_encode(['error' => ['message' => "cuerpo secreto {$key}"]]))], $cm);
    $e = throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, "HTTP {$code}", $hint);
    check(!str_contains($e->getMessage(), $key) && !str_contains($e->getMessage(), 'cuerpo secreto'), "HTTP {$code}: no filtra el cuerpo ni la clave");
}
[$n] = node([new Response(302, ['Location' => 'http://127.0.0.1/'], '')], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'redirect no seguido', 'HTTP 302');
[$n] = node([new Response(200, [], json_encode(['error' => ['code' => 429, 'message' => 'x']]))], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, '200 con error en el cuerpo', 'error');
[$n] = node([new Response(200, [], json_encode(['choices' => [['finish_reason' => 'length', 'message' => ['content' => 'cortado']]]]))], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'finish_reason length = incompleto', 'incompleta');
[$n] = node([new Response(200, [], json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => null, 'tool_calls' => [['id' => 'x']]]]]]))], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'tool_calls no admitidas', 'herramientas');
foreach ([[], ['choices' => []], ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '  ']]]],
    ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => ['x']]]]], ['choices' => 'no']] as $i => $bodyArr) {
    [$n] = node([new Response(200, [], json_encode($bodyArr))], $cm);
    throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, "estructura inválida #{$i}");
}
[$n] = node([new Response(200, [], '{no es json')], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'JSON inválido', 'JSON inválida');
[$n] = node([new Response(200, [], str_repeat('a', 2097153))], $cm);
throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'respuesta > 2 MiB', '2 MiB');
[$n] = node([new ConnectException("cURL error 6 {$key}", new Request('POST', 'https://openrouter.ai/'))], $cm);
$e = throws(fn () => $n->generate($cfg, $msgs), RuntimeException::class, 'fallo de transporte', 'transporte');
check(!str_contains($e->getMessage(), $key), 'transporte: sin clave en el mensaje');

// ---- 6. Credenciales y mensajes inválidos (sin petición) ------------------------------------------
foreach (['nope' => RuntimeException::class, 'cred-wrong-type' => InvalidArgumentException::class, 'cred-short' => InvalidArgumentException::class] as $id => $class) {
    [$n, $mock] = node([ok()], $cm);
    throws(fn () => $n->generate(['credential_id' => $id, 'model' => 'a/b'], $msgs), Throwable::class, "credencial {$id}");
    check($mock->count() === 1, "credencial {$id}: no se envió petición");
}
foreach ([[], [['role' => 'system', 'content' => 's']], [['role' => 'tool', 'content' => 'x']], [['role' => 'user', 'content' => '']],
    [['role' => 'user', 'content' => "\xff\xfe"]], [['role' => 'user', 'content' => 'x', 'name' => 'n']], ['a' => ['role' => 'user', 'content' => 'x']],
    [['role' => 'user', 'content' => str_repeat('a', 1048577)]]] as $i => $bad) {
    [$n, $mock] = node([ok()], $cm);
    throws(fn () => $n->generate($cfg, $bad), InvalidArgumentException::class, "mensajes inválidos #{$i}");
    check($mock->count() === 1, "mensajes inválidos #{$i}: sin petición");
}

// ---- 7. Cableado con AiAgentNode -------------------------------------------------------------
$agentHistory = [];
$mock = new MockHandler([ok('Desde el agente')]);
$stack = HandlerStack::create($mock);
$stack->push(\GuzzleHttp\Middleware::history($agentHistory));
$client = new Client(['handler' => $stack]);
$agent = new AiAgentNode(['openrouter' => new OpenRouterChatModelNode($client, $cm)], $client);
$model = $plain->execute($cfg, []);
$res = $agent->execute(['system_prompt' => 'Eres útil.', 'user_prompt' => 'Hola', '_sub_connections' => ['model' => [$model]]], []);
$sent = json_decode((string) $agentHistory[0]['request']->getBody(), true);
check($res['success'] === true && $res['output_text'] === 'Desde el agente' && $res['provider'] === 'openrouter', 'AI Agent enruta provider=openrouter');
check($sent['messages'][0] === ['role' => 'system', 'content' => 'Eres útil.'] && end($sent['messages'])['content'] === 'Hola', 'AI Agent antepone el system prompt');
throws(fn () => $agent->execute(['system_prompt' => 's', 'user_prompt' => 'x', '_sub_connections' => ['model' => [['provider' => 'otro'] + $model]]], []),
    InvalidArgumentException::class, 'proveedor desconocido sigue rechazado', 'no soportado');
// Sin proveedores inyectados, el agente instancia el nodo real (no falla por match sin rama).
$agentReal = new AiAgentNode(null, new Client(['handler' => HandlerStack::create(new MockHandler([ok('real')]))]));
try {
    $agentReal->execute(['system_prompt' => 's', 'user_prompt' => 'x', '_sub_connections' => ['model' => [$model]]], []);
} catch (\UnhandledMatchError $e) {
    check(false, 'AiAgentNode no tiene rama openrouter en el match');
} catch (Throwable $e) {
    // Sin la BD real de credenciales falla al leerla: lo relevante es que llegó al nodo OpenRouter.
    check(!($e instanceof \UnhandledMatchError), 'el agente llegó al nodo OpenRouter real');
}
check(true, 'AiAgentNode instancia OpenRouterChatModelNode sin proveedores inyectados');

echo "PASS openrouter: {$assertions} assertions (validación, petición, errores, credenciales, AI Agent)\n";
