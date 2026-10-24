"""Build Document B: Chapter 6 (System Implementation) and Chapter 7 (System Test Plan).

    python build_doc_b.py
"""
import os

from docx_lib import build_twice
from doc_common import TEMPLATE, OUTDIR, cover_lines
import ch6a
import ch6b
import ch7a
import ch7b

OUT_NAME = 'UB-HMS_Chapters_6-7_Implementation_and_Test_Plan.docx'


def build(d):
    d.cover(cover_lines(['CHAPTER 6: SYSTEM IMPLEMENTATION', 'CHAPTER 7: SYSTEM TEST PLAN']))
    d.toc()
    d.list_of('LIST OF FIGURES', 'FigureCaption')
    d.page_break()
    d.list_of('LIST OF TABLES', 'TableCaption')
    ch6a.build_intro(d)
    ch6a.build_architecture(d)
    ch6a.build_modules(d)
    ch6a.build_database(d)
    ch6b.build_ui(d)
    ch6b.build_process(d)
    ch6b.build_security(d)
    ch6b.build_deployment(d)
    ch6b.build_appendices(d)
    ch7a.build_intro(d)
    ch7a.build_approach(d)
    ch7a.build_test_cases(d)
    ch7a.build_results(d)
    ch7b.build_bugs(d)
    ch7b.build_feedback(d)
    ch7b.build_appendices(d)


if __name__ == '__main__':
    os.makedirs(OUTDIR, exist_ok=True)
    out = os.path.join(OUTDIR, OUT_NAME)
    refs = build_twice(build, TEMPLATE, out)
    print('saved', out, len(refs), 'labels')
