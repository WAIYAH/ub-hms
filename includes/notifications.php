<?php
/**
 * Notifications and sidebar badges.
 *
 * These are live conditions read from the day-to-day records ("3 patients are waiting for triage"), not stored
 * messages, so there is nothing to mark as read: when the condition is dealt with, the notification disappears.
 * Each person sees only the conditions their role is allowed to act on, and nothing here carries patient details.
 */
declare(strict_types=1);

/**
 * The counts behind the notifications and badges for one user, worked out once per request.
 * A count is only looked up when the user's role may act on it.
 */
function shell_counts(array $user): array
{
    static $cache = [];
    $key = (int) $user['id'] . ':' . $user['role'];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $role = (string) $user['role'];
    $c = [];
    $can = static fn (string $p): bool => role_can($role, $p);
    $today = app_today();

    if ($can('requests.manage')) {
        $c['requests_new'] = count_new_public_requests();
    }
    if ($can('users.manage')) {
        $c['locked'] = (int) db_value('SELECT COUNT(*) FROM users WHERE locked_until > NOW()');
    }
    if ($can('system.health')) {
        $files = glob(config('paths.backups') . '/*.sql*') ?: [];
        if (!$files) {
            $c['backup'] = 'none';
        } else {
            usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $c['backup'] = (time() - filemtime($files[0])) / 3600 > 26 ? 'stale' : 'ok';
        }
        $c['failed_24h'] = (int) db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed' AND created_at > (NOW() - INTERVAL 24 HOUR)");
    }
    if ($can('visits.checkin')) {
        $c['booked_today'] = (int) db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'Booked'", [$today]);
    }
    if ($can('triage.record')) {
        $c['triage_wait'] = (int) db_value("SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND NOT EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)");
    }
    if ($can('consult.record')) {
        $c['consult_wait'] = (int) db_value(
            "SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)
                AND NOT EXISTS (SELECT 1 FROM consultations c WHERE c.visit_id = v.id) AND (v.doctor_id IS NULL OR v.doctor_id = ?)",
            [(int) $user['id']]
        );
    }
    if ($can('lab.request')) {
        $c['results_ready'] = results_ready_count((int) $user['id']);
    }
    if ($can('lab.worklist')) {
        $c['lab_wait'] = (int) db_value("SELECT COUNT(*) FROM lab_requests WHERE status = 'Requested'");
        $c['lab_progress'] = (int) db_value("SELECT COUNT(*) FROM lab_requests WHERE status = 'In progress'");
    }
    if ($can('pharmacy.dispense')) {
        $c['rx_wait'] = (int) db_value("SELECT COUNT(*) FROM prescriptions WHERE status IN ('Pending','Partially dispensed')");
    }
    if ($can('pharmacy.stock') && ($can('pharmacy.dispense') || $role === 'manager')) {
        $a = stock_alerts();
        $c['stock_out'] = count($a['out']);
        $c['stock_low'] = count($a['low']);
        $c['expired'] = count($a['expired']);
        $c['expiring'] = count($a['expiring']);
    }
    if ($can('billing.pay')) {
        $c['unpaid'] = (int) db_value("SELECT COUNT(*) FROM invoices WHERE status IN ('Unpaid', 'Partially paid')");
    }
    return $cache[$key] = $c;
}

/**
 * The notifications for a user, most urgent first. Each is ['level' => danger|warn|info, 'icon', 'title', 'text', 'href'].
 * $counts may be passed in (the tests do), otherwise they are looked up.
 */
