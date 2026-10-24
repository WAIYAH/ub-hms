"""Render a PDF to contact sheets (PNG) so the layout can be checked by eye: python pdf_sheets.py file.pdf outdir [per_sheet]"""
import sys, os
import fitz
from PIL import Image, ImageDraw
pdf, out = sys.argv[1], sys.argv[2]
per = int(sys.argv[3]) if len(sys.argv) > 3 else 8
os.makedirs(out, exist_ok=True)
doc = fitz.open(pdf)
cols = 4
cell_w = 450
for s in range(0, len(doc), per):
    tiles = []
    for i in range(s, min(s + per, len(doc))):
        pix = doc[i].get_pixmap(matrix=fitz.Matrix(0.75, 0.75))
        im = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
        im = im.resize((cell_w, int(im.height * cell_w / im.width)))
        d = ImageDraw.Draw(im); d.rectangle((0, 0, 40, 14), fill='black'); d.text((3, 2), str(i + 1), fill='white')
        tiles.append(im)
    rows = (len(tiles) + cols - 1) // cols
    h = max(t.height for t in tiles)
    sheet = Image.new('RGB', (cols * cell_w, rows * h), 'white')
    for k, t in enumerate(tiles):
        sheet.paste(t, ((k % cols) * cell_w, (k // cols) * h))
    sheet.save(os.path.join(out, f'sheet_{s // per + 1:02d}.png'))
print(len(doc), 'pages')
