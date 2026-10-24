"""Hand-drawn diagrams (Graphviz DOT): data flow diagrams, architecture, network, flowcharts, structured chart.
Everything is black and white. Written to <outdir>/<name>.dot and rendered by dot2png.js.

Layouts are kept compact and the fonts large so the text is still easy to read when a figure is scaled
to the width of a printed page.
"""

PRE = '''
  graph [fontname="Arial", fontsize=13, bgcolor="white", pad="0.15"];
  node  [fontname="Arial", fontsize=13, color="black", fontcolor="black"];
  edge  [fontname="Arial", fontsize=11, color="black", fontcolor="black", arrowsize=0.8];
'''

ENT = 'shape=box, style="bold", penwidth=1.6, margin="0.18,0.1"'
PROC = 'shape=ellipse, penwidth=1.4, margin="0.12,0.07"'


def store(node_id, code, label):
    """Open-ended rectangle used for data stores."""
    return (f'{node_id} [shape=none, margin=0, label=<<TABLE BORDER="0" CELLBORDER="0" CELLSPACING="0" CELLPADDING="5">'
            f'<TR><TD SIDES="TB" BORDER="1"><B>{code}</B>&nbsp;&nbsp;{label}</TD></TR></TABLE>>];')


DOTS = {}

# --------------------------------------------------------------------------------------------- DFD level 0
DOTS['dfd_context'] = 'digraph ctx {' + PRE + '''
  rankdir=LR; nodesep=0.3; ranksep=1.9;
  node [''' + ENT + '''];
  REC [label="Receptionist /\\nRecords clerk"]; NUR [label="Nurse"]; DOC [label="Doctor /\\nClinical officer"]; LAB [label="Laboratory\\ntechnologist"];
  PHA [label="Pharmacist"]; CAS [label="Cashier /\\nBilling clerk"]; MGR [label="Hospital\\nmanager"]; ADM [label="System\\nadministrator"];
  P0 [shape=circle, penwidth=2.2, width=2.7, label="0\\nUhai Bora Hospital\\nManagement System\\n(UB-HMS)"];
  REC -> P0 [label="patient details,\\nbookings, check-in"];
  P0 -> REC [label="outpatient number,\\ndiary, queues"];
  NUR -> P0 [label="vital signs,\\nprocedures done"];
  P0 -> NUR [label="triage queue,\\nallergy alerts"];
  DOC -> P0 [label="consultation, test\\nrequests, prescriptions"];
  P0 -> DOC [label="patient history,\\ntest results, stock"];
  LAB -> P0 [label="test results"];
  P0 -> LAB [label="test worklist"];
  P0 -> PHA [label="prescriptions,\\nstock alerts"];
  PHA -> P0 [label="dispensing, stock\\nreceived, medicine list", constraint=false];
  P0 -> CAS [label="invoices,\\nreceipts"];
  CAS -> P0 [label="payments", constraint=false];
  P0 -> MGR [label="management reports,\\naudit trail"];
  MGR -> P0 [label="report requests", constraint=false];
  P0 -> ADM [label="audit trail,\\nsystem health"];
  ADM -> P0 [label="accounts, prices,\\nbackup", constraint=false];
  {rank=same; REC; NUR; DOC; LAB;}
  {rank=same; PHA; CAS; MGR; ADM;}
}
'''

