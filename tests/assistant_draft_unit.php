<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';require ROOT.'/app/Web.php';
$draftChecks=0;
function dc(bool $ok,string $label):void {global $draftChecks;if(!$ok)throw new \RuntimeException($label);++$draftChecks;}
function dd(callable $fn,int $status):void {try{$fn();}catch(Problem $e){dc($e->status===$status,'expected '.$status.' got '.$e->status.': '.$e->getMessage());return;}throw new \RuntimeException('Expected rejection');}
function draftFixture(string $title,string $body,array $refs):array {return ['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode(['title'=>$title,'body'=>$body,'sources'=>$refs],JSON_THROW_ON_ERROR)]]]]];}
$input=aiDraftInput(['kind'=>'task','assignee'=>'7','due_on'=>'2026-12-01','priority'=>'2']);dc($input['assignee']===7&&$input['priority']===2&&$input['due']==='2026-12-01','typed task controls');
dc(aiDraftInput(['kind'=>'estimate','target'=>'3','amount'=>'123.45'])['amount']===12345,'exact human amount');
dd(fn()=>aiDraftInput(['kind'=>'estimate','target'=>'3','amount'=>'1e3']),422);dd(fn()=>aiDraftInput(['kind'=>'publish']),422);dd(fn()=>aiDraftInput(['kind'=>'task','priority'=>'8']),422);
$ctx=['project_id'=>2,'title'=>'Test','topic'=>'tasks','term'=>'','coverage'=>['tasks'=>['truncated'=>false]],'sources'=>[aiSource('tasks',2,9,'Task',['state'=>'todo'])],'policy'=>['model'=>'test-model']];
$snapshot=['context'=>$ctx,'target'=>['recipient'=>'recipient-never-sent@example.test','before'=>['secret'=>'private-never-sent']]];
$p=aiDraftPayload($snapshot,$input,'Prepare',time());dc($p['store']===false&&!isset($p['tools'])&&$p['text']['format']['strict']===true,'no write tools');dc(!str_contains($p['input'],'never-sent')&&!str_contains($p['input'],'href'),'local target excluded');
$r=aiDraftResponse(draftFixture('New task','Check [T9]',['T9']),$snapshot,$input);dc($r===['title'=>'New task','body'=>'Check [T9]','refs'=>['T9']],'valid typed output');
dd(fn()=>aiDraftResponse(draftFixture('New','Check [T99]',['T99']),$snapshot,$input),503);dd(fn()=>aiDraftResponse(draftFixture('New','Check [T9]',[]),$snapshot,$input),503);dd(fn()=>aiDraftResponse(draftFixture('New','https://evil.test',['T9']),$snapshot,$input),503);dd(fn()=>aiDraftResponse(draftFixture('New','Check',[]),$snapshot,$input),503);
dd(fn()=>aiDraftResponse(draftFixture('New','Price 100 [T9]',['T9']),$snapshot,['kind'=>'estimate']),503);
dc(sensitiveAction('ai.draft.create')&&sensitiveAction('ai.draft.confirm'),'MFA both stages');dc(count(AI_DRAFT_TYPES)===6,'six draft types');
$_SESSION=['csrf'=>'test','mfa_at'=>time()];$u=['id'=>1,'role'=>'owner','name'=>'Fixture','organization_id'=>1];$proposal=['input'=>['kind'=>'letter'],'handle'=>str_repeat('a',32),'expires'=>time()+60];$data=['projects'=>[2=>'Test'],'selected'=>['id'=>2,'title'=>'Test'],'topic'=>'overview','term'=>'','question'=>'','result'=>null,'draftOptions'=>[],'draftError'=>null,'draftReview'=>['proposal'=>$proposal,'target'=>['recipient'=>'<script>','href'=>url('project',['id'=>2]),'before'=>[]],'after'=>['Текст'=>'<script>'],'links'=>$ctx['sources'],'coverage'=>[]]];
ob_start();pageView('assistant','Test',$u,$data);$html=ob_get_clean();dc(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;')&&str_contains($html,'ai.draft.confirm')&&str_contains($html,'name="confirm"'),'escaped review and separate explicit confirmation');
echo "OK · $draftChecks draft unit checks\n";
