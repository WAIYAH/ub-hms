"""Build the final report: the approved proposal (Chapters 1 to 3, unchanged) followed by Chapters 4 to 8.

    python merge_final.py

1. builds Chapters 4-5, 6-7 and 8 without cover pages or contents lists, with citations of the proposal
   written as "Chapters 1 to 3";
2. makes a copy of the proposal with the cover wording changed, new abbreviations and new references added
   (Chapters 1 to 3 themselves are not touched);
3. lets Word insert the three parts before REFERENCES, then refreshes every list and page number.
"""
import copy
import os
import re
import subprocess

from docx import Document
from docx.oxml.ns import qn
from docx.table import _Row
from docx.text.paragraph import Paragraph

import docx_lib
docx_lib.MERGED = True

from docx_lib import build_twice
from doc_common import TEMPLATE, OUTDIR, REFERENCES
import build_doc_a
import build_doc_b
import build_doc_c

PROPOSAL = r'D:\SHELAX\PROPOSAL_Susan_Wanjiku_Waweru_Final.docx'
PARTS = os.path.join(OUTDIR, '_parts')
FINAL = os.path.join(OUTDIR, 'UB-HMS_Final_Report_Chapters_1-8.docx')
HERE = os.path.dirname(os.path.abspath(__file__))

NEW_ABBREVIATIONS = [
    ('CSP', 'Content Security Policy'), ('CSRF', 'Cross-Site Request Forgery'), ('E2E', 'End to end (test)'), ('FK', 'Foreign Key'),
    ('HTTP', 'Hypertext Transfer Protocol'), ('HTTPS', 'Hypertext Transfer Protocol Secure'), ('OP', 'Outpatient'), ('PDO', 'PHP Data Objects'),
    ('PK', 'Primary Key'), ('REQ', 'Requirement'), ('SDS', 'System Design Specification'), ('SRS', 'System Requirements Specification'),
    ('SUS', 'System Usability Scale'), ('UAT', 'User Acceptance Testing'), ('UC', 'Use Case'), ('US', 'User Story'),
    ('UT', 'Unit Test'), ('XSS', 'Cross-Site Scripting'),
]
NEW_REFERENCE_KEYS = ['brooke', 'chen', 'codd', 'dennis', 'elmasri', 'ieee830', 'nielsen', 'owaspcheat', 'php', 'provos',
                      'shneiderman', 'w3c', 'who2019', 'wiegers']


def build_parts():
    os.makedirs(PARTS, exist_ok=True)
    paths = []
    for mod, name in ((build_doc_a, 'part1_ch4-5.docx'), (build_doc_b, 'part2_ch6-7.docx'), (build_doc_c, 'part3_ch8.docx')):
        out = os.path.join(PARTS, name)
        build_twice(lambda d, m=mod: m.build(d, bare=True), TEMPLATE, out)
        paths.append(out)
        print('built', name, flush=True)
    return paths


def set_text(par, old, new):
    for r in par.runs:
        if old in r.text:
            r.text = r.text.replace(old, new)
            return True
    return False


def add_runs(par_el, text):
    """Append runs for text with *italic* markup."""
    para = Paragraph(par_el, None)
    for part in re.split(r'(\*[^*]+\*)', text):
        if not part:
            continue
        italic = part.startswith('*') and part.endswith('*')
        run = para.add_run(part[1:-1] if italic else part)
        if italic:
            run.italic = True


def norm(text):
    t = text.casefold().replace('(n.d.)', '(0000)')
    return re.sub(r'^the ', '', t)


def prepare_proposal(out):
    d = Document(PROPOSAL)
    P = d.paragraphs
    assert 'PROJECT' in P[14].text and 'PROPOSAL' in P[14].text
    set_text(P[14], 'PROPOSAL', 'DOCUMENTATION')
    assert set_text(P[29], 'proposal', 'documentation')

    table = d.tables[0]
    assert table.rows[0].cells[0].text.strip() == 'Abbreviation'
    template_row = table.rows[1]._tr
    existing = {r.cells[0].text.strip().casefold() for r in table.rows[1:]}
    for abbr, meaning in NEW_ABBREVIATIONS:
        if abbr.casefold() in existing:
            continue
        new_tr = copy.deepcopy(template_row)
        target = None
        for r in table.rows[1:]:
            if r.cells[0].text.strip().casefold() > abbr.casefold():
                target = r._tr
                break
        if target is not None:
            target.addprevious(new_tr)
        else:
            table.rows[-1]._tr.addnext(new_tr)
        row = _Row(new_tr, table)
        for cell, text in zip(row.cells, (abbr, meaning)):
            p = cell.paragraphs[0]
            for r in p.runs[1:]:
                r._r.getparent().remove(r._r)
            p.runs[0].text = text

    entries = [p for p in d.paragraphs if p.style.name == 'RefEntry']
    for key in NEW_REFERENCE_KEYS:
        text = REFERENCES[key]
        plain = text.replace('*', '')
        new_p = copy.deepcopy(entries[0]._p)
        for child in list(new_p):
            if child.tag != qn('w:pPr'):
                new_p.remove(child)
        add_runs(new_p, text)
        placed = False
        for i, e in enumerate(entries):
            if norm(e.text) > norm(plain):
                e._p.addprevious(new_p)
                entries.insert(i, Paragraph(new_p, e._parent))
                placed = True
                break
        if not placed:
            entries[-1]._p.addnext(new_p)
            entries.append(Paragraph(new_p, entries[-1]._parent))
    d.save(out)
    print('proposal copy saved;', len(entries), 'reference entries', flush=True)


def fix_numbering(path):
    """Word copies the proposal's 'start at page 1' into several inserted sections; keep it only for the first three
    sections (cover, front matter, Chapter 1 onwards) so that page numbers run on through all eight chapters."""
    import shutil
    import zipfile
    tmp = path + '.tmp'
    with zipfile.ZipFile(path) as zin, zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == 'word/document.xml':
                text = data.decode('utf-8')
                count = [0]

                def fix(m):
                    count[0] += 1
                    block = m.group(0)
                    return block if count[0] <= 3 else re.sub(r'<w:pgNumType[^>]*/>', '', block)
                text = re.sub(r'<w:sectPr.*?</w:sectPr>', fix, text, flags=re.S)
                data = text.encode('utf-8')
            zout.writestr(item, data)
    shutil.move(tmp, path)
    print('page numbering fixed', flush=True)


def main():
    import sys
    paths = [os.path.join(PARTS, n) for n in ('part1_ch4-5.docx', 'part2_ch6-7.docx', 'part3_ch8.docx')] if 'reuse' in sys.argv else build_parts()
    base = os.path.join(PARTS, 'proposal_base.docx')
    prepare_proposal(base)
    cmd = ['powershell', '-ExecutionPolicy', 'Bypass', '-File', os.path.join(HERE, 'merge_docx.ps1'), '-Base', base, '-Out', FINAL, '-Parts', ';'.join(paths)]
    subprocess.run(cmd, check=True)
    fix_numbering(FINAL)
    print('merged ->', FINAL, flush=True)


if __name__ == '__main__':
    main()
