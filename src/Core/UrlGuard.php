<?php

namespace P8P\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * SSRF guard para nodos que llaman a URLs arbitrarias.
 *
 * Valida la URL, resuelve el host UNA vez y exige que TODAS las direcciones sean
 * públicas. El llamador debe fijar la conexión a la IP validada (CURLOPT_RESOLVE con
 * el valor de `resolve`) para que un segundo lookup no pueda devolver otra dirección
 * (DNS rebinding). La política de rangos es la misma que ya usa RssAtomNode.
 */
final class UrlGuard
{
    /** Sufijos de nombres internos que nunca deben salir por este camino. */
    private const BLOCKED_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp',
        'home.arpa', 'test', 'invalid', 'example',
    ];

    /** @var \Closure(string): array<int, string> */
    private \Closure $resolver;

    /** @param (\Closure(string): array<int, string>)|null $resolver Inyectable para pruebas. */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            return $ips;
        };
    }

    /**
     * @return array{scheme: string, host: string, port: int, ip: string, resolve: ?string}
     * @throws InvalidArgumentException si la URL o su destino no están permitidos
     * @throws RuntimeException si el host no resuelve a ninguna dirección
     */
    public function check(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('URL inválida.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('URL inválida.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Solo se permiten URLs http o https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('No se permiten credenciales dentro de la URL.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Puerto inválido.');
        }

        // parse_url devuelve los literales IPv6 entre corchetes.
        $host = strtolower(trim($parts['host'], '[]'));
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$isLiteral) {
            $this->assertHostname($host);
        }

        $addresses = $isLiteral ? [$host] : ($this->resolver)($host);
        if (!is_array($addresses) || $addresses === []) {
            throw new RuntimeException('No se pudo resolver el host.');
        }
        if (count($addresses) > 64) {
            throw new InvalidArgumentException('El host devuelve demasiadas direcciones.');
        }
        foreach ($addresses as $ip) {
            if (!is_string($ip) || !self::isPublicIp($ip)) {
                throw new InvalidArgumentException('El destino apunta a una dirección no pública.');
            }
        }

        // Preferir IPv4: es lo que garantiza que curl use la dirección fijada.
        $pinned = $addresses[0];
        foreach ($addresses as $ip) {
            if (!str_contains($ip, ':')) {
                $pinned = $ip;
                break;
            }
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'ip' => $pinned,
            'resolve' => $isLiteral
                ? null
                : $host . ':' . $port . ':' . (str_contains($pinned, ':') ? '[' . $pinned . ']' : $pinned),
        ];
    }

    private function assertHostname(string $host): void
    {
        // Exige nombre DNS ASCII con TLD alfabético: descarta formas numéricas que curl
        // interpretaría como IP (2130706433, 0x7f.1, 127.1) y nombres de una sola etiqueta.
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) {
            throw new InvalidArgumentException('Nombre de host no permitido.');
        }
        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                throw new InvalidArgumentException('Nombre de host interno no permitido.');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // Solo unicast global (2000::/3); excluye documentación, transición y uso especial
            // (incluye ::ffff:0:0/96 y 64:ff9b::/96, que quedan fuera de 2000::/3).
            if ((ord($packed[0]) & 0xe0) !== 0x20) {
                return false;
            }
            $ranges = ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        } else {
            $ranges = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
                '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];
        }
        foreach ($ranges as $range) {
            [$base, $bits] = explode('/', $range);
            $network = inet_pton($base);
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && ($remainder === 0
                    || (ord($packed[$bytes]) & (0xff << (8 - $remainder))) === (ord($network[$bytes]) & (0xff << (8 - $remainder))))) {
                return false;
            }
        }
        return true;
    }
}
