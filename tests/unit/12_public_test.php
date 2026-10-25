<?php
declare(strict_types=1);

suite('Public website: access requests, contact messages and pages');

/** A complete, valid access request; pass overrides to change one thing. */
function valid_access_input(array $o = []): array
{
    return $o + [
        'full_name' => 'Wanjiru Kamau', 'email' => 'Wanjiru.Kamau@Example.com', 'phone' => '0712 345 678',
        'department' => 'Nursing and triage', 'requested_role' => 'nurse', 'staff_number' => 'UB/N/0457',
        'supervisor' => 'Sister Achieng Odhiambo', 'message' => 'Starting on 3 November.', 'consent' => '1',
    ];
}

function valid_contact_input(array $o = []): array
{
    return $o + [
        'full_name' => 'Peter Mwangi', 'email' => 'peter@example.com', 'phone' => '', 'topic' => 'General question',
        'message' => 'Which browsers does the system support?', 'consent' => '1',
    ];
}

test('UT-PUB-001', 'a complete access request is accepted and cleaned', function () {
    [$clean, $errors] = validate_access_request(valid_access_input());
    assert_count(0, $errors);
    assert_same('wanjiru.kamau@example.com', $clean['email'], 'email stored in lower case');
    assert_same('+254712345678', $clean['phone'], 'phone normalised');
    assert_same('nurse', $clean['requested_role']);
});

test('UT-PUB-002', 'an access request is refused when something is missing or wrong', function () {
    [, $e] = validate_access_request(['full_name' => 'Al', 'email' => 'not-an-email', 'phone' => '123', 'department' => 'Casino', 'requested_role' => 'wizard', 'staff_number' => '<script>', 'supervisor' => '', 'consent' => '']);
    foreach (['full_name', 'email', 'phone', 'department', 'requested_role', 'staff_number', 'supervisor', 'consent'] as $k) {
        assert_has_key($k, $e, "$k is checked");
    }
});

test('UT-PUB-003', 'nobody can ask for the administrator role from a public page', function () {
    assert_false(array_key_exists('admin', requestable_roles()));
    [, $e] = validate_access_request(valid_access_input(['requested_role' => 'admin']));
    assert_has_key('requested_role', $e);
    assert_count(7, requestable_roles());
});

test('UT-PUB-004', 'an email address with a line break (header injection) is refused', function () {
    [, $e] = validate_access_request(valid_access_input(['email' => "a@example.com\nBcc: x@evil.example"]));
    assert_has_key('email', $e);
    assert_false(valid_email_address("a@example.com\r\nBcc: x@evil.example"));
    assert_true(valid_email_address('name@example.com'));
});

test('UT-PUB-005', 'a stored access request has a reference, starts as new and creates no account', function () {
    $admins = make_user('admin');
    $usersBefore = (int) db_value('SELECT COUNT(*) FROM users');
    [$clean] = validate_access_request(valid_access_input());
    [$id, $ref] = create_public_request('access', $clean, '10.0.0.7');
    $row = get_public_request($id);
    assert_same($ref, $row['reference']);
    assert_true((bool) preg_match('/^AR-\d{4}-\d{6}$/', $ref), 'reference format');
    assert_same('new', $row['status']);
    assert_same('access', $row['kind']);
    assert_same('10.0.0.7', $row['ip_address']);
    assert_eq($usersBefore, (int) db_value('SELECT COUNT(*) FROM users'), 'no account is created by a request');
    assert_not_null($admins);
});

test('UT-PUB-006', 'submitting a request is audited by reference, never with the message text', function () {
    [$clean] = validate_access_request(valid_access_input(['message' => 'MY-PRIVATE-REASON-TEXT']));
    [$id, $ref] = create_public_request('access', $clean, '10.0.0.8');
    $row = db_one("SELECT * FROM audit_log WHERE entity = 'public_request' AND entity_id = ?", [$id]);
    assert_not_null($row, 'an audit entry exists');
    assert_same('submit', $row['action']);
    assert_contains($ref, (string) $row['details']);
    assert_not_contains('MY-PRIVATE-REASON-TEXT', (string) $row['details']);
    assert_same('10.0.0.8', $row['ip_address']);
});

