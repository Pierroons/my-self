#!/usr/bin/env python3
"""Garde-fou — un article se dit applicable selon sa période, pas selon son seul état.

🔑 Pourquoi ce banc existe. Jusqu'au 14/10/2026, l'API rendait
`'en_vigueur' => $row['etat'] === 'VIGUEUR'` : les dates étaient servies, jamais
comparées. Mesuré le 12/10 sur la base servie, 1 828 339 articles :

    etat = 'VIGUEUR'                       683 080 tenus pour en vigueur
    période + trois états vivants          693 141

Soit environ dix mille articles applicables annoncés « PLUS EN VIGUEUR » —
l'article 8 du code de procédure pénale, applicable jusqu'au 1er janvier 2029,
en tête. Et plus grave que le libellé : le sélecteur de version prenait
`ORDER BY date_debut DESC`, qui sert la version FUTURE de l'article 10 du même
code, et la ligne sentinelle `2999-01-01` d'un arrêté à la place du texte en
vigueur.

L'état LEGI nomme le dernier événement subi par une version, pas son
applicabilité ; Légifrance ne l'affiche d'ailleurs pas, elle affiche une fenêtre
de dates. Trois états seulement désignent une position dans le cycle de vie —
VIGUEUR, VIGUEUR_DIFF, ABROGE_DIFF — et ce sont les trois qui n'ont pas de
contrepartie dans la table des liens de `legi.py`.

Le jeu de données est EXTRAIT de la base servie (lignes réelles, textes
intégraux), comme celui de `test_renvoi_article.sh` : un cas écrit à la main
prouverait que le code marche sur ce qu'on a imaginé, pas sur ce que LEGI
contient.

🔑 La date est FIGÉE. L'article 8 cesse d'être applicable le 1er janvier 2029,
L229-61-1 du code de l'environnement le devient le 1er janvier 2027 : un banc
adossé au jour courant rougirait tout seul à ces dates, sans qu'aucun défaut ne
soit revenu. L'API lit le jour dans `SELFJUSTICE_JOUR`, que ce banc fixe.

Six volets, les mêmes lignes extraites :
  1. l'API — `en_vigueur`, `applicabilite` et la version servie ;
  2. la recherche — chaque résultat porte `en_vigueur` ;
  3. `/status` — son compte « en vigueur », que des badges publics affichent,
     et sa simple réponse, dont dépend le bandeau de chaque réponse du MCP ;
  4. les listes d'états — PHP et Python disent la même ;
  5. l'outil d'audit — il doit rendre le même verdict que l'API : la règle y
     est recopiée, et une règle recopiée diverge en silence tant que rien ne
     la confronte à sa source ;
  6. le client MCP — le libellé rendu, qui ne dit plus jamais `(état : None)`.

Usage : python3 tests/test_applicabilite.py
Sortie : 0 si tout concorde, 1 sinon, 2 si un prérequis manque.
"""

import asyncio
import json
import pathlib
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ICI = pathlib.Path(__file__).resolve().parent
RACINE = ICI.parent
JEU = ICI / "legi-jeu-applicabilite.json"
JOUR = "2026-10-14"

CPP = "LEGITEXT000006071154"
CIVIL = "LEGITEXT000006070721"
ENVIRONNEMENT = "LEGITEXT000006074220"

