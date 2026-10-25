<?php
/**
 * Patient data rights (Data Protection Act, 2019): a copy of a patient's record, and erasure.
 *
 * Every request is written down in data_requests: who is asking, how their identity was checked, and who decided.
 *
 * COPY (Export). The Records officer checks who is asking, records the request, and prints the copy or downloads
 * it as a JSON file. Each time the copy is opened or downloaded it is written to the audit trail.
 *
 * ERASURE. A hospital cannot simply delete a clinical or financial record: invoices and payments must still add up, and
 * medical records may have to be kept for a set number of years. So erasure here means ANONYMISATION. The patient
 * row stays (so the money and the counts stay correct) but loses every detail that identifies the person: name,
 * numbers, phone, address, next of kin, allergies, and the exact birth date (only the year is kept). The free text
 * of the clinical records (reasons, complaints, examinations, plans, notes, results) is replaced by "[erased]".
 * Coded data (diagnosis code, vital signs, which tests and medicines) is kept for statistics.
 *
 * Records staff ask; the Hospital Manager decides, and never sees the patient's name. A request is refused
 * automatically while there is a reason the record must stay (an open visit, booked appointments, money still owed,
 * a refund waiting, or the retention period set in config 'privacy.retention_years').
 *
 * What erasure cannot reach: the audit trail is append-only by design, so entries made before the erasure still carry
 * the old outpatient number (never a name or clinical detail). Paper files and backups taken earlier also still hold the
 * original details until they are destroyed or expire; the hospital must handle those under its own procedures.
 */
declare(strict_types=1);

const DATA_RELATIONS = ['The patient', 'Parent or guardian', 'Legal representative', 'Other (explain in the note)'];
const DATA_REQUEST_STATUSES = ['Open', 'Completed', 'Refused', 'Cancelled'];
const ERASED_TEXT = '[erased]';

/* ---------- Requests ---------- */

/** Returns [clean, errors] for the "who is asking" part of the form shared by copy and erasure requests. */
function validate_data_request_input(array $in): array
{
    $errors = [];
    $name = clean_text($in['requester_name'] ?? '', 120);
    $relation = clean_text($in['requester_relation'] ?? '', 40);
    $note = clean_text($in['identity_note'] ?? '', 255);
    $reason = clean_text($in['reason'] ?? '', 255);

    if (mb_strlen($name) < 2) {
        $errors['requester_name'] = 'Enter the name of the person who is asking.';
    }
    if (!in_array($relation, DATA_RELATIONS, true)) {
        $errors['requester_relation'] = 'Choose who is asking.';
    }
    if (($in['identity_checked'] ?? '') !== '1') {
        $errors['identity_checked'] = 'Tick the box to confirm you have checked who is asking.';
    }
    if (mb_strlen($note) < 3) {
        $errors['identity_note'] = 'Say how you checked, for example "National ID card seen". Do not write the ID number.';
    }
    return [['requester_name' => $name, 'requester_relation' => $relation, 'identity_note' => $note, 'reason' => $reason], $errors];
}

/**
 * Record a request. $kind is 'Export' (completed at once) or 'Erasure' (stays open for the manager).
 * Returns ['ok' => true, 'id' => n, 'reference' => 'DR-...'] or ['ok' => false, 'errors' => [...]].
 */
