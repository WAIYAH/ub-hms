<?php
/**
 * Module 2: clinical consultation.
 *
 * A visit starts at check-in, passes through triage and the doctor's
 * consultation, and is closed by the doctor. Laboratory requests,
 * prescriptions and procedures recorded during the visit later feed the
 * invoice (see billing.php).
 */
declare(strict_types=1);

/* ---------- Visits and check-in ---------- */

function get_visit(int $id): ?array
{
    return db_one(
        'SELECT v.*, p.op_number, p.first_name, p.middle_name, p.last_name, p.sex, p.date_of_birth, p.allergies,
                d.full_name AS doctor_name,
                (SELECT COUNT(*) FROM triage t WHERE t.visit_id = v.id) AS has_triage,
                (SELECT COUNT(*) FROM consultations c WHERE c.visit_id = v.id) AS has_consultation
           FROM visits v JOIN patients p ON p.id = v.patient_id LEFT JOIN users d ON d.id = v.doctor_id WHERE v.id = ?',
        [$id]
    );
}

/** Where a visit is in the clinic flow. */
function visit_stage(array $v): string
{
    if ($v['status'] === 'Closed') {
        return 'Closed';
    }
    if ((int) $v['has_consultation'] > 0) {
        return 'With doctor';
    }
    if ((int) $v['has_triage'] > 0) {
        return 'Waiting for doctor';
    }
    return 'Waiting for triage';
}

/**
 * Check a patient in. Pass an appointment id for a booked patient; otherwise
 * it is a walk-in (a preferred doctor is optional).
 * Returns ['ok' => true, 'visit_id' => n] or ['ok' => false, 'error' => message].
 */