# (étiquette, num, code, en_vigueur, applicabilite, version servie attendue)
# La version servie n'est nommée que là où le choix est en jeu : plusieurs
# versions, et un `ORDER BY date_debut DESC` qui se trompe.
CAS = [
    ("CPP art. 8 — abrogation différée, applicable jusqu'en 2029",
     "8", CPP, True, "en_vigueur", "LEGIARTI000049531911"),
    ("CPP art. 10 — la version de 2019 s'applique, celle de 2027 pas encore",
     "10", CPP, True, "en_vigueur", "LEGIARTI000038311450"),
    ("arrêté amiante art. 13 — la version de 2026, pas la ligne sentinelle",
     "13", "JORFTEXT000026308174", True, "en_vigueur", "LEGIARTI000054173807"),
    ("env. L229-61-1 — VIGUEUR, mais à compter du 1er janvier 2027",
     "L229-61-1", ENVIRONNEMENT, False, "pas_encore", None),
    ("un PERIME — la sentinelle 2999 n'en fait pas une perpétuité",
     "1", "JORFTEXT000000746851", False, "plus_en_vigueur", None),
    ("un ANNULE — idem, annulé par le juge",
     "1", "JORFTEXT000039701920", False, "plus_en_vigueur", None),
    ("un état NULL — ni vivant ni mort : non déclaré",
     "3", "JORFTEXT000000728893", False, "sans_etat", None),
    # Une version sans état DANS sa période : sa colonne `applicable` vaut NULL,
    # et un `ORDER BY applicable DESC` la rangeait SOUS les versions mortes —
    # l'abrogée de 2017 était servie à la place de la version de 2023.
    ("art. 41 d'un texte de 2014 — sa version de 2023, pas l'abrogée de 2017",
     "41", "JORFTEXT000028613875", False, "sans_etat", "LEGIARTI000047944853"),
    # Une version close face à une sentinelle 2999 → 2999 : la version datée
    # est la seule qui dise jusqu'à quand le texte s'appliquait.
    ("art. 135 d'un texte de 2008 — l'abrogée de 2022, pas la sentinelle",
     "135", "JORFTEXT000019995721", False, "plus_en_vigueur", "LEGIARTI000025074899"),
    ("contrôle : civil 1240, VIGUEUR depuis 2016",
     "1240", CIVIL, True, "en_vigueur", "LEGIARTI000032041571"),
    ("contrôle : un ABROGE clos en 2017",
     "1", "JORFTEXT000000549593", False, "plus_en_vigueur", None),
]

# Les numéros sans homonyme dans le jeu : `audit_articles.py` cherche par numéro
# SANS code, il ne peut donc départager les « 1 » de plusieurs textes.
NUMEROS_AUDITABLES = {"8", "10", "13", "L229-61-1", "1240", "3", "41", "135"}

ROUGES: list[str] = []


def verifier(etiquette: str, ok: bool, detail: str = "") -> None:
    print(f"  {'✓' if ok else '✗'} {etiquette}" + ("" if ok else f"\n      {detail}"))
    if not ok:
        ROUGES.append(etiquette)


def batir_base(chemin: pathlib.Path) -> None:
    """Le schéma de `test_renvoi_article.sh`, alimenté par les lignes extraites.

    L'index plein texte est construit par les instructions MÊMES de
    `extract_selfjustice_db.py`, importées et non recopiées : c'est là que se
    décide quels articles une recherche par mot peut trouver, et une copie à
    côté ne verrait pas la source changer."""
    lignes = json.loads(JEU.read_text(encoding="utf-8"))
    c = sqlite3.connect(chemin)
    c.execute("CREATE TABLE articles (id TEXT PRIMARY KEY, num TEXT, etat TEXT, "
              "date_debut TEXT, date_fin TEXT, code_id TEXT, code_titre TEXT, texte TEXT)")
    c.execute("CREATE INDEX idx_num ON articles(num)")
    c.executemany("INSERT INTO articles VALUES (?,?,?,?,?,?,?,?)",
                  [tuple(l[k] for k in ("id", "num", "etat", "date_debut", "date_fin",
                                        "code_id", "code_titre", "texte"))
                   for l in lignes])
    sys.path.insert(0, str(RACINE / "tools"))
    import extract_selfjustice_db
    for instruction in extract_selfjustice_db.FTS:
        c.execute(instruction)
    c.commit()
    c.close()


def port_libre() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def lancer_api(base: pathlib.Path) -> tuple[subprocess.Popen, str]:
    api = RACINE / "api"
    # 🔑 Les bases UE et jurisprudence sont ABSENTES, exprès : c'est l'instance
    # qu'on monte base par base. Une seule base manquante faisait tomber /status
    # en entier — `open_db()` termine la requête, le `try` de chaque bloc ne le
    # voyait jamais —, et le bandeau de chaque réponse du MCP s'éteignait avec
    # lui. Le volet /status le mesure.
    absentes = {nom: str(base.parent / f"{nom}-absente.sqlite") for nom in ("eu", "juris")}
    env = {"PATH": "/usr/bin:/bin", "SELFJUSTICE_LEGI_DB": str(base), "SELFJUSTICE_JOUR": JOUR,
           "SELFJUSTICE_EU_DB": absentes["eu"], "SELFJUSTICE_JURIS_DB": absentes["juris"]}
    for _ in range(3):
        port = port_libre()
        php = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(api), str(api / "api.php")],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env)
        url = f"http://127.0.0.1:{port}"
        for _ in range(40):
            try:
                urllib.request.urlopen(f"{url}/api/legi/article/1240?code={CIVIL}", timeout=2)
                return php, url
            except (urllib.error.URLError, OSError):
                time.sleep(0.1)
        php.terminate()
    print("✗ le serveur PHP n'a pas démarré", file=sys.stderr)
    raise SystemExit(2)


