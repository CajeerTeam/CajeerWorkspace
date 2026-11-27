<?php
declare(strict_types=1);

namespace Workspace;

use PDO;

const PAGE_TITLES = ['lead'=>'Рабочее место тимлида','assistant'=>'AI-ассистент проекта','overview'=>'Рабочий обзор','projects'=>'Проекты','project'=>'Проект','finance'=>'Финансы','documents'=>'Документы','document'=>'История документа','connections'=>'Подключения','team'=>'Команда','login'=>'Вход в Workspace','invite'=>'Принять приглашение','search'=>'Поиск','notifications'=>'Напоминания','digest'=>'Сводка владельцу','audit'=>'Аудит','payments'=>'Согласование платежей','security'=>'Безопасность','task'=>'Задача','workload'=>'Спринты и время','clients'=>'Клиенты и заказы','oss_maintenance'=>'Сопровождение OSS','oss'=>'Open-source workflow','github'=>'GitHub App','delivery'=>'Карточка поставки и QA','releases'=>'Выпуски проекта','infrastructure'=>'Инфраструктура проекта','deployments'=>'Управляемое развёртывание','invoices'=>'Счета и задолженность','accounting'=>'Бухгалтерский журнал','bank'=>'Банковская сверка','economics'=>'Экономика проекта','tax'=>'UK VAT','contracts'=>'Карточки договоров','preparation'=>'Подготовка документов','approved_acts'=>'Согласованные акты','signing'=>'Подписание и архив','retention'=>'Хранение и выдача','engine'=>'Сайты и публикации Engine','book'=>'Книги и документация','forum'=>'Обсуждения Forum','lite'=>'Сайты EngineLite','editorial'=>'Редакторские выпуски','logs'=>'Журналы Logs','bots'=>'Доставка Bots','incidents'=>'Инциденты и дежурства','reliability'=>'Надёжность интеграций'];
const PAGE_RIGHTS = ['finance'=>'finance','export'=>'finance','documents'=>'legal','document'=>'legal','download'=>'legal','connections'=>'connections','team'=>'people','audit'=>'audit','payments'=>'finance','invoices'=>'finance','economics'=>'finance','tax.print'=>'finance','contracts'=>'legal','preparation'=>'legal','prep.print'=>'legal','approved_acts'=>'finance','prep.act'=>'finance','signing'=>'legal','sign.download'=>'legal','retention'=>'legal','engine'=>'connections','book'=>'connections','book.export'=>'connections','forum'=>'connections','lite'=>'connections','editorial'=>'connections','logs'=>'connections','logs.export'=>'connections','bots'=>'connections','incidents'=>'connections'];

