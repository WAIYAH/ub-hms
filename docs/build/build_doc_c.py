"""Build Document C: Chapter 8 (System User Manual).

    python build_doc_c.py
"""
import os

from docx_lib import build_twice
from doc_common import TEMPLATE, OUTDIR, cover_lines
import ch8a
import ch8b

OUT_NAME = 'UB-HMS_Chapter_8_User_Manual.docx'


def build(d):
    d.cover(cover_lines(['CHAPTER 8: SYSTEM USER MANUAL']))
    d.toc()
    d.list_of('LIST OF FIGURES', 'FigureCaption')
    d.page_break()
    d.list_of('LIST OF TABLES', 'TableCaption')
    ch8a.build_intro(d)
    ch8a.build_getting_started(d)
    ch8a.build_navigation(d)
    ch8b.build_advanced(d)
    ch8b.build_maintenance(d)
    ch8b.build_troubleshooting(d)
    ch8b.build_faq_glossary(d)


if __name__ == '__main__':
    os.makedirs(OUTDIR, exist_ok=True)
    out = os.path.join(OUTDIR, OUT_NAME)
    refs = build_twice(build, TEMPLATE, out)
    print('saved', out, len(refs), 'labels')
