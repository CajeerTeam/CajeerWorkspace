<?php
declare(strict_types=1);
namespace Workspace;
use PDO;

const ORG_AUTH_POLICIES=['inherit'=>'Наследовать политику установки','required'=>'Обязательный SkiF4er ID'];
const INSTALL_AUTH_POLICIES=['local'=>'Локальный вход','mixed'=>'Локальный + SkiF4er ID','required'=>'Обязательный SkiF4er ID'];

function organizationAuthPolicy(PDO $db,int $org): string {
    return query($db,'SELECT mode FROM organization_auth_policies WHERE organization_id=?',[$org])->fetchColumn() ?: 'inherit';
}
function authMethodAllowed(string $installation,string $organization,string $kind,bool $emergencyOwner=false): bool {
    // Local-only installation and mandatory organization SSO are incompatible: fail closed.
    if ($kind==='oidc') return in_array($installation,['mixed','required'],true);
    if ($organization!=='inherit') return false;
    if ($kind==='local') return in_array($installation,['local','mixed'],true);
    return $kind==='emergency' && $installation==='required' && $emergencyOwner;
}
function organizationAuthAllowed(PDO $db,int $org,string $kind,int $uid=0): bool {
    return authMethodAllowed(installationAuthPolicy(),organizationAuthPolicy($db,$org),$kind,
        $kind==='emergency' && emergencyAllowed($db,$uid) && (bool)query($db,"SELECT 1 FROM organization_members WHERE organization_id=? AND user_id=? AND role='owner'",[$org,$uid])->fetchColumn());
}
function requireOrganizationAuth(PDO $db,int $org,string $kind,int $uid=0): void {
    if (!organizationAuthAllowed($db,$org,$kind,$uid)) throw new Problem('Политика организации требует другой способ входа. Войдите через SkiF4er ID, если он разрешён установкой.',403);
}
function firstAuthOrganization(PDO $db,array $orgs,string $kind,int $uid): ?int {
    foreach ($orgs as $org) if (organizationAuthAllowed($db,(int)$org['id'],$kind,$uid)) return (int)$org['id'];
    return null;
}
function setOrganizationAuthPolicy(PDO $db,array $user,string $mode): void {
    choice($mode,ORG_AUTH_POLICIES);
    if (!mfaFresh()) throw new Problem('Подтвердите второй фактор.',403);
    atomic($db,function() use($db,$user,$mode) {
        $u=orgOwner($db,$user); $org=(int)$u['organization_id'];
        $previous=organizationAuthPolicy($db,$org);
        if ($previous===$mode) return;
        if (($user['auth_kind']??null)!=='oidc' || ($_SESSION['auth_kind']??null)!=='oidc' || (int)($_SESSION['uid']??0)!==(int)$u['id'] || (int)($_SESSION['v']??0)!==(int)$u['session_version']) throw new Problem('Изменять политику входа может владелец после входа через SkiF4er ID и подтверждения MFA.',403);
        if (!oidcEnabled()) throw new Problem('SkiF4er ID запрещён политикой установки.',409);
        if ($mode==='required') {
            // Ensure the current owner has a durable operator-approved identity before tightening.
            entity($db,'SELECT user_id FROM oidc_identities WHERE user_id=? AND issuer=?',[$u['id'],ID_ISSUER]);
            query($db,"INSERT INTO organization_auth_policies(organization_id,mode) VALUES(?,'required') ON CONFLICT DO NOTHING",[$org]);
        } else query($db,'DELETE FROM organization_auth_policies WHERE organization_id=?',[$org]);
        auditEvent($db,$u,null,'org.auth-policy',$org,$previous.' -> '.$mode);
    });
}

// Administrative diagnostic; no credentials or subject values leave the process.
function authPolicyReport(PDO $db): array {
    $mode=installationAuthPolicy(); $rows=[]; $ok=true;
    foreach (query($db,"SELECT o.id,o.name,COALESCE(p.mode,'inherit') mode FROM organizations o LEFT JOIN organization_auth_policies p ON p.organization_id=o.id ORDER BY o.id")->fetchAll() as $r) {
        $id=(int)$r['id']; $effective=$r['mode']==='required'?'required':$mode;
        $owners=(int)query($db,"SELECT COUNT(*) FROM organization_members m JOIN users u ON u.id=m.user_id JOIN oidc_identities i ON i.user_id=u.id AND i.issuer=? WHERE m.organization_id=? AND m.role='owner' AND u.enabled=1",[ID_ISSUER,$id])->fetchColumn();
        $status=$mode==='local'&&$effective==='required'?'blocked_by_installation':($effective==='required'&&!$owners?'no_bound_owner':'configured');
        if ($status!=='configured') $ok=false;
        $rows[]=['id'=>$id,'name'=>$r['name'],'policy'=>$r['mode'],'effective'=>$effective,'status'=>$status,'bound_owners'=>$owners];
    }
    return ['installation'=>$mode,'ok'=>$ok,'organizations'=>$rows];
}
