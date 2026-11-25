<?php namespace Workspace; ?>
<?php if (($t['milestone_state']??null)!=='closed' && (roleAllows($projectRole,'manage') || (roleAllows($projectRole,'work') && (int)$t['assignee_id']===(int)$user['id']))): ?>
<form class="inline task-state" method="post"><?php formFields('task.state') ?><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="version" value="<?= (int)$t['version'] ?>">
<?php if (isset($taskDetail)): ?><input type="hidden" name="return_to" value="task"><?php endif ?>
<label>Статус<select name="state" aria-label="Статус задачи <?= h($t['title']) ?>"><?php selectOptions(STATES,$t['state']) ?></select></label><button class="secondary compact">Сохранить</button></form>
<?php else: ?><span class="pill <?= h($t['state']) ?>"><?= h(STATES[$t['state']]) ?></span><?php endif ?>
