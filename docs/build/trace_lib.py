"""Helpers that read the test results and check that the requirement tables only cite tests that exist."""
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, '..', '..', 'tests', 'output')
EVIDENCE = os.path.join(HERE, '..', 'evidence')


def _first_existing(*names):
    for d in (EVIDENCE, OUT):
        for n in names:
            p = os.path.join(d, n)
            if os.path.exists(p):
                return p
    raise FileNotFoundError(names)


def load_results():
    """Return {test id: (description, status)} for the unit, end-to-end and Apache tests."""
    res = {}
    for r in json.load(open(_first_existing('unit-results.json'), encoding='utf-8'))['results']:
        res[r['id']] = (r.get('description') or r.get('name'), r.get('status'))
    for fname in ('e2e-results.txt',):
        try:
            path = _first_existing(fname)
        except FileNotFoundError:
            continue
        for line in open(path, encoding='utf-8'):
            m = re.match(r'(PASS|FAIL)\s+(E2E-\d+)\s+(.*)', line.strip())
            if m:
                res[m.group(2)] = (m.group(3), m.group(1))
        break
    for line in open(_first_existing('apache-results.txt'), encoding='utf-8'):
        m = re.match(r'(PASS|FAIL)\s+(APA-\d+)\s+(.*)', line.strip())
        if m:
            res[m.group(2)] = (m.group(3), m.group(1))
    return res


_RANGE = re.compile(r'((?:UT-[A-Z]{3})|E2E|APA|PERF)-(\d+) to \1-(\d+)')
_ID = re.compile(r'(?:UT-[A-Z]{3}|E2E|APA|PERF|MAN)-\d+')


def expand(text, known=None):
    """List every test id named in text, expanding 'X-001 to X-005' ranges (only ids that exist)."""
    ids = []

    def spread(m):
        prefix, a, b = m.group(1), int(m.group(2)), int(m.group(3))
        width = len(m.group(2))
        for n in range(a, b + 1):
            tid = f'{prefix}-{n:0{width}d}'
            if known is None or tid in known or prefix in ('PERF',):
                ids.append(tid)
        return ' '
    rest = _RANGE.sub(spread, text)
    ids += _ID.findall(rest)
    seen, out = set(), []
    for i in ids:
        if i not in seen:
            seen.add(i)
            out.append(i)
    return out


def check():
    from req_data import FEATURES
    from nfr_data import PERFORMANCE, SAFETY, SECURITY, QUALITY
    known = load_results()
    used, missing = set(), []
    entries = [(r[0], r[4]) for f in FEATURES for r in f['reqs']] + [(r[0], r[4]) for grp in (PERFORMANCE, SAFETY, SECURITY, QUALITY) for r in grp]
    for rid, tests in entries:
        for t in expand(tests, known):
            used.add(t)
            if t.split('-')[0] in ('UT', 'E2E', 'APA') and t not in known:
                missing.append((rid, t))
    unused = sorted(t for t in known if t not in used and not t.startswith('APA'))
    return missing, unused


if __name__ == '__main__':
    missing, unused = check()
    print('cited but not found:', missing)
    print('tests not cited by any requirement:', unused)
