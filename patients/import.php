<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.import');

$stage = $_SESSION['import_file'] ?? null; // path of a previewed file waiting for confirmation
$preview = null;

if (is_post()) {
    require_post();
    $action = post_str('action', 10);

    if ($action === 'preview') {
        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            flash('error', 'Choose a CSV file to upload.');
            redirect('patients/import.php');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv' || $file['size'] > 1048576) {
            flash('error', 'Upload a .csv file smaller than 1 MB.');
            redirect('patients/import.php');
        }
        // Keep the upload under a random name outside the web-served folders until it is confirmed or discarded.
        $dir = APP_ROOT . '/storage/tmp';
        $dest = $dir . '/import_' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            flash('error', 'The file could not be saved. Try again.');
            redirect('patients/import.php');
        }
        if ($stage && is_file($stage)) {
            @unlink($stage);
        }
        $_SESSION['import_file'] = $dest;
        audit('import_preview', 'patient', null, 'CSV file uploaded for checking');
        redirect('patients/import.php');
    }

    if ($action === 'commit' && $stage && is_file($stage)) {
        $n = commit_patient_import($stage, (int) $user['id']);
        @unlink($stage);
        unset($_SESSION['import_file']);
        flash('success', plural($n, 'patient') . ' registered from the file.');
        redirect('patients/index.php');
    }

    if ($action === 'cancel') {
        if ($stage && is_file($stage)) {
            @unlink($stage);
        }
        unset($_SESSION['import_file']);
        flash('info', 'Import cancelled. Nothing was saved.');
        redirect('patients/import.php');
    }
}

if ($stage && is_file($stage)) {
    $preview = preview_patient_import($stage);
}

page_start('Import patients from a CSV file', 'import');
?>
<div class="card">
    <h2>Step 1: choose a file</h2>
    <p>Use this to move patients from the paper register or an old spreadsheet. The file must be a CSV with these column headings (the first four are required):</p>
    <p><code><?= e(implode(', ', PATIENT_IMPORT_COLUMNS)) ?></code></p>
    <ul>
        <li><strong>sex</strong>: Female or Male. <strong>date_of_birth</strong>: 2019-04-23 or 23/04/2019.</li>
        <li>Rows for people who look already registered (same ID number, or same name and date of birth) are skipped.</li>
        <li>Nothing is saved until you confirm in step 2. Up to 500 rows and 1 MB per file.</li>
    </ul>
    <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <div class="field"><label for="csv">CSV file</label><input id="csv" name="csv" type="file" accept=".csv,text/csv" required></div>
        <button class="btn" type="submit">Check the file</button>
    </form>
</div>

<?php if ($preview): ?>
<div class="card">
    <h2>Step 2: check the result</h2>
    <?php if ($preview['error']): ?>
        <div class="flash flash-error" role="alert"><?= e($preview['error']) ?></div>
    <?php else: ?>
        <p><strong><?= (int) $preview['valid'] ?></strong> of <?= count($preview['rows']) ?> rows are ready to register.</p>
        <div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit">
        <table class="data">
            <thead><tr><th>Line</th><th>Name</th><th>Date of birth</th><th>Status</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($preview['rows'] as $r): ?>
                <tr>
                    <td><?= (int) $r['line'] ?></td>
                    <td><?= e(trim(($r['data']['first_name'] ?? '') . ' ' . ($r['data']['last_name'] ?? ''))) ?></td>
                    <td><?= e($r['data']['date_of_birth'] ?? '') ?></td>
                    <td><?= badge($r['status']) ?></td>
                    <td><?= e($r['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <div class="actions">
        <?php if (!$preview['error'] && $preview['valid'] > 0): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="commit">
                <button class="btn" type="submit">Register <?= (int) $preview['valid'] ?> patients</button></form>
        <?php endif; ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-secondary" type="submit">Cancel</button></form>
    </div>
</div>
<?php endif; ?>
<?php page_end();
