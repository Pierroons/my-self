#!/usr/bin/env python3
"""Garde-fou — le rafraîchissement Judilibre repart de ses propres juridictions.

`depuis_auto()` fixe le début de la fenêtre que `--depuis auto` remoissonne. La
table `decisions` qu'il lit est partagée avec le collecteur JADE, dont certains
fonds sont éteints (la Cour de discipline budgétaire s'arrête le 19/04/2000).
Compter ces fonds fait remoissonner toute l'histoire de `cc` et `ca` à chaque
passage : au 01/11/2026, une fenêtre ouverte le 20/03/2000, 147 tranches, et
l'amont qui en refuse une partie.

Les fonctions sont chargées par AST, comme dans `test_sedimentation.py` : le
script complet importe l'API amont et une clé que l'intégration continue n'a pas.

    python3 tests/test_reprise_judilibre.py
"""

import ast
import pathlib
import sqlite3
import sys
from datetime import date, datetime, timedelta

SCRIPT = pathlib.Path(__file__).resolve().parent.parent / "tools" / "build_judilibre_index.py"
VOULUES = {"depuis_auto", "JURIDICTIONS", "DATE_DEBUT", "RECOUVREMENT_JOURS"}


def charger() -> dict:
    arbre = ast.parse(SCRIPT.read_text())
    gardes, trouves = [], set()
    for noeud in arbre.body:
        nom = None
        if isinstance(noeud, ast.FunctionDef):
            nom = noeud.name
        elif isinstance(noeud, ast.Assign) and isinstance(noeud.targets[0], ast.Name):
            nom = noeud.targets[0].id
        if nom in VOULUES:
            gardes.append(noeud)
            trouves.add(nom)
    if trouves != VOULUES:
        print(f"✗ manquant dans build_judilibre_index.py : {sorted(VOULUES - trouves)}",
              file=sys.stderr)
        raise SystemExit(2)
    espace: dict = {"date": date, "datetime": datetime, "timedelta": timedelta}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SCRIPT), "exec"), espace)
    return espace


def base(lignes) -> sqlite3.Connection:
    db = sqlite3.connect(":memory:")
    db.execute("CREATE TABLE decisions (id TEXT PRIMARY KEY, jurisdiction TEXT, "
               "decision_date TEXT, date_suspecte INTEGER DEFAULT 0)")
    db.executemany("INSERT INTO decisions VALUES (?,?,?,?)",
                   [(f"d{i}", j, d, s) for i, (j, d, s) in enumerate(lignes)])
    return db


def main() -> int:
    espace = charger()
    depuis_auto = espace["depuis_auto"]
    juridictions = espace["JURIDICTIONS"]
    debut = espace["DATE_DEBUT"]
    recouvrement = espace["RECOUVREMENT_JOURS"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    def moins_recouvrement(jour: str) -> str:
        return (date.fromisoformat(jour) - timedelta(days=recouvrement)).isoformat()

    print(f"▸ Les juridictions moissonnées sont {juridictions}")
    verdict(set(juridictions) == {"cc", "ca"},
            "le banc décrit `cc` et `ca` ; une liste changée demande de relire ses cas")

    # Les dates de l'index servi, lues le 03/11/2026 sur la base de production.
    servie = [
        ("cc", "2026-10-22", 0), ("ca", "2026-10-29", 0),
        ("ce", "2026-10-29", 0), ("caa", "2026-10-29", 0), ("tc", "2026-10-05", 0),
        ("ta", "2009-12-17", 0), ("cdbf", "2000-04-19", 0),
    ]

    print()
    print("▸ Un fonds JADE éteint ne recule pas la reprise")
    obtenu = depuis_auto(base(servie))
    attendu = moins_recouvrement("2026-10-22")
    verdict(obtenu == attendu,
            f"index servi du 03/11/2026 → {obtenu}, attendu {attendu}")

    print()
    print("▸ La reprise suit la juridiction moissonnée la plus en retard")
    obtenu = depuis_auto(base([("cc", "2026-06-01", 0), ("ca", "2026-10-29", 0)]))
    attendu = moins_recouvrement("2026-06-01")
    verdict(obtenu == attendu, f"cc au 01/06, ca au 29/10 → {obtenu}, attendu {attendu}")

    print()
    print("▸ Une date suspecte ne fixe pas la reprise")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ca", "2026-10-29", 0),
                               ("ca", "2099-01-01", 1)]))
    attendu = moins_recouvrement("2026-10-22")
    verdict(obtenu == attendu, f"une décision datée de 2099 marquée suspecte → {obtenu}")

    print()
    print("▸ Une juridiction moissonnée absente de l'index repart du début")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ce", "2026-10-29", 0)]))
    verdict(obtenu == debut, f"`ca` absente, `cc` et `ce` présentes → {obtenu}, attendu {debut}")
    obtenu = depuis_auto(base([("cc", "2026-10-22", 0), ("ca", "0201-02-24", 1)]))
    verdict(obtenu == debut,
            f"`ca` n'a que des dates suspectes → {obtenu}, attendu {debut}")
    obtenu = depuis_auto(base([]))
    verdict(obtenu == debut, f"index vide → {obtenu}, attendu {debut}")

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ la reprise ne dépend que des juridictions moissonnées")
    return 0


if __name__ == "__main__":
    sys.exit(main())
