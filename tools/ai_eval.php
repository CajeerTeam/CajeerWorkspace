<?php
declare(strict_types=1);
namespace Workspace;
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once ROOT.'/app/Web.php';

// Synthetic role cases, independent of production database and personal data.
function aiEvalCases(): array {
    $rows=[
        ['owner','budget','B1',['currency'=>'EUR','revenue'=>['planned_minor'=>10000,'actual_minor'=>8000,'variance_minor'=>-2000]],'Есть ли отрицательное отклонение выручки?','Отрицательное отклонение выручки; точные значения показаны сервером.'],
        ['manager','blockers','T1',['state'=>'doing','blocked'=>true,'priority'=>1],'Какая задача блокирует выпуск?','Задача заблокирована; нельзя утверждать, что GitHub CI проверен.'],
        ['freelancer','stages','S1',['offer_status'=>'draft','delivery_status'=>null],'Какие согласования ещё требуются?','Предложение не согласовано, приёмка не подтверждена.'],
        ['accountant','discrepancies','I1',['currency'=>'EUR','outstanding_minor'=>15000,'pending_receipt_minor'=>0],'Что требует сверки?','Есть непогашенный остаток; точная сумма показана сервером.'],
        ['legal','revisions','C1',['previous_revision'=>1,'latest_revision'=>2,'changes'=>['document_hash'=>true]],'Что изменилось в договоре?','Изменился хеш редакции; содержание PDF неизвестно.'],
    ];$cases=[];
    foreach($rows as [$role,$topic,$ref,$fields,$question,$rubric]){
        $kind=$topic==='blockers'?'tasks':$topic;
        $source=['ref'=>$ref,'kind'=>$kind,'title'=>'Synthetic object','fields'=>$fields,'href'=>'./?p=project&id=1'];
        $context=['project_id'=>1,'title'=>'Synthetic evaluation','topic'=>$topic,'term'=>'','role'=>$role==='freelancer'?'owner':$role,'coverage'=>[$kind=>['count'=>1,'truncated'=>false,'selection'=>'prioritized_sample']],'sources'=>[$source]];
        $cases[]=['id'=>$role.'.positive','role'=>$role,'context'=>$context,'question'=>$question,'expected'=>[$ref],'rubric'=>$rubric];
        // Missing data case: model must not invent another object or a result.
        $context['sources']=[];$context['coverage'][$kind]['count']=0;
        $cases[]=['id'=>$role.'.missing','role'=>$role,'context'=>$context,'question'=>$question,'expected'=>[],'rubric'=>'Отметить отсутствие сведений, не придумывать результат.'];
    }return $cases;
}
function aiEvalScore(array $case,array $raw): array {
    $answer=aiResponse($raw,$case['context']);$actual=array_column($answer['links'],'ref');sort($actual);$expected=$case['expected'];sort($expected);
    // Financial links are server-enforced; measure model-supplied refs separately.
    $modelRefs=[];foreach($raw['output'] as $item)foreach($item['content']??[] as $part)if(($part['type']??null)==='output_text')$modelRefs=json_decode($part['text'],true,16,JSON_THROW_ON_ERROR)['sources'];sort($modelRefs);
    return ['references_ok'=>$actual===$expected&&$modelRefs===$expected,'answer'=>$answer['answer']];
}
function aiEvalRun(string $model,string $key,bool $show,?callable $transport=null): array {
    $results=[];$price=aiPrice($model);$roles=[];foreach(aiEvalCases() as $c){$start=hrtime(true);$r=['case'=>$c['id'],'role'=>$c['role'],'references_ok'=>false,'status'=>'failed','semantic_review'=>'required'];$usage=[null,null,null];
        try{$raw=($transport??__NAMESPACE__.'\\aiHttp')(aiPayload($c['context'],$c['question'],$model,time()),$key);$usage=aiUsage($raw,$price);$score=aiEvalScore($c,$raw);$r['references_ok']=$score['references_ok'];$r['status']='ok';if($show)$r+=['answer'=>$score['answer'],'rubric'=>$c['rubric']];}
        catch(\Throwable){/* do not print provider response, credentials or exception payload */}
        $r+=['latency_ms'=>(int)((hrtime(true)-$start)/1000000),'input_tokens'=>$usage[0],'output_tokens'=>$usage[1],'cost_micro'=>$usage[2]];$results[]=$r;
        $a=&$roles[$c['role']];$a??=['cases'=>0,'references_ok'=>0,'failures'=>0];$a['cases']++;$a['references_ok']+=(int)$r['references_ok'];$a['failures']+=(int)($r['status']!=='ok');unset($a);
        if($r['status']!=='ok'&&$transport===null)break; // manual retry, never automatically duplicate a paid request
    }return ['model'=>$model,'evaluated'=>count($results),'expected_cases'=>count(aiEvalCases()),'roles'=>$roles,'cases'=>$results,'semantic_acceptance'=>'manual_required'];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    try{if(PHP_SAPI!=='cli'||!in_array('--live',$argv,true)||!isset($argv[1])||str_starts_with($argv[1],'--'))throw new Problem('Usage: php tools/ai_eval.php MODEL --live [--show-answers]');$cfg=aiConfig();if(!in_array($argv[1],aiModels($cfg),true))throw new Problem('Model not allowed');$r=aiEvalRun($argv[1],$cfg['key'],in_array('--show-answers',$argv,true));echo json_encode($r,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;exit(count($r['cases'])===10&&count(array_filter($r['cases'],fn($c)=>$c['references_ok']))===10?0:1);}catch(\Throwable){fwrite(STDERR,"Evaluation unavailable; check arguments and AI configuration.\n");exit(1);}
}
