<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('settings.manage');

if (is_post()) {
    require_post();
    $kind = post_str('kind', 10);
    $id = (int) post_int('id');
    $price = str_replace(',', '', post_str('price', 12));
    $active = post_str('is_active', 1) === '1' ? 1 : 0;
    $table = $kind === 'service' ? 'services' : ($kind === 'test' ? 'lab_tests' : '');
    $old = $table !== '' ? db_one("SELECT name, price, is_active FROM $table WHERE id = ?", [$id]) : null;

    if (!$old) {
        flash('error', 'That item does not exist.');
    } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $price)) {
        flash('error', 'Enter the price as a number such as 500 or 750.50.');
    } else {
        db_exec("UPDATE $table SET price = ?, is_active = ? WHERE id = ?", [$price, $active, $id]);
        audit('price_change', $kind, $id, $old['name'] . ': price ' . $old['price'] . ' to ' . $price . ($active !== (int) $old['is_active'] ? ($active ? '; activated' : '; deactivated') : ''), (int) $user['id']);
        flash('success', $old['name'] . ' updated. Invoices that have not yet received a payment pick up the new price.');
    }
    redirect('admin/prices.php');
}

$services = db_all('SELECT * FROM services ORDER BY category, name');
$tests = db_all('SELECT * FROM lab_tests ORDER BY name');

/** One editable row. */
function price_row(string $kind, array $r): string
{
    return '<tr><td>' . e($r['name']) . (isset($r['category']) ? ' <span class="muted small">(' . e($r['category']) . ')</span>' : '') . '</td><td colspan="3">'
        . '<form method="post" class="filters">' . csrf_field()
        . '<input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
        . '<label>KSh <input class="qty-input" name="price" value="' . e($r['price']) . '" inputmode="decimal" aria-label="Price of ' . e($r['name']) . '"></label>'
        . '<label class="check"><input type="checkbox" name="is_active" value="1"' . ((int) $r['is_active'] ? ' checked' : '') . '> In use</label>'
        . '<button class="btn btn-small btn-secondary" type="submit">Save</button></form></td></tr>';
}

page_start('Price list', 'prices');
?>
<p class="muted">Prices apply to new charges. An invoice that has already received a payment keeps the prices it was agreed at. Every change is recorded in the audit trail. Medicine prices are set on the pharmacist's medicine list.</p>
<h2>Services</h2>
<div class="table-wrap"><table class="data"><thead><tr><th>Service</th><th colspan="3">Price</th></tr></thead><tbody>
<?php foreach ($services as $s) { echo price_row('service', $s); } ?>
</tbody></table></div>
<h2>Laboratory tests</h2>
<div class="table-wrap"><table class="data"><thead><tr><th>Test</th><th colspan="3">Price</th></tr></thead><tbody>
<?php foreach ($tests as $t) { echo price_row('test', $t); } ?>
</tbody></table></div>
<?php page_end();
