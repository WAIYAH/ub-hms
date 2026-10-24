<?php
/**
 * Module 1: patient registration, outpatient numbers, duplicate checks,
 * search, correction and CSV import.
 */
declare(strict_types=1);

const PATIENT_FIELDS = [
    'first_name', 'middle_name', 'last_name', 'sex', 'date_of_birth', 'dob_estimated', 'national_id',
    'phone', 'residence', 'allergies', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relation',
];

/**
 * Issue the next outpatient number, e.g. OP26-00001 (OP + two-digit year +
 * five-digit serial). The counter is incremented inside the caller's
 * transaction, so two receptionists can never receive the same number.
 */
function next_op_number(?DateTimeImmutable $on = null): string
{
    $on = $on ?? app_now();
    $year = (int) $on->format('Y');
    return db_tx(static function () use ($year): string {
        db_exec('INSERT INTO op_sequences (year, last_number) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1', [$year]);
        $n = (int) db_value('SELECT last_number FROM op_sequences WHERE year = ?', [$year]);
        return sprintf('OP%02d-%05d', $year % 100, $n);
    });
}

/**
 * Validate registration or correction input. Returns [clean, errors].
 * Names are limited to letters, spaces, apostrophes, dots and hyphens.
 */
function validate_patient_input(array $in): array
{
    $errors = [];
    $c = [];
    $name = static fn (string $k, int $max) => clean_text($in[$k] ?? '', $max);

    $c['first_name'] = $name('first_name', 50);
    $c['middle_name'] = $name('middle_name', 50);
    $c['last_name'] = $name('last_name', 50);
    foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $k => $label) {
        if ($c[$k] === '') {
            $errors[$k] = "Enter the patient's $label.";
        }
    }
    foreach (['first_name', 'middle_name', 'last_name'] as $k) {
        if ($c[$k] !== '' && !preg_match("/^[\\p{L}][\\p{L} .'\\-]*$/u", $c[$k])) {
            $errors[$k] = 'Use letters, spaces, apostrophes, dots or hyphens only.';
        }
    }

    $c['sex'] = $name('sex', 10);
    if (!in_array($c['sex'], ['Female', 'Male'], true)) {
        $errors['sex'] = 'Choose Female or Male.';
    }

    $c['date_of_birth'] = $name('date_of_birth', 10);
    if (!is_valid_date($c['date_of_birth'])) {
        $errors['date_of_birth'] = 'Enter a valid date of birth.';
    } elseif ($c['date_of_birth'] > app_today()) {
        $errors['date_of_birth'] = 'The date of birth cannot be in the future.';
    } elseif (age_on($c['date_of_birth']) > 120) {
        $errors['date_of_birth'] = 'Check the date of birth: the age would be over 120 years.';
    }
    $c['dob_estimated'] = !empty($in['dob_estimated']) ? 1 : 0;

    $c['national_id'] = strtoupper($name('national_id', 20));
    if ($c['national_id'] !== '' && !preg_match('/^[A-Z0-9][A-Z0-9\/-]{4,19}$/', $c['national_id'])) {
        $errors['national_id'] = 'Use 5 to 20 letters or numbers (ID, passport or birth certificate number).';
    }
    if ($c['national_id'] === '') {
        $c['national_id'] = null;
    }

    $c['phone'] = $name('phone', 20);
    if ($c['phone'] !== '') {
        $p = normalize_phone($c['phone']);
        if ($p === null) {
            $errors['phone'] = 'Enter a Kenyan mobile number such as 0712 345 678.';
        } else {
            $c['phone'] = $p;
        }
    } else {
        $c['phone'] = null;
    }

    $c['residence'] = $name('residence', 100) ?: null;
    $c['allergies'] = $name('allergies', 255) ?: null;

    $c['next_of_kin_name'] = $name('next_of_kin_name', 100);
    $c['next_of_kin_phone'] = $name('next_of_kin_phone', 20);
    $c['next_of_kin_relation'] = $name('next_of_kin_relation', 40);
    if ($c['next_of_kin_phone'] !== '') {
        $p = normalize_phone($c['next_of_kin_phone']);
        if ($p === null) {
            $errors['next_of_kin_phone'] = 'Enter a Kenyan mobile number such as 0712 345 678.';
        } else {
            $c['next_of_kin_phone'] = $p;
        }
    }
    if (($c['next_of_kin_phone'] !== '' || $c['next_of_kin_relation'] !== '') && $c['next_of_kin_name'] === '') {
        $errors['next_of_kin_name'] = "Enter the next of kin's name.";
    }
    foreach (['next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relation'] as $k) {
        $c[$k] = $c[$k] !== '' ? $c[$k] : null;
    }

    return [$c, $errors];
}

