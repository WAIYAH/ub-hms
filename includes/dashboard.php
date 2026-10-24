<?php
/**
 * Dashboard tiles and alerts, chosen by the signed-in user's permissions.
 */
declare(strict_types=1);

function dashboard_tile(string $label, $value, string $href = '', string $class = ''): string
{
    $inner = '<div class="num">' . e($value) . '</div><div class="label">' . e($label) . '</div>';
    return '<div class="stat ' . e($class) . '">' . ($href !== '' ? '<a href="' . e(url($href)) . '">' . $inner . '</a>' : $inner) . '</div>';
}

function dashboard_tiles(array $user): array
{
    $t = [];
    $today = app_today();
    $doctorId = (int) $user['id'];

    if (user_can('appointments.view')) {
        $t[] = dashboard_tile(
            $user['role'] === 'doctor' ? 'My appointments today' : 'Appointments today',
            db_value(
                "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('Booked','Attended')" . ($user['role'] === 'doctor' ? ' AND doctor_id = ?' : ''),
                $user['role'] === 'doctor' ? [$today, $doctorId] : [$today]
            ),
            'appointments/index.php'
        );
    }
    if (user_can('visits.checkin')) {
        $t[] = dashboard_tile('Booked, not yet checked in', db_value("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'Booked'", [$today]), 'appointments/index.php');
        $t[] = dashboard_tile('Open visits', db_value("SELECT COUNT(*) FROM visits WHERE status = 'Open'"), 'visits/queue.php');
    }
    if (user_can('triage.record')) {
        $t[] = dashboard_tile(
            'Waiting for triage',
            db_value("SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND NOT EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)"),
            'visits/queue.php?stage=triage'
        );
    }
    if (user_can('consult.record')) {
        $t[] = dashboard_tile(
            'Patients waiting for a doctor',
            db_value(
                "SELECT COUNT(*) FROM visits v WHERE v.status = 'Open' AND EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)
                    AND NOT EXISTS (SELECT 1 FROM consultations c WHERE c.visit_id = v.id) AND (v.doctor_id IS NULL OR v.doctor_id = ?)",
                [$doctorId]
            ),
            'visits/queue.php?stage=consult'
        );
    }
    foreach (['dashboard_tiles_laboratory', 'dashboard_tiles_pharmacy', 'dashboard_tiles_billing'] as $fn) {
        if (function_exists($fn)) {
            $t = array_merge($t, $fn($user));
        }
    }
    return $t;
}

function dashboard_alerts(array $user): string
{
    $out = '';
    foreach (['dashboard_alerts_pharmacy'] as $fn) {
        if (function_exists($fn)) {
            $out .= $fn($user);
        }
    }
    return $out;
}
