<?php namespace Workspace; ?>
<?php if(($user['nav_rights']['connections']??false)): ?><p><a href="<?= h(url('forum')) ?>">Лента и поиск обсуждений Forum (проверка доступа в реальном времени)</a></p><?php endif ?>
<div class="overviewhead"><p class="lede">В начале очереди — ваши задачи. Ближайшие сроки идут первыми.</p><?php if (can($user,'manage')): ?><a class="button primary" href="<?= h(url('projects',['new'=>1])) ?>#new">Создать проект</a><?php endif ?></div>
<div class="stats">
<?php foreach ([['Проекты',$counts['projects']],['Открытые задачи',$counts['active']],['Завершённые задачи',$counts['done']]] as [$label,$count]): ?><div class="stat"><span><?= h($label) ?></span><strong><?= (int)$count ?></strong></div><?php endforeach ?>
</div>
<?php if(isset($reminderOverview)): ?><section class="panel inset"><h2>Личные напоминания</h2><p><a href="<?= h(url('notifications',['unread'=>'1'])) ?>">Непрочитанные: <?= h($reminderOverview['count']) ?></a><?php if($reminderOverview['errors']): ?> · часть источников недоступна<?php endif ?><?php if(!mfaFresh()): ?> · защищённые сроки после MFA<?php endif ?></p><?php if($user['role']==='owner'): ?><p><a href="<?= h(url('digest')) ?>">Сводка владельцу</a></p><?php endif ?></section><?php endif ?>
<section class="panel"><div class="panelhead"><h2>Очередь задач</h2></div>
<?php if (!$tasks): ?><p class="empty">Открытых задач пока нет. Задачи добавляются в карточке проекта.</p><?php else: ?><div class="tablewrap"><table class="queue-table"><thead><tr><th>Задача / проект</th><th>Исполнитель</th><th>Срок</th><th>Статус</th></tr></thead><tbody>
<?php foreach ($tasks as $t): ?><tr><td><a class="itemtitle" href="<?= h(url('task',['id'=>$t['id']])) ?>"><?= h($t['title']) ?></a><small><?= h($t['project_title']) ?></small></td>
<td data-label="Исполнитель"><?= h($t['assignee'] ?? 'Не назначен') ?></td><td data-label="Срок" class="nowrap"><?= h(displayDate($t['due_on'])) ?></td><td><span class="pill <?= h($t['state']) ?>"><?= h(STATES[$t['state']]) ?></span><?php if ($t['blocked']): ?><small class="planning-warning"><?= $t['late_blocker']?'Просроченная блокировка':'Заблокирована' ?></small><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><?php endif ?></section>
<section class="panel"><div class="panelhead"><h2>Последние проекты</h2><a href="<?= h(url('projects')) ?>">Все проекты</a></div>
<?php if (!$recent): ?><p class="empty">Создайте первый проект, чтобы собрать задачи и материалы команды в одном месте.</p><?php else: ?>
<div class="projectlist"><?php foreach ($recent as $p): ?><a href="<?= h(url('project',['id'=>$p['id']])) ?>"><span class="itemtitle"><?= h($p['title']) ?></span><span><?= h(PROJECT_KINDS[$p['kind']]) ?></span></a><?php endforeach ?></div><?php endif ?></section>
