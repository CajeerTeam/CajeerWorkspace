<?php namespace Workspace;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$nav = ['overview'=>'Обзор','projects'=>'Проекты'];
if ($user && !empty($user['organization_id'])) {
    $nav['search']='Поиск'; $nav['notifications']='Напоминания'; if($user['role']==='owner') {$nav['digest']='Сводка владельцу';$nav['reliability']='Надёжность интеграций';}
    if ($user['role']==='owner') $nav['clients']='Клиенты и заказы';
    if (($user['nav_rights']['finance']??can($user,'finance'))) { $nav['finance']='Финансы'; $nav['payments']='Платежи'; $nav['invoices']='Счета'; $nav['economics']='Экономика'; $nav['approved_acts']='Принятые акты'; }
    if (in_array($user['role'],['owner','accountant'],true)) { $nav['accounting']='Бухгалтерия'; $nav['bank']='Банк'; $nav['tax']='UK VAT'; }
    if (($user['nav_rights']['legal']??can($user,'legal'))) { $nav['documents']='Документы'; $nav['contracts']='Договоры'; $nav['preparation']='Согласование'; $nav['signing']='Подписание'; $nav['retention']='Хранение'; }
    if (($user['nav_rights']['connections']??can($user,'connections'))) { $nav['connections']='Подключения'; $nav['logs']='Журналы Logs'; $nav['bots']='Доставка Bots'; $nav['incidents']='Инциденты'; $nav['engine']='Сайты Engine'; $nav['book']='Книги'; $nav['forum']='Форум'; $nav['lite']='Сайты Lite'; if(in_array($user['role'],['owner','manager'],true)) $nav['editorial']='Редакторские выпуски'; }
    if ($user['nav_rights']['audit']??false) $nav['audit']='Аудит';
    if (can($user,'people')) $nav['team']='Команда';
}
if ($user) { $nav['invite']='Приглашение'; $nav['security']='Безопасность'; }
if ($user && ($_SESSION['auth_kind']??null)==='emergency' && !($_SESSION['emergency_verified']??false)) $nav=['security'=>'Безопасность'];
$current = match ($view) {'project','task','workload','oss','github','releases','infrastructure','deployments'=>'projects','document'=>'documents',default=>$view};
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= h($title) ?> · CajeerWorkspace</title><link rel="stylesheet" href="./style.css"></head>
<body class="<?= $user ? 'signed-in' : 'guest' ?>"><a class="skip" href="#content">К содержимому</a>
<?php if ($user): ?><div class="shell">
<aside class="sidebar"><a class="brand" href="<?= h(url('overview')) ?>"><span class="brandmark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>Cajeer<span class="brandlight">Workspace</span></span></a>
<nav aria-label="Основная навигация"><?php foreach ($nav as $n=>$label): ?><a href="<?= h(url($n)) ?>" <?= $current===$n?'aria-current="page"':'' ?>><?= h($label) ?></a><?php endforeach ?></nav>
<div class="profile"><?php if (!empty($user['organizations'])): ?><form method="post"><?php formFields('org.switch') ?><label>Организация<select name="org"><?php selectOptions(array_column($user['organizations'],'name','id'),$user['organization_id']) ?></select></label><button class="secondary compact">Переключить</button></form><?php endif ?><strong><?= h($user['name']) ?></strong><span><?= h(ROLES[$user['role']]) ?></span><form method="post"><?php formFields('logout') ?><button class="logout">Выйти</button></form></div></aside>
<main id="content" class="main"><div class="heading"><div class="crumb"><a href="<?= h(url('overview')) ?>">Workspace</a><span>/</span><?= h($nav[$current] ?? 'Состояние') ?></div>
<h1><?= h($title) ?></h1></div>
<?php if ($notice): ?><div role="status" class="notice"><?= h($notice) ?></div><?php endif; require ROOT.'/views/'.$view.'.php'; ?>
</main></div><?php else: ?><main id="content" class="guestmain"><?php require ROOT.'/views/'.$view.'.php'; ?></main><?php endif ?>
</body></html>