test('UT-PUB-007', 'one waiting request per email address; a new one is allowed after the first is dealt with', function () {
    $admin = make_user('admin');
    [$clean] = validate_access_request(valid_access_input());
    [$id] = create_public_request('access', $clean, '10.0.0.9');
    [, $e] = validate_access_request(valid_access_input(['email' => 'WANJIRU.KAMAU@example.com']));
    assert_has_key('email', $e, 'a second waiting request is refused');
    assert_null(set_public_request_status($id, 'done', 'Account created', $admin));
    [, $e2] = validate_access_request(valid_access_input());
    assert_false(isset($e2['email']), 'allowed again once the first is done');
});

test('UT-PUB-008', 'one computer can send only a few requests an hour', function () {
    assert_eq(5, (int) config('site.form_limit'));
    for ($i = 1; $i <= 5; $i++) {
        assert_false(public_request_limit_reached('contact', '10.1.1.1'), "request $i is still allowed");
        [$c] = validate_contact_message(valid_contact_input(['email' => "p$i@example.com"]));
        create_public_request('contact', $c, '10.1.1.1');
    }
    assert_true(public_request_limit_reached('contact', '10.1.1.1'), 'the sixth is refused');
    assert_false(public_request_limit_reached('contact', '10.1.1.2'), 'another computer is not affected');
    assert_false(public_request_limit_reached('access', '10.1.1.1'), 'access requests are counted separately');
    db_exec("UPDATE public_requests SET created_at = NOW() - INTERVAL 2 HOUR WHERE ip_address = '10.1.1.1'");
    assert_false(public_request_limit_reached('contact', '10.1.1.1'), 'requests older than an hour are forgotten');
});

test('UT-PUB-009', 'a contact message needs a topic, a real message and the confirmation', function () {
    assert_count(0, validate_contact_message(valid_contact_input())[1]);
    [, $e] = validate_contact_message(valid_contact_input(['topic' => 'Spam', 'message' => 'hi', 'consent' => '', 'email' => 'x']));
    foreach (['topic', 'message', 'consent', 'email'] as $k) {
        assert_has_key($k, $e);
    }
    [$clean] = validate_contact_message(valid_contact_input(['message' => str_repeat('a', 5000)]));
    assert_eq(2000, mb_strlen($clean['message']), 'long messages are cut to 2000 characters');
});

test('UT-PUB-010', 'only the administrator may handle requests, and every change is audited', function () {
    assert_true(role_can('admin', 'requests.manage'));
    foreach (['manager', 'receptionist', 'nurse', 'doctor', 'lab', 'pharmacist', 'cashier'] as $role) {
        assert_false(role_can($role, 'requests.manage'), "$role cannot manage requests");
    }
    $admin = make_user('admin');
    [$clean] = validate_contact_message(valid_contact_input());
    [$id, $ref] = create_public_request('contact', $clean, '10.2.2.2');
    assert_not_null(set_public_request_status($id, 'wizard', '', $admin), 'unknown status refused');
    assert_not_null(set_public_request_status(999999, 'done', '', $admin), 'unknown request refused');
    assert_null(set_public_request_status($id, 'in_progress', 'Replied by phone', $admin));
    $row = get_public_request($id);
    assert_same('in_progress', $row['status']);
    assert_same('Replied by phone', $row['admin_note']);
    assert_eq($admin, (int) $row['handled_by']);
    $a = db_one("SELECT * FROM audit_log WHERE entity = 'public_request' AND entity_id = ? AND action = 'update'", [$id]);
    assert_not_null($a);
    assert_contains($ref, (string) $a['details']);
});

