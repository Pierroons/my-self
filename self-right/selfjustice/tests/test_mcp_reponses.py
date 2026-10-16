#!/usr/bin/env python3
"""Garde-fou — ce que le client MCP répond quand l'instance ne rend pas un résultat ordinaire.

🔑 Pourquoi ce banc existe. Chaque réponse du client est une consigne pour le
modèle : « aucune décision ne correspond » lui fait affirmer une absence de
jurisprudence, « API injoignable » l'envoie chercher une panne, et un terme
déclaré « absent de l'index » devient un constat sur le droit. Ces consignes
sortent de corps que l'instance rend réellement — un 503 `etat: indeterminee`
quand Judilibre n'a pas été interrogé, une `reserve` sur une combinaison qui ne
peut rien rendre, une liste de gabarits vide, une page d'erreur qui n'est pas du
JSON. Aucune ne se voit en relisant une fonction seule : il faut le corps HTTP,
le trajet par `_get`, et le texte rendu au bout.

Le banc sert donc ces corps depuis une fausse instance locale — un serveur HTTP
dans un thread, une réponse scriptée par chemin, un journal de chaque requête
reçue — et appelle les VRAIS outils du client, chargé depuis l'arbre courant. Le
journal sert aux sondes de termes et à la juridiction : ce que le client
transmet ne se lit pas dans le texte rendu, seulement dans ce que l'instance a
reçu.

Aucune requête ne sort de la machine.

Usage : python3 tests/test_mcp_reponses.py
Sortie : 0 si tout tient, 1 dès qu'un contrôle est en défaut, 2 si un prérequis manque.
"""

import asyncio
import datetime as dt
import http.server
import json
import os
import pathlib
import sys
import threading
import urllib.parse

# L'équivalent de PYTHONDONTWRITEBYTECODE : le client s'importe depuis l'arbre,
# et un banc ne laisse pas de __pycache__ dans le code qu'il éprouve.
sys.dont_write_bytecode = True

ICI = pathlib.Path(__file__).resolve().parent
MCP = ICI.parent / "mcp"

RECHERCHE = "/api/jurisprudence/search"
GABARITS = "/act/api/gabarits.php"
ARTICLE = "/api/legi/article/1240"
VERIFIER = "/api/jurisprudence/verifier/25-10.377"

# Quatre mots utiles, un seul surligné par les résultats : la couverture tombe
# sous la moitié et le client sonde les trois autres, un par un.
REQUETE = "licenciement banane saxophone tracteur"
JAMAIS_REPRIS = ("banane", "saxophone", "tracteur")

# Les corps de l'API, tels que `json_indetermine` et `champ_juris` les écrivent.
CLE_ABSENTE = ("Clé Judilibre absente de cette instance : la recherche par thème et "
               "le texte intégral sont indisponibles. La vérification d'une "
               "référence dans l'index local reste possible.")
AMONT_INJOIGNABLE = ("API Judilibre injoignable : Operation timed out after 15001 "
                     "milliseconds with 0 bytes received")
RESERVE_CA = ("Les décisions de cour d'appel n'ont pas de sommaire : cette combinaison "
              "ne peut rien rendre. Rappeler avec champ=text pour chercher dans le "
              "texte intégral.")
PAGE_404 = "<html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>"
PAGE_502 = "<html><head><title>502 Bad Gateway</title></head><body><h1>Bad Gateway</h1></body></html>"


def indeterminee(raison: str) -> tuple[int, dict]:
    return 503, {"etat": "indeterminee", "error": raison, "raison": raison}


def trouvee(total: int, resultats: list | None = None, reserve: str | None = None) -> tuple[int, dict]:
    return 200, {"etat": "trouvee", "total": total, "results": resultats or [],
                 "reserve": reserve, "source": "banc"}


def decision(n: int, surligne: str) -> dict:
    return {
        "id": f"banc{n:04d}", "number": f"2{n}-10.00{n}", "jurisdiction": "cc",
        "chamber": "soc", "decision_date": "2024-03-12", "solution": "Rejet",
        "publication": ["b"], "summary": f"Sommaire de banc numéro {n}.",
        "highlights": {"summary": [f"… le <em>{surligne}</em> du salarié …"]},
    }


