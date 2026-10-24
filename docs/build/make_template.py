"""Create template.docx: the approved proposal's page set-up and styles with all content removed.

The chapter documents are built on this template so fonts, headings, captions and tables
match the proposal exactly and the chapters can be pasted in without restyling.

    python make_template.py "D:\\SHELAX\\PROPOSAL_Susan_Wanjiku_Waweru_Final.docx" template.docx
"""
import sys
from docx import Document
from docx.enum.style import WD_STYLE_TYPE
from docx.shared import Pt, RGBColor
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

src, dst = sys.argv[1], sys.argv[2]
doc = Document(src)

body = doc.element.body
sect = body.find(qn('w:sectPr'))
for child in list(body):
    if child is not sect:
        body.remove(child)

# keep one section only, with no header/footer text
for s in list(doc.sections)[1:]:
    pass
for hdr in sect.findall(qn('w:headerReference')) + sect.findall(qn('w:footerReference')):
    sect.remove(hdr)

styles = doc.styles

# A fourth heading level for feature names such as 4.3.1.1
h4 = styles['Heading 4']
h4.font.name = 'Times New Roman'
h4.font.size = Pt(12)
h4.font.bold = True
h4.font.italic = True
h4.font.color.rgb = RGBColor(0, 0, 0)
h4.paragraph_format.space_before = Pt(8)
h4.paragraph_format.space_after = Pt(3)
h4.paragraph_format.keep_with_next = True

for name in ('Heading 1', 'Heading 2', 'Heading 3', 'Heading 4'):
    st = styles[name]
    st.font.name = 'Times New Roman'
    st.font.color.rgb = RGBColor(0, 0, 0)
    st.paragraph_format.keep_with_next = True
    # remove the theme-font links, which would otherwise override the font name
    rfonts = st.element.get_or_add_rPr().find(qn('w:rFonts'))
    if rfonts is not None:
        for att in ('w:asciiTheme', 'w:hAnsiTheme', 'w:eastAsiaTheme', 'w:cstheme'):
            if rfonts.get(qn(att)) is not None:
                del rfonts.attrib[qn(att)]
        for att in ('w:ascii', 'w:hAnsi', 'w:cs', 'w:eastAsia'):
            rfonts.set(qn(att), 'Times New Roman')

# code and pseudo-code
if 'CodeBlock' not in [s.name for s in styles]:
    code = styles.add_style('CodeBlock', WD_STYLE_TYPE.PARAGRAPH)
    code.base_style = styles['Normal']
    code.font.name = 'Consolas'
    code.font.size = Pt(8.5)
    code.paragraph_format.alignment = 0
    code.paragraph_format.line_spacing = 1.0
    code.paragraph_format.space_before = Pt(0)
    code.paragraph_format.space_after = Pt(0)
    code.paragraph_format.left_indent = Pt(6)
    rpr = code.element.get_or_add_rPr()
    rfonts = rpr.find(qn('w:rFonts'))
    if rfonts is None:
        rfonts = OxmlElement('w:rFonts')
        rpr.append(rfonts)
    for att in ('w:ascii', 'w:hAnsi', 'w:cs', 'w:eastAsia'):
        rfonts.set(qn(att), 'Consolas')
    ppr = code.element.get_or_add_pPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), 'F2F2F2')
    ppr.append(shd)

# a tidy note box style
if 'NoteText' not in [s.name for s in styles]:
    note = styles.add_style('NoteText', WD_STYLE_TYPE.PARAGRAPH)
    note.base_style = styles['Normal']
    note.font.size = Pt(10.5)
    note.font.italic = True
    note.paragraph_format.left_indent = Pt(18)
    note.paragraph_format.line_spacing = 1.15

for name in ('toc 3', 'List Bullet 2'):
    try:
        styles[name]
    except KeyError:
        pass

doc.save(dst)
print('template written:', dst, '| styles:', len(styles))