test('UT-PUB-011', 'requests can be listed and searched by type, status and text', function () {
    [$a] = validate_access_request(valid_access_input());
    create_public_request('access', $a, '10.3.3.1');
    [$c] = validate_contact_message(valid_contact_input());
    create_public_request('contact', $c, '10.3.3.2');
    assert_count(2, list_public_requests());
    assert_count(1, list_public_requests(['kind' => 'access']));
    assert_count(1, list_public_requests(['q' => 'peter@example']));
    assert_count(0, list_public_requests(['status' => 'done']));
    assert_count(2, list_public_requests(['kind' => 'nonsense', 'status' => 'nonsense']), 'unknown filters are ignored, not trusted');
    assert_eq(2, count_new_public_requests());
    assert_count(0, list_public_requests(['q' => '%']), 'a percent sign is searched for, not used as a wildcard');
});

test('UT-PUB-012', 'a suggested username comes from the name and avoids names already taken', function () {
    assert_same('j.wairimu', suggest_username('Jane Wairimu'));
    make_user('nurse', 'j.wairimu');
    assert_same('j.wairimu2', suggest_username('Jane Wairimu'));
    assert_same('amani', suggest_username('Amani'));
    assert_same('m.otieno', suggest_username('  Mary   Akinyi  Otieno '));
    assert_same('', suggest_username('  '));
});

test('UT-PUB-013', 'the opening hours sentence follows the clinic settings', function () {
    assert_same('Monday to Saturday, 8:00 am to 5:00 pm', site_hours());
    $c = site_contact();
    assert_same('+254200000000', $c['tel']);
    assert_true($c['placeholder'], 'the example contact details are flagged as placeholders');
});

test('UT-PUB-014', 'every link in the header and footer leads to a page that exists', function () {
    $html = site_header('home') . site_footer();
    preg_match_all('/href="([^"]+)"/', $html, $m);
    $seen = 0;
    foreach (array_unique($m[1]) as $href) {
        $href = html_entity_decode($href);
        if ($href === '' || $href[0] === '#' || preg_match('#^(https?:|mailto:|tel:)#', $href)) {
            continue;
        }
        $path = ltrim((string) parse_url($href, PHP_URL_PATH), '/');
        assert_true(is_file(APP_ROOT . '/' . $path), "$href points to a file that exists");
        $seen++;
    }
    assert_true($seen >= 12, 'the header and footer link to at least 12 pages');
});

test('UT-PUB-015', 'the header marks the current page and offers sign-in and request access', function () {
    $h = site_header('services');
    assert_contains('aria-current="page"', $h);
    assert_eq(1, substr_count($h, 'aria-current="page"'), 'exactly one current page');
    assert_contains('login.php', $h);
    assert_contains('register.php', $h);
    assert_contains('Skip to content', (function () { ob_start(); site_start('Test', 'home'); $o = ob_get_clean(); return $o; })());
});

test('UT-PUB-016', 'the legal pages, the photo credits and the icons used on the public pages all exist', function () {
    foreach (site_legal_pages() as [$file]) {
        assert_true(is_file(APP_ROOT . '/' . $file), "$file exists");
    }
    foreach (['welcome.php', 'services.php', 'about.php', 'contact.php', 'register.php'] as $page) {
        assert_true(is_file(APP_ROOT . '/' . $page), "$page exists");
    }
    assert_true(count(photo_credits()) >= 10);
    foreach (photo_credits() as [$who, $id]) {
        assert_true($who !== '' && $id > 0);
    }
    foreach (['phone', 'mail', 'clock', 'map-pin', 'send', 'lock', 'badge-check', 'accessibility', 'cookie', 'file-text', 'building'] as $ic) {
        assert_true(icon($ic) !== '', "icon $ic is defined");
    }
    assert_same('', icon('no-such-icon'));
});

test('UT-PUB-017', 'every image the public pages use is present in the assets folder', function () {
    $missing = [];
    foreach (['welcome.php', 'services.php', 'about.php', 'contact.php', 'register.php', 'includes/site.php', 'assets/css/site.css'] as $file) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $file);
        preg_match_all('#img/[A-Za-z0-9_./-]+\.(?:webp|jpg|png|svg)#', $src, $m);
        foreach (array_unique($m[0]) as $img) {
            if (!is_file(APP_ROOT . '/assets/' . $img)) {
                $missing[] = $img;
            }
        }
    }
    assert_same([], $missing, 'missing images');
});