def lire(url: str) -> dict:
    try:
        with urllib.request.urlopen(url, timeout=10) as r:
            return json.loads(r.read())
    except urllib.error.HTTPError as e:
        return json.loads(e.read() or b"{}")


def version_servie(reponse: dict) -> str:
    m = re.search(r"(LEGIARTI\d{12})", (reponse.get("source") or {}).get("legifrance_url", ""))
    return m.group(1) if m else "?"


def volet_api(url: str) -> None:
    print("▸ L'API — l'applicabilité se lit sur la période, la version servie aussi")
    for etiquette, num, code, vigueur, applicabilite, version in CAS:
        r = lire(f"{url}/api/legi/article/{urllib.parse.quote(num)}?code={code}")
        servie = version_servie(r)
        defauts = []
        if r.get("en_vigueur") is not vigueur:
            defauts.append(f"en_vigueur={r.get('en_vigueur')!r}, attendu {vigueur!r}")
        if r.get("applicabilite") != applicabilite:
            defauts.append(f"applicabilite={r.get('applicabilite')!r}, attendu {applicabilite!r}")
        if version and servie != version:
            defauts.append(f"version servie {servie} ({r.get('etat')}, {r.get('date_debut')}), "
                           f"attendu {version}")
        verifier(etiquette, not defauts, " · ".join(defauts))


def resultats_de(url: str, requete: str) -> list[dict]:
    r = lire(f"{url}/api/legi/search?q={urllib.parse.quote(requete)}&limit=20")
    return r.get("results") or r.get("resultats") or []


def volet_recherche(url: str) -> None:
    print("▸ La recherche — un article applicable se trouve, et chaque résultat dit ce qu'il est")
    # Par un mot de son texte. L'index plein texte ne retenait que l'état VIGUEUR :
    # un article en abrogation ou en entrée en vigueur différée était introuvable
    # par son contenu. Chacun de ces deux mots n'est, dans ce jeu, que dans une
    # seule version — « révolues » dans l'art. 8 du CPP (ABROGE_DIFF),
    # « empoussièrement » dans la version de 2026 de l'arrêté amiante
    # (VIGUEUR_DIFF) : retirer l'un des deux états de l'index fait rougir sa ligne.
    for mot, num, code, etat in (("révolues", "8", CPP, "ABROGE_DIFF"),
                                 ("empoussièrement", "13", "JORFTEXT000026308174", "VIGUEUR_DIFF")):
        trouves = [x for x in resultats_de(url, mot)
                   if x.get("reference") == num and x.get("code_id") == code]
        verifier(f"« {mot} » trouve la version {etat}, et la dit applicable",
                 bool(trouves) and trouves[0].get("applicabilite") == "en_vigueur",
                 f"rendu : {trouves[:1] or 'introuvable par son texte'}")

    # Une version SANS état, hors sentinelle, que l'API sert comme version courante
    # de l'art. 41 : « ministérielle » n'est que dans son texte. L'index ne
    # prenait que les états vivants, et l'article servi était introuvable par ses
    # propres mots.
    trouves = [x for x in resultats_de(url, "ministérielle")
               if x.get("reference") == "41" and x.get("code_id") == "JORFTEXT000028613875"]
    verifier("« ministérielle » trouve la version sans état que l'API sert pour l'art. 41",
             bool(trouves) and trouves[0].get("applicabilite") == "sans_etat",
             f"rendu : {trouves[:1] or 'introuvable par son texte'}")

    # Deux versions d'un même article répondent au même mot, et la morte — trente-six
    # caractères, « a modifié les dispositions suivantes » — passe devant au score.
    # L'art. 1 du décret de 1992 doit ressortir sous sa version applicable.
    trouves = [x for x in resultats_de(url, "dispositions")
               if x.get("reference") == "1" and x.get("code_id") == "JORFTEXT000000724377"]
    verifier("« dispositions » rend l'art. 1 de 1992 sous sa version applicable, pas sous la morte",
             bool(trouves) and trouves[0].get("applicabilite") == "en_vigueur",
             f"rendu : {trouves[:1] or 'absent'}")

    # Même article, une fenêtre d'un seul résultat : la version applicable n'y
    # est pas, elle se cherche par son numéro.
    r = lire(f"{url}/api/legi/search?q={urllib.parse.quote('modifié dispositions suivantes')}&limit=1")
    tete = (r.get("results") or r.get("resultats") or [{}])[0]
    verifier("fenêtre d'un résultat : l'art. 1 de 1992 ressort sous sa version applicable",
             tete.get("reference") == "1" and tete.get("code_id") == "JORFTEXT000000724377"
             and tete.get("applicabilite") == "en_vigueur", f"rendu : {tete}")

    # Une référence coupée par un « / » non encodé ne sert pas son début.
    try:
        urllib.request.urlopen(f"{url}/api/legi/article/8/2?code={CPP}", timeout=5)
        code = 200
    except urllib.error.HTTPError as e:
        code = e.code
    verifier("« 8/2 » non encodé, à l'API → 400, pas l'article 8", code == 400, f"HTTP {code}")

    # Par numéro : chaque résultat porte `en_vigueur` ET `applicabilite`. Le
    # booléen seul ne distingue pas un texte qui ne s'applique plus d'un texte qui
    # ne s'applique pas encore, et le client rangeait le second parmi les morts.
    for requete, applicabilite in (("1240", "en_vigueur"), ("L229-61-1", "pas_encore")):
        resultats = resultats_de(url, requete)
        sans = [x.get("reference") for x in resultats
                if "en_vigueur" not in x or "applicabilite" not in x]
        cible = [x for x in resultats if x.get("reference") == requete]
        verifier(f"« {requete} » — chaque résultat porte les deux champs, "
                 f"applicabilite = {applicabilite}",
                 bool(cible) and not sans and cible[0].get("applicabilite") == applicabilite,
                 f"sans les champs : {sans} · cible : {cible[:1]}")

    # L'ancien droit, trié `date_fin DESC` puis dédoublonné par numéro et code :
    # une sentinelle 2999 → 2999 sans état passait devant la version abrogée et la
    # masquait — la seule ligne qui dise jusqu'à quand le texte s'appliquait.
    cible = [x for x in resultats_de(url, "135")
             if x.get("reference") == "135" and x.get("code_id") == "JORFTEXT000019995721"]
    verifier("« 135 » — l'ancien droit rend la version abrogée de 2022, pas la sentinelle",
             bool(cible) and cible[0].get("etat") == "ABROGE"
             and str(cible[0].get("date_fin") or "").startswith("2022"),
             f"rendu : {cible[:1] or 'absent'}")


