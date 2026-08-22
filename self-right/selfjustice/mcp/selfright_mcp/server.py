"""SelfJustice — serveur MCP de consultation juridique.

Expose la base SelfJustice (droit français LEGI + conventionnalité UE/CEDH)
à un modèle de langage, via le protocole MCP.

## Pourquoi ce serveur existe

Une IA à qui l'on pose une question de droit cite de mémoire. Elle donne un
numéro d'article plausible, un texte plausible, une date plausible — et se
trompe sans le signaler, parce que rien dans sa réponse ne distingue ce qu'elle
sait de ce qu'elle reconstitue.

Ce serveur remplace la mémoire par une lecture. Chaque article rendu vient d'une
requête à une base construite depuis les dumps officiels DILA, et porte la date
à laquelle cette base a été synchronisée.

## Le contrôle de fraîcheur

🔑 **Une base juridique périmée est plus dangereuse qu'une absence de base.**
Elle répond, elle a l'air juste, et elle sert du droit abrogé. C'est arrivé :
de juillet 2025 à août 2026, l'API a servi le droit au 13 juillet 2025 sans que
personne ne s'en aperçoive — la synchronisation tournait à l'heure, c'est son
résultat qui était mort.

Chaque réponse de chaque outil porte donc un état de fraîcheur, calculé contre
la cadence de synchronisation annoncée (1er et 15 du mois). Quand la base est en
retard, le texte le dit avant de donner l'article, pour que le modèle le
répercute à son utilisateur.

## Alerte ntfy — pourquoi elle est optionnelle

Ce serveur tourne chez l'utilisateur, et son code est public. Y inscrire un
jeton ntfy reviendrait à le publier. Les variables ci-dessous restent donc
vides par défaut : seul l'exploitant de l'instance les renseigne, dans son
environnement, pour être prévenu quand sa propre base décroche.

Configuration :
    SELFRIGHT_API_URL         racine de l'API à interroger — OBLIGATOIRE,
                                sans valeur par défaut. Sans elle, le serveur
                                démarre, annonce ses outils, et les refuse tous.

    Les suivantes sont facultatives :
    SELFRIGHT_NTFY_URL        topic ntfy à prévenir en cas de retard
    SELFRIGHT_NTFY_TOKEN      jeton du topic
    SELFRIGHT_TIMEOUT         délai réseau en secondes (défaut : 15)
    SELFRIGHT_PLAFOND_TEXTE   longueur au-delà de laquelle le texte d'une
                                décision est réduit (défaut : 20000)
"""

from __future__ import annotations

import datetime as dt
import logging
import os
import re
import time
import unicodedata
import urllib.parse
from typing import Any

import httpx
from mcp.server import MCPServer

from . import __version__

# httpx journalise chaque requête en INFO. Sur le transport stdio, stdout porte
# le protocole et stderr remonte dans les logs du client : une ligne par appel
# d'outil noie ce qui compte vraiment.
logging.getLogger("httpx").setLevel(logging.WARNING)

# Sans valeur par défaut, délibérément. Y inscrire une instance en dur ferait
# porter à son exploitant le trafic de toutes les installations du paquet, sans
# qu'il l'ait choisi ni qu'aucun de ces utilisateurs ne le sache. Chacun désigne
# l'instance qu'il interroge — la sienne, ou une qu'on lui a indiquée.
API_URL = os.environ.get("SELFRIGHT_API_URL", "").rstrip("/")

# Les routes SelfAct vivent sous `/act/api/`, à côté de l'API du droit et non
# dessous : la base se dérive donc de `SELFRIGHT_API_URL` en remplaçant son
# dernier segment. La dérivation reste surchargeable — une instance disposée
# autrement se déclare sans qu'on touche au code, et si rien ne permet de la
# construire, les outils SelfAct le disent au lieu d'interroger une adresse
# fausse.
ACT_URL = os.environ.get("SELFRIGHT_ACT_URL", "").rstrip("/") or (
    API_URL[: -len("/api")] + "/act/api" if API_URL.endswith("/api") else ""
)
NTFY_URL = os.environ.get("SELFRIGHT_NTFY_URL", "")
NTFY_TOKEN = os.environ.get("SELFRIGHT_NTFY_TOKEN", "")
TIMEOUT = float(os.environ.get("SELFRIGHT_TIMEOUT", "15"))

# Longueur au-delà de laquelle le texte d'une décision est coupé. Assez pour
# rendre la plupart des arrêts entiers, assez peu pour qu'aucun ne sature le
# client : un arrêt réel mesuré à 79 658 caractères dépassait la limite et se
# perdait dans un fichier annexe, laissant le modèle sans rien à citer.
PLAFOND_TEXTE = int(os.environ.get("SELFRIGHT_PLAFOND_TEXTE", "20000"))

SOURCES_UE = ("CEDH", "CHARTE_UE", "TUE", "TFUE", "RGPD", "AI_ACT")

MOIS = {
    "janvier": 1, "février": 2, "fevrier": 2, "mars": 3, "avril": 4,
    "mai": 5, "juin": 6, "juillet": 7, "août": 8, "aout": 8,
    "septembre": 9, "octobre": 10, "novembre": 11, "décembre": 12, "decembre": 12,
}

server = MCPServer(
    name="selfright",
    title="Self-Right — droit français, conventionnalité et démarches",
    version=__version__,
    instructions=(
        "Consulte le droit français (base LEGI, dumps officiels DILA), les textes "
        "de conventionnalité (CEDH, Charte UE, TUE, TFUE, RGPD, règlement IA), la "
        "jurisprudence judiciaire et les démarches administratives officielles. "
        "Ne cite jamais un article de mémoire : appelle `statut` puis l'outil de "
        "consultation qui convient, et reprends la date de synchronisation rendue "
        "dans ton avertissement à l'utilisateur. Si un outil signale un retard de "
        "base ou une API injoignable, dis-le avant de répondre au fond."
    ),
)


# ---------------------------------------------------------------- fraîcheur

def _parse_date_fr(texte: str) -> dt.date | None:
    """Convertit « 2 août 2026 » ou « 2026-08-02 » en date, sinon None.

    Les deux formes sont acceptées parce que l'API ne les sert pas toutes de
    la même façon : les bases LEGI et conventionnalité rendent une date écrite
    en toutes lettres, la jurisprudence une date ISO. Refuser l'une des deux
    reviendrait à annoncer une fraîcheur « indéterminée » sur une date pourtant
    parfaitement lisible — l'avertissement le plus grave du serveur, déclenché
    par une simple différence de présentation.
    """
    if not texte:
        return None

    m = re.match(r"\s*(\d{4})-(\d{2})-(\d{2})\s*$", texte)
    if m:
        try:
            return dt.date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
        except ValueError:
            return None

    m = re.match(r"\s*(\d{1,2})\s+([^\s]+)\s+(\d{4})\s*$", texte)
    if not m:
        return None
    mois = MOIS.get(m.group(2).lower())
    if not mois:
        return None
    try:
        return dt.date(int(m.group(3)), mois, int(m.group(1)))
    except ValueError:
        return None


def _derniere_echeance(aujourdhui: dt.date) -> dt.date:
    """Dernière échéance de synchronisation **exigible** (cadence 1er et 15).

    🔑 Le contrôle porte sur la date du **contenu**, jamais sur celle de la
    dernière exécution. Un cron ponctuel qui reconstruit une base identique
    passe tous les contrôles d'exécution en servant du droit périmé — c'est
    exactement ce qui s'est produit pendant treize mois.

    Le jour même d'une échéance, la synchronisation n'a pas encore tourné : à
    00h10 le 15, une base arrêtée au 1er est normale. Exiger l'échéance du jour
    déclenchait une alerte « retard de 14 jours » sur une base saine, deux fois
    par mois, ntfy compris — et une alerte qui crie pour rien finit ignorée le
    jour où elle a raison. On exige donc l'échéance précédente ce jour-là, ce
    qui reporte la détection à J+1 : très en deçà des treize mois de la panne
    fondatrice.
    """
    if aujourdhui.day > 15:
        return aujourdhui.replace(day=15)
    if aujourdhui.day > 1:
        return aujourdhui.replace(day=1)
    # Le 1er : l'échéance exigible est le 15 du mois précédent.
    return (aujourdhui - dt.timedelta(days=1)).replace(day=15)


# Les codes de juridiction tels que l'index les publie. Table close et courte —
# la même information écrite en toutes lettres ailleurs dans ce fichier vaut
# pour l'affichage d'une décision, pas pour une borne de couverture.
_NOM_JURIDICTION_COURT = {"cc": "Cour de cassation", "ca": "cours d'appel"}


def _etat_fraicheur(bloc: dict, base: str) -> tuple[str, bool]:
    """Rend (message lisible, retard constaté).

    🔑 **Ne jamais juger un retard sur la date du CONTENU.** Elle est fixée par
    l'amont, pas par nous : pour LEGI, c'est celle du dernier diff publié par la
    DILA, qui précède forcément notre passage et n'avance plus jusqu'au suivant.
    La comparer au calendrier de nos synchronisations la condamne à ne jamais
    l'atteindre.

    Mesuré le 21/08/2026 : ce serveur annonçait « RETARD — base LEGI arrêtée au
    14 août, l'échéance du 15 n'a pas été honorée » dans CHAQUE réponse, alors
    que la synchronisation du 15 avait tourné et pris le dernier diff
    disponible. Les deux autres bases passaient par accident — leur marqueur
    portait la date d'exécution, pas celle du contenu. Trois bases, deux
    sémantiques, un seul nom de champ.

    L'instance expose donc `last_sync` à côté de `last_update`. Le retard se
    juge sur la première — un cron mort, c'est une synchronisation qui n'a pas
    eu lieu — et le contenu se dit sur la seconde, pour que le lecteur voie
    jusqu'où va ce qu'on lui sert.

    Le bloc entier est passé plutôt que ses deux champs : deux valeurs qui n'ont
    de sens qu'ensemble ne se recopient pas chez chaque appelant.
    """
    date_contenu = _parse_date_fr(bloc.get("last_update", ""))
    date_synchro = _parse_date_fr(bloc.get("last_sync", ""))

    if date_contenu is None:
        return (
            f"Fraîcheur de la base {base} indéterminée : l'API annonce "
            f"« {bloc.get('last_update') or 'rien'} », qui ne se lit pas comme "
            "une date. Traite les articles rendus comme non datés et "
            "vérifie-les à la source.",
            True,
        )

    # La date affichée vient de la valeur parsée, jamais du texte reçu : l'API
    # écrit la jurisprudence en ISO et les deux autres bases en toutes lettres.
    # Recopier la chaîne telle quelle ferait cohabiter « 2 août 2026 » et
    # « 2026-08-09 » dans le même statut.
    contenu = _format_fr(date_contenu)

    # 🔑 **Le marqueur de passage n'est pas une date de contenu.** Pour LEGI,
    # `last_update` porte la date du dump DILA — donc bien du contenu. Pour la
    # jurisprudence, il porte la date où NOTRE moisson a tourné, et l'index
    # s'arrête ailleurs : mesuré le 21/08/2026, le marqueur disait le 15 août
    # quand la Cour de cassation n'allait que jusqu'au 30 juillet. Annoncer
    # « contenu daté du 15 août » était faux de seize jours, sur chaque réponse
    # de chaque outil touchant la jurisprudence.
    #
    # Quand la base publie sa couverture, c'est elle qui dit jusqu'où va le
    # contenu — et elle le dit par juridiction, ce qu'aucune date unique ne peut.
    couverture = bloc.get("couverture")
    if isinstance(couverture, dict) and couverture:
        bornes = {
            code: _parse_date_fr(str((b or {}).get("fin") or ""))
            for code, b in couverture.items()
        }
        bornes = {c: d for c, d in bornes.items() if d}
        if bornes:
            contenu = ", ".join(
                f"{_NOM_JURIDICTION_COURT.get(c, c)} jusqu'au {_format_fr(d)}"
                for c, d in sorted(bornes.items())
            )
            date_contenu = min(bornes.values())

    # ⚠️ Une instance qui n'annonce pas sa date de synchronisation ne permet pas
    # de distinguer un cron mort d'un amont silencieux. On le DIT, plutôt que de
    # trancher au hasard : une alerte permanente vaut moins qu'une incertitude
    # énoncée, et une absence d'alerte tromperait dans l'autre sens.
    aujourdhui = dt.date.today()
    # ⚠️ L'âge se dit, sans être jugé. « À jour » ne parle que de notre tuyau :
    # la synchronisation a eu lieu quand elle devait. Il ne dit rien de l'âge du
    # droit servi, et le taire laissait lire un verdict sur le droit là où il n'y
    # en a pas — le 31 août, une base arrêtée au 14 s'annonçait « à jour » sans
    # que rien ne signale les dix-sept jours. Le chiffre est un fait ; en faire
    # une alarme rouvrirait la fausse alerte qu'on vient de retirer.
    age = (aujourdhui - date_contenu).days
    vieillesse = f" ({age} jour{'s' if age > 1 else ''})" if age > 0 else ""

    if date_synchro is None:
        return (
            f"Base {base} — contenu : {contenu}{vieillesse}. Cette instance "
            "n'annonce pas la date de sa dernière synchronisation : impossible "
            "de dire si elle est à jour. Signale-le si la réponse a des "
            "conséquences.",
            False,
        )

    echeance = _derniere_echeance(aujourdhui)
    synchro = _format_fr(date_synchro)

    if date_synchro >= echeance:
        return (
            f"Base {base} synchronisée le {synchro} · contenu : "
            f"{contenu}{vieillesse} — synchronisation à jour.",
            False,
        )

    retard = (aujourdhui - date_synchro).days
    return (
        f"⚠️ RETARD — la synchronisation de la base {base} n'a pas tourné depuis "
        f"le {synchro}, soit {retard} jours, et l'échéance du "
        f"{_format_fr(echeance)} n'a pas été honorée. Contenu servi : "
        f"{contenu}{vieillesse} — les textes rendus peuvent être abrogés ou "
        "modifiés depuis. Signale-le à l'utilisateur et renvoie-le vers "
        "legifrance.gouv.fr avant tout usage ayant des conséquences (saisine, "
        "courrier, décision).",
        True,
    )


