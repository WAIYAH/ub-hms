"""Use case diagrams drawn as SVG (UML style, black and white): actors as stick figures, a system boundary,
use cases as ellipses and dashed <<include>> arrows. One diagram per module."""
import os
import textwrap
from xml.sax.saxutils import escape

from usecases_data import MODULES, USE_CASES

W = 540
LEFT_X, RIGHT_X = 52, W - 52
BOX_X0, BOX_X1 = 118, W - 118
CX = (BOX_X0 + BOX_X1) / 2
RX, RY = 128, 23
ROW = 62
TOP = 70
FONT = 12


def stick(x, y, name):
    """Stick figure with its head at (x, y); returns svg and the body anchor y."""
    parts = [
        f'<circle cx="{x}" cy="{y}" r="8" fill="white" stroke="black" stroke-width="1.5"/>',
        f'<line x1="{x}" y1="{y + 8}" x2="{x}" y2="{y + 30}" stroke="black" stroke-width="1.5"/>',
        f'<line x1="{x - 14}" y1="{y + 17}" x2="{x + 14}" y2="{y + 17}" stroke="black" stroke-width="1.5"/>',
        f'<line x1="{x}" y1="{y + 30}" x2="{x - 11}" y2="{y + 48}" stroke="black" stroke-width="1.5"/>',
        f'<line x1="{x}" y1="{y + 30}" x2="{x + 11}" y2="{y + 48}" stroke="black" stroke-width="1.5"/>',
    ]
    lines = textwrap.wrap(name, 13) or [name]
    for i, ln in enumerate(lines[:3]):
        parts.append(f'<text x="{x}" y="{y + 64 + i * 13}" font-size="11" text-anchor="middle" font-family="Arial">{escape(ln)}</text>')
    return ''.join(parts), y + 20


def diagram(key):
    mod = next(m for m in MODULES if m[0] == key)
    title, left_actors, right_actors = mod[1], mod[3], mod[4]
    ucs = USE_CASES[key]
    n = len(ucs)
    height = TOP + n * ROW + 40
    ys = {}
    for i, uc in enumerate(ucs):
        ys[i] = TOP + 30 + i * ROW
    # actor vertical positions: average of their use cases, then spread
    def place(actors, side):
        pos = []
        for a in actors:
            linked = [ys[i] for i, uc in enumerate(ucs) if a in uc[2]]
            pos.append([a, sum(linked) / len(linked) if linked else height / 2])
        pos.sort(key=lambda p: p[1])
        for i in range(1, len(pos)):
            if pos[i][1] - pos[i - 1][1] < 105:
                pos[i][1] = pos[i - 1][1] + 105
        return pos
    left_pos, right_pos = place(left_actors, 'L'), place(right_actors, 'R')
    bottom = max([height] + [p[1] + 100 for p in left_pos + right_pos])
    out = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{int(bottom)}" viewBox="0 0 {W} {int(bottom)}" font-family="Arial">',
           '<defs><marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto"><path d="M0,0 L10,5 L0,10 z" fill="black"/></marker></defs>',
           f'<rect x="0" y="0" width="{W}" height="{int(bottom)}" fill="white"/>',
           f'<rect x="{BOX_X0}" y="36" width="{BOX_X1 - BOX_X0}" height="{int(bottom) - 44}" fill="none" stroke="black" stroke-width="1.6"/>',
           f'<text x="{W / 2}" y="22" font-size="13" font-weight="bold" text-anchor="middle">{escape("UB-HMS: " + title)}</text>']
    anchors = {}
    for name, ycentre in left_pos:
        svg, ya = stick(LEFT_X, ycentre - 20, name)
        out.append(svg)
        anchors[name] = (LEFT_X + 6, ya, 'L')
    for name, ycentre in right_pos:
        svg, ya = stick(RIGHT_X, ycentre - 20, name)
        out.append(svg)
        anchors[name] = (RIGHT_X - 6, ya, 'R')
    # lines first (under the ellipses)
    for i, uc in enumerate(ucs):
        for a in uc[2]:
            ax, ay, side = anchors[a]
            ex = CX - RX if side == 'L' else CX + RX
            out.append(f'<line x1="{ax}" y1="{ay}" x2="{ex}" y2="{ys[i]}" stroke="black" stroke-width="1"/>')
    base_index = None
    for i, uc in enumerate(ucs):
        if uc[0]:
            base_index = i
        elif base_index is not None:
            out.append(f'<line x1="{CX + 40}" y1="{ys[i - 1] + RY}" x2="{CX + 40}" y2="{ys[i] - RY - 1}" stroke="black" stroke-width="1.1" stroke-dasharray="5,3" marker-end="url(#arr)"/>')
            out.append(f'<text x="{CX + 46}" y="{(ys[i - 1] + ys[i]) / 2 + 4}" font-size="10" font-style="italic">&#171;include&#187;</text>')
    for i, uc in enumerate(ucs):
        label = (uc[0] + ' ' if uc[0] else '') + uc[1]
        lines = textwrap.wrap(label, 30)[:2]
        out.append(f'<ellipse cx="{CX}" cy="{ys[i]}" rx="{RX}" ry="{RY}" fill="white" stroke="black" stroke-width="1.3"{" stroke-dasharray=\"0\"" if uc[0] else ""}/>')
        for j, ln in enumerate(lines):
            dy = (j - (len(lines) - 1) / 2) * 13
            out.append(f'<text x="{CX}" y="{ys[i] + dy + 4}" font-size="{FONT}" text-anchor="middle">{escape(ln)}</text>')
    out.append('</svg>')
    return '\n'.join(out)


def write_all(outdir):
    names = []
    for key in USE_CASES:
        with open(os.path.join(outdir, key + '.svg'), 'w', encoding='utf-8', newline='\n') as f:
            f.write(diagram(key))
        names.append(key)
    return names
