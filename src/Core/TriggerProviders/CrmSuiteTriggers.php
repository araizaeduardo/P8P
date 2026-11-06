<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;

/** Authenticated Pipedrive v2 webhook; bounded read-only ActiveCampaign/Klaviyo polling. */
class CrmSuiteTriggers implements TriggerProviderInterface
{
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function definitions(): array
    {
        $field=static fn(string $label):array=>['type'=>'string','label'=>$label,'required'=>true];
        $definitions=[];
        foreach (['contact_created','contact_updated','deal_created','deal_updated'] as $event) {
            $definitions['active_campaign_' . $event]=[
                'name'=>'ActiveCampaign: ' . $event,'node_type'=>'active_campaign','event'=>$event,'transport'=>'polling','config_fields'=>[],
                'secret_fields'=>['base_url'=>$field('Origen API HTTPS (demo.api-us1.com)'),'api_token'=>$field('API token')],
                'help'=>'API v3; sin histórico inicial. Una página de hasta 100 por tick, ventanas hasta 1000 páginas y solapamiento 5 minutos. Consulta cdate/udate/mdate, no inventa firmas para webhooks sin firma del proveedor. Credencial igual a acciones. No registra webhooks ni cambia suscripciones. Requiere permisos de lectura del usuario y pipeline; deals limitados sin fecha se rechazan. Cambios borrados antes del sondeo o recibidos con más de 5 minutos de retraso no se garantizan; usar sistema externo de webhooks autenticados si requiere cada transición.',
                'docs'=>['https://developers.activecampaign.com/reference/list-all-contacts','https://developers.activecampaign.com/reference/list-all-deals','https://developers.activecampaign.com/reference/webhooks']];
        }
        foreach (['person','organization','deal'] as $entity) {
            foreach (['create','change'] as $action) {
                $key='pipedrive_' . $entity . '_' . $action;
                $definitions[$key]=['name'=>'Pipedrive: ' . $entity . ' ' . $action,'node_type'=>'pipedrive','event'=>$action . '.' . $entity,'transport'=>'webhook',
                    'config_fields'=>['company_id'=>$field('ID de empresa Pipedrive')],
                    'secret_fields'=>['http_auth_user'=>$field('Usuario Basic exclusivo del receptor'),'http_auth_password'=>$field('Contraseña Basic exclusiva (mínimo 16 caracteres)')],
                    'help'=>'Registrar externamente webhook version 2.0 con este event_action/event_object exacto y URL HTTPS, sin redirecciones. Configurar http_auth_user/http_auth_password exclusivos y hacer que PHP reciba Authorization. No hay firma inventada: autenticación HTTP Basic oficial sobre TLS. Verifica company_id y meta.version; deduplica meta.id. No necesita renovación de suscripción, pero Pipedrive puede eliminar receptores con fallos prolongados: supervisar y volver a registrar externamente. No registra ni borra webhooks automáticamente. Límite oficial 40 webhooks/usuario; visibilidad del usuario de registro limita entregas.',
                    'docs'=>['https://developers.pipedrive.com/docs/api/v1/Webhooks','https://pipedrive.readme.io/docs/guide-for-webhooks-v2']];
            }
        }
        $definitions['klaviyo_event_received']=['name'=>'Klaviyo: evento recibido (polling)','node_type'=>'klaviyo','event'=>'event_received','transport'=>'polling','config_fields'=>[],
            'secret_fields'=>['private_api_key'=>$field('Private API key pk_ con events:read')],
            'help'=>'Consulta exclusivamente Events API oficial revisión 2026-10-15, scope events:read. Sin histórico inicial ni creación de métricas/eventos. No promete webhooks de topics no disponibles para esta cuenta/credencial ni webhooks de flows sin autenticación. Una página hasta 100 por tick, ventana hasta 1000 páginas, solapamiento 5 minutos. Eventos con fecha de negocio retroactiva más allá del solapamiento pueden no observarse; no equivale a stream íntegro de ingestión. Datos: event_id, datetime, metric/profile relationships y properties (máximo 64 KiB por evento).',
            'docs'=>['https://developers.klaviyo.com/en/reference/get_events','https://developers.klaviyo.com/en/reference/get_webhook_topics']];
        return $definitions;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $definition=$this->definitions()[$key] ?? null;
        if (($definition['transport'] ?? null)!=='webhook') { throw new LogicException('Este trigger solo admite polling.'); }
        $user=$this->scalar($secrets['http_auth_user'] ?? null,255); $password=$this->scalar($secrets['http_auth_password'] ?? null,4096);
        if (str_contains($user,':') || strlen($password)<16) { throw new InvalidArgumentException('Pipedrive: configuración Basic inválida.'); }
        $authorization=$request['headers']['authorization'] ?? null;
        if (!is_string($authorization) || !hash_equals('Basic ' . base64_encode($user . ':' . $password),$authorization)) { throw new InvalidArgumentException('Pipedrive: autenticación Basic inválida.'); }
        $raw=$request['raw_body'] ?? null;
        if (($request['method'] ?? null)!=='POST' || !is_string($raw) || strlen($raw)>1048576) { throw new InvalidArgumentException('Pipedrive: petición inválida.'); }
        $body=$this->json($raw); $meta=$body['meta'] ?? null;
        if (!is_array($meta) || ($meta['version'] ?? null)!=='2.0' || $this->positive($meta['company_id'] ?? null)!==$this->positive($config['company_id'] ?? null)) { throw new InvalidArgumentException('Pipedrive: empresa o versión de evento incompatible.'); }
        [$action,$entity]=explode('.',$definition['event']);
        if (($meta['action'] ?? null)!==$action || ($meta['entity'] ?? null)!==$entity) { throw new InvalidArgumentException('Pipedrive: evento no corresponde al receptor dedicado.'); }
        $id=$this->scalar($meta['id'] ?? null,128);
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$id)) { throw new InvalidArgumentException('Pipedrive: ID de evento inválido.'); }
        $at=$this->date($meta['timestamp'] ?? null); $entityId=$this->positive($meta['entity_id'] ?? null);
        if (!is_array($body['data'] ?? null) || $this->positive($body['data']['id'] ?? null)!==$entityId) { throw new InvalidArgumentException('Pipedrive: recurso de evento inválido.'); }
        $data=['id'=>$entityId,'entity'=>$entity,'action'=>$action,'company_id'=>(string)$meta['company_id'],'event_id'=>$id];
        foreach (['stage_id','pipeline_id','person_id','org_id','owner_id','status','update_time','add_time'] as $field) {
            if (array_key_exists($field,$body['data'])) {
                $v=$body['data'][$field];
                if (!is_scalar($v) && $v!==null) { throw new InvalidArgumentException('Pipedrive: metadatos inválidos.'); }
                if (is_string($v)) { $this->scalar($v,256); } $data[$field]=$v;
            }
        }
        $this->noSecrets($data,$secrets);
        return ['status'=>200,'body'=>'','content_type'=>'text/plain','events'=>[['id'=>$id,'type'=>$definition['event'],'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'data'=>$data]]];
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        $d=$this->definitions()[$key] ?? null;
        if (($d['transport'] ?? null)!=='polling') { throw new LogicException('Tipo de polling inválido.'); }
        $klaviyo=$d['node_type']==='klaviyo';
        if ($klaviyo) {
            $token=$this->scalar($secrets['private_api_key'] ?? null,4096);
            if (!preg_match('/^pk_[A-Za-z0-9_-]{5,512}$/D',$token)) { throw new InvalidArgumentException('Klaviyo: private API key inválida.'); }
            $origin='https://a.klaviyo.com';
        } else {
            $origin=$this->scalar($secrets['base_url'] ?? null,512);
            if (!preg_match('~^https://[a-zA-Z0-9-]+\.(?:api-us1|api-eu1|api-ap1)\.com/?$~D',$origin)) { throw new InvalidArgumentException('ActiveCampaign: origen API inválido.'); }
            $origin=rtrim($origin,'/'); $token=$this->scalar($secrets['api_token'] ?? null,4096);
            if (preg_match('/\s/',$token)) { throw new InvalidArgumentException('ActiveCampaign: token inválido.'); }
        }
        ksort($secrets); ksort($config); $binding=hash('sha256',json_encode([$key,$config,$secrets],JSON_THROW_ON_ERROR));
        if (isset($state['binding']) && $state['binding']!==$binding) { throw new RuntimeException('Credencial o configuración cambió: pausa y reactiva el trigger.'); }
        $state['binding']=$binding; $activated=$this->date($state['activated_at'] ?? null);
        if (!isset($state['watermark'])) { $state['watermark']=$activated; return ['events'=>[],'state'=>$state,'has_more'=>false]; }
        if (!is_int($state['watermark']) || $state['watermark']<$activated) { throw new RuntimeException('Estado de polling inválido.'); }
        $w=$state['window'] ?? ['lower'=>max($activated,$state['watermark']-300),'upper'=>max($activated,$now-30),'cursor'=>null,'page'=>0];
        if (!is_array($w) || !is_int($w['lower'] ?? null) || !is_int($w['upper'] ?? null) || $w['lower']>$w['upper'] || !is_int($w['page'] ?? null) || $w['page']<0 || $w['page']>=1000) { throw new RuntimeException('Ventana inválida o supera 1000 páginas; cursor conservado.'); }
        if ($w['upper']<=$activated) { return ['events'=>[],'state'=>$state,'has_more'=>false]; }
        $created=str_ends_with($d['event'],'_created'); $stamp=$created?'cdate':(str_starts_with($d['event'],'contact')?'udate':'mdate');
        $entity=str_starts_with($d['event'],'contact')?'contacts':'deals';
        if ($klaviyo) {
            $path='/api/events'; $query=['page[size]'=>100,'sort'=>'datetime','filter'=>'and(greater-or-equal(datetime,' . gmdate('Y-m-d\TH:i:s\Z',$w['lower']) . '),less-or-equal(datetime,' . gmdate('Y-m-d\TH:i:s\Z',$w['upper']) . '))'];
            if ($w['cursor']!==null) { $query['page[cursor]']=$this->scalar($w['cursor'],8192); }
            $headers=['Authorization'=>'Klaviyo-API-Key ' . $token,'revision'=>'2026-10-15','Accept'=>'application/vnd.api+json'];
        } else {
            $path='/api/3/' . $entity;
            $query=['limit'=>100,'offset'=>$w['cursor']??0,'filters[' . ($created?'created':'updated') . '_after]'=>gmdate('Y-m-d\TH:i:s\Z',$w['lower']-1),'filters[' . ($created?'created':'updated') . '_before]'=>gmdate('Y-m-d\TH:i:s\Z',$w['upper']+1),'orders[cdate]'=>'ASC'];
            if (!is_int($query['offset']) || $query['offset']<0 || $query['offset']>100000) { throw new RuntimeException('ActiveCampaign: offset inválido.'); }
            $headers=['Api-Token'=>$token,'Accept'=>'application/json'];
        }
        $body=$this->request($origin . $path,$headers,$query,$now);
        $rows=$klaviyo?($body['data']??null):($body[$entity]??null);
        if (!is_array($rows) || !array_is_list($rows) || count($rows)>100) { throw new RuntimeException('Polling: lista remota inválida.'); }
        $events=[];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new RuntimeException('Polling: recurso remoto inválido.'); }
            if ($klaviyo) {
                if (($row['type'] ?? null)!=='event' || !is_array($row['attributes'] ?? null)) { throw new RuntimeException('Klaviyo: evento incompleto.'); }
                $id=$this->scalar($row['id'] ?? null,128); $at=$this->date($row['attributes']['datetime'] ?? null);
                $data=['event_id'=>$id,'datetime'=>$row['attributes']['datetime'],'properties'=>$row['attributes']['event_properties'] ?? []];
                foreach (['metric','profile'] as $relationship) {
                    if (isset($row['relationships'][$relationship]['data']['id'])) { $data[$relationship . '_id']=$this->scalar($row['relationships'][$relationship]['data']['id'],128); }
                }
                if (!is_array($data['properties']) || strlen(json_encode($data,JSON_THROW_ON_ERROR))>65536) { throw new RuntimeException('Klaviyo: propiedades remotas inválidas o >64 KiB.'); }
            } else {
                $id=$this->positive($row['id'] ?? null); $at=$this->date($row[$stamp] ?? null);
                $data=['id'=>$id,'resource'=>$entity,'created_at'=>$row['cdate'] ?? null,'updated_at'=>$row[$entity==='contacts'?'udate':'mdate'] ?? null];
                foreach (['stage','group','status','contact'] as $f) { if (isset($row[$f])) { $data[$f]=$this->scalar($row[$f],128); } }
            }
            $this->noSecrets($data,$secrets);
            if ($at>$activated && $at>=$w['lower'] && $at<=$w['upper']) { $events[]=['id'=>$key . ':' . $id . ':' . $at,'type'=>$d['event'],'occurred_at'=>gmdate('Y-m-d\TH:i:s\Z',$at),'data'=>$data]; }
        }
        $next=null;
        if ($klaviyo && ($body['links']['next'] ?? null)!==null) {
            $url=$this->scalar($body['links']['next'],16384); $u=parse_url($url);
            if (($u['scheme']??null)!=='https' || ($u['host']??null)!=='a.klaviyo.com' || !in_array($u['path']??null,['/api/events','/api/events/'],true) || isset($u['port']) || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || str_contains($url,'\\')) { throw new RuntimeException('Klaviyo: continuación fuera de origen permitido.'); }
            parse_str($u['query']??'',$q); $next=$this->scalar($q['page']['cursor']??null,8192);
        } elseif (!$klaviyo) {
            $total=$body['meta']['total'] ?? null;
            if ((!is_int($total) && !(is_string($total)&&ctype_digit($total))) || (int)$total<0) { throw new RuntimeException('ActiveCampaign: falta total de paginación.'); }
            if ((int)$total>$query['offset']+count($rows)) {
                if ($rows===[]) { throw new RuntimeException('ActiveCampaign: página vacía sin avance.'); }
                $next=$query['offset']+count($rows);
            }
        }
        if ($next!==null) {
            if ($next===$w['cursor']) { throw new RuntimeException('Cursor de polling sin avance.'); }
            $w['cursor']=$next; $w['page']++; $state['window']=$w;
        } else { $state['watermark']=$w['upper']; unset($state['window']); }
        return ['events'=>$events,'state'=>$state,'has_more'=>$next!==null];
    }

    private function request(string $url, array $headers, array $query, int $now): array
    {
        try {
            $response=($this->client ?? new Client())->request('GET',$url,['headers'=>$headers,'query'=>$query,'timeout'=>25,'connect_timeout'=>8,'read_timeout'=>25,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false,'stream'=>true]);
            $status=$response->getStatusCode(); $body=$response->getBody(); $raw='';
            try {
                while (!$body->eof() && strlen($raw)<=4194304) {
                    $part=$body->read(min(65536,4194305-strlen($raw)));
                    if ($part==='' && !$body->eof()) { throw new RuntimeException(); } $raw.=$part;
                }
            } finally { $body->close(); }
        } catch (\Throwable $e) { throw new TriggerRetryException(60); }
        if ($status===408 || $status===429 || $status>=500) {
            $retry=trim($response->getHeaderLine('Retry-After')); $seconds=ctype_digit($retry)?(int)$retry:(($at=strtotime($retry))!==false?max(1,$at-$now):60);
            throw new TriggerRetryException(max(1,min(86400,$seconds)));
        }
        if ($status!==200) { throw new RuntimeException('Polling rechazado por autenticación, permisos o recurso (HTTP ' . $status . ').'); }
        if (strlen($raw)>4194304) { throw new RuntimeException('Polling: respuesta >4 MiB.'); }
        $data=$this->json($raw);
        if (!empty($data['errors']) || !empty($data['error'])) { throw new RuntimeException('Polling: error funcional del proveedor.'); }
        return $data;
    }

    private function json(string $raw): array
    {
        try { $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('JSON remoto inválido.'); }
        if (!is_array($data) || array_is_list($data)) { throw new InvalidArgumentException('Objeto JSON remoto requerido.'); }
        return $data;
    }

    private function scalar(mixed $value, int $max): string
    {
        if ((!is_string($value) && !is_int($value)) || trim((string)$value)==='' || strlen((string)$value)>$max || preg_match('/[\x00-\x1f\x7f]/',(string)$value)) { throw new InvalidArgumentException('Dato requerido o metadato inválido.'); }
        return (string)$value;
    }

    private function positive(mixed $value): string
    {
        $value=$this->scalar($value,19);
        if (!preg_match('/^[1-9][0-9]*$/D',$value)) { throw new InvalidArgumentException('ID positivo requerido.'); }
        return $value;
    }

    private function date(mixed $value): int
    {
        $value=$this->scalar($value,40);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value)) { throw new InvalidArgumentException('Fecha RFC3339 requerida.'); }
        try { $date=new \DateTimeImmutable($value); } catch (\Throwable $e) { throw new InvalidArgumentException('Fecha remota inválida.'); }
        $errors=\DateTimeImmutable::getLastErrors();
        if ($errors!==false && ($errors['warning_count'] || $errors['error_count'])) { throw new InvalidArgumentException('Fecha remota inválida.'); }
        return $date->getTimestamp();
    }

    private function noSecrets(array $data, array $secrets): void
    {
        $encoded=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        foreach ($secrets as $name=>$secret) {
            if (!in_array($name,['base_url','http_auth_user'],true) && is_string($secret) && strlen($secret)>=8 && str_contains($encoded,$secret)) { throw new RuntimeException('Secreto reflejado por proveedor: evento rechazado.'); }
        }
    }
}
