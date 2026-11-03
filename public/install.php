<?php

session_start();

$envFile = __DIR__ . '/../.env';
$isInstalled = file_exists($envFile);
$step = (int)($_GET['step'] ?? 1);

// Permitir visualizar el paso 3 recién completado o si viene forzado
if ($isInstalled && ($step !== 3 || $_SERVER['REQUEST_METHOD'] === 'POST')) {
    http_response_code(403);
    die('P8P ya está instalado. Utiliza las migraciones incrementales desde CLI para actualizarlo.');
}

$error = '';$success = '';

// COMPROBACIÓN DE REQUISITOS (Paso 1)
$requirements = [
        'php_version' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'curl' => extension_loaded('curl'),
        'openssl' => extension_loaded('openssl'),
        'mbstring' => extension_loaded('mbstring'),
        'writable_config' => is_writable(__DIR__ . '/..')
];

$allRequirementsMet = !in_array(false,$requirements, true);

// PROCESAR FORMULARIO DE INSTALACIÓN (Paso 2 -> Paso 3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&$step === 2) {
    $dbHost    = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort    = trim($_POST['db_port'] ?? '3306');
    $dbName    = trim($_POST['db_name'] ?? '');
    $dbUser    = trim($_POST['db_user'] ?? '');
    $dbPass    =$_POST['db_pass'] ?? '';
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = trim($_POST['admin_pass'] ?? '');

    if (!$allRequirementsMet || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName) || !ctype_digit($dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
        $error = 'Comprueba requisitos, nombre de base de datos y puerto.';
    } elseif (preg_match('/[\r\n\x00]/', implode('', [$dbHost,$dbUser,$dbPass,$adminUser,$adminPass]))) {
        $error = 'Los valores de configuración no pueden contener saltos de línea.';
    } elseif (empty($adminUser) || empty($adminPass)) {$error = "El usuario y la contraseña del Administrador son obligatorios.";
    } else {
        try {
            // 1. Probar conexión PDO
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser,$dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // 2. Crear base de datos si no existe y usarla
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `{$dbName}`;");

            // 3. Crear Estructura de Tablas
            $schema = "
            CREATE TABLE IF NOT EXISTS workflows (
                id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                structure LONGTEXT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS queue_jobs (
                id VARCHAR(255) NOT NULL,
                workflow_id VARCHAR(255) NULL,
                payload LONGTEXT NOT NULL,
                status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                available_at DATETIME NULL,
                claim_token VARCHAR(64) NULL,
                error_message TEXT NULL,
                result LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_status (status),
                KEY idx_queue_available (status, available_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS credentials (
                id VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(100) NOT NULL,
                data LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";

            $pdo->exec($schema);
            require_once __DIR__ . '/../vendor/autoload.php';
            \P8P\Core\TriggerSchema::install($pdo);

            // 4. Generar archivo .env unificado
            $appSecret = base64_encode(random_bytes(32));
            $adminSecretKey = bin2hex(random_bytes(16));

            $envContent  = "# Configuración del Sistema\n";
            $envContent .= "APP_NAME=P8P_Engine\n";
            $envContent .= "APP_ENV=production\n";
            $envContent .= "APP_SECRET={$appSecret}\n\n";

            $envContent .= "# Configuración de MariaDB\n";
            $envContent .= "DB_HOST={$dbHost}\n";
            $envContent .= "DB_PORT={$dbPort}\n";
            $envContent .= "DB_NAME={$dbName}\n";
            $envContent .= "DB_USER={$dbUser}\n";
            $envContent .= "DB_PASS={$dbPass}\n";
            $envContent .= "DB_DATABASE={$dbName}\n";
            $envContent .= "DB_USERNAME={$dbUser}\n";
            $envContent .= "DB_PASSWORD={$dbPass}\n\n";

            $envContent .= "# Credenciales del Administrador\n";
            $envContent .= "ADMIN_USER={$adminUser}\n";
            $envContent .= "ADMIN_PASS={$adminPass}\n";
            $envContent .= "ADMIN_SECRET_KEY={$adminSecretKey}\n";

            // Exclusive creation prevents concurrent requests from replacing an existing key.
            $envHandle = fopen($envFile, 'x');
            if ($envHandle === false) {
                throw new RuntimeException('No se pudo crear el archivo de configuración.');
            }
            $written = fwrite($envHandle, $envContent);
            fclose($envHandle);
            if ($written !== strlen($envContent)) {
                unlink($envFile);
                throw new RuntimeException('No se pudo guardar el archivo de configuración completo.');
            }
            chmod($envFile, 0600);

            // config/database.php carga los valores desde .env; no duplicar credenciales.

            header("Location: install.php?step=3&force=1");
            exit;
        } catch (\Throwable $e) {$error = "Error durante la instalación: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador P8P Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen flex items-center justify-center p-4">
<div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl">
    <div class="flex items-center gap-3 border-b border-slate-800 pb-4 mb-6">
        <div class="bg-indigo-600 p-2 rounded-xl text-white font-bold text-lg">P8P</div>
        <div>
            <h1 class="text-base font-bold text-slate-100">Instalador de Motor P8P</h1>
            <p class="text-xs text-slate-400">Paso <?= $step ?> de 3</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-3 mb-4 bg-red-500/10 border border-red-500/30 rounded-xl text-xs text-red-400">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- PASO 1: REQUISITOS DEL SISTEMA -->
    <?php if ($step === 1): ?>
        <h2 class="text-sm font-semibold mb-3 text-indigo-400">Comprobación del Entorno Server</h2>
        <div class="space-y-2 mb-6">
            <div class="flex justify-between items-center p-2.5 bg-slate-950 rounded-xl text-xs border border-slate-800">
                <span>PHP Version (>= 8.1.0) [Actual: <?= PHP_VERSION ?>]</span>
                <span class="<?= $requirements['php_version'] ? 'text-emerald-400' : 'text-red-400' ?>">
                        <?= $requirements['php_version'] ? '✓ Correcto' : '✗ Requerido' ?>
                    </span>
            </div>
            <div class="flex justify-between items-center p-2.5 bg-slate-950 rounded-xl text-xs border border-slate-800">
                <span>Extensión PDO MySQL</span>
                <span class="<?= $requirements['pdo_mysql'] ? 'text-emerald-400' : 'text-red-400' ?>">
                        <?= $requirements['pdo_mysql'] ? '✓ Habilitada' : '✗ Faltante' ?>
                    </span>
            </div>
            <div class="flex justify-between items-center p-2.5 bg-slate-950 rounded-xl text-xs border border-slate-800">
                <span>Extensión cURL</span>
                <span class="<?= $requirements['curl'] ? 'text-emerald-400' : 'text-red-400' ?>">
                        <?= $requirements['curl'] ? '✓ Habilitada' : '✗ Faltante' ?>
                    </span>
            </div>
            <div class="flex justify-between items-center p-2.5 bg-slate-950 rounded-xl text-xs border border-slate-800">
                <span>Extensión OpenSSL (Cifrado AES)</span>
                <span class="<?= $requirements['openssl'] ? 'text-emerald-400' : 'text-red-400' ?>">
                        <?= $requirements['openssl'] ? '✓ Habilitada' : '✗ Faltante' ?>
                    </span>
            </div>
            <div class="flex justify-between items-center p-2.5 bg-slate-950 rounded-xl text-xs border border-slate-800">
                <span>Permisos de Escritura del Servidor</span>
                <span class="<?= $requirements['writable_config'] ? 'text-emerald-400' : 'text-red-400' ?>">
                        <?= $requirements['writable_config'] ? '✓ Permitido' : '✗ Bloqueado' ?>
                    </span>
            </div>
        </div>

        <?php if ($allRequirementsMet): ?>
            <a href="install.php?step=2" class="block w-full text-center py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold transition">
                Continuar a Configuración ➔
            </a>
        <?php else: ?>
            <button disabled class="w-full py-2.5 bg-slate-800 text-slate-500 rounded-xl text-xs font-semibold cursor-not-allowed">
                Corrige los requisitos para continuar
            </button>
        <?php endif; ?>
    <?php endif; ?>

    <!-- PASO 2: FORMULARIO -->
    <?php if ($step === 2): ?>
        <h2 class="text-sm font-semibold mb-3 text-indigo-400">Configuración de Base de Datos y Administrador</h2>
        <form method="POST" action="install.php?step=2" class="space-y-3">
            <div>
                <label class="block text-xs text-slate-300 mb-1">Host de BD</label>
                <input type="text" name="db_host" value="127.0.0.1" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>
            <div>
                <label class="block text-xs text-slate-300 mb-1">Puerto de BD</label>
                <input type="text" name="db_port" value="3306" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>
            <div>
                <label class="block text-xs text-slate-300 mb-1">Nombre de la Base de Datos</label>
                <input type="text" name="db_name" value="p8p_db" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>
            <div>
                <label class="block text-xs text-slate-300 mb-1">Usuario de BD</label>
                <input type="text" name="db_user" value="root" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>
            <div>
                <label class="block text-xs text-slate-300 mb-1">Contraseña de BD</label>
                <input type="password" name="db_pass" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>

            <hr class="border-slate-800 my-4">

            <div>
                <label class="block text-xs text-indigo-400 font-semibold mb-1">Usuario Administrador (.env)</label>
                <input type="text" name="admin_user" value="admin" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>
            <div>
                <label class="block text-xs text-indigo-400 font-semibold mb-1">Contraseña Administrador (.env)</label>
                <input type="password" name="admin_pass" required class="w-full bg-slate-950 border border-slate-800 rounded-xl p-2.5 text-xs text-slate-100 focus:outline-none focus:border-indigo-500 font-mono">
            </div>

            <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold transition mt-4">
                Instalar y Guardar .env ➔
            </button>
        </form>
    <?php endif; ?>

    <!-- PASO 3: FINALIZACIÓN Y AVISOS DE SEGURIDAD -->
    <?php if ($step === 3): ?>
        <div class="text-center space-y-4 py-2">
            <div class="inline-flex p-3 rounded-full bg-emerald-500/20 text-emerald-400 mb-1">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-100">¡Instalación Exitosa!</h2>
            <p class="text-xs text-slate-400 max-w-sm mx-auto">
                El esquema de la base de datos se ha creado correctamente y el archivo <code class="text-indigo-400 font-mono">.env</code> ha sido generado.
            </p>

            <!-- Alerta de Seguridad Crítica: Eliminar install.php -->
            <div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl text-left text-xs space-y-1 text-amber-300">
                <div class="font-bold flex items-center gap-1.5 text-amber-400">
                    <span>🛑 ACCIÓN DE SEGURIDAD OBLIGATORIA:</span>
                </div>
                <div class="text-[11px] leading-relaxed text-amber-200">
                    Por motivos de seguridad en producción, **debes eliminar el archivo <code class="text-amber-100 bg-slate-900 px-1 rounded">public/install.php</code>** de tu servidor para prevenir la reescritura o sobreescritura no autorizada del archivo <code class="text-amber-100 bg-slate-900 px-1 rounded">.env</code>.
                </div>
            </div>

            <!-- Recordatorio de Configuración de Cron Job -->
            <div class="p-3 bg-slate-950 border border-slate-800 rounded-xl text-left text-xs space-y-1.5 font-mono text-slate-400">
                <div class="text-slate-300 font-sans font-semibold">⚠️ Tarea Programada Requerida (Cron Job):</div>
                <div class="text-[11px]">Para procesar las colas asíncronas de trabajo (como el chat web), configura este Cron en tu cPanel o servidor (ejecución cada minuto):</div>
                <div class="p-2 bg-slate-900 border border-slate-800 rounded text-emerald-400 text-[11px] break-all select-all font-mono">
                    * * * * * php <?= realpath(__DIR__ . '/cron.php') ?: '/ruta/servidor/public/cron.php' ?>
                </div>
            </div>

            <a href="/" class="inline-block px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold transition mt-2">
                Ir al Editor Visual ➔
            </a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
