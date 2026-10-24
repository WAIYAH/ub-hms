"""One-off edit of diagrams_flow.py: split the two biggest flowcharts and trim the laboratory one."""
p = 'diagrams_flow.py'
s = open(p, encoding='utf-8', newline='').read()


def cut(start_marker, end_marker, replacement):
    global s
    a = s.index(start_marker)
    b = s.index(end_marker, a) if end_marker else len(s)
    s = s[:a] + replacement + s[b:]


consult = '''FLOWS['flow_checkin'] = flow('checkin', [
    ('s', 'T', 'Start: patient arrives'), ('d0', 'Q', 'Open visit already?'), ('x0', 'IO', 'Refuse; show the open visit'),
    ('d1', 'Q', 'Booked appointment today?'), ('a1', 'S', 'Mark the appointment Attended'), ('a2', 'S', 'Record a walk-in'),
    ('v', 'S', 'Open the visit (waiting for triage)'), ('tr', 'IO', 'Nurse enters the vital signs'),
    ('d2', 'Q', 'Values plausible?'), ('e1', 'IO', 'Show messages and ask again'),
    ('st', 'S', 'Save triage and write the audit entry (visit is now waiting for the doctor)'), ('f', 'T', 'End')],
    [('s', 'd0'), ('d0', 'x0', 'yes'), ('d0', 'd1', 'no'), ('d1', 'a1', 'yes'), ('d1', 'a2', 'no'), ('a1', 'v'), ('a2', 'v'), ('v', 'tr'), ('tr', 'd2'),
     ('d2', 'e1', 'no'), ('e1', 'tr', '', 'loose'), ('d2', 'st', 'yes'), ('st', 'f'), ('x0', 'f', '', 'loose')],
    same=[('d0', 'x0'), ('a1', 'a2'), ('d2', 'e1')])

FLOWS['flow_consult'] = flow('consult', [
    ('s', 'T', 'Start: patient is waiting for the doctor'), ('c', 'IO', 'Doctor enters complaint, diagnosis, optional ICD-10 code and plan'),
    ('d3', 'Q', 'Complaint, diagnosis, code valid?'), ('e2', 'IO', 'Show messages and ask again'),
    ('d5', 'Q', 'Recorded by another doctor?'), ('e4', 'IO', 'Refuse: only that doctor can change it'),
    ('sc', 'S', 'Save the consultation, record the doctor, charge the consultation fee, write the audit entry'),
    ('o', 'S', 'Doctor requests tests, prescribes and records procedures (own pages)'), ('cl', 'S', 'Doctor closes the visit'),
    ('d4', 'Q', 'Consultation recorded?'), ('e3', 'IO', 'Refuse: record the consultation first'), ('f', 'T', 'End (visit closed)')],
    [('s', 'c'), ('c', 'd3'), ('d3', 'e2', 'no'), ('e2', 'c', '', 'loose'), ('d3', 'd5', 'yes'), ('d5', 'e4', 'yes'), ('d5', 'sc', 'no'), ('sc', 'o'), ('o', 'cl'),
     ('cl', 'd4'), ('d4', 'e3', 'no'), ('e3', 'o', '', 'loose'), ('d4', 'f', 'yes'), ('e4', 'f', '', 'loose')],
    same=[('d3', 'e2'), ('d5', 'e4'), ('d4', 'e3')])

'''
cut("FLOWS['flow_consult']", "FLOWS['flow_lab']", consult)

lab = '''FLOWS['flow_lab'] = flow('lab', [
    ('s', 'T', 'Start'), ('i', 'IO', 'Doctor chooses one or more tests'),
    ('d1', 'Q', 'Visit open and consultation recorded?'), ('e0', 'IO', 'Refuse with the reason'),
    ('d2', 'Q', 'Same test still pending for this visit?'), ('e1', 'IO', 'Roll back everything; name the test'),
    ('w', 'S', "In one transaction save each request with the doctor's id and add the test price to the invoice"),
    ('l1', 'IO', 'Technologist starts the test and enters the result and range flag'),
    ('d3', 'Q', 'Request cancelled by the doctor?'), ('e2', 'IO', 'Refuse; no result can be entered'),
    ('r', 'S', 'Save the result, who entered it and when; mark Completed; show it to the requesting doctor'), ('f', 'T', 'End')],
    [('s', 'i'), ('i', 'd1'), ('d1', 'e0', 'no'), ('d1', 'd2', 'yes'), ('d2', 'e1', 'yes'), ('d2', 'w', 'no'), ('w', 'l1'), ('l1', 'd3'),
     ('d3', 'e2', 'yes'), ('d3', 'r', 'no'), ('r', 'f'), ('e0', 'f', '', 'loose'), ('e1', 'f', '', 'loose'), ('e2', 'f', '', 'loose')],
    same=[('d1', 'e0'), ('d2', 'e1'), ('d3', 'e2')])

'''
cut("FLOWS['flow_lab']", "FLOWS['flow_dispense']", lab)

