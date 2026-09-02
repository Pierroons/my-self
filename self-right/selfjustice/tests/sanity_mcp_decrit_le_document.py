#!/usr/bin/env python3
"""Garde-fou — le MCP ne décrit pas un avertissement que le document ne porte plus.

🔑 Le serveur MCP n'expose pas `/act/api/draft`, mais il le DÉCRIT au modèle :
`gabarit_document` dit quel avertissement porte le document qu'il envoie ouvrir.
Ce texte part chez le client, qui le répète à l'utilisateur.

Le 02/09/2026, `draft.php` a remplacé son filigrane SVG « NON OFFICIEL —
IRRECEVABLE » par la mention « NON OFFICIEL » en tête, au milieu et en pied. Le
serveur MCP a continué d'annoncer le filigrane. Le défaut n'a rien cassé : deux
fichiers de deux modules décrivent la même chose, et rien ne les tenait ensemble.

⚠️ Ce banc protège aussi l'ORDRE des publications. Corriger le MCP avant que
`draft.php` ne soit livré inverse simplement le sens de l'écart : le serveur
annoncerait une mention que le document servi ne porte pas encore. Les deux
doivent voyager ensemble, et c'est ce que ce contrôle impose.

    python3 tests/sanity_mcp_decrit_le_document.py
"""

import ast
import pathlib
import re
import sys

RACINE = pathlib.Path(__file__).resolve().parent.parent.parent   # self-right/
SERVEUR = RACINE / "selfjustice" / "mcp" / "selfright_mcp" / "server.py"
DOCUMENT = RACINE / "selfact" / "api" / "draft.php"

# Les deux mécanismes d'avertissement que ce document a portés. Inventoriés dans
# le fichier, pas supposés : le filigrane SVG jusqu'au 02/09/2026, la mention
# répétée depuis. Un troisième s'ajoute ici le jour où il existe.
MARQUEURS = {
    "filigrane": 'class="watermark"',
    "mention": 'class="mention"',
}


def docstrings_des_outils(source: str) -> list:
    """Les docstrings des fonctions décorées @server.tool() — ce que le client reçoit."""
    sorties = []
    for noeud in ast.walk(ast.parse(source)):
        if not isinstance(noeud, (ast.FunctionDef, ast.AsyncFunctionDef)):
            continue
        expose = any(isinstance(d, ast.Call) and getattr(d.func, "attr", "") == "tool"
                     for d in noeud.decorator_list)
        if expose and ast.get_docstring(noeud):
            sorties.append((noeud.name, ast.get_docstring(noeud)))
    return sorties


def main() -> int:
    document = DOCUMENT.read_text()
    outils = docstrings_des_outils(SERVEUR.read_text())
    if not outils:
        print("✗ aucun outil trouvé dans server.py — le banc ne mesure rien", file=sys.stderr)
        return 2

    echecs = 0
    vus = 0
    print(f"▸ {len(outils)} outil(s) exposé(s), leurs docstrings relus")

    for nom, doc in outils:
        # 1. Toute formule d'avertissement citée entre guillemets doit figurer
        #    telle quelle dans le document.
        for formule in re.findall(r"«\s*([^»]*OFFICIEL[^»]*?)\s*»", doc):
            vus += 1
            if formule in document:
                print(f"  ✓ {nom} annonce « {formule} », le document la porte")
            else:
                print(f"  ✗ {nom} annonce « {formule} », ABSENTE de draft.php")
                echecs += 1

        # 2. Le mécanisme nommé doit exister. Annoncer un filigrane quand le
        #    document porte une mention est faux même si les mots de l'avertissement
        #    se retrouvent par ailleurs dans le fichier.
        for mot, marqueur in MARQUEURS.items():
            if re.search(rf"\b{mot}s?\b", doc, re.IGNORECASE):
                vus += 1
                if marqueur in document:
                    print(f"  ✓ {nom} parle de « {mot} », draft.php porte {marqueur}")
                else:
                    print(f"  ✗ {nom} parle de « {mot} », mais draft.php ne porte "
                          f"aucun {marqueur}")
                    echecs += 1

    print()
    if not vus:
        print("✗ aucun outil ne décrit l'avertissement du document — le contrôle "
              "rendrait vert sans rien mesurer", file=sys.stderr)
        return 2
    if echecs:
        print(f"✗ {echecs} affirmation(s) du MCP que le document ne soutient pas")
        return 1
    print(f"✓ les {vus} affirmation(s) du MCP sur le document correspondent à draft.php")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
