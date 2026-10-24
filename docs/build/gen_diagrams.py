"""Draw every diagram used in the chapters.

    python gen_diagrams.py

Writes DOT / SVG sources to diagrams_src/ and black-and-white PNG (plus SVG) files to ../figures/.
Needs Node.js with the packages in package.json (npm install).
"""
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, 'diagrams_src')
OUT = os.path.join(HERE, '..', 'figures')


def main(only=None):
    if os.path.isdir(SRC):
        shutil.rmtree(SRC)
    os.makedirs(SRC)
    import diagrams_dot
    names = diagrams_dot.write_all(SRC)
    try:
        import diagrams_erd
        names += diagrams_erd.write_all(SRC)
    except ImportError:
        pass
    try:
        import diagrams_uc
        names += diagrams_uc.write_all(SRC)
    except ImportError:
        pass
    if only:
        for f in os.listdir(SRC):
            if os.path.splitext(f)[0] not in only:
                os.remove(os.path.join(SRC, f))
    os.makedirs(OUT, exist_ok=True)
    r = subprocess.run(['node', os.path.join(HERE, 'dot2png.js'), SRC, OUT], cwd=HERE, capture_output=True, text=True)
    print(r.stdout[-1500:])
    if r.returncode != 0:
        print(r.stderr[-3000:])
        sys.exit(1)


if __name__ == '__main__':
    main(sys.argv[1:] or None)
