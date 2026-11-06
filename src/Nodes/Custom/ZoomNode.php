<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;


class ZoomNode implements NodeInterface
{
    private const NAME='Zoom';
    private const OPS=['list_meetings','get_meeting','create_meeting','update_meeting','list_registrants','add_registrant','update_registrant_status','report_participants','list_recordings','get_recordings'];
    public function __construct(private ?ClientInterface $client=null,private ?CredentialManager $credentials=null) {}

    public function getSchema(): array
    {
        return ['type'=>'zoom','name'=>'Zoom','category'=>'Community','icon'=>'Video','is_start'=>false,
            'description'=>'Reuniones, registrantes, informes de asistentes y metadatos de grabaciones Zoom.',
            'help_text'=>'generic_bearer JSON {"access_token":"TOKEN","auth_mode":"user"} o auth_mode=account (Server-to-Server OAuth externo). Tokens caducan: renovación manual, sin nuevas pantallas OAuth. meeting:read/meeting:write, recording:read, report:read:admin o granular equivalente según operación; account requiere variantes :admin y usuario explícito. Registrantes requieren anfitrión Licensed, reunión programada tipo 2 y registration habilitado; añadir puede enviar email de Zoom. report_participants son asistentes históricos, no registrantes, requiere Pro+ y permisos de informes; grabaciones requieren cloud recording y plan elegible. Una página máximo 100; next_page_token oficial. Fechas ISO estrictas, zonas IANA. Crear/actualizar solo programadas tipo 2, sin recurrencia. body contiene campos permitidos: topic,agenda,start_time,duration,timezone,settings; PATCH solo proporcionados (agenda="" vacía). Nunca devuelve start_url, contraseñas, tokens ni URLs de reproducción/descarga de grabaciones. No descarga grabaciones. Triggers meeting.created/updated/started/ended y recording.completed con firma y CRC/revalidación 72h automáticas al recibir; registrar manualmente suscripción remota.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial Zoom JSON','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_meetings','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],self::OPS)],
                'user_id'=>['type'=>'string','label'=>'Usuario anfitrión (me solo OAuth user)','default'=>'me'],
                'meeting_id'=>['type'=>'string','label'=>'ID numérico o UUID de reunión'],
                'body'=>['type'=>'textarea','label'=>'Campos JSON de reunión/registrante/status'],
                'page_size'=>['type'=>'number','label'=>'Resultados 1–100','default'=>30],
                'next_page_token'=>['type'=>'string','label'=>'Cursor oficial de continuación'],
                'from'=>['type'=>'string','label'=>'Grabaciones: desde YYYY-MM-DD'],
                'to'=>['type'=>'string','label'=>'Grabaciones: hasta YYYY-MM-DD (máximo 30 días)'],
                'registrant_status'=>['type'=>'select','label'=>'Estado al listar registrantes','default'=>'approved','options'=>[['value'=>'approved','label'=>'Aprobado'],['value'=>'pending','label'=>'Pendiente'],['value'=>'denied','label'=>'Rechazado']]]
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
        if (preg_match('/\s/',$token)||!in_array($c['auth_mode']??null,['user','account'],true)||($c['auth_mode']==='account'&&($p['user_id']??null)==='me')) { throw new InvalidArgumentException('Zoom: token/auth_mode inválido; account requiere user_id explícito.'); }
        $op=$p['operation']; $method='GET'; $options=[];
        if ($op==='update_meeting') {
            [, $existing]=$this->http('GET','https://api.zoom.us/v2/meetings/'.rawurlencode($p['meeting_id']),['Authorization'=>'Bearer '.$token]);
            if (($existing['type']??null)!==2) { throw new InvalidArgumentException('Zoom: actualizar admite solamente reunión programada tipo 2.'); }
        }
        $user=rawurlencode($p['user_id']??'me'); $meeting=$p['meeting_id']??'';
        // UUIDs que comienzan / o contienen // necesitan doble codificación oficial.
        $mid=rawurlencode($meeting); if (str_starts_with($meeting,'/')||str_contains($meeting,'//')) { $mid=rawurlencode($mid); }
        $path=match($op) {
            'list_meetings','create_meeting'=>'/users/'.$user.'/meetings',
            'get_meeting','update_meeting'=>'/meetings/'.$mid,
            'list_registrants','add_registrant'=>'/meetings/'.$mid.'/registrants',
            'update_registrant_status'=>'/meetings/'.$mid.'/registrants/status',
            'report_participants'=>'/report/meetings/'.$mid.'/participants',
            'list_recordings'=>'/users/'.$user.'/recordings',
            'get_recordings'=>'/meetings/'.$mid.'/recordings'
        };
        if (in_array($op,['list_meetings','list_registrants','report_participants','list_recordings'],true)) {
            $options['query']=['page_size'=>$p['page_size']]; if (isset($p['next_page_token'])) { $options['query']['next_page_token']=$p['next_page_token']; }
            if ($op==='list_registrants') { $options['query']['status']=$p['registrant_status']; }
            if ($op==='list_recordings') { $options['query']+=['from'=>$p['from'],'to'=>$p['to']]; }
        }
        if (in_array($op,['create_meeting','add_registrant'],true)) { $method='POST'; $options['json']=$p['body']; }
        if ($op==='update_meeting') { $method='PATCH'; $options['json']=$p['body']; }
        if ($op==='update_registrant_status') { $method='PUT'; $options['json']=$p['body']; }
        [$status,$body]=$this->http($method,'https://api.zoom.us/v2'.$path,['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],$options);
        if (isset($body['code'])&&$body['code']!==0) { throw new RuntimeException('Zoom: error funcional del servicio.'); }
        if (in_array($op,['list_meetings','list_registrants','report_participants','list_recordings'],true)) {
            $key=match($op) {'list_meetings','list_recordings'=>'meetings','list_registrants'=>'registrants','report_participants'=>'participants'};
            if (!is_array($body[$key]??null)||!array_is_list($body[$key])||count($body[$key])>$p['page_size']) { throw new RuntimeException('Zoom: página incompleta o inválida.'); }
            $next=$body['next_page_token']??''; if (!is_string($next)||strlen($next)>8192) { throw new RuntimeException('Zoom: cursor inválido.'); }
            $body['pagination']=['next_page_token'=>$next,'has_more'=>$next!=='','processed_pages'=>1];
        } elseif (in_array($op,['get_meeting','create_meeting','get_recordings'],true)&&!isset($body['id'])) { throw new RuntimeException('Zoom: respuesta sin reunión válida; comprueba el recurso antes de repetir.'); }
        elseif ($op==='add_registrant'&&!is_string($body['registrant_id']??null)) { throw new RuntimeException('Zoom: respuesta sin registrante válido; comprueba el recurso antes de repetir.'); }
        return $this->result($status,$body===[]?['accepted'=>true]:$body,[$token]);
    }

    private function prepare(array $p): array
    {
        $op=$p['operation']??'list_meetings'; if (!is_string($op)||!in_array($op,self::OPS,true)) { throw new InvalidArgumentException('Zoom: operación inválida.'); }
        $r=['credential_id'=>$this->text($p['credential_id']??null,'credential_id',255),'operation'=>$op];
        if (in_array($op,['list_meetings','create_meeting','list_recordings'],true)) { $r['user_id']=$this->text($p['user_id']??'me','user_id',255); if (!preg_match('/^[A-Za-z0-9_.@+-]+$/D',$r['user_id'])) { throw new InvalidArgumentException('Zoom: user_id inválido.'); } }
        else { $r['meeting_id']=$this->text($p['meeting_id']??null,'meeting_id',256); if (!preg_match('/^[A-Za-z0-9_+\/=\-]+$/D',$r['meeting_id'])) { throw new InvalidArgumentException('Zoom: meeting_id inválido.'); } }
        if (in_array($op,['list_meetings','list_registrants','report_participants','list_recordings'],true)) {
            $r['page_size']=$this->integer($p['page_size']??30,'page_size',1,100);
            if (isset($p['next_page_token'])&&$p['next_page_token']!=='') { $r['next_page_token']=$this->text($p['next_page_token'],'next_page_token',8192); }
        }
        if ($op==='list_registrants') { $r['registrant_status']=$p['registrant_status']??'approved'; if (!in_array($r['registrant_status'],['approved','pending','denied'],true)) { throw new InvalidArgumentException('Zoom: estado de registrante inválido.'); } }
        if ($op==='list_recordings') {
            foreach (['from','to'] as $key) { $r[$key]=$this->text($p[$key]??null,$key,10); $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$r[$key]); if (!$dt||$dt->format('Y-m-d')!==$r[$key]) { throw new InvalidArgumentException('Zoom: fecha YYYY-MM-DD inválida.'); } }
            $distance=strtotime($r['to'])-strtotime($r['from']); if ($distance<0||$distance>30*86400) { throw new InvalidArgumentException('Zoom: intervalo de grabaciones máximo 30 días.'); }
        }
        if (in_array($op,['create_meeting','update_meeting','add_registrant','update_registrant_status'],true)) {
            $b=$this->object($p['body']??null,'body');
            if ($b===[]) { throw new InvalidArgumentException('Zoom: body debe contener al menos un campo.'); }
            if (in_array($op,['create_meeting','update_meeting'],true)) {
                $this->allowed($b,['topic','agenda','start_time','duration','timezone','settings'],'body');
                foreach (['topic','agenda'] as $field) { if (array_key_exists($field,$b)) { $b[$field]=$this->text($b[$field],$field,$field==='topic'?200:2000,$field==='agenda'); } }
                if ($op==='create_meeting'&&!isset($b['topic'])) { throw new InvalidArgumentException('Zoom: topic es obligatorio al crear.'); }
                if (isset($b['duration'])) { $b['duration']=$this->integer($b['duration'],'duration',1,1440); }
                if (array_key_exists('timezone',$b)) { if (!is_string($b['timezone'])||!in_array($b['timezone'],\DateTimeZone::listIdentifiers(),true)) { throw new InvalidArgumentException('Zoom: zona IANA inválida.'); } }
                if (array_key_exists('start_time',$b)) {
                    $at=$b['start_time']; if (!is_string($at)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D',$at)||strtotime($at)===false) { throw new InvalidArgumentException('Zoom: start_time ISO inválida.'); }
                    try { $dt=new \DateTimeImmutable($at); } catch (\Throwable $e) { throw new InvalidArgumentException('Zoom: fecha inválida.'); } if (\DateTimeImmutable::getLastErrors()!==false) { throw new InvalidArgumentException('Zoom: fecha inexistente.'); }
                    if (!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D',$at)&&!isset($b['timezone'])) { throw new InvalidArgumentException('Zoom: fecha local requiere timezone IANA explícita.'); }
                } elseif ($op==='create_meeting') { throw new InvalidArgumentException('Zoom: start_time requerido.'); }
                if (array_key_exists('settings',$b)) {
                    $b['settings']=$this->object($b['settings'],'settings'); $this->allowed($b['settings'],['approval_type','registration_type','join_before_host','waiting_room','mute_upon_entry','auto_recording'],'settings');
                    foreach ($b['settings'] as $key=>$v) { if (in_array($key,['join_before_host','waiting_room','mute_upon_entry'],true)&&!is_bool($v)) { throw new InvalidArgumentException('Zoom: setting booleano requerido.'); } }
                    if (isset($b['settings']['approval_type'])) { $b['settings']['approval_type']=$this->integer($b['settings']['approval_type'],'approval_type',0,2); }
                    if (isset($b['settings']['registration_type'])) { $b['settings']['registration_type']=$this->integer($b['settings']['registration_type'],'registration_type',1,3); }
                    if (isset($b['settings']['auto_recording'])&&!in_array($b['settings']['auto_recording'],['local','cloud','none'],true)) { throw new InvalidArgumentException('Zoom: auto_recording inválido.'); }
                    $b['settings']=(object)$b['settings'];
                }
                if ($op==='create_meeting') { $b['type']=2; }
            } elseif ($op==='add_registrant') {
                $this->allowed($b,['email','first_name','last_name'],'body');
                if (!is_string($b['email']??null)||!filter_var($b['email'],FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Zoom: email válido requerido.'); }
                $b['first_name']=$this->text($b['first_name']??null,'first_name',64);
                if (array_key_exists('last_name',$b)) { $b['last_name']=$this->text($b['last_name'],'last_name',64,true); }
            } else {
                $this->allowed($b,['action','registrants'],'body');
                if (!in_array($b['action']??null,['approve','cancel','deny'],true)||!is_array($b['registrants']??null)||!array_is_list($b['registrants'])||count($b['registrants'])<1||count($b['registrants'])>30) { throw new InvalidArgumentException('Zoom: action y 1–30 registrants requeridos.'); }
                foreach ($b['registrants'] as &$row) { $row=$this->object($row,'registrants'); $this->allowed($row,['id','email'],'registrants'); $row['id']=$this->id($row['id']??null,'registrant_id'); if (isset($row['email'])&&!filter_var($row['email'],FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Zoom: email registrante inválido.'); } } unset($row);
            }
            $r['body']=$b;
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