# --------------------------------------------------------------------------------------------- DFD level 1
DOTS['dfd_level1'] = 'digraph l1 {' + PRE + '''
  rankdir=LR; nodesep=0.12; ranksep=0.85; splines=true; newrank=true;
  node [''' + ENT + ''', fontsize=12];
  edge [fontsize=10];
  REC [label="Receptionist"]; NUR [label="Nurse"]; DOC [label="Doctor"]; LAB [label="Laboratory\\ntechnologist"];
  PHA [label="Pharmacist"]; CAS [label="Cashier"]; MGR [label="Hospital\\nmanager"]; ADM [label="System\\nadministrator"]; ALL [label="Any staff\\nmember"];
  node [''' + PROC + ''', fontsize=12];
  P1 [label="1.0\\nRegister patients and\\nbook appointments"]; P2 [label="2.0\\nRecord clinical\\nconsultation"]; P3 [label="3.0\\nManage laboratory\\ntests"];
  P4 [label="4.0\\nManage pharmacy\\nand stock"]; P5 [label="5.0\\nBill and report"]; P6 [label="6.0\\nManage users\\nand security"];
  ''' + store('D1', 'D1', 'Patients') + '''
  ''' + store('D2', 'D2', 'Appointments') + '''
  ''' + store('D3', 'D3', 'Visits and clinical records') + '''
  ''' + store('D4', 'D4', 'Laboratory requests and results') + '''
  ''' + store('D5', 'D5', 'Prescriptions') + '''
  ''' + store('D6', 'D6', 'Medicines and stock') + '''
  ''' + store('D7', 'D7', 'Invoices and payments') + '''
  ''' + store('D8', 'D8', 'Users and roles') + '''
  ''' + store('D9', 'D9', 'Audit trail') + '''
  {rank=same; REC; NUR; DOC; LAB; PHA; CAS; MGR; ADM; ALL;}
  {rank=same; P1; P2; P3; P4; P5; P6;}
  {rank=same; D1; D2; D3; D4; D5; D6; D7; D8; D9;}
  REC -> NUR -> DOC -> LAB -> PHA -> CAS -> MGR -> ADM -> ALL [style=invis];
  P1 -> P2 -> P3 -> P4 -> P5 -> P6 [style=invis];
  D1 -> D2 -> D3 -> D4 -> D5 -> D6 -> D7 -> D8 -> D9 [style=invis];
  REC -> P1 [dir=both, label="details, bookings /\\nOP number, diary"];
  REC -> P2 [label="check-in"];
  NUR -> P2 [dir=both, label="vital signs /\\ntriage queue"];
  DOC -> P2 [dir=both, label="consultation, orders /\\nhistory, allergies"];
  P3 -> DOC [label="results to the\\nrequesting doctor", constraint=false];
  LAB -> P3 [dir=both, label="results /\\nworklist"];
  PHA -> P4 [dir=both, label="dispensing, stock /\\nqueue, alerts"];
  CAS -> P5 [dir=both, label="payments /\\ninvoices, receipts"];
  P5 -> MGR [label="reports", constraint=false];
  ALL -> P6 [dir=both, label="sign-in /\\nmenu by role"];
  ADM -> P6 [dir=both, label="accounts, prices /\\naudit, health"]; P6 -> MGR [label="audit trail", constraint=false];
  P2 -> P3 [label="test requests"]; P2 -> P4 [label="prescriptions"];
  P1 -> D1 [dir=both, label="patients"]; P1 -> D2 [dir=both, label="bookings"];
  P2 -> D1 [dir=back, label="details,\\nallergies"]; P2 -> D2 [dir=both, label="attended"]; P2 -> D3 [dir=both, label="visits, triage,\\nconsultations"];
  P3 -> D4 [dir=both, label="requests,\\nresults"];
  P4 -> D5 [dir=both, label="prescriptions,\\nitems given"]; P4 -> D6 [dir=both, label="stock in,\\nstock out"];
  D3 -> P5 [label="consultation,\\nservices"]; D4 -> P5 [label="tests done"]; D5 -> P5 [label="medicines given"];
  D1 -> P5 [label="report data"]; D2 -> P5 [label="attendance"];
  P5 -> D7 [dir=both, label="invoices,\\npayments"];
  P6 -> D8 [dir=both, label="accounts,\\nroles"]; P6 -> D9 [dir=both, label="audit records,\\naudit trail"];
}
'''

