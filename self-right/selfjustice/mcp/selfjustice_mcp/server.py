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
    SELFJUSTICE_API_URL         racine de l'API (défaut : instance publique)
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
from typing import Any

import httpx
from mcp.server import MCPServer

# httpx journalise chaque requête en INFO. Sur le transport stdio, stdout porte
# le protocole et stderr remonte dans les logs du client : une ligne par appel
# d'outil noie ce qui compte vraiment.
logging.getLogger("httpx").setLevel(logging.WARNING)

API_URL = os.environ.get("SELFJUSTICE_API_URL", "https://justice.my-self.fr/api").rstrip("/")
NTFY_URL = os.environ.get("SELFJUSTICE_NTFY_URL", "")
NTFY_TOKEN = os.environ.get("SELFJUSTICE_NTFY_TOKEN", "")
TIMEOUT = float(os.environ.get("SELFJUSTICE_TIMEOUT", "15"))

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
    """Convertit « 2 août 2026 » en date. Rend None si le format est autre."""
    if not texte:
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
    """Dernière échéance de synchronisation passée (cadence 1er et 15).

    🔑 Le contrôle porte sur la date du **contenu**, jamais sur celle de la
    dernière exécution. Un cron ponctuel qui reconstruit une base identique
    passe tous les contrôles d'exécution en servant du droit périmé — c'est
    exactement ce qui s'est produit pendant treize mois.
    """
    if aujourdhui.day >= 15:
        return aujourdhui.replace(day=15)
    if aujourdhui.day >= 1:
        return aujourdhui.replace(day=1)
    return aujourdhui.replace(day=1)


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

    if date_base >= echeance:
        return (f"Base {base} synchronisée au {last_update} — à jour.", False)

    retard = (aujourdhui - date_base).days
    return (
        f"⚠️ RETARD — base {base} arrêtée au {last_update}, soit {retard} jours. "
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


async def _get(chemin: str, params: dict[str, Any] | None = None) -> Any:
    url = f"{API_URL}{chemin}"
    try:
        async with httpx.AsyncClient(timeout=TIMEOUT, follow_redirects=True) as client:
            r = await client.get(url, params=params)
    except Exception as e:
        raise ApiIndisponible(f"{type(e).__name__} : {e}") from e

    if r.status_code >= 400:
        # 🔑 Un 404 se lit de deux façons, et les confondre est dangereux.
        #
        # L'API répond en JSON, y compris pour dire « article introuvable » —
        # c'est une réponse légitime dont on peut se servir. Un 404 qui rend
        # autre chose (page HTML de nginx, proxy, portail captif) signifie que
        # l'API n'est pas là où on croit : la traiter comme « cet article
        # n'existe pas » ferait affirmer au modèle l'absence d'un texte qui
        # existe peut-être. Mieux vaut avouer qu'on n'a pas pu vérifier.
        try:
            corps = r.json()
        except ValueError:
            raise ApiIndisponible(
                f"HTTP {r.status_code}, réponse non-JSON (l'API n'est "
                f"probablement pas à l'adresse {API_URL})"
            ) from None

        message = str(corps.get("error", "")) if isinstance(corps, dict) else ""
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
    cle = "legi" if base == "LEGI" else "eu"
    bloc = statut.get(cle, {})
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
    return (
        f"{etat_legi}\n{etat_eu}\n\n"
        f"Droit français (LEGI, dumps officiels DILA) : "
        f"{legi.get('articles', '?')} articles dont {legi.get('vigueur', '?')} en vigueur.\n"
        f"Conventionnalité : {eu.get('articles', '?')} articles — {sources}.\n\n"
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
    except ApiIndisponible as e:
        return _msg_api_morte(str(e))

    bandeau = await _bandeau("LEGI")

    if data.get("__introuvable__"):
        return (
            f"{bandeau}\n\nArticle « {reference} » introuvable"
            + (f" dans le code « {code} »" if code else "")
            + ".\n\nNe substitue pas un article de mémoire. Vérifie la référence "
            "avec `rechercher_droit_francais`, ou dis à l'utilisateur que la "
            "référence qu'il donne n'existe pas telle quelle."
        )

    # Numéro porté par plusieurs codes : l'API rend les alternatives, pas un
    # texte. Rendre l'un des deux au hasard serait la pire réponse possible —
    # L1152-1 existe au code du travail et au code de la défense.
    if data.get("ambiguous"):
        lignes = "\n".join(
            f"  · code={a.get('code_id')} — « {(a.get('apercu') or '').strip()[:120]}… »"
            for a in data.get("alternatives", [])
        )
        return (
            f"{bandeau}\n\nL'article {reference} existe dans plusieurs codes. "
            "Rappelle `article_francais` avec l'argument `code` (alias en clair "
            "acceptés : travail, civil, penal, consommation, sante_publique, "
            f"assurances, urbanisme…) :\n{lignes}"
        )

    src = data.get("source", {})
    vigueur = (
        "EN VIGUEUR"
        if data.get("en_vigueur")
        else f"⚠️ PAS EN VIGUEUR (état : {data.get('etat', 'inconnu')})"
    )
    return (
        f"{bandeau}\n\n"
        f"{data.get('code_titre') or data.get('code_id', '')} — article {data.get('reference', reference)}\n"
        f"{vigueur} · en vigueur depuis le {data.get('date_debut', '?')}\n\n"
        f"{data.get('texte', '(texte absent de la base)')}\n\n"
        f"Source : {src.get('origine', 'LEGI')} — base au {src.get('last_update', '?')}\n"
        f"Légifrance : {src.get('legifrance_url', '(lien indisponible)')}"
    )


@server.tool()
async def rechercher_droit_francais(requete: str, limite: int = 20) -> str:
    """Cherche des références d'articles français par numéro ou fragment.

    Rend une liste de références à confirmer ensuite avec `article_francais` —
    la recherche ne rend pas les textes, seulement les articles candidats.

    Args:
        requete: fragment de numéro, ex. « L1152 », « 1240 ».
        limite: nombre maximum de résultats (défaut 20).
    """
    try:
        data = await _get("/legi/search", {"q": requete, "limit": limite})
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

    lignes = "\n".join(
        f"  · {r.get('reference')} — {r.get('code_titre') or r.get('code_id')} "
        f"[{r.get('etat')}, depuis {r.get('date_debut')}]"
        for r in resultats
    )
    return (
        f"{bandeau}\n\n{data.get('count', len(resultats))} résultat(s) pour « {requete} » :\n"
        f"{lignes}\n\n"
        "Appelle `article_francais` sur la référence retenue pour en obtenir le texte."
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


def main() -> None:
    """Point d'entrée — transport stdio, celui qu'attend un client MCP local."""
    server.run(transport="stdio")


if __name__ == "__main__":
    main()