def lignes_du_jeu() -> int:
    return len(json.loads(JEU.read_text(encoding="utf-8")))


# Compté à la main sur les lignes du jeu, au jour figé — jamais tiré de l'API, qui
# serait alors juge de sa propre copie. S'appliquent au 2026-10-14 : l'art. 8 du
# CPP, l'art. 10 dans sa version de 2019, l'art. 13 de l'arrêté amiante dans sa
# version de 2026, civil 1240, et l'art. 1 du décret de 1992 dans sa version de
# 2024. Les art. 41 et 135 n'en ajoutent aucune : le premier n'a en cours qu'une
# version sans état, le second que du passé et une sentinelle. L'ancienne règle en
# comptait TROIS — civil 1240, l'art. 1 de 1992 et L229-61-1, qui est VIGUEUR mais
# ne s'applique qu'en 2027.
VIGUEUR_ATTENDUE = 5
VIGUEUR_ANCIENNE_REGLE = 3


def volet_status(url: str) -> None:
    print("▸ /status — le compte « en vigueur » suit la règle, et /status répond")
    # 🔑 /status n'était éprouvé qu'à travers le bandeau du client MCP, qui rendait
    # une chaîne vide quand l'API échouait : un /status cassé passait en silence.
    # Il est appelé avant chaque réponse du MCP — c'est ici qu'il se mesure, sur
    # une instance dont la base UE manque.
    r = lire(f"{url}/api/status")
    legi = r.get("legi") or {}
    verifier("/status répond et porte son bloc LEGI, la base UE absente",
             bool(legi) and r.get("eu") is None, f"rendu : {str(r)[:160]}")
    n = lignes_du_jeu()
    verifier(f"{legi.get('articles')} versions en base, le jeu en porte {n}",
             legi.get("articles") == n, f"articles = {legi.get('articles')!r}")
    vigueur = legi.get("vigueur")
    verifier(f"compte « en vigueur » = {vigueur}, attendu {VIGUEUR_ATTENDUE}",
             vigueur == VIGUEUR_ATTENDUE,
             f"{vigueur!r} — {'c’est l’ancienne règle, sur l’état seul' if vigueur == VIGUEUR_ANCIENNE_REGLE else 'ni la règle ni l’ancienne'}")


