"""Flowcharts for the six level-1 processes (eight charts), built from a small specification.

Each chart is a list of nodes (id, kind, text) and edges (from, to, label[, 'loose']). Text is wrapped to a
sensible width for each shape so the charts stay narrow enough to read on a printed page.
Kinds: T start/end, S process, Q decision, IO input or output.
"""
import textwrap

PRE = '''
  graph [fontname="Arial", fontsize=13, bgcolor="white", pad="0.15"];
  node  [fontname="Arial", fontsize=13, color="black", fontcolor="black"];
  edge  [fontname="Arial", fontsize=11, color="black", fontcolor="black", arrowsize=0.8];
'''

SHAPES = {
    'T': ('shape=box, style="rounded,bold", penwidth=1.5', 0),
    'S': ('shape=box', 30),
    'Q': ('shape=diamond, margin="0.0,0.0"', 21),
    'IO': ('shape=parallelogram', 26),
}


def flow(name, nodes, edges, same=()):
    out = [f'digraph {name} {{' + PRE + '  rankdir=TB; nodesep=0.3; ranksep=0.24;\n  node [fontsize=12, margin="0.1,0.05"];\n  edge [fontsize=11];']
    for nid, kind, text in nodes:
        style, width = SHAPES[kind]
        text = ' '.join(text.split())
        label = '\\n'.join(textwrap.wrap(text, width)) if width else text
        out.append(f'  {nid} [{style}, label="{label}"];')
    for group in same:
        out.append('  {rank=same; ' + '; '.join(group) + ';}')
    for e in edges:
        a, b = e[0], e[1]
        lab = e[2] if len(e) > 2 else ''
        loose = len(e) > 3 and e[3] == 'loose'
        attrs = []
        if lab:
            attrs.append(f'label="{lab}"')
        if loose:
            attrs.append('constraint=false')
        out.append(f'  {a} -> {b}' + (f' [{", ".join(attrs)}]' if attrs else '') + ';')
    out.append('}')
    return '\n'.join(out) + '\n'


FLOWS = {}

FLOWS['flow_register'] = flow('register', [
    ('s', 'T', 'Start'), ('i', 'IO', "Receive the patient's details from the form"),
    ('v', 'S', 'Validate every field: names, sex, date of birth, phone number and ID number'),
    ('d1', 'Q', 'Any errors?'), ('e1', 'IO', 'Show the messages beside the fields'),
    ('k', 'S', 'Look for the same ID number, and for the same name and date of birth'),
    ('d2', 'Q', 'ID number already used?'), ('e2', 'IO', 'Refuse and show the existing OP number'),
    ('d3', 'Q', 'Similar patient found, not yet confirmed?'), ('e3', 'IO', 'Show similar records and ask staff to confirm'),
    ('w', 'S', "In one transaction: add 1 to this year's counter, build OPyy-nnnnn, save the record and write the audit entry"),
    ('o', 'IO', 'Show the new record and its OP number'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 'v'), ('v', 'd1'), ('d1', 'e1', 'yes'), ('d1', 'k', 'no'), ('k', 'd2'), ('d2', 'e2', 'yes'), ('d2', 'd3', 'no'),
     ('d3', 'e3', 'yes'), ('d3', 'w', 'no'), ('w', 'o'), ('o', 'f')],
    same=[('d1', 'e1'), ('d2', 'e2'), ('d3', 'e3')])

FLOWS['flow_book'] = flow('book', [
    ('s', 'T', 'Start'), ('i', 'IO', 'Receive patient, doctor, date, time and reason'),
    ('c', 'S', 'Check: patient and active doctor exist; date is today or later, within 90 days and a working day; time is on the 20-minute grid and not past'),
    ('d1', 'Q', 'All checks passed?'), ('e1', 'IO', 'Show the reason beside the field'),
    ('t', 'S', "Begin a transaction and lock the doctor's row"),
    ('d2', 'Q', 'Doctor or patient already booked then?'), ('e2', 'IO', 'Roll back; ask for another slot'),
    ('w', 'S', "Save the appointment as Booked (the database's unique slot key is the final guard), write the audit entry, commit"),
    ('o', 'IO', 'Show the diary for that day'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 'c'), ('c', 'd1'), ('d1', 'e1', 'no'), ('d1', 't', 'yes'), ('t', 'd2'), ('d2', 'e2', 'yes'), ('d2', 'w', 'no'), ('w', 'o'), ('o', 'f')],
    same=[('d1', 'e1'), ('d2', 'e2')])

