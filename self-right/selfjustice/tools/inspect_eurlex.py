#!/usr/bin/env python3
"""Inspecter la structure HTML d'EUR-Lex pour identifier les classes CSS des articles."""
import re
import sys
from urllib.request import Request, urlopen

url = sys.argv[1] if len(sys.argv) > 1 else "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:12016P/TXT"

req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
html = urlopen(req, timeout=30).read().decode("utf-8", errors="replace")

print(f"=== Longueur HTML : {len(html)} ===\n")

# Chercher les classes CSS
classes = {}
for m in re.finditer(r'class="([^"]+)"', html):
    for c in m.group(1).split():
        classes[c] = classes.get(c, 0) + 1

# Classes contenant 'art', 'ti', 'doc', 'num'
relevant = [(c, n) for c, n in classes.items() if any(k in c.lower() for k in ("art", "ti-", "sti", "doc-", "num"))]
relevant.sort(key=lambda x: -x[1])

print("=== Classes CSS pertinentes (triées par occurrence) ===")
for c, n in relevant[:30]:
    print(f"  {n:5d}  .{c}")

# Chercher quelques exemples de paragraphes avec la classe la plus prometteuse
print("\n=== Exemples de <p class='ti-art'> (début d'article) ===")
for m in re.finditer(r'<p[^>]*class="[^"]*ti-art[^"]*"[^>]*>([^<]+)</p>', html)[:5] if False else []:
    print(f"  {m.group(1)[:80]}")

# Approche alternative : chercher les éléments avec id="art_X"
ids = re.findall(r'id="(art_[^"]+)"', html)
print(f"\n=== IDs 'art_X' trouvés : {len(ids)} ===")
for i in ids[:10]:
    print(f"  {i}")
