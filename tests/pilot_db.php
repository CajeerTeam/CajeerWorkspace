<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$n=0;
function pilotExpect(bool $ok,string $name):void { if (!$ok) throw new \RuntimeException($name); ++$GLOBALS['n']; }
function pilotReject(callable $f,int $status):void { try { $f(); } catch (Problem $e) { pilotExpect($e->status===$status,'Unexpected error: '.$e->getMessage()); return; } throw new \RuntimeException('Expected rejection'); }
function pilotClean(string $d):void { foreach(new \FilesystemIterator($d) as $f) { if($f->isDir()&&!$f->isLink()) pilotClean($f->getPathname()); else unlink($f->getPathname()); } rmdir($d); }
$dir=sys_get_temp_dir().'/cw-pilot-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
putenv('WORKSPACE_DATA='.$dir); putenv('WORKSPACE_DB=sqlite'); putenv('WORKSPACE_CONFIG='.$dir.'/config.json'); putenv('WORKSPACE_PILOT=1');
putenv('WORKSPACE_AAPANEL_KEY=fixture-panel-key'); putenv('WORKSPACE_GITHUB_TOKEN=fixture-github-key');
$config=['_stand'=>['environment'=>'staging','name'=>'Test Pilot','owner'=>'Ops']];
foreach(CATALOG as $type=>$v) { $config[$type]=['owner'=>'Ops','launch_url'=>'https://'.$type.'.example.test']; if($type==='github') $config[$type]['repository']='Example/pilot-oss'; else { $config[$type]['base_url']='https://'.$type.'.example.test'; $config[$type]['installed_version']='1.2.3'; } }
file_put_contents($dir.'/config.json',json_encode($config,JSON_THROW_ON_ERROR));
$input=['organization'=>'Pilot','internal'=>'Internal secret','client'=>'External client secret','oss'=>'OSS pilot','client_name'=>'Client','oss_slug'=>'test-pilot','staff'=>[]];
foreach(PILOT_STAFF as $slot=>$v) $input['staff'][$slot]=['email'=>$slot.'@example.test','name'=>$slot,'password'=>'test-only-'.bin2hex(random_bytes(16))];
try {
    $catalog=pilotCatalog(connectionConfig()); pilotExpect(count($catalog)===9,'nine types');
    putenv('WORKSPACE_PILOT=0'); pilotReject(fn()=>pilotCatalog(connectionConfig()),403); putenv('WORKSPACE_PILOT=1');
    $bad=$config; $bad['_stand']['environment']='production'; file_put_contents($dir.'/config.json',json_encode($bad)); pilotReject(fn()=>pilotCatalog(connectionConfig()),403);
    $bad=$config; unset($bad['logs']); file_put_contents($dir.'/config.json',json_encode($bad)); pilotReject(fn()=>pilotCatalog(connectionConfig()),422);
    $bad=$config; $bad['other-engine']=$bad['engine']+['type'=>'engine']; file_put_contents($dir.'/config.json',json_encode($bad)); pilotReject(fn()=>pilotCatalog(connectionConfig()),422);
    file_put_contents($dir.'/config.json',json_encode($config));
    $bad=$input; $bad['staff']['legal']['email']=$bad['staff']['accountant']['email']; pilotReject(fn()=>pilotInput($bad),422);
    $bad=$input; $bad['staff']['legal']['password']=$bad['staff']['accountant']['password']; pilotReject(fn()=>pilotInput($bad),422);
    // Each independent simulated outage is visible without masking the other eight probes.
    $transport=function($m,$url,$headers,$form) use($catalog) { foreach($catalog as $type=>$c) if(connectionEndpoint($c)===$url) return match($type) {'github'=>['full_name'=>'Example/pilot-oss','archived'=>false,'default_branch'=>'main'],'aapanel'=>['version'=>'1.2.3','cpuNum'=>2,'memTotal'=>4096],default=>['status'=>CATALOG[$type][3],'version'=>'1.2.3','private'=>'NEVER_EXPORT']}; throw new Problem('unexpected fixture'); };
    $r=pilotProbes($catalog,$transport); pilotExpect(count(array_filter($r,fn($v)=>$v['status']==='ready'))===9,'all probes'); pilotExpect(!str_contains(json_encode($r),'NEVER_EXPORT'),'filtered report');
    foreach($catalog as $type=>$c) { $r=pilotProbes($catalog,fn($m,$url,$h,$f)=>$url===connectionEndpoint($c)?throw new Problem('PRIVATE_FAILURE'): $transport($m,$url,$h,$f)); pilotExpect($r[$type]['status']==='unavailable' && count(array_filter($r,fn($v)=>$v['status']==='ready'))===8,'independent outage '.$type); pilotExpect(!str_contains(json_encode($r),'PRIVATE_FAILURE'),'sanitized failure'); }
    $r=pilotProbes($catalog,fn($m,$url,$h,$f)=>$url===connectionEndpoint($catalog['engine'])?['status'=>'ready','version'=>'9.9.9']:$transport($m,$url,$h,$f)); pilotExpect($r['engine']['status']==='version_mismatch','version drift');
    $r=pilotProbes($catalog,fn($m,$url,$h,$f)=>$url===connectionEndpoint($catalog['engine'])?['status'=>'ready']:$transport($m,$url,$h,$f)); pilotExpect($r['engine']['status']==='declared_version_only'&&$r['engine']['observed_version']===null,'no invented observed version');
    $db=database(true); addUser($db,'owner@example.test','Owner','owner','test-only-owner-password',true);
    $bad=$input; $bad['staff']['legal']['email']='owner@example.test'; pilotReject(fn()=>pilotSeed($db,1,$bad),422);
    pilotExpect((int)$db->query('SELECT COUNT(*) FROM projects')->fetchColumn()===0,'failed seed atomic');
    $seed=pilotSeed($db,1,$input); $p=$seed['projects']; $s=$seed['staff'];
    pilotExpect(count($p)===3&&count($s)===count(PILOT_STAFF)&&count($seed['connections'])===9,'seed topology'); pilotExpect(!str_contains(json_encode($seed),'password'),'no credential output');
    pilotReject(fn()=>pilotSeed($db,1,$input),409);
    $check=pilotCheck($db,1); pilotExpect($check['topology_ready']&&!$check['guest_ready'],'guest not claimed');
    $bad=$config; $bad['engine']['base_url']='https://other-engine.example.test'; file_put_contents($dir.'/config.json',json_encode($bad));
    pilotExpect(in_array('engine:target_changed',pilotCheck($db,1)['issues'],true),'changed target blocked'); file_put_contents($dir.'/config.json',json_encode($config));
    $actors=[]; foreach($s as $slot=>$id) { $row=entity($db,'SELECT * FROM users WHERE id=?',[$id]); pilotExpect(password_verify($input['staff'][$slot]['password'],$row['password_hash']),'separate password '.$slot); pilotExpect((int)authenticate($db,$row['email'],$input['staff'][$slot]['password'],'127.0.0.1')['id']===$id,'separate authentication '.$slot); $actors[$slot]=organizationUser($db,['id'=>$id,'organization_id'=>1]); }
    $u=organizationUser($db,['id'=>1,'organization_id'=>1]);
    pilotReject(fn()=>allowedProject($db,$actors['developer'],$p['client']),404);
    pilotReject(fn()=>allowedProject($db,$actors['client_developer'],$p['internal']),404);
    pilotReject(fn()=>requireProjectRight($db,$actors['client_developer'],$p['client'],'finance'),403);
    pilotReject(fn()=>requireProjectRight($db,$actors['accountant'],$p['client'],'legal.write'),403);
    requireProjectRight($db,$actors['accountant'],$p['client'],'finance'); requireProjectRight($db,$actors['legal'],$p['client'],'legal.write'); requireProjectRight($db,$actors['operator'],$p['internal'],'operations');
    pilotReject(fn()=>requireProjectRight($db,$actors['lead'],$p['internal'],'operations'),403);
    pilotExpect(count(scopedConnections($db,$actors['developer'],$p['internal']))===5,'internal connections'); pilotExpect(count(scopedConnections($db,$actors['developer'],$p['oss']))===9,'OSS connections');
    $task=createTask($db,$actors['client_manager'],bin2hex(random_bytes(16)),$p['client'],['title'=>'Client task','assignee'=>(string)$s['client_developer'],'due_on'=>'2030-01-01','result_customer'=>'Client','acceptance_criteria'=>'Working feature']);
    pilotReject(fn()=>changeTask($db,$actors['developer'],bin2hex(random_bytes(16)),$task,1,'doing'),404);
    pilotExpect(changeTask($db,$actors['client_developer'],bin2hex(random_bytes(16)),$task,1,'doing')===$task,'assigned employee work');
    $pdf="%PDF-1.4\nPilot test document\n%%EOF"; $doc=storeDocument($db,$actors['legal'],bin2hex(random_bytes(16)),$p['client'],null,0,['title'=>'Pilot document','kind'=>'other'],$pdf);
    putenv('WORKSPACE_OIDC_REQUIRED=1'); putenv('WORKSPACE_OIDC_CLIENT_ID=fixture-client'); putenv('WORKSPACE_OIDC_CLIENT_SECRET=fixture-secret'); putenv('WORKSPACE_PUBLIC_URL=https://pilot.example.test/');
    pilotReject(fn()=>pilotGuestInvite($db,1,$doc,1,'legal@example.test',1),409);
    $token=pilotGuestInvite($db,1,$doc,1,'guest@example.test',1);
    pilotExpect(!pilotCheck($db,1)['guest_ready'],'invited not active');
    // Exercise the verified-claims boundary, not a real IdP login.
    pilotReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,['sub'=>'fixture-guest','email'=>'wrong@example.test','email_verified'=>true],hash('sha256',$token))),403);
    $guest=atomic($db,fn()=>oidcPrincipal($db,['sub'=>'fixture-guest','email'=>'guest@example.test','email_verified'=>true],hash('sha256',$token)));
    pilotExpect($guest['user']===null&&pilotCheck($db,1)['guest_ready'],'guest scoped accepted');
    pilotReject(fn()=>atomic($db,fn()=>oidcPrincipal($db,['sub'=>'fixture-guest','email'=>'guest@example.test','email_verified'=>true],hash('sha256',$token))),404);
    pilotExpect(retentionDocumentBytes($db,$doc,1)===$pdf,'guest pinned bytes');
    revokeGuest($db,$u,$p['client'],$guest['guest']); pilotExpect(!pilotCheck($db,1)['guest_ready'],'revoked guest not active');
    changeMembership($db,$u,'project',$p['internal'],$s['developer'],false); pilotExpect(!pilotCheck($db,1)['topology_ready'],'revoked membership detected');
    echo "OK · $n pilot checks\n";
} finally { $db=null; pilotClean($dir); }
