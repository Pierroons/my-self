#!/usr/bin/env python3
"""Garde-fou — sur quelle date le serveur MCP juge qu'une base est en retard.

🔑 **Ne jamais juger un retard sur la date du CONTENU.** Elle est fixée par
l'amont, pas par nous : celle de LEGI est la date du dernier diff publié par la
DILA, qui précède forcément notre passage et n'avance plus jusqu'au suivant. La
comparer au calendrier de nos synchronisations la condamne à ne jamais
l'atteindre.

Mesuré le 21/08/2026 sur le paquet installé : le serveur annonçait « RETARD —
base LEGI arrêtée au 14 août, l'échéance du 15 n'a pas été honorée » dans
CHAQUE réponse, alors que la synchronisation du 15 avait tourné et pris le
dernier diff disponible. Les deux autres bases passaient — par accident : leur
marqueur portait la date d'exécution, pas celle du contenu. Trois bases, deux
sémantiques, un seul nom de champ, et un client incapable de trancher.

L'instance expose donc `last_sync` à côté de `last_update`. Les cas ci-dessous
fixent ce qui se juge sur laquelle, et surtout ce qui doit rester SILENCIEUX :
un outil dont la raison d'être est de dater le droit ne peut pas crier au loup
sur une base à jour.

Le calcul dépend du jour où il tourne : la date est gelée ici, sans quoi le
test dirait autre chose chaque quinzaine et finirait par ne plus rien dire.

    python3 tests/sanity_fraicheur_bases.py
"""

import ast
import datetime
import pathlib
import re
import sys
import unicodedata

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"

VOULUES = {
    "_etat_fraicheur", "_derniere_echeance", "_parse_date_fr", "_format_fr",
    # La table des noms de juridiction sert à dire les bornes de couverture :
    # sans elle, le chargement rend un NameError au premier cas qui en porte.
    "_NOM_JURIDICTION_COURT", "_PERIMETRE", "MOIS", "DATE_ISO",
}

