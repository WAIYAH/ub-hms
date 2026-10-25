<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.manage');

$search = get_str('q', 60);
$rows = medicine_stock($search, false);
page_start('Medicine list', 'medicines');
?>
<form method="get" class="filters">
    <div class="field grow"><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($search) ?>"></div>
    <button class="btn btn-secondary" type="submit">Search</button>
    <a class="btn" href="<?= e(url('pharmacy/medicine.php')) ?>">Add medicine</a>
</form>
<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
<table class="data">
    <thead><tr><th>Medicine</th><th>Unit</th><th class="num">Price</th><th class="num">Reorder level</th><th>In use</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr><td><?= e(medicine_label($m)) ?></td><td><?= e($m['unit']) ?></td><td class="num"><?= e(fmt_money($m['unit_price'])) ?></td><td class="num"><?= (int) $m['reorder_level'] ?></td>
            <td><?= (int) $m['is_active'] ? badge('Active') : badge('Inactive') ?></td>
            <td><a class="btn btn-small btn-secondary" href="<?= e(url('pharmacy/medicine.php', ['id' => $m['id']])) ?>">Edit</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No medicines yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
