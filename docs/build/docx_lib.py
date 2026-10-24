"""Small helper library for building the UB-HMS chapter documents with python-docx.

It builds on template.docx (the approved proposal's styles). Features:
  * headings, paragraphs with **bold**, *italic* and `code` markup, bullet and numbered lists;
  * tables in the proposal's style (grey header row, 10 pt text, header row repeated across pages);
  * numbered figure and table captions ("Figure 4.1:", "Table 4.1:") with cross-references
    resolved by building the document twice;
  * code blocks, notes, page breaks, contents and lists of figures and tables (Word fields);
  * page numbers in the footer.
"""
import os
import re
from docx import Document
from docx.enum.section import WD_ORIENT, WD_SECTION
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.shared import Cm, Pt
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

TEXT_WIDTH_CM = 15.29  # A4 portrait less the proposal's margins (3.175 cm left, 2.54 cm right)
LANDSCAPE_WIDTH_CM = 23.98  # A4 landscape: 29.69 - 3.175 - 2.54
PORTRAIT_FIG_HEIGHT_CM = 21.0
LANDSCAPE_FIG_HEIGHT_CM = 14.2
MERGED = False  # set to True when the chapters are built for insertion into the full report
_INLINE = re.compile(r'(\*\*.+?\*\*|`.+?`|\*[^*\s][^*]*?\*)')


def _set_cell_shading(cell, fill):
    tcpr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), fill)
    tcpr.append(shd)


def _set_cell_margins(table, top=40, bottom=40, left=70, right=70):
    tblpr = table._tbl.tblPr
    mar = OxmlElement('w:tblCellMar')
    for side, val in (('top', top), ('left', left), ('bottom', bottom), ('right', right)):
        el = OxmlElement('w:' + side)
        el.set(qn('w:w'), str(val))
        el.set(qn('w:type'), 'dxa')
        mar.append(el)
    tblpr.append(mar)


def _field(paragraph, instruction, placeholder=''):
    """Insert a complex field (TOC, PAGE, ...) into a paragraph."""
    def run_with(child):
        r = OxmlElement('w:r')
        r.append(child)
        paragraph._p.append(r)
        return r
    begin = OxmlElement('w:fldChar')
    begin.set(qn('w:fldCharType'), 'begin')
    run_with(begin)
    instr = OxmlElement('w:instrText')
    instr.set(qn('xml:space'), 'preserve')
    instr.text = ' ' + instruction + ' '
    run_with(instr)
    sep = OxmlElement('w:fldChar')
    sep.set(qn('w:fldCharType'), 'separate')
    run_with(sep)
    t = OxmlElement('w:t')
    t.text = placeholder
    run_with(t)
    end = OxmlElement('w:fldChar')
    end.set(qn('w:fldCharType'), 'end')
    run_with(end)


