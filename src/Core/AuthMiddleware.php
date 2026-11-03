<?php

namespace P8P\Core;

class AuthMiddleware
{
    private const TOKEN_TTL = 28800;

    /** Signed administrator session. Existing opaque tokens must log in again. */
    public static function issueToken(string $user, ?int $now = null): string
    {
        [$adminUser, $secret] = self::configuration();
        if (!hash_equals($adminUser, $user)) {
            throw new \InvalidArgumentException('Usuario administrador inválido.');
        }
        $now ??= time();
        if ($now < 0 || $now > PHP_INT_MAX - self::TOKEN_TTL) {
            throw new \InvalidArgumentException('Fecha de sesión inválida.');
        }
        $payload = self::encode(json_encode([
            'sub' => $user, 'iat' => $now, 'exp' => $now + self::TOKEN_TTL,
            'jti' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signed = 'p8p1.' . $payload;
        return $signed . '.' . self::encode(hash_hmac('sha256', $signed, $secret, true));
    }

    public static function validateToken(string $token, ?int $now = null): bool
    {
        try {
            [$adminUser, $secret] = self::configuration();
            if (strlen($token) > 4096 || !preg_match('/^p8p1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/D', $token, $parts)) {
                return false;
            }
            $expected = self::encode(hash_hmac('sha256', 'p8p1.' . $parts[1], $secret, true));
            if (!hash_equals($expected, $parts[2])) {
                return false;
            }
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($decoded === false || self::encode($decoded) !== $parts[1]) {
                return false;
            }
            $payload = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
            $now ??= time();
            return is_array($payload) && count($payload) === 4
                && is_string($payload['sub'] ?? null) && hash_equals($adminUser, $payload['sub'])
                && is_int($payload['iat'] ?? null) && $payload['iat'] >= 0 && $payload['iat'] <= $now
                && is_int($payload['exp'] ?? null) && $payload['exp'] > $now
                && $payload['iat'] <= PHP_INT_MAX - self::TOKEN_TTL && $payload['exp'] === $payload['iat'] + self::TOKEN_TTL
                && is_string($payload['jti'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $payload['jti']) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function check(): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = null;
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                if (!is_string($value) || $authHeader !== null) {
                    self::reject('Acceso no autorizado: encabezado de autenticación inválido.');
                }
                $authHeader = $value;
            }
        }
        $serverHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($serverHeader !== null && (!is_string($serverHeader) || ($authHeader !== null && $authHeader !== $serverHeader))) {
            self::reject('Acceso no autorizado: encabezado de autenticación inválido.');
        }
        $authHeader ??= $serverHeader;
        if (!is_string($authHeader) || !preg_match('/^Bearer[ \t]+([^\s,]+)$/iD', $authHeader, $matches)
            || !self::validateToken($matches[1])) {
            self::reject('Acceso no autorizado: inicia sesión de nuevo.');
        }
    }

    private static function configuration(): array
    {
        EnvLoader::load(dirname(__DIR__, 2) . '/.env', false);
        $user = $_ENV['ADMIN_USER'] ?? getenv('ADMIN_USER');
        $secret = $_ENV['ADMIN_SECRET_KEY'] ?? getenv('ADMIN_SECRET_KEY');
        if (!is_string($user) || trim($user) === '' || strlen($user) > 255
            || !is_string($secret) || trim($secret) === '' || strlen($secret) > 16384) {
            throw new \RuntimeException('La autenticación administrativa no está configurada.');
        }
        return [$user, $secret];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function reject(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('WWW-Authenticate: Bearer');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}