def _format_fr(d: dt.date) -> str:
    noms = [
        "janvier", "février", "mars", "avril", "mai", "juin",
        "juillet", "août", "septembre", "octobre", "novembre", "décembre",
    ]
    return f"{d.day} {noms[d.month - 1]} {d.year}"


_alerte_envoyee: set[str] = set()


async def _alerter(titre: str, message: str, cle: str) -> None:
    """Prévient l'exploitant de l'instance, si et seulement s'il l'a configuré.

    Une seule alerte par clé et par exécution du serveur : une session de
    travail peut enchaîner vingt consultations, elles décrivent le même retard.
    """
    if not NTFY_URL or not NTFY_TOKEN or cle in _alerte_envoyee:
        return
    _alerte_envoyee.add(cle)
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            await client.post(
                NTFY_URL,
                content=message.encode("utf-8"),
                headers={
                    "Authorization": f"Bearer {NTFY_TOKEN}",
                    "Title": titre,
                    "Priority": "high",
                    "Tags": "warning,scales",
                },
            )
    except Exception:
        # Une alerte qui échoue ne doit pas casser la consultation en cours.
        pass


# ------------------------------------------------------------------- réseau

class ApiIndisponible(Exception):
    """L'API n'a pas répondu — distinct d'un article introuvable."""


class RequeteInvalide(Exception):
    """L'API a répondu qu'elle refusait l'argument — distinct d'une panne.

    🔑 Un identifiant mal recopié se corrige en un appel. Le confondre avec une
    panne fait dire au modèle que la vérification est impossible et renvoie
    l'utilisateur vers Légifrance, alors que la réponse était à portée d'outil.
    """


async def _get(
    chemin: str,
    params: dict[str, Any] | None = None,
    base: str | None = None,
) -> Any:
    racine = API_URL if base is None else base
    if not racine:
        raise ApiIndisponible(
            "SELFRIGHT_API_URL n'est pas défini : ce serveur ne sait pas quelle "
            "instance interroger. Renseigne-la dans la configuration du client MCP. "
            "N'invente aucun texte de loi en attendant."
        )
    url = f"{racine}{chemin}"
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, follow_redirects=True) as client:
            r = await client.get(url, params=params)
    except Exception as e:
        raise ApiIndisponible(f"{type(e).__name__} : {e}") from e

    if r.status_code >= 400:
        # L'API répond en JSON, y compris pour dire « article introuvable ».
        # Un 404 qui rend autre chose (page HTML de nginx, proxy, portail
        # captif) signifie que l'API n'est pas là où on croit.
        try:
            corps = r.json()
        except ValueError:
            raise ApiIndisponible(
                f"HTTP {r.status_code}, réponse non-JSON (l'API n'est "
                f"probablement pas à l'adresse {API_URL})"
            ) from None

        message = str(corps.get("error", "")) if isinstance(corps, dict) else ""

        # 🔑 L'API joint souvent la liste des valeurs acceptées ; la jeter oblige
        # l'appelant à la deviner. Mesuré le 21/08/2026 : « type inconnu » se
        # relayait nu, et la seule valeur valide ne se découvrait qu'en lisant
        # une étiquette dans une réponse précédente. Relayer l'indication est le
        # seul correctif qui reste juste quand l'énumération évolue — corriger
        # la notice à la main la fait diverger au premier ajout.
        if isinstance(corps, dict) and corps.get("hint"):
            message = f"{message} — {corps['hint']}" if message else str(corps["hint"])

        # 400 : l'API a compris la demande et refuse l'argument. Ce n'est pas
        # une panne, et le dire comme tel envoie chercher ailleurs une réponse
        # qui tient dans une correction de frappe.
        if r.status_code == 400:
            raise RequeteInvalide(message or r.text[:200])

        # L'amont rend « etat: indeterminee » avec un 503 : c'est une donnée —
        # « je n'ai pas pu vérifier » — que l'appelant doit pouvoir distinguer
        # d'une API muette. La convertir en exception ici rendait inatteignables
        # les branches que les outils consacrent à ce cas.
        if isinstance(corps, dict) and corps.get("etat") == "indeterminee":
            return corps

        if r.status_code == 404 and message.startswith("Endpoint inconnu"):
            raise ApiIndisponible(
                f"l'API ne connaît pas cette route ({message}) — vérifier "
                f"SELFRIGHT_API_URL, actuellement {API_URL}"
            )
        if r.status_code == 404:
            return {"__introuvable__": True, "detail": message or r.text[:400]}
        raise ApiIndisponible(f"HTTP {r.status_code} — {message or r.text[:200]}")

    try:
        return r.json()
    except ValueError as e:
        raise ApiIndisponible(f"réponse illisible : {e}") from e


# 🔑 Les mots qui ne portent aucun sujet, et qu'un score de pertinence ne doit
# donc pas compter. La liste tenait d'abord aux seuls articles et prépositions,
# ce qui laissait passer tout le vocabulaire de la question — « mon », « mes »,
# « puis », « quels », « combien ». Une vraie question de droit posée en langue
# courante en contient la moitié, et la couverture la punissait à ce titre :
# mesuré le 21/08/2026 sur neuf questions réelles, cinq déclenchaient l'alerte
# de pertinence au même niveau que des chaînes de mots sans rapport. Avec cette
# liste, trois. Le chevauchement se resserre sans disparaître.
#
# Les entrées s'écrivent sans accent : la comparaison se fait sur la forme
# désaccentuée, sinon « même » et « été » échappent à la liste qui les nomme.
_MOTS_OUTILS = {
    # articles, prépositions, conjonctions
    "de", "des", "du", "la", "le", "les", "un", "une", "et", "ou", "en", "au",
    "aux", "sur", "sous", "dans", "pour", "par", "avec", "sans", "que", "qui",
    "ce", "cet", "cette", "ces", "celui", "celle", "ceux", "dont", "mais",
    "donc", "car", "comme", "chez", "vers", "entre", "contre", "apres",
    "avant", "depuis", "pendant", "alors", "ainsi", "cela", "ceci",
    # pronoms et possessifs
    "son", "ses", "leur", "leurs", "mon", "ton", "mes", "tes", "nos", "vos",
    "notre", "votre", "moi", "toi", "lui", "elle", "elles", "ils", "eux",
    # auxiliaires et verbes support
    "est", "sont", "suis", "etes", "etre", "ete", "etait", "etaient", "sera",
    "seront", "avoir", "peut", "peux", "puis", "peuvent", "pouvez", "dois",
    "doit", "doivent", "fait", "faire", "faut", "agit",
    # vocabulaire de la question
    "quel", "quelle", "quels", "quelles", "quoi", "comment", "pourquoi",
    "combien", "quand", "lequel", "laquelle", "lesquels",
    # quantifieurs et adverbes
    "pas", "plus", "tout", "tous", "toute", "toutes", "meme", "aussi", "tres",
    "bien",
}


def _sans_accents(mot: str) -> str:
    """Retire les diacritiques, pour comparer « harcèlement » et « harcelement »."""
    return "".join(
        c for c in unicodedata.normalize("NFD", mot)
        if not unicodedata.combining(c)
    )


def _mots_utiles(requete: str) -> list[str]:
    """Les mots qui portent le sujet, sans les mots-outils ni les sigles courts."""
    return [
        m for m in re.findall(r"\w{3,}", requete.lower())
        if _sans_accents(m) not in _MOTS_OUTILS
    ]


def _conseil_et_implicite(requete: str, resultats: int) -> str:
    """Dit que la recherche LEGI exige TOUS les mots, quand ça se voit.

    🔑 Les deux recherches de ce serveur se comportent à l'inverse l'une de
    l'autre. Côté LEGI, les termes sont assemblés par un espace, qui est un ET
    implicite en FTS5 : sept mots rendent un résultat, quatre en rendent six,
    deux en rendent vingt. Côté jurisprudence, l'amont pondère et n'exige rien —
    allonger la question y ramène plus de décisions, pas moins.

    Un utilisateur qui précise sa question obtient donc davantage d'un côté et
    presque rien de l'autre, sans qu'aucune des deux réponses ne le dise. Le
    conseil ne se déclenche que là où il sert : une requête assez longue pour
    que la conjonction morde, et une liste assez courte pour qu'on la croie
    vide de droit applicable.
    """
    mots = _mots_utiles(requete)
    if len(mots) < 3 or resultats >= 5:
        return ""
    return (
        f"\n\nCette recherche impose que TOUS les mots figurent dans le même "
        f"article — ici {len(mots)} : {', '.join(mots)}. C'est l'inverse de "
        "`rechercher_jurisprudence`, où ajouter des mots élargit la réponse. "
        "Une liste courte tient souvent à la longueur de la requête, pas à "
        "l'absence de textes : rappelle l'outil avec les deux ou trois mots "
        "les plus spécifiques avant de conclure."
    )


def _couverture(requete: str, resultat: dict) -> tuple[int, int] | None:
    """Termes de la requête retrouvés dans le résultat, sur le total posé.

    🔑 Le score de l'amont ne dit rien de la pertinence : il est normalisé au
    meilleur résultat de chaque requête. Une question absurde obtient 1 / 0,916
    / 0,860 comme une question précise obtient 1 / 0,995 / 0,965 — aucun seuil
    ne les sépare. Ce qui les sépare est ailleurs : la question absurde ne fait
    correspondre que deux de ses sept mots, la précise trois sur quatre.

    Les `highlights` portent cette information et n'étaient pas lus. La
    comparaison se fait sur les cinq premières lettres, accents retirés des deux
    côtés, pour que « motivation » reconnaisse « motivée » et que
    « harcelement » — l'amont accepte la requête sans accents et surligne le
    texte avec — reconnaisse « harcèlement ». Sans ce pli, « harce » et
    « harcè » divergent à la lettre près, et le terme le mieux ciblé de la
    question compte comme absent.

    Le compte est rendu brut, et non en fraction : voir `_sous_la_moitie`.
    """
    repris = _termes_repris(requete, resultat)
    if repris is None:
        return None
    trouves, termes = repris
    return len(trouves), len(termes)


