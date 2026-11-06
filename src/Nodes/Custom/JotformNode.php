<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;


class JotformNode implements NodeInterface
{
    private const NAME='Jotform';
    private const OPS=['list_forms','get_form','get_questions','list_submissions','get_submission'];
    public function __construct(private ?ClientInterface $client=null,private ?CredentialManager $credentials=null) {}

    public function getSchema(): array
    {
        return ['type'=>'jotform','name'=>'Jotform','category'=>'Community','icon'=>'ClipboardList','is_start'=>false,
            'description'=>'Consulta formularios, preguntas y envíos conservando IDs y respuestas originales.',
            'help_text'=>'Credencial generic_bearer JSON {"api_key":"KEY","region":"standard"}; region standard/eu/hipaa elige origen oficial. API key de solo lectura; cuota depende del plan. Una página 1–100 resultados, offset máximo 100000. filter objeto JSON: claves documentadas id,form_id,status,created_at,updated_at y operadores :gt/:lt/:ne; order_by ID/created_at/updated_at. Se conservan preguntas y answers sin renombrar. Nunca descarga adjuntos ni sigue URLs. Trigger Jotform sin firma oficial: verifica submissionID mediante API autenticada y form_id, descarta payload no verificado; no autentica identidad del emisor. URL pública difícil de adivinar y no divulgar. Sin renovación o registro remoto automático.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial Jotform JSON','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','required'=>true,'default'=>'list_forms','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],self::OPS)],
                'form_id'=>['type'=>'string','label'=>'ID numérico del formulario','required'=>false],
                'submission_id'=>['type'=>'string','label'=>'ID numérico del envío','required'=>false],
                'limit'=>['type'=>'number','label'=>'Resultados 1–100','default'=>100],
                'offset'=>['type'=>'number','label'=>'Offset 0–100000','default'=>0],
                'filter'=>['type'=>'textarea','label'=>'Filtro JSON documentado (opcional)'],
                'order_by'=>['type'=>'select','label'=>'Orden','default'=>'id','options'=>[['value'=>'id','label'=>'ID'],['value'=>'created_at','label'=>'Creación'],['value'=>'updated_at','label'=>'Modificación']]]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters,array $context): array
    {
        // ExpressionEvaluator ya se ejecutó en Engine; no reinterpretar datos de respuestas.
        $p=$this->prepare($parameters); $c=$this->credential($p['credential_id']);
        $apiKey=$this->text($c['api_key']??null,'api_key',4096);
        if (preg_match('/\s/',$apiKey)) { throw new InvalidArgumentException('Jotform: API key inválida.'); }
        $origin=match($c['region']??'standard') {'standard'=>'https://api.jotform.com','eu'=>'https://eu-api.jotform.com','hipaa'=>'https://hipaa-api.jotform.com',default=>throw new InvalidArgumentException('Jotform: región no admitida.')};
        $op=$p['operation'];
        $path=match($op) {'list_forms'=>'/user/forms','get_form'=>'/form/'.$p['form_id'],'get_questions'=>'/form/'.$p['form_id'].'/questions','list_submissions'=>'/form/'.$p['form_id'].'/submissions','get_submission'=>'/submission/'.$p['submission_id']};
        $options=[];
        if (in_array($op,['list_forms','list_submissions'],true)) {
            $options['query']=['limit'=>$p['limit'],'offset'=>$p['offset'],'orderby'=>$p['order_by']];
            if ($p['filter']!==[]) { $options['query']['filter']=json_encode((object)$p['filter'],JSON_THROW_ON_ERROR); }
        }
        [$status,$body]=$this->http('GET',$origin.$path,['APIKEY'=>$apiKey,'Accept'=>'application/json'],$options);
        if (($body['responseCode']??null)!==200||!array_key_exists('content',$body)||!is_array($body['content'])) { throw new RuntimeException('Jotform: error funcional o contenido incompleto.'); }
        $data=['content'=>$body['content']];
        if (in_array($op,['list_forms','list_submissions'],true)) {
            if (!array_is_list($body['content'])||count($body['content'])>$p['limit']) { throw new RuntimeException('Jotform: página remota inválida.'); }
            $next=count($body['content'])===$p['limit']?$p['offset']+count($body['content']):null;
            $data['pagination']=['offset'=>$p['offset'],'limit'=>$p['limit'],'next_offset'=>$next!==null&&$next<=100000?$next:null,'has_more'=>$next!==null,'limit_reached'=>$next!==null&&$next>100000,'processed_pages'=>1];
        } elseif ($op==='get_submission' && ((string)($body['content']['id']??'')!==$p['submission_id']||!is_array($body['content']['answers']??null))) {
            throw new RuntimeException('Jotform: respuesta sin envío/answers válido.');
        } elseif ($op==='get_form'&&(string)($body['content']['id']??'')!==$p['form_id']) { throw new RuntimeException('Jotform: formulario remoto incompatible.'); }
        if (isset($body['limit-left'])&&(is_int($body['limit-left'])||is_string($body['limit-left']))) { $data['quota_remaining']=$body['limit-left']; }
        return $this->result($status,$data,[$apiKey]);
    }

    private function prepare(array $p): array
    {
        $op=$p['operation']??'list_forms';
        if (!is_string($op)||!in_array($op,self::OPS,true)) { throw new InvalidArgumentException('Jotform: operación inválida.'); }
        $r=['credential_id'=>$this->text($p['credential_id']??null,'credential_id',255),'operation'=>$op];
        foreach (['form_id'=>['get_form','get_questions','list_submissions'],'submission_id'=>['get_submission']] as $field=>$ops) {
            if (in_array($op,$ops,true)) { $r[$field]=$this->id($p[$field]??null,$field); if (!ctype_digit($r[$field])) { throw new InvalidArgumentException('Jotform: ID numérico requerido.'); } }
        }
        if (in_array($op,['list_forms','list_submissions'],true)) {
            $r['limit']=$this->integer($p['limit']??100,'limit',1,100); $r['offset']=$this->integer($p['offset']??0,'offset',0,100000);
            $r['order_by']=$p['order_by']??'id'; if (!in_array($r['order_by'],['id','created_at','updated_at'],true)) { throw new InvalidArgumentException('Jotform: orden no admitido.'); }
            $r['filter']=!isset($p['filter'])||$p['filter']===''?[]:$this->object($p['filter'],'filter');
            if (count($r['filter'])>20) { throw new InvalidArgumentException('Jotform: demasiados filtros.'); }
            foreach ($r['filter'] as $key=>$value) {
                if (!is_string($key)||!preg_match('/^(?:id|form_id|status|created_at|updated_at)(?::(?:gt|lt|ne))?$/D',$key)||(!is_string($value)&&!is_int($value))||strlen((string)$value)>255||preg_match('/[\x00-\x1f\x7f]/',(string)$value)) { throw new InvalidArgumentException('Jotform: filtro no admitido.'); }
            }
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
