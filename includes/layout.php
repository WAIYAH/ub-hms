<?php
/**
 * Page layout: header, sidebar menu, flash messages, footer and error pages.
 * The menu is built from the signed-in user's permissions, so staff only see
 * the functions their role allows.
 */
declare(strict_types=1);

/** Sidebar groups: [group title, [[label, path, permission, key], ...]]. */
function nav_items(): array
{
    return [
        ['Patients', [
            ['Search patients',  'patients/index.php',    'patients.view',     'patients'],
            ['Register patient', 'patients/register.php', 'patients.register', 'register'],
            ['Import patients',  'patients/import.php',   'patients.import',   'import'],
        ]],
        ['Appointments', [
            ['Appointment diary', 'appointments/index.php', 'appointments.view', 'appointments'],
            ['Book appointment',  'appointments/book.php',  'appointments.book', 'book'],
            ['Check-in',          'visits/checkin.php',     'visits.checkin',    'checkin'],
        ]],
        ['Clinic', [
            ['Triage queue',       'visits/queue.php?stage=triage',  'triage.record',  'triage'],
            ['Consultation queue', 'visits/queue.php?stage=consult', 'consult.record', 'consult'],
            ['All open visits',    'visits/queue.php',               'visits.view',    'visits'],
        ]],
        ['Laboratory', [
            ['Laboratory worklist', 'lab/index.php',   'lab.worklist', 'lab'],
            ['My test results',     'lab/results.php', 'lab.request',  'labresults'],
        ]],
        ['Pharmacy', [
            ['Prescriptions',  'pharmacy/index.php',     'pharmacy.dispense', 'pharmacy'],
            ['Medicine stock', 'pharmacy/stock.php',     'pharmacy.stock',    'stock'],
            ['Batches',        'pharmacy/batches.php',   'pharmacy.stock',    'batches'],
            ['Receive stock',  'pharmacy/receive.php',   'pharmacy.manage',   'receive'],
            ['Medicine list',  'pharmacy/medicines.php', 'pharmacy.manage',   'medicines'],
        ]],
        ['Billing', [
            ['Invoices', 'billing/index.php', 'billing.view', 'billing'],
        ]],
        ['Reports', [
            ['Attendance',         'reports/attendance.php', 'reports.attendance', 'r-attendance'],
            ['Revenue',            'reports/revenue.php',    'reports.revenue',    'r-revenue'],
            ['Stock',              'reports/stock.php',      'reports.stock',      'r-stock'],
            ['Laboratory workload', 'reports/lab.php',       'reports.lab',        'r-lab'],
        ]],
        ['Administration', [
            ['User accounts', 'admin/users.php',   'users.manage',    'users'],
            ['Price list',    'admin/prices.php',  'settings.manage', 'prices'],
            ['Audit trail',   'admin/audit.php',   'audit.view',      'audit'],
            ['System health', 'admin/health.php',  'system.health',   'health'],
        ]],
    ];
}

/**
 * Start a page. $active is the nav key to highlight. Call page_end() after the content.
 */
function page_start(string $title, string $active = '', array $opts = []): void
{
    $user = current_user();
    $app = config('short_name');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' | ' . e($app) . '</title>';
    echo '<link rel="icon" href="' . e(asset('img/favicon.svg')) . '" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">';
    echo '<script src="' . e(asset('js/app.js')) . '" defer></script>';
    echo '</head><body' . ($user ? '' : ' class="plain"') . '>';
    echo '<a class="skip" href="#main">Skip to content</a>';

    if ($user) {
        echo '<header class="topbar"><button type="button" class="menu-toggle" data-toggle-menu aria-label="Open menu">&#9776;</button>';
        echo '<a class="brand" href="' . e(url('index.php')) . '"><span class="brand-mark" aria-hidden="true">+</span> ' . e($app) . '</a>';
        echo '<div class="topbar-user"><span class="who"><strong>' . e($user['full_name']) . '</strong><small>' . e(role_labels()[$user['role']] ?? $user['role']) . '</small></span>';
        echo '<a class="btn btn-quiet" href="' . e(url('password.php')) . '">Password</a>';
        echo '<form method="post" action="' . e(url('logout.php')) . '" class="inline">' . csrf_field() . '<button class="btn btn-quiet" type="submit">Sign out</button></form></div></header>';
        echo '<div class="shell"><nav class="sidebar" id="sidebar" aria-label="Main menu">';
        echo '<a class="' . ($active === 'home' ? 'active' : '') . '" href="' . e(url('index.php')) . '">Dashboard</a>';
        foreach (nav_items() as [$group, $items]) {
            $visible = array_filter($items, static fn ($i) => user_can($i[2]));
            if (!$visible) {
                continue;
            }
            echo '<div class="nav-group">' . e($group) . '</div>';
            foreach ($visible as [$label, $path, , $key]) {
                echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . e(url($path)) . '">' . e($label) . '</a>';
            }
        }
        echo '</nav><main id="main" class="content" tabindex="-1">';
    } else {
        echo '<main id="main" class="content-plain" tabindex="-1">';
    }

    foreach (take_flashes() as $f) {
        $role = $f['type'] === 'error' ? 'alert' : 'status';
        echo '<div class="flash flash-' . e($f['type']) . '" role="' . $role . '">' . e($f['message']) . '</div>';
    }
    if (empty($opts['no_title'])) {
        echo '<h1 class="page-title">' . e($title) . '</h1>';
    }
}