def _termes_repris(requete: str, resultat: dict) -> tuple[set[str], set[str]] | None:
    """(termes de la question surlignés dans ce résultat, termes posés).

    Les comptes de `_couverture` en dérivent : le rapprochement des mots ne
    s'écrit qu'ici. Écrit deux fois, il divergerait au premier ajustement — et
    c'est le rapprochement lui-même qui porte la subtilité des préfixes.
    """
    termes = set(_mots_utiles(requete))
    if not termes:
        return None

    surlignes: set[str] = set()
    for extraits in (resultat.get("highlights") or {}).values():
        for extrait in extraits if isinstance(extraits, list) else [extraits]:
            surlignes.update(
                m.lower() for m in re.findall(r"<em>(.*?)</em>", str(extrait))
            )
    if not surlignes:
        return None

    debuts = {_sans_accents(s)[:5] for s in surlignes}
    return {t for t in termes if _sans_accents(t)[:5] in debuts}, termes


def _termes_jamais_repris(requete: str, resultats: list) -> list[str]:
    """Les termes de la question qu'AUCUN résultat ne surligne.

    🔑 C'est le collectif qui compte : un terme repris par un seul arrêt de la
    liste est repris. Mesuré le 21/08/2026 — « puis-je contester une amende de
    stationnement reçue par erreur » n'obtient que 28 % en moyenne par arrêt,
    mais quatre de ses cinq termes apparaissent quelque part dans la liste. La
    moyenne dit que chaque décision n'en couvre qu'une partie ; celle-ci dit ce
    que la liste entière laisse de côté. Ce sont deux informations, et seule la
    seconde permet de nommer un terme à l'utilisateur.
    """
    repris: set[str] = set()
    poses: set[str] = set()
    for r in resultats:
        paire = _termes_repris(requete, r)
        if paire is not None:
            repris |= paire[0]
            poses |= paire[1]
    return sorted(poses - repris)


# Au-delà de ce nombre, on cesse d'interroger l'index terme à terme : quatre
# absences suffisent à expliquer une liste, et chaque terme coûte un aller-retour.
PLAFOND_TERMES_SONDES = 4


async def _termes_absents(termes: list[str]) -> tuple[list[str], int]:
    """Ceux de ces termes qui n'apparaissent dans AUCUNE décision de l'index.

    🔑 **« Hors sujet » ne s'infère pas d'un faible recouvrement lexical ; « ce
    mot n'existe pas dans l'index » se mesure.** L'ancienne alerte affirmait que
    la recherche avait « accroché quelques mots isolés, sans rapport avec le
    sujet ». Mesuré le 21/08/2026 sur seize questions : la moyenne des termes
    repris valait 28 % pour « puis-je contester une amende de stationnement » et
    25 % pour « est-ce que ma banane peut saxophoner pendant un tracteur nuage ».
    Aucun seuil ne les sépare, parce que la jurisprudence n'emploie simplement
    pas les mots de celui qui la cherche.

    La fréquence des termes semblait offrir le discriminant qui manquait — les
    questions ordinaires plancher à 138 décisions par terme, les absurdes
    plafonnaient à 5. Éprouvé sur huit questions juridiques au vocabulaire
    récent, il s'est effondré : `cryptoactifs`, `trottinette`, `webcam`,
    `algorithme` et `deepfake` valent tous 0. Cinq vraies questions sur huit
    auraient été traitées d'absurdes.

    Ce qui reste vrai des deux côtés, c'est le fait lui-même — et il est utile
    des deux côtés. Que « saxophoner » n'apparaisse nulle part explique la
    liste ; que « cryptoactifs » n'apparaisse nulle part est peut-être la
    réponse que l'utilisateur cherchait.

    Rend (termes absents, nombre de termes réellement interrogés).
    """
    absents: list[str] = []
    sondes = 0
    for terme in termes[:PLAFOND_TERMES_SONDES]:
        try:
            data = await _get("/jurisprudence/search", {"q": terme, "limit": 1})
        except ApiIndisponible:
            # Une sonde muette ne doit pas transformer un terme en « absent » :
            # on rend ce qu'on a pu mesurer, et le compte le dit.
            break
        sondes += 1
        if isinstance(data, dict) and data.get("total") == 0:
            absents.append(terme)
    return absents, sondes


def _sous_la_moitie(trouves: int, total: int) -> bool:
    """Vrai quand moins de la moitié des termes posés sont retrouvés.

    🔑 Un seuil en fraction change de sévérité avec la longueur de la question,
    parce qu'une fraction à petit dénominateur ne prend que quelques valeurs.
    Le seuil « < 0,4 » qui a précédé coupait entre 1/4 et 2/4 sur quatre termes,
    entre 1/3 et 2/3 sur trois, et laissait passer 2/5 — qui vaut 0,400
    exactement, donc n'est pas inférieur. Deux mesures de la même liste, l'une
    sur quatre termes et l'autre sur six, donnaient des verdicts opposés sans
    que rien ne les départage.

    Le critère est donc entier, et l'égalité épargnée : 1/3, 2/5 et 2/6 se
    marquent, 2/4 et 3/6 non. Un produit plutôt qu'une division, pour ne pas
    réintroduire le flottant qu'on vient d'écarter.
    """
    return trouves * 2 < total


MARQUEUR_ANNEXES = re.compile(r"\n\s*MOYENS?\s+ANNEXES?", re.IGNORECASE)


def _sans_annexes(texte: str, zones: Any) -> str | None:
    """Rend le texte privé de ses moyens annexés, ou None si indéterminable.

    Les moyens annexés reproduisent l'argumentation des parties : ils ne sont
    pas la décision, et forment pourtant l'essentiel des arrêts longs. Les
    retirer laisse le raisonnement et le dispositif entiers.

    Les intervalles sont retirés un à un plutôt que coupés au premier : rien ne
    garantit que les annexes forment un bloc unique en fin de texte, et une
    zone peut arriver dans le désordre — `moyens` en compte trois, non triés.
    """
    if not isinstance(zones, dict):
        # 🔑 Judilibre ne découpe pas les décisions anciennes, et c'est
        # justement là que les annexes pèsent le plus. Le marqueur est dans le
        # texte : sur quinze décisions longues sans `zones`, il était présent
        # quinze fois, et les quinze repassaient entières sous le plafond une
        # fois coupées — 63 à 89 % du volume.
        #
        # Le premier marqueur, jamais le dernier : les annexes forment la fin du
        # texte, et couper au dernier en garderait l'essentiel.
        m = MARQUEUR_ANNEXES.search(texte)
        return (texte[: m.start()].strip() or None) if m else None

    # Les bornes sont ramenées dans le texte au lieu d'être rejetées : l'amont
    # annonce une fin à 76 373 pour un texte de 76 372 caractères, et écarter
    # la zone pour ce caractère de trop reviendrait à garder les annexes.
    blocs = [
        (max(0, b["start"]), min(len(texte), b["end"]))
        for b in (zones.get("annexes") or [])
        if isinstance(b, dict)
        and isinstance(b.get("start"), int)
        and isinstance(b.get("end"), int)
        and b["start"] < b["end"]
        and b["start"] < len(texte)
    ]
    if not blocs:
        return None

    morceaux: list[str] = []
    curseur = 0
    for debut, fin in sorted(blocs):
        if debut > curseur:
            morceaux.append(texte[curseur:debut])
        curseur = max(curseur, fin)
    morceaux.append(texte[curseur:])

    return "".join(morceaux).strip() or None


# Les 36 cours d'appel, telles que l'index les nomme. Une table plutôt qu'une
# transformation mécanique : celle-ci rendrait « St Denis Reunion » ou
# « Aix Provence », approximations qui n'ont pas leur place dans une référence
# juridique. La liste est close — le ressort des cours d'appel ne change pas
# d'une année sur l'autre.
COURS_APPEL = {
    "ca_agen": "Agen", "ca_aix_provence": "Aix-en-Provence", "ca_amiens": "Amiens",
    "ca_angers": "Angers", "ca_basse_terre": "Basse-Terre", "ca_bastia": "Bastia",
    "ca_besancon": "Besançon", "ca_bordeaux": "Bordeaux", "ca_bourges": "Bourges",
    "ca_caen": "Caen", "ca_cayenne": "Cayenne", "ca_chambery": "Chambéry",
    "ca_colmar": "Colmar", "ca_dijon": "Dijon", "ca_douai": "Douai",
    "ca_fort_de_france": "Fort-de-France", "ca_grenoble": "Grenoble",
    "ca_limoges": "Limoges", "ca_lyon": "Lyon", "ca_metz": "Metz",
    "ca_montpellier": "Montpellier", "ca_nancy": "Nancy", "ca_nimes": "Nîmes",
    "ca_noumea": "Nouméa", "ca_orleans": "Orléans", "ca_papeete": "Papeete",
    "ca_paris": "Paris", "ca_pau": "Pau", "ca_poitiers": "Poitiers",
    "ca_reims": "Reims", "ca_rennes": "Rennes", "ca_riom": "Riom",
    "ca_rouen": "Rouen", "ca_st_denis_reunion": "Saint-Denis de La Réunion",
    "ca_toulouse": "Toulouse", "ca_versailles": "Versailles",
}

JURIDICTIONS = {"cc": "Cour de cassation", "ca": "Cour d'appel"}

# Les formations de la Cour de cassation, libellés repris de la taxonomie
# Judilibre (`/taxonomy?id=chamber&context_value=cc`), abaissés en minuscule
# initiale parce qu'ils s'écrivent en apposition — « Cour de cassation, chambre
# mixte ». La liste est close : les onze codes ci-dessous sont exactement ceux
# présents en base, du plus fourni (soc, 145 814 décisions) au plus rare
# (creun, 40). `allciv` existe dans la taxonomie mais n'est qu'un agrégat de
# recherche, jamais porté par une décision.
#
# 🔑 Sans cette table, une décision s'affichait « Cour de cassation, mi » — un
# code de deux lettres à la place de la chambre mixte. Le travail avait été
# fait pour les 36 cours d'appel et laissé pour les 11 chambres, plus courtes à
# inventorier.
CHAMBRES = {
    "civ1": "première chambre civile",
    "civ2": "deuxième chambre civile",
    "civ3": "troisième chambre civile",
    "comm": "chambre commerciale financière et économique",
    "soc": "chambre sociale",
    "cr": "chambre criminelle",
    "mi": "chambre mixte",
    "pl": "assemblée plénière",
    "creun": "chambres réunies",
    "ordo": "première présidence (ordonnance)",
    "other": "formation non précisée",
}

# Le fonds d'où vient le texte. Il ne dit pas la juridiction : dix décisions de
# cassation tirées au hasard portent toutes `dila`, aucune `jurinet`. La table
# bâtie sur ce champ pour nommer la juridiction ne reconnaissait donc presque
# rien — elle avait été écrite sur la seule valeur rencontrée. La juridiction se
# lit désormais sur `jurisdiction`, renseigné partout ; le fonds n'est plus
# qu'un complément.
FONDS_JUDILIBRE = {
    "jurinet": "fonds Jurinet",
    "jurica": "fonds JuriCA",
    "dila": "diffusion DILA",
}


def _nom_chambre(chambre: Any) -> str:
    """Nomme la formation, ou rend le code tel quel s'il est inconnu."""
    code = str(chambre or "")
    return CHAMBRES.get(code.lower(), code)


DATE_ISO = re.compile(r"\d{4}-\d{2}-\d{2}")


def _nom_juridiction(juridiction: Any, cour: Any) -> str:
    """Rend « Cour d'appel de Nîmes » plutôt que « ca ca_nimes ».

    Le code brut ne dit rien à qui lit la réponse, et le préfixe de la cour
    répétait la juridiction. Une valeur inconnue est rendue telle quelle : mieux
    vaut un code lisible qu'un nom inventé.
    """
    juri = JURIDICTIONS.get(str(juridiction), str(juridiction or ""))
    if not cour:
        return juri
    nom = COURS_APPEL.get(str(cour))
    if nom is None:
        return f"{juri} {cour}"          # code inconnu : le rendre tel quel
    liaison = "d'" if nom[0] in "AEIOUÉÈaeiou" else "de "
    return f"{juri} {liaison}{nom}"


def _fin_reelle(date_fin: Any) -> str | None:
    """Rend la date de fin, ou None quand elle n'en est pas une.

    LEGI note « sans terme prévu » par une date lointaine — 2999-01-01. La
    reprendre telle quelle donnerait une échéance à un texte qui n'en a pas, et
    daterait un article en vigueur comme s'il expirait.
    """
    d = _parse_date_fr(str(date_fin or ""))
    if d is None or d.year > dt.date.today().year + 50:
        return None
    return str(date_fin)


