<?php namespace Workspace; ?>
<?php if (can($user,'manage')): ?><details class="editor" id="new" <?= (!$projects || isset($_GET['new']))?'open':'' ?>><summary>Создать проект</summary>
<form method="post" class="formgrid"><?php formFields('project.create') ?>
<label>Название проекта<input name="title" required maxlength="120"></label>
<label>Тип проекта<select name="kind"><?php selectOptions(PROJECT_KINDS) ?></select></label>
<label>Заказчик <small>необязательно</small><input name="client" maxlength="120"></label>
<div class="formend"><button class="primary">Создать проект</button></div></form></details><?php endif ?>
<section class="panel"><div class="panelhead"><h2>Портфель проектов</h2><span class="count"><?= count($projects) ?></span></div>
<?php if (!$projects): ?><p class="empty">Проектов пока нет.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>Название</th><th>Тип</th><th>Заказчик</th><th>Задачи</th></tr></thead>
<tbody><?php foreach ($projects as $p): ?><tr><td><a class="itemtitle" href="<?= h(url('project',['id'=>$p['id']])) ?>"><?= h($p['title']) ?></a></td><td><?= h(PROJECT_KINDS[$p['kind']]) ?></td><td><?= h($p['client'] ?: '—') ?></td><td><?= (int)$p['done'] ?> из <?= (int)$p['tasks'] ?> готовы</td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>
