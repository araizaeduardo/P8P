<?php

namespace P8P\Core\TriggerProviders;

use P8P\Interfaces\TriggerProviderInterface;

/** Notion page notifications. The unsigned bootstrap never admits a workflow event. */
class NotionWebhooks implements TriggerProviderInterface
{
    public function definitions(): array
    {
        $field = static fn (string $label, bool $required = true): array => ['label' => $label, 'type' => 'string', 'required' => $required];
        $definitions = [];
        foreach (['created' => 'Página creada', 'properties_updated' => 'Propiedades de página modificadas', 'content_updated' => 'Contenido de página modificado'] as $suffix => $label) {
            $definitions['notion_webhook_page_' . $suffix] = [
                'name' => 'Notion webhook: ' . $label, 'node_type' => 'notion', 'event' => 'page.' . $suffix, 'transport' => 'webhook',
                'config_fields' => [
                    'workspace_id' => $field('UUID del workspace Notion'),
                    'integration_id' => $field('UUID de la integración Notion'),
                    'subscription_id' => $field('UUID de la suscripción (si se conoce)', false),
                ],
                'secret_fields' => ['verification_token' => $field('verification_token del handshake Notion (no el token API)')],
                'help' => 'Suscripción manual en Notion; HTTPS público. Crear borrador y capturar handshake sin activar. Consultar el token solo como administrador, verificarlo en Notion y guardarlo cifrado en la credencial antes de activar. Firma HMAC-SHA256 obligatoria en eventos; payload contiene IDs, no contenido completo. Ver docs/triggers-notion.md.',
                'docs' => ['https://developers.notion.com/reference/webhooks', 'https://developers.notion.com/reference/webhooks-events-delivery'],
            ];
        }
        return $definitions;
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        throw new \LogicException('Este evento Notion requiere webhook.');
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $definition = $this->definitions()[$key] ?? throw new \InvalidArgumentException('Evento Notion desconocido.');
        if (($request['method'] ?? '') !== 'POST') {
            throw new \InvalidArgumentException('Notion requiere POST.');
        }
        $raw = $request['raw_body'] ?? null;
        $headers = $request['headers'] ?? [];
        if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576 || !is_array($headers)) {
            throw new \InvalidArgumentException('Petición Notion inválida o demasiado grande.');
        }
        $signature = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'x-notion-signature') === 0) {
                if ($signature !== null || !is_string($value)) {
                    throw new \InvalidArgumentException('Encabezado de firma Notion ambiguo.');
                }
                $signature = $value;
            }
        }
        $result = ['status' => 200, 'body' => '', 'content_type' => 'text/plain', 'events' => []];
        if (($request['setup_allowed'] ?? false) === true && $signature === null && !isset($secrets['verification_token'])) {
            // setup_allowed is supplied only by the trusted draft-receiver path, never from
            // the public request JSON. The manager persists this candidate encrypted and
            // removes setup_secret from the public response before returning its ACK.
            $candidate = $this->json($raw);
            if (count($candidate) !== 1 || !isset($candidate['verification_token'])) {
                throw new \InvalidArgumentException('El alta Notion solo admite un verification_token.');
            }
            $token = $this->token($candidate['verification_token']);
            return $result + ['challenge' => true, 'setup_secret' => ['verification_token' => $token]];
        }

        $token = $this->token($secrets['verification_token'] ?? null);
        if (!is_string($signature) || !preg_match('/^sha256=[a-f0-9]{64}$/D', $signature)
            || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $token), $signature)) {
            throw new \InvalidArgumentException('Firma Notion inválida.');
        }
        // Parse only after verifying the exact original bytes, never a JSON reserialization.
        $payload = $this->json($raw);
        foreach (['workspace_id', 'integration_id'] as $scope) {
            if (!hash_equals($this->uuid($config[$scope] ?? null), $this->uuid($payload[$scope] ?? null))) {
                throw new \InvalidArgumentException('Evento Notion fuera de la configuración receptora.');
            }
        }
        $subscription = $this->uuid($payload['subscription_id'] ?? null);
        if (isset($config['subscription_id']) && $config['subscription_id'] !== ''
            && !hash_equals($this->uuid($config['subscription_id']), $subscription)) {
            throw new \InvalidArgumentException('Suscripción Notion distinta de la configurada.');
        }
        $id = $this->uuid($payload['id'] ?? null);
        $time = $payload['timestamp'] ?? null;
        if (!is_string($time) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $time)
            || ($occurred = strtotime($time)) === false || $occurred > $now + 300 || $occurred < $now - 90000) {
            throw new \InvalidArgumentException('Fecha Notion fuera de la ventana de recepción de 25 horas.');
        }
        if (!is_string($payload['type'] ?? null)) {
            throw new \InvalidArgumentException('Falta el tipo de evento Notion.');
        }
        if ($payload['type'] !== $definition['event']) {
            return $result; // Authenticated but not subscribed by this receiver: ACK without enqueue.
        }
        if (!is_array($payload['entity'] ?? null) || ($payload['entity']['type'] ?? null) !== 'page') {
            throw new \InvalidArgumentException('Entidad de página Notion inválida.');
        }
        $entityId = $this->uuid($payload['entity']['id'] ?? null);
        $attempt = $payload['attempt_number'] ?? null;
        if (!is_int($attempt) || $attempt < 1 || $attempt > 8) {
            throw new \InvalidArgumentException('Intento de entrega Notion inválido.');
        }
        $detail = $payload['data'] ?? null;
        if (!is_array($detail) || ($detail !== [] && array_is_list($detail))) {
            throw new \InvalidArgumentException('Datos de evento Notion inválidos.');
        }
        $data = [
            'id' => $id, 'type' => $payload['type'], 'timestamp' => $time,
            'workspace_id' => strtolower($payload['workspace_id']), 'integration_id' => strtolower($payload['integration_id']),
            'subscription_id' => $subscription, 'entity' => ['id' => $entityId, 'type' => 'page'],
            'authors' => $this->identities($payload['authors'] ?? [], ['person', 'bot', 'agent']),
            'attempt_number' => $attempt, 'data' => [],
        ];
        if (isset($payload['api_version'])) {
            if (!is_string($payload['api_version']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $payload['api_version'])) {
                throw new \InvalidArgumentException('Versión de evento Notion inválida.');
            }
            $data['api_version'] = $payload['api_version'];
        }
        if (isset($detail['parent'])) {
            if (!is_array($detail['parent']) || !in_array($detail['parent']['type'] ?? null, ['page', 'database', 'data_source', 'space', 'workspace', 'block'], true)) {
                throw new \InvalidArgumentException('Padre Notion inválido.');
            }
            $data['data']['parent'] = ['id' => $this->uuid($detail['parent']['id'] ?? null), 'type' => $detail['parent']['type']];
        }
        if ($payload['type'] === 'page.properties_updated') {
            $properties = $detail['updated_properties'] ?? [];
            if (!is_array($properties) || !array_is_list($properties) || count($properties) > 1000) {
                throw new \InvalidArgumentException('Lista de propiedades Notion inválida.');
            }
            foreach ($properties as $property) {
                if (!is_string($property) || $property === '' || strlen($property) > 256 || preg_match('/[\x00-\x1f\x7f]/', $property)) {
                    throw new \InvalidArgumentException('Identificador de propiedad Notion inválido.');
                }
            }
            $data['data']['updated_properties'] = $properties;
        } elseif ($payload['type'] === 'page.content_updated') {
            $data['data']['updated_blocks'] = $this->identities($detail['updated_blocks'] ?? [], ['block'], 1000);
        }
        // A provider should not include the signing secret in payload data. Redact any echo.
        array_walk_recursive($data, static function (&$value) use ($token): void {
            if (is_string($value)) {
                $value = str_replace($token, '[redactado]', $value);
            }
        });
        $result['events'][] = ['id' => $id, 'type' => $payload['type'], 'occurred_at' => $time, 'data' => $data];
        return $result;
    }

    private function json(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('JSON Notion inválido.');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('El cuerpo Notion debe ser un objeto JSON.');
        }
        return $value;
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            throw new \InvalidArgumentException('UUID Notion inválido.');
        }
        return strtolower($value);
    }

    private function token(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 16 || strlen($value) > 512 || !preg_match('/^[a-zA-Z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('verification_token Notion inválido.');
        }
        return $value;
    }

    private function identities(mixed $items, array $types, int $limit = 100): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > $limit) {
            throw new \InvalidArgumentException('Lista de identificadores Notion inválida.');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || !in_array($item['type'] ?? null, $types, true)) {
                throw new \InvalidArgumentException('Tipo de identificador Notion inválido.');
            }
            $result[] = ['id' => $this->uuid($item['id'] ?? null), 'type' => $item['type']];
        }
        return $result;
    }
}