# Un vendredi ordinaire, postérieur à l'échéance du 15 : c'est la configuration
# où le retard doit se dire, donc celle qui vaut d'être figée. C'est aussi le
# jour où la fausse alerte a été mesurée.
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
    etat = charger()["_etat_fraicheur"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    # (bloc, retard attendu, fragments exigés, fragments interdits, libellé)
    cas = [
        # 🔑 Le cas de la fausse alerte : la synchronisation du 15 a tourné, le
        # contenu porte la date du dernier diff de l'amont, antérieure.
        ({"last_update": "14 août 2026", "last_sync": "2026-08-15"},
         False, ["synchronisée le 15 août 2026", "contenu : 14 août 2026 (7 jours)",
          "synchronisation à jour"],
         ["RETARD", "⚠️"],
         "synchro à l'échéance, contenu d'amont antérieur → silence"),

        ({"last_update": "14 août 2026", "last_sync": "2026-08-21"},
         False, ["synchronisation à jour"], ["RETARD"],
         "synchro du jour même → silence"),

        # Le cron n'a pas tourné le 15 : là, le retard est réel.
        ({"last_update": "14 août 2026", "last_sync": "2026-08-01"},
         True, ["⚠️ RETARD", "n'a pas tourné depuis le 1 août 2026",
                "l'échéance du 15 août 2026", "Contenu servi : 14 août 2026 (7 jours)",
                "legifrance.gouv.fr"],
         [],
         "synchro antérieure à l'échéance → retard, les deux dates nommées"),

        # ⚠️ Une instance muette sur sa synchronisation ne permet aucune
        # conclusion. Le dire vaut mieux que trancher au hasard.
        ({"last_update": "14 août 2026"},
         False, ["n'annonce pas la date de sa dernière synchronisation",
                 "impossible de dire si elle est à jour"],
         # ⚠️ « à jour » tout court se trouve aussi dans « impossible de dire si
         # elle est à jour » : c'est la forme AFFIRMATIVE qu'on interdit ici.
         ["RETARD", "synchronisation à jour"],
         "sans last_sync → incertitude énoncée, pas d'alarme"),

        ({"last_update": "14 août 2026", "last_sync": ""},
         False, ["n'annonce pas"], ["RETARD"],
         "last_sync vide → traité comme absent"),

        # Le contenu très ancien alors que la synchro vient de tourner : c'est
        # l'amont qui s'est tu. Le client le montre, l'exploitant est alerté de
        # son côté — ce n'est pas au client de crier sur ce qu'il ne sait pas.
        ({"last_update": "2 février 2026", "last_sync": "2026-08-15"},
         False, ["contenu : 2 février 2026 (200 jours)"], ["RETARD"],
         "amont silencieux, synchro à l'heure → le contenu se dit, sans alarme"),

        ({"last_update": "pas une date", "last_sync": "2026-08-15"},
         True, ["indéterminée", "pas une date"], [],
         "contenu illisible → signalé, valeur citée"),

        # La jurisprudence date en ISO, les autres bases en toutes lettres.
        ({"last_update": "2026-08-15", "last_sync": "2026-08-15"},
         False, ["contenu : 15 août 2026 (6 jours)"], ["RETARD"],
         "date ISO → lue et reformatée comme les autres"),
    ]

    # 🔑 Le cas du 21/08/2026 : la jurisprudence publie sa COUVERTURE, et son
    # `last_update` n'est qu'un marqueur de passage. Étiqueté « contenu daté
    # du », il annonçait le 15 août quand la Cour de cassation n'allait que
    # jusqu'au 30 juillet — faux de seize jours, sur chaque réponse de chaque
    # outil touchant cette base.
    couvert = {
        "last_update": "2026-08-15",
        "last_sync": "2026-08-15",
        "couverture": {
            "cc": {"debut": "1860-08-01", "fin": "2026-07-30"},
            "ca": {"debut": "1996-03-25", "fin": "2026-08-05"},
        },
    }
    cas.append((
        couvert, False,
        ["Cour de cassation jusqu'au 30 juillet 2026",
         "cours d'appel jusqu'au 5 août 2026", "(22 jours)"],
        ["contenu : 15 août 2026"],
        "une base qui publie sa couverture → ses bornes, pas son marqueur",
    ))
    # ⚠️ L'âge se compte sur la borne la PLUS ANCIENNE : c'est elle qui limite
    # ce qu'on peut affirmer absent.
    cas.append((
        {"last_update": "2026-08-15", "last_sync": "2026-08-15",
         "couverture": {"cc": {"fin": "2026-08-20"}}},
        False, ["Cour de cassation jusqu'au 20 août 2026", "(1 jour)"], [],
        "une seule juridiction couverte → une seule borne dite",
    ))

    print("▸ Ce que le serveur annonce, selon les deux dates")
    for bloc, retard_attendu, exiges, interdits, libelle in cas:
        message, retard = etat(bloc, "LEGI")
        manquants = [m for m in exiges if m not in message]
        indus = [m for m in interdits if m in message]
        verdict(
            retard is retard_attendu and not manquants and not indus,
            libelle
            + ("" if retard is retard_attendu else f" — retard={retard}, attendu {retard_attendu}")
            + (f" — manque {manquants}" if manquants else "")
            + (f" — contient à tort {indus}" if indus else ""),
        )

    print("\n▸ L'avertissement de périmètre")
    # ⚠️ Ce cas éprouve le CONTENU de la table, pas son branchement : le
    # raccordement à `_bandeau` demande le réseau et se vérifie en recette. Il
    # empêche au moins qu'on la vide sans s'en apercevoir — c'est son absence
    # sur la recherche qui a fait servir 37 159 arrêts civils à une question de
    # droit administratif, sans un mot.
    perimetre = charger()["_PERIMETRE"]
    texte = perimetre.get("jurisprudence", "")
    for attendu in ("JUDICIAIRE", "Conseil d'État", "tribunaux", "ArianeWeb"):
        verdict(attendu in texte, f"le périmètre nomme « {attendu} »")
    verdict(
        "jurisprudence" in perimetre,
        "la clé est celle du bloc de statut, pas un nom d'outil",
    )

    print()
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