# --------------------------------------------------------------------------------------------- patient journey
DOTS['patient_journey'] = 'digraph journey {' + PRE + '''
  rankdir=TB; nodesep=0.9; ranksep=0.5;
  node [shape=box, style="rounded", fontsize=13, margin="0.2,0.12"];
  A [label=<<B>RECEPTION</B><BR/><BR ALIGN="LEFT"/>Register once (OPyy-nnnnn)<BR ALIGN="LEFT"/>Book appointment, no clash<BR ALIGN="LEFT"/>Check in, open the visit<BR ALIGN="LEFT"/>>];
  B [label=<<B>TRIAGE</B><BR/><BR ALIGN="LEFT"/>Nurse records vital signs<BR ALIGN="LEFT"/>Procedures are recorded<BR ALIGN="LEFT"/>>];
  C [label=<<B>CONSULTATION</B><BR/><BR ALIGN="LEFT"/>Doctor sees history and allergies<BR ALIGN="LEFT"/>Diagnosis: ICD-10 and words<BR ALIGN="LEFT"/>Requests tests, prescribes<BR ALIGN="LEFT"/>>];
  D [label=<<B>LABORATORY</B><BR/><BR ALIGN="LEFT"/>Worklist, enter result<BR ALIGN="LEFT"/>Result returns to the<BR ALIGN="LEFT"/>requesting doctor<BR ALIGN="LEFT"/>>];
  E [label=<<B>PHARMACY</B><BR/><BR ALIGN="LEFT"/>Prescription queue<BR ALIGN="LEFT"/>Dispense, first expiry first<BR ALIGN="LEFT"/>Stock deducted, alerts<BR ALIGN="LEFT"/>>];
  F [label=<<B>CASHIER</B><BR/><BR ALIGN="LEFT"/>Invoice built automatically<BR ALIGN="LEFT"/>Full or part payment<BR ALIGN="LEFT"/>Receipt<BR ALIGN="LEFT"/>>];
  G [label=<<B>MANAGEMENT</B><BR/><BR ALIGN="LEFT"/>Attendance, revenue, stock<BR ALIGN="LEFT"/>and laboratory reports<BR ALIGN="LEFT"/>>];
  {rank=same; D; E;}
  A -> B -> C;
  C -> D [label="  test request"]; D -> C [label="result  ", constraint=false];
  C -> E [label="  prescription"];
  C -> F [label="consultation fee", style=dashed];
  D -> F [label="test charge", style=dashed];
  E -> F [label="medicine charge", style=dashed];
  F -> G [label="records feed the reports"];
}
'''

# --------------------------------------------------------------------------------------------- architecture
DOTS['architecture'] = 'digraph arch {' + PRE + '''
  rankdir=TB; nodesep=0.3; ranksep=0.7; compound=true;
  node [shape=box, style="rounded", margin="0.15,0.09", fontsize=12];
  subgraph cluster_c { label=<<B>Presentation tier</B> (staff computers)>; style="rounded"; penwidth=1.4;
    B1 [label="Web browser\\nChrome, Edge or Firefox"]; B2 [label="HTML5 pages and\\nCSS3 stylesheet"]; B3 [label="JavaScript\\n(small extras)"]; {rank=same; B1; B2; B3;} }
  subgraph cluster_a { label=<<B>Application tier</B> (Apache web server with PHP 8)>; style="rounded"; penwidth=1.4;
    A1 [label="Pages\\none folder per\\ndepartment"]; A2 [label="Security layer\\nsession, CSRF,\\npermissions, headers"]; A3 [label="Business rules\\none file per module"]; A4 [label="Data access\\nPDO prepared\\nstatements"]; {rank=same; A1; A2; A3; A4;} }
  subgraph cluster_d { label=<<B>Data tier</B> (MariaDB in XAMPP)>; style="rounded"; penwidth=1.4;
    D1 [shape=cylinder, label="uhai_bora_hms\\n22 tables"]; D2 [shape=cylinder, label="Audit trail\\n(append-only)"]; {rank=same; D1; D2;} }
  subgraph cluster_s { label=<<B>Server files</B>>; style="rounded,dashed";
    F1 [shape=note, label="Application logs\\nstorage/logs"]; F2 [shape=note, label="Nightly backups\\nstorage/backups"]; {rank=same; F1; F2;} }
  B1 -> A1 [dir=both, label="  HTTP over the hospital LAN\\n  (HTTPS recommended)", ltail=cluster_c, lhead=cluster_a];
  B2 -> B1 [dir=none, style=dotted]; B3 -> B1 [dir=none, style=dotted];
  A1 -> A2 -> A3 -> A4;
  A4 -> D1 [dir=both, label="  SQL"]; A2 -> D2 [label="  audit records"];
  A2 -> F1 [style=dashed, label="  errors"]; D1 -> F2 [style=dashed, label="  backup.ps1"];
}
'''

