<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const AUTH_SCENARIOS=['staff_login','guest_invite','guest_login','emergency_login','staff_logout','guest_logout','staff_backchannel','guest_backchannel'];
const AUTH_EVIDENCE_TTL=30*86400;
const AUTH_ACCEPTANCE_WINDOW=7*86400;

// Bind evidence to this confidential client and installation without publishing credentials.
// The policy is recorded separately: switching mixed/required for a drill is legitimate.
function authConfigFingerprint(): string {
    $cfg=oidcConfig();
    return hash_hmac('sha256',serialize([ID_ISSUER,$cfg['id'],$cfg['secret'],$cfg['callback']]),authKey());
}
function authEvidenceInsert(PDO $db,string $scenario,string $handle,string $config,int $principal,int $org,string $mode,int $at): void {
    query($db,'DELETE FROM auth_scenario_evidence WHERE occurred_at<?',[$at-AUTH_EVIDENCE_TTL]);
    query($db,'INSERT INTO auth_scenario_evidence(scenario,session_hash,config_hash,principal_id,organization_id,mode,occurred_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(scenario,session_hash) DO NOTHING',[$scenario,$handle,$config,$principal,$org,$mode,$at]);
}
// Invoked only on a validated protected GET, following a new callback or emergency password login.
function observeAuthRequest(PDO $db,?array $user,string $page): void {
    $pending=$_SESSION['auth_evidence_pending']??null;
    if (!is_array($pending)) return;
    $kind=$_SESSION['auth_kind']??null;
    if ($kind==='emergency' && (!mfaFresh() || !($_SESSION['emergency_verified']??false))) return;
    if ($user && $page==='overview' && !empty($user['organization_id']) && in_array($kind,['oidc','emergency'],true)) {
        $scenario=$kind==='emergency'?'emergency_login':'staff_login';
        $handle=staffHandle(); $principal=(int)$user['id']; $org=(int)$user['organization_id'];
    } elseif (!$user && $page==='guest' && $kind==='oidc' && !empty($_SESSION['guest_id'])) {
        $guest=activeGuest($db,(int)$_SESSION['guest_id']); if (!$guest) return;
        $scenario=($pending['invite']??false)?'guest_invite':'guest_login';
        $handle=hash('sha256',$_SESSION['oidc_handle']); $principal=(int)$guest['id']; $org=(int)$guest['organization_id'];
    } else return;
    if ($handle===null) return;
    // Diagnostics must not make a valid emergency session depend on the network or IdP.
    try { $config=authConfigFingerprint(); } catch (\Throwable) { return; }
    if (!hash_equals($config,$pending['config']??'')) { unset($_SESSION['auth_evidence_pending']); return; }
    $mode=installationAuthPolicy(); $now=time();
    try { atomic($db,function() use($db,$user,$scenario,$handle,$config,$principal,$org,$mode,$now,$kind) {
        if ($user && (!staffSessionValid($db,$user) || !organizationAuthAllowed($db,$org,$kind,$principal))) return;
        if ($kind==='oidc' && !query($db,'SELECT 1 FROM oidc_sessions WHERE handle=? AND revoked_at IS NULL AND expires_at>? AND issuer=?',[$handle,$now,ID_ISSUER])->fetchColumn()) return;
        if (!$user && !activeGuest($db,$principal)) return;
        authEvidenceInsert($db,$scenario,$handle,$config,$principal,$org,$mode,$now);
    }); } catch (\Throwable) { error_log('CajeerWorkspace authentication evidence write failed'); return; }
    unset($_SESSION['auth_evidence_pending']);
}
// Called in the same transaction as an actual session revocation. No proof for an unmatched/replayed token.
function observeAuthEnd(PDO $db,string $handle,string $event): void {
    try { $config=authConfigFingerprint(); } catch (\Throwable) { return; }
    $row=query($db,"SELECT * FROM auth_scenario_evidence WHERE session_hash=? AND config_hash=? AND scenario IN ('staff_login','guest_invite','guest_login') AND occurred_at>=?",[$handle,$config,time()-AUTH_EVIDENCE_TTL])->fetch();
    if (!$row) return;
    $scenario=($row['scenario']==='staff_login'?'staff':'guest').'_'.$event;
    authEvidenceInsert($db,$scenario,$handle,$config,(int)$row['principal_id'],(int)$row['organization_id'],$row['mode'],time());
}
// Called inside the revocation transaction; diagnostic failures must not prevent revocation.
function observeAuthEndSafely(PDO $db,string $handle,string $event): void {
    $db->exec('SAVEPOINT auth_evidence_write');
    try { observeAuthEnd($db,$handle,$event); }
    catch (\Throwable) {
        $db->exec('ROLLBACK TO SAVEPOINT auth_evidence_write');
        error_log('CajeerWorkspace authentication evidence write failed');
    }
    $db->exec('RELEASE SAVEPOINT auth_evidence_write');
}
function authEvidenceSummary(array $rows,int $now): array {
    $last=array_fill_keys(AUTH_SCENARIOS,null); $recent=[];
    foreach ($rows as $r) {
        $scenario=$r['scenario']; $at=(int)$r['occurred_at'];
        if (!array_key_exists($scenario,$last)||$at>$now||$at<$now-AUTH_EVIDENCE_TTL) continue;
        if ($last[$scenario]===null||$at>$last[$scenario]) $last[$scenario]=$at;
        if ($at>=$now-AUTH_ACCEPTANCE_WINDOW) $recent[$scenario][$r['session_hash']]=$r;
    }
    $staff=[];
    foreach ($recent['staff_login']??[] as $handle=>$r) {
        $end=$recent['staff_logout'][$handle]??null;
        if ($end && (int)$end['occurred_at']>=(int)$r['occurred_at']) $staff[]=$r;
    }
    $pair=false; $first=$staff[0]??null; $differentUser=false; $differentOrg=false;
    foreach ($staff as $r) {
        $userDiff=(int)$r['principal_id']!==(int)$first['principal_id']; $orgDiff=(int)$r['organization_id']!==(int)$first['organization_id'];
        if ($userDiff&&$orgDiff) { $pair=true; break; }
        $differentUser=$differentUser||$userDiff; $differentOrg=$differentOrg||$orgDiff;
        if ($differentUser&&$differentOrg) { $pair=true; break; }
    }
    $guest=false;
    foreach ($recent['guest_invite']??[] as $handle=>$r) {
        $end=$recent['guest_logout'][$handle]??null;
        if ($end && (int)$end['occurred_at']>=(int)$r['occurred_at']) { $guest=true; break; }
    }
    $checks=['two_staff_distinct_organizations_login_logout'=>$pair,'invited_guest_login_logout'=>$guest,'emergency_owner_totp'=>!empty($recent['emergency_login']),'signed_backchannel_revoked_observed_session'=>!empty($recent['staff_backchannel'])||!empty($recent['guest_backchannel'])];
    return ['status'=>'available','last_success_utc'=>array_map(fn($v)=>$v===null?null:gmdate('Y-m-d\TH:i:s\Z',$v),$last),'window_seconds'=>AUTH_ACCEPTANCE_WINDOW,'checks'=>$checks,'observed_ok'=>!in_array(false,$checks,true)];
}
// Probe config, discovery and local evidence independently, including when the database is unavailable.
function authDiagnostic(bool $acceptance=false): array {
    $report=['version'=>VERSION,'issuer'=>ID_ISSUER,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),'mode'=>null,'configuration'=>['status'=>'invalid'],'discovery'=>['status'=>'not_checked'],'evidence'=>['status'=>'not_checked','last_success_utc'=>array_fill_keys(AUTH_SCENARIOS,null),'observed_ok'=>false],'ok'=>false];
    $config=null; $mode=null; $mfa=false;
    try { $mode=installationAuthPolicy(); $report['mode']=$mode; } catch (Problem) {}
    try { authKey(); $mfa=true; } catch (Problem) {}
    $report['configuration']['mfa_key']=$mfa?'configured':'invalid';
    $report['configuration']['emergency_flag']=getenv('WORKSPACE_EMERGENCY_LOCAL')==='1'?'enabled':'disabled';
    if ($mode!==null) {
        if ($mode==='local') $report['configuration']['oidc_client']='disabled';
        else {
            try { $cfg=oidcConfig(); $report['configuration']['oidc_client']='configured'; $report['redirect_uri']=$cfg['callback']; $report['post_logout_redirect_uri']=$cfg['logout']; if ($mfa) $config=authConfigFingerprint(); }
            catch (\Throwable) { $report['configuration']['oidc_client']='invalid'; }
        }
        if ($mfa && ($mode==='local'||$config!==null)) $report['configuration']['status']='valid';
    }
    if ($mode==='local') $report['discovery']['status']='disabled';
    elseif (!function_exists('curl_init')) $report['discovery']['status']='runtime_missing_curl';
    else {
        try { $metadata=oidcHttp(ID_ISSUER.'/.well-known/openid-configuration');
            try { validateOidcDiscovery($metadata); $report['discovery']['status']='available'; } catch (Problem) { $report['discovery']['status']='invalid_metadata'; }
        }
        catch (Problem) { $report['discovery']['status']='unavailable_or_invalid_response'; }
        catch (\Throwable) { $report['discovery']['status']='probe_failed'; }
    }
    if ($config!==null) {
        try { $db=database(); $now=time(); $report['evidence']=authEvidenceSummary(query($db,'SELECT * FROM auth_scenario_evidence WHERE config_hash=? AND occurred_at>=? AND occurred_at<=?',[$config,$now-AUTH_EVIDENCE_TTL,$now])->fetchAll(),$now); }
        catch (\Throwable) { $report['evidence']['status']='database_unavailable'; }
    } else $report['evidence']['status']='configuration_unavailable';
    $report['manual_acceptance_required']=['provider_logout_and_relogin','realm_signing_key_rotation','provider_outage_required_policy_and_recovery','guest_cross_project_denial','emergency_flag_disabled_after_drill'];
    $report['ok']=$report['configuration']['status']==='valid'&&in_array($report['discovery']['status'],['available','disabled'],true)&&(!$acceptance||($mode!=='local'&&$report['configuration']['emergency_flag']==='disabled'&&$report['evidence']['observed_ok']));
    return $report;
}
