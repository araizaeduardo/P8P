<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class HubSpotNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type'=>'hubspot', 'name'=>'HubSpot', 'category'=>'Community', 'icon'=>'Contact', 'is_start'=>false,
            'description'=>'Crea o actualiza contactos y deals; crea notas asociadas mediante CRM v3.',
            'help_text'=>'Guarda un token de aplicación privada pat-... como Bearer Token / API Key, sin prefijo Bearer. Scopes de escritura de los objetos utilizados: crm.objects.contacts.write, crm.objects.deals.write y crm.objects.companies.write para asociaciones a empresas; verifica acceso a notas de la aplicación/cuenta. Sin OAuth ni renovación automática; rota manualmente tokens revocados. CRM v3 sigue documentado en la sección legacy de HubSpot. properties admite objeto JSON o expresión: máximo 100 propiedades escalares, hasta 65536 caracteres por valor y 256 KiB total; números y booleanos se envían como texto, null se rechaza. Actualizar envía solo claves proporcionadas; texto vacío limpia la propiedad. Crear contacto exige email para la identificación única nativa; un conflicto detiene, no se convierte en update. Actualiza por ID o email actual con idProperty=email (sin buscar/crear); properties.email puede cambiar el email. Crear deal exige dealname, pipeline y dealstage internos explícitos; actualizar usa deal_id. Crear nota exige properties.hs_note_body y hs_timestamp (ISO8601 UTC o milisegundos Unix), owner opcional. associations solo notas: lista de {object_type:"contact"|"company"|"deal",object_id:"123"}, máximo 50; relaciones HUBSPOT_DEFINED 202/190/214 en el mismo POST. Sin reintentos ni idempotencia garantizada para creaciones; ante resultado incierto revisa HubSpot antes de repetir. Salida data.id/object_type/properties (solo las solicitadas y devueltas)/created_at/updated_at. Sin listas/paginación. Este nodo ejecuta acciones. Configura eventos disponibles por separado en Triggers con un inicio Start o Evento de servicio; consulta catálogo y docs/triggers.md.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial HubSpot (token privado)','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'create_contact','options'=>[
                    ['value'=>'create_contact','label'=>'Crear contacto'],['value'=>'update_contact','label'=>'Actualizar contacto'],
                    ['value'=>'create_deal','label'=>'Crear deal'],['value'=>'update_deal','label'=>'Actualizar deal'],['value'=>'create_note','label'=>'Crear nota con asociaciones']
                ]],
                'contact_identity'=>['type'=>'select','label'=>'Identificar contacto al actualizar','required'=>false,'default'=>'id','options'=>[['value'=>'id','label'=>'ID del contacto'],['value'=>'email','label'=>'Email actual del contacto']]],
                'contact_id'=>['type'=>'string','label'=>'ID de contacto (actualizar por ID)','required'=>false],
                'contact_email'=>['type'=>'string','label'=>'Email actual (actualizar por email)','required'=>false],
                'deal_id'=>['type'=>'string','label'=>'ID de deal (actualizar)','required'=>false],
                'properties'=>['type'=>'textarea','label'=>'Propiedades (objeto JSON o expresión)','required'=>true,'placeholder'=>'{"email":"ana@example.com","firstname":"Ana"}'],
                'associations'=>['type'=>'textarea','label'=>'Asociaciones de la nota (lista JSON o expresión)','required'=>false,'placeholder'=>'[{"object_type":"contact","object_id":"123"}]']
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
        // Engine usa ExpressionEvaluator antes de ejecutar el nodo; conservar valores resueltos.
        $config = $this->prepare($parameters);
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($config['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('HubSpot: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('HubSpot: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token) || preg_match('/^pat-[A-Za-z0-9_-]{12,512}$/D', $token) !== 1) {
            throw new InvalidArgumentException('HubSpot: selecciona una credencial Bearer Token / API Key con token privado pat-... sin prefijo Bearer.');
        }
        try {
            $response = ($this->client ?? new Client())->request($config['method'], 'https://api.hubapi.com' . $config['path'], [
                'headers'=>['Authorization'=>'Bearer ' . $token,'Accept'=>'application/json'], 'query'=>$config['query'], 'json'=>$config['payload'],
                'timeout'=>30,'connect_timeout'=>10,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getBody()->read(1048577);
        } catch (Throwable $e) {
            throw new RuntimeException('HubSpot: fallo de transporte o lectura; la escritura puede haberse aplicado. Revisa el registro antes de repetir; no hubo reintento automático.');
        }
        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                $status === 401 => 'autenticación rechazada; token inválido o revocado',
                $status === 403 => 'permisos o disponibilidad insuficientes; revisa scopes y acceso a los objetos de esta cuenta',
                $status === 404 => 'registro, email o asociación inexistente o inaccesible',
                $status === 409 => 'conflicto de identificación única; el contacto o valor ya existe, selecciona actualización explícitamente',
                $status === 429 => 'cuota o límite de solicitudes alcanzado',
                $status >= 500 => 'fallo del servicio; escritura potencialmente incierta',
                $status >= 300 && $status < 400 => 'redirección rechazada',
                default => 'solicitud rechazada; revisa propiedades, etapas, asociaciones y campos internos'
            };
            throw new RuntimeException("HubSpot: {$reason} (HTTP {$status}). Sin reintento automático.", $status);
        }
        try {
            $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (strlen($raw) > 1048576 || !$body instanceof stdClass || isset($body->error) || isset($body->errors)
                || isset($body->category) || ($body->status ?? null) === 'error') {
                throw new InvalidArgumentException();
            }
            $id = $this->id($body->id ?? null);
            if (isset($config['expected_id']) && $config['expected_id'] !== $id) {
                throw new InvalidArgumentException();
            }
            $data = ['id'=>$id,'object_type'=>$config['object_type'],'properties'=>new stdClass()];
            $returned = $this->object($body->properties ?? null);
            $filtered = [];
            foreach ($config['payload']['properties'] as $key => $requested) {
                if (array_key_exists($key, $returned)) {
                    if ($returned[$key] !== null && !is_string($returned[$key])) {
                        throw new InvalidArgumentException();
                    }
                    if (is_string($returned[$key]) && (!mb_check_encoding($returned[$key], 'UTF-8') || mb_strlen($returned[$key]) > 65536)) {
                        throw new InvalidArgumentException();
                    }
                    $filtered[$key] = $returned[$key];
                }
            }
            $data['properties'] = (object)$filtered;
            foreach (['createdAt'=>'created_at','updatedAt'=>'updated_at'] as $remote=>$local) {
                if (isset($body->{$remote})) {
                    $data[$local] = $this->timestamp($body->{$remote});
                }
            }
            if (isset($body->archived)) {
                if (!is_bool($body->archived) || $body->archived) {
                    throw new InvalidArgumentException();
                }
                $data['archived'] = false;
            }
            if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $token)) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable $e) {
            throw new RuntimeException('HubSpot: respuesta de escritura inválida o incompleta; el cambio puede haberse aplicado. Revisa el registro antes de repetir.', $status);
        }
        return ['success'=>true,'status_code'=>$status,'data'=>$data];
    }

    private function prepare(array $p): array
    {
        $credential = $this->text($p['credential_id'] ?? null, 256, false);
        $operation = $p['operation'] ?? 'create_contact';
        if (!in_array($operation, ['create_contact','update_contact','create_deal','update_deal','create_note'], true)) {
            throw new InvalidArgumentException('HubSpot: operación inválida.');
        }
        $properties = $this->object($p['properties'] ?? null);
        if ($properties === [] || count($properties) > 100) {
            throw new InvalidArgumentException('HubSpot: proporciona de 1 a 100 propiedades.');
        }
        foreach ($properties as $name => $value) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $name) !== 1
                || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))
                || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('HubSpot: propiedades requieren nombres internos y valores texto, número o booleano; para vaciar usa texto vacío, no null.');
            }
            $properties[$name] = $this->text(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value, 65536, true, true);
        }
        if (strlen(json_encode($properties, JSON_THROW_ON_ERROR)) > 262144) {
            throw new InvalidArgumentException('HubSpot: propiedades exceden 256 KiB.');
        }
        $objectType = str_contains($operation, 'contact') ? 'contacts' : (str_contains($operation, 'deal') ? 'deals' : 'notes');
        $create = str_starts_with($operation, 'create_');
        $config = ['credential_id'=>$credential,'object_type'=>$objectType,'method'=>$create ? 'POST' : 'PATCH',
            'path'=>'/crm/v3/objects/' . $objectType,'query'=>[],'payload'=>['properties'=>$properties]];
        if (array_key_exists('email', $properties) && $properties['email'] !== '') {
            $this->email($properties['email']);
        }
        if ($operation === 'create_contact') {
            $this->email($properties['email'] ?? null);
        } elseif ($operation === 'update_contact') {
            $identity = $p['contact_identity'] ?? 'id';
            if ($identity === 'email') {
                $config['path'] .= '/' . rawurlencode($this->email($p['contact_email'] ?? null));
                $config['query']['idProperty'] = 'email';
            } elseif ($identity === 'id') {
                $config['expected_id'] = $this->id($p['contact_id'] ?? null);
                $config['path'] .= '/' . $config['expected_id'];
            } else {
                throw new InvalidArgumentException('HubSpot: contact_identity debe ser id o email.');
            }
        } elseif ($operation === 'create_deal') {
            foreach (['dealname','dealstage','pipeline'] as $required) {
                $this->text($properties[$required] ?? null, 65536, false);
            }
        } elseif ($operation === 'update_deal') {
            $config['expected_id'] = $this->id($p['deal_id'] ?? null);
            $config['path'] .= '/' . $config['expected_id'];
        } else {
            if (array_diff(array_keys($properties), ['hs_note_body','hs_timestamp','hubspot_owner_id']) !== []) {
                throw new InvalidArgumentException('HubSpot: nota admite hs_note_body, hs_timestamp y hubspot_owner_id.');
            }
            $this->text($properties['hs_note_body'] ?? null, 65536, false, true);
            $this->timestamp($properties['hs_timestamp'] ?? null);
            if (isset($properties['hubspot_owner_id'])) {
                $this->id($properties['hubspot_owner_id']);
            }
            $associations = $p['associations'] ?? [];
            if (is_string($associations)) {
                $associations = $associations === '' ? [] : $this->json($associations);
            }
            if (!is_array($associations) || !array_is_list($associations) || count($associations) > 50) {
                throw new InvalidArgumentException('HubSpot: associations debe ser una lista de hasta 50 relaciones.');
            }
            $types = ['contact'=>202,'company'=>190,'deal'=>214];
            $prepared = []; $seen = [];
            foreach ($associations as $association) {
                $association = $this->object($association);
                if (array_diff(array_keys($association), ['object_type','object_id']) !== [] || !is_string($association['object_type'] ?? null)
                    || !array_key_exists($association['object_type'], $types)) {
                    throw new InvalidArgumentException('HubSpot: asociación requiere object_type contact, company o deal y object_id.');
                }
                $id = $this->id($association['object_id'] ?? null);
                $key = $association['object_type'] . ':' . $id;
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('HubSpot: asociación duplicada.');
                }
                $seen[$key] = true;
                $prepared[] = ['to'=>['id'=>$id],'types'=>[['associationCategory'=>'HUBSPOT_DEFINED','associationTypeId'=>$types[$association['object_type']]]]];
            }
            $config['payload']['associations'] = $prepared;
        }
        if ($objectType !== 'notes' && array_key_exists('associations', $p) && !in_array($p['associations'], ['', []], true)) {
            throw new InvalidArgumentException('HubSpot: associations está disponible solamente al crear notas.');
        }
        return $config;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('HubSpot: hs_timestamp requiere UTC ISO8601 o milisegundos Unix como texto.');
        }
        if (preg_match('/^[0-9]{1,15}$/D', $value) === 1 && (float)$value <= 253402300799999) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s' . (str_contains($value, '.') ? '.v' : '') . '\Z', $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && substr($value, 0, 4) !== '0000') {
                return $value;
            }
        }
        throw new InvalidArgumentException('HubSpot: fecha UTC o timestamp en milisegundos inválido.');
    }

    private function email(mixed $value): string
    {
        $value = $this->text($value, 254, false);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('HubSpot: indica un email válido para identificar el contacto.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if (is_int($value)) {
            $value = (string)$value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,29}$/D', $value) !== 1) {
            throw new InvalidArgumentException('HubSpot: el ID debe ser un entero positivo, como texto o número entero.');
        }
        return $value;
    }

    private function object(mixed $value): array
    {
        if (is_string($value)) {
            $value = $this->json($value);
        }
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('HubSpot: se requiere un objeto JSON de propiedades o asociación.');
        }
        return $value;
    }

    private function json(string $value): mixed
    {
        try {
            return json_decode($value, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('HubSpot: JSON inválido o excesivamente anidado.');
        }
    }

    private function text(mixed $value, int $max, bool $empty, bool $multiline = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max
            || (!$empty && trim($value) === '') || preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('HubSpot: campo vacío, de tipo incorrecto o fuera de límites.');
        }
        return $value;
    }
}