function notifications_for(array $user, ?array $counts = null): array
{
    $c = $counts ?? shell_counts($user);
    $n = [];
    $add = static function (string $level, string $icon, string $title, string $text, string $href) use (&$n): void {
        $n[] = ['level' => $level, 'icon' => $icon, 'title' => $title, 'text' => $text, 'href' => $href];
    };

    if (($c['backup'] ?? 'ok') === 'none') {
        $add('danger', 'server', 'No backup found', 'Run the backup script and schedule it every day.', 'admin/health.php');
    } elseif (($c['backup'] ?? 'ok') === 'stale') {
        $add('warn', 'server', 'The last backup is more than a day old', 'Check that the nightly backup is running.', 'admin/health.php');
    }
    if (($c['stock_out'] ?? 0) > 0) {
        $add('danger', 'box', plural($c['stock_out'], 'medicine') . ' out of stock', 'Reorder as soon as possible.', 'pharmacy/stock.php');
    }
    if (($c['expired'] ?? 0) > 0) {
        $add('danger', 'clock', plural($c['expired'], 'batch', 'batches') . ' expired', 'Remove them from the shelf.', 'pharmacy/batches.php');
    }
    if (($c['locked'] ?? 0) > 0) {
        $add('warn', 'lock', plural($c['locked'], 'account') . ' locked', 'Unlock them once you have checked the person.', 'admin/users.php');
    }
    if (($c['failed_24h'] ?? 0) > 20) {
        $add('warn', 'shield', $c['failed_24h'] . ' failed sign-ins in 24 hours', 'Look at the audit trail for a pattern.', 'admin/audit.php');
    }
    if (($c['stock_low'] ?? 0) > 0) {
        $add('warn', 'box', plural($c['stock_low'], 'medicine') . ' running low', 'At or below the reorder level.', 'pharmacy/stock.php');
    }
    if (($c['expiring'] ?? 0) > 0) {
        $add('warn', 'clock', plural($c['expiring'], 'batch', 'batches') . ' expiring soon', 'Use them first.', 'pharmacy/batches.php');
    }
    if (($c['triage_wait'] ?? 0) > 0) {
        $add('warn', 'thermometer', plural($c['triage_wait'], 'patient') . ' waiting for triage', 'Record their vital signs.', 'visits/queue.php?stage=triage');
    }
    if (($c['lab_wait'] ?? 0) > 0) {
        $add('warn', 'flask', plural($c['lab_wait'], 'test') . ' waiting', 'Start the oldest request first.', 'lab/index.php');
    }
    if (($c['consult_wait'] ?? 0) > 0) {
        $add('info', 'stethoscope', plural($c['consult_wait'], 'patient') . ' waiting for a doctor', 'Triage is done.', 'visits/queue.php?stage=consult');
    }
    if (($c['results_ready'] ?? 0) > 0) {
        $add('info', 'file-check', plural($c['results_ready'], 'test result') . ' ready', 'For patients you are seeing.', 'lab/results.php');
    }
    if (($c['requests_new'] ?? 0) > 0) {
        $add('info', 'inbox', plural($c['requests_new'], 'new access request or message', 'new access requests and messages'), 'Review them and create accounts.', 'admin/requests.php?status=new');
    }
    if (($c['rx_wait'] ?? 0) > 0) {
        $add('info', 'pill', plural($c['rx_wait'], 'prescription') . ' waiting', 'Ready to dispense.', 'pharmacy/index.php');
    }
    if (($c['unpaid'] ?? 0) > 0) {
        $add('info', 'wallet', plural($c['unpaid'], 'invoice') . ' waiting for payment', 'Open invoices to take payment.', 'billing/index.php');
    }
    if (($c['booked_today'] ?? 0) > 0) {
        $add('info', 'calendar-check', plural($c['booked_today'], 'booked patient') . ' not yet checked in', 'Expected today.', 'appointments/index.php');
    }
    return $n;
}

/** Numbers to show beside sidebar items, keyed by the item's key. Zero counts are left out. */
function nav_badges(array $user, ?array $counts = null): array
{
    $c = $counts ?? shell_counts($user);
    $map = [
        'requests'   => $c['requests_new'] ?? 0,
        'users'      => $c['locked'] ?? 0,
        'triage'     => $c['triage_wait'] ?? 0,
        'consult'    => $c['consult_wait'] ?? 0,
        'labresults' => $c['results_ready'] ?? 0,
        'lab'        => ($c['lab_wait'] ?? 0),
        'pharmacy'   => $c['rx_wait'] ?? 0,
        'billing'    => $c['unpaid'] ?? 0,
        'checkin'    => $c['booked_today'] ?? 0,
        'stock'      => ($c['stock_out'] ?? 0) + ($c['stock_low'] ?? 0),
        'batches'    => ($c['expired'] ?? 0) + ($c['expiring'] ?? 0),
    ];
    return array_filter($map, static fn ($v) => (int) $v > 0);
}
