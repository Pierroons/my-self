#!/usr/bin/env python3
"""Garde-fou — le délai de prescription se lit, il ne se sérialise pas.

🔑 Il sortait en `repr` Python : « {'duree_mois': 12, 'article': 'art. L1471-1
al. 2 du code du travail', …} », sur les trois seules situations qui en portent
un. Relevé par un contrôle extérieur le 22/08/2026, avec ce qui rend le défaut
sérieux : c'est l'information dont le module dit lui-même qu'« un délai manqué
ne se rattrape pas ».

Ce banc existe pour que la leçon ne dépende plus de qui relit.

Deux formes existent dans les données et une troisième est possible : une durée
unique avec son fondement, plusieurs durées selon la qualification, et tout ce
qui viendra. Aucune ne doit rendre une accolade.

    python3 tests/sanity_prescription.py
"""

import ast
import pathlib
import sys

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"


def charger():
    arbre = ast.parse(SERVEUR.read_text())
    for noeud in arbre.body:
        if isinstance(noeud, ast.FunctionDef) and noeud.name == "_formater_prescription":
            espace: dict = {"Any": object}
            exec(compile(ast.Module(body=[noeud], type_ignores=[]), str(SERVEUR), "exec"), espace)
            return espace["_formater_prescription"]
    print("✗ _formater_prescription absente de server.py", file=sys.stderr)
    raise SystemExit(2)


def main() -> int:
    formater = charger()
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    # (valeur, fragments exigés, libellé)
    cas = [
        ({"duree_mois": 12, "article": "art. L1471-1 al. 2 du code du travail",
          "texte": "Toute action … se prescrit par douze mois.",
          "point_de_depart": "notification de la rupture"},
         ["12 mois", "notification de la rupture", "L1471-1", "ne se rattrape pas"],
         "durée en mois → lisible, fondement et point de départ nommés"),

        ({"duree_annees": 10, "article": "art. 1792 du code civil",
          "texte": "La responsabilité décennale …", "point_de_depart": "réception des travaux"},
         ["10 ans", "réception des travaux", "1792"],
         "durée en années → le pluriel suit le nombre"),

        ({"duree_annees": 1, "article": "art. X"}, ["1 an"], "une seule année → singulier"),

        ({"contravention": "1 an (art. 9 CPP)", "delit": "6 ans (art. 8 CPP)",
          "crime": "20 ans (art. 7 CPP)"},
         ["contravention", "6 ans", "20 ans", "ne choisis pas le délai"],
         "plusieurs délais → tous rendus, aucun choisi à la place de l'utilisateur"),

        ("six mois à compter de la notification",
         ["six mois"], "chaîne simple → rendue telle quelle"),
    ]

    print("▸ Ce que le lecteur reçoit")
    for valeur, attendus, libelle in cas:
        rendu = formater(valeur)
        manquants = [m for m in attendus if m not in rendu]
        verdict(not manquants, libelle + (f" — manque {manquants}" if manquants else ""))

    print()
    print("▸ Aucune forme ne laisse échapper une structure Python")
    # 🔑 Le contrôle qui compte : accolade, apostrophe de clé, ou flèche de dict
    # signalent une valeur sérialisée au lieu d'être écrite.
    for valeur, _, libelle in cas:
        rendu = formater(valeur)
        fautes = [m for m in ("{", "}", "':", "\": ") if m in rendu]
        verdict(not fautes, f"{libelle.split(' →')[0]} — {fautes if fautes else 'rien de brut'}")

    print()
    print("▸ L'absence reste silencieuse")
    # Les 17 situations sans prescription ne doivent pas gagner un bandeau vide.
    for vide in (None, "", {}, 0):
        verdict(formater(vide) == "", f"« {vide!r} » → aucune ligne")

    print()
    print("▸ Une forme inconnue perd l'affichage, jamais l'information")
    inedit = {"duree_semaines": 3, "commentaire": "forme non prévue"}
    rendu = formater(inedit)
    verdict("duree semaines" in rendu or "duree_semaines" in rendu,
            "clé inédite → rendue plutôt que tue")

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
