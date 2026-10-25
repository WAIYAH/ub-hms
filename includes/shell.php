<?php
/**
 * The signed-in application shell: header (search, quick add, notifications, user menu) and sidebar.
 *
 * The three dropdowns are native <details> elements, so they open and close without JavaScript; assets/js/dash.js only
 * adds closing on an outside click or Escape. The Sign out button stays a POST form carrying the security token.
 */
declare(strict_types=1);

/** The sidebar icon for each menu item key (see nav_items() in layout.php). */
function nav_icon(string $key): string
{
    static $map = [
        'home' => 'grid', 'patients' => 'search', 'register' => 'user-plus', 'import' => 'upload',
        'appointments' => 'calendar', 'book' => 'calendar-plus', 'checkin' => 'log-in',
        'triage' => 'thermometer', 'consult' => 'stethoscope', 'visits' => 'activity',
        'lab' => 'flask', 'labresults' => 'file-check',
        'pharmacy' => 'pill', 'stock' => 'box', 'batches' => 'layers', 'receive' => 'package-plus', 'medicines' => 'list',
        'billing' => 'receipt', 'refunds' => 'undo', 'privacy' => 'shield', 'conflicts' => 'calendar-x', 'schedules' => 'clock',
        'r-attendance' => 'users', 'r-revenue' => 'trending-up', 'r-stock' => 'pie', 'r-lab' => 'droplet',
        'users' => 'users', 'requests' => 'inbox', 'prices' => 'tag', 'audit' => 'history', 'health' => 'server',
    ];
    return $map[$key] ?? 'chart';
}

/** The "quick add" menu: the few things people do most, limited to what their role may do. [label, path, icon, permission] */
function shell_quick_add(): array
{
    $all = [
        ['Register a patient', 'patients/register.php', 'user-plus', 'patients.register'],
        ['Book an appointment', 'appointments/book.php', 'calendar-plus', 'appointments.book'],
        ['Check in a patient', 'visits/checkin.php', 'log-in', 'visits.checkin'],
        ['Receive stock', 'pharmacy/receive.php', 'package-plus', 'pharmacy.manage'],
        ['Add a user account', 'admin/user.php', 'users', 'users.manage'],
    ];
    return array_values(array_filter($all, static fn ($i) => user_can($i[3])));
}

/** A circle with the person's initials. The colour comes from the user number, so it is the same on every page. */
function shell_avatar(array $user, string $size = ''): string
{
    return '<span class="av av-' . ((int) $user['id'] % 6) . ($size !== '' ? ' av-' . e($size) : '') . '" aria-hidden="true">' . e(user_initials((string) $user['full_name'])) . '</span>';
}