def _msg_argument_refuse(detail: str) -> str:
    """Message pour un argument que l'API refuse — jamais une panne.

    🔑 Distinct de `_msg_api_morte` : là où celui-ci dit « impossible de
    vérifier, va voir ailleurs », celui-ci dit « corrige et rappelle ». Les
    confondre envoie l'utilisateur vers Légifrance quand la réponse tenait dans
    une faute de frappe.
    """
    return (
        f"Demande refusée par l'API ({detail}).\n\n"
        "Ce n'est pas une panne : le service répond et signale un argument "
        "invalide. Corrige-le et rappelle l'outil. N'affirme rien sur ce point "
        "de droit tant que la vérification n'a pas abouti."
    )


def _msg_api_morte(detail: str) -> str:
    """🔑 Le silence est le pire résultat possible ici.

    Si l'API ne répond pas et que l'outil rend une erreur discrète, le modèle
    enchaîne sur sa mémoire et sert exactement l'hallucination que ce serveur
    existe pour empêcher. Le message est donc une instruction, pas un constat.
    """
    return (
        f"API SelfJustice injoignable ({detail}).\n\n"
        "Ne cite AUCUN article de mémoire. Dis à l'utilisateur que la "
        "vérification en base officielle est impossible pour l'instant, et "
        "renvoie-le vers legifrance.gouv.fr. Une référence citée sans "
        "vérification n'a aucune valeur juridique."
    )


_statut_cache: tuple[float, Any] | None = None
_CACHE_TTL = 300  # secondes


async def _statut_cache_court() -> Any:
    """`/status` mis en cache cinq minutes.

    Chaque outil a besoin de la date de synchronisation pour son bandeau. Sans
    cache, une session de travail qui consulte vingt articles frappe quarante
    fois l'API pour lire quarante fois la même date — la base ne bouge que deux
    fois par mois.
    """
    global _statut_cache
    maintenant = time.monotonic()
    if _statut_cache and maintenant - _statut_cache[0] < _CACHE_TTL:
        return _statut_cache[1]
    statut = await _get("/status")
    _statut_cache = (maintenant, statut)
    return statut


async def _bandeau(base: str) -> str:
    """Ligne d'état à placer en tête de chaque réponse."""
    try:
        statut = await _statut_cache_court()
    except ApiIndisponible:
        return ""
    # Une correspondance explicite plutôt qu'un « sinon » : avec deux bases le
    # raccourci passait, la troisième l'aurait fait lire le mauvais bloc et
    # dater la jurisprudence avec la fraîcheur de la conventionnalité.
    cle = {
        "LEGI": "legi",
        "conventionnalité": "eu",
        "jurisprudence": "jurisprudence",
    }.get(base, "")
    bloc = statut.get(cle, {})
    if not bloc:
        return ""
    message, retard = _etat_fraicheur(bloc, base)
    if retard:
        await _alerter(
            f"SelfJustice — base {base} en retard",
            f"Consultation MCP : {message}",
            cle,
        )
    return message + _PERIMETRE.get(cle, "")


# 🔑 **L'avertissement de périmètre doit accompagner l'outil qu'on APPELLE.**
# Il existait, mais seulement dans `verifier_jurisprudence` — jamais sur la
# recherche. Mesuré le 21/08/2026 par un essai extérieur : « excès de pouvoir
# arrêt du Conseil d'État » rendait 37 159 décisions de la Cour de cassation,
# dont un arrêt de 1965 dont le sommaire commence par « L'ARRÊT DU CONSEIL
# D'ÉTAT QUI ANNULE UN DÉCRET POUR EXCÈS DE POUVOIR ». Du matériau réel,
# vérifiable, et hors sujet : la pire combinaison pour fabriquer une réponse
# fausse et confiante. Rien ne disait que la justice administrative n'est pas
# dans la base.
#
# Placé dans le bandeau, il accompagne tous les outils de la base d'un coup —
# écrit outil par outil, il aurait manqué le suivant.
_PERIMETRE = {
    "jurisprudence": (
        "\nPérimètre : justice JUDICIAIRE seulement. La justice administrative "
        "— Conseil d'État, cours administratives d'appel, tribunaux "
        "administratifs — n'est pas dans cette base : si la question en relève, "
        "dis-le et renvoie vers ArianeWeb plutôt que de servir un arrêt civil "
        "qui parle du même mot."
    ),
}


# -------------------------------------------------------------------- outils

@server.tool()
async def statut() -> str:
    """État des bases juridiques : volumétrie, sources et date de synchronisation.

    À appeler avant toute consultation. La date rendue ici est celle que tu
    dois reprendre dans ton avertissement à l'utilisateur — n'invente jamais
    une date de mise à jour.
    """
    try:
        data = await _statut_cache_court()
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    legi, eu = data.get("legi", {}), data.get("eu", {})

    # Une réponse sans aucun des deux blocs n'est pas un statut SelfJustice.
    # Sans ce garde-fou, le serveur afficherait « ? articles » avec une
    # fraîcheur « indéterminée » — présentable, et faux : rien ne dirait au
    # modèle qu'il parle à autre chose que la base attendue.
    if not legi and not eu:
        return _msg_api_morte(
            f"{API_URL}/status répond, mais sans les blocs attendus — ce n'est "
            "pas une instance SelfJustice"
        )
    etat_legi, retard_legi = _etat_fraicheur(legi, "LEGI")
    etat_eu, retard_eu = _etat_fraicheur(eu, "conventionnalité")

    if retard_legi:
        await _alerter("SelfJustice — base LEGI en retard", etat_legi, "legi")
    if retard_eu:
        await _alerter("SelfJustice — base UE en retard", etat_eu, "eu")

    sources = ", ".join(f"{k} ({v})" for k, v in sorted(eu.get("sources", {}).items()))

    # La jurisprudence peut manquer : une instance plus ancienne ne sert que
    # LEGI et la conventionnalité. Son absence se dit, elle ne s'invente pas —
    # taire la ligne laisserait croire que la vérification des arrêts est
    # disponible alors qu'elle ne l'est pas.
    juris = data.get("jurisprudence", {})
    if juris:
        etat_juris, retard_juris = _etat_fraicheur(juris, "jurisprudence")
        if retard_juris:
            await _alerter(
                "SelfJustice — index jurisprudence en retard", etat_juris, "jurisprudence"
            )
        # La couverture prime sur le total : un numéro hors période ne peut pas
        # être déclaré absent, seulement indéterminé.
        def _borne(bloc: dict) -> str:
            fin = _parse_date_fr(bloc.get("fin", ""))
            return _format_fr(fin) if fin else bloc.get("fin", "?")

        bornes = " · ".join(
            f"{code} jusqu'au {_borne(bloc)} ({bloc.get('decisions', '?')} décisions)"
            for code, bloc in sorted(juris.get("couverture", {}).items())
        )
        ligne_juris = (
            f"Jurisprudence : {juris.get('decisions', '?')} décisions — {bornes}.\n"
        )
    else:
        etat_juris = (
            "Index de jurisprudence absent de cette instance : aucun numéro "
            "d'arrêt ne peut être vérifié ici."
        )
        ligne_juris = ""

    # 🔑 **Cet outil doit couvrir tout ce qui sera consulté.** Il s'annonce comme
    # l'état des bases et la consigne dit de l'appeler avant toute consultation —
    # mais le catalogue des démarches n'y figurait pas, alors qu'il a sa propre
    # cadence et décrochait de dix-huit jours sans que `statut` en dise un mot.
    # Une vue d'ensemble qui oublie une source n'est pas une vue d'ensemble.
    #
    # Il vit sous une autre racine : son absence ne doit pas faire échouer le
    # reste, elle doit se dire.
    try:
        meta = (await _get("/catalog", {"limit": 1}, base=ACT_URL)).get("meta") or {}
        ligne_catalogue = await _bandeau_catalogue(meta) + (
            f"\nDémarches officielles : {meta.get('total', '?')} ressources."
        )
    except (ApiIndisponible, RequeteInvalide) as e:
        ligne_catalogue = (
            f"Catalogue des démarches injoignable ({e}) : `catalogue_actes` et "
            "`actes_pour_situation` ne répondront pas non plus."
        )

    return (
        f"{etat_legi}\n{etat_eu}\n{etat_juris}\n{ligne_catalogue}\n\n"
        f"Droit français (LEGI, dumps officiels DILA) : "
        f"{legi.get('articles', '?')} articles dont {legi.get('vigueur', '?')} en vigueur.\n"
        f"Conventionnalité : {eu.get('articles', '?')} articles — {sources}.\n"
        f"{ligne_juris}\n"
        "Cadence de synchronisation annoncée : le 1er et le 15 de chaque mois. "
        "⚠️ Le rapprochement situation → acte est curé à la main, sans cadence : "
        "sa date ne se lit pas comme un retard."
    )


@server.tool()
async def article_francais(reference: str, code: str | None = None) -> str:
    """Texte intégral d'un article du droit français, tel qu'en vigueur.

    Args:
        reference: numéro de l'article, ex. « L1152-1 », « R4127-1 », « 1240 ».
        code: code concerné, en clair (« travail », « civil », « penal ») ou par
            identifiant LEGITEXT. Sans ce filtre, un numéro porté par plusieurs
            codes rend la liste des codes possibles plutôt qu'un texte au hasard.
    """
    try:
        data = await _get(f"/legi/article/{reference}", {"code": code} if code else None)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau("LEGI")

    if data.get("__introuvable__"):
        return (
            f"{bandeau}\n\nArticle « {reference} » introuvable"
            + (f" dans le code « {code} »" if code else "")
            + ".\n\nNe substitue pas un article de mémoire. Dis à l'utilisateur "
            "que la référence qu'il donne n'existe pas telle quelle dans la "
            "base, et demande-lui d'où il la tient."
        )

    # Numéro porté par plusieurs codes : l'API rend les alternatives, pas un
    # texte. Rendre l'un des deux au hasard serait la pire réponse possible —
    # L1152-1 existe au code du travail et au code de la défense.
    if data.get("ambiguous"):
        lignes = "\n".join(
            f"  · code={a.get('code_id')}"
            + (f" — {a.get('code')}" if a.get("code") else "")
            # L'état décide souvent du choix : entre deux codes, celui qui est
            # encore en vigueur n'est pas un détail de présentation.
            + (f" [{a.get('etat')}" + (f", jusqu'au {a.get('date_fin')}" if a.get("date_fin") else "") + "]"
               if a.get("etat") else "")
            + f"\n      « {(a.get('apercu') or '').strip()[:120]}… »"
            for a in data.get("alternatives", [])
        )
        return (
            f"{bandeau}\n\nL'article {reference} existe dans plusieurs codes. "
            "Rappelle `article_francais` avec l'argument `code` (alias en clair "
            "acceptés : travail, civil, penal, consommation, sante_publique, "
            f"assurances, urbanisme…) :\n{lignes}"
        )

    src = data.get("source", {})

    # La période, pas seulement le point de départ. « PAS EN VIGUEUR · en
    # vigueur depuis le 2014-03-27 » se contredisait dans la même ligne, et
    # `date_fin` — que l'API rend — était jetée alors qu'elle porte
    # l'information décisive sur un article abrogé : jusqu'à quand il
    # s'appliquait, donc à quels faits il s'applique encore.
    debut = data.get("date_debut") or "?"
    fin = _fin_reelle(data.get("date_fin"))
    if data.get("en_vigueur"):
        vigueur = f"EN VIGUEUR · depuis le {debut}"
    else:
        vigueur = f"⚠️ PLUS EN VIGUEUR (état : {data.get('etat', 'inconnu')})"
        vigueur += f" · applicable du {debut} au {fin}" if fin else f" · entré en vigueur le {debut}"
        vigueur += (
            "\nIl reste opposable aux faits de cette période : ne le présente "
            "ni comme le droit actuel, ni comme sans effet."
        )

    # 🔑 Un numéro recyclé est plus piégeux qu'un article mort : il rend un
    # texte en vigueur, exact et correctement daté, mais sans rapport avec ce
    # qu'on cherchait. L'article 1382 du code civil porte les présomptions
    # judiciaires depuis 2016, quand la responsabilité délictuelle qu'on y vise
    # est passée au 1240. L'avertissement se place AVANT le texte : après, il
    # arrive une fois la lecture faite et la citation déjà formée.
    renvoi = data.get("renvoi") or {}
    alerte = f"{renvoi['message']}\n\n" if renvoi.get("message") else ""

    return (
        f"{bandeau}\n\n"
        f"{data.get('code_titre') or data.get('code_id', '')} — article {data.get('reference', reference)}\n"
        f"{vigueur}\n\n"
        f"{alerte}"
        f"{data.get('texte', '(texte absent de la base)')}\n\n"
        f"Source : {src.get('origine', 'LEGI')} — base au {src.get('last_update', '?')}\n"
        f"Légifrance : {src.get('legifrance_url', '(lien indisponible)')}"
    )


