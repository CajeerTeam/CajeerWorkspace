<?php namespace Workspace; ?>
<p class="lede">Участники этой установки и назначенные им роли.</p>
<section class="panel"><div class="panelhead"><h2>Команда Workspace</h2><span class="count"><?= count($members) ?></span></div><div class="tablewrap"><table><thead><tr><th>Участник</th><th>Роль</th><th>Доступ</th></tr></thead><tbody>
<?php foreach ($members as $m): ?><tr><td><b><?= h($m['name']) ?></b><small><?= h($m['email']) ?></small></td><td><?= h(ROLES[$m['role']]) ?></td><td><span class="pill <?= $m['enabled']?'done':'' ?>"><?= $m['enabled']?'Активен':'Отключён' ?></span></td></tr><?php endforeach ?></tbody></table></div></section>
