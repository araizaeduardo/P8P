<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MicrosoftGraphNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;
    private const ORIGIN = 'https://graph.microsoft.com';
    private const MESSAGE_FIELDS = 'id,subject,from,toRecipients,receivedDateTime,isRead';
    private const CONTACT_FIELDS = 'id,displayName,givenName,surname,emailAddresses,businessPhones';

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'microsoft_graph', 'name' => 'Microsoft Outlook / Graph',
            'category' => 'Community', 'icon' => 'Mail', 'is_start' => false,
            'description' => 'Envía correo, crea eventos y lista una página de mensajes o contactos.',
            'help_text' => 'Credencial generic_bearer: API Key contiene JSON {"access_token":"TOKEN","auth_mode":"delegated"} o {"access_token":"TOKEN","auth_mode":"application","user_id":"usuario@example.com"}. Delegated usa /me; application exige user_id y consentimiento administrativo. Permisos: Mail.Send, Calendars.ReadWrite, Mail.ReadBasic (application: Mail.ReadBasic.All), Contacts.Read. Token y renovación manual en credenciales. Listados: una página, límite 1–100; pasa data.next_cursor con la misma operación, credencial y límite. Correo 202 significa aceptado, no entregado. Eventos: horas locales YYYY-MM-DDTHH:MM:SS y zona IANA/UTC admitida por el buzón; sin asistentes. transaction_id permite deduplicación oficial al repetir el mismo evento. Sin reintentos automáticos. Los eventos disponibles se configuran por separado. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Graph (JSON en API Key)'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'send_mail', 'options' => [
                    ['value' => 'send_mail', 'label' => 'Enviar correo'], ['value' => 'create_event', 'label' => 'Crear evento'],
                    ['value' => 'list_messages', 'label' => 'Listar mensajes'], ['value' => 'list_contacts', 'label' => 'Listar contactos']
                ]],
                'to_recipients' => ['type' => 'textarea', 'label' => 'Correo: destinatarios (array JSON de direcciones)', 'placeholder' => '["persona@example.com"]'],
                'subject' => ['type' => 'string', 'label' => 'Correo/evento: asunto'],
                'body' => ['type' => 'textarea', 'label' => 'Correo: cuerpo (puede estar vacío)'],
                'body_type' => ['type' => 'select', 'label' => 'Correo: formato', 'default' => 'Text', 'options' => [['value' => 'Text', 'label' => 'Texto'], ['value' => 'HTML', 'label' => 'HTML']]],
                'start_time' => ['type' => 'string', 'label' => 'Evento: inicio local', 'placeholder' => '2026-10-23T09:00:00'],
                'end_time' => ['type' => 'string', 'label' => 'Evento: fin local', 'placeholder' => '2026-10-23T10:00:00'],
                'time_zone' => ['type' => 'string', 'label' => 'Evento: zona IANA/UTC del buzón', 'default' => 'UTC'],
                'transaction_id' => ['type' => 'string', 'label' => 'Evento: ID de transacción estable (opcional)'],
                'limit' => ['type' => 'number', 'label' => 'Listados: máximo por página (1–100)', 'default' => 25],
                'cursor' => ['type' => 'textarea', 'label' => 'Listados: data.next_cursor de la página anterior']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepare($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine aplica ExpressionEvaluator una vez antes de ejecutar el nodo.
        $p = $this->prepare($parameters);
        $config = $this->credential($p['credential_id']);
        $root = $config['auth_mode'] === 'delegated' ? '/me' : '/users/' . rawurlencode($config['user_id']);
        $resource = match ($p['operation']) {
            'send_mail' => '/sendMail', 'create_event' => '/events',
            'list_messages' => '/messages', 'list_contacts' => '/contacts'
        };
        $path = '/v1.0' . $root . $resource;
        $write = isset($p['payload']);
        $url = self::ORIGIN . $path;
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $config['access_token'], 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'verify' => true,
            'allow_redirects' => false, 'http_errors' => false
        ];
        if ($write) {
            $options['json'] = $p['payload'];
        } else {
            $fields = $p['operation'] === 'list_messages' ? self::MESSAGE_FIELDS : self::CONTACT_FIELDS;
            if ($p['cursor'] !== '') {
                $this->checkCursor($p['cursor'], $path, $p['limit'], $fields);
                $url = $p['cursor'];
            } else {
                $options['query'] = ['$top' => $p['limit'], '$select' => $fields];
            }
        }
        try {
            $response = ($this->client ?? new Client())->request($write ? 'POST' : 'GET', $url, $options);
            $status = $response->getStatusCode();
            // Limitar también la lectura del cuerpo remoto.
            $stream = $response->getBody();
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 4 * 1024 * 1024) {
                $chunk = $stream->read(8192);
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Microsoft Graph: fallo de transporte o lectura.' . ($write
                ? ' Resultado incierto: la acción puede haberse realizado; verifica el buzón antes de repetir.' : ' No se obtuvo la página.'));
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'token inválido o caducado; renueva manualmente la credencial',
                403 => 'permisos insuficientes; revisa consentimiento, tipo de token y acceso al buzón',
                404 => 'recurso o buzón inexistente o inaccesible',
                429 => 'cuota o límite de solicitudes; espera antes de repetir',
                default => 'solicitud rechazada por el servicio'
            };
            throw new RuntimeException("Microsoft Graph: {$reason} (HTTP {$status})." . ($write && $status >= 500 ? ' Resultado incierto; verifica antes de repetir.' : ''), $status);
        }
        $invalid = 'Microsoft Graph: respuesta inválida o incompleta.' . ($write ? ' Resultado incierto; verifica el buzón antes de repetir.' : '');
        if (strlen($raw) > 4 * 1024 * 1024) {
            throw new RuntimeException($invalid, $status);
        }
        if ($p['operation'] === 'send_mail') {
            if ($status !== 202 || trim($raw) !== '') {
                throw new RuntimeException($invalid, $status);
            }
            return ['success' => true, 'status_code' => $status, 'data' => ['accepted' => true, 'delivered' => false]];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || array_is_list($data) || array_key_exists('error', $data)) {
            throw new RuntimeException($invalid, $status);
        }
        if ($write) {
            if ($status !== 201 || !is_string($data['id'] ?? null) || $data['id'] === '') {
                throw new RuntimeException($invalid, $status);
            }
            $data = array_intersect_key($data, array_flip(['id', 'subject', 'start', 'end', 'webLink', 'transactionId']));
        } else {
            if (!is_array(json_decode($raw)->value ?? null) || $status !== 200 || !is_array($data['value'] ?? null) || !array_is_list($data['value']) || count($data['value']) > $p['limit']) {
                throw new RuntimeException($invalid, $status);
            }
            $items = [];
            foreach ($data['value'] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || $item['id'] === '') {
                    throw new RuntimeException($invalid, $status);
                }
                $items[] = array_intersect_key($item, array_flip(explode(',', $fields)));
            }
            $cursor = $data['@odata.nextLink'] ?? null;
            if ($cursor !== null) {
                try {
                    $this->checkCursor($cursor, $path, $p['limit'], $fields);
                } catch (\Throwable $e) {
                    throw new RuntimeException('Microsoft Graph: enlace de paginación remoto inválido.', $status);
                }
            }
            $data = ['value' => $items, 'next_cursor' => $cursor];
        }
        // Nunca permitir que un servicio refleje el secreto en el historial.
        if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $config['access_token'])) {
            throw new RuntimeException($invalid, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepare(array $p): array
    {
        $id = $this->text($p['credential_id'] ?? null, 'credential_id', true, 200);
        $op = array_key_exists('operation', $p) ? $p['operation'] : 'send_mail';
        if (!is_string($op) || !in_array($op, ['send_mail', 'create_event', 'list_messages', 'list_contacts'], true)) {
            throw new InvalidArgumentException('Microsoft Graph: operación inválida.');
        }
        $result = ['credential_id' => $id, 'operation' => $op];
        if ($op === 'send_mail' || $op === 'create_event') {
            $subject = $this->text($p['subject'] ?? null, 'subject', true, 255);
            if ($op === 'send_mail') {
                $recipients = $p['to_recipients'] ?? null;
                if (is_string($recipients)) {
                    $recipients = json_decode($recipients, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new InvalidArgumentException('Microsoft Graph: destinatarios JSON inválidos.');
                    }
                } elseif ($recipients instanceof \stdClass) {
                    $recipients = (array) $recipients;
                }
                if (!is_array($recipients) || !array_is_list($recipients) || count($recipients) < 1 || count($recipients) > 100) {
                    throw new InvalidArgumentException('Microsoft Graph: indica entre 1 y 100 direcciones destinatarias.');
                }
                $to = [];
                foreach ($recipients as $address) {
                    if (!is_string($address) || strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        throw new InvalidArgumentException('Microsoft Graph: dirección destinataria inválida.');
                    }
                    $to[] = ['emailAddress' => ['address' => $address]];
                }
                $body = $this->text(array_key_exists('body', $p) ? $p['body'] : '', 'body', false, 1000000);
                $type = array_key_exists('body_type', $p) ? $p['body_type'] : 'Text';
                if (!in_array($type, ['Text', 'HTML'], true)) {
                    throw new InvalidArgumentException('Microsoft Graph: formato del cuerpo inválido.');
                }
                $result['payload'] = ['message' => ['subject' => $subject, 'body' => ['contentType' => $type, 'content' => $body], 'toRecipients' => $to]];
            } else {
                $zone = $this->text(array_key_exists('time_zone', $p) ? $p['time_zone'] : 'UTC', 'time_zone', true, 100);
                if ($zone !== 'UTC' && !in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                    throw new InvalidArgumentException('Microsoft Graph: zona IANA/UTC inválida.');
                }
                $start = $this->localDate($p['start_time'] ?? null, $zone);
                $end = $this->localDate($p['end_time'] ?? null, $zone);
                if ($end <= $start) {
                    throw new InvalidArgumentException('Microsoft Graph: el fin debe ser posterior al inicio.');
                }
                $result['payload'] = ['subject' => $subject, 'start' => ['dateTime' => $p['start_time'], 'timeZone' => $zone], 'end' => ['dateTime' => $p['end_time'], 'timeZone' => $zone]];
                if (array_key_exists('transaction_id', $p)) {
                    $transaction = $this->text($p['transaction_id'], 'transaction_id', false, 128);
                    if ($transaction !== '') {
                        if (preg_match('/^[A-Za-z0-9_-]+$/D', $transaction) !== 1) {
                            throw new InvalidArgumentException('Microsoft Graph: ID de transacción inválido.');
                        }
                        $result['payload']['transactionId'] = $transaction;
                    }
                }
            }
        } else {
            $limit = array_key_exists('limit', $p) ? $p['limit'] : 25;
            if (is_string($limit) && preg_match('/^[0-9]{1,3}$/D', $limit)) {
                $limit = (int) $limit;
            }
            if (!is_int($limit) || $limit < 1 || $limit > 100) {
                throw new InvalidArgumentException('Microsoft Graph: límite entero entre 1 y 100.');
            }
            $result['limit'] = $limit;
            $result['cursor'] = $this->text(array_key_exists('cursor', $p) ? $p['cursor'] : '', 'cursor', false, 16000);
        }
        return $result;
    }

    private function credential(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('Microsoft Graph: fallo del gestor al recuperar o descifrar la credencial.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Microsoft Graph: credencial inexistente.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($raw)) {
            throw new InvalidArgumentException('Microsoft Graph: usa generic_bearer con configuración JSON en API Key.');
        }
        $config = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)
            || !is_string($config['access_token'] ?? null) || strlen($config['access_token']) < 8
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $config['access_token']) !== 1
            || !in_array($config['auth_mode'] ?? null, ['delegated', 'application'], true)) {
            throw new InvalidArgumentException('Microsoft Graph: configuración de credencial JSON inválida.');
        }
        if ($config['auth_mode'] === 'application') {
            $user = $config['user_id'] ?? null;
            if (!is_string($user) || strlen($user) > 254
                || (filter_var($user, FILTER_VALIDATE_EMAIL) === false && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $user) !== 1)) {
                throw new InvalidArgumentException('Microsoft Graph: application requiere user_id (UUID o UPN).');
            }
        } elseif (isset($config['user_id']) && $config['user_id'] !== '') {
            throw new InvalidArgumentException('Microsoft Graph: delegated usa /me; omite user_id.');
        }
        return $config;
    }

    private function text(mixed $value, string $field, bool $required, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0") || ($required && trim($value) === '')) {
            throw new InvalidArgumentException("Microsoft Graph: campo {$field} inválido.");
        }
        return $value;
    }

    private function localDate(mixed $value, string $zone): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Microsoft Graph: fecha local requerida YYYY-MM-DDTHH:MM:SS.');
        }
        $tz = new DateTimeZone($zone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, $tz);
        if (!$date || $date->format('Y-m-d\TH:i:s') !== $value || substr($value, 0, 4) === '0000') {
            throw new InvalidArgumentException('Microsoft Graph: fecha u hora inexistente.');
        }
        // Rechazar horas ambiguas durante el retorno de horario estacional.
        $transitions = $tz->getTransitions($date->getTimestamp() - 172800, $date->getTimestamp() + 172800);
        $old = $transitions[0]['offset'] ?? 0;
        foreach (array_slice($transitions ?: [], 1) as $transition) {
            if ($transition['offset'] < $old && $date->getTimestamp() >= $transition['ts'] - ($old - $transition['offset'])
                && $date->getTimestamp() < $transition['ts'] + ($old - $transition['offset'])) {
                throw new InvalidArgumentException('Microsoft Graph: hora local ambigua; utiliza UTC.');
            }
            $old = $transition['offset'];
        }
        return $date;
    }

    private function checkCursor(mixed $cursor, string $path, int $limit, string $fields): void
    {
        if (!is_string($cursor) || strlen($cursor) > 16000 || preg_match('/[\x00-\x20\x7f\\\\]/', $cursor)) {
            throw new InvalidArgumentException('Microsoft Graph: cursor inválido.');
        }
        $parts = parse_url($cursor);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'graph.microsoft.com'
            || ($parts['path'] ?? '') !== $path || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Microsoft Graph: origen o recurso del cursor no permitido.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (($query['$top'] ?? null) !== (string) $limit || ($query['$select'] ?? null) !== $fields
            || array_diff(array_keys($query), ['$top', '$select', '$skip', '$skiptoken']) !== []
            || (!isset($query['$skip']) && !isset($query['$skiptoken']))) {
            throw new InvalidArgumentException('Microsoft Graph: cursor incompatible con el límite o selección actual.');
        }
        foreach ($query as $value) {
            if (!is_string($value) || strlen($value) > 12000) {
                throw new InvalidArgumentException('Microsoft Graph: consulta de cursor inválida.');
            }
        }
    }
}
