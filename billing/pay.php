<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_permission('billing.pay');
require_post();

$invoiceId = (int) post_int('invoice_id');
$result = record_payment($invoiceId, $_POST, (int) $user['id']);

if ($result['ok']) {
    flash('success', 'Payment recorded. Receipt ' . receipt_number(get_payment($result['payment_id'])) . '.');
    redirect('billing/receipt.php', ['id' => $result['payment_id']]);
}
remember_form(['method' => post_str('method', 12), 'amount' => post_str('amount', 14), 'reference' => post_str('reference', 40)], $result['errors']);
redirect('billing/invoice.php', ['id' => $invoiceId]);
