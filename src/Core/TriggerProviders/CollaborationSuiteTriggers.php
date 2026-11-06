<?php

namespace P8P\Core\TriggerProviders;

use InvalidArgumentException;
use LogicException;
use P8P\Interfaces\TriggerProviderInterface;

/** Verification adapters. TriggerManager owns durable admission, deduplication and ACK. */
class CollaborationSuiteTriggers implements TriggerProviderInterface
{
    public function definitions(): array
    {
        $out = [];
        foreach (['push'=>'Push Hook','issues'=>'Issue Hook','pipelines'=>'Pipeline Hook'] as $event=>$header) {
            $out['gitlab_'.$event] = [
                'name'=>'GitLab: '.$event,'node_type'=>'gitlab','event'=>$event,'transport'=>'webhook',
                'config_fields'=>['project_id'=>['type'=>'string','label'=>'ID numérico del proyecto','required'=>true], 'auth_mode'=>['type'=>'select','label'=>'Autenticación','required'=>true,'options'=>[['value'=>'signing','label'=>'Signing token GitLab 19+'],['value'=>'legacy_secret','label'=>'Secret token legado']]]],
                'secret_fields'=>['signing_token'=>['type'=>'string','label'=>'Signing token whsec_ (modo signing)','required'=>false], 'webhook_secret'=>['type'=>'string','label'=>'Secret token (modo legacy_secret)','required'=>false]],
                'help'=>'Registrar manualmente URL HTTPS y evento en Settings > Webhooks del proyecto GitLab.com o Self-Managed. Preferir auth_mode signing (GitLab 19+) con signing_token whsec_: verifica Standard Webhooks HMAC-SHA256 sobre webhook-id.timestamp.raw-body y tolerancia 300s. Para instalaciones antiguas auth_mode legacy_secret y webhook_secret de al menos 16 caracteres compara X-Gitlab-Token; este mecanismo antiguo es bearer sin integridad del cuerpo. TLS y URL/secreto exclusivos son esenciales. Se valida project_id, tipo de header y object_kind; dedupe durable por hash del cuerpo (eventos idénticos colapsan). No requiere OAuth ni renovación. Sin registro remoto automático. Configurar receptor y workflow activos; no queda activo por crear el nodo.',
                'docs'=>['https://docs.gitlab.com/user/project/integrations/webhooks/','https://docs.gitlab.com/user/project/integrations/webhook_events/']];
        }
        foreach (['create_item','change_column_value'] as $event) {
            $out['monday_'.$event] = [
                'name'=>'Monday.com: '.$event,'node_type'=>'monday','event'=>$event,'transport'=>'webhook','setup_when_disabled'=>true,
                'config_fields'=>[
                    'board_id'=>['type'=>'string','label'=>'ID del tablero','required'=>true],
                    'subscription_id'=>['type'=>'string','label'=>'ID remoto: completar tras handshake, obligatorio en eventos','required'=>false],
                    'account_id'=>['type'=>'string','label'=>'ID de cuenta firmado en JWT','required'=>true]],
                'secret_fields'=>['signing_secret'=>['type'=>'string','label'=>'Signing Secret de app (board webhook)','required'=>true]],
                'help'=>'Guardar borrador, registrar webhook externamente mediante app Monday con webhooks:write (consulta webhooks:read), completar subscription_id y activar receptor. Eventos remotos create_item o change_column_value; payload types create_pulse/update_column_value. Solo board webhooks que incluyen Authorization JWT HS256 firmado con Signing Secret de app; webhooks sin JWT NO se aceptan. JWT valida exp, nbf cuando existe, aud callback exacto y accountId; payload coteja boardId/subscriptionId/tipo. JWT autentica petición, no es HMAC raw-body; secreto y callback exclusivos. Challenge inicial devuelve challenge sin iniciar workflows; no prueba autenticidad. subscription_id se obtiene del registro remoto, editar receptor después del handshake y antes de activarlo. Deduplicación hash del cuerpo firmado por canal JWT y triggerUuid si existe. No renovación JWT local: proveedor emite por entrega; rotar Signing Secret y actualizar credencial. Registro/activar externo pendiente.',
                'docs'=>['https://developer.monday.com/api-reference/reference/webhooks','https://developer.monday.com/apps/docs/integration-authorization']];
        }
        return $out;
    }