function h(mixed $value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function url(string $page, array $params = []): string {
    return './?'.http_build_query(['p'=>$page]+$params,'','&',PHP_QUERY_RFC3986);
}

function go(string $page, array $params = []): never {
    header('Location: '.url($page,$params),true,303);
    exit;
}

function secureHeaders(): void {
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

function sessionOpen(): void {
    $path = dataDir().'/sessions';
    if (!is_dir($path) && !@mkdir($path,0700) && !is_dir($path)) throw new \RuntimeException('Недоступен каталог сессий.');
    session_name('cajeerws');
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.gc_maxlifetime','43200');
    session_save_path($path);
    $secure = oidcEnabled() || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || getenv('WORKSPACE_HTTPS')==='1';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) throw new \RuntimeException('Не удалось открыть сессию.');
    if(isset($_SESSION['ai_draft'])&&(!aiEnabled()||($_SESSION['ai_draft']['expires']??0)<=time()))unset($_SESSION['ai_draft']);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function killLogin(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function currentUser(PDO $db): ?array {
    if (empty($_SESSION['uid'])) return null;
    if (!in_array($_SESSION['auth_kind']??null,['local','emergency','oidc'],true)) { invalidateStaffLogin($db); return null; }
    $now = time();
    $u = query($db,'SELECT id,name,email,role,session_version FROM users WHERE id=? AND enabled=1',[$_SESSION['uid']])->fetch();
    if (!$u || (int)$u['session_version'] !== $_SESSION['v'] || $now-$_SESSION['seen']>1800 || $now-$_SESSION['born']>43200) {
        invalidateStaffLogin($db); return null;
    }
    $kind=$_SESSION['auth_kind'];
    if (($kind==='local' && !localAuthEnabled()) || ($kind==='oidc' && !oidcEnabled()) || ($kind==='emergency' && (installationAuthPolicy()!=='required' || !emergencyAllowed($db,(int)$u['id'])))) { invalidateStaffLogin($db); return null; }
    if (!staffSessionValid($db,$u,true)) { invalidateStaffLogin($db); return null; }
    $u['auth_kind']=$kind;
    $_SESSION['seen'] = $now;
    $orgs=organizationsFor($db,(int)$u['id']);
    if (!$orgs) return array_replace($u,['organization_id'=>null,'organization_name'=>'Нет организации','organizations'=>[],'role'=>'viewer']);
    $selected=(int)($_SESSION['org']??0);
    if (!in_array($selected,array_map('intval',array_column($orgs,'id')),true)) $selected=firstAuthOrganization($db,$orgs,$kind,(int)$u['id']);
    if ($selected===null || !organizationAuthAllowed($db,$selected,$kind,(int)$u['id'])) { invalidateStaffLogin($db); return null; }
    $_SESSION['org']=$selected;
    return organizationUser($db,$u,$selected)+['organizations'=>$orgs];
}

function formFields(string $act): void {
    echo '<input type="hidden" name="act" value="',h($act),'"><input type="hidden" name="csrf" value="',h($_SESSION['csrf']),'">';
    echo '<input type="hidden" name="request_key" value="',bin2hex(random_bytes(16)),'">';
}

function selectOptions(array $options, mixed $selected = null): void {
    foreach ($options as $key=>$value) echo '<option value="',h($key),'"',((string)$key === (string)$selected?' selected':''),'>',h($value),'</option>';
}

function displayDate(?string $value): string {
    return $value ? implode('.',array_reverse(explode('-',substr($value,0,10)))) : '—';
}

function pageView(string $view, string $title, ?array $user = null, array $data = []): void {
    extract($data, EXTR_SKIP);
    require ROOT.'/views/frame.php';
}

function handlePost(PDO $db, ?array $user): never {
    if (!isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) throw new Problem('Срок действия формы истёк. Обновите страницу.',403);
    $act = word($_POST['act'] ?? '',32,'Действие');
    if (isset($_SESSION['oss_contributor'])) {
        if ($act==='oss.logout') { killLogin(); go('oss.join'); }
        if (!in_array($act,['oss.submit','oss.reply'],true)) throw new Problem('Действие недоступно contributor.',403);
        $c=ossSession($db); authThrottle($db,'oss.write:'.$c['id'],60);
        $id=ossExternal($db,(int)$c['id'],$_SESSION['oss_grant'],word($_POST['request_key']??'',32,'Форма'),$act,$_POST);
        go('oss.contributor',['id'=>$id]);
    }
    if ($act==='oss.join') {
        if ($user || !empty($_SESSION['guest_id'])) throw new Problem('Выйдите из текущей учётной записи.',403);
        authThrottle($db,'oss.join:'.($_SERVER['REMOTE_ADDR']??''),12);
        $c=ossRedeem($db,$_POST['token']??''); killLogin();
        $_SESSION['oss_contributor']=(int)$c['id']; $_SESSION['oss_grant']=$c['grant']; $_SESSION['oss_seen']=time(); go('oss.contributor');
    }
    if (!empty($_SESSION['guest_id'])) {
        if ($act==='logout') authLogout($db);
        if (in_array($act,['guest.offer','guest.delivery'],true)) {
            if (($_SESSION['auth_kind']??null)!=='oidc') throw new Problem('Требуется вход ID.',403);
            clientGuestDecision($db,(int)$_SESSION['guest_id'],$act,numberId($_POST['id']??0),numberId($_POST['version']??0),choice($_POST['decision']??'',['accept'=>'','return'=>''])==='accept',word($_POST['note']??'',1000,'Комментарий',false),hash('sha256',$_SESSION['oidc_handle']));
            $_SESSION['notice']='Решение сохранено.'; go('guest');
        }
        throw new Problem('Действие недоступно в гостевом кабинете.',403);
    }
    if (in_array($act,['oidc.begin','guest.begin','staff.begin'],true)) {
        if ($user || !oidcEnabled()) throw new Problem('Вход ID недоступен.',403);
        authThrottle($db,'oidc:'.($_SERVER['REMOTE_ADDR']??''),40);
        $hash=null;
        if ($act!=='oidc.begin') {
            $token=word($_POST['token']??'',64,'Код');
            if (!preg_match('/\A[a-f0-9]{64}\z/',$token)) throw new Problem('Недействительное приглашение.',404);
            $hash=hash('sha256',$token);
            $table=$act==='guest.begin'?'guest_invites':'invitations';
            entity($db,"SELECT id FROM $table WHERE token_hash=? AND expires_at>? AND accepted_at IS NULL AND revoked_at IS NULL",[$hash,time()]);
        }
        oidcBegin($act==='guest.begin'?$hash:null,$act==='staff.begin'?$hash:null);
    }
    if ($act==='login') {
        if ($user) go('overview');
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $u = authenticate($db,$email,$password,$_SERVER['REMOTE_ADDR'] ?? '');
        if (!$u || (!localAuthEnabled() && !emergencyAllowed($db,(int)$u['id']))) throw new Problem('Проверьте почту и пароль либо используйте SkiF4er ID.',401);
        $kind=localAuthEnabled()?'local':'emergency';
        $orgs=organizationsFor($db,(int)$u['id']); $org=firstAuthOrganization($db,$orgs,$kind,(int)$u['id']);
        if ($orgs && $org===null) throw new Problem('Для ваших организаций требуется вход через SkiF4er ID.',401);
        killLogin();
        $handle=bin2hex(random_bytes(32));
        atomic($db,fn()=>staffSessionCreate($db,$u,$kind,$handle));
        $_SESSION += ['staff_handle'=>$handle,'uid'=>(int)$u['id'],'v'=>(int)$u['session_version'],'born'=>time(),'seen'=>time(),'auth_kind'=>$kind,'org'=>$org];
        if ($kind==='emergency') { try { $_SESSION['auth_evidence_pending']=['config'=>authConfigFingerprint()]; } catch (\Throwable) {} }
        go($kind==='emergency'?'security':'overview');
    }
    if ($act==='invite.accept') {
        if (!localAuthEnabled() && !$user) throw new Problem('Войдите через SkiF4er ID. Новую внутреннюю учётную запись привязывает оператор.',403);
        if (($_SESSION['auth_kind']??null)==='emergency' && !($_SESSION['emergency_verified']??false)) throw new Problem('Подтвердите второй фактор.',403);
        $result=acceptInvitation($db,word($_POST['token']??'',64,'Код'),$user, is_string($_POST['name']??null)?$_POST['name']:'',is_string($_POST['password']??null)?$_POST['password']:'');
        if (!$user) { go('login'); }
        $_SESSION['org']=$result['organization_id']; unset($_SESSION['oss_token'],$_SESSION['invite_token'],$_SESSION['guest_token'],$_SESSION['guest_token_project'],$_SESSION['client_token'],$_SESSION['client_token_order'],$_SESSION['retention_link']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('overview');
    }
    if (!$user) go('login');
    if ($act==='logout') authLogout($db);
    if (in_array($act,['mfa.enroll','mfa.verify'],true)) { verifyMfa($db,$user,word($_POST['code']??'',6,'Код'),$act==='mfa.enroll'); $_SESSION['notice']='Второй фактор подтверждён на 5 минут.'; go('security'); }
    if (($_SESSION['auth_kind']??null)==='emergency' && !($_SESSION['emergency_verified']??false)) throw new Problem('Подтвердите второй фактор.',403);
    requireMfa($act);
    if ($act==='workspace.update.check') { $u=organizationUser($db,$user); requireUpdateAdmin($u); unset($_SESSION['workspace_update_report']); go('workspace_updates'); }
    if ($act==='identity.link') selfLinkBegin($db,$user,$_POST['password']??null);
    if ($act==='identity.unlink') { selfUnlink($db,$user,$_POST['password']??null); killLogin(); $_SESSION['notice']='ID отвязан. Войдите заново.'; go('login'); }
    if ($act==='identity.revoke') { $current=selfRevokeSession($db,$user,word($_POST['handle']??'',64,'Сессия')); if ($current) { killLogin(); go('login'); } $_SESSION['notice']='Сессия отозвана.'; go('security'); }
    if ($act==='org.switch') { $u=organizationUser($db,$user,numberId($_POST['org']??0),false);
        if (!organizationAuthAllowed($db,(int)$u['organization_id'],$user['auth_kind'],(int)$user['id'])) {
            if ($user['auth_kind']==='local' && oidcEnabled()) {
                if (!query($db,'SELECT 1 FROM oidc_identities WHERE user_id=? AND issuer=?',[$user['id'],ID_ISSUER])->fetchColumn()) throw new Problem('Для перехода в эту организацию оператор должен привязать ваш SkiF4er ID.',403);
                authThrottle($db,'oidc:'.($_SERVER['REMOTE_ADDR']??''),40);
                oidcBegin(null,null,['uid'=>(int)$user['id'],'version'=>(int)$user['session_version'],'org'=>(int)$u['organization_id']]);
            }
            throw new Problem('Организация требует вход через SkiF4er ID.',403);
        }
        unset($_SESSION['oidc_tx'],$_SESSION['ai_draft']); $_SESSION['org']=$u['organization_id']; unset($_SESSION['oss_token'],$_SESSION['invite_token'],$_SESSION['guest_token'],$_SESSION['guest_token_project'],$_SESSION['client_token'],$_SESSION['client_token_order'],$_SESSION['retention_link']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('overview'); }
    $user=organizationUser($db,$user);
    $key = word($_POST['request_key'] ?? '',32,'Форма');
    $projectId = static fn() => numberId($_POST['project_id'] ?? 0);
    switch ($act) {
        case 'ai.cancel':
        case 'ai.rate':
            aiOpsAction($db,$user,$act,word($_POST['handle']??'',32,'Вызов'),$_POST['quality']??null);go('assistant');
        case 'ai.chatgpt.policy':
            aiMcpSetPolicy($db,$user,($_POST['enabled']??'')==='1');go('assistant');
        case 'ai.chatgpt.revoke':
            aiMcpRevoke($db,$user);go('assistant');
        case 'ai.policy':
            aiPolicySave($db,$user,$_POST,aiConfig());unset($_SESSION['ai_draft']);go('assistant');
        case 'ai.draft.create':
        case 'ai.draft.confirm':
        case 'ai.draft.cancel':
            $pid=$projectId();$cfg=aiConfig();aiProject($db,$user,$pid,$cfg);
            if($act==='ai.draft.cancel'){
                $proposal=$_SESSION['ai_draft']??null;
                if(is_array($proposal)&&$proposal['handle']===($_POST['handle']??null)&&$proposal['project']===$pid&&$proposal['org']===(int)$user['organization_id']&&$proposal['user']===(int)$user['id'])unset($_SESSION['ai_draft']);
                go('assistant',['project'=>$pid]);
            }
            if(($_SESSION['auth_kind']??null)==='oidc'&&!validateOidcSession($db))throw new Problem('Сессия ID больше не подтверждена.',403);
            $recheck=static function()use($db):array{
                $fresh=currentUser($db);if(!$fresh||empty($fresh['organization_id'])||(($fresh['auth_kind']??null)==='emergency'&&!($_SESSION['emergency_verified']??false)))throw new Problem('Сессия больше не действует.',403);
                requireIdentitySession($db,$fresh);return $fresh;
            };
            if($act==='ai.draft.create'){
                if(($_POST['consent']??'')!=='yes')throw new Problem('Подтвердите передачу выбранного контекста в OpenAI.',422);
                unset($_SESSION['ai_draft']);
                $checkProvider=static function()use($db,$recheck):array{if(($_SESSION['auth_kind']??null)==='oidc'&&!validateOidcSession($db))throw new Problem('Сессия ID больше не подтверждена.',403);return $recheck();};
                $_SESSION['ai_draft']=aiDraftCreate($db,$user,$pid,$_POST,$cfg,$checkProvider);
                go('assistant',['project'=>$pid]);
            }
            $proposal=$_SESSION['ai_draft']??null;
            if(!is_array($proposal)||!is_string($_POST['handle']??null)||!hash_equals($proposal['handle'],$_POST['handle'])||($_POST['confirm']??'')!=='yes')throw new Problem('Нет подтверждённого предпросмотра. Создайте новый черновик.',409);
            $result=aiDraftConfirm($db,$user,$pid,$proposal,$cfg,$recheck);unset($_SESSION['ai_draft']);
            if($result['text']!==null){header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="workspace-'.$result['kind'].'-'.$pid.'.txt"');header('Cache-Control: private, no-store');echo $result['text'];exit;}
            $_SESSION['notice']='Черновик применён.';header('Location: '.$result['href'],true,303);exit;
        case 'ai.ask':
            $cfg=aiConfig();$pid=$projectId();$topic=choice($_POST['topic']??'',AI_TOPICS);$term=word($_POST['term']??'',120,'Ключевые слова',false);$question=word($_POST['question']??'',1200,'Вопрос');
            if(($_POST['consent']??'')!=='yes')throw new Problem('Подтвердите передачу выбранного контекста в OpenAI.',422);
            $freshUser=$user;
            $recheck=static function()use($db,&$freshUser):array{
                if(($_SESSION['auth_kind']??null)==='oidc'&&!validateOidcSession($db))throw new Problem('Сессия ID больше не подтверждена.',403);
                $fresh=currentUser($db);if(!$fresh||empty($fresh['organization_id'])||(($fresh['auth_kind']??null)==='emergency'&&!($_SESSION['emergency_verified']??false)))throw new Problem('Сессия больше не действует.',403);
                return $freshUser=$fresh;
            };
            $answer=aiAsk($db,$user,$pid,$topic,$term,$question,$cfg,$recheck);
            $fresh=$freshUser;$projects=aiProjects($db,$fresh,$cfg);$selected=aiProject($db,$fresh,$pid,$cfg);
            pageView('assistant',PAGE_TITLES['assistant'],$fresh,['projects'=>$projects,'selected'=>$selected,'topic'=>$topic,'term'=>$term,'question'=>$question,'result'=>$answer,'policy'=>aiPolicy($db,(int)$fresh['organization_id'],$cfg),'models'=>aiModels($cfg),'topics'=>aiTopicsFor($db,$fresh,$pid,aiPolicy($db,(int)$fresh['organization_id'],$cfg)),'history'=>[],'ops'=>aiOpsReport($db,$fresh)]);exit;
        case 'reliability.reconcile':
            $pid=$projectId();$result=relReconcile($db,$user,$pid);$_SESSION['notice']='Сверка поставлена в очередь: '.json_encode($result,JSON_THROW_ON_ERROR);go('reliability',['project'=>$pid]);
        case 'reliability.verify':
            $pid=$projectId();$status=relLogsVerify($db,$user,$pid,numberId($_POST['id']??0));$_SESSION['notice']=$status==='delivered'?'Событие найдено и подтверждено.':'Событие не найдено; это не доказывает отсутствие доставки.';go('reliability',['project'=>$pid]);
        case 'reliability.retry':
            $pid=$projectId();relRetry($db,$user,$pid,choice($_POST['provider']??'',REL_PROVIDERS),numberId($_POST['id']??0));$_SESSION['notice']='Задание поставлено на безопасную обработку.';go('reliability',['project'=>$pid]);
        case 'reminder.subscribe':
            $pid=$projectId();$lead=(int)choice($_POST['lead']??'',array_combine(array_map('strval',range(0,90)),range(0,90)));reminderSubscribe($db,$user,$pid,word($_POST['kind']??'',32,'Тип'),$lead,choice($_POST['assigned']??'',['0'=>'','1'=>''])==='1',choice($_POST['enable']??'',['0'=>'','1'=>''])==='1',mfaFresh());go('notifications',['project'=>$pid]);
        case 'reminder.mark':
            reminderMark($db,$user,numberId($_POST['subscription']??null),numberId($_POST['object']??null),word($_POST['deadline']??'',32,'Срок'),choice($_POST['read']??'',['0'=>'','1'=>''])==='1',mfaFresh(),time());go('notifications');
        case 'incident.policy':
            $pid=$projectId();incidentConfigure($db,$user,$pid,numberId($_POST['responsible_id']??null),numberId($_POST['minutes']??null),choice($_POST['active']??'',['1'=>'','0'=>''])==='1');go('incidents',['project'=>$pid]);
        case 'incident.scan':
            $pid=$projectId();$result=incidentScan($db,$user,$pid);$_SESSION['notice']='Новых сигналов: '.$result['accepted'].'. Проверок инфраструктуры: '.$result['infra_checks'].($result['errors']?' · ошибки '.implode(', ',$result['errors']):'').($result['logs_truncated']?' · выборка Logs неполная':'');go('incidents',['project'=>$pid]);
        case 'incident.assign': case 'incident.link': case 'incident.ack': case 'incident.resolve':
            $pid=$projectId();$id=numberId($_POST['id']??null);incidentCommand($db,$user,$pid,$id,numberId($_POST['version']??null),substr($act,9),$_POST);go('incidents',['project'=>$pid,'id'=>$id]);
        case 'bots.binding':
            $pid=$projectId();if(($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите привязку.');botsConfigure($db,$user,$pid,choice($_POST['active']??'',['1'=>'on','0'=>'off'])==='1');go('bots',['project'=>$pid]);
        case 'bots.draft':
            $pid=$projectId();$id=botsDraft($db,$user,$pid,$key,optionalId($_POST['release_id']??''),$_POST['message']??null);go('bots',['project'=>$pid,'id'=>$id]);
        case 'bots.confirm': case 'bots.retry': case 'bots.cancel': case 'bots.check': case 'bots.close':
            $pid=$projectId();$id=numberId($_POST['id']??0);if(($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите действие.');botsCommand($db,$user,$pid,$id,substr($act,5),word($_POST['preview_hash']??'',64,'Снимок'));go('bots',['project'=>$pid,'id'=>$id]);
        case 'logs.policy':
            $pid=$projectId();if(($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите политику.');
            logsConfigure($db,$user,$pid,numberId($_POST['days']??0),choice($_POST['active']??'', ['1'=>'on','0'=>'off'])==='1');go('logs',['project'=>$pid]);
        case 'editorial.create':
            $pid=$projectId();$form=$_POST;$parts=explode(':',word($_POST['page_choice']??'',1200,'Страница'),2);if(count($parts)!==2)throw new Problem('Выберите страницу.');$form['edition_id']=$parts[0];$form['page_key']=$parts[1];
            if(($_POST['cms']??'')==='lite'){$parts=explode(':',word($_POST['lite_target']??'',100,'Цель'),2);if(count($parts)!==2)throw new Problem('Выберите сайт Lite.');$form['lite_site_id']=$parts[0];$form['lite_hash']=$parts[1];}
            $id=editorialCreate($db,$user,$pid,$key,$form);go('editorial',['project'=>$pid,'id'=>$id]);
        case 'editorial.cms': case 'editorial.forum': case 'editorial.retry': case 'editorial.abandon':
            $pid=$projectId();$id=numberId($_POST['id']??0);if(($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите действие.');
            if($act==='editorial.cms')editorialCms($db,$user,$pid,$id);elseif($act==='editorial.abandon')editorialAbandon($db,$user,$pid,$id);else editorialForum($db,$user,$pid,$id,$act==='editorial.retry'?'retry':'advance',word($_POST['attempt_key']??'',32,'Ключ попытки'));go('editorial',['project'=>$pid,'id'=>$id]);
        case 'lite.bind':
            $pid=$projectId();$sid=liteBind($db,$user,$pid,word($_POST['target']??'',48,'Цель Lite'),word($_POST['name']??'',120,'Сайт'));go('lite',['project'=>$pid,'site'=>$sid]);
        case 'lite.toggle':
            $pid=$projectId();$sid=numberId($_POST['site_id']??0);liteToggle($db,$user,$pid,$sid,choice($_POST['active']??'', ['0'=>'off','1'=>'on'])==='1');go('lite',['project'=>$pid,'site'=>$sid]);
        case 'lite.queue':
            $pid=$projectId();$sid=numberId($_POST['site_id']??0);liteQueue($db,$user,$pid,$sid,$key,$_POST);go('lite',['project'=>$pid,'site'=>$sid]);
        case 'lite.restore': case 'lite.cancel':
            $pid=$projectId();$sid=numberId($_POST['site_id']??0);if($act==='lite.restore'&&($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите восстановление.');liteCommand($db,$user,$pid,$sid,numberId($_POST['job_id']??0),$act==='lite.restore'?'restore':'cancel');go('lite',['project'=>$pid,'site'=>$sid]);
        case 'forum.bind':
            $pid=$projectId();$sid=forumBind($db,$user,$pid,word($_POST['connection']??'',48,'Подключение'),forumId($_POST['board']??null));go('forum',['project'=>$pid,'source'=>$sid]);
        case 'forum.toggle':
            $pid=$projectId();forumToggle($db,$user,$pid,numberId($_POST['source_id']??0),choice($_POST['active']??'', ['0'=>'off','1'=>'on'])==='1');go('forum',['project'=>$pid]);
        case 'forum.attach': case 'forum.create':
            $pid=$projectId();$sid=numberId($_POST['source_id']??0);$kind=choice($_POST['kind']??'', ['task'=>'task','release'=>'release']);$target=numberId($_POST['target']??0);
            $topic=$act==='forum.attach'?forumAttach($db,$user,$pid,$sid,$kind,$target,forumId($_POST['thread']??null)):forumCreate($db,$user,$pid,$sid,$kind,$target,$key,word($_POST['title']??'',180,'Название'),forumBody($_POST['body']??null));go('forum',['project'=>$pid,'source'=>$sid,'topic'=>$topic]);
        case 'forum.action': case 'forum.reconcile':
            $pid=$projectId();$sid=numberId($_POST['source_id']??0);$topic=numberId($_POST['topic_id']??0);
            if($act==='forum.reconcile')forumReconcile($db,$user,$pid,$sid,$topic);else forumAction($db,$user,$pid,$sid,$topic,$key,word($_POST['operation']??'',16,'Операция'),$_POST);
            go('forum',['project'=>$pid,'source'=>$sid,'topic'=>$topic,'n'=>numberId($_POST['page']??1)]);
        case 'book.bind':
            $pid=$projectId();$id=bookBind($db,$user,$pid,word($_POST['connection']??'',48,'Подключение'),bookScope($_POST['scope']??null),word($_POST['name']??'',120,'Книга'));go('book',['project'=>$pid,'source'=>$id]);
        case 'book.toggle':
            $pid=$projectId();$id=numberId($_POST['source_id']??0);bookToggle($db,$user,$pid,$id,choice($_POST['active']??'', ['0'=>'off','1'=>'on'])==='1');go('book',['project'=>$pid,'source'=>$id]);
        case 'book.capture':
            $pid=$projectId();$id=bookCapture($db,$user,$pid,numberId($_POST['source_id']??0),$key,$_POST);go('book',['project'=>$pid,'edition'=>$id]);
        case 'book.review': case 'book.release':
            $pid=$projectId();$id=numberId($_POST['edition_id']??0);$hash=word($_POST['hash']??'',64,'SHA-256');
            if($act==='book.review')bookReview($db,$user,$pid,$id,$hash,choice($_POST['decision']??'', ['approve'=>'approve','changes'=>'changes']),word($_POST['note']??'',1000,'Комментарий'));
            else bookLinkRelease($db,$user,$pid,$id,numberId($_POST['release_id']??0),$hash);
            go('book',['project'=>$pid,'edition'=>$id]);
        case 'engine.bind':
            $pid=$projectId();$site=engineBind($db,$user,$pid,word($_POST['connection']??'',48,'Подключение'),word($_POST['name']??'',120,'Сайт'));go('engine',['project'=>$pid,'site'=>$site]);
        case 'engine.toggle':
            $pid=$projectId();$site=numberId($_POST['site_id']??0);engineToggle($db,$user,$pid,$site,choice($_POST['active']??'', ['0'=>'off','1'=>'on'])==='1');go('engine',['project'=>$pid]);
        case 'engine.prepare':
            $pid=$projectId();$id=enginePrepare($db,$user,$pid,numberId($_POST['site_id']??0),engineSegment($_POST['type']??null),engineSegment($_POST['entry']??null),numberId($_POST['revision']??0),word($_POST['hash']??'',64,'SHA-256'));go('engine',['project'=>$pid,'publication'=>$id]);
        case 'engine.confirm': case 'engine.reconcile': case 'engine.cancel':
            $pid=$projectId();$id=numberId($_POST['publication']??0);
            if($act==='engine.confirm'){if(($_POST['confirm']??'')!=='yes')throw new Problem('Подтвердите публикацию.');engineConfirm($db,$user,$pid,$id,word($_POST['hash']??'',64,'SHA-256'));}
            elseif($act==='engine.reconcile')engineReconcile($db,$user,$pid,$id);else engineCancel($db,$user,$pid,$id);
            go('engine',['project'=>$pid,'publication'=>$id]);
        case 'retention.policy': case 'retention.assign': case 'retention.hold': case 'retention.release': case 'retention.offload': case 'retention.restore': case 'retention.purge': case 'retention.link': case 'retention.revoke':
            orgOwner($db,$user);if($act==='retention.policy'){retentionPolicy($db,$user,$_POST);go('retention');}
            $doc=numberId($_POST['document_id']??0);$rev=numberId($_POST['revision']??0);$package=optionalId($_POST['package_id']??'');
            match($act){'retention.assign'=>retentionAssign($db,$user,$doc,$rev,numberId($_POST['policy_id']??0),calendarDate($_POST['starts_on']??'')), 'retention.hold'=>retentionHold($db,$user,$doc,$rev,word($_POST['reason']??'',1000,'Основание')), 'retention.release'=>retentionHold($db,$user,$doc,$rev,word($_POST['reason']??'',1000,'Основание'),numberId($_POST['hold_id']??0)), 'retention.offload','retention.restore'=>retentionMove($db,$user,$doc,$rev,$act==='retention.restore',$package), 'retention.purge'=>($_POST['confirm']??'')==='yes'?retentionPurge($db,$user,$doc,$rev):throw new Problem('Подтвердите удаление.'), 'retention.revoke'=>retentionRevoke($db,$user,$doc,$rev,numberId($_POST['link_id']??0)), 'retention.link'=>$_SESSION['retention_link']=['token'=>retentionLink($db,$user,$doc,$rev,$package,numberId($_POST['minutes']??0),numberId($_POST['uses']??0),$key),'org'=>(int)$user['organization_id'],'doc'=>$doc,'rev'=>$rev,'actor'=>(int)$user['id']]};
            go('retention',['id'=>$doc,'v'=>$rev]);
        case 'sign.create':
            $pid=$projectId();$id=signCreate($db,$user,$pid,$key,$_POST,signConfig());$_SESSION['notice']='Пакет поставлен в очередь.';go('signing',['project'=>$pid,'id'=>$id]);
        case 'sign.cancel': case 'sign.sync': case 'sign.adopt':
            $pid=$projectId();$id=numberId($_POST['id']??0);orgOwner($db,$user);signGet($db,$user,$pid,$id);
            if($act==='sign.cancel')signCancel($db,$user,$pid,$id);else { $cfg=signConfig(); if($cfg['org']!==(int)$user['organization_id'])throw new Problem('Провайдер не подключён.',403); $result=signProcess($db,$id,$cfg,$act==='sign.adopt'?'adopt':'sync',$act==='sign.adopt'?signId($_POST['remote_id']??null):null);$_SESSION['notice']=SIGN_STATES[$result]; }go('signing',['project'=>$pid,'id'=>$id]);
        case 'prep.template': case 'prep.create': case 'prep.revise': case 'prep.assign': case 'prep.submit': case 'prep.legal': case 'prep.approve': case 'prep.return': case 'prep.cancel':
            $pid=$projectId();$id=prepCommand($db,$user,$pid,$key,$act,$_POST);$_SESSION['notice']='Операция сохранена.';go('preparation',$act==='prep.template'?['project'=>$pid]:['project'=>$pid,'id'=>$id]);
        case 'contract.create': case 'contract.revise': case 'contract.attach': case 'contract.detach': case 'contract.activate': case 'contract.obligation': case 'contract.decide':
            $pid=$projectId();$cid=contractCommand($db,$user,$pid,$key,$act,$_POST);$_SESSION['notice']='Карточка договора сохранена.';go('contracts',['project'=>$pid,'id'=>$cid]);
        case 'econ.settings': case 'econ.budget': case 'econ.rate': case 'econ.class': case 'econ.plan': case 'econ.allocate': case 'econ.cancel': case 'econ.reschedule': case 'econ.unallocate':
            $pid=$projectId();$month=econMonth($_POST['report_month']??gmdate('Y-m'));$horizon=calendarDate($_POST['horizon']??gmdate('Y-m-d',time()+90*86400));
            econCommand($db,$user,$pid,$key,$act,$_POST);$_SESSION['notice']='Экономика проекта: изменения сохранены.';go('economics',['project'=>$pid,'month'=>substr($month,0,7),'horizon'=>$horizon]);
        case 'bank.account': case 'bank.rate': case 'bank.import': case 'bank.match': case 'bank.unmatch': case 'bank.reconcile':
            $form=$_POST;
            if($act==='bank.import' && isset($_FILES['statement_file']) && $_FILES['statement_file']['error']!==UPLOAD_ERR_NO_FILE){
                accountingUser($db,$user,true);$upload=$_FILES['statement_file'];
                if($upload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']) || $upload['size']>1048576 || trim((string)($form['csv']??''))!=='')throw new Problem('Загрузите один CSV до 1 МиБ или вставьте текст.');
                $form['csv']=file_get_contents($upload['tmp_name'],false,null,0,1048577);
            }
            $bid=bankCommand($db,$user,$key,$act,$form);$params=[];
            if($act==='bank.account')$params=['account'=>$bid];elseif($act==='bank.import')$params=['statement'=>$bid];elseif(in_array($act,['bank.match','bank.unmatch'],true))$params=['statement'=>(int)bankRow($db,(int)$user['organization_id'],$bid)['statement_id'],'row'=>$bid,'filter'=>'all'];elseif($act==='bank.reconcile')$params=['statement'=>numberId($form['id'])];
            $_SESSION['notice']='Банковская операция сохранена.';go('bank',$params);
        case 'accounting.init': case 'accounting.account': case 'accounting.period': case 'accounting.period_edit': case 'accounting.prepare': case 'accounting.approve': case 'accounting.post': case 'accounting.reverse': case 'accounting.close':
            accountingCommand($db,$user,$key,$act,$_POST); $_SESSION['notice']='Бухгалтерская операция сохранена.'; go('accounting');
        case 'tax.prepare': case 'tax.approve':
            ukProfileCommand($db,$user,$act,$_POST);$_SESSION['notice']='VAT-профиль сохранён.';go('tax');
        case 'invoice.entity':
            invoiceEntitySave($db,$user,$_POST); $_SESSION['notice']='Юрлицо сохранено.'; go('invoices');
        case 'invoice.create': case 'invoice.line': case 'invoice.remove': case 'invoice.terms': case 'invoice.issue': case 'invoice.void': case 'invoice.credit': case 'invoice.payment':
            $pid=$projectId();
            if($act==='invoice.create') $iid=invoiceCreate($db,$user,$pid,$key,$_POST);
            else { $iid=numberId($_POST['id']??0); if($act==='invoice.payment') invoicePayment($db,$user,$pid,$iid,$key,$_POST); else invoiceChange($db,$user,$pid,$iid,$key,$act,$_POST); }
            $_SESSION['notice']='Изменения счёта сохранены.'; go('invoices',['project'=>$pid,'id'=>$iid]);
        case 'deploy.create': case 'deploy.cancel':
            $pid=$projectId();
            if($act==='deploy.create') deploymentCreate($db,$user,$pid,$key,$_POST);
            else deploymentCancel($db,$user,$pid,numberId($_POST['id']??0));
            $_SESSION['notice']='Задание развёртывания сохранено.'; go('deployments',['project'=>$pid]);
        case 'infra.host': case 'infra.sync': case 'infra.environment': case 'infra.bind': case 'infra.detach': case 'infra.owner': case 'infra.deletehost':
            $pid=$projectId(); orgOwner($db,$user); allowedProject($db,$user,$pid);
            if($act==='infra.host') infraHostCreate($db,$user,$_POST);
            elseif($act==='infra.sync') infraSync($db,$user,numberId($_POST['host_id']??null));
            else infraChange($db,$user,$pid,$act,$_POST);
            $_SESSION['notice']='Инфраструктура: изменения сохранены; проверьте состояние источников.'; go('infrastructure',['project'=>$pid]);
        case 'lead.review': case 'lead.decision': case 'lead.wait':
            $pid=$projectId(); if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            match($act){'lead.review'=>leadReview($db,$user,$pid,$key,$_POST),'lead.decision'=>leadDecision($db,$user,$pid,$key,$_POST),'lead.wait'=>leadWaitReason($db,$user,$pid,$key,$_POST)};
            $_SESSION['notice']='Изменения сохранены.';go('lead',['project'=>$pid]);
        case 'release.qa':
            $pid=$projectId();$id=numberId($_POST['id']??0);deliveryReview($db,$user,$pid,$id,$key,$_POST);$_SESSION['notice']='Результат QA сохранён.';go('delivery',['project'=>$pid,'id'=>$id]);
        case 'release.create': case 'release.verify': case 'release.confirm': case 'release.cancel':
            $pid=$projectId();
            if($act==='release.create') $id=releaseCreate($db,$user,$pid,$key,$_POST);
            else { $id=numberId($_POST['id']??0); $version=numberId($_POST['version']??0);
                if($act==='release.cancel') releaseCancel($db,$user,$pid,$id,$version);
                else { releaseManager($db,$user,$pid); releaseInspect($db,$user,$pid,$id,$version,$act==='release.confirm',GitHubApp::configured()); } }
            $_SESSION['notice']='Выпуск сохранён.'; go('releases',['project'=>$pid,'id'=>$id]);
        case 'github.link': case 'github.unlink': case 'github.sync': case 'github.disable':
            $pid=$projectId(); ghManage($db,$user,$pid,$act,$_POST); $_SESSION['notice']='Изменения GitHub сохранены.';
            if (in_array($act,['github.link','github.unlink'],true)) go('task',['id'=>numberId($_POST['task_id']??0)]);
            go('github',['project'=>$pid]);
        case 'oss.line': case 'oss.fix': case 'oss.line.publish':
            $pid=$projectId(); $f=$_POST; unset($f['csrf'],$f['request_key'],$f['act']);
            if($act==='oss.line.publish') ossLinePublish($db,$user,$pid,$key,$f); else ossLineCommand($db,$user,$pid,$key,$act,$f);
            $line=optionalId($f['line_id']??''); go('oss_maintenance',['project'=>$pid]+($line?['line'=>$line]:[]));
        case 'oss.invite': case 'oss.reissue':
            $pid=$projectId(); $token=ossIssue($db,$user,$pid,$_POST['display_name']??'',workloadInt($_POST['hours']??null,1,168),$key,$act==='oss.reissue'?numberId($_POST['contributor_id']??0):null);
            // One-time display, bound to both actor and project.
            $_SESSION['oss_token']=['project'=>$pid,'actor'=>(int)$user['id'],'token'=>$token]; go('oss',['project'=>$pid]);
        case 'oss.settings': case 'oss.milestone': case 'oss.revoke': case 'oss.link': case 'oss.triage': case 'oss.comment': case 'oss.release':
            $pid=$projectId(); $form=$_POST; unset($form['csrf'],$form['request_key'],$form['act']); ossCommand($db,$user,$key,$pid,$act,$form);
            if ($act==='oss.link') go('task',['id'=>numberId($_POST['task_id']??0)]);
            go('oss',['project'=>$pid]+(in_array($act,['oss.triage','oss.comment'],true)?['id'=>numberId($_POST['id']??0)]:[]));
        case 'client.party': case 'client.contact': case 'client.request': case 'client.offer': case 'client.offer.edit': case 'client.stage': case 'client.stage.remove': case 'client.publish': case 'client.task': case 'client.deliver':
            $oid=clientCommand($db,$user,$key,$act,$_POST); $_SESSION['notice']='Изменения сохранены.'; go('clients',in_array($act,['client.party','client.contact'],true)?[]:['id'=>$oid]);
        case 'client.invite':
            $order=clientOrder($db,$user,numberId($_POST['order_id']??0)); $pair=word($_POST['document']??'',64,'Редакция');
            if (!preg_match('/\A([0-9]+):([0-9]+)\z/',$pair,$m)) throw new Problem('Выберите редакцию PDF.');
            $_SESSION['client_token']=issueGuest($db,$user,(int)$order['project_id'],numberId($m[1]),numberId($m[2]),$order['contact_email'],numberId($_POST['hours']??0),(int)$order['id']); $_SESSION['client_token_order']=(int)$order['id']; go('clients',['id'=>$order['id']]);
        case 'client.revoke':
            $order=clientOrder($db,$user,numberId($_POST['order_id']??0)); $gid=numberId($_POST['guest_id']??0); entity($db,'SELECT guest_id FROM client_order_guests WHERE order_id=? AND guest_id=?',[$order['id'],$gid]); revokeGuest($db,$user,(int)$order['project_id'],$gid); go('clients',['id'=>$order['id']]);
        case 'sprint.save': case 'sprint.state': case 'sprint.task': case 'availability.save': case 'rate.save': case 'time.save': case 'time.submit': case 'time.approve': case 'time.reject':
            $pid=$projectId(); $week=weekStart($_POST['week']??''); $version=workloadInt($_POST['version']??null,0,PHP_INT_MAX); $sprint=optionalId($_POST['sprint_id']??'');
            switch ($act) {
                case 'sprint.save': $sprint=saveSprint($db,$user,$key,$pid,$sprint,$version,$_POST); break;
                case 'sprint.state': sprintTransition($db,$user,$key,$pid,numberId($sprint),$version,choice($_POST['state']??'',SPRINT_STATES)); break;
                case 'sprint.task': sprintTask($db,$user,$key,$pid,numberId($sprint),$version,numberId($_POST['task_id']??0),($_POST['remove']??'')==='1'?null:workloadInt($_POST['estimate_minutes']??null,0,60000)); break;
                case 'availability.save': saveAvailability($db,$user,$key,$pid,numberId($_POST['member_id']??0),$week,workloadInt($_POST['minutes']??null,0,10080),$version); break;
                case 'rate.save': saveRate($db,$user,$key,$pid,$version,$_POST); break;
                case 'time.save': saveTime($db,$user,$key,$pid,$week,$version,$_POST); break;
                case 'time.submit': submitTime($db,$user,$key,$pid,numberId($_POST['sheet_id']??0),$version); break;
                case 'time.approve': case 'time.reject': decideTime($db,$user,$key,$pid,numberId($_POST['sheet_id']??0),$version,$act==='time.approve',word($_POST['decision_note']??'',500,'Решение',false)); break;
            }
            $_SESSION['notice']='Изменения сохранены.'; go('workload',['project'=>$pid,'week'=>$week]+($sprint?['sprint'=>$sprint]:[]));
        case 'guest.issue':
            $pair=word($_POST['version']??'',64,'Редакция');
            if (!preg_match('/\A([0-9]+):([0-9]+)\z/',$pair,$m)) throw new Problem('Выберите редакцию.');
            $_SESSION['guest_token']=issueGuest($db,$user,$projectId(),numberId($m[1]),numberId($m[2]),word($_POST['email']??'',254,'Email'),numberId($_POST['hours']??0));
            $_SESSION['guest_token_project']=$projectId(); go('project',['id'=>$projectId()]);
        case 'guest.revoke':
            revokeGuest($db,$user,$projectId(),numberId($_POST['guest_id']??0)); go('project',['id'=>$projectId()]);
        case 'org.auth-policy':
            setOrganizationAuthPolicy($db,$user,choice($_POST['mode']??'',ORG_AUTH_POLICIES)); $_SESSION['notice']='Политика входа сохранена.'; go('team');
        case 'org.create':
            $_SESSION['org']=createOrganization($db,$user,word($_POST['name']??'',120,'Организация')); unset($_SESSION['oss_token'],$_SESSION['invite_token'],$_SESSION['guest_token'],$_SESSION['guest_token_project'],$_SESSION['client_token'],$_SESSION['client_token_order'],$_SESSION['retention_link']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('team');
        case 'org.rename':
            renameOrganization($db,$user,word($_POST['name']??'',120,'Организация')); go('team');
        case 'team.rename': case 'team.delete':
            editTeam($db,$user,numberId($_POST['team_id']??0),$act==='team.delete'?null:word($_POST['name']??'',100,'Команда')); go('team');
        case 'team.create':
            createTeam($db,$user,word($_POST['name']??'',100,'Команда')); go('team');
        case 'team.add': case 'team.remove':
            changeMembership($db,$user,'team',numberId($_POST['team_id']??0),numberId($_POST['member_id']??0),$act==='team.add'); go('team');
        case 'project.add': case 'project.remove':
            changeMembership($db,$user,'project',$projectId(),numberId($_POST['member_id']??0),$act==='project.add'); go('project',['id'=>$projectId()]);
        case 'project.role':
            changeProjectRole($db,$user,$projectId(),numberId($_POST['member_id']??0),choice($_POST['role']??'',PROJECT_ROLES)); go('project',['id'=>$projectId()]);
        case 'payment.policy':
            changePaymentPolicy($db,$user,$projectId(),choice($_POST['dual']??'',['0'=>'','1'=>''])==='1'); go('project',['id'=>$projectId()]);
        case 'org.role':
            changeOrganizationRole($db,$user,numberId($_POST['member_id']??0),choice($_POST['role']??'',ROLES)); go('team');
        case 'org.remove':
            removeOrganizationMember($db,$user,numberId($_POST['member_id']??0)); go('team');
        case 'invite.issue':
            $token=issueInvitation($db,$user,word($_POST['email']??'',254,'Email'),choice($_POST['role']??'',ROLES),numberId($_POST['hours']??0));
            $_SESSION['invite_token']=$token; go('team');
        case 'invite.revoke':
            revokeInvitation($db,$user,numberId($_POST['invite_id']??0)); go('team');
        case 'project.create':
            $id = createProject($db,$user,$key,$_POST);
            $_SESSION['notice'] = 'Проект создан.'; go('project',['id'=>$id]);
        case 'task.create':
            createTask($db,$user,$key,$projectId(),$_POST);
            $_SESSION['notice'] = 'Задача добавлена.'; go('project',['id'=>$projectId()]);
        case 'task.edit':
            $id=editTask($db,$user,$key,numberId($_POST['task_id']??0),numberId($_POST['version']??0),$_POST);
            $_SESSION['notice']='План задачи сохранён.'; go('task',['id'=>$id]);
        case 'task.dependency.add': case 'task.dependency.remove':
            $id=changeDependency($db,$user,$key,numberId($_POST['task_id']??0),numberId($_POST['version']??0),numberId($_POST['prerequisite_id']??0),$act==='task.dependency.add');
            $_SESSION['notice']='Зависимости сохранены.'; go('task',['id'=>$id]);
        case 'milestone.create': case 'milestone.edit':
            saveMilestone($db,$user,$key,$projectId(),$act==='milestone.edit'?numberId($_POST['milestone_id']??0):null,$act==='milestone.edit'?numberId($_POST['version']??0):null,$_POST);
            $_SESSION['notice']='Веха сохранена.'; go('project',['id'=>$projectId()]);
        case 'milestone.state':
            changeMilestone($db,$user,$key,$projectId(),numberId($_POST['milestone_id']??0),numberId($_POST['version']??0),choice($_POST['state']??'',MILESTONE_STATES));
            $_SESSION['notice']='Статус вехи сохранён.'; go('project',['id'=>$projectId()]);
        case 'task.state':
            $id = changeTask($db,$user,$key,numberId($_POST['task_id'] ?? 0),numberId($_POST['version'] ?? 0),choice($_POST['state'] ?? '',STATES));
            $_SESSION['notice'] = 'Статус задачи сохранён.';
            $task = entity($db,'SELECT project_id FROM tasks WHERE id=?',[$id]); allowedProject($db,$user,(int)$task['project_id']);
            if (($_POST['return_to']??'')==='task') go('task',['id'=>$id]);
            go('project',['id'=>$task['project_id']]);
        case 'cash.post':
            $payment=submitPayment($db,$user,$key,$projectId(),paymentData($_POST));
            $_SESSION['notice'] = $payment['status']==='pending'?'Заявка ожидает согласования.':'Результат операции: '.$payment['status']; go('finance',['project'=>$projectId()]);
        case 'cash.reverse':
            $entry=numberId($_POST['entry_id']??0); $pid=(int)entity($db,'SELECT project_id FROM cash_entries WHERE id=?',[$entry])['project_id'];
            $payment=submitPayment($db,$user,$key,$pid,[],$entry);
            $_SESSION['notice'] = $payment['status']==='pending'?'Сторно ожидает согласования.':'Результат сторно: '.$payment['status']; go('finance');
        case 'payment.approve': case 'payment.reject': case 'payment.cancel':
            decidePayment($db,$user,$key,numberId($_POST['payment_id']??0),substr($act,8));
            $_SESSION['notice']='Решение сохранено.'; go('payments');
        case 'document.version':
            requireProjectRight($db,$user,$projectId(),'legal.write');
            $documentId = ($_POST['document_id'] ?? '') === '' ? null : numberId($_POST['document_id']);
            $id = storeDocument($db,$user,$key,$projectId(),$documentId,$documentId ? numberId($_POST['revision'] ?? 0) : 0,$_POST,documentFile($_FILES['pdf'] ?? []));
            $_SESSION['notice'] = 'Версия документа сохранена.'; go('document',['id'=>$id]);
        case 'connection.attach':
        case 'connection.detach':
            linkConnection($db,$user,$key,$projectId(),word($_POST['connection'] ?? '',48,'Подключение'),$act==='connection.attach');
            go('project',['id'=>$projectId()]);
        default: throw new Problem('Неизвестное действие.',404);
    }
}

function csvText(string $s): string {
    // Spreadsheet applications may evaluate text columns as formulas.
    return preg_match('/^\s*[=+@-]/u',$s) ? "'".$s : $s;
}

function sendCsv(PDO $db,array $user): never {
    $entries = ledger($db,$user,$_GET);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cashbook.csv"');
    $out = fopen('php://output','wb');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['ID','Дата','Проект','Операция','Сумма','Валюта','Назначение','Сторно для','Пользователь'],';','"','');
    foreach ($entries as $r) {
        fputcsv($out,[$r['id'],$r['booked_on'],csvText($r['project_title']),DIRECTIONS[$r['direction']],money((int)$r['amount_minor'],false),
            $r['currency'],csvText($r['memo']),$r['reversal_of'],csvText($r['actor_name'])],';','"','');
    }
    fclose($out); exit;
}

function dispatch(PDO $db, ?array $user, string $page): void {
    if ($page==='oss.public' || $page==='oss.export') {
        $data=ossPublicData($db,word($_GET['slug']??'',80,'Адрес'),numberId($_GET['n']??1));
        if ($page==='oss.export') { header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="oss-history.json"'); echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); return; }
        pageView('oss_public',$data['project']['public_title'],null,$data); return;
    }
    if (isset($_SESSION['oss_contributor'])) {
        if ($page!=='oss.contributor') go('oss.contributor');
        $c=ossSession($db); pageView('oss_contributor','Кабинет contributor',null,ossContributorPage($db,$c,optionalId($_GET['id']??''),numberId($_GET['n']??1))); return;
    }
    if ($page==='oss.join') { if ($user || !empty($_SESSION['guest_id'])) throw new Problem('Выйдите из текущей учётной записи или откройте отдельный браузер.',403); pageView('oss_join','Вход contributor'); return; }
    if (!empty($_SESSION['guest_id'])) { guestPage($db,$page); return; }
    if ($page==='guest.join' && !$user && oidcEnabled()) { pageView('guest_join','Приглашение клиента'); return; }
    if ($user && ($_SESSION['auth_kind']??null)==='emergency' && !($_SESSION['emergency_verified']??false) && $page!=='security') go('security');
    if ($page==='security' && $user) { mfaPage($db,$user); return; }
    if ($page==='invite') { pageView('invite',PAGE_TITLES['invite'],$user); return; }
    if (!$user) {
        if ($page !== 'login') go('login');
        pageView('login',PAGE_TITLES['login']); return;
    }
    if ($page === 'login') go('overview');
    if (empty($user['organization_id'])) go('invite');
    $user=organizationUser($db,$user);
    if ($page==='workspace_updates') { if (!updateAdmin($user)) throw new Problem('Нет доступа к обновлениям установки.',403); $report=mfaFresh()?updateStoredReport(schemaVersion($db)):null; pageView('workspace_updates','Обновления Workspace',$user,['report'=>$report]); return; }
    [$scope,$scopeArgs]=projectScope($db,$user);
    $navRights=[];
    foreach (['finance','legal','connections','audit'] as $right) $navRights[$right]=hasProjectRight($db,$user,$right) || can($user,$right);
    $user['nav_rights']=$navRights;
    if (isset(PAGE_RIGHTS[$page])) {
        $right=PAGE_RIGHTS[$page];
        if ($right==='people') permit($user,$right);
        elseif (!$navRights[$right]) throw new Problem('Нет доступа к разделу.',403);
        if (in_array($right,['finance','legal'],true)) [$scope,$scopeArgs]=projectRightScope($db,$user,$right);
    }
    $projectOptions = static fn() => array_column(query($db,"SELECT p.id,p.title FROM projects p WHERE $scope ORDER BY p.title,p.id",$scopeArgs)->fetchAll(),'title','id');
    $projectId = ($_GET['project'] ?? '') === '' ? null : numberId($_GET['project']);
    if ($projectId) {
        allowedProject($db,$user,$projectId);
        if (isset(PAGE_RIGHTS[$page]) && in_array(PAGE_RIGHTS[$page],['finance','legal','connections','audit'],true)) requireProjectRight($db,$user,$projectId,PAGE_RIGHTS[$page]);
    }
    $data = [];
    switch ($page) {
        case 'assistant':
            $cfg=aiConfig();$projects=aiProjects($db,$user,$cfg);$selected=$projectId===null?null:aiProject($db,$user,$projectId,$cfg);
            $policy=aiPolicy($db,(int)$user['organization_id'],$cfg,$user['role']==='owner');$models=aiModels($cfg);
            $history=$selected&&in_array($policy['model'],$models,true)?aiHistory($db,$user,(int)$selected['id'],$cfg):[];
            if($selected){$current=currentUser($db);if(!$current||(int)$current['id']!==(int)$user['id']||(int)$current['organization_id']!==(int)$user['organization_id'])throw new Problem('Сессия изменена.',403);if(aiPolicy($db,(int)$user['organization_id'],$cfg,$user['role']==='owner')!==$policy)throw new Problem('Политика изменена.',409);aiProject($db,$current,(int)$selected['id'],$cfg);}
            $data=['projects'=>$projects,'selected'=>$selected,'topic'=>'role','term'=>'','question'=>'','result'=>null,'policy'=>$policy,'models'=>$models,'topics'=>$selected?aiTopicsFor($db,$user,(int)$selected['id'],$policy):[],'history'=>$history];
            $data['ops']=aiOpsReport($db,$user);$data['delegation']=aiMcpAvailable()?aiMcpPolicy($db,(int)$user['organization_id']):null;
            $data['draftOptions']=[];$data['draftReview']=null;$data['draftError']=null;
            $pending=$_SESSION['ai_draft']??null;
            if(is_array($pending)&&($pending['expires']<=time()||$pending['user']!==(int)$user['id']||$pending['org']!==(int)$user['organization_id'])){unset($_SESSION['ai_draft']);$pending=null;}
            if($selected&&in_array($policy['model'],$models,true)){
                $data['draftOptions']=aiDraftOptions($db,$user,(int)$selected['id'],$cfg);
                if(is_array($pending)&&$pending['project']===(int)$selected['id']){try{$data['draftReview']=aiDraftReview($db,$user,(int)$selected['id'],$pending,$cfg);}catch(Problem $e){$data['draftError']=$e->getMessage();if($e->status!==403)unset($_SESSION['ai_draft']);}}
            };break;
        case 'reliability':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=relPage($db,$user,$projectId,optionalId($_GET['before']??''));break;
        case 'incidents':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=incidentPage($db,$user,$projectId,$_GET);break;
        case 'bots':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=botsPage($db,$user,$projectId,$_GET);break;
        case 'logs.export':
            if(!mfaFresh()||$projectId===null)throw new Problem('Выберите проект и подтвердите второй фактор.',403);
            $result=logsProjectRows($db,$user,$projectId,$_GET);unset($result['external_url']);header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="workspace-logs.json"');echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);return;
        case 'logs':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=logsPage($db,$user,$projectId,$_GET);break;
        case 'editorial':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=editorialPage($db,$user,$projectId,$_GET);break;
        case 'lite':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=litePage($db,$user,$projectId,$_GET);break;
        case 'forum':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=forumPage($db,$user,$projectId,$_GET);break;
        case 'book':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=bookPage($db,$user,$projectId,$_GET);break;
        case 'book.export':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$r=bookEdition($db,$user,numberId($_GET['project']??0),numberId($_GET['edition']??0));
            header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="book-edition-'.(int)$r['id'].'.json"');header('Cache-Control: private, no-store');echo $r['snapshot'];return;
        case 'engine':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=enginePage($db,$user,$projectId,$_GET);break;
        case 'retention':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=retentionPage($db,$user,optionalId($_GET['id']??''),optionalId($_GET['v']??''),optionalId($_GET['before']??''),optionalId($_GET['log_before']??''));break;
        case 'signing':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);$data=signPage($db,$user,$projectId,optionalId($_GET['id']??''),optionalId($_GET['before']??''));break;
        case 'sign.download':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);signDownload($db,$user,numberId($projectId),numberId($_GET['id']??0),word($_GET['kind']??'',32,'Файл'));
        case 'preparation':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            $data=prepPage($db,$user,$projectId,optionalId($_GET['id']??''),optionalId($_GET['compare']??''),optionalId($_GET['before']??''));break;
        case 'approved_acts':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            $data=prepActs($db,$user,$projectId,optionalId($_GET['before']??''));break;
        case 'prep.print':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            $c=prepGet($db,$user,numberId($projectId),numberId($_GET['id']??0));$r=entity($db,'SELECT * FROM preparation_revisions WHERE preparation_id=? AND revision=?',[$c['id'],numberId($_GET['revision']??0)]);prepVerify($db,$r,false);
            require ROOT.'/views/preparation_print.php';return;
        case 'prep.act':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            $r=prepApprovedAct($db,$user,numberId($projectId),numberId($_GET['id']??0));$bytes=atomic($db,function()use($db,$user,$projectId,$r){$fresh=prepApprovedAct($db,$user,numberId($projectId),(int)$r['preparation_id']);if((int)$fresh['id']!==(int)$r['id'])throw new Problem('Редакция изменилась.',409);$b=retentionDocumentBytes($db,(int)$r['document_id'],(int)$r['document_revision']);retentionLog($db,(int)$r['document_id'],(int)$r['document_revision'],null,(int)$user['id'],null,null,'approved_act',$b);return $b;});
            if(!hash_equals($r['document_hash'],hash('sha256',$bytes)))throw new Problem('Хеш акта изменился.',409);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="approved-act-'.$r['preparation_id'].'-v'.$r['revision'].'.pdf"');echo $bytes;return;
        case 'contracts':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            $data=contractPage($db,$user,$projectId,optionalId($_GET['id']??''),optionalId($_GET['before']??''));break;
        case 'tax.print':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            renderUkPrint(ukPrintData($db,$user,numberId($_GET['project']??0),numberId($_GET['id']??0),($_GET['credit']??'')==='1'));return;
        case 'tax': case 'tax.export':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор.',403);
            $data=ukReport($db,$user,calendarDate($_GET['from']??substr(ukToday(),0,7).'-01'),calendarDate($_GET['to']??ukToday()));
            if($page==='tax.export'){sendUkCsv($data);return;}break;
        case 'economics':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            $data=econPage($db,$user,$projectId,econMonth($_GET['month']??gmdate('Y-m')),calendarDate($_GET['horizon']??gmdate('Y-m-d',time()+90*86400)));break;
        case 'bank':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            $data=bankPage($db,$user,optionalId($_GET['account']??''),optionalId($_GET['statement']??''),optionalId($_GET['row']??''),choice($_GET['filter']??'unmatched',['all'=>'','unmatched'=>'','matched'=>'']),optionalId($_GET['after']??''));break;
        case 'accounting':
            if(!mfaFresh())throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            $data=accountingPage($db,$user,optionalId($_GET['period']??''),optionalId($_GET['before']??'')); break;
        case 'invoices':
            $data=invoicePage($db,$user,$projectId,optionalId($_GET['id']??''),optionalId($_GET['before']??'')); break;
        case 'deployments':
            $data=deploymentPage($db,$user,numberId($_GET['project']??0)); break;
        case 'infrastructure':
            $data=infraPage($db,$user,numberId($_GET['project']??0)); break;
        case 'lead':
            $data=leadPage($db,$user,numberId($_GET['project']??0),optionalId($_GET['after']??'')??0,word($_GET['mode']??'all',16,'Очередь'));break;
        case 'delivery':
            $data=deliveryPage($db,$user,numberId($_GET['project']??0),numberId($_GET['id']??0)); break;
        case 'releases':
            $data=releasePage($db,$user,numberId($_GET['project']??0),optionalId($_GET['id']??'')); break;
        case 'github':
            $data=ghPage($db,$user,numberId($_GET['project']??0)); break;
        case 'oss_maintenance':
            $data=ossLinePage($db,$user,numberId($_GET['project']??0),optionalId($_GET['line']??''),optionalId($_GET['id']??''),numberId($_GET['n']??1),word($_GET['state']??'',20,'Статус',false)); break;
        case 'oss':
            $pid=numberId($_GET['project']??0); $data=ossAdminPage($db,$user,$pid,optionalId($_GET['id']??''),numberId($_GET['n']??1),word($_GET['state']??'',20,'Статус',false));
            $token=$_SESSION['oss_token']??null; unset($_SESSION['oss_token']);
            if (is_array($token) && $token['project']===$pid && $token['actor']===(int)$user['id']) $data['inviteToken']=$token['token'];
            break;
        case 'clients':
            $id=optionalId($_GET['id']??''); $data=clientsPage($db,$user,$id,numberId($_GET['n']??1));
            if ($id!==null && ($_SESSION['client_token_order']??null)===$id) { $data['clientToken']=$_SESSION['client_token']??null; unset($_SESSION['client_token'],$_SESSION['client_token_order'],$_SESSION['retention_link']); }
            break;
        case 'workload':
            if (!$projectId) throw new Problem('Выберите проект.',404);
            $data=workloadPage($db,$user,$projectId,weekStart($_GET['week']??gmdate('Y-m-d',strtotime('monday this week'))),optionalId($_GET['sprint']??'')); break;
        case 'search':
            $data=discoverySearch($db,$user,word($_GET['q']??'',120,'Поиск',false),$projectId,word($_GET['kind']??'',32,'Тип',false),numberId($_GET['n']??1),mfaFresh());break;
        case 'notifications':
            $data=reminderInbox($db,$user,$projectId,mfaFresh(),time(),word($_GET['kind']??'',32,'Тип',false),numberId($_GET['n']??1),choice($_GET['unread']??'0',['0'=>'','1'=>''])==='1')+reminderSettings($db,$user,$projectId,mfaFresh());break;
        case 'digest':
            $horizon=(int)choice($_GET['horizon']??'7',array_combine(array_map('strval',range(0,90)),range(0,90)));$data=reminderDigest($db,$user,$projectId,mfaFresh(),time(),$horizon);break;
        case 'overview':
            $data['reminderOverview']=reminderOverview($db,$user,mfaFresh(),time());
            $data['counts'] = query($db,"SELECT COUNT(DISTINCT p.id) projects,count(CASE WHEN t.state!='done' THEN 1 END) active,count(CASE WHEN t.state='done' THEN 1 END) done FROM projects p LEFT JOIN tasks t ON t.project_id=p.id WHERE $scope",$scopeArgs)->fetch();
            $blocked=planningBlockedSql(); $late=planningBlockedSql(true);
            $data['tasks'] = query($db,"SELECT t.*,p.title project_title,u.name assignee,CASE WHEN $blocked THEN 1 ELSE 0 END blocked,CASE WHEN $late THEN 1 ELSE 0 END late_blocker FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id
                WHERE $scope AND t.state!='done' ORDER BY CASE WHEN t.assignee_id=? THEN 0 ELSE 1 END,t.due_on IS NULL,t.due_on,t.id LIMIT 8",[...$scopeArgs,$user['id']])->fetchAll();
            $data['recent'] = query($db,"SELECT p.* FROM projects p WHERE $scope ORDER BY p.id DESC LIMIT 4",$scopeArgs)->fetchAll();
            break;
        case 'projects':
            $data['projects'] = query($db,"SELECT p.*,COUNT(t.id) tasks,COUNT(CASE WHEN t.state='done' THEN 1 END) done FROM projects p LEFT JOIN tasks t ON t.project_id=p.id WHERE $scope GROUP BY p.id ORDER BY p.id DESC",$scopeArgs)->fetchAll();
            break;
        case 'task':
            $t=planningTask($db,$user,numberId($_GET['id']??0)); $pid=(int)$t['project_id'];
            $data=['task'=>$t,'projectRole'=>projectRole($db,$user,$pid),'dependencies'=>planningDependencies($db,$user,(int)$t['id']),'blocked'=>blockedTask($db,$pid,(int)$t['id'])];
            $data['github']=ghTaskData($db,$user,$pid,(int)$t['id']);
            $data['taskReleases']=roleAllows($data['projectRole'],'connections')?query($db,'SELECT r.id,r.tag,r.state FROM project_release_tasks rt JOIN project_releases r ON r.id=rt.release_id AND r.project_id=rt.project_id WHERE rt.project_id=? AND rt.task_id=? ORDER BY r.id DESC LIMIT 100',[$pid,$t['id']])->fetchAll():[];
            $data['ossConfig']=query($db,'SELECT upstream FROM oss_projects WHERE project_id=?',[$pid])->fetch()?:null;
            $data['ossLink']=query($db,'SELECT upstream_url FROM oss_task_links WHERE project_id=? AND task_id=?',[$pid,$t['id']])->fetchColumn()?:null;
            if (roleAllows($data['projectRole'],'manage')) {
                $data['assignable']=[];
                foreach (planningPeople($db,$user,$pid) as $uid=>$name) if (roleAllows(projectRole($db,['id'=>$uid,'organization_id'=>$user['organization_id']],$pid),'work')) $data['assignable'][$uid]=$name;
                $data['milestones']=planningMilestones($db,$pid);
            }
            break;
        case 'project':
            $p = allowedProject($db,$user,numberId($_GET['id'] ?? 0));
            $filters=planningFilters($_GET); $pageNo=numberId($_GET['n']??1);
            $data=['project'=>$p,'filters'=>$filters,'pageNo'=>$pageNo,'lanes'=>planningRows($db,$user,(int)$p['id'],$filters,$pageNo),'milestones'=>planningMilestones($db,(int)$p['id']),'people'=>planningPeople($db,$user,(int)$p['id'])];
            $data['projectRole']=projectRole($db,$user,(int)$p['id']);
            $data['dual']=dualControl($db,(int)$p['id']);
            if ($user['role']==='owner' && $p['kind']==='client') {
                $data['guestVersions']=query($db,'SELECT d.id,d.title,v.revision FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? ORDER BY d.id,v.revision DESC',[$p['id']])->fetchAll();
                $data['guestInvites']=query($db,'SELECT id,email,document_id,revision,expires_at,accepted_at,access_until,revoked_at FROM guest_invites WHERE project_id=? AND organization_id=? ORDER BY id DESC LIMIT 100',[$p['id'],$user['organization_id']])->fetchAll();
                if (($_SESSION['guest_token_project']??null)===(int)$p['id']) { $data['guestToken']=$_SESSION['guest_token']??null; unset($_SESSION['guest_token'],$_SESSION['guest_token_project']); }
            }
            $data['memberAdmin']=($user['role']==='owner'||($user['role']==='manager' && roleAllows($data['projectRole'],'manage')));
            if (roleAllows($data['projectRole'],'manage')) {
                $data['members']=orgMembers($db,$user);
            $data['orgAuthPolicy']=organizationAuthPolicy($db,(int)$user['organization_id']);
                $data['projectMembers']=array_map('intval',query($db,'SELECT user_id FROM project_members WHERE project_id=?',[$p['id']])->fetchAll(PDO::FETCH_COLUMN));
                $data['explicitRoles']=array_column(query($db,'SELECT user_id,role FROM project_access WHERE project_id=?',[$p['id']])->fetchAll(),'role','user_id');
                $data['assignable']=array_column(array_filter($data['members'],fn($m)=>$m['enabled'] && ($m['role']==='owner'||(in_array((int)$m['id'],$data['projectMembers'],true) && roleAllows($data['explicitRoles'][$m['id']]??$m['role'],'work')))),'name','id');
            }
            if (roleAllows($data['projectRole'],'connections')) {
                $data['connections'] = scopedConnections($db,$user,(int)$p['id']);
                if (roleAllows($data['projectRole'],'operations')) $data['connections'] += scopedConnections($db,$user);
                $data['links'] = query($db,'SELECT connection_key FROM project_connections WHERE project_id=? ORDER BY connection_key',[$p['id']])->fetchAll(PDO::FETCH_COLUMN);
            }
            break;
        case 'finance':
            $pageNo = numberId($_GET['n'] ?? 1);
            if ($pageNo > 10000000) throw new Problem('Некорректная страница.');
            $rows = ledger($db,$user,$_GET,($pageNo-1)*50)->fetchAll();
            $data = ['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'pageNo'=>$pageNo,'balances'=>balances($db,$user,$_GET),'projects'=>$projectOptions(),'selected'=>$projectId,
                'filters'=>array_filter(['project'=>$projectId,'from'=>$_GET['from'] ?? null,'to'=>$_GET['to'] ?? null])];
            $data['writableProjects']=rightProjects($db,$user,'payment.create');
            $data['pendingReversals']=array_map('intval',query($db,"SELECT r.reversal_of FROM payment_requests r JOIN projects p ON p.id=r.project_id WHERE $scope AND r.status='pending' AND r.reversal_of IS NOT NULL",$scopeArgs)->fetchAll(PDO::FETCH_COLUMN));
            break;
        case 'payments':
            $before=isset($_GET['before'])?numberId($_GET['before']):null;
            $rows=paymentQueue($db,$user,$projectId,$before);
            $data=['payments'=>array_slice($rows,0,50),'nextBefore'=>count($rows)>50?(int)$rows[49]['id']:null,'selected'=>$projectId,'projects'=>$projectOptions(),'creatable'=>rightProjects($db,$user,'payment.create'),'approvable'=>rightProjects($db,$user,'payment.approve')]; break;
        case 'audit':
            $rows=auditRows($db,$user,$projectId,isset($_GET['before'])?numberId($_GET['before']):null);
            $data=['events'=>array_slice($rows,0,50),'nextBefore'=>count($rows)>50?(int)$rows[49]['id']:null,'selected'=>$projectId,'projects'=>rightProjects($db,$user,'audit')]; break;
        case 'export':
            if (!mfaFresh()) throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            sendCsv($db,$user);
        case 'documents':
            $data['projects'] = $projectOptions();
            $data['selected'] = $projectId;
            $data['writableProjects']=rightProjects($db,$user,'legal.write');
            $data['docs'] = query($db,'SELECT d.*,p.title project_title,MAX(v.revision) revision FROM documents d JOIN projects p ON d.project_id=p.id JOIN document_versions v ON v.document_id=d.id '.
                "WHERE $scope ".($projectId ? 'AND d.project_id=? ' : '').'GROUP BY d.id,p.title ORDER BY d.id DESC',$projectId?[...$scopeArgs,$projectId]:$scopeArgs)->fetchAll();
            break;
        case 'document':
            $id = numberId($_GET['id'] ?? 0);
            $data['doc'] = entity($db,"SELECT d.*,p.title project_title FROM documents d JOIN projects p ON d.project_id=p.id WHERE d.id=? AND $scope",[$id,...$scopeArgs]);
            $data['writable']=roleAllows(projectRole($db,$user,(int)$data['doc']['project_id']),'legal.write');
            $data['versions'] = query($db,'SELECT v.revision,v.sha256,v.created_at,COALESCE(v.original_size,length(v.bytes)) size,v.storage_state,u.name FROM document_versions v JOIN users u ON v.actor_id=u.id WHERE document_id=? ORDER BY revision DESC',[$id])->fetchAll();
            break;
        case 'download':
            $id = numberId($_GET['id'] ?? 0); $rev = numberId($_GET['v'] ?? 0);
            $doc = entity($db,"SELECT d.project_id FROM document_versions v JOIN documents d ON d.id=v.document_id JOIN projects p ON p.id=d.project_id WHERE v.document_id=? AND v.revision=? AND $scope",[$id,$rev,...$scopeArgs]);
            if (!mfaFresh()) throw new Problem('Подтвердите второй фактор в разделе «Безопасность».',403);
            $doc['bytes']=atomic($db,function()use($db,$user,$doc,$id,$rev){requireProjectRight($db,$user,(int)$doc['project_id'],'legal');$b=retentionDocumentBytes($db,$id,$rev);retentionLog($db,$id,$rev,null,(int)$user['id'],null,null,'document',$b);return $b;});
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="document-'.$id.'-v'.$rev.'.pdf"');
            header('Content-Length: '.strlen($doc['bytes']));
            echo $doc['bytes']; return;
        case 'connections':
            $report=profileReport($db);
            $visible=scopedConnections($db,$user);
            $data=['items'=>array_intersect_key($report['connections'],$visible),'stand'=>($user['organization_id']===1 && $user['role']==='owner')?$report['stand']:['name'=>$user['organization_name'],'environment'=>'','owner'=>'','contact'=>'','notes'=>''],'issues'=>($user['organization_id']===1 && $user['role']==='owner' && (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn()===1)?$report['issues']:[],'instance'=>null,'checks'=>[],'nextBefore'=>null];
            if (isset($_GET['instance'])) {
                $id=word($_GET['instance'],48,'Подключение');
                if (!isset($data['items'][$id])) throw new Problem('Подключение не найдено.',404);
                $before=isset($_GET['before'])?numberId($_GET['before']):null;
                $rows=connectionHistory($db,$id,$before,51);
                $data['instance']=$id;
                $data['checks']=array_slice($rows,0,50);
                $data['nextBefore']=count($rows)>50?(int)$rows[49]['id']:null;
            }
            break;
        case 'team':
            $data['members']=orgMembers($db,$user);
            $data['orgAuthPolicy']=organizationAuthPolicy($db,(int)$user['organization_id']);
            $data['teams']=query($db,'SELECT * FROM teams WHERE organization_id=? ORDER BY name',[$user['organization_id']])->fetchAll();
            $data['teamMembers']=query($db,'SELECT team_id,user_id FROM team_members WHERE organization_id=?',[$user['organization_id']])->fetchAll();
            if ($user['role']==='owner') {
                $data['invitations']=query($db,'SELECT id,email,role,expires_at,accepted_at,revoked_at FROM invitations WHERE organization_id=? ORDER BY id DESC LIMIT 100',[$user['organization_id']])->fetchAll();
                $data['inviteToken']=$_SESSION['invite_token']??null; unset($_SESSION['oss_token'],$_SESSION['invite_token'],$_SESSION['guest_token'],$_SESSION['guest_token_project'],$_SESSION['client_token'],$_SESSION['client_token_order'],$_SESSION['retention_link']);
            }
            break;
        default: throw new Problem('Страница не найдена.',404);
    }
    $title = match ($page) { 'project'=>$data['project']['title'], 'task'=>$data['task']['title'], 'document'=>$data['doc']['title'], default=>PAGE_TITLES[$page] };
    pageView($page,$title,$user,$data);
}

function sendUkCsv(array $data): void {
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="uk-vat-sales.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['document','type','date','original_invoice','original_tax_point','entity_id','project_id','supplier','supplier_address','vat_number','buyer','buyer_address','currency','net_minor','vat_minor','gross_minor','rule','credit_reason'],',','"','');
    foreach($data['rows'] as $r){$s=json_decode($r['issuer_snapshot'],true,512,JSON_THROW_ON_ERROR);$b=json_decode($r['client_snapshot'],true,512,JSON_THROW_ON_ERROR);$p=json_decode($r['profile_snapshot'],true,512,JSON_THROW_ON_ERROR);fputcsv($out,[$r['doc_number'],$r['kind'],$r['date'],invoiceNumber($r),$r['tax_point'],$r['entity_id'],$r['project_id'],csvText($s['name']),csvText($s['address']),'GB'.$p['vat_number'],csvText($b['name']),csvText($r['buyer_address']),'GBP',$r['net'],$r['vat'],$r['gross'],$r['rule_version'],csvText($r['kind']==='credit'?$r['credit_reason']:'')],',','"','');}fclose($out);
}

function renderUkPrint(array $data): void {extract($data,EXTR_SKIP);require ROOT.'/views/tax_print.php';}
