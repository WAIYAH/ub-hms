<?php
/**
 * Module 3: laboratory management.
 *
 * A doctor requests tests during a consultation. The laboratory sees each
 * request on its worklist, enters the result, and the result is returned to
 * the doctor who made the request (requested_by).
 */
declare(strict_types=1);

const LAB_OPEN_STATUSES = ['Requested', 'In progress'];

function active_lab_tests(): array
{
    return db_all('SELECT id, name, price, reference_range FROM lab_tests WHERE is_active = 1 ORDER BY name');
}

/**
 * Request one or more tests for an open visit. Duplicate requests for a test
 * that is still pending are refused. Returns ['ok' => bool, 'error' => string, 'count' => int].
 */
function request_lab_tests(int $visitId, array $testIds, string $notes, int $doctorId): array
{
    $v = get_visit($visitId);
    if (!$v || $v['status'] !== 'Open') {
        return ['ok' => false, 'error' => 'Tests can only be requested on an open visit.', 'count' => 0];
    }
    if (!(int) $v['has_consultation']) {
        return ['ok' => false, 'error' => 'Record the consultation before requesting tests.', 'count' => 0];
    }
    $testIds = array_values(array_unique(array_filter(array_map('intval', $testIds))));
    if (!$testIds) {
        return ['ok' => false, 'error' => 'Choose at least one test.', 'count' => 0];
    }
    $notes = clean_text($notes, 255);

    return db_tx(function () use ($visitId, $testIds, $notes, $doctorId): array {
        $made = 0;
        foreach ($testIds as $testId) {
            $test = db_one('SELECT id, name FROM lab_tests WHERE id = ? AND is_active = 1', [$testId]);
            if (!$test) {
                throw new RuntimeException('bad_test');
            }
            $pending = db_value("SELECT id FROM lab_requests WHERE visit_id = ? AND test_id = ? AND status IN ('Requested','In progress')", [$visitId, $testId]);
            if ($pending) {
                throw new RuntimeException('dup:' . $test['name']);
            }
            $id = db_insert(
                'INSERT INTO lab_requests (visit_id, test_id, requested_by, clinical_notes) VALUES (?, ?, ?, ?)',
                [$visitId, $testId, $doctorId, $notes !== '' ? $notes : null]
            );
            audit('create', 'lab_request', $id, 'Test requested for visit ' . $visitId, $doctorId);
            $made++;
        }
        if (function_exists('billing_sync_visit')) {
            billing_sync_visit($visitId, $doctorId);
        }
        return ['ok' => true, 'error' => '', 'count' => $made];
    });
}

/** Wrapper that turns the internal exceptions of request_lab_tests into messages. */
function request_lab_tests_safe(int $visitId, array $testIds, string $notes, int $doctorId): array
{
    try {
        return request_lab_tests($visitId, $testIds, $notes, $doctorId);
    } catch (RuntimeException $e) {
        $m = $e->getMessage();
        if ($m === 'bad_test') {
            return ['ok' => false, 'error' => 'One of the chosen tests is not available.', 'count' => 0];
        }
        if (strpos($m, 'dup:') === 0) {
            return ['ok' => false, 'error' => substr($m, 4) . ' has already been requested for this visit and is still pending.', 'count' => 0];
        }
        throw $e;
    }
}

/** Cancel a request that the laboratory has not started. Only the requesting doctor may do this. */
function cancel_lab_request(int $id, int $doctorId): ?string
{
    $r = db_one('SELECT * FROM lab_requests WHERE id = ?', [$id]);
    if (!$r) {
        return 'Request not found.';
    }
    if ((int) $r['requested_by'] !== $doctorId) {
        return 'Only the doctor who requested the test can cancel it.';
    }
    if ($r['status'] !== 'Requested') {
        return 'The laboratory has already started this test, so it cannot be cancelled.';
    }
    try {
        db_tx(function () use ($id, $r, $doctorId): void {
            db_exec("UPDATE lab_requests SET status = 'Cancelled' WHERE id = ?", [$id]);
            audit('cancel', 'lab_request', $id, 'Request cancelled', $doctorId);
            if (function_exists('billing_sync_visit')) {
                billing_sync_visit((int) $r['visit_id'], $doctorId); // removes the charge; throws if it was already paid
            }
        });
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'billing:') === 0) {
            return substr($e->getMessage(), 8);
        }
        throw $e;
    }
    return null;
}

function get_lab_request(int $id): ?array
{
    return db_one(
        'SELECT r.*, t.name AS test_name, t.reference_range, t.price, p.id AS patient_id, p.op_number, p.first_name, p.middle_name, p.last_name,
                p.sex, p.date_of_birth, d.full_name AS requested_by_name, l.full_name AS result_by_name
           FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id JOIN visits v ON v.id = r.visit_id
           JOIN patients p ON p.id = v.patient_id JOIN users d ON d.id = r.requested_by LEFT JOIN users l ON l.id = r.result_by
          WHERE r.id = ?',
        [$id]
    );
}

