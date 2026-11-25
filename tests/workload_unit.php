<?php
declare(strict_types=1);
namespace Workspace;
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;
function checkWorkload(bool $ok): void { if (!$ok) throw new \RuntimeException('Workload validation failed'); ++$GLOBALS['checks']; }
foreach ([[1,100,2],[30,101,51],[90,12345,18518],[10080,99999999999,16799999999832]] as [$minutes,$rate,$expected]) checkWorkload(timeCost($minutes,$rate)===$expected);
checkWorkload(weekStart('2024-12-30')==='2024-12-30'); checkWorkload(weekEnd('2024-12-30')==='2025-01-05');
checkWorkload(workloadInt('0',0,10080)===0); checkWorkload(workloadInt('10080',0,10080)===10080);
foreach ([fn()=>weekStart('2025-01-01'),fn()=>weekStart('2025-02-30'),fn()=>workloadInt('1.5',0,10080),fn()=>workloadInt(-1,0,10080),fn()=>workloadInt(10081,0,10080),fn()=>workloadInt([],0,10080)] as $call) {
    try { $call(); } catch (Problem $e) { checkWorkload($e->status===422); continue; } throw new \RuntimeException('Expected rejection');
}
foreach (['rate.save','time.approve','time.reject'] as $act) checkWorkload(sensitiveAction($act));
checkWorkload(!sensitiveAction('time.save'));
echo "OK · $checks проверок времени и ставок\n";
