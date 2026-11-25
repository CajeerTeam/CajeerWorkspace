<?php namespace Workspace;
$values=$taskForm??[];
$milestoneChoices=[''=>'Без вехи']; foreach ($milestones as $m) if ($m['state']==='open') $milestoneChoices[$m['id']]=$m['title'];
$assigneeChoices=[''=>'Не назначен']+$assignable;
// A disabled or revoked assignee is not offered as a valid new assignment.
?>
<label class="wide">Название задачи<input name="title" maxlength="180" required value="<?= h($values['title']??'') ?>"></label>
<label>Исполнитель<select name="assignee" aria-label="Исполнитель"><?php selectOptions($assigneeChoices,$values['assignee_id']??'') ?></select></label>
<label>Срок<input type="date" name="due_on" value="<?= h($values['due_on']??'') ?>"></label>
<label>Приоритет<select name="priority"><?php selectOptions(PRIORITIES,$values['priority']??3) ?></select></label>
<label>Веха<select name="milestone_id"><?php selectOptions($milestoneChoices,$values['milestone_id']??'') ?></select></label>
<label class="wide">Заказчик результата<input name="result_customer" maxlength="180" required value="<?= h($values['result_customer']??'') ?>" placeholder="Имя, команда или организация"></label>
<label class="wide">Критерии приёмки<textarea name="acceptance_criteria" maxlength="4000" rows="4" required placeholder="Наблюдаемый результат и способ его проверки"><?= h($values['acceptance_criteria']??'') ?></textarea></label>
