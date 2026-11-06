#!/usr/bin/env python3
"""Garde-fou — sur quelle date le serveur MCP juge qu'une base est en retard.

🔑 **Ne jamais comparer la date du CONTENU au calendrier.** Elle est fixée par
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

🔑 **Le contenu se confronte à la synchronisation.** Une synchronisation qui
tourne à l'heure et ne rapporte plus rien tient la cadence : c'est la panne des
treize mois, un droit arrêté au 13 juillet 2025 annoncé « cadence tenue » à
chaque échéance. L'écart entre les deux dates la dit, et sa frontière se fixe
ici des deux côtés.

Les outils se rejouent sur un `/status` et une API simulés : rien ne part sur
le réseau. Ce qui s'éprouve est la lecture qu'en fait le serveur — le bandeau
qui ne se tait jamais, `statut` devant un bloc nul, la provenance de chaque
texte européen, l'adresse où renvoyer et le titre de l'alerte.

Le calcul dépend du jour où il tourne : la date est gelée ici, sans quoi le
test dirait autre chose chaque quinzaine et finirait par ne plus rien dire.

    python3 tests/sanity_fraicheur_bases.py
"""

import ast
import asyncio
import datetime
import pathlib
import re
import sys
import unicodedata
import urllib.parse

SERVEUR = pathlib.Path(__file__).resolve().parent.parent / "mcp" / "selfright_mcp" / "server.py"
API = pathlib.Path(__file__).resolve().parent.parent / "api" / "api.php"

VOULUES = {
    "_etat_fraicheur", "_derniere_echeance", "_parse_date_fr", "_format_fr",
    # La table des noms de juridiction sert à dire les bornes de couverture :
    # sans elle, le chargement rend un NameError au premier cas qui en porte.
    "_NOM_JURIDICTION_COURT", "_PERIMETRE", "MOIS", "DATE_ISO",
    "_perimetre", "_ADMINISTRATIVES", "_PERIMETRE_JURIS_ADMINISTRATIF",
    "_SILENCE_MAX_JOURS", "_CONTENU_FIGE_JOURS",
    # Les outils rejoués sur un /status simulé, et ce qu'ils appellent.
    "_bandeau", "_provenance_eu", "_titre_alerte", "_SOURCE_OFFICIELLE",
    "statut", "article_europeen",
    "rechercher_conventionnalite", "ApiIndisponible", "RequeteInvalide",
    "_ou", "_msg_api_morte", "_msg_argument_refuse", "SOURCES_UE",
}

# Un vendredi ordinaire, postérieur à l'échéance du 15 : c'est la configuration
# où le retard doit se dire, donc celle qui vaut d'être figée. C'est aussi le
# jour où la fausse alerte a été mesurée.
AUJOURDHUI = datetime.date(2026, 8, 21)

# Deux blocs sains, tels que `/status` les rend au lendemain de l'échéance.
LEGI = {"last_update": "14 août 2026", "last_sync": "2026-08-15",
        "articles": 1000, "vigueur": 800}
EU = {"last_update": "15 août 2026", "last_sync": "2026-08-15",
      "articles": 300, "sources": {"CEDH": 100, "RGPD": 200}}


class DateGelee(datetime.date):
    @classmethod
    def today(cls):
        return AUJOURDHUI


def charger() -> tuple[dict, list]:
    """Rend l'espace chargé et les noms que `server.py` ne porte pas.

    ⚠️ Un nom absent ne coupe pas le banc : chaque cas qui en dépend échoue
    sous son propre libellé, et la sortie dit lequel a cédé. Le code de sortie
    reste 2.
    """
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

    faux_dt = type(sys)("datetime")
    faux_dt.date = DateGelee
    faux_dt.timedelta = datetime.timedelta
    espace: dict = {"dt": faux_dt, "re": re, "unicodedata": unicodedata,
                    "urllib": urllib, "Any": object, "API_URL": "", "ACT_URL": ""}
    exec(compile(ast.Module(body=gardes, type_ignores=[]), str(SERVEUR), "exec"), espace)
    return espace, sorted(VOULUES - trouves)