FLOWS['flow_checkin'] = flow('checkin', [
    ('s', 'T', 'Start: patient arrives'), ('d0', 'Q', 'Open visit already?'), ('x0', 'IO', 'Refuse; show the open visit'),
    ('d1', 'Q', 'Booked appointment today?'), ('a1', 'S', 'Mark the appointment Attended'), ('a2', 'S', 'Record a walk-in'),
    ('v', 'S', 'Open the visit (waiting for triage)'), ('tr', 'IO', 'Nurse enters the vital signs'),
    ('d2', 'Q', 'Values plausible?'), ('e1', 'IO', 'Show messages and ask again'),
    ('st', 'S', 'Save triage and write the audit entry (visit is now waiting for the doctor)'), ('f', 'T', 'End')],
    [('s', 'd0'), ('d0', 'x0', 'yes'), ('d0', 'd1', 'no'), ('d1', 'a1', 'yes'), ('d1', 'a2', 'no'), ('a1', 'v'), ('a2', 'v'), ('v', 'tr'), ('tr', 'd2'),
     ('d2', 'e1', 'no'), ('e1', 'tr', '', 'loose'), ('d2', 'st', 'yes'), ('st', 'f')],
    same=[('d0', 'x0'), ('a1', 'a2'), ('d2', 'e1')])

FLOWS['flow_consult'] = flow('consult', [
    ('s', 'T', 'Start: patient is waiting for the doctor'), ('c', 'IO', 'Doctor enters complaint, diagnosis, optional ICD-10 code and plan'),
    ('d3', 'Q', 'Complaint, diagnosis, code valid?'), ('e2', 'IO', 'Show messages and ask again'),
    ('d5', 'Q', 'Recorded by another doctor?'), ('e4', 'IO', 'Refuse: only that doctor can change it'),
    ('sc', 'S', 'Save the consultation, record the doctor, charge the consultation fee, write the audit entry'),
    ('o', 'S', 'Doctor requests tests, prescribes and records procedures (own pages)'), ('cl', 'S', 'Doctor closes the visit'),
    ('d4', 'Q', 'Consultation recorded?'), ('e3', 'IO', 'Refuse: record the consultation first'), ('f', 'T', 'End (visit closed)')],
    [('s', 'c'), ('c', 'd3'), ('d3', 'e2', 'no'), ('e2', 'c', '', 'loose'), ('d3', 'd5', 'yes'), ('d5', 'e4', 'yes'), ('d5', 'sc', 'no'), ('sc', 'o'), ('o', 'cl'),
     ('cl', 'd4'), ('d4', 'e3', 'no'), ('e3', 'o', '', 'loose'), ('d4', 'f', 'yes')],
    same=[('d3', 'e2'), ('d5', 'e4'), ('d4', 'e3')])

FLOWS['flow_lab'] = flow('lab', [
    ('s', 'T', 'Start'), ('i', 'IO', 'Doctor chooses one or more tests'),
    ('d1', 'Q', 'Visit open and consultation recorded?'), ('e0', 'IO', 'Refuse with the reason'),
    ('d2', 'Q', 'Same test still pending for this visit?'), ('e1', 'IO', 'Roll back everything; name the test'),
    ('w', 'S', "In one transaction save each request with the doctor's id and add the test price to the invoice"),
    ('l1', 'IO', 'Technologist starts the test and enters the result and range flag'),
    ('d3', 'Q', 'Request cancelled by the doctor?'), ('e2', 'IO', 'Refuse; no result can be entered'),
    ('r', 'S', 'Save the result, who entered it and when; mark Completed; show it to the requesting doctor'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 'd1'), ('d1', 'e0', 'no'), ('d1', 'd2', 'yes'), ('d2', 'e1', 'yes'), ('d2', 'w', 'no'), ('w', 'l1'), ('l1', 'd3'),
     ('d3', 'e2', 'yes'), ('d3', 'r', 'no'), ('r', 'f')],
    same=[('d1', 'e0'), ('d2', 'e1'), ('d3', 'e2')])

