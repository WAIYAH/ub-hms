"""Build Document A: Chapter 4 (System Requirements Specification) and Chapter 5 (System Design Specification).

    python build_doc_a.py
"""
import os
import shutil
import sys

from docx_lib import build_twice
from doc_common import TEMPLATE, OUTDIR, COPYDIR, cover_lines
import ch4a
import ch4b
import ch4c

OUT_NAME = 'UB-HMS_Chapters_4-5_Requirements_and_Design.docx'
CH4_CITED = ['proposal', 'ieee830', 'wiegers', 'kenya2017', 'kenya2019', 'kenya2023', 'owasp', 'owaspcheat', 'provos', 'php', 'nielsen', 'shneiderman', 'dennis', 'schwaber']


def build(d):
    d.cover(cover_lines(['CHAPTER 4: SYSTEM REQUIREMENTS SPECIFICATION', 'CHAPTER 5: SYSTEM DESIGN SPECIFICATION']))
    d.toc()
    d.list_of('LIST OF FIGURES', 'FigureCaption')
    d.page_break()
    d.list_of('LIST OF TABLES', 'TableCaption')
    # chapter 4
    ch4a.build_intro(d)
    ch4a.build_overall(d)
    ch4a.build_features(d)
    ch4b.build_use_cases(d)
    ch4b.build_stories(d)
    ch4b.build_traceability(d)
    ch4c.build_interfaces(d)
    ch4c.build_nonfunctional(d)
    ch4c.build_other(d)
    ch4c.build_references_and_glossary(d, CH4_CITED)
    try:
        import ch5
    except ImportError:
        return
    ch5.build(d)


if __name__ == '__main__':
    os.makedirs(OUTDIR, exist_ok=True)
    out = os.path.join(OUTDIR, OUT_NAME)
    refs = build_twice(build, TEMPLATE, out)
    print('saved', out, len(refs), 'labels')
