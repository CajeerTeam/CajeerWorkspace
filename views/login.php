<?php namespace Workspace; ?>
<div class="loginwrap"><div class="loginbrand"><span class="brandmark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>CajeerWorkspace</span></div>
<div class="loginbox"><h1>Вход в Workspace</h1><p>Войдите под своей учётной записью команды.</p>
<?php if (!empty($error)): ?><div role="alert" class="alert"><?= h($error) ?></div><?php endif ?>
<form method="post" class="stack"><?php formFields('login') ?>
<label>Почта<input name="email" type="email" autocomplete="username" maxlength="254" required autofocus></label>
<label>Пароль<input name="password" type="password" autocomplete="current-password" required></label>
<button class="primary">Войти</button></form><p><a href="<?= h(url('invite')) ?>">Есть приглашение?</a></p></div></div>