/**
 * Look for patients who may already be registered.
 * 'national_id' is an exact match (blocks registration); 'similar' lists
 * patients with the same name and date of birth, or the same phone and first name.
 */
function find_patient_duplicates(array $c, ?int $excludeId = null): array
{
    $exclude = $excludeId ?? 0;
    $out = ['national_id' => null, 'similar' => []];
    if (!empty($c['national_id'])) {
        $out['national_id'] = db_one(
            'SELECT id, op_number, first_name, middle_name, last_name FROM patients WHERE national_id = ? AND id <> ?',
            [$c['national_id'], $exclude]
        );
    }
    $out['similar'] = db_all(
        'SELECT id, op_number, first_name, middle_name, last_name, date_of_birth, phone FROM patients
          WHERE id <> ? AND (
                (first_name = ? AND last_name = ? AND date_of_birth = ?)
             OR (? IS NOT NULL AND phone = ? AND first_name = ?))
          ORDER BY id LIMIT 5',
        [$exclude, $c['first_name'], $c['last_name'], $c['date_of_birth'], $c['phone'], $c['phone'], $c['first_name']]
    );
    return $out;
}

/**
 * Register a patient. Returns ['ok' => true, 'id' => n, 'op_number' => ...] or
 * ['ok' => false, 'errors' => [...], 'duplicates' => [...], 'needs_confirmation' => bool].
 * Set $confirmedNotDuplicate when staff have looked at the similar records and still want to register.
 */
function register_patient(array $input, int $userId, bool $confirmedNotDuplicate = false): array
{
    [$c, $errors] = validate_patient_input($input);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'duplicates' => [], 'needs_confirmation' => false];
    }
    $dups = find_patient_duplicates($c);
    if ($dups['national_id']) {
        $errors['national_id'] = 'A patient with this ID number is already registered (' . $dups['national_id']['op_number'] . ').';
        return ['ok' => false, 'errors' => $errors, 'duplicates' => $dups, 'needs_confirmation' => false];
    }
    if ($dups['similar'] && !$confirmedNotDuplicate) {
        return ['ok' => false, 'errors' => [], 'duplicates' => $dups, 'needs_confirmation' => true];
    }

    $result = db_tx(static function () use ($c, $userId): array {
        $op = next_op_number();
        $id = db_insert(
            'INSERT INTO patients (op_number, first_name, middle_name, last_name, sex, date_of_birth, dob_estimated,
                                   national_id, phone, residence, allergies, next_of_kin_name, next_of_kin_phone,
                                   next_of_kin_relation, registered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$op, $c['first_name'], $c['middle_name'] ?: null, $c['last_name'], $c['sex'], $c['date_of_birth'], $c['dob_estimated'],
             $c['national_id'], $c['phone'], $c['residence'], $c['allergies'], $c['next_of_kin_name'], $c['next_of_kin_phone'],
             $c['next_of_kin_relation'], $userId]
        );
        audit('create', 'patient', $id, 'Registered ' . $op, $userId);
        return ['ok' => true, 'id' => $id, 'op_number' => $op];
    });
    return $result;
}

function get_patient(int $id): ?array
{
    return db_one('SELECT p.*, u.full_name AS registered_by_name FROM patients p JOIN users u ON u.id = p.registered_by WHERE p.id = ?', [$id]);
}

/**
 * Correct a patient's details. The outpatient number never changes.
 * Returns ['ok' => bool, 'errors' => [...]].
 */
