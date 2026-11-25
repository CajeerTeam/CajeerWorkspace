<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';

$count=0;
function expectProfile(bool $value,string $message): void {
    if (!$value) throw new \RuntimeException($message);
    ++$GLOBALS['count'];
}
function configRejected(callable $f): void {
    try { $f(); } catch (\RuntimeException) { expectProfile(true,'config rejected'); return; }
    throw new \RuntimeException('Invalid config accepted');
}
$file=tempnam(sys_get_temp_dir(),'cws-profile-');
try {
    $config=['_stand'=>['name'=>'Pilot','environment'=>'staging','owner'=>'Operations'],
        'engine'=>['base_url'=>'https://example.invalid/engine','launch_url'=>'https://example.invalid','owner'=>'CMS team','installed_version'=>'1.5.0'],
        'docs'=>['type'=>'book','base_url'=>'https://example.invalid/book','version_path'=>'/version','version_field'=>'build.release'],
        'github'=>['repository'=>'example/project'],'aapanel'=>['base_url'=>'https://panel.invalid']];
    file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
    $profile=connectionConfig($file); $items=$profile['items'];
    expectProfile(count($items)===10 && $profile['stand']['environment']==='staging','profile parsing');
    expectProfile($items['engine']['owner']==='CMS team' && $items['engine']['installed_version']==='1.5.0','operator metadata');
    expectProfile($items['engine']['hash']===hash('sha256',json_encode(['engine',null,'https://example.invalid/engine',null],JSON_THROW_ON_ERROR)),'legacy hash compatibility');
    $r=observeConnection($items['engine'],fn()=>['status'=>'ready','version'=>'1.5.1','private'=>'never-store-this']);
    expectProfile($r['ok']===1 && $r['observed_version']==='1.5.1' && $r['version_source']==='readiness','actual API version');
    expectProfile(!str_contains(json_encode($r),'never-store-this') && $r['http_status']===null && $r['duration_ms']>=0,'no fabricated HTTP or body');
    $r=observeConnection($items['engine'],fn()=>['status'=>'ready']);
    expectProfile($r['ok']===1 && $r['observed_version']===null && $r['version_error']!==null,'operator version is not observation');
    $r=observeConnection($items['docs'],static function ($method,$url) {
        expectProfile($method==='GET','read only');
        return match($url) {
            'https://example.invalid/book/health/ready'=>['status'=>'ok'],
            'https://example.invalid/book/version'=>['build'=>['release'=>'2.0.3']],
            default=>throw new \RuntimeException('Unexpected version route'),
        };
    });
    expectProfile($r['ok']===1 && $r['observed_version']==='2.0.3' && $r['version_source']==='version_api','nested version endpoint');
    $r=observeConnection($items['docs'],fn($m,$u)=>str_ends_with($u,'/version')?throw new Problem('HTTP 503'):['status'=>'ok']);
    expectProfile($r['ok']===1 && $r['observed_version']===null && $r['version_error']==='HTTP 503','version failure distinct from readiness');
    $r=observeConnection($items['engine'],fn()=>throw new Problem('HTTP 503'));
    expectProfile($r['ok']===0 && $r['summary']==='HTTP 503','readiness failure');
    putenv('WORKSPACE_AAPANEL_KEY=fixture-secret');
    $r=observeConnection($items['aapanel'],static function ($method,$url,$headers,$form) {
        expectProfile($method==='POST' && $form['request_token']===md5($form['request_time'].md5('fixture-secret')),'signed panel request');
        return ['version'=>'7.0.1','cpuNum'=>4,'memTotal'=>4096,'secret'=>'fixture-secret'];
    });
    expectProfile($r['ok']===1 && $r['observed_version']==='7.0.1' && !str_contains(json_encode($r),'fixture-secret'),'panel version and redaction');
    putenv('WORKSPACE_AAPANEL_KEY=rotated-key');
    $r=observeConnection($items['aapanel'],static function ($m,$u,$h,$f) {
        expectProfile($f['request_token']===md5($f['request_time'].md5('rotated-key')),'new key read on next probe');
        return ['version'=>$f['request_token'],'cpuNum'=>4,'memTotal'=>4096];
    });
    expectProfile($r['ok']===0 && $r['observed_version']===null,'reflected signature rejected');
    putenv('WORKSPACE_GITHUB_TOKEN=fixture-github-token');
    $r=observeConnection($items['github'],fn()=>['full_name'=>'example/project','default_branch'=>'main','archived'=>false,'token'=>'fixture-github-token']);
    expectProfile($r['ok']===1 && $r['repository_owner']==='example' && $r['observed_version']===null && !str_contains(json_encode($r),'fixture-github-token'),'GitHub SaaS metadata');
    $r=observeConnection($items['github'],fn()=>['full_name'=>'example/project','default_branch'=>'fixture-github-token','archived'=>false]);
    expectProfile($r['ok']===0 && !str_contains(json_encode($r),'fixture-github-token'),'reflected credential rejected');
    foreach (['//evil.invalid/v','/../version','/v?token=x','https://evil.invalid/v','/v/%2e%2e'] as $path) {
        $bad=$config; $bad['docs']['version_path']=$path; file_put_contents($file,json_encode($bad));
        configRejected(fn()=>connectionConfig($file));
    }
    echo "OK · $count profile/adapter checks\n";
} finally { unlink($file); }
