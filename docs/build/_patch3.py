"""Remove connectors from error branches to the common End node: a branch that ends in a message simply stops."""
import re

p = 'diagrams_flow.py'
s = open(p, encoding='utf-8', newline='').read()
before = len(re.findall(r"\('\w+', 'f', '', 'loose'\)", s))
s = re.sub(r",\s*\('\w+', 'f', '', 'loose'\)", '', s)          # entries after another entry
s = re.sub(r"\[\('\w+', 'f', '', 'loose'\),\s*", '[', s)         # entry at the start of a list
after = len(re.findall(r"\('\w+', 'f', '', 'loose'\)", s))
open(p, 'w', encoding='utf-8', newline='').write(s)
print('removed', before - after, 'remaining', after)
