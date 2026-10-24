"""Print how large the text in each figure will be when the figure is scaled to the page."""
import glob
import os
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
print(f"{'figure':20} {'natural cm':>13} {'portrait':>20} {'landscape':>20}")
for f in sorted(glob.glob(os.path.join(HERE, '..', 'figures', '*.png'))):
    w, h = Image.open(f).size
    wcm, hcm = w / 192 * 2.54, h / 192 * 2.54
    p = min(15.29 / wcm, 21.0 / hcm)
    l = min(23.98 / wcm, 14.2 / hcm)
    print(f"{os.path.basename(f)[:-4]:20} {wcm:5.1f}x{hcm:<6.1f}  scale {p:4.2f} = {12 * p:4.1f} pt   scale {l:4.2f} = {12 * l:4.1f} pt")
