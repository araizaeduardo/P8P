<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MondayNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        $ops=['list_boards','list_items','get_item','create_item','update_item','change_columns','change_status','create_update'];
        $fields=['credential_id'=>['type'=>'credential','required'=>true,'label'=>'Credencial Monday.com'],'operation'=>['type'=>'select','default'=>'list_boards','label'=>'Operación','options'=>array_map(fn($v)=>['value'=>$v,'label'=>$v],$ops)]];
        foreach(['board_id','item_id','group_id','item_name','column_id','cursor'] as $k){$fields[$k]=['type'=>'string','label'=>$k];}
        $fields['column_values']=['type'=>'textarea','label'=>'Valores de columnas JSON','placeholder'=>'{"status":{"label":"Done"},"text":""}'];
        $fields['body']=['type'=>'textarea','label'=>'Comentario HTML'];
        foreach(['page'=>1,'limit'=>50] as $k=>$v){$fields[$k]=['type'=>'number','default'=>$v,'label'=>$k];}
        return ['type'=>'monday','name'=>'Monday.com','category'=>'Community','icon'=>'Columns3','is_start'=>false,'description'=>'Tableros, elementos, columnas y comentarios mediante GraphQL fijo.',
            'help_text'=>'generic_bearer/API Key: token personal o JSON {"access_token":"EJEMPLO"}. API-Version 2026-10; permisos boards:read, boards:write y updates:write según acción y acceso al tablero. Una página por ejecución (1–100 elementos), cursor expira a los 60 minutos. update_item cambia solo item_name; change_columns cambia solo claves del JSON, ""/null vacían según tipo. create_item requiere board_id, group_id, item_name. Antes de escribir columnas se consulta su esquema; tipos soportados: text,long_text,numbers,status,date,checkbox,email,link,dropdown,people y name; otros se rechazan. No crea etiquetas de estado ni transforma valores automáticamente. Complejidad/cuotas se exponen; errores GraphQL HTTP 200 detienen el flujo. Triggers de elementos/columnas se configuran por separado con app Signing Secret y JWT; webhooks sin JWT se rechazan. Sin renovación OAuth nueva. https://developer.monday.com/api-reference/docs/api-versioning', 'parameters'=>$fields];
    }

    public function validate(array $parameters): bool
    {
        try{$this->prepare($parameters);return true;}catch(\Throwable $e){return false;}
    }

    public function execute(array $parameters,array $context): array
    {
        $p=$this->prepare($parameters);
        try{$c=($this->credentials??new CredentialManager())->getCredentialDecrypted($p['credential_id']);}catch(\Throwable $e){throw new RuntimeException('Monday: fallo del gestor o descifrado de credenciales.');}
        if($c===null){throw new InvalidArgumentException('Monday: credencial inexistente.');}
        $token=$c['data']['api_key']??null;
        if(is_string($token)&&str_starts_with(ltrim($token),'{')){try{$s=json_decode($token,true,32,JSON_THROW_ON_ERROR);$token=$s['access_token']??null;}catch(\Throwable $e){throw new InvalidArgumentException('Monday: configuración JSON inválida.');}}
        if(($c['type']??'')!=='generic_bearer'||!is_string($token)||strlen($token)<8||strlen($token)>8192||preg_match('/[\x00-\x20\x7f]/',$token)){throw new InvalidArgumentException('Monday: credencial Bearer incompatible o token inválido.');}
        $op=$p['operation'];$v=[];$result='';$mutation=false;
        if(in_array($op,['create_item','change_columns','change_status'],true)&&$p['columns']!==[]){$this->validateColumns($p,$token);}
        switch($op){
            case 'list_boards': $q='query ($limit:Int!,$page:Int!) { complexity { query before after reset_in_x_seconds } boards(limit:$limit,page:$page) { id name state board_kind } }';$v=['limit'=>$p['limit'],'page'=>$p['page']];$result='boards';break;
            case 'list_items':
                if($p['cursor']!==''){$q='query ($limit:Int!,$cursor:String!) { complexity { query before after reset_in_x_seconds } next_items_page(limit:$limit,cursor:$cursor) { cursor items { id name updated_at column_values { id type text value } } } }';$v=['limit'=>$p['limit'],'cursor'=>$p['cursor']];$result='next_items_page';}
                else{$q='query ($boards:[ID!]!,$limit:Int!) { complexity { query before after reset_in_x_seconds } boards(ids:$boards) { id items_page(limit:$limit) { cursor items { id name updated_at column_values { id type text value } } } } }';$v=['boards'=>[$p['board_id']],'limit'=>$p['limit']];$result='boards';}break;
            case 'get_item': $q='query ($ids:[ID!]!) { complexity { query before after reset_in_x_seconds } items(ids:$ids) { id name updated_at board { id } column_values { id type text value } } }';$v=['ids'=>[$p['item_id']]];$result='items';break;
            case 'create_item': $q='mutation ($board:ID!,$group:String!,$name:String!,$values:JSON!) { complexity { query before after reset_in_x_seconds } create_item(board_id:$board,group_id:$group,item_name:$name,column_values:$values,create_labels_if_missing:false) { id name } }';$v=['board'=>$p['board_id'],'group'=>$p['group_id'],'name'=>$p['item_name'],'values'=>json_encode((object)$p['columns'],JSON_THROW_ON_ERROR)];$result='create_item';$mutation=true;break;
            case 'update_item':$q='mutation ($board:ID!,$item:ID!,$value:String!) { complexity { query before after reset_in_x_seconds } change_simple_column_value(board_id:$board,item_id:$item,column_id:"name",value:$value) { id name } }';$v=['board'=>$p['board_id'],'item'=>$p['item_id'],'value'=>$p['item_name']];$result='change_simple_column_value';$mutation=true;break;
            case 'change_columns':case 'change_status':$q='mutation ($board:ID!,$item:ID!,$values:JSON!) { complexity { query before after reset_in_x_seconds } change_multiple_column_values(board_id:$board,item_id:$item,column_values:$values,create_labels_if_missing:false) { id name } }';$v=['board'=>$p['board_id'],'item'=>$p['item_id'],'values'=>json_encode((object)$p['columns'],JSON_THROW_ON_ERROR)];$result='change_multiple_column_values';$mutation=true;break;
            case 'create_update':$q='mutation ($item:ID!,$body:String!) { complexity { query before after reset_in_x_seconds } create_update(item_id:$item,body:$body) { id } }';$v=['item'=>$p['item_id'],'body'=>$p['body']];$result='create_update';$mutation=true;break;
        }
        [$b,$status]=$this->request($q,$v,$token,$mutation);$data=$b['data'][$result]??null;
        if(!is_array($data)){throw new RuntimeException('Monday: resultado incompleto.');}
        $next=null;
        if($op==='list_items'){
            if($result==='boards'){$data=$data[0]['items_page']??null;}
            if(!is_array($data)||!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])>$p['limit']||!array_key_exists('cursor',$data)||($data['cursor']!==null&&!is_string($data['cursor']))){throw new RuntimeException('Monday: página de elementos inválida.');}
            $next=$data['cursor'];$data=$data['items'];
        }elseif(in_array($op,['list_boards','get_item'],true)){
            if(!array_is_list($data)||count($data)>($op==='get_item'?1:$p['limit'])){throw new RuntimeException('Monday: lista inválida.');}
            if($op==='get_item'&&$data===[]){throw new RuntimeException('Monday: elemento inexistente o inaccesible.');}
        }elseif(!isset($data['id'])){throw new RuntimeException('Monday: escritura sin identificador; comprobar antes de repetir.');}
        $records = in_array($op, ['list_items','list_boards','get_item'], true) ? $data : [$data];
        foreach ($records as $row) {
            if (!is_array($row)) { throw new RuntimeException('Monday: registro remoto inválido.'); }
            $this->number($row['id'] ?? null, PHP_INT_MAX);
        }
        $encoded=json_encode($data,JSON_THROW_ON_ERROR);if(str_contains($encoded,$token)){throw new RuntimeException('Monday: respuesta contiene datos de autenticación.');}
        return ['success'=>true,'status_code'=>$status,'data'=>$data,'pagination'=>['limit'=>$p['limit'],'page'=>$p['page'],'next_cursor'=>$next,'next_page'=>$op==='list_boards'&&count($data)===$p['limit']?$p['page']+1:null,'has_more'=>$next!==null||($op==='list_boards'&&count($data)===$p['limit'])],'complexity'=>$b['data']['complexity']??null,'api_version'=>'2026-10'];
    }

    private function prepare(array $p): array
    {
        $ops=['list_boards','list_items','get_item','create_item','update_item','change_columns','change_status','create_update'];$op=$p['operation']??'list_boards';
        if(!in_array($op,$ops,true)){throw new InvalidArgumentException('Monday: operación no admitida.');}
        $p['operation']=$op;$p['credential_id']=$this->text($p['credential_id']??null,255);$p['limit']=$this->number($p['limit']??50,100);$p['page']=$this->number($p['page']??1,10000);
        $p['cursor']=isset($p['cursor'])&&$p['cursor']!==''?$this->text($p['cursor'],8192):'';
        foreach(['board_id','item_id'] as $k){if(isset($p[$k])&&$p[$k]!==''){$p[$k]=(string)$this->number($p[$k],PHP_INT_MAX);}}
        if(in_array($op,['list_items','create_item','update_item','change_columns','change_status'],true)&&($p['cursor']===''||$op!=='list_items')){$this->number($p['board_id']??null,PHP_INT_MAX);}
        if(in_array($op,['get_item','update_item','change_columns','change_status','create_update'],true)){$this->number($p['item_id']??null,PHP_INT_MAX);}
        if(in_array($op,['create_item','update_item'],true)){$p['item_name']=$this->text($p['item_name']??null,255);}
        if($op==='create_item'){$p['group_id']=$this->text($p['group_id']??null,255);}
        if($op==='create_update'){$p['body']=$this->text($p['body']??null,65536);}
        $p['columns']=$this->object($p['column_values']??[]);
        if(in_array($op,['change_columns','change_status'],true)&&$p['columns']===[]){throw new InvalidArgumentException('Monday: valores de columnas requeridos.');}
        if($op==='change_status'){$id=$this->text($p['column_id']??null,255);if(count($p['columns'])!==1||!array_key_exists($id,$p['columns'])){throw new InvalidArgumentException('Monday: change_status requiere exactamente column_id en column_values.');}}
        if(!in_array($op,['create_item','change_columns','change_status'],true)&&$p['columns']!==[]){throw new InvalidArgumentException('Monday: esta operación no acepta columnas.');}
        return $p;
    }

    private function validateColumns(array $p,string $token): void
    {
        [$b]=$this->request('query ($ids:[ID!]!) { boards(ids:$ids) { id columns { id type settings_str } } }',['ids'=>[$p['board_id']]],$token,false);
        $rows=$b['data']['boards'][0]['columns']??null;if(!is_array($rows)){throw new RuntimeException('Monday: esquema del tablero inaccesible.');}$map=[];foreach($rows as $r){if(is_array($r)&&is_string($r['id']??null)){$map[$r['id']]=$r;}}
        if(count($p['columns'])>100){throw new InvalidArgumentException('Monday: máximo 100 columnas.');}
        foreach($p['columns'] as $id=>$v){
            $type=$map[$id]['type']??null;if($p['operation']==='change_status'&&$type!=='status'){throw new InvalidArgumentException('Monday: columna no es de estado.');}
            if(!in_array($type,['text','long_text','numbers','status','date','checkbox','email','link','dropdown','people','name'],true)){throw new InvalidArgumentException('Monday: columna inexistente o tipo no soportado.');}
            if($v===null){continue;}
            $valid=match($type){
                'text','name'=>is_string($v)&&strlen($v)<=10000,
                'numbers'=>$v===''||((is_int($v)||is_float($v)||is_string($v))&&is_numeric($v)&&is_finite((float)$v)),
                'long_text'=>is_array($v)&&array_keys($v)===['text']&&is_string($v['text'])&&strlen($v['text'])<=65536,
                'status'=>is_array($v)&&count($v)===1&&((isset($v['label'])&&is_string($v['label'])&&strlen($v['label'])<=255)||(isset($v['index'])&&is_int($v['index'])&&$v['index']>=0)),
                'date'=>is_array($v)&&!array_diff(array_keys($v),['date','time'])&&array_key_exists('date',$v)&&($v['date']===null||$v['date']===''||(is_string($v['date'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v['date'])&&date('Y-m-d',strtotime($v['date']))===$v['date']))&&(!isset($v['time'])||(is_string($v['time'])&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',$v['time']))),
                'checkbox'=>is_array($v)&&array_keys($v)===['checked']&&in_array($v['checked'],['true','false'],true),
                'email'=>is_array($v)&&!array_diff(array_keys($v),['email','text'])&&is_string($v['email']??null)&&($v['email']===''||filter_var($v['email'],FILTER_VALIDATE_EMAIL))&&is_string($v['text']??null),
                'link'=>is_array($v)&&!array_diff(array_keys($v),['url','text'])&&is_string($v['url']??null)&&($v['url']===''||preg_match('~^https?://[^\s]+$~D',$v['url']))&&is_string($v['text']??null),
                'dropdown'=>is_array($v)&&count($v)===1&&($this->listValues($v['ids']??null,true)||$this->listValues($v['labels']??null,false)),
                'people'=>is_array($v)&&array_keys($v)===['personsAndTeams']&&$this->people($v['personsAndTeams']),
                default=>false};
            if(!$valid){throw new InvalidArgumentException('Monday: formato de valor incompatible con el tipo de columna.');}
            if($type==='status'&&is_array($v)&&isset($v['label'])){$settings=json_decode($map[$id]['settings_str']??'{}',true);$labels=$settings['labels']??null;if(!is_array($labels)||!in_array($v['label'],$labels,true)){throw new InvalidArgumentException('Monday: etiqueta de estado inexistente.');}}
        }
    }
    private function people(mixed $v): bool
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>100){return false;}foreach($v as $x){if(!is_array($x)||count($x)!==2||!in_array($x['kind']??null,['person','team'],true)){return false;}try{$this->number($x['id']??null,PHP_INT_MAX);}catch(\Throwable $e){return false;}}return true;
    }
    private function listValues(mixed $v,bool $numeric): bool
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>100){return false;}foreach($v as $x){if($numeric?(!is_int($x)||$x<0):(!is_string($x)||$x===''||strlen($x)>255)){return false;}}return true;
    }
    private function request(string $q,array $vars,string $token,bool $mutation): array
    {
        $uncertain=$mutation?' Resultado incierto; comprobar antes de repetir.':'';
        try{$r=($this->client??new Client())->request('POST','https://api.monday.com/v2',['headers'=>['Authorization'=>$token,'API-Version'=>'2026-10'],'json'=>['query'=>$q,'variables'=>$vars],'timeout'=>30,'connect_timeout'=>10,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false,'stream'=>true]);$raw=$r->getBody()->read(4194305);$r->getBody()->close();}catch(\Throwable $e){throw new RuntimeException('Monday: fallo de transporte.'.$uncertain);}
        $status=$r->getStatusCode();if($status<200||$status>=300){throw new RuntimeException('Monday: '.match($status){401=>'autenticación rechazada',403=>'permisos insuficientes',429=>'cuota o complejidad agotada',default=>'solicitud rechazada'}.' (HTTP '.$status.').'.$uncertain);}
        try{$b=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('Monday: JSON remoto inválido.'.$uncertain);}
        if(strlen($raw)>4194304||!is_array($b)||!empty($b['errors'])||isset($b['error_code'])||isset($b['error_message'])||!is_array($b['data']??null)){throw new RuntimeException('Monday: error GraphQL, complejidad/cuota o respuesta inválida.'.$uncertain);}
        return [$b,$status];
    }
    private function object(mixed $v): array
    {
        if($v===''){return [];}try{if(is_string($v)){$v=json_decode($v,false,32,JSON_THROW_ON_ERROR);}if($v instanceof \stdClass){$v=json_decode(json_encode($v,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);}}catch(\Throwable $e){throw new InvalidArgumentException('Monday: JSON inválido.');}if(!is_array($v)||($v!==[]&&array_is_list($v))||strlen(json_encode($v))>131072||preg_match('/{{[^}]*}}/',json_encode($v))){throw new InvalidArgumentException('Monday: objeto de columnas inválido.');}return $v;
    }
    private function text(mixed $v,int $max): string
    {
        if(!is_string($v)||trim($v)===''||strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|{{[^}]*}}/',$v)){throw new InvalidArgumentException('Monday: texto inválido.');}return $v;
    }
    private function number(mixed $v,int $max): int
    {
        if((!is_string($v)&&!is_int($v))||!preg_match('/^[1-9][0-9]*$/D',(string)$v)||filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v>$max){throw new InvalidArgumentException('Monday: ID o límite inválido.');}return (int)$v;
    }
}
