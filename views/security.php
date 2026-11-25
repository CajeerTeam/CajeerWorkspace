<?php namespace Workspace; ?>
<section class="panel inset"><h2>Двухфакторное подтверждение</h2>
<p>Финансовые операции, изменения договоров, подключения, полномочия, приглашения, выгрузка CSV и скачивание внутренних PDF требуют свежего кода TOTP. Подтверждение действует 5 минут; повторно тот же код использовать нельзя.</p>
<?php if (mfaFresh()): ?><p role="status">Второй фактор подтверждён.</p><?php endif ?>
<?php if ($enrolled): ?><form method="post" class="stack"><?php formFields('mfa.verify') ?>
<label>Код приложения-аутентификатора<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="primary">Подтвердить</button></form>
<?php elseif ($secret): ?><p>Добавьте в приложение-аутентификатор аккаунт CajeerWorkspace: TOTP, SHA-1, 6 цифр, 30 секунд. Секрет:</p><p><code id="mfa-secret"><?= h($secret) ?></code></p>
<form method="post" class="stack"><?php formFields('mfa.enroll') ?><label>Первый код<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="primary">Подключить и подтвердить</button></form>
<?php else: ?><p>Для подключения второго фактора выйдите и войдите заново.</p><?php endif ?>
<p><a href="<?= h(url('overview')) ?>">Вернуться в Workspace</a></p></section>