FLOWS['flow_dispense'] = flow('dispense', [
    ('s', 'T', 'Start'), ('i', 'IO', 'Pharmacist opens a prescription and enters the quantity to give for each line'),
    ('t', 'S', 'Begin a transaction; lock the prescription lines'), ('n', 'S', 'Take the next line that has a quantity'),
    ('d1', 'Q', 'More than is still to give?'), ('x', 'S', 'Roll back everything; show the line that failed and why'),
    ('b', 'S', "List the medicine's unexpired batches, earliest expiry first, and lock them"), ('d2', 'Q', 'Enough unexpired stock?'),
    ('p', 'S', 'Take from each batch in turn: reduce the batch (never below 0), write a negative ledger entry, add to the quantity given'),
    ('d3', 'Q', 'More lines?'),
    ('st', 'S', 'Set the prescription status (Dispensed or Partially dispensed); add the medicines to the invoice; audit entry; commit'),
    ('m', 'IO', 'Show what was given and from which batch'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 't'), ('t', 'n'), ('n', 'd1'), ('d1', 'x', 'yes'), ('d1', 'b', 'no'), ('b', 'd2'), ('d2', 'x', 'no', 'loose'), ('d2', 'p', 'yes'), ('p', 'd3'),
     ('d3', 'n', 'yes', 'loose'), ('d3', 'st', 'no'), ('st', 'm'), ('m', 'f')],
    same=[('d1', 'x')])

FLOWS['flow_invoice'] = flow('invoice', [
    ('s', 'T', 'Start: a service is recorded (consultation, test, medicine given or procedure)'),
    ('t', 'S', "Begin a transaction; lock the visit's invoice"),
    ('l', 'S', 'List everything the visit should be charged for, from the clinical, laboratory and pharmacy records'),
    ('d0', 'Q', 'Invoice exists, or something to charge?'), ('z', 'T', 'End (no invoice yet)'),
    ('cr', 'S', 'Create the invoice if new: number INV-yyyy-nnnnnn and an audit entry'),
    ('ch', 'S', 'For each charge: add it if new; if a payment has already been taken update the quantity only, otherwise update description, quantity and price'),
    ('rm', 'S', 'Delete lines whose source no longer exists (a cancelled test)'), ('tot', 'S', 'Recalculate the total'),
    ('d5', 'Q', 'Total below the amount already paid?'), ('rb', 'S', 'Roll back and refuse'),
    ('stt', 'S', 'Set the status: Unpaid, Partially paid or Paid; commit'), ('f', 'T', 'End')],
    [('s', 't'), ('t', 'l'), ('l', 'd0'), ('d0', 'z', 'no'), ('d0', 'cr', 'yes'), ('cr', 'ch'), ('ch', 'rm'), ('rm', 'tot'), ('tot', 'd5'), ('d5', 'rb', 'yes'), ('d5', 'stt', 'no'),
     ('stt', 'f')],
    same=[('d0', 'z'), ('d5', 'rb')])

FLOWS['flow_payment'] = flow('payment', [
    ('s', 'T', 'Start'), ('i', 'IO', 'Cashier enters the method, amount and reference'), ('t', 'S', 'Begin a transaction; lock the invoice'),
    ('d1', 'Q', 'Method, amount (not above the balance) and reference all valid?'), ('e', 'IO', 'Roll back; show the reason beside the field'),
    ('w', 'S', 'Save the payment'), ('u', 'S', 'Add it to the amount paid and set the status: Partially paid or Paid'),
    ('a', 'S', 'Write the audit entry (amount and method only); commit'), ('r', 'IO', 'Show the receipt RCT-yyyy-nnnnnn'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 't'), ('t', 'd1'), ('d1', 'e', 'no'), ('d1', 'w', 'yes'), ('w', 'u'), ('u', 'a'), ('a', 'r'), ('r', 'f')],
    same=[('d1', 'e')])

