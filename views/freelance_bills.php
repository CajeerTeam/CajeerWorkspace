<?php namespace Workspace; ?>
<?php if(!$billRows): ?><p>Связанных счетов пока нет.</p><?php endif ?>
<?php foreach($billRows as $b): ?><article class="client-stage"><h3><?php if(!$billGuest): ?><a href="<?= h(url('invoices',['project'=>$b['project_id'],'id'=>$b['id']])) ?>"><?= h($b['number']?:'Черновик #'.$b['id']) ?></a><?php else: ?><?= h($b['number']) ?><?php endif ?> · <?= h(match($b['slot']){'advance'=>'Аванс','balance'=>'Остаток','change'=>'Изменение объёма',default=>'Обслуживание с '.$b['slot']}) ?></h3>
<p><?= h(money((int)$b['total_minor']).' '.$b['currency']) ?> · срок <?= h(displayDate($b['due_on'])) ?> · <?= h($b['credited']?'Кредит-нота':match($b['state']){'draft'=>'Черновик','void'=>'Аннулирован',default=>$b['outstanding']?'К оплате':'Оплачен'}) ?></p>
<p>Получено <?= h(money($b['received']).' '.$b['currency']) ?> · возвращено <?= h(money($b['refunded']).' '.$b['currency']) ?> · к оплате <?= h(money($b['outstanding']).' '.$b['currency']) ?></p>
<?php if($b['overdue']): ?><p class="notice" role="status">Напоминание: срок оплаты истёк. Просроченный остаток <?= h(money($b['outstanding']).' '.$b['currency']) ?>.</p><?php endif ?></article><?php endforeach ?>
