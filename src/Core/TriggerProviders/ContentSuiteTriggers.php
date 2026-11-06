<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;
use RuntimeException;

/** Read-only cursor polling and verified webhooks; TriggerManager owns durable delivery. */
class ContentSuiteTriggers implements TriggerProviderInterface
{
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function definitions(): array
    {
        $field = static fn(string $label, bool $required = true): array => ['type'=>'string','label'=>$label,'required'=>$required];
        $defs = [
            'dropbox_changes' => [
                'name'=>'Dropbox: cambios de archivos y carpetas','node_type'=>'dropbox','event'=>'file.changed','transport'=>'polling',
                'config_fields'=>['path'=>$field('Ruta Dropbox, vacío = raíz', false)],
                'secret_fields'=>['access_token'=>$field('Access token Dropbox OAuth')],
                'help'=>'Polling oficial list_folder/get_latest_cursor y continue, recursivo. Primera ejecución establece cursor sin histórico; después entrega cambios y borrados. Máximo 100 eventos por turno, conserva páginas restantes cifradas y cursor en transacción con la cola. Requiere files.metadata.read. Cursor reset/caducado exige desactivar/reactivar y nueva baseline, no pierde cambios silenciosamente. Sin webhook remoto ni renovación OAuth; no se necesitan notificaciones para este modo.',
                'docs'=>['https://www.dropbox.com/developers/documentation/http/documentation#files-list_folder-get_latest_cursor','https://www.dropbox.com/developers/reference/webhooks']
            ],
            'jotform_new_submission'=>[
                'name'=>'Jotform: nueva respuesta confirmada','node_type'=>'jotform','event'=>'submission.created','transport'=>'webhook',
                'config_fields'=>['form_id'=>$field('ID numérico del formulario')],
                'secret_fields'=>['api_key'=>$field('API key Jotform de lectura'),'region'=>$field('standard, eu o hipaa')],
                'help'=>'Registra manualmente webhook en formulario. Jotform no documenta firma de eventos: no inventa HMAC. Usa únicamente submissionID y formID y confirma mediante GET autenticado /submission/ID, verifica pertenencia y answers; no confía en respuestas del POST. API autentica existencia del recurso, no identidad del remitente; quien conozca URL e ID podría solicitar entrega de un envío antiguo no procesado. Deduplicación durable por ID de envío. Mantén URL privada. Soporta multipart, form-urlencoded y JSON; sin descargas ni suscripciones nuevas automáticas.',
                'docs'=>['https://www.jotform.com/apidocs-v1/','https://www.jotform.com/help/245-how-to-send-submission-data-via-a-webhook/']
            ]
        ];
        foreach (['meeting.created','meeting.updated','meeting.started','meeting.ended','recording.completed'] as $event) {
            $defs['zoom_'.str_replace('.','_',$event)] = [
                'name'=>'Zoom: '.$event,'node_type'=>'zoom','event'=>$event,'transport'=>'webhook','setup_when_disabled'=>true,
                'config_fields'=>['account_id'=>$field('Account ID Zoom de la suscripción')],
                'secret_fields'=>['webhook_secret'=>$field('Secret token de webhook Zoom')],
                'help'=>'Registrar suscripción externa al evento exacto y account_id. Firma raw-body v0 HMAC-SHA256 con timestamp ±300 segundos. CRC endpoint.url_validation y revalidación cada 72 horas al recibir (también draft). Eventos no se admiten en draft/desactivado. Solo IDs/metadatos; sin start_url ni tokens/URLs grabaciones. Deduplicación hash del cuerpo firmado. Permisos del evento en Zoom Marketplace y plan elegible para cloud recording; sin registro/renovación OAuth automática.',
                'docs'=>['https://developers.zoom.us/docs/api/webhooks/','https://developers.zoom.us/docs/api/meetings/']
            ];
        }
        foreach (['form_submission','collection_item_created','collection_item_changed','collection_item_deleted','collection_item_published','collection_item_unpublished'] as $event) {
            $config=['site_id'=>$field('Site ID Webflow')];
            if ($event!=='form_submission') { $config['collection_id']=$field('Collection ID'); }
            $defs['webflow_'.$event] = [
                'name'=>'Webflow: '.$event,'node_type'=>'webflow','event'=>$event,'transport'=>'webhook',
                'config_fields'=>$config,'secret_fields'=>['webhook_secret'=>$field('Secreto webhook de site token o client_secret OAuth')],
                'help'=>'Webhook creado por API con firma obligatoria x-webflow-signature y timestamp; rechaza Dashboard sin firma. Valida raw body con timestamp:body, tolerancia 5 minutos. Site/collection firmados deben coincidir. Registrar evento y URL manualmente; tokens/scopes cms:read o forms:read y permiso para webhooks según servicio. Deduplicación por hash del cuerpo firmado. Sin renovación ni registro remoto automáticos.',
                'docs'=>['https://developers.webflow.com/data/docs/working-with-webhooks','https://developers.webflow.com/data/reference/webhooks/create']
            ];
        }
        return $defs;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $def=$this->definitions()[$key]??null;
        if (!$def||$def['transport']!=='webhook'||($request['method']??null)!=='POST') { throw new InvalidArgumentException('Content: receptor o método inválido.'); }
        $raw=$request['raw_body']??null;
        if (!is_string($raw)||strlen($raw)>1048576||!is_array($request['headers']??null)) { throw new InvalidArgumentException('Content: petición inválida.'); }
        $headers=$request['headers'];
        if ($def['node_type']==='jotform') {
            if (($request['setup_allowed']??false)===true) { throw new InvalidArgumentException('Jotform: receptor no activado.'); }
            return $this->jotform($config,$secrets,$request);
        }
        $secret=$this->text($secrets['webhook_secret']??null,'webhook_secret',4096);
        if ($def['node_type']==='zoom') {
            $timestamp=$headers['x-zm-request-timestamp']??null;
            $signature=$headers['x-zm-signature']??null;
            if (!is_string($timestamp)||!preg_match('/^[0-9]{10}$/D',$timestamp)||abs($now-(int)$timestamp)>300
                ||!is_string($signature)||!hash_equals('v0='.hash_hmac('sha256','v0:'.$timestamp.':'.$raw,$secret),$signature)) { throw new InvalidArgumentException('Zoom: firma inválida o caducada.'); }
            $body=$this->json($raw);
            if (($body['event']??null)==='endpoint.url_validation') {
                $plain=$this->text($body['payload']['plainToken']??null,'plainToken',512);
                return ['status'=>200,'content_type'=>'application/json','body'=>json_encode(['plainToken'=>$plain,'encryptedToken'=>hash_hmac('sha256',$plain,$secret)],JSON_THROW_ON_ERROR),'events'=>[],'challenge'=>true];
            }
            if (($request['setup_allowed']??false)===true) { throw new InvalidArgumentException('Zoom: receptor no activado.'); }
            if (($body['event']??null)!==$def['event']) { return $this->ack([]); }
            $account=$this->text($config['account_id']??null,'account_id',255);
            if (($body['payload']['account_id']??null)!==$account||!is_int($body['event_ts']??null)||!is_array($body['payload']['object']??null)) { throw new InvalidArgumentException('Zoom: cuenta o evento incompatible.'); }
            $object=$body['payload']['object'];
            $id=$object['id']??null;
            if ((!is_int($id)&&!is_string($id))||(string)$id===''||strlen((string)$id)>255) { throw new InvalidArgumentException('Zoom: ID de reunión inválido.'); }
            $data=$this->pick($object,['id','uuid','host_id','topic','type','start_time','end_time','duration','timezone','total_size','recording_count']);
            if ($def['event']==='recording.completed') {
                if (!is_array($object['recording_files']??null)||!array_is_list($object['recording_files'])||count($object['recording_files'])>1000) { throw new InvalidArgumentException('Zoom: metadatos de grabación inválidos.'); }
                $data['recording_files']=[];
                foreach ($object['recording_files'] as $file) {
                    if (!is_array($file)||!is_string($file['id']??null)) { throw new InvalidArgumentException('Zoom: archivo de grabación inválido.'); }
                    $data['recording_files'][]=$this->pick($file,['id','recording_start','recording_end','file_type','file_extension','file_size','status','recording_type']);
                }
            }
            $data['account_id']=$account;
            $data['event_ts']=$body['event_ts'];
            $data=$this->safe($data,[$secret]);
            return $this->ack([['id'=>hash('sha256',$raw),'type'=>$def['event'],'data'=>$data]]);
        }
        if (($request['setup_allowed']??false)===true) { throw new InvalidArgumentException('Webflow: receptor no activado.'); }
        $timestamp=$headers['x-webflow-timestamp']??null;
        $signature=$headers['x-webflow-signature']??null;
        if (!is_string($timestamp)||!preg_match('/^[0-9]{13}$/D',$timestamp)||abs($now*1000-(int)$timestamp)>300000
            ||!is_string($signature)||!hash_equals(hash_hmac('sha256',$timestamp.':'.$raw,$secret),$signature)) { throw new InvalidArgumentException('Webflow: firma inválida o caducada.'); }
        $body=$this->json($raw);
        if (($body['triggerType']??null)!==$def['event']) { return $this->ack([]); }
        $payload=$body['payload']??null;
        if (!is_array($payload)||($payload['siteId']??null)!==$this->hexId($config['site_id']??null)) { throw new InvalidArgumentException('Webflow: sitio incompatible.'); }
        $this->hexId($payload['id']??null);
        if ($def['event']!=='form_submission'&&($payload['collectionId']??null)!==$this->hexId($config['collection_id']??null)) { throw new InvalidArgumentException('Webflow: colección incompatible.'); }
        $data=$this->pick($payload,['id','siteId','collectionId','formId','formElementId','localeId','cmsLocaleId','submittedAt','createdOn','lastUpdated','lastPublished','isDraft','isArchived','name']);
        foreach ($def['event']==='form_submission'?['data','schema']:['fieldData'] as $field) {
            if (array_key_exists($field,$payload)) {
                if (!is_array($payload[$field])) { throw new InvalidArgumentException('Webflow: campos de evento inválidos.'); }
                $data[$field]=$payload[$field];
            }
        }
        return $this->ack([['id'=>hash('sha256',$raw),'type'=>$def['event'],'data'=>$this->safe($data,[$secret])]]);
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        if ($key!=='dropbox_changes') { throw new \LogicException('Content: este evento utiliza webhook.'); }
        $path=$config['path']??'';
        if (!is_string($path)||strlen($path)>2048||($path!==''&&!str_starts_with($path,'/'))||preg_match('/[\x00-\x1f\x7f\\\\]/',$path)||preg_match('#(?:^|/)\.{1,2}(?:/|$)#',$path)) { throw new InvalidArgumentException('Dropbox: ruta de polling inválida.'); }
        $token=$this->text($secrets['access_token']??null,'access_token',16384);
        if (preg_match('/\s/',$token)) { throw new InvalidArgumentException('Dropbox: access_token inválido.'); }
        $binding=hash('sha256',$path.'|'.hash('sha256',$token));
        if (isset($state['binding'])&&!hash_equals($state['binding'],$binding)) { throw new InvalidArgumentException('Dropbox: credencial/ruta cambió; desactiva y reactiva para nueva baseline.'); }
        $state['binding']=$binding;
        if (!isset($state['cursor'])) {
            $body=$this->request('POST','https://api.dropboxapi.com/2/files/list_folder/get_latest_cursor',['Authorization'=>'Bearer '.$token],['json'=>['path'=>$path,'recursive'=>true,'include_deleted'=>true,'limit'=>100]]);
            $state['cursor']=$this->text($body['cursor']??null,'cursor',16384);
            return ['events'=>[],'state'=>$state,'has_more'=>false];
        }
        $events=[];
        if (!isset($state['pending'])) {
            $previous=$this->text($state['cursor'],'cursor',16384);
            $body=$this->request('POST','https://api.dropboxapi.com/2/files/list_folder/continue',['Authorization'=>'Bearer '.$token],['json'=>['cursor'=>$previous]]);
            if (!is_array($body['entries']??null)||!array_is_list($body['entries'])||count($body['entries'])>2000||!is_bool($body['has_more']??null)) { throw new RuntimeException('Dropbox: página de cambios inválida; cursor conservado.'); }
            $next=$this->text($body['cursor']??null,'cursor',16384);
            $pending=[];
            foreach ($body['entries'] as $i=>$row) {
                if (!is_array($row)||!in_array($row['.tag']??null,['file','folder','deleted'],true)||!is_string($row['path_lower']??null)||($row['.tag']!=='deleted'&&!is_string($row['id']??null))) { throw new RuntimeException('Dropbox: cambio inválido; cursor conservado.'); }
                $data=$this->pick($row,['.tag','id','name','path_lower','path_display','rev','size','server_modified','client_modified','content_hash']);
                $pending[]=['id'=>hash('sha256',$previous.'|'.$next.'|'.$i.'|'.json_encode($data,JSON_THROW_ON_ERROR)),'type'=>'file.changed','data'=>$this->safe($data,[$token])];
            }
            $state['cursor']=$next; $state['pending']=$pending; $state['remote_more']=$body['has_more'];
        }
        if (!is_array($state['pending'])||!array_is_list($state['pending'])) { throw new RuntimeException('Dropbox: estado de cursor inválido.'); }
        $events=array_splice($state['pending'],0,100);
        $more=$state['pending']!==[]||($state['remote_more']??false);
        if ($state['pending']===[]) { unset($state['pending'],$state['remote_more']); }
        return ['events'=>$events,'state'=>$state,'has_more'=>$more];
    }

