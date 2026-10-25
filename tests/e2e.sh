#!/usr/bin/env bash
# UB-HMS end-to-end tests.
#
#   bash tests/e2e.sh
#
# Builds a fresh database (uhai_bora_hms_e2e) with synthetic demo data, starts
# PHP's built-in web server on 127.0.0.1:$E2E_PORT, then drives the real pages
# with curl exactly as a browser would (cookies, CSRF tokens, redirects).
# Results are printed and saved to tests/output/e2e-results.txt.
#
# Environment: PHP_BIN (default php), E2E_PORT (8089), UBHMS_DB_HOST/PORT/USER/PASS.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
PORT="${E2E_PORT:-8089}"
B="http://127.0.0.1:$PORT"
export UBHMS_DB_NAME="${UBHMS_E2E_DB:-uhai_bora_hms_e2e}"
export UBHMS_BCRYPT_COST=4
DEMO_PW='UbHms#Demo2026'
ADMIN_USER='sysadmin'
ADMIN_PW1='Setup#Hospital2026'
ADMIN_PW2='Hospital#Ops2026x'
J="$(mktemp -d)"
OUT="$ROOT/tests/output"
mkdir -p "$OUT"
: > "$OUT/e2e-results.txt"
pass=0
fail=0

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  rm -rf "$J"
}
trap cleanup EXIT

record() { # status id description
  echo "$1  $2  $3" | tee -a "$OUT/e2e-results.txt"
  if [ "$1" = "PASS" ]; then pass=$((pass+1)); else fail=$((fail+1)); fi
}
check() { # id description expression
  if eval "$3"; then record PASS "$1" "$2"; return 0; else record FAIL "$1" "$2"; return 1; fi
}

