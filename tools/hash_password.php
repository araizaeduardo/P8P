<?php

/**
 * Genera el valor de ADMIN_PASS_HASH para el .env.
 *
 *   php tools/hash_password.php                  (pide la contraseña sin eco)
 *   printf '%s' 'mi-clave-larga' | php tools/hash_password.php
 *
 * Después, en el .env: pega la línea ADMIN_PASS_HASH=... y borra ADMIN_PASS.
 * EnvLoader no expande variables, así que los '$' del hash no necesitan escape.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

const MIN_LENGTH = 12;

if (stream_isatty(STDIN)) {
    $echoOff = @shell_exec('stty -echo 2>/dev/null') !== null;
    fwrite(STDERR, 'Contraseña: ');
    $first = rtrim((string) fgets(STDIN), "\r\n");
    fwrite(STDERR, "\nRepite la contraseña: ");
    $second = rtrim((string) fgets(STDIN), "\r\n");
    fwrite(STDERR, "\n");
    if ($echoOff) {
        @shell_exec('stty echo 2>/dev/null');
    }
    if ($first !== $second) {
        fwrite(STDERR, "Las contraseñas no coinciden.\n");
        exit(1);
    }
    $password = $first;
} else {
    $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
}

if (strlen($password) < MIN_LENGTH) {
    fwrite(STDERR, 'La contraseña debe tener al menos ' . MIN_LENGTH . " caracteres.\n");
    exit(1);
}
if (strlen($password) > 72) {
    fwrite(STDERR, "bcrypt solo usa los primeros 72 bytes; usa una contraseña de hasta 72 caracteres.\n");
    exit(1);
}

echo 'ADMIN_PASS_HASH=' . password_hash($password, PASSWORD_DEFAULT) . "\n";
fwrite(STDERR, "Listo. Pega esa línea en el .env y elimina ADMIN_PASS.\n");
