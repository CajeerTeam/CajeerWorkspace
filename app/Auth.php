<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

function authKey(): string {
    $key=getenv('WORKSPACE_MFA_KEY')?:'';
    if (!preg_match('/\A[a-fA-F0-9]{64}\z/',$key)) throw new Problem('Настройте WORKSPACE_MFA_KEY (32 случайных байта в hex).',503);
    return hex2bin($key);
}
function sealMfa(string $secret,int $uid): string {
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',authKey(),OPENSSL_RAW_DATA,$iv,$tag,'workspace-mfa:'.$uid,16);
    if ($cipher===false) throw new Problem('Не удалось защитить второй фактор.',503);
    return base64_encode($iv.$tag.$cipher);
}
function openMfa(string $box,int $uid): string {
    $raw=base64_decode($box,true);
    if ($raw===false||strlen($raw)!==48) throw new Problem('Повреждён второй фактор.',503);
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',authKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'workspace-mfa:'.$uid);
    if ($plain===false||strlen($plain)!==20) throw new Problem('Не удалось открыть второй фактор.',503);
    return $plain;
}
function base32Secret(string $bytes): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=0; $acc=0; $out='';
    foreach (str_split($bytes) as $ch) { $acc=($acc<<8)|ord($ch); $bits+=8; while ($bits>=5) { $bits-=5; $out.=$alphabet[($acc>>$bits)&31]; } $acc&=(1<<$bits)-1; }
    if ($bits) $out.=$alphabet[($acc<<(5-$bits))&31]; return $out;
}
function totp(string $secret,int $step): string {
    $mac=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$secret,true);
    $offset=ord($mac[19])&15; $value=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}
