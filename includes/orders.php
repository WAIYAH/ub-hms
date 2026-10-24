<?php
/**
 * Screens that let the doctor order laboratory tests and prescribe medicines
 * from the consultation page, and show them (read-only) on the visit summary.
 */
declare(strict_types=1);

/** Handle a POST from the consultation page. Returns true when the action was recognised (the caller redirects). */
function handle_consult_action(string $action, array $v, array $user): bool
{
    $doctorId = (int) $user['id'];

    if ($action === 'add_lab' && user_can('lab.request')) {
        $ids = array_map('intval', (array) ($_POST['test_ids'] ?? []));
        $r = request_lab_tests_safe((int) $v['id'], $ids, (string) ($_POST['clinical_notes'] ?? ''), $doctorId);
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? plural($r['count'], 'test') . ' sent to the laboratory.' : $r['error']);
        return true;
    }

    if ($action === 'cancel_lab' && user_can('lab.request')) {
        $err = cancel_lab_request((int) post_int('request_id'), $doctorId);
        flash($err ? 'error' : 'success', $err ?? 'Test request cancelled.');
        return true;
    }

    if ($action === 'add_rx' && user_can('pharmacy.prescribe')) {
        $items = [];
        foreach ((array) ($_POST['rx'] ?? []) as $row) {
            if (is_array($row)) {
                $items[] = [
                    'medicine_id' => $row['medicine_id'] ?? '', 'dosage' => $row['dosage'] ?? '', 'frequency' => $row['frequency'] ?? '',
                    'duration' => $row['duration'] ?? '', 'quantity' => $row['quantity'] ?? '',
                ];
            }
        }
        $r = create_prescription((int) $v['id'], $items, $doctorId);
        if ($r['ok']) {
            flash('success', 'Prescription sent to the pharmacy.');
            foreach ($r['warnings'] as $w) {
                flash('warning', 'Allergy reminder: ' . $w);
            }
        } else {
            flash('error', $r['error']);
        }
        return true;
    }
    return false;
}

function lab_status_text(array $r): string
{
    $out = badge($r['status']);
    if ($r['status'] === 'Completed' && $r['is_abnormal'] !== null) {
        $out .= ' ' . badge((int) $r['is_abnormal'] ? 'Abnormal' : 'Normal');
    }
    return $out;
}

