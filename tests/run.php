<?php
declare(strict_types=1);

namespace Workspace;

use RuntimeException;
use Throwable;

require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/Web.php';

function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    ++$GLOBALS['checks'];
}

function rejected(callable $work, int $status, string $label): void {
    try { $work(); }
    catch (Problem $e) { check($e->status===$status,$label.' ('.$e->status.')'); return; }
    throw new RuntimeException($label.' (действие разрешено)');
}

function token(): string { return bin2hex(random_bytes(16)); }

$temp=sys_get_temp_dir().'/cajeerws-'.bin2hex(random_bytes(8));
mkdir($temp,0700);
putenv('WORKSPACE_DATA='.$temp);
putenv('WORKSPACE_CONFIG');
$checks=0;
try {
    $db=database(true);
    addUser($db,'owner@example.test','Владелец','owner','sample-password-only',true);
    rejected(fn()=>addUser($db,'second@example.test','Второй','owner','sample-password-only',true),422,'Повторная инициализация');
    foreach (['manager','developer','accountant','legal','viewer'] as $role) addUser($db,$role.'@example.test',$role,$role,'sample-password-only');
    $users=[];
    foreach ($db->query('SELECT * FROM users') as $u) $users[$u['role']]=$u;
    extract($users);
    check(authenticate($db,'OWNER@example.test','sample-password-only','127.0.0.1')['role']==='owner','Вход');
    for ($i=0;$i<10;$i++) check(authenticate($db,'bad@example.test','incorrect','127.0.0.9')===null,'Неверный пароль');
    rejected(fn()=>authenticate($db,'bad@example.test','incorrect','127.0.0.9'),429,'Ограничение попыток');
    $form=['title'=>'Библиотека','client'=>'Команда','kind'=>'oss']; $key=token();
    $pid=createProject($db,$manager,$key,$form);
    check(createProject($db,$manager,$key,$form)===$pid,'Повтор создания проекта');
    rejected(fn()=>createProject($db,$manager,$key,['title'=>'Другое']+$form),409,'Изменённое тело повтора');
    rejected(fn()=>createProject($db,$viewer,token(),$form),403,'Создание проекта наблюдателем');
    $tid=createTask($db,$manager,token(),$pid,['title'=>'Сборка','assignee'=>$developer['id'],'due_on'=>'2028-02-29']);
    changeTask($db,$developer,token(),$tid,1,'doing');
    rejected(fn()=>changeTask($db,$owner,token(),$tid,1,'done'),409,'Устаревшая задача');
    $other=createTask($db,$manager,token(),$pid,['title'=>'Приёмка','assignee'=>$owner['id']]);
    rejected(fn()=>changeTask($db,$developer,token(),$other,1,'done'),403,'Чужая задача');
    rejected(fn()=>calendarDate('2026-02-29'),422,'Календарная дата');
    $cash=['amount'=>'0,10','currency'=>'EUR','direction'=>'income','memo'=>'=тест','booked_on'=>gmdate('Y-m-d')];
    $key=token();
    $entry=recordCash($db,$accountant,$key,$pid,$cash);
    check(recordCash($db,$accountant,$key,$pid,$cash)===$entry,'Повтор проведения');
    recordCash($db,$owner,token(),$pid,['amount'=>'0.20']+$cash);
    recordCash($db,$owner,token(),$pid,['amount'=>'7','currency'=>'PLN','direction'=>'expense']+$cash);
    $totals=balances($db,[]);
    check(count($totals)===2 && (int)$totals[0]['income']===30 && (int)$totals[1]['expense']===700,'Точная сумма и валюты');
    check(!balances($db,['to'=>gmdate('Y-m-d',time()-86400)]),'Фильтр периода');
    rejected(fn()=>recordCash($db,$manager,token(),$pid,$cash),403,'Доступ к кассе');
    rejected(fn()=>recordCash($db,$owner,token(),$pid,['amount'=>'1.001']+$cash),422,'Скрытая потеря точности');
    rejected(fn()=>moneyMinor('0.00'),422,'Нулевая сумма');
    $reversed=reverseCash($db,$owner,token(),$entry);
    check((int)balances($db,[])[0]['income']===20,'Сторно уменьшило поступление');
    rejected(fn()=>reverseCash($db,$owner,token(),$entry),409,'Повторное сторно');
    rejected(fn()=>reverseCash($db,$owner,token(),$reversed),409,'Сторно корректировки');
    check(csvText('=123')==="'=123" && money(10,false)==='0,10','Формат CSV');
    $key=token(); $pdf="%PDF-1.4\nfirst\n%%EOF\n"; $new="%PDF-1.4\nsecond\n%%EOF\n";
    $file=['kind'=>'contract','title'=>'Договор'];
    $doc=storeDocument($db,$legal,$key,$pid,null,0,$file,$pdf);
    check(storeDocument($db,$legal,$key,$pid,null,0,$file,$pdf)===$doc,'Повтор загрузки PDF');
    storeDocument($db,$legal,token(),$pid,$doc,1,[],$new);
    check(query($db,'SELECT bytes FROM document_versions WHERE document_id=? AND revision=1',[$doc])->fetchColumn()===$pdf,'Сохранена первая редакция');
    rejected(fn()=>storeDocument($db,$legal,token(),$pid,$doc,1,[],$new),409,'Конфликт версий PDF');
    rejected(fn()=>storeDocument($db,$legal,token(),$pid,$doc,2,[],$new),409,'Идентичная редакция');
    rejected(fn()=>storeDocument($db,$accountant,token(),$pid,null,0,$file,$pdf),403,'Доступ к договорам');
    rejected(fn()=>storeDocument($db,$owner,token(),$pid,null,0,$file,'not pdf'),422,'Неверный формат');
    rejected(fn()=>storeDocument($db,$owner,token(),$pid,null,0,$file,'%PDF-'.str_repeat('a',2097152).'%%EOF'),422,'Превышение размера');
    $items=connections(ROOT.'/config/connections.example.json');
    check(count($items)===9,'Девять типов адаптеров');
    $paths=['engine'=>['/health/ready','ready'],'logs'=>['/ready','ok'],'forum'=>['/health/ready','ready'],'book'=>['/health/ready','ok'],'lite'=>['/health/ready','ready'],'bots'=>['/api/health','ok'],'servers'=>['/api/v1/ready','ready']];
    foreach ($paths as $kind=>[$path,$status]) {
        $c=array_merge($items[$kind],['configured'=>true,'base'=>'https://demo.invalid']);
        probe($c,static function ($verb,$url) use ($path,$status) {check($verb==='GET' && $url==='https://demo.invalid'.$path,'Маршрут '.$url); return ['status'=>$status];});
        rejected(fn()=>probe($c,fn()=>['status'=>'unknown']),422,'Неожиданный ответ '.$kind);
    }
    putenv('WORKSPACE_GITHUB_TOKEN=local-test-token');
    $summary=probe($items['github'],static function ($verb,$url,$headers) {
        check($verb==='GET' && $url==='https://api.github.com/repos/CajeerTeam/CajeerWorkspace','GitHub URL');
        check(in_array('Authorization: Bearer local-test-token',$headers,true),'GitHub авторизация');
        return ['full_name'=>'CajeerTeam/CajeerWorkspace','default_branch'=>'main','archived'=>false,'temp_clone_token'=>'never-store-this'];
    });
    check(!str_contains($summary,'token') && str_contains($summary,'main'),'Разрешённые поля GitHub');
    $c=array_merge($items['aapanel'],['configured'=>true,'base'=>'https://panel.invalid']);
    putenv('WORKSPACE_AAPANEL_KEY=local-test-key');
    probe($c,static function ($method,$url,$headers,$body) {
        check($method==='POST' && $url==='https://panel.invalid/system?action=GetSystemTotal','aaPanel URL');
        check($body['request_token']===md5($body['request_time'].md5('local-test-key')),'Подпись aaPanel');
        return ['version'=>'7.0','cpuNum'=>2,'memTotal'=>2048];
    });
    putenv('WORKSPACE_AAPANEL_KEY');
    rejected(fn()=>probe($c),422,'aaPanel без ключа');
    $path=$temp.'/connections.json';
    file_put_contents($path,'{"github":{"repository":"example/repo"},"docs":{"type":"book","base_url":"https://docs.invalid"}}');
    putenv('WORKSPACE_CONFIG='.$path); $items=connections();
    linkConnection($db,$owner,token(),$pid,'docs',true);
    check((int)$db->query('SELECT COUNT(*) FROM project_connections')->fetchColumn()===1,'Привязка подключения');
    query($db,'INSERT INTO connection_results VALUES(?,?,?,?,?)',['docs',$items['docs']['hash'],1,'ok',time()-301]);
    check(connectionViews($db)['docs']['status']==='Данные устарели','Давность снимка');
    file_put_contents($path,'{"docs":{"type":"book","base_url":"https://other.invalid"}}');
    check(connectionViews($db)['docs']['snapshot']===null,'Изменение адреса сбрасывает снимок');
    $target=connections()['docs'];
    $good=fn()=>['status'=>'ok','version'=>'2.1.0'];
    check(syncConnections($db,['docs'=>$target],$good)===0,'Первый опрос');
    check(syncConnections($db,['docs'=>$target],fn()=>throw new Problem('HTTP 503'))===1,'Отказ');
    $state=connectionViews($db)['docs'];
    check($state['status']==='Ошибка' && $state['last_success']['ok']==1 && $state['last_version']['observed_version']==='2.1.0','Последний успех сохраняется при отказе');
    check(syncConnections($db,['docs'=>$target],$good)===0,'Восстановление');
    $history=connectionHistory($db,'docs');
    check(array_column($history,'transition')===['recovered','down','initial'],'Порядок событий');
    check(count(connectionHistory($db,'docs',(int)$history[0]['id'],1))===1,'Пагинация протокола');
    check(profileReport($db)['connections']['docs']['latest']['observed_version']==='2.1.0','Профиль API');
    check(!isset(profileReport($db)['connections']['docs']['env']),'Секреты не попадают в профиль');
    query($db,'UPDATE connection_checks SET checked_at=? WHERE id=?',[time()-31*86400,$history[1]['id']]);
    syncConnections($db,['docs'=>$target],$good);
    check(count(connectionHistory($db,'docs'))===3,'Очистка старого протокола');
    // Upgrade a genuine schema 1 database with existing business data and its old snapshot.
    $legacyDir=$temp.'/legacy'; mkdir($legacyDir,0700);
    $legacy=new \PDO('sqlite:'.$legacyDir.'/workspace.sqlite');
    $legacy->exec(file_get_contents(ROOT.'/database/001.sql')); $legacy->exec('PRAGMA user_version=1');
    $legacy->exec("INSERT INTO projects(title,kind) VALUES('Legacy project','internal')");
    query($legacy,'INSERT INTO connection_results VALUES(?,?,?,?,?)',['docs',$target['hash'],1,'legacy result',time()]);
    $legacy=null; putenv('WORKSPACE_DATA='.$legacyDir);
    rejected(fn()=>database(),503,'Старая схема требует явного обновления');
    $legacy=database(false,true);
    check((int)$legacy->query('PRAGMA user_version')->fetchColumn()===SCHEMA_VERSION,'Миграция схемы');
    check($legacy->query('SELECT title FROM projects')->fetchColumn()==='Legacy project','Данные сохранены');
    $old=connectionHistory($legacy,'docs')[0];
    check($old['transition']==='legacy' && $old['endpoint']===null && $old['http_status']===null,'Миграция без фиктивных метрик');
    $legacy=null; $legacy=database(false,true);
    check(count(connectionHistory($legacy,'docs'))===1,'Повтор обновления безопасен');
    $legacy=null; putenv('WORKSPACE_DATA='.$temp);
    foreach (glob($legacyDir.'/*') as $f) unlink($f); rmdir($legacyDir);
    check($db->query('PRAGMA integrity_check')->fetchColumn()==='ok' && !$db->query('PRAGMA foreign_key_check')->fetch(),'Целостность SQLite');
    echo "OK · $checks проверок\n";
} finally {
    $db=null;
    foreach (glob($temp.'/*') as $f) { if (is_dir($f)) { foreach (glob($f.'/*') as $child) unlink($child); rmdir($f); } else unlink($f); }
    rmdir($temp);
}