# --------------------------------------------------------------------------------------------- network / deployment
DOTS['network'] = 'digraph net {' + PRE + """
  rankdir=TB; nodesep=0.28; ranksep=0.75;
  node [shape=box, margin="0.14,0.08", fontsize=12];
  UPS [shape=ellipse, label="UPS\\n(backup power)"]; SRV [label="Server computer\\nXAMPP: Apache, PHP 8,\\nMariaDB; UB-HMS and\\nits database", style="bold", penwidth=1.8]; BK [shape=cylinder, label="External hard disk\\n(copy of backups)"];
  SW [shape=box, style="bold", penwidth=1.8, label="Network switch (wired hospital LAN)"];
  W1 [label="Reception\\nPC"]; W2 [label="Triage\\nPC"]; W3 [label="Doctors'\\nPCs"]; W4 [label="Laboratory\\nPC"]; W5 [label="Pharmacy\\nPC"]; W6 [label="Cashier\\nPC"]; W7 [label="Manager's\\nPC"]; W8 [label="Administrator's\\nPC"];
  P1 [shape=note, label="Office\\nprinter"]; P2 [shape=note, label="Office\\nprinter"]; P3 [shape=note, label="Receipt\\nprinter"];
  {rank=same; UPS; SRV; BK;} {rank=same; W1; W2; W3; W4; W5; W6; W7; W8;} {rank=same; P1; P2; P3;}
  UPS -> SRV [dir=none, style=dashed, label=" power"]; SRV -> BK [dir=none, style=dashed, label=" copy"];
  SRV -> SW [dir=none, penwidth=1.8];
  SW -> W1 [dir=none]; SW -> W2 [dir=none]; SW -> W3 [dir=none]; SW -> W4 [dir=none]; SW -> W5 [dir=none]; SW -> W6 [dir=none]; SW -> W7 [dir=none]; SW -> W8 [dir=none];
  W1 -> P1 [dir=none, style=dotted]; W5 -> P2 [dir=none, style=dotted]; W6 -> P3 [dir=none, style=dotted];
}
"""

