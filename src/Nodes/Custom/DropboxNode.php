<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;


class DropboxNode implements NodeInterface
{
    private const NAME = 'Dropbox';
    private const OPS = ['list_folder','list_folder_continue','upload_file','download_file','create_folder','move','list_shared_links','create_shared_link'];
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null) {}

    public function getSchema(): array
    {
        return ['type'=>'dropbox','name'=>'Dropbox','category'=>'Community','icon'=>'Folder','is_start'=>false,
            'description'=>'Archivos y carpetas mediante Dropbox API v2, sin rutas locales ni enlaces públicos implícitos.',
            'help_text'=>'generic_bearer API Key JSON {"access_token":"TOKEN"}. OAuth vigente, actualización manual; scopes files.metadata.read, files.content.read, files.content.write, sharing.read/sharing.write según operación y acceso App Folder/Full Dropbox. Una página por ejecución, 1–100 entradas, cursor opaco. Upload/download: Base64 canónico, máximo local 10 MiB, nunca archivos del servidor. La subida simple queda por debajo de 150 MB oficiales: no requiere sesiones. Conflicto predeterminado fail; overwrite requiere selección explícita. Crear enlace exige visibility=public/team_only/password explícito; password no soportado aquí. No crea enlaces ni modifica archivos al listar. Triggers: polling oficial con cursor, baseline sin histórico. Sin reintentos ni redirecciones.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial Dropbox JSON','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_folder','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],self::OPS)],
                'path'=>['type'=>'string','label'=>'Ruta Dropbox (/carpeta/archivo); vacío = raíz al listar','required'=>false],
                'to_path'=>['type'=>'string','label'=>'Ruta destino (mover)','required'=>false],
                'cursor'=>['type'=>'textarea','label'=>'Cursor oficial (continuar)','required'=>false],
                'limit'=>['type'=>'number','label'=>'Entradas por página 1–100','default'=>100,'required'=>false],
                'recursive'=>['type'=>'select','label'=>'Recursivo','default'=>'false','options'=>[['value'=>'false','label'=>'No'],['value'=>'true','label'=>'Sí']]],
                'content_base64'=>['type'=>'textarea','label'=>'Archivo Base64 canónico, máximo 10 MiB','required'=>false],
                'conflict_policy'=>['type'=>'select','label'=>'Conflictos','default'=>'fail','options'=>[['value'=>'fail','label'=>'Fallar'],['value'=>'rename','label'=>'Renombrar'],['value'=>'overwrite','label'=>'Sobrescribir explícitamente (solo subir)']]],
                'visibility'=>['type'=>'select','label'=>'Visibilidad explícita al crear enlace','options'=>[['value'=>'','label'=>'Seleccionar'],['value'=>'public','label'=>'Público'],['value'=>'team_only','label'=>'Solo equipo']]]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine resuelve ExpressionEvaluator una sola vez antes de execute; validar el tipo resultante.
        $p=$this->prepare($parameters); $c=$this->credential($p['credential_id']);
        $token=$this->text($c['access_token']??null,'access_token',16384);
        if (preg_match('/\s/', $token)) { throw new InvalidArgumentException('Dropbox: access_token inválido.'); }
        $h=['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'];
        $op=$p['operation']; $arg=[]; $endpoint=''; $host='https://api.dropboxapi.com/2/'; $options=[];
        switch ($op) {
            case 'list_folder':
                $endpoint='files/list_folder'; $arg=['path'=>$p['path'],'limit'=>$p['limit'],'recursive'=>$p['recursive'],'include_deleted'=>false]; break;
            case 'list_folder_continue': $endpoint='files/list_folder/continue'; $arg=['cursor'=>$p['cursor']]; break;
            case 'create_folder': $endpoint='files/create_folder_v2'; $arg=['path'=>$p['path'],'autorename'=>$p['conflict_policy']==='rename']; break;
            case 'move': $endpoint='files/move_v2'; $arg=['from_path'=>$p['path'],'to_path'=>$p['to_path'],'autorename'=>$p['conflict_policy']==='rename','allow_shared_folder'=>false,'allow_ownership_transfer'=>false]; break;
            case 'list_shared_links': $endpoint='sharing/list_shared_links'; $arg=['path'=>$p['path'],'direct_only'=>true]; if (isset($p['cursor'])) { $arg['cursor']=$p['cursor']; } break;
            case 'create_shared_link': $endpoint='sharing/create_shared_link_with_settings'; $arg=['path'=>$p['path'],'settings'=>['requested_visibility'=>$p['visibility']]]; break;
            case 'upload_file':
                $host='https://content.dropboxapi.com/2/'; $endpoint='files/upload';
                $arg=['path'=>$p['path'],'mode'=>$p['conflict_policy']==='overwrite'?'overwrite':'add','autorename'=>$p['conflict_policy']==='rename','strict_conflict'=>true,'mute'=>false];
                $h['Dropbox-API-Arg']=json_encode($arg,JSON_THROW_ON_ERROR); $h['Content-Type']='application/octet-stream'; $options=['body'=>$p['bytes']]; break;
            case 'download_file':
                $host='https://content.dropboxapi.com/2/'; $endpoint='files/download'; $h['Dropbox-API-Arg']=json_encode(['path'=>$p['path']],JSON_THROW_ON_ERROR);
                [$status,$bytes,$response]=$this->http('POST',$host.$endpoint,$h,[],true);
                $metadata=json_decode($response->getHeaderLine('Dropbox-API-Result'),true);
                if (!is_array($metadata) || !is_string($metadata['id']??null) || !is_int($metadata['size']??null) || strlen($bytes)!==$metadata['size']) { throw new RuntimeException('Dropbox: metadatos de descarga incompletos.'); }
                if (str_contains($bytes,$token)) { throw new RuntimeException('Dropbox: contenido descartado por contener credenciales.'); }
                return $this->result($status,$metadata+['content_base64'=>base64_encode($bytes)],[$token]);
        }
        if ($op!=='upload_file') { $options['json']=$arg; }
        [$status,$body]=$this->http('POST',$host.$endpoint,$h,$options);
        if (in_array($op,['list_folder','list_folder_continue'],true)) {
            if (!is_array($body['entries']??null) || !array_is_list($body['entries']) || count($body['entries'])>100 || !is_bool($body['has_more']??null) || !is_string($body['cursor']??null)) { throw new RuntimeException('Dropbox: página o cursor incompletos.'); }
            $body['pagination']=['cursor'=>$body['cursor'],'has_more'=>$body['has_more'],'processed_pages'=>1];
        } elseif ($op==='list_shared_links') {
            if (!is_array($body['links']??null) || !array_is_list($body['links']) || count($body['links'])>1000 || !is_bool($body['has_more']??null) || ($body['has_more']&&!is_string($body['cursor']??null))) { throw new RuntimeException('Dropbox: página de enlaces inválida o superior a 1000.'); }
            $body['pagination']=['cursor'=>$body['cursor']??null,'has_more'=>$body['has_more'],'processed_pages'=>1];
        } elseif ($op==='create_shared_link') {
            if (!is_string($body['url']??null) || !str_starts_with($body['url'],'https://www.dropbox.com/')) { throw new RuntimeException('Dropbox: enlace compartido remoto inválido.'); }
        } elseif (!is_string(($body['metadata']??$body)['id']??null)) { throw new RuntimeException('Dropbox: respuesta sin recurso; resultado de escritura incierto.'); }
        return $this->result($status,$body,[$token]);
    }

    private function prepare(array $p): array
    {
        $op=$p['operation']??'list_folder'; if (!is_string($op)||!in_array($op,self::OPS,true)) { throw new InvalidArgumentException('Dropbox: operación inválida.'); }
        $r=['operation'=>$op,'credential_id'=>$this->text($p['credential_id']??null,'credential_id',255)];
        if ($op==='list_folder_continue') { $r['cursor']=$this->text($p['cursor']??null,'cursor',8192); return $r; }
        $path=$this->text($p['path']??'','path',2048,$op==='list_folder');
        if (($path!==''&&!str_starts_with($path,'/'))||str_contains($path,'\\')||preg_match('#(?:^|/)\.{1,2}(?:/|$)#',$path)||($path!==''&&str_ends_with($path,'/'))) { throw new InvalidArgumentException('Dropbox: ruta inválida.'); }
        $r['path']=$path;
        if ($op==='list_folder') {
            $r['limit']=$this->integer($p['limit']??100,'limit',1,100);
            $v=$p['recursive']??false; if (!in_array($v,[true,false,'true','false'],true)) { throw new InvalidArgumentException('Dropbox: recursive inválido.'); }
            $r['recursive']=$v===true||$v==='true';
        }
        if (in_array($op,['upload_file','create_folder','move'],true)) {
            $policy=$p['conflict_policy']??'fail'; if (!in_array($policy,['fail','rename','overwrite'],true)||($op!=='upload_file'&&$policy==='overwrite')) { throw new InvalidArgumentException('Dropbox: conflicto inválido.'); } $r['conflict_policy']=$policy;
        }
        if ($op==='move') {
            $r['to_path']=$this->text($p['to_path']??null,'to_path',2048);
            if (!str_starts_with($r['to_path'],'/')||str_ends_with($r['to_path'],'/')||str_contains($r['to_path'],'\\')||preg_match('#(?:^|/)\.{1,2}(?:/|$)#',$r['to_path'])||$path===$r['to_path']) { throw new InvalidArgumentException('Dropbox: destino inválido.'); }
        }
        if ($op==='upload_file') {
            $b64=$p['content_base64']??null;
            if (!is_string($b64)||strlen($b64)>13981016||($bytes=base64_decode($b64,true))===false||base64_encode($bytes)!==$b64||strlen($bytes)>10485760) { throw new InvalidArgumentException('Dropbox: Base64 canónico máximo 10 MiB requerido.'); } $r['bytes']=$bytes;
        }
        if ($op==='create_shared_link') {
            if (!in_array($p['visibility']??null,['public','team_only'],true)) { throw new InvalidArgumentException('Dropbox: selecciona explícitamente visibilidad public o team_only.'); } $r['visibility']=$p['visibility'];
        }
        if ($op==='list_shared_links'&&isset($p['cursor'])&&$p['cursor']!=='') { $r['cursor']=$this->text($p['cursor'],'cursor',8192); }
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
