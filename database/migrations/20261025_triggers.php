<?php

// Explicit CLI-only, additive migration; no connection is opened for preview.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
if (!in_array('--apply', $argv, true)) {
    echo "Esta migración crea trigger_configs y trigger_events sin borrar datos existentes.\n";
    echo "Respalda la BD y detén workers y entradas durante el cambio de versión.\n";
    echo "Aplicar: php database/migrations/20261025_triggers.php --apply\n";
    echo "Sin --apply no se abre conexión ni se modifica la BD.\n";
    exit(0);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = null;
$locked = false;
$exitCode = 0;
try {
    $db = \P8P\Core\Database::getConnection(require dirname(__DIR__, 2) . '/config/database.php');
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('La migración de producción requiere MySQL/MariaDB.');
    }
    $locked = (int) $db->query("SELECT GET_LOCK('p8p_triggers_migration', 0)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Otra migración de triggers está en curso.');
    }
    \P8P\Core\TriggerSchema::install($db);
    echo "Tablas de triggers preparadas. No se borraron workflows, credenciales, eventos ni trabajos.\n";
    echo "Completa configuración HTTPS, credenciales, cron y registro externo antes de activar receptores.\n";
} catch (Throwable $e) {
    // Database exceptions may contain hosts, usernames or SQL; do not print them.
    fwrite(STDERR, "No se completó la migración de triggers. Revisa conectividad y permisos DDL con los workers detenidos. El comando es repetible.\n");
    $exitCode = 1;
} finally {
    if ($locked && $db !== null) {
        try {
            $db->query("SELECT RELEASE_LOCK('p8p_triggers_migration')");
        } catch (Throwable $e) {
            fwrite(STDERR, "No se confirmó la liberación del bloqueo; se libera al cerrar la conexión.\n");
            $exitCode = 1;
        }
    }
}
exit($exitCode);
