<?php
declare(strict_types=1);

namespace Workspace;

require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
secureHeaders();
$user = null;
try {
    $db = database();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $page=word($_GET['p']??'overview',32,'Страница');
    if ($page==='retention.share') {
        if($method==='GET'){$token=word($_GET['token']??'',64,'Ссылка');retentionFindLink($db,$token);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Выдача документа</title><h1>Скачать документ</h1><p>Выдача регистрируется, число скачиваний ограничено.</p><form method="post" action="./?p=retention.share"><input type="hidden" name="token" value="'.h($token).'"><button>Скачать PDF</button></form></html>';exit;}
        if ($method!=='POST') {header('Allow: GET, POST');throw new Problem('Метод не поддерживается.',405);}
        $bytes=retentionRedeem($db,word($_POST['token']??'',64,'Ссылка'));header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="shared-document.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
    }
    if ($page==='sign.webhook') {
        if ($method!=='POST') { header('Allow: POST'); throw new Problem('Метод не поддерживается.',405); }
        signWebhook($db);
    }
    if ($page==='github.webhook') {
        if ($method!=='POST') { header('Allow: POST'); throw new Problem('Метод не поддерживается.',405); }
        ghWebhook($db);
    }
    if ($page==='oidc.backchannel') {
        if ($method!=='POST') { header('Allow: POST'); throw new Problem('Метод не поддерживается.',405); }
        backchannelLogout($db); exit;
    }
    sessionOpen();
    if (($_SESSION['auth_kind']??null)==='oidc') {
        if (!validateOidcSession($db) || time()-($_SESSION['seen']??0)>1800 || time()-($_SESSION['born']??0)>43200) killLogin();
        elseif (!empty($_SESSION['guest_id'])) $_SESSION['seen']=time();
    }
    $user = currentUser($db);
    if ($page==='oidc.callback') {
        if ($method!=='GET'||$user||!empty($_SESSION['guest_id'])) throw new Problem('Недопустимый callback.',403);
        oidcCallback($db);
    }
    if ($method === 'POST') handlePost($db,$user);
    if ($method !== 'GET' && $method !== 'HEAD') { header('Allow: GET, HEAD, POST'); throw new Problem('Метод не поддерживается.',405); }
    dispatch($db,$user,$page);
} catch (Problem $e) {
    http_response_code($e->status);
    if (!$user && $e->status===401) pageView('login','Вход в Workspace',null,['error'=>$e->getMessage()]);
    else pageView('error','Не удалось выполнить действие',$user,['error'=>$e->getMessage()]);
} catch (\Throwable $e) {
    error_log('CajeerWorkspace request failed: '.$e::class);
    http_response_code(500);
    pageView('error','Ошибка приложения',$user,['error'=>'Проверьте журнал PHP, конфигурацию и доступ к данным.']);
}
