<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GitLabNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        $operations = ['list_projects','get_project','get_file','list_issues','get_issue','create_issue','update_issue','list_pipelines','get_pipeline','list_jobs','get_job','create_pipeline','trigger_pipeline'];
        $fields = ['credential_id'=>['type'=>'credential','required'=>true,'label'=>'Credencial GitLab'], 'operation'=>['type'=>'select','default'=>'list_projects','label'=>'Operación','options'=>array_map(fn($v)=>['value'=>$v,'label'=>$v], $operations)]];
        foreach (['project','issue_iid','pipeline_id','job_id','file_path','ref','search'] as $key) {
            $fields[$key] = ['type'=>'string','label'=>$key];
        }
        $fields['payload'] = ['type'=>'textarea','label'=>'Campos JSON (solo campos proporcionados)','placeholder'=>'{"title":"Incidencia de ejemplo","description":""}'];
        $fields['page'] = ['type'=>'number','default'=>1,'label'=>'Página (1–10000)'];
        $fields['per_page'] = ['type'=>'number','default'=>50,'label'=>'Resultados (1–100)'];
        return ['type'=>'gitlab','name'=>'GitLab','category'=>'Community','icon'=>'GitBranch','is_start'=>false,
            'description'=>'Proyectos, archivos, issues, pipelines y jobs de GitLab REST v4.',
            'help_text'=>'generic_bearer/API Key: {"base_url":"https://gitlab.com","access_token":"glpat_EJEMPLO"}; para trigger_pipeline usar {"base_url":"https://gitlab.example.org","trigger_token":"EJEMPLO"}. base_url admite prefijo de instalación. read_api para consultas; api para escrituras; read_repository también permite leer archivos. El token de trigger SOLO dispara pipelines y no es un token de acceso. project acepta ID o grupo/subgrupo/proyecto, sin pre-codificar. payload: campos permitidos por operación; omitir una clave conserva su valor, "" y [] vacían cuando la API lo permite. Archivos hasta 1 MiB, una página hasta 100 resultados; usar pagination.next_page. No reintentos de escrituras. Tokens con caducidad/SSO requieren reemplazo externo; sin renovación propia. Configura push/issues/pipelines por separado en Triggers (webhook con secreto y proyecto). Documentación: https://docs.gitlab.com/api/ .',
            'parameters'=>$fields];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (\Throwable $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare($parameters);
        try { $c = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($p['credential_id']); }
        catch (\Throwable $e) { throw new RuntimeException('GitLab: fallo del gestor o descifrado de credenciales.'); }
        if ($c === null) { throw new InvalidArgumentException('GitLab: credencial inexistente.'); }
        try { $s = json_decode($c['data']['api_key'] ?? '', true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new InvalidArgumentException('GitLab: API Key debe contener la configuración JSON.'); }
        if (($c['type'] ?? '') !== 'generic_bearer' || !is_array($s)) { throw new InvalidArgumentException('GitLab: tipo de credencial incompatible.'); }
        $base = $this->origin($s['base_url'] ?? 'https://gitlab.com');
        $trigger = $p['operation'] === 'trigger_pipeline';
        $token = $this->text($s[$trigger ? 'trigger_token' : 'access_token'] ?? null, 4096);
        if (preg_match('/[\x00-\x20\x7f]/', $token)) { throw new InvalidArgumentException('GitLab: token inválido.'); }
        $o = ['headers'=>['Accept'=>'application/json'],'query'=>$p['query'],'timeout'=>30,'connect_timeout'=>10,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false,'stream'=>true];
        if ($trigger) { $o['form_params'] = $p['payload'] + ['token'=>$token]; }
        else { $o['headers']['PRIVATE-TOKEN'] = $token; if ($p['method'] !== 'GET') { $o['json'] = $p['payload']; } }
        $uncertain = $p['method'] === 'GET' ? '' : ' Resultado de escritura incierto; comprueba GitLab antes de repetir.';
        try {
            $r = ($this->client ?? new Client())->request($p['method'], $base . '/api/v4' . $p['path'], $o);
            $raw = $r->getBody()->read(4194305); $r->getBody()->close();
        } catch (\Throwable $e) { throw new RuntimeException('GitLab: fallo de transporte.' . $uncertain); }
        $status = $r->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $reason = match($status) {401=>'autenticación rechazada',403=>'permisos insuficientes',404=>'recurso inexistente o inaccesible',429=>'cuota agotada',default=>'solicitud rechazada'};
            throw new RuntimeException('GitLab: ' . $reason . ' (HTTP ' . $status . ').' . $uncertain);
        }
        try { $b = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new RuntimeException('GitLab: respuesta JSON inválida.' . $uncertain); }
        if (strlen($raw) > 4194304 || !is_array($b) || isset($b['error']) || isset($b['message']) || ($p['list'] && (!array_is_list($b) || count($b) > $p['query']['per_page'])) || (!$p['list'] && !isset($b['id']) && $p['operation'] !== 'get_file')) {
            throw new RuntimeException('GitLab: respuesta incompleta o fuera de límites.' . $uncertain);
        }
        if ($p['list']) {
            foreach ($b as $row) {
                if (!is_array($row) || array_is_list($row)) { throw new RuntimeException('GitLab: registro de lista inválido.'); }
                $this->number($row['id'] ?? null, PHP_INT_MAX);
            }
        } elseif ($p['operation'] !== 'get_file') {
            $this->number($b['id'] ?? null, PHP_INT_MAX);
        }
        if ($p['operation'] === 'get_file') {
            $content = $b['content'] ?? null;
            $decoded = is_string($content) ? base64_decode(str_replace(["\r","\n"], '', $content), true) : false;
            if (($b['encoding'] ?? '') !== 'base64' || !is_int($b['size'] ?? null) || $b['size'] > 1048576 || $decoded === false || strlen($decoded) !== $b['size']) { throw new RuntimeException('GitLab: archivo inválido o superior a 1 MiB.'); }
            $b = array_intersect_key($b, array_flip(['file_name','file_path','size','encoding','content','ref','blob_id','commit_id','last_commit_id','content_sha256']));
        }
        $b = $this->clean($b, $token);
        $next = $r->getHeaderLine('X-Next-Page');
        if ($next !== '' && (!ctype_digit($next) || (int)$next <= $p['query']['page'] || (int)$next > 10000)) { throw new RuntimeException('GitLab: cursor de página inválido.'); }
        return ['success'=>true,'status_code'=>$status,'data'=>$b,'pagination'=>['page'=>$p['query']['page'],'per_page'=>$p['query']['per_page'],'next_page'=>$p['list'] && $next !== '' ? (int)$next : null,'has_more'=>$p['list'] && $next !== '']];
    }

    private function prepare(array $p): array
    {
        $credential = $this->text($p['credential_id'] ?? null, 255);
        $op = $p['operation'] ?? 'list_projects'; $query = ['page'=>$this->number($p['page'] ?? 1,10000),'per_page'=>$this->number($p['per_page'] ?? 50,100)];
        $payload = $this->object($p['payload'] ?? []); $method = 'GET'; $list = false;
        if ($op === 'list_projects') { $path='/projects'; $list=true; if (isset($p['search']) && $p['search'] !== '') { $query['search']=$this->text($p['search'],256); } }
        else {
            $project = $this->text($p['project'] ?? null, 1024);
            if (!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D', $project) || array_intersect(explode('/',$project),['.','..'])) { throw new InvalidArgumentException('GitLab: proyecto inválido.'); }
            $base='/projects/' . rawurlencode($project);
            switch ($op) {
                case 'get_project': $path=$base; break;
                case 'get_file':
                    $file=$this->text($p['file_path'] ?? null,2048);
                    if (str_starts_with($file,'/') || str_contains($file,'\\') || array_intersect(explode('/',$file),['.','..',''])) { throw new InvalidArgumentException('GitLab: ruta relativa inválida.'); }
                    $path=$base.'/repository/files/'.rawurlencode($file); $query['ref']=$this->text($p['ref'] ?? null,1024); break;
                case 'list_issues': $path=$base.'/issues'; $list=true; break;
                case 'get_issue': case 'update_issue': $path=$base.'/issues/'.$this->number($p['issue_iid'] ?? null,PHP_INT_MAX); if ($op==='update_issue') {$method='PUT';} break;
                case 'create_issue': $path=$base.'/issues'; $method='POST'; break;
                case 'list_pipelines': $path=$base.'/pipelines'; $list=true; break;
                case 'get_pipeline': $path=$base.'/pipelines/'.$this->number($p['pipeline_id'] ?? null,PHP_INT_MAX); break;
                case 'list_jobs': $path=$base.'/pipelines/'.$this->number($p['pipeline_id'] ?? null,PHP_INT_MAX).'/jobs'; $list=true; break;
                case 'get_job': $path=$base.'/jobs/'.$this->number($p['job_id'] ?? null,PHP_INT_MAX); break;
                case 'create_pipeline': case 'trigger_pipeline':
                    $path=$base.($op==='create_pipeline'?'/pipeline':'/trigger/pipeline'); $method='POST'; $payload['ref']=$this->text($p['ref'] ?? null,1024); break;
                default: throw new InvalidArgumentException('GitLab: operación no admitida.');
            }
        }
        if (in_array($op,['create_issue','update_issue'],true)) {
            if (array_diff(array_keys($payload),['title','description','labels','assignee_ids','milestone_id','due_date','confidential','state_event'])) { throw new InvalidArgumentException('GitLab: campo de issue no admitido.'); }
            if ($op==='create_issue') { $this->text($payload['title'] ?? null,255); }
            if ($payload === []) { throw new InvalidArgumentException('GitLab: actualización vacía.'); }
            foreach ($payload as $k=>$v) {
                if ($k==='assignee_ids') { if(!is_array($v)||!array_is_list($v)||count($v)>100){throw new InvalidArgumentException('GitLab: asignados inválidos.');} foreach($v as $id){$this->number($id,PHP_INT_MAX);} }
                elseif($k==='confidential') { if(!is_bool($v)){throw new InvalidArgumentException('GitLab: confidential requiere booleano.');} }
                elseif($k==='milestone_id') { if ($v!==null && $v!==0) {$this->number($v,PHP_INT_MAX);} }
                elseif($k==='state_event') { if(!in_array($v,['close','reopen'],true)){throw new InvalidArgumentException('GitLab: estado inválido.');} }
                elseif(!is_string($v)||strlen($v)>65536||($k==='title'&&trim($v)==='')||preg_match('/{{[^}]*}}/', $v)){throw new InvalidArgumentException('GitLab: campo de issue inválido.');}
                if ($k==='due_date' && $v!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||date('Y-m-d',strtotime($v))!==$v)) {throw new InvalidArgumentException('GitLab: fecha inválida.');}
            }
        } elseif(in_array($op,['create_pipeline','trigger_pipeline'],true)) {
            if(array_diff(array_keys($payload),['ref','variables'])){throw new InvalidArgumentException('GitLab: campo de pipeline no admitido.');}
            if(isset($payload['variables'])) {
                $vars=$payload['variables']; if(!is_array($vars)||count($vars)>50){throw new InvalidArgumentException('GitLab: variables inválidas.');}
                if($op==='trigger_pipeline') { foreach($vars as $k=>$v){if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',(string)$k)||!is_string($v)||strlen($v)>8192){throw new InvalidArgumentException('GitLab: variables inválidas.');}} }
                else { if(!array_is_list($vars)){throw new InvalidArgumentException('GitLab: variables requiere lista.');} foreach($vars as $v){if(!is_array($v)||array_diff(array_keys($v),['key','value','variable_type'])||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$v['key']??'')||!is_string($v['value']??null)||strlen($v['value'])>8192||!in_array($v['variable_type']??'env_var',['env_var','file'],true)){throw new InvalidArgumentException('GitLab: variable inválida.');}} }
            }
        } elseif($payload!==[]) { throw new InvalidArgumentException('GitLab: esta consulta no acepta payload.'); }
        return ['credential_id'=>$credential,'operation'=>$op,'path'=>$path,'method'=>$method,'payload'=>$payload,'query'=>$query,'list'=>$list];
    }

    private function text(mixed $v, int $max): string
    {
        if ((!is_string($v)&&!is_int($v))||trim((string)$v)===''||strlen((string)$v)>$max||preg_match('/[\x00-\x1f\x7f]|{{[^}]*}}/',(string)$v)) { throw new InvalidArgumentException('GitLab: texto o identificador inválido.'); } return (string)$v;
    }
    private function number(mixed $v,int $max): int
    {
        if ((!is_string($v)&&!is_int($v))||!preg_match('/^[1-9][0-9]*$/D',(string)$v)||filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v>$max){throw new InvalidArgumentException('GitLab: entero fuera de límites.');} return (int)$v;
    }
    private function object(mixed $v): array
    {
        if ($v==='') {return [];} try {if(is_string($v)){$v=json_decode($v,false,32,JSON_THROW_ON_ERROR);} if($v instanceof \stdClass){$v=json_decode(json_encode($v,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);} }catch(\Throwable $e){throw new InvalidArgumentException('GitLab: JSON inválido.');}
        if(!is_array($v)||($v!==[]&&array_is_list($v))){throw new InvalidArgumentException('GitLab: se requiere objeto JSON.');} return $v;
    }
    private function origin(mixed $v): string
    {
        $v=$this->text($v,2048); $u=parse_url($v); $host=strtolower($u['host']??'');
        if(($u['scheme']??'')!=='https'||$host===''||isset($u['user'],$u['pass'])||isset($u['user'])||isset($u['query'])||isset($u['fragment'])||isset($u['port'])||filter_var($host,FILTER_VALIDATE_IP)||$host==='localhost'||str_ends_with($host,'.local')||str_contains($v,'\\')||preg_match('~(?:^|/)\.{1,2}(?:/|$)|%~',$u['path']??'')){throw new InvalidArgumentException('GitLab: origen HTTPS inválido.');} return rtrim($v,'/');
    }
    private function clean(mixed $v,string $token): mixed
    {
        if(is_array($v)){ $out=[]; foreach($v as $k=>$x){if(preg_match('/token|secret|password|authorization|api_key|runners_token/i',(string)$k)){continue;} $out[$k]=$this->clean($x,$token);} return $out; }
        return is_string($v)?str_replace($token,'[REDACTED]',$v):$v;
    }
}