# --------------------------------------------------------------------------------------------- implementation components (Chapter 6)
DOTS['components'] = 'digraph comp {' + PRE + '''
  rankdir=TB; nodesep=0.25; ranksep=0.55; compound=true;
  node [shape=box, style="rounded", margin="0.1,0.07", fontsize=12];
  subgraph cluster_pages { label=<<B>Pages: what the browser asks for</B>>; style="rounded";
    pg1 [label="login, logout,\\npassword, index"]; pg2 [label="patients/\\nappointments/\\nvisits/"]; pg3 [label="lab/\\npharmacy/"]; pg4 [label="billing/\\nreports/"]; pg5 [label="admin/"]; {rank=same; pg1; pg2; pg3; pg4; pg5;} }
  subgraph cluster_rules { label=<<B>includes/: business rules, one file per module</B>>; style="rounded";
    r1 [label="patients.php\\nappointments.php"]; r2 [label="clinical.php\\norders.php"]; r3 [label="laboratory.php\\npharmacy.php"]; r4 [label="billing.php\\nreports.php"]; r5 [label="users.php, health.php\\ndashboard.php"]; {rank=same; r1; r2; r3; r4; r5;} }
  subgraph cluster_core { label=<<B>includes/: shared code</B>>; style="rounded";
    c1 [label="bootstrap.php\\nheaders, session,\\nerror handling"]; c2 [label="auth.php, csrf.php,\\naudit.php\\nsecurity layer"]; c3 [label="helpers.php,\\nlayout.php\\nforms and page frame"]; c4 [label="db.php\\nPDO helpers,\\ntransactions"]; {rank=same; c1; c2; c3; c4;} }
  subgraph cluster_db { label=<<B>MariaDB</B>>; style="rounded"; db [shape=cylinder, label="uhai_bora_hms\\n22 tables"]; }
  subgraph cluster_ops { label=<<B>Operations</B>>; style="rounded,dashed"; o1 [label="database/\\ninstall.php\\nschema.sql"]; o2 [label="scripts/\\nbackup.ps1\\nrestore.ps1"]; o3 [label="tests/\\nrun_tests.php\\ne2e.sh"]; {rank=same; o1; o2; o3;} }
  pg3 -> r3 [ltail=cluster_pages, lhead=cluster_rules]; r3 -> c3 [ltail=cluster_rules, lhead=cluster_core]; c4 -> db [dir=both, ltail=cluster_core];
  o2 -> db [style=dashed]; o1 -> db [style=dashed]; o3 -> db [style=dashed];
}
'''

# --------------------------------------------------------------------------------------------- screen map (Chapter 6 and 8)
DOTS['screen_map'] = 'digraph nav {' + PRE + """
  rankdir=LR; nodesep=0.1; ranksep=0.3; compound=true;
  node [shape=box, style="rounded", margin="0.08,0.05", fontsize=11];
  edge [fontsize=10];
  START [label="Sign in", style="rounded,bold"]; PW [label="Change\\npassword\\n(first time)"]; DASH [label="Dashboard\\n(menu shows only what\\nthe role may do)", style="rounded,bold"];
  START -> PW; START -> DASH; PW -> DASH;
  subgraph cluster_1 { label="Patients and appointments"; style="rounded"; a1 [label="Search\\npatients"]; a2 [label="Patient\\nrecord"]; a3 [label="Register\\npatient"]; a4 [label="Import\\npatients"]; a5 [label="Appointment\\ndiary"]; a6 [label="Book\\nappointment"]; a1 -> a2; a3 -> a4 [style=invis]; a2 -> a3 [style=invis]; a4 -> a5 [style=invis]; a5 -> a6; }
  subgraph cluster_2 { label="Clinic"; style="rounded"; b1 [label="Check-in"]; b2 [label="Triage\\nqueue"]; b3 [label="Triage\\nform"]; b4 [label="Consultation\\nqueue"]; b5 [label="Consultation (tests,\\nprescriptions)"]; b1 -> b2; b2 -> b3; b3 -> b4 [style=invis]; b4 -> b5; }
  subgraph cluster_3 { label="Laboratory"; style="rounded"; c1 [label="Laboratory\\nworklist"]; c2 [label="Enter\\nresult"]; c3 [label="My test\\nresults"]; c1 -> c2; c2 -> c3 [style=dashed]; }
  subgraph cluster_4 { label="Pharmacy"; style="rounded"; d1 [label="Prescription\\nqueue"]; d2 [label="Dispense"]; d3 [label="Medicine\\nstock"]; d4 [label="Batches"]; d5 [label="Receive\\nstock"]; d6 [label="Medicine\\nlist"]; d1 -> d2; d2 -> d3 [style=invis]; d3 -> d4; d4 -> d5 [style=invis]; d5 -> d6 [style=invis]; }
  subgraph cluster_5 { label="Billing and reports"; style="rounded"; e1 [label="Invoices"]; e2 [label="Invoice and\\npayment"]; e3 [label="Receipt"]; e4 [label="Reports"]; e1 -> e2 -> e3; e3 -> e4 [style=invis]; }
  subgraph cluster_6 { label="Administration"; style="rounded"; f1 [label="User\\naccounts"]; f2 [label="Price\\nlist"]; f3 [label="Audit\\ntrail"]; f4 [label="System\\nhealth"]; f1 -> f2 -> f3 -> f4 [style=invis]; }
  DASH -> a1 [lhead=cluster_1]; DASH -> b1 [lhead=cluster_2]; DASH -> c1 [lhead=cluster_3]; DASH -> d1 [lhead=cluster_4]; DASH -> e1 [lhead=cluster_5]; DASH -> f1 [lhead=cluster_6];
}
"""

