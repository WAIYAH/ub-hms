"""Take the screenshots used in Chapters 5, 7 and 8, signed in as each role, against the synthetic demo data.

    python take_screenshots.py

Needs: the demo database (php database/install.php --demo), Playwright and Microsoft Edge.
Starts PHP's built-in server on port 8095 and writes PNG files to ../screenshots.
"""
import json
import os
import subprocess
import sys
import time
import urllib.request
from datetime import date, timedelta

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
OUT = os.path.join(HERE, '..', 'screenshots')
PHP = os.environ.get('PHP_BIN', r'C:\xampp\php\php.exe')
PORT = 8095
BASE = f'http://127.0.0.1:{PORT}'
DEMO_PW = 'UbHms#Demo2026'
ADMIN_USER, ADMIN_PW_OLD, ADMIN_PW_NEW = 'sysadmin', 'Setup#Hospital2026', 'Hospital#Ops2026x'
os.makedirs(OUT, exist_ok=True)


def ids():
    out = subprocess.run([PHP, os.path.join(HERE, 'shot_ids.php')], capture_output=True, text=True, cwd=REPO)
    return json.loads(out.stdout)


def next_workday(offset=1):
    d = date.today() + timedelta(days=offset)
    while d.isoweekday() == 7:
        d += timedelta(days=1)
    return d.isoformat()


class Shooter:
    def __init__(self, browser):
        self.browser = browser
        self.n = 0
        self.log = []

    def context(self, width=960, height=720, mobile=False):
        return self.browser.new_context(viewport={'width': width, 'height': height}, device_scale_factor=2, locale='en-GB',
                                        timezone_id='Africa/Nairobi', is_mobile=mobile)

    def shot(self, page, name, full=False, max_height=None, selector=None):
        path = os.path.join(OUT, name + '.png')
        if selector:
            page.locator(selector).first.screenshot(path=path)
        elif full:
            if max_height:
                h = page.evaluate('document.documentElement.scrollHeight')
                page.screenshot(path=path, full_page=True, clip={'x': 0, 'y': 0, 'width': page.viewport_size['width'], 'height': min(h, max_height)})
            else:
                page.screenshot(path=path, full_page=True)
        else:
            page.screenshot(path=path)
        self.n += 1
        self.log.append(name)
        print('shot', name)

    def login(self, page, user, pw):
        page.goto(BASE + '/login.php')
        page.fill('#username', user)
        page.fill('#password', pw)
        page.click('button[type=submit]')
        page.wait_for_load_state('networkidle')


