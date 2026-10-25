<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('appointments.update');
require_post();

$id = post_int('id');
$status = post_str('status', 12);
$back = post_str('back', 10);
$error = $id === null ? 'Appointment not found.' : set_appointment_status($id, $status, (int) $user['id'], post_str('reason', 255));

if ($error) {
    flash('error', $error);
} else {
    flash('success', 'Appointment marked ' . strtolower($status) . '.');
}
if (post_str('return', 10) === 'conflicts') {
    redirect('appointments/conflicts.php');
}
redirect('appointments/index.php', is_valid_date($back) ? ['date' => $back, 'doctor_id' => ''] : []);