@server.tool()
async def rechercher_droit_francais(requete: str, limite: int = 20) -> str:
    """Cherche des articles français par mot-clé ou par numéro.

    Les correspondances de numéro viennent en premier, puis celles trouvées
    dans le texte des articles, classées par pertinence. Rend des références à
    confirmer ensuite avec `article_francais`, qui seul rend le texte complet.

    Les accents sont facultatifs : « prenom » trouve « prénom ».

    ⚠️ Tous les mots posés doivent figurer dans le même article : la recherche
    les assemble par un ET. Une question longue restreint donc la réponse —
    c'est l'inverse de `rechercher_jurisprudence`. Deux ou trois mots
    spécifiques valent mieux qu'une phrase.

    Args:
        requete: mots du sujet cherché (« harcèlement moral », « chanvre »)
            ou fragment de numéro (« L1152 », « 1240 »).
        limite: nombre maximum de résultats (défaut 20).
    """
    try:
        data = await _get("/legi/search", {"q": requete, "limit": limite})
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau("LEGI")
    resultats = data.get("results", []) if not data.get("__introuvable__") else []

    if not resultats:
        return (
            f"{bandeau}\n\nAucun article ne correspond à « {requete} »."
            + _conseil_et_implicite(requete, 0)
            + "\n\nN'invente pas de référence approchante : dis à l'utilisateur "
            "que la recherche ne rend rien et demande-lui de préciser."
        )

    def _ligne_article(r: dict) -> str:
        # `code` est le titre lisible ; `code_titre` n'est rendu par aucune des
        # routes et faisait retomber l'affichage sur l'identifiant technique.
        fin = _fin_reelle(r.get("date_fin"))
        periode = f"{r.get('date_debut')} → {fin}" if fin else f"depuis {r.get('date_debut')}"
        ligne = (
            f"  · {r.get('reference')} — {r.get('code') or r.get('code_id')} "
            f"[{r.get('etat')}, {periode}]"
        )
        extrait = (r.get("extrait") or "").strip()
        return ligne + (f"\n      {extrait[:160]}" if extrait else "")

    # Les abrogés arrivent en dernier, jamais mêlés au droit applicable : c'est
    # l'ordre qui porte l'avertissement, autant que la mention d'état.
    en_vigueur = [r for r in resultats if r.get("etat") == "VIGUEUR"]
    anciens = [r for r in resultats if r.get("etat") != "VIGUEUR"]

    blocs = []
    if en_vigueur:
        blocs.append("\n".join(_ligne_article(r) for r in en_vigueur))
    if anciens:
        blocs.append(
            "— Ne sont plus en vigueur (utiles pour des faits antérieurs, "
            "jamais pour dire le droit d'aujourd'hui) :\n"
            + "\n".join(_ligne_article(r) for r in anciens)
        )

    return (
        f"{bandeau}\n\n{data.get('count', len(resultats))} résultat(s) pour « {requete} » :\n"
        + "\n\n".join(blocs)
        + _conseil_et_implicite(requete, len(resultats))
        + "\n\nAppelle `article_francais` sur la référence retenue pour en obtenir le texte."
    )


@server.tool()
async def article_europeen(source: str, numero: str) -> str:
    """Texte d'un article de conventionnalité (UE, CEDH, RGPD, règlement IA).

    Args:
        source: l'un de CEDH, CHARTE_UE, TUE, TFUE, RGPD, AI_ACT.
            AI_ACT est le règlement (UE) 2024/1689 sur l'intelligence
            artificielle — son article 50 porte les obligations de transparence
            applicables aux contenus générés par IA.
        numero: numéro de l'article, ex. « 50 », « 8 », « P1-1 » pour les
            protocoles de la CEDH.
    """
    src = source.strip().upper()
    if src not in SOURCES_UE:
        return (
            f"Source « {source} » inconnue. Sources disponibles : "
            f"{', '.join(SOURCES_UE)}."
        )

    try:
        data = await _get(f"/eu/article/{src}/{numero}")
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau("conventionnalité")

    if data.get("__introuvable__"):
        return (
            f"{bandeau}\n\nArticle {numero} introuvable dans {src}.\n\n"
            "Ne cite pas de mémoire : vérifie le numéro avec "
            "`rechercher_conventionnalite`."
        )

    titre = data.get("titre") or ""
    return (
        f"{bandeau}\n\n"
        f"{src} — article {data.get('reference', numero)}"
        + (f" : {titre}" if titre else "")
        + f"\n\n{data.get('texte', '(texte absent de la base)')}"
    )


@server.tool()
async def rechercher_conventionnalite(requete: str, source: str | None = None) -> str:
    """Cherche dans les textes de conventionnalité (UE, CEDH, RGPD, règlement IA).

    Args:
        requete: mots à chercher, ex. « transparence », « vie privée ».
        source: restreint à une source (CEDH, CHARTE_UE, TUE, TFUE, RGPD, AI_ACT).
    """
    params: dict[str, Any] = {"q": requete}
    if source:
        src = source.strip().upper()
        if src not in SOURCES_UE:
            return (
                f"Source « {source} » inconnue. Sources disponibles : "
                f"{', '.join(SOURCES_UE)}."
            )
        params["source"] = src

    try:
        data = await _get("/eu/search", params)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau("conventionnalité")
    resultats = data.get("results", []) if not data.get("__introuvable__") else []

    # 🔑 Les formes réellement cherchées. Les textes écrivent « données à
    # caractère personnel » et jamais « données personnelles » : la recherche
    # retombe donc sur « personnel ». Le taire rendrait la réponse
    # inexplicable — et le silence sur ce point a coûté cher, puisque la
    # recherche ne trouvait auparavant que les citations verbatim et rendait
    # zéro sur la formulation la plus courante du RGPD.
    # L'API rend des couples [mot posé, forme cherchée] ; seuls les couples qui
    # DIFFÈRENT valent d'être dits. Une première version comparait la forme à la
    # requête entière : « personnel » y étant contenu — c'est un préfixe de
    # « personnelles » — la transformation la plus utile restait invisible.
    changees = [
        (pose, forme)
        for couple in (data.get("mots_cherches") or [])
        if isinstance(couple, list) and len(couple) == 2
        for pose, forme in [couple]
        if pose != forme
    ]
    note_formes = (
        "\n\nCherché « " + " », « ".join(f"{f} » pour « {p}" for p, f in changees)
        + " » : les textes n'emploient pas toujours le mot au genre ou au nombre "
        "où on le pose."
        if changees else ""
    )

    if not resultats:
        return (
            f"{bandeau}\n\nAucun résultat pour « {requete} »"
            + (f" dans {params.get('source')}" if source else "")
            + "."
            + note_formes
            + "\n\nN'invente pas de référence : dis-le à l'utilisateur. "
            "Si le sujet devrait s'y trouver, reformule avec les mots des "
            "textes plutôt qu'avec les tiens."
        )

    # Les titres sont souvent vides dans la base UE : l'aperçu est alors le
    # seul élément qui permet de choisir un article plutôt qu'un autre.
    lignes = "\n".join(
        f"  · {r.get('source')} art. {r.get('reference')}"
        + (f" — {r.get('titre')}" if r.get("titre") else "")
        + (f"\n      « {(r.get('apercu') or '').strip()[:140]}… »" if r.get("apercu") else "")
        for r in resultats
    )
    # ⚠️ Le compte rendu et le compte total ne se confondent pas : « 20
    # résultats » là où il y en a 83 fait croire qu'on a tout vu.
    montres = data.get("count", len(resultats))
    total = data.get("total")
    entete = (
        f"{montres} résultat(s) sur {total} pour « {requete} » :"
        if isinstance(total, int) and total > montres
        else f"{montres} résultat(s) pour « {requete} » :"
    )
    return (
        f"{bandeau}\n\n{entete}\n{lignes}"
        + note_formes
        + "\n\nAppelle `article_europeen` sur la référence retenue pour en "
        "obtenir le texte."
    )


def _msg_juris_morte(detail: str) -> str:
    """Même logique que `_msg_api_morte`, appliquée à la jurisprudence.

    Le risque y est plus grand qu'ailleurs : un arrêt inventé porte un numéro
    crédible, une chambre plausible et une solution vraisemblable. Trois sites
    juridiques ont publié trois décisions différentes sous le même numéro sans
    que personne ne s'en aperçoive.
    """
    return (
        f"Vérification de jurisprudence impossible ({detail}).\n\n"
        "Ne cite AUCUNE décision de mémoire, même si elle te paraît certaine. "
        "Dis à l'utilisateur que la vérification est indisponible et renvoie-le "
        "vers courdecassation.fr ou judilibre.io. Un numéro d'arrêt cité sans "
        "vérification n'a aucune valeur."
    )


@server.tool()
async def verifier_jurisprudence(
    reference: str,
    juridiction: str | None = None,
    date: str | None = None,
) -> str:
    """Vérifie qu'un numéro de décision existe réellement, avant de le citer.

    À appeler chaque fois qu'un numéro d'arrêt apparaît — qu'il vienne de
    l'utilisateur, d'un site web ou de ta propre mémoire. Un numéro plausible
    n'est pas un numéro réel.

    Args:
        reference: le numéro tel qu'il s'écrit — « 25-10.377 » pour un pourvoi,
            « 26/00027 » pour un rôle général de cour d'appel.
        juridiction: « cc » (Cour de cassation) ou « ca » (cours d'appel).
            À préciser quand on la connaît : le même numéro normalisé peut
            désigner un pourvoi et un RG de cour d'appel.
        date: la date attribuée à la décision, au format « AAAA-MM-JJ », quand
            la référence en porte une. Elle change la réponse en cas d'échec :
            une date couverte par l'index rend le « introuvable » ferme, là où
            il reste prudent sans elle.
    """
    chemin = f"/jurisprudence/verifier/{urllib.parse.quote(reference, safe='')}"
    # La date n'est transmise que bien formée : mal formée, l'index la refuse en
    # 400, ce qui ferait passer une réponse sur la date pour une réponse sur la
    # référence. Le format fautif se dit plus bas, où il ne coûte rien.
    params = {
        cle: val
        for cle, val in (
            ("jurisdiction", juridiction),
            ("date", date.strip() if date and DATE_ISO.fullmatch(date.strip()) else None),
        )
        if val
    } or None
    try:
        data = await _get(chemin, params)
    except RequeteInvalide as e:
        return (
            f"La référence « {reference} » n'a pas été acceptée par l'index ({e}).\n\n"
            "Ce n'est pas une panne : reprends le numéro tel qu'il s'écrit "
            "(« 25-10.377 » pour un pourvoi, « 26/00027 » pour un rôle général "
            "de cour d'appel) et rappelle l'outil. N'affirme rien sur cette "
            "décision tant qu'elle n'est pas vérifiée."
        )
    except ApiIndisponible as e:
        return _msg_juris_morte(str(e))

    # Une route inconnue rendrait ici « existe — 0 décision(s) » : une
    # confirmation d'existence pour une référence introuvable, exactement le
    # contresens que cet outil doit empêcher.
    if data.get("__introuvable__"):
        return _msg_juris_morte(
            f"l'index ne connaît pas cette route ({data.get('detail', '')})"
        )

    etat = data.get("etat")

    if etat == "indeterminee":
        return _msg_juris_morte(data.get("raison", "raison non précisée"))

    # 🔑 Le bandeau compte surtout ici : « introuvable » ne veut pas dire la même
    # chose selon que l'index s'arrête au mois dernier ou date de la veille.
    bandeau = await _bandeau("jurisprudence")

    if etat == "absente":
        # 🔑 **La réserve se lit, elle ne se recalcule pas.** Ce bloc la
        # reconstruisait à partir de `couverture` et de la date, et écrasait
        # celle que l'index venait de rendre — deux raisonnements sur la même
        # question, dont celui-ci est le moins informé : depuis le 22/08/2026 la
        # route interroge aussi la base amont quand la date sort de la plage
        # locale, et ce résultat-là ne se devine pas d'ici. Le module aurait
        # continué d'annoncer « une décision postérieure ne peut pas être
        # exclue » alors que l'amont venait de trancher.
        reserve = data.get("reserve") or ""
        note = ""
        # La date n'est transmise que bien formée (voir plus haut) : mal formée,
        # l'index ne l'a pas vue et sa réserve est restée prudente sans savoir
        # pourquoi. C'est le seul endroit où le client sait quelque chose que
        # l'index ignore, donc le seul qu'il ait à dire.
        if date and not DATE_ISO.fullmatch(date.strip()):
            note = (
                f"\n\n(La date « {date} » n'a pas été comprise — format "
                "attendu : AAAA-MM-JJ. Elle n'a donc pas été transmise à "
                "l'index, et la réserve ci-dessus reste prudente par défaut.)"
            )
        return (
            f"{bandeau}\n\n"
            f"« {reference} » est INTROUVABLE dans l'index.\n\n"
            f"{reserve}{note}\n\n"
            "Ne présente pas cette décision comme existante. Si l'utilisateur "
            "l'a lue quelque part, dis-lui que la référence n'a pas pu être "
            "confirmée et invite-le à vérifier sa source."
        )

    decisions = data.get("decisions", [])
    lignes = "\n".join(
        # La cour distingue les rôles généraux entre eux : sans elle, deux
        # décisions portant le même numéro sont impossibles à départager.
        f"  · {d.get('numero')} — {_nom_juridiction(d.get('juridiction'), d.get('cour'))}"
        + (f", {_nom_chambre(d.get('chambre'))}" if d.get("chambre") else "")
        + f", {d.get('date') or 'date douteuse en base amont'}"
        + (f" — {d.get('solution')}" if d.get("solution") else "")
        + (" — publié au Bulletin" if "b" in (d.get("publication") or "") else "")
        + (f"\n      {d.get('ecli')}" if d.get("ecli") else "")
        + f"\n      id : {d.get('id')}"
        for d in decisions
    )

    total = data.get("count", len(decisions))
    entete = f"« {reference} » existe — {total} décision(s)"
    if data.get("tronquee"):
        entete += f", les {len(decisions)} plus récentes affichées"

    avert = data.get("avertissement")
    # Une décision retrouvée en amont mais pas encore dans l'index local porte
    # une réserve ici aussi. Sans elle, un second appel sur la même référence
    # semblerait démentir le premier.
    reserve = data.get("reserve")
    return (
        f"{bandeau}\n\n"
        f"{entete} :\n{lignes}\n\n"
        + (f"⚠️ {avert}\n\n" if avert else "")
        + (f"{reserve}\n\n" if reserve else "")
        + "L'existence est confirmée, pas le contenu : appelle `texte_decision` "
        "avec l'id avant d'affirmer ce que la décision juge."
    )