def main():
    info = ids()
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{PORT}', '-t', REPO], cwd=REPO, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                              env={**os.environ, 'UBHMS_BCRYPT_COST': '11'})
    try:
        for _ in range(40):
            try:
                urllib.request.urlopen(BASE + '/login.php', timeout=1)
                break
            except Exception:
                time.sleep(0.3)
        with sync_playwright() as p:
            browser = p.chromium.launch(channel='msedge', headless=True)
            s = Shooter(browser)

            # ---------- sign-in page, error, lockout ----------
            ctx = s.context()
            pg = ctx.new_page()
            pg.goto(BASE + '/login.php')
            s.shot(pg, '01-login')
            s.login(pg, 'doctor1', 'wrong password')
            s.shot(pg, '02-login-error')
            for i in range(5):
                s.login(pg, 'reception2', 'Wrong#Password' + str(i))
            s.login(pg, 'reception2', DEMO_PW)
            s.shot(pg, '03-login-locked')
            ctx.close()

            # ---------- administrator ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, ADMIN_USER, ADMIN_PW_OLD)
            if 'password.php' in pg.url:
                pg.fill('#f_current_password', ADMIN_PW_OLD)
                pg.fill('#f_new_password', 'weak')
                pg.fill('#f_confirm_password', 'weak')
                pg.click('button[type=submit]')
                s.shot(pg, '04-password-weak')
                pg.fill('#f_current_password', ADMIN_PW_OLD)
                pg.fill('#f_new_password', ADMIN_PW_NEW)
                pg.fill('#f_confirm_password', ADMIN_PW_NEW)
                pg.click('button[type=submit]')
                pg.wait_for_load_state('networkidle')
            else:
                s.login(pg, ADMIN_USER, ADMIN_PW_NEW)
            s.shot(pg, '05-admin-dashboard', full=True)
            pg.goto(BASE + '/admin/users.php'); s.shot(pg, '06-admin-users', full=True)
            pg.goto(BASE + '/admin/user.php?id=' + str(info['user_edit'])); s.shot(pg, '07-admin-user-form', full=True)
            pg.goto(BASE + '/admin/audit.php'); s.shot(pg, '08-admin-audit', full=True, max_height=1300)
            pg.goto(BASE + '/admin/prices.php'); s.shot(pg, '09-admin-prices', full=True, max_height=1300)
            pg.goto(BASE + '/admin/health.php'); s.shot(pg, '10-admin-health', full=True)
            ctx.close()

            # ---------- receptionist ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'reception1', DEMO_PW)
            s.shot(pg, '11-reception-dashboard', full=True)
            pg.goto(BASE + '/patients/index.php?q=' + info['patient_last']); s.shot(pg, '12-patient-search', full=True, max_height=1000)
            pg.goto(BASE + '/patients/register.php'); s.shot(pg, '13-patient-register', full=True)
            pg.click('button[type=submit]'); pg.wait_for_load_state('networkidle'); s.shot(pg, '14-patient-register-errors', full=True, max_height=1500)
            pg.goto(BASE + '/patients/view.php?id=' + str(info['patient_id'])); s.shot(pg, '15-patient-record-reception', full=True, max_height=1500)
            pg.goto(BASE + '/appointments/index.php?date=' + date.today().isoformat() + '&doctor_id='); s.shot(pg, '16-appointment-diary', full=True, max_height=1400)
            pg.goto(BASE + f'/appointments/book.php?patient_id={info["patient_id"]}&doctor_id={info["doctor1"]}&date={next_workday(1)}'); s.shot(pg, '17-book-appointment', full=True, max_height=1700)
            pg.goto(BASE + '/visits/checkin.php'); s.shot(pg, '18-checkin', full=True, max_height=1400)
            pg.goto(BASE + '/visits/queue.php'); s.shot(pg, '19-open-visits', full=True, max_height=1200)
            pg.goto(BASE + '/patients/import.php'); s.shot(pg, '20-patient-import', full=True, max_height=1300)
            pg.goto(BASE + '/admin/users.php'); s.shot(pg, '21-access-denied', full=True)
            ctx.close()

            # ---------- nurse ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'nurse1', DEMO_PW)
            s.shot(pg, '22-nurse-dashboard', full=True)
            pg.goto(BASE + '/visits/queue.php?stage=triage'); s.shot(pg, '23-triage-queue', full=True, max_height=1100)
            pg.goto(BASE + '/visits/triage.php?visit=' + str(info['triage_visit']))
            pg.fill('#f_temperature', '60'); pg.fill('#f_pulse', '5')
            pg.click('form[method=post] button[type=submit]'); pg.wait_for_load_state('networkidle')
            s.shot(pg, '24-triage-errors', full=True, max_height=1500)
            pg.goto(BASE + '/visits/triage.php?visit=' + str(info['triage_visit'])); s.shot(pg, '25-triage-form', full=True, max_height=1500)
            ctx.close()

            # ---------- doctor ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'doctor1', DEMO_PW)
            s.shot(pg, '26-doctor-dashboard', full=True)
            pg.goto(BASE + '/visits/queue.php?stage=consult'); s.shot(pg, '27-consult-queue', full=True, max_height=1200)
            pg.goto(BASE + '/visits/consult.php?visit=' + str(info['orders_visit'])); s.shot(pg, '28-consult-top', full=True, max_height=1500)
            pg.evaluate("document.querySelector('.card h2').scrollIntoView()")
            h = pg.evaluate("[...document.querySelectorAll('h2')].find(e => e.textContent.trim() === 'Laboratory tests').getBoundingClientRect().top + window.scrollY")
            pg.evaluate(f'window.scrollTo(0, {h - 20})'); s.shot(pg, '29-consult-orders')
            pg.goto(BASE + '/patients/view.php?id=' + str(info['patient_id'])); s.shot(pg, '30-patient-record-doctor', full=True, max_height=1700)
            pg.goto(BASE + '/lab/results.php'); s.shot(pg, '31-my-test-results', full=True, max_height=1400)
            pg.goto(BASE + '/visits/consult.php?visit=' + str(info['result_visit'])); s.shot(pg, '32-consult-with-result', full=True, max_height=2200)
            ctx.close()

            # ---------- laboratory ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'lab1', DEMO_PW)
            s.shot(pg, '33-lab-dashboard', full=True)
            pg.goto(BASE + '/lab/index.php'); s.shot(pg, '34-lab-worklist', full=True, max_height=1200)
            pg.goto(BASE + '/lab/result.php?id=' + str(info['lab_request'])); s.shot(pg, '35-lab-result-form', full=True, max_height=1300)
            ctx.close()

            # ---------- pharmacist ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'pharmacist1', DEMO_PW)
            s.shot(pg, '36-pharmacy-dashboard', full=True, max_height=1700)
            pg.goto(BASE + '/pharmacy/index.php'); s.shot(pg, '37-pharmacy-queue', full=True, max_height=1200)
            pg.goto(BASE + '/pharmacy/dispense.php?id=' + str(info['rx'])); s.shot(pg, '38-dispense', full=True, max_height=1400)
            pg.goto(BASE + '/pharmacy/stock.php'); s.shot(pg, '39-stock', full=True, max_height=1700)
            pg.goto(BASE + '/pharmacy/batches.php'); s.shot(pg, '40-batches', full=True, max_height=1700)
            pg.goto(BASE + '/pharmacy/receive.php'); s.shot(pg, '41-receive-stock', full=True, max_height=1200)
            pg.goto(BASE + '/pharmacy/medicines.php'); s.shot(pg, '42-medicine-list', full=True, max_height=1500)
            pg.goto(BASE + '/pharmacy/medicine.php?id=' + str(info['medicine_edit'])); s.shot(pg, '43-medicine-edit', full=True, max_height=1300)
            ctx.close()

            # ---------- cashier ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'cashier1', DEMO_PW)
            s.shot(pg, '44-cashier-dashboard', full=True)
            pg.goto(BASE + '/billing/index.php'); s.shot(pg, '45-invoice-list', full=True, max_height=1700)
            pg.goto(BASE + '/billing/invoice.php?id=' + str(info['invoice_unpaid']))
            pg.select_option('#f_method', 'M-Pesa'); pg.fill('#f_amount', '100'); pg.fill('#f_reference', 'BAD')
            pg.click('#pay button[type=submit]'); pg.wait_for_load_state('networkidle')
            s.shot(pg, '46-payment-error', full=True, max_height=2000)
            pg.goto(BASE + '/billing/invoice.php?id=' + str(info['invoice_part'])); s.shot(pg, '47-invoice-partial', full=True, max_height=2000)
            pg.goto(BASE + '/billing/receipt.php?id=' + str(info['payment'])); s.shot(pg, '48-receipt', full=True, max_height=1200)
            pg.goto(BASE + '/billing/invoice.php?id=' + str(info['invoice_paid']))
            pg.emulate_media(media='print'); s.shot(pg, '49-invoice-print', full=True, max_height=1700)
            pg.emulate_media(media='screen')
            ctx.close()

            # ---------- manager ----------
            ctx = s.context()
            pg = ctx.new_page()
            s.login(pg, 'manager1', DEMO_PW)
            s.shot(pg, '50-manager-dashboard', full=True, max_height=1800)
            q = f'?from={info["range_from"]}&to={info["today"]}'
            pg.goto(BASE + '/reports/attendance.php' + q); s.shot(pg, '51-report-attendance', full=True, max_height=2000)
            pg.goto(BASE + '/reports/revenue.php' + q); s.shot(pg, '52-report-revenue', full=True, max_height=2000)
            pg.goto(BASE + '/reports/stock.php' + q); s.shot(pg, '53-report-stock', full=True, max_height=1900)
            pg.goto(BASE + '/reports/lab.php' + q); s.shot(pg, '54-report-lab', full=True, max_height=1700)
            ctx.close()

            # ---------- small screen ----------
            ctx = s.context(width=390, height=780, mobile=True)
            pg = ctx.new_page()
            s.login(pg, 'reception1', DEMO_PW)
            s.shot(pg, '55-mobile-dashboard')
            pg.click('[data-toggle-menu]')
            s.shot(pg, '56-mobile-menu')
            ctx.close()
            browser.close()
        print(s.n, 'screenshots')
    finally:
        server.terminate()


if __name__ == '__main__':
    main()
