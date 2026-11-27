<?php namespace Workspace; ?>
<section class="panel"><div class="panelhead"><h2>Выберите один проект</h2></div>
<p class="inset">Ассистент читает разрешённые данные одного проекта. Обычные вопросы не изменяют данные проекта. Черновики применяются только после отдельного подтверждения. В OpenAI передаются вопрос и ограниченная выборка; не вводите секреты или персональные данные. Финансовый, клиентский и юридический контекст требует свежего MFA. История ответов сохраняется только по политике организации.</p>
<?php if(!$projects): ?><p class="empty">Нет доступных пилотных проектов.</p><?php else: ?>
<form method="get" class="inline inset"><input type="hidden" name="p" value="assistant"><label>Проект<select name="project" required><option value="">Выберите проект</option><?php selectOptions($projects,$selected['id']??null) ?></select></label><button class="secondary">Выбрать</button></form>
<?php endif ?></section>
<?php if(isset($policy)&&($user['role']??'')==='owner'): ?><section class="panel"><div class="panelhead"><h2>Политика AI организации</h2></div><form method="post" class="inset" action="<?= h(url('assistant')) ?>"><?php formFields('ai.policy') ?><input type="hidden" name="version" value="<?= h($policy['version']) ?>">
<?php foreach(AI_DATA as $k=>$label): ?><label><input type="checkbox" name="data_types[]" value="<?= h($k) ?>" <?= in_array($k,$policy['data'],true)?'checked':'' ?>> <?= h($label) ?></label><?php endforeach ?>
<?php if(!in_array($policy['model'],$models,true)): ?><p>Прежняя модель больше не разрешена. Выберите новую и сохраните политику.</p><?php endif ?><label>Модель<select name="model"><?php selectOptions(array_combine($models,$models),$policy['model']) ?></select></label><label>Запросов в сутки UTC (0 отключает)<input type="number" name="daily_quota" min="0" max="1000" required value="<?= h($policy['quota']) ?>"></label><label>Хранение ответов, суток (0 — без истории)<input type="number" name="retention_days" min="0" max="30" required value="<?= h($policy['days']) ?>"></label><p>Смена политики удаляет предыдущую историю. Для сохранения требуется MFA.</p><button>Сохранить политику</button></form></section><?php endif ?>
<?php if($selected): ?><section class="panel"><div class="panelhead"><h2><?= h($selected['title']) ?></h2><a href="<?= h(url('project',['id'=>$selected['id']])) ?>">Открыть проект</a></div>
<form method="post" action="<?= h(url('assistant',['project'=>$selected['id']])) ?>" class="inset"><?php formFields('ai.ask') ?><input type="hidden" name="project_id" value="<?= h($selected['id']) ?>">
<label>Тема<select name="topic"><?php selectOptions($topics??AI_TOPICS,$topic) ?></select></label>
<label>Ключевые слова для задач, вех и релизов (необязательно)<input name="term" maxlength="120" value="<?= h($term) ?>" placeholder="Например: авторизация, v1.2.0"></label>
<label>Вопрос<input name="question" maxlength="1200" required value="<?= h($question) ?>" placeholder="Что мешает выпустить этот проект?"></label>
<label><input type="checkbox" name="consent" value="yes" required> Разрешаю передать вопрос и ограниченный контекст этого проекта в OpenAI API.</label>
<button>Спросить AI</button></form></section><?php endif ?>
<?php if($result!==null): ?><section class="panel"><div class="panelhead"><h2>Ответ AI</h2><small><?= h(gmdate('Y-m-d H:i:s',$result['observed'])) ?> UTC</small></div>
<p class="inset">Ответ модели, не подтверждение состояния GitHub в реальном времени. Сверяйте выводы по источникам.</p>
<div class="inset"><?= nl2br(h($result['answer']),false) ?></div>
<h3 class="inset">Источники ответа</h3>
<?php if(!$result['links']): ?><p class="inset">Подтверждённых ссылок нет.</p><?php endif ?>
<?php foreach($result['links'] as $source): ?><div class="splitrow"><a href="<?= h($source['href']) ?>">[<?= h($source['ref']) ?>] <?= h($source['title']) ?></a><small><?= h(AI_TOPICS[$source['kind']]) ?><?php if($source['kind']==='releases'): ?> · CI: <?= h($source['fields']['ci']==='historical_success'?'успешная сохранённая проверка':'не подтверждён') ?><?php if($source['fields']['verified_at']!==null): ?> · <?= h($source['fields']['verified_at']) ?><?= strtotime($source['fields']['verified_at']) < $result['observed']-RELEASE_VERIFY_TTL?' · наблюдение устарело':'' ?><?php endif ?><?php endif ?></small></div><?php if(in_array($source['kind'],AI_SENSITIVE,true)): ?><div class="inset"><?php foreach(aiFactLines($source['fields']) as [$label,$value]): ?><div class="splitrow"><span><?= h($label) ?></span><b><?= h($value) ?></b></div><?php endforeach ?></div><?php endif ?><?php endforeach ?>
<h3 class="inset">Ограничения выборки</h3>
<?php foreach($result['coverage'] as $kind=>$coverage): ?><div class="splitrow"><b><?= h(AI_TOPICS[$kind]) ?></b><span><?= h(is_array($coverage)?('Объектов: '.$coverage['count'].' · '.($coverage['selection']==='search_matches'?'совпадения поиска':'приоритетная выборка').($coverage['truncated']?' · выборка неполная':'')):$coverage) ?></span></div><?php endforeach ?>
</section><?php endif ?>

<?php if(!empty($history)): ?><section class="panel"><div class="panelhead"><h2>Моя история по проекту</h2></div><?php foreach($history as $item): ?><details class="inset"><summary><?= h(gmdate('Y-m-d H:i:s',$item['observed'])) ?> UTC</summary><p><?= nl2br(h($item['answer']),false) ?></p><?php foreach($item['links'] as $source): ?><p><a href="<?= h($source['href']) ?>">[<?= h($source['ref']) ?>] <?= h($source['title']) ?></a></p><?php if(in_array($source['kind'],AI_SENSITIVE,true)): ?><?php foreach(aiFactLines($source['fields']) as [$label,$value]): ?><div class="splitrow"><span><?= h($label) ?></span><b><?= h($value) ?></b></div><?php endforeach ?><?php endif ?><?php endforeach ?></details><?php endforeach ?></section><?php endif ?>

<?php if($selected && isset($draftOptions)) require ROOT.'/views/assistant_draft.php'; ?>

<?php if(isset($ops)) require ROOT.'/views/assistant_ops.php'; ?>
