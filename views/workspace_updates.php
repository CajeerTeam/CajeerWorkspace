<?php namespace Workspace; ?>
<section class="panel inset"><h2>Стабильный канал GitHub Releases</h2>
<p>Установлена версия <?= h(VERSION) ?>, схема <?= h(SCHEMA_VERSION) ?>. Источник: <a href="https://github.com/CajeerTeam/CajeerWorkspace/releases" rel="noreferrer">CajeerTeam/CajeerWorkspace</a>.</p>
<p>Проверка, скачивание и предварительный план выполняются отдельно через CLI. Веб-интерфейс читает только итоговый отчёт, не запускает процессы и не устанавливает код.</p>
<form method="post"><?php formFields('workspace.update.check') ?><button class="primary">Обновить отображение отчёта</button></form>
<?php if (!mfaFresh()): ?><p>Для проверки подтвердите <a href="<?= h(url('security')) ?>">TOTP</a>.</p><?php endif ?>
<?php if ($report): ?>
<p>Последний отчёт CLI: <?= h($report['checked_at']) ?>.</p>
<h3><?= h(['compatible'=>'Доступен совместимый выпуск','incompatible'=>'Выпуск несовместим','current'=>'Установлен текущий выпуск','ahead'=>'Установлена более новая версия','empty'=>'Нет стабильных выпусков','not_configured'=>'Не настроено доверие','unverified'=>'Выпуск не подтверждён','running'=>'Операция выполняется','failed'=>'Операция не прошла','downloaded'=>'Пакет скачан и проверен','planned'=>'Предварительный план проверен'][$report['status']]??'Неизвестный результат') ?></h3>
<?php foreach($report['reasons'] as $reason): ?><p><?= h($reason) ?></p><?php endforeach ?>
<?php if(isset($report['release'])): $r=$report['release']; ?><dl><dt>Версия / схема</dt><dd><?= h($r['version']) ?> / <?= h($r['schema']) ?></dd><dt>Коммит</dt><dd><code><?= h($r['commit']) ?></code></dd><dt>SHA-256 пакета</dt><dd><code><?= h($r['package']['sha256']) ?></code></dd><dt>Размер (байт)</dt><dd><?= h($r['package']['size']) ?></dd><dt>PHP</dt><dd><?= h($r['runtime']['php_min']) ?> – менее <?= h($r['runtime']['php_max_exclusive']) ?></dd><dt>SQLite / PostgreSQL / Python (минимум)</dt><dd><?= h($r['runtime']['sqlite_min']) ?> / <?= h($r['runtime']['postgres_min']) ?> / <?= h($r['runtime']['python_min']) ?></dd><dt>Допустимые исходные версии</dt><dd><?php foreach($r['supported_from'] as $s): ?><?= h($s['version']) ?> (схема <?= h($s['schema']) ?>) <?php endforeach ?></dd><dt>Хеш закрытого допуска</dt><dd><code><?= h($r['acceptance_sha256']) ?></code></dd></dl><?php endif ?>
<?php if(isset($report['package_result'])): $p=$report['package_result']; ?>
<h3>Результат операции <?= h($report['operation']) ?></h3>
<?php if($p['status']==='downloaded'): ?><p>Подпись и содержимое ZIP проверены. Размер: <?= h($p['size']) ?> байт, SHA-256: <code><?= h($p['sha256']) ?></code>.</p>
<?php elseif($p['status']==='planned'): ?><dl><dt>Схема</dt><dd><?= h($p['source_schema']) ?> → <?= h($p['target_schema']) ?></dd><dt>Файлы: новые / изменённые / удалённые</dt><dd><?= h($p['added']) ?> / <?= h($p['changed']) ?> / <?= h($p['removed']) ?></dd><dt>Новые миграции</dt><dd><?= h(implode(', ', $p['pending_migrations']) ?: 'Нет') ?></dd><dt>SHA-256 исходного пакета</dt><dd><code><?= h($p['previous_sha256']) ?></code></dd><dt>Python</dt><dd><?= h($p['python']) ?></dd></dl><?php foreach($p['required_actions'] as $action): ?><p><?= h($action) ?></p><?php endforeach ?><?php endif ?>
<p>Установка не выполнялась.</p><?php endif ?>
<?php else: ?><p>Нет актуального отчёта CLI (или не подтверждён TOTP). Выполните <code>php bin/workspace updates:check</code> на сервере.</p><?php endif ?></section>
