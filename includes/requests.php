<?php
/**
 * Public requests: staff access requests (the Request access page) and contact messages (the Contact page).
 *
 * Nothing sent from a public page can create an account or change clinical data. An access request is only a
 * note for the System Administrator, who checks the person and creates the account by hand. The forms are
 * protected by the CSRF token, a hidden "trap" field that people never fill in, and a limit on how many
 * requests one computer may send in an hour.
 */
declare(strict_types=1);

/** Departments offered on the access request form. */
function request_departments(): array
{
    return [
        'Reception and Records', 'Outpatient clinic', 'Nursing and triage', 'Laboratory', 'Pharmacy',
        'Billing and accounts', 'Hospital management', 'ICT and administration',
    ];
}

/** Roles a person may ask for. The System Administrator role cannot be requested from a public page. */
function requestable_roles(): array
{
    $roles = role_labels();
    unset($roles['admin']);
    return $roles;
}

/** Topics offered on the contact form. */
function contact_topics(): array
{
    return [
        'General question', 'Technical support', 'Account or password help',
        'Feedback or complaint', 'Data protection request',
    ];
}

function public_request_statuses(): array
{
    return ['new' => 'New', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined'];
}

/** Basic address check: one address, no line breaks, a sensible length. */
function valid_email_address(string $email): bool
{
    return $email !== '' && mb_strlen($email) <= 160 && strpos($email, "\n") === false
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Shared checks for name, email and phone. Returns [clean, errors]. */
function validate_contact_person(array $in): array
{
    $errors = [];
    $clean = [
        'full_name' => clean_text($in['full_name'] ?? '', 120),
        'email'     => strtolower(clean_text($in['email'] ?? '', 160)),
        'phone'     => clean_text($in['phone'] ?? '', 20),
    ];
    if (mb_strlen($clean['full_name']) < 3 || !preg_match('/\p{L}/u', $clean['full_name'])) {
        $errors['full_name'] = 'Enter your full name.';
    }
    if (!valid_email_address($clean['email'])) {
        $errors['email'] = 'Enter a valid email address, such as name@example.com.';
    }
    if ($clean['phone'] !== '') {
        $phone = normalize_phone($clean['phone']);
        if ($phone === null) {
            $errors['phone'] = 'Enter a Kenyan mobile number such as 0712 345 678, or leave this blank.';
        } else {
            $clean['phone'] = $phone;
        }
    }
    return [$clean, $errors];
}

/** Validate the staff access form. Returns [clean, errors]. */
function validate_access_request(array $in): array
{
    [$clean, $errors] = validate_contact_person($in);
    $clean += [
        'department'     => clean_text($in['department'] ?? '', 80),
        'requested_role' => clean_text($in['requested_role'] ?? '', 30),
        'staff_number'   => clean_text($in['staff_number'] ?? '', 40),
        'supervisor'     => clean_text($in['supervisor'] ?? '', 120),
        'message'        => clean_multiline($in['message'] ?? '', 500),
        'consent'        => ($in['consent'] ?? '') === '1' ? '1' : '',
    ];
    if (!in_array($clean['department'], request_departments(), true)) {
        $errors['department'] = 'Choose your department.';
    }
    if (!array_key_exists($clean['requested_role'], requestable_roles())) {
        $errors['requested_role'] = 'Choose the role you need.';
    }
    if ($clean['staff_number'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \/._-]{0,39}$/', $clean['staff_number'])) {
        $errors['staff_number'] = 'Use letters, numbers, spaces, slashes, dots or dashes only.';
    }
    if (mb_strlen($clean['supervisor']) < 3 || !preg_match('/\p{L}/u', $clean['supervisor'])) {
        $errors['supervisor'] = 'Enter the name of your supervisor or head of department.';
    }
    if ($clean['consent'] !== '1') {
        $errors['consent'] = 'Please confirm that you have read the privacy policy and the terms of use.';
    }
    if (!isset($errors['email']) && db_value("SELECT id FROM public_requests WHERE kind = 'access' AND email = ? AND status IN ('new', 'in_progress') LIMIT 1", [$clean['email']]) !== null) {
        $errors['email'] = 'A request for this email address is already waiting for the administrator.';
    }
    return [$clean, $errors];
}

/** Validate the contact form. Returns [clean, errors]. */
function validate_contact_message(array $in): array
{
    [$clean, $errors] = validate_contact_person($in);
    $clean += [
        'topic'   => clean_text($in['topic'] ?? '', 40),
        'message' => clean_multiline($in['message'] ?? '', 2000),
        'consent' => ($in['consent'] ?? '') === '1' ? '1' : '',
    ];
    if (!in_array($clean['topic'], contact_topics(), true)) {
        $errors['topic'] = 'Choose what your message is about.';
    }
    if (mb_strlen($clean['message']) < 10) {
        $errors['message'] = 'Write your message (at least 10 characters).';
    }
    if ($clean['consent'] !== '1') {
        $errors['consent'] = 'Please confirm that your message contains no patient information.';
    }
    return [$clean, $errors];
}

/** True when this computer has already sent as many requests of this kind as the hourly limit allows. */
function public_request_limit_reached(string $kind, ?string $ip = null): bool
{
    $limit = (int) config('site.form_limit', 5);
    $count = (int) db_value(
        'SELECT COUNT(*) FROM public_requests WHERE kind = ? AND ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
        [$kind, $ip ?? client_ip()]
    );
    return $count >= $limit;
}

/** Store a validated request. Returns [id, reference]. */
function create_public_request(string $kind, array $clean, ?string $ip = null): array
{
    $prefix = $kind === 'access' ? 'AR' : 'MSG';
    return db_tx(function () use ($kind, $clean, $ip, $prefix): array {
        $id = db_insert(
            'INSERT INTO public_requests (kind, full_name, email, phone, department, requested_role, staff_number, supervisor, topic, message, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $kind, $clean['full_name'], $clean['email'], ($clean['phone'] ?? '') !== '' ? $clean['phone'] : null,
                ($clean['department'] ?? '') !== '' ? $clean['department'] : null,
                ($clean['requested_role'] ?? '') !== '' ? $clean['requested_role'] : null,
                ($clean['staff_number'] ?? '') !== '' ? $clean['staff_number'] : null,
                ($clean['supervisor'] ?? '') !== '' ? $clean['supervisor'] : null,
                ($clean['topic'] ?? '') !== '' ? $clean['topic'] : null,
                ($clean['message'] ?? '') !== '' ? $clean['message'] : null,
                $ip ?? client_ip(),
            ]
        );
        $reference = sprintf('%s-%s-%06d', $prefix, app_now()->format('Y'), $id);
        db_exec('UPDATE public_requests SET reference = ? WHERE id = ?', [$reference, $id]);
        // The audit trail holds identifiers only, never the text of the message.
        audit('submit', 'public_request', $id, ($kind === 'access' ? 'Staff access request ' : 'Contact message ') . $reference, null, $ip);
        return [$id, $reference];
    });
}

function get_public_request(int $id): ?array
{
    return db_one(
        'SELECT r.*, u.full_name AS handler_name FROM public_requests r LEFT JOIN users u ON u.id = r.handled_by WHERE r.id = ?',
        [$id]
    );
}

/** List requests, newest first. Filters: kind, status, q (name, email or reference). */
function list_public_requests(array $f = [], int $limit = 200): array
{
    $sql = 'SELECT r.*, u.full_name AS handler_name FROM public_requests r LEFT JOIN users u ON u.id = r.handled_by WHERE 1 = 1';
    $params = [];
    if (in_array($f['kind'] ?? '', ['access', 'contact'], true)) {
        $sql .= ' AND r.kind = ?';
        $params[] = $f['kind'];
    }
    if (array_key_exists($f['status'] ?? '', public_request_statuses())) {
        $sql .= ' AND r.status = ?';
        $params[] = $f['status'];
    }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . like_escape((string) $f['q']) . '%';
        $sql .= ' AND (r.full_name LIKE ? OR r.email LIKE ? OR r.reference LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    return db_all($sql . ' ORDER BY r.id DESC LIMIT ' . (int) $limit, $params);
}

function count_new_public_requests(): int
{
    return (int) db_value("SELECT COUNT(*) FROM public_requests WHERE status = 'new'");
}

/** Record what the administrator did with a request. Returns an error message or null. */
function set_public_request_status(int $id, string $status, string $note, int $actorId): ?string
{
    if (!array_key_exists($status, public_request_statuses())) {
        return 'Choose a status.';
    }
    $row = get_public_request($id);
    if (!$row) {
        return 'That request does not exist.';
    }
    $note = clean_text($note, 255);
    db_exec(
        'UPDATE public_requests SET status = ?, admin_note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?',
        [$status, $note !== '' ? $note : null, $actorId, $id]
    );
    audit('update', 'public_request', $id, $row['reference'] . ' marked ' . $status, $actorId);
    return null;
}

/** A first suggestion for a username from a full name, for the administrator to accept or change. */
function suggest_username(string $fullName): string
{
    $parts = preg_split('/\s+/u', mb_strtolower(trim($fullName))) ?: [];
    $parts = array_values(array_filter(array_map(static fn ($p) => preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $p) ?: $p) ?? '', $parts)));
    if (count($parts) === 0) {
        return '';
    }
    $name = count($parts) === 1 ? $parts[0] : $parts[0][0] . '.' . $parts[count($parts) - 1];
    $name = substr($name, 0, 30);
    $base = $name;
    for ($i = 2; db_value('SELECT id FROM users WHERE username = ?', [$name]) !== null && $i < 100; $i++) {
        $name = $base . $i;
    }
    return $name;
}