/** Requests on a visit, oldest first. */
function visit_lab_requests(int $visitId): array
{
    return db_all(
        'SELECT r.*, t.name AS test_name, t.reference_range, d.full_name AS requested_by_name
           FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id JOIN users d ON d.id = r.requested_by
          WHERE r.visit_id = ? AND r.status <> \'Cancelled\' ORDER BY r.id',
        [$visitId]
    );
}

/** Laboratory worklist. $statuses defaults to the open ones. Returns rows oldest first. */
function lab_worklist(array $statuses = LAB_OPEN_STATUSES, int $limit = 200): array
{
    $statuses = array_values(array_intersect($statuses, ['Requested', 'In progress', 'Completed', 'Cancelled']));
    if (!$statuses) {
        return [];
    }
    $in = implode(',', array_fill(0, count($statuses), '?'));
    return db_all(
        "SELECT r.*, t.name AS test_name, p.op_number, p.first_name, p.middle_name, p.last_name, p.sex, p.date_of_birth,
                d.full_name AS requested_by_name
           FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id JOIN visits v ON v.id = r.visit_id
           JOIN patients p ON p.id = v.patient_id JOIN users d ON d.id = r.requested_by
          WHERE r.status IN ($in) ORDER BY r.requested_at, r.id LIMIT " . (int) $limit,
        $statuses
    );
}

/** The laboratory begins work on a request. */
function start_lab_request(int $id, int $userId): ?string
{
    $r = db_one('SELECT status FROM lab_requests WHERE id = ?', [$id]);
    if (!$r) {
        return 'Request not found.';
    }
    if ($r['status'] !== 'Requested') {
        return 'Only a new request can be started. This one is ' . strtolower($r['status']) . '.';
    }
    db_exec("UPDATE lab_requests SET status = 'In progress' WHERE id = ?", [$id]);
    audit('start', 'lab_request', $id, null, $userId);
    return null;
}

/**
 * Enter or correct a result. Returns an error message or null.
 * $abnormal: true, false or null (not stated).
 */
function record_lab_result(int $id, string $result, ?bool $abnormal, int $userId): ?string
{
    $r = db_one('SELECT status FROM lab_requests WHERE id = ?', [$id]);
    if (!$r) {
        return 'Request not found.';
    }
    if ($r['status'] === 'Cancelled') {
        return 'This request was cancelled by the doctor.';
    }
    $result = clean_multiline($result, 2000);
    if ($result === '') {
        return 'Enter the result.';
    }
    $amend = $r['status'] === 'Completed';
    db_exec(
        "UPDATE lab_requests SET status = 'Completed', result = ?, is_abnormal = ?, result_by = ?, result_at = NOW() WHERE id = ?",
        [$result, $abnormal === null ? null : ($abnormal ? 1 : 0), $userId, $id]
    );
    audit($amend ? 'result_amended' : 'result', 'lab_request', $id, $amend ? 'Result corrected' : 'Result entered', $userId);
    return null;
}

/**
 * Results for the tests one doctor requested. $onlyOpenVisits limits to
 * patients still being seen. Returns rows, newest result first.
 */
function doctor_lab_requests(int $doctorId, string $status = '', int $limit = 100): array
{
    $sql = 'SELECT r.*, t.name AS test_name, t.reference_range, v.status AS visit_status, p.id AS patient_id, p.op_number,
                   p.first_name, p.middle_name, p.last_name, l.full_name AS result_by_name
              FROM lab_requests r JOIN lab_tests t ON t.id = r.test_id JOIN visits v ON v.id = r.visit_id
              JOIN patients p ON p.id = v.patient_id LEFT JOIN users l ON l.id = r.result_by
             WHERE r.requested_by = ? AND r.status <> \'Cancelled\'';
    $params = [$doctorId];
    if (in_array($status, ['Requested', 'In progress', 'Completed'], true)) {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }
    return db_all($sql . ' ORDER BY COALESCE(r.result_at, r.requested_at) DESC, r.id DESC LIMIT ' . (int) $limit, $params);
}

/** Results the doctor has not yet had a chance to act on: completed tests on visits that are still open. */
function results_ready_count(int $doctorId): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM lab_requests r JOIN visits v ON v.id = r.visit_id
          WHERE r.requested_by = ? AND r.status = 'Completed' AND v.status = 'Open'",
        [$doctorId]
    );
}

/** Dashboard tiles for laboratory staff and doctors. */
function dashboard_tiles_laboratory(array $user): array
{
    $t = [];
    if (user_can('lab.worklist')) {
        $t[] = dashboard_tile('Tests waiting', db_value("SELECT COUNT(*) FROM lab_requests WHERE status = 'Requested'"), 'lab/index.php');
        $t[] = dashboard_tile('Tests in progress', db_value("SELECT COUNT(*) FROM lab_requests WHERE status = 'In progress'"), 'lab/index.php');
    }
    if (user_can('lab.request')) {
        $t[] = dashboard_tile('Test results ready for my patients', results_ready_count((int) $user['id']), 'lab/results.php');
    }
    return $t;
}
