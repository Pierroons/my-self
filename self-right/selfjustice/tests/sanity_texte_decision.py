#!/usr/bin/env python3
"""Garde-fou — ce que `texte_decision` rend d'une décision, selon son fonds.

🔑 **Deux fonds, deux formes de réponse.** L'API sert l'ordre judiciaire tel
que Judilibre le rend (`text`, `chamber`, `source` = le fonds amont) et
l'ordre administratif depuis son propre index JADE (`texte`, `formation`,
`source` = un libellé déjà rédigé). Mesuré le 27/09/2026 sur le MCP 0.4.4 :
`verifier_jurisprudence` trouvait la décision 519395 du Conseil d'État et
renvoyait vers `texte_decision`, qui rendait « (texte non fourni par la
source) » et l'attribuait à Judilibre — alors que l'API servait le texte
entier. Toute décision administrative aboutissait à cette impasse.

Les réponses de l'API sont simulées : ce test éprouve la lecture qu'en fait
le serveur, pas le réseau. La forme JADE est celle rendue par la production
le 27/09/2026, texte raccourci.

    python3 tests/sanity_texte_decision.py
"""

import ast
import asyncio
import os
import pathlib
import re
import sys
import urllib.parse

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"

VOULUES = {
    "texte_decision", "_nom_juridiction", "_nom_chambre", "_sans_annexes",
    "_msg_juris_morte", "ApiIndisponible", "RequeteInvalide",
    "JURIDICTIONS", "COURS_APPEL", "CHAMBRES", "FONDS_JUDILIBRE",
    "PLAFOND_TEXTE", "_ADMINISTRATIVES", "MARQUEUR_ANNEXES",
}

JADE = {
    "id": "CETATEXT000054824598",
    "number": "519395",
    "decision_date": "2026-09-09",
    "jurisdiction": "ce",
    "location": "",
    "formation": "Juge des référés",
    "publication": "C",
    "solution": "",
    "type": "Excès de pouvoir",
    "texte": "Vu la procédure suivante :\n\n Par une requête, enregistrée le "
             "2 septembre 2026 au secrétariat du contentieux du Conseil d'État…",
    "date_suspecte": 0,
    "juridiction_libelle": "Conseil d'État",
    "source": "JADE (DILA) — jurisprudence administrative",
}

JUDILIBRE = {
    "id": "0123456789abcdef01234567",
    "number": "21-12.345",
    "decision_date": "2023-01-05",
    "jurisdiction": "cc",
    "chamber": "soc",
    "ecli": "ECLI:FR:CCASS:2023:SO00001",
    "text": "LA COUR DE CASSATION, CHAMBRE SOCIALE, a rendu l'arrêt suivant…",
    "source": "dila",
}


def charger() -> dict:
    arbre = ast.parse(SERVEUR.read_text())
    gardes, trouves = [], set()
    for noeud in arbre.body:
        nom = None
        if isinstance(noeud, (ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
            nom = noeud.name
        elif isinstance(noeud, ast.Assign) and isinstance(noeud.targets[0], ast.Name):
            nom = noeud.targets[0].id
        if nom in VOULUES:
            # Le décorateur d'outil demande l'objet serveur, absent ici : la
            # fonction se charge nue.
            if isinstance(noeud, ast.AsyncFunctionDef):
                noeud.decorator_list = []
            gardes.append(noeud)
            trouves.add(nom)
    if trouves != VOULUES:
        print(f"✗ manquant dans server.py : {sorted(VOULUES - trouves)}", file=sys.stderr)
        raise SystemExit(2)

    espace: dict = {"os": os, "re": re, "urllib": urllib, "Any": object}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SERVEUR), "exec"), espace)

    async def bandeau(_base):
        return "[bandeau]"
    espace["_bandeau"] = bandeau
    return espace


def rendre(espace: dict, reponse: dict, identifiant: str) -> str:
    async def get(_chemin, *_a, **_k):
        return reponse
    espace["_get"] = get
    return asyncio.run(espace["texte_decision"](identifiant))


def main() -> int:
    espace = charger()
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    def controle(sortie: str, exiges: list, interdits: list, libelle: str) -> None:
        manquants = [m for m in exiges if m not in sortie]
        indus = [m for m in interdits if m in sortie]
        verdict(
            not manquants and not indus,
            libelle
            + (f" — manque {manquants}" if manquants else "")
            + (f" — contient à tort {indus}" if indus else ""),
        )

    print("▸ Une décision administrative, servie par l'index JADE")
    sortie = rendre(espace, JADE, JADE["id"])
    controle(sortie, ["Vu la procédure suivante"], ["texte non fourni"],
             "le texte servi par l'API est rendu")
    controle(sortie, ["JADE (DILA)"], ["Judilibre"],
             "la provenance est celle que l'API déclare, jamais Judilibre")
    controle(sortie, ["519395 — Conseil d'État, Juge des référés, 2026-09-09"], [],
             "l'en-tête nomme la juridiction et la formation")

    suspecte = dict(JADE, date_suspecte=1,
                    reserve="La date de cette décision est hors des bornes plausibles.")
    controle(rendre(espace, suspecte, JADE["id"]),
             ["hors des bornes plausibles"], [],
             "la réserve d'une date aberrante est relayée")

    caa = dict(JADE, jurisdiction="caa", location="Paris", formation="1ère chambre")
    controle(rendre(espace, caa, JADE["id"]),
             ["Cour administrative d'appel de Paris, 1ère chambre"], ["caa"],
             "une cour administrative d'appel est nommée avec sa ville")

    long = dict(JADE, texte="x" * (espace["PLAFOND_TEXTE"] * 2))
    controle(rendre(espace, long, JADE["id"]),
             ["EXTRAIT", "legifrance.gouv.fr"], ["courdecassation.fr"],
             "un texte coupé renvoie vers la source qui publie le fonds")

    print("\n▸ Une décision judiciaire, servie par Judilibre")
    sortie = rendre(espace, JUDILIBRE, JUDILIBRE["id"])
    controle(sortie, ["LA COUR DE CASSATION", "Judilibre", "diffusion DILA"],
             ["texte non fourni", "JADE"],
             "le texte et la provenance Judilibre restent inchangés")
    controle(sortie, ["21-12.345 — Cour de cassation, chambre sociale, 2023-01-05"], [],
             "l'en-tête judiciaire reste inchangé")

    print("\n▸ L'API injoignable")
    verdict("legifrance.gouv.fr" in espace["_msg_juris_morte"]("panne simulée"),
            "le repli nomme aussi la source de la justice administrative")

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
