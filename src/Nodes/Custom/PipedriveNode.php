<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

/** Pipedrive API v2, explicit creates and ID-only updates (no synthetic upsert). */
class PipedriveNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }
    private const OPERATIONS = ['list_persons','get_person','create_person','update_person','search_persons','list_organizations','get_organization','create_organization','update_organization','search_organizations','list_deals','get_deal','create_deal','update_deal','search_deals','list_pipelines','list_stages','move_deal','list_person_fields','list_organization_fields','list_deal_fields'];

    public function getSchema(): array
    {
        return ['type'=>'pipedrive','name'=>'Pipedrive','category'=>'Community','icon'=>'Users','is_start'=>false,
            'description'=>'Personas, organizaciones, deals, pipelines y etapas mediante API v2.',
            'help_text'=>'generic_bearer/API Key JSON: {"company_domain":"demo","api_token":"TOKEN_FICTICIO"}; alternativamente access_token OAuth externo, nunca ambos. Tokens OAuth caducan: renovación externa/manual; no hay refresh nuevo. Scopes contacts:read/full, deals:read/full según operación; permisos del usuario y visibilidad limitan resultados. Actualizar identifica exclusivamente por resource_id. create NO hace upsert ni deduplica y puede crear duplicados; usa search_* exacto y luego update por ID. Campos custom_fields con hash de 40 caracteres; null borra, [] no se admite en multiselección. Una página de máximo 100. Triggers create/change oficiales vía Service Trigger con Basic Auth exclusivo y registro remoto manual. Docs: https://developers.pipedrive.com/docs/api/v1/Persons https://developers.pipedrive.com/docs/api/v1/Deals https://developers.pipedrive.com/docs/api/v1/Webhooks',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial Pipedrive','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_persons','options'=>array_map(static fn($op)=>['value'=>$op,'label'=>$op],self::OPERATIONS)],
                'resource_id'=>['type'=>'string','label'=>'ID de recurso para obtener/actualizar/mover','required'=>false],
                'properties'=>['type'=>'textarea','label'=>'Propiedades JSON parciales; mover: {"stage_id":2}','required'=>false],
                'filters'=>['type'=>'textarea','label'=>'Filtros JSON o {"term":"ana@example.com"} en búsquedas exactas','required'=>false],
                'limit'=>['type'=>'number','label'=>'Límite de página (1–100)','default'=>100,'required'=>false],
                'cursor'=>['type'=>'string','label'=>'Cursor opaco de página','required'=>false],
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $r = $this->prepare($parameters);
        $c = $this->credential($r['credential_id']);
        $domain = $c['company_domain'] ?? null;
        if (!is_string($domain) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D',$domain)) { throw new InvalidArgumentException('Pipedrive: company_domain debe ser el subdominio de la cuenta.'); }
        $api = $c['api_token'] ?? null; $oauth = $c['access_token'] ?? null;
        if (($api === null) === ($oauth === null)) { throw new InvalidArgumentException('Pipedrive: selecciona api_token o access_token, exclusivamente uno.'); }
        $token = $this->text($api ?? $oauth,4096);
        if (preg_match('/[\x00-\x20\x7f]/',$token)) { throw new InvalidArgumentException('Pipedrive: token inválido.'); }
        $headers = ['Accept'=>'application/json'];
        $headers[$api !== null ? 'x-api-token' : 'Authorization'] = $api !== null ? $token : 'Bearer ' . $token;
        [$status,$body] = $this->request($r['method'],'https://' . $domain . '.pipedrive.com/api/v2/' . $r['path'],$headers,$r['query'],$r['payload']);
        if (($body['success'] ?? null) !== true || !array_key_exists('data',$body)) { throw new RuntimeException('Pipedrive: respuesta incompleta o error funcional.'); }
        $data = $body['data']; $pagination = null;
        if ($r['list']) {
            $rows = $r['search'] ? ($data['items'] ?? null) : $data;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > $r['query']['limit']) { throw new RuntimeException('Pipedrive: lista remota inválida o superior al límite.'); }
            foreach ($rows as $row) {
                $item=$r['search'] ? ($row['item'] ?? null) : $row;
                if (!is_array($item)) { throw new RuntimeException('Pipedrive: fila remota inválida.'); }
                if (str_ends_with($r['path'],'Fields')) { $this->text($item['field_code'] ?? $item['key'] ?? null,128); } else { $this->id($item['id'] ?? null); }
            }
            $next = $body['additional_data']['next_cursor'] ?? null;
            if ($next !== null) { $this->text($next,8192); }
            $pagination = ['next_cursor'=>$next,'limit'=>$r['query']['limit'],'pages_processed'=>1];
        } elseif (!is_array($data) || !isset($data['id'])) { throw new RuntimeException('Pipedrive: recurso remoto incompleto.'); }
        return ['success'=>true,'status_code'=>$status,'data'=>$this->sanitize($data,[$token]),'pagination'=>$pagination];
    }

    private function prepare(array $p): array
    {
        $credential = $this->text($p['credential_id'] ?? null,255); $op = $p['operation'] ?? 'list_persons';
        if (!in_array($op,self::OPERATIONS,true)) { throw new InvalidArgumentException('Pipedrive: operación inválida.'); }
        $r = ['credential_id'=>$credential,'method'=>'GET','path'=>'','query'=>[],'payload'=>null,'list'=>false,'search'=>false];
        if (preg_match('/^list_(person|organization|deal)_fields$/D',$op,$m)) { $r['path']=$m[1] . 'Fields'; $r['list']=true; }
        elseif ($op === 'move_deal') {
            $r['path']='deals/' . $this->id($p['resource_id'] ?? null); $r['method']='PATCH';
            $data=$this->object($p['properties'] ?? '{}'); $this->allowed($data,['stage_id']); $data['stage_id']=(int)$this->id($data['stage_id'] ?? null); $r['payload']=$data;
        } else {
            [$verb,$entity] = explode('_',$op,2); $r['list']=in_array($verb,['list','search'],true); $r['search']=$verb === 'search';
            $resource=match(rtrim($entity,'s')) { 'person'=>'persons','organization'=>'organizations','deal'=>'deals','pipeline'=>'pipelines','stage'=>'stages' };
            $r['path']=$resource;
            if ($verb === 'search') { $r['path'].='/search'; }
            if (in_array($verb,['get','update'],true)) { $r['path'].='/' . $this->id($p['resource_id'] ?? null); }
            if (in_array($verb,['create','update'],true)) {
                $data=$this->object($p['properties'] ?? '{}');
                if ($data === []) { throw new InvalidArgumentException('Pipedrive: propiedades vacías.'); }
                $allowed = match($resource) {
                    'persons'=>['name','owner_id','org_id','emails','phones','visible_to','label_ids','custom_fields'],
                    'organizations'=>['name','owner_id','visible_to','label_ids','address','website','linkedin','industry','annual_revenue','employee_count','custom_fields'],
                    'deals'=>['title','owner_id','person_id','org_id','pipeline_id','stage_id','value','currency','status','probability','lost_reason','visible_to','label_ids','custom_fields']
                };
                $this->allowed($data,$allowed);
                $name=$resource === 'deals' ? 'title' : 'name';
                if ($verb === 'create' || array_key_exists($name,$data)) { $this->text($data[$name] ?? null,1024); }
                foreach (['owner_id','org_id','person_id','pipeline_id','stage_id'] as $key) { if (array_key_exists($key,$data) && $data[$key] !== null) { $data[$key]=(int)$this->id($data[$key]); } }
                foreach (['visible_to'=>[1,7],'industry'=>[0,1000000],'annual_revenue'=>[0,1000000000],'employee_count'=>[0,1000000000]] as $key=>$range) { if (array_key_exists($key,$data) && $data[$key] !== null) { $data[$key]=$this->number($data[$key],...$range); } }
                foreach (['value','probability'] as $key) {
                    if (array_key_exists($key,$data) && $data[$key] !== null && ((!is_int($data[$key]) && !is_float($data[$key])) || !is_finite((float)$data[$key]) || $data[$key] < 0 || ($key === 'probability' && $data[$key] > 100))) { throw new InvalidArgumentException('Pipedrive: valor numérico inválido.'); }
                }
                if (isset($data['currency']) && (!is_string($data['currency']) || !preg_match('/^[A-Z]{3}$/D',$data['currency']))) { throw new InvalidArgumentException('Pipedrive: moneda ISO inválida.'); }
                if (isset($data['status']) && !in_array($data['status'],['open','won','lost'],true)) { throw new InvalidArgumentException('Pipedrive: status inválido.'); }
                foreach (['lost_reason','website','linkedin'] as $key) { if (array_key_exists($key,$data) && $data[$key] !== null) { $this->text($data[$key],4096,true); } }
                foreach (['emails','phones'] as $key) {
                    if (array_key_exists($key,$data)) {
                        if (!is_array($data[$key]) || !array_is_list($data[$key]) || count($data[$key]) > 100) { throw new InvalidArgumentException('Pipedrive: emails/phones debe ser lista hasta 100.'); }
                        $primary=0;
                        foreach ($data[$key] as &$item) {
                            $item=$this->object($item); $this->allowed($item,['value','primary','label']); $this->text($item['value'] ?? null,512);
                            if ($key === 'emails' && !filter_var($item['value'],FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Pipedrive: email inválido.'); }
                            if (array_key_exists('primary',$item) && !is_bool($item['primary'])) { throw new InvalidArgumentException('Pipedrive: primary requiere booleano.'); }
                            if (isset($item['label'])) { $this->text($item['label'],255,true); } $primary += ($item['primary'] ?? false) ? 1 : 0;
                        } unset($item);
                        if ($primary > 1) { throw new InvalidArgumentException('Pipedrive: múltiples valores primarios.'); }
                    }
                }
                if (isset($data['label_ids'])) {
                    if (!is_array($data['label_ids']) || !array_is_list($data['label_ids']) || count($data['label_ids']) > 100) { throw new InvalidArgumentException('Pipedrive: label_ids inválido.'); }
                    foreach ($data['label_ids'] as &$id) { $id=(int)$this->id($id); } unset($id);
                }
                if (array_key_exists('address',$data) && $data['address'] !== null) {
                    $data['address']=$this->object($data['address']);
                    $this->allowed($data['address'],['value','country','admin_area_level_1','admin_area_level_2','locality','sublocality','route','street_number','subpremise','postal_code']);
                    foreach ($data['address'] as $v) { if ($v !== null) { $this->text($v,2048,true); } }
                }
                if (array_key_exists('custom_fields',$data)) {
                    $data['custom_fields']=$this->object($data['custom_fields']);
                    if (count($data['custom_fields']) > 100) { throw new InvalidArgumentException('Pipedrive: máximo 100 campos personalizados.'); }
                    foreach ($data['custom_fields'] as $key=>$value) {
                        if (!preg_match('/^[a-f0-9]{40}$/D',(string)$key) || $value === [] || (!is_scalar($value) && $value !== null && !is_array($value) && !$value instanceof \stdClass)) { throw new InvalidArgumentException('Pipedrive: clave/valor custom_fields inválido; vaciar con null.'); }
                    }
                    $data['custom_fields']=(object)$data['custom_fields'];
                }
                $r['payload']=$data; $r['method']=$verb === 'create' ? 'POST' : 'PATCH';
            }
        }
        if ($r['list']) {
            $r['query']=['limit'=>$this->number($p['limit'] ?? 100,1,100)];
            if (($p['cursor'] ?? '') !== '') { $r['query']['cursor']=$this->text($p['cursor'],8192); }
            $filters=$this->object(($p['filters'] ?? '') === '' ? '{}' : $p['filters']);
            $allowed=$r['search'] ? ['term','fields'] : match($r['path']) {
                'persons'=>['owner_id','org_id','filter_id','updated_since','updated_until','sort_by','sort_direction'],
                'organizations'=>['owner_id','filter_id','updated_since','updated_until','sort_by','sort_direction'],
                'deals'=>['owner_id','person_id','org_id','pipeline_id','stage_id','status','filter_id','updated_since','updated_until','sort_by','sort_direction'],
                'stages'=>['pipeline_id','sort_by','sort_direction'], 'pipelines'=>['sort_by','sort_direction'], default=>[]
            };
            $this->allowed($filters,$allowed);
            foreach ($filters as $key=>$v) {
                if (!is_string($v) && !is_int($v)) { throw new InvalidArgumentException('Pipedrive: filtro escalar requerido.'); }
                $this->text((string)$v,1024);
                if (str_ends_with($key,'_id')) { $filters[$key]=(int)$this->id($v); }
                if (in_array($key,['updated_since','updated_until'],true) && (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$v) || strtotime($v) === false)) { throw new InvalidArgumentException('Pipedrive: fecha RFC3339 inválida.'); }
                if ($key === 'sort_by' && !in_array($v,['id','update_time','add_time'],true)) { throw new InvalidArgumentException('Pipedrive: orden inválido.'); }
                if ($key === 'sort_direction' && !in_array($v,['asc','desc'],true)) { throw new InvalidArgumentException('Pipedrive: dirección inválida.'); }
                if ($key === 'status' && !in_array($v,['open','won','lost'],true)) { throw new InvalidArgumentException('Pipedrive: estado inválido.'); }
            }
            if ($r['search']) { $this->text($filters['term'] ?? null,255); $r['query']['exact_match']='true'; }
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
