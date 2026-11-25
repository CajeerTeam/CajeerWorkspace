<?php namespace Workspace; ?>
<section class="panel inset"><h1>Приглашение в организацию</h1><p>Введите одноразовый код, который передал владелец. Если учётная запись с приглашённой почтой уже существует, сначала войдите под ней.</p>
<?php if (oidcEnabled() && !$user): ?><form method="post" class="stack"><?php formFields('staff.begin') ?><label>Код приглашения<input name="token" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off" required></label><button class="primary">Принять через SkiF4er ID</button></form><?php else: ?><form method="post" class="stack"><?php formFields('invite.accept') ?>
<label>Код приглашения<input name="token" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="off" required></label>
<?php if (!$user): ?><label>Имя нового участника<input name="name" maxlength="90" required></label><label>Новый пароль<input name="password" type="password" autocomplete="new-password" minlength="12" required></label><?php endif ?>
<button class="primary">Принять приглашение</button></form><?php endif ?>
<?php if (!$user): ?><p><a href="<?= h(url('login')) ?>">Войти в существующую учётную запись</a></p><?php endif ?></section>