function update_patient(int $id, array $input, int $userId): array
{
    $old = get_patient($id);
    if (!$old) {
        return ['ok' => false, 'errors' => ['first_name' => 'Patient not found.']];
    }
    [$c, $errors] = validate_patient_input($input);
    if (!$errors) {
        $dups = find_patient_duplicates($c, $id);
        if ($dups['national_id']) {
            $errors['national_id'] = 'Another patient with this ID number is already registered (' . $dups['national_id']['op_number'] . ').';
        }
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $changed = [];
    foreach (PATIENT_FIELDS as $f) {
        $new = $f === 'middle_name' ? ($c[$f] ?: null) : $c[$f];
        if ((string) ($old[$f] ?? '') !== (string) ($new ?? '')) {
            $changed[] = $f;
        }
    }
    db_exec(
        'UPDATE patients SET first_name = ?, middle_name = ?, last_name = ?, sex = ?, date_of_birth = ?, dob_estimated = ?,
                national_id = ?, phone = ?, residence = ?, allergies = ?, next_of_kin_name = ?, next_of_kin_phone = ?,
                next_of_kin_relation = ? WHERE id = ?',
        [$c['first_name'], $c['middle_name'] ?: null, $c['last_name'], $c['sex'], $c['date_of_birth'], $c['dob_estimated'],
         $c['national_id'], $c['phone'], $c['residence'], $c['allergies'], $c['next_of_kin_name'], $c['next_of_kin_phone'],
         $c['next_of_kin_relation'], $id]
    );
    audit('update', 'patient', $id, $changed ? 'Fields changed: ' . implode(', ', $changed) : 'Saved with no changes', $userId);
    return ['ok' => true, 'errors' => []];
}

/**
 * Build the WHERE clause for a patient search. Understands outpatient numbers
 * (OP26-00001), ID or phone numbers, and one or more name parts.
 */
function patient_search_clause(string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return ['1 = 1', []];
    }
    if (preg_match('/^OP\d{2}-\d{1,5}$/i', $q)) {
        [$prefix, $num] = explode('-', strtoupper($q));
        return ['p.op_number = ?', [$prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT)]];
    }
    if (preg_match('/^[+\d][\d\s\-]{4,}$/', $q)) {
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $phone = normalize_phone($q);
        $like = '%' . like_escape($digits) . '%';
        if ($phone !== null) {
            return ['(p.phone = ? OR p.national_id = ? OR p.next_of_kin_phone = ?)', [$phone, $digits, $phone]];
        }
        return ['(p.national_id LIKE ? OR p.phone LIKE ?)', [$like, $like]];
    }
    $clauses = [];
    $params = [];
    foreach (preg_split('/\s+/', $q) ?: [] as $token) {
        if ($token === '') {
            continue;
        }
        $like = like_escape($token) . '%';
        $clauses[] = '(p.first_name LIKE ? OR p.middle_name LIKE ? OR p.last_name LIKE ? OR p.national_id LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    return [$clauses ? implode(' AND ', $clauses) : '1 = 1', $params];
}

