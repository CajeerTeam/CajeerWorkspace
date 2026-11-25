<?php namespace Workspace;
$manage=roleAllows($projectRole,'manage');
$milestoneOptions=[''=>'Все','none'=>'Без вехи']; foreach ($milestones as $m) $milestoneOptions[$m['id']]=$m['title'];
$peopleOptions=[''=>'Все','none'=>'Не назначен']+$people;
?>
<section class="panel inset"><h2>Вехи проекта</h2>
<?php if (!$milestones): ?><p>Вех пока нет.</p><?php endif ?>
<?php foreach ($milestones as $m): ?><article class="milestone-row" id="milestone-<?= (int)$m['id'] ?>">
<div class="splitrow"><div><a class="itemtitle" href="<?= h(url('project',['id'=>$id,'milestone'=>$m['id']])) ?>"><?= h($m['title']) ?></a><small><?= h(MILESTONE_STATES[$m['state']]) ?> · <?= h(displayDate($m['due_on'])) ?> · <?= (int)$m['done'] ?> / <?= (int)$m['total'] ?> задач завершено</small></div>
<?php if ($manage): ?><form method="post"><?php formFields('milestone.state') ?><input type="hidden" name="project_id" value="<?= $id ?>"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="version" value="<?= (int)$m['version'] ?>"><input type="hidden" name="state" value="<?= $m['state']==='open'?'closed':'open' ?>"><button class="secondary compact"><?= $m['state']==='open'?'Закрыть веху':'Открыть веху' ?></button></form><?php endif ?></div>
<?php if ($manage && $m['state']==='open'): ?><details><summary>Редактировать веху</summary><form method="post" class="formgrid"><?php formFields('milestone.edit') ?><input type="hidden" name="project_id" value="<?= $id ?>"><input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="version" value="<?= (int)$m['version'] ?>"><label>Название<input name="title" maxlength="180" required value="<?= h($m['title']) ?>"></label><label>Срок<input name="due_on" type="date" value="<?= h($m['due_on']??'') ?>"></label><button class="secondary">Сохранить веху</button></form></details><?php endif ?>
</article><?php endforeach ?>
<?php if ($manage): ?><details><summary>Добавить веху</summary><form method="post" class="formgrid"><?php formFields('milestone.create') ?><input type="hidden" name="project_id" value="<?= $id ?>"><label>Название<input name="title" maxlength="180" required></label><label>Срок<input name="due_on" type="date"></label><button class="primary">Создать веху</button></form></details><?php endif ?></section>
<?php if ($manage): ?><details class="editor"><summary>Добавить задачу</summary><form method="post" class="formgrid"><?php formFields('task.create') ?><input type="hidden" name="project_id" value="<?= $id ?>"><?php require ROOT.'/views/task_fields.php'; ?><div class="formend"><button class="primary">Добавить задачу</button></div></form></details><?php endif ?>
<section class="panel inset"><h2>Планирование задач</h2>
<form method="get" class="formgrid"><input type="hidden" name="p" value="project"><input type="hidden" name="id" value="<?= $id ?>">
<label class="wide">Поиск по названию, заказчику и критериям<input name="q" type="search" maxlength="120" value="<?= h($filters['q']) ?>"></label>
<label>Представление<select name="view"><?php selectOptions(['board'=>'Доска статусов','backlog'=>'Backlog'],$filters['view']) ?></select></label>
<label>Статус<select name="state"><?php selectOptions([''=>'Все']+STATES,$filters['state']) ?></select></label>
<label>Приоритет<select name="priority"><?php selectOptions([''=>'Все']+PRIORITIES,$filters['priority']) ?></select></label>
<label>Веха<select name="milestone"><?php selectOptions($milestoneOptions,$filters['milestone']) ?></select></label>
<label>Исполнитель<select name="assignee"><?php selectOptions($peopleOptions,$filters['assignee']) ?></select></label>
<label>Блокировки<select name="blocked"><?php selectOptions([''=>'Все','yes'=>'Заблокированы','no'=>'Без блокировок','late'=>'Просроченные предшественники'],$filters['blocked']) ?></select></label>
<label>Срок<select name="overdue"><?php selectOptions([''=>'Все','yes'=>'Просрочены'],$filters['overdue']) ?></select></label>
<div class="formend"><button class="primary">Применить фильтры</button> <a href="<?= h(url('project',['id'=>$id])) ?>">Сбросить</a></div></form>
<p>Сначала высокий приоритет, затем ближайший срок. Backlog по умолчанию показывает все незавершённые задачи.</p>
</section>
<div class="<?= $filters['view']==='board'?'planning-board':'planning-backlog' ?>">
<?php foreach ($lanes as $lane=>$group): ?><section class="panel planning-lane"><div class="panelhead"><h2><?= h(STATES[$lane]??'Backlog') ?></h2><span class="count"><?= count($group['rows']) ?><?= $group['more']?'+':'' ?></span></div>
<?php if (!$group['rows']): ?><p class="empty">Нет задач по фильтрам.</p><?php endif ?>
<?php foreach ($group['rows'] as $t): ?><article class="planning-card"><a class="itemtitle" href="<?= h(url('task',['id'=>$t['id']])) ?>"><?= h($t['title']) ?></a><small>#<?= (int)$t['id'] ?> · <?= h(PRIORITIES[$t['priority']]) ?> · <?= h($t['assignee']??'Не назначен') ?></small>
<small>Срок: <?= h(displayDate($t['due_on'])) ?><?= $t['state']!=='done' && $t['due_on'] && $t['due_on']<gmdate('Y-m-d')?' · Просрочена':'' ?></small>
<?php if ($t['milestone_title']!==null): ?><small>Веха: <?= h($t['milestone_title']) ?></small><?php endif ?>
<small>Заказчик: <?= h($t['result_customer']?:'Не указан') ?></small>
<?php if ($t['blocked']): ?><span class="planning-warning"><?= $t['late_blocker']?'Просроченная блокировка':'Заблокирована зависимостью' ?></span><?php endif ?>
<?php if ($t['result_customer']==='' || $t['acceptance_criteria']===''): ?><small class="planning-warning">Требуется заполнить условия приёмки</small><?php endif ?>
<?php require ROOT.'/views/task_state.php'; ?></article><?php endforeach ?></section><?php endforeach ?></div>
<?php $next=false; foreach ($lanes as $group) $next=$next||$group['more']; $params=['id'=>$id]+$filters; ?>
<nav class="inline planning-pagination" aria-label="Страницы задач"><?php if ($pageNo>1): ?><a href="<?= h(url('project',$params+['n'=>$pageNo-1])) ?>">Предыдущая</a><?php endif ?><span>Страница <?= $pageNo ?> · до 50 задач в списке/колонке</span><?php if ($next): ?><a href="<?= h(url('project',$params+['n'=>$pageNo+1])) ?>">Следующая</a><?php endif ?></nav>
