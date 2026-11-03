<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Lecturas incrementales: una página por tick, ventanas y cursores serializables. */
class BusinessPollingProviders implements TriggerProviderInterface
{
    private ?ClientInterface $client;
    private ?int $requestNow = null;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client;
    }

    public function definitions(): array
    {
        $field = static fn (string $label, bool $required = true): array => ['label' => $label, 'type' => 'string', 'required' => $required];
        $token = ['access_token' => $field('Token de acceso / PAT')];
        $groups = [
            'airtable' => ['Airtable', ['record_created', 'record_updated'], ['base_id' => $field('ID base app...'), 'table' => $field('ID o nombre de tabla')], $token, 'https://airtable.com/developers/web/api/list-records'],
            'notion' => ['Notion', ['page_created', 'page_updated'], ['data_source_id' => $field('ID de fuente de datos')], $token, 'https://developers.notion.com/reference/query-a-data-source'],
            'asana' => ['Asana', ['task_created', 'task_updated'], ['project_id' => $field('GID proyecto')], $token, 'https://developers.asana.com/reference/getTasks'],
            'trello' => ['Trello', ['card_created', 'card_moved'], ['board_id' => $field('ID de tablero')], ['api_key' => $field('API key de aplicación'), 'token' => $field('Token usuario')], 'https://developer.atlassian.com/cloud/trello/guides/rest-api/api-introduction/'],
            'jira' => ['Jira Cloud', ['issue_created', 'issue_updated'], ['project_key' => $field('Clave del proyecto'), 'user_timezone' => $field('Zona horaria de la cuenta: UTC')], ['site_url' => $field('https://empresa.atlassian.net'), 'email' => $field('Correo usuario'), 'api_token' => $field('API token sin scopes')], 'https://developer.atlassian.com/cloud/jira/platform/rest/v3/api-group-issue-search/'],
            'hubspot' => ['HubSpot', ['contact_created', 'contact_updated', 'deal_created', 'deal_updated'], [], $token, 'https://developers.hubspot.com/docs/api-reference/crm-contacts-v3/search/post-crm-v3-objects-contacts-search'],
            'salesforce' => ['Salesforce', ['record_created', 'record_updated'], ['object' => $field('Objeto: Lead, Account, Contact u Opportunity')], ['instance_url' => $field('Origen HTTPS instancia Salesforce'), 'api_version' => $field('Versión API admitida (v66.0)'), 'access_token' => $field('Token OAuth scope api')], 'https://developer.salesforce.com/docs/platform/api-rest/guide/resources-query.html'],
            'wordpress' => ['WordPress', ['post_published', 'post_updated'], [], ['site_url' => $field('URL HTTPS sitio'), 'username' => $field('Usuario'), 'application_password' => $field('Application Password')], 'https://developer.wordpress.org/rest-api/reference/posts/'],
            'discord' => ['Discord', ['message_created'], ['channel_id' => $field('ID del canal')], ['bot_token' => $field('Token de bot')], 'https://docs.discord.com/developers/resources/message#get-channel-messages'],
        ];
        $definitions = [];
        foreach ($groups as $provider => [$name, $events, $config, $secrets, $docs]) {
            foreach ($events as $event) {
                $definitions[$provider . '_' . $event] = [
                    'name' => $name . ': ' . str_replace('_', ' ', $event), 'node_type' => $provider,
                    'event' => $event, 'transport' => 'polling', 'config_fields' => $config,
                    'secret_fields' => $secrets, 'docs' => [$docs],
                    'help' => 'Una página (máximo 100) por intento; sin histórico inicial. Metadatos del recurso en event.data. Consultar docs/triggers-business.md para permisos, límites y diferencias frente a webhooks.',
                ];
                if ($provider === 'airtable' && $event === 'record_updated') {
                    $definitions['airtable_record_updated']['config_fields']['modified_field'] = $field('Nombre del campo Last modified time (todos los campos)');
                }
            }
        }
        $webhooks = [
            'paypal_payment_capture_completed' => ['PayPal: captura completada', 'paypal', 'PAYMENT.CAPTURE.COMPLETED', ['environment' => $field('sandbox o live')], ['client_id' => $field('Client ID'), 'client_secret' => $field('Client Secret'), 'webhook_id' => $field('Webhook ID')], 'https://developer.paypal.com/api/webhooks/v1/verify-webhook-signature-post'],
            'paypal_subscription_created' => ['PayPal: suscripción creada', 'paypal', 'BILLING.SUBSCRIPTION.CREATED', ['environment' => $field('sandbox o live')], ['client_id' => $field('Client ID'), 'client_secret' => $field('Client Secret'), 'webhook_id' => $field('Webhook ID')], 'https://developer.paypal.com/api/webhooks/v1/verify-webhook-signature-post'],
            'resend_email_delivered' => ['Resend: entregado', 'transactional_email', 'email.delivered', [], ['signing_secret' => $field('Signing secret whsec_...')], 'https://resend.com/docs/webhooks/verify-webhooks-requests'],
            'resend_email_bounced' => ['Resend: rebote', 'transactional_email', 'email.bounced', [], ['signing_secret' => $field('Signing secret whsec_...')], 'https://resend.com/docs/webhooks/verify-webhooks-requests'],
            'sendgrid_email_delivered' => ['SendGrid: entregado', 'transactional_email', 'delivered', [], ['verification_key' => $field('Clave pública Base64 ECDSA')], 'https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features'],
            'sendgrid_email_bounced' => ['SendGrid: rebote', 'transactional_email', 'bounce', [], ['verification_key' => $field('Clave pública Base64 ECDSA')], 'https://www.twilio.com/docs/sendgrid/for-developers/tracking-events/getting-started-event-webhook-security-features'],
            'mailchimp_subscriber_changed' => ['Mailchimp: suscripción/cambio de contacto', 'mailchimp', 'subscriber_changed', ['list_id' => $field('ID audiencia')], ['signing_secret' => $field('Signing secret webhook HMAC')], 'https://mailchimp.com/developer/marketing/api-concepts/webhooks/'],
            'linkedin_organization_social_action' => ['LinkedIn: acción social de organización', 'linkedin', 'ORGANIZATION_SOCIAL_ACTION_NOTIFICATIONS', ['organization_urn' => $field('urn:li:organization:123')], ['client_secret' => $field('Client secret aplicación aprobada')], 'https://learn.microsoft.com/en-us/linkedin/shared/api-guide/webhook-validation'],
        ];
        foreach ($webhooks as $key => [$name, $nodeType, $event, $config, $secretFields, $docs]) {
            $definitions[$key] = ['name' => $name, 'node_type' => $nodeType, 'event' => $event, 'transport' => 'webhook',
                'config_fields' => $config, 'secret_fields' => $secretFields, 'docs' => [$docs],
                'help' => 'Registro externo manual requerido tras desplegar HTTPS. Ver docs/triggers-business.md; solo se entregan metadatos filtrados.'];
        }
        foreach (['contact' => 'contacto', 'deal' => 'negocio'] as $object => $label) {
            foreach (['created' => 'creation', 'property_changed' => 'propertyChange'] as $suffix => $event) {
                $key = 'hubspot_webhook_' . $object . '_' . $suffix;
                $fields = ['portal_id' => $field('ID de cuenta HubSpot'), 'app_id' => $field('ID de aplicación HubSpot')];
                if ($event === 'propertyChange') {
                    $fields['property_name'] = $field('Nombre interno de propiedad suscrita');
                }
                $definitions[$key] = ['name' => 'HubSpot webhook: ' . $label . ' ' . $suffix, 'node_type' => 'hubspot',
                    'event' => $object . '.' . $event, 'transport' => 'webhook', 'config_fields' => $fields,
                    'secret_fields' => ['client_secret' => $field('Client secret de la app (firma v3)')],
                    'docs' => ['https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/request-validation', 'https://developers.hubspot.com/docs/api-reference/legacy/webhooks/guide'],
                    'help' => 'Registra el evento y callback exactos en la app HubSpot. Se exige firma v3; el token privado de acciones no sustituye client_secret. El target URL es por app, no por suscripción: usar una app/configuración receptora dedicada.'];
            }
        }
        return $definitions;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $this->requestNow = $now;
        $definition = $this->definitions()[$key] ?? null;
        if (($definition['transport'] ?? null) !== 'webhook') {
            throw new LogicException('Este trigger utiliza polling; no recibe webhooks.');
        }
        foreach ($definition['secret_fields'] as $name => $field) {
            $this->scalar($secrets[$name] ?? null, 8192);
        }
        $method = $request['method'] ?? 'POST';
        $headers = $request['headers'] ?? [];
        $raw = $request['raw_body'] ?? '';
        if (!is_string($raw) || strlen($raw) > 1048576) {
            throw new RuntimeException('Webhook: tamaño inválido.');
        }
        if ($key === 'linkedin_organization_social_action' && $method === 'GET') {
            $challenge = $this->scalar($request['query']['challengeCode'] ?? null, 128);
            if (!preg_match('/^[a-zA-Z0-9-]{1,128}$/D', $challenge)) {
                throw new RuntimeException('Desafío LinkedIn inválido.');
            }
            return ['events' => [], 'status' => 200, 'body' => json_encode(['challengeCode' => $challenge, 'challengeResponse' => hash_hmac('sha256', $challenge, $secrets['client_secret'])]), 'content_type' => 'application/json'];
        }
        if ($key === 'mailchimp_subscriber_changed' && $method === 'GET') {
            return ['events' => [], 'status' => 200, 'body' => '', 'content_type' => 'text/plain'];
        }
        if ($method !== 'POST') {
            throw new RuntimeException('Webhook requiere POST autenticado.');
        }
        $events = [];
        if (str_starts_with($key, 'resend_')) {
            $id = $this->scalar($headers['svix-id'] ?? null, 256);
            $ts = $this->signedTimestamp($headers['svix-timestamp'] ?? null, $now);
            $secret = $secrets['signing_secret'];
            $decoded = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : false;
            if ($decoded === false || strlen($decoded) < 16) {
                throw new RuntimeException('Resend: signing secret inválido.');
            }
            $expected = base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $raw, $decoded, true));
            $valid = false;
            foreach (explode(' ', $this->scalar($headers['svix-signature'] ?? null, 4096)) as $candidate) {
                if (str_starts_with($candidate, 'v1,') && hash_equals($expected, substr($candidate, 3))) {
                    $valid = true;
                }
            }
            $this->signature($valid);
            $body = $this->webhookJson($raw);
            if (($body['type'] ?? null) === $definition['event']) {
                $events[] = $this->webhookEvent($key, $id, $body['created_at'] ?? null, ['email_id' => $this->scalar($body['data']['email_id'] ?? null, 128), 'event' => $definition['event']]);
            }
        } elseif (str_starts_with($key, 'sendgrid_')) {
            $ts = $this->signedTimestamp($headers['x-twilio-email-event-webhook-timestamp'] ?? null, $now);
            $sig = base64_decode($this->scalar($headers['x-twilio-email-event-webhook-signature'] ?? null, 4096), true);
            $der = base64_decode($secrets['verification_key'], true);
            if ($sig === false || $der === false) {
                throw new RuntimeException('SendGrid: firma o clave inválida.');
            }
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $publicKey = @openssl_pkey_get_public($pem);
            $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
            $this->signature(is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC
                && ($details['ec']['curve_name'] ?? null) === 'prime256v1'
                && @openssl_verify($ts . $raw, $sig, $publicKey, OPENSSL_ALGO_SHA256) === 1);
            $body = $this->webhookJson($raw);
            if (!array_is_list($body) || count($body) > 1000) {
                throw new RuntimeException('SendGrid: lote inválido.');
            }
            foreach ($body as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('SendGrid: evento inválido.');
                }
                if (($row['event'] ?? null) === $definition['event']) {
                    if (!is_int($row['timestamp'] ?? null)) {
                        throw new RuntimeException('SendGrid: fecha de evento inválida.');
                    }
                    $events[] = $this->webhookEvent($key, $this->scalar($row['sg_event_id'] ?? null, 256), $this->iso($row['timestamp']), ['message_id' => $this->scalar($row['sg_message_id'] ?? null, 256), 'event' => $definition['event']]);
                }
            }
        } elseif ($key === 'mailchimp_subscriber_changed') {
            $header = $this->scalar($headers['x-mailchimp-signature'] ?? null, 4096);
            if (!preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $header, $match)) {
                throw new RuntimeException('Mailchimp: firma ausente o malformada.');
            }
            $ts = $this->signedTimestamp($match[1], $now);
            $this->signature(hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secrets['signing_secret']), $match[2]));
            // Classic Marketing webhooks use form encoding; signed JSON deliveries are also accepted.
            if (str_starts_with(ltrim($raw), '{')) {
                $body = $this->webhookJson($raw);
            } else {
                parse_str($raw, $body);
            }
            if (($body['data']['list_id'] ?? null) !== ($config['list_id'] ?? null)) {
                throw new RuntimeException('Mailchimp: audiencia ajena a la configuración.');
            }
            if (in_array($body['type'] ?? null, ['subscribe', 'unsubscribe', 'profile', 'upemail', 'cleaned'], true)) {
                $subscriber = $this->scalar($body['data']['id'] ?? null, 128);
                $fired = $this->scalar($body['fired_at'] ?? null, 64);
                $at = strtotime($fired . ' UTC');
                if ($at === false) {
                    throw new RuntimeException('Mailchimp: fecha de evento inválida.');
                }
                $eventId = hash('sha256', $config['list_id'] . ':' . $subscriber . ':' . $body['type'] . ':' . $fired . ':' . hash('sha256', $raw));
                $events[] = $this->webhookEvent($key, $eventId, $this->iso($at), ['subscriber_id' => $subscriber, 'list_id' => $config['list_id'], 'event' => $body['type']]);
            }
        } elseif ($key === 'linkedin_organization_social_action') {
            $this->signature(hash_equals(hash_hmac('sha256', 'hmacsha256=' . $raw, $secrets['client_secret']), $this->scalar($headers['x-li-signature'] ?? null, 128)));
            $body = $this->webhookJson($raw);
            if (($body['type'] ?? null) !== 'ORGANIZATION_SOCIAL_ACTION_NOTIFICATIONS') {
                return ['events' => []];
            }
            foreach ($this->list($body, 'notifications') as $row) {
                if (($row['organizationalEntity'] ?? null) !== ($config['organization_urn'] ?? null)) {
                    throw new RuntimeException('LinkedIn: organización ajena a la configuración.');
                }
                if (!is_int($row['lastModifiedAt'] ?? null)) {
                    throw new RuntimeException('LinkedIn: fecha inválida.');
                }
                $events[] = $this->webhookEvent($key, $this->scalar($row['notificationId'] ?? null, 128), $this->iso((int) floor($row['lastModifiedAt'] / 1000)), array_intersect_key($row, array_flip(['notificationId', 'organizationalEntity', 'action', 'sourcePost', 'lastModifiedAt', 'generatedActivity'])));
            }
        } elseif (str_starts_with($key, 'hubspot_webhook_')) {
            $ts = $this->scalar($headers['x-hubspot-request-timestamp'] ?? null, 16);
            if (!preg_match('/^\d{13}$/D', $ts) || abs($now * 1000 - (int) $ts) > 300000) {
                throw new RuntimeException('HubSpot: fecha de firma fuera de ventana.');
            }
            $uri = $this->scalar($request['callback_url'] ?? null, 8192);
            $parts = parse_url($uri);
            if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('HubSpot: callback HTTPS canónico inválido.');
            }
            // HubSpot requires decoding only this documented character set, not general URL decoding.
            $decodedUri = preg_replace_callback('/%(?:3A|2F|3F|40|21|24|27|28|29|2A|2C|3B)/i', static fn (array $m): string => rawurldecode($m[0]), $uri);
            $expected = base64_encode(hash_hmac('sha256', $method . $decodedUri . $raw . $ts, $secrets['client_secret'], true));
            $this->signature(hash_equals($expected, $this->scalar($headers['x-hubspot-signature-v3'] ?? null, 256)));
            $body = $this->webhookJson($raw);
            if (!array_is_list($body) || count($body) > 100) {
                throw new RuntimeException('HubSpot: lote inválido o superior a 100 eventos.');
            }
            foreach (['portal_id', 'app_id'] as $field) {
                if (!preg_match('/^\d{1,20}$/D', $this->scalar($config[$field] ?? null, 20))) {
                    throw new InvalidArgumentException('HubSpot: ID de cuenta o app inválido.');
                }
            }
            $property = $config['property_name'] ?? '';
            if (str_ends_with($definition['event'], '.propertyChange') && (!is_string($property) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $property)
                || in_array($property, ['num_unique_conversion_events', 'hs_lastmodifieddate'], true))) {
                throw new InvalidArgumentException('HubSpot: propiedad no suscribible o nombre inválido.');
            }
            foreach ($body as $row) {
                if (!is_array($row) || (string) ($row['portalId'] ?? '') !== (string) $config['portal_id']
                    || (string) ($row['appId'] ?? '') !== (string) $config['app_id']) {
                    throw new RuntimeException('HubSpot: cuenta o aplicación ajena a la configuración.');
                }
                $eventType = $row['subscriptionType'] ?? $row['eventType'] ?? null;
                if ($eventType !== $definition['event'] || ($property !== '' && ($row['propertyName'] ?? null) !== $property)) {
                    continue;
                }
                if (!is_int($row['occurredAt'] ?? null) || $row['occurredAt'] < 0) {
                    throw new RuntimeException('HubSpot: fecha de evento inválida.');
                }
                $objectId = $this->scalar($row['objectId'] ?? null, 128);
                $eventId = $this->scalar($row['eventId'] ?? null, 128);
                $subscriptionId = $this->scalar($row['subscriptionId'] ?? null, 128);
                $identity = [$config['portal_id'], $config['app_id'], $objectId, $eventType, $property,
                    $row['occurredAt'], $eventId, $subscriptionId, $row['propertyValue'] ?? null];
                $id = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
                $events[] = $this->webhookEvent($key, $id, $this->iso((int) floor($row['occurredAt'] / 1000)), [
                    'object_id' => $objectId, 'portal_id' => (string) $config['portal_id'], 'app_id' => (string) $config['app_id'],
                    'event' => $eventType, 'property_name' => $property ?: null, 'occurred_at_ms' => $row['occurredAt'],
                ]);
            }
        } elseif (str_starts_with($key, 'paypal_')) {
            if (!in_array($config['environment'] ?? null, ['sandbox', 'live'], true)) {
                throw new RuntimeException('PayPal: entorno inválido.');
            }
            $root = $config['environment'] === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
            $body = $this->webhookJson($raw);
            $verification = ['webhook_id' => $secrets['webhook_id'], 'webhook_event' => $body];
            foreach (['auth_algo' => 'paypal-auth-algo', 'cert_url' => 'paypal-cert-url', 'transmission_id' => 'paypal-transmission-id', 'transmission_sig' => 'paypal-transmission-sig', 'transmission_time' => 'paypal-transmission-time'] as $name => $header) {
                $verification[$name] = $this->scalar($headers[$header] ?? null, 4096);
            }
            $transmitted = $this->timestamp($verification['transmission_time']);
            if (abs($now - $transmitted) > 300) {
                throw new RuntimeException('PayPal: transmisión fuera de ventana.');
            }
            // No fetch of payload-controlled certificate URLs: PayPal verifies with the receiver's webhook ID.
            [$token] = $this->request('POST', $root . '/v1/oauth2/token', ['headers' => ['Authorization' => 'Basic ' . base64_encode($secrets['client_id'] . ':' . $secrets['client_secret'])], 'form_params' => ['grant_type' => 'client_credentials']]);
            $accessToken = $this->scalar($token['access_token'] ?? null, 8192);
            [$result] = $this->request('POST', $root . '/v1/notifications/verify-webhook-signature', ['headers' => ['Authorization' => 'Bearer ' . $accessToken], 'json' => $verification]);
            $this->signature(($result['verification_status'] ?? null) === 'SUCCESS');
            if (($body['event_type'] ?? null) === $definition['event']) {
                $events[] = $this->webhookEvent($key, $this->scalar($body['id'] ?? null, 256), $body['create_time'] ?? null, [$key === 'paypal_subscription_created' ? 'subscription_id' : 'capture_id' => $this->scalar($body['resource']['id'] ?? null, 128), 'status' => $this->scalar($body['resource']['status'] ?? null, 64), 'event' => $definition['event']]);
            }
        }
        $this->noSecrets($events, $secrets);
        return ['events' => $events, 'status' => 200, 'body' => '', 'content_type' => 'text/plain'];
    }

    private function signedTimestamp(mixed $timestamp, int $now): string
    {
        if (!is_string($timestamp) || !preg_match('/^\d{1,12}$/D', $timestamp) || abs($now - (int) $timestamp) > 300) {
            throw new RuntimeException('Webhook: fecha de firma fuera de ventana.');
        }
        return $timestamp;
    }

    private function signature(bool $valid): void
    {
        if (!$valid) {
            throw new RuntimeException('Webhook: firma inválida.');
        }
    }

    private function webhookJson(string $raw): array
    {
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Webhook: JSON inválido.');
        }
        if (!is_array($body)) {
            throw new RuntimeException('Webhook: estructura inválida.');
        }
        return $body;
    }

    private function webhookEvent(string $key, string $id, mixed $at, array $data): array
    {
        $this->timestamp($at);
        $this->metadata($data);
        return ['id' => $key . ':' . $id, 'type' => $key, 'occurred_at' => $at, 'data' => $data];
    }

    private function noSecrets(array $events, array $secrets): void
    {
        $encoded = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($secrets as $name => $secret) {
            if (in_array($name, ['site_url', 'instance_url', 'api_version', 'username', 'email', 'webhook_id', 'client_id'], true)) {
                continue;
            }
            if (is_string($secret) && strlen($secret) >= 8 && str_contains($encoded, $secret)) {
                throw new RuntimeException('El proveedor reflejó un secreto en los metadatos; evento rechazado.');
            }
        }
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        $this->requestNow = $now;
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Tipo de polling desconocido.');
        }
        $provider = $definition['node_type'];
        $event = $definition['event'];
        if ($definition['transport'] !== 'polling') {
            throw new LogicException('Este trigger requiere webhook.');
        }
        $this->validateConfiguration($provider, $config, $secrets);
        if ($key === 'airtable_record_updated' && (!is_string($config['modified_field'] ?? null) || !preg_match('/^[^{}\x00-\x1f]{1,128}$/uD', $config['modified_field']))) {
            throw new InvalidArgumentException('Airtable actualizado requiere un campo Last modified time que observe todos los campos.');
        }
        $boundConfig = $config;
        $boundSecrets = $secrets;
        ksort($boundConfig);
        ksort($boundSecrets);
        $binding = hash('sha256', json_encode([$key, $boundConfig, $boundSecrets], JSON_THROW_ON_ERROR));
        if (isset($state['binding']) && (!is_string($state['binding']) || !hash_equals($state['binding'], $binding))) {
            throw new RuntimeException('El recurso o la credencial cambió; pausa y reactiva el trigger para establecer una nueva línea base.');
        }
        $state['binding'] = $binding;
        $activated = $this->timestamp($state['activated_at'] ?? null);
        if (!isset($state['watermark'])) {
            // No leer el histórico. Conservar la fecha de activación para recuperar después del reinicio.
            $state['watermark'] = $this->iso($activated);
            return ['events' => [], 'state' => $state, 'has_more' => false];
        }
        $watermark = $this->timestamp($state['watermark']);
        $window = $state['window'] ?? [
            'lower' => max($activated, $watermark - 300),
            'upper' => max($activated, $now - 30),
            'cursor' => null,
        ];
        if (!is_array($window) || !is_int($window['lower'] ?? null) || !is_int($window['upper'] ?? null)
            || $window['lower'] > $window['upper']) {
            throw new RuntimeException('Estado de polling inválido; requiere revisión administrativa.');
        }
        if ($window['upper'] <= $activated) {
            return ['events' => [], 'state' => $state, 'has_more' => false];
        }
        [$records, $next] = $this->page($provider, $event, $config, $secrets, $window);
        if (count($records) > 100) {
            throw new RuntimeException('El proveedor excedió el tamaño de página solicitado.');
        }
        $events = [];
        foreach ($records as $record) {
            $this->timestamp($record['at'] ?? null);
            $at = (float) (new \DateTimeImmutable($record['at']))->format('U.u');
            // El filtro local asegura que resultados fuera de ventana no adelanten el cursor ni disparen históricos.
            if ($at <= $activated || $at < $window['lower'] || $at > $window['upper']) {
                continue;
            }
            $id = $this->scalar($record['id'] ?? null, 256);
            $events[] = [
                'id' => $key . ':' . $id . ':' . ($record['version'] ?? $record['at']),
                'type' => $key, 'occurred_at' => $record['at'], 'data' => $record['data'],
            ];
        }
        if ($next !== null) {
            if (($window['cursor'] ?? null) === $next) {
                throw new RuntimeException('El proveedor devolvió un cursor sin progreso.');
            }
            $window['cursor'] = $next;
            $state['window'] = $window;
        } else {
            $state['watermark'] = $this->iso($window['upper']);
            unset($state['window']);
        }
        $this->noSecrets($events, $secrets);
        return ['events' => $events, 'state' => $state, 'has_more' => $next !== null];
    }

    private function validateConfiguration(string $provider, array $config, array $secrets): void
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['node_type'] !== $provider) {
                continue;
            }
            foreach (['config_fields' => $config, 'secret_fields' => $secrets] as $group => $values) {
                foreach ($definition[$group] as $name => $field) {
                    $this->scalar($values[$name] ?? null, 8192);
                }
            }
            break;
        }
        $idFields = match ($provider) {
            'airtable' => ['base_id'], 'asana' => ['project_id'], 'trello' => ['board_id'],
            'notion' => ['data_source_id'], 'discord' => ['channel_id'], default => [],
        };
        foreach ($idFields as $name) {
            if (!preg_match('/^[A-Za-z0-9-]{1,128}$/D', $config[$name])) {
                throw new InvalidArgumentException('Identificador de recurso inválido.');
            }
        }
        if ($provider === 'jira') {
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $config['project_key']) || $config['user_timezone'] !== 'UTC') {
                throw new InvalidArgumentException('Jira requiere clave de proyecto válida y zona horaria de cuenta UTC.');
            }
            $this->origin($secrets['site_url'], 'jira');
            if (!filter_var($secrets['email'], FILTER_VALIDATE_EMAIL) || str_contains($secrets['email'], ':')) {
                throw new InvalidArgumentException('Correo de credencial Jira inválido.');
            }
        }
        if ($provider === 'salesforce') {
            if (!in_array($config['object'], ['Lead', 'Account', 'Contact', 'Opportunity'], true)
                || !preg_match('/^v[0-9]{2,3}\.0$/D', $secrets['api_version'])) {
                throw new InvalidArgumentException('Objeto o versión de Salesforce no admitido.');
            }
            $this->origin($secrets['instance_url'], 'salesforce');
        }
        if ($provider === 'wordpress') {
            $this->origin($secrets['site_url'], 'wordpress');
            if (str_contains($secrets['username'], ':')) {
                throw new InvalidArgumentException('Usuario WordPress inválido.');
            }
        }
        if ($provider === 'discord' && !preg_match('/^[0-9]{16,20}$/D', $config['channel_id'])) {
            throw new InvalidArgumentException('ID Discord inválido.');
        }
    }

    /** @return array{0:array,1:mixed} */
    private function page(string $provider, string $event, array $c, array $s, array $w): array
    {
        $created = str_ends_with($event, '_created') || $event === 'post_published';
        $lower = $this->iso($w['lower']);
        $upper = $this->iso($w['upper']);
        $cursor = $w['cursor'] ?? null;
        $options = ['headers' => ['Accept' => 'application/json']];
        if (in_array($provider, ['airtable', 'notion', 'asana', 'hubspot', 'salesforce'], true)) {
            $options['headers']['Authorization'] = 'Bearer ' . $s['access_token'];
        }
        $rows = [];
        $next = null;
        switch ($provider) {
            case 'airtable':
                $stamp = $created ? 'CREATED_TIME()' : '{' . $c['modified_field'] . '}';
                $options['query'] = ['pageSize' => 100, 'filterByFormula' => 'AND(' . $stamp . '>=DATETIME_PARSE("' . $lower . '"),' . $stamp . '<=DATETIME_PARSE("' . $upper . '"))'];
                if ($cursor !== null) {
                    $options['query']['offset'] = $this->cursor($cursor);
                }
                [$body] = $this->request('GET', 'https://api.airtable.com/v0/' . rawurlencode($c['base_id']) . '/' . rawurlencode($c['table']), $options);
                // Airtable does not return formula evaluation timestamps directly. Updated events require a Last modified time field.
                foreach ($this->list($body, 'records') as $row) {
                    $at = $created ? ($row['createdTime'] ?? null) : ($row['fields'][$c['modified_field']] ?? null);
                    $rows[] = $this->record($row['id'] ?? null, $at, ['id' => $row['id'] ?? null, 'created_time' => $row['createdTime'] ?? null]);
                }
                $next = isset($body['offset']) ? $this->cursor($body['offset']) : null;
                break;
            case 'notion':
                $stamp = $created ? 'created_time' : 'last_edited_time';
                $options['headers']['Notion-Version'] = '2026-03-11';
                $options['json'] = ['page_size' => 100, 'sorts' => [['timestamp' => $stamp, 'direction' => 'ascending']], 'filter' => ['and' => [
                    ['timestamp' => $stamp, $stamp => ['on_or_after' => $lower]], ['timestamp' => $stamp, $stamp => ['on_or_before' => $upper]],
                ]]];
                if ($cursor !== null) {
                    $options['json']['start_cursor'] = $this->cursor($cursor);
                }
                [$body] = $this->request('POST', 'https://api.notion.com/v1/data_sources/' . rawurlencode($c['data_source_id']) . '/query', $options);
                foreach ($this->list($body, 'results') as $row) {
                    $rows[] = $this->record($row['id'] ?? null, $row[$stamp] ?? null, array_intersect_key($row, array_flip(['id', 'created_time', 'last_edited_time', 'in_trash'])));
                }
                if (!is_bool($body['has_more'] ?? null)) {
                    throw new RuntimeException('Notion: indicador de paginación ausente.');
                }
                $next = ($body['has_more'] ?? false) ? $this->cursor($body['next_cursor'] ?? null) : null;
                break;
            case 'asana':
                $options['query'] = ['project' => $c['project_id'], 'modified_since' => $lower, 'completed_since' => '1970-01-01T00:00:00Z', 'limit' => 100, 'opt_fields' => 'gid,created_at,modified_at,completed'];
                if ($cursor !== null) {
                    $options['query']['offset'] = $this->cursor($cursor);
                }
                [$body] = $this->request('GET', 'https://app.asana.com/api/1.0/tasks', $options);
                foreach ($this->list($body, 'data') as $row) {
                    $rows[] = $this->record($row['gid'] ?? null, $row[$created ? 'created_at' : 'modified_at'] ?? null, array_intersect_key($row, array_flip(['gid', 'created_at', 'modified_at', 'completed'])));
                }
                $next = isset($body['next_page']['offset']) ? $this->cursor($body['next_page']['offset']) : null;
                break;
            case 'trello':
                $options['headers']['Authorization'] = 'OAuth oauth_consumer_key="' . rawurlencode($s['api_key']) . '", oauth_token="' . rawurlencode($s['token']) . '"';
                $options['query'] = ['filter' => $created ? 'createCard' : 'updateCard:idList', 'limit' => 100, 'since' => $lower, 'before' => $cursor === null ? $upper : $this->cursor($cursor), 'memberCreator' => 'false', 'member' => 'false'];
                [$body] = $this->request('GET', 'https://api.trello.com/1/boards/' . rawurlencode($c['board_id']) . '/actions', $options);
                foreach ($this->list($body) as $row) {
                    $rows[] = $this->record($row['id'] ?? null, $row['date'] ?? null, [
                        'action_id' => $row['id'] ?? null, 'card_id' => $row['data']['card']['id'] ?? null,
                        'list_before_id' => $row['data']['listBefore']['id'] ?? null, 'list_after_id' => $row['data']['listAfter']['id'] ?? null,
                    ]);
                }
                $next = count($body) === 100 ? $this->cursor($body[99]['id'] ?? null) : null;
                break;
            case 'jira':
                $stamp = $created ? 'created' : 'updated';
                $options['headers']['Authorization'] = 'Basic ' . base64_encode($s['email'] . ':' . $s['api_token']);
                $options['json'] = [
                    'jql' => 'project = "' . $c['project_key'] . '" AND ' . $stamp . ' >= "' . gmdate('Y-m-d H:i', $w['lower'] - 60) . '" AND ' . $stamp . ' <= "' . gmdate('Y-m-d H:i', $w['upper'] + 60) . '" ORDER BY ' . $stamp . ' ASC, id ASC',
                    'fields' => ['created', 'updated', 'status'], 'maxResults' => 100,
                ];
                if ($cursor !== null) {
                    $options['json']['nextPageToken'] = $this->cursor($cursor);
                }
                [$body] = $this->request('POST', $this->origin($s['site_url'], 'jira') . '/rest/api/3/search/jql', $options);
                foreach ($this->list($body, 'issues') as $row) {
                    $rows[] = $this->record($row['id'] ?? null, $row['fields'][$stamp] ?? null, ['id' => $row['id'] ?? null, 'key' => $row['key'] ?? null, 'status_id' => $row['fields']['status']['id'] ?? null]);
                }
                if (($body['isLast'] ?? true) === false && !isset($body['nextPageToken'])) {
                    throw new RuntimeException('Jira: falta cursor de continuación.');
                }
                $next = isset($body['nextPageToken']) ? $this->cursor($body['nextPageToken']) : null;
                break;
            case 'hubspot':
                $object = str_starts_with($event, 'contact_') ? 'contacts' : 'deals';
                $stamp = $created ? 'createdate' : ($object === 'contacts' ? 'lastmodifieddate' : 'hs_lastmodifieddate');
                $options['json'] = ['limit' => 100, 'sorts' => [$stamp], 'properties' => ['createdate', $stamp], 'filterGroups' => [['filters' => [
                    ['propertyName' => $stamp, 'operator' => 'GTE', 'value' => (string) ($w['lower'] * 1000)],
                    ['propertyName' => $stamp, 'operator' => 'LTE', 'value' => (string) ($w['upper'] * 1000)],
                ]]]];
                if ($cursor !== null) {
                    $options['json']['after'] = $this->cursor($cursor);
                }
                [$body] = $this->request('POST', 'https://api.hubapi.com/crm/v3/objects/' . $object . '/search', $options);
                if (($body['total'] ?? 0) > 10000) {
                    // Search's 10k cap must never silently advance a watermark.
                    throw new RuntimeException('HubSpot: ventana superior a 10000 resultados; reduce la carga o usa webhooks. Cursor conservado.');
                }
                foreach ($this->list($body, 'results') as $row) {
                    $rows[] = $this->record($row['id'] ?? null, $row['properties'][$stamp] ?? null, ['id' => $row['id'] ?? null, 'object_type' => $object, 'created_at' => $row['createdAt'] ?? null, 'updated_at' => $row['updatedAt'] ?? null]);
                }
                $next = isset($body['paging']['next']['after']) ? $this->cursor($body['paging']['next']['after']) : null;
                break;
            case 'salesforce':
                $stamp = $created ? 'CreatedDate' : 'SystemModstamp';
                $q = 'SELECT Id,CreatedDate,SystemModstamp FROM ' . $c['object'] . ' WHERE ' . $stamp . ' >= ' . $lower . ' AND ' . $stamp . ' <= ' . $upper;
                if ($cursor !== null) {
                    if (!is_array($cursor) || !preg_match('/^[a-zA-Z0-9]{15,18}$/D', $cursor['id'] ?? '')) {
                        throw new RuntimeException('Cursor Salesforce inválido.');
                    }
                    $this->timestamp($cursor['at'] ?? null);
                    // Normalize to SOQL UTC while preserving milliseconds for timestamp ties.
                    $at = (new \DateTimeImmutable($cursor['at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
                    $q .= ' AND (' . $stamp . ' > ' . $at . ' OR (' . $stamp . ' = ' . $at . " AND Id > '" . $cursor['id'] . "'))";
                }
                $q .= ' ORDER BY ' . $stamp . ' ASC, Id ASC LIMIT 100';
                $options['query'] = ['q' => $q];
                [$body] = $this->request('GET', $this->origin($s['instance_url'], 'salesforce') . '/services/data/' . $s['api_version'] . '/query', $options);
                $records = $this->list($body, 'records');
                foreach ($records as $row) {
                    $rows[] = $this->record($row['Id'] ?? null, $row[$stamp] ?? null, ['id' => $row['Id'] ?? null, 'object_type' => $c['object'], 'created_at' => $row['CreatedDate'] ?? null, 'modified_at' => $row['SystemModstamp'] ?? null]);
                }
                if (count($records) === 100) {
                    $last = $records[99];
                    $next = ['at' => $last[$stamp], 'id' => $last['Id']];
                }
                break;
            case 'wordpress':
                $options['headers']['Authorization'] = 'Basic ' . base64_encode($s['username'] . ':' . $s['application_password']);
                $options['query'] = ['per_page' => 100, 'page' => $cursor === null ? 1 : (int) $cursor, 'status' => 'publish', 'orderby' => $created ? 'date' : 'modified', 'order' => 'asc', '_fields' => 'id,status,date_gmt,modified_gmt'];
                $options['query'][$created ? 'after' : 'modified_after'] = $lower;
                $options['query'][$created ? 'before' : 'modified_before'] = $upper;
                [$body, $headers] = $this->request('GET', $this->origin($s['site_url'], 'wordpress') . '/wp-json/wp/v2/posts', $options);
                foreach ($this->list($body) as $row) {
                    $raw = $row[$created ? 'date_gmt' : 'modified_gmt'] ?? null;
                    $at = is_string($raw) ? $raw . 'Z' : null;
                    $rows[] = $this->record($row['id'] ?? null, $at, array_intersect_key($row, array_flip(['id', 'status', 'date_gmt', 'modified_gmt'])));
                }
                $page = $options['query']['page'];
                $pages = (int) ($headers['x-wp-totalpages'] ?? 0);
                if ($pages > $page || ($pages === 0 && count($body) === 100)) {
                    $next = (string) ($page + 1);
                }
                break;
            case 'discord':
                $options['headers']['Authorization'] = 'Bot ' . $s['bot_token'];
                // Freeze an upper snowflake and walk backwards; never skip a backlog by jumping to latest ID.
                $before = $cursor === null ? (string) (($w['upper'] * 1000 - 1420070400000) << 22) : $this->cursor($cursor);
                $options['query'] = ['limit' => 100, 'before' => $before];
                [$body] = $this->request('GET', 'https://discord.com/api/v10/channels/' . $c['channel_id'] . '/messages', $options);
                $reachedLower = false;
                foreach ($this->list($body) as $row) {
                    $at = $this->timestamp($row['timestamp'] ?? null);
                    $reachedLower = $reachedLower || $at < $w['lower'];
                    $rows[] = $this->record($row['id'] ?? null, $row['timestamp'] ?? null, ['id' => $row['id'] ?? null, 'channel_id' => $c['channel_id'], 'author_id' => $row['author']['id'] ?? null, 'timestamp' => $row['timestamp'] ?? null]);
                }
                $next = !$reachedLower && count($body) === 100 ? $this->cursor($body[99]['id'] ?? null) : null;
                break;
        }
        return [$rows, $next];
    }

    private function request(string $method, string $url, array $options): array
    {
        $options += ['timeout' => 25, 'connect_timeout' => 8, 'read_timeout' => 25, 'stream' => true, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false];
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody();
            $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 4194304) {
                    $part = $stream->read(min(65536, 4194305 - strlen($raw)));
                    if ($part === '' && !$stream->eof()) {
                        throw new RuntimeException('Lectura remota detenida.');
                    }
                    $raw .= $part;
                }
            } finally {
                $stream->close();
            }
        } catch (Throwable $e) {
            throw new TriggerRetryException(60);
        }
        if ($status === 408 || $status === 429 || $status >= 500) {
            $retry = trim($response->getHeaderLine('Retry-After'));
            $seconds = ctype_digit($retry) ? (int) $retry : (($date = strtotime($retry)) !== false ? max(1, $date - ($this->requestNow ?? time())) : 60);
            throw new TriggerRetryException(min(86400, max(1, $seconds)));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(in_array($status, [401, 403], true) ? 'Polling: autenticación o permisos rechazados.' : 'Polling: solicitud rechazada por el proveedor (HTTP ' . $status . ').');
        }
        if (strlen($raw) > 4194304) {
            throw new RuntimeException('Polling: respuesta demasiado grande.');
        }
        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Polling: JSON remoto inválido.');
        }
        if (!is_array($body)) {
            throw new RuntimeException('Polling: estructura remota inválida.');
        }
        return [$body, ['x-wp-totalpages' => $response->getHeaderLine('X-WP-TotalPages')]];
    }

    private function record(mixed $id, mixed $at, array $data): array
    {
        $this->scalar($id, 256);
        $this->timestamp($at);
        $this->metadata($data);
        // Only explicitly chosen metadata leaves the adapter. Never reflect entire API responses.
        return ['id' => (string) $id, 'at' => $at, 'data' => $data];
    }

    private function metadata(array $data): void
    {
        foreach ($data as $value) {
            if ((!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)
                || (is_string($value) && (strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $value)))) {
                throw new RuntimeException('El proveedor devolvió metadatos con estructura inválida.');
            }
        }
    }

    private function list(array $body, ?string $key = null): array
    {
        $items = $key === null ? $body : ($body[$key] ?? null);
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Polling: lista remota inválida.');
        }
        foreach ($items as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Polling: registro remoto inválido.');
            }
        }
        return $items;
    }

    private function cursor(mixed $value): string
    {
        return $this->scalar($value, 8192);
    }

    private function scalar(mixed $value, int $max): string
    {
        if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '' || strlen((string) $value) > $max || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
            throw new InvalidArgumentException('Campo requerido o dato remoto inválido.');
        }
        return (string) $value;
    }

    private function timestamp(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D', $value) || ($time = strtotime($value)) === false) {
            throw new RuntimeException('Polling: fecha de estado o recurso inválida.');
        }
        return $time;
    }

    private function iso(int $at): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $at);
    }

    private function origin(string $value, string $provider): string
    {
        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || $host === '' || $host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP)
            || str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new InvalidArgumentException('Origen HTTPS del proveedor inválido.');
        }
        if (($provider !== 'wordpress' && !in_array($parts['path'] ?? '', ['', '/'], true))
            || ($provider === 'jira' && !preg_match('/^[a-z0-9-]+\.atlassian\.net$/D', $host))
            || ($provider === 'salesforce' && !preg_match('/^[a-z0-9.-]+\.salesforce\.com$/D', $host))) {
            throw new InvalidArgumentException('Origen fuera del dominio admitido por el proveedor.');
        }
        return rtrim($value, '/');
    }
}
