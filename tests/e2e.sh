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
  if eval "$3"; then record PASS "$1" "$2"; else record FAIL "$1" "$2"; fi
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
"$PHP_BIN" "$ROOT/database/install.php" --force --demo --admin-user="$ADMIN_USER" --admin-pass="$ADMIN_PW1" > "$J/install.log" 2>&1 || { cat "$J/install.log"; echo "Install failed"; exit 2; }
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
  for i in 1 2 3 4 5; do login lock manager1 "Wrong#Password$i" >/dev/null; done
  r=$(login lock manager1 "$DEMO_PW")
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

# Later sprints add their own sec_* functions above this line.
for fn in sec_sprint1 sec_sprint2 sec_sprint3 sec_sprint4 sec_sprint5; do
  if declare -F "$fn" > /dev/null; then "$fn"; fi
done

echo
echo "----------------------------------------"
echo "End-to-end checks: $((pass+fail))   Passed: $pass   Failed: $fail"
echo "Finished: $(date '+%Y-%m-%d %H:%M:%S')" >> "$OUT/e2e-results.txt"
echo "Totals: $((pass+fail)) run, $pass passed, $fail failed" >> "$OUT/e2e-results.txt"
[ "$fail" -eq 0 ]
