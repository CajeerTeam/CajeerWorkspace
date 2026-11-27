<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// Operator-supplied USD microdollars/token (round rates up), never silently assume a zero price.
function aiPrice(string $model): ?array {
    $raw=getenv('WORKSPACE_AI_PRICES');if($raw===false||$raw==='')return null;
    try{$all=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Problem('Некорректная таблица цен AI.',503);}
    if(!is_array($all))throw new Problem('Некорректная таблица цен AI.',503);
    $p=$all[$model]??null;if($p===null)return null;
    if(!is_array($p)||!array_is_list($p)||count($p)!==2||!is_int($p[0])||!is_int($p[1])||min($p)<0||max($p)>100000)throw new Problem('Некорректная цена модели AI.',503);
    return $p;
}
function aiCostCap(): ?int {
    $v=getenv('WORKSPACE_AI_DAILY_MICRO_USD');if($v===false||$v==='')return null;
    if(!asciiDigits($v)||strlen($v)>12)throw new Problem('Некорректный денежный лимит AI.',503);return (int)$v;
}
function aiUsage(array $raw,?array $price): array {
    $u=$raw['usage']??null;$i=$u['input_tokens']??null;$o=$u['output_tokens']??null;
    if(!is_int($i)||!is_int($o)||min($i,$o)<0||max($i,$o)>10000000)return [null,null,null];
    return [$i,$o,$price===null?null:$i*$price[0]+$o*$price[1]];
}
function aiRetrySeconds(?string $value,int $now): int {
    if($value!==null&&asciiDigits($value)&&strlen($value)<=6)return max(1,min(3600,(int)$value));
    $t=$value===null?false:strtotime($value);return $t===false?60:max(1,min(3600,$t-$now));
}
final class AiProviderFailure extends \RuntimeException {
    public function __construct(public readonly string $outcome, public readonly int $http,public readonly int $retry=0){parent::__construct($outcome);}
}
function aiOpsStart(PDO $db,array $u,int $p,string $role,string $op,array $payload): array {
    $project=allowedProject($db,$u,$p);if(projectRole($db,$u,$p)!==$role)throw new Problem('Роль изменилась.',409);if($role==='owner'&&$project['kind']==='client')$role='freelancer';
    $price=aiPrice($payload['model']);$cap=aiCostCap();$now=time();$org=(int)$u['organization_id'];
    // Conservative reservation: serialized input bytes + framing allowance, max generated tokens.
    $reserve=$price===null?null:(strlen(json_encode($payload,JSON_THROW_ON_ERROR))+4096)*$price[0]+(int)$payload['max_output_tokens']*$price[1];
    $id=bin2hex(random_bytes(16));
    atomic($db,function()use($db,$u,$p,$role,$op,$payload,$cap,$reserve,$now,$org,$id){
        query($db,"UPDATE ai_calls SET status='failed',finished_at=? WHERE status='running' AND started_at<?",[$now,$now-180]);
        if(query($db,"SELECT 1 FROM ai_calls WHERE user_id=? AND status='running'",[$u['id']])->fetchColumn())throw new Problem('У вас уже выполняется запрос AI.',409);
        if(query($db,'SELECT 1 FROM ai_calls WHERE organization_id=? AND model=? AND retry_until>?',[$org,$payload['model'],$now])->fetchColumn())throw new Problem('OpenAI ограничил запросы. Дождитесь окончания паузы.',429);
        if($cap!==null){
            if($reserve===null)throw new Problem('Для модели не задана цена.',503);
            $day=intdiv($now,86400)*86400;
            if(query($db,'SELECT 1 FROM ai_calls WHERE organization_id=? AND started_at>=? AND reserved_micro IS NULL',[$org,$day])->fetchColumn())throw new Problem('Есть вызовы с неизвестной стоимостью; лимит не может быть проверен.',429);
            $used=(int)query($db,'SELECT COALESCE(SUM(COALESCE(cost_micro,reserved_micro)),0) FROM ai_calls WHERE organization_id=? AND started_at>=?',[$org,$day])->fetchColumn();
            if($used>$cap||$reserve>$cap-$used)throw new Problem('Дневной денежный лимит AI исчерпан.',429);
        }
        query($db,"INSERT INTO ai_calls(handle,organization_id,user_id,project_id,role,model,operation,started_at,status,reserved_micro) VALUES(?,?,?,?,?,?,?,?,'running',?)",[$id,$org,$u['id'],$p,$role,$payload['model'],$op,$now,$reserve]);
    });return [$id,$price];
}
function aiOpsCancelled(PDO $db,string $id): bool {
    $r=query($db,'SELECT status,cancel_requested FROM ai_calls WHERE handle=?',[$id])->fetch();return !$r||$r['status']!=='running'||(int)$r['cancel_requested']===1;
}
function aiOpsRun(PDO $db,array $u,int $p,string $role,string $op,array $payload,string $key,?callable $transport,callable $validate): array {
    [$id,$price]=aiOpsStart($db,$u,$p,$role,$op,$payload);$start=hrtime(true);$usage=[null,null,null];$status='failed';$retry=null;
    $pollAt=0.0;$cancelled=false;
    $cancel=function()use($db,$id,&$pollAt,&$cancelled):bool{if(!$cancelled&&microtime(true)>=$pollAt){$pollAt=microtime(true)+0.4;$cancelled=aiOpsCancelled($db,$id);}return $cancelled;};
    try{
        $open=session_status()===PHP_SESSION_ACTIVE;if($open)session_write_close();
        try{$raw=($transport??__NAMESPACE__.'\\aiHttp')($payload,$key,$cancel);}finally{if($open&&!session_start())throw new Problem('Сессия недоступна.',403);}
        $usage=aiUsage($raw,$price);
        if(aiOpsCancelled($db,$id))throw new AiProviderFailure('cancelled',409);
        $status='rejected';$result=$validate($raw);
        // Serialize final cancellation with the operator's cancel request.
        atomic($db,function()use($db,$id,$usage,$start){if(aiOpsCancelled($db,$id))throw new AiProviderFailure('cancelled',409);aiOpsFinish($db,$id,'ok',$usage,$start,null);});
        $status='ok';return $result+['call_id'=>$id];
    }catch(AiProviderFailure $e){$status=$e->outcome;$retry=$e->retry?time()+$e->retry:null;throw new Problem(match($status){'cancelled'=>'Запрос AI отменён.','timeout'=>'Истёк тайм-аут OpenAI. Повторите вручную.','rate_limited'=>'OpenAI ограничил запросы; повторите после паузы.',default=>'OpenAI недоступен.'},$e->http);}
    finally{if($status!=='ok')aiOpsFinish($db,$id,$status,$usage,$start,$retry);}
}
function aiOpsFinish(PDO $db,string $id,string $status,array $usage,int $start,?int $retry): void {
    query($db,"UPDATE ai_calls SET status=?,finished_at=?,latency_ms=?,input_tokens=?,output_tokens=?,cost_micro=?,retry_until=? WHERE handle=? AND status='running'",[$status,time(),(int)((hrtime(true)-$start)/1000000),...$usage,$retry,$id]);
}
function aiOpsAction(PDO $db,array $u,string $act,string $id,mixed $rating=null): void {
    if(!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new Problem('Некорректный вызов AI.');
    $u=organizationUser($db,$u);atomic($db,function()use($db,$u,$act,$id,$rating){
        $r=entity($db,'SELECT * FROM ai_calls WHERE handle=? AND organization_id=? AND user_id=?',[$id,$u['organization_id'],$u['id']]);allowedProject($db,$u,(int)$r['project_id']);
        if($act==='ai.cancel')query($db,"UPDATE ai_calls SET cancel_requested=1 WHERE handle=? AND status='running'",[$id]);
        else{if(!in_array($rating,['0','1'],true)||$r['status']!=='ok')throw new Problem('Оценка недоступна.',409);query($db,'UPDATE ai_calls SET quality=? WHERE handle=?',[(int)$rating,$id]);}
    });
}
function aiOpsReport(PDO $db,array $u): array {
    $u=organizationUser($db,$u);$org=(int)$u['organization_id'];$since=time()-30*86400;
    $own=query($db,'SELECT handle,project_id,model,operation,status,started_at,latency_ms,input_tokens,output_tokens,cost_micro,reserved_micro,quality,cancel_requested,retry_until FROM ai_calls WHERE organization_id=? AND user_id=? AND started_at>=? ORDER BY started_at DESC,handle LIMIT 30',[$org,$u['id'],$since])->fetchAll();
    $own=array_values(array_filter($own,function($r)use($db,$u){try{allowedProject($db,$u,(int)$r['project_id']);return true;}catch(Problem){return false;}}));
    $roles=[];if($u['role']==='owner'&&mfaFresh())foreach(query($db,'SELECT role,status,latency_ms,cost_micro,reserved_micro,quality FROM ai_calls WHERE organization_id=? AND started_at>=? ORDER BY started_at DESC LIMIT 30000',[$org,$since])->fetchAll() as $r){$k=$r['role'];$roles[$k]??=['calls'=>0,'ok'=>0,'rated'=>0,'positive'=>0,'latencies'=>[],'known_micro'=>0,'unknown'=>0];$a=&$roles[$k];$a['calls']++;$a['ok']+=(int)($r['status']==='ok');if($r['quality']!==null){$a['rated']++;$a['positive']+=(int)$r['quality'];}if($r['latency_ms']!==null)$a['latencies'][]=(int)$r['latency_ms'];if($r['cost_micro']===null)$a['unknown']++;else $a['known_micro']+=(int)$r['cost_micro'];unset($a);}
    foreach($roles as &$a){sort($a['latencies']);$n=count($a['latencies']);$a['p95']=$n?$a['latencies'][(int)ceil(.95*$n)-1]:null;unset($a['latencies']);}unset($a);
    return ['own'=>$own,'roles'=>$roles,'cap'=>aiCostCap()];
}
function aiOpsPurge(PDO $db): void {query($db,'DELETE FROM ai_calls WHERE started_at<?',[time()-30*86400]);query($db,'DELETE FROM ai_delegations WHERE expires_at<=?',[time()]);}
