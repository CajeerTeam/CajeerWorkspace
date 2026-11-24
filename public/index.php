<?php
declare(strict_types=1);

namespace Workspace;

require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';
secureHeaders();
$user = null;
try {
    $db = database();
    sessionOpen();
    $user = currentUser($db);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') handlePost($db,$user);
    if ($method !== 'GET' && $method !== 'HEAD') { header('Allow: GET, HEAD, POST'); throw new Problem('Метод не поддерживается.',405); }
    dispatch($db,$user,word($_GET['p'] ?? 'overview',32,'Страница'));
} catch (Problem $e) {
    http_response_code($e->status);
    if (!$user && $e->status===401) pageView('login','Вход в Workspace',null,['error'=>$e->getMessage()]);
    else pageView('error','Не удалось выполнить действие',$user,['error'=>$e->getMessage()]);
} catch (\Throwable $e) {
    error_log('CajeerWorkspace request failed: '.$e::class);
    http_response_code(500);
    pageView('error','Ошибка приложения',$user,['error'=>'Проверьте журнал PHP, конфигурацию и доступ к данным.']);
}
