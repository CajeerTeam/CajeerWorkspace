<?php namespace Workspace; ?>
<section class="panel"><div class="panelhead"><h2>Подтверждаемые AI-черновики</h2></div>
<p class="inset">Подготовка не меняет проект. После предпросмотра задача создаётся или изменяется только черновой этап сметы; остальные типы выгружаются в текстовый файл. Публикация, отправка и подпись не выполняются. Требуется свежий MFA. Один черновик на сессию, до 15 минут; новый заменяет старый.</p>
<?php if(!mfaFresh()): ?><p class="inset"><a href="<?= h(url('security')) ?>">Подтвердить MFA</a></p><?php endif ?>
<?php if($draftError): ?><p class="inset"><?= h($draftError) ?></p><?php endif ?>
<?php if($draftReview): $proposal=$draftReview['proposal']; ?>
<div class="inset"><h3>Предпросмотр: <?= h(AI_DRAFT_TYPES[$proposal['input']['kind']]) ?></h3><p>Действителен до <?= h(gmdate('Y-m-d H:i:s',$proposal['expires'])) ?> UTC.</p>
<p><b>Адресат / назначение:</b> <?= h($draftReview['target']['recipient']) ?></p><p><a href="<?= h($draftReview['target']['href']) ?>">Открыть исходный объект</a></p>
<h4>До</h4><?php if(!$draftReview['target']['before']): ?><p>Новый текстовый файл; исходные записи не изменяются.</p><?php endif ?>
<?php foreach($draftReview['target']['before'] as $label=>$value): ?><div class="splitrow"><b><?= h($label) ?></b><span><?= nl2br(h($value),false) ?></span></div><?php endforeach ?>
<h4>После подтверждения</h4><?php foreach($draftReview['after'] as $label=>$value): ?><div class="splitrow"><b><?= h($label) ?></b><span><?= nl2br(h($value),false) ?></span></div><?php endforeach ?>
<h4>Источники</h4><?php if(!$draftReview['links']): ?><p>Источников нет; задача предложена по заданию пользователя.</p><?php endif ?>
<?php foreach($draftReview['links'] as $source): ?><p><a href="<?= h($source['href']) ?>">[<?= h($source['ref']) ?>] <?= h($source['title']) ?></a></p><?php foreach(aiFactLines($source['fields']) as [$label,$value]): ?><div class="splitrow"><span><?= h($label) ?></span><span><?= h($value) ?></span></div><?php endforeach ?><?php endforeach ?>
<?php foreach($draftReview['coverage'] as $kind=>$coverage): ?><p><?= h(AI_TOPICS[$kind]) ?>: <?= h(is_array($coverage)?('объектов '.$coverage['count'].($coverage['truncated']?' · выборка неполная':'')):$coverage) ?></p><?php endforeach ?>
<p>Проверьте текст, адресата, факты и изменения. AI может ошибаться. Подтверждение не заменяет штатное согласование.</p>
<form method="post" action="<?= h(url('assistant',['project'=>$selected['id']])) ?>"><?php formFields('ai.draft.confirm') ?><input type="hidden" name="project_id" value="<?= h($selected['id']) ?>"><input type="hidden" name="handle" value="<?= h($proposal['handle']) ?>"><label><input type="checkbox" name="confirm" value="yes" required> Проверил поля, адресата, источники и изменения.</label><button>Подтвердить <?= in_array($proposal['input']['kind'],['task','estimate'],true)?'и применить':'и скачать' ?></button></form>
<form method="post" action="<?= h(url('assistant',['project'=>$selected['id']])) ?>"><?php formFields('ai.draft.cancel') ?><input type="hidden" name="project_id" value="<?= h($selected['id']) ?>"><input type="hidden" name="handle" value="<?= h($proposal['handle']) ?>"><button class="secondary">Отменить черновик</button></form></div><?php endif ?>
<?php if(!$draftOptions): ?><p class="empty">Нет доступных типов черновиков.</p><?php endif ?>
<?php foreach($draftOptions as $kind=>$options): ?><details class="inset"><summary><?= h($options['label']) ?></summary>
<?php if(mfaFresh()): ?>
<?php if(in_array($kind,['notes','letter','estimate'],true)&&!$options['targets']): ?><p>Нет доступных исходных объектов.</p><?php else: ?>
<form method="post" action="<?= h(url('assistant',['project'=>$selected['id']])) ?>"><?php formFields('ai.draft.create') ?><input type="hidden" name="project_id" value="<?= h($selected['id']) ?>"><input type="hidden" name="kind" value="<?= h($kind) ?>">
<?php if($options['targets']): ?><label>Исходный объект<select name="target" required><option value="">Выберите</option><?php selectOptions($options['targets']) ?></select></label><?php endif ?>
<?php if($kind==='task'): ?><label>Исполнитель<select name="assignee"><option value="">Не назначен</option><?php selectOptions($options['people']) ?></select></label><label>Срок<input type="date" name="due_on"></label><label>Приоритет<select name="priority"><?php selectOptions(PRIORITIES,3) ?></select></label><?php endif ?>
<?php if($kind==='estimate'): ?><label>Новая сумма выбранного этапа в валюте сметы<input name="amount" inputmode="decimal" required maxlength="12" placeholder="100.00"></label><p>Сумму задаёте вы; срок и валюта сохраняются. Модель предлагает только название и объём работ.</p><?php endif ?>
<label>Задание модели<input name="question" maxlength="1200" required placeholder="Что нужно подготовить? Не вводите секреты или контакты."></label>
<label><input type="checkbox" name="consent" value="yes" required> Разрешаю передать задание и ограниченный контекст в OpenAI.</label><button>Подготовить черновик</button></form><?php endif ?>
<?php else: ?><p>Сначала подтвердите MFA.</p><?php endif ?></details><?php endforeach ?></section>
