#!/usr/bin/env python3
"""Garde-fou — toute variable d'environnement lue est documentée.

🔑 Une variable qu'un opérateur ne peut pas connaître n'existe pas pour lui : il
configure ce que la documentation nomme, et découvre le reste en lisant le code
— ou ne le découvre jamais. `SELFRIGHT_ACT_URL` a manqué au docstring du module
pendant qu'elle figurait au README ; l'écart a été relevé par un contrôle, pas
par une sonde.

Les deux surfaces comptent, et pour des raisons différentes : le README est ce
qu'on lit avant d'installer, le docstring ce qu'on lit quand on ouvre le
fichier. Une variable absente de l'un des deux est absente pour quelqu'un.

    python3 tests/sanity_variables_documentees.py
"""

import pathlib
import re
import sys

RACINE = pathlib.Path(__file__).resolve().parent.parent / "mcp"
SERVEUR = RACINE / "selfright_mcp" / "server.py"
README = RACINE / "README.md"


def main() -> int:
    src = SERVEUR.read_text()
    # Le docstring du module : tout ce qui précède la première ligne de code.
    fin = src.index('"""', src.index('"""') + 3)
    docstring = src[:fin]
    readme = README.read_text()

    lues = sorted(set(re.findall(r'os\.environ\.get\(\s*"([A-Z_]+)"', src)))
    if not lues:
        print("✗ aucune variable trouvée — l'extraction ne mesure plus rien", file=sys.stderr)
        return 2

    echecs = 0
    print(f"▸ Les {len(lues)} variables lues par le code")
    for v in lues:
        au_doc = v in docstring
        au_readme = v in readme
        ok = au_doc and au_readme
        if not ok:
            echecs += 1
        manque = " · ".join(
            n for n, present in (("docstring", au_doc), ("README", au_readme)) if not present)
        print(f"  {'✓' if ok else '✗'} {v}" + (f" — absente de : {manque}" if manque else ""))

    print()
    if echecs:
        print(f"✗ {echecs} variable(s) qu'un opérateur ne peut pas connaître")
        return 1
    print("✓ toutes documentées, des deux côtés")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
