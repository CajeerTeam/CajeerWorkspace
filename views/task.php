<?php namespace Workspace;
$t=$task; $id=(int)$t['id']; $pid=(int)$t['project_id']; $taskDetail=true;
$manage=roleAllows($projectRole,'manage') && $t['milestone_state']!=='closed'; ?>
<p><a href="<?= h(url('project',['id'=>$pid])) ?>">← <?= h($t['project_title']) ?></a></p>
<section class="panel inset"><h2>Задача #<?= $id ?></h2>
<p><strong><?= h(PRIORITIES[$t['priority']]) ?></strong> · <?= h(STATES[$t['state']]) ?> · <?= h($t['assignee']??'Не назначен') ?> · срок <?= h(displayDate($t['due_on'])) ?></p>
<p>Веха: <?= h($t['milestone_title']??'Не назначена') ?><?= $t['milestone_state']==='closed'?' · Закрыта':'' ?></p>
<?php if ($blocked): ?><p class="planning-warning">Задача заблокирована незавершёнными предшественниками.</p><?php endif ?>
<h3>Заказчик результата</h3><p><?= h($t['result_customer']?:'Не указан — заполните план задачи.') ?></p>
<h3>Критерии приёмки</h3><p class="criteria-text"><?= h($t['acceptance_criteria']?:'Не указаны — заполните перед завершением.') ?></p>
<?php require ROOT.'/views/task_state.php'; ?></section>
<?php if ($manage): ?><details class="editor"><summary>Редактировать задачу</summary><form class="formgrid" method="post"><?php formFields('task.edit') ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>"><?php $taskForm=$t; require ROOT.'/views/task_fields.php'; ?><button class="primary">Сохранить план</button></form></details><?php endif ?>
<?php foreach (['requires'=>'Предшественники (задача зависит от них)','dependents'=>'Зависимые задачи'] as $kind=>$label): ?><section class="panel inset"><h2><?= h($label) ?></h2>
<?php if (!$dependencies[$kind]): ?><p>Нет зависимостей.</p><?php endif ?>
<?php foreach ($dependencies[$kind] as $d): ?><div class="splitrow"><div><a href="<?= h(url('task',['id'=>$d['id']])) ?>">#<?= (int)$d['id'] ?> <?= h($d['title']) ?></a><small><?= h(STATES[$d['state']]) ?> · <?= h(displayDate($d['due_on'])) ?><?= $kind==='requires' && $d['state']!=='done' && $d['due_on'] && $d['due_on']<gmdate('Y-m-d')?' · Просрочена':'' ?></small></div>
<?php if ($manage && $kind==='requires'): ?><form method="post"><?php formFields('task.dependency.remove') ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>"><input type="hidden" name="prerequisite_id" value="<?= (int)$d['id'] ?>"><button class="secondary compact">Удалить зависимость</button></form><?php endif ?></div><?php endforeach ?>
<?php if ($manage && $kind==='requires'): ?><form method="post" class="inline"><?php formFields('task.dependency.add') ?><input type="hidden" name="task_id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>"><label>Номер предшествующей задачи в этом проекте<input type="number" name="prerequisite_id" min="1" required></label><button class="primary">Добавить зависимость</button></form><p><a href="<?= h(url('project',['id'=>$pid,'view'=>'backlog'])) ?>">Найти номер задачи в backlog</a></p><?php endif ?></section><?php endforeach ?>
