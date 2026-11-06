<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

/** API v3. API Key contains {"base_url":"https://demo.api-us1.com","api_token":"fictional"}. */
class ActiveCampaignNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    private const OPERATIONS = ['list_contacts','get_contact','create_contact','update_contact','list_contact_lists','set_list_status','list_contact_tags','add_contact_tag','remove_contact_tag','list_lists','list_tags','list_deals','get_deal','create_deal','update_deal','list_campaigns','get_campaign'];

    public function getSchema(): array
    {
        return ['type'=>'active_campaign', 'name'=>'ActiveCampaign', 'category'=>'Community', 'icon'=>'Contact', 'is_start'=>false,
            'description'=>'Contactos, listas, etiquetas, deals y lectura de campañas mediante API v3.',
            'help_text'=>'generic_bearer/API Key: {"base_url":"https://demo.api-us1.com","api_token":"TOKEN_FICTICIO"}. Usa el origen API de tu cuenta api-us1/api-eu1/api-ap1; nunca el token en parámetros. Permisos de usuario/grupo y pipeline. properties es un objeto JSON parcial; omitir conserva, null/vacío se envía explícitamente. set_list_status solicita explícitamente suscripción (status 1) o baja (status 2); no se hace automáticamente. Crear contactos o etiquetas puede activar automatizaciones configuradas externamente en la cuenta. Campañas: solo consultar, nunca enviar ni activar. Una página máximo 100. Triggers de contactos/deals disponibles en Service Trigger mediante polling sin histórico inicial. Docs: https://developers.activecampaign.com/reference/overview y https://developers.activecampaign.com/reference/webhooks',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial API v3','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_contacts','options'=>array_map(static fn($op)=>['value'=>$op,'label'=>$op], self::OPERATIONS)],
                'resource_id'=>['type'=>'string','label'=>'ID contacto, deal, campaña o asociación contactTag','required'=>false],
                'properties'=>['type'=>'textarea','label'=>'Propiedades JSON de contacto/deal o {"contact":"1","list":"2","status":1} / {"contact":"1","tag":"3"}','required'=>false],
                'filters'=>['type'=>'textarea','label'=>'Filtros JSON de lectura (email/search/listid/tagid/status)','required'=>false],
                'limit'=>['type'=>'number','label'=>'Resultados por página (1–100)','default'=>100,'required'=>false],
                'offset'=>['type'=>'number','label'=>'Offset de página (0–1000000)','default'=>0,'required'=>false],
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $parameters; // Engine resolves ExpressionEvaluator once.
        $r = $this->prepare($p); $c = $this->credential($r['credential_id']);
        $base = $c['base_url'] ?? null; $token = $c['api_token'] ?? null;
        if (!is_string($base) || !preg_match('~^https://[a-zA-Z0-9-]+\.(?:api-us1|api-eu1|api-ap1)\.com/?$~D', $base)) { throw new InvalidArgumentException('ActiveCampaign: origen API de cuenta inválido.'); }
        $this->text($token, 4096);
        if (preg_match('/[\x00-\x20\x7f]/', $token)) { throw new InvalidArgumentException('ActiveCampaign: token inválido.'); }
        [$status,$body] = $this->request($r['method'], rtrim($base,'/') . '/api/3/' . $r['path'], ['Api-Token'=>$token,'Accept'=>'application/json'], $r['query'], $r['payload']);
        if ($status === 202 || ($status === 204 && $r['method'] !== 'DELETE')) { throw new RuntimeException('ActiveCampaign: estado HTTP inesperado.'); }
        if ($status !== 204 && !array_key_exists($r['response_key'], $body)) { throw new RuntimeException('ActiveCampaign: respuesta incompleta; resultado de escritura incierto.'); }
        $data = $body[$r['response_key']] ?? null;
        if ($r['list'] && (!is_array($data) || !array_is_list($data) || count($data) > $r['query']['limit'])) { throw new RuntimeException('ActiveCampaign: lista remota inválida o fuera del límite.'); }
        if (!$r['list'] && $status !== 204 && (!is_array($data) || !isset($data['id']))) { throw new RuntimeException('ActiveCampaign: recurso remoto inválido.'); }
        if ($r['list']) { foreach ($data as $row) { if (!is_array($row)) { throw new RuntimeException('ActiveCampaign: fila remota inválida.'); } $this->id($row['id'] ?? null); } }
        $pagination = null;
        if ($r['list']) {
            $total = isset($body['meta']['total']) ? $this->number($body['meta']['total'],0,1000000000) : null;
            $next = ($total !== null ? $r['query']['offset'] + count($data) < $total : count($data) === $r['query']['limit']) ? $r['query']['offset'] + $r['query']['limit'] : null;
            $pagination = ['limit'=>$r['query']['limit'],'offset'=>$r['query']['offset'],'next_offset'=>$next,'total'=>$total,'pages_processed'=>1];
        }
        return ['success'=>true,'status_code'=>$status,'data'=>$this->sanitize($data,[$token]),'pagination'=>$pagination];
    }

    private function prepare(array $p): array
    {
        $credentialId = $this->text($p['credential_id'] ?? null,255);
        $op = $p['operation'] ?? 'list_contacts';
        if (!in_array($op,self::OPERATIONS,true)) { throw new InvalidArgumentException('ActiveCampaign: operación inválida.'); }
        $r = ['credential_id'=>$credentialId,'method'=>'GET','path'=>'','query'=>[],'payload'=>null,'list'=>false,'response_key'=>''];
        $plural = ['contact'=>'contacts','deal'=>'deals','campaign'=>'campaigns'];
        if (preg_match('/^(list|get|create|update)_(contacts?|deals?|campaigns?)$/D',$op,$m)) {
            $verb = $m[1]; $entity = rtrim($m[2],'s'); $resource = $plural[$entity];
            $r['path'] = $resource; $r['response_key'] = $verb === 'list' ? $resource : $entity;
            $r['list'] = $verb === 'list';
            if (in_array($verb,['get','update'],true)) { $r['path'] .= '/' . $this->id($p['resource_id'] ?? null); }
            if (in_array($verb,['create','update'],true)) {
                $data = $this->object($p['properties'] ?? '{}');
                if ($data === []) { throw new InvalidArgumentException('ActiveCampaign: propiedades vacías.'); }
                if ($entity === 'contact') {
                    $this->allowed($data,['email','firstName','lastName','phone','fieldValues']);
                    foreach (['email','firstName','lastName','phone'] as $field) { if (array_key_exists($field,$data) && $data[$field] !== null) { $this->text($data[$field],1024,true); } }
                    if ($verb === 'create' || (isset($data['email']) && $data['email'] !== '')) {
                        if (!is_string($data['email'] ?? null) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('ActiveCampaign: email válido requerido.'); }
                    }
                    if (array_key_exists('fieldValues',$data)) {
                        if (!is_array($data['fieldValues']) || !array_is_list($data['fieldValues']) || count($data['fieldValues']) > 100) { throw new InvalidArgumentException('ActiveCampaign: fieldValues debe ser lista hasta 100.'); }
                        foreach ($data['fieldValues'] as &$field) {
                            $field = $this->object($field); $this->allowed($field,['field','value']); $field['field'] = $this->id($field['field'] ?? null);
                            if (!array_key_exists('value',$field) || (!is_scalar($field['value']) && $field['value'] !== null)) { throw new InvalidArgumentException('ActiveCampaign: valor de campo inválido.'); }
                        } unset($field);
                    }
                } else {
                    $this->allowed($data,['title','description','contact','value','currency','group','stage','owner','status','percent']);
                    if ($verb === 'create') { foreach (['title','contact','value','currency','group','stage','owner'] as $key) { if (!array_key_exists($key,$data)) { throw new InvalidArgumentException('ActiveCampaign: faltan campos requeridos de deal.'); } } }
                    foreach (['contact','group','stage','owner'] as $key) { if (array_key_exists($key,$data)) { $data[$key] = $this->id($data[$key]); } }
                    foreach (['title','description'] as $key) { if (array_key_exists($key,$data) && $data[$key] !== null) { $this->text($data[$key],65536,$key === 'description'); } }
                    if (isset($data['currency']) && (!is_string($data['currency']) || !preg_match('/^[a-zA-Z]{3}$/D',$data['currency']))) { throw new InvalidArgumentException('ActiveCampaign: moneda ISO inválida.'); }
                    foreach (['value'=>[0,1000000000],'status'=>[0,2],'percent'=>[0,100]] as $key=>$range) { if (array_key_exists($key,$data)) { $data[$key] = $this->number($data[$key],...$range); } }
                }
                $r['method'] = $verb === 'create' ? 'POST' : 'PUT'; $r['payload'] = [$entity=>$data];
            }
        } elseif (in_array($op,['list_lists','list_tags'],true)) {
            $r['path'] = substr($op,5); $r['response_key'] = $r['path']; $r['list'] = true;
        } elseif (in_array($op,['list_contact_lists','list_contact_tags'],true)) {
            $r['response_key'] = $op === 'list_contact_lists' ? 'contactLists' : 'contactTags';
            $r['path'] = 'contacts/' . $this->id($p['resource_id'] ?? null) . '/' . $r['response_key']; $r['list'] = true;
        } elseif ($op === 'remove_contact_tag') {
            $r['method'] = 'DELETE'; $r['path'] = 'contactTags/' . $this->id($p['resource_id'] ?? null); $r['response_key'] = 'contactTag';
        } else {
            $data = $this->object($p['properties'] ?? '{}');
            $isList = $op === 'set_list_status'; $this->allowed($data,$isList ? ['contact','list','status'] : ['contact','tag']);
            $data['contact'] = $this->id($data['contact'] ?? null); $key = $isList ? 'list' : 'tag'; $data[$key] = $this->id($data[$key] ?? null);
            if ($isList) {
                $data['status'] = $this->number($data['status'] ?? null,1,2);
            }
            $r['response_key'] = $isList ? 'contactList' : 'contactTag'; $r['path'] = $isList ? 'contactLists' : 'contactTags'; $r['method'] = 'POST'; $r['payload'] = [$r['response_key']=>$data];
        }
        if ($r['list']) {
            $r['query'] = ['limit'=>$this->number($p['limit'] ?? 100,1,100),'offset'=>$this->number($p['offset'] ?? 0,0,1000000)];
            $filters = $this->object(($p['filters'] ?? '') === '' ? '{}' : $p['filters']);
            $allowed = match ($op) { 'list_contacts'=>['email','search','listid','tagid','status'], 'list_deals'=>['filters[contact]','filters[stage]','filters[group]','filters[status]'], default=>[] };
            $this->allowed($filters,$allowed);
            foreach ($filters as $key=>$value) { if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('ActiveCampaign: filtro escalar requerido.'); } $this->text((string)$value,512); }
            $r['query'] += $filters;
        }
        return $r;
    }
    private function object(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 102400) { throw new InvalidArgumentException('Objeto JSON demasiado grande.'); }
            try { $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException('JSON inválido.'); }
        }
        if ($value instanceof \stdClass) { $value = get_object_vars($value); }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) { throw new InvalidArgumentException('Se requiere objeto JSON.'); }
        return $value;
    }

    private function text(mixed $value, int $max = 4096, bool $empty = false): string
    {
        if (!is_string($value) || strlen($value) > $max || (!$empty && trim($value) === '') || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Texto requerido o tipo/límite inválido.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value)) { throw new InvalidArgumentException('ID positivo requerido.'); }
        return (string)$value;
    }

    private function number(mixed $value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value)) { $value = (int)$value; }
        if (!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException('Entero fuera del límite permitido.'); }
        return $value;
    }

    private function allowed(array $data, array $fields): void
    {
        if (array_diff(array_keys($data), $fields)) { throw new InvalidArgumentException('Campo no admitido en esta operación.'); }
    }

    private function credential(string $id): array
    {
        try { $row = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (\Throwable $e) { throw new RuntimeException('No se pudo recuperar o descifrar la credencial de p8p.'); }
        if ($row === null) { throw new InvalidArgumentException('La credencial seleccionada no existe.'); }
        if (($row['type'] ?? null) !== 'generic_bearer' || !is_string($row['data']['api_key'] ?? null)) { throw new InvalidArgumentException('Selecciona generic_bearer con configuración JSON en API Key.'); }
        $config = $this->object($row['data']['api_key']);
        return $config;
    }

    private function request(string $method, string $url, array $headers, array $query = [], ?array $payload = null): array
    {
        $options = ['headers'=>$headers, 'query'=>$query, 'timeout'=>30, 'connect_timeout'=>8, 'read_timeout'=>30, 'verify'=>true, 'allow_redirects'=>false, 'http_errors'=>false, 'stream'=>true];
        if ($payload !== null) {
            try { $json = json_encode($payload, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException('Datos JSON inválidos.'); }
            if (strlen($json) > 102400) { throw new InvalidArgumentException('El cuerpo supera 100 KiB.'); }
            $options['body'] = $json;
            $options['headers']['Content-Type'] = 'application/json';
        }
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options);
            $status = $response->getStatusCode();
            $stream = $response->getBody(); $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 4194304) {
                    $part = $stream->read(min(65536, 4194305 - strlen($raw)));
                    if ($part === '' && !$stream->eof()) { throw new RuntimeException(); }
                    $raw .= $part;
                }
            } finally { $stream->close(); }
        } catch (\Throwable $e) { throw new RuntimeException('Fallo de transporte/lectura. Una escritura puede haberse aplicado; comprueba el proveedor antes de repetir. Sin reintento automático.'); }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) { 401=>'autenticación rechazada', 403=>'permisos insuficientes', 404=>'recurso inexistente o inaccesible', 409=>'conflicto o duplicado; identifica el registro existente', 429=>'cuota excedida', default=>'solicitud rechazada por el proveedor' };
            throw new RuntimeException($reason . ' (HTTP ' . $status . '). Sin reintento automático; escrituras con HTTP 5xx pueden ser inciertas.', $status);
        }
        if (strlen($raw) > 4194304) { throw new RuntimeException('Respuesta demasiado grande.'); }
        if ($raw === '' && in_array($status, [202,204], true)) { return [$status, []]; }
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new RuntimeException('Respuesta JSON inválida; si hubo escritura su resultado es incierto.'); }
        if (!is_array($body) || array_is_list($body) || !empty($body['errors']) || !empty($body['error']) || ($body['success'] ?? true) === false) { throw new RuntimeException('El proveedor devolvió un error funcional o estructura inválida.'); }
        return [$status, $body];
    }

    private function sanitize(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key=>$item) {
                if (is_string($key) && preg_match('/(?:token|secret|password|api_key|authorization|smart_bcc_email)/i', $key)) { continue; }
                $out[$key] = $this->sanitize($item, $secrets);
            }
            return $out;
        }
        if (is_string($value)) {
            foreach ($secrets as $secret) {
                if (is_string($secret) && strlen($secret) >= 8 && str_contains($value, $secret)) { return '[redacted]'; }
            }
        }
        return $value;
    }
}
