<?php namespace Workspace; ?>
<section class="panel inset"><h2>Стабильный канал GitHub Releases</h2>
<p>Установлена версия <?= h(VERSION) ?>, схема <?= h(SCHEMA_VERSION) ?>. Источник: <a href="https://github.com/CajeerTeam/CajeerWorkspace/releases" rel="noreferrer">CajeerTeam/CajeerWorkspace</a>.</p>
<p>Проверка не скачивает и не устанавливает дистрибутив. Доверенный ключ задаёт администратор установки на сервере.</p>
<form method="post"><?php formFields('workspace.update.check') ?><button class="primary">Проверить выпуски</button></form>
<?php if (!mfaFresh()): ?><p>Для проверки подтвердите <a href="<?= h(url('security')) ?>">TOTP</a>.</p><?php endif ?>
<?php if ($report): ?>
<p>Последняя проверка в этой сессии: <?= h($report['checked_at']) ?>.</p>
<h3><?= h(['compatible'=>'Доступен совместимый выпуск','incompatible'=>'Выпуск несовместим','current'=>'Установлен текущий выпуск','ahead'=>'Установлена более новая версия','empty'=>'Нет стабильных выпусков','not_configured'=>'Не настроено доверие','unverified'=>'Выпуск не подтверждён'][$report['status']]??'Неизвестный результат') ?></h3>
<?php foreach($report['reasons'] as $reason): ?><p><?= h($reason) ?></p><?php endforeach ?>
<?php if(isset($report['release'])): $r=$report['release']; ?><dl><dt>Версия / схема</dt><dd><?= h($r['version']) ?> / <?= h($r['schema']) ?></dd><dt>Коммит</dt><dd><code><?= h($r['commit']) ?></code></dd><dt>SHA-256 пакета</dt><dd><code><?= h($r['package']['sha256']) ?></code></dd><dt>Размер (байт)</dt><dd><?= h($r['package']['size']) ?></dd><dt>PHP</dt><dd><?= h($r['runtime']['php_min']) ?> – менее <?= h($r['runtime']['php_max_exclusive']) ?></dd><dt>SQLite / PostgreSQL / Python (минимум)</dt><dd><?= h($r['runtime']['sqlite_min']) ?> / <?= h($r['runtime']['postgres_min']) ?> / <?= h($r['runtime']['python_min']) ?></dd><dt>Допустимые исходные версии</dt><dd><?php foreach($r['supported_from'] as $s): ?><?= h($s['version']) ?> (схема <?= h($s['schema']) ?>) <?php endforeach ?></dd><dt>Хеш закрытого допуска</dt><dd><code><?= h($r['acceptance_sha256']) ?></code></dd></dl><?php endif ?>
<?php endif ?></section>