# --------------------------------------------------------------------------------------------- structured chart
TREE = ('UB-HMS', [
    ('1. Patient registration\nand appointments', ['1.1 Register patient', '1.2 Check duplicates', '1.3 Issue OP number', '1.4 Search and correct', '1.5 Import from CSV', '1.6 Book appointment', '1.7 Change appointment status']),
    ('2. Clinical\nconsultation', ['2.1 Check in patient', '2.2 Show queues', '2.3 Record triage', '2.4 Record consultation', '2.5 Record procedures', '2.6 Show patient history', '2.7 Close visit']),
    ('3. Laboratory\nmanagement', ['3.1 Request tests', '3.2 Show worklist', '3.3 Start test', '3.4 Enter or correct result', '3.5 Return result to doctor']),
    ('4. Pharmacy and\ninventory', ['4.1 Maintain medicine list', '4.2 Receive stock batch', '4.3 Prescribe', '4.4 Dispense, first expiry first', '4.5 Adjust stock', '4.6 Raise stock alerts']),
    ('5. Billing\nand reports', ['5.1 Build invoice', '5.2 Take payment', '5.3 Print receipt', '5.4 Attendance report', '5.5 Revenue report', '5.6 Stock report', '5.7 Laboratory report', '5.8 Export to CSV']),
    ('6. User management\nand security', ['6.1 Sign in and out', '6.2 Manage accounts', '6.3 Check permissions', '6.4 Record audit trail', '6.5 Price list', '6.6 System health and backup']),
])


def structured_chart():
    lines = ['digraph tree {' + PRE + '  rankdir=TB; nodesep=0.18; ranksep=0.55; node [shape=box, fontsize=12, margin="0.1,0.07"];',
             f'  root [label="{TREE[0]}", style="bold", penwidth=2, fontsize=15];',
             '  {rank=same;']
    for i in range(len(TREE[1])):
        lines.append(f'  m{i};')
    lines.append('  }')
    for i, (title, leaves) in enumerate(TREE[1]):
        lines.append(f'  m{i} [label="{title}", style="bold", penwidth=1.5, fontsize=13];')
        lines.append(f'  root -> m{i} [arrowhead=none];')
        # the modules of one process, stacked as a column of boxes
        cells = '|'.join(l.replace('\n', ' ') for l in leaves)
        lines.append(f'  l{i} [shape=record, fontsize=11.5, margin="0.08,0.05", label="{{{cells}}}"];')
        lines.append(f'  m{i} -> l{i} [arrowhead=none];')
    lines.append('}')
    return '\n'.join(lines) + '\n'


DOTS['structured_chart'] = structured_chart()


def write_all(outdir):
    import os
    os.makedirs(outdir, exist_ok=True)
    from diagrams_flow import FLOWS
    DOTS.update(FLOWS)
    for name, text in DOTS.items():
        with open(os.path.join(outdir, name + '.dot'), 'w', encoding='utf-8', newline='\n') as f:
            f.write(text)
    return list(DOTS)
