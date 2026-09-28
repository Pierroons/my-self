#!/usr/bin/env python3
"""Garde-fou — les pages de justice.my-self.fr restent compatibles avec sa CSP.

Le vhost sert `script-src 'self'` : un navigateur n'exécute que les scripts
chargés depuis le site. Un `<script>` inline, un attribut `onclick=` ou un lien
`javascript:` ne produit aucune erreur côté serveur — la page répond 200, et la
fonction qu'il portait est morte chez le visiteur, en silence.

C'est arrivé à l'accueil : le bouton de copie, le formulaire de retours et la
grille des consultations par IA étaient des scripts inline, bloqués par la CSP.
Le formulaire, privé de son JavaScript, partait en GET et mettait le
commentaire dans l'URL.

Le vhost vit hors dépôt ; ce test tient le côté du dépôt. Il lit chaque page
que ce vhost sert, et échoue si l'une d'elles manque.

    python3 tests/sanity_csp_compatible.py

`CSP_PAGES` (chemins séparés par `:`) remplace la liste, pour l'éprouver sur
une page plantée.
"""

import os
import pathlib
import re
import sys

RACINE = pathlib.Path(__file__).resolve().parents[3]

PAGES = [
    "self-right/selfjustice/site/index.php",
    "self-right/selfjustice/admin/watch.php",
    "self-right/selfact/site/act.php",
    "self-right/selfact/site/act-docs.html",
]

# Un bloc de données n'est pas exécuté : la CSP ne le concerne pas.
TYPES_DONNEES = ("application/ld+json", "application/json")

BALISE_SCRIPT = re.compile(r"<script\b([^>]*)>", re.I)
ATTR_SRC = re.compile(r"\ssrc\s*=\s*[\"']([^\"']*)", re.I)
ATTR_TYPE = re.compile(r"\stype\s*=\s*[\"']([^\"']*)", re.I)
GESTIONNAIRE = re.compile(r"<[a-z][^<>]*?\s(on[a-z]+)\s*=", re.I)
URL_JS = re.compile(r"(?:href|src|action)\s*=\s*[\"']\s*javascript:", re.I)


def ligne(texte, pos):
    return texte.count("\n", 0, pos) + 1


def examiner(chemin):
    texte = chemin.read_text(encoding="utf-8")
    defauts = []
    for m in BALISE_SCRIPT.finditer(texte):
        attrs = m.group(1)
        src = ATTR_SRC.search(attrs)
        if src is None:
            type_ = ATTR_TYPE.search(attrs)
            if type_ and type_.group(1).strip().lower() in TYPES_DONNEES:
                continue
            defauts.append((ligne(texte, m.start()), "script inline"))
        elif re.match(r"(?i)\s*(https?:)?//", src.group(1)):
            defauts.append((ligne(texte, m.start()), f"script hors du site : {src.group(1)}"))
    for m in GESTIONNAIRE.finditer(texte):
        defauts.append((ligne(texte, m.start()), f"gestionnaire {m.group(1)}="))
    for m in URL_JS.finditer(texte):
        defauts.append((ligne(texte, m.start()), "URL javascript:"))
    return defauts


def main():
    pages = os.environ.get("CSP_PAGES")
    chemins = [pathlib.Path(p) for p in pages.split(":")] if pages else [RACINE / p for p in PAGES]

    manquantes = [c for c in chemins if not c.is_file()]
    if manquantes:
        for c in manquantes:
            print(f"❌ page introuvable : {c}")
        return 1

    echecs = 0
    for chemin in chemins:
        for num, quoi in examiner(chemin):
            print(f"❌ {chemin}:{num} — {quoi}")
            echecs += 1
    if echecs:
        print(f"\n{echecs} défaut(s) : la CSP du vhost bloquerait ces scripts dans le navigateur.")
        return 1
    print(f"✅ {len(chemins)} page(s) lue(s), aucun script que la CSP bloquerait")
    return 0


if __name__ == "__main__":
    sys.exit(main())