function create_data_request(int $patientId, string $kind, array $in, int $userId): array
{
    if (!in_array($kind, ['Export', 'Erasure'], true)) {
        return ['ok' => false, 'errors' => ['kind' => 'Unknown kind of request.']];
    }
    [$c, $errors] = validate_data_request_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    return db_tx(function () use ($patientId, $kind, $c, $userId): array {
        $p = db_one('SELECT id, op_number, erased_at FROM patients WHERE id = ? FOR UPDATE', [$patientId]);
        if (!$p) {
            return ['ok' => false, 'errors' => ['requester_name' => 'Patient not found.']];
        }
        if ($p['erased_at'] !== null) {
            return ['ok' => false, 'errors' => ['requester_name' => 'This record has already been erased.']];
        }
        if ($kind === 'Erasure' && db_value("SELECT id FROM data_requests WHERE patient_id = ? AND kind = 'Erasure' AND status = 'Open'", [$patientId])) {
            return ['ok' => false, 'errors' => ['requester_name' => 'There is already an open erasure request for this patient. Wait for the manager to decide it.']];
        }
        $id = db_insert(
            'INSERT INTO data_requests (patient_id, kind, requester_name, requester_relation, identity_checked, identity_note, reason, status, created_by, decided_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ' . ($kind === 'Export' ? 'NOW()' : 'NULL') . ')',
            [$patientId, $kind, $c['requester_name'], $c['requester_relation'], $c['identity_note'], $c['reason'] !== '' ? $c['reason'] : null,
             $kind === 'Export' ? 'Completed' : 'Open', $userId]
        );
        $reference = sprintf('DR-%s-%06d', app_now()->format('Y'), $id);
        db_exec('UPDATE data_requests SET reference = ? WHERE id = ?', [$reference, $id]);
        // Identifiers only: never the requester's name, the identity note or the reason.
        audit('data_request', 'data_request', $id, "$reference: " . strtolower($kind) . ' request for ' . $p['op_number'], $userId);
        return ['ok' => true, 'id' => $id, 'reference' => $reference];
    });
}

function get_data_request(int $id): ?array
{
    return db_one(
        'SELECT dr.*, p.op_number, p.first_name, p.middle_name, p.last_name, p.erased_at, cu.full_name AS created_by_name, du.full_name AS decided_by_name
           FROM data_requests dr JOIN patients p ON p.id = dr.patient_id JOIN users cu ON cu.id = dr.created_by LEFT JOIN users du ON du.id = dr.decided_by
          WHERE dr.id = ?',
        [$id]
    );
}

/** Requests, open ones first and then newest first. Filters: kind, status, q (reference or outpatient number). */
function list_data_requests(array $f = [], int $limit = 200): array
{
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!empty($f['kind']) && in_array($f['kind'], ['Export', 'Erasure'], true)) {
        $where .= ' AND dr.kind = ?';
        $params[] = $f['kind'];
    }
    if (!empty($f['status']) && in_array($f['status'], DATA_REQUEST_STATUSES, true)) {
        $where .= ' AND dr.status = ?';
        $params[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $where .= ' AND (dr.reference LIKE ? OR p.op_number LIKE ?)';
        $like = like_escape(strtoupper(trim((string) $f['q']))) . '%';
        array_push($params, $like, $like);
    }
    return db_all(
        'SELECT dr.id, dr.reference, dr.kind, dr.status, dr.requester_relation, dr.created_at, dr.decided_at, dr.patient_id, p.op_number, p.first_name, p.middle_name, p.last_name,
                cu.full_name AS created_by_name, du.full_name AS decided_by_name
           FROM data_requests dr JOIN patients p ON p.id = dr.patient_id JOIN users cu ON cu.id = dr.created_by LEFT JOIN users du ON du.id = dr.decided_by'
        . $where . " ORDER BY (dr.status = 'Open') DESC, dr.id DESC LIMIT " . (int) $limit,
        $params
    );
}

function patient_data_requests(int $patientId): array
{
    return db_all(
        'SELECT dr.id, dr.reference, dr.kind, dr.status, dr.requester_relation, dr.created_at, dr.decided_at, cu.full_name AS created_by_name, du.full_name AS decided_by_name
           FROM data_requests dr JOIN users cu ON cu.id = dr.created_by LEFT JOIN users du ON du.id = dr.decided_by WHERE dr.patient_id = ? ORDER BY dr.id DESC',
        [$patientId]
    );
}

/** Records staff withdraw their own erasure request while it is still open. Returns an error message, or null. */
function cancel_data_request(int $id, int $userId): ?string
{
    return db_tx(function () use ($id, $userId): ?string {
        $dr = db_one('SELECT * FROM data_requests WHERE id = ? FOR UPDATE', [$id]);
        if (!$dr) {
            return 'That request does not exist.';
        }
        if ((int) $dr['created_by'] !== $userId) {
            return 'Only the person who recorded the request can withdraw it.';
        }
        if ($dr['status'] !== 'Open') {
            return 'This request has already been ' . strtolower($dr['status']) . '.';
        }
        db_exec("UPDATE data_requests SET status = 'Cancelled', decided_by = ?, decided_at = NOW(), decision_note = 'Withdrawn by Records' WHERE id = ?", [$userId, $id]);
        audit('data_request_cancel', 'data_request', $id, $dr['reference'] . ' withdrawn', $userId);
        return null;
    });
}

