"""Photograph the pages added in the final release: doctor hours and leave, refunds, and patient data requests.

    php docs/build/seed_rights_demo.php      (after take_screenshots.py, on the same demonstration database)
    python docs/build/take_screenshots_rights.py

Writes 57-... to 70-... into ../screenshots. Uses the same server settings as take_screenshots.py.
"""
import json
import os
import subprocess
import time
import urllib.request

from playwright.sync_api import sync_playwright

import take_screenshots as t

PHP_IDS = r'''define("APP_ROOT", "%s"); require APP_ROOT . "/includes/core.php";
echo json_encode([
 "doc" => (int) db_value("SELECT id FROM users WHERE username='doctor3'"),
 "pend" => (int) db_value("SELECT id FROM refund_requests WHERE status='Pending' LIMIT 1"),
 "appr" => (int) db_value("SELECT id FROM refund_requests WHERE status='Approved' LIMIT 1"),
 "refpay" => (int) db_value("SELECT refund_payment_id FROM refund_requests WHERE status='Approved' LIMIT 1"),
 "rpay" => (int) db_value("SELECT payment_id FROM refund_requests WHERE status='Pending' LIMIT 1"),
 "rvisit" => (int) db_value("SELECT i.visit_id FROM refund_requests r JOIN invoices i ON i.id=r.invoice_id WHERE r.status='Approved' LIMIT 1"),
 "exp" => (int) db_value("SELECT id FROM data_requests WHERE kind='Export' LIMIT 1"),
 "er_ok" => (int) db_value("SELECT id FROM data_requests WHERE kind='Erasure' ORDER BY id LIMIT 1"),
 "er_blocked" => (int) db_value("SELECT id FROM data_requests WHERE kind='Erasure' ORDER BY id DESC LIMIT 1"),
 "pat" => (int) db_value("SELECT patient_id FROM data_requests WHERE kind='Export' LIMIT 1"),
]);''' % t.REPO.replace('\\', '/')


def main():
    ids = json.loads(subprocess.run([t.PHP, '-r', PHP_IDS], capture_output=True, text=True, cwd=t.REPO).stdout)
    server = subprocess.Popen([t.PHP, '-S', f'127.0.0.1:{t.PORT}', '-t', t.REPO], cwd=t.REPO, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              env={**os.environ, 'UBHMS_BCRYPT_COST': '11'})
    try:
        for _ in range(40):
            try:
                urllib.request.urlopen(t.BASE + '/login.php', timeout=1)
                break
            except Exception:
                time.sleep(0.3)
        plan = [
            ('manager1', [('57-doctor-schedules', '/admin/schedules.php', 1400),
                          ('58-doctor-schedule-edit', f"/admin/schedule.php?doctor={ids['doc']}", 2000),
                          ('60-refund-list', '/billing/refunds.php', 1100),
                          ('62-refund-decision', f"/billing/refund.php?id={ids['pend']}", 1500),
                          ('65-data-requests', '/privacy/index.php', 1100),
                          ('67-erasure-decision', f"/privacy/request.php?id={ids['er_ok']}", 1900),
                          ('68-erasure-blocked', f"/privacy/request.php?id={ids['er_blocked']}", 1900)]),
            ('reception1', [('59-appointments-to-move', '/appointments/conflicts.php', 1300),
                            ('66-data-request-form', f"/patients/data.php?id={ids['pat']}", 2300),
                            ('69-record-copy', f"/patients/export.php?request={ids['exp']}", 2300)]),
            ('cashier1', [('61-refund-request-form', f"/billing/refund_request.php?payment={ids['rpay']}", 1500),
                          ('63-invoice-with-refund', f"/billing/invoice.php?visit={ids['rvisit']}", 2100),
                          ('64-refund-voucher', f"/billing/receipt.php?id={ids['refpay']}", 1300)]),
            ('doctor3', [('70-doctor-profile-hours', '/profile.php', 1800)]),
        ]
        with sync_playwright() as p:
            browser = p.chromium.launch(channel='msedge', headless=True)
            s = t.Shooter(browser)
            for user, pages in plan:
                ctx = s.context()
                pg = ctx.new_page()
                s.login(pg, user, t.DEMO_PW)
                for name, path, max_h in pages:
                    pg.goto(t.BASE + path)
                    s.shot(pg, name, full=True, max_height=max_h)
                ctx.close()
            browser.close()
        print(s.n, 'screenshots')
    finally:
        server.terminate()


if __name__ == '__main__':
    main()
