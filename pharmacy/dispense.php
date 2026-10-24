<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.dispense');

$id = get_int('id') ?? post_int('id');
$rx = $id !== null ? get_prescription($id) : null;
if (!$rx) {
    abort(404, 'That prescription does not exist.');
}
$error = '';

if (is_post()) {
    require_post();
    $qty = [];
    foreach ((array) ($_POST['qty'] ?? []) as $itemId => $q) {
        if (is_numeric($itemId) && is_string($q) && preg_match('/^\d{1,5}$/', trim($q))) {
            $qty[(int) $itemId] = (int) $q;
        }
    }
    $result = dispense_prescription((int) $rx['id'], $qty, (int) $user['id']);
    if ($result['ok']) {
        $msg = [];
        foreach ($result['lines'] as $l) {
            $msg[] = $l['qty'] . ' x ' . $l['item'] . ' (batch ' . implode(', ', array_map(static fn ($b) => $b['batch_no'] . ' x' . $b['qty'], $l['batches'])) . ')';
        }
        flash('success', 'Dispensed and stock updated: ' . implode('; ', $msg));
        redirect('pharmacy/index.php');
    }
    $error = $result['error'];
    $rx = get_prescription((int) $rx['id']);
}

page_start('Dispense medicines', 'pharmacy');
?>
<?= render_patient_banner($rx) ?>
<p>Prescribed by <?= e($rx['doctor_name']) ?> on <?= e(fmt_datetime($rx['created_at'])) ?> &middot; <?= badge($rx['status']) ?></p>
<?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<form method="post" novalidate>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $rx['id'] ?>">
    <div class="table-wrap">
    <table class="data">
        <thead><tr><th>Medicine</th><th>Directions</th><th class="num">Prescribed</th><th class="num">Given</th><th class="num">In stock</th><th>Dispense now</th></tr></thead>
        <tbody>
        <?php foreach ($rx['items'] as $it): ?>
            <?php $max = min($it['remaining'], $it['available']); ?>
            <tr>
                <td><?= e(medicine_label(['name' => $it['medicine_name'], 'strength' => $it['strength'], 'form' => $it['form']])) ?></td>
                <td><?= e(trim($it['dosage'] . ', ' . $it['frequency'] . ($it['duration'] ? ', ' . $it['duration'] : ''))) ?></td>
                <td class="num"><?= (int) $it['quantity'] ?></td>
                <td class="num"><?= (int) $it['dispensed_qty'] ?></td>
                <td class="num"><?= $it['available'] > 0 ? (int) $it['available'] : '<span class="badge badge-out">Out</span>' ?></td>
                <td>
                    <?php if ($it['remaining'] > 0): ?>
                        <input class="qty-input" type="number" name="qty[<?= (int) $it['id'] ?>]" min="0" max="<?= (int) $it['remaining'] ?>" value="<?= (int) $max ?>" aria-label="Quantity of <?= e($it['medicine_name']) ?> to dispense">
                        <span class="muted small">of <?= (int) $it['remaining'] ?> left</span>
                    <?php else: ?><span class="badge badge-dispensed">Done</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted small">Stock is taken from the batch that expires first. Expired batches are never used. If you cannot give the full amount, reduce the number; the rest stays on the prescription.</p>
    <div class="actions">
        <?php if ($rx['status'] !== 'Dispensed'): ?><button class="btn" type="submit">Confirm and update stock</button><?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(url('pharmacy/index.php')) ?>">Back</a>
    </div>
</form>
<?php page_end();