# ---------- HTTP helpers (one cookie jar per "browser": admin, rec, doc ...) ----------
get()   { curl -s -b "$J/$1" -c "$J/$1" "$B/$2"; }
code()  { curl -s -o /dev/null -w '%{http_code}' -b "$J/$1" -c "$J/$1" "$B/$2"; }
token() { get "$1" "${2:-index.php}" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
# post JAR PATH [curl args...] -> prints "HTTP_CODE REDIRECT_URL"; body saved in $J/body
post()  { local jar=$1 path=$2; shift 2; curl -s -o "$J/body" -w '%{http_code} %{redirect_url}' -b "$J/$jar" -c "$J/$jar" "$B/$path" "$@"; }
# post a form with a fresh token taken from page TOKENPAGE
form()  { local jar=$1 tokpage=$2 path=$3; shift 3; local t; t=$(token "$jar" "$tokpage"); post "$jar" "$path" --data-urlencode "_csrf=$t" "$@"; }
login() { local jar=$1 user=$2 pw=$3; local t; t=$(token "$jar" login.php); post "$jar" login.php --data-urlencode "_csrf=$t" --data-urlencode "username=$user" --data-urlencode "password=$pw"; }
body()  { cat "$J/body"; }
# first patient id whose OP number appears on a page: patient_id_from_view "<html>"
last_id() { grep -o 'id=[0-9]*' | tail -1 | cut -d= -f2; }

# ---------- set-up ----------
echo "== Setting up database $UBHMS_DB_NAME"
"$PHP_BIN" "$ROOT/database/install.php" --force --demo=basic --admin-user="$ADMIN_USER" --admin-pass="$ADMIN_PW1" > "$J/install.log" 2>&1 || { cat "$J/install.log"; echo "Install failed"; exit 2; }
if [ "${E2E_LEAST_PRIVILEGE:-0}" = "1" ]; then
  # Run the web application as the restricted database account, as a real installation should.
  "$PHP_BIN" "$ROOT/database/create_app_user.php" --user=ubhms_app --password='E2e#AppUser2026!' --host=localhost > "$J/appuser.log" 2>&1 || { cat "$J/appuser.log"; echo "App user set-up failed"; exit 2; }
  export UBHMS_DB_USER=ubhms_app UBHMS_DB_PASS='E2e#AppUser2026!'
  echo "== The web server will connect as the least-privilege account ubhms_app"
fi
echo "== Starting web server on $B"
"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$ROOT" > "$J/server.log" 2>&1 &
SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/login.php" && break; sleep 0.3; done

# =====================================================================
# SPRINT 1: authentication, accounts, patient registration
# =====================================================================
sec_sprint1() {
  echo; echo "== Sprint 1: authentication, accounts and patients"

  local r h t
  r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/patients/index.php")
  check E2E-001 "Anonymous visitor is redirected to the sign-in page" '[[ "$r" == 303* && "$r" == *login.php* ]]'

  h=$(curl -s -D - -o /dev/null "$B/login.php")
  check E2E-002 "Security headers are sent (CSP, frame, nosniff)" '[[ "$h" == *"Content-Security-Policy"* && "$h" == *"X-Frame-Options: DENY"* && "$h" == *"nosniff"* ]]'

  r=$(post anon login.php --data-urlencode "username=$ADMIN_USER" --data-urlencode "password=$ADMIN_PW1")
  check E2E-003 "Sign-in without a CSRF token is refused (419)" '[[ "$r" == 419* ]]'

  r=$(login bad "$ADMIN_USER" 'Wrong#Password1')
  check E2E-004 "Wrong password is refused with a neutral message" '[[ "$r" == 200* ]] && body | grep -q "username or password is not correct"'
  r=$(login bad nobody.at.all 'Wrong#Password1')
  check E2E-005 "Unknown username gets the same neutral message" 'body | grep -q "username or password is not correct"'

  # administrator: first sign-in forces a new password
  r=$(login admin "$ADMIN_USER" "$ADMIN_PW1")
  check E2E-006 "First sign-in sends the administrator to change the password" '[[ "$r" == 303* && "$r" == *password.php* ]]'
  r=$(code admin patients/index.php)
  check E2E-007 "Pages are blocked until the password is changed" '[[ "$r" == 303 ]]'
  r=$(form admin password.php password.php --data-urlencode "current_password=$ADMIN_PW1" --data-urlencode "new_password=weak" --data-urlencode "confirm_password=weak")
  check E2E-008 "A weak new password is rejected with reasons" 'body | grep -q "at least 10 characters"'
  r=$(form admin password.php password.php --data-urlencode "current_password=$ADMIN_PW1" --data-urlencode "new_password=$ADMIN_PW2" --data-urlencode "confirm_password=$ADMIN_PW2")
  check E2E-009 "A strong new password is accepted and the dashboard opens" '[[ "$r" == 303* ]] && get admin index.php | grep -q "Welcome"'

  # role-based access
  h=$(get admin index.php)
  check E2E-010 "Administrator menu shows user accounts and audit trail but no patient pages" '[[ "$h" == *"User accounts"* && "$h" == *"Audit trail"* && "$h" != *"Search patients"* ]]'
  check E2E-011 "Administrator cannot open patient records (403)" '[[ "$(code admin patients/index.php)" == 403 ]]'

  r=$(login rec reception1 "$DEMO_PW")
  check E2E-012 "Receptionist signs in with the demo account" '[[ "$r" == 303* && "$r" == *index.php* ]]'
  check E2E-013 "Receptionist cannot open user accounts (403)" '[[ "$(code rec admin/users.php)" == 403 ]]'
  check E2E-014 "Receptionist cannot open the audit trail (403)" '[[ "$(code rec admin/audit.php)" == 403 ]]'

  # create a user as admin
  r=$(form admin admin/user.php admin/user.php --data-urlencode "full_name=Test Nurse Njeri" --data-urlencode "username=nurse.test" --data-urlencode "role=nurse" --data-urlencode "phone=0712345678")
  h=$(get admin "admin/user.php?$(echo "$r" | grep -o 'id=[0-9]*')")   # the one-time password is shown once, on this page
  check E2E-015 "Administrator creates an account and sees a one-time password" '[[ "$r" == 303* && "$h" == *"One-time password"* ]]'
  local temp
  temp=$(echo "$h" | grep -o 'One-time password: [A-Za-z0-9]*' | head -1 | sed 's/.*: //')
  r=$(login nurseT nurse.test "$temp")
  check E2E-016 "The new user must change the one-time password at first sign-in" '[[ "$r" == *password.php* ]]'
  r=$(form admin admin/user.php admin/user.php --data-urlencode "full_name=Dup" --data-urlencode "username=nurse.test" --data-urlencode "role=nurse")
  check E2E-017 "A duplicate username is refused" 'get admin admin/user.php | grep -q "already taken"'

  # patient registration
  local form_data=(--data-urlencode "first_name=Amani" --data-urlencode "last_name=Kiplagat" --data-urlencode "sex=Male" --data-urlencode "date_of_birth=1985-06-20" --data-urlencode "national_id=31234567" --data-urlencode "phone=0711 222 333" --data-urlencode "residence=Kasarani")
  r=$(form rec patients/register.php patients/register.php "${form_data[@]}")
  check E2E-018 "Receptionist registers a patient and receives an outpatient number" '[[ "$r" == 303* && "$r" == *patients/view.php* ]]'
  local pid; pid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  h=$(get rec "patients/view.php?id=$pid")
  check E2E-019 "The record shows an outpatient number in the OPyy-nnnnn format" 'echo "$h" | grep -Eq "OP[0-9]{2}-[0-9]{5}"'

  r=$(form rec patients/register.php patients/register.php "${form_data[@]}")
  check E2E-020 "The same ID number cannot be registered again" 'body | grep -q "already registered"'
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=Amani" --data-urlencode "last_name=Kiplagat" --data-urlencode "sex=Male" --data-urlencode "date_of_birth=1985-06-20")
  check E2E-021 "Same name and date of birth is flagged for confirmation" 'body | grep -q "may already be registered"'
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=" --data-urlencode "last_name=" --data-urlencode "sex=" --data-urlencode "date_of_birth=")
  check E2E-022 "Missing required fields show an error summary" 'body | grep -q "Please correct the following"'

  # XSS and injection
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=Xss" --data-urlencode "last_name=Tester" --data-urlencode "sex=Female" --data-urlencode "date_of_birth=1990-01-01" --data-urlencode "residence=<script>alert(1)</script>")
  local xid; xid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  h=$(get rec "patients/view.php?id=$xid")
  check E2E-023 "Stored text is escaped on screen (no script injection)" '[[ "$h" == *"&lt;script&gt;alert(1)&lt;/script&gt;"* && "$h" != *"<script>alert(1)"* ]]'
  h=$(get rec "patients/index.php?q=%27+OR+1%3D1+--+")
  check E2E-024 "SQL injection text in the search box finds nothing" '[[ "$h" == *"0 patients found"* ]]'

  # search
  h=$(get rec "patients/index.php?q=Kiplagat")
  check E2E-025 "Search by name finds the patient" '[[ "$h" == *"Amani Kiplagat"* ]]'
  h=$(get rec "patients/index.php?q=0711222333")
  check E2E-026 "Search by phone number finds the patient" '[[ "$h" == *"Amani Kiplagat"* ]]'
  h=$(get rec "patients/index.php?q=31234567")
  check E2E-027 "Search by ID number finds the patient" '[[ "$h" == *"Amani Kiplagat"* ]]'

  # edit
  r=$(form rec "patients/edit.php?id=$pid" "patients/edit.php?id=$pid" "${form_data[@]/Kasarani/Ruaka}")
  check E2E-028 "A receptionist can correct patient details" '[[ "$r" == 303* ]] && get rec "patients/view.php?id=$pid" | grep -q "Ruaka"'

  # CSRF on state-changing pages
  r=$(post rec "patients/edit.php?id=$pid" "${form_data[@]}")
  check E2E-029 "Saving a patient without a CSRF token is refused" '[[ "$r" == 419* ]]'
  curl -s -o /dev/null -b "$J/rec" -c "$J/rec" "$B/logout.php"
  check E2E-030 "A plain link (GET) cannot sign a user out" '[[ "$(code rec patients/index.php)" == 200 ]]'

  # audit trail
  h=$(get admin admin/audit.php)
  check E2E-031 "Audit trail shows sign-ins, patient registration and views" '[[ "$h" == *"login"* && "$h" == *"create"* && "$h" == *"view"* ]]'
  check E2E-032 "Audit trail records the computer address" '[[ "$h" == *"127.0.0.1"* ]]'
  check E2E-033 "Audit trail never contains the patient names or phone numbers" '[[ "$h" != *"Kiplagat"* && "$h" != *"0711"* ]]'

  # open redirect
  r=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J/rec" -c "$J/rec" "$B/login.php?next=//evil.example/x")
  check E2E-034 "Sign-in page does not redirect to other websites" '[[ "$r" != *evil.example* ]]'

  # logout
  r=$(form rec index.php logout.php)
  check E2E-035 "Signing out ends the session" '[[ "$r" == 303* && "$(code rec patients/index.php)" == 303 ]]'

  # lockout over HTTP
  for i in 1 2 3 4 5; do login lock reception2 "Wrong#Password$i" >/dev/null; done
  r=$(login lock reception2 "$DEMO_PW")
  check E2E-036 "An account locked by five wrong passwords refuses the right password" 'body | grep -q "locked"'
}

# =====================================================================
# SPRINT 2: appointments, check-in, triage, consultation
# =====================================================================

# Run a line of PHP against the e2e database (used to set up data a browser cannot, such as a booking for earlier today).
php_eval() {
  UBHMS_DB_NAME="$UBHMS_DB_NAME" "$PHP_BIN" -r 'define("APP_ROOT", $argv[1]); require APP_ROOT . "/includes/core.php"; '"$2" "$ROOT" 2>&1
}
next_workday() { # prints the next date that is not a Sunday, at least $1 days ahead
  local d=${1:-1}
  while [ "$(date -d "+$d day" +%u)" = "7" ]; do d=$((d+1)); done
  date -d "+$d day" +%F
}

sec_sprint2() {
  echo; echo "== Sprint 2: appointments, check-in, triage and consultation"
  local r h pid pid2 docid day

  login rec reception1 "$DEMO_PW" >/dev/null
  login nurse nurse1 "$DEMO_PW" >/dev/null
  login doc doctor1 "$DEMO_PW" >/dev/null

  # two patients to work with
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=Baraka" --data-urlencode "last_name=Mwiti" --data-urlencode "sex=Male" --data-urlencode "date_of_birth=1990-01-01" --data-urlencode "national_id=32000001" --data-urlencode "allergies=Penicillin")
  pid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=Zawadi" --data-urlencode "last_name=Chepkoech" --data-urlencode "sex=Female" --data-urlencode "date_of_birth=1995-03-03" --data-urlencode "national_id=32000002")
  pid2=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)

  # --- appointments ---
  h=$(get rec "appointments/book.php?patient_id=$pid")
  docid=$(echo "$h" | grep -o '<option value="[0-9]*">Dr. Samuel Kamau' | grep -o '[0-9]*' | head -1)
  day=$(next_workday 2)
  h=$(get rec "appointments/book.php?patient_id=$pid&doctor_id=$docid&date=$day")
  check E2E-040 "Booking page shows 27 free time slots for a working day" '[[ "$(echo "$h" | grep -o "name=\"start_time\"" | wc -l)" -eq 27 ]]'

  r=$(form rec "appointments/book.php?patient_id=$pid&doctor_id=$docid&date=$day" "appointments/book.php?patient_id=$pid" --data-urlencode "patient_id=$pid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$day" --data-urlencode "start_time=10:00" --data-urlencode "reason=Review")
  check E2E-041 "Receptionist books an appointment and returns to the diary" '[[ "$r" == 303* && "$r" == *appointments/index.php* ]]'
  h=$(get rec "appointments/index.php?date=$day")
  check E2E-042 "The diary lists the booked appointment" '[[ "$h" == *"Baraka Mwiti"* && "$h" == *"10:00"* && "$h" == *"Booked"* ]]'

  r=$(form rec "appointments/book.php?patient_id=$pid2" "appointments/book.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$day" --data-urlencode "start_time=10:00")
  check E2E-043 "A second booking for the same doctor and time is refused" 'body | grep -q "already booked"'
  r=$(form rec "appointments/book.php?patient_id=$pid2" "appointments/book.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$day" --data-urlencode "start_time=10:20")
  check E2E-044 "The next slot is still free and can be booked" '[[ "$r" == 303* ]]'
  r=$(form rec "appointments/book.php?patient_id=$pid2" "appointments/book.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "doctor_id=$docid" --data-urlencode "date=2020-01-06" --data-urlencode "start_time=10:00")
  check E2E-045 "A date in the past is refused" 'body | grep -q "already passed"'
  local sunday; sunday=$(date -d "next sunday" +%F)
  r=$(form rec "appointments/book.php?patient_id=$pid2" "appointments/book.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$sunday" --data-urlencode "start_time=10:00")
  check E2E-046 "Sunday is refused because the clinic is closed" 'body | grep -q "closed"'

  # cancel
  local aid; aid=$(get rec "appointments/index.php?date=$day" | grep -B30 "Zawadi" | grep -o 'name="id" value="[0-9]*"' | tail -1 | grep -o '[0-9]*')
  r=$(form rec "appointments/index.php?date=$day" appointments/action.php --data-urlencode "id=$aid" --data-urlencode "status=Cancelled" --data-urlencode "back=$day")
  check E2E-047 "An appointment can be cancelled from the diary" '[[ "$r" == 303* ]] && get rec "appointments/index.php?date=$day" | grep -q "Cancelled"'
  r=$(post rec appointments/action.php --data-urlencode "id=$aid" --data-urlencode "status=Cancelled")
  check E2E-048 "Changing an appointment without a CSRF token is refused" '[[ "$r" == 419* ]]'

  # --- check-in from an appointment made for earlier today (set up directly) ---
  php_eval x 'db_exec("INSERT INTO appointments (patient_id, doctor_id, appointment_date, start_time, end_time, reason, booked_by) VALUES (?, ?, CURDATE(), \"06:00:00\", \"06:20:00\", \"E2E check-in\", ?)", ['"$pid2"', '"$docid"', '"$(php_eval x 'echo db_value("SELECT id FROM users WHERE username=\"reception1\"");')"']);' >/dev/null
  h=$(get rec "visits/checkin.php?patient_id=$pid2")
  check E2E-049 "Check-in offers today's booked appointment" '[[ "$h" == *"Booked appointment at"* && "$h" == *"06:00"* ]]'
  local appt; appt=$(echo "$h" | grep -o 'name="appointment_id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
  r=$(form rec "visits/checkin.php?patient_id=$pid2" "visits/checkin.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "appointment_id=$appt")
  check E2E-050 "Checking in from the appointment opens a visit and goes to the triage queue" '[[ "$r" == 303* && "$r" == *stage=triage* ]]'
  h=$(get rec "appointments/index.php?date=$(date +%F)&doctor_id=")
  check E2E-051 "The appointment now shows as Attended" '[[ "$h" == *"Attended"* ]]'
  r=$(form rec "visits/checkin.php?patient_id=$pid2" "visits/checkin.php?patient_id=$pid2" --data-urlencode "patient_id=$pid2" --data-urlencode "appointment_id=")
  check E2E-052 "A patient with an open visit cannot be checked in twice" 'body | grep -q "already has an open visit"'

  # --- walk-in and triage ---
  r=$(form rec "visits/checkin.php?patient_id=$pid" "visits/checkin.php?patient_id=$pid" --data-urlencode "patient_id=$pid" --data-urlencode "appointment_id=" --data-urlencode "reason=Fever and cough")
  check E2E-053 "A walk-in patient is checked in without an appointment" '[[ "$r" == 303* ]]'
  h=$(get nurse "visits/queue.php?stage=triage")
  check E2E-054 "The nurse's triage queue lists both patients with an allergy flag" '[[ "$h" == *"Baraka Mwiti"* && "$h" == *"Zawadi Chepkoech"* && "$h" == *"Allergy"* ]]'
  local vid; vid=$(echo "$h" | grep -A10 "Baraka Mwiti" | grep -o 'triage.php?visit=[0-9]*' | head -1 | cut -d= -f2)
  r=$(form nurse "visits/triage.php?visit=$vid" "visits/triage.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=triage" --data-urlencode "temperature=60" --data-urlencode "pulse=5")
  check E2E-055 "Impossible vital signs are rejected with messages" 'body | grep -q "Temperature" && body | grep -q "Pulse"'
  r=$(form nurse "visits/triage.php?visit=$vid" "visits/triage.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=triage" --data-urlencode "temperature=38.6" --data-urlencode "pulse=104" --data-urlencode "bp_systolic=124" --data-urlencode "bp_diastolic=80" --data-urlencode "spo2=97" --data-urlencode "weight_kg=70" --data-urlencode "notes=Dry cough")
  check E2E-056 "The nurse saves triage" '[[ "$r" == 303* ]]'
  r=$(form nurse "visits/triage.php?visit=$vid" "visits/triage.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=service" --data-urlencode "service_id=$(php_eval x 'echo db_value("SELECT id FROM services WHERE name=\"Injection administration\"");')" --data-urlencode "quantity=1")
  check E2E-057 "The nurse records a procedure" '[[ "$r" == 303* ]] && get nurse "visits/triage.php?visit=$vid" | grep -q "Injection administration"'

  # --- consultation ---
  h=$(get doc "visits/queue.php?stage=consult")
  check E2E-058 "The doctor's queue shows the triaged patient" '[[ "$h" == *"Baraka Mwiti"* ]]'
  h=$(get doc "visits/consult.php?visit=$vid")
  check E2E-059 "The consultation page shows triage, allergy warning and the ICD-10 list" '[[ "$h" == *"38.6"* && "$h" == *"Penicillin"* && "$h" == *"icd-list"* && "$h" == *"J06.9"* ]]'
  local svc; svc=$(echo "$h" | grep -o '<option value="[0-9]*"[^>]*>General consultation' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*' | head -1)
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=consultation" --data-urlencode "service_id=$svc" --data-urlencode "complaint=" --data-urlencode "diagnosis=")
  check E2E-060 "A consultation without complaint and diagnosis is refused" 'body | grep -q "Please correct the following"'
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=consultation" --data-urlencode "service_id=$svc" --data-urlencode "complaint=Cough <b>three</b> days" --data-urlencode "diagnosis=Upper respiratory infection" --data-urlencode "icd10_code=J06.9" --data-urlencode "plan=Fluids and rest")
  check E2E-061 "The doctor saves the consultation" '[[ "$r" == 303* ]]'
  h=$(get doc "visits/consult.php?visit=$vid")
  check E2E-062 "Typed markup in the complaint is shown as text, not run" '[[ "$h" == *"&lt;b&gt;three&lt;/b&gt;"* && "$h" != *"<b>three</b>"* ]]'
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=consultation" --data-urlencode "service_id=$svc" --data-urlencode "complaint=Cough" --data-urlencode "diagnosis=Test" --data-urlencode "icd10_code=ZZ99")
  check E2E-063 "An ICD-10 code that is not in the hospital list is refused" 'body | grep -q "not in the hospital list"'
  r=$(post doc "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=consultation")
  check E2E-064 "Saving a consultation without a CSRF token is refused" '[[ "$r" == 419* ]]'

  # --- access control for the clinical flow ---
  check E2E-065 "A nurse cannot open the consultation page (403)" '[[ "$(code nurse "visits/consult.php?visit=$vid")" == 403 ]]'
  check E2E-066 "A receptionist cannot open the triage page (403)" '[[ "$(code rec "visits/triage.php?visit=$vid")" == 403 ]]'
  check E2E-067 "A doctor cannot book appointments (403)" '[[ "$(code doc appointments/book.php)" == 403 ]]'
  h=$(get rec "patients/view.php?id=$pid")
  check E2E-068 "The receptionist sees the visit but not the diagnosis" '[[ "$h" == *"Visits"* && "$h" != *"Upper respiratory infection"* ]]'

  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=close")
  check E2E-069 "The doctor closes the visit" '[[ "$r" == 303* ]]'
  h=$(get doc "patients/view.php?id=$pid")
  check E2E-070 "The doctor's patient record shows the diagnosis in the visit history" '[[ "$h" == *"Upper respiratory infection"* && "$h" == *"J06.9"* ]]'
  h=$(get admin admin/audit.php)
  check E2E-071 "The audit trail records check-in, triage and consultation" '[[ "$h" == *"checkin"* && "$h" == *"triage"* && "$h" == *"consultation"* ]]'
}

# =====================================================================
# SPRINT 3: laboratory, prescriptions, dispensing, stock
# =====================================================================

# Register a patient, check in, triage and let doctor1 record a consultation. Sets NEW_PID and NEW_VID.
e2e_new_visit() { # first last national_id
  local r h svc
  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=$1" --data-urlencode "last_name=$2" --data-urlencode "sex=Female" --data-urlencode "date_of_birth=1993-07-07" --data-urlencode "national_id=$3" --data-urlencode "allergies=$4")
  NEW_PID=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  form rec "visits/checkin.php?patient_id=$NEW_PID" "visits/checkin.php?patient_id=$NEW_PID" --data-urlencode "patient_id=$NEW_PID" --data-urlencode "appointment_id=" --data-urlencode "reason=Fever" >/dev/null
  h=$(get nurse "visits/queue.php?stage=triage")
  NEW_VID=$(echo "$h" | grep -A10 "$1 $2" | grep -o 'triage.php?visit=[0-9]*' | head -1 | cut -d= -f2)
  form nurse "visits/triage.php?visit=$NEW_VID" "visits/triage.php?visit=$NEW_VID" --data-urlencode "visit=$NEW_VID" --data-urlencode "action=triage" --data-urlencode "temperature=38.2" --data-urlencode "pulse=90" >/dev/null
  h=$(get doc "visits/consult.php?visit=$NEW_VID")
  svc=$(echo "$h" | grep -o '<option value="[0-9]*"[^>]*>General consultation' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*' | head -1)
  form doc "visits/consult.php?visit=$NEW_VID" "visits/consult.php?visit=$NEW_VID" --data-urlencode "visit=$NEW_VID" --data-urlencode "action=consultation" --data-urlencode "service_id=$svc" --data-urlencode "complaint=Fever and headache" --data-urlencode "diagnosis=Suspected malaria" --data-urlencode "icd10_code=B54" >/dev/null
}

sec_sprint3() {
  echo; echo "== Sprint 3: laboratory, prescriptions, dispensing and stock"
  local r h vid pid testid medid medid2 d60 d400 d200 d_old

  login lab lab1 "$DEMO_PW" >/dev/null
  login pharm pharmacist1 "$DEMO_PW" >/dev/null
  login doc2 doctor2 "$DEMO_PW" >/dev/null
  login cash cashier1 "$DEMO_PW" >/dev/null
  d60=$(date -d "+60 days" +%F); d200=$(date -d "+200 days" +%F); d400=$(date -d "+400 days" +%F); d_old=$(date -d "-10 days" +%F)

  # --- pharmacy set-up: medicines and stock ---
  r=$(form pharm pharmacy/medicine.php pharmacy/medicine.php --data-urlencode "name=Paracetamol" --data-urlencode "strength=500 mg" --data-urlencode "form=Tablet" --data-urlencode "unit=tablet" --data-urlencode "unit_price=5.00" --data-urlencode "reorder_level=50" --data-urlencode "is_active=1")
  check E2E-080 "The pharmacist adds a medicine to the list" '[[ "$r" == 303* ]] && get pharm pharmacy/medicines.php | grep -q "Paracetamol"'
  r=$(form pharm pharmacy/medicine.php pharmacy/medicine.php --data-urlencode "name=Zincex" --data-urlencode "strength=20 mg" --data-urlencode "form=Dispersible" --data-urlencode "unit=tablet" --data-urlencode "unit_price=10.00" --data-urlencode "reorder_level=20" --data-urlencode "is_active=1")
  r=$(form pharm pharmacy/medicine.php pharmacy/medicine.php --data-urlencode "name=<script>x</script>" --data-urlencode "unit_price=1" --data-urlencode "reorder_level=1" --data-urlencode "is_active=1")
  check E2E-081 "Medicine names are escaped on screen" 'h=$(get pharm pharmacy/medicines.php); [[ "$h" == *"&lt;script&gt;x&lt;/script&gt;"* && "$h" != *"<script>x</script>"* ]]'
  r=$(form pharm pharmacy/medicine.php pharmacy/medicine.php --data-urlencode "name=Bad" --data-urlencode "unit_price=abc" --data-urlencode "reorder_level=x")
  check E2E-082 "An invalid price is refused" 'body | grep -q "Enter the price per unit"'

  h=$(get pharm pharmacy/receive.php)
  medid=$(echo "$h" | grep -o '<option value="[0-9]*">Paracetamol' | grep -o '[0-9]*' | head -1)
  medid2=$(echo "$h" | grep -o '<option value="[0-9]*">Zincex' | grep -o '[0-9]*' | head -1)
  r=$(form pharm pharmacy/receive.php pharmacy/receive.php --data-urlencode "medicine_id=$medid" --data-urlencode "batch_no=par-a1" --data-urlencode "expiry_date=$d60" --data-urlencode "quantity=30")
  check E2E-083 "The pharmacist receives a batch that expires in 60 days" '[[ "$r" == 303* ]]'
  r=$(form pharm pharmacy/receive.php pharmacy/receive.php --data-urlencode "medicine_id=$medid" --data-urlencode "batch_no=PAR-B2" --data-urlencode "expiry_date=$d400" --data-urlencode "quantity=500")
  r=$(form pharm pharmacy/receive.php pharmacy/receive.php --data-urlencode "medicine_id=$medid2" --data-urlencode "batch_no=ZN-1" --data-urlencode "expiry_date=$d200" --data-urlencode "quantity=10")
  r=$(form pharm pharmacy/receive.php pharmacy/receive.php --data-urlencode "medicine_id=$medid" --data-urlencode "batch_no=OLD1" --data-urlencode "expiry_date=$d_old" --data-urlencode "quantity=10")
  check E2E-084 "Stock that has already expired cannot be received" 'body | grep -q "already expired"'
  h=$(get pharm pharmacy/stock.php)
  check E2E-085 "Stock page shows usable stock of 530 Paracetamol and Zincex as Low" '[[ "$h" == *"530"* && "$h" == *"Zincex"* && "$h" == *"badge-low"* ]]'
  h=$(get pharm index.php)
  check E2E-086 "The pharmacist dashboard warns about low stock and the batch expiring soon" '[[ "$h" == *"Reorder and use soon"* && "$h" == *"Zincex"* && "$h" == *"PAR-A1"* ]]'
  h=$(get pharm pharmacy/batches.php)
  check E2E-087 "Batches are listed with their expiry state" '[[ "$h" == *"PAR-A1"* && "$h" == *"badge-expiring"* && "$h" == *"PAR-B2"* ]]'

  # --- a patient is seen by doctor1 ---
  e2e_new_visit Imani Wekesa 32000003 "Penicillin"; vid=$NEW_VID; pid=$NEW_PID
  h=$(get doc "visits/consult.php?visit=$vid")
  check E2E-088 "The consultation page offers test requests and prescribing" '[[ "$h" == *"Request tests"* && "$h" == *"Prescribe"* && "$h" == *"Paracetamol 500 mg Tablet (530 in stock)"* ]]'

  # --- laboratory round trip ---
  testid=$(echo "$h" | grep -o 'name="test_ids\[\]" value="[0-9]*"> Malaria' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=add_lab" --data-urlencode "test_ids[]=$testid" --data-urlencode "clinical_notes=Fever 3 days")
  check E2E-089 "The doctor requests a malaria test" '[[ "$r" == 303* ]] && get doc "visits/consult.php?visit=$vid" | grep -q "Waiting for the laboratory"'
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=add_lab" --data-urlencode "test_ids[]=$testid")
  check E2E-090 "Requesting the same pending test again is refused" 'get doc "visits/consult.php?visit=$vid" | grep -q "already been requested"'

  h=$(get lab lab/index.php)
  check E2E-091 "The laboratory worklist shows the test, the patient and the requesting doctor" '[[ "$h" == *"Malaria test"* && "$h" == *"Imani Wekesa"* && "$h" == *"Dr. Samuel Kamau"* ]]'
  local lid; lid=$(echo "$h" | grep -A12 "Imani Wekesa" | grep -o 'lab/result.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
  r=$(form lab lab/index.php lab/index.php --data-urlencode "id=$lid")
  check E2E-092 "The technologist starts the test" '[[ "$r" == 303* ]] && get lab lab/index.php | grep -q "In progress"'
  r=$(form lab "lab/result.php?id=$lid" "lab/result.php?id=$lid" --data-urlencode "id=$lid" --data-urlencode "result=")
  check E2E-093 "An empty result is refused" 'body | grep -q "Enter the result"'
  r=$(form lab "lab/result.php?id=$lid" "lab/result.php?id=$lid" --data-urlencode "id=$lid" --data-urlencode "result=Malaria parasites seen (+)" --data-urlencode "is_abnormal=1")
  check E2E-094 "The technologist enters the result" '[[ "$r" == 303* ]]'
  h=$(get doc lab/results.php)
  check E2E-095 "The result appears in the requesting doctor's list" '[[ "$h" == *"Malaria parasites seen (+)"* && "$h" == *"Abnormal"* ]]'
  h=$(get doc "visits/consult.php?visit=$vid")
  check E2E-096 "The result also appears on the consultation page" '[[ "$h" == *"Malaria parasites seen (+)"* ]]'
  h=$(get doc2 lab/results.php)
  check E2E-097 "Another doctor does not see this doctor's results" '[[ "$h" != *"Malaria parasites seen"* ]]'

  # --- prescribing and dispensing ---
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=add_rx" \
      --data-urlencode "rx[0][medicine_id]=$medid" --data-urlencode "rx[0][quantity]=40" --data-urlencode "rx[0][dosage]=1 tablet" --data-urlencode "rx[0][frequency]=three times a day" --data-urlencode "rx[0][duration]=5 days" \
      --data-urlencode "rx[1][medicine_id]=$medid2" --data-urlencode "rx[1][quantity]=50" --data-urlencode "rx[1][dosage]=1 tablet" --data-urlencode "rx[1][frequency]=daily" --data-urlencode "rx[1][duration]=10 days")
  check E2E-098 "The doctor prescribes two medicines" '[[ "$r" == 303* ]] && get doc "visits/consult.php?visit=$vid" | grep -q "Pending"'
  r=$(form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=add_rx" --data-urlencode "rx[0][medicine_id]=$medid" --data-urlencode "rx[0][quantity]=0" --data-urlencode "rx[0][dosage]=1" --data-urlencode "rx[0][frequency]=daily")
  check E2E-099 "A prescription line with quantity zero is refused" 'get doc "visits/consult.php?visit=$vid" | grep -q "whole number from 1 to 9999"'

  h=$(get pharm pharmacy/index.php)
  check E2E-100 "The prescription is waiting in the pharmacy queue with an allergy flag" '[[ "$h" == *"Imani Wekesa"* && "$h" == *"Allergy"* ]]'
  local rxid; rxid=$(echo "$h" | grep -o 'dispense.php?id=[0-9]*' | tail -1 | grep -o '[0-9]*$')
  h=$(get pharm "pharmacy/dispense.php?id=$rxid")
  local item1 item2; item1=$(echo "$h" | grep -o 'name="qty\[[0-9]*\]"' | head -1 | grep -o '[0-9]*'); item2=$(echo "$h" | grep -o 'name="qty\[[0-9]*\]"' | tail -1 | grep -o '[0-9]*')
  check E2E-101 "The dispensing page shows what to give and the stock available" '[[ "$h" == *"three times a day"* && "$h" == *"530"* ]]'
  r=$(post pharm "pharmacy/dispense.php?id=$rxid" --data-urlencode "id=$rxid" --data-urlencode "qty[$item1]=40")
  check E2E-102 "Dispensing without a CSRF token is refused" '[[ "$r" == 419* ]]'
  r=$(form pharm "pharmacy/dispense.php?id=$rxid" "pharmacy/dispense.php?id=$rxid" --data-urlencode "id=$rxid" --data-urlencode "qty[$item1]=40" --data-urlencode "qty[$item2]=50")
  check E2E-103 "Dispensing more Zincex than is in stock is refused and nothing is deducted" 'body | grep -q "only 10 in stock" && get pharm pharmacy/stock.php | grep -q "530"'
  r=$(form pharm "pharmacy/dispense.php?id=$rxid" "pharmacy/dispense.php?id=$rxid" --data-urlencode "id=$rxid" --data-urlencode "qty[$item1]=40" --data-urlencode "qty[$item2]=10")
  check E2E-104 "Dispensing what is in stock succeeds" '[[ "$r" == 303* ]]'
  h=$(get pharm pharmacy/batches.php)
  check E2E-105 "The batch expiring first was used first (30 from PAR-A1, 10 from PAR-B2)" '[[ "$h" == *"PAR-B2"* && "$h" != *">PAR-A1<"* ]] && echo "$h" | grep -A8 "PAR-B2" | grep -q ">490<"'
  h=$(get pharm "pharmacy/batches.php?medicine_id=$medid")
  check E2E-106 "The stock ledger shows the receipt and each dispensed batch" '[[ "$h" == *"Dispense"* && "$h" == *"-30"* && "$h" == *"-10"* && "$h" == *"Receive"* ]]'
  h=$(get pharm pharmacy/index.php)
  check E2E-107 "A partly dispensed prescription stays in the queue" '[[ "$h" == *"Partially dispensed"* ]]'

  # --- separation of duties ---
  check E2E-108 "A laboratory technologist cannot open the pharmacy (403)" '[[ "$(code lab pharmacy/index.php)" == 403 ]]'
  check E2E-109 "A pharmacist cannot open the laboratory worklist (403)" '[[ "$(code pharm lab/index.php)" == 403 ]]'
  check E2E-110 "A nurse cannot open the laboratory worklist (403)" '[[ "$(code nurse lab/index.php)" == 403 ]]'
  check E2E-111 "A doctor cannot dispense medicines (403)" '[[ "$(code doc pharmacy/index.php)" == 403 ]]'
  check E2E-112 "A doctor can view stock but not receive it (403)" '[[ "$(code doc pharmacy/stock.php)" == 200 && "$(code doc pharmacy/receive.php)" == 403 ]]'
  check E2E-113 "A cashier cannot open stock or the laboratory (403)" '[[ "$(code cash pharmacy/stock.php)" == 403 && "$(code cash lab/index.php)" == 403 ]]'
  h=$(get admin admin/audit.php)
  check E2E-114 "The audit trail records test results, dispensing and stock receipts" '[[ "$h" == *"dispense"* && "$h" == *"result"* && "$h" == *"receive"* ]]'
  check E2E-115 "The audit trail does not contain the lab result text" '[[ "$h" != *"Malaria parasites"* ]]'
}

# =====================================================================
# SPRINT 4: billing, payments and reports
# =====================================================================
sec_sprint4() {
  echo; echo "== Sprint 4: invoices, payments and reports"
  local r h vid pid iid mgr

  login mgr manager1 "$DEMO_PW" >/dev/null
  # a fresh patient with a consultation (500), a malaria test (300) and an injection (150)
  e2e_new_visit Neema Kerubo 32000004 ""; vid=$NEW_VID; pid=$NEW_PID
  h=$(get doc "visits/consult.php?visit=$vid")
  local testid; testid=$(echo "$h" | grep -o 'name="test_ids\[\]" value="[0-9]*"> Malaria' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
  form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=add_lab" --data-urlencode "test_ids[]=$testid" >/dev/null
  local svc; svc=$(echo "$h" | grep -o '<option value="[0-9]*">Injection administration' | grep -o '[0-9]*' | head -1)
  form doc "visits/consult.php?visit=$vid" "visits/consult.php?visit=$vid" --data-urlencode "visit=$vid" --data-urlencode "action=service" --data-urlencode "service_id=$svc" --data-urlencode "quantity=1" >/dev/null

  h=$(get cash "billing/index.php")
  check E2E-120 "The cashier sees the new invoice in the list of unpaid invoices" '[[ "$h" == *"Neema Kerubo"* && "$h" == *"INV-"* ]]'
  h=$(get cash "billing/invoice.php?visit=$vid")
  iid=$(echo "$h" | grep -o 'name="invoice_id" value="[0-9]*"' | grep -o '[0-9]*')
  check E2E-121 "The invoice was created automatically and lists each service with its price" '[[ "$h" == *"General consultation"* && "$h" == *"Malaria test"* && "$h" == *"Injection administration"* && "$h" == *"KSh 500.00"* ]]'
  check E2E-122 "The invoice total is KSh 950.00 with the same balance due" '[[ "$h" == *"KSh 950.00"* && "$h" == *"Balance due"* ]]'
  check E2E-123 "There is no way to edit a charge on the invoice (no price or quantity fields)" '[[ "$h" != *"name=\"unit_price\""* && "$h" != *"name=\"quantity\""* ]]'

  r=$(post cash billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=Cash" --data-urlencode "amount=100")
  check E2E-124 "A payment without a CSRF token is refused" '[[ "$r" == 419* ]]'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=Cash" --data-urlencode "amount=2000")
  check E2E-125 "Paying more than the balance is refused" 'get cash "billing/invoice.php?id=$iid" | grep -q "more than the balance"'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=M-Pesa" --data-urlencode "amount=200" --data-urlencode "reference=BAD")
  check E2E-126 "An M-Pesa payment needs a valid confirmation code" 'get cash "billing/invoice.php?id=$iid" | grep -q "10-character M-Pesa confirmation code"'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=M-Pesa" --data-urlencode "amount=400" --data-urlencode "reference=QJK4H2M9XP")
  check E2E-127 "A part-payment by M-Pesa is recorded and a receipt is shown" '[[ "$r" == 303* && "$r" == *receipt.php* ]]'
  h=$(get cash "billing/invoice.php?id=$iid")
  check E2E-128 "The invoice is now Partially paid with a balance of KSh 550.00" '[[ "$h" == *"Partially paid"* && "$h" == *"KSh 550.00"* && "$h" == *"QJK4H2M9XP"* ]]'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=M-Pesa" --data-urlencode "amount=50" --data-urlencode "reference=QJK4H2M9XP")
  check E2E-129 "The same M-Pesa code cannot be used twice" 'get cash "billing/invoice.php?id=$iid" | grep -q "already been used"'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=Insurance" --data-urlencode "amount=550" --data-urlencode "reference=AAR/2026/0099")
  h=$(get cash "billing/invoice.php?id=$iid")
  check E2E-130 "The balance is cleared by an insurance payment and the invoice is Paid" '[[ "$h" == *"badge-paid"* && "$h" == *"This invoice is fully paid"* ]]'
  r=$(form cash "billing/invoice.php?id=$iid" billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=Cash" --data-urlencode "amount=1")
  check E2E-131 "A paid invoice accepts no further payment" 'get cash "billing/invoice.php?id=$iid" | grep -q "more than the balance" || ! get cash "billing/invoice.php?id=$iid" | grep -q "Take a payment"'
  local payid; payid=$(get cash "billing/invoice.php?id=$iid" | grep -o 'receipt.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
  h=$(get cash "billing/receipt.php?id=$payid")
  check E2E-132 "The receipt shows the receipt number, method, amount and who received it" '[[ "$h" == *"RCT-"* && "$h" == *"M-Pesa"* && "$h" == *"KSh 400.00"* && "$h" == *"Lucy Muthoni"* ]]'
  h=$(get cash "billing/index.php")
  check E2E-133 "The cashier's summary shows today's collections by method" '[[ "$h" == *"My collections today"* && "$h" == *"KSh 400.00"* && "$h" == *"KSh 550.00"* && "$h" == *"KSh 950.00"* ]]'

  # access control
  check E2E-134 "A manager cannot open individual invoices: managers see totals in reports, not what each patient was charged for (403)" '[[ "$(code mgr "billing/invoice.php?id=$iid")" == 403 ]]'
  r=$(form mgr index.php billing/pay.php --data-urlencode "invoice_id=$iid" --data-urlencode "method=Cash" --data-urlencode "amount=1")
  check E2E-135 "A manager cannot post a payment (403)" '[[ "$(code mgr billing/pay.php)" == 403 || "$r" == 403* ]]'
  check E2E-136 "A doctor and a nurse cannot open invoices (403)" '[[ "$(code doc billing/index.php)" == 403 && "$(code nurse billing/index.php)" == 403 ]]'
  check E2E-137 "A receptionist cannot open reports (403)" '[[ "$(code rec reports/revenue.php)" == 403 ]]'
  check E2E-138 "A cashier cannot open reports (403)" '[[ "$(code cash reports/revenue.php)" == 403 ]]'

  # reports
  local today; today=$(date +%F)
  h=$(get mgr "reports/revenue.php?from=$today&to=$today")
  check E2E-139 "The revenue report shows billed, collected and the split by method" '[[ "$h" == *"Collected by payment method"* && "$h" == *"M-Pesa"* && "$h" == *"Insurance"* && "$h" == *"KSh 950.00"* ]]'
  h=$(get mgr "reports/attendance.php?from=$today&to=$today")
  check E2E-140 "The attendance report shows visits and new patients for the day" '[[ "$h" == *"Total visits"* && "$h" == *"New patients"* ]]'
  h=$(get mgr "reports/lab.php?from=$today&to=$today")
  check E2E-141 "The laboratory report lists the malaria test and the requesting doctor" '[[ "$h" == *"Malaria test"* && "$h" == *"Dr. Samuel Kamau"* ]]'
  h=$(get mgr "reports/stock.php?from=$today&to=$today")
  check E2E-142 "The stock report shows Paracetamol received and dispensed today" '[[ "$h" == *"Paracetamol"* && "$h" == *"Value of usable stock"* ]]'
  r=$(curl -s -D - -o "$J/rev.csv" -b "$J/mgr" "$B/reports/revenue.php?from=$today&to=$today&format=csv")
  check E2E-143 "The revenue report downloads as a CSV file" '[[ "$r" == *"text/csv"* && "$r" == *"attachment"* ]] && head -c 200 "$J/rev.csv" | grep -q "Billed"'
  check E2E-144 "The CSV contains the collected total" 'grep -q "Collected by M-Pesa" "$J/rev.csv"'
  h=$(get admin admin/audit.php)
  check E2E-145 "The audit trail records payments and report exports" '[[ "$h" == *"payment"* && "$h" == *"export"* ]]'
  check E2E-146 "The audit trail does not repeat the M-Pesa code" '[[ "$h" != *"QJK4H2M9XP"* ]]'
  h=$(get mgr index.php)
  check E2E-147 "The manager's dashboard shows revenue and stock alerts" '[[ "$h" == *"Collected today"* && "$h" == *"Unpaid balances"* ]]'
}

# =====================================================================
# SPRINT 5: integration and security (OWASP Top 10)
# =====================================================================
cookie_value() { grep -h "UBHMSSESSID" "$J/$1" 2>/dev/null | awk '{print $NF}' | tail -1; }

sec_sprint5() {
  echo; echo "== Sprint 5: access control matrix and security checks (OWASP Top 10)"
  local r h
  local n=199
  chk() { n=$((n+1)); check "E2E-$n" "$@"; }

  # --- A01 Broken access control: every page against every role, driven by the permission matrix ---
  local pages=(
    "patients/index.php:patients.view" "patients/register.php:patients.register" "patients/import.php:patients.import"
    "appointments/index.php:appointments.view" "appointments/book.php:appointments.book" "visits/checkin.php:visits.checkin"
    "visits/queue.php:visits.view" "lab/index.php:lab.worklist" "lab/results.php:lab.request"
    "pharmacy/index.php:pharmacy.dispense" "pharmacy/stock.php:pharmacy.stock" "pharmacy/batches.php:pharmacy.stock"
    "pharmacy/receive.php:pharmacy.manage" "pharmacy/medicines.php:pharmacy.manage" "billing/index.php:billing.view"
    "reports/attendance.php:reports.attendance" "reports/revenue.php:reports.revenue" "reports/stock.php:reports.stock" "reports/lab.php:reports.lab"
    "admin/users.php:users.manage" "admin/user.php:users.manage" "admin/prices.php:settings.manage" "admin/audit.php:audit.view" "admin/health.php:system.health"
  )
  declare -A jar=( [admin]=admin [manager]=mgr [receptionist]=rec [nurse]=nurse [doctor]=doc [lab]=lab [pharmacist]=pharm [cashier]=cash )
  login rec reception1 "$DEMO_PW" >/dev/null; login nurse nurse1 "$DEMO_PW" >/dev/null; login doc doctor1 "$DEMO_PW" >/dev/null
  login lab lab1 "$DEMO_PW" >/dev/null; login pharm pharmacist1 "$DEMO_PW" >/dev/null; login cash cashier1 "$DEMO_PW" >/dev/null; login mgr manager1 "$DEMO_PW" >/dev/null
  local combos=0 wrong=0 page perm expected role want got
  for entry in "${pages[@]}"; do
    page=${entry%%:*}; perm=${entry##*:}
    expected=$(php_eval x 'foreach (permission_matrix() as $r => $p) { echo $r, ":", in_array("'"$perm"'", $p, true) ? "200" : "403", " "; }')
    bad=""
    for pair in $expected; do
      role=${pair%%:*}; want=${pair##*:}
      got=$(code "${jar[$role]}" "$page"); combos=$((combos+1))
      [ "$got" = "$want" ] || { bad="$bad $role(got $got, expected $want)"; wrong=$((wrong+1)); }
    done
    chk "Access matrix: $page is open only to roles holding $perm" '[ -z "$bad" ]' || echo "      mismatch:$bad"
  done
  chk "All $combos role-and-page combinations behave exactly as the permission table says" '[ "$wrong" -eq 0 ]'

  r=$(post rec admin/users.php --data-urlencode "id=1" --data-urlencode "action=deactivate")
  chk "A forbidden POST (receptionist trying to deactivate an account) is refused before the token is even checked (403)" '[[ "$r" == 403* ]]'
  chk "Refused requests are recorded in the audit trail" 'get admin admin/audit.php | grep -q "access_denied"'

  # --- A01: least privilege on shared pages ---
  local vid2; vid2=$(php_eval x 'echo db_value("SELECT id FROM visits ORDER BY id DESC LIMIT 1");')
  h=$(get rec "visits/view.php?id=$vid2")
  chk "A receptionist's visit page shows no vital signs or diagnosis" '[[ "$h" != *"Oxygen saturation"* && "$h" != *"Pulse (beats"* && "$h" != *"Diagnosis"* ]]'
  h=$(get doc "visits/view.php?id=$vid2")
  chk "A doctor's visit page shows the clinical record" '[[ "$h" == *"Diagnosis"* ]]'
  chk "A manager cannot open individual invoices (reports only)" '[[ "$(code mgr billing/index.php)" == 403 ]]'

  # --- A02/A07 cookies, fixation, replay, timeouts ---
  h=$(curl -s -D - -o /dev/null "$B/login.php")
  chk "The session cookie is HttpOnly and SameSite=Strict" '[[ "$h" == *"HttpOnly"* && "$h" == *"SameSite=Strict"* ]]'
  local t; t=$(token fix login.php); local before; before=$(cookie_value fix)
  post fix login.php --data-urlencode "_csrf=$t" --data-urlencode "username=cashier1" --data-urlencode "password=$DEMO_PW" >/dev/null
  local after; after=$(cookie_value fix)
  chk "A new session id is issued at sign-in (session fixation defence)" '[ -n "$before" ] && [ -n "$after" ] && [ "$before" != "$after" ]'
  cp "$J/fix" "$J/fix_old"
  form fix index.php logout.php >/dev/null
  chk "After sign-out the old session cookie no longer works (replay defence)" '[[ "$(code fix_old billing/index.php)" == 303 ]]'

  chk "A token taken from one session is rejected in another (CSRF tokens are per session)" 'tok=$(token rec index.php); r2=$(post doc logout.php --data-urlencode "_csrf=$tok"); [[ "$r2" == 419* ]]'

  # idle timeout: a second server with a 2-second limit
  local B_SAVE="$B"; B="http://127.0.0.1:$((PORT+1))"
  UBHMS_IDLE_TIMEOUT=2 "$PHP_BIN" -S "127.0.0.1:$((PORT+1))" -t "$ROOT" > "$J/server2.log" 2>&1 &
  local pid2=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$B/login.php" && break; sleep 0.3; done
  login idle cashier1 "$DEMO_PW" >/dev/null
  local a b; a=$(code idle billing/index.php); sleep 3; b=$(code idle billing/index.php)
  kill "$pid2" 2>/dev/null; B="$B_SAVE"
  chk "A session left idle past the limit is ended automatically" '[ "$a" = 200 ] && [ "$b" = 303 ]'

  # a deactivated user loses access at once
  login n2 nurse2 "$DEMO_PW" >/dev/null
  local uid; uid=$(get admin "admin/users.php?q=nurse2" | grep -o 'user.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
  a=$(code n2 visits/queue.php)
  form admin admin/users.php admin/users.php --data-urlencode "id=$uid" --data-urlencode "action=deactivate" >/dev/null
  b=$(code n2 visits/queue.php)
  form admin admin/users.php admin/users.php --data-urlencode "id=$uid" --data-urlencode "action=activate" >/dev/null
  chk "Deactivating an account ends that person's open session immediately" '[ "$a" = 200 ] && [ "$b" = 303 ]'

  r=$(form cash password.php password.php --data-urlencode "current_password=wrong" --data-urlencode "new_password=Another#Pass123" --data-urlencode "confirm_password=Another#Pass123")
  chk "Changing a password requires the current password" 'body | grep -q "current password is not correct"'

  # --- A03 injection and XSS ---
  r=$(login sqli "admin' OR '1'='1' -- " "x")
  chk "A SQL injection attempt in the sign-in form fails with the normal message" 'body | grep -q "username or password is not correct"'
  h=$(get cash "billing/index.php?q=%27%20OR%201%3D1%20--%20&status=%27%3B%20DROP%20TABLE%20invoices%3B--")
  chk "SQL in the invoice filters is treated as text (no error, tables intact)" '[[ "$h" == *"Invoices"* && "$h" != *"SQL"* && "$h" != *"Fatal"* ]] && [[ "$(code cash billing/index.php)" == 200 ]]'
  h=$(get mgr "reports/revenue.php?from=%27%3B%20DROP%20TABLE%20payments%3B--&to=2026-13-45")
  chk "Rubbish in report dates falls back to a safe default" '[[ "$h" == *"Revenue report"* && "$h" != *"SQL"* ]]'
  h=$(get admin "admin/audit.php?action=%27%20OR%201%3D1%20--&user_id=1%20OR%201%3D1")
  chk "SQL in the audit trail filters returns nothing and breaks nothing" '[[ "$h" == *"Audit trail"* && "$h" != *"SQL"* ]]'

  r=$(form rec patients/register.php patients/register.php --data-urlencode "first_name=Xssy" --data-urlencode "last_name=Allergy" --data-urlencode "sex=Male" --data-urlencode "date_of_birth=1980-02-02" --data-urlencode "allergies=<img src=x onerror=alert(1)>" --data-urlencode "residence=\"><script>alert(2)</script>")
  local xp; xp=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  h=$(get rec "patients/view.php?id=$xp")
  chk "Markup stored in allergies and residence is shown as harmless text" '[[ "$h" == *"&lt;img src=x onerror=alert(1)&gt;"* && "$h" != *"<img src=x"* && "$h" != *"<script>alert(2)"* ]]'
  r=$(form admin admin/user.php admin/user.php --data-urlencode "full_name=<script>alert(3)</script> Nurse" --data-urlencode "username=xss.user" --data-urlencode "role=nurse")
  h=$(get admin admin/users.php)
  chk "A user name containing a script tag is escaped in the user list" '[[ "$h" == *"&lt;script&gt;alert(3)&lt;/script&gt; Nurse"* && "$h" != *"<script>alert(3)"* ]]'
  local bad=0
  for p in index.php patients/index.php appointments/index.php billing/index.php pharmacy/stock.php; do
    case $p in patients/*|appointments/*) jr=rec;; billing/*) jr=cash;; pharmacy/*) jr=pharm;; *) jr=rec;; esac
    h=$(get $jr $p)
    if echo "$h" | grep -Eq '<script>|<script [^>]*[^>]>[^<]|[[:space:]]on(click|load|error|submit|mouseover)='; then bad=$((bad+1)); fi
  done
  chk "No page contains inline scripts or inline event handlers (compatible with the strict CSP)" '[ "$bad" -eq 0 ]'
  h=$(curl -s -D - -o /dev/null -b "$J/rec" "$B/patients/index.php")
  chk "Pages are served with no-store caching so records are not kept in the browser after sign-out" '[[ "$h" == *"no-store"* ]]'

  # CSV formula injection
  r=$(form pharm pharmacy/medicine.php pharmacy/medicine.php --data-urlencode "name==HYPERLINK(\"http://evil.example\",\"x\")" --data-urlencode "unit_price=1" --data-urlencode "reorder_level=1" --data-urlencode "is_active=1")
  curl -s -b "$J/mgr" -o "$J/stock.csv" "$B/reports/stock.php?format=csv&from=2000-01-01&to=2100-01-01"
  local apos="'"; local fcell; fcell=$(grep -o ".=HYPERLINK" "$J/stock.csv" | head -1)
  chk "A spreadsheet formula typed as a medicine name is neutralised in the CSV download" '[[ "$fcell" == "${apos}=HYPERLINK" ]]'

  # --- A05 misconfiguration / error handling ---
  h=$(get doc "visits/consult.php?visit=999999")
  chk "A missing record shows a calm error page without file paths or stack traces" '[[ "$h" == *"does not exist"* && "$h" != *"Stack trace"* && "$h" != *"ub-hms"* && "$h" != *".php on line"* ]]'
  h=$(get doc "patients/view.php?id[]=1")
  chk "Unexpected input types are handled without warnings" '[[ "$h" != *"Warning"* && "$h" != *"Fatal"* && "$h" != *"Notice"* ]]'
  r=$(curl -s -o /dev/null -w '%{http_code}' -X PUT -b "$J/doc" "$B/patients/index.php")
  chk "Unusual HTTP methods do not change data" '[[ "$r" == 200 || "$r" == 405 || "$r" == 303 ]]'
  h=$(get admin admin/health.php)
  chk "The system health page shows the integrity check result" '[[ "$h" == *"Data integrity"* && "$h" == *"All records reconcile"* ]]'

  # --- price list ---
  local sid; sid=$(get admin admin/prices.php | grep -o 'name="id" value="[0-9]*"' | head -1 | grep -o '[0-9]*')
  r=$(form admin admin/prices.php admin/prices.php --data-urlencode "kind=service" --data-urlencode "id=$sid" --data-urlencode "price=550" --data-urlencode "is_active=1")
  h=$(get admin admin/audit.php)
  chk "The administrator can change a price and the change is audited with old and new values" '[[ "$r" == 303* && "$h" == *"price_change"* && "$h" == *"to 550"* ]]'
  r=$(form admin admin/prices.php admin/prices.php --data-urlencode "kind=service" --data-urlencode "id=$sid" --data-urlencode "price=abc" --data-urlencode "is_active=1")
  chk "An invalid price is refused" 'get admin admin/prices.php | grep -q "Enter the price as a number"'
  form admin admin/prices.php admin/prices.php --data-urlencode "kind=service" --data-urlencode "id=$sid" --data-urlencode "price=500" --data-urlencode "is_active=1" >/dev/null
  get admin admin/audit.php >/dev/null
  chk "Opening the audit trail is itself recorded in the audit trail" 'get admin admin/audit.php | grep -q "Opened the audit trail"'
}

# =====================================================================
# PUBLIC WEBSITE: welcome, services, about, contact, request access and the legal pages
# =====================================================================
sec_public() {
  echo; echo "== Public website: pages, forms, access requests and contact messages"
  local r h n=299
  chk() { n=$((n+1)); check "E2E-$n" "$@"; }
  local pages=(welcome.php services.php about.php contact.php register.php privacy.php terms.php cookies.php accessibility.php)

  # --- every public page opens for a visitor who is not signed in ---
  local bad="" p
  for p in "${pages[@]}"; do
    h=$(get anon "$p")
    [[ "$(code anon "$p")" == 200 && "$h" == *"Skip to content"* && "$h" == *"<title>"*"UB-HMS</title>"* && "$h" == *'<main id="main"'* && "$h" == *"</footer>"* ]] || bad="$bad $p"
  done
  chk "All nine public pages open without signing in, with a title, a skip link, a main area and a footer" '[ -z "$bad" ]' || echo "      problem pages:$bad"

  bad=""
  for p in "${pages[@]}"; do
    h=$(get anon "$p")
    [ "$(echo "$h" | grep -o '<h1' | wc -l)" = 1 ] || bad="$bad $p"
  done
  chk "Every public page has exactly one main heading" '[ -z "$bad" ]' || echo "      problem pages:$bad"

  bad=""
  for p in "${pages[@]}"; do
    h=$(get anon "$p")
    if echo "$h" | grep -Eq '<script>|<script [^>]*[^>]>[^<]|[[:space:]]on(click|load|error|submit|mouseover)=|[[:space:]]style="'; then bad="$bad $p"; fi
  done
  chk "No public page has inline scripts, event handlers or style attributes (compatible with the strict CSP)" '[ -z "$bad" ]' || echo "      problem pages:$bad"

  bad=""
  for p in "${pages[@]}"; do
    h=$(get anon "$p")
    echo "$h" | grep -oE '(src|href)="https?://[^"]+"' | grep -vE 'pexels\.com|odpc\.go\.ke|www\.nngroup' | grep -q . && bad="$bad $p"
    echo "$h" | grep -oE '(src|href)="[^"]*(fonts\.googleapis|cdn\.|unpkg|jsdelivr|googletagmanager|google-analytics)[^"]*"' | grep -q . && bad="$bad $p"
  done
  chk "No public page loads a font, script or image from another website, and none uses an analytics service" '[ -z "$bad" ]' || echo "      problem pages:$bad"

  r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/")
  chk "The site address itself opens the welcome page for a visitor who is not signed in" '[[ "$r" == 303* && "$r" == *welcome.php ]]'
  r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/index.php")
  chk "index.php still sends a visitor who is not signed in to the sign-in page" '[[ "$r" == 303* && "$r" == *login.php* ]]'
  r=$(code rec welcome.php); h=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J/rec" "$B/welcome.php")
  chk "A signed-in user who opens the welcome page is sent on to the dashboard" '[ "$r" = 303 ] && [[ "$h" == *index.php ]]'
  h=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J/rec" "$B/register.php")
  chk "A signed-in user who opens the request-access page is sent on to the dashboard" '[[ "$h" == *index.php ]]'

  h=$(get anon welcome.php)
  chk "The header offers Services, About, Contact, Legal, Sign in and Request access, and the footer lists the legal pages" '[[ "$h" == *"services.php"* && "$h" == *"about.php"* && "$h" == *"contact.php"* && "$h" == *"login.php"* && "$h" == *"register.php"* && "$h" == *"privacy.php"* && "$h" == *"terms.php"* && "$h" == *"cookies.php"* && "$h" == *"accessibility.php"* ]]'
  h=$(get anon services.php)
  chk "The header marks the page you are on (Services)" '[[ "$h" == *"is-current"* && "$h" == *"aria-current=\"page\""* ]]'

  # The only cookie is the session cookie, and it carries the protections the cookie notice promises.
  h=$(curl -s -D - -o /dev/null "$B/welcome.php" | tr -d '\r')
  chk "The welcome page sets one cookie only, and it is HttpOnly and SameSite=Strict" '[ "$(echo "$h" | grep -ic "^set-cookie:")" = 1 ] && [[ "$h" == *HttpOnly* && "$h" == *SameSite=Strict* ]]'
  h=$(get anon cookies.php)
  chk "The cookie notice names the cookie the system really sets" '[[ "$h" == *"UBHMSSESSID"* ]]'

  # --- request access: validation ---
  local t
  r=$(form anon register.php register.php --data-urlencode "full_name=Al" --data-urlencode "email=nope" --data-urlencode "department=" --data-urlencode "requested_role=admin" --data-urlencode "supervisor=" )
  h=$(get anon register.php)
  chk "An incomplete request is sent back with every problem listed and nothing stored" '[[ "$r" == 303* && "$h" == *"Please check the form"* && "$h" == *"Enter your full name"* && "$h" == *"valid email address"* && "$h" == *"Choose your department"* && "$h" == *"Choose the role you need"* && "$h" == *"supervisor"* && "$h" == *"read the privacy policy"* ]]'
  chk "The form remembers what the person typed after an error" '[[ "$h" == *'"'"'value="nope"'"'"'* || "$h" == *"value=\"nope\""* ]]'
  h=$(get admin admin/requests.php)
  chk "A refused request is not stored" '[[ "$h" != *"nope"* ]]'
  r=$(form anon register.php register.php --data-urlencode "full_name=Mercy Njeri" --data-urlencode "email=mercy@example.com" --data-urlencode "department=Pharmacy" --data-urlencode "requested_role=admin" --data-urlencode "supervisor=Mr Otieno" --data-urlencode "consent=1")
  h=$(get anon register.php)
  chk "The System Administrator role cannot be requested from the public form" '[[ "$h" == *"Choose the role you need"* ]]'

  # --- request access: a good request ---
  local users_before; users_before=$(get admin admin/users.php | grep -c '<tr>')
  local xss='<script>alert(7)</script>'
  r=$(form anon register.php register.php --data-urlencode "full_name=Mercy Njeri" --data-urlencode "email=Mercy@Example.com" --data-urlencode "phone=0722 111 222" --data-urlencode "department=Pharmacy" --data-urlencode "requested_role=pharmacist" --data-urlencode "staff_number=UB/P/0033" --data-urlencode "supervisor=Mr Otieno" --data-urlencode "message=$xss Starting Monday" --data-urlencode "consent=1")
  h=$(get anon register.php)
  chk "A good request is accepted and the person is given a reference number" '[[ "$r" == 303* && "$h" == *"Your request has been sent"* && "$h" =~ AR-[0-9]{4}-[0-9]{6} ]]'
  local ref; ref=$(echo "$h" | grep -oE 'AR-[0-9]{4}-[0-9]{6}' | head -1)
  h=$(get anon register.php)
  chk "The confirmation is shown once and then the empty form returns" '[[ "$h" != *"Your request has been sent"* && "$h" == *"Staff access request"* ]]'
  h=$(get admin admin/users.php)
  chk "Sending a request does not create an account" '[ "$(echo "$h" | grep -c "<tr>")" = "$users_before" ] && [[ "$h" != *"Mercy Njeri"* ]]'
  r=$(form anon register.php register.php --data-urlencode "full_name=Mercy Njeri" --data-urlencode "email=mercy@example.com" --data-urlencode "department=Pharmacy" --data-urlencode "requested_role=pharmacist" --data-urlencode "supervisor=Mr Otieno" --data-urlencode "consent=1")
  h=$(get anon register.php)
  chk "A second request while the first is waiting is refused" '[[ "$h" == *"already waiting for the administrator"* ]]'

  # --- the trap field, the security token and the hourly limit ---
  r=$(form anon register.php register.php --data-urlencode "website=http://spam.example" --data-urlencode "full_name=Robot Robot" --data-urlencode "email=robot@example.com" --data-urlencode "department=Pharmacy" --data-urlencode "requested_role=nurse" --data-urlencode "supervisor=Bot Bot" --data-urlencode "consent=1")
  h=$(get admin admin/requests.php)
  chk "A form filled in by a robot (the hidden field) looks successful but stores nothing" '[[ "$r" == 303* && "$h" != *"robot@example.com"* && "$h" != *"Robot Robot"* ]]'
  r=$(post anon register.php --data-urlencode "full_name=No Token" --data-urlencode "email=notoken@example.com")
  chk "A request sent without the security token is refused (419)" '[[ "$r" == 419* ]]'
  r=$(post anon register.php --data-urlencode "_csrf=wrong" --data-urlencode "full_name=Wrong Token")
  chk "A request sent with a wrong security token is refused (419)" '[[ "$r" == 419* ]]'
  r=$(code anon "register.php?x=1"); h=$(curl -s -X POST -o /dev/null -w '%{http_code}' "$B/contact.php")
  chk "Posting to a public form without a session is refused" '[ "$h" = 405 ] || [ "$h" = 419 ]'

  # --- contact page ---
  r=$(form anon contact.php contact.php --data-urlencode "full_name=Peter Mwangi" --data-urlencode "email=peter@example.com" --data-urlencode "topic=Spam" --data-urlencode "message=hi")
  h=$(get anon contact.php)
  chk "An incomplete message is sent back with the problems listed" '[[ "$h" == *"Please check the form"* && "$h" == *"what your message is about"* && "$h" == *"at least 10 characters"* && "$h" == *"contains no patient information"* ]]'
  r=$(form anon contact.php contact.php --data-urlencode "full_name=Peter Mwangi" --data-urlencode "email=peter@example.com" --data-urlencode "topic=Technical support" --data-urlencode "message=<img src=x onerror=alert(1)> Which browsers are supported?" --data-urlencode "consent=1")
  h=$(get anon contact.php)
  chk "A good message is accepted with a reference number (MSG-)" '[[ "$r" == 303* && "$h" == *"your message is on its way"* && "$h" =~ MSG-[0-9]{4}-[0-9]{6} ]]'
  h=$(get anon "contact.php?topic=Data%20protection%20request")
  chk "A link from the footer pre-selects the topic on the contact form" '[[ "$h" == *"selected>Data protection request"* ]]'

  # --- administrator: reading and handling requests ---
  h=$(get admin admin/requests.php)
  chk "The administrator sees the request and the message with the details the person gave" '[[ "$h" == *"Mercy Njeri"* && "$h" == *"UB/P/0033"* && "$h" == *"Mr Otieno"* && "$h" == *"Pharmacist"* && "$h" == *"peter@example.com"* && "$h" == *"Technical support"* ]]'
  chk "Text typed into the public forms is shown as harmless text, never as code" '[[ "$h" == *"&lt;script&gt;alert(7)&lt;/script&gt;"* && "$h" != *"<script>alert(7)"* && "$h" == *"&lt;img src=x onerror=alert(1)&gt;"* && "$h" != *"<img src=x"* ]]'
  h=$(get admin index.php)
  chk "The administrator dashboard shows how many new requests and messages are waiting" '[[ "$h" == *"New access requests and messages"* ]]'
  h=$(get admin admin/requests.php?kind=contact)
  chk "The list can be filtered to contact messages only" '[[ "$h" == *"peter@example.com"* && "$h" != *"UB/P/0033"* ]]'
  local reqid; reqid=$(php_eval x 'echo db_value("SELECT id FROM public_requests WHERE reference = ?", ["'"$ref"'"]);')
  h=$(get admin "admin/user.php?from_request=$reqid")
  chk "Create account from a request fills in the name, a suggested username and the role, and shows where it came from" '[[ "$h" == *"From access request"* && "$h" == *"value=\"Mercy Njeri\""* && "$h" == *"value=\"m.njeri\""* && "$h" == *"value=\"pharmacist\" selected"* ]] || [[ "$h" == *"From access request"* && "$h" == *"m.njeri"* && "$h" == *"Mercy Njeri"* ]]'
  r=$(form admin "admin/user.php?from_request=$reqid" "admin/user.php" --data-urlencode "from_request=$reqid" --data-urlencode "full_name=Mercy Njeri" --data-urlencode "username=m.njeri" --data-urlencode "role=pharmacist" --data-urlencode "phone=0722 111 222")
  h=$(get admin "admin/requests.php?q=$ref")
  chk "Creating the account from the request marks the request as done and records which account was made" '[[ "$r" == 303* && "$h" == *"Done"* && "$h" == *"Account created: m.njeri"* ]]'
  h=$(get admin admin/users.php)
  chk "The new account now exists" '[[ "$h" == *"Mercy Njeri"* && "$h" == *"m.njeri"* ]]'
  r=$(form admin admin/requests.php admin/requests.php --data-urlencode "id=$reqid" --data-urlencode "status=declined" --data-urlencode "note=Test change")
  h=$(get admin admin/audit.php)
  chk "Changing a request's status is recorded in the audit trail" '[[ "$r" == 303* && "$h" == *"marked declined"* && "$h" == *"public_request"* ]]'
  r=$(form admin admin/requests.php admin/requests.php --data-urlencode "id=$reqid" --data-urlencode "status=wizard")
  h=$(get admin admin/requests.php)
  chk "An unknown status is refused" '[[ "$h" == *"Choose a status"* ]]'

  # --- who may open the administrator's request list ---
  local denied="" role want got
  for role in manager:mgr receptionist:rec nurse:nurse doctor:doc lab:lab pharmacist:pharm cashier:cash; do
    got=$(code "${role##*:}" admin/requests.php)
    [ "$got" = 403 ] || denied="$denied ${role%%:*}(got $got)"
  done
  chk "Only the administrator can open the requests page; the other seven roles are refused (403)" '[ -z "$denied" ]' || echo "      not refused:$denied"
  r=$(curl -s -o /dev/null -w '%{http_code}' "$B/admin/requests.php")
  chk "A visitor who is not signed in cannot open the requests page" '[ "$r" = 303 ]'
  h=$(get admin admin/health.php)
  chk "The system health page counts the tables of the schema (27) and finds them all" '[[ "$h" == *"27 tables present"* ]]'

  # --- the hourly limit (last, because it uses up this computer's allowance) ---
  local i
  for i in 1 2 3 4; do
    form anon contact.php contact.php --data-urlencode "full_name=Limit Tester$i" --data-urlencode "email=limit$i@example.com" --data-urlencode "topic=General question" --data-urlencode "message=Message number $i for the limit test" --data-urlencode "consent=1" >/dev/null
    get anon contact.php >/dev/null   # reading the page clears the thank-you card, as a person would
  done
  get anon contact.php >/dev/null
  r=$(form anon contact.php contact.php --data-urlencode "full_name=Limit Tester6" --data-urlencode "email=limit6@example.com" --data-urlencode "topic=General question" --data-urlencode "message=This sixth message should be refused" --data-urlencode "consent=1")
  h=$(get anon contact.php)
  chk "The sixth message from one computer in an hour is refused with a polite explanation" '[[ "$h" == *"Too many messages were sent from this computer"* ]]'
  h=$(get admin admin/requests.php?kind=contact)
  chk "The refused message was not stored, and the five earlier ones were" '[[ "$h" != *"limit6@example.com"* && "$h" == *"limit4@example.com"* ]]'
}

# =====================================================================
# DASHBOARDS: the application shell (header, sidebar, notifications, user menu), the eight role dashboards, profile and settings
# =====================================================================
sec_dashboards() {
  echo; echo "== Dashboards: header, sidebar, notifications, eight role dashboards, profile and settings"
  local r h n=349
  chk() { n=$((n+1)); check "E2E-$n" "$@"; }
  declare -A jar=( [admin]=admin [manager]=mgr [receptionist]=rec [nurse]=nurse [doctor]=doc [lab]=lab [pharmacist]=pharm [cashier]=cash )
  local roles=(admin manager receptionist nurse doctor lab pharmacist cashier)
  # a number shown on a card: kpi "<html>" "<label>"
  kpi() { echo "$1" | grep -o "<div class=\"num\">[^<]*</div><div class=\"label\">$2" | head -1 | sed 's/<div class="num">//; s/<\/div>.*//'; }
  local role bad

  # --- every role gets a complete, clean dashboard ---
  bad=""
  for role in "${roles[@]}"; do
    h=$(get "${jar[$role]}" index.php)
    [[ "$(code "${jar[$role]}" index.php)" == 200 && "$h" =~ Good\ (morning|afternoon|evening),\  && "$h" == *'class="kpi-grid'* && "$h" == *'class="dash-grid"'* && "$h" == *'aria-label="Quick actions"'* ]] || bad="$bad $role"
  done
  chk "All eight roles get a dashboard with a greeting, coloured cards, panels and quick-action buttons" '[ -z "$bad" ]' || echo "      problem roles:$bad"

  bad=""
  for role in "${roles[@]}"; do
    h=$(get "${jar[$role]}" index.php)
    [ "$(echo "$h" | grep -o '<h1' | wc -l)" = 1 ] || bad="$bad $role"
    echo "$h" | grep -Eq 'Warning:|Notice:|Fatal error|Deprecated:|Stack trace' && bad="$bad $role(php)"
    echo "$h" | grep -Eq '<script>|<script [^>]*[^>]>[^<]|[[:space:]]on(click|load|error|submit|mouseover)=|[[:space:]]style="' && bad="$bad $role(inline)"
  done
  chk "Every dashboard has one main heading, no PHP messages, and no inline scripts, handlers or styles (compatible with the strict CSP)" '[ -z "$bad" ]' || echo "      problems:$bad"

  # --- the application shell on every dashboard ---
  bad=""
  for role in "${roles[@]}"; do
    h=$(get "${jar[$role]}" index.php)
    [[ "$h" == *'class="app-top"'* && "$h" == *'class="sidebar"'* && "$h" == *"View profile"* && "$h" == *"Settings"* && "$h" == *"Change password"* && "$h" == *'class="dd-signout"'* && "$h" == *'name="_csrf"'* && "$h" == *'class="dd dd-notif"'* && "$h" == *'class="av av-'* && "$h" == *"Skip to content"* ]] || bad="$bad $role"
  done
  chk "Every signed-in page has the header with a notification bell and a user menu (profile, settings, change password, sign out) and the sidebar" '[ -z "$bad" ]' || echo "      problem roles:$bad"

  h=$(get rec index.php)
  chk "The sign-out button in the user menu is a protected form (POST with the security token), not a link" '[[ "$h" == *"<form method=\"post\" action=\""*"logout.php\" class=\"dd-signout\"><input type=\"hidden\" name=\"_csrf\""* && "$h" != *"href=\"/logout.php\""* ]]'
  h=$(get rec index.php)
  chk "Staff who may search patients get a search box in the header; the cashier and laboratory do not" '[[ "$h" == *"role=\"search\""* ]] && [[ "$(get cash index.php)" != *"role=\"search\""* && "$(get lab index.php)" != *"role=\"search\""* ]]'
  h=$(get rec index.php)
  chk "The quick-add menu offers only what the role can do (receptionist: register, book, check in)" '[[ "$h" == *"Register a patient"* && "$h" == *"Book an appointment"* && "$h" == *"Check in a patient"* && "$h" != *"Add a user account"* && "$h" != *"Receive stock"* ]]'
  h=$(get admin index.php)
  chk "The administrator's quick-add offers only adding a user" '[[ "$h" == *"Add a user account"* && "$h" != *"Register a patient"* ]]'

  # --- the sidebar shows each role only its own pages, with an icon on each ---
  h=$(get cash index.php)
  chk "The cashier's sidebar lists invoices but no patient, clinic, laboratory or administration pages" '[[ "$h" == *"billing/index.php"* && "$h" != *"patients/index.php\" title"* && "$h" != *"lab/index.php"* && "$h" != *"admin/users.php"* ]]'
  h=$(get admin index.php)
  chk "The administrator's sidebar lists accounts, access requests, prices, audit trail and health but no clinical pages" '[[ "$h" == *"admin/users.php"* && "$h" == *"admin/requests.php"* && "$h" == *"admin/prices.php"* && "$h" == *"admin/audit.php"* && "$h" == *"admin/health.php"* && "$h" != *"visits/queue.php"* ]]'
  h=$(get doc index.php)
  chk "Every sidebar item has an icon, and the current page is marked for screen readers" '[ "$(echo "$h" | grep -o "class=\"nav-link" | wc -l)" = "$(echo "$h" | grep -o "class=\"nav-ic\"" | wc -l)" ] && [[ "$h" == *"aria-current=\"page\""* ]]'

  # --- numbers on the cards match the database (checked with separate SQL) ---
  local db patients
  patients=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM patients");')
  h=$(get rec index.php)
  chk "Receptionist: 'Patients registered' equals the number of patients in the database ($patients)" '[ "$(kpi "$h" "Patients registered")" = "$patients" ]'
  db=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM users WHERE is_active = 1");')
  h=$(get admin index.php)
  chk "Administrator: 'Active user accounts' equals the database ($db)" '[ "$(kpi "$h" "Active user accounts")" = "$db" ]'
  db=$(php_eval x 'echo fmt_money((string) db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(paid_at) = ?", [app_today()]));')
  h=$(get mgr index.php)
  chk "Manager: 'Collected today' equals the payments taken today in the database ($db)" '[ "$(kpi "$h" "Collected today")" = "$db" ]'
  db=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM invoices WHERE status <> ?", ["Paid"]);')
  h=$(get cash index.php)
  chk "Cashier: 'Invoices waiting for payment' equals the unpaid invoices in the database ($db)" '[ "$(kpi "$h" "Invoices waiting for payment")" = "$db" ]'
  db=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM prescriptions WHERE status IN (?, ?)", ["Pending", "Partially dispensed"]);')
  h=$(get pharm index.php)
  chk "Pharmacist: 'Prescriptions waiting' equals the database ($db)" '[ "$(kpi "$h" "Prescriptions waiting")" = "$db" ]'
  db=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM lab_requests WHERE status = ?", ["Requested"]);')
  h=$(get lab index.php)
  chk "Laboratory: 'Tests waiting' equals the database ($db)" '[ "$(kpi "$h" "Tests waiting")" = "$db" ]'
  local tw; tw=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM visits v WHERE v.status = ? AND NOT EXISTS (SELECT 1 FROM triage t WHERE t.visit_id = v.id)", ["Open"]);')
  h=$(get nurse index.php)
  chk "Nurse: 'Waiting for triage' equals the database ($tw)" '[ "$(kpi "$h" "Waiting for triage")" = "$tw" ]'

  # --- charts: drawn on the server, readable without seeing them ---
  h=$(get mgr index.php)
  chk "The manager's dashboard has charts drawn as SVG, each with a text summary and a hidden table of its numbers" '[[ "$h" == *"<svg class=\"chart-svg\""* && "$h" == *"<svg class=\"donut-svg\""* && "$h" == *"role=\"img\" aria-label=\"Money collected per day for the last 14 days\""* && "$h" == *"<div class=\"sr-only\"><table><caption>"* ]]'
  chk "The manager's dashboard shows money, visits, payment methods, busiest doctors and tests" '[[ "$h" == *"Money collected, last 14 days"* && "$h" == *"Patients seen per day"* && "$h" == *"How patients pay"* && "$h" == *"Busiest doctors"* && "$h" == *"Most requested tests"* ]]'
  h=$(get admin index.php)
  chk "The administrator's dashboard shows sign-ins (successful and failed), accounts by role and latest activity" '[[ "$h" == *"Sign-ins, last 14 days"* && "$h" == *"Accounts by role"* && "$h" == *"Latest activity"* && "$h" == *">Failed<"* ]]'
  h=$(get doc index.php)
  chk "The doctor's dashboard is about the doctor's own work (consultations, diagnoses, queue, results)" '[[ "$h" == *"My consultations, last 14 days"* && "$h" == *"What I diagnose most"* && "$h" == *"Waiting for me"* && "$h" == *"Test results ready"* ]]'
  h=$(get pharm index.php)
  chk "The pharmacist's dashboard shows dispensing, stock status, most-dispensed medicines and the stock alerts" '[[ "$h" == *"Units dispensed, last 14 days"* && "$h" == *"Stock status"* && "$h" == *"Dispensed most"* && ( "$h" == *"Needs action now"* || "$h" == *"Reorder and use soon"* ) ]]'
  h=$(get cash index.php)
  chk "The cashier sees only their own collections, not the hospital's revenue" '[[ "$h" == *"Collected by me today"* && "$h" == *"Money I collected, last 14 days"* && "$h" != *"Revenue report"* && "$h" != *"Unpaid balances"* ]]'
  h=$(get rec index.php)
  chk "The receptionist's dashboard shows today's appointments, who is in the clinic, new patients and appointment outcomes" '[[ "$h" == *"Appointments today"* && "$h" == *"In the clinic now"* && "$h" == *"New patients, last 14 days"* && "$h" == *"How appointments ended"* ]]'

  # --- no role sees another role's data on its dashboard ---
  bad=""
  for role in nurse doctor lab pharmacist cashier receptionist; do
    h=$(get "${jar[$role]}" index.php)
    [[ "$h" != *"Active user accounts"* && "$h" != *"Audit entries today"* && "$h" != *"Accounts by role"* ]] || bad="$bad $role"
  done
  for role in nurse lab receptionist doctor; do
    h=$(get "${jar[$role]}" index.php)
    [[ "$h" != *"Unpaid balances"* && "$h" != *"Collected today"* ]] || bad="$bad $role(money)"
  done
  chk "Clinical roles see no administrator or revenue figures on their dashboards" '[ -z "$bad" ]' || echo "      leaks:$bad"

  # --- notifications ---
  local N w
  h=$(get nurse index.php)
  N=$(echo "$h" | grep -o 'class="notif notif-' | wc -l)
  if [ "$N" -gt 0 ]; then w="Notifications: $N need"; else w="Notifications: nothing needs attention"; fi
  chk "The nurse's bell count matches the list inside it ($N) and says so in words" '[[ "$h" == *"$w"* ]]'
  if [ "$tw" = 1 ]; then w="1 patient waiting for triage"; else w="$tw patients waiting for triage"; fi
  if [ "$tw" -gt 0 ]; then chk "The nurse is notified of the patients waiting for triage ($tw), and the sidebar badge shows the same number" '[[ "$h" == *"$w"* && "$h" == *"class=\"nav-badge\">$tw<"* ]]'; else chk "With nobody waiting for triage the nurse has no triage notification" '[[ "$h" != *"waiting for triage"* ]]'; fi
  h=$(get pharm index.php)
  chk "The pharmacist is notified of medicines out of stock or low, and of batches expiring" '[[ "$h" == *"out of stock"* || "$h" == *"running low"* ]] && [[ "$h" == *"expiring soon"* || "$h" == *"expired"* ]]'
  h=$(get admin index.php)
  chk "The administrator is notified of new access requests and messages" '[[ "$h" == *"new access request"* && "$h" == *"admin/requests.php?status=new"* ]]'
  chk "A notification links to the page where the work is done" '[[ "$(get pharm index.php)" == *"pharmacy/stock.php"* ]] && [[ "$(get mgr index.php)" == *"notif-body"* ]]'
  h=$(get cash index.php)
  chk "The cashier is told about invoices waiting for payment" '[[ "$h" == *"waiting for payment"* ]]'

  # --- profile ---
  r=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/profile.php")
  chk "A visitor who is not signed in cannot open the profile or settings page" '[[ "$r" == 303* && "$r" == *login.php* && "$(curl -s -o /dev/null -w "%{http_code}" "$B/settings.php")" == 303 ]]'
  bad=""
  for role in "${roles[@]}"; do
    h=$(get "${jar[$role]}" profile.php)
    [[ "$(code "${jar[$role]}" profile.php)" == 200 && "$h" == *"What your role can do"* && "$h" == *"Your recent activity"* && "$h" == *'class="av av-'* ]] || bad="$bad $role"
  done
  chk "All eight roles can open their own profile (details, what the role allows, recent activity)" '[ -z "$bad" ]' || echo "      problem roles:$bad"
  h=$(get doc profile.php)
  chk "A profile shows the person's own name and role and the permissions of that role, and nobody else's account" '[[ "$h" == *"Doctor / Clinical Officer"* && "$h" == *"Record consultations"* && "$h" != *"Create and manage user accounts"* && "$h" != *"Faith Achieng"* ]]'
  h=$(get admin profile.php)
  chk "The administrator's profile lists the administrator's permissions" '[[ "$h" == *"Create and manage user accounts"* && "$h" == *"Review staff access requests"* ]]'
  code rec admin/users.php >/dev/null          # a refused page is recorded under the person's name
  h=$(get rec profile.php)
  chk "Recent activity shows the person's own latest actions in plain words, not codes" '[[ "$h" == *"A page was refused (no permission)"* && "$h" != *"access_denied"* && "$h" != *"csrf_rejected"* ]]'

  # --- settings: a person can change only their own phone number ---
  local before; before=$(php_eval x 'echo (string) db_value("SELECT phone FROM users WHERE username = ?", ["nurse1"]);')
  r=$(form rec settings.php settings.php --data-urlencode "action=phone" --data-urlencode "phone=0722 555 666")
  h=$(get rec profile.php)
  chk "A person can change their own phone number, which is stored in one standard form" '[[ "$r" == 303* && "$h" == *"+254722555666"* ]]'
  r=$(form rec settings.php settings.php --data-urlencode "action=phone" --data-urlencode "phone=12")
  h=$(get rec settings.php)
  chk "A wrong phone number is refused with a message, and the old number stays" '[[ "$h" == *"Kenyan mobile number"* && "$(get rec profile.php)" == *"+254722555666"* ]]'
  r=$(form rec settings.php settings.php --data-urlencode "action=phone" --data-urlencode "phone=0733 111 000" --data-urlencode "id=1" --data-urlencode "user_id=1" --data-urlencode "username=nurse1")
  local after; after=$(php_eval x 'echo (string) db_value("SELECT phone FROM users WHERE username = ?", ["nurse1"]);')
  chk "Sending someone else's id with the form changes only the signed-in person's number" '[ "$before" = "$after" ] && [[ "$(get rec profile.php)" == *"+254733111000"* ]]'
  h=$(get admin admin/audit.php)
  chk "The change is in the audit trail, but the number itself is not" '[[ "$h" == *"Own phone number changed"* && "$h" != *"733111000"* && "$h" != *"722555666"* ]]'
  r=$(form rec settings.php settings.php --data-urlencode "action=phone" --data-urlencode "phone=")
  chk "A blank phone number removes it" '[[ "$r" == 303* && "$(get rec profile.php)" == *"Not given"* ]]'
  r=$(post rec settings.php --data-urlencode "action=phone" --data-urlencode "phone=0700 000 000")
  chk "Settings sent without the security token are refused (419)" '[[ "$r" == 419* ]]'
  h=$(get rec settings.php)
  chk "The display choices (shrink the menu, compact tables) are hidden until JavaScript can make them work" '[[ "$h" == *"data-prefs"* && "$h" == *"hidden"* ]] && [[ "$h" == *"data-pref=\"sb-collapsed\""* && "$h" == *"data-pref=\"dense\""* ]]'

  # --- assets and speed ---
  chk "The dashboard stylesheet and script are served" '[ "$(code anon assets/css/dash.css)" = 200 ] && [ "$(code anon assets/js/dash.js)" = 200 ]'
  local t; t=$(curl -s -o /dev/null -w '%{time_total}' -b "$J/mgr" "$B/index.php")
  chk "The manager's dashboard (the busiest) loads in under 3 seconds on the test database ($t s)" 'awk "BEGIN { exit !($t < 3) }"'
  h=$(get doc patients/index.php)
  chk "Older pages use the new header and sidebar too (patient search)" '[[ "$h" == *"class=\"app-top\""* && "$h" == *"class=\"sidebar\""* && "$h" == *"Search patients"* ]]'
}

# =====================================================================
# DOCTOR SCHEDULES, REFUNDS AND PATIENT DATA RIGHTS
# =====================================================================
sec_rights() {
  echo; echo "== Doctor schedules and leave, refunds, and patient data export and erasure"
  local r h n=399
  chk() { n=$((n+1)); check "E2E-$n" "$@"; }
  declare -A jar=( [admin]=admin [manager]=mgr [receptionist]=rec [nurse]=nurse [doctor]=doc [lab]=lab [pharmacist]=pharm [cashier]=cash )
  local roles=(admin manager receptionist nurse doctor lab pharmacist cashier)
  login admin "$ADMIN_USER" "$ADMIN_PW2" >/dev/null
  login rec reception1 "$DEMO_PW" >/dev/null; login doc doctor1 "$DEMO_PW" >/dev/null; login cash cashier1 "$DEMO_PW" >/dev/null
  login mgr manager1 "$DEMO_PW" >/dev/null; login nurse nurse1 "$DEMO_PW" >/dev/null

  local docid; docid=$(php_eval x 'echo db_value("SELECT id FROM users WHERE username = ?", ["doctor1"]);')
  # one paid visit for a patient with a surname that is easy to search for; prints "patientId visitId paymentId invoiceNo"
  mkvisit() {
    php_eval x '
      $rec = (int) db_value("SELECT id FROM users WHERE username = ?", ["reception1"]);
      $doc = (int) db_value("SELECT id FROM users WHERE username = ?", ["doctor1"]);
      $cash = (int) db_value("SELECT id FROM users WHERE username = ?", ["cashier1"]);
      $p = register_patient(["first_name" => "Pat", "last_name" => "'"$1"'", "sex" => "Female", "date_of_birth" => "1991-03-03", "national_id" => (string) random_int(10000000, 99999999), "phone" => "0712 000 000", "residence" => "Ruaka", "allergies" => "None known"], $rec, true);
      $v = check_in_patient($p["id"], null, $doc, "Cough and fever", $rec)["visit_id"];
      save_consultation($v, ["service_id" => (int) db_value("SELECT id FROM services WHERE name = ?", ["General consultation"]), "complaint" => "Cough for three days", "examination" => "Chest clear", "diagnosis" => "Upper respiratory infection", "icd10_code" => "J06.9", "plan" => "Rest and fluids"], $doc);
      $inv = get_invoice_for_visit($v);
      $pay = '"$2"' ? record_payment((int) $inv["id"], ["method" => "Cash", "amount" => $inv["total"], "reference" => ""], $cash) : ["payment_id" => 0];
      echo $p["id"], " ", $v, " ", $pay["payment_id"], " ", $inv["invoice_no"];'
  }

  # ---------------------------------------------------------------- schedules
  h=$(get mgr admin/schedules.php)
  chk "The Hospital Manager sees every doctor with their hours, today's status and a button for hours and leave" '[[ "$h" == *"Doctor schedules"* && "$h" == *"Dr. Samuel Kamau"* && "$h" == *"Clinic hours (no personal schedule set)"* && "$h" == *"Hours and leave"* ]]'
  local bad=""
  for role in admin receptionist nurse doctor lab pharmacist cashier; do
    [ "$(code "${jar[$role]}" admin/schedules.php)" = 403 ] || bad="$bad $role"
    [ "$(code "${jar[$role]}" "admin/schedule.php?doctor=$docid")" = 403 ] || bad="$bad $role(edit)"
  done
  chk "Nobody but the Hospital Manager can open the schedule pages (403 for the other seven roles)" '[ -z "$bad" ]' || echo "      problem roles:$bad"
  chk "An unknown doctor number is a 404, not an error page" '[[ "$(code mgr "admin/schedule.php?doctor=999999")" == 404 && "$(code mgr "admin/schedule.php?doctor=abc")" == 404 ]]'

  local D; D=$(next_workday 3)
  local sch=() wd
  for wd in 1 2 3 4 5 6; do
    sch+=(--data-urlencode "sch[$wd][0][from]=09:00" --data-urlencode "sch[$wd][0][to]=12:00" --data-urlencode "sch[$wd][1][from]=14:00" --data-urlencode "sch[$wd][1][to]=17:00")
  done
  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=save_hours" "${sch[@]}")
  h=$(get mgr "admin/schedule.php?doctor=$docid")
  chk "Saving weekly hours works and the page shows them back" '[[ "$r" == 303* && "$h" == *"Working hours saved for Dr. Samuel Kamau"* ]]'
  h=$(get mgr admin/schedules.php)
  chk "The overview now shows the hours in words" '[[ "$h" == *"Mon-Sat 09:00-12:00 and 14:00-17:00"* ]]'

  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=save_hours" --data-urlencode "sch[1][0][from]=12:00" --data-urlencode "sch[1][0][to]=09:00")
  h=$(body)
  chk "Hours that end before they start are refused with a message on that day, and the saved hours stay" '[[ "$h" == *"The end time must be after the start time"* ]] && [[ "$(get mgr admin/schedules.php)" == *"Mon-Sat 09:00-12:00 and 14:00-17:00"* ]]'
  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=save_hours")
  chk "A form with no hours at all is refused" 'body | grep -q "Enter working hours for at least one day"'

  local sp; sp=$(mkvisit Schedwell 0); local spid=${sp%% *}
  h=$(get rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D")
  chk "The booking page offers only the times inside the doctor's hours and states them" '[[ "$h" == *"value=\"09:00\""* && "$h" == *"value=\"11:40\""* && "$h" == *"value=\"14:00\""* && "$h" != *"value=\"08:00\""* && "$h" != *"value=\"12:00\""* && "$h" != *"value=\"13:40\""* && "$h" == *"Mon-Sat 09:00-12:00 and 14:00-17:00"* ]]'
  r=$(form rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D" "appointments/book.php?patient_id=$spid" --data-urlencode "patient_id=$spid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$D" --data-urlencode "start_time=12:20")
  chk "Forcing a time outside the hours (a hand-made request) is refused by the server" 'body | grep -q "does not work at that time"'
  r=$(form rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D" "appointments/book.php?patient_id=$spid" --data-urlencode "patient_id=$spid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$D" --data-urlencode "start_time=10:00" --data-urlencode "reason=Review")
  chk "A time inside the hours books normally" '[[ "$r" == 303* ]]'

  # leave over the booked day: the booking stays, becomes a conflict, and everyone who must act is told
  local L2; L2=$(date -d "$D +1 day" +%F)
  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=add_leave" --data-urlencode "start_date=$D" --data-urlencode "end_date=$L2" --data-urlencode "type=Sick leave" --data-urlencode "reason=Private medical reason")
  h=$(get mgr "admin/schedule.php?doctor=$docid")
  chk "Recording leave over a booked day warns that 1 appointment must be moved, and lists it by outpatient number, not by name" '[[ "$r" == 303* && "$h" == *"1 booked appointment"* && "$h" == *"inside recorded leave"* && "$h" != *"Schedwell"* ]]'
  h=$(get rec appointments/conflicts.php)
  chk "The receptionist sees the appointment on the 'Appointments to move' page with the patient's name and the problem" '[[ "$h" == *"Schedwell"* && "$h" == *"Doctor away"* && "$h" == *"Book new time"* ]]'
  h=$(get rec index.php)
  chk "The receptionist's bell and sidebar show the appointment to move" '[[ "$h" == *"booked appointment to move"* && "$h" == *"Appointments to move"* ]]'
  h=$(get doc profile.php)
  chk "The doctor sees their own hours and leave on their profile" '[[ "$h" == *"Your working hours"* && "$h" == *"09:00 to 12:00"* && "$h" == *"Your leave"* && "$h" == *"Sick leave"* ]]'
  h=$(get nurse profile.php)
  chk "A nurse's profile has no hours or leave panels" '[[ "$h" != *"Your working hours"* && "$h" != *"Your leave"* ]]'
  h=$(get rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D")
  chk "On a day of leave the booking page says the doctor is away, offers no times and does not say why" '[[ "$h" == *"is away on that date"* && "$h" != *"name=\"start_time\""* && "$h" != *"Sick leave"* && "$h" != *"Private medical"* ]]'
  r=$(form rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D" "appointments/book.php?patient_id=$spid" --data-urlencode "patient_id=$spid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$D" --data-urlencode "start_time=10:20")
  chk "A hand-made booking on a day of leave is refused" 'body | grep -q "is away on that date"'

  local aid; aid=$(php_eval x 'echo db_value("SELECT id FROM appointments WHERE patient_id = ? AND status = ?", ['"$spid"', "Booked"]);')
  r=$(form rec appointments/conflicts.php appointments/action.php --data-urlencode "id=$aid" --data-urlencode "status=Cancelled" --data-urlencode "reason=Doctor not available at that time" --data-urlencode "return=conflicts")
  h=$(get rec appointments/conflicts.php)
  chk "Cancelling from the list returns to the list, which is now empty" '[[ "$r" == 303* && "$r" == *conflicts.php* && "$h" == *"Every booked appointment fits"* ]]'

  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=add_leave" --data-urlencode "start_date=$D" --data-urlencode "end_date=$D" --data-urlencode "type=Training")
  chk "Leave that overlaps leave already recorded is refused" 'body | grep -q "overlaps leave that is already recorded"'
  local lid; lid=$(php_eval x 'echo db_value("SELECT id FROM doctor_leave WHERE doctor_id = ? ORDER BY id LIMIT 1", ['"$docid"']);')
  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=remove_leave" --data-urlencode "leave_id=$lid")
  r=$(form rec "appointments/book.php?patient_id=$spid&doctor_id=$docid&date=$D" "appointments/book.php?patient_id=$spid" --data-urlencode "patient_id=$spid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$D" --data-urlencode "start_time=10:00")
  chk "After the leave is removed the same day can be booked again" '[[ "$r" == 303* ]]'
  r=$(post mgr admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=remove_leave" --data-urlencode "leave_id=1")
  chk "A schedule change sent without the security token is refused (419)" '[[ "$r" == 419* ]]'
  r=$(form mgr "admin/schedule.php?doctor=$docid" admin/schedule.php --data-urlencode "doctor=$docid" --data-urlencode "action=clear_hours")
  h=$(get mgr admin/schedules.php)
  chk "Removing the personal schedule returns the doctor to clinic hours" '[[ "$h" == *"Clinic hours (no personal schedule set)"* ]]'
  h=$(get admin "admin/audit.php?entity=schedule"); h2=$(get admin "admin/audit.php?entity=leave")
  chk "Schedule and leave changes are in the audit trail, and the reason for leave is not" '[[ "$h" == *"Weekly hours set"* && "$h2" == *"Leave recorded for doctor"* && "$h$h2" != *"Private medical reason"* ]]'

  # ---------------------------------------------------------------- refunds
  local pv; pv=$(mkvisit Refunder 1); local rpid vid rpay rinv; read -r rpid vid rpay rinv <<< "$pv"
  h=$(get cash "billing/invoice.php?visit=$vid")
  chk "The cashier sees a Refund button next to the payment" '[[ "$h" == *"refund_request.php?payment=$rpay"* ]]'
  chk "The manager cannot open an invoice (so cannot see patient names through billing)" '[[ "$(code mgr "billing/invoice.php?visit=$vid")" == 403 ]]'
  chk "Only a cashier can ask for a refund (the manager and the other six roles get 403 on the request form)" 'bad=""; for role in admin manager receptionist nurse doctor lab pharmacist; do [ "$(code "${jar[$role]}" "billing/refund_request.php?payment=$rpay")" = 403 ] || bad="$bad $role"; done; [ -z "$bad" ] && [ "$(code cash "billing/refund_request.php?payment=$rpay")" = 200 ]'
  h=$(get cash "billing/refund_request.php?payment=$rpay")
  chk "The request form shows the payment and how much can still be refunded" '[[ "$h" == *"Can still be refunded"* && "$h" == *"500.00"* ]]'
  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=200" --data-urlencode "reason=no")
  chk "A refund with no proper reason is refused and nothing is created" 'body | grep -q "Say why the money is being refunded" && [ "$(php_eval x "echo db_value(\"SELECT COUNT(*) FROM refund_requests\");")" = 0 ]'
  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=900" --data-urlencode "reason=Paid twice at the desk")
  chk "More than the payment is refused with the most that can be given back" 'body | grep -q "most that can still be refunded on this payment is KSh 500.00"'
  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=200" --data-urlencode "reason=Paid twice at the desk")
  local rrid; rrid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  h=$(get cash "billing/refund.php?id=$rrid")
  chk "A valid request is recorded as Pending and sent for approval; the invoice is unchanged" '[[ "$r" == 303* && "$h" == *"Pending"* && "$h" == *"RF-"* && "$(php_eval x "echo db_value(\"SELECT amount_paid FROM invoices WHERE invoice_no = ?\", [\"$rinv\"]);")" == "500.00" ]]'
  r=$(form cash "billing/refund.php?id=$rrid" "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=approve")
  chk "A cashier cannot approve a refund, even with a hand-made request, and it is recorded as refused" '[ "$(php_eval x "echo db_value(\"SELECT status FROM refund_requests WHERE id = $rrid\");")" = Pending ] && get admin "admin/audit.php?action=access_denied" | grep -q "Refused a refund action"'

  h=$(get mgr index.php)
  chk "The manager's bell and sidebar show the refund waiting for a decision" '[[ "$h" == *"refund request waiting for your decision"* ]] && get mgr billing/refunds.php | grep -q "Decide"'
  h=$(get mgr "billing/refund.php?id=$rrid")
  chk "The manager sees the invoice number, amount and reason but never the patient (no name, no outpatient number, no link to the invoice)" '[[ "$h" == *"$rinv"* && "$h" == *"200.00"* && "$h" == *"Paid twice at the desk"* && "$h" != *"Refunder"* && "$h" != *"Pat Refunder"* && "$h" != *"billing/invoice.php"* && "$h" == *"Approve refund"* ]]'
  r=$(post mgr "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=approve")
  chk "A decision sent without the security token is refused (419)" '[[ "$r" == 419* ]]'
  r=$(form mgr "billing/refund.php?id=$rrid" "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=reject" --data-urlencode "note=")
  chk "A rejection without a reason is refused" '[ "$(php_eval x "echo db_value(\"SELECT status FROM refund_requests WHERE id = $rrid\");")" = Pending ]'
  r=$(form mgr "billing/refund.php?id=$rrid" "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=reject" --data-urlencode "note=The receipt shows one payment only")
  h=$(get cash "billing/refund.php?id=$rrid")
  chk "A rejected request shows the manager's reason to the cashier and moves no money" '[[ "$h" == *"Rejected"* && "$h" == *"The receipt shows one payment only"* ]] && [ "$(php_eval x "echo db_value(\"SELECT COUNT(*) FROM payments WHERE kind = ?\", [\"Refund\"]);")" = 0 ]'

  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=200" --data-urlencode "refund_reference=rev-2026" --data-urlencode "reason=Duplicate charge for the consultation")
  rrid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  r=$(form mgr "billing/refund.php?id=$rrid" "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=approve" --data-urlencode "note=Checked against the till")
  h=$(get cash "billing/invoice.php?visit=$vid")
  chk "An approved refund appears on the invoice as a negative REF line and the amount paid goes down" '[[ "$r" == 303* && "$h" == *"REF-"* && "$h" == *"KSh -200.00"* ]] && [ "$(php_eval x "echo db_value(\"SELECT amount_paid FROM invoices WHERE invoice_no = ?\", [\"$rinv\"]);")" = "300.00" ]'
  local refpay; refpay=$(php_eval x 'echo db_value("SELECT id FROM payments WHERE kind = ?", ["Refund"]);')
  h=$(get cash "billing/receipt.php?id=$refpay")
  chk "The refund has a printable voucher with the reason, the approver and the balance" '[[ "$h" == *"Refund voucher"* && "$h" == *"Duplicate charge for the consultation"* && "$h" == *"Grace Njoroge"* && "$h" == *"REV-2026"* ]]'
  h=$(get cash "billing/invoice.php?visit=$vid")
  chk "The invoice now shows it is partly paid, and the paid payment no longer offers more than is left" '[[ "$h" == *"Partially paid"* && "$h" == *"refund_request.php?payment=$rpay"* ]]'
  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=400" --data-urlencode "reason=Trying for too much")
  chk "After a part-refund only the rest can be asked for" 'body | grep -q "most that can still be refunded on this payment is KSh 300.00"'

  r=$(form cash "billing/refund_request.php?payment=$rpay" billing/refund_request.php --data-urlencode "payment=$rpay" --data-urlencode "amount=50" --data-urlencode "reason=Asked by mistake")
  rrid=$(echo "$r" | grep -o 'id=[0-9]*' | cut -d= -f2)
  r=$(form cash "billing/refund.php?id=$rrid" "billing/refund.php?id=$rrid" --data-urlencode "id=$rrid" --data-urlencode "action=withdraw")
  chk "The cashier can withdraw their own waiting request" '[ "$(php_eval x "echo db_value(\"SELECT status FROM refund_requests WHERE id = $rrid\");")" = Cancelled ]'

  h=$(get mgr reports/revenue.php)
  chk "The revenue report shows the refunded amount and collected net of refunds" '[[ "$h" == *"Refunded"* && "$h" == *"net of refunds"* ]]'
  h=$(curl -s -D - -o /dev/null -b "$J/mgr" "$B/reports/revenue.php?format=csv")
  chk "The revenue CSV carries a Refunded column" '[[ "$h" == *"text/csv"* ]] && get mgr "reports/revenue.php?format=csv" | head -1 | grep -q "Refunded"'
  h=$(get admin "admin/audit.php?action=refund_request"); h2=$(get admin "admin/audit.php?action=refund_approve"); local h3; h3=$(get admin "admin/audit.php?action=refund_reject")
  chk "Refund requests and decisions are in the audit trail with the reference and amount, never the reason" '[[ "$h" == *"RF-"*"KSh 200.00 on INV-"* && "$h2" == *"RF-"*"KSh 200.00 on INV-"* && "$h3" == *"rejected"* && "$h$h2$h3" != *"Duplicate charge for the consultation"* && "$h$h2$h3" != *"Paid twice at the desk"* ]]'
  h=$(get admin admin/health.php)
  chk "The health page reports 27 tables and a reconciled database after refunds" '[[ "$h" == *"27 tables present"* && "$h" == *"All records reconcile"* ]]'

  # ---------------------------------------------------------------- patient data: copy
  chk "Only Records can open the data-request page of a patient (the other seven roles get 403)" 'bad=""; for role in admin manager nurse doctor lab pharmacist cashier; do [ "$(code "${jar[$role]}" "patients/data.php?id=$rpid")" = 403 ] || bad="$bad $role"; done; [ -z "$bad" ] && [ "$(code rec "patients/data.php?id=$rpid")" = 200 ]'
  h=$(get rec "patients/view.php?id=$rpid")
  chk "The patient's page offers a Data requests button to Records only" '[[ "$h" == *"patients/data.php?id=$rpid"* && "$(get doc "patients/view.php?id=$rpid")" != *"patients/data.php"* ]]'
  r=$(form rec "patients/data.php?id=$rpid" patients/data.php --data-urlencode "id=$rpid" --data-urlencode "action=export" --data-urlencode "ex_requester_name=Pat Refunder" --data-urlencode "ex_requester_relation=The patient" --data-urlencode "ex_identity_note=ID seen")
  chk "A copy cannot be given until the identity check box is ticked" 'body | grep -q "Tick the box to confirm"'
  r=$(form rec "patients/data.php?id=$rpid" patients/data.php --data-urlencode "id=$rpid" --data-urlencode "action=export" --data-urlencode "ex_requester_name=Pat Refunder" --data-urlencode "ex_requester_relation=The patient" --data-urlencode "ex_identity_checked=1" --data-urlencode "ex_identity_note=National ID card seen")
  local exid; exid=$(echo "$r" | grep -o 'request=[0-9]*' | cut -d= -f2)
  h=$(get rec "patients/export.php?request=$exid")
  chk "The copy shows the details, visit, diagnosis, invoice, payments and the refund of that patient" '[[ "$r" == 303* && "$h" == *"Copy of a patient record"* && "$h" == *"Pat Refunder"* && "$h" == *"Upper respiratory infection"* && "$h" == *"$rinv"* && "$h" == *"REF-"* && "$h" == *"Duplicate charge for the consultation"* ]]'
  h=$(get rec "patients/export.php?request=$exid")
  chk "The copy page has a print button and a download link, and says the security log is not included" '[[ "$h" == *"data-print"* && "$h" == *"format=json"* && "$h" == *"does not include the hospital"* ]]'
  h=$(curl -s -D "$J/hdr" -b "$J/rec" "$B/patients/export.php?request=$exid&format=json")
  chk "The same copy downloads as a JSON file with the reference in its name" 'grep -qi "content-disposition: attachment; filename=\"DR-" "$J/hdr" && grep -qi "application/json" "$J/hdr" && echo "$h" | grep -q "\"outpatient_number\"" && echo "$h" | grep -q "Upper respiratory infection"'
  chk "The JSON file is valid JSON" 'echo "$h" | "$PHP_BIN" -r "json_decode(stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR); echo 1;" | grep -q 1'
  chk "Opening and downloading the copy are written to the audit trail" '[ "$(php_eval x "echo db_value(\"SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = $exid\", [\"data_export\"]);")" -ge 2 ]'
  chk "Other roles cannot open the copy or the file (403)" 'bad=""; for role in admin manager nurse doctor cashier; do [ "$(code "${jar[$role]}" "patients/export.php?request=$exid&format=json")" = 403 ] || bad="$bad $role"; done; [ -z "$bad" ]'
  chk "An unknown copy request is a 404" '[ "$(code rec "patients/export.php?request=999999")" = 404 ] && [ "$(code rec "patients/export.php?request=abc")" = 404 ]'
  h=$(get admin "admin/audit.php?action=data_request")
  chk "The audit trail records the request by reference and outpatient number, without the requester's name or the identity note" '[[ "$h" == *"export request for OP"* && "$h" != *"National ID card seen"* && "$h" != *"Pat Refunder"* ]]'

  # ---------------------------------------------------------------- patient data: erasure
  local ev; ev=$(mkvisit Erasable 0); local epid evid epay einv; read -r epid evid epay einv <<< "$ev"
  r=$(form rec "patients/data.php?id=$epid" patients/data.php --data-urlencode "id=$epid" --data-urlencode "action=erase" --data-urlencode "er_requester_name=Pat Erasable" --data-urlencode "er_requester_relation=The patient" --data-urlencode "er_identity_checked=1" --data-urlencode "er_identity_note=Passport seen" --data-urlencode "er_reason=Does not want the record kept")
  local erid; erid=$(echo "$r" | grep -o 'id=[0-9]*' | tail -1 | cut -d= -f2)
  h=$(get rec "patients/data.php?id=$epid")
  chk "An erasure request is recorded (not carried out) and the blockers are explained: an open visit and money still owed" '[[ "$r" == 303* && "$h" == *"open visit"* && "$h" == *"balance of KSh 500.00"* ]] && [ "$(php_eval x "echo db_value(\"SELECT status FROM data_requests WHERE id = $erid\");")" = Open ] && [[ "$(get rec "patients/view.php?id=$epid")" == *"Pat Erasable"* ]]'
  r=$(form rec "privacy/request.php?id=$erid" "privacy/request.php?id=$erid" --data-urlencode "id=$erid" --data-urlencode "action=approve")
  chk "Records cannot decide the request (a hand-made approval is refused and nothing is erased)" '[ "$(php_eval x "echo db_value(\"SELECT status FROM data_requests WHERE id = $erid\");")" = Open ] && [[ "$(get rec "patients/view.php?id=$epid")" == *"Pat Erasable"* ]]'
  h=$(get mgr index.php)
  chk "The manager is told that an erasure waits for a decision" '[[ "$h" == *"record erasure request waiting for your decision"* ]]'
  h=$(get mgr "privacy/request.php?id=$erid")
  chk "The manager sees the outpatient number, the blockers and what is kept, and never the patient's name, the requester's name or the identity note" '[[ "$h" == *"Not yet."* && "$h" == *"open visit"* && "$h" != *"Erasable"* && "$h" != *"Passport seen"* && "$h" == *"What is kept and what is removed"* && "$h" == *"Does not want the record kept"* && "$h" != *"Approve and erase the record"* ]]'
  h=$(get mgr privacy/index.php)
  chk "The manager's list of requests shows outpatient numbers only" '[[ "$h" == *"Patient data requests"* && "$h" == *"$erid"* || "$h" == *"DR-"* ]] && [[ "$h" != *"Erasable"* && "$h" != *"Refunder"* ]]'
  r=$(form mgr "privacy/request.php?id=$erid" "privacy/request.php?id=$erid" --data-urlencode "id=$erid" --data-urlencode "action=approve" --data-urlencode "note=")
  chk "Even a hand-made approval is refused while something blocks it, and the record is untouched" '[ "$(php_eval x "echo db_value(\"SELECT status FROM data_requests WHERE id = $erid\");")" = Open ] && [[ "$(get rec "patients/view.php?id=$epid")" == *"Pat Erasable"* ]]'

  # clear the blockers (finish the visit, nothing is owed), then the manager can decide
  php_eval x '$doc = (int) db_value("SELECT id FROM users WHERE username = ?", ["doctor1"]); $cash = (int) db_value("SELECT id FROM users WHERE username = ?", ["cashier1"]); close_visit('"$evid"', $doc); $inv = get_invoice_for_visit('"$evid"'); record_payment((int) $inv["id"], ["method" => "Cash", "amount" => $inv["total"], "reference" => ""], $cash);' >/dev/null
  h=$(get mgr "privacy/request.php?id=$erid")
  chk "Once nothing blocks it the manager is offered the erasure" '[[ "$h" == *"Nothing stops it."* && "$h" == *"Approve and erase the record"* && "$h" == *"Refuse the request"* ]]'
  local collected_before; collected_before=$(php_eval x 'echo db_value("SELECT COALESCE(SUM(amount), 0) FROM payments");')
  local invoices_before; invoices_before=$(php_eval x 'echo db_value("SELECT COUNT(*) FROM invoices");')
  r=$(post mgr "privacy/request.php?id=$erid" --data-urlencode "id=$erid" --data-urlencode "action=approve")
  chk "An approval without the security token is refused (419) and erases nothing" '[[ "$r" == 419* ]] && [[ "$(get rec "patients/view.php?id=$epid")" == *"Pat Erasable"* ]]'
  r=$(form mgr "privacy/request.php?id=$erid" "privacy/request.php?id=$erid" --data-urlencode "id=$erid" --data-urlencode "action=approve" --data-urlencode "note=Checked")
  h=$(get rec "patients/view.php?id=$epid")
  chk "Approving erases the record: the name and details are gone and the page says so" '[[ "$r" == 303* && "$h" == *"This record was erased"* && "$h" == *"Erased Patient"* && "$h" != *"Erasable"* && "$h" != *"Ruaka"* && "$h" != *"None known"* && "$h" != *"Check in for a visit"* && "$h" != *"Correct details"* ]]'
  chk "The money and the invoices are unchanged by the erasure" '[ "$(php_eval x "echo db_value(\"SELECT COALESCE(SUM(amount), 0) FROM payments\");")" = "$collected_before" ] && [ "$(php_eval x "echo db_value(\"SELECT COUNT(*) FROM invoices\");")" = "$invoices_before" ]'
  h=$(get rec "patients/index.php?q=Erasable")
  chk "The erased patient can no longer be found by their old name (the search box repeats what was typed, but no result is listed)" '[[ "$h" != *"patients/view.php?id=$epid"* && "$h" != *"Pat Erasable"* ]]'
  h=$(get rec "patients/data.php?id=$epid")
  chk "The data page of an erased record offers no forms" '[[ "$h" == *"has been erased"* && "$h" != *"Record the erasure request"* && "$h" != *"Record the request and show the copy"* ]]'
  r=$(form rec "visits/checkin.php?patient_id=$epid" "visits/checkin.php?patient_id=$epid" --data-urlencode "patient_id=$epid" --data-urlencode "appointment_id=" --data-urlencode "reason=Cough")
  chk "An erased record cannot be checked in again, even with a hand-made request" 'body | grep -q "has been erased"'
  r=$(form rec "appointments/book.php?patient_id=$epid&doctor_id=$docid&date=$D" "appointments/book.php?patient_id=$epid" --data-urlencode "patient_id=$epid" --data-urlencode "doctor_id=$docid" --data-urlencode "date=$D" --data-urlencode "start_time=10:00")
  chk "An erased record cannot be booked, even with a hand-made request" 'body | grep -q "has been erased"'
  h=$(get rec "privacy/request.php?id=$erid")
  chk "Records sees the completed request with the requester's details blanked" '[[ "$h" == *"Completed"* && "$h" == *"[erased]"* && "$h" != *"Pat Erasable"* && "$h" != *"Passport seen"* ]]'
  h=$(get mgr "privacy/request.php?id=$erid")
  chk "The manager sees the completed request and still no name" '[[ "$h" == *"Completed"* && "$h" != *"Erasable"* ]]'
  h=$(get admin "admin/audit.php?action=erase")
  chk "The audit trail records the erasure by reference and carries no name" '[[ "$h" == *"Record anonymised (DR-"* && "$h" != *"Erasable"* ]]'
  h=$(get admin admin/health.php)
  chk "The health check still reconciles after an erasure and finds no personal details left on an erased record" '[[ "$h" == *"All records reconcile"* ]]'

  # a second request is refused by the manager with a reason
  local rv; rv=$(mkvisit Retained 1); local rtpid; rtpid=${rv%% *}
  r=$(form rec "patients/data.php?id=$rtpid" patients/data.php --data-urlencode "id=$rtpid" --data-urlencode "action=erase" --data-urlencode "er_requester_name=Pat Retained" --data-urlencode "er_requester_relation=Parent or guardian" --data-urlencode "er_identity_checked=1" --data-urlencode "er_identity_note=ID seen")
  local rtid; rtid=$(echo "$r" | grep -o 'id=[0-9]*' | tail -1 | cut -d= -f2)
  r=$(form mgr "privacy/request.php?id=$rtid" "privacy/request.php?id=$rtid" --data-urlencode "id=$rtid" --data-urlencode "action=refuse" --data-urlencode "note=")
  chk "A refusal without a reason is rejected" '[ "$(php_eval x "echo db_value(\"SELECT status FROM data_requests WHERE id = $rtid\");")" = Open ]'
  r=$(form mgr "privacy/request.php?id=$rtid" "privacy/request.php?id=$rtid" --data-urlencode "id=$rtid" --data-urlencode "action=refuse" --data-urlencode "note=Records must be kept for the insurer")
  h=$(get rec "privacy/request.php?id=$rtid")
  chk "A refused request keeps the record and shows Records the reason" '[[ "$h" == *"Refused"* && "$h" == *"Records must be kept for the insurer"* && "$(get rec "patients/view.php?id=$rtpid")" == *"Pat Retained"* ]]'

  # ---------------------------------------------------------------- shared checks
  bad=""
  for p in "mgr admin/schedules.php" "mgr admin/schedule.php?doctor=$docid" "rec appointments/conflicts.php" "cash billing/refunds.php" "cash billing/refund_request.php?payment=$rpay" "mgr billing/refunds.php" "mgr privacy/index.php" "rec privacy/index.php" "rec patients/data.php?id=$rpid" "rec privacy/request.php?id=$erid" "mgr privacy/request.php?id=$erid" "rec patients/export.php?request=$exid" "cash billing/refund.php?id=$rrid" "doc profile.php"; do
    set -- $p
    h=$(get "$1" "$2")
    [ "$(code "$1" "$2")" = 200 ] || bad="$bad $2(status)"
    echo "$h" | grep -Eq 'Warning:|Notice:|Fatal error|Deprecated:|Stack trace' && bad="$bad $2(php)"
    echo "$h" | grep -Eq '<script>|<script [^>]*[^>]>[^<]|[[:space:]]on(click|load|error|submit|mouseover)=|[[:space:]]style="' && bad="$bad $2(inline)"
    [ "$(echo "$h" | grep -o '<h1' | wc -l)" = 1 ] || bad="$bad $2(h1)"
  done
  chk "Every new page opens, shows no PHP message, has one main heading and no inline script, handler or style (strict CSP)" '[ -z "$bad" ]' || echo "      problems:$bad"

  # the access matrix for the new pages, driven by the permission table
  local pages=(
    "admin/schedules.php:schedules.manage" "admin/schedule.php?doctor=$docid:schedules.manage" "appointments/conflicts.php:appointments.update"
    "billing/refunds.php:refunds.view" "billing/refund_request.php?payment=$rpay:refunds.request" "billing/refund.php?id=$rrid:refunds.view"
    "privacy/index.php:privacy.view" "privacy/request.php?id=$erid:privacy.view" "patients/data.php?id=$rpid:patients.export" "patients/export.php?request=$exid:patients.export"
  )
  local combos=0 wrong=0 page perm expected pair role want got
  for entry in "${pages[@]}"; do
    page=${entry%:*}; perm=${entry##*:}
    expected=$(php_eval x 'foreach (permission_matrix() as $r => $p) { echo $r, ":", in_array("'"$perm"'", $p, true) ? "200" : "403", " "; }')
    bad=""
    for pair in $expected; do
      role=${pair%%:*}; want=${pair##*:}
      got=$(code "${jar[$role]}" "$page"); combos=$((combos+1))
      [ "$got" = "$want" ] || { bad="$bad $role(got $got, expected $want)"; wrong=$((wrong+1)); }
    done
    [ -z "$bad" ] || echo "      $page mismatch:$bad"
  done
  chk "All $combos role-and-page combinations for the new pages behave exactly as the permission table says" '[ "$wrong" -eq 0 ]'
  h=$(get rec privacy.php); h2=$(get rec terms.php)
  chk "The legal pages no longer carry the 'not reviewed by a lawyer' notice" '[[ "$(curl -s "$B/privacy.php")" != *"not been reviewed by a lawyer"* && "$(curl -s "$B/terms.php")" != *"not been reviewed by a lawyer"* ]]'
  h=$(curl -s "$B/privacy.php")
  chk "The privacy policy describes the copy and erasure process that now exists" '[[ "$h" == *"copy of your record"* && "$h" == *"anonymis"* && "$h" != *"does not yet have a button"* && "$h" != *"does not yet apply retention"* ]]'
}

# Later sprints add their own sec_* functions above this line.
for fn in sec_sprint1 sec_sprint2 sec_sprint3 sec_sprint4 sec_sprint5 sec_public sec_dashboards sec_rights; do
  if declare -F "$fn" > /dev/null; then "$fn"; fi
done

echo
echo "----------------------------------------"
echo "End-to-end checks: $((pass+fail))   Passed: $pass   Failed: $fail"
echo "Finished: $(date '+%Y-%m-%d %H:%M:%S')" >> "$OUT/e2e-results.txt"
echo "Totals: $((pass+fail)) run, $pass passed, $fail failed" >> "$OUT/e2e-results.txt"
[ "$fail" -eq 0 ]