function page_end(): void
{
    $user = current_user();
    echo '</main>';
    if ($user) {
        echo '</div>';
    }
    echo '<footer class="footer">' . e(config('hospital')) . ' &middot; ' . e(config('short_name'))
        . ' &middot; Confidential: for authorised staff only. All activity is recorded.</footer>';
    echo '</body></html>';
}

/** Stop the request with an error page and HTTP status. */
function abort(int $status, string $message): void
{
    http_response_code($status);
    $titles = [403 => 'Access denied', 404 => 'Page not found', 405 => 'Action not allowed', 419 => 'Form expired', 500 => 'Something went wrong'];
    $title = $titles[$status] ?? 'Error';
    if (headers_sent()) {
        echo '<p>' . e($message) . '</p>';
        exit;
    }
    page_start($title, '', ['no_title' => true]);
    echo '<section class="error-box"><p class="error-code">' . (int) $status . '</p><h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
    echo '<p><a class="btn" href="' . e(url('index.php')) . '">Go to the dashboard</a></p></section>';
    page_end();
    exit;
}

/* ---------- Small view helpers ---------- */

/** Open a form that posts back to the same page, with the CSRF field. */
function form_open(string $action = '', string $class = ''): string
{
    $action = $action !== '' ? url($action) : '';
    return '<form method="post"' . ($action !== '' ? ' action="' . e($action) . '"' : '') . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' novalidate>' . csrf_field();
}

/** Labelled text-like input with its error message. $f = ['values' => [], 'errors' => []]. */
function field(array $f, string $name, string $label, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $value = $f['values'][$name] ?? ($o['default'] ?? '');
    $err = $f['errors'][$name] ?? '';
    $id = 'f_' . $name;
    $attrs = '';
    foreach (['maxlength', 'min', 'max', 'step', 'placeholder', 'autocomplete', 'inputmode', 'pattern', 'list'] as $a) {
        if (isset($o[$a])) {
            $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }
    if (!empty($o['required'])) {
        $attrs .= ' required';
    }
    if (!empty($o['readonly'])) {
        $attrs .= ' readonly';
    }
    $out = '<div class="field' . ($err ? ' has-error' : '') . (isset($o['class']) ? ' ' . e($o['class']) : '') . '">';
    $out .= '<label for="' . e($id) . '">' . e($label) . (!empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>';
    if ($type === 'textarea') {
        $out .= '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 3) . '"' . $attrs
            . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '') . '>' . e($value) . '</textarea>';
    } else {
        $out .= '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . $attrs
            . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '') . '>';
    }
    if (!empty($o['hint'])) {
        $out .= '<small class="hint">' . e($o['hint']) . '</small>';
    }
    if ($err) {
        $out .= '<small class="error" id="' . e($id) . '_err">' . e($err) . '</small>';
    }
    return $out . '</div>';
}

/** Labelled <select>. $options is value => label. */
function select_field(array $f, string $name, string $label, array $options, array $o = []): string
{
    $value = (string) ($f['values'][$name] ?? ($o['default'] ?? ''));
    $err = $f['errors'][$name] ?? '';
    $id = 'f_' . $name;
    $out = '<div class="field' . ($err ? ' has-error' : '') . (isset($o['class']) ? ' ' . e($o['class']) : '') . '">';
    $out .= '<label for="' . e($id) . '">' . e($label) . (!empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>';
    $out .= '<select id="' . e($id) . '" name="' . e($name) . '"' . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '') . '>';
    if (isset($o['empty'])) {
        $out .= '<option value="">' . e($o['empty']) . '</option>';
    }
    foreach ($options as $v => $text) {
        $out .= '<option value="' . e($v) . '"' . ((string) $v === $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $out .= '</select>';
    if (!empty($o['hint'])) {
        $out .= '<small class="hint">' . e($o['hint']) . '</small>';
    }
    if ($err) {
        $out .= '<small class="error" id="' . e($id) . '_err">' . e($err) . '</small>';
    }
    return $out . '</div>';
}

/** Summary of all errors at the top of a form. */
function error_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $out = '<div class="flash flash-error" role="alert"><strong>Please correct the following:</strong><ul>';
    foreach ($errors as $name => $msg) {
        $out .= '<li><a href="#f_' . e($name) . '">' . e($msg) . '</a></li>';
    }
    return $out . '</ul></div>';
}

/** Simple pager: returns [offset, html]. */
function pager(int $total, int $page, int $perPage, array $query = []): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $out = '<nav class="pager" aria-label="Pages">';
    $link = static function (int $p, string $text) use ($query, $path): string {
        return '<a href="' . e($path . '?' . http_build_query($query + ['page' => $p])) . '">' . e($text) . '</a>';
    };
    if ($page > 1) {
        $out .= $link($page - 1, 'Previous');
    }
    $out .= '<span class="pager-info">Page ' . $page . ' of ' . $pages . ' (' . $total . ' records)</span>';
    if ($page < $pages) {
        $out .= $link($page + 1, 'Next');
    }
    return $out . '</nav>';
}