FLOWS['flow_signin'] = flow('signin', [
    ('s', 'T', 'Start'), ('i', 'IO', 'User enters username and password; the browser sends the CSRF token'),
    ('c', 'Q', 'CSRF token valid?'), ('x0', 'IO', 'Refuse (419)'),
    ('ip', 'Q', '20 or more failures from this computer in 15 minutes?'), ('xb', 'IO', "Show 'too many attempts'"),
    ('lk', 'S', 'Find the account and check the password against its hash (a dummy hash if the name is unknown)'),
    ('d1', 'Q', 'Account locked?'), ('xl', 'IO', "Show 'temporarily locked', even for the right password"),
    ('d2', 'Q', 'Account exists and password correct?'), ('fa', 'S', 'Count the failure; lock for 15 minutes at the fifth; audit entry; one message for all failures'),
    ('d3', 'Q', 'Account active?'), ('xi', 'IO', "Show 'deactivated'"),
    ('ok', 'S', 'Reset the failure count, record the sign-in, issue a new session id and CSRF token, audit entry'),
    ('dh', 'IO', "Open the dashboard with the menu for the user's role (or the change-password page the first time)")],
    [('s', 'i'), ('i', 'c'), ('c', 'x0', 'no'), ('c', 'ip', 'yes'), ('ip', 'xb', 'yes'), ('ip', 'lk', 'no'), ('lk', 'd1'), ('d1', 'xl', 'yes'), ('d1', 'd2', 'no'),
     ('d2', 'fa', 'no', 'loose'), ('d2', 'd3', 'yes'), ('d3', 'xi', 'no'), ('d3', 'ok', 'yes'), ('ok', 'dh')],
    same=[('c', 'x0'), ('ip', 'xb'), ('d1', 'xl'), ('d3', 'xi'), ('d2', 'fa')])

FLOWS['flow_access'] = flow('access', [
    ('s', 'T', 'Start: any page is requested'), ('d0', 'Q', 'Signed in?'), ('x0', 'IO', 'Send to the sign-in page'),
    ('d1', 'Q', 'Idle over 30 minutes, or signed in over 8 hours?'), ('x1', 'S', 'End the session; audit entry; ask for sign-in'),
    ('d2', 'Q', 'Account still active?'), ('x2', 'S', 'End the session at once'),
    ('d3', 'Q', 'Must change password?'), ('x3', 'IO', 'Send to the change-password page'),
    ('d4', 'Q', "Role holds the page's permission?"), ('x4', 'IO', 'Refuse (403); write an audit entry'),
    ('d5', 'Q', 'Page changes data?'), ('d6', 'Q', 'POST with a valid CSRF token?'), ('x6', 'IO', 'Refuse (419); write an audit entry'),
    ('ok', 'S', 'Run the page, apply the business rules, write the audit entry'), ('f', 'T', 'End')],
    [('s', 'd0'), ('d0', 'x0', 'no'), ('d0', 'd1', 'yes'), ('d1', 'x1', 'yes'), ('d1', 'd2', 'no'), ('d2', 'x2', 'no'), ('d2', 'd3', 'yes'), ('d3', 'x3', 'yes'), ('d3', 'd4', 'no'),
     ('d4', 'x4', 'no'), ('d4', 'd5', 'yes'), ('d5', 'd6', 'yes'), ('d5', 'ok', 'no'), ('d6', 'x6', 'no'), ('d6', 'ok', 'yes'), ('ok', 'f')],
    same=[('d0', 'x0'), ('d1', 'x1'), ('d2', 'x2'), ('d3', 'x3'), ('d4', 'x4'), ('d6', 'x6')])
