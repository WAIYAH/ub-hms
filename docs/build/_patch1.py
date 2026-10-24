"""One-off edit of diagrams_dot.py: remove the old flowchart section, use the flow module, relayout others."""
p = 'diagrams_dot.py'
s = open(p, encoding='utf-8', newline='').read()

# 1. drop the old flowchart section; flowcharts now come from diagrams_flow.py
a = s.index('# --------------------------------------------------------------------------------------------- flowcharts')
b = s.index('# --------------------------------------------------------------------------------------------- structured chart')
s = s[:a] + s[b:]
s = s.replace("    for name, text in DOTS.items():", "    from diagrams_flow import FLOWS\n    DOTS.update(FLOWS)\n    for name, text in DOTS.items():")

# 2. level 1 DFD: tighter
s = s.replace('rankdir=LR; nodesep=0.22; ranksep=1.45; splines=true; newrank=true;', 'rankdir=LR; nodesep=0.12; ranksep=0.85; splines=true; newrank=true;')

# 3. network: one row of workstations
a = s.index("DOTS['network']")
b = s.index('# ------', a)
net = '''DOTS['network'] = 'digraph net {' + PRE + """
  rankdir=TB; nodesep=0.28; ranksep=0.75;
  node [shape=box, margin="0.14,0.08", fontsize=12];
  UPS [shape=ellipse, label="UPS\\\\n(backup power)"]; SRV [label="Server computer\\\\nXAMPP: Apache, PHP 8,\\\\nMariaDB; UB-HMS and\\\\nits database", style="bold", penwidth=1.8]; BK [shape=cylinder, label="External hard disk\\\\n(copy of backups)"];
  SW [shape=box, style="bold", penwidth=1.8, label="Network switch (wired hospital LAN)"];
  W1 [label="Reception\\\\nPC"]; W2 [label="Triage\\\\nPC"]; W3 [label="Doctors'\\\\nPCs"]; W4 [label="Laboratory\\\\nPC"]; W5 [label="Pharmacy\\\\nPC"]; W6 [label="Cashier\\\\nPC"]; W7 [label="Manager's\\\\nPC"]; W8 [label="Administrator's\\\\nPC"];
  P1 [shape=note, label="Office\\\\nprinter"]; P2 [shape=note, label="Office\\\\nprinter"]; P3 [shape=note, label="Receipt\\\\nprinter"];
  {rank=same; UPS; SRV; BK;} {rank=same; W1; W2; W3; W4; W5; W6; W7; W8;} {rank=same; P1; P2; P3;}
  UPS -> SRV [dir=none, style=dashed, label=" power"]; SRV -> BK [dir=none, style=dashed, label=" copy"];
  SRV -> SW [dir=none, penwidth=1.8];
  SW -> W1 [dir=none]; SW -> W2 [dir=none]; SW -> W3 [dir=none]; SW -> W4 [dir=none]; SW -> W5 [dir=none]; SW -> W6 [dir=none]; SW -> W7 [dir=none]; SW -> W8 [dir=none];
  W1 -> P1 [dir=none, style=dotted]; W5 -> P2 [dir=none, style=dotted]; W6 -> P3 [dir=none, style=dotted];
}
"""

'''
s = s[:a] + net + s[b:]

# 4. screen map: modules laid out in rows
a = s.index("DOTS['screen_map']")
b = s.index('# ------', a)
sm = '''DOTS['screen_map'] = 'digraph nav {' + PRE + """
  rankdir=LR; nodesep=0.1; ranksep=0.3; compound=true;
  node [shape=box, style="rounded", margin="0.08,0.05", fontsize=11];
  edge [fontsize=10];
  START [label="Sign in", style="rounded,bold"]; PW [label="Change\\\\npassword\\\\n(first time)"]; DASH [label="Dashboard\\\\n(menu shows only what\\\\nthe role may do)", style="rounded,bold"];
  START -> PW; START -> DASH; PW -> DASH;
  subgraph cluster_1 { label="Patients and appointments"; style="rounded"; a1 [label="Search\\\\npatients"]; a2 [label="Patient\\\\nrecord"]; a3 [label="Register\\\\npatient"]; a4 [label="Import\\\\npatients"]; a5 [label="Appointment\\\\ndiary"]; a6 [label="Book\\\\nappointment"]; a1 -> a2; a3 -> a4 [style=invis]; a2 -> a3 [style=invis]; a4 -> a5 [style=invis]; a5 -> a6; }
  subgraph cluster_2 { label="Clinic"; style="rounded"; b1 [label="Check-in"]; b2 [label="Triage\\\\nqueue"]; b3 [label="Triage\\\\nform"]; b4 [label="Consultation\\\\nqueue"]; b5 [label="Consultation (tests,\\\\nprescriptions)"]; b1 -> b2; b2 -> b3; b3 -> b4 [style=invis]; b4 -> b5; }
  subgraph cluster_3 { label="Laboratory"; style="rounded"; c1 [label="Laboratory\\\\nworklist"]; c2 [label="Enter\\\\nresult"]; c3 [label="My test\\\\nresults"]; c1 -> c2; c2 -> c3 [style=dashed]; }
  subgraph cluster_4 { label="Pharmacy"; style="rounded"; d1 [label="Prescription\\\\nqueue"]; d2 [label="Dispense"]; d3 [label="Medicine\\\\nstock"]; d4 [label="Batches"]; d5 [label="Receive\\\\nstock"]; d6 [label="Medicine\\\\nlist"]; d1 -> d2; d2 -> d3 [style=invis]; d3 -> d4; d4 -> d5 [style=invis]; d5 -> d6 [style=invis]; }
  subgraph cluster_5 { label="Billing and reports"; style="rounded"; e1 [label="Invoices"]; e2 [label="Invoice and\\\\npayment"]; e3 [label="Receipt"]; e4 [label="Reports"]; e1 -> e2 -> e3; e3 -> e4 [style=invis]; }
  subgraph cluster_6 { label="Administration"; style="rounded"; f1 [label="User\\\\naccounts"]; f2 [label="Price\\\\nlist"]; f3 [label="Audit\\\\ntrail"]; f4 [label="System\\\\nhealth"]; f1 -> f2 -> f3 -> f4 [style=invis]; }
  DASH -> a1 [lhead=cluster_1]; DASH -> b1 [lhead=cluster_2]; DASH -> c1 [lhead=cluster_3]; DASH -> d1 [lhead=cluster_4]; DASH -> e1 [lhead=cluster_5]; DASH -> f1 [lhead=cluster_6];
}
"""

'''
s = s[:a] + sm + s[b:]
open(p, 'w', encoding='utf-8', newline='').write(s)
print('patched')