def volet_listes(_: object = None) -> None:
    print("▸ Les listes d'états — une seule, quel que soit le langage")
    # La liste vit dans api.php et dans tools/extract_selfjustice_db.py, que
    # audit_articles.py partage. Les comportements sont confrontés plus haut, mais
    # un état AJOUTÉ à une seule copie ne changerait aucun cas du jeu et passerait :
    # c'est l'égalité des ensembles qui le voit.
    source = (RACINE / "api" / "api.php").read_text(encoding="utf-8")
    m = re.search(r"const LEGI_ETATS_VIVANTS\s*=\s*\[([^\]]*)\]", source)
    if not m:
        verifier("la liste se lit dans api.php", False,
                 "`const LEGI_ETATS_VIVANTS = [...]` introuvable : sa forme a changé, "
                 "et ce contrôle doit la suivre au lieu de passer")
        return
    php = set(re.findall(r"'([A-Z_]+)'", m.group(1)))
    sys.path.insert(0, str(RACINE / "tools"))
    import audit_articles
    import extract_selfjustice_db
    for nom, valeur in (("extract_selfjustice_db.ETATS_VIVANTS", extract_selfjustice_db.ETATS_VIVANTS),
                        ("audit_articles.ETATS_VIVANTS", audit_articles.ETATS_VIVANTS)):
        verifier(f"{nom} = celle d'api.php ({len(php)} états)", set(valeur) == php,
                 f"api.php : {sorted(php)} · {nom} : {sorted(valeur)}")
    verifier("l'index plein texte est bâti sur cette liste",
             all(f"'{e}'" in extract_selfjustice_db.CONDITION_INDEXEE for e in php),
             f"CONDITION_INDEXEE : {extract_selfjustice_db.CONDITION_INDEXEE}")


def volet_audit(base: pathlib.Path) -> None:
    print("▸ L'outil d'audit — il rend le même verdict que l'API")
    sys.path.insert(0, str(RACINE / "tools"))
    try:
        import audit_articles
    except Exception as e:
        verifier("audit_articles.py s'importe", False, f"{type(e).__name__} : {e}")
        return
    # Chaque cas de l'API a SON statut d'audit. « Plus en vigueur » en couvre
    # plusieurs — abrogé, modifié, périmé… — mais jamais l'un des trois autres :
    # accepter n'importe quel statut « mort » pour une version sans état laissait
    # passer un « ABROGÉ » que la source n'affirme pas.
    vivants_ou_inconnus = {"EN VIGUEUR", "VIGUEUR DIFFÉRÉE", "SANS ÉTAT", "INTROUVABLE"}
    for _etiquette, num, _code, _vigueur, applicabilite, _version in CAS:
        if num not in NUMEROS_AUDITABLES:
            continue
        rendu = audit_articles.check_article_in_db(str(base), num, "code", jour=JOUR)["status"]
        ok = {"en_vigueur": rendu == "EN VIGUEUR",
              "pas_encore": rendu == "VIGUEUR DIFFÉRÉE",
              "sans_etat": rendu == "SANS ÉTAT",
              "plus_en_vigueur": rendu not in vivants_ou_inconnus}[applicabilite]
        verifier(f"art. {num} — {rendu}", ok, f"l'API dit {applicabilite!r}, l'outil dit {rendu!r}")