@server.tool()
async def rechercher_jurisprudence(
    requete: str,
    limite: int = 10,
    depuis: str | None = None,
    jusqu_a: str | None = None,
    juridiction: str | None = None,
    champ: str | None = None,
) -> str:
    """Cherche des décisions par thème dans la base de la Cour de cassation.

    Rend des références à confirmer ensuite avec `texte_decision`, qui seul
    donne le contenu réel.

    Args:
        requete: les mots du sujet cherché (« rupture conventionnelle »).
            Pour un numéro, utiliser `verifier_jurisprudence`.
        limite: nombre maximum de résultats (défaut 10).
        champ: où chercher. Laissé vide, il suit la juridiction — le sommaire
            pour la Cour de cassation, le texte entier pour les cours d'appel,
            qui n'en rédigent pas.
            🔑 Le sommaire dit la portée de l'arrêt et n'existe que sur les
            décisions publiées : c'est ce qu'on veut pour « la jurisprudence sur
            X », pas pour retrouver une espèce particulière — `text` sert alors,
            au prix de résultats bien plus nombreux et moins ciblés.
            Autres valeurs : `themes`, `motivations`, `dispositif`, `visa`.
        depuis: date minimale, au format « 2024-01-01 ». 🔑 À renseigner dès que
            la question porte sur l'état actuel du droit : la recherche classe
            par score, pas par date, et rend sinon des arrêts des années 1970
            en tête — qu'un revirement a pu priver de toute valeur.
        jusqu_a: date maximale, même format.
        juridiction: « cc » (Cour de cassation) ou « ca » (cours d'appel).
    """
    # 🔑 Le sommaire est une pratique de la Cour de cassation : les cours
    # d'appel n'en rédigent pas. Chercher dedans par défaut faisait répondre
    # « aucune décision ne correspond » à toute recherche `juridiction="ca"`,
    # là où le texte entier en rend des milliers — un faux négatif produit par
    # le réglage lui-même. Le défaut suit donc la juridiction ; une valeur
    # explicite est toujours respectée.
    if champ is None:
        champ = "text" if juridiction == "ca" else "summary"

    params: dict[str, Any] = {"q": requete, "limit": limite, "champ": champ}
    if depuis:
        params["date_start"] = depuis
    if jusqu_a:
        params["date_end"] = jusqu_a
    if juridiction:
        params["jurisdiction"] = juridiction

    try:
        data = await _get("/jurisprudence/search", params)
    except RequeteInvalide as e:
        return (
            f"La recherche a été refusée par l'index ({e}).\n\n"
            "Ce n'est pas une panne : corrige le paramètre signalé et rappelle "
            "l'outil. N'invente aucune décision en attendant."
        )
    except ApiIndisponible as e:
        return _msg_juris_morte(str(e))

    # 🔑 Un 404 propre de l'amont rend le sentinelle, pas une liste vide. Sans
    # ce test, `data.get("results", [])` valait [] et l'outil annonçait « aucune
    # décision ne correspond » — c'est-à-dire l'instruction de conclure à
    # l'absence, là où l'index n'avait tout simplement pas répondu. Le faux
    # négatif silencieux que ce module existe pour empêcher, sur l'outil par
    # lequel on cherche.
    # Les six autres outils faisaient déjà ce test ; celui-ci était le seul à
    # ne pas l'avoir, et le protocole du constat d'origine ne visait que
    # /verifier et /decision.
    if data.get("__introuvable__"):
        return _msg_juris_morte(
            f"l'index ne connaît pas cette route ({data.get('detail', '')})"
        )

    bandeau = await _bandeau("jurisprudence")

    resultats = data.get("results", [])
    if not resultats:
        # Le bandeau compte particulièrement ici : une recherche qui ne rend
        # rien sur un index arrêté au mois dernier ne dit pas la même chose que
        # sur un index de la veille.
        return (
            f"{bandeau}\n\n"
            f"Aucune décision ne correspond à « {requete} ».\n\n"
            "N'invente pas d'arrêt approchant : dis que la recherche ne rend "
            "rien et demande à l'utilisateur de préciser son sujet."
        )

    def _ligne(r: dict) -> str:
        # 🔑 Le résumé est écrit par la Cour elle-même et fait autorité. Le taire
        # obligeait à passer par `texte_decision` — jusqu'à 80 000 caractères,
        # au-delà de ce qu'un client encaisse — pour une information que la
        # recherche tenait déjà.
        #
        # La moyenne absorbe l'isolé : sept arrêts qui reprennent la question et
        # un qui n'en reprend rien restent au-dessus du seuil global, et le
        # dernier passerait sans marque — avec le sommaire officiel et la
        # mention « publié au Bulletin » pour lui. D'où une marque par ligne,
        # au même critère que l'ensemble.
        couv = _couverture(requete, r)
        # ⚠️ Un compte, pas un verdict. « hors sujet probable » affirmait une
        # pertinence que le recouvrement lexical ne permet pas de juger : la
        # jurisprudence n'emploie pas les mots de celui qui la cherche.
        doute = (
            f" ⚠️ {couv[0]}/{couv[1]} termes repris"
            if couv is not None and _sous_la_moitie(*couv) else ""
        )
        tete = (
            f"  · {r.get('number')} — "
            + _nom_juridiction(r.get("jurisdiction"), r.get("location"))
            + (f", {_nom_chambre(r.get('chamber'))}" if r.get("chamber") else "")
            + f", {r.get('decision_date')}"
            + (f" — {r.get('solution')}" if r.get("solution") else "")
            + (" — publié au Bulletin" if "b" in (r.get("publication") or []) else "")
            + doute
        )
        resume = (r.get("summary") or "").strip()
        if resume:
            tete += f"\n      résumé officiel : {resume[:400]}"
            if len(resume) > 400:
                tete += " […]"
        return tete + f"\n      id : {r.get('id')}"

    lignes = "\n".join(_ligne(r) for r in resultats)

    # 🔑 Prévenir, jamais écarter. Depuis que les résultats portent le sommaire
    # de la Cour et la mention « publié au Bulletin », un hors-sujet a les
    # habits d'un arrêt de principe — une requête absurde rend 14 821 décisions
    # d'apparence irréprochable. Le dire vaut mieux que de trier en silence :
    # un serveur qui cache ce qu'il juge mauvais ne se vérifie plus.
    couvertures = [c for c in (_couverture(requete, r) for r in resultats) if c is not None]
    alerte = ""
    trouves = sum(t for t, _ in couvertures)
    poses = sum(n for _, n in couvertures)
    if poses and _sous_la_moitie(trouves, poses):
        moyenne = round(100 * trouves / poses)

        jamais = _termes_jamais_repris(requete, resultats)
        absents, sondes = await _termes_absents(jamais)

        alerte = (
            f"\n\n⚠️ Ces décisions ne reprennent que {moyenne} % des termes de "
            "la question."
        )
        if jamais:
            alerte += (
                "\n   Aucune ne surligne : " + ", ".join(f"« {t} »" for t in jamais) + "."
            )
        if absents:
            alerte += (
                "\n   Et " + ", ".join(f"« {t} »" for t in absents)
                + (" n'apparaît" if len(absents) == 1 else " n'apparaissent")
                + " dans AUCUNE décision de l'index : la jurisprudence indexée "
                "n'emploie pas ce vocabulaire. Ce n'est pas forcément une "
                "erreur de la question — le sujet peut n'avoir donné lieu à "
                "aucune décision publiée."
            )
        if len(jamais) > sondes:
            # ⚠️ Ne jamais laisser croire que tout a été vérifié : le plafond
            # existe, il se dit.
            alerte += (
                f"\n   ({len(jamais) - sondes} autre(s) terme(s) non vérifié(s) "
                "dans l'index.)"
            )
        alerte += (
            "\n   Vérifie chaque sommaire avant d'en citer une, et reformule "
            "avec le vocabulaire des décisions si aucune ne convient."
        )

    # Deux conseils distincts, chacun pour un manque différent : la date parce
    # que le classement se fait par score et remonte des arrêts d'il y a
    # cinquante ans ; le champ parce qu'une recherche élargie au texte entier
    # ramène des décisions étrangères au sujet.
    conseils = []
    # Les deux bornes se consultent, pas seulement `depuis` : qui borne à
    # `jusqu_a` cherche explicitement de l'ancien, et s'entendait quand même
    # conseiller de viser « l'état actuel du droit ».
    if not depuis and not jusqu_a:
        conseils.append(
            "Ces résultats sont classés par pertinence, pas par date : pour "
            "l'état actuel du droit, rappelle l'outil avec `depuis` "
            "(par exemple « 2022-01-01 »)."
        )
    if champ == "summary":
        conseils.append(
            "La recherche porte sur les sommaires, donc sur les arrêts publiés. "
            "Si tu cherches une espèce précise plutôt qu'un principe, rappelle "
            "l'outil avec `champ=\"text\"`."
        )
    conseil = ("\n\n" + " ".join(conseils)) if conseils else ""
    return (
        f"{bandeau}\n\n"
        f"{data.get('total', len(resultats))} décision(s) pour « {requete} » "
        f"(les {len(resultats)} premières) :\n{lignes}{alerte}{conseil}\n\n"
        "Le résumé dit la solution, pas le raisonnement : appelle "
        "`texte_decision` sur l'id retenu avant de citer quoi que ce soit."
    )


