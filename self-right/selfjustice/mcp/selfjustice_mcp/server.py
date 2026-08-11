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

Configuration (toutes optionnelles) :
    SELFJUSTICE_API_URL         racine de l'API — OBLIGATOIRE, sans défaut
    SELFJUSTICE_NTFY_URL        topic ntfy à prévenir en cas de retard
    SELFJUSTICE_NTFY_TOKEN      jeton du topic
    SELFJUSTICE_TIMEOUT         délai réseau en secondes (défaut : 15)
"""

from __future__ import annotations

import datetime as dt
import logging
import os
import re
import time
import urllib.parse
from typing import Any

import httpx
from mcp.server import MCPServer

# httpx journalise chaque requête en INFO. Sur le transport stdio, stdout porte
# le protocole et stderr remonte dans les logs du client : une ligne par appel
# d'outil noie ce qui compte vraiment.
logging.getLogger("httpx").setLevel(logging.WARNING)

# Sans valeur par défaut, délibérément. Y inscrire une instance en dur ferait
# porter à son exploitant le trafic de toutes les installations du paquet, sans
# qu'il l'ait choisi ni qu'aucun de ces utilisateurs ne le sache. Chacun désigne
# l'instance qu'il interroge — la sienne, ou une qu'on lui a indiquée.
API_URL = os.environ.get("SELFJUSTICE_API_URL", "").rstrip("/")
NTFY_URL = os.environ.get("SELFJUSTICE_NTFY_URL", "")
NTFY_TOKEN = os.environ.get("SELFJUSTICE_NTFY_TOKEN", "")
TIMEOUT = float(os.environ.get("SELFJUSTICE_TIMEOUT", "15"))

# Longueur au-delà de laquelle le texte d'une décision est coupé. Assez pour
# rendre la plupart des arrêts entiers, assez peu pour qu'aucun ne sature le
# client : un arrêt réel mesuré à 79 658 caractères dépassait la limite et se
# perdait dans un fichier annexe, laissant le modèle sans rien à citer.
PLAFOND_TEXTE = int(os.environ.get("SELFJUSTICE_PLAFOND_TEXTE", "20000"))

SOURCES_UE = ("CEDH", "CHARTE_UE", "TUE", "TFUE", "RGPD", "AI_ACT")

MOIS = {
    "janvier": 1, "février": 2, "fevrier": 2, "mars": 3, "avril": 4,
    "mai": 5, "juin": 6, "juillet": 7, "août": 8, "aout": 8,
    "septembre": 9, "octobre": 10, "novembre": 11, "décembre": 12, "decembre": 12,
}

server = MCPServer(
    name="selfjustice",
    title="SelfJustice — droit français et conventionnalité",
    version="0.1.0",
    instructions=(
        "Consulte le droit français (base LEGI, dumps officiels DILA) et les textes "
        "de conventionnalité (CEDH, Charte UE, TUE, TFUE, RGPD, règlement IA). "
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


def _etat_fraicheur(last_update: str, base: str) -> tuple[str, bool]:
    """Rend (message lisible, retard constaté)."""
    date_base = _parse_date_fr(last_update)
    if date_base is None:
        return (
            f"Fraîcheur de la base {base} indéterminée : l'API annonce "
            f"« {last_update or 'rien'} », qui ne se lit pas comme une date. "
            "Traite les articles rendus comme non datés et vérifie-les à la source.",
            True,
        )

    aujourdhui = dt.date.today()
    echeance = _derniere_echeance(aujourdhui)

    # La date affichée vient de la valeur parsée, jamais du texte reçu : l'API
    # écrit la jurisprudence en ISO et les deux autres bases en toutes lettres.
    # Recopier la chaîne telle quelle ferait cohabiter « 2 août 2026 » et
    # « 2026-08-09 » dans le même statut.
    lisible = _format_fr(date_base)

    if date_base >= echeance:
        return (f"Base {base} synchronisée au {lisible} — à jour.", False)

    retard = (aujourdhui - date_base).days
    return (
        f"⚠️ RETARD — base {base} arrêtée au {lisible}, soit {retard} jours. "
        f"L'échéance du {_format_fr(echeance)} n'a pas été honorée. Les textes "
        "rendus peuvent être abrogés ou modifiés depuis : signale-le à "
        "l'utilisateur et renvoie-le vers legifrance.gouv.fr avant tout usage "
        "ayant des conséquences (saisine, courrier, décision).",
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


async def _get(chemin: str, params: dict[str, Any] | None = None) -> Any:
    if not API_URL:
        raise ApiIndisponible(
            "SELFJUSTICE_API_URL n'est pas défini : ce serveur ne sait pas quelle "
            "instance interroger. Renseigne-la dans la configuration du client MCP. "
            "N'invente aucun texte de loi en attendant."
        )
    url = f"{API_URL}{chemin}"
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
                f"SELFJUSTICE_API_URL, actuellement {API_URL}"
            )
        if r.status_code == 404:
            return {"__introuvable__": True, "detail": message or r.text[:400]}
        raise ApiIndisponible(f"HTTP {r.status_code} — {message or r.text[:200]}")

    try:
        return r.json()
    except ValueError as e:
        raise ApiIndisponible(f"réponse illisible : {e}") from e


_MOTS_OUTILS = {
    "de", "des", "du", "la", "le", "les", "un", "une", "et", "ou", "en", "au",
    "aux", "sur", "sous", "dans", "pour", "par", "avec", "sans", "que", "qui",
    "est", "sont", "son", "ses", "leur", "leurs", "ce", "cet", "cette",
}


def _couverture(requete: str, resultat: dict) -> float | None:
    """Part des termes de la requête effectivement retrouvés dans le résultat.

    🔑 Le score de l'amont ne dit rien de la pertinence : il est normalisé au
    meilleur résultat de chaque requête. Une question absurde obtient 1 / 0,916
    / 0,860 comme une question précise obtient 1 / 0,995 / 0,965 — aucun seuil
    ne les sépare. Ce qui les sépare est ailleurs : la question absurde ne fait
    correspondre que deux de ses sept mots, la précise trois sur quatre.

    Les `highlights` portent cette information et n'étaient pas lus. La
    comparaison se fait sur les cinq premières lettres, pour que « motivation »
    reconnaisse « motivée » sans réclamer un analyseur morphologique.
    """
    termes = {
        m for m in re.findall(r"\w{3,}", requete.lower())
        if m not in _MOTS_OUTILS
    }
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

    trouves = sum(
        1 for t in termes
        if any(s[:5] == t[:5] for s in surlignes)
    )
    return trouves / len(termes)


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
        return None

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
    message, retard = _etat_fraicheur(bloc.get("last_update", ""), base)
    if retard:
        await _alerter(
            f"SelfJustice — base {base} en retard",
            f"Consultation MCP : {message}",
            cle,
        )
    return message


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
    etat_legi, retard_legi = _etat_fraicheur(legi.get("last_update", ""), "LEGI")
    etat_eu, retard_eu = _etat_fraicheur(eu.get("last_update", ""), "conventionnalité")

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
        etat_juris, retard_juris = _etat_fraicheur(
            juris.get("last_update", ""), "jurisprudence"
        )
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

    return (
        f"{etat_legi}\n{etat_eu}\n{etat_juris}\n\n"
        f"Droit français (LEGI, dumps officiels DILA) : "
        f"{legi.get('articles', '?')} articles dont {legi.get('vigueur', '?')} en vigueur.\n"
        f"Conventionnalité : {eu.get('articles', '?')} articles — {sources}.\n"
        f"{ligne_juris}\n"
        "Cadence de synchronisation annoncée : le 1er et le 15 de chaque mois."
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

    return (
        f"{bandeau}\n\n"
        f"{data.get('code_titre') or data.get('code_id', '')} — article {data.get('reference', reference)}\n"
        f"{vigueur}\n\n"
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
            f"{bandeau}\n\nAucun article ne correspond à « {requete} ».\n\n"
            "N'invente pas de référence approchante : dis à l'utilisateur que "
            "la recherche ne rend rien et demande-lui de préciser."
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

    if not resultats:
        return (
            f"{bandeau}\n\nAucun résultat pour « {requete} »"
            + (f" dans {params.get('source')}" if source else "")
            + ".\n\nN'invente pas de référence : dis-le à l'utilisateur."
        )

    # Les titres sont souvent vides dans la base UE : l'aperçu est alors le
    # seul élément qui permet de choisir un article plutôt qu'un autre.
    lignes = "\n".join(
        f"  · {r.get('source')} art. {r.get('reference')}"
        + (f" — {r.get('titre')}" if r.get("titre") else "")
        + (f"\n      « {(r.get('apercu') or '').strip()[:140]}… »" if r.get("apercu") else "")
        for r in resultats
    )
    return (
        f"{bandeau}\n\n{data.get('count', len(resultats))} résultat(s) pour « {requete} » :\n"
        f"{lignes}\n\n"
        "Appelle `article_europeen` sur la référence retenue pour en obtenir le texte."
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
async def verifier_jurisprudence(reference: str, juridiction: str | None = None) -> str:
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
    """
    chemin = f"/jurisprudence/verifier/{urllib.parse.quote(reference, safe='')}"
    params = {"jurisdiction": juridiction} if juridiction else None
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
        return (
            f"{bandeau}\n\n"
            f"« {reference} » est INTROUVABLE dans l'index.\n\n"
            f"{data.get('reserve', '')}\n\n"
            "Ne présente pas cette décision comme existante. Si l'utilisateur "
            "l'a lue quelque part, dis-lui que la référence n'a pas pu être "
            "confirmée et invite-le à vérifier sa source."
        )

    decisions = data.get("decisions", [])
    lignes = "\n".join(
        f"  · {d.get('numero')} — {d.get('juridiction')}"
        # La cour distingue les rôles généraux entre eux : sans elle, deux
        # décisions portant le même numéro sont impossibles à départager. Elle
        # est préfixée du code de juridiction en base (`ca_nimes`), qu'on retire
        # pour ne pas écrire « ca ca_nimes ».
        + (
            f" {str(d.get('cour')).removeprefix(str(d.get('juridiction')) + '_')}"
            if d.get("cour") else ""
        )
        + (f"/{d.get('chambre')}" if d.get("chambre") else "")
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
    return (
        f"{bandeau}\n\n"
        f"{entete} :\n{lignes}\n\n"
        + (f"⚠️ {avert}\n\n" if avert else "")
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
    champ: str = "summary",
) -> str:
    """Cherche des décisions par thème dans la base de la Cour de cassation.

    Rend des références à confirmer ensuite avec `texte_decision`, qui seul
    donne le contenu réel.

    Args:
        requete: les mots du sujet cherché (« rupture conventionnelle »).
            Pour un numéro, utiliser `verifier_jurisprudence`.
        limite: nombre maximum de résultats (défaut 10).
        champ: où chercher. `summary` par défaut — le sommaire rédigé par la
            Cour, qui dit la portée de l'arrêt. Chercher dans le texte entier
            (`text`) pondère des mots isolés et rend des milliers de décisions
            étrangères au sujet. 🔑 `summary` ne trouve que les décisions
            pourvues d'un sommaire, c'est-à-dire les arrêts publiés : c'est ce
            qu'on veut pour « la jurisprudence sur X », pas pour retrouver une
            espèce particulière. Autres valeurs : `themes`, `motivations`,
            `dispositif`, `visa`.
        depuis: date minimale, au format « 2024-01-01 ». 🔑 À renseigner dès que
            la question porte sur l'état actuel du droit : la recherche classe
            par score, pas par date, et rend sinon des arrêts des années 1970
            en tête — qu'un revirement a pu priver de toute valeur.
        jusqu_a: date maximale, même format.
        juridiction: « cc » (Cour de cassation) ou « ca » (cours d'appel).
    """
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
        tete = (
            f"  · {r.get('number')} — {r.get('jurisdiction')}"
            + (f"/{r.get('chamber')}" if r.get("chamber") else "")
            + f", {r.get('decision_date')}"
            + (f" — {r.get('solution')}" if r.get("solution") else "")
            + (" — publié au Bulletin" if "b" in (r.get("publication") or []) else "")
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
    if couvertures and sum(couvertures) / len(couvertures) < 0.5:
        moyenne = round(100 * sum(couvertures) / len(couvertures))
        alerte = (
            f"\n\n⚠️ Ces décisions ne reprennent en moyenne que {moyenne} % des "
            "termes de la question : la recherche a probablement accroché "
            "quelques mots isolés, sans rapport avec le sujet. Vérifie chaque "
            "sommaire avant d'en citer une, et reformule si aucune ne convient."
        )

    # Deux conseils distincts, chacun pour un manque différent : la date parce
    # que le classement se fait par score et remonte des arrêts d'il y a
    # cinquante ans ; le champ parce qu'une recherche élargie au texte entier
    # ramène des décisions étrangères au sujet.
    conseils = []
    if not depuis:
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
        f"{data.get('number')} — {data.get('jurisdiction')}"
        + (f"/{data.get('chamber')}" if data.get("chamber") else "")
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
        if garde is not None:
            coupe = (
                f"\n\n[…] Moyens annexés écartés — {len(texte) - len(garde)} "
                f"caractères sur {len(texte)}. La décision est ici entière, "
                "dispositif compris. `integral=True` rend aussi les annexes."
            )
            texte = garde
        else:
            # Sans zones exploitables, la coupe à l'aveugle reste le dernier
            # recours : mieux vaut un extrait annoncé qu'un texte que le client
            # ne transmettra pas.
            coupe = (
                f"\n\n[…] ⚠️ EXTRAIT — {PLAFOND_TEXTE} des {len(texte)} "
                "caractères, la structure de la décision n'étant pas fournie. "
                "Ne conclus pas sur ce qui n'est pas affiché : le dispositif se "
                "trouve en fin de texte. Rappelle l'outil avec `integral=True` "
                "si le client peut l'encaisser, ou lis la décision sur "
                "courdecassation.fr."
            )
            texte = texte[:PLAFOND_TEXTE]

    return (
        f"{bandeau}\n\n{entete}\n{data.get('ecli', '')}\n\n{texte}{coupe}\n\n"
        "Source : Judilibre (Cour de cassation), open data. Cite la décision "
        "telle qu'elle est écrite ici, sans la reformuler en règle générale."
    )


def main() -> None:
    """Point d'entrée — transport stdio, celui qu'attend un client MCP local."""
    server.run(transport="stdio")


if __name__ == "__main__":
    main()
