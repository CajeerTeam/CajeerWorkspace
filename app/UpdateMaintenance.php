<?php
declare(strict_types=1);
namespace Workspace;

/** Hold a shared lease until request/CLI termination, not just until opening PDO. */
function updateWriterLease(): void {
    static $leases=[];
    $dir=dataDir();
    $marker=$dir.'/update-maintenance.json';
    if(PHP_SAPI==='cli' && ($token=getenv('WORKSPACE_UPDATE_MAINTENANCE_TOKEN')) && preg_match('/\A[a-f0-9]{64}\z/',$token) && is_file($marker) && !is_link($marker)) {
        $raw=@file_get_contents($marker,false,null,0,4097);
        $state=is_string($raw)&&strlen($raw)<=4096?json_decode($raw,true):null;
        if(is_array($state)&&is_string($state['token']??null)&&hash_equals($state['token'],$token))return;
    }
    if(isset($leases[$dir]))return;
    $path=$dir.'/update-access.lock';
    if(is_link($path)|| (file_exists($path)&&!is_file($path)))throw new Problem('Небезопасная блокировка обновления.',503);
    $handle=@fopen($path,'c');
    if(!is_resource($handle))throw new Problem('Не удалось открыть блокировку обновления.',503);
    if(!flock($handle,LOCK_SH|LOCK_NB)){fclose($handle);throw new Problem('Выполняется обслуживание Workspace.',503);}
    clearstatcache(true,$marker);
    if(file_exists($marker)||is_link($marker)){fclose($handle);throw new Problem('Workspace в режиме обслуживания. Требуется завершение обновления.',503);}
    $leases[$dir]=$handle;
}