function check_in_patient(int $patientId, ?int $appointmentId, ?int $doctorId, string $reason, int $userId): array
{
    if (!db_value('SELECT id FROM patients WHERE id = ?', [$patientId])) {
        return ['ok' => false, 'error' => 'Patient not found.'];
    }
    $open = db_one("SELECT id FROM visits WHERE patient_id = ? AND status = 'Open'", [$patientId]);
    if ($open) {
        return ['ok' => false, 'error' => 'This patient already has an open visit. Finish or close it before checking in again.', 'visit_id' => (int) $open['id']];
    }
    $reason = clean_text($reason, 255);

    return db_tx(function () use ($patientId, $appointmentId, $doctorId, $reason, $userId): array {
        if ($appointmentId !== null) {
            $a = db_one('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$appointmentId]);
            if (!$a || (int) $a['patient_id'] !== $patientId) {
                return ['ok' => false, 'error' => 'That appointment does not belong to this patient.'];
            }
            if ($a['status'] !== 'Booked') {
                return ['ok' => false, 'error' => 'That appointment is ' . strtolower($a['status']) . ' and cannot be used to check in.'];
            }
            if ($a['appointment_date'] !== app_today()) {
                return ['ok' => false, 'error' => 'Check-in is only possible on the day of the appointment (' . fmt_date($a['appointment_date']) . ').'];
            }
            db_exec("UPDATE appointments SET status = 'Attended' WHERE id = ?", [$appointmentId]);
            $doctorId = (int) $a['doctor_id'];
            $reason = $reason !== '' ? $reason : (string) $a['reason'];
        } elseif ($doctorId !== null && !db_value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.code = 'doctor' AND u.is_active = 1", [$doctorId])) {
            return ['ok' => false, 'error' => 'Choose a valid doctor, or leave it blank for the next available doctor.'];
        }
        $id = db_insert(
            'INSERT INTO visits (patient_id, appointment_id, doctor_id, reason, opened_by) VALUES (?, ?, ?, ?, ?)',
            [$patientId, $appointmentId, $doctorId, $reason !== '' ? $reason : null, $userId]
        );
        audit('checkin', 'visit', $id, $appointmentId !== null ? 'Checked in from an appointment' : 'Walk-in check-in', $userId);
        return ['ok' => true, 'visit_id' => $id];
    });
}

/**
 * Open visits for the queues. $stage: 'triage', 'consult' or '' (all open).
 * For 'consult', $doctorId (optional) shows that doctor's patients and anyone not yet assigned.
 */
function visit_queue(string $stage = '', ?int $doctorId = null): array
{
    $rows = db_all(
        "SELECT v.*, p.op_number, p.first_name, p.middle_name, p.last_name, p.sex, p.date_of_birth, p.allergies,
                d.full_name AS doctor_name,
                (SELECT COUNT(*) FROM triage t WHERE t.visit_id = v.id) AS has_triage,
                (SELECT COUNT(*) FROM consultations c WHERE c.visit_id = v.id) AS has_consultation
           FROM visits v JOIN patients p ON p.id = v.patient_id LEFT JOIN users d ON d.id = v.doctor_id
          WHERE v.status = 'Open' ORDER BY v.visit_date, v.id"
    );
    $out = [];
    foreach ($rows as $v) {
        $s = visit_stage($v);
        if ($stage === 'triage' && $s !== 'Waiting for triage') {
            continue;
        }
        if ($stage === 'consult') {
            if ($s === 'Waiting for triage') {
                continue;
            }
            if ($doctorId !== null && $v['doctor_id'] !== null && (int) $v['doctor_id'] !== $doctorId) {
                continue;
            }
        }
        $v['stage'] = $s;
        $out[] = $v;
    }
    return $out;
}

/** Close the visit after the consultation. Only a doctor with a recorded consultation can do this. */
function close_visit(int $visitId, int $userId): ?string
{
    $v = get_visit($visitId);
    if (!$v) {
        return 'Visit not found.';
    }
    if ($v['status'] === 'Closed') {
        return 'This visit is already closed.';
    }
    if (!(int) $v['has_consultation']) {
        return 'Record the consultation before closing the visit.';
    }
    db_exec("UPDATE visits SET status = 'Closed', closed_at = NOW() WHERE id = ?", [$visitId]);
    audit('close', 'visit', $visitId, null, $userId);
    return null;
}

/* ---------- Triage ---------- */

/** Allowed ranges guard against typing errors (a plausible value, not a clinical judgement). */
function triage_limits(): array
{
    return [
        'temperature'  => ['Temperature (°C)', 30, 45, 1],
        'pulse'        => ['Pulse (beats per minute)', 20, 250, 0],
        'resp_rate'    => ['Respiratory rate (breaths per minute)', 5, 80, 0],
        'bp_systolic'  => ['Blood pressure, upper (mmHg)', 50, 300, 0],
        'bp_diastolic' => ['Blood pressure, lower (mmHg)', 30, 200, 0],
        'spo2'         => ['Oxygen saturation (%)', 50, 100, 0],
        'weight_kg'    => ['Weight (kg)', 0.3, 400, 1],
        'height_cm'    => ['Height (cm)', 20, 250, 1],
    ];
}

/** Returns [clean, errors]. At least one measurement or a note is required. */
function validate_triage_input(array $in): array
{
    $errors = [];
    $clean = [];
    $any = false;
    foreach (triage_limits() as $field => [$label, $min, $max, $decimals]) {
        $raw = clean_text($in[$field] ?? '', 10);
        if ($raw === '') {
            $clean[$field] = null;
            continue;
        }
        if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
            $errors[$field] = "$label must be a number from $min to $max.";
            continue;
        }
        if ($decimals === 0 && (float) $raw != (int) $raw) {
            $errors[$field] = "$label must be a whole number.";
            continue;
        }
        $clean[$field] = $decimals === 0 ? (int) $raw : round((float) $raw, 1);
        $any = true;
    }
    if (($clean['bp_systolic'] ?? null) !== null xor ($clean['bp_diastolic'] ?? null) !== null) {
        $errors['bp_systolic'] = 'Enter both blood pressure values, or neither.';
    } elseif (($clean['bp_systolic'] ?? null) !== null && $clean['bp_systolic'] <= $clean['bp_diastolic'] && !isset($errors['bp_diastolic'])) {
        $errors['bp_systolic'] = 'The upper blood pressure must be higher than the lower one.';
    }
    $clean['notes'] = clean_text($in['notes'] ?? '', 500);
    if ($clean['notes'] === '') {
        $clean['notes'] = null;
    } else {
        $any = true;
    }
    if (!$any && !$errors) {
        $errors['temperature'] = 'Record at least one measurement or a note.';
    }
    return [$clean, $errors];
}