    private function jotform(array $config,array $secrets,array $request): array
    {
        $fields=$request['form_fields']??[];
        if (!is_array($fields)) { throw new InvalidArgumentException('Jotform: formulario inválido.'); }
        if ($fields===[]) {
            $contentType=$request['headers']['content-type']??'';
            if (str_starts_with($contentType,'application/json')) { $fields=$this->json($request['raw_body']); }
            elseif (str_starts_with($contentType,'application/x-www-form-urlencoded')) { parse_str($request['raw_body'],$fields); }
            elseif (preg_match('/^multipart\/form-data;\s*boundary=(?:"([^"]{1,70})"|([^;\s]{1,70}))/i',$contentType,$m)) {
                $boundary=$m[1]!==''?$m[1]:$m[2];
                foreach (explode('--'.$boundary,$request['raw_body']) as $part) {
                    if (preg_match('/Content-Disposition:\s*form-data;\s*name="(submissionID|formID)"\r\n\r\n([0-9]{1,30})\r\n/i',$part,$match)) {
                        if (isset($fields[$match[1]])) { throw new InvalidArgumentException('Jotform: IDs duplicados.'); }
                        $fields[$match[1]]=$match[2];
                    }
                }
            } else { throw new InvalidArgumentException('Jotform: formato no admitido.'); }
        }
        $id=$this->numberId($fields['submissionID']??null);
        $form=$this->numberId($config['form_id']??null);
        if ($this->numberId($fields['formID']??null)!==$form) { throw new InvalidArgumentException('Jotform: formulario no coincide.'); }
        $origin=match($secrets['region']??null) {'standard'=>'https://api.jotform.com','eu'=>'https://eu-api.jotform.com','hipaa'=>'https://hipaa-api.jotform.com',default=>throw new InvalidArgumentException('Jotform: región inválida.')};
        $apiKey=$this->text($secrets['api_key']??null,'api_key',4096);
        $body=$this->request('GET',$origin.'/submission/'.$id,['APIKEY'=>$apiKey]);
        $content=$body['content']??null;
        if (($body['responseCode']??null)!==200||!is_array($content)||(string)($content['id']??'')!==$id||(string)($content['form_id']??'')!==$form||!is_array($content['answers']??null)) { throw new InvalidArgumentException('Jotform: envío no confirmado por API.'); }
        $data=$this->pick($content,['id','form_id','created_at','updated_at','status']);
        $data['answers']=$content['answers'];
        return $this->ack([['id'=>$id,'type'=>'submission.created','data'=>$this->safe($data,[$apiKey])]]);
    }