@server.tool()
async def texte_decision(identifiant: str, integral: bool = False) -> str:
    """Rend le texte d'une décision, à partir de son identifiant.

    Args:
        identifiant: l'`id` rendu par `verifier_jurisprudence` ou
            `rechercher_jurisprudence`. Ce n'est pas le numéro de la décision.
        integral: rend le texte entier, moyens annexés compris. Par défaut, les
            annexes sont écartées : elles pèsent l'essentiel des arrêts longs —
            63 036 caractères sur 76 372 pour une décision mesurée — alors que
            la décision elle-même, dispositif compris, en fait 13 337. Les
            écarter rend l'arrêt entier au lieu d'un début coupé avant sa
            conclusion.
    """
    try:
        data = await _get(f"/jurisprudence/decision/{urllib.parse.quote(identifiant, safe='')}")
    except RequeteInvalide as e:
        return (
            f"L'identifiant « {identifiant} » a été refusé par l'index ({e}).\n\n"
            "Ce n'est pas une panne : l'identifiant attendu est celui rendu par "
            "`verifier_jurisprudence` ou `rechercher_jurisprudence`, pas le "
            "numéro de la décision. Reprends-le et rappelle l'outil."
        )
    except ApiIndisponible as e:
        return _msg_juris_morte(str(e))

    if data.get("__introuvable__") or data.get("etat") == "indeterminee":
        return _msg_juris_morte(data.get("raison", data.get("detail", "raison non précisée")))

    bandeau = await _bandeau("jurisprudence")
    entete = (
        f"{data.get('number')} — "
        + _nom_juridiction(data.get("jurisdiction"), data.get("location"))
        + (f", {_nom_chambre(data.get('chamber'))}" if data.get("chamber") else "")
        + f", {data.get('decision_date')}"
    )

    texte = data.get("text") or "(texte non fourni par la source)"
    coupe = ""

    if not integral and len(texte) > PLAFOND_TEXTE:
        # L'amont rend la décision découpée en zones, avec leurs offsets. Les
        # moyens annexés en forment l'essentiel — 82 % d'un arrêt mesuré — et
        # ne sont pas la décision : les écarter rend le raisonnement et le
        # dispositif entiers, là où une coupe à longueur fixe gardait
        # l'introduction et jetait la conclusion.
        garde = _sans_annexes(texte, data.get("zones"))

        # Le retrait des annexes ne suffit pas toujours : une décision aux
        # annexes courtes et à la motivation longue repasserait au-dessus du
        # plafond. On revérifie plutôt que de le supposer.
        if garde is not None and len(garde) <= PLAFOND_TEXTE:
            coupe = (
                f"\n\n[…] Moyens annexés écartés — {len(texte) - len(garde)} "
                f"caractères sur {len(texte)}. La décision est ici entière, "
                "dispositif compris. `integral=True` rend aussi les annexes."
            )
            texte = garde
        else:
            # 🔑 Repli à deux bouts, jamais une coupe franche du début.
            #
            # Judilibre ne découpe pas les décisions anciennes : la moitié des
            # arrêts longs arrivent sans `zones`. Ne garder que le début revenait
            # alors à servir l'introduction et les faits, et à jeter la
            # motivation et le dispositif — c'est-à-dire tout ce que la décision
            # juge, et précisément ce que le message disait d'aller chercher en
            # fin de texte.
            #
            # Deux tiers au début pour le raisonnement, un tiers à la fin pour le
            # dispositif, qui y tient toujours.
            if garde is not None:
                texte = garde         # annexes déjà retirées, encore trop long
            tete = (PLAFOND_TEXTE * 2) // 3
            queue = PLAFOND_TEXTE - tete
            coupe = (
                f"\n\n[…] ⚠️ EXTRAIT — {PLAFOND_TEXTE} des {len(texte)} "
                "caractères. Le milieu de la décision manque ; le début et la "
                "fin, dispositif compris, sont là. Ne conclus pas sur ce qui "
                "n'est pas affiché. `integral=True` rend le texte entier si le "
                "client peut l'encaisser, sinon lis la décision sur "
                "courdecassation.fr."
            )
            texte = (
                texte[:tete]
                + "\n\n[…  partie centrale omise  …]\n\n"
                + texte[-queue:]
            )

    # La juridiction commande, le fonds complète : `source` vaut `dila` sur
    # toutes les décisions mesurées et ne permet pas de dire d'où vient le
    # texte. Un champ inconnu se tait plutôt que de nommer une origine fausse.
    juri = JURIDICTIONS.get(str(data.get("jurisdiction") or ""))
    fonds = FONDS_JUDILIBRE.get(str(data.get("source") or "").lower())
    detail = ", ".join(x for x in (juri, fonds) if x)
    provenance = f"Judilibre ({detail})" if detail else "Judilibre"

    return (
        f"{bandeau}\n\n{entete}\n{data.get('ecli', '')}\n\n{texte}{coupe}\n\n"
        f"Source : {provenance}, open data. Cite la décision "
        "telle qu'elle est écrite ici, sans la reformuler en règle générale."
    )


# ------------------------------------------------------------------ SelfAct
#
# 🔑 Trois outils, pas quatre. L'API SelfAct expose aussi `/act/api/draft`, qui
# produit un acte pré-rempli — et qui n'est PAS exposé ici, pour une raison
# technique avant d'être juridique : son garde-fou est un filigrane SVG
# « NON OFFICIEL — IRRECEVABLE » appliqué à une page HTML imprimable. Un outil
# MCP rend du texte à un modèle ; le filigrane ne survivrait pas au passage, et
# le modèle recevrait un brouillon d'acte propre qu'il recopierait tel quel.
# Exposer `draft` reviendrait à retirer sa protection en la croyant intacte.
#
# Ce qui reste — indexer, orienter, calculer — cite des sources officielles et
# montre son raisonnement, sans jamais produire d'acte.


async def _bandeau_catalogue(meta: Any) -> str:
    """Ligne d'état du catalogue, lue là où elle vit.

    Le catalogue ne figure pas dans `/api/status` : sa date de synchronisation
    voyage dans `meta.last_sync` de chaque réponse. On la lit donc sur place
    plutôt que d'ajouter un appel — et l'absence de date se dit, au lieu de
    laisser croire à une fraîcheur qu'on n'a pas vérifiée.
    """
    # ⚠️ Les deux noms ne désignent pas la même chose, et c'est tout l'objet de
    # cette fonction. `last_sync` date une synchronisation automatique, de
    # cadence bimensuelle comme les bases de textes — elle peut donc être en
    # retard, et le retard se dit. `last_update` date la curation manuelle du
    # rapprochement situation → acte : rien ne la synchronise, aucune échéance
    # ne lui est opposable, et l'annoncer « synchronisée » ferait conclure à une
    # panne de cron devant une date simplement ancienne.
    if not isinstance(meta, dict):
        return ("Catalogue SelfAct — date inconnue : traite les références "
                "comme à vérifier.")

    version = meta.get("version", "?")
    # Les dates arrivent en ISO, parfois horodatées (« 2026-08-03T17:58:05+00:00 »).
    # La troncature sert à isoler le jour, pas à décrire la valeur reçue : le
    # message d'erreur, lui, cite la valeur entière, sans quoi il ferait chercher
    # un défaut dans dix caractères choisis par nous.
    brut_synchro = str(meta.get("last_sync") or "")
    synchro = brut_synchro[:10]
    curation = str(meta.get("last_update") or "")[:10]

    if synchro:
        date_base = _parse_date_fr(synchro)
        if date_base is None:
            return (f"Catalogue SelfAct — l'index annonce « {brut_synchro} », qui ne "
                    "se lit pas comme une date : traite les références comme à vérifier.")
        echeance = _derniere_echeance(dt.date.today())
        if date_base >= echeance:
            return f"Catalogue SelfAct synchronisé au {synchro} (version {version})."
        retard = (dt.date.today() - date_base).days
        return (
            f"⚠️ RETARD — catalogue SelfAct arrêté au {synchro} (version "
            f"{version}), soit {retard} jours. L'échéance du "
            f"{_format_fr(echeance)} n'a pas été honorée : une démarche ou un "
            "formulaire peut avoir changé de référence depuis. Renvoie vers "
            "service-public.gouv.fr avant tout usage."
        )

    if curation:
        ligne = (f"Rapprochement situation → acte, curé à la main le {curation} "
                 f"(version {version}). Aucune synchronisation automatique ne "
                 "s'y applique : une date ancienne n'y signale pas de retard.")
        # Les suggestions de cette route viennent du catalogue synchronisé, dont
        # le retard ne se voyait nulle part ici : la date de curation n'en dit
        # rien, et c'est pourtant lui qui a composé la moitié de la réponse.
        sous_catalogue = meta.get("catalogue")
        if isinstance(sous_catalogue, dict) and sous_catalogue.get("last_sync"):
            second = await _bandeau_catalogue(sous_catalogue)
            if second.startswith("⚠️"):
                return f"{ligne}\n{second}"
        return ligne

    return "Catalogue SelfAct — date inconnue : traite les références comme à vérifier."


@server.tool()
async def catalogue_actes(
    recherche: str | None = None,
    categorie: str | None = None,
    type_ressource: str | None = None,
) -> str:
    """Cherche dans le catalogue des ressources officielles de l'administration.

    Modèles de lettres, formulaires CERFA et démarches en ligne, indexés depuis
    service-public.gouv.fr. Rend des références à ouvrir sur le site officiel :
    ce sont des pointeurs, pas des documents.

    Args:
        recherche: mots du sujet (« congés payés », « logement insalubre »).
        categorie: restreint à une catégorie du catalogue.
        type_ressource: « modele_lettre », « formulaire » ou « teleservice ».
            🔑 Ce sont les valeurs de l'index, pas des mots choisis pour la
            documentation. Trois valeurs plus courtes y figuraient — « modele »,
            « cerfa », « demarche » — et l'API les refusait toutes les trois :
            le paramètre était inutilisable en suivant sa propre notice, et la
            seule valeur qui marchait ne se découvrait qu'en lisant une étiquette
            dans une réponse.
    """
    params = {k: v for k, v in (("q", recherche), ("category", categorie), ("type", type_ressource)) if v}
    try:
        data = await _get("/catalog", params, base=ACT_URL)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau_catalogue(data.get("meta"))
    modeles = data.get("models") or data.get("results") or []
    if not modeles:
        return (
            f"{bandeau}\n\nAucune ressource officielle ne correspond"
            + (f" à « {recherche} »" if recherche else "")
            + ".\n\nNe propose pas de modèle de ton cru : oriente vers "
            "service-public.fr ou demande à l'utilisateur de préciser."
        )

    lignes = "\n".join(
        f"  · {m.get('label') or '(sans intitulé)'}"
        + (f" [{m.get('type')}]" if m.get("type") else "")
        + (f" · {m.get('category')}" if m.get("category") else "")
        + (f"\n      {m.get('url')}" if m.get("url") else "")
        for m in modeles[:20]
    )
    reste = f"\n\n({len(modeles) - 20} autres non affichées)" if len(modeles) > 20 else ""
    return (
        f"{bandeau}\n\n{len(modeles)} ressource(s) officielle(s) :\n{lignes}{reste}\n\n"
        "Renvoie l'utilisateur vers l'adresse officielle. Ne recopie pas un "
        "formulaire de mémoire et n'en rédige pas d'équivalent."
    )


