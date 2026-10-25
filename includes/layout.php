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
            ['Access requests', 'admin/requests.php', 'requests.manage', 'requests'],
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
    echo '<meta name="theme-color" content="#07423d">';
    echo '<title>' . e($title) . ' | ' . e($app) . '</title>';
    echo '<link rel="icon" href="' . e(asset('img/favicon.svg')) . '" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">';
    if ($user) {
        echo '<link rel="stylesheet" href="' . e(asset('css/dash.css')) . '">';
    }
    // $opts['css'] lists extra stylesheets (the sign-in page uses css/site.css).
    foreach ((array) ($opts['css'] ?? []) as $extra) {
        echo '<link rel="stylesheet" href="' . e(asset((string) $extra)) . '">';
    }
    echo '<script src="' . e(asset('js/app.js')) . '" defer></script>';
    if ($user) {
        echo '<script src="' . e(asset('js/dash.js')) . '" defer></script>';
    }
    // $opts['body_class'] adds a class to pages shown before sign-in (the landing page uses "landing").
    echo '</head><body' . ($user ? ' class="app"' : ' class="plain' . (isset($opts['body_class']) ? ' ' . e($opts['body_class']) : '') . '"') . '>';
    echo '<a class="skip" href="#main">Skip to content</a>';

    if ($user) {
        echo shell_header($user);
        echo '<div class="shell">' . shell_sidebar($user, $active) . '<div class="sb-overlay" data-close-menu aria-hidden="true"></div>';
        echo '<main id="main" class="content" tabindex="-1">';
    } else {
        echo '<main id="main" class="' . e($opts['main_class'] ?? 'content-plain') . '" tabindex="-1">';
    }

    // A page that places the messages itself (the landing page) passes no_flashes and calls render_flashes().
    if (empty($opts['no_flashes'])) {
        echo render_flashes();
    }
    if (empty($opts['no_title'])) {
        echo '<h1 class="page-title">' . e($title) . '</h1>';
    }
}

/** Messages stored for the next page (success, error, warning, info), as HTML. Reading them clears them. */
function render_flashes(): string
{
    $out = '';
    foreach (take_flashes() as $f) {
        $role = $f['type'] === 'error' ? 'alert' : 'status';
        $out .= '<div class="flash flash-' . e($f['type']) . '" role="' . $role . '">' . e($f['message']) . '</div>';
    }
    return $out;
}

/** End a page. A page that prints its own footer (the landing page) passes false. */
function page_end(bool $footer = true): void
{
    $user = current_user();
    echo '</main>';
    if ($user) {
        echo '</div>';
    }
    if ($footer) {
        echo '<footer class="footer">' . e(config('hospital')) . ' &middot; ' . e(config('short_name'))
            . ' &middot; Confidential: for authorised staff only. All activity is recorded.</footer>';
    }
    echo '</body></html>';
}

/** The animated heartbeat line used on the welcome and sign-in pages (decorative; the line is drawn by the stylesheet). */
function ecg_svg(): string
{
    $d = 'M0 70H150L168 58L186 70H236L252 82L274 6L298 112L314 70H380L402 52L424 70H800';
    return '<svg class="hero-ecg" viewBox="0 0 800 120" aria-hidden="true" focusable="false">'
        . '<path class="ecg-base" pathLength="1000" d="' . $d . '"/><path class="ecg-pulse" pathLength="1000" d="' . $d . '"/></svg>';
}