function shell_header(array $user): string
{
    $app = (string) config('short_name');
    $role = role_labels()[$user['role']] ?? (string) $user['role'];
    $h = '<header class="app-top">';
    $h .= '<button type="button" class="top-btn top-burger" data-toggle-menu aria-controls="sidebar" aria-expanded="false" aria-label="Open the menu">' . icon('menu') . '</button>';
    $h .= '<button type="button" class="top-btn top-collapse" data-collapse aria-pressed="false" aria-controls="sidebar" aria-label="Shrink the menu to icons" title="Shrink or widen the menu" hidden>' . icon('panel-left') . '</button>';
    $h .= '<a class="top-brand" href="' . e(url('index.php')) . '"><span class="brand-mark" aria-hidden="true">+</span><span class="top-brand-text"><strong>' . e($app) . '</strong><small>' . e(config('hospital')) . '</small></span></a>';

    if (user_can('patients.view')) {
        $h .= '<form class="top-search" role="search" method="get" action="' . e(url('patients/index.php')) . '">' . icon('search')
            . '<input type="search" name="q" maxlength="60" placeholder="Search patients by name, number or phone" aria-label="Search patients by name, number or phone" autocomplete="off">'
            . '</form>';
    } else {
        $h .= '<span class="top-grow"></span>';
    }

    $h .= '<div class="top-end">';

    $quick = shell_quick_add();
    if ($quick) {
        $h .= '<details class="dd dd-add"><summary class="top-btn top-btn-add" aria-label="Quick add" title="Quick add">' . icon('plus') . '</summary><div class="dd-panel dd-panel-sm"><p class="dd-title">Quick add</p><ul class="dd-list">';
        foreach ($quick as [$label, $path, $ic]) {
            $h .= '<li><a href="' . e(url($path)) . '"><span class="dd-ic">' . icon($ic) . '</span>' . e($label) . '</a></li>';
        }
        $h .= '</ul></div></details>';
    }

    $notes = notifications_for($user);
    $count = count($notes);
    $h .= '<details class="dd dd-notif"><summary class="top-btn top-btn-bell" aria-label="Notifications: ' . ($count ? $count . ' need attention' : 'nothing needs attention') . '" title="Notifications">'
        . icon('bell') . ($count ? '<span class="top-badge" aria-hidden="true">' . ($count > 9 ? '9+' : $count) . '</span>' : '') . '</summary>';
    $h .= '<div class="dd-panel dd-panel-wide"><div class="dd-head"><strong>Notifications</strong><span class="dd-sub">' . ($count ? $count . ' need' . ($count === 1 ? 's' : '') . ' attention' : 'All clear') . '</span></div>';
    if ($notes) {
        $h .= '<ul class="notif-list">';
        foreach ($notes as $nt) {
            $h .= '<li class="notif notif-' . e($nt['level']) . '"><a href="' . e(url($nt['href'])) . '"><span class="notif-ic">' . icon($nt['icon']) . '</span><span class="notif-body"><strong>' . e($nt['title']) . '</strong><small>' . e($nt['text']) . '</small></span>' . icon('arrow-right', 'icon notif-go') . '</a></li>';
        }
        $h .= '</ul>';
    } else {
        $h .= '<div class="dd-empty">' . icon('check-circle') . '<strong>You are all caught up</strong><span>Nothing needs your attention right now.</span></div>';
    }
    $h .= '<p class="dd-foot">These are live alerts from the records. They go away when the work is done.</p></div></details>';

    $h .= '<details class="dd dd-user"><summary class="user-btn">' . shell_avatar($user)
        . '<span class="user-btn-text" aria-hidden="true"><strong>' . e($user['full_name']) . '</strong><small>' . e($role) . '</small></span>'
        . '<span class="sr-only">Account menu for ' . e($user['full_name']) . ', ' . e($role) . '</span>' . icon('chevron-down', 'icon user-caret') . '</summary>';
    $h .= '<div class="dd-panel dd-panel-user"><div class="user-card">' . shell_avatar($user, 'lg') . '<div><strong>' . e($user['full_name']) . '</strong><small>' . e($role) . '</small><small class="user-name">@' . e($user['username']) . '</small></div></div>';
    $h .= '<ul class="dd-list"><li><a href="' . e(url('profile.php')) . '"><span class="dd-ic">' . icon('user-circle') . '</span>View profile</a></li>'
        . '<li><a href="' . e(url('settings.php')) . '"><span class="dd-ic">' . icon('settings') . '</span>Settings</a></li>'
        . '<li><a href="' . e(url('password.php')) . '"><span class="dd-ic">' . icon('key') . '</span>Change password</a></li></ul>';
    $h .= '<form method="post" action="' . e(url('logout.php')) . '" class="dd-signout">' . csrf_field() . '<button type="submit">' . icon('log-out') . 'Sign out</button></form>';
    $h .= '</div></details>';

    return $h . '</div></header>';
}

function shell_sidebar(array $user, string $active): string
{
    $badges = nav_badges($user);
    $item = static function (string $label, string $path, string $key) use ($active, $badges): string {
        $on = $active === $key;
        $b = $badges[$key] ?? 0;
        return '<a class="nav-link' . ($on ? ' is-active' : '') . '" href="' . e(url($path)) . '" title="' . e($label) . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span class="nav-ic">' . icon(nav_icon($key)) . '</span><span class="nav-text">' . e($label) . '</span>'
            . ($b > 0 ? '<span class="nav-badge">' . (int) $b . '<span class="sr-only"> need attention</span></span>' : '') . '</a>';
    };
    $s = '<nav class="sidebar" id="sidebar" aria-label="Main menu"><div class="nav-scroll">';
    $s .= $item('Dashboard', 'index.php', 'home');
    foreach (nav_items() as [$group, $items]) {
        $visible = array_filter($items, static fn ($i) => user_can($i[2]));
        if (!$visible) {
            continue;
        }
        $s .= '<div class="nav-group"><span>' . e($group) . '</span></div>';
        foreach ($visible as [$label, $path, , $key]) {
            $s .= $item($label, $path, $key);
        }
    }
    $s .= '</div><div class="nav-foot"><a class="nav-link nav-help" href="' . e(url('profile.php')) . '" title="Your profile"><span class="nav-ic">' . icon('user-circle') . '</span><span class="nav-text">Your profile</span></a>'
        . '<p class="nav-note">' . e(config('hospital')) . '</p></div></nav>';
    return $s;
}
