<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.view');

$q = get_str('q', 60);
$page = max(1, get_int('page') ?? 1);
$perPage = 25;
$searched = $q !== '';
[$rows, $total] = $searched ? search_patients($q, $perPage, ($page - 1) * $perPage) : [[], 0];
if ($searched) {
    audit('search', 'patient', null, 'Patient search performed');
}

page_start('Search patients', 'patients');
?>
<form method="get" class="filters">
    <div class="field grow">
        <label for="q">Outpatient number, name, ID number or phone</label>
        <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="e.g. OP26-00001, Wanjiru, 0712 345 678" autofocus>
    </div>
    <button class="btn" type="submit">Search</button>
    <?php if (user_can('patients.register')): ?><a class="btn btn-secondary" href="<?= e(url('patients/register.php')) ?>">Register new patient</a><?php endif; ?>
</form>

<?php if (!$searched): ?>
    <div class="card"><p class="muted">Type at least part of a name, an outpatient number, an ID number or a phone number to find a patient.</p></div>
<?php else: ?>
    <p class="muted"><?= plural($total, 'patient') ?> found.</p>
    <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
    <table class="data">
        <thead><tr><th>OP number</th><th>Name</th><th>Sex</th><th>Age</th><th>Phone</th><th>ID number</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): ?>
            <tr>
                <td><span class="op-number"><?= e($p['op_number']) ?></span></td>
                <td><?= e(patient_full_name($p)) ?></td>
                <td><?= e($p['sex']) ?></td>
                <td><?= e(age_label($p['date_of_birth'])) ?></td>
                <td><?= e($p['phone']) ?></td>
                <td><?= e($p['national_id']) ?></td>
                <td><a class="btn btn-small btn-secondary" href="<?= e(url('patients/view.php', ['id' => $p['id']])) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">No patient matches. Check the spelling or register a new patient.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?= pager($total, $page, $perPage, ['q' => $q]) ?>
<?php endif; ?>
<?php page_end();
