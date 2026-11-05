<?php

namespace P8P\Nodes\Core;

use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class RedisNode implements NodeInterface
{
    public function __construct(private ?CredentialManager $credentials = null, private ?\Closure $clientFactory = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'redis', 'name' => 'Redis Cache & Store', 'category' => 'Core', 'icon' => 'Database', 'is_start' => false,
            'description' => 'Claves aisladas, texto/JSON, contadores atómicos y TTL mediante phpredis.',
            'help_text' => 'Credencial generic_bearer API Key JSON: host, port, tls (boolean), username/password opcionales, database y prefix. ACL limitada al prefijo. JSON se guarda como JSON UTF-8; texto exige string. GET ausente: found=false/value=null; JSON null: found=true/value=null. TTL -2=ausente, -1=sin expiración. SET ttl=0 quita expiración. Contadores conservan TTL y solo aceptan enteros. Sin reintentos automáticos.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'label' => 'Credencial Redis', 'required' => true],
                'operation' => ['type' => 'select', 'label' => 'Operación', 'required' => true, 'default' => 'get', 'options' => array_map(fn($v) => ['value' => $v, 'label' => $v], ['get', 'set', 'delete', 'exists', 'increment', 'decrement', 'expire', 'ttl'])],
                'key' => ['type' => 'string', 'label' => 'Clave relativa', 'required' => true],
                'format' => ['type' => 'select', 'label' => 'Serialización', 'default' => 'text', 'options' => [['value' => 'text', 'label' => 'Texto'], ['value' => 'json', 'label' => 'JSON']]],
                'value' => ['type' => 'textarea', 'label' => 'Valor SET (JSON textual o valor resuelto)'],
                'amount' => ['type' => 'number', 'label' => 'Incremento/decremento positivo', 'default' => 1],
                'ttl' => ['type' => 'number', 'label' => 'Expiración SET/EXPIRE en segundos (0=sin expiración)', 'default' => 0]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->parameters($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    private function parameters(array $p): array
    {
        $op = $p['operation'] ?? 'get';
        if (!is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '' || !is_string($p['key'] ?? null)
            || !preg_match('/^[A-Za-z0-9_.:\/-]{1,200}$/D', $p['key']) || !in_array($op, ['get','set','delete','exists','increment','decrement','expire','ttl'], true)
            || !in_array($p['format'] ?? 'text', ['text','json'], true)) {
            throw new \InvalidArgumentException('Redis: credencial, operación, clave o formato inválido.');
        }
        $ttl = filter_var($p['ttl'] ?? 0, FILTER_VALIDATE_INT);
        $amount = filter_var($p['amount'] ?? 1, FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 0 || $ttl > 31536000 || $amount === false || $amount < 1 || $amount > 2147483647) {
            throw new \InvalidArgumentException('Redis: TTL debe estar entre 0 y 31536000; cantidad entre 1 y 2147483647.');
        }
        $value = null;
        if ($op === 'set') {
            if (!array_key_exists('value', $p)) { throw new \InvalidArgumentException('Redis: falta valor SET.'); }
            if (($p['format'] ?? 'text') === 'text') {
                if (!is_string($p['value'])) { throw new \InvalidArgumentException('Redis: texto requiere string.'); }
                $value = $p['value'];
            } else {
                $value = $p['value'];
                if (is_string($value)) { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            if (strlen($value) > 1048576) { throw new \InvalidArgumentException('Redis: valor máximo 1 MiB.'); }
        }
        return [$op, $ttl, $amount, $value];
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine resuelve expresiones una sola vez antes de llamar execute().
        [$op, $ttl, $amount, $value] = $this->parameters($parameters);
        try {
            $record = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($parameters['credential_id']);
        } catch (\Throwable $e) { throw new \RuntimeException('Redis: no se pudo recuperar la credencial.'); }
        try {
            $c = json_decode($record['data']['api_key'] ?? '', true, 32, JSON_THROW_ON_ERROR);
            if (($record['type'] ?? '') !== 'generic_bearer' || !is_array($c)
                || !is_string($c['host'] ?? null) || !preg_match('/^[A-Za-z0-9.-]+$/D', $c['host'])
                || !is_bool($c['tls'] ?? null) || !is_string($c['prefix'] ?? null) || !preg_match('/^[A-Za-z0-9_:-]{1,80}$/D', $c['prefix'])
                || filter_var($c['port'] ?? 6379, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
                || filter_var($c['database'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 15]]) === false
                || (isset($c['password']) && !is_string($c['password'])) || (isset($c['username']) && !is_string($c['username']))) {
                throw new \Exception();
            }
        } catch (\Throwable $e) { throw new \InvalidArgumentException('Redis: API Key debe contener JSON válido con host, tls booleano y prefix obligatorio.'); }
        if (!$this->clientFactory && !class_exists(\Redis::class)) { throw new \RuntimeException('Redis: falta la extensión phpredis.'); }
        $client = null;
        try {
            $client = $this->clientFactory ? ($this->clientFactory)() : new \Redis();
            // Disable phpredis command replay after reconnect; writes are never retried here.
            if (!defined('Redis::OPT_MAX_RETRIES') || !$client->setOption(\Redis::OPT_MAX_RETRIES, 0)
                || !$client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE)) { throw new \RuntimeException(); }
            $stream = ['stream' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host'], 'allow_self_signed' => false]];
            if (!$client->connect(($c['tls'] ? 'tls://' : '') . $c['host'], (int) ($c['port'] ?? 6379), 5, null, 0, 10, $stream)) { throw new \RuntimeException(); }
            if (isset($c['username']) && !isset($c['password'])) { throw new \RuntimeException(); }
            if (isset($c['password']) && !$client->auth(isset($c['username']) ? [$c['username'], $c['password']] : $c['password'])) { throw new \RuntimeException(); }
            if (!$client->select((int) ($c['database'] ?? 0))) { throw new \RuntimeException(); }
            $key = $c['prefix'] . $parameters['key'];
            $result = match ($op) {
                'get' => $client->get($key),
                'set' => $ttl > 0 ? $client->set($key, $value, ['ex' => $ttl]) : $client->set($key, $value),
                'delete' => $client->del($key), 'exists' => (bool) $client->exists($key),
                'increment' => $client->incrBy($key, $amount), 'decrement' => $client->decrBy($key, $amount),
                'expire' => $ttl === 0 ? $client->persist($key) : $client->expire($key, $ttl), 'ttl' => $client->ttl($key)
            };
            if (($op === 'set' && $result !== true) || (in_array($op, ['increment','decrement'], true) && $result === false)) { throw new \RuntimeException(); }
            if ($op === 'get') {
                $found = $result !== false;
                if ($found && strlen($result) > 1048576) { throw new \RuntimeException(); }
                $result = !$found ? null : (($parameters['format'] ?? 'text') === 'json' ? json_decode($result, true, 64, JSON_THROW_ON_ERROR) : $result);
                return ['success' => true, 'operation' => $op, 'found' => $found, 'value' => $result];
            }
            return ['success' => true, 'operation' => $op, 'value' => $result];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Redis: conexión, ACL, tipo almacenado o respuesta inválida. Una escritura puede haberse aplicado; no se reintenta automáticamente.');
        } finally {
            if ($client) { try { $client->close(); } catch (\Throwable $e) {} }
        }
    }
}
