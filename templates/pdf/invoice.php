<?php
/**
 * Invoice PDF template (Dompdf). Tables only: Dompdf does not support flexbox or grid.
 *
 * Variables: $invoice (with client_* fields), $items, $profile, $logo (data URI or null)
 */

if (!defined('BASE_PATH')) {
    exit;
}

$cur = $invoice['currency'];

$fromLines = array_filter([
    $profile['address_line1'],
    $profile['address_line2'],
    trim(($profile['postal_code'] ?? '') . ' ' . ($profile['city'] ?? '')),
    $profile['country'],
]);
$toLines = array_filter([
    $invoice['client_contact'],
    $invoice['client_address_line1'],
    $invoice['client_address_line2'],
    trim(($invoice['client_postal_code'] ?? '') . ' ' . ($invoice['client_city'] ?? '')),
    $invoice['client_country'],
]);

$balance   = invoice_balance($invoice);
$amountDue = $invoice['status'] === 'draft' ? (float) $invoice['total'] : $balance;

$watermark = match ($invoice['status']) {
    'draft'     => 'DRAFT',
    'cancelled' => 'CANCELLED',
    default     => null,
};
$stamp = match (true) {
    $invoice['status'] === 'paid'    => ['PAID', '#2F7A4B'],
    $invoice['status'] === 'overdue' => ['OVERDUE', '#B4372E'],
    default                          => null,
};