/** Save (or correct) triage for an open visit. Returns ['ok' => bool, 'errors' => []]. */
function save_triage(int $visitId, array $in, int $userId): array
{
    $v = get_visit($visitId);
    if (!$v || $v['status'] !== 'Open') {
        return ['ok' => false, 'errors' => ['temperature' => 'Triage can only be recorded for an open visit.']];
    }
    [$c, $errors] = validate_triage_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $exists = db_value('SELECT id FROM triage WHERE visit_id = ?', [$visitId]);
    if ($exists) {
        db_exec(
            'UPDATE triage SET temperature = ?, pulse = ?, bp_systolic = ?, bp_diastolic = ?, resp_rate = ?, spo2 = ?,
                    weight_kg = ?, height_cm = ?, notes = ?, recorded_by = ?, recorded_at = NOW() WHERE visit_id = ?',
            [$c['temperature'], $c['pulse'], $c['bp_systolic'], $c['bp_diastolic'], $c['resp_rate'], $c['spo2'], $c['weight_kg'], $c['height_cm'], $c['notes'], $userId, $visitId]
        );
        audit('update', 'triage', (int) $exists, 'Triage corrected for visit ' . $visitId, $userId);
    } else {
        $id = db_insert(
            'INSERT INTO triage (visit_id, temperature, pulse, bp_systolic, bp_diastolic, resp_rate, spo2, weight_kg, height_cm, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$visitId, $c['temperature'], $c['pulse'], $c['bp_systolic'], $c['bp_diastolic'], $c['resp_rate'], $c['spo2'], $c['weight_kg'], $c['height_cm'], $c['notes'], $userId]
        );
        audit('create', 'triage', $id, 'Triage recorded for visit ' . $visitId, $userId);
    }
    return ['ok' => true, 'errors' => []];
}

function get_triage(int $visitId): ?array
{
    return db_one('SELECT t.*, u.full_name AS nurse_name FROM triage t JOIN users u ON u.id = t.recorded_by WHERE t.visit_id = ?', [$visitId]);
}

/* ---------- Consultation ---------- */

function consultation_services(): array
{
    return db_all("SELECT id, name, price FROM services WHERE category = 'Consultation' AND is_active = 1 ORDER BY id");
}

function icd10_lookup(string $q, int $limit = 15): array
{
    $like = '%' . like_escape($q) . '%';
    return db_all('SELECT code, description FROM icd10_codes WHERE is_active = 1 AND (code LIKE ? OR description LIKE ?) ORDER BY code LIMIT ' . (int) $limit, [$like, $like]);
}

function get_consultation(int $visitId): ?array
{
    return db_one(
        'SELECT c.*, u.full_name AS doctor_name, i.description AS icd10_description, s.name AS service_name
           FROM consultations c JOIN users u ON u.id = c.doctor_id
           LEFT JOIN icd10_codes i ON i.code = c.icd10_code LEFT JOIN services s ON s.id = c.service_id
          WHERE c.visit_id = ?',
        [$visitId]
    );
}

/** Returns [clean, errors]. */
function validate_consultation_input(array $in): array
{
    $errors = [];
    $c = [
        'complaint'   => clean_multiline($in['complaint'] ?? '', 2000),
        'examination' => clean_multiline($in['examination'] ?? '', 2000),
        'diagnosis'   => clean_text($in['diagnosis'] ?? '', 255),
        'icd10_code'  => strtoupper(clean_text($in['icd10_code'] ?? '', 10)),
        'plan'        => clean_multiline($in['plan'] ?? '', 2000),
        'service_id'  => (int) ($in['service_id'] ?? 0),
    ];
    if ($c['complaint'] === '') {
        $errors['complaint'] = "Record the patient's complaint.";
    }
    if ($c['diagnosis'] === '') {
        $errors['diagnosis'] = 'Enter the diagnosis in words. A code can be added in addition.';
    }
    if ($c['icd10_code'] !== '' && !db_value('SELECT code FROM icd10_codes WHERE code = ? AND is_active = 1', [$c['icd10_code']])) {
        $errors['icd10_code'] = 'That ICD-10 code is not in the hospital list. Leave it blank or pick one from the list.';
    }
    if (!db_value("SELECT id FROM services WHERE id = ? AND category = 'Consultation' AND is_active = 1", [$c['service_id']])) {
        $errors['service_id'] = 'Choose the consultation type.';
    }
    foreach (['icd10_code', 'examination', 'plan'] as $k) {
        if ($c[$k] === '') {
            $c[$k] = null;
        }
    }
    return [$c, $errors];
}