class Doc:
    def __init__(self, template, refs=None):
        self.d = Document(template)
        self.refs = refs if refs is not None else {}   # label -> "Figure 4.2" (filled on the first pass)
        self.new_refs = {}
        self.chapter_no = 0
        self.fig_n = 0
        self.tab_n = 0
        self.first_figure_seen = False
        self.text_width = TEXT_WIDTH_CM
        self.max_fig_height = PORTRAIT_FIG_HEIGHT_CM
        self._setup_page()

    # ------------------------------------------------------------------ set-up
    def _setup_page(self):
        sec = self.d.sections[0]
        sec.different_first_page_header_footer = True  # no page number on the cover
        footer = sec.footer
        footer.is_linked_to_previous = False
        p = footer.paragraphs[0] if footer.paragraphs else footer.add_paragraph()
        p.style = self.d.styles['Footer']
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        _field(p, 'PAGE', '1')

    @staticmethod
    def _continue_numbering(sec):
        """A new section copies the first section's 'start at page 1'; remove it so the numbers carry on."""
        for el in sec._sectPr.findall(qn('w:pgNumType')):
            sec._sectPr.remove(el)

    def landscape(self):
        """Start a new page in landscape (for wide figures and tables). Page numbering continues."""
        sec = self.d.add_section(WD_SECTION.NEW_PAGE)
        self._continue_numbering(sec)
        sec.orientation = WD_ORIENT.LANDSCAPE
        sec.page_width, sec.page_height = sec.page_height, sec.page_width
        sec.different_first_page_header_footer = False
        self.text_width = LANDSCAPE_WIDTH_CM
        self.max_fig_height = LANDSCAPE_FIG_HEIGHT_CM

    def portrait(self):
        sec = self.d.add_section(WD_SECTION.NEW_PAGE)
        self._continue_numbering(sec)
        sec.orientation = WD_ORIENT.PORTRAIT
        if sec.page_width > sec.page_height:
            sec.page_width, sec.page_height = sec.page_height, sec.page_width
        sec.different_first_page_header_footer = False
        self.text_width = TEXT_WIDTH_CM
        self.max_fig_height = PORTRAIT_FIG_HEIGHT_CM

    def set_chapter(self, number):
        self.chapter_no = number
        self.fig_n = 0
        self.tab_n = 0

    # ------------------------------------------------------------------ text
    def _runs(self, paragraph, text, bold=None, italic=None, size=None):
        for part in _INLINE.split(text):
            if not part:
                continue
            if part.startswith('**') and part.endswith('**') and len(part) > 4:
                r = paragraph.add_run(part[2:-2])
                r.bold = True
            elif part.startswith('`') and part.endswith('`') and len(part) > 2:
                r = paragraph.add_run(part[1:-1])
                r.font.name = 'Consolas'
                r._element.get_or_add_rPr().rFonts.set(qn('w:eastAsia'), 'Consolas')
                r.font.size = Pt((size or 12) - 1.5)
            elif part.startswith('*') and part.endswith('*') and len(part) > 2:
                r = paragraph.add_run(part[1:-1])
                r.italic = True
            else:
                r = paragraph.add_run(part)
            if bold:
                r.bold = True
            if italic:
                r.italic = True
            if size:
                r.font.size = Pt(size)
        return paragraph

    def ref(self, label):
        return self.refs.get(label, '[' + label + ']')

    def _expand(self, text):
        text = re.sub(r'\{(?:ref):([A-Za-z0-9_.:-]+)\}', lambda m: self.ref(m.group(1)), text)
        if MERGED:
            # in the merged report the proposal is Chapters 1 to 3 of the same document
            text = text.replace('Waweru, 2026', 'Chapters 1 to 3')
        return text

    def h1(self, text):
        return self.d.add_paragraph(text, style='Heading 1')

    def h2(self, text):
        return self.d.add_paragraph(text, style='Heading 2')

    def h3(self, text):
        return self.d.add_paragraph(text, style='Heading 3')

    def h4(self, text):
        return self.d.add_paragraph(text, style='Heading 4')

    def p(self, text, style='Normal', align=None, keep_next=False, bold=None, italic=None, space_after=None):
        para = self.d.add_paragraph(style=style)
        self._runs(para, self._expand(text), bold=bold, italic=italic)
        if align == 'center':
            para.alignment = WD_ALIGN_PARAGRAPH.CENTER
        elif align == 'left':
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT
        if keep_next:
            para.paragraph_format.keep_with_next = True
        if space_after is not None:
            para.paragraph_format.space_after = Pt(space_after)
        return para

    def bullets(self, items, style='List Bullet'):
        for it in items:
            para = self.d.add_paragraph(style=style)
            self._runs(para, self._expand(it))
            para.paragraph_format.space_after = Pt(3)
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT

    def numbered(self, items):
        # Word's automatic numbering would continue across lists; number by hand so each list restarts at 1.
        for i, it in enumerate(items, 1):
            para = self.d.add_paragraph(style='Normal')
            para.paragraph_format.left_indent = Cm(0.9)
            para.paragraph_format.first_line_indent = Cm(-0.6)
            para.paragraph_format.space_after = Pt(3)
            para.alignment = WD_ALIGN_PARAGRAPH.LEFT
            self._runs(para, f'{i}.  ' + self._expand(it))

    def note(self, text):
        return self.p(text, style='NoteText')

    def code(self, text):
        lines = text.rstrip('\n').split('\n')
        for i, line in enumerate(lines):
            para = self.d.add_paragraph(style='CodeBlock')
            r = para.add_run(line if line else ' ')
            r.font.size = Pt(9)
            if i < len(lines) - 1:
                para.paragraph_format.keep_with_next = True
        self.d.add_paragraph(style='Normal').paragraph_format.space_after = Pt(2)

    def page_break(self):
        self.d.add_paragraph().add_run().add_break(WD_BREAK.PAGE)

    # ------------------------------------------------------------------ captions
    def _caption(self, kind, text, label):
        if kind == 'Figure':
            self.fig_n += 1
            n = self.fig_n
            style = 'FigureCaption'
        else:
            self.tab_n += 1
            n = self.tab_n
            style = 'TableCaption'
        number = f'{kind} {self.chapter_no}.{n}'
        if label:
            self.new_refs[label] = number
        para = self.d.add_paragraph(style=style)
        para.add_run(f'{number}: {self._expand(text)}')
        return para

    # ------------------------------------------------------------------ tables
    def table(self, headers, rows, widths=None, caption=None, label=None, size=9.5, align=None, header=True):
        """widths: list of centimetres (scaled to the text width). align: list of 'l'/'r'/'c' per column."""
        if caption:
            self._caption('Table', caption, label).paragraph_format.keep_with_next = True
        ncols = len(headers) if headers else len(rows[0])
        t = self.d.add_table(rows=0, cols=ncols)
        t.style = 'Table Grid'
        t.autofit = False
        _set_cell_margins(t)
        if widths is None:
            widths = [1] * ncols
        scale = self.text_width / sum(widths)
        widths = [w * scale for w in widths]

        def fill(row, values, head=False):
            for i, v in enumerate(values):
                cell = row.cells[i]
                cell.width = Cm(widths[i])
                text = '' if v is None else str(v)
                chunks = text.split('\n') if text else ['']
                for j, chunk in enumerate(chunks):
                    para = cell.paragraphs[0] if j == 0 else cell.add_paragraph()
                    para.style = self.d.styles['TableHead' if head else 'TableText']
                    self._runs(para, self._expand(chunk), size=size)
                    a = (align[i] if align else 'l')
                    para.alignment = {'l': WD_ALIGN_PARAGRAPH.LEFT, 'r': WD_ALIGN_PARAGRAPH.RIGHT, 'c': WD_ALIGN_PARAGRAPH.CENTER}[a]
                if head:
                    _set_cell_shading(cell, 'D9D9D9')

        if header and headers:
            row = t.add_row()
            fill(row, headers, head=True)
            trpr = row._tr.get_or_add_trPr()
            th = OxmlElement('w:tblHeader')
            th.set(qn('w:val'), 'true')
            trpr.append(th)
        for values in rows:
            row = t.add_row()
            fill(row, values)
            trpr = row._tr.get_or_add_trPr()
            cs = OxmlElement('w:cantSplit')
            cs.set(qn('w:val'), 'true')
            trpr.append(cs)
        # a short table should stay on one page: keep every row with the next one
        if len(rows) <= 14:
            for r in t.rows[:-1]:
                for cell in r.cells:
                    for para in cell.paragraphs:
                        para.paragraph_format.keep_with_next = True
        # fixed layout so the column widths hold
        tblpr = t._tbl.tblPr
        layout = OxmlElement('w:tblLayout')
        layout.set(qn('w:type'), 'fixed')
        tblpr.append(layout)
        after = self.d.add_paragraph(style='Normal')
        after.paragraph_format.space_after = Pt(4)
        return t

    # ------------------------------------------------------------------ figures
    def figure(self, path, caption, label=None, width_cm=None, max_height_cm=None):
        if not os.path.exists(path):
            raise FileNotFoundError(path)
        from PIL import Image
        with Image.open(path) as im:
            w, h = im.size
        max_height_cm = max_height_cm or self.max_fig_height
        width = min(width_cm or self.text_width, self.text_width)
        height = width * h / w
        if height > max_height_cm:
            width = width * max_height_cm / height
        para = self.d.add_paragraph(style='Normal')
        para.alignment = WD_ALIGN_PARAGRAPH.CENTER
        para.paragraph_format.keep_with_next = True
        para.paragraph_format.space_before = Pt(6)
        run = para.add_run()
        pic = run.add_picture(path, width=Cm(width))
        docpr = pic._inline.docPr
        docpr.set('descr', self._expand(caption))
        docpr.set('title', self._expand(caption)[:80])
        self._caption('Figure', caption, label)

    # ------------------------------------------------------------------ front matter
    def cover(self, lines):
        """lines: list of (text, kind) where kind is 'line', 'bold', 'big' or 'gap'."""
        for text, kind in lines:
            if kind == 'gap':
                self.d.add_paragraph(style='TitleLine').paragraph_format.space_after = Pt(18)
                continue
            para = self.d.add_paragraph(style='TitleBig' if kind == 'big' else 'TitleLine')
            run = para.add_run(text)
            if kind == 'bold':
                run.bold = True
        self.page_break()

    def toc(self, title='CONTENTS', levels='1-3'):
        self.d.add_paragraph(title, style='PrelimHeading')
        para = self.d.add_paragraph(style='Normal')
        _field(para, f'TOC \\o "{levels}" \\h \\z \\u', 'Right-click here and choose Update Field to build the contents list.')
        self.page_break()

    def list_of(self, title, style_name):
        self.d.add_paragraph(title, style='PrelimHeading')
        para = self.d.add_paragraph(style='Normal')
        _field(para, f'TOC \\h \\z \\t "{style_name},1"', 'Right-click here and choose Update Field to build this list.')

    # ------------------------------------------------------------------ output
    def save(self, path):
        self.d.core_properties.author = 'Susan Wanjiku Waweru'
        self.d.core_properties.title = os.path.basename(path)
        self.d.core_properties.comments = 'Uhai Bora Hospital Management System (UB-HMS) project documentation'
        # ask Word to refresh fields when the file is opened
        settings = self.d.settings.element
        upd = OxmlElement('w:updateFields')
        upd.set(qn('w:val'), 'true')
        settings.append(upd)
        self.d.save(path)
        return self.new_refs


def build_twice(build_fn, template, out_path):
    """Run build_fn(doc) twice so cross-references to later figures and tables resolve."""
    first = Doc(template)
    build_fn(first)
    refs = first.save(out_path)
    second = Doc(template, refs=refs)
    build_fn(second)
    second.save(out_path)
    return refs