$footer = implode('  ·  ', array_filter([
    $profile['business_name'],
    $profile['email'],
    $profile['phone'],
    $profile['website'] ? preg_replace('#^https?://#', '', $profile['website']) : null,
    $profile['vat_number'] ? 'VAT ' . $profile['vat_number'] : null,
]));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice <?= e($invoice['invoice_number']) ?></title>
<style>
  @page { margin: 40px 42px 64px 42px; }
  * { box-sizing: border-box; }
  body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 9.5px;
    line-height: 1.45;
    color: #2B3A46;
  }
  table { width: 100%; border-collapse: collapse; }
  td, th { vertical-align: top; }
  .num { text-align: right; white-space: nowrap; }
  .muted { color: #808B94; }
  .label {
    font-size: 7.5px;
    font-weight: bold;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: #808B94;
    margin-bottom: 4px;
  }

  /* Accent bar across the top */
  .topbar { height: 5px; background: #0E5E5A; margin: -40px -42px 28px -42px; }
  .topbar span { display: block; width: 90px; height: 5px; background: #B5733A; }

  .head td { padding: 0; }
  .brand { font-size: 17px; font-weight: bold; color: #15212B; }
  .logo { max-height: 54px; max-width: 220px; }
  .title { font-size: 26px; font-weight: bold; color: #B5733A; letter-spacing: -0.5px; line-height: 1; }
  .number { font-size: 11px; font-weight: bold; color: #15212B; margin-top: 4px; }

  .parties { margin-top: 26px; }
  .parties td { width: 33%; padding-right: 14px; }
  .party-name { font-size: 11px; font-weight: bold; color: #15212B; margin-bottom: 2px; }

  .meta td { padding: 3px 0; }
  .meta .k { color: #808B94; }
  .meta .v { text-align: right; font-weight: bold; color: #15212B; }
  .due-box {
    margin-top: 6px;
    padding: 8px 10px;
    background: #E3EFEE;
    border-radius: 4px;
  }
  .due-box .k { color: #0E5E5A; font-weight: bold; }
  .due-box .v { text-align: right; font-size: 13px; font-weight: bold; color: #0E5E5A; }

  .items { margin-top: 26px; }
  .items th {
    padding: 7px 8px;
    background: #15212B;
    color: #FFFFFF;
    font-size: 7.5px;
    font-weight: bold;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    text-align: left;
  }
  .items th.num { text-align: right; }
  .items td { padding: 8px; border-bottom: 1px solid #E6E3DD; }
  .items tr.alt td { background: #FBFAF8; }
  .item-name { font-weight: bold; color: #15212B; }
  .item-desc { color: #5B6873; font-size: 8.5px; margin-top: 2px; }

  .bottom { margin-top: 18px; page-break-inside: avoid; }
  .bottom > tbody > tr > td.left { width: 55%; padding-right: 24px; }
  .totals td { padding: 4px 8px; }
  .totals .k { color: #5B6873; }
  .totals .v { text-align: right; white-space: nowrap; color: #15212B; }
  .totals .grand td {
    padding: 9px 8px;
    background: #0E5E5A;
    color: #FFFFFF;
    font-size: 12px;
    font-weight: bold;
  }
  .totals .grand .v { color: #FFFFFF; }
  .totals .balance td { padding-top: 7px; font-weight: bold; color: #15212B; }

  .box {
    padding: 10px 12px;
    border: 1px solid #E6E3DD;
    border-left: 3px solid #B5733A;
    border-radius: 3px;
    margin-bottom: 12px;
  }
  .iban { font-size: 10.5px; font-weight: bold; color: #15212B; letter-spacing: 0.4px; }

  .stamp-cell { text-align: right; vertical-align: middle; padding-right: 22px; }
  .stamp {
    display: inline-block;
    padding: 4px 12px;
    border: 2px solid;
    border-radius: 4px;
    font-size: 14px;
    font-weight: bold;
    letter-spacing: 2px;
    transform: rotate(-8deg);
  }
  .watermark {
    position: fixed;
    top: 330px;
    left: 0;
    width: 100%;
    text-align: center;
    font-size: 96px;
    font-weight: bold;
    letter-spacing: 8px;
    color: #15212B;
    opacity: 0.06;
    transform: rotate(-30deg);
  }
  .footer-line {
    position: fixed;
    bottom: -38px;
    left: 0;
    right: 150px;
    text-align: left;
    font-size: 7.5px;
    color: #8C949C;
  }
</style>
</head>
<body>

<?php if ($watermark): ?>
  <div class="watermark"><?= e($watermark) ?></div>
<?php endif; ?>
<?php if ($footer !== ''): ?>
  <div class="footer-line"><?= e($footer) ?></div>
<?php endif; ?>

<div class="topbar"><span></span></div>

<table class="head">
  <tr>
    <td style="width: 260px;">
      <?php if ($logo): ?>
        <img class="logo" src="<?= e($logo) ?>" alt="">
      <?php else: ?>
        <div class="brand"><?= e($profile['business_name']) ?></div>
      <?php endif; ?>
    </td>
    <td class="stamp-cell">
      <?php if ($stamp): ?>
        <div class="stamp" style="color: <?= e($stamp[1]) ?>; border-color: <?= e($stamp[1]) ?>;"><?= e($stamp[0]) ?></div>
      <?php endif; ?>
    </td>
    <td class="num" style="width: 170px;">
      <div class="title">INVOICE</div>
      <div class="number"><?= e($invoice['invoice_number']) ?></div>
    </td>
  </tr>
</table>

<table class="parties">
  <tr>
    <td>
      <div class="label">From</div>
      <div class="party-name"><?= e($profile['business_name']) ?></div>
      <?php foreach ($fromLines as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
      <?php if ($profile['vat_number']): ?><div>VAT <?= e($profile['vat_number']) ?></div><?php endif; ?>
      <?php if ($profile['email']): ?><div><?= e($profile['email']) ?></div><?php endif; ?>
      <?php if ($profile['phone']): ?><div><?= e($profile['phone']) ?></div><?php endif; ?>
    </td>
    <td>
      <div class="label">Bill to</div>
      <div class="party-name"><?= e($invoice['client_name']) ?></div>
      <?php foreach ($toLines as $line): ?><div><?= e($line) ?></div><?php endforeach; ?>
      <?php if ($invoice['client_vat_number']): ?><div>VAT <?= e($invoice['client_vat_number']) ?></div><?php endif; ?>
      <?php if ($invoice['client_email']): ?><div><?= e($invoice['client_email']) ?></div><?php endif; ?>
    </td>
    <td style="padding-right: 0;">
      <table class="meta">
        <tr><td class="k">Invoice no.</td><td class="v"><?= e($invoice['invoice_number']) ?></td></tr>
        <tr><td class="k">Issue date</td><td class="v"><?= e(format_date($invoice['issue_date'], 'd M Y')) ?></td></tr>
        <tr><td class="k">Due date</td><td class="v"><?= e(format_date($invoice['due_date'], 'd M Y')) ?></td></tr>
      </table>
      <div class="due-box">
        <table>
          <tr>
            <td class="k"><?= $invoice['status'] === 'paid' ? 'Paid in full' : 'Amount due' ?></td>
            <td class="v"><?= e(money($invoice['status'] === 'paid' ? $invoice['total'] : $amountDue, $cur)) ?></td>
          </tr>
        </table>
      </div>
    </td>
  </tr>
</table>

<table class="items">
  <thead>
    <tr>
      <th style="width: 46%;">Description</th>
      <th class="num">Qty</th>
      <th class="num">Unit price</th>
      <th class="num">Tax</th>
      <th class="num">Amount</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): ?>
      <tr class="<?= $i % 2 ? 'alt' : '' ?>">
        <td>
          <div class="item-name"><?= e($item['name']) ?></div>
          <?php if ($item['description']): ?><div class="item-desc"><?= nl2br(e($item['description'])) ?></div><?php endif; ?>
        </td>
        <td class="num"><?= e(trim_decimal($item['quantity'])) ?><?= $item['unit'] ? ' <span class="muted">' . e($item['unit']) . '</span>' : '' ?></td>
        <td class="num"><?= e(money($item['unit_price'], $cur)) ?></td>
        <td class="num"><?= e(trim_decimal($item['tax_rate'])) ?>%</td>
        <td class="num"><?= e(money($item['line_subtotal'], $cur)) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table class="bottom">
  <tr>
    <td class="left">
      <?php if ($profile['iban']): ?>
        <div class="box">
          <div class="label">Payment details</div>
          <?php if ($profile['bank_name']): ?><div><?= e($profile['bank_name']) ?></div><?php endif; ?>
          <div class="iban">IBAN <?= e(format_iban($profile['iban'])) ?></div>
          <?php if ($profile['swift_bic']): ?><div>SWIFT / BIC <?= e($profile['swift_bic']) ?></div><?php endif; ?>
          <div class="muted" style="margin-top: 4px;">Please use <strong><?= e($invoice['invoice_number']) ?></strong> as the payment reference.</div>
        </div>
      <?php endif; ?>
      <?php if ($invoice['notes']): ?>
        <div class="label">Notes</div>
        <div><?= nl2br(e($invoice['notes'])) ?></div>
      <?php endif; ?>
    </td>
    <td>
      <table class="totals">
        <tr><td class="k">Subtotal</td><td class="v"><?= e(money($invoice['subtotal'], $cur)) ?></td></tr>
        <tr><td class="k">Tax</td><td class="v"><?= e(money($invoice['tax_total'], $cur)) ?></td></tr>
        <?php if ((float) $invoice['discount_amount'] > 0): ?>
          <tr>
            <td class="k">Discount<?= $invoice['discount_type'] === 'percent' ? ' (' . e(trim_decimal($invoice['discount_value'])) . '%)' : '' ?></td>
            <td class="v">−<?= e(money($invoice['discount_amount'], $cur)) ?></td>
          </tr>
        <?php endif; ?>
        <tr class="grand"><td>Total</td><td class="v"><?= e(money($invoice['total'], $cur)) ?></td></tr>
        <?php if ((float) $invoice['amount_paid'] > 0): ?>
          <tr><td class="k">Paid</td><td class="v">−<?= e(money($invoice['amount_paid'], $cur)) ?></td></tr>
          <tr class="balance"><td>Balance due</td><td class="v"><?= e(money($balance, $cur)) ?></td></tr>
        <?php endif; ?>
      </table>
    </td>
  </tr>
</table>

</body>
</html>
