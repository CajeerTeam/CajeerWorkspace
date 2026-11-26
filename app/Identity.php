<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

// All credential mutations use the same serialized transaction as invitations and policies.
function identityEvent(PDO $db,int $uid,string $actor,string $action,string $reason=''): void {
    query($db,'INSERT INTO identity_history(event_key,user_id,actor,action,occurred_at,reason) VALUES(?,?,?,?,?,?)',
        [bin2hex(random_bytes(32)),$uid,$actor,$action,time(),$reason]);
}
function revokeStaffCredentials(PDO $db,int $uid): void {
    query($db,'UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);
    foreach (['staff_sessions','oidc_sessions'] as $table) query($db,"UPDATE $table SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL",[time(),$uid]);
}
function staffSessionCreate(PDO $db,array $u,string $kind,string $handle): void {
    $now=time();
    entity($db,'SELECT id FROM users WHERE id=? AND enabled=1 AND session_version=?',[$u['id'],$u['session_version']]);
    query($db,'DELETE FROM staff_sessions WHERE expires_at<=?',[$now]);
    query($db,'INSERT INTO staff_sessions(handle,user_id,kind,session_version,created_at,seen_at,expires_at) VALUES(?,?,?,?,?,?,?)',
        [hash('sha256',$handle),$u['id'],$kind,$u['session_version'],$now,$now,$now+43200]);
}
function staffHandle(): ?string {
    $raw=$_SESSION['staff_handle']??null;
    return is_string($raw)&&preg_match('/\A[a-f0-9]{64}\z/',$raw)?hash('sha256',$raw):null;
}
function staffSessionValid(PDO $db,array $u,bool $touch=false): bool {
    $handle=staffHandle(); if ($handle===null) return false;
    $now=time(); $args=[$handle,$u['id'],$u['session_version'],$_SESSION['auth_kind']??'', $now,$now-1800];
    $where='handle=? AND user_id=? AND session_version=? AND kind=? AND revoked_at IS NULL AND expires_at>? AND seen_at>=?';
    if ($touch) return query($db,"UPDATE staff_sessions SET seen_at=CASE WHEN seen_at>? THEN seen_at ELSE ? END WHERE $where",[$now,$now,...$args])->rowCount()===1;
    return (bool)query($db,"SELECT 1 FROM staff_sessions WHERE $where",$args)->fetchColumn();
}
function staffSessionEnd(PDO $db,string $handle,int $uid): void {
    foreach (['staff_sessions','oidc_sessions'] as $table) query($db,"UPDATE $table SET revoked_at=? WHERE handle=? AND user_id=? AND revoked_at IS NULL",[time(),$handle,$uid]);
}
function invalidateStaffLogin(PDO $db): void {
    try { if (staffHandle()!==null && !empty($_SESSION['uid'])) staffSessionEnd($db,staffHandle(),(int)$_SESSION['uid']); } finally { killLogin(); }
}
function activeStaffSessions(PDO $db,array $u): array {
    $now=time();
    $rows=query($db,"SELECT s.handle,s.kind,s.created_at,s.seen_at,s.expires_at FROM staff_sessions s WHERE s.user_id=? AND s.session_version=? AND s.revoked_at IS NULL AND s.expires_at>? AND s.seen_at>=? AND (s.kind<>'oidc' OR EXISTS (SELECT 1 FROM oidc_sessions o WHERE o.handle=s.handle AND o.user_id=s.user_id AND o.revoked_at IS NULL AND o.expires_at>?)) ORDER BY s.seen_at DESC,s.handle",[$u['id'],$u['session_version'],$now,$now-1800,$now])->fetchAll();
    return array_values(array_filter($rows,fn($r)=>match($r['kind']){'local'=>localAuthEnabled(),'oidc'=>oidcEnabled(),'emergency'=>installationAuthPolicy()==='required'&&emergencyAllowed($db,(int)$u['id']),default=>false}));
}
function requireIdentitySession(PDO $db,array $u): void {
    if (!mfaFresh() || (int)($_SESSION['uid']??0)!==(int)$u['id'] || (int)($_SESSION['v']??0)!==(int)$u['session_version'] || !staffSessionValid($db,$u)) throw new Problem('Требуется действующая сессия и свежее подтверждение TOTP.',403);
    entity($db,'SELECT id FROM users WHERE id=? AND enabled=1 AND session_version=?',[$u['id'],$u['session_version']]);
    if ($_SESSION['auth_kind']==='oidc' && !query($db,'SELECT 1 FROM oidc_sessions WHERE handle=? AND user_id=? AND revoked_at IS NULL AND expires_at>?',[staffHandle(),$u['id'],time()])->fetchColumn()) throw new Problem('Сессия ID отозвана.',403);
    if (!empty($u['organization_id'])) requireOrganizationAuth($db,(int)$u['organization_id'],$_SESSION['auth_kind'],(int)$u['id']);
}
function proveLocalPassword(PDO $db,array $u,mixed $password): void {
    requireIdentitySession($db,$u);
    if (!is_string($password)||strlen($password)>72||str_contains($password,"\0")) throw new Problem('Подтвердите локальный пароль.',403);
    $fresh=authenticate($db,$u['email'],$password,$_SERVER['REMOTE_ADDR']??'');
    if (!$fresh||(int)$fresh['id']!==(int)$u['id']||(int)$fresh['session_version']!==(int)$u['session_version']) throw new Problem('Локальный пароль не подтверждён.',403);
}
function identitySubjectAvailable(PDO $db,int $uid,string $sub): void {
    if ($sub===''||strlen($sub)>255||preg_match('/[[:cntrl:]]/',$sub)) throw new Problem('Некорректный subject.',403);
    if (query($db,'SELECT 1 FROM oidc_identities WHERE issuer=? AND subject=? AND user_id<>?',[ID_ISSUER,$sub,$uid])->fetchColumn() || query($db,'SELECT 1 FROM guest_invites WHERE issuer=? AND subject=? AND revoked_at IS NULL AND access_until>?',[ID_ISSUER,$sub,time()])->fetchColumn()) throw new Problem('Этот ID уже используется другой учётной записью.',409);
}
function assertUnlinkAllowed(PDO $db,int $uid): void {
    // Conservative: a member of any mandatory-SSO organization must retain ID.
    // Emergency access is not a replacement for an ordinary verified login.
    if (!localAuthEnabled() || query($db,"SELECT 1 FROM organization_members m JOIN organization_auth_policies p ON p.organization_id=m.organization_id WHERE m.user_id=? AND p.mode='required'",[$uid])->fetchColumn()) throw new Problem('Обязательный SkiF4er ID нельзя отвязать. Для восстановления обратитесь к оператору.',409);
}
function selfLinkBegin(PDO $db,array $u,mixed $password): never {
    if (!oidcEnabled()||($_SESSION['auth_kind']??null)!=='local') throw new Problem('Для привязки нужен локальный вход в смешанном режиме.',403);
    proveLocalPassword($db,$u,$password);
    if (query($db,'SELECT 1 FROM oidc_identities WHERE user_id=?',[$u['id']])->fetchColumn()) throw new Problem('ID уже привязан.',409);
    authThrottle($db,'identity-link:'.$u['id'],8);
    oidcBegin(null,null,null,['uid'=>(int)$u['id'],'version'=>(int)$u['session_version'],'handle'=>staffHandle(),'mfa_at'=>$_SESSION['mfa_at']]);
}
function completeSelfLink(PDO $db,array $u,array $link,array $claims,int $started): void {
    // Called inside the callback's transaction, after verified signature, nonce, PKCE and introspection.
    requireIdentitySession($db,$u);
    if (!oidcEnabled()||($_SESSION['auth_kind']??null)!=='local'||$started<time()-300||$link['uid']!==(int)$u['id']||$link['version']!==(int)$u['session_version']||$link['handle']!==staffHandle()||$link['mfa_at']!==($_SESSION['mfa_at']??null)||!is_int($claims['auth_time']??null)||$claims['auth_time']<$started-30||$claims['auth_time']>time()+30) throw new Problem('Повторная авторизация ID не подтверждена.',403);
    if (query($db,'SELECT 1 FROM oidc_identities WHERE user_id=?',[$u['id']])->fetchColumn()) throw new Problem('ID уже привязан.',409);
    identitySubjectAvailable($db,(int)$u['id'],$claims['sub']);
    query($db,'INSERT INTO oidc_identities(user_id,issuer,subject) VALUES(?,?,?)',[$u['id'],ID_ISSUER,$claims['sub']]);
    identityEvent($db,(int)$u['id'],'self','bind'); revokeStaffCredentials($db,(int)$u['id']);
}
function selfUnlink(PDO $db,array $u,mixed $password): void {
    proveLocalPassword($db,$u,$password);
    atomic($db,function() use($db,$u) {
        requireIdentitySession($db,$u); $uid=(int)$u['id']; assertUnlinkAllowed($db,$uid);
        if (!query($db,'DELETE FROM oidc_identities WHERE user_id=? AND issuer=?',[$uid,ID_ISSUER])->rowCount()) throw new Problem('Нет привязанного ID.',409);
        identityEvent($db,$uid,'self','unbind'); revokeStaffCredentials($db,$uid);
    });
}
function selfRevokeSession(PDO $db,array $u,string $handle): bool {
    if (!preg_match('/\A[a-f0-9]{64}\z/',$handle)) throw new Problem('Некорректная сессия.');
    return atomic($db,function() use($db,$u,$handle) {
        requireIdentitySession($db,$u); $uid=(int)$u['id'];
        $target=entity($db,'SELECT handle,revoked_at FROM staff_sessions WHERE handle=? AND user_id=?',[$handle,$uid]);
        if ($target['revoked_at']===null) { staffSessionEnd($db,$handle,$uid); identityEvent($db,$uid,'self','session.revoke'); }
        return $handle===staffHandle();
    });
}
function operatorRecoverIdentity(PDO $db,string $email,string $sub,string $reason): void {
    atomic($db,function() use($db,$email,$sub,$reason) {
        $u=entity($db,'SELECT id FROM users WHERE LOWER(email)=? AND enabled=1',[$email]); $uid=(int)$u['id'];
        identitySubjectAvailable($db,$uid,$sub);
        query($db,'DELETE FROM oidc_identities WHERE user_id=?',[$uid]);
        query($db,'INSERT INTO oidc_identities(user_id,issuer,subject) VALUES(?,?,?)',[$uid,ID_ISSUER,$sub]);
        identityEvent($db,$uid,'operator','recover',$reason); revokeStaffCredentials($db,$uid);
    });
}
function identityHistory(PDO $db,int $uid): array {
    return query($db,'SELECT actor,action,occurred_at,reason FROM identity_history WHERE user_id=? ORDER BY occurred_at DESC,event_key DESC LIMIT 200',[$uid])->fetchAll();
}
