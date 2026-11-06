<?php

// Additive and idempotent; applying is an explicit CLI operation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
if (!in_array('--apply', $argv, true)) {
    echo "Crea conversation_memory sin modificar workflows, credenciales ni trabajos existentes.\n";
    echo "Respalda la base y detén workers y entradas durante el cambio de versión.\n";
    echo "Aplicar: php database/migrations/20261105_simple_memory.php --apply\n";
    echo "Sin --apply no se abre conexión ni se modifica la base de datos.\n";
    exit(0);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = null;
$locked = false;
$exitCode = 0;
try {
    $db = \P8P\Core\Database::getConnection(require dirname(__DIR__, 2) . '/config/database.php');
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Producción requiere MySQL/MariaDB.');
    }
    $locked = (int)$db->query("SELECT GET_LOCK('p8p_simple_memory_migration', 0)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Otra migración de memoria está en curso.');
    }
    \P8P\Core\ConversationMemorySchema::install($db);
    echo "Simple Memory preparado. No se borraron datos existentes.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "No se completó la migración de Simple Memory. Revisa conectividad y permisos DDL con los workers detenidos. El comando es repetible.\n");
    $exitCode = 1;
} finally {
    if ($locked && $db !== null) {
        try {
            $db->query("SELECT RELEASE_LOCK('p8p_simple_memory_migration')");
        } catch (Throwable $e) {
            fwrite(STDERR, "El bloqueo de migración se liberará al cerrar la conexión.\n");
            $exitCode = 1;
        }
    }
}
exit($exitCode);