/**
 * Inline SVG icon, hidden from screen readers (the words next to it carry the meaning).
 * Drawn on a 24 x 24 grid with the current text colour. Styled by the stylesheet, so it works with the strict CSP.
 */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'lock'        => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'eye'         => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'     => '<path d="M3 3l18 18"/><path d="M10.6 6.1A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 7.6A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'shield'      => '<path d="M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'clipboard'   => '<path d="M9 4h6v3H9z"/><path d="M15 5.5h2a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1h2"/><path d="M9 12h6M9 16h4"/>',
        'alert'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
        'user-plus'   => '<circle cx="9" cy="8" r="4"/><path d="M2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M19 8v6M16 11h6"/>',
        'stethoscope' => '<path d="M5 3H4v6a4 4 0 0 0 8 0V3h-1"/><path d="M8 13v2a5 5 0 0 0 10 0v-2"/><circle cx="18" cy="11" r="2"/>',
        'flask'       => '<path d="M9 3h6"/><path d="M10 3v6l-5.5 9.5A2 2 0 0 0 6.2 21.5h11.6a2 2 0 0 0 1.7-3L14 9V3"/><path d="M7.5 15h9"/>',
        'pill'        => '<path d="M10.5 20.5a5 5 0 0 1-7-7l10-10a5 5 0 0 1 7 7l-10 10z"/><path d="M8.5 8.5l7 7"/>',
        'receipt'     => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9.5 8h5M9.5 12h5"/>',
        'chart'       => '<path d="M4 20V11M10 20V4M16 20v-7M22 20H2"/>',
        'heart'       => '<path d="M12 20.5s-8-4.7-8-10.6A4.6 4.6 0 0 1 12 7a4.6 4.6 0 0 1 8 2.9c0 5.9-8 10.6-8 10.6z"/>',
        'check'       => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'arrow-down'  => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'phone'       => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'mail'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'map-pin'     => '<path d="M12 21s7-5.6 7-11a7 7 0 0 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'arrow-up'    => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'file-text'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'scale'       => '<path d="M12 4v16M7 20h10M5 7h14"/><path d="M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
        'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'building'    => '<path d="M4 21V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16M14 10h5a1 1 0 0 1 1 1v10M2 21h20M8 8h2M8 12h2M8 16h2M17 14h1M17 18h1"/>',
        'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.01"/>',
        'send'        => '<path d="M21 3L10 14M21 3l-7 18-4-7-7-4z"/>',
        'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18"/>',
        'accessibility' => '<circle cx="12" cy="4.5" r="1.8"/><path d="M5 8l7 1.5L19 8M12 9.5V14l-3 6M12 14l3 6"/>',
        'cookie'      => '<path d="M21 12a9 9 0 1 1-9-9 4 4 0 0 0 4 4 4 4 0 0 0 5 5z"/><path d="M8.5 11.5v.01M12 16v.01M15.5 13v.01M9 7.5v.01"/>',
        'badge-check' => '<path d="M12 3l2.4 1.8 3-.2 1 2.8 2.5 1.7-.9 2.9.9 2.9-2.5 1.7-1 2.8-3-.2L12 21l-2.4-1.8-3 .2-1-2.8-2.5-1.7.9-2.9-.9-2.9 2.5-1.7 1-2.8 3 .2z"/><path d="M9 12l2 2 4-4"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'calendar-plus' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v5M9.5 15.5h5"/>',
        'calendar-check' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M9 16l2 2 4-4"/>',
        'log-in' => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 8l4 4-4 4M14 12H3"/>',
        'log-out' => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M16 8l4 4-4 4M20 12H9"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'file-check' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 14l2 2 4-4"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 12.5l9 5 9-5M3 17l9 5 9-5"/>',
        'package-plus' => '<path d="M20 10V8l-8-5-9 5v8l9 5 3.5-2"/><path d="M3 8l9 5 8-5M12 13v8M19 15v6M16 18h6"/>',
        'tag' => '<path d="M3 4h8.5L21 13.5a2 2 0 0 1 0 2.8l-4.7 4.7a2 2 0 0 1-2.8 0L3 11.5z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'server' => '<rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/><path d="M7 7h.01M7 17h.01"/>',
        'inbox' => '<path d="M3 13l3-8h12l3 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
        'bell' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'user-circle' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.5a6.5 6.5 0 0 1 11.6 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevrons-left' => '<path d="M11 6l-6 6 6 6M19 6l-6 6 6 6"/>',
        'trending-up' => '<path d="M3 17l6-6 4 4 8-8M15 7h6v6"/>',
        'trending-down' => '<path d="M3 7l6 6 4-4 8 8M15 17h6v-6"/>',
        'banknote' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'wallet' => '<path d="M19 7V5a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2V6"/><path d="M16 14h.01"/>',
        'credit-card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h3"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
        'pie' => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'edit' => '<path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 7.5l2 2"/>',
        'printer' => '<path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'key' => '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.5 12.5L20 3M16 7l3 3"/>',
        'sliders' => '<path d="M4 6h8M18 6h2M4 12h2M12 12h8M4 18h10M20 18h0"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
        'panel-left' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'droplet' => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
        'thermometer' => '<path d="M14 14.8V5a2 2 0 0 0-4 0v9.8a4 4 0 1 0 4 0z"/>',
        'clipboard-check' => '<path d="M9 4h6v3H9z"/><path d="M15 5.5h2a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1h2"/><path d="M9 14l2 2 4-4"/>',
        'zap' => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
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