    private function request(string $method,string $url,array $headers,array $options=[]): array
    {
        $headers['Accept']='application/json';
        if (isset($options['json'])) { $headers['Content-Type']='application/json'; $options['body']=json_encode($options['json'],JSON_THROW_ON_ERROR); unset($options['json']); }
        try {
            $response=($this->client??new Client())->send(new Request($method,$url,$headers),$options+['headers'=>null,'auth'=>null,'cookies'=>false,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false,'timeout'=>20,'connect_timeout'=>5,'read_timeout'=>20,'stream'=>true]);
            $status=$response->getStatusCode();
            if ($status===429||$status===408||$status>=500) {
                $retry=$response->getHeaderLine('Retry-After'); $response->getBody()->close();
                throw new TriggerRetryException(ctype_digit($retry)?min(86400,max(1,(int)$retry)):60);
            }
            if ($status<200||$status>=300) { $response->getBody()->close(); throw new RuntimeException('Content: lectura rechazada por proveedor (HTTP '.$status.'); revisa credencial, permisos o cursor.'); }
            $raw=''; $stream=$response->getBody();
            try {
                while (!$stream->eof()&&strlen($raw)<=8388608) { $part=$stream->read(min(65536,8388609-strlen($raw))); if ($part===''&&!$stream->eof()) { throw new RuntimeException('Content: lectura incompleta.'); } $raw.=$part; }
                if (strlen($raw)>8388608) { throw new RuntimeException('Content: página demasiado grande.'); }
            } finally { $stream->close(); }
        } catch (TriggerRetryException $e) { throw $e; }
        catch (RuntimeException $e) {
            // Never leak Guzzle URLs/headers or transport exception messages.
            if ($e instanceof \GuzzleHttp\Exception\GuzzleException) { throw new TriggerRetryException(60); }
            throw $e;
        } catch (\Throwable $e) { throw new TriggerRetryException(60); }
        $body=$this->json($raw);
        if (isset($body['error'])||!empty($body['errors'])) { throw new RuntimeException('Content: error funcional de lectura.'); }
        return $body;
    }

