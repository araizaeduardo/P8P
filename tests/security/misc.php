<?php

/** Regresión de parches menores: UUID criptográfico y comprobaciones estáticas del código fuente. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use P8P\Core\Uuid;

$root = dirname(__DIR__, 2);
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

// UUID v4 válido y sin colisiones.
$seen = [];
for ($i = 0; $i < 2000; $i++) {
    $id = Uuid::v4();
    check(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1, "formato UUID v4: {$id}");
    $seen[$id] = true;
}
check(count($seen) === 2000, 'sin colisiones en 2000 UUID');

// Búsqueda estática en src/ y public/ (sin assets compilados).
$files = [];
foreach (['src', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php' && !str_contains($f->getPathname(), '/public/assets/')) {
            $files[] = $f->getPathname();
        }
    }
}
check(count($files) > 50, 'se recorrieron los fuentes PHP (' . count($files) . ')');

$offenders = ['mt_rand' => [], 'tls_off' => []];
foreach ($files as $file) {
    $code = file_get_contents($file);
    if (preg_match('/\bmt_rand\s*\(/', $code)) {
        $offenders['mt_rand'][] = $file;
    }
    if (preg_match('/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*,\s*(?:false|0)\b/', $code)) {
        $offenders['tls_off'][] = $file;
    }
}
check($offenders['mt_rand'] === [], 'ningún mt_rand() para IDs: ' . implode(', ', $offenders['mt_rand']));
check($offenders['tls_off'] === [], 'ningún CURLOPT_SSL_VERIFY* desactivado: ' . implode(', ', $offenders['tls_off']));

$telegram = file_get_contents("{$root}/src/Nodes/Custom/TelegramNode.php");
check(str_contains($telegram, 'CURLOPT_SSL_VERIFYPEER, true') && str_contains($telegram, 'CURLOPT_SSL_VERIFYHOST, 2'), 'Telegram verifica TLS y host');

$credentials = file_get_contents("{$root}/public/credentials.php");
check(!preg_match('/echo\s+json_encode\([^;]*getMessage\(\)/', $credentials), 'credentials.php no devuelve getMessage() al cliente');

echo "PASS misc: {$assertions} assertions (UUID v4, sin mt_rand, TLS activo, errores genéricos)\n";