function matchingStep(string $secret,string $code,int $last,int $now): ?int {
    if (!preg_match('/\A[0-9]{6}\z/',$code)) return null;
    $step=intdiv($now,30);
    foreach ([$step,$step-1,$step+1] as $candidate) if ($candidate>$last&&$candidate>=0&&hash_equals(totp($secret,$candidate),$code)) return $candidate;
    return null;
}
function authThrottle(PDO $db,string $bucket,int $limit=8): void {
    $key=hash('sha256','auth:'.$bucket); $now=time();
    atomic($db,function() use($db,$key,$now,$limit) {
        query($db,'DELETE FROM login_attempts WHERE started<=?',[$now-600]);
        if ((int)query($db,'SELECT attempts FROM login_attempts WHERE bucket=?',[$key])->fetchColumn()>=$limit) throw new Problem('Слишком много попыток. Повторите через 10 минут.',429);
        query($db,'INSERT INTO login_attempts VALUES(?,?,1) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1',[$key,$now]);
    });
}
function mfaFresh(): bool { return (int)($_SESSION['mfa_at']??0)>=time()-300 && ($_SESSION['mfa_at']??0)<=time(); }
function sensitiveAction(string $act): bool {
    return in_array($act,['rate.save','time.approve','time.reject'],true) || in_array(explode('.',$act,2)[0],['cash','payment','document','connection','org','team','invite','guest','project','client','oss','github','release','infra','deploy','invoice','accounting','bank','econ','tax','contract','prep','sign','retention','engine','book','forum','lite','editorial','logs','bots','incident','reliability'],true)&&!in_array($act,['org.switch','project.create','invite.accept'],true);
}
function requireMfa(string $act): void {
    if (sensitiveAction($act)&&!mfaFresh()) throw new Problem('Подтвердите второй фактор в разделе «Безопасность», затем повторите действие.',403);
}
function emergencyAllowed(PDO $db,int $uid): bool {
    return getenv('WORKSPACE_EMERGENCY_LOCAL')==='1' && (bool)query($db,"SELECT 1 FROM emergency_accounts e JOIN organization_members m ON m.user_id=e.user_id JOIN mfa_credentials f ON f.user_id=e.user_id WHERE e.user_id=? AND m.role='owner'",[$uid])->fetchColumn();
}
function verifyMfa(PDO $db,array $user,string $code,bool $enroll): void {
    $uid=(int)$user['id']; authThrottle($db,'mfa:'.$uid);
    $pending=$_SESSION['mfa_pending']??null;
    atomic($db,function() use($db,$user,$uid,$code,$enroll,$pending) {
        entity($db,'SELECT id FROM users WHERE id=? AND enabled=1 AND session_version=?',[$uid,$user['session_version']]);
        if (staffHandle()!==null && !staffSessionValid($db,$user)) throw new Problem('Сессия отозвана.',403);
        $row=query($db,'SELECT * FROM mfa_credentials WHERE user_id=?',[$uid])->fetch();
        if ($enroll) {
            if ($row || !is_array($pending)||($pending['uid']??null)!==$uid||$pending['at']<time()-300||($_SESSION['born']??0)<time()-300) throw new Problem('Для подключения фактора войдите заново.',403);
            $secret=openMfa($pending['box'],$uid); $step=matchingStep($secret,$code,-1,time());
            if ($step===null) throw new Problem('Неверный код подтверждения.',403);
            query($db,'INSERT INTO mfa_credentials(user_id,secret_box,last_step) VALUES(?,?,?)',[$uid,$pending['box'],$step]);
            // Invalidate other sessions when the credential is first installed.
            query($db,'UPDATE users SET session_version=session_version+1 WHERE id=?',[$uid]);
            if (staffHandle()!==null) {
                query($db,'UPDATE staff_sessions SET session_version=session_version+1 WHERE handle=? AND user_id=? AND revoked_at IS NULL',[staffHandle(),$uid]);
                foreach (['staff_sessions','oidc_sessions'] as $table) query($db,"UPDATE $table SET revoked_at=? WHERE user_id=? AND handle<>? AND revoked_at IS NULL",[time(),$uid,staffHandle()]);
            }
        } else {
            if (!$row) throw new Problem('Сначала подключите второй фактор.',403);
            $step=matchingStep(openMfa($row['secret_box'],$uid),$code,(int)$row['last_step'],time());
            if ($step===null) throw new Problem('Неверный или уже использованный код.',403);
            query($db,'UPDATE mfa_credentials SET last_step=? WHERE user_id=?',[$step,$uid]);
        }
    });
    if ($enroll) $_SESSION['v']++;
    unset($_SESSION['mfa_pending']); session_regenerate_id(true); $_SESSION['mfa_at']=time(); $_SESSION['emergency_verified']=true;
}
function mfaPage(PDO $db,array $user): void {
    $uid=(int)$user['id']; $enrolled=(bool)query($db,'SELECT 1 FROM mfa_credentials WHERE user_id=?',[$uid])->fetchColumn();
    $secret=null;
    if (!$enrolled && ($_SESSION['born']??0)>=time()-300) {
        if (!isset($_SESSION['mfa_pending'])||$_SESSION['mfa_pending']['uid']!==$uid||$_SESSION['mfa_pending']['at']<time()-300) $_SESSION['mfa_pending']=['uid'=>$uid,'at'=>time(),'box'=>sealMfa(random_bytes(20),$uid)];
        $secret=base32Secret(openMfa($_SESSION['mfa_pending']['box'],$uid));
    }
    $canUnlink=true; try { assertUnlinkAllowed($db,$uid); } catch (Problem $e) { if ($e->status!==409) throw $e; $canUnlink=false; }
    $orgPolicy=empty($user['organization_id'])?'inherit':organizationAuthPolicy($db,(int)$user['organization_id']);
    pageView('security','Безопасность',$user,['enrolled'=>$enrolled,'secret'=>$secret,'canUnlink'=>$canUnlink,'orgPolicy'=>$orgPolicy,'linked'=>(bool)query($db,'SELECT 1 FROM oidc_identities WHERE user_id=? AND issuer=?',[$uid,ID_ISSUER])->fetchColumn(),'sessions'=>activeStaffSessions($db,$user),'history'=>identityHistory($db,$uid)]);
}
function issueGuest(PDO $db,array $user,int $project,int $document,int $revision,string $email,int $hours,?int $orderId=null): string {
    if (!oidcEnabled()) throw new Problem('Настройте SkiF4er ID для гостевого кабинета.',503);
    $email=normalizedEmail($email); if ($hours<1||$hours>168) throw new Problem('Срок приглашения: 1–168 часов.');
    return atomic($db,function() use($db,$user,$project,$document,$revision,$email,$hours,$orderId) {
        $u=orgOwner($db,$user); $p=allowedProject($db,$u,$project);
        if ($p['kind']!=='client') throw new Problem('Приглашение клиента доступно только для клиентского проекта.');
        entity($db,'SELECT d.id FROM documents d JOIN document_versions v ON v.document_id=d.id WHERE d.project_id=? AND d.id=? AND v.revision=?',[$project,$document,$revision]);
        retentionDocumentBytes($db,$document,$revision);
        if (query($db,'SELECT 1 FROM users WHERE LOWER(email)=?',[$email])->fetchColumn()) throw new Problem('Внутренние учётные записи не могут стать гостями.',409);
        if ($orderId!==null) { $order=clientOrder($db,$u,$orderId); if ((int)$order['project_id']!==$project || $order['contact_email']!==$email) throw new Problem('Приглашение должно соответствовать контакту заказа.',403); }
        $now=time(); query($db,'UPDATE guest_invites SET revoked_at=? WHERE project_id=? AND email=? AND accepted_at IS NULL AND revoked_at IS NULL',[$now,$project,$email]);
        $token=bin2hex(random_bytes(32)); $id=insertId($db,'INSERT INTO guest_invites(organization_id,project_id,document_id,revision,email,token_hash,created_by,expires_at) VALUES(?,?,?,?,?,?,?,?)',[$u['organization_id'],$project,$document,$revision,$email,hash('sha256',$token),$u['id'],$now+$hours*3600]);
        if ($orderId!==null) query($db,'INSERT INTO client_order_guests(guest_id,project_id,order_id) VALUES(?,?,?)',[$id,$project,$orderId]);
        auditEvent($db,$u,$project,'guest.issue',$id); return $token;
    });
}
function revokeGuest(PDO $db,array $user,int $project,int $id): void {
    atomic($db,function() use($db,$user,$project,$id) {
        $u=orgOwner($db,$user); $r=entity($db,'SELECT project_id FROM guest_invites WHERE id=? AND organization_id=? AND project_id=? AND revoked_at IS NULL',[$id,$u['organization_id'],$project]);
        query($db,'UPDATE guest_invites SET revoked_at=? WHERE id=?',[time(),$id]);
        query($db,'UPDATE oidc_sessions SET revoked_at=? WHERE guest_id=? AND revoked_at IS NULL',[time(),$id]);
        auditEvent($db,$u,(int)$r['project_id'],'guest.revoke',$id);
    });
}
function activeGuest(PDO $db,int $id): ?array {
    $r=query($db,"SELECT g.*,p.title project_title,d.title document_title,v.sha256 FROM guest_invites g JOIN projects p ON p.id=g.project_id AND p.organization_id=g.organization_id JOIN documents d ON d.id=g.document_id AND d.project_id=p.id JOIN document_versions v ON v.document_id=d.id AND v.revision=g.revision WHERE g.id=? AND g.accepted_at IS NOT NULL AND g.revoked_at IS NULL AND g.access_until>? AND p.kind='client'",[$id,time()])->fetch();
    return $r?:null;
}
function oidcPrincipal(PDO $db,array $claims,?string $guestHash,?string $staffHash=null): array {
    $sub=$claims['sub']; $now=time();
    $staff=query($db,'SELECT u.* FROM oidc_identities i JOIN users u ON u.id=i.user_id WHERE i.issuer=? AND i.subject=?',[ID_ISSUER,$sub])->fetch();
    if ($staffHash!==null) {
        $email=($claims['email_verified']??false)===true&&is_string($claims['email']??null)?normalizedEmail($claims['email']):null;
        $inv=entity($db,'SELECT * FROM invitations WHERE token_hash=? AND expires_at>? AND accepted_at IS NULL AND revoked_at IS NULL',[$staffHash,$now]);
        if ($email===null||$email!==$inv['email']||$guestHash!==null) throw new Problem('Приглашение требует подтверждённую почту сотрудника.',403);
        if ($staff) {
            if (!$staff['enabled']||strtolower($staff['email'])!==$email) throw new Problem('Приглашение предназначено другой учётной записи.',403);
        } else {
            if (query($db,'SELECT 1 FROM users WHERE LOWER(email)=?',[$email])->fetchColumn()||query($db,'SELECT 1 FROM guest_invites WHERE issuer=? AND subject=? AND revoked_at IS NULL AND access_until>?',[ID_ISSUER,$sub,$now])->fetchColumn()) throw new Problem('Существующую учётную запись должен привязать оператор.',403);
            $name=is_string($claims['name']??null)&&trim($claims['name'])!==''?$claims['name']:$email;
            $uid=insertId($db,'INSERT INTO users(email,name,role,password_hash) VALUES(?,?,?,?)',[$email,word($name,90,'Имя'),'viewer',passwordHash(bin2hex(random_bytes(32)))]);
            query($db,'INSERT INTO oidc_identities(user_id,issuer,subject) VALUES(?,?,?)',[$uid,ID_ISSUER,$sub]);
            identityEvent($db,$uid,'invitation','bind');
            $staff=entity($db,'SELECT * FROM users WHERE id=?',[$uid]);
        }
        if (query($db,'SELECT 1 FROM organization_members WHERE organization_id=? AND user_id=?',[$inv['organization_id'],$staff['id']])->fetchColumn()) throw new Problem('Участник уже зарегистрирован.',409);
        query($db,'INSERT INTO organization_members VALUES(?,?,?)',[$inv['organization_id'],$staff['id'],$inv['role']]);
        query($db,'UPDATE invitations SET accepted_at=?,accepted_by=? WHERE id=?',[$now,$staff['id'],$inv['id']]);
        auditEvent($db,['organization_id'=>$inv['organization_id'],'id'=>$staff['id']],null,'invite.accept',(int)$inv['id']);
        return ['user'=>$staff,'guest'=>null,'organization'=>(int)$inv['organization_id']];
    }
    if ($staff) {
        if (!$staff['enabled']||$guestHash!==null) throw new Problem('Нет доступа к этому входу.',403);
        return ['user'=>$staff,'guest'=>null];
    }
    if ($guestHash!==null) {
        $email=($claims['email_verified']??false)===true&&is_string($claims['email']??null)?normalizedEmail($claims['email']):null;
        $g=entity($db,'SELECT * FROM guest_invites WHERE token_hash=? AND expires_at>? AND accepted_at IS NULL AND revoked_at IS NULL',[$guestHash,$now]);
        if ($email===null||$email!==$g['email']||query($db,'SELECT 1 FROM users WHERE LOWER(email)=?',[$email])->fetchColumn()) throw new Problem('Приглашение требует подтверждённую почту клиента.',403);
        if (query($db,'SELECT 1 FROM guest_invites WHERE issuer=? AND subject=? AND revoked_at IS NULL AND access_until>?',[ID_ISSUER,$sub,$now])->fetchColumn()) throw new Problem('У клиента уже есть активный кабинет.',409);
        query($db,'UPDATE guest_invites SET issuer=?,subject=?,accepted_at=?,access_until=? WHERE id=?',[ID_ISSUER,$sub,$now,$now+30*86400,$g['id']]);
        if (!activeGuest($db,(int)$g['id'])) throw new Problem('Приглашённая редакция недоступна.',403);
        return ['user'=>null,'guest'=>(int)$g['id']];
    }
    $id=query($db,'SELECT id FROM guest_invites WHERE issuer=? AND subject=? AND revoked_at IS NULL AND access_until>?',[ID_ISSUER,$sub,$now])->fetchColumn();
    if (!$id||!activeGuest($db,(int)$id)) throw new Problem('Учётная запись ID не привязана к Workspace.',403);
    return ['user'=>null,'guest'=>(int)$id];
}
// Validate the bound identity and membership again inside the callback transaction.
function oidcSwitchPrincipal(PDO $db,array $principal,array $switch): array {
    if (!$principal['user'] || (int)$principal['user']['id']!==$switch['uid'] || (int)$principal['user']['session_version']!==$switch['version']) throw new Problem('SkiF4er ID должен принадлежать исходной учётной записи.',403);
    organizationUser($db,$principal['user']+['auth_kind'=>'oidc'],$switch['org']);
    $principal['organization']=$switch['org'];
    return $principal;
}
function oidcCallback(PDO $db,?array $current=null): never {
    if (!oidcEnabled()) throw new Problem('Вход ID отключён политикой установки.',403);
    $tx=$_SESSION['oidc_tx']??null; unset($_SESSION['oidc_tx']);
    if (!is_array($tx)||$tx['at']<time()-300||!is_string($_GET['state']??null)||!hash_equals($tx['state'],$_GET['state'])||isset($_GET['error'])||(isset($_GET['iss'])&&$_GET['iss']!==ID_ISSUER)||!is_string($_GET['code']??null)||strlen($_GET['code'])>4096||$_GET['code']==='') throw new Problem('Вход ID не подтверждён. Начните заново.',401);
    $switch=$tx['switch']??null; $link=$tx['link']??null;
    if ($switch!==null && (!$current || ($current['auth_kind']??null)!=='local' || (int)$current['id']!==$switch['uid'] || (int)$current['session_version']!==$switch['version'])) throw new Problem('Исходная сессия больше не действительна.',403);
    if ($link!==null && (!$current || $switch!==null)) throw new Problem('Исходная сессия больше не действительна.',403);
    if ($switch===null && $link===null && $current) throw new Problem('Недопустимый callback.',403);
    $cfg=oidcConfig(); $response=oidcHttp(oidcDiscovery()['token_endpoint'],['grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$cfg['callback'],'code_verifier'=>$tx['verifier']]);
    $tokens=oidcTokenSet($response); $claims=oidcClaims($tokens['id'],$tx['nonce']);
    $record=['subject'=>$claims['sub'],'sid'=>$claims['sid']??null]; oidcOnline($tokens,$record);
    $handle=bin2hex(random_bytes(32));
    $principal=atomic($db,function() use($db,$tx,$claims,$record,$handle,$switch,$link,$current) {
        if (query($db,'SELECT 1 FROM oidc_logout_events WHERE issuer=? AND issued_at>=? AND ((sid IS NOT NULL AND sid=? AND (subject IS NULL OR subject=?)) OR (sid IS NULL AND subject=?))',[ID_ISSUER,$claims['iat'],$record['sid'],$record['subject'],$record['subject']])->fetchColumn()) throw new Problem('Сессия ID уже завершена.',401);
        if ($link!==null) { completeSelfLink($db,$current,$link,$claims,$tx['at']); return null; }
        $p=oidcPrincipal($db,$claims,$tx['guest'],$tx['staff']??null);
        if ($switch!==null) {
            if (!$current || !staffSessionValid($db,$current)) throw new Problem('Исходная сессия отозвана.',403);
            $p=oidcSwitchPrincipal($db,$p,$switch);
        }
        query($db,'DELETE FROM oidc_sessions WHERE expires_at<=?',[time()]);
        query($db,'INSERT INTO oidc_sessions(handle,issuer,subject,sid,user_id,guest_id,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?)',[hash('sha256',$handle),ID_ISSUER,$record['subject'],$record['sid'],$p['user']['id']??null,$p['guest'],$claims['iat'],time()+43200]);
        if ($p['user']) staffSessionCreate($db,$p['user'],'oidc',$handle);
        if ($switch!==null && staffHandle()!==null) staffSessionEnd($db,staffHandle(),$switch['uid']);
        return $p;
    });
    if ($principal===null) { killLogin(); $_SESSION['notice']='SkiF4er ID привязан. Войдите заново.'; go('login'); }
    killLogin(); $_SESSION+=['auth_kind'=>'oidc','oidc_handle'=>$handle,'oidc_tokens'=>$tokens,'born'=>time(),'seen'=>time()];
    if ($principal['user']) { $_SESSION['staff_handle']=$handle; $_SESSION['uid']=(int)$principal['user']['id']; $_SESSION['v']=(int)$principal['user']['session_version']; if (isset($principal['organization'])) $_SESSION['org']=$principal['organization']; go('overview'); }
    $_SESSION['guest_id']=$principal['guest']; go('guest');
}
function validateOidcSession(PDO $db): bool {
    if (!oidcEnabled()||!is_string($_SESSION['oidc_handle']??null)||!is_array($_SESSION['oidc_tokens']??null)) return false;
    $r=query($db,'SELECT * FROM oidc_sessions WHERE handle=? AND issuer=? AND expires_at>? AND revoked_at IS NULL',[hash('sha256',$_SESSION['oidc_handle']),ID_ISSUER,time()])->fetch();
    if (!$r || ($r['user_id']!==null&&((int)$r['user_id']!==(int)($_SESSION['uid']??0)||!query($db,'SELECT 1 FROM oidc_identities WHERE user_id=? AND issuer=? AND subject=?',[$r['user_id'],$r['issuer'],$r['subject']])->fetchColumn())) || ($r['guest_id']!==null&&((int)$r['guest_id']!==(int)($_SESSION['guest_id']??0)||!activeGuest($db,(int)$r['guest_id'])))) return false;
    try { oidcOnline($_SESSION['oidc_tokens'],$r); } catch (\Throwable) { return false; }
    return true;
}
function authLogout(PDO $db): never {
    $kind=$_SESSION['auth_kind']??null; $id=$_SESSION['oidc_tokens']['id']??null;
    try { if (staffHandle()!==null && !empty($_SESSION['uid'])) staffSessionEnd($db,staffHandle(),(int)$_SESSION['uid']); if (is_string($_SESSION['oidc_handle']??null)) query($db,'UPDATE oidc_sessions SET revoked_at=? WHERE handle=? AND revoked_at IS NULL',[time(),hash('sha256',$_SESSION['oidc_handle'])]); } finally { killLogin(); }
    if ($kind==='oidc'&&is_string($id)&&oidcEnabled()) {
        try { $d=oidcDiscovery(); $cfg=oidcConfig(); header('Location: '.$d['end_session_endpoint'].'?'.http_build_query(['id_token_hint'=>$id,'client_id'=>$cfg['id'],'post_logout_redirect_uri'=>$cfg['logout']],'','&',PHP_QUERY_RFC3986),true,303); exit; } catch (\Throwable) {}
    }
    go('login');
}
function backchannelLogout(PDO $db): void {
    $token=$_POST['logout_token']??null;
    if (!is_string($token)||!oidcEnabled()) throw new Problem('Logout token отсутствует.',400);
    try { $claims=oidcClaims($token,null,true); } catch (Problem $e) { throw new Problem('Logout token не подтверждён.', $e->status===503?503:400); }
    applyBackchannelLogout($db,$claims);
    http_response_code(200); header('Content-Type: text/plain; charset=utf-8'); echo 'OK';
}
function applyBackchannelLogout(PDO $db,array $claims): void {
    atomic($db,function() use($db,$claims) {
        query($db,'DELETE FROM oidc_logout_events WHERE issued_at<?',[time()-600]);
        $hash=hash('sha256',ID_ISSUER."\0".$claims['jti']);
        if (!query($db,'INSERT INTO oidc_logout_events(event_hash,issuer,subject,sid,issued_at) VALUES(?,?,?,?,?) ON CONFLICT DO NOTHING',[$hash,ID_ISSUER,$claims['sub']??null,$claims['sid']??null,$claims['iat']])->rowCount()) return;
        $where='issuer=? AND revoked_at IS NULL'; $args=[ID_ISSUER];
        if (isset($claims['sid'])) { $where.=' AND sid=?'; $args[]=$claims['sid']; } else { $where.=' AND created_at<=?'; $args[]=$claims['iat']; }
        if (isset($claims['sub'])) { $where.=' AND subject=?'; $args[]=$claims['sub']; }
        query($db,"UPDATE oidc_sessions SET revoked_at=? WHERE $where",[time(),...$args]);
        query($db,"UPDATE staff_sessions SET revoked_at=? WHERE kind='oidc' AND revoked_at IS NULL AND handle IN (SELECT handle FROM oidc_sessions WHERE revoked_at IS NOT NULL)",[time()]);
    });
}
function guestPage(PDO $db,string $page): void {
    $g=activeGuest($db,(int)$_SESSION['guest_id']); if (!$g) { killLogin(); go('login'); }
    $order=guestClientOrder($db,(int)$g['id']);
    if ($page==='guest.result') {
        if (!$order) throw new Problem('Нет доступа к заказу.',403);
        $r=entity($db,'SELECT v.document_id,v.revision FROM client_deliveries d JOIN client_stages s ON s.id=d.stage_id JOIN document_versions v ON v.document_id=d.document_id AND v.revision=d.document_revision JOIN client_order_guests l ON l.order_id=s.order_id JOIN guest_invites g ON g.id=l.guest_id WHERE d.id=? AND l.guest_id=? AND g.revoked_at IS NULL AND g.access_until>?',[numberId($_GET['id']??0),$g['id'],time()]);
        $bytes=retentionGuestBytes($db,$g,$r,'guest.result');
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="client-result.pdf"'); header('Content-Length: '.strlen($bytes)); echo $bytes; return;
    }
    if ($page==='guest.download') {
        // Only the pinned revision; do not accept document or project IDs from the browser.
        $r=entity($db,'SELECT '.(isPostgres($db)?"encode(v.bytes,'hex')":"v.bytes").' bytes,v.sha256 FROM document_versions v JOIN guest_invites g ON g.document_id=v.document_id AND g.revision=v.revision WHERE g.id=? AND g.revoked_at IS NULL AND g.access_until>?',[$g['id'],time()]);
        $bytes=retentionGuestBytes($db,$g,$r,'guest.download');
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="client-document.pdf"'); header('Content-Length: '.strlen($bytes)); echo $bytes; return;
    }
    if (!in_array($page,['guest','overview','login'],true)) throw new Problem('Гостевой доступ ограничен согласованным проектом.',403);
    pageView('guest','Кабинет клиента',null,['grant'=>$g,'order'=>$order,'offers'=>$order?clientOrderDetails($db,$order,true):[]]);
}
