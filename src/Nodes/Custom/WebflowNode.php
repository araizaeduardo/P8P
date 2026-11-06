<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;


class WebflowNode implements NodeInterface
{
    private const NAME='Webflow';
    private const OPS=['list_collections','get_collection','list_items','get_item','create_item','update_item','publish_items','list_form_submissions'];
    public function __construct(private ?ClientInterface $client=null,private ?CredentialManager $credentials=null) {}

    public function getSchema(): array
    {
        return ['type'=>'webflow','name'=>'Webflow','category'=>'Community','icon'=>'Layout','is_start'=>false,
            'description'=>'Colecciones y CMS staged/live, publicación explícita y envíos de formularios Data API v2.',
            'help_text'=>'generic_bearer JSON {"access_token":"TOKEN"}. Site token/OAuth externo con cms:read, cms:write; forms:read para envíos; sites:read para validar locales. Renovación manual si caduca. Crear/actualizar siempre staged, creación borrador isDraft=true; solo publish_items publica y puede hacer público el contenido. field_data objeto JSON validado contra esquema de colección (lectura previa). Actualizar envía solo campos proporcionados; vacío/null explícito permitido en campos opcionales según tipo/API. cms_locale_id opcional requiere site_id para validar locale CMS (distinto de locale ID del sitio). content_mode staged/live solo lectura. Una página 1–100 y offset máximo 100000. Publicar máximo 100 IDs, locale opcional. Triggers oficiales CMS/formularios requieren webhook creado por API con secreto de firma; se rechazan webhooks de Dashboard sin firma. Registro remoto manual; sin reintentos/redirecciones.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial Webflow JSON','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_collections','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],self::OPS)],
                'site_id'=>['type'=>'string','label'=>'Site ID (listar colecciones/envíos/validar locale)'],
                'collection_id'=>['type'=>'string','label'=>'Collection ID'],
                'item_id'=>['type'=>'string','label'=>'Item ID (obtener/actualizar)'],
                'cms_locale_id'=>['type'=>'string','label'=>'CMS Locale ID opcional'],
                'field_data'=>['type'=>'textarea','label'=>'fieldData JSON (crear/actualizar)'],
                'item_ids'=>['type'=>'textarea','label'=>'Lista JSON de IDs para publicación explícita'],
                'content_mode'=>['type'=>'select','label'=>'Contenido al leer','default'=>'staged','options'=>[['value'=>'staged','label'=>'Staged'],['value'=>'live','label'=>'Publicado']]],
                'limit'=>['type'=>'number','label'=>'Resultados 1–100','default'=>100],
                'offset'=>['type'=>'number','label'=>'Offset 0–100000','default'=>0],
                'form_element_id'=>['type'=>'string','label'=>'Element ID de formulario para filtrar envíos (opcional)']
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters,array $context): array
    {
        $p=$this->prepare($parameters); $c=$this->credential($p['credential_id']);
        $token=$this->text($c['access_token']??null,'access_token',16384);
        if (preg_match('/\s/',$token)) { throw new InvalidArgumentException('Webflow: access_token inválido.'); }
        $h=['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']; $op=$p['operation']; $method='GET'; $options=[];
        if (isset($p['cms_locale_id'])) {
            [, $site]=$this->http('GET','https://api.webflow.com/v2/sites/'.$p['site_id'],$h);
            $locales=$site['locales']??null;
            if (!is_array($locales)||!is_array($locales['primary']??null)||!is_array($locales['secondary']??null)) { throw new RuntimeException('Webflow: esquema de locales incompleto.'); }
            $valid=array_column([$locales['primary'],...$locales['secondary']],'cmsLocaleId');
            if (!in_array($p['cms_locale_id'],$valid,true)) { throw new InvalidArgumentException('Webflow: CMS locale no pertenece al sitio.'); }
        }
        $collection='/collections/'.($p['collection_id']??'');
        if (in_array($op,['create_item','update_item'],true)) {
            [, $schema]=$this->http('GET','https://api.webflow.com/v2'.$collection,$h);
            $this->fields($p['field_data'],$schema,$op==='create_item');
        }
        $path=match($op) {
            'list_collections'=>'/sites/'.$p['site_id'].'/collections','get_collection'=>$collection,
            'list_items'=>$collection.'/items'.($p['content_mode']==='live'?'/live':''),
            'get_item'=>$collection.'/items/'.$p['item_id'].($p['content_mode']==='live'?'/live':''),
            'create_item'=>$collection.'/items','update_item'=>$collection.'/items/'.$p['item_id'],
            'publish_items'=>$collection.'/items/publish','list_form_submissions'=>'/sites/'.$p['site_id'].'/form_submissions'
        };
        if (in_array($op,['list_items','list_collections','list_form_submissions'],true)) {
            $options['query']=['limit'=>$p['limit'],'offset'=>$p['offset']];
            if (isset($p['form_element_id'])) { $options['query']['elementId']=$p['form_element_id']; }
        }
        if (isset($p['cms_locale_id'])&&in_array($op,['list_items','get_item'],true)) { $options['query']['cmsLocaleId']=$p['cms_locale_id']; }
        if (in_array($op,['create_item','update_item'],true)) {
            $method=$op==='create_item'?'POST':'PATCH'; $options['json']=['fieldData'=>(object)$p['field_data']];
            if ($op==='create_item') { $options['json']['isDraft']=true; }
            if (isset($p['cms_locale_id'])) { $options['json']['cmsLocaleId']=$p['cms_locale_id']; }
        }
        if ($op==='publish_items') {
            $method='POST';
            $options['json']=isset($p['cms_locale_id'])?['items'=>array_map(fn($id)=>['id'=>$id,'cmsLocaleIds'=>[$p['cms_locale_id']]],$p['item_ids'])]:['itemIds'=>$p['item_ids']];
        }
        [$status,$body]=$this->http($method,'https://api.webflow.com/v2'.$path,$h,$options);
        if (in_array($op,['list_items','list_collections','list_form_submissions'],true)) {
            $key=match($op) {'list_items'=>'items','list_collections'=>'collections','list_form_submissions'=>'formSubmissions'};
            if (!is_array($body[$key]??null)||!array_is_list($body[$key])||count($body[$key])>$p['limit']) { throw new RuntimeException('Webflow: página incompleta o inválida.'); }
            $pagination=$body['pagination']??null;
            if (!is_array($pagination)||!is_int($pagination['total']??null)||$pagination['total']<0) {
                // Collection listing may omit pagination: it returns all collections for one site.
                if ($op!=='list_collections') { throw new RuntimeException('Webflow: metadatos de paginación incompletos.'); }
                $pagination=['total'=>count($body[$key])];
            }
            $next=$p['offset']+count($body[$key]); $more=$next<$pagination['total'];
            $body['pagination']=$pagination+['offset'=>$p['offset'],'limit'=>$p['limit']];
            $body['pagination']+=['next_offset'=>$more&&$next<=100000?$next:null,'has_more'=>$more,'limit_reached'=>$more&&$next>100000,'processed_pages'=>1];
        } elseif ($op==='publish_items') {
            if (!is_array($body['publishedItemIds']??null)) { throw new RuntimeException('Webflow: confirmación de publicación incompleta; comprueba estado remoto antes de repetir.'); }
        } elseif (!is_string($body['id']??null)) { throw new RuntimeException('Webflow: respuesta sin recurso válido; comprueba estado remoto antes de repetir.'); }
        return $this->result($status,$body,[$token]);
    }

    private function fields(array $data,array $schema,bool $create): void
    {
        if (!is_array($schema['fields']??null)||!array_is_list($schema['fields'])) { throw new RuntimeException('Webflow: esquema de colección inválido.'); }
        $fields=[];
        foreach ($schema['fields'] as $field) {
            if (!is_array($field)||!is_string($field['slug']??null)||!is_string($field['type']??null)||!is_bool($field['isRequired']??null)) { throw new RuntimeException('Webflow: definición de campo incompleta.'); }
            $fields[$field['slug']]=$field;
        }
        foreach (['name','slug'] as $key) { $fields[$key]??=['type'=>'PlainText','isRequired'=>true]; }
        $this->allowed($data,array_keys($fields),'field_data');
        foreach ($fields as $slug=>$definition) {
            if (!array_key_exists($slug,$data)) { if ($create&&$definition['isRequired']) { throw new InvalidArgumentException('Webflow: falta campo requerido de colección.'); } continue; }
            $v=$data[$slug]; $type=$definition['type'];
            if ($definition['isRequired']&&($v===null||$v===''||$v===[])) { throw new InvalidArgumentException('Webflow: campo requerido no admite vaciado.'); }
            if ($v===null) { continue; }
            if (in_array($type,['PlainText','RichText','Email','Link','Phone','VideoLink','Color','DateTime','Option','Reference'],true)&&!is_string($v)) { throw new InvalidArgumentException('Webflow: tipo de campo debe ser texto.'); }
            if (is_string($v)&&strlen($v)>262144) { throw new InvalidArgumentException('Webflow: campo demasiado largo.'); }
            if ($type==='Number'&&!is_int($v)&&!is_float($v)) { throw new InvalidArgumentException('Webflow: número requerido.'); }
            if ($type==='Switch'&&!is_bool($v)) { throw new InvalidArgumentException('Webflow: booleano requerido.'); }
            if (in_array($type,['MultiReference','MultiImage'],true)&&(!is_array($v)||!array_is_list($v)||count($v)>100)) { throw new InvalidArgumentException('Webflow: lista máxima 100 requerida.'); }
            if ($type==='Reference'&&$v!=='') { $this->webflowId($v,'reference'); }
            if ($type==='MultiReference') { foreach ($v as $id) { $this->webflowId($id,'reference'); } }
            if ($type==='Email'&&$v!==''&&!filter_var($v,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Webflow: email inválido.'); }
            if ($type==='DateTime'&&$v!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}T/',$v)||strtotime($v)===false)) { throw new InvalidArgumentException('Webflow: fecha inválida.'); }
            if ($slug==='slug'&&$v!==''&&!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$v)) { throw new InvalidArgumentException('Webflow: slug inválido.'); }
        }
    }

    private function webflowId(mixed $v,string $field): string
    {
        $v=$this->text($v,$field,24); if (!preg_match('/^[a-f0-9]{24}$/D',$v)) { throw new InvalidArgumentException('Webflow: ID hexadecimal de 24 caracteres requerido.'); } return $v;
    }

    private function prepare(array $p): array
    {
        $op=$p['operation']??'list_collections'; if (!is_string($op)||!in_array($op,self::OPS,true)) { throw new InvalidArgumentException('Webflow: operación inválida.'); }
        $r=['credential_id'=>$this->text($p['credential_id']??null,'credential_id',255),'operation'=>$op];
        if (in_array($op,['list_collections','list_form_submissions'],true)) { $r['site_id']=$this->webflowId($p['site_id']??null,'site_id'); }
        else { $r['collection_id']=$this->webflowId($p['collection_id']??null,'collection_id'); }
        if (in_array($op,['get_item','update_item'],true)) { $r['item_id']=$this->webflowId($p['item_id']??null,'item_id'); }
        if (isset($p['cms_locale_id'])&&$p['cms_locale_id']!=='') { $r['cms_locale_id']=$this->webflowId($p['cms_locale_id'],'cms_locale_id'); $r['site_id']=$this->webflowId($p['site_id']??null,'site_id'); }
        if (in_array($op,['list_items','get_item'],true)) { $r['content_mode']=$p['content_mode']??'staged'; if (!in_array($r['content_mode'],['staged','live'],true)) { throw new InvalidArgumentException('Webflow: content_mode inválido.'); } }
        if (in_array($op,['list_items','list_collections','list_form_submissions'],true)) { $r['limit']=$this->integer($p['limit']??100,'limit',1,100); $r['offset']=$this->integer($p['offset']??0,'offset',0,100000); }
        if ($op==='list_form_submissions'&&isset($p['form_element_id'])&&$p['form_element_id']!=='') { $r['form_element_id']=$this->id($p['form_element_id'],'form_element_id'); }
        if (in_array($op,['create_item','update_item'],true)) { $r['field_data']=$this->object($p['field_data']??null,'field_data'); if ($r['field_data']===[]) { throw new InvalidArgumentException('Webflow: field_data vacío.'); } }
        if ($op==='publish_items') {
            $v=$p['item_ids']??null; if (is_string($v)) { try { $v=json_decode($v,true,32,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('Webflow: lista JSON inválida.'); } }
            if (!is_array($v)||!array_is_list($v)||count($v)<1||count($v)>100) { throw new InvalidArgumentException('Webflow: 1–100 item_ids requeridos.'); }
            $r['item_ids']=[]; foreach ($v as $id) { $r['item_ids'][]=$this->webflowId($id,'item_ids'); } $r['item_ids']=array_values(array_unique($r['item_ids']));
        }
        return $r;
    }

    private function credential(mixed $id): array
    {
        $id = $this->text($id, 'credential_id', 255);
        try {
            $record = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException(self::NAME . ': fallo al recuperar o descifrar la credencial en p8p.');
        }
        if ($record === null) { throw new InvalidArgumentException(self::NAME . ': credencial inexistente.'); }
        if (($record['type'] ?? null) !== 'generic_bearer' || !is_string($record['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException(self::NAME . ': requiere generic_bearer con JSON en API Key.');
        }
        return $this->object($record['data']['api_key'], 'credencial');
    }

    private function http(string $method, string $url, array $headers, array $options = [], bool $binary = false): array
    {
        $write = $method !== 'GET' && !str_contains($url, '/list_') && !str_contains($url, '/download');
        if (isset($options['json'])) {
            $options['body'] = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            unset($options['json']);
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = ($this->client ?? new Client())->send(new Request($method, $url, $headers), $options + [
                'headers' => null, 'auth' => null, 'cookies' => false, 'timeout' => 30, 'connect_timeout' => 8,
                'read_timeout' => 30, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(self::NAME . ': fallo de transporte; sin reintentos.' . ($write ? ' Resultado incierto: comprueba el recurso antes de repetir.' : ''));
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $response->getBody()->close();
            $reason = match ($status) { 401 => 'autenticación caducada o inválida', 403 => 'permisos o plan insuficientes', 404 => 'recurso inexistente o inaccesible', 409 => 'conflicto', 429 => 'cuota o límite de solicitudes', default => 'solicitud rechazada por el servicio' };
            throw new RuntimeException(self::NAME . ': ' . $reason . ' (HTTP ' . $status . '). Sin reintentos.', $status);
        }
        $limit = $binary ? 10485760 : 8388608;
        $raw = ''; $stream = $response->getBody();
        try {
            while (!$stream->eof() && strlen($raw) <= $limit) {
                $part = $stream->read(min(65536, $limit + 1 - strlen($raw)));
                if ($part === '' && !$stream->eof()) { throw new RuntimeException(); }
                $raw .= $part;
            }
            if (strlen($raw) > $limit) { throw new RuntimeException(); }
        } catch (\Throwable $e) {
            throw new RuntimeException(self::NAME . ': respuesta incompleta o superior al límite local.' . ($write ? ' Resultado incierto; comprueba el recurso antes de repetir.' : ''));
        } finally { $stream->close(); }
        if ($binary) { return [$status, $raw, $response]; }
        if ($raw === '' && in_array($status, [202, 204], true)) { return [$status, [], $response]; }
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new RuntimeException(self::NAME . ': JSON remoto inválido.'); }
        if (!is_array($body) || array_is_list($body) || isset($body['error']) || (!empty($body['errors']))) {
            throw new RuntimeException(self::NAME . ': respuesta inválida o error funcional.');
        }
        return [$status, $body, $response];
    }

    private function text(mixed $value, string $field, int $max = 1024, bool $empty = false): string
    {
        if ((!is_string($value) && !is_int($value)) || (!$empty && trim((string)$value) === '') || strlen((string)$value) > $max
            || !mb_check_encoding((string)$value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', (string)$value)) {
            throw new InvalidArgumentException(self::NAME . ': campo ' . $field . ' inválido.');
        }
        return (string)$value;
    }

    private function id(mixed $value, string $field): string
    {
        $id = $this->text($value, $field, 255);
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id)) { throw new InvalidArgumentException(self::NAME . ': ID ' . $field . ' inválido.'); }
        return $id;
    }

    private function integer(mixed $value, string $field, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/D', $value)) { $value = (int)$value; }
        if (!is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException(self::NAME . ': ' . $field . ' fuera de rango.'); }
        return $value;
    }

    private function object(mixed $value, string $field): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) { throw new InvalidArgumentException(self::NAME . ': JSON demasiado grande.'); }
            try { $shape = json_decode($value, false, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException(self::NAME . ': JSON ' . $field . ' inválido.'); }
            if (!$shape instanceof \stdClass) { throw new InvalidArgumentException(self::NAME . ': ' . $field . ' debe ser objeto JSON.'); }
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        }
        if ($value instanceof \stdClass) { $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR); }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) { throw new InvalidArgumentException(self::NAME . ': ' . $field . ' debe ser objeto.'); }
        return $value;
    }

    private function allowed(array $value, array $keys, string $field): void
    {
        if (array_diff(array_keys($value), $keys)) { throw new InvalidArgumentException(self::NAME . ': campos no admitidos en ' . $field . '.'); }
    }

    private function safe(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $part) {
                if (is_string($key) && preg_match('/^(?:access_token|refresh_token|start_url|download_access_token|recording_play_passcode|password|token|download_url|play_url|share_url|host_email)$/iD', $key)) { continue; }
                $out[$key] = $this->safe($part, $secrets);
            }
            return $out;
        }
        if (is_string($value)) {
            foreach ($secrets as $secret) {
                if (is_string($secret) && strlen($secret) >= 4 && (str_contains($value, $secret) || str_contains($value, rawurlencode($secret)))) {
                    return '[redacted]';
                }
            }
        }
        return $value;
    }

    private function result(int $status, array $body, array $secrets): array
    {
        return ['success' => true, 'status_code' => $status, 'data' => $this->safe($body, $secrets)];
    }
}
