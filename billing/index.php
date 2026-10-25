<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('billing.view');

$f = [
    'q'      => get_str('q', 60),
    'status' => get_str('status', 20),
    'from'   => get_str('from', 10),
    'to'     => get_str('to', 10),
    'open'   => get_str('open', 1) === '1' ? 1 : 0,
];
// Cashiers start with the invoices that still need payment.
if (!isset($_GET['status']) && !isset($_GET['q']) && !isset($_GET['from']) && !isset($_GET['to']) && !isset($_GET['open']) && user_can('billing.pay')) {
    $f['open'] = 1;
}
$page = max(1, get_int('page') ?? 1);
$perPage = 25;
[$rows, $total] = list_invoices($f, $perPage, ($page - 1) * $perPage);
$today = user_can('billing.pay') ? collections_for_day(app_today(), (int) $user['id']) : null;

page_start('Invoices', 'billing');
?>
<?php if ($today): ?>
<div class="card">
    <h2>My collections today (<?= e(fmt_date(app_today())) ?>)</h2>
    <div class="grid">
        <?php foreach ($today['methods'] as $method => $amount): ?><?= dashboard_tile($method, fmt_money($amount)) ?><?php endforeach; ?>
        <?= dashboard_tile('Total (' . $today['count'] . ' payments)', fmt_money($today['total'])) ?>
    </div>
</div>
<?php endif; ?>

<form method="get" class="filters">
    <div class="field grow"><label for="q">Invoice number, outpatient number or name</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>"></div>
    <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><option value="">Any</option>
            <?php foreach (['Unpaid', 'Partially paid', 'Paid'] as $s): ?><option<?= $f['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= e($f['from']) ?>"></div>
    <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= e($f['to']) ?>"></div>
    <label class="check"><input type="checkbox" name="open" value="1"<?= $f['open'] ? ' checked' : '' ?>> Only invoices with a balance</label>
    <button class="btn btn-secondary" type="submit">Show</button>
</form>

<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Invoice</th><th>Date</th><th>Patient</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i): ?>
        <tr>
            <td><?= e($i['invoice_no']) ?></td>
            <td class="nowrap"><?= e(fmt_date($i['created_at'])) ?></td>
            <td><span class="op-number"><?= e($i['op_number']) ?></span> <?= e(patient_full_name($i)) ?></td>
            <td class="num"><?= e(fmt_money($i['total'])) ?></td>
            <td class="num"><?= e(fmt_money($i['amount_paid'])) ?></td>
            <td class="num"><?= e(fmt_money(invoice_balance($i))) ?></td>
            <td><?= badge($i['status']) ?></td>
            <td><a class="btn btn-small <?= $i['status'] === 'Paid' ? 'btn-secondary' : '' ?>" href="<?= e(url('billing/invoice.php', ['id' => $i['id']])) ?>"><?= $i['status'] !== 'Paid' && user_can('billing.pay') ? 'Take payment' : 'Open' ?></a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">No invoices match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pager($total, $page, $perPage, array_filter($f, static fn ($v) => $v !== '' && $v !== 0)) ?>
<?php page_end();
