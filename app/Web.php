<?php
declare(strict_types=1);

namespace Workspace;

use PDO;

const PAGE_TITLES = ['overview'=>'Рабочий обзор','projects'=>'Проекты','project'=>'Проект','finance'=>'Финансы','documents'=>'Документы','document'=>'История документа','connections'=>'Подключения','team'=>'Команда','login'=>'Вход в Workspace','invite'=>'Принять приглашение','search'=>'Поиск'];
const PAGE_RIGHTS = ['finance'=>'finance','export'=>'finance','documents'=>'legal','document'=>'legal','download'=>'legal','connections'=>'connections','team'=>'people'];

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
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || getenv('WORKSPACE_HTTPS')==='1';
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) throw new \RuntimeException('Не удалось открыть сессию.');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function killLogin(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function currentUser(PDO $db): ?array {
    if (empty($_SESSION['uid'])) return null;
    $now = time();
    $u = query($db,'SELECT id,name,email,role,session_version FROM users WHERE id=? AND enabled=1',[$_SESSION['uid']])->fetch();
    if (!$u || (int)$u['session_version'] !== $_SESSION['v'] || $now-$_SESSION['seen']>1800 || $now-$_SESSION['born']>43200) {
        killLogin(); return null;
    }
    $_SESSION['seen'] = $now;
    $orgs=organizationsFor($db,(int)$u['id']);
    if (!$orgs) return array_replace($u,['organization_id'=>null,'organization_name'=>'Нет организации','organizations'=>[],'role'=>'viewer']);
    $selected=(int)($_SESSION['org']??0);
    if (!in_array($selected,array_map('intval',array_column($orgs,'id')),true)) $selected=(int)$orgs[0]['id'];
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
    if ($act==='login') {
        if ($user) go('overview');
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $u = authenticate($db,$email,$password,$_SERVER['REMOTE_ADDR'] ?? '');
        if (!$u) throw new Problem('Проверьте почту и пароль.',401);
        killLogin();
        $_SESSION += ['uid'=>(int)$u['id'],'v'=>(int)$u['session_version'],'born'=>time(),'seen'=>time()];
        go('overview');
    }
    if ($act==='invite.accept') {
        $result=acceptInvitation($db,word($_POST['token']??'',64,'Код'),$user, is_string($_POST['name']??null)?$_POST['name']:'',is_string($_POST['password']??null)?$_POST['password']:'');
        if (!$user) { go('login'); }
        $_SESSION['org']=$result['organization_id']; unset($_SESSION['invite_token']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('overview');
    }
    if (!$user) go('login');
    if ($act==='logout') { killLogin(); go('login'); }
    if ($act==='org.switch') { $u=organizationUser($db,$user,numberId($_POST['org']??0)); $_SESSION['org']=$u['organization_id']; unset($_SESSION['invite_token']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('overview'); }
    $user=organizationUser($db,$user);
    $key = word($_POST['request_key'] ?? '',32,'Форма');
    $projectId = static fn() => numberId($_POST['project_id'] ?? 0);
    switch ($act) {
        case 'org.create':
            $_SESSION['org']=createOrganization($db,$user,word($_POST['name']??'',120,'Организация')); unset($_SESSION['invite_token']); $_SESSION['csrf']=bin2hex(random_bytes(32)); go('team');
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
        case 'task.state':
            $id = changeTask($db,$user,$key,numberId($_POST['task_id'] ?? 0),numberId($_POST['version'] ?? 0),choice($_POST['state'] ?? '',STATES));
            $_SESSION['notice'] = 'Статус задачи сохранён.';
            $task = entity($db,'SELECT project_id FROM tasks WHERE id=?',[$id]); allowedProject($db,$user,(int)$task['project_id']);
            go('project',['id'=>$task['project_id']]);
        case 'cash.post':
            recordCash($db,$user,$key,$projectId(),$_POST);
            $_SESSION['notice'] = 'Операция проведена.'; go('finance',['project'=>$projectId()]);
        case 'cash.reverse':
            reverseCash($db,$user,$key,numberId($_POST['entry_id'] ?? 0));
            $_SESSION['notice'] = 'Сторно проведено.'; go('finance');
        case 'document.version':
            permit($user,'legal');
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
    if ($page==='invite') { pageView('invite',PAGE_TITLES['invite'],$user); return; }
    if (!$user) {
        if ($page !== 'login') go('login');
        pageView('login',PAGE_TITLES['login']); return;
    }
    if ($page === 'login') go('overview');
    if (empty($user['organization_id'])) go('invite');
    $user=organizationUser($db,$user);
    [$scope,$scopeArgs]=projectScope($db,$user);
    if (isset(PAGE_RIGHTS[$page])) permit($user,PAGE_RIGHTS[$page]);
    $projectOptions = static fn() => array_column(query($db,"SELECT p.id,p.title FROM projects p WHERE $scope ORDER BY p.title,p.id",$scopeArgs)->fetchAll(),'title','id');
    $projectId = ($_GET['project'] ?? '') === '' ? null : numberId($_GET['project']);
    if ($projectId) allowedProject($db,$user,$projectId);
    $data = [];
    switch ($page) {
        case 'search':
            $q=word($_GET['q']??'',120,'Поиск',false);
            $data=['term'=>$q,'matches'=>[]];
            if ($q!=='') {
                $like='%'.strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
                $data['matches']['projects']=query($db,"SELECT p.id,p.title FROM projects p WHERE $scope AND (LOWER(p.title) LIKE LOWER(?) ESCAPE '!' OR LOWER(p.client) LIKE LOWER(?) ESCAPE '!') ORDER BY p.id DESC LIMIT 100",[...$scopeArgs,$like,$like])->fetchAll();
                $data['matches']['tasks']=query($db,"SELECT t.title,t.project_id AS id FROM tasks t JOIN projects p ON p.id=t.project_id WHERE $scope AND LOWER(t.title) LIKE LOWER(?) ESCAPE '!' ORDER BY t.id DESC LIMIT 100",[...$scopeArgs,$like])->fetchAll();
                if (can($user,'legal')) $data['matches']['documents']=query($db,"SELECT d.id,d.title FROM documents d JOIN projects p ON p.id=d.project_id WHERE $scope AND LOWER(d.title) LIKE LOWER(?) ESCAPE '!' ORDER BY d.id DESC LIMIT 100",[...$scopeArgs,$like])->fetchAll();
            }
            break;
        case 'overview':
            $data['counts'] = query($db,"SELECT COUNT(DISTINCT p.id) projects,count(CASE WHEN t.state!='done' THEN 1 END) active,count(CASE WHEN t.state='done' THEN 1 END) done FROM projects p LEFT JOIN tasks t ON t.project_id=p.id WHERE $scope",$scopeArgs)->fetch();
            $data['tasks'] = query($db,"SELECT t.*,p.title project_title,u.name assignee FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id
                WHERE $scope AND t.state!='done' ORDER BY CASE WHEN t.assignee_id=? THEN 0 ELSE 1 END,t.due_on IS NULL,t.due_on,t.id LIMIT 8",[...$scopeArgs,$user['id']])->fetchAll();
            $data['recent'] = query($db,"SELECT p.* FROM projects p WHERE $scope ORDER BY p.id DESC LIMIT 4",$scopeArgs)->fetchAll();
            break;
        case 'projects':
            $data['projects'] = query($db,"SELECT p.*,COUNT(t.id) tasks,COUNT(CASE WHEN t.state='done' THEN 1 END) done FROM projects p LEFT JOIN tasks t ON t.project_id=p.id WHERE $scope GROUP BY p.id ORDER BY p.id DESC",$scopeArgs)->fetchAll();
            break;
        case 'project':
            $p = allowedProject($db,$user,numberId($_GET['id'] ?? 0));
            $data = ['project'=>$p,'tasks'=>query($db,"SELECT t.*,u.name assignee FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? ORDER BY t.state='done',t.due_on IS NULL,t.due_on,t.id",[$p['id']])->fetchAll()];
            if (can($user,'manage')) {
                $data['members']=orgMembers($db,$user);
                $data['projectMembers']=array_map('intval',query($db,'SELECT user_id FROM project_members WHERE project_id=?',[$p['id']])->fetchAll(PDO::FETCH_COLUMN));
                $data['assignable']=array_column(array_filter($data['members'],fn($m)=>$m['enabled'] && in_array($m['role'],['owner','manager','developer'],true) && ($m['role']==='owner'||in_array((int)$m['id'],$data['projectMembers'],true))),'name','id');
            }
            if (can($user,'connections')) {
                $data['connections'] = scopedConnections($db,$user,(int)$p['id']);
                $data['links'] = query($db,'SELECT connection_key FROM project_connections WHERE project_id=? ORDER BY connection_key',[$p['id']])->fetchAll(PDO::FETCH_COLUMN);
            }
            break;
        case 'finance':
            $pageNo = numberId($_GET['n'] ?? 1);
            if ($pageNo > 10000000) throw new Problem('Некорректная страница.');
            $rows = ledger($db,$user,$_GET,($pageNo-1)*50)->fetchAll();
            $data = ['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'pageNo'=>$pageNo,'balances'=>balances($db,$user,$_GET),'projects'=>$projectOptions(),'selected'=>$projectId,
                'filters'=>array_filter(['project'=>$projectId,'from'=>$_GET['from'] ?? null,'to'=>$_GET['to'] ?? null])];
            break;
        case 'export': sendCsv($db,$user);
        case 'documents':
            $data['projects'] = $projectOptions();
            $data['selected'] = $projectId;
            $data['docs'] = query($db,'SELECT d.*,p.title project_title,MAX(v.revision) revision FROM documents d JOIN projects p ON d.project_id=p.id JOIN document_versions v ON v.document_id=d.id '.
                "WHERE $scope ".($projectId ? 'AND d.project_id=? ' : '').'GROUP BY d.id,p.title ORDER BY d.id DESC',$projectId?[...$scopeArgs,$projectId]:$scopeArgs)->fetchAll();
            break;
        case 'document':
            $id = numberId($_GET['id'] ?? 0);
            $data['doc'] = entity($db,"SELECT d.*,p.title project_title FROM documents d JOIN projects p ON d.project_id=p.id WHERE d.id=? AND $scope",[$id,...$scopeArgs]);
            $data['versions'] = query($db,'SELECT v.revision,v.sha256,v.created_at,length(v.bytes) size,u.name FROM document_versions v JOIN users u ON v.actor_id=u.id WHERE document_id=? ORDER BY revision DESC',[$id])->fetchAll();
            break;
        case 'download':
            $id = numberId($_GET['id'] ?? 0); $rev = numberId($_GET['v'] ?? 0);
            $doc = entity($db,"SELECT ".(isPostgres($db)?"encode(v.bytes,'hex')":"v.bytes")." AS bytes FROM document_versions v JOIN documents d ON d.id=v.document_id JOIN projects p ON p.id=d.project_id WHERE v.document_id=? AND v.revision=? AND $scope",[$id,$rev,...$scopeArgs]);
            if (isPostgres($db)) $doc['bytes']=hex2bin($doc['bytes']);
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
            $data['teams']=query($db,'SELECT * FROM teams WHERE organization_id=? ORDER BY name',[$user['organization_id']])->fetchAll();
            $data['teamMembers']=query($db,'SELECT team_id,user_id FROM team_members WHERE organization_id=?',[$user['organization_id']])->fetchAll();
            if ($user['role']==='owner') {
                $data['invitations']=query($db,'SELECT id,email,role,expires_at,accepted_at,revoked_at FROM invitations WHERE organization_id=? ORDER BY id DESC LIMIT 100',[$user['organization_id']])->fetchAll();
                $data['inviteToken']=$_SESSION['invite_token']??null; unset($_SESSION['invite_token']);
            }
            break;
        default: throw new Problem('Страница не найдена.',404);
    }
    $title = match ($page) { 'project'=>$data['project']['title'], 'document'=>$data['doc']['title'], default=>PAGE_TITLES[$page] };
    pageView($page,$title,$user,$data);
}