    public function poll(string $key,array $config,array $secrets,array $state,int $now): array
    {
        throw new LogicException('Estos eventos utilizan webhooks, no polling.');
    }

    public function verify(string $key,array $config,array $secrets,array $request,int $now): array
    {
        $d=$this->definitions()[$key]??null;$raw=$request['raw_body']??null;$headers=$request['headers']??null;
        if(!$d||($request['method']??'')!=='POST'||!is_string($raw)||strlen($raw)>1048576||!is_array($headers)){throw new InvalidArgumentException('Collaboration: petición webhook inválida.');}
        $body=$this->json($raw);$ack=['status'=>200,'body'=>'','content_type'=>'text/plain','events'=>[]];
        if($d['node_type']==='gitlab'){
            $mode=$config['auth_mode']??'signing';
            if($mode==='signing') {
                $token=$secrets['signing_token']??null; $id=$headers['webhook-id']??null; $timestamp=$headers['webhook-timestamp']??null; $signature=$headers['webhook-signature']??null;
                if(!is_string($token)||!str_starts_with($token,'whsec_')||strlen($token)>4096||!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id)||!is_string($timestamp)||!ctype_digit($timestamp)||strlen($timestamp)>12||abs($now-(int)$timestamp)>300||!is_string($signature)||strlen($signature)>4096) { throw new InvalidArgumentException('GitLab: firma o timestamp inválido.'); }
                $keyBytes=base64_decode(substr($token,6),true);
                if($keyBytes===false||strlen($keyBytes)<16) { throw new InvalidArgumentException('GitLab: signing token inválido.'); }
                $expected='v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$raw,$keyBytes,true)); $valid=false;
                foreach(explode(' ',$signature) as $candidate) { $valid=hash_equals($expected,$candidate)||$valid; }
                if(!$valid) { throw new InvalidArgumentException('GitLab: firma inválida.'); }
            } elseif($mode==='legacy_secret') {
                $secret=$secrets['webhook_secret']??null; $given=$headers['x-gitlab-token']??null;
                if(!is_string($secret)||strlen($secret)<16||strlen($secret)>4096||!is_string($given)||!hash_equals($secret,$given)) { throw new InvalidArgumentException('GitLab: secreto del webhook inválido.'); }
            } else { throw new InvalidArgumentException('GitLab: modo de autenticación inválido.'); }
            $project=$this->id($config['project_id']??null);
            if((string)($body['project']['id']??'')!==$project){throw new InvalidArgumentException('GitLab: proyecto no coincide.');}
            $expected=['push'=>['Push Hook','push'],'issues'=>['Issue Hook','issue'],'pipelines'=>['Pipeline Hook','pipeline']][$d['event']];
            if(($headers['x-gitlab-event']??null)!==$expected[0]||($body['object_kind']??null)!==$expected[1]){throw new InvalidArgumentException('GitLab: evento no coincide con receptor.');}
            $data=['project_id'=>$project];
            if($d['event']==='push'){
                foreach(['ref','before','after'] as $field){$data[$field]=$this->text($body[$field]??null,1024);}
                foreach(['before','after'] as $field){if(!preg_match('/^[a-f0-9]{40,64}$/D',$data[$field])){throw new InvalidArgumentException('GitLab: revisión inválida.');}}
                $data['total_commits_count']=is_int($body['total_commits_count']??null)?$body['total_commits_count']:null;
            }else{
                $attr=$body['object_attributes']??null;if(!is_array($attr)){throw new InvalidArgumentException('GitLab: atributos ausentes.');}
                $data['id']=$this->id($attr['id']??null);
                if($d['event']==='issues'){$data['iid']=$this->id($attr['iid']??null);$data+=$this->pick($attr,['action','state','title','updated_at']);}
                else{$data+=$this->pick($attr,['ref','sha','status','source','created_at','finished_at']);if(!is_string($data['status']??null)){throw new InvalidArgumentException('GitLab: pipeline incompleto.');}}
            }
            $ack['events']=[['id'=>hash('sha256',$raw),'type'=>$d['event'],'data'=>$data]];return $ack;
        }
        // Official URL verification is intentionally an ACK-only unauthenticated handshake.
        if(array_key_exists('challenge',$body)){
            $challenge=$this->text($body['challenge'],1024);$ack['body']=json_encode(['challenge'=>$challenge],JSON_THROW_ON_ERROR);$ack['content_type']='application/json';$ack['challenge']=true;return $ack;
        }
        if (($request['setup_allowed'] ?? false) === true) { throw new InvalidArgumentException('Monday: borrador solo permite challenge.'); }
        $jwt=$headers['authorization']??null;$secret=$secrets['signing_secret']??null;
        if(!is_string($jwt)||strlen($jwt)>16384||!is_string($secret)||strlen($secret)<16||strlen($secret)>4096){throw new InvalidArgumentException('Monday: JWT de app obligatorio.');}
        if(str_starts_with($jwt,'Bearer ')){$jwt=substr($jwt,7);}
        $parts=explode('.',$jwt);if(count($parts)!==3){throw new InvalidArgumentException('Monday: JWT inválido.');}
        $head=$this->json($this->unbase($parts[0]));$claims=$this->json($this->unbase($parts[1]));
        if(($head['alg']??null)!=='HS256'||isset($head['crit'])||!hash_equals(hash_hmac('sha256',$parts[0].'.'.$parts[1],$secret,true),$this->unbase($parts[2]))){throw new InvalidArgumentException('Monday: firma JWT inválida.');}
        $callback=$request['callback_url']??null;$aud=$claims['aud']??null;
        if(!is_int($claims['exp']??null)||$claims['exp']<=$now||$claims['exp']>$now+3600||(isset($claims['nbf'])&&(!is_int($claims['nbf'])||$claims['nbf']>$now+30))||!is_string($callback)||$callback===''||(!is_string($aud)&&!is_array($aud))||(is_string($aud)?!hash_equals($callback,$aud):!in_array($callback,$aud,true))||(string)($claims['accountId']??'')!==$this->id($config['account_id']??null)){throw new InvalidArgumentException('Monday: JWT expirado o audiencia/cuenta incompatible.');}
        $event=$body['event']??null;
        if(!is_array($event)||(string)($event['boardId']??'')!==$this->id($config['board_id']??null)||(string)($event['subscriptionId']??'')!==$this->id($config['subscription_id']??null)||($event['type']??null)!==($d['event']==='create_item'?'create_pulse':'update_column_value')){throw new InvalidArgumentException('Monday: evento, tablero o suscripción incompatible.');}
        $data=['board_id'=>$this->id($event['boardId']),'item_id'=>$this->id($event['pulseId']??$event['itemId']??null)];
        $data+=$this->pick($event,['type','triggerTime','columnId','columnType','pulseName','groupId']);
        // Column values may contain personal data or signed URLs: resolve full item through authenticated action.
        $eventId=$event['triggerUuid']??null;
        $ack['events']=[['id'=>is_string($eventId)&&preg_match('/^[a-zA-Z0-9-]{1,128}$/D',$eventId)?$eventId:hash('sha256',$raw),'type'=>$d['event'],'data'=>$data]];
        return $ack;
    }

    private function json(string $raw): array
    {
        try{$v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new InvalidArgumentException('Webhook: JSON inválido.');}if(!is_array($v)||array_is_list($v)){throw new InvalidArgumentException('Webhook: objeto requerido.');}return $v;
    }
    private function unbase(string $v): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$v)){throw new InvalidArgumentException('Monday: JWT inválido.');}$decoded=base64_decode(strtr($v,'-_','+/'),true);if($decoded===false){throw new InvalidArgumentException('Monday: JWT inválido.');}return $decoded;
    }
    private function id(mixed $v): string
    {
        if((!is_string($v)&&!is_int($v))||!preg_match('/^[1-9][0-9]{0,19}$/D',(string)$v)){throw new InvalidArgumentException('Webhook: ID inválido.');}return (string)$v;
    }
    private function text(mixed $v,int $max): string
    {
        if(!is_string($v)||$v===''||strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v)){throw new InvalidArgumentException('Webhook: texto inválido.');}return $v;
    }
    private function pick(array $body,array $keys): array
    {
        $out=[];foreach($keys as $key){if(array_key_exists($key,$body)&&(is_scalar($body[$key])||$body[$key]===null)){if(is_string($body[$key])&&strlen($body[$key])>4096){throw new InvalidArgumentException('Webhook: metadato excesivo.');}$out[$key]=$body[$key];}}return $out;
    }
}