def volet_mcp(url: str) -> None:
    print("▸ Le client MCP — il présente ce que l'API a établi")
    sys.path.insert(0, str(RACINE / "mcp"))
    try:
        import os
        os.environ["SELFRIGHT_API_URL"] = f"{url}/api"
        from selfright_mcp import server
    except ImportError as e:
        print(f"  ? le paquet `mcp` manque ({e}) : volet non joué — pip install mcp httpx")
        ROUGES.append("volet MCP non joué")
        return

    attendus = {
        "en_vigueur": "EN VIGUEUR",
        "pas_encore": "PAS ENCORE EN VIGUEUR",
        "plus_en_vigueur": "PLUS EN VIGUEUR",
        "sans_etat": "SANS ÉTAT DÉCLARÉ",
    }
    SENTINELLES = ("2999-01-01", "2222-02-22")

    def propre(texte: str) -> list[str]:
        """Ce qu'aucun texte rendu ne doit porter, quel que soit l'outil."""
        defauts = []
        if "None" in texte:
            defauts.append("le texte rendu contient « None »")
        if "sera abrogé" in texte:
            defauts.append("le texte promet une abrogation")
        defauts += [f"le texte affiche la sentinelle {s}" for s in SENTINELLES if s in texte]
        return defauts

    def bloc_de(texte: str, reference: str) -> str:
        """Le titre du bloc où une référence est rangée — « » pour le premier bloc,
        celui du droit applicable, qui n'en porte pas."""
        lignes = texte.splitlines()
        i = next((k for k, l in enumerate(lignes) if f"{reference} —" in l), None)
        if i is None:
            return "(absent)"
        return next((lignes[k] for k in range(i, -1, -1) if lignes[k].startswith("— ")), "")

    async def jouer() -> None:
        for _etiquette, num, code, _vigueur, applicabilite, _version in CAS:
            texte = await server.article_francais(num, code=code)
            ligne = next((l for l in texte.splitlines() if "VIGUEUR" in l or "ÉTAT" in l), "")
            defauts = propre(texte)
            if not ligne.lstrip("⚠️ ").startswith(attendus[applicabilite]):
                defauts.append(f"libellé {ligne!r}, attendu « {attendus[applicabilite]} »")
            verifier(f"{num} — {ligne.strip()[:70]}", not defauts, " · ".join(defauts))

        # La recherche range chaque résultat dans le bloc de SON applicabilité.
        # L229-61-1 ne s'applique qu'en 2027 : il tombait sous « Ne sont plus en
        # vigueur (utiles pour des faits antérieurs…) ».
        for requete, reference, bloc_attendu in (("L229-61-1", "L229-61-1", "Pas encore en vigueur"),
                                                 ("135", "135", "Ne sont plus en vigueur")):
            texte = await server.rechercher_droit_francais(requete)
            titre = bloc_de(texte, reference)
            defauts = propre(texte)
            if bloc_attendu not in titre:
                defauts.append(f"rangé sous {titre or '(le bloc du droit applicable)'!r}")
            verifier(f"recherche « {requete} » rangée sous « {bloc_attendu} »",
                     not defauts, " · ".join(defauts))

        # Une référence qui porte « / » : interpolée telle quelle dans le chemin,
        # « 8/2 » servait l'article 8 du CPP sous l'en-tête de l'article demandé.
        texte = await server.article_francais("8/2", code=CPP)
        verifier("« 8/2 » ne sert pas l'article 8", "révolues" not in texte,
                 f"rendu : {texte[:160]!r}")

        # Les alternatives d'un numéro porté par plusieurs textes : elles
        # affichaient l'état brut et la date de fin brute — sentinelles comprises.
        texte = await server.article_francais("1")
        defauts = propre(texte)
        verifier("les alternatives d'un numéro ambigu, sans None ni sentinelle",
                 not defauts, " · ".join(defauts))

    asyncio.run(jouer())


def main() -> int:
    for outil in ("php", "sqlite3"):
        if not shutil.which(outil):
            print(f"✗ {outil} introuvable", file=sys.stderr)
            return 2
    if not JEU.is_file():
        print(f"✗ jeu de données introuvable : {JEU}", file=sys.stderr)
        return 2

    bac = pathlib.Path(tempfile.mkdtemp(prefix="banc-applicabilite-"))
    php = None
    try:
        base = bac / "legi.sqlite"
        batir_base(base)
        php, url = lancer_api(base)
        print(f"Jour figé au {JOUR} — jeu de {len(json.loads(JEU.read_text()))} lignes extraites de la base servie\n")
        # Un volet qui plante n'arrête pas les suivants : il compte comme un
        # rouge. Sans cette garde, une exception dans l'un laissait les autres
        # jamais joués — un banc qui s'interrompt ne dit pas ce qu'il n'a pas vu.
        for volet, argument in ((volet_api, url), (volet_recherche, url), (volet_status, url),
                                (volet_listes, None), (volet_audit, base), (volet_mcp, url)):
            try:
                volet(argument)
            except Exception as e:
                verifier(f"{volet.__name__} s'exécute jusqu'au bout", False,
                         f"{type(e).__name__} : {e}")
    finally:
        if php:
            php.terminate()
            php.wait(timeout=5)
        shutil.rmtree(bac, ignore_errors=True)

    print()
    if ROUGES:
        print(f"✗ {len(ROUGES)} contrôle(s) en défaut")
        return 1
    print("✓ l'applicabilité se lit sur la période, partout où elle se décide")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
