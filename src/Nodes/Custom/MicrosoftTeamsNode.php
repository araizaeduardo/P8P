<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MicrosoftTeamsNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type'=>'microsoft_teams','name'=>'Microsoft Teams','category'=>'Community','icon'=>'MessagesSquare','is_start'=>false,
            'description'=>'Mensajes de canal con Graph delegado y tarjetas adaptativas con Teams Workflows.',
            'help_text'=>'generic_bearer/API Key JSON: Graph {"mode":"graph_delegated","access_token":"EJEMPLO"}; permiso delegado ChannelMessage.Send, cuenta de trabajo/escuela y usuario con acceso al canal. Tokens de aplicación y Teamwork.Migrate.All NO sirven para envío habitual; bots requieren otro registro y no se simulan aquí. Workflows {"mode":"workflow_anyone","workflow_url":"https://ENTORNO.environment.api.powerplatform.com/powerautomate/automations/direct/workflows/ID/triggers/manual/paths/invoke?api-version=1&sig=EJEMPLO"}; URL secreta solo en credencial. Para trigger protegido, mode workflow_oauth + access_token con audiencia del flujo (no Graph). Crear externamente el flujo When a Teams webhook request is received y acción Post card in chat or channel; equipo/canal y propietarios/co-propietarios se fijan en ese flujo. Usa URL nueva migrada de Power Automate; no Office 365 Connectors retirados. 202 significa aceptado, no entregado. Hasta 28 KiB por mensaje/tarjeta. Sin renovación OAuth ni reintento; reemplaza tokens caducados externamente. https://learn.microsoft.com/connectors/teams/ ; https://learn.microsoft.com/graph/api/chatmessage-post?view=graph-rest-1.0',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','required'=>true,'label'=>'Credencial Teams'],
                'operation'=>['type'=>'select','default'=>'send_channel_message','label'=>'Operación','options'=>[['value'=>'send_channel_message','label'=>'Mensaje Graph delegado'],['value'=>'send_workflow_message','label'=>'Mensaje vía Workflows'],['value'=>'send_adaptive_card','label'=>'Tarjeta vía Workflows']]],
                'team_id'=>['type'=>'string','label'=>'ID de equipo (Graph)'], 'channel_id'=>['type'=>'string','label'=>'ID de canal (Graph)'],
                'content'=>['type'=>'textarea','label'=>'Contenido del mensaje'],
                'content_type'=>['type'=>'select','default'=>'text','label'=>'Formato Graph','options'=>[['value'=>'text','label'=>'Texto'],['value'=>'html','label'=>'HTML']]],
                'card'=>['type'=>'textarea','label'=>'Adaptive Card JSON','placeholder'=>'{"type":"AdaptiveCard","version":"1.4","body":[{"type":"TextBlock","text":"Ejemplo"}]}']]];
    }

    public function validate(array $parameters): bool
    {
        try{$this->prepare($parameters);return true;}catch(\Throwable $e){return false;}
    }

    public function execute(array $parameters,array $context): array
    {
        // Engine resuelve ExpressionEvaluator una sola vez antes de execute.
        $p=$this->prepare($parameters);
        try{$c=($this->credentials??new CredentialManager())->getCredentialDecrypted($p['credential_id']);}catch(\Throwable $e){throw new RuntimeException('Teams: fallo del gestor o descifrado de credenciales.');}
        if($c===null){throw new InvalidArgumentException('Teams: credencial inexistente.');}
        if(($c['type']??'')!=='generic_bearer'){throw new InvalidArgumentException('Teams: tipo de credencial incompatible.');}
        try{$s=json_decode($c['data']['api_key']??'',true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new InvalidArgumentException('Teams: API Key requiere configuración JSON.');}
        if(!is_array($s)){throw new InvalidArgumentException('Teams: configuración inválida.');}
        $mode=$s['mode']??null;$headers=['Accept'=>'application/json'];$secrets=[];
        if($p['operation']==='send_channel_message'){
            if($mode!=='graph_delegated'){throw new InvalidArgumentException('Teams: envío habitual Graph requiere mode graph_delegated.');}
            $token=$this->token($s['access_token']??null);$this->delegated($token);$secrets[]=$token;$headers['Authorization']='Bearer '.$token;
            $url='https://graph.microsoft.com/v1.0/teams/'.rawurlencode($p['team_id']).'/channels/'.rawurlencode($p['channel_id']).'/messages';
            $body=['body'=>['contentType'=>$p['content_type'],'content'=>$p['content']]];
        }else{
            if(!in_array($mode,['workflow_anyone','workflow_oauth'],true)){throw new InvalidArgumentException('Teams: tarjetas/mensajes Workflows requieren su credencial específica.');}
            $url=$this->workflowUrl($s['workflow_url']??null);$secrets[]=$url;
            if($mode==='workflow_oauth'){$token=$this->token($s['access_token']??null);$secrets[]=$token;$headers['Authorization']='Bearer '.$token;}
            $card=$p['operation']==='send_adaptive_card'?$p['card']:['type'=>'AdaptiveCard','version'=>'1.4','body'=>[['type'=>'TextBlock','text'=>$p['content'],'wrap'=>true]]];
            $body=['type'=>'message','attachments'=>[['contentType'=>'application/vnd.microsoft.card.adaptive','contentUrl'=>null,'content'=>$card]]];
        }
        if(strlen(json_encode($body,JSON_THROW_ON_ERROR))>28672){throw new InvalidArgumentException('Teams: mensaje supera 28 KiB.');}
        try{$r=($this->client??new Client())->request('POST',$url,['headers'=>$headers,'json'=>$body,'timeout'=>30,'connect_timeout'=>10,'verify'=>true,'allow_redirects'=>false,'http_errors'=>false,'stream'=>true]);$raw=$r->getBody()->read(1048577);$r->getBody()->close();}catch(\Throwable $e){throw new RuntimeException('Teams: fallo de transporte; el envío puede haber ocurrido. Comprueba el canal antes de repetir.');}
        $status=$r->getStatusCode();if($status<200||$status>=300){throw new RuntimeException('Teams: '.match($status){401=>'autenticación rechazada o token caducado',403=>'permisos o política del tenant insuficientes',404=>'equipo, canal o flujo inexistente',429=>'cuota agotada',default=>'solicitud rechazada'}.' (HTTP '.$status.'). Comprueba antes de repetir.');}
        if(strlen($raw)>1048576){throw new RuntimeException('Teams: respuesta excesiva; comprobar entrega antes de repetir.');}
        if($p['operation']==='send_channel_message'){
            try{$b=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('Teams: respuesta Graph inválida; comprobar entrega antes de repetir.');}
            if(!is_array($b)||isset($b['error'])||!is_string($b['id']??null)||$b['id']===''){throw new RuntimeException('Teams: mensaje sin identificador; comprobar entrega antes de repetir.');}
            $data=['id'=>$b['id'],'team_id'=>$p['team_id'],'channel_id'=>$p['channel_id'],'created_date_time'=>is_string($b['createdDateTime']??null)?$b['createdDateTime']:null];
        }else{
            if(trim($raw)!==''&&str_starts_with(ltrim($raw),'{')){try{$b=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('Teams: confirmación Workflows inválida.');}if(isset($b['error'])||($b['success']??true)===false){throw new RuntimeException('Teams: Workflows rechazó la solicitud.');}}
            $data=['accepted'=>true,'delivery_confirmed'=>false,'mechanism'=>'workflows'];
        }
        $out=json_encode($data,JSON_THROW_ON_ERROR);foreach($secrets as $secret){if(str_contains($out,$secret)){throw new RuntimeException('Teams: confirmación contiene información secreta.');}}
        return ['success'=>true,'status_code'=>$status,'data'=>$data];
    }

    private function prepare(array $p): array
    {
        $p['credential_id']=$this->text($p['credential_id']??null,255);$op=$p['operation']??'send_channel_message';
        if(!in_array($op,['send_channel_message','send_workflow_message','send_adaptive_card'],true)){throw new InvalidArgumentException('Teams: operación no admitida.');}$p['operation']=$op;
        if($op==='send_channel_message'){
            $team=$p['team_id']??null;if(!is_string($team)||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$team)){throw new InvalidArgumentException('Teams: team_id debe ser UUID.');}
            $channel=$p['channel_id']??null;if(!is_string($channel)||strlen($channel)>512||!preg_match('/^19:[A-Za-z0-9_.@:-]+@thread\.(?:tacv2|skype)$/D',$channel)){throw new InvalidArgumentException('Teams: channel_id inválido.');}
            $p['content_type']=$p['content_type']??'text';if(!in_array($p['content_type'],['text','html'],true)){throw new InvalidArgumentException('Teams: formato inválido.');}
        }
        if($op==='send_adaptive_card'){
            $card=$p['card']??null;try{if(is_string($card)){$card=json_decode($card,false,32,JSON_THROW_ON_ERROR);}if($card instanceof \stdClass){$card=json_decode(json_encode($card,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);}}catch(\Throwable $e){throw new InvalidArgumentException('Teams: tarjeta JSON inválida.');}
            if(!is_array($card)||($card['type']??null)!=='AdaptiveCard'||!in_array($card['version']??null,['1.0','1.1','1.2','1.3','1.4','1.5'],true)||!is_array($card['body']??null)||!array_is_list($card['body'])||count($card['body'])>100||array_diff(array_keys($card),['type','version','$schema','body','actions','fallbackText','speak','lang','rtl','verticalContentAlignment','minHeight'])){throw new InvalidArgumentException('Teams: estructura o versión de tarjeta inválida.');}
            $this->cardTree($card);if(strlen(json_encode($card,JSON_THROW_ON_ERROR))>27000){throw new InvalidArgumentException('Teams: tarjeta demasiado grande.');}$p['card']=$card;
        }else{$p['content']=$this->text($p['content']??null,27000);}
        return $p;
    }
    private function cardTree(mixed $v,int $depth=0): void
    {
        if($depth>20){throw new InvalidArgumentException('Teams: tarjeta demasiado profunda.');}if(!is_array($v)){return;}
        if(isset($v['type'])&&(!is_string($v['type'])||!in_array($v['type'],['AdaptiveCard','TextBlock','RichTextBlock','TextRun','Image','ImageSet','Container','Column','ColumnSet','FactSet','ActionSet','Action.OpenUrl','Action.ShowCard','Action.ToggleVisibility'],true))){throw new InvalidArgumentException('Teams: elemento de tarjeta no soportado; inputs/submit requieren bot o flujo de respuestas.');}
        foreach($v as $k=>$x){if(in_array($k,['url','backgroundImage'],true)){if(!is_string($x)||!preg_match('~^https://[^\s]+$~D',$x)){throw new InvalidArgumentException('Teams: URL de tarjeta debe ser HTTPS.');}}if(is_string($x)&&preg_match('/{{[^}]*}}/',$x)){throw new InvalidArgumentException('Teams: expresión sin resolver.');}$this->cardTree($x,$depth+1);}
    }
    private function delegated(string $token): void
    {
        // Solo inspección preventiva; Graph valida firma, audiencia, expiración y permisos.
        $parts=explode('.',$token);if(count($parts)!==3){return;}$raw=base64_decode(strtr($parts[1],'-_','+/'),true);$claims=$raw===false?null:json_decode($raw,true);
        if(!is_array($claims)||isset($claims['roles'])||!is_string($claims['scp']??null)||!in_array('ChannelMessage.Send',explode(' ',$claims['scp']),true)){throw new InvalidArgumentException('Teams: token no delegado o sin ChannelMessage.Send.');}
    }
    private function workflowUrl(mixed $url): string
    {
        $url=$this->text($url,8192);$u=parse_url($url);$host=strtolower($u['host']??'');
        if(($u['scheme']??'')!=='https'||!preg_match('/^[a-z0-9-]+\.environment\.api\.powerplatform\.com$/D',$host)||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['fragment'])||!preg_match('~^/powerautomate/automations/direct/workflows/[a-zA-Z0-9-]+/triggers/manual/paths/invoke$~D',$u['path']??'')||str_contains($url,'\\')||preg_match('/[\x00-\x20\x7f]/',$url)){throw new InvalidArgumentException('Teams: usa URL Workflows HTTPS migrada de Power Platform, sin redirecciones.');}return $url;
    }
    private function token(mixed $v): string
    {
        $v=$this->text($v,16384);if(preg_match('/[\x00-\x20\x7f]/',$v)){throw new InvalidArgumentException('Teams: token inválido.');}return $v;
    }
    private function text(mixed $v,int $max): string
    {
        if(!is_string($v)||trim($v)===''||strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|{{[^}]*}}/',$v)){throw new InvalidArgumentException('Teams: texto requerido inválido.');}return $v;
    }
}
