<?php namespace Workspace; ?>
<section class="panel inset"><h1>Приглашение клиента</h1><p>Введите одноразовый код и войдите через SkiF4er ID с подтверждённой почтой, указанной в приглашении.</p>
<form method="post" class="stack"><?php formFields('guest.begin') ?><label>Код<input name="token" autocomplete="off" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" required></label><button class="primary">Принять через SkiF4er ID</button></form><p><a href="<?= h(url('login')) ?>">Обычный вход</a></p></section>