signin = '''FLOWS['flow_signin'] = flow('signin', [
    ('s', 'T', 'Start'), ('i', 'IO', 'User enters username and password; the browser sends the CSRF token'),
    ('c', 'Q', 'CSRF token valid?'), ('x0', 'IO', 'Refuse (419)'),
    ('ip', 'Q', '20 or more failures from this computer in 15 minutes?'), ('xb', 'IO', "Show 'too many attempts'"),
    ('lk', 'S', 'Find the account and check the password against its hash (a dummy hash if the name is unknown)'),
    ('d1', 'Q', 'Account locked?'), ('xl', 'IO', "Show 'temporarily locked', even for the right password"),
    ('d2', 'Q', 'Account exists and password correct?'), ('fa', 'S', 'Count the failure; lock for 15 minutes at the fifth; audit entry; one message for all failures'),
    ('d3', 'Q', 'Account active?'), ('xi', 'IO', "Show 'deactivated'"),
    ('ok', 'S', 'Reset the failure count, record the sign-in, issue a new session id and CSRF token, audit entry'),
    ('d4', 'Q', 'Must change password?'), ('pw', 'IO', 'Change-password page'), ('dh', 'IO', "Dashboard with the menu for the user's role")],
    [('s', 'i'), ('i', 'c'), ('c', 'x0', 'no'), ('c', 'ip', 'yes'), ('ip', 'xb', 'yes'), ('ip', 'lk', 'no'), ('lk', 'd1'), ('d1', 'xl', 'yes'), ('d1', 'd2', 'no'),
     ('d2', 'fa', 'no', 'loose'), ('d2', 'd3', 'yes'), ('d3', 'xi', 'no'), ('d3', 'ok', 'yes'), ('ok', 'd4'), ('d4', 'pw', 'yes'), ('d4', 'dh', 'no')],
    same=[('c', 'x0'), ('ip', 'xb'), ('d1', 'xl'), ('d3', 'xi'), ('pw', 'dh'), ('d2', 'fa')])

FLOWS['flow_access'] = flow('access', [
    ('s', 'T', 'Start: any page is requested'), ('d0', 'Q', 'Signed in?'), ('x0', 'IO', 'Send to the sign-in page'),
    ('d1', 'Q', 'Idle over 30 minutes, or signed in over 8 hours?'), ('x1', 'S', 'End the session; audit entry; ask for sign-in'),
    ('d2', 'Q', 'Account still active?'), ('x2', 'S', 'End the session at once'),
    ('d3', 'Q', 'Must change password?'), ('x3', 'IO', 'Send to the change-password page'),
    ('d4', 'Q', "Role holds the page's permission?"), ('x4', 'IO', 'Refuse (403); write an audit entry'),
    ('d5', 'Q', 'Page changes data?'), ('d6', 'Q', 'POST with a valid CSRF token?'), ('x6', 'IO', 'Refuse (419); write an audit entry'),
    ('ok', 'S', 'Run the page, apply the business rules, write the audit entry'), ('f', 'T', 'End')],
    [('s', 'd0'), ('d0', 'x0', 'no'), ('d0', 'd1', 'yes'), ('d1', 'x1', 'yes'), ('d1', 'd2', 'no'), ('d2', 'x2', 'no'), ('d2', 'd3', 'yes'), ('d3', 'x3', 'yes'), ('d3', 'd4', 'no'),
     ('d4', 'x4', 'no'), ('d4', 'd5', 'yes'), ('d5', 'd6', 'yes'), ('d5', 'ok', 'no'), ('d6', 'x6', 'no'), ('d6', 'ok', 'yes'), ('ok', 'f'),
     ('x0', 'f', '', 'loose'), ('x1', 'f', '', 'loose'), ('x2', 'f', '', 'loose'), ('x3', 'f', '', 'loose'), ('x4', 'f', '', 'loose'), ('x6', 'f', '', 'loose')],
    same=[('d0', 'x0'), ('d1', 'x1'), ('d2', 'x2'), ('d3', 'x3'), ('d4', 'x4'), ('d6', 'x6')])
'''
cut("FLOWS['flow_signin']", None, signin)
open(p, 'w', encoding='utf-8', newline='').write(s)
print('patched')
