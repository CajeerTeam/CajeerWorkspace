<?php namespace Workspace; ?>
<section class="panel"><div class="panelhead"><h2>Выберите один проект</h2></div>
<p class="inset">Ассистент читает только задачи, вехи и выпуски выбранного проекта. Данные не изменяются. В OpenAI отправляются вопрос и ограниченная выборка полей; не вводите секреты, персональные или финансовые данные. История не сохраняется в Workspace.</p>
<?php if(!$projects): ?><p class="empty">Нет доступных пилотных проектов.</p><?php else: ?>
<form method="get" class="inline inset"><input type="hidden" name="p" value="assistant"><label>Проект<select name="project" required><option value="">Выберите проект</option><?php selectOptions($projects,$selected['id']??null) ?></select></label><button class="secondary">Выбрать</button></form>
<?php endif ?></section>
<?php if($selected): ?><section class="panel"><div class="panelhead"><h2><?= h($selected['title']) ?></h2><a href="<?= h(url('project',['id'=>$selected['id']])) ?>">Открыть проект</a></div>
<form method="post" action="<?= h(url('assistant',['project'=>$selected['id']])) ?>" class="inset"><?php formFields('ai.ask') ?><input type="hidden" name="project_id" value="<?= h($selected['id']) ?>">
<label>Тема<select name="topic"><?php selectOptions(AI_TOPICS,$topic) ?></select></label>
<label>Ключевые слова для поиска (необязательно)<input name="term" maxlength="120" value="<?= h($term) ?>" placeholder="Например: авторизация, v1.2.0"></label>
<label>Вопрос<input name="question" maxlength="1200" required value="<?= h($question) ?>" placeholder="Что мешает выпустить этот проект?"></label>
<label><input type="checkbox" name="consent" value="yes" required> Разрешаю передать вопрос и ограниченный контекст этого проекта в OpenAI API.</label>
<button>Спросить AI</button></form></section><?php endif ?>
<?php if($result!==null): ?><section class="panel"><div class="panelhead"><h2>Ответ AI</h2><small><?= h(gmdate('Y-m-d H:i:s',$result['observed'])) ?> UTC</small></div>
<p class="inset">Ответ модели, не подтверждение состояния GitHub в реальном времени. Сверяйте выводы по источникам.</p>
<div class="inset"><?= nl2br(h($result['answer']),false) ?></div>
<h3 class="inset">Источники ответа</h3>
<?php if(!$result['links']): ?><p class="inset">Подтверждённых ссылок нет.</p><?php endif ?>
<?php foreach($result['links'] as $source): ?><div class="splitrow"><a href="<?= h($source['href']) ?>">[<?= h($source['ref']) ?>] <?= h($source['title']) ?></a><small><?= h(AI_TOPICS[$source['kind']]) ?><?php if($source['kind']==='releases'): ?> · CI: <?= h($source['fields']['ci']==='historical_success'?'успешная сохранённая проверка':'не подтверждён') ?><?php if($source['fields']['verified_at']!==null): ?> · <?= h($source['fields']['verified_at']) ?><?= strtotime($source['fields']['verified_at']) < $result['observed']-RELEASE_VERIFY_TTL?' · наблюдение устарело':'' ?><?php endif ?><?php endif ?></small></div><?php endforeach ?>
<h3 class="inset">Ограничения выборки</h3>
<?php foreach($result['coverage'] as $kind=>$coverage): ?><div class="splitrow"><b><?= h(AI_TOPICS[$kind]) ?></b><span><?= h(is_array($coverage)?('Объектов: '.$coverage['count'].' · '.($coverage['selection']==='search_matches'?'совпадения поиска':'приоритетная выборка').($coverage['truncated']?' · выборка неполная':'')):$coverage) ?></span></div><?php endforeach ?>
</section><?php endif ?>
