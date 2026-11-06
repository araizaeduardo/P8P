<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

/** Fixed revision 2026-10-15; private API key, never public site ID. */
class KlaviyoNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    private const OPERATIONS = ['list_profiles','get_profile','create_profile','update_profile','list_lists','get_list','list_segments','get_segment','list_list_profiles','list_segment_profiles','add_profiles_to_list','remove_profiles_from_list','create_event','subscribe_profiles','unsubscribe_profiles'];

    public function getSchema(): array
    {
        return ['type'=>'klaviyo','name'=>'Klaviyo','category'=>'Community','icon'=>'Mail','is_start'=>false,
            'description'=>'Perfiles, listas, segmentos, pertenencia, eventos y suscripciones explícitas.',
            'help_text'=>'generic_bearer/API Key JSON: {"private_api_key":"pk_FICTICIA"}. Revisión fija 2026-10-15. Scopes mínimos: profiles:read/write, lists:read/write, segments:read, events:write, subscriptions:write según operación; listas además profiles:write. Crear perfil o pertenencia NO cambia consentimiento. subscribe_profiles/unsubscribe_profiles cambia consentimiento solo como operación explícita; suscribir respeta opt-in de la lista, puede enviar confirmación y quitar supresiones. unsubscribe_profiles es baja global del canal y no acepta list_id; para quitar solo pertenencia usa remove_profiles_from_list. Sin histórico import ni campañas. Eventos requieren unique_id propio (deduplicación oficial por perfil/métrica); pueden activar flows de cuenta. 202 significa aceptado, no procesado. properties perfil admite null para vaciar; omitir conserva. Una página máximo 100. Triggers: Service Trigger consulta eventos oficiales, sin emisión de eventos ni webhooks ficticios. Docs: https://developers.klaviyo.com/en/reference/create_event https://developers.klaviyo.com/en/reference/bulk_subscribe_profiles https://developers.klaviyo.com/en/reference/get_profiles',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial private API key','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_profiles','options'=>array_map(static fn($op)=>['value'=>$op,'label'=>$op],self::OPERATIONS)],
                'resource_id'=>['type'=>'string','label'=>'ID perfil/lista/segmento','required'=>false],
                'properties'=>['type'=>'textarea','label'=>'Propiedades JSON perfil/evento; suscribir: {"profiles":[{"email":"ana@example.com"}],"channel":"email","list_id":"ABC123"}','required'=>false],
                'profile_ids'=>['type'=>'textarea','label'=>'IDs de perfiles (lista JSON) para pertenencia','required'=>false],
                'filters'=>['type'=>'textarea','label'=>'Filtros JSON: {"filter":"equals(email,\"ana@example.com\")","sort":"created"}','required'=>false],
                'limit'=>['type'=>'number','label'=>'Límite de página (1–100)','default'=>100,'required'=>false],
                'cursor'=>['type'=>'string','label'=>'Cursor opaco devuelto en pagination.next_cursor','required'=>false],
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine already resolves ExpressionEvaluator once, preserving literal resolved strings.
        $r=$this->prepare($parameters); $c=$this->credential($r['credential_id']);
        $token=$this->text($c['private_api_key'] ?? null,4096);
        if (!preg_match('/^pk_[A-Za-z0-9_-]{5,512}$/D',$token)) { throw new InvalidArgumentException('Klaviyo: private_api_key pk_ inválida.'); }
        [$status,$body]=$this->request($r['method'],'https://a.klaviyo.com/api/' . $r['path'],['Authorization'=>'Klaviyo-API-Key ' . $token,'Accept'=>'application/vnd.api+json','revision'=>'2026-10-15','Content-Type'=>'application/vnd.api+json'],$r['query'],$r['payload']);
        if ($r['async']) {
            if ($status !== 202) { throw new RuntimeException('Klaviyo: estado asíncrono inesperado.'); }
            return ['success'=>true,'status_code'=>$status,'data'=>['accepted'=>true,'processed'=>false],'pagination'=>null];
        }
        if ($r['no_content']) {
            if ($status !== 204) { throw new RuntimeException('Klaviyo: estado de pertenencia inesperado.'); }
            return ['success'=>true,'status_code'=>$status,'data'=>null,'pagination'=>null];
        }
        if (!array_key_exists('data',$body) || !is_array($body['data'])) { throw new RuntimeException('Klaviyo: respuesta incompleta.'); }
        $data=$body['data']; $next=null;
        if ($r['list']) {
            if (!array_is_list($data) || count($data)>$r['query']['page[size]']) { throw new RuntimeException('Klaviyo: lista inválida o fuera del límite.'); }
            foreach ($data as $row) { $this->resource($row,$r['expected_type']); }
            $next=$this->nextCursor($body['links']['next'] ?? null,$r['path']);
        } else { $this->resource($data,$r['expected_type']); }
        return ['success'=>true,'status_code'=>$status,'data'=>$this->sanitize($data,[$token]),'pagination'=>$r['list'] ? ['next_cursor'=>$next,'limit'=>$r['query']['page[size]'],'pages_processed'=>1] : null];
    }

    private function resource(mixed $row, string $type): void
    {
        if (!is_array($row) || ($row['type'] ?? null)!==$type) { throw new RuntimeException('Klaviyo: tipo de recurso remoto inválido.'); }
        $this->key($row['id'] ?? null);
        if (!is_array($row['attributes'] ?? null)) { throw new RuntimeException('Klaviyo: atributos remotos ausentes.'); }
    }

    private function key(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$value)) { throw new InvalidArgumentException('Klaviyo: ID inválido.'); }
        return $value;
    }

    private function nextCursor(mixed $url, string $path): ?string
    {
        if ($url === null) { return null; }
        if (!is_string($url) || strlen($url)>16384 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) { throw new RuntimeException('Klaviyo: continuación inválida.'); }
        $u=parse_url($url);
        if (($u['scheme'] ?? null)!=='https' || ($u['host'] ?? null)!=='a.klaviyo.com' || !in_array($u['path'] ?? '',['/api/' . $path,'/api/' . $path . '/'],true) || (isset($u['user']) || isset($u['pass'])) || isset($u['port']) || isset($u['fragment'])) { throw new RuntimeException('Klaviyo: continuación fuera del origen/ruta permitido.'); }
        parse_str($u['query'] ?? '',$query);
        return $this->text($query['page']['cursor'] ?? null,8192);
    }

    private function timestamp(mixed $value): string
    {
        $value=$this->text($value,40);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)) { throw new InvalidArgumentException('Klaviyo: fecha RFC3339 requerida.'); }
        try { $date=new \DateTimeImmutable($value); } catch (\Throwable $e) { throw new InvalidArgumentException('Klaviyo: fecha inválida.'); }
        $errors=\DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { throw new InvalidArgumentException('Klaviyo: fecha inválida.'); }
        if ($date->format('Y') < '1991') { throw new InvalidArgumentException('Klaviyo: fecha debe ser posterior a 1990.'); }
        return $value;
    }

    private function profile(array $data, bool $create): array
    {
        $this->allowed($data,['email','phone_number','external_id','first_name','last_name','organization','locale','title','image','location','properties']);
        if ($create && !isset($data['email']) && !isset($data['phone_number']) && !isset($data['external_id'])) { throw new InvalidArgumentException('Klaviyo: identificador de perfil requerido.'); }
        foreach ($data as $key=>$value) {
            if ($value === null) { continue; }
            if ($key === 'properties') { $data[$key]=(object)$this->object($value); }
            elseif ($key === 'location') {
                $location=$this->object($value); $this->allowed($location,['address1','address2','city','country','latitude','longitude','region','zip','timezone']);
                foreach ($location as $k=>$v) {
                    if (in_array($k,['latitude','longitude'],true)) { if ($v !== null && ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs($v)>($k==='latitude'?90:180))) { throw new InvalidArgumentException('Klaviyo: coordenadas inválidas.'); } }
                    elseif ($v !== null) { $this->text($v,2048,true); }
                }
                $data[$key]=(object)$location;
            } else {
                $this->text($value,4096,true);
                if ($key === 'email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Klaviyo: email inválido; vaciar con null.'); }
                if ($key === 'phone_number' && !preg_match('/^\+[1-9][0-9]{6,14}$/D',$value)) { throw new InvalidArgumentException('Klaviyo: teléfono E.164 inválido; vaciar con null.'); }
                if ($key === 'locale' && !preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D',$value)) { throw new InvalidArgumentException('Klaviyo: locale inválido.'); }
                if ($create && in_array($key,['external_id'],true) && trim($value)==='') { throw new InvalidArgumentException('Klaviyo: identificador vacío.'); }
            }
        }
        return $data;
    }

    private function prepare(array $p): array
    {
        $credential=$this->text($p['credential_id'] ?? null,255); $op=$p['operation'] ?? 'list_profiles';
        if (!in_array($op,self::OPERATIONS,true)) { throw new InvalidArgumentException('Klaviyo: operación inválida.'); }
        $r=['credential_id'=>$credential,'method'=>'GET','path'=>'','query'=>[],'payload'=>null,'list'=>false,'async'=>false,'no_content'=>false,'expected_type'=>''];
        if (in_array($op,['create_profile','update_profile'],true)) {
            $data=$this->profile($this->object($p['properties'] ?? '{}'),$op==='create_profile');
            if ($data===[]) { throw new InvalidArgumentException('Klaviyo: propiedades vacías.'); }
            $r['path']='profiles'; $r['method']=$op==='create_profile'?'POST':'PATCH';
            $resource=['type'=>'profile','attributes'=>(object)$data];
            if ($op==='update_profile') { $resource['id']=$this->key($p['resource_id'] ?? null); $r['path'].='/' . $resource['id']; }
            $r['payload']=['data'=>$resource]; $r['expected_type']='profile';
        } elseif (in_array($op,['add_profiles_to_list','remove_profiles_from_list'],true)) {
            $ids=$p['profile_ids'] ?? null;
            if (is_string($ids)) { try { $ids=json_decode($ids,true,8,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('Klaviyo: lista JSON de IDs inválida.'); } }
            if (!is_array($ids) || !array_is_list($ids) || count($ids)<1 || count($ids)>100) { throw new InvalidArgumentException('Klaviyo: requiere entre 1 y 100 IDs de perfil.'); }
            $rows=[]; foreach ($ids as $id) { $rows[]=['type'=>'profile','id'=>$this->key($id)]; }
            $r['path']='lists/' . $this->key($p['resource_id'] ?? null) . '/relationships/profiles'; $r['method']=$op==='add_profiles_to_list'?'POST':'DELETE'; $r['payload']=['data'=>$rows]; $r['no_content']=true;
        } elseif ($op==='create_event') {
            $data=$this->object($p['properties'] ?? '{}'); $this->allowed($data,['metric_name','profile_id','email','phone_number','external_id','properties','time','unique_id','value','value_currency','backfill']);
            $metric=$this->text($data['metric_name'] ?? null,255); $unique=$this->text($data['unique_id'] ?? null,255);
            $properties=$this->object($data['properties'] ?? '{}');
            if (count($properties)>400) { throw new InvalidArgumentException('Klaviyo: máximo 400 propiedades de evento.'); }
            $profile=['type'=>'profile'];
            if (isset($data['profile_id'])) { $profile['id']=$this->key($data['profile_id']); }
            else { $profile['attributes']=$this->profile(array_intersect_key($data,array_flip(['email','phone_number','external_id'])),true); }
            $attributes=['metric'=>['data'=>['type'=>'metric','attributes'=>['name'=>$metric]]],'profile'=>['data'=>$profile],'properties'=>(object)$properties,'unique_id'=>$unique];
            if (array_key_exists('time',$data)) { $attributes['time']=$this->timestamp($data['time']); }
            if (array_key_exists('value',$data)) { if ((!is_int($data['value']) && !is_float($data['value'])) || !is_finite((float)$data['value'])) { throw new InvalidArgumentException('Klaviyo: value numérico requerido.'); } $attributes['value']=$data['value']; }
            if (array_key_exists('value_currency',$data)) { if (!is_string($data['value_currency']) || !preg_match('/^[A-Z]{3}$/D',$data['value_currency'])) { throw new InvalidArgumentException('Klaviyo: moneda ISO inválida.'); } $attributes['value_currency']=$data['value_currency']; }
            if (array_key_exists('backfill',$data)) { if (!is_bool($data['backfill'])) { throw new InvalidArgumentException('Klaviyo: backfill requiere booleano.'); } $attributes['backfill']=$data['backfill']; }
            $r['path']='events'; $r['method']='POST'; $r['payload']=['data'=>['type'=>'event','attributes'=>$attributes]]; $r['async']=true;
        } elseif (in_array($op,['subscribe_profiles','unsubscribe_profiles'],true)) {
            $data=$this->object($p['properties'] ?? '{}'); $this->allowed($data,['profiles','channel','list_id','custom_source']);
            $channel=$data['channel'] ?? null;
            if (!in_array($channel,['email','sms'],true) || !is_array($data['profiles'] ?? null) || !array_is_list($data['profiles']) || count($data['profiles'])<1 || count($data['profiles'])>100) { throw new InvalidArgumentException('Klaviyo: canal email/sms y 1–100 perfiles requeridos.'); }
            $subscribe=$op==='subscribe_profiles'; $rows=[];
            foreach ($data['profiles'] as $item) {
                $item=$this->object($item); $this->allowed($item,['email','phone_number']); $this->profile($item,true);
                if (!isset($item[$channel==='email'?'email':'phone_number'])) { throw new InvalidArgumentException('Klaviyo: identificador requerido para el canal.'); }
                if ($subscribe) { $item['subscriptions']=[$channel=>['marketing'=>['consent'=>'SUBSCRIBED']]]; }
                else { $item['subscriptions']=[$channel=>['marketing'=>['consent'=>'UNSUBSCRIBED']]]; }
                $rows[]=['type'=>'profile','attributes'=>$item];
            }
            $type=$subscribe?'profile-subscription-bulk-create-job':'profile-subscription-bulk-delete-job';
            $resource=['type'=>$type,'attributes'=>['profiles'=>['data'=>$rows]]];
            if (isset($data['custom_source'])) { if (!$subscribe) { throw new InvalidArgumentException('Klaviyo: custom_source solo al suscribir.'); } $resource['attributes']['custom_source']=$this->text($data['custom_source'],255); }
            if (!$subscribe && isset($data['list_id'])) { throw new InvalidArgumentException('Klaviyo: unsubscribe_profiles es global; usa remove_profiles_from_list para quitar pertenencia.'); }
            if (isset($data['list_id'])) { $resource['relationships']=['list'=>['data'=>['type'=>'list','id'=>$this->key($data['list_id'])]]]; }
            $r['method']='POST'; $r['path']=$subscribe?'profile-subscription-bulk-create-jobs':'profile-subscription-bulk-delete-jobs'; $r['payload']=['data'=>$resource]; $r['async']=true;
        } else {
            [$verb,$entity]=explode('_',$op,2); $r['list']=$verb==='list';
            if (in_array($entity,['list_profiles','segment_profiles'],true)) {
                $r['path']=($entity==='list_profiles'?'lists':'segments') . '/' . $this->key($p['resource_id'] ?? null) . '/profiles'; $r['expected_type']='profile';
            } else {
                $type=rtrim($entity,'s'); $r['expected_type']=$type; $r['path']=$type . 's';
                if ($verb==='get') { $r['path'].='/' . $this->key($p['resource_id'] ?? null); }
            }
        }
        if ($r['list']) {
            $r['query']=['page[size]'=>$this->number($p['limit'] ?? 100,1,100)];
            if (($p['cursor'] ?? '')!=='') { $r['query']['page[cursor]']=$this->text($p['cursor'],8192); }
            $filters=$this->object(($p['filters'] ?? '')===''?'{}':$p['filters']);
            $this->allowed($filters,['filter','sort']);
            foreach ($filters as $k=>$v) { $this->text($v,4096); }
            if (isset($filters['sort'])) {
                $sorts=$r['expected_type']==='profile'?['id','created','updated','email']:['id','name','created','updated'];
                if (!in_array(ltrim($filters['sort'],'-'),$sorts,true)) { throw new InvalidArgumentException('Klaviyo: orden no admitido.'); }
            }
            $r['query']+=$filters;
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
            $options['headers']['Content-Type'] ??= 'application/json';
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