/** Returns [rows, total]. */
function search_patients(string $q, int $limit = 25, int $offset = 0): array
{
    [$where, $params] = patient_search_clause($q);
    $total = (int) db_value("SELECT COUNT(*) FROM patients p WHERE $where", $params);
    $rows = db_all(
        "SELECT p.id, p.op_number, p.first_name, p.middle_name, p.last_name, p.sex, p.date_of_birth, p.phone, p.national_id
           FROM patients p WHERE $where ORDER BY p.last_name, p.first_name, p.id LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return [$rows, $total];
}

/* ---------- Shared form fields (register and edit pages) ---------- */

/** The patient details form fields, refilled from $f['values'] and showing $f['errors']. */
function patient_form_fields(array $f): string
{
    $o = static fn (array $extra = []) => $extra;
    $out = '<fieldset><legend>Personal details</legend><div class="form-grid">';
    $out .= field($f, 'first_name', 'First name', ['required' => true, 'maxlength' => 50, 'autocomplete' => 'off']);
    $out .= field($f, 'middle_name', 'Middle name', ['maxlength' => 50, 'autocomplete' => 'off']);
    $out .= field($f, 'last_name', 'Last name', ['required' => true, 'maxlength' => 50, 'autocomplete' => 'off']);
    $out .= select_field($f, 'sex', 'Sex', ['Female' => 'Female', 'Male' => 'Male'], ['required' => true, 'empty' => 'Choose']);
    $out .= field($f, 'date_of_birth', 'Date of birth', ['type' => 'date', 'required' => true, 'max' => app_today()]);
    $checked = !empty($f['values']['dob_estimated']) ? ' checked' : '';
    $out .= '<div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="dob_estimated" value="1"' . $checked . '> Date of birth is an estimate</label></div>';
    $out .= field($f, 'national_id', 'ID, passport or birth certificate number', ['maxlength' => 20, 'autocomplete' => 'off', 'hint' => 'Leave blank for a child without one.']);
    $out .= field($f, 'phone', 'Mobile phone', ['maxlength' => 20, 'inputmode' => 'tel', 'placeholder' => '0712 345 678']);
    $out .= field($f, 'residence', 'Residence (estate or town)', ['maxlength' => 100, 'class' => 'wide']);
    $out .= field($f, 'allergies', 'Known allergies', ['maxlength' => 255, 'class' => 'wide', 'hint' => 'For example: penicillin, sulfa drugs. Doctors see this when prescribing.']);
    $out .= '</div></fieldset>';
    $out .= '<fieldset><legend>Next of kin</legend><div class="form-grid">';
    $out .= field($f, 'next_of_kin_name', 'Name', ['maxlength' => 100]);
    $out .= field($f, 'next_of_kin_relation', 'Relationship', ['maxlength' => 40, 'placeholder' => 'Mother, spouse, brother...']);
    $out .= field($f, 'next_of_kin_phone', 'Mobile phone', ['maxlength' => 20, 'inputmode' => 'tel']);
    $out .= '</div></fieldset>';
    return $out;
}

/* ---------- CSV import (data migration from paper registers) ---------- */

const PATIENT_IMPORT_COLUMNS = [
    'first_name', 'middle_name', 'last_name', 'sex', 'date_of_birth', 'national_id', 'phone', 'residence',
    'allergies', 'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relation',
];

/**
 * Read and check a patient CSV file. Nothing is saved. Returns
 * ['rows' => [[line, status, message, data]], 'valid' => n, 'error' => string|null].
 */
function preview_patient_import(string $path): array
{
    if (!is_file($path) || filesize($path) > 1048576) {
        return ['rows' => [], 'valid' => 0, 'error' => 'The file is missing or larger than 1 MB.'];
    }
    $h = fopen($path, 'rb');
    if ($h === false) {
        return ['rows' => [], 'valid' => 0, 'error' => 'The file could not be read.'];
    }
    $header = fgetcsv($h, 2000, ',', '"', '');
    if (!$header) {
        fclose($h);
        return ['rows' => [], 'valid' => 0, 'error' => 'The file is empty.'];
    }
    $header = array_map(static fn ($x) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $x) ?? '')), $header);
    $missing = array_diff(['first_name', 'last_name', 'sex', 'date_of_birth'], $header);
    if ($missing) {
        fclose($h);
        return ['rows' => [], 'valid' => 0, 'error' => 'Missing required column(s): ' . implode(', ', $missing) . '.'];
    }

    $rows = [];
    $valid = 0;
    $line = 1;
    $seenIds = [];
    while (($rec = fgetcsv($h, 4000, ',', '"', '')) !== false) {
        $line++;
        if ($rec === [null] || count(array_filter($rec, static fn ($x) => trim((string) $x) !== '')) === 0) {
            continue;
        }
        if ($line > 501) {
            $rows[] = ['line' => $line, 'status' => 'Skipped', 'message' => 'More than 500 rows: split the file.', 'data' => []];
            break;
        }
        $in = [];
        foreach ($header as $i => $col) {
            $in[$col] = (string) ($rec[$i] ?? '');
        }
        // Accept dd/mm/yyyy as well as yyyy-mm-dd.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $in['date_of_birth'] ?? '', $m)) {
            $in['date_of_birth'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        $in['sex'] = ucfirst(strtolower(trim($in['sex'] ?? '')));
        [$c, $errors] = validate_patient_input($in);
        if ($errors) {
            $rows[] = ['line' => $line, 'status' => 'Error', 'message' => implode(' ', $errors), 'data' => $in];
            continue;
        }
        $dups = find_patient_duplicates($c);
        if ($dups['national_id'] || ($c['national_id'] && isset($seenIds[$c['national_id']]))) {
            $rows[] = ['line' => $line, 'status' => 'Duplicate', 'message' => 'ID number is already registered.', 'data' => $in];
            continue;
        }
        if ($dups['similar']) {
            $rows[] = ['line' => $line, 'status' => 'Duplicate', 'message' => 'Same name and date of birth as ' . $dups['similar'][0]['op_number'] . '.', 'data' => $in];
            continue;
        }
        if ($c['national_id']) {
            $seenIds[$c['national_id']] = true;
        }
        $rows[] = ['line' => $line, 'status' => 'Valid', 'message' => '', 'data' => $in];
        $valid++;
    }
    fclose($h);
    return ['rows' => $rows, 'valid' => $valid, 'error' => null];
}

/** Save every valid row of a previewed file. Returns the number of patients registered. */
function commit_patient_import(string $path, int $userId): int
{
    $preview = preview_patient_import($path);
    if ($preview['error']) {
        return 0;
    }
    $count = 0;
    db_tx(static function () use ($preview, $userId, &$count): void {
        foreach ($preview['rows'] as $row) {
            if ($row['status'] !== 'Valid') {
                continue;
            }
            $r = register_patient($row['data'], $userId, true);
            if ($r['ok']) {
                $count++;
            }
        }
        audit('import', 'patient', null, "Imported $count patients from CSV", $userId);
    });
    return $count;
}