    private function json(string $raw): array
    {
        try { $shape=json_decode($raw,false,64,JSON_THROW_ON_ERROR); $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new InvalidArgumentException('Content: JSON inválido.'); }
        if (!$shape instanceof \stdClass||!is_array($body)) { throw new InvalidArgumentException('Content: objeto JSON requerido.'); }
        return $body;
    }

    private function pick(array $data,array $keys): array
    {
        $out=[];
        foreach ($keys as $key) {
            if (array_key_exists($key,$data)) {
                $v=$data[$key];
                if ((!is_scalar($v)&&$v!==null)||(is_string($v)&&(strlen($v)>4096||preg_match('/[\x00-\x1f\x7f]/',$v)))) { throw new InvalidArgumentException('Content: metadato remoto inválido.'); }
                $out[$key]=$v;
            }
        }
        return $out;
    }

    private function safe(mixed $v,array $secrets): mixed
    {
        if (is_array($v)) {
            $out=[];
            foreach ($v as $key=>$part) {
                if (is_string($key)&&preg_match('/(?:access_token|refresh_token|start_url|download_url|play_url|password|secret|file_path)/i',$key)) { continue; }
                $out[$key]=$this->safe($part,$secrets);
            }
            return $out;
        }
        if (is_string($v)) { foreach ($secrets as $s) { if (strlen($s)>=4&&(str_contains($v,$s)||str_contains($v,rawurlencode($s)))) { return '[redacted]'; } } }
        return $v;
    }

    private function text(mixed $v,string $field,int $max): string
    {
        if ((!is_string($v)&&!is_int($v))||trim((string)$v)===''||strlen((string)$v)>$max||preg_match('/[\x00-\x1f\x7f]/',(string)$v)) { throw new InvalidArgumentException('Content: '.$field.' inválido.'); }
        return (string)$v;
    }

    private function numberId(mixed $v): string
    {
        $v=$this->text($v,'ID',30);
        if (!ctype_digit($v)) { throw new InvalidArgumentException('Jotform: ID numérico requerido.'); }
        return $v;
    }

    private function hexId(mixed $v): string
    {
        $v=$this->text($v,'ID',24);
        if (!preg_match('/^[a-f0-9]{24}$/D',$v)) { throw new InvalidArgumentException('Webflow: ID inválido.'); }
        return $v;
    }

    private function ack(array $events): array
    {
        return ['status'=>200,'content_type'=>'text/plain','body'=>'','events'=>$events];
    }
}
