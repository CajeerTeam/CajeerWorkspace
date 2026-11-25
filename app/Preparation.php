<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const PREP_KINDS=['contract'=>'Договор услуг','nda'=>'NDA','specification'=>'Техническое задание','acceptance'=>'Акт','amendment'=>'Дополнительное соглашение'];
const PREP_STATES=['draft'=>'Черновик','legal_review'=>'У юриста','approval'=>'У уполномоченного лица','approved'=>'Согласован','changes'=>'На исправлении','cancelled'=>'Отменён'];
const PREP_FIELDS=['number','date','supplier','customer','subject','terms'];
function prepPreset(string $kind): string {
    choice($kind,PREP_KINDS);
    $head=PREP_KINDS[$kind]." № {{number}}\nДата: {{date}}\nСторона 1: {{supplier}}\nСторона 2: {{customer}}\n";
    return $head.match($kind){
        'contract'=>"1. Предмет услуг: {{subject}}\n2. Стоимость, сроки, порядок оплаты и приёмки: {{terms}}\n3. Изменения, ответственность и прекращение: по согласованным сторонами условиям.\n4. Представители и подписи сторон: __________________",
        'nda'=>"1. Конфиденциальная информация и цель раскрытия: {{subject}}\n2. Допустимое использование, исключения, срок защиты, возврат и уничтожение: {{terms}}\n3. Представители и подписи сторон: __________________",
        'specification'=>"1. Цель и объём работ: {{subject}}\n2. Требования, ограничения, этапы, результаты и критерии приёмки: {{terms}}\n3. Согласование сторон: __________________",
        'acceptance'=>"1. Основание и переданные результаты: {{subject}}\n2. Период, стоимость, замечания и условия приёмки: {{terms}}\n3. Подтверждение сторон: __________________",
        'amendment'=>"1. Исходный договор и изменяемые положения: {{subject}}\n2. Новая редакция условий и дата вступления в силу: {{terms}}\n3. Прочие условия исходного договора сохраняются, если стороны не согласовали иное.\n4. Представители и подписи сторон: __________________"};
}
function prepText(mixed $raw,int $max=16000): string {
    if(!is_string($raw)||strlen($raw)>$max*4||!preg_match('//u',$raw))throw new Problem('Некорректный текст.');
    $s=trim(str_replace(["\r\n","\r"],"\n",$raw));
    if($s===''||preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$s)||preg_match_all('/./us',$s)>$max||substr_count($s,"\n")>=250)throw new Problem('Проверьте текст (до '.$max.' символов и 250 строк).');
    return $s;
}
function prepTemplateText(mixed $raw): string {
    $s=prepText($raw);preg_match_all('/\{\{([a-z_]+)\}\}/',$s,$m);
    if(array_diff($m[1],PREP_FIELDS)||array_diff(PREP_FIELDS,$m[1])||preg_match('/[{}]/',preg_replace('/\{\{[a-z_]+\}\}/','',$s)))throw new Problem('Шаблон должен содержать все шесть допустимых полей и не содержать иных фигурных скобок.');
    return $s;
}
function prepRender(string $template,array $f): string {
    $map=[];foreach(PREP_FIELDS as $k){$v=$k==='date'?calendarDate($f[$k]??''):prepText($f[$k]??'',4000);if(str_contains($v,'{{')||str_contains($v,'}}'))throw new Problem('Вложенные поля запрещены.');$map['{{'.$k.'}}']=$v;}
    return prepText(strtr(prepTemplateText($template),$map));
}
function prepGet(PDO $db,array $u,int $p,int $id,string $right='legal'): array {requireProjectRight($db,$u,$p,$right);return entity($db,'SELECT * FROM preparations WHERE project_id=? AND id=?',[$p,$id]);}
function prepLatest(PDO $db,int $id): array {return entity($db,'SELECT * FROM preparation_revisions WHERE preparation_id=? ORDER BY revision DESC LIMIT 1',[$id]);}
function prepTemplate(PDO $db,int $p,int $id): array { $t=entity($db,'SELECT * FROM preparation_templates WHERE project_id=? AND id=?',[$p,$id]);if(!hash_equals($t['sha256'],hash('sha256',$t['body'])))throw new Problem('Повреждён шаблон.',409);return $t; }
function prepActorRole(PDO $db,int $org,int $p,int $uid,string $role): bool {
    try{$u=organizationUser($db,['id'=>$uid],$org);$r=projectRole($db,$u,$p);return match($role){'editor'=>roleAllows($r,'legal.write'),'lawyer'=>$r==='legal','approver'=>$u['role']==='owner',default=>false};}catch(Problem $e){if(in_array($e->status,[403,404],true))return false;throw $e;}
}
function prepRoute(PDO $db,array $u,int $p,int $e,int $l,int $a): void {
    if(count(array_unique([$e,$l,$a]))!==3)throw new Problem('Редактор, юрист и уполномоченное лицо должны быть разными пользователями.');
    foreach(['editor'=>$e,'lawyer'=>$l,'approver'=>$a] as $role=>$id)if(!prepActorRole($db,(int)$u['organization_id'],$p,$id,$role))throw new Problem('Недействующие полномочия: '.$role,403);
}
function prepEvidence(array $t,string $body,?array $pdf): string {
    return hash('sha256',json_encode([(int)$t['id'],(int)$t['revision'],$t['sha256'],$body,$pdf?[(int)$pdf['id'],(int)$pdf['revision'],$pdf['sha256']]:null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function prepPdf(PDO $db,int $p,string $kind,string $pair): array {
    $d=contractPdf($db,$p,$pair);if($d['kind']!==($kind==='specification'?'other':$kind))throw new Problem('Тип PDF не соответствует типу подготовки.');contractCheckPdf($db,$d);return $d;
}
function prepVerify(PDO $db,array $r,bool $required=true): void {
    $t=prepTemplate($db,(int)$r['project_id'],(int)$r['template_id']);$d=null;
    if($r['document_id']!==null){$d=prepPdf($db,(int)$r['project_id'],$r['kind'],$r['document_id'].':'.$r['document_revision']);if(!hash_equals($r['document_hash'],$d['sha256']))throw new Problem('PDF изменён.',409);}
    elseif($required)throw new Problem('Прикрепите точную редакцию PDF.',409);
    if(!hash_equals($r['evidence_hash'],prepEvidence($t,$r['body'],$d)))throw new Problem('Снимок редакции повреждён.',409);
}
function prepRevision(PDO $db,array $c,array $t,string $body,?array $pdf,int $n,int $uid): int {
    if($t['kind']!==$c['kind'])throw new Problem('Тип шаблона не соответствует документу.');
    return insertId($db,'INSERT INTO preparation_revisions(project_id,preparation_id,kind,revision,template_id,body,document_id,document_revision,document_hash,evidence_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$c['project_id'],$c['id'],$c['kind'],$n,$t['id'],$body,$pdf['id']??null,$pdf['revision']??null,$pdf['sha256']??null,prepEvidence($t,$body,$pdf),$uid,time()]);
}
function prepRouteSnapshot(array $c): string {return json_encode([(int)$c['editor_id'],(int)$c['lawyer_id'],(int)$c['approver_id']],JSON_THROW_ON_ERROR);}
function prepEvent(PDO $db,array $c,array $r,string $decision,int $actor,string $note): void {
    query($db,'INSERT INTO preparation_events(project_id,preparation_id,revision_id,decision,evidence_hash,actor_id,route_snapshot,note,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$c['project_id'],$c['id'],$r['id'],$decision,$r['evidence_hash'],$actor,prepRouteSnapshot($c),$note,time()]);
}
function prepCommand(PDO $db,array $user,int $p,string $key,string $act,array $f): int {
    $u=organizationUser($db,$user);requireProjectRight($db,$u,$p,'legal.write');
    $fields=match($act){'prep.template'=>['kind','body','expected'],'prep.create'=>['template_id','title','lawyer_id','approver_id',...PREP_FIELDS],'prep.revise'=>['id','version','body','document'],'prep.assign'=>['id','version','editor_id','lawyer_id','approver_id','note'],'prep.submit','prep.legal','prep.approve','prep.return','prep.cancel'=>['id','version','note'],default=>throw new Problem('Неизвестная операция.')};
    $data=[];foreach($fields as $k){$v=$f[$k]??'';if(!is_string($v)&&!is_int($v))throw new Problem('Некорректная форма.');$data[$k]=(string)$v;}$f=$data;
    if(in_array($act,['prep.template','prep.assign','prep.approve','prep.cancel'],true))orgOwner($db,$u);
    return action($db,$u,$key,$act,[$p,$f],function()use($db,$u,$p,$act,$f){
        $u=organizationUser($db,$u);requireProjectRight($db,$u,$p,'legal.write');$uid=(int)$u['id'];
        if($act==='prep.template'){
            orgOwner($db,$u);$kind=choice($f['kind'],PREP_KINDS);$body=prepTemplateText($f['body']);$n=(int)query($db,'SELECT COALESCE(MAX(revision),0) FROM preparation_templates WHERE project_id=? AND kind=?',[$p,$kind])->fetchColumn();
            if((string)$n!==$f['expected'])throw new Problem('Шаблон изменился. Обновите страницу.',409);
            return insertId($db,'INSERT INTO preparation_templates(project_id,kind,revision,body,sha256,created_by,created_at) VALUES(?,?,?,?,?,?,?)',[$p,$kind,$n+1,$body,hash('sha256',$body),$uid,time()]);
        }
        if($act==='prep.create'){
            $t=prepTemplate($db,$p,numberId($f['template_id']));if((int)$t['revision']!==(int)query($db,'SELECT MAX(revision) FROM preparation_templates WHERE project_id=? AND kind=?',[$p,$t['kind']])->fetchColumn())throw new Problem('Выберите актуальный шаблон.',409);
            $l=numberId($f['lawyer_id']);$a=numberId($f['approver_id']);prepRoute($db,$u,$p,$uid,$l,$a);$body=prepRender($t['body'],$f);
            $id=insertId($db,'INSERT INTO preparations(project_id,kind,title,editor_id,lawyer_id,approver_id,created_at) VALUES(?,?,?,?,?,?,?)',[$p,$t['kind'],word($f['title'],150,'Название'),$uid,$l,$a,time()]);$c=prepGet($db,$u,$p,$id,'legal.write');prepRevision($db,$c,$t,$body,null,1,$uid);prepEvent($db,$c,prepLatest($db,$id),'create',$uid,'Создано из шаблона');return $id;
        }
        $id=numberId($f['id']);$c=prepGet($db,$u,$p,$id,'legal.write');$r=prepLatest($db,$id);$state=$c['state'];
        if((int)$c['version']!==numberId($f['version']))throw new Problem('Карточка изменилась.',409);
        if($state==='cancelled')throw new Problem('Карточка отменена.',409);
        $editor=$uid===(int)$c['editor_id'];$lawyer=$uid===(int)$c['lawyer_id'];$approver=$uid===(int)$c['approver_id'];
        if($act==='prep.assign'){
            orgOwner($db,$u);if($state==='approved')throw new Problem('Согласованная редакция неизменяема; сначала создайте новую.',409);
            $e=numberId($f['editor_id']);$l=numberId($f['lawyer_id']);$a=numberId($f['approver_id']);prepRoute($db,$u,$p,$e,$l,$a);
            // A new revision invalidates every old review, including when only the route changes.
            $t=prepTemplate($db,$p,(int)$r['template_id']);prepRevision($db,$c,$t,$r['body'],null,(int)$r['revision']+1,$uid);$r=prepLatest($db,$id);
            query($db,'UPDATE preparations SET editor_id=?,lawyer_id=?,approver_id=? WHERE id=?',[$e,$l,$a,$id]);$c=prepGet($db,$u,$p,$id,'legal.write');$next='draft';$decision='assign';$note=prepText($f['note'],2000);
        }elseif($act==='prep.cancel'){
            orgOwner($db,$u);if($state==='approved')throw new Problem('Согласованную редакцию нельзя отменить.',409);$next='cancelled';$decision='cancel';$note=prepText($f['note'],2000);
        }else{
            prepRoute($db,$u,$p,(int)$c['editor_id'],(int)$c['lawyer_id'],(int)$c['approver_id']);
            if($act==='prep.revise'){
                if(!$editor)throw new Problem('Только назначенный редактор.',403);if(!in_array($state,['draft','changes','approved'],true))throw new Problem('Сначала верните документ на исправление.',409);
                $t=prepTemplate($db,$p,(int)$r['template_id']);$body=prepText($f['body']);$pdf=$f['document']===''?null:prepPdf($db,$p,$c['kind'],$f['document']);
                prepRevision($db,$c,$t,$body,$pdf,(int)$r['revision']+1,$uid);$r=prepLatest($db,$id);$next='draft';$decision='revise';$note='Новая редакция; предыдущие решения не переносятся';
            }else{
                $note=prepText($f['note'],2000);
                if($act==='prep.submit'){
                    if(!$editor)throw new Problem('Только назначенный редактор.',403);if($state!=='draft')throw new Problem('Создайте исправленную редакцию перед отправкой.',409);prepVerify($db,$r);$next='legal_review';$decision='submit';
                }elseif($act==='prep.legal'){
                    if(!$lawyer)throw new Problem('Только назначенный юрист.',403);if($state!=='legal_review')throw new Problem('Нет редакции на юридической проверке.',409);prepVerify($db,$r);$next='approval';$decision='legal_accept';
                }elseif($act==='prep.approve'){
                    orgOwner($db,$u);if(!$approver)throw new Problem('Только назначенное уполномоченное лицо.',403);if($state!=='approval')throw new Problem('Требуется проверка юриста.',409);prepVerify($db,$r);
                    $ev=entity($db,"SELECT * FROM preparation_events WHERE revision_id=? AND decision='legal_accept' ORDER BY id DESC LIMIT 1",[$r['id']]);if((int)$ev['actor_id']!==(int)$c['lawyer_id']||!hash_equals($r['evidence_hash'],$ev['evidence_hash'])||!hash_equals(prepRouteSnapshot($c),$ev['route_snapshot']))throw new Problem('Согласование юриста не соответствует редакции.',409);$next='approved';$decision='approve';
                }else{
                    if(!(($state==='legal_review'&&$lawyer)||($state==='approval'&&$approver)))throw new Problem('Нет полномочий на возврат на этом этапе.',403);$next='changes';$decision='return';
                }
            }
        }
        prepEvent($db,$c,$r,$decision,$uid,$note);query($db,'UPDATE preparations SET state=?,version=version+1 WHERE id=?',[$next,$id]);return $id;
    });
}
// Bounded line LCS; preserves unchanged lines and both sides of replacements.
function prepDiff(string $old,string $new): array {
    $a=explode("\n",prepText($old));$b=explode("\n",prepText($new));$n=count($a);$m=count($b);$d=array_fill(0,$n+1,array_fill(0,$m+1,0));
    for($i=$n-1;$i>=0;$i--)for($j=$m-1;$j>=0;$j--)$d[$i][$j]=$a[$i]===$b[$j]?1+$d[$i+1][$j+1]:max($d[$i+1][$j],$d[$i][$j+1]);
    $out=[];$i=0;$j=0;while($i<$n||$j<$m){if($i<$n&&$j<$m&&$a[$i]===$b[$j]){$out[]=[' ',$a[$i]];$i++;$j++;}elseif($j===$m||($i<$n&&$d[$i+1][$j]>=$d[$i][$j+1]))$out[]=['-',$a[$i++]];else $out[]=['+',$b[$j++]];}return $out;
}
function prepPage(PDO $db,array $u,?int $p,?int $id,?int $compare,?int $before): array {
    $projects=rightProjects($db,$u,'legal');$data=['projects'=>$projects,'selected'=>$p,'card'=>null,'templates'=>[],'cards'=>[]];if(!$p)return $data;requireProjectRight($db,$u,$p,'legal');
    $data['writable']=roleAllows(projectRole($db,$u,$p),'legal.write');$data['templates']=query($db,'SELECT t.* FROM preparation_templates t WHERE project_id=? AND revision=(SELECT MAX(x.revision) FROM preparation_templates x WHERE x.project_id=t.project_id AND x.kind=t.kind) ORDER BY kind',[$p])->fetchAll();
    $data['cards']=query($db,'SELECT * FROM preparations WHERE project_id=? AND id<? ORDER BY id DESC LIMIT 51',[$p,$before??PHP_INT_MAX])->fetchAll();
    $data['actors']=[];foreach(orgMembers($db,$u) as $m)foreach(['editor','lawyer','approver'] as $role)if(prepActorRole($db,(int)$u['organization_id'],$p,(int)$m['id'],$role))$data['actors'][$role][$m['id']]=$m['name'];
    if(!$id)return $data;$c=prepGet($db,$u,$p,$id);$r=prepLatest($db,$id);$data['card']=$c;$data['latest']=$r;$data['template']=prepTemplate($db,$p,(int)$r['template_id']);
    $data['history']=query($db,'SELECT id,revision,document_id,document_revision,document_hash,evidence_hash,created_at FROM preparation_revisions WHERE preparation_id=? ORDER BY revision DESC LIMIT 250',[$id])->fetchAll();
    $data['events']=query($db,'SELECT e.*,r.revision,u.name actor_name FROM preparation_events e JOIN preparation_revisions r ON r.id=e.revision_id JOIN users u ON u.id=e.actor_id WHERE e.preparation_id=? ORDER BY e.id DESC LIMIT 250',[$id])->fetchAll();
    $data['pdfs']=query($db,'SELECT d.id,d.title,v.revision FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? AND d.kind=? ORDER BY d.id DESC,v.revision DESC LIMIT 250',[$p,$c['kind']==='specification'?'other':$c['kind']])->fetchAll();
    $data['diff']=null;if($compare){$old=entity($db,'SELECT * FROM preparation_revisions WHERE preparation_id=? AND revision=?',[$id,$compare]);$data['diff']=prepDiff($old['body'],$r['body']);$data['compare']=$old;}
    return $data;
}
function prepActs(PDO $db,array $u,?int $p,?int $before): array {
    $projects=rightProjects($db,$u,'finance');$rows=[];if($p){requireProjectRight($db,$u,$p,'finance');$rows=query($db,"SELECT c.id,c.title,r.revision,r.document_hash FROM preparations c JOIN preparation_revisions r ON r.preparation_id=c.id WHERE c.project_id=? AND c.kind='acceptance' AND c.state='approved' AND c.id<? AND r.revision=(SELECT MAX(x.revision) FROM preparation_revisions x WHERE x.preparation_id=c.id) ORDER BY c.id DESC LIMIT 51",[$p,$before??PHP_INT_MAX])->fetchAll();}return ['projects'=>$projects,'selected'=>$p,'rows'=>$rows];
}
function prepApprovedAct(PDO $db,array $u,int $p,int $id): array {
    $c=prepGet($db,$u,$p,$id,'finance');if($c['kind']!=='acceptance'||$c['state']!=='approved')throw new Problem('Принятый акт не найден.',404);$r=prepLatest($db,$id);prepVerify($db,$r);$e=entity($db,"SELECT * FROM preparation_events WHERE revision_id=? AND decision='approve' ORDER BY id DESC LIMIT 1",[$r['id']]);if((int)$e['actor_id']!==(int)$c['approver_id']||!hash_equals(prepRouteSnapshot($c),$e['route_snapshot'])||!hash_equals($e['evidence_hash'],$r['evidence_hash']))throw new Problem('Согласование не соответствует акту.',409);return $r;
}
