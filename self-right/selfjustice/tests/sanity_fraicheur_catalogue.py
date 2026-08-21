#!/usr/bin/env python3
"""Garde-fou — ce que le serveur MCP dit de la fraîcheur du catalogue SelfAct.

🔑 Deux jeux de données servent ce module, et ils n'ont pas le même régime.
`catalog.json` est synchronisé automatiquement les 1er et 15 ; il peut donc
prendre du retard, et ce retard doit se voir. `situations.json` porte le
rapprochement situation → acte, curé à la main, sans cron : aucune échéance ne
lui est opposable.

Jusqu'au 21/08/2026 les deux étaient annoncés d'un même « Catalogue SelfAct
synchronisé au … ». Un lecteur voyait donc le même module se dater du 18 avril
sur un outil et du 3 août sur l'autre, sans rien pour trancher entre une panne
de cron et une curation simplement ancienne — et le vrai retard, dix-huit jours
sur le catalogue synchronisé, ne se signalait nulle part alors que les bases de
textes ont, elles, une alerte.

Le calcul dépend du jour où il tourne : la date est donc gelée ici, sans quoi le
test dirait quelque chose de différent à chaque exécution et finirait par ne
plus rien dire du tout.

    python3 tests/sanity_fraicheur_catalogue.py
"""

import ast
import asyncio
import datetime
import pathlib
import re
import sys
import unicodedata

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"

VOULUES = {
    "_bandeau_catalogue", "_parse_date_fr", "_derniere_echeance", "_format_fr",
    "MOIS", "DATE_ISO",
}

# Un mardi ordinaire, postérieur à l'échéance du 15 : c'est la configuration où
# le retard doit se dire, donc celle qui vaut d'être figée.
AUJOURDHUI = datetime.date(2026, 8, 21)


class DateGelee(datetime.date):
    @classmethod
    def today(cls):
        return AUJOURDHUI


def charger() -> dict:
    arbre = ast.parse(SERVEUR.read_text())
    gardes, trouves = [], set()
    for noeud in arbre.body:
        nom = None
        if isinstance(noeud, (ast.FunctionDef, ast.AsyncFunctionDef)):
            nom = noeud.name
        elif isinstance(noeud, ast.Assign) and isinstance(noeud.targets[0], ast.Name):
            nom = noeud.targets[0].id
        if nom in VOULUES:
            gardes.append(noeud)
            trouves.add(nom)
    if trouves != VOULUES:
        print(f"✗ manquant dans server.py : {sorted(VOULUES - trouves)}", file=sys.stderr)
        raise SystemExit(2)

    faux_dt = type(sys)("datetime")
    faux_dt.date = DateGelee
    faux_dt.timedelta = datetime.timedelta
    espace: dict = {"dt": faux_dt, "re": re, "unicodedata": unicodedata, "Any": object}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SERVEUR), "exec"), espace)
    return espace


def main() -> int:
    bandeau = charger()["_bandeau_catalogue"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    # (métadonnées, ce que la ligne doit contenir, ce qu'elle ne doit pas, libellé)
    cas = [
        ({"version": "2026.08", "last_sync": "2026-08-03T17:58:05+00:00"},
         ["⚠️", "18 jours", "15 août 2026"], [],
         "catalogue synchronisé en retard → le retard est chiffré et l'échéance nommée"),
        ({"version": "2026.08", "last_sync": "2026-08-15"},
         ["synchronisé au 2026-08-15"], ["⚠️"],
         "catalogue à l'heure → aucune alerte"),
        ({"version": "2026.08", "last_sync": "2026-08-21"},
         ["synchronisé au 2026-08-21"], ["⚠️"],
         "catalogue du jour même → aucune alerte"),
        ({"version": "2026.04", "last_update": "2026-04-18"},
         ["curé à la main", "signale pas de retard"], ["⚠️", "synchronisé au"],
         "curation manuelle ancienne → pas de retard opposable"),
        ({"version": "2026.04", "last_update": "2026-04-18",
          "catalogue": {"version": "2026.08", "last_sync": "2026-08-03"}},
         ["curé à la main", "⚠️", "18 jours"], [],
         "curation + catalogue en retard → les deux se disent"),
        ({"version": "2026.04", "last_update": "2026-04-18",
          "catalogue": {"version": "2026.08", "last_sync": "2026-08-15"}},
         ["curé à la main"], ["⚠️"],
         "curation + catalogue à jour → une seule ligne"),
        ({"version": "?", "last_sync": "pas une date du tout"},
         ["pas une date du tout", "à vérifier"], [],
         "date illisible → la valeur entière est citée, pas une troncature"),
        (None, ["date inconnue"], [],
         "métadonnées absentes → dit, plutôt qu'une fraîcheur supposée"),
    ]

    print("▸ Ce que le bandeau annonce, selon la source")
    for meta, attendus, interdits, libelle in cas:
        ligne = asyncio.run(bandeau(meta))
        manquants = [m for m in attendus if m not in ligne]
        indus = [m for m in interdits if m in ligne]
        verdict(
            not manquants and not indus,
            libelle
            + (f" — manque {manquants}" if manquants else "")
            + (f" — contient à tort {indus}" if indus else ""),
        )

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