/** Laboratory and prescription sections of the consultation page. */
function render_consult_orders(array $v, array $user, bool $canOrder): string
{
    $labs = visit_lab_requests((int) $v['id']);
    $items = visit_prescription_items((int) $v['id']);
    $out = '<div class="card"><h2>Laboratory tests</h2>';
    if ($labs) {
        $out .= '<div class="table-wrap"><table class="data"><thead><tr><th>Test</th><th>Status</th><th>Result</th><th></th></tr></thead><tbody>';
        foreach ($labs as $r) {
            $out .= '<tr><td>' . e($r['test_name']) . '<br><span class="muted small">Requested by ' . e($r['requested_by_name']) . ', ' . e(fmt_datetime($r['requested_at'])) . '</span></td>'
                . '<td>' . lab_status_text($r) . '</td>'
                . '<td>' . ($r['result'] !== null ? nl2br(e($r['result'])) . ($r['reference_range'] ? '<br><span class="muted small">Reference: ' . e($r['reference_range']) . '</span>' : '') : '<span class="muted">Waiting for the laboratory</span>') . '</td><td>';
            if ($canOrder && $r['status'] === 'Requested' && (int) $r['requested_by'] === (int) $user['id']) {
                $out .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="visit" value="' . (int) $v['id'] . '"><input type="hidden" name="action" value="cancel_lab"><input type="hidden" name="request_id" value="' . (int) $r['id'] . '"><button class="btn btn-small btn-secondary" type="submit" data-confirm="Cancel this test request?">Cancel</button></form>';
            }
            $out .= '</td></tr>';
        }
        $out .= '</tbody></table></div>';
    } else {
        $out .= '<p class="muted">No tests requested.</p>';
    }
    if ($canOrder && user_can('lab.request')) {
        $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="visit" value="' . (int) $v['id'] . '"><input type="hidden" name="action" value="add_lab">'
            . '<fieldset><legend>Request tests</legend><div class="form-grid">';
        foreach (active_lab_tests() as $t) {
            $out .= '<label class="check"><input type="checkbox" name="test_ids[]" value="' . (int) $t['id'] . '"> ' . e($t['name']) . ' <span class="muted small">(' . e(fmt_money($t['price'])) . ')</span></label>';
        }
        $out .= '</div><div class="field mt"><label for="clinical_notes">Why is this test needed? (optional)</label><input id="clinical_notes" name="clinical_notes" maxlength="255"></div></fieldset>'
            . '<button class="btn" type="submit">Send to laboratory</button></form>';
    }
    $out .= '</div>';

    $out .= '<div class="card"><h2>Prescriptions</h2>';
    if ($items) {
        $out .= '<div class="table-wrap"><table class="data"><thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th><th class="num">Qty</th><th>Status</th></tr></thead><tbody>';
        foreach ($items as $i) {
            $out .= '<tr><td>' . e(medicine_label(['name' => $i['medicine_name'], 'strength' => $i['strength'], 'form' => $i['form']])) . '</td><td>' . e($i['dosage']) . '</td><td>' . e($i['frequency']) . '</td><td>' . e($i['duration']) . '</td>'
                . '<td class="num">' . (int) $i['quantity'] . '</td><td>' . badge($i['prescription_status']) . ' <span class="muted small">' . (int) $i['dispensed_qty'] . ' given</span></td></tr>';
        }
        $out .= '</tbody></table></div>';
    } else {
        $out .= '<p class="muted">Nothing prescribed.</p>';
    }
    if ($canOrder && user_can('pharmacy.prescribe')) {
        if (!empty($v['allergies'])) {
            $out .= '<div class="allergy" role="note"><strong>Allergies:</strong> ' . e($v['allergies']) . '</div>';
        }
        $meds = medicine_stock('', true);
        $out .= '<form method="post">' . csrf_field() . '<input type="hidden" name="visit" value="' . (int) $v['id'] . '"><input type="hidden" name="action" value="add_rx">'
            . '<fieldset><legend>Prescribe</legend>';
        for ($n = 0; $n < 4; $n++) {
            $out .= '<div class="form-grid rx-row"><div class="field"><label for="rx_' . $n . '_m">Medicine</label><select id="rx_' . $n . '_m" name="rx[' . $n . '][medicine_id]"><option value="">None</option>';
            foreach ($meds as $m) {
                $out .= '<option value="' . (int) $m['id'] . '">' . e(medicine_label($m)) . ' (' . ($m['on_hand'] > 0 ? $m['on_hand'] . ' in stock' : 'out of stock') . ')</option>';
            }
            $out .= '</select></div>'
                . '<div class="field"><label for="rx_' . $n . '_q">Quantity to dispense</label><input id="rx_' . $n . '_q" name="rx[' . $n . '][quantity]" type="number" min="1" max="9999"></div>'
                . '<div class="field"><label for="rx_' . $n . '_d">Dose</label><input id="rx_' . $n . '_d" name="rx[' . $n . '][dosage]" maxlength="60" placeholder="e.g. 1 tablet"></div>'
                . '<div class="field"><label for="rx_' . $n . '_f">How often</label><input id="rx_' . $n . '_f" name="rx[' . $n . '][frequency]" maxlength="60" placeholder="e.g. three times a day"></div>'
                . '<div class="field"><label for="rx_' . $n . '_u">For how long</label><input id="rx_' . $n . '_u" name="rx[' . $n . '][duration]" maxlength="60" placeholder="e.g. 5 days"></div></div>';
        }
        $out .= '</fieldset><p class="muted small">The system does not check doses. The pharmacist dispenses exactly what you write.</p><button class="btn" type="submit">Send to pharmacy</button></form>';
    }
    return $out . '</div>';
}

/** Read-only laboratory and medicine summary for the visit page (clinical roles only). */
function render_visit_orders(array $v, array $user): string
{
    if (!role_can($user['role'], 'consult.record')) {
        return '';
    }
    return render_consult_orders($v, $user, false);
}