@server.tool()
async def actes_pour_situation(situation: str | None = None) -> str:
    """Quelles démarches officielles existent pour une situation donnée.

    Appelée sans argument, rend la liste des situations connues. Les slugs ne se
    devinent pas : commence par la liste plutôt que par une supposition.

    Args:
        situation: identifiant d'une situation, tel qu'il figure dans la liste.
    """
    params = {"situation": situation} if situation else {"list": "1"}
    try:
        data = await _get("/find", params, base=ACT_URL)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau_catalogue(data.get("meta"))

    if not situation or data.get("situations"):
        noms = data.get("situations") or []
        liste = "\n".join(
            f"  · {s if isinstance(s, str) else s.get('slug', '?')}"
            + ("" if isinstance(s, str) else f" — {s.get('label', '')}"
               + (f" ({s.get('acts_count')} actes)" if s.get("acts_count") else ""))
            for s in noms
        )
        return f"{bandeau}\n\n{len(noms)} situation(s) couverte(s) :\n{liste}\n\nRappelle l'outil avec l'une d'elles."

    # L'amont répond « situation_not_found » avec un indice : le relayer plutôt
    # que de laisser le modèle inventer un slug voisin.
    if data.get("ok") is False:
        return (
            f"{bandeau}\n\nLa situation « {situation} » n'est pas couverte "
            f"({data.get('error', 'inconnue')}).\n\nRappelle l'outil sans argument "
            "pour obtenir la liste, et n'invente pas de démarche pour combler le trou."
        )

    actes = data.get("acts") or []
    lignes = "\n".join(
        f"  · {a.get('label') or '(sans intitulé)'}"
        + (f" [{a.get('type')}]" if a.get("type") else "")
        + (f" — {a.get('status')}" if a.get("status") else "")
        + (f"\n      {a.get('url')}" if a.get("url") else "")
        for a in actes
    )

    # La réponse porte plus que des démarches : les articles applicables, le
    # délai de prescription et les seuils. Les taire obligerait le modèle à les
    # chercher ailleurs — ou à les inventer.
    # ⚠️ `articles` est une CHAÎNE, pas une liste — « art. 1240 C. civ.,
    # art. R. 1336-5 C. santé publique, … ». La parcourir comme une liste
    # affichait ses caractères un à un. Type vérifié, pas supposé.
    articles = data.get("articles")
    bloc_art = ""
    if isinstance(articles, str) and articles.strip():
        bloc_art = f"\n\nArticles cités par la fiche : {articles.strip()}"
    elif isinstance(articles, list) and articles:
        bloc_art = "\n\nArticles cités par la fiche :\n" + "\n".join(
            f"  · {a if isinstance(a, str) else a.get('reference') or a.get('label') or a}"
            for a in articles[:10]
        )
    presc = data.get("prescription")
    bloc_presc = f"\n\n⚠️ Prescription : {presc}" if presc else ""
    urgence = data.get("urgency")

    return (
        f"{bandeau}\n\n{data.get('label') or situation}"
        + (f" — urgence : {urgence}" if urgence else "")
        + f"\n\n{len(actes)} acte(s), les officiels d'abord :\n{lignes}"
        + bloc_art + bloc_presc
        + "\n\nCes démarches sont des points de départ, pas un conseil sur le cas "
        "de l'utilisateur : les faits de son espèce ne sont pas connus ici. "
        "Vérifie chaque article avec `article_francais` avant de le citer."
    )


@server.tool()
async def calculer_echeance(
    depart: str,
    jours: int | None = None,
    mois: int | None = None,
    annees: int | None = None,
    distance: str = "metropole",
) -> str:
    """Calcule une échéance de procédure et montre le raisonnement suivi.

    Applique les articles 640 à 643 du code de procédure civile — le jour de
    départ ne compte pas, le report tombe au premier jour ouvrable, et les
    délais de distance s'ajoutent le cas échéant.

    Args:
        depart: date de départ au format « AAAA-MM-JJ ».
        jours: durée en jours.
        mois: durée en mois.
        annees: durée en années.
        distance: où demeure la personne — « metropole » (défaut), « outremer »
            ou « etranger ». L'article 643 augmente le délai d'un mois pour
            l'outre-mer et de deux mois pour l'étranger, et seulement pour les
            délais de comparution, d'appel, d'opposition, de tierce opposition,
            de recours en révision et de pourvoi en cassation.
    """
    # 🔑 Trois états, pas deux. Ce paramètre a d'abord été un booléen, qui ne
    # pouvait pas exprimer la différence entre un mois et deux — et qui ne
    # marchait pas non plus : l'index attend le nom du lieu, si bien qu'un
    # « oui » partait en « 1 » et revenait refusé. Le paramètre documenté ne
    # rendait jamais de résultat.
    lieux = ("metropole", "outremer", "etranger")
    lieu = str(distance).strip().lower()
    if lieu not in lieux:
        return (
            f"« {distance} » n'est pas un lieu connu pour l'article 643.\n\n"
            f"Valeurs acceptées : {', '.join(lieux)}. L'augmentation vaut un mois "
            "pour l'outre-mer et deux mois pour l'étranger ; sans précision, le "
            "calcul suppose la France métropolitaine. N'annonce aucune échéance "
            "tant que le calcul n'a pas abouti."
        )
    params: dict[str, Any] = {"start": depart}
    for nom, valeur in (("days", jours), ("months", mois), ("years", annees)):
        if valeur is not None:
            params[nom] = valeur
    if lieu != "metropole":
        params["distance"] = lieu

    try:
        data = await _get("/deadline", params, base=ACT_URL)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    if data.get("ok") is False:
        return (
            f"Le calcul a été refusé ({data.get('error', 'raison non précisée')}).\n\n"
            "Vérifie le format de la date (AAAA-MM-JJ) et qu'une durée est bien "
            "donnée. N'annonce aucune échéance tant qu'elle n'est pas calculée."
        )

    # 🔑 Le raisonnement fait la valeur de cet outil : une date sans ses règles
    # ne se vérifie pas, et un délai de procédure manqué ne se rattrape pas.
    etapes = "\n".join(
        f"  · {e.get('regle', '?')} — {e.get('detail', '')}"
        for e in (data.get("raisonnement") or [])
        if isinstance(e, dict)
    )
    # 🔑 L'API rend un lien d'agenda dérivé des paramètres qui ont servi au
    # calcul ; cet outil ne l'affichait nulle part. Le correctif du 21/08/2026 —
    # qui a réparé un lien menant deux mois avant la date annoncée — était donc
    # invisible à qui passe par le serveur MCP. Un essai extérieur l'a vu :
    # « aucun export .ics dans les treize outils ».
    #
    # Le chemin est relatif à la racine des démarches : on le rend absolu ici,
    # sans quoi il ne mène nulle part chez le lecteur.
    ics = data.get("ics")
    agenda = (
        f"\n\nAjouter à un agenda : {ACT_URL}/deadline{ics}"
        if isinstance(ics, str) and ics.startswith("?") else ""
    )

    # Le fondement arrive en table — un article par clé, plus la source et la
    # mention d'indépendance. Interpolé tel quel, il rendait la représentation
    # Python du dictionnaire, accolades et guillemets compris.
    fondement = data.get("fondement")
    if isinstance(fondement, dict):
        articles = "\n".join(
            f"  · {cle} — {val}" for cle, val in fondement.items() if cle.startswith("art.")
        )
        reste = "\n".join(
            str(fondement[cle]) for cle in ("source", "mention") if fondement.get(cle)
        )
        fondement = "\n\n".join(bloc for bloc in (articles, reste) if bloc)
    elif fondement is not None:
        fondement = str(fondement)

    return (
        f"Départ {data.get('depart')} → **échéance le {data.get('echeance')}**"
        + (f" ({data.get('jour')})" if data.get("jour") else "")
        + ("\n⚠️ Échéance reportée : la date obtenue par le calcul n'était pas "
           "un jour ouvrable." if data.get("reporte") else "")
        + (f"\n\nRaisonnement :\n{etapes}" if etapes else "")
        + (f"\n\nFondement :\n{fondement}" if fondement else "")
        + (f"\n\n⚠️ {data.get('avertissement')}" if data.get("avertissement") else "")
        + agenda
        + "\n\nDonne la date AVEC son raisonnement : c'est ce qui permet à "
        "l'utilisateur de le faire vérifier. Un délai manqué ne se rattrape pas."
    )




@server.tool()
async def gabarit_document(type_document: str | None = None) -> str:
    """Donne l'adresse d'un gabarit de courrier et les ressources officielles.

    🔑 Cet outil ne rédige rien et ne rend pas le document. Il indique où
    l'obtenir et ce qu'il restera à compléter. Le gabarit est un document à
    trous : les faits, montants, dates et noms n'y sont jamais devinés — ils
    n'appartiennent qu'à la personne qui l'utilise.

    Il nomme surtout les modèles, formulaires et démarches OFFICIELS qui
    couvrent la même chose. Quand il en existe un, il vaut mieux que le
    gabarit : c'est une pièce que l'administration reconnaît.

    Le document lui-même s'ouvre dans un navigateur et s'imprime en PDF. Il
    porte un filigrane « NON OFFICIEL — IRRECEVABLE » que rien ne retire : le
    rendre ici en texte le perdrait.

    Args:
        type_document: l'un des gabarits disponibles. Sans argument, les liste.
    """
    if not ACT_URL:
        return _msg_api_morte("SELFRIGHT_ACT_URL introuvable et non dérivable")

    # 🔑 La liste vient de l'instance, elle n'est plus écrite ici. Elle l'était,
    # à côté de celle de `draft.php` : deux tables pour une seule vérité, qui
    # divergeaient déjà d'une entrée.
    try:
        data = await _get("/gabarits.php", None, base=ACT_URL)
    except RequeteInvalide as e:
        return _msg_argument_refuse(str(e))
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    gabarits = data.get("gabarits") or {}
    if not gabarits:
        return _msg_api_morte("l'instance ne déclare aucun gabarit")

    if not type_document:
        liste = "\n".join(
            f"  · {cle} — {g.get('label', cle)}"
            + (f" ({len(g.get('officiels') or [])} ressource(s) officielle(s))"
               if g.get("officiels") else "")
            for cle, g in gabarits.items()
        )
        return (
            f"{len(gabarits)} gabarits disponibles :\n{liste}\n\n"
            "Rappelle l'outil avec l'un d'eux."
        )

    cle = type_document.strip().lower()
    if cle not in gabarits:
        return (
            f"« {type_document} » n'est pas un gabarit connu.\n\n"
            f"Disponibles : {', '.join(gabarits)}.\n\n"
            "N'en invente pas un autre et ne rédige pas de courrier à la place : "
            "un modèle inventé n'a aucune valeur devant l'administration."
        )

    g = gabarits[cle]
    officiels = g.get("officiels") or []

    if officiels:
        lignes = "\n".join(
            f"  · {o.get('titre')}\n    {o.get('url')}"
            + (f"\n    → {o['quand']}" if o.get("quand") else "")
            for o in officiels
        )
        bloc = (
            f"Ressources OFFICIELLES pour cette démarche ({len(officiels)}) :\n"
            f"{lignes}\n\n"
            "Propose-les EN PREMIER : ce sont les pièces que l'administration "
            "reconnaît. Le gabarit ci-dessous ne sert qu'à préparer le récit des "
            "faits, ou quand aucune ne correspond.\n\n"
        )
    else:
        bloc = (
            "Aucune ressource officielle ne correspond à ce gabarit au "
            "catalogue. Dis-le plutôt que de laisser croire qu'il n'en existe "
            "pas ailleurs.\n\n"
        )

    reserve = f"⚠️ {g['reserve']}\n\n" if g.get("reserve") else ""
    # Un renvoi que le catalogue ne connaît plus se dit : il vaut mieux savoir
    # qu'une ressource manque à l'appel que la croire inexistante.
    perdus = g.get("inconnus") or []
    manque = (f"({len(perdus)} renvoi(s) de cette démarche ne sont plus au "
              f"catalogue : {', '.join(perdus)}. La liste ci-dessus est donc "
              f"incomplète.)\n\n") if perdus else ""

    url = f"{ACT_URL}/draft.php?type={urllib.parse.quote(cle)}"
    return (
        f"Gabarit « {g.get('label', cle)} »\n\n"
        f"{bloc}{reserve}{manque}"
        f"Gabarit à trous, à ouvrir dans un navigateur puis imprimer en PDF :\n"
        f"  {url}\n\n"
        "Champs laissés à compléter par la personne concernée :\n"
        "  · identité et adresse de l'expéditeur et du destinataire\n"
        "  · objet du courrier et action demandée\n"
        "  · chronologie des faits\n"
        "  · lieu, date et signature\n\n"
        "⚠️ Ne recopie pas ce gabarit de mémoire et ne le reconstitue pas : "
        "donne l'adresse. Le document porte une mention « non officiel » qui ne "
        "survivrait pas à une restitution en texte, et une version approximative "
        "d'un courrier type se retourne contre celui qui l'envoie.\n\n"
        "Les faits, montants et dates ne se devinent pas : demande-les, ou "
        "laisse les crochets en place pour que la personne les complète."
    )


def main() -> None:
    """Point d'entrée — transport stdio, celui qu'attend un client MCP local."""
    server.run(transport="stdio")


if __name__ == "__main__":
    main()
