<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('patients.export');

$dr = get_int('request') !== null ? get_data_request((int) get_int('request')) : null;
if (!$dr || $dr['kind'] !== 'Export' || $dr['status'] !== 'Completed') {
    abort(404, 'That copy request does not exist.');
}
if ($dr['erased_at'] !== null) {
    abort(404, 'This record has since been erased, so a copy can no longer be made.');
}
$bundle = patient_record_bundle((int) $dr['patient_id']);
if (!$bundle) {
    abort(404, 'That patient record does not exist.');
}

// Every time the copy is opened or downloaded is written to the audit trail.
if (get_str('format', 4) === 'json') {
    audit('data_export', 'data_request', (int) $dr['id'], $dr['reference'] . ' downloaded as a file');
    $bundle = ['request' => ['reference' => $dr['reference'], 'recorded_on' => $dr['created_at'], 'requested_by' => $dr['requester_relation']]] + $bundle;
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dr['reference'] . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
audit('data_export', 'data_request', (int) $dr['id'], $dr['reference'] . ' opened for printing');

page_start('Copy of a patient record', 'privacy');
?>
<div class="actions no-print">
    <button class="btn" type="button" data-print>Print the copy</button>
    <a class="btn btn-secondary" href="<?= e(url('patients/export.php', ['request' => $dr['id'], 'format' => 'json'])) ?>">Download as a file (JSON)</a>
    <a class="btn btn-secondary" href="<?= e(url('patients/data.php', ['id' => $dr['patient_id']])) ?>">Back</a>
</div>
<p class="muted small no-print">Request <?= e($dr['reference']) ?>. The copy shows the record as it is now. Hand it to the person who asked only after you have checked who they are.</p>
<?= render_record_copy($bundle) ?>
<?php page_end();
