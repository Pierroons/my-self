#!/usr/bin/env python3
"""Inspecter la structure d'un article sur EUR-Lex pour comprendre où est le contenu."""
import sys
from urllib.request import Request, urlopen
from lxml import html as lhtml

url = "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:12016P/TXT"
req = Request(url, headers={"User-Agent": "Mozilla/5.0"})
raw = urlopen(req, timeout=30).read()

tree = lhtml.fromstring(raw)
art_titles = tree.xpath('//p[contains(@class, "ti-art")]')

print(f"Articles trouvés : {len(art_titles)}\n")

# Regarder l'article 8 — prendre le 8e ti-art
if len(art_titles) >= 8:
    ti = art_titles[7]  # 0-indexed
    t = ti.text_content().strip()
    print(f"=== {t} ===")
    elem = ti
    for _ in range(15):
        elem = elem.getnext()
        if elem is None:
            break
        cls = elem.get("class", "") or ""
        tag = elem.tag
        content = elem.text_content().strip()[:200]
        print(f"  <{tag} class='{cls}'>")
        print(f"    {content!r}")
        if "ti-art" in cls:
            break