/* ---------- The copy of a record ---------- */

/** Money as a plain decimal string for the copy (no currency symbol, so the JSON can be read by other software). */
function copy_money($amount): string
{
    return number_format(to_cents($amount) / 100, 2, '.', '');
}

/**
 * Everything the hospital holds about one patient, in a structure that is printed as a page and also downloaded as JSON.
 * It does not include the audit trail (the hospital's own security log of which staff opened the record).
 */
function patient_record_bundle(int $patientId): ?array
{
    $p = db_one(
        'SELECT p.*, u.full_name AS registered_by_name FROM patients p JOIN users u ON u.id = p.registered_by WHERE p.id = ?',
        [$patientId]
    );
    if (!$p) {
        return null;
    }
    $bundle = [
        'hospital' => (string) config('hospital'),
        'prepared_on' => app_now()->format('Y-m-d H:i'),
        'patient' => [
            'outpatient_number' => $p['op_number'], 'first_name' => $p['first_name'], 'middle_name' => $p['middle_name'], 'last_name' => $p['last_name'],
            'sex' => $p['sex'], 'date_of_birth' => $p['date_of_birth'], 'date_of_birth_is_estimated' => (bool) $p['dob_estimated'],
            'national_id' => $p['national_id'], 'phone' => $p['phone'], 'residence' => $p['residence'], 'allergies' => $p['allergies'],
            'next_of_kin' => ['name' => $p['next_of_kin_name'], 'phone' => $p['next_of_kin_phone'], 'relationship' => $p['next_of_kin_relation']],
            'registered_on' => $p['created_at'], 'registered_by' => $p['registered_by_name'],
        ],
        'appointments' => [], 'visits' => [], 'invoices' => [], 'refund_requests' => [], 'data_requests' => [],
    ];

    foreach (db_all(
        'SELECT a.appointment_date, a.start_time, a.end_time, a.reason, a.status, a.cancel_reason, d.full_name AS doctor
           FROM appointments a JOIN users d ON d.id = a.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date, a.start_time',
        [$patientId]
    ) as $a) {
        $bundle['appointments'][] = [
            'date' => $a['appointment_date'], 'from' => substr($a['start_time'], 0, 5), 'to' => substr($a['end_time'], 0, 5), 'doctor' => $a['doctor'],
            'reason' => $a['reason'], 'status' => $a['status'], 'cancel_reason' => $a['cancel_reason'],
        ];
    }

    $visits = db_all(
        'SELECT v.id, v.visit_date, v.reason, v.status, v.closed_at, d.full_name AS doctor FROM visits v LEFT JOIN users d ON d.id = v.doctor_id
          WHERE v.patient_id = ? ORDER BY v.visit_date, v.id',
        [$patientId]
    );
    $ids = array_map(static fn ($v) => (int) $v['id'], $visits);
    $byVisit = static function (string $sql) use ($ids): array {
        if (!$ids) {
            return [];
        }
        $out = [];
        // The ids are integers read from the database, so listing them directly is safe.
        foreach (db_all(str_replace('{ids}', implode(',', $ids), $sql)) as $r) {
            $out[(int) $r['visit_id']][] = $r;
        }
        return $out;
    };
    $triage = $byVisit('SELECT t.*, u.full_name AS recorded_by_name FROM triage t JOIN users u ON u.id = t.recorded_by WHERE t.visit_id IN ({ids})');
    $consult = $byVisit(
        'SELECT c.*, d.full_name AS doctor, i.description AS icd10_description, s.name AS service FROM consultations c JOIN users d ON d.id = c.doctor_id
           LEFT JOIN icd10_codes i ON i.code = c.icd10_code LEFT JOIN services s ON s.id = c.service_id WHERE c.visit_id IN ({ids})'
    );
    $services = $byVisit('SELECT vs.visit_id, s.name, vs.quantity, vs.recorded_at FROM visit_services vs JOIN services s ON s.id = vs.service_id WHERE vs.visit_id IN ({ids}) ORDER BY vs.id');
    $labs = $byVisit(
        'SELECT l.visit_id, t.name AS test, l.status, l.requested_at, l.clinical_notes, l.result, l.is_abnormal, l.result_at
           FROM lab_requests l JOIN lab_tests t ON t.id = l.test_id WHERE l.visit_id IN ({ids}) ORDER BY l.id'
    );
    $rxItems = $byVisit(
        'SELECT rx.visit_id, rx.id AS prescription_id, rx.created_at, rx.status, m.name, m.form, m.strength, it.dosage, it.frequency, it.duration, it.quantity, it.dispensed_qty
           FROM prescriptions rx JOIN prescription_items it ON it.prescription_id = rx.id JOIN medicines m ON m.id = it.medicine_id
          WHERE rx.visit_id IN ({ids}) ORDER BY rx.id, it.id'
    );

    foreach ($visits as $v) {
        $vid = (int) $v['id'];
        $t = $triage[$vid][0] ?? null;
        $c = $consult[$vid][0] ?? null;
        $bundle['visits'][] = [
            'visit_date' => $v['visit_date'], 'reason' => $v['reason'], 'status' => $v['status'], 'closed_at' => $v['closed_at'], 'doctor' => $v['doctor'],
            'triage' => $t ? [
                'recorded_at' => $t['recorded_at'], 'recorded_by' => $t['recorded_by_name'], 'temperature_c' => $t['temperature'], 'pulse' => $t['pulse'],
                'blood_pressure' => $t['bp_systolic'] !== null ? $t['bp_systolic'] . '/' . $t['bp_diastolic'] : null, 'respiratory_rate' => $t['resp_rate'],
                'oxygen_saturation' => $t['spo2'], 'weight_kg' => $t['weight_kg'], 'height_cm' => $t['height_cm'], 'notes' => $t['notes'],
            ] : null,
            'consultation' => $c ? [
                'recorded_at' => $c['created_at'], 'doctor' => $c['doctor'], 'complaint' => $c['complaint'], 'examination' => $c['examination'],
                'diagnosis' => $c['diagnosis'], 'icd10_code' => $c['icd10_code'], 'icd10_description' => $c['icd10_description'], 'plan' => $c['plan'], 'service' => $c['service'],
            ] : null,
            'services' => array_map(static fn ($s) => ['service' => $s['name'], 'quantity' => (int) $s['quantity'], 'recorded_at' => $s['recorded_at']], $services[$vid] ?? []),
            'laboratory' => array_map(static fn ($l) => [
                'test' => $l['test'], 'status' => $l['status'], 'requested_at' => $l['requested_at'], 'clinical_notes' => $l['clinical_notes'],
                'result' => $l['result'], 'abnormal' => $l['is_abnormal'] === null ? null : (bool) $l['is_abnormal'], 'result_at' => $l['result_at'],
            ], $labs[$vid] ?? []),
            'medicines' => array_map(static fn ($m) => [
                'prescribed_on' => $m['created_at'], 'medicine' => trim($m['name'] . ' ' . $m['form'] . ' ' . $m['strength']), 'dosage' => $m['dosage'], 'frequency' => $m['frequency'],
                'duration' => $m['duration'], 'quantity_prescribed' => (int) $m['quantity'], 'quantity_dispensed' => (int) $m['dispensed_qty'], 'prescription_status' => $m['status'],
            ], $rxItems[$vid] ?? []),
        ];
    }

    foreach (db_all(
        'SELECT i.id, i.invoice_no, i.created_at, i.total, i.amount_paid, i.status FROM invoices i JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ? ORDER BY i.id',
        [$patientId]
    ) as $i) {
        $bundle['invoices'][] = [
            'invoice_number' => $i['invoice_no'], 'date' => $i['created_at'], 'status' => $i['status'], 'total' => copy_money($i['total']), 'amount_paid' => copy_money($i['amount_paid']),
            'items' => array_map(static fn ($it) => [
                'description' => $it['description'], 'type' => $it['source'], 'quantity' => (int) $it['quantity'], 'unit_price' => copy_money($it['unit_price']), 'amount' => copy_money($it['line_total']),
            ], db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$i['id']])),
            'payments' => array_map(static fn ($pay) => [
                'number' => receipt_number($pay), 'type' => $pay['kind'], 'date' => $pay['paid_at'], 'method' => $pay['method'], 'reference' => $pay['reference'], 'amount' => copy_money($pay['amount']),
            ], db_all('SELECT * FROM payments WHERE invoice_id = ? ORDER BY id', [$i['id']])),
        ];
    }

    foreach (db_all(
        'SELECT rr.reference, rr.amount, rr.reason, rr.status, rr.requested_at, rr.decided_at, i.invoice_no
           FROM refund_requests rr JOIN invoices i ON i.id = rr.invoice_id JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ? ORDER BY rr.id',
        [$patientId]
    ) as $r) {
        $bundle['refund_requests'][] = [
            'reference' => $r['reference'], 'invoice_number' => $r['invoice_no'], 'amount' => copy_money($r['amount']), 'reason' => $r['reason'],
            'status' => $r['status'], 'requested_at' => $r['requested_at'], 'decided_at' => $r['decided_at'],
        ];
    }

    foreach (patient_data_requests($patientId) as $d) {
        $bundle['data_requests'][] = ['reference' => $d['reference'], 'kind' => $d['kind'], 'status' => $d['status'], 'recorded_on' => $d['created_at']];
    }
    return $bundle;
}

