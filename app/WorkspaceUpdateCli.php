<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function updateWriteReport(string $dir,array $report): void {
    $path=$dir.'/report.json';$tmp=$dir.'/.report-'.bin2hex(random_bytes(16));
    $raw=json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    updateAssert(strlen($raw)<=131072,'Слишком большой отчёт обновления.');
    $file=fopen($tmp,'xb');updateAssert(is_resource($file),'Не удалось создать отчёт.');
    try {
        try{updateAssert(fwrite($file,$raw)===strlen($raw)&&fflush($file)&&fsync($file),'Не удалось записать отчёт.');}finally{fclose($file);}
        updateAssert(chmod($tmp,0640)&&rename($tmp,$path),'Не удалось опубликовать отчёт.');
    } finally {if(is_file($tmp))unlink($tmp);}
}
function updatePackageProcess(string $action,string $store,array $envelope,?string $previous): array {
    updateAssert(PHP_SAPI==='cli','Операция доступна только в CLI.');
    $args=['python3','-B',ROOT.'/tools/update_package.py',$action,$store];if($previous!==null)$args[]=$previous;
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,ROOT,['PATH'=>getenv('PATH')?:'/usr/local/bin:/usr/bin:/bin','LANG'=>'C.UTF-8']);
    updateAssert(is_resource($p),'Не удалось запустить проверяющий процесс.');
    try {
        $raw=json_encode($envelope,JSON_THROW_ON_ERROR);$offset=0;
        while($offset<strlen($raw)){$n=fwrite($pipes[0],substr($raw,$offset));updateAssert(is_int($n)&&$n>0,'Ошибка передачи метаданных.');$offset+=$n;}
        fclose($pipes[0]);$result=stream_get_contents($pipes[1],131073);fclose($pipes[1]);
    } finally {foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);$code=proc_close($p);}
    updateAssert(is_string($result)&&strlen($result)<=131072,'Некорректный ответ проверяющего процесса.');
    $out=updateJson($result);
    updateAssert($code===0&&($out['status']??null)===($action==='plan'?'planned':'downloaded'),is_string($out['reason']??null)?$out['reason']:'Проверяющий процесс завершился с ошибкой.');
    return $out;
}
function updateCli(PDO $db,string $action,?string $previous=null): array {
    $dir=updateStateDirectory();$path=$dir.'/schedule.lock';
    updateAssert(!is_link($path)&&(!file_exists($path)||is_file($path)),'Небезопасная блокировка планировщика.');
    $lock=fopen($path,'c+');updateAssert(is_resource($lock),'Не удалось открыть блокировку планировщика.');
    try {
        if(!flock($lock,LOCK_EX|LOCK_NB)){
            $token=getenv('WORKSPACE_UPDATE_SCHEDULE_TOKEN')?:'';
            updateAssert(updateHex($token)&&hash_equals($token,stream_get_contents($lock,65)),'Планировщик выполняет операцию.');
        }
        return updateCliLocked($db,$action,$previous);
    } finally {fclose($lock);}
}
function updateCliLocked(PDO $db,string $action,?string $previous=null): array {
    updateAssert(PHP_SAPI==='cli'&&in_array($action,['check','download','plan'],true),'Неверная команда обновления.');
    $dir=updateStateDirectory();$store=$dir.'/packages';
    updateAssert(is_writable($dir),'Каталог отчётов недоступен для записи.');
    if(!is_dir($store))updateAssert(mkdir($store,0700),'Не удалось создать приватный каталог пакетов.');
    updateAssert(realpath($store)===$store&&!is_link($store)&&((fileperms($store)?:0)&0077)===0,'Небезопасный каталог пакетов.');
    $lockPath=$dir.'/update.lock';updateAssert(!is_link($lockPath)&&(!file_exists($lockPath)||is_file($lockPath)),'Небезопасная блокировка.');
    $lock=fopen($lockPath,'c');updateAssert(is_resource($lock),'Не удалось открыть блокировку.');
    try {
        updateAssert(flock($lock,LOCK_EX|LOCK_NB),'Другая операция обновления выполняется.');
        $journal=$store.'/install.json';
        if(file_exists($journal)||is_link($journal)){
            updateAssert(is_file($journal)&&!is_link($journal),'Небезопасный журнал установки.');
            $raw=file_get_contents($journal,false,null,0,1048577);updateAssert(is_string($raw)&&strlen($raw)<=1048576,'Некорректный журнал установки.');
            $j=updateJson($raw);updateAssert(in_array($j['phase']??null,['completed','rolled_back'],true),'Незавершённая установка: сначала завершите восстановление.');
        }
        $report=['checked_at'=>gmdate('c'),'installed_version'=>VERSION,'installed_schema'=>schemaVersion($db),'trust_pin'=>getenv('WORKSPACE_UPDATE_KEY_SHA256')?:'','operation'=>$action,'channel'=>getenv('WORKSPACE_UPDATE_CHANNEL')?:'stable','status'=>'running','reasons'=>['CLI выполняет проверку.']];
        updateWriteReport($dir,$report);
        try {
            [$pem,$pin]=updateKey();$env=updateEnvironment($db);$envelope=null;
            $report=array_replace($report,updateDiscover($env,$pem,$pin,null,static function($raw,$sig,$size)use(&$envelope,$pem,$env){$envelope=['metadata'=>base64_encode($raw),'signature'=>base64_encode($sig),'key'=>base64_encode($pem),'signature_size'=>$size,'environment'=>$env];},$report['channel']));
            if($action!=='check'){
                $expected=getenv('WORKSPACE_UPDATE_EXPECTED_METADATA')?:'';
                if($expected!=='')updateAssert($envelope!==null&&updateHex($expected)&&hash_equals($expected,hash('sha256',base64_decode($envelope['metadata'],true))),'Выпуск изменился после проверки.');
                updateAssert($report['status']==='compatible'&&$envelope!==null,'Для скачивания или плана требуется подтверждённый совместимый новый выпуск.');
                if($action==='plan')updateAssert(is_string($previous)&&str_starts_with($previous,'/')&&is_file($previous)&&!is_link($previous),'Укажите абсолютный путь к точному исходному ZIP.');
                $report['package_result']=updatePackageProcess($action,$store,$envelope,$previous);
                $report['status']=$report['package_result']['status'];
            }
        } catch(\Throwable $e) {
            $report['status']='failed';unset($report['package_result']);$report['reasons'][]=$e instanceof \RuntimeException?substr($e->getMessage(),0,500):'Ошибка проверки или обработки пакета.';
        }
        $report['checked_at']=gmdate('c');updateWriteReport($dir,$report);return $report;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
