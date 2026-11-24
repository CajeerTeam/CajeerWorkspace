<?php namespace Workspace; ?>
<section class="panel errorpanel"><?php if (!$user): ?><h1><?= h($title) ?></h1><?php endif ?>
<p role="alert"><?= h($error) ?></p><a class="button secondary" href="<?= h(url($user?'overview':'login')) ?>"><?= $user?'Открыть обзор':'Перейти ко входу' ?></a></section>