/**
 * Record or correct the consultation. Only the doctor who started it may change it.
 * Returns ['ok' => bool, 'errors' => []].
 */
function save_consultation(int $visitId, array $in, int $doctorId): array
{
    $v = get_visit($visitId);
    if (!$v) {
        return ['ok' => false, 'errors' => ['complaint' => 'Visit not found.']];
    }
    if ($v['status'] !== 'Open') {
        return ['ok' => false, 'errors' => ['complaint' => 'This visit is closed, so the consultation can no longer be changed.']];
    }
    $existing = get_consultation($visitId);
    if ($existing && (int) $existing['doctor_id'] !== $doctorId) {
        return ['ok' => false, 'errors' => ['complaint' => 'This consultation was recorded by ' . $existing['doctor_name'] . ' and only that doctor can change it.']];
    }
    [$c, $errors] = validate_consultation_input($in);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    db_tx(function () use ($existing, $visitId, $doctorId, $c, $v): void {
        if ($existing) {
            db_exec(
                'UPDATE consultations SET service_id = ?, complaint = ?, examination = ?, diagnosis = ?, icd10_code = ?, plan = ? WHERE visit_id = ?',
                [$c['service_id'], $c['complaint'], $c['examination'], $c['diagnosis'], $c['icd10_code'], $c['plan'], $visitId]
            );
            audit('update', 'consultation', (int) $existing['id'], 'Consultation corrected for visit ' . $visitId, $doctorId);
        } else {
            $id = db_insert(
                'INSERT INTO consultations (visit_id, doctor_id, service_id, complaint, examination, diagnosis, icd10_code, plan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$visitId, $doctorId, $c['service_id'], $c['complaint'], $c['examination'], $c['diagnosis'], $c['icd10_code'], $c['plan']]
            );
            if ($v['doctor_id'] === null || (int) $v['doctor_id'] !== $doctorId) {
                db_exec('UPDATE visits SET doctor_id = ? WHERE id = ?', [$doctorId, $visitId]);
            }
            audit('create', 'consultation', $id, 'Consultation recorded for visit ' . $visitId, $doctorId);
        }
        if (function_exists('billing_sync_visit')) {
            billing_sync_visit($visitId, $doctorId);
        }
    });
    return ['ok' => true, 'errors' => []];
}

/* ---------- Procedures and other services ---------- */

function chargeable_services(): array
{
    return db_all("SELECT id, name, price FROM services WHERE category <> 'Consultation' AND is_active = 1 ORDER BY name");
}

/** Record a procedure (for example a wound dressing) so it appears on the invoice. */
function record_visit_service(int $visitId, int $serviceId, int $quantity, int $userId): ?string
{
    $v = get_visit($visitId);
    if (!$v || $v['status'] !== 'Open') {
        return 'Services can only be recorded on an open visit.';
    }
    if (!db_value("SELECT id FROM services WHERE id = ? AND category <> 'Consultation' AND is_active = 1", [$serviceId])) {
        return 'Choose a service from the list.';
    }
    if ($quantity < 1 || $quantity > 20) {
        return 'Quantity must be from 1 to 20.';
    }
    db_tx(function () use ($visitId, $serviceId, $quantity, $userId): void {
        $id = db_insert('INSERT INTO visit_services (visit_id, service_id, quantity, recorded_by) VALUES (?, ?, ?, ?)', [$visitId, $serviceId, $quantity, $userId]);
        audit('create', 'visit_service', $id, "Service $serviceId recorded for visit $visitId", $userId);
        if (function_exists('billing_sync_visit')) {
            billing_sync_visit($visitId, $userId);
        }
    });
    return null;
}

