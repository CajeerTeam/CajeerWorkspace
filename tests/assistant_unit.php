<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';require ROOT.'/app/Web.php';
$n=0;
function aiCheck(bool $ok,string $why): void {global $n;if(!$ok)throw new \RuntimeException($why);++$n;}
function aiDeny(callable $fn,int $status): void {try{$fn();}catch(Problem $e){aiCheck($e->status===$status,'expected status '.$status.', got '.$e->status);return;}throw new \RuntimeException('Expected rejection');}
function aiFixture(string $answer,array $refs): array {return ['status'=>'completed','output'=>[['type'=>'reasoning'],['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode(['answer'=>$answer,'sources'=>$refs],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]]]]];}
putenv('WORKSPACE_AI_ENABLED=0');aiDeny(fn()=>aiConfig(),503);
putenv('WORKSPACE_AI_ENABLED=1');putenv('WORKSPACE_OPENAI_API_KEY=');putenv('WORKSPACE_OPENAI_MODEL=gpt-4.1-mini');putenv('WORKSPACE_AI_PROJECT_IDS=2,3');aiDeny(fn()=>aiConfig(),503);
putenv('WORKSPACE_OPENAI_API_KEY=test-only-key-123456');aiCheck(aiConfig()['projects']===[2=>true,3=>true],'explicit allowlist');aiCheck(aiProjectLinkEnabled(2)&&!aiProjectLinkEnabled(1),'project entry point restricted');
foreach(['0','1, 2','2,','-2','92233720368547758080'] as $bad){putenv('WORKSPACE_AI_PROJECT_IDS='.$bad);aiDeny(fn()=>aiConfig(),503);}putenv('WORKSPACE_AI_PROJECT_IDS=2,3');
putenv('WORKSPACE_OPENAI_MODEL=https://evil.test');aiDeny(fn()=>aiConfig(),503);putenv('WORKSPACE_OPENAI_MODEL=gpt-4.1-mini');
putenv("WORKSPACE_OPENAI_API_KEY=bad\r\nAuthorization: secret");aiDeny(fn()=>aiConfig(),503);putenv('WORKSPACE_OPENAI_API_KEY=test-only-key-123456');
$sources=[aiSource('tasks',2,9,'<script>test</script>',['state'=>'todo']),aiSource('milestones',2,10,'Milestone',['state'=>'open']),aiSource('releases',2,11,'v1',['verified_at'=>gmdate('c',1000),'ci'=>'historical_success'])];
aiCheck($sources[0]['href']===url('task',['id'=>9]),'task link');aiCheck($sources[1]['href']===url('project',['id'=>2,'milestone'=>10]).'#milestone-10','milestone link');aiCheck($sources[2]['href']===url('releases',['project'=>2,'id'=>11]),'release link');
$c=['project_id'=>2,'title'=>'Test','topic'=>'overview','term'=>'','coverage'=>['tasks'=>['truncated'=>true]],'sources'=>$sources];
$p=aiPayload($c,'Question','gpt-4.1-mini',1100);$input=json_decode($p['input'],true,16,JSON_THROW_ON_ERROR);aiCheck($p['store']===false&&!isset($p['tools'],$p['previous_response_id'])&&$p['max_output_tokens']===1800,'stateless without tools');aiCheck($p['text']['format']['strict']===true,'structured output');aiCheck(!str_contains($p['input'],'href')&&!str_contains($p['input'],'./?')&&!str_contains($p['input'],'test-only-key'),'no URLs/key in payload');aiCheck($input['objects'][2]['fields']['ci_observation_fresh']===true,'fresh local observation');aiCheck(json_decode(aiPayload($c,'Question','gpt-4.1-mini',2000)['input'],true)['objects'][2]['fields']['ci_observation_fresh']===false,'old observation');
$r=aiResponse(aiFixture('Need [T9] and [R11]',['T9','R11']),$c);aiCheck($r['links']===[$sources[0],$sources[2]],'server-only links');
foreach([aiFixture('unknown',['T99']),aiFixture('https://evil.test',['T9']),aiFixture('[fake](./?p=task&id=99)',['T9']),aiFixture('unknown [M88]',['T9']),aiFixture('duplicate',['T9','T9']),aiFixture('inconsistent [T9]',[]),aiFixture('',[]),['status'=>'incomplete','output'=>[]],['status'=>'completed','output'=>[['type'=>'function_call']]],['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>'bad JSON']]]]]] as $bad)aiDeny(fn()=>aiResponse($bad,$c),503);
aiDeny(fn()=>aiResponse(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'refusal','refusal'=>'not available']]]]],$c),422);
aiCheck(aiResponse(aiFixture('Not enough data',[]),$c)['links']===[],'explicit uncertainty');aiCheck(aiText(str_repeat('я',300))===str_repeat('я',240),'UTF8 bound');
$big=$c;$big['title']=str_repeat('a',65537);aiDeny(fn()=>aiPayload($big,'q','test',1000),422);
$_SESSION=['csrf'=>'test'];$u=['id'=>1,'role'=>'owner','name'=>'Fixture','organization_id'=>1];$x=aiResponse(aiFixture('<script>alert(1)</script> [T9]',['T9']),$c)+['coverage'=>$c['coverage'],'observed'=>1100];
$x['coverage']['tasks']=['count'=>1,'selection'=>'prioritized_sample','truncated'=>true];ob_start();pageView('assistant','Test',$u,['projects'=>[2=>'Test'],'selected'=>['id'=>2,'title'=>'Test'],'topic'=>'overview','term'=>'','question'=>'<script>','result'=>$x]);$html=ob_get_clean();aiCheck(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,'href="https://evil.test')&&str_contains($html,'href="./?p=task&amp;id=9"'),'escaped answer and server link');
foreach(['WORKSPACE_AI_ENABLED','WORKSPACE_OPENAI_API_KEY','WORKSPACE_OPENAI_MODEL','WORKSPACE_AI_PROJECT_IDS'] as $name)putenv($name);
echo "OK · $n assistant unit checks\n";
