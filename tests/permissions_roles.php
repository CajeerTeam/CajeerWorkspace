<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;
foreach (PROJECT_RIGHTS as $right=>$roles) {
    foreach (array_keys(PROJECT_ROLES) as $role) {
        if (roleAllows($role,$right)!==in_array($role,$roles,true)) throw new \RuntimeException("Unexpected role $role / $right");
        ++$checks;
    }
    if (!roleAllows('owner',$right)) throw new \RuntimeException('Owner permission missing');
    ++$checks;
}
foreach ([['payment_creator','payment.approve'],['payment_approver','payment.create'],['finance_reader','payment.create'],['legal_reader','legal.write'],['integration_reader','operations'],['auditor','finance'],['auditor','legal']] as [$role,$right]) {
    if (roleAllows($role,$right)) throw new \RuntimeException('Separation of duties failed');
    ++$checks;
}
echo "OK · $checks role checks\n";
