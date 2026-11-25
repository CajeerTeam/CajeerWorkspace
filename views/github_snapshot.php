<?php namespace Workspace; ?>
<p><a href="<?= h('https://github.com/'.$repo['full_name'].($link['kind']==='pull'?'/pull/':'/issues/').$link['number']) ?>" rel="noopener noreferrer"><?= $link['kind']==='pull'?'PR':'Issue' ?> #<?= (int)$link['number'] ?></a></p>
<?php if ($link['last_error']!==''): ?><p class="planning-warning"><?= h($link['last_error']) ?></p><?php endif ?>
<?php if (!$link['data']): ?><p>Нет подтверждённого снимка GitHub.</p><?php else: $remote=$link['data']; ?>
<p><strong><?= h($remote['title']) ?></strong> · <?= h($remote['state']) ?><?= $remote['merged']?' · merged':'' ?></p>
<p><small>Сверка: <?= h(gmdate('Y-m-d H:i:s',(int)$link['synced_at'])) ?> UTC<?= time()-(int)$link['synced_at']>900?' · снимок устарел':'' ?><?= $link['last_error']!==''||$repo['last_error']!==''?' · последняя попытка неуспешна':'' ?></small></p>
<?php if ($remote['head_sha']): ?><p>Head SHA: <code><?= h($remote['head_sha']) ?></code> · Commit status: <?= h($remote['commit_status']) ?></p>
<?php if (!$remote['complete']): ?><p class="planning-warning">Неполная выборка checks: не использовать для подтверждения готовности.</p><?php endif ?>
<?php if (!$remote['checks']): ?><p>GitHub не вернул check runs.</p><?php else: ?><details><summary>Check runs (<?= count($remote['checks']) ?>)</summary><div class="tablewrap"><table><thead><tr><th>Проверка</th><th>Статус</th><th>Результат</th></tr></thead><tbody><?php foreach ($remote['checks'] as $check): ?><tr><td><?= h($check['name']) ?></td><td><?= h($check['status']) ?></td><td><?= h($check['conclusion']?:'—') ?></td></tr><?php endforeach ?></tbody></table></div></details><?php endif ?><?php endif ?>
<?php endif ?>