def simuler(espace: dict, statut, reponses: dict | None = None) -> list:
    """Branche un `/status` simulé — un dict, ou l'exception qu'il lève — et
    une API qui répond par préfixe de chemin. Un chemin non prévu est une API
    injoignable : le catalogue de `statut` passe par là. Rend la liste où
    s'enregistrent les alertes envoyées, (titre, message, clé)."""
    alertes: list = []

    async def statut_cache():
        if isinstance(statut, BaseException):
            raise statut
        return statut

    async def get(chemin, params=None, base=None):
        for prefixe, corps in (reponses or {}).items():
            if chemin.startswith(prefixe):
                return corps
        raise espace["ApiIndisponible"](f"{chemin} non simulé")

    async def alerter(titre, message, cle):
        alertes.append((titre, message, cle))

    espace.update(_statut_cache_court=statut_cache, _get=get, _alerter=alerter)
    return alertes


def rendre(appel) -> str:
    """Ce que l'outil rend au modèle. Une exception se lit comme une réponse :
    un outil qui lève n'a rien rendu, et c'est ce que le cas doit voir."""
    try:
        return asyncio.run(appel)
    except Exception as e:
        return f"‼ lève {type(e).__name__} : {e}"


def main() -> int:
    serveur, absents_serveur = charger()
    if absents_serveur:
        print(f"✗ manquant dans server.py : {absents_serveur}", file=sys.stderr)
    etat = serveur["_etat_fraicheur"]
    echecs = 0

    def verdict(ok: bool, libelle: str) -> None:
        nonlocal echecs
        if not ok:
            echecs += 1
        print(f"  {'✓' if ok else '✗'} {libelle}")

    def juger(bloc, retard_attendu, exiges, interdits, libelle, base="LEGI") -> None:
        message, retard = etat(bloc, base)
        manquants = [m for m in exiges if m not in message]
        indus = [m for m in interdits if m in message]
        verdict(
            retard is retard_attendu and not manquants and not indus,
            libelle
            + ("" if retard is retard_attendu else f" — retard={retard}, attendu {retard_attendu}")
            + (f" — manque {manquants}" if manquants else "")
            + (f" — contient à tort {indus}" if indus else ""),
        )

    def controle(sortie: str, exiges: list, interdits: list, libelle: str) -> None:
        if sortie.startswith("‼"):
            verdict(False, f"{libelle} — {sortie[2:]}")
            return
        manquants = [m for m in exiges if m not in sortie]
        indus = [m for m in interdits if m in sortie]
        verdict(
            not manquants and not indus,
            libelle
            + (f" — manque {manquants}" if manquants else "")
            + (f" — contient à tort {indus}" if indus else ""),
        )

    # (bloc, retard attendu, fragments exigés, fragments interdits, libellé)
    cas = [
        # 🔑 Le cas de la fausse alerte : la synchronisation du 15 a tourné, le
        # contenu porte la date du dernier diff de l'amont, antérieure.
        # « à jour » sous sa forme affirmative est interdit ici, comme plus bas :
        # le lecteur le porterait sur les données, quand la ligne ne certifie
        # que la cadence.
        ({"last_update": "14 août 2026", "last_sync": "2026-08-15"},
         False, ["synchronisée le 15 août 2026", "cadence tenue",
                 "contenu : 14 août 2026 (7 jours)"],
         ["RETARD", "⚠️", "synchronisation à jour", "à jour."],
         "synchro à l'échéance, contenu d'amont antérieur → cadence dite, contenu daté"),

        ({"last_update": "14 août 2026", "last_sync": "2026-08-21"},
         False, ["cadence tenue"], ["RETARD", "synchronisation à jour"],
         "synchro du jour même → cadence dite, aucune promesse sur le contenu"),

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

        # 🔑 La panne des treize mois : le cron passait à chaque échéance sur un
        # droit arrêté au 13 juillet 2025. La cadence est tenue, le contenu ne
        # bouge plus — ce n'est pas un retard, c'est une synchronisation qui ne
        # rapporte rien, et elle se dit comme telle.
        ({"last_update": "13 juillet 2025", "last_sync": "2026-08-21"},
         True, ["⚠️ CONTENU FIGÉ", "synchronisée le 21 août 2026",
                "date du 13 juillet 2025, 404 jours plus tôt",
                "Contenu servi : 13 juillet 2025 (404 jours)", "legifrance.gouv.fr"],
         ["cadence tenue", "RETARD"],
         "synchro du jour, contenu de treize mois → contenu figé, pas un retard"),

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

    # 🔑 Un fonds qui ne reçoit plus rien borne la couverture, il ne la vieillit
    # pas. Mesuré le 27/09/2026 : les tribunaux administratifs s'arrêtent en
    # 2009 dans JADE, la Cour de discipline budgétaire en 2000, et chaque
    # réponse annonçait « (9657 jours) » — une base à jour lue comme vieille de
    # vingt-six ans. Couverture de ce jour-là, décalée sur la date gelée.
    mesuree = {
        "last_update": "2026-08-15", "last_sync": "2026-08-15",
        "couverture": {
            "ca": {"fin": "2026-08-10"}, "caa": {"fin": "2026-08-09"},
            "cc": {"fin": "2026-08-16"}, "cdbf": {"fin": "2000-04-19"},
            "ce": {"fin": "2026-08-09"}, "ta": {"fin": "2009-12-17"},
            "tc": {"fin": "2026-07-06"},
        },
    }
    ancien = (AUJOURDHUI - datetime.date(2000, 4, 19)).days
    cas.append((
        mesuree, False,
        ["Conseil d'État jusqu'au 9 août 2026",
         "cours administratives d'appel jusqu'au 9 août 2026",
         # Le moins prolifique des fonds vivants fixe l'âge : c'est lui qui
         # limite ce qu'on peut affirmer absent.
         "Tribunal des conflits jusqu'au 6 juillet 2026 (46 jours)",
         "hors du compte des jours",
         "tribunaux administratifs jusqu'au 17 décembre 2009",
         "Cour de discipline budgétaire et financière jusqu'au 19 avril 2000"],
        [f"({ancien} jours)", "caa jusqu'au", "ce jusqu'au", "cdbf"],
        "fonds arrêtés → nommés à part, hors de l'âge ; juridictions nommées",
    ))

    # 🔑 Le contenu figé se juge sur le FRONT de la couverture : ni sur le
    # marqueur de passage, qui avance à chaque moisson même vide, ni sur le
    # fonds le plus lent. Un seul fonds vivant à jour dit que la moisson
    # rapporte ; quand tous restent en arrière, le marqueur du jour ne couvre
    # rien.
    cas.append((
        {"last_update": "2026-08-15", "last_sync": "2026-08-15",
         "couverture": {"cc": {"fin": "2026-08-14"}, "tc": {"fin": "2026-06-01"}}},
        False, ["cadence tenue", "Tribunal des conflits jusqu'au 1 juin 2026 (81 jours)"],
        ["FIGÉ"],
        "un fonds vivant récent, un autre lent → la moisson rapporte, rien de figé",
    ))
    cas.append((
        {"last_update": "2026-08-15", "last_sync": "2026-08-15",
         "couverture": {"cc": {"fin": "2026-06-30"}, "ca": {"fin": "2026-06-20"}}},
        True, ["⚠️ CONTENU FIGÉ", "date du 30 juin 2026, 46 jours plus tôt",
               "Cour de cassation jusqu'au 30 juin 2026"],
        ["cadence tenue"],
        "marqueur du jour, tous les fonds arrêtés 46 jours plus tôt → figé",
    ))

    # La frontière du silence, des deux côtés : lue dans le serveur, pour que ce
    # cas suive la valeur au lieu de la recopier.
    seuil = serveur["_SILENCE_MAX_JOURS"]
    front = datetime.date(2026, 8, 20)
    for ecart, arrete in ((seuil, False), (seuil + 1, True)):
        fin = front - datetime.timedelta(days=ecart)
        bloc = {"last_update": "2026-08-15", "last_sync": "2026-08-15",
                "couverture": {"cc": {"fin": front.isoformat()},
                               "tc": {"fin": fin.isoformat()}}}
        age = (AUJOURDHUI - fin).days
        cas.append((
            bloc, False,
            ["hors du compte des jours", "(1 jour)"] if arrete else [f"({age} jours)"],
            [] if arrete else ["hors du compte des jours"],
            f"silence de {ecart} jours → {'arrêté' if arrete else 'vivant'}",
        ))

    print("▸ Ce que le serveur annonce, selon les deux dates")
    for bloc, retard_attendu, exiges, interdits, libelle in cas:
        juger(bloc, retard_attendu, exiges, interdits, libelle)

    print("\n▸ La frontière du contenu figé")
    # Même règle que pour le silence : la valeur se lit dans le serveur. Un jour
    # d'écart est le cas courant — l'amont publie la veille de notre passage.
    fige_max = serveur.get("_CONTENU_FIGE_JOURS")
    if fige_max is None:
        verdict(False, "le serveur ne porte pas _CONTENU_FIGE_JOURS → frontière non éprouvée")
    else:
        synchro = datetime.date(2026, 8, 15)
        for ecart in (1, fige_max, fige_max + 1):
            fige = ecart > fige_max
            juger(
                {"last_update": (synchro - datetime.timedelta(days=ecart)).isoformat(),
                 "last_sync": synchro.isoformat()},
                fige,
                ["⚠️ CONTENU FIGÉ", f"{ecart} jours plus tôt"] if fige else ["cadence tenue"],
                ["cadence tenue"] if fige else ["FIGÉ"],
                f"contenu {ecart} jour{'s' if ecart > 1 else ''} avant la synchro → "
                f"{'figé' if fige else 'normal'}",
            )

    print("\n▸ Le seuil du silence, le même des deux côtés")
    # 🔑 L'API et le client trient les fonds arrêtés de la jurisprudence sur
    # deux constantes, que rien d'autre que ce contrôle ne lie : un seuil changé
    # d'un seul côté ferait dire « arrêté » à l'un et « vivant » à l'autre.
    m = re.search(r"const JURIS_SILENCE_MAX_JOURS\s*=\s*(\d+)", API.read_text(encoding="utf-8"))
    if not m:
        verdict(False, "`const JURIS_SILENCE_MAX_JOURS = …` introuvable dans api.php : "
                       "sa forme a changé, et ce contrôle doit la suivre au lieu de passer")
    else:
        verdict(int(m.group(1)) == seuil,
                f"api.php JURIS_SILENCE_MAX_JOURS ({m.group(1)}) = "
                f"_SILENCE_MAX_JOURS du serveur ({seuil})")

    print("\n▸ L'avertissement de périmètre")
    # ⚠️ Ce cas éprouve le CONTENU de la table, pas son branchement : le
    # raccordement à `_bandeau` demande le réseau et se vérifie en recette. Il
    # empêche au moins qu'on la vide sans s'en apercevoir — c'est son absence
    # sur la recherche qui a fait servir 37 159 arrêts civils à une question de
    # droit administratif, sans un mot.
    perimetre = serveur["_PERIMETRE"]
    texte = perimetre.get("jurisprudence", "")
    for attendu in ("JUDICIAIRE", "Conseil d'État", "tribunaux", "ArianeWeb"):
        verdict(attendu in texte, f"le périmètre nomme « {attendu} »")
    verdict(
        "jurisprudence" in perimetre,
        "la clé est celle du bloc de statut, pas un nom d'outil",
    )

    print("\n▸ Le périmètre suit la couverture que déclare /status")
    # 🔑 La réserve « la justice administrative n'est pas dans cette base » est
    # vraie tant que l'index ne sert que cc et ca, fausse dès qu'il sert le
    # Conseil d'État. Les deux branches s'éprouvent : une seule ne distingue pas
    # une réserve déduite d'une réserve écrite en dur.
    judiciaire = serveur["_perimetre"]("jurisprudence", {"couverture": {"cc": {}, "ca": {}}})
    verdict("ArianeWeb" in judiciaire,
            "index judiciaire seul → la réserve ArianeWeb est dite")
    elargi = serveur["_perimetre"]("jurisprudence",
                                  {"couverture": {"cc": {}, "ca": {}, "ce": {}}})
    verdict("ArianeWeb" not in elargi and "n'est pas dans cette base" not in elargi,
            "index élargi au Conseil d'État → la réserve disparaît")
    verdict("verifier_jurisprudence" in elargi,
            "index élargi → le périmètre dit comment atteindre le Conseil d'État")
    verdict(serveur["_perimetre"]("legi", {}) == perimetre["legi"],
            "les autres bases gardent leur périmètre")

    print("\n▸ Le bandeau ne se tait jamais")
    # 🔑 Vide, il laissait partir un article sans son âge et sans rien qui dise
    # que l'âge n'avait pas pu être lu : le modèle n'avait rien à répercuter.
    non_lu = ["l'état des bases n'a pas pu être lu", "n'est pas daté"]
    non_decrit = ["l'instance ne décrit pas cette base dans son état", "n'est pas daté"]
    for statut_simule, base, exiges, libelle in (
        (serveur["ApiIndisponible"]("délai de 15 s dépassé"), "LEGI",
         ["⚠️ Fraîcheur de la base LEGI non vérifiable", "(délai de 15 s dépassé)", *non_lu],
         "/status injoignable → non vérifiable, la cause citée"),
        (serveur["RequeteInvalide"]("HTTP 400"), "LEGI",
         ["⚠️ Fraîcheur de la base LEGI non vérifiable", "(HTTP 400)", *non_lu],
         "/status refusé → non vérifiable, sans exception"),
        # Une instance antérieure à l'index ne porte pas de bloc jurisprudence.
        ({"legi": LEGI, "eu": EU}, "jurisprudence",
         ["⚠️ Fraîcheur de la base jurisprudence non vérifiable", *non_decrit],
         "bloc absent → non vérifiable, dit"),
        ({"legi": None, "eu": EU}, "LEGI",
         ["⚠️ Fraîcheur de la base LEGI non vérifiable", *non_decrit],
         "bloc à null → non vérifiable, dit"),
    ):
        simuler(serveur, statut_simule)
        controle(rendre(serveur["_bandeau"](base)), exiges, [], libelle)

    print("\n▸ `statut` devant un bloc à null")
    # 🔑 L'API rend `null` pour une base qui manque ou ne se lit pas, et
    # `get(cle, {})` ne prend son défaut que si la clé MANQUE : l'outil qu'on
    # appelle avant toute consultation levait, et le modèle n'avait aucun état.
    for statut_simule, exiges, libelle in (
        ({"legi": None, "eu": EU, "jurisprudence": None},
         ["Base LEGI absente ou illisible sur cette instance",
          "Base conventionnalité synchronisée le 15 août 2026",
          "Index de jurisprudence absent"],
         "legi à null → LEGI dite absente ou illisible, les autres bases dites"),
        ({"legi": LEGI, "eu": None},
         ["Base conventionnalité absente ou illisible sur cette instance",
          "Base LEGI synchronisée le 15 août 2026"],
         "eu à null → conventionnalité dite absente ou illisible, LEGI dite"),
    ):
        simuler(serveur, statut_simule)
        controle(rendre(serveur["statut"]()), exiges,
                 ["API SelfJustice injoignable", "None"], libelle)

    print("\n▸ Où renvoyer quand la base ne suffit plus")
    # 🔑 Une seule adresse pour toutes les bases renvoyait vers Légifrance pour
    # la CEDH. Un texte européen se vérifie sur EUR-Lex, ou sur HUDOC.
    fige_legi = {"last_update": "13 juillet 2025", "last_sync": "2026-08-15"}
    en_retard = {"last_update": "14 août 2026", "last_sync": "2026-08-01"}
    juger(fige_legi, True, ["⚠️ CONTENU FIGÉ", "eur-lex.europa.eu", "hudoc.echr.coe.int"],
          ["legifrance"], "conventionnalité figée → EUR-Lex et HUDOC, pas Légifrance",
          base="conventionnalité")
    juger(en_retard, True, ["⚠️ RETARD", "eur-lex.europa.eu"], ["legifrance"],
          "conventionnalité en retard → EUR-Lex, pas Légifrance", base="conventionnalité")

    print("\n▸ Ce que l'exploitant reçoit")
    # 🔑 Un contenu figé n'est pas un retard : son cron tourne, et une alerte
    # « en retard » envoie chercher pourquoi il ne tourne pas.
    for statut_simule, appel, attendu, libelle in (
        ({"legi": fige_legi, "eu": EU}, lambda: serveur["_bandeau"]("LEGI"),
         ["SelfJustice — base LEGI : contenu figé"], "bandeau, contenu figé → « contenu figé »"),
        ({"legi": en_retard, "eu": EU}, lambda: serveur["_bandeau"]("LEGI"),
         ["SelfJustice — base LEGI : retard"], "bandeau, synchro manquée → « retard »"),
        ({"legi": LEGI, "eu": dict(EU, **fige_legi)}, lambda: serveur["statut"](),
         ["SelfJustice — base conventionnalité : contenu figé"], "statut, conventionnalité figée → « contenu figé »"),
    ):
        alertes = simuler(serveur, statut_simule)
        sortie = rendre(appel())
        titres = [titre for titre, _message, _cle in alertes]
        verdict(titres == attendu,
                libelle + ("" if titres == attendu else f" — reçu {titres}")
                + (f" — {sortie[2:]}" if sortie.startswith("‼") else ""))

    print("\n▸ La provenance de chaque texte européen se dit")
    # 🔑 La date de contenu de la base UE est celle de sa dernière construction
    # réussie : un texte servi d'une copie déposée à la main, ou d'une source
    # qu'on ne reconstruit plus, s'y lit comme frais. `/status` dit d'où vient
    # chaque texte, sous la forme que rend l'API. Le retard d'une source se
    # mesure contre `last_sync` (15 août), au seuil du serveur et un jour
    # au-delà ; sans ce seuil, la frontière est déjà comptée en échec plus haut.
    seuil_source = fige_max if fige_max is not None else 31
    synchro_ue = datetime.date(2026, 8, 15)
    provenance = {
        "AI_ACT": {"origine": "copie_locale", "fichier": "AI_ACT.html",
                   "depose_le": "2026-06-10"},
        "CEDH": {"origine": "copie_locale", "fichier": "CEDH.pdf",
                 "depose_le": "2026-03-02"},
        "RGPD": {"origine": "reseau", "construite_le": synchro_ue.isoformat()},
        "TFUE": {"origine": "reseau", "construite_le": (
            synchro_ue - datetime.timedelta(days=seuil_source + 1)).isoformat()},
        "TUE": {"origine": "reseau", "construite_le": (
            synchro_ue - datetime.timedelta(days=seuil_source)).isoformat()},
    }
    derniere = serveur["_format_fr"](synchro_ue - datetime.timedelta(days=seuil_source + 1))
    arretee = f"TFUE (reconstruite pour la dernière fois le {derniere})"
    reponses_ue = {
        "/eu/article/": {"titre": "Article simulé", "texte": "Texte simulé."},
        "/eu/search": {"results": [{"source": "CEDH", "reference": "8"}],
                       "count": 1, "total": 1},
    }
    simuler(serveur, {"legi": LEGI, "eu": dict(EU, provenance=provenance)}, reponses_ue)
    controle(rendre(serveur["article_europeen"]("CEDH", "8")),
             ["Base conventionnalité synchronisée le 15 août 2026", "CEDH — article 8",
              "copie déposée à la main",
              "CEDH (copie déposée le 2 mars 2026, il y a 172 jours)"],
             ["AI_ACT", "TFUE", "2026-03-02", "non vérifiable"],
             "article servi d'une copie → elle seule, sa date en toutes lettres et son âge")
    controle(rendre(serveur["rechercher_conventionnalite"]("vie privée", "cedh")),
             ["CEDH (copie déposée le 2 mars 2026"], ["AI_ACT (copie", "TFUE"],
             "recherche bornée à une source copiée → cette copie seule")
    controle(rendre(serveur["rechercher_conventionnalite"]("transparence")),
             ["AI_ACT (copie déposée le 10 juin 2026, il y a 72 jours)",
              "CEDH (copie déposée le 2 mars 2026, il y a 172 jours)"],
             ["RGPD (", "2026-06-10", "2026-03-02"],
             "toutes les sources → chaque copie nommée en toutes lettres, la source saine tue")
    controle(rendre(serveur["article_europeen"]("TFUE", "16")),
             ["⚠️ Source dont la reconstruction échoue depuis plus d'un mois : " + arretee,
              "eur-lex.europa.eu"],
             ["legifrance", "copie déposée"],
             f"source réseau reconstruite {seuil_source + 1} jours avant la synchro → l'avertissement")
    controle(rendre(serveur["article_europeen"]("TUE", "6")),
             ["Base conventionnalité synchronisée le 15 août 2026", "TUE — article 6"],
             ["reconstruction échoue", "copie déposée"],
             f"même source à {seuil_source} jours → rien")
    controle(rendre(serveur["statut"]()),
             ["Conventionnalité : 300 articles",
              "CEDH (copie déposée le 2 mars 2026, il y a 172 jours)", arretee],
             ["2026-03-02", "None"],
             "statut → la copie et la source arrêtée nommées avec la conventionnalité")
    # L'âge d'une copie s'accorde, et une date de dépôt à venir n'en a pas : « il
    # y a 1 jours », « il y a 0 jours » et « il y a -3 jours » sortaient tels quels.
    veille = (AUJOURDHUI - datetime.timedelta(days=1)).isoformat()
    a_venir = (AUJOURDHUI + datetime.timedelta(days=3)).isoformat()
    simuler(serveur, {"legi": LEGI, "eu": dict(EU, provenance={
        "CEDH": {"origine": "copie_locale", "depose_le": veille},
        "RGPD": {"origine": "copie_locale", "depose_le": AUJOURDHUI.isoformat()},
        "TUE": {"origine": "copie_locale", "depose_le": a_venir}})}, reponses_ue)
    controle(rendre(serveur["rechercher_conventionnalite"]("transparence")),
             ["il y a 1 jour)", "RGPD (copie déposée le 21 août 2026, aujourd'hui)",
              "TUE (copie déposée le 24 août 2026)"],
             ["1 jours", "0 jour", "il y a -"],
             "l'âge d'une copie s'accorde, et une date à venir n'en porte pas")

    # ⚠️ `or {}` ne protège que du null : une entrée qui n'est pas un objet
    # levait, et l'outil entier tombait pour une source.
    simuler(serveur, {"legi": LEGI, "eu": dict(EU, provenance={
        "CEDH": provenance["CEDH"], "CHARTE_UE": "reseau"})}, reponses_ue)
    controle(rendre(serveur["rechercher_conventionnalite"]("transparence")),
             ["CEDH (copie déposée le 2 mars 2026"], ["CHARTE_UE ("],
             "entrée de provenance qui n'est pas un objet → ignorée, les autres dites")

    print()
    if absents_serveur:
        print(f"✗ {len(absents_serveur)} nom(s) absent(s) de server.py, "
              f"{echecs} contrôle(s) en échec")
        return 2
    if echecs:
        print(f"✗ {echecs} contrôle(s) en échec")
        return 1
    print("✓ tous les contrôles passent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
