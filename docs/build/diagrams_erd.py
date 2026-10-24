"""Entity relationship diagrams generated from schema.json (the live database structure).

* erd_conceptual: entities and relationships only (crow's foot), for the overview.
* erd_m1 ... erd_m6: one logical diagram per module showing every column, with PK / FK / UQ markers.
  Columns that refer to users (created_by, recorded_by ...) are marked FK but the lines to users are
  left out to keep the pictures readable; the caption says so.
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
PRE = '''
  graph [fontname="Arial", fontsize=12, bgcolor="white", pad="0.15"];
  node  [fontname="Arial", fontsize=11, color="black", fontcolor="black"];
  edge  [fontname="Arial", fontsize=10, color="black", fontcolor="black", arrowsize=0.9];
'''

MODULES = {
    'erd_m1': ('Module 1: patient registration and appointments', ['patients', 'op_sequences', 'appointments'], ['users']),
    'erd_m2': ('Module 2: clinical consultation', ['visits', 'triage', 'consultations', 'visit_services', 'services', 'icd10_codes'], ['patients', 'appointments', 'users']),
    'erd_m3': ('Module 3: laboratory management', ['lab_tests', 'lab_requests'], ['visits', 'users']),
    'erd_m4': ('Module 4: pharmacy and inventory', ['medicines', 'medicine_batches', 'prescriptions', 'prescription_items', 'stock_movements'], ['visits', 'users']),
    'erd_m5': ('Module 5: billing', ['invoices', 'invoice_items', 'payments'], ['visits', 'users']),
    'erd_m6': ('Module 6: user management and security', ['roles', 'users', 'audit_log'], []),
}


def load():
    with open(os.path.join(HERE, 'schema.json'), encoding='utf-8-sig') as f:
        return json.load(f)['tables']


def short_type(col):
    t = col['type'].lower()
    if t.startswith('enum'):
        return 'ENUM'
    if t.startswith('tinyint(1)'):
        return 'BOOL'
    base = t.split('(')[0].split(' ')[0].upper()
    if base in ('VARCHAR', 'CHAR', 'DECIMAL'):
        return t.split(' ')[0].upper()
    if base in ('INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT'):
        return {'INT': 'INT', 'BIGINT': 'BIGINT', 'SMALLINT': 'SMALLINT', 'TINYINT': 'TINYINT', 'MEDIUMINT': 'MEDIUMINT'}[base]
    return base


def esc(text):
    return str(text).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def table_node(name, info, full=True):
    pk = {c['name'] for c in info['columns'] if c['ckey'] == 'PRI'}
    fk = {f['col'] for f in info['foreign_keys']}
    uq = set()
    for ix in info['indexes']:
        if ix['unique'] and ix['name'] != 'PRIMARY' and len(ix['columns']) == 1:
            uq.add(ix['columns'][0])
    rows = [f'<TR><TD COLSPAN="3" SIDES="B" BORDER="1" ALIGN="CENTER"><B>{esc(name)}</B></TD></TR>']
    for c in info['columns']:
        if not full and c['name'] not in pk:
            continue
        mark = 'PK' if c['name'] in pk else ('FK' if c['name'] in fk else ('UQ' if c['name'] in uq else ''))
        if c['name'] in pk and c['name'] in fk:
            mark = 'PK, FK'
        rows.append(f'<TR><TD ALIGN="LEFT">{("<B>" + mark + "</B>") if mark else "&nbsp;"}</TD><TD ALIGN="LEFT">{esc(c["name"])}</TD><TD ALIGN="LEFT">{esc(short_type(c))}</TD></TR>')
    return (f'  {name} [shape=none, margin=0, label=<<TABLE BORDER="1" CELLBORDER="0" CELLSPACING="0" CELLPADDING="3">'
            + ''.join(rows) + '</TABLE>>];')


def module_diagram(key):
    title, tables, stubs = MODULES[key]
    schema = load()
    lines = [f'digraph {key} {{' + PRE + '  rankdir=LR; nodesep=0.45; ranksep=0.9;']
    shown = set(tables) | set(stubs)
    for t in tables:
        lines.append(table_node(t, schema[t], True))
    for t in stubs:
        if t != 'users':
            lines.append(table_node(t, schema[t], False))
    for t in tables:
        for fk in schema[t]['foreign_keys']:
            if fk['ref_table'] == 'users' or fk['ref_table'] not in shown:
                continue
            lines.append(f'  {t} -> {fk["ref_table"]} [arrowtail=crow, arrowhead=tee, dir=both];')
    # keep unrelated tables of the module (op_sequences) beside the others
    lines.append('}')
    return '\n'.join(lines) + '\n'


CONCEPTUAL = [
    # parent, child, label, child side
    ('roles', 'users', 'has'), ('users', 'audit_log', 'is recorded in'),
    ('patients', 'appointments', 'books'), ('patients', 'visits', 'makes'), ('appointments', 'visits', 'leads to'),
    ('visits', 'triage', 'has'), ('visits', 'consultations', 'has'), ('visits', 'visit_services', 'includes'),
    ('services', 'visit_services', 'priced by'), ('services', 'consultations', 'charged as'), ('icd10_codes', 'consultations', 'codes'),
    ('visits', 'lab_requests', 'needs'), ('lab_tests', 'lab_requests', 'is'),
    ('visits', 'prescriptions', 'gets'), ('prescriptions', 'prescription_items', 'lists'), ('medicines', 'prescription_items', 'is'),
    ('medicines', 'medicine_batches', 'is held in'), ('medicine_batches', 'stock_movements', 'changes by'), ('medicines', 'stock_movements', 'moves'),
    ('visits', 'invoices', 'is billed on'), ('invoices', 'invoice_items', 'has'), ('invoices', 'payments', 'is paid by'),
]

LABELS = {
    'roles': 'Role', 'users': 'User (staff)', 'audit_log': 'Audit entry', 'patients': 'Patient', 'appointments': 'Appointment', 'visits': 'Visit',
    'triage': 'Triage record', 'consultations': 'Consultation', 'visit_services': 'Visit service', 'services': 'Service', 'icd10_codes': 'ICD-10 code',
    'lab_requests': 'Laboratory request', 'lab_tests': 'Laboratory test', 'prescriptions': 'Prescription', 'prescription_items': 'Prescription item',
    'medicines': 'Medicine', 'medicine_batches': 'Medicine batch', 'stock_movements': 'Stock movement', 'invoices': 'Invoice', 'invoice_items': 'Invoice item',
    'payments': 'Payment',
}


def conceptual():
    lines = ['digraph conceptual {' + PRE + '  rankdir=TB; nodesep=0.25; ranksep=0.55; node [shape=box, fontsize=12, margin="0.12,0.07"];']
    for k, v in LABELS.items():
        lines.append(f'  {k} [label="{v}"];')
    for parent, child, label in CONCEPTUAL:
        lines.append(f'  {parent} -> {child} [arrowtail=tee, arrowhead=crow, dir=both, label=" {label} "];')
    lines.append('  patients -> audit_log [style=invis];')
    lines.append('}')
    return '\n'.join(lines) + '\n'


def write_all(outdir):
    names = []
    for key in MODULES:
        with open(os.path.join(outdir, key + '.dot'), 'w', encoding='utf-8', newline='\n') as f:
            f.write(module_diagram(key))
        names.append(key)
    with open(os.path.join(outdir, 'erd_conceptual.dot'), 'w', encoding='utf-8', newline='\n') as f:
        f.write(conceptual())
    names.append('erd_conceptual')
    return names