/** The copy as an HTML page body (for printing). */
function render_record_copy(array $b): string
{
    $kv = static function (array $pairs): string {
        $h = '<dl class="kv">';
        foreach ($pairs as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $h .= '<dt>' . e($label) . '</dt><dd>' . e(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value) . '</dd>';
        }
        return $h . '</dl>';
    };
    $table = static function (array $head, array $rows, string $empty): string {
        if (!$rows) {
            return '<p class="muted">' . e($empty) . '</p>';
        }
        $h = '<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data"><thead><tr>';
        foreach ($head as $th) {
            $h .= '<th>' . e($th) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $cell) {
                $h .= '<td>' . e((string) $cell) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    };

    $p = $b['patient'];
    $h = '<div class="invoice record-copy">'
        . '<div class="invoice-head"><div><h2>' . e($b['hospital']) . '</h2><div class="muted">Copy of a patient record</div></div>'
        . '<div class="right"><div>Prepared ' . e(fmt_datetime($b['prepared_on'] . ':00')) . '</div></div></div>'
        . '<h3>Personal details</h3>'
        . $kv([
            'Outpatient number' => $p['outpatient_number'], 'Name' => implode(' ', array_filter([$p['first_name'], $p['middle_name'], $p['last_name']])),
            'Sex' => $p['sex'], 'Date of birth' => fmt_date($p['date_of_birth']) . ($p['date_of_birth_is_estimated'] ? ' (estimated)' : ''),
            'ID number' => $p['national_id'], 'Phone' => $p['phone'], 'Residence' => $p['residence'], 'Allergies' => $p['allergies'],
            'Next of kin' => $p['next_of_kin']['name'] ? $p['next_of_kin']['name'] . ' (' . $p['next_of_kin']['relationship'] . ') ' . $p['next_of_kin']['phone'] : null,
            'Registered' => fmt_datetime($p['registered_on']) . ' by ' . $p['registered_by'],
        ]);

    $h .= '<h3>Appointments</h3>' . $table(
        ['Date', 'Time', 'Doctor', 'Reason', 'Status'],
        array_map(static fn ($a) => [fmt_date($a['date']), $a['from'] . ' to ' . $a['to'], $a['doctor'], $a['reason'], $a['status'] . ($a['cancel_reason'] ? ' (' . $a['cancel_reason'] . ')' : '')], $b['appointments']),
        'No appointments.'
    );

    $h .= '<h3>Visits</h3>';
    if (!$b['visits']) {
        $h .= '<p class="muted">No visits.</p>';
    }
    foreach ($b['visits'] as $v) {
        $h .= '<div class="copy-visit"><h4>' . e(fmt_datetime($v['visit_date'])) . ' &middot; ' . e($v['status']) . '</h4>'
            . $kv(['Reason' => $v['reason'], 'Doctor' => $v['doctor']]);
        if ($v['triage']) {
            $t = $v['triage'];
            $h .= $kv([
                'Vital signs' => implode(', ', array_filter([
                    $t['temperature_c'] !== null ? 'temperature ' . $t['temperature_c'] . ' C' : null, $t['pulse'] !== null ? 'pulse ' . $t['pulse'] : null,
                    $t['blood_pressure'] !== null ? 'blood pressure ' . $t['blood_pressure'] : null, $t['respiratory_rate'] !== null ? 'breathing ' . $t['respiratory_rate'] : null,
                    $t['oxygen_saturation'] !== null ? 'oxygen ' . $t['oxygen_saturation'] . '%' : null, $t['weight_kg'] !== null ? 'weight ' . $t['weight_kg'] . ' kg' : null,
                    $t['height_cm'] !== null ? 'height ' . $t['height_cm'] . ' cm' : null,
                ])),
                'Nurse notes' => $t['notes'],
            ]);
        }
        if ($v['consultation']) {
            $c = $v['consultation'];
            $h .= $kv([
                'Complaint' => $c['complaint'], 'Examination' => $c['examination'],
                'Diagnosis' => $c['diagnosis'] . ($c['icd10_code'] ? ' [' . $c['icd10_code'] . ']' : ''), 'Plan' => $c['plan'], 'Seen by' => $c['doctor'],
            ]);
        }
        if ($v['services']) {
            $h .= $kv(['Procedures' => implode(', ', array_map(static fn ($s) => $s['service'] . ($s['quantity'] > 1 ? ' x' . $s['quantity'] : ''), $v['services']))]);
        }
        if ($v['laboratory']) {
            $h .= $table(['Test', 'Status', 'Result', 'Abnormal'], array_map(static fn ($l) => [$l['test'], $l['status'], $l['result'], $l['abnormal'] === null ? '' : ($l['abnormal'] ? 'Yes' : 'No')], $v['laboratory']), '');
        }
        if ($v['medicines']) {
            $h .= $table(
                ['Medicine', 'Dosage', 'Quantity', 'Given'],
                array_map(static fn ($m) => [$m['medicine'], trim(($m['dosage'] ?? '') . ' ' . ($m['frequency'] ?? '') . ' ' . ($m['duration'] ?? '')), $m['quantity_prescribed'], $m['quantity_dispensed']], $v['medicines']),
                ''
            );
        }
        $h .= '</div>';
    }

    $h .= '<h3>Invoices and payments</h3>';
    if (!$b['invoices']) {
        $h .= '<p class="muted">No invoices.</p>';
    }
    foreach ($b['invoices'] as $i) {
        $h .= '<h4>' . e($i['invoice_number']) . ' &middot; ' . e(fmt_date(substr($i['date'], 0, 10))) . ' &middot; ' . e($i['status']) . '</h4>'
            . $table(['Item', 'Qty', 'Amount (KSh)'], array_map(static fn ($it) => [$it['description'], $it['quantity'], $it['amount']], $i['items']), 'No charges.')
            . $table(['Payment', 'Date', 'Method', 'Amount (KSh)'], array_map(static fn ($pay) => [$pay['number'], fmt_datetime($pay['date']), $pay['method'], $pay['amount']], $i['payments']), 'No payments.')
            . '<p class="small">Total ' . e($i['total']) . ' &middot; paid ' . e($i['amount_paid']) . '</p>';
    }
    if ($b['refund_requests']) {
        $h .= '<h3>Refund requests</h3>' . $table(
            ['Reference', 'Invoice', 'Amount (KSh)', 'Status', 'Reason'],
            array_map(static fn ($r) => [$r['reference'], $r['invoice_number'], $r['amount'], $r['status'], $r['reason']], $b['refund_requests']),
            ''
        );
    }
    if ($b['data_requests']) {
        $h .= '<h3>Requests about this record</h3>' . $table(
            ['Reference', 'Kind', 'Status', 'Recorded'],
            array_map(static fn ($d) => [$d['reference'], $d['kind'], $d['status'], fmt_datetime($d['recorded_on'])], $b['data_requests']),
            ''
        );
    }
    return $h . '<p class="muted small">This copy does not include the hospital&#39;s security log of which staff opened the record. Ask the hospital&#39;s data protection contact if you want to know more about how your information is used.</p></div>';
}

/* ---------- Erasure ---------- */

/**
 * Reasons a record cannot be erased right now, as plain sentences. Empty means it can be erased.
 * These never contain a name. $retentionYears overrides config 'privacy.retention_years' (the tests use it).
 */
function erasure_blockers(int $patientId, ?int $retentionYears = null): array
{
    $p = db_one('SELECT id, erased_at, created_at FROM patients WHERE id = ?', [$patientId]);
    if (!$p) {
        return ['The patient record does not exist.'];
    }
    if ($p['erased_at'] !== null) {
        return ['This record has already been erased.'];
    }
    $b = [];
    if ((int) db_value("SELECT COUNT(*) FROM visits WHERE patient_id = ? AND status = 'Open'", [$patientId]) > 0) {
        $b[] = 'The patient has an open visit. It must be finished and closed first.';
    }
    $booked = (int) db_value("SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status = 'Booked'", [$patientId]);
    if ($booked > 0) {
        $b[] = plural($booked, 'booked appointment') . ' must be cancelled first.';
    }
    foreach (db_all(
        'SELECT i.invoice_no, i.total - i.amount_paid AS balance FROM invoices i JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ? AND i.total > i.amount_paid ORDER BY i.id',
        [$patientId]
    ) as $inv) {
        $b[] = 'Invoice ' . $inv['invoice_no'] . ' still has a balance of ' . fmt_money($inv['balance']) . '. The hospital may still need the record to collect it.';
    }
    $pendingRefunds = (int) db_value(
        "SELECT COUNT(*) FROM refund_requests rr JOIN invoices i ON i.id = rr.invoice_id JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ? AND rr.status = 'Pending'",
        [$patientId]
    );
    if ($pendingRefunds > 0) {
        $b[] = plural($pendingRefunds, 'refund request') . ' for this patient must be decided first.';
    }
    $years = $retentionYears ?? (int) config('privacy.retention_years', 0);
    if ($years > 0) {
        $last = db_value('SELECT MAX(visit_date) FROM visits WHERE patient_id = ?', [$patientId]) ?: $p['created_at'];
        $until = (new DateTimeImmutable((string) $last))->modify("+$years years");
        if ($until > app_now()) {
            $b[] = "The record must be kept until " . fmt_date($until->format('Y-m-d')) . " ($years years after the last visit).";
        }
    }
    return $b;
}

/** What an erasure keeps and what it blanks, as counts (no names), for the manager's page. */
function erasure_impact(int $patientId): array
{
    $n = static fn (string $sql): int => (int) db_value($sql, [$patientId]);
    return [
        'visits' => $n('SELECT COUNT(*) FROM visits WHERE patient_id = ?'),
        'appointments' => $n('SELECT COUNT(*) FROM appointments WHERE patient_id = ?'),
        'consultations' => $n('SELECT COUNT(*) FROM consultations c JOIN visits v ON v.id = c.visit_id WHERE v.patient_id = ?'),
        'lab_requests' => $n('SELECT COUNT(*) FROM lab_requests l JOIN visits v ON v.id = l.visit_id WHERE v.patient_id = ?'),
        'invoices' => $n('SELECT COUNT(*) FROM invoices i JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ?'),
        'payments' => $n('SELECT COUNT(*) FROM payments pay JOIN invoices i ON i.id = pay.invoice_id JOIN visits v ON v.id = i.visit_id WHERE v.patient_id = ?'),
    ];
}

/**
 * Anonymise one patient. Call inside a transaction with the patient row locked. See the file header for what changes.
 */
function erase_patient_record(int $patientId, int $userId, string $requestReference): void
{
    $gone = ERASED_TEXT;
    db_exec(
        "UPDATE patients SET first_name = 'Erased', middle_name = NULL, last_name = 'Patient', op_number = CONCAT('ERASED-', LPAD(id, 6, '0')),
                national_id = NULL, phone = NULL, residence = NULL, allergies = NULL,
                next_of_kin_name = NULL, next_of_kin_phone = NULL, next_of_kin_relation = NULL,
                date_of_birth = MAKEDATE(YEAR(date_of_birth), 1), dob_estimated = 1, erased_at = NOW(), erased_by = ?
          WHERE id = ?",
        [$userId, $patientId]
    );
    db_exec('UPDATE visits SET reason = ? WHERE patient_id = ? AND reason IS NOT NULL', [$gone, $patientId]);
    db_exec(
        'UPDATE appointments SET reason = IF(reason IS NULL, NULL, ?), cancel_reason = IF(cancel_reason IS NULL, NULL, ?) WHERE patient_id = ?',
        [$gone, $gone, $patientId]
    );
    db_exec(
        'UPDATE consultations c JOIN visits v ON v.id = c.visit_id
            SET c.complaint = IF(c.complaint IS NULL, NULL, ?), c.examination = IF(c.examination IS NULL, NULL, ?), c.plan = IF(c.plan IS NULL, NULL, ?),
                c.diagnosis = IF(c.diagnosis IS NULL, NULL, COALESCE((SELECT d.description FROM icd10_codes d WHERE d.code = c.icd10_code), ?))
          WHERE v.patient_id = ?',
        [$gone, $gone, $gone, $gone, $patientId]
    );
    db_exec('UPDATE triage t JOIN visits v ON v.id = t.visit_id SET t.notes = IF(t.notes IS NULL, NULL, ?) WHERE v.patient_id = ?', [$gone, $patientId]);
    db_exec(
        'UPDATE lab_requests l JOIN visits v ON v.id = l.visit_id
            SET l.clinical_notes = IF(l.clinical_notes IS NULL, NULL, ?), l.result = IF(l.result IS NULL, NULL, ?) WHERE v.patient_id = ?',
        [$gone, $gone, $patientId]
    );
    db_exec(
        'UPDATE refund_requests rr JOIN invoices i ON i.id = rr.invoice_id JOIN visits v ON v.id = i.visit_id
            SET rr.reason = ?, rr.decision_note = IF(rr.decision_note IS NULL, NULL, ?) WHERE v.patient_id = ?',
        [$gone, $gone, $patientId]
    );
    // The requests name the people who asked; once the record is erased they are blanked too.
    db_exec(
        'UPDATE data_requests SET requester_name = ?, identity_note = NULL, reason = NULL, decision_note = IF(decision_note IS NULL, NULL, ?) WHERE patient_id = ?',
        [$gone, $gone, $patientId]
    );
    audit('erase', 'patient', $patientId, "Record anonymised ($requestReference)", $userId);
}

/**
 * The manager approves or refuses an erasure request. A refusal needs a note; an approval is only possible when nothing blocks it.
 * Returns ['ok' => true] or ['ok' => false, 'error' => text].
 */
function decide_erasure(int $requestId, string $decision, string $note, int $userId): array
{
    $note = clean_text($note, 255);
    if (!in_array($decision, ['approve', 'refuse'], true)) {
        return ['ok' => false, 'error' => 'Choose approve or refuse.'];
    }
    if ($decision === 'refuse' && $note === '') {
        return ['ok' => false, 'error' => 'Say why the request is refused. Records must be able to tell the patient.'];
    }
    return db_tx(function () use ($requestId, $decision, $note, $userId): array {
        $dr = db_one("SELECT * FROM data_requests WHERE id = ? AND kind = 'Erasure' FOR UPDATE", [$requestId]);
        if (!$dr) {
            return ['ok' => false, 'error' => 'That erasure request does not exist.'];
        }
        if ($dr['status'] !== 'Open') {
            return ['ok' => false, 'error' => 'This request has already been ' . strtolower($dr['status']) . '.'];
        }
        if ((int) $dr['created_by'] === $userId) {
            return ['ok' => false, 'error' => 'You cannot decide a request you recorded yourself. Another person must decide it.'];
        }
        if ($decision === 'refuse') {
            db_exec("UPDATE data_requests SET status = 'Refused', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$userId, $note, $requestId]);
            audit('erase_refused', 'data_request', $requestId, $dr['reference'] . ' refused', $userId);
            return ['ok' => true];
        }
        // The patient row is locked, so a check-in or booking made at the same moment waits until the erasure is finished.
        db_value('SELECT id FROM patients WHERE id = ? FOR UPDATE', [$dr['patient_id']]);
        $blockers = erasure_blockers((int) $dr['patient_id']);
        if ($blockers) {
            return ['ok' => false, 'error' => 'This record cannot be erased yet. ' . implode(' ', $blockers) . ' Refuse the request with that reason, or ask Records to try again later.'];
        }
        erase_patient_record((int) $dr['patient_id'], $userId, (string) $dr['reference']);
        db_exec("UPDATE data_requests SET status = 'Completed', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$userId, $note !== '' ? $note : null, $requestId]);
        return ['ok' => true];
    });
}
