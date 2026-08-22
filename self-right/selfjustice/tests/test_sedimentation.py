#!/usr/bin/env python3
"""Garde-fou — une fenêtre de moisson récente ne se déclare jamais complète.

🔑 Judilibre publie une décision de cour d'appel douze jours après qu'elle a été
rendue, en médiane, et jusqu'à soixante-douze. Moissonner une fenêtre qui se
termine aujourd'hui, puis l'inscrire comme faite, grave donc un trou définitif.

C'est arrivé le 15/08/2026 : la fenêtre 2026-06-30 → 2026-08-15 a été demandée,
7 160 décisions reçues — la plus récente datant du 5 août — et la fenêtre close.
L'index s'est arrêté au 5 août, et rien ne l'aurait jamais rattrapé : la reprise
saute les tranches déjà faites. Le module servait donc « cours d'appel jusqu'au
5 août » pendant que la base amont publiait des arrêts du 12.

Ce banc existe pour que le défaut ne dépende plus de quelqu'un qui ouvre un
journal.

Les fonctions sont chargées par AST : le script complet importe l'API amont et
une clé que l'intégration continue n'a pas.

    python3 tests/test_sedimentation.py
"""

import ast
import pathlib
import sqlite3
import sys
from datetime import date, datetime, timedelta

SCRIPT = pathlib.Path(__file__).resolve().parent.parent / "tools" / "build_judilibre_index.py"
VOULUES = {"fenetre_definitive", "SEDIMENTATION"}


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
    espace: dict = {"date": date, "datetime": datetime}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SCRIPT), "exec"), espace)
    return espace


def main() -> int:
    espace = charger()
    definitive, seuil = espace["fenetre_definitive"], espace["SEDIMENTATION"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    print(f"▸ Le seuil de sédimentation ({seuil} jours)")
    # 🔑 Le seuil est mesuré, pas choisi : sur les 25 215 décisions de 2026,
    # 97 % étaient publiées sous 29 jours et 98,5 % sous 30. Un seuil de 15 jours
    # laisserait passer une décision sur quatre — c'est la tranche 15-29 qui pèse,
    # à elle seule 21 % des publications.
    verdict(seuil >= 30, f"couvre au moins le délai courant de publication (≥ 30 j)")

    print()
    print("▸ Ce qu'une fenêtre déclare d'elle-même")
    for jours, attendu in [(0, False), (1, False), (seuil - 1, False),
                           (seuil, True), (seuil + 300, True)]:
        borne = (date.today() - timedelta(days=jours)).isoformat()
        obtenu = definitive(borne)
        verdict(obtenu is attendu,
                f"fin il y a {jours:>3} jour(s) → "
                f"{'définitive' if obtenu else 'à refaire'}")

    print()
    print("▸ Une borne illisible ne se déclare pas complète")
    # Refaire une fenêtre coûte du temps ; la clore à tort coûte des décisions.
    for mauvaise in ["", "pas-une-date", "2026-13-45"]:
        verdict(definitive(mauvaise) is False, f"« {mauvaise or '(vide)'} » → à refaire")

    print()
    print("▸ La purge rouvre ce qui a été clos trop tôt")
    # 🔑 Sans elle, le correctif ne vaudrait que pour l'avenir : les fenêtres
    # déjà inscrites resteraient « faites », avec leur trou.
    db = sqlite3.connect(":memory:")
    db.execute("""CREATE TABLE intervalles_faits (
        jurisdiction TEXT, date_debut TEXT, date_fin TEXT, recus INTEGER, maj TEXT,
        PRIMARY KEY (jurisdiction, date_debut, date_fin))""")
    cas = [
        # (fin, jour de la clôture, doit être rouverte, libellé)
        ("2026-08-15", "2026-08-15", True,  "close le jour même de sa fin"),
        ("2026-06-01", "2026-06-20", True,  "close 19 jours après sa fin"),
        ("2020-01-01", "2026-08-15", False, "close des années après sa fin"),
    ]
    for i, (fin, maj, _, _) in enumerate(cas):
        db.execute("INSERT INTO intervalles_faits VALUES (?,?,?,?,?)",
                   ("ca", f"2000-01-0{i+1}", fin, 10, maj))
    db.commit()

    rouvertes = db.execute(
        "DELETE FROM intervalles_faits WHERE jurisdiction=? AND "
        "julianday(substr(maj,1,10)) - julianday(date_fin) < ?", ("ca", seuil)).rowcount
    db.commit()
    restantes = {r[0] for r in db.execute("SELECT date_fin FROM intervalles_faits")}

    for fin, _, doit_partir, libelle in cas:
        partie = fin not in restantes
        verdict(partie is doit_partir,
                f"{libelle} → {'rouverte' if partie else 'conservée'}")
    verdict(rouvertes == 2, f"{rouvertes} tranche(s) rouverte(s), attendu 2")

    print()
    print("▸ Les décisions sont validées même quand la fenêtre reste ouverte")
    # 🔑 Le contrôle qui manquait, et qui a coûté un moissonnage entier. Le
    # `commit` était le SEUL du chemin ; placé sous la condition de
    # sédimentation, il faisait perdre toutes les décisions d'une fenêtre
    # récente — exactement celles que le correctif devait rendre accessibles.
    # Mesuré en production le 22/08/2026 : 7 753 reçues, aucune écrite, et le
    # journal annonçant fièrement ses 7 753.
    #
    # Le banc d'origine éprouvait la DÉCISION de clore ; il ne disait rien de ce
    # qu'il advenait des données. Une sonde qui vérifie qu'on a bien choisi ne
    # vérifie pas qu'on a bien fait.
    source = SCRIPT.read_text()
    bloc = source[source.index("    recus, lot = 0, 0"):source.index("def moissonner(")]
    commits = [l for l in bloc.splitlines() if "conn.commit()" in l]
    verdict(bool(commits), "un commit existe après la boucle de moisson")
    # Le commit des décisions doit être au premier niveau d'indentation de la
    # fonction, pas imbriqué sous le test de sédimentation.
    verdict(any(l.startswith("    conn.commit()") for l in commits),
            "il est inconditionnel — pas imbriqué sous fenetre_definitive")
    sous_condition = [l for l in commits if l.startswith("        ")]
    verdict(len(sous_condition) <= 1,
            f"{len(sous_condition)} commit(s) sous condition — un seul est licite "
            f"(la fenêtre vide, qui n'a rien reçu)")

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