def recherche_et_sondes(sondes: dict[str, tuple[int, object]]):
    """La recherche rend deux décisions qui ne surlignent que « licenciement » ;
    chaque sonde (`limit=1`) rend ce que `sondes` prévoit pour son terme, et
    douze décisions sinon."""
    def repondre(params: dict[str, str]) -> tuple[int, object]:
        if params.get("limit") == "1":
            return sondes.get(params.get("q", ""), trouvee(12))
        return trouvee(2, [decision(1, "licenciement"), decision(2, "licenciement")])
    return repondre


def statut_sain() -> dict:
    jour = dt.date.today().isoformat()
    return {"jurisprudence": {"last_update": jour, "last_sync": jour, "decisions": 2}}


# ------------------------------------------------------------ fausse instance

JOURNAL: list[tuple[str, dict[str, str]]] = []
ROUTES: dict[str, object] = {}


class Guichet(http.server.BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        url = urllib.parse.urlsplit(self.path)
        params = dict(urllib.parse.parse_qsl(url.query, keep_blank_values=True))
        JOURNAL.append((url.path, params))
        if url.path in ROUTES:
            reponse = ROUTES[url.path]
        elif url.path == "/api/status":
            reponse = (200, statut_sain())
        else:
            reponse = (404, {"error": "route non scriptée par le banc"})
        if callable(reponse):
            reponse = reponse(params)
        code, corps = reponse
        if isinstance(corps, str):
            donnees, type_ = corps.encode(), "text/html; charset=utf-8"
        else:
            donnees, type_ = json.dumps(corps, ensure_ascii=False).encode(), "application/json"
        self.send_response(code)
        self.send_header("Content-Type", type_)
        self.send_header("Content-Length", str(len(donnees)))
        self.end_headers()
        self.wfile.write(donnees)

    def log_message(self, *_: object) -> None:
        pass


ROUGES: list[str] = []
CONTROLES = 0


def verifier(etiquette: str, ok: bool, detail: str = "") -> None:
    global CONTROLES
    CONTROLES += 1
    print(f"  {'✓' if ok else '✗'} {etiquette}")
    if not ok:
        ROUGES.append(etiquette)
        if detail:
            print("      " + detail.replace("\n", "\n      "))


def main() -> int:
    instance = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Guichet)
    adresse = f"127.0.0.1:{instance.server_address[1]}"
    threading.Thread(target=instance.serve_forever, daemon=True).start()

    # Fixées AVANT l'import : le client lit son environnement une seule fois.
    os.environ["SELFRIGHT_API_URL"] = f"http://{adresse}/api"
    os.environ["SELFRIGHT_ACT_URL"] = f"http://{adresse}/act/api"
    for variable in ("SELFRIGHT_NTFY_URL", "SELFRIGHT_NTFY_TOKEN"):
        os.environ.pop(variable, None)

    sys.path.insert(0, str(MCP))
    try:
        from selfright_mcp import server as client
    except ImportError as e:
        print(f"✗ le client ne s'importe pas ({e}) : pip install mcp httpx", file=sys.stderr)
        return 2
    # ⚠️ Un `selfright-mcp` installé dans l'environnement passerait pour celui de
    # l'arbre : le banc éprouverait alors un code que personne n'a écrit ici.
    if pathlib.Path(client.__file__).resolve().parent != (MCP / "selfright_mcp").resolve():
        print(f"✗ client importé depuis {client.__file__}, pas depuis {MCP}", file=sys.stderr)
        return 2

    # Les alertes à l'exploitant s'enregistrent au lieu de partir : une alerte
    # déclenchée à tort est un défaut que le texte rendu ne montre pas.
    alertes: list[str] = []

    async def enregistrer_alerte(titre: str, message: str, cle: str) -> None:
        alertes.append(f"{cle} : {titre}")

    client._alerter = enregistrer_alerte

    def jouer(routes: dict[str, object], outil, *args, **kwargs) -> str:
        JOURNAL.clear()
        alertes.clear()
        ROUTES.clear()
        ROUTES.update(routes)
        # `/status` est mis en cache : chaque cas sert le sien.
        client._statut_cache = None
        return asyncio.run(outil(*args, **kwargs))

    def sondes_recues() -> list[dict[str, str]]:
        return [p for chemin, p in JOURNAL if chemin == RECHERCHE and p.get("limit") == "1"]

    def cas(titre: str):
        """Joue le cas dès sa déclaration. Un cas qui lève compte comme un rouge
        et n'arrête pas les suivants."""
        def decorer(fonction):
            print(f"\n▸ {titre}")
            try:
                fonction()
            except Exception as e:
                verifier("le cas s'exécute jusqu'au bout", False, f"{type(e).__name__} : {e}")
            return fonction
        return decorer

    print(f"Fausse instance sur {adresse} — client chargé depuis {MCP}")

    @cas("J2 — une recherche qui n'a pas eu lieu ne se dit pas vide")
    def _():
        for declencheur, raison in (("clé Judilibre absente", CLE_ABSENTE),
                                    ("amont injoignable", AMONT_INJOIGNABLE)):
            texte = jouer({RECHERCHE: indeterminee(raison)},
                          client.rechercher_jurisprudence, "bail commercial")
            ok = ("Vérification de jurisprudence impossible" in texte
                  and raison in texte and "Aucune décision ne correspond" not in texte)
            verifier(f"{declencheur} : « vérification impossible », la raison relayée", ok, texte)

    @cas("J2 — une recherche vide relaie la réserve de l'index")
    def _():
        texte = jouer({RECHERCHE: trouvee(0, reserve=RESERVE_CA)},
                      client.rechercher_jurisprudence, "bail commercial",
                      juridiction="ca", champ="summary")
        verifier("« L'index précise : » suivi de la réserve",
                 f"L'index précise : {RESERVE_CA}" in texte, texte)

    @cas("J3 — les sondes de termes cherchent dans le périmètre de la recherche")
    def _():
        texte = jouer({RECHERCHE: recherche_et_sondes({"banane": trouvee(0)})},
                      client.rechercher_jurisprudence, REQUETE, juridiction="cc",
                      champ="text", depuis="2020-01-01", jusqu_a="2024-12-31")
        attendu = {"champ": "text", "jurisdiction": "cc",
                   "date_start": "2020-01-01", "date_end": "2024-12-31"}
        recues = sondes_recues()
        ecarts = [p for p in recues if any(p.get(k) != v for k, v in attendu.items())]
        verifier(f"chaque sonde porte champ, juridiction et dates ({len(recues)} reçue(s))",
                 bool(recues) and not ecarts, f"sondes reçues : {recues}")
        verifier("l'absence se dit bornée au périmètre de la recherche",
                 "« banane » n'apparaît dans AUCUNE décision, sur le périmètre de cette "
                 "recherche" in texte, texte)

    @cas("J3 — une sonde qui n'a pas mesuré arrête le compte")
    def _():
        texte = jouer({RECHERCHE: recherche_et_sondes({
                          "banane": trouvee(0),
                          "saxophone": indeterminee(AMONT_INJOIGNABLE)})},
                      client.rechercher_jurisprudence, REQUETE)
        verifier("indéterminée : l'absence mesurée reste, les deux suivants non vérifiés",
                 "« banane » n'apparaît" in texte
                 and "(2 autre(s) terme(s) non vérifié(s)" in texte, texte)
        for nom, reponse in (("introuvable", (404, {"error": "Route absente de cette instance"})),
                             ("refusée", (400, {"error": "Champ de recherche inconnu"}))):
            texte = jouer({RECHERCHE: recherche_et_sondes(dict.fromkeys(JAMAIS_REPRIS, reponse))},
                          client.rechercher_jurisprudence, REQUETE)
            verifier(f"{nom} : aucun terme déclaré absent, les trois non vérifiés",
                     "n'apparaît" not in texte
                     and "(3 autre(s) terme(s) non vérifié(s)" in texte, texte)

    @cas("J3 — sur les sommaires, l'absence se dit « dans aucun sommaire »")
    def _():
        texte = jouer({RECHERCHE: recherche_et_sondes({"tracteur": trouvee(0)})},
                      client.rechercher_jurisprudence, REQUETE)
        verifier("« dans AUCUN sommaire », jamais « dans AUCUNE décision »",
                 "« tracteur » n'apparaît dans AUCUN sommaire" in texte
                 and "AUCUNE décision" not in texte, texte)
        texte = jouer({RECHERCHE: recherche_et_sondes({"banane": trouvee(0),
                                                       "tracteur": trouvee(0)})},
                      client.rechercher_jurisprudence, REQUETE)
        verifier("deux termes absents : la phrase s'accorde, sans « l'emploient »",
                 "« banane », « tracteur » n'apparaissent dans AUCUN sommaire" in texte
                 and "n'emploient pas ce vocabulaire dans leur sommaire" in texte
                 and "l'emploient" not in texte, texte)

    @cas("J3 — sur un autre champ, l'absence se dit dans CE champ")
    def _():
        texte = jouer({RECHERCHE: recherche_et_sondes({"banane": trouvee(0)})},
                      client.rechercher_jurisprudence, REQUETE, champ="themes")
        verifier("« dans le champ « themes » d'AUCUNE décision », jamais « dans AUCUNE décision »",
                 "« banane » n'apparaît dans le champ « themes » d'AUCUNE décision" in texte
                 and "n'apparaît dans AUCUNE décision" not in texte, texte)

    @cas("J6 — « CA » en capitales et entouré d'espaces cherche dans le texte intégral")
    def _():
        jouer({RECHERCHE: trouvee(0)}, client.rechercher_jurisprudence,
              "bail commercial", juridiction=" CA ")
        envoyees = [p for chemin, p in JOURNAL if chemin == RECHERCHE]
        verifier("la recherche part avec champ=text et jurisdiction=ca",
                 len(envoyees) == 1 and envoyees[0].get("champ") == "text"
                 and envoyees[0].get("jurisdiction") == "ca", f"requêtes reçues : {envoyees}")

    @cas("D6 — une instance sans gabarit répond, elle n'est pas en panne")
    def _():
        texte = jouer({GABARITS: (200, {"gabarits": {}})}, client.gabarit_document)
        verifier("« ne déclare aucun gabarit … Ce n'est pas une panne », sans « injoignable »",
                 "ne déclare aucun gabarit" in texte and "Ce n'est pas une panne" in texte
                 and "injoignable" not in texte, texte)

    @cas("D6 — une route des gabarits absente ne se dit pas « aucun gabarit »")
    def _():
        texte = jouer({GABARITS: (404, {"ok": False, "error": "route inconnue"})},
                      client.gabarit_document)
        verifier("404 JSON : la route manque, ce n'est ni « aucun gabarit » ni « pas une panne »",
                 "ne sert pas la route des gabarits" in texte
                 and "aucun gabarit" not in texte
                 and "Ce n'est pas une panne" not in texte, texte)

    @cas("verifier_jurisprudence — un refus de l'index désigne l'argument fautif")
    def _():
        refus = ("Juridiction « cx » inconnue. Valeurs acceptées : cc (Cour de "
                 "cassation), ca (cours d'appel).")
        texte = jouer({VERIFIER: (400, {"error": refus})},
                      client.verifier_jurisprudence, "25-10.377", juridiction="cx")
        verifier("400 sur la juridiction : « corrige l'argument que l'index désigne »",
                 refus in texte and "corrige l'argument que l'index désigne" in texte
                 and "reprends le numéro" not in texte, texte)

    @cas("T5 — une réponse non-JSON nomme la variable, jamais l'adresse")
    def _():
        texte = jouer({ARTICLE: (404, PAGE_404)}, client.article_francais, "1240", code="civil")
        verifier("4xx sur l'API : SELFRIGHT_API_URL nommée, son adresse tue",
                 "pas à l'adresse que donne SELFRIGHT_API_URL" in texte
                 and adresse not in texte, texte)
        texte = jouer({GABARITS: (404, PAGE_404)}, client.gabarit_document)
        verifier("4xx sur les démarches : SELFRIGHT_ACT_URL nommée, aucune adresse",
                 "pas à l'adresse que donne SELFRIGHT_ACT_URL" in texte
                 and adresse not in texte, texte)
        texte = jouer({"/api/status": (200, {"name": "une autre API", "version": "1.0"})},
                      client.statut)
        verifier("`statut` sur un /status sans blocs : l'adresse tue",
                 "sans les blocs attendus" in texte and adresse not in texte, texte)

    @cas("statut — une base à null se dit absente ou illisible, et l'exploitant est alerté")
    def _():
        texte = jouer({"/api/status": (200, {"legi": None, "eu": None,
                                             **statut_sain()})}, client.statut)
        verifier("legi et eu à null : la ligne de jurisprudence est rendue",
                 "pas une instance SelfJustice" not in texte
                 and "Jurisprudence : 2 décisions" in texte, texte)
        intrus = [m for m in ("? articles", "annonce « rien »", "indéterminée") if m in texte]
        verifier("les deux bases se disent « absente ou illisible », sans volumétrie ni fraîcheur",
                 "Base LEGI absente ou illisible sur cette instance" in texte
                 and "Base conventionnalité absente ou illisible sur cette instance" in texte
                 and not intrus, f"présents : {intrus}\n{texte}")
        # 🔑 L'API rend `null` pour une base absente comme pour une base devenue
        # illisible : se taire ménagerait la première et avalerait la seconde.
        attendues = ["eu : SelfJustice — base conventionnalité : absente ou illisible",
                     "legi : SelfJustice — base LEGI : absente ou illisible"]
        verifier("une alerte par base nulle, et aucune autre", sorted(alertes) == attendues,
                 f"alertes : {alertes}")

    @cas("statut — trois bases à null : l'instance répond, aucune base ne se lit")
    def _():
        texte = jouer({"/api/status": (200, {"legi": None, "eu": None, "jurisprudence": None})},
                      client.statut)
        verifier("« aucune de ses bases ne se lit », pas « pas une instance SelfJustice »",
                 "aucune de ses bases ne se lit" in texte
                 and "pas une instance SelfJustice" not in texte, texte)
        verifier("une alerte « aucune base lisible »",
                 alertes == ["statut : SelfJustice — aucune base lisible"], f"alertes : {alertes}")

    @cas("statut — sans aucune clé de base, ce n'est pas une instance SelfJustice")
    def _():
        texte = jouer({"/api/status": (200, {"name": "une autre API", "version": "1.0"})},
                      client.statut)
        verifier("« pas une instance SelfJustice », pas « aucune de ses bases »",
                 "pas une instance SelfJustice" in texte
                 and "aucune de ses bases" not in texte, texte)

    @cas("T5 — un 5xx non-JSON se dit erreur interne, pas mauvaise adresse")
    def _():
        texte = jouer({ARTICLE: (502, PAGE_502)}, client.article_francais, "1240", code="civil")
        verifier("« erreur interne », sans « pas à l'adresse » ni l'adresse",
                 "l'instance a rencontré une erreur interne" in texte
                 and "pas à l'adresse" not in texte and adresse not in texte, texte)

    @cas("T5 — une erreur de transport ne rend ni l'adresse ni le nom d'hôte")
    def _():
        import httpx
        hote = urllib.parse.urlsplit(client.API_URL).hostname
        vrai = httpx.AsyncClient.get

        async def faux(self, url, *args, **kwargs):
            raise httpx.ConnectError(
                f"[SSL: CERTIFICATE_VERIFY_FAILED] hostname '{hote}' doesn't match, for {url}")

        httpx.AsyncClient.get = faux
        try:
            texte = jouer({}, client.article_francais, "1240", code="civil")
        finally:
            httpx.AsyncClient.get = vrai
        verifier("ni l'URL ni le nom d'hôte, la variable nommée à leur place",
                 client.API_URL not in texte and hote not in texte and "<instance>" in texte,
                 texte[:240])

    @cas("Une base de jurisprudence nulle alerte, comme les deux autres")
    def _():
        jour = dt.date.today().isoformat()
        bloc = {"last_update": jour, "last_sync": jour, "articles": 1, "vigueur": 1}
        texte = jouer({"/api/status": (200, {"legi": bloc, "eu": dict(bloc, sources={}),
                                             "jurisprudence": None})}, client.statut)
        verifier("« index absent », et l'alerte « absente ou illisible »",
                 "Index de jurisprudence absent" in texte
                 and any("jurisprudence : absente ou illisible" in a for a in alertes),
                 f"alertes : {alertes}")

    instance.shutdown()
    instance.server_close()

    print()
    if ROUGES:
        print(f"✗ {len(ROUGES)} contrôle(s) en défaut sur {CONTROLES}")
        return 1
    print(f"✓ {CONTROLES} contrôles — le client dit ce que l'instance a réellement répondu")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
