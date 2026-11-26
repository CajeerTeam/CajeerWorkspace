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
<?php if (($_SESSION['auth_kind']??null)!=='emergency' || !empty($_SESSION['emergency_verified'])): ?>
<section class="panel inset"><h2>Способы входа</h2>
<p>Политика установки: <?= h(INSTALL_AUTH_POLICIES[installationAuthPolicy()]) ?>. Текущий вход: <?= h($_SESSION['auth_kind']??'') ?>.<?php if (!empty($user['organization_id'])): ?> Политика выбранной организации: <?= h(ORG_AUTH_POLICIES[$orgPolicy]) ?>.<?php endif ?></p>
<p>Локальный пароль: <?= localAuthEnabled()?'разрешён политикой установки':'не разрешён для обычного входа' ?>. SkiF4er ID: <?= $linked?'привязан':'не привязан' ?><?= oidcEnabled()?'':' (отключён политикой установки)' ?>.</p>
<?php if (!$linked && oidcEnabled() && ($_SESSION['auth_kind']??null)==='local'): ?>
<form method="post" class="stack"><?php formFields('identity.link') ?><label>Текущий локальный пароль<input name="password" type="password" autocomplete="current-password" required></label><button>Привязать SkiF4er ID</button></form><p>Подтвердите TOTP выше. После отдельного входа в ID все текущие сессии Workspace будут завершены.</p>
<?php elseif ($linked && $canUnlink): ?><form method="post" class="stack"><?php formFields('identity.unlink') ?><label>Текущий локальный пароль<input name="password" type="password" autocomplete="current-password" required></label><button>Отвязать SkiF4er ID</button></form><p>Отвязка требует TOTP и разрешённого локального входа во всех ваших организациях. Все сессии будут завершены.</p><?php elseif ($linked): ?><p>Отвязка недоступна: требуется SkiF4er ID. Для восстановления обратитесь к администратору установки.</p><?php endif ?>
</section>
<section class="panel inset"><h2>Активные сессии Workspace</h2><p>Отзыв требует свежего TOTP. Сессии у самого провайдера ID управляются отдельно.</p>
<?php foreach ($sessions as $s): ?><div><strong><?= h($s['kind']) ?><?= $s['handle']===staffHandle()?' — текущая':'' ?></strong> · Начало <?= h(gmdate('Y-m-d H:i:s',(int)$s['created_at'])) ?> UTC · Активность <?= h(gmdate('Y-m-d H:i:s',(int)$s['seen_at'])) ?> UTC
<form method="post"><?php formFields('identity.revoke') ?><input type="hidden" name="handle" value="<?= h($s['handle']) ?>"><button>Отозвать сессию</button></form></div><?php endforeach ?>
</section>
<section class="panel inset"><h2>История безопасности (последние 200 записей)</h2>
<?php foreach ($history as $e): ?><p><?= h(gmdate('Y-m-d H:i:s',(int)$e['occurred_at'])) ?> UTC · <?= h($e['actor']) ?> · <?= h($e['action']) ?></p><?php endforeach ?>
<p>Восстановление недоступного ID выполняет администратор установки после независимой проверки личности.</p></section>

<?php endif ?>
