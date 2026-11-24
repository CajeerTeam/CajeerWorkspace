<?php
declare(strict_types=1);

namespace Workspace;

use PDO;

const PAGE_TITLES = ['overview'=>'Рабочий обзор','projects'=>'Проекты','project'=>'Проект','finance'=>'Финансы','documents'=>'Документы','document'=>'История документа','connections'=>'Подключения','team'=>'Команда','login'=>'Вход в Workspace'];
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
    return $u;
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
    if (!$user) go('login');
    if ($act==='logout') { killLogin(); go('login'); }
    $key = word($_POST['request_key'] ?? '',32,'Форма');
    $projectId = static fn() => numberId($_POST['project_id'] ?? 0);
    switch ($act) {
        case 'project.create':
            $id = createProject($db,$user,$key,$_POST);
            $_SESSION['notice'] = 'Проект создан.'; go('project',['id'=>$id]);
        case 'task.create':
            createTask($db,$user,$key,$projectId(),$_POST);
            $_SESSION['notice'] = 'Задача добавлена.'; go('project',['id'=>$projectId()]);
        case 'task.state':
            $id = changeTask($db,$user,$key,numberId($_POST['task_id'] ?? 0),numberId($_POST['version'] ?? 0),choice($_POST['state'] ?? '',STATES));
            $_SESSION['notice'] = 'Статус задачи сохранён.';
            $task = entity($db,'SELECT project_id FROM tasks WHERE id=?',[$id]);
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

function sendCsv(PDO $db): never {
    $entries = ledger($db,$_GET);
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
    if (!$user) {
        if ($page !== 'login') go('login');
        pageView('login',PAGE_TITLES['login']); return;
    }
    if ($page === 'login') go('overview');
    if (isset(PAGE_RIGHTS[$page])) permit($user,PAGE_RIGHTS[$page]);
    $projectOptions = static fn() => array_column($db->query('SELECT id,title FROM projects ORDER BY title,id')->fetchAll(),'title','id');
    $projectId = ($_GET['project'] ?? '') === '' ? null : numberId($_GET['project']);
    if ($projectId) project($db,$projectId);
    $data = [];
    switch ($page) {
        case 'overview':
            $data['counts'] = $db->query("SELECT (SELECT count(*) FROM projects) projects,count(CASE WHEN state!='done' THEN 1 END) active,count(CASE WHEN state='done' THEN 1 END) done FROM tasks")->fetch();
            $data['tasks'] = query($db,"SELECT t.*,p.title project_title,u.name assignee FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id
                WHERE t.state!='done' ORDER BY CASE WHEN t.assignee_id=? THEN 0 ELSE 1 END,t.due_on IS NULL,t.due_on,t.id LIMIT 8",[$user['id']])->fetchAll();
            $data['recent'] = $db->query('SELECT * FROM projects ORDER BY id DESC LIMIT 4')->fetchAll();
            break;
        case 'projects':
            $data['projects'] = $db->query("SELECT p.*,COUNT(t.id) tasks,COUNT(CASE WHEN t.state='done' THEN 1 END) done FROM projects p LEFT JOIN tasks t ON t.project_id=p.id GROUP BY p.id ORDER BY p.id DESC")->fetchAll();
            break;
        case 'project':
            $p = project($db,numberId($_GET['id'] ?? 0));
            $data = ['project'=>$p,'tasks'=>query($db,"SELECT t.*,u.name assignee FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? ORDER BY t.state='done',t.due_on IS NULL,t.due_on,t.id",[$p['id']])->fetchAll()];
            if (can($user,'manage')) $data['assignable'] = array_column($db->query("SELECT id,name FROM users WHERE enabled=1 AND role IN ('owner','manager','developer') ORDER BY name")->fetchAll(),'name','id');
            if (can($user,'connections')) {
                $data['connections'] = connectionViews($db);
                $data['links'] = query($db,'SELECT connection_key FROM project_connections WHERE project_id=? ORDER BY connection_key',[$p['id']])->fetchAll(PDO::FETCH_COLUMN);
            }
            break;
        case 'finance':
            $pageNo = numberId($_GET['n'] ?? 1);
            if ($pageNo > 10000000) throw new Problem('Некорректная страница.');
            $rows = ledger($db,$_GET,($pageNo-1)*50)->fetchAll();
            $data = ['rows'=>array_slice($rows,0,50),'more'=>count($rows)>50,'pageNo'=>$pageNo,'balances'=>balances($db,$_GET),'projects'=>$projectOptions(),'selected'=>$projectId,
                'filters'=>array_filter(['project'=>$projectId,'from'=>$_GET['from'] ?? null,'to'=>$_GET['to'] ?? null])];
            break;
        case 'export': sendCsv($db);
        case 'documents':
            $data['projects'] = $projectOptions();
            $data['selected'] = $projectId;
            $data['docs'] = query($db,'SELECT d.*,p.title project_title,MAX(v.revision) revision FROM documents d JOIN projects p ON d.project_id=p.id JOIN document_versions v ON v.document_id=d.id '.
                ($projectId ? 'WHERE d.project_id=? ' : '').'GROUP BY d.id ORDER BY d.id DESC',$projectId?[$projectId]:[])->fetchAll();
            break;
        case 'document':
            $id = numberId($_GET['id'] ?? 0);
            $data['doc'] = entity($db,'SELECT d.*,p.title project_title FROM documents d JOIN projects p ON d.project_id=p.id WHERE d.id=?',[$id]);
            $data['versions'] = query($db,'SELECT v.revision,v.sha256,v.created_at,length(v.bytes) size,u.name FROM document_versions v JOIN users u ON v.actor_id=u.id WHERE document_id=? ORDER BY revision DESC',[$id])->fetchAll();
            break;
        case 'download':
            $id = numberId($_GET['id'] ?? 0); $rev = numberId($_GET['v'] ?? 0);
            $doc = entity($db,'SELECT bytes FROM document_versions WHERE document_id=? AND revision=?',[$id,$rev]);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="document-'.$id.'-v'.$rev.'.pdf"');
            header('Content-Length: '.strlen($doc['bytes']));
            echo $doc['bytes']; return;
        case 'connections': $data['items'] = connectionViews($db); break;
        case 'team': $data['members'] = $db->query('SELECT name,email,role,enabled FROM users ORDER BY enabled DESC,name')->fetchAll(); break;
        default: throw new Problem('Страница не найдена.',404);
    }
    $title = match ($page) { 'project'=>$data['project']['title'], 'document'=>$data['doc']['title'], default=>PAGE_TITLES[$page] };
    pageView($page,$title,$user,$data);
}
