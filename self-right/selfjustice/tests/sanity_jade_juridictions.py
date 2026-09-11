#!/usr/bin/env python3
"""Garde-fou — les 113 libellés du fonds JADE tombent tous dans le bon code.

🔑 Une table de correspondance se juge sur ce qu'elle ÉNUMÈRE, jamais sur ce
qu'elle compte. Ce contrôle rejoue les libellés RÉELS du dump — extraits par
inventaire du fonds entier, pas écrits à la main — et exige que la répartition
obtenue soit celle qui a été mesurée, décision pour décision.

Pourquoi le jeu vient du fonds et non de l'intuition : les quatre derniers diffs
quotidiens ne montraient que dix libellés, tous récents et tous accentués. Le
fonds remonte à 1873 et écrit « Conseil d'Etat » sans accent 113 069 fois. Une
table bâtie sur l'échantillon récent aurait normalisé le présent et laissé
soixante ans de décisions sous des juridictions fantômes — sans rien casser, et
sans qu'aucun compte ne le révèle.

Trois propriétés, et la troisième est celle qui coûte le plus cher à perdre :

1. **Aucun libellé n'est refusé.** Un seul `None` signifie des décisions
   perdues à la collecte.
2. **La répartition est exactement celle de l'inventaire.** Un libellé rangé
   sous le mauvais code garde les comptes justes au total et faux par
   juridiction : c'est le défaut qu'aucune mesure de volume ne montre.
3. **Ce qui n'est pas du fonds est refusé.** Une table qui accepte tout ne
   range rien — elle attribue.

    python3 tests/sanity_jade_juridictions.py
"""

import collections
import json
import pathlib
import sys

RACINE = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RACINE / "tools"))
JEU = RACINE / "tests" / "jade-jeu-juridictions.json"

from jade_juridictions import CODES, normaliser  # noqa: E402

# La répartition mesurée sur Freemium_jade_global_20250713, le 10/09/2026.
# Elle est écrite ici pour que le contrôle ait un attendu indépendant du jeu :
# si les deux dérivaient de la même source, il ne resterait qu'une tautologie.
#
# `ce` porte 170 502 décisions dont le libellé nomme le Conseil d'État, PLUS les
# deux dont le libellé est « Section du Contentieux » — une formation, pas une
# juridiction. C'est une décision de rangement, pas une mesure : elle est donc
# écrite ici, où on la voit, plutôt que fondue dans un total.
ATTENDU = {"caa": 373679, "ce": 170502 + 2, "ta": 6514, "tc": 1811, "cdbf": 68}

# Des libellés qui n'appartiennent pas au fonds administratif. Ils doivent être
# refusés : les accepter rangerait sous une juridiction administrative ce qui
# n'en est pas une.
ETRANGERS = (
    "Chambre régionale des comptes",
    "Cour de cassation",
    "Cour d'appel de Paris",
    "Tribunal judiciaire de Lyon",
    "Commission nationale de l'informatique et des libertés",
    "",
    "   ",
)


def main() -> int:
    jeu = json.loads(JEU.read_text(encoding="utf-8"))
    libelles = jeu["libelles"]
    defauts = []

    obtenu = collections.Counter()
    refuses = []
    villes = collections.Counter()
    for e in libelles:
        r = normaliser(e["libelle"])
        if r is None:
            refuses.append((e["libelle"], e["decisions"]))
            continue
        code, ville = r
        if code not in CODES:
            defauts.append(f"« {e['libelle']} » rend le code « {code} », "
                           f"absent de CODES")
            continue
        obtenu[code] += e["decisions"]
        if ville:
            villes[ville] += e["decisions"]

    for lib, n in refuses:
        defauts.append(f"« {lib} » ({n} décisions) n'est pas reconnu — "
                       f"elles seraient perdues à la collecte")

    for code in sorted(set(ATTENDU) | set(obtenu)):
        if obtenu.get(code, 0) != ATTENDU.get(code, 0):
            defauts.append(f"{code} : {obtenu.get(code, 0)} décisions classées, "
                           f"{ATTENDU.get(code, 0)} mesurées dans le fonds")

    total = sum(obtenu.values())
    if total != jeu["_total_decisions"]:
        defauts.append(f"{total} décisions classées sur {jeu['_total_decisions']} "
                       f"— l'écart est perdu")

    for lib in ETRANGERS:
        if normaliser(lib) is not None:
            defauts.append(f"« {lib} » est accepté alors qu'il n'appartient pas "
                           f"au fonds administratif")

    # Le repli doit absorber casse et accents : les trois graphies de Marseille
    # existent réellement dans le fonds, et doivent donner le même couple.
    graphies = ("CAA de MARSEILLE", "Cour Administrative d'Appel de Marseille",
                "Cour administrative d'appel de Marseille")
    codes_vus = {normaliser(g)[0] for g in graphies if normaliser(g)}
    if codes_vus != {"caa"}:
        defauts.append(f"les graphies de Marseille rendent {codes_vus or 'rien'} "
                       f"au lieu de caa seul")

    if defauts:
        print(f"✗ {len(defauts)} défaut(s) :", file=sys.stderr)
        for d in defauts:
            print("  · " + d, file=sys.stderr)
        return 1

    print(f"✓ {len(libelles)} libellés du fonds classés sans reste — "
          f"{total} décisions")
    print("  " + " · ".join(f"{c} {obtenu[c]}" for c in
                            sorted(obtenu, key=obtenu.get, reverse=True)))
    print(f"  {len(villes)} villes distinctes · {len(ETRANGERS)} libellés "
          f"étrangers refusés")
    return 0


if __name__ == "__main__":
    sys.exit(main())
