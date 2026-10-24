<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('pharmacy.dispense');

$rows = pharmacy_queue();
page_start('Prescriptions waiting', 'pharmacy');
?>
<p class="muted">Oldest prescriptions are first. Open one to dispense; stock is deducted automatically when you confirm.</p>
<div class="table-wrap">
<table class="data">
    <thead><tr><th>Written</th><th>Patient</th><th>Doctor</th><th class="num">Items</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $rx): ?>
        <tr>
            <td class="nowrap"><?= e(fmt_datetime($rx['created_at'])) ?></td>
            <td><span class="op-number"><?= e($rx['op_number']) ?></span> <?= e(patient_full_name($rx)) ?>
                <?php if ($rx['allergies']): ?><span class="badge badge-abnormal" title="<?= e($rx['allergies']) ?>">Allergy</span><?php endif; ?></td>
            <td><?= e($rx['doctor_name']) ?></td>
            <td class="num"><?= (int) $rx['item_count'] ?></td>
            <td><?= badge($rx['status']) ?></td>
            <td><a class="btn btn-small" href="<?= e(url('pharmacy/dispense.php', ['id' => $rx['id']])) ?>">Dispense</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">No prescriptions are waiting.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php page_end();
