<?php namespace Workspace; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h(($credit?'Credit note CN-':'VAT invoice ').invoiceNumber($i)) ?></title><link rel="stylesheet" href="./style.css"></head><body class="tax-print"><main>
<p class="tax-screen">Print or save as PDF using your browser's print command.</p>
<h1><?= $credit?'VAT credit note CN-':'VAT invoice ' ?><?= h(invoiceNumber($i)) ?></h1>
<p>Issue date: <?= h($credit?$d['credit_on']:$i['issued_on']) ?> · Tax point: <?= h($d['tax_point']) ?></p>
<?php if($credit): ?><p>Original invoice: <?= h(invoiceNumber($i)) ?>, issued <?= h($i['issued_on']) ?>. Reason: <?= h($d['credit_reason']) ?></p><?php endif ?>
<section><h2>Supplier</h2><p><?= h($profile['proprietor']) ?> (sole trader) · <?= h($seller['name']) ?><br><?= h($seller['address']) ?><br>VAT registration number: GB<?= h($profile['vat_number']) ?><br>Contact: <?= h($profile['contact']) ?><br>Address for service: <?= h($profile['service_address']) ?></p><h2>Customer</h2><p><?= h($buyer['name']) ?><br><?= h($d['buyer_address']) ?></p></section>
<table><thead><tr><th>Description</th><th>Quantity</th><th>Unit price excl. VAT (GBP)</th><th>Net (GBP)</th><th>VAT rate</th></tr></thead><tbody><?php foreach($lines as $l): ?><tr><td><?= h($l['description']) ?></td><td><?= h($l['quantity']) ?></td><td><?= h(ukMoney((int)$l['unit_minor'])) ?></td><td><?= h(ukMoney(invoiceLineAmount((int)$l['quantity'],(int)$l['unit_minor']))) ?></td><td>20%</td></tr><?php endforeach ?></tbody></table>
<p>Total excluding VAT: GBP <?= h(ukMoney((int)$d['net_minor'])) ?><br>VAT (20%, rounded down to penny): GBP <?= h(ukMoney((int)$d['vat_minor'])) ?><br><strong><?= $credit?'Total credited':'Total payable' ?>: GBP <?= h(ukMoney((int)$d['gross_minor'])) ?></strong></p>
<?php if(!$credit): ?><p>Cash discount: none. Payment due: <?= h($i['due_on']) ?><br>Terms: <?= h($i['terms']) ?><br>Bank details: <?= h($seller['bank_details']) ?></p><?php endif ?>
</main></body></html>