function visit_services_list(int $visitId): array
{
    return db_all(
        'SELECT vs.*, s.name, s.price, u.full_name AS recorded_by_name FROM visit_services vs
           JOIN services s ON s.id = vs.service_id JOIN users u ON u.id = vs.recorded_by WHERE vs.visit_id = ? ORDER BY vs.id',
        [$visitId]
    );
}

/** Patient name, number, age and allergy warning shown at the top of clinical pages. $p needs the patients columns. */
function render_patient_banner(array $p): string
{
    $out = '<div class="card"><div class="record-head"><h2>' . e(patient_full_name($p)) . '</h2><span class="op-number">' . e($p['op_number']) . '</span>'
        . '<span class="muted">' . e($p['sex']) . ', ' . e(age_label($p['date_of_birth'])) . '</span></div>';
    if (!empty($p['allergies'])) {
        $out .= '<div class="allergy" role="note"><strong>Allergies:</strong> ' . e($p['allergies']) . '</div>';
    }
    return $out . '</div>';
}

/* ---------- Earlier visits (history) ---------- */

/**
 * A patient's earlier visits, newest first, with diagnosis, tests and medicines
 * when those modules are present. Pass $excludeVisitId to leave out the current visit.
 */
function patient_history(int $patientId, ?int $excludeVisitId = null, int $limit = 20): array
{
    $visits = db_all(
        'SELECT v.id, v.visit_date, v.status, v.reason, d.full_name AS doctor_name,
                c.diagnosis, c.icd10_code, c.plan, c.complaint
           FROM visits v LEFT JOIN users d ON d.id = v.doctor_id LEFT JOIN consultations c ON c.visit_id = v.id
          WHERE v.patient_id = ? AND v.id <> ? ORDER BY v.visit_date DESC, v.id DESC LIMIT ' . (int) $limit,
        [$patientId, $excludeVisitId ?? 0]
    );
    foreach ($visits as &$v) {
        $v['tests'] = function_exists('visit_lab_requests') ? visit_lab_requests((int) $v['id']) : [];
        $v['medicines'] = function_exists('visit_prescription_items') ? visit_prescription_items((int) $v['id']) : [];
    }
    unset($v);
    return $visits;
}

/**
 * Block shown on the patient record: full clinical history for doctors,
 * a plain list of visits and appointments for other roles that may see visits.
 */
function render_patient_history(array $p, array $user): string
{
    $clinical = role_can($user['role'], 'consult.record');
    if (!$clinical && !role_can($user['role'], 'visits.view')) {
        return '';
    }
    $visits = patient_history((int) $p['id']);
    $out = '<div class="card"><h2>Visits</h2>';
    if (!$visits) {
        return $out . '<p class="muted">No visits recorded yet.</p></div>';
    }
    $out .= '<div class="table-wrap" tabindex="0" role="group" aria-label="Table: scroll sideways if it does not fit"><table class="data"><thead><tr><th>Date</th><th>Doctor</th><th>Status</th>'
        . ($clinical ? '<th>Diagnosis</th><th>Tests</th><th>Medicines</th>' : '<th>Reason</th>') . '<th><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($visits as $v) {
        $out .= '<tr><td class="nowrap">' . e(fmt_datetime($v['visit_date'])) . '</td><td>' . e($v['doctor_name'] ?? '') . '</td><td>' . badge($v['status']) . '</td>';
        if ($clinical) {
            $tests = implode(', ', array_map(static fn ($t) => $t['test_name'] . ' (' . strtolower($t['status']) . ')', $v['tests']));
            $meds = implode(', ', array_map(static fn ($m) => $m['medicine_name'], $v['medicines']));
            $out .= '<td>' . e($v['diagnosis'] ?? '') . ($v['icd10_code'] ? ' <span class="muted">[' . e($v['icd10_code']) . ']</span>' : '') . '</td>'
                . '<td>' . e($tests) . '</td><td>' . e($meds) . '</td>';
        } else {
            $out .= '<td>' . e($v['reason'] ?? '') . '</td>';
        }
        $out .= '<td><a class="btn btn-small btn-secondary" href="' . e(url('visits/view.php', ['id' => $v['id']])) . '">Open</a></td></tr>';
    }
    return $out . '</tbody></table></div></div>';
}
