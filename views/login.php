<?php namespace Workspace; ?>
<div class="loginwrap"><div class="loginbrand"><span class="brandmark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>CajeerWorkspace</span></div>
<div class="loginbox"><h1>Вход в Workspace</h1>
<?php if (!empty($error)): ?><div role="alert" class="alert"><?= h($error) ?></div><?php endif ?>
<?php if (oidcEnabled()): ?><form method="post"><?php formFields('oidc.begin') ?><button class="primary">Войти через SkiF4er ID</button></form><p><a href="<?= h(url('guest.join')) ?>">Приглашение клиента</a></p><?php endif ?>
<?php if (!oidcEnabled() || getenv('WORKSPACE_EMERGENCY_LOCAL')==='1'): ?>
<?php if (oidcEnabled()): ?><p>Аварийный локальный вход владельца</p><?php endif ?>
<form method="post" class="stack"><?php formFields('login') ?>
<label>Почта<input name="email" type="email" autocomplete="username" maxlength="254" required></label>
<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
<button class="primary">Войти</button></form><?php endif ?>
<p><a href="<?= h(url('invite')) ?>">Приглашение сотрудника</a></p></div></div>
