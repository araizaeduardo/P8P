<?php

namespace P8P\Core;

/**
 * Límite de intentos fallidos de login por clave (IP), con estado en archivos y flock.
 * No requiere cambios de esquema. Falla abierto (y lo registra) si el directorio no es
 * escribible, para no dejar al administrador fuera por un problema de permisos.
 */
final class LoginThrottle
{
    public function __construct(
        private string $dir,
        private int $maxFailures = 8,
        private int $window = 900
    ) {
    }

    public static function defaultDir(): string
    {
        $dir = $_ENV['P8P_THROTTLE_DIR'] ?? getenv('P8P_THROTTLE_DIR');
        return is_string($dir) && $dir !== '' ? $dir : sys_get_temp_dir() . '/p8p-throttle';
    }

    /** Segundos que faltan para poder reintentar (0 = permitido). */
    public function retryAfter(string $key, ?int $now = null): int
    {
        $now ??= time();
        $failures = $this->mutate($key, $now, static fn (array $f): array => $f, false);
        if (count($failures) < $this->maxFailures) {
            return 0;
        }
        // Se libera cuando el fallo más antiguo sale de la ventana.
        return max(1, min($failures) + $this->window - $now);
    }

    public function recordFailure(string $key, ?int $now = null): void
    {
        $now ??= time();
        $this->mutate($key, $now, static function (array $f) use ($now): array {
            $f[] = $now;
            return $f;
        }, true);
        if (random_int(1, 50) === 1) {
            $this->gc($now);
        }
    }

    public function reset(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return rtrim($this->dir, '/') . '/' . hash('sha256', $key) . '.json';
    }

    /** @return list<int> */
    private function mutate(string $key, int $now, \Closure $change, bool $write): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            error_log('P8P LoginThrottle: no se pudo crear ' . $this->dir);
            return [];
        }
        $handle = @fopen($this->path($key), 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            error_log('P8P LoginThrottle: no se pudo abrir el estado de intentos');
            return [];
        }
        try {
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $failures = array_values(array_filter(
                is_array($decoded) ? $decoded : [],
                fn ($t): bool => is_int($t) && $t > $now - $this->window
            ));
            $failures = $change($failures);
            if ($write) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($failures));
                fflush($handle);
            }
            return $failures;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function gc(int $now): void
    {
        foreach (glob(rtrim($this->dir, '/') . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < $now - $this->window) {
                @unlink($file);
            }
        }
    }
}
