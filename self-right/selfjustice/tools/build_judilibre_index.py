#!/usr/bin/env python3
"""Judilibre — construction de l'index léger de vérification de référence.

Objectif : répondre à « cette référence existe-t-elle ? » sans dépendre d'un
service tiers au moment de la question. On ne rapatrie donc que l'identité des
décisions — numéro, date, juridiction, formation — pas leur texte. Le texte
intégral pèse ~7 Go et se récupère à la demande via /decision?id=.

Reprise : le script note son avancement. Relancé, il repart du dernier lot
confirmé plutôt que de tout refaire.

    python3 build_judilibre_index.py                   # construction complète
    python3 build_judilibre_index.py --depuis auto     # rafraîchissement
    python3 build_judilibre_index.py --depuis 2026-06-01

Le mode `--depuis` efface d'abord les tranches couvrant la fenêtre visée, sans
quoi elles seraient sautées et le rafraîchissement ne rapporterait rien.
"""

import json
import os
import sqlite3
import sys
import time
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = "https://api.piste.gouv.fr/cassation/judilibre/v1.0"
# Les deux chemins se surchargent par l'environnement : sur le serveur, la clé
# vient d'un EnvironmentFile en 0600 et la base vit à côté des autres bases
# SelfJustice, pas dans le répertoire de travail d'un poste de développement.
KEY_FILE = os.environ.get(
    "JUDILIBRE_KEY_FILE", os.path.expanduser("~/.config/judilibre/keyid")
)
DB = os.environ.get(
    "JUDILIBRE_DB",
    os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "data", "judilibre_index.sqlite"),
)
MARQUEUR = os.environ.get("JUDILIBRE_MARQUEUR", os.path.join(os.path.dirname(DB), "judilibre_last_update.txt"))

BATCH_SIZE = 1000          # plafond de l'API : 2000 est refusé par un 400
DELAI = 1.2                # rythme poli ; la rafale courte est limitée à 20
JURIDICTIONS = ["cc", "ca"]

# Une date antérieure à celle-ci trahit une donnée corrompue : la base contient
# une décision de cour d'appel datée du 24 février 0201.
DATE_PLANCHER = "1800-01-01"

# Plafond de pagination de l'API : au-delà, le 11e lot rend 416.
FENETRE_MAX = 10000

# Bornes de la moisson. On part de l'an 0100 à dessein : les dates corrompues
# (0201-02-24 côté cours d'appel) doivent entrer dans l'index, sans quoi le
# module répondrait « inexistante » à une décision pourtant présente en base.
DATE_DEBUT = "0100-01-01"
DATE_FIN = "2027-12-31"


def cle():
    try:
        with open(KEY_FILE) as f:
            return f.read().strip()
    except OSError as e:
        sys.exit(f"Clé illisible ({KEY_FILE}) : {e}")


KEY = cle()


def journal(msg):
    print(f"[{datetime.now():%H:%M:%S}] {msg}", flush=True)


def appel(chemin, params):
    """Un appel, avec respect du quota annoncé par la passerelle.

    L'en-tête x-rate-limit est renvoyé à chaque réponse : on s'y adapte plutôt
    que de temporiser sur une constante écrite en dur, qui serait fausse le jour
    où le quota change sans que personne ne le remarque.
    """
    url = f"{BASE}/{chemin}?" + urllib.parse.urlencode(params, doseq=True)
    req = urllib.request.Request(url, headers={"KeyId": KEY})
    for tentative in range(1, 6):
        try:
            with urllib.request.urlopen(req, timeout=90) as r:
                limite = r.headers.get("x-rate-limit")
                corps = json.loads(r.read())
            if limite:
                try:
                    restant = min(w.get("remaining", 999)
                                  for w in json.loads(limite))
                    if restant < 5:
                        journal(f"quota bas ({restant}) — pause 30 s")
                        time.sleep(30)
                except (ValueError, TypeError):
                    pass
            return corps
        except urllib.error.HTTPError as e:
            if e.code == 429:
                attente = 10 * tentative
                journal(f"429 — pause {attente} s")
                time.sleep(attente)
                continue
            journal(f"HTTP {e.code} sur {chemin} {params} : {e.read()[:200]}")
            return None
        except Exception as e:
            journal(f"échec réseau ({tentative}/5) : {e}")
            time.sleep(5 * tentative)
    return None


def normaliser(num):
    """`25-10.377` -> `2510377`, `25/01234` -> `2501234`.

    Sans cette normalisation, une requête contenant un slash part en recherche
    plein texte et rend des centaines de milliers de résultats — un faux positif
    qui ferait conclure « la référence existe » à un module naïf.
    """
    if not num:
        return None
    return "".join(c for c in str(num) if c.isalnum()).lower()


def ouvrir_base():
    conn = sqlite3.connect(DB)
    conn.executescript("""
        PRAGMA journal_mode = WAL;

        CREATE TABLE IF NOT EXISTS decisions (
            id             TEXT PRIMARY KEY,
            number         TEXT,
            decision_date  TEXT,
            jurisdiction   TEXT,
            chamber        TEXT,
            location       TEXT,
            formation      TEXT,
            publication    TEXT,
            solution       TEXT,
            ecli           TEXT,
            type           TEXT,
            update_date    TEXT,
            date_suspecte  INTEGER DEFAULT 0
        );

        -- Une décision peut porter plusieurs numéros de pourvoi (affaires
        -- jointes). Les loger à part évite qu'une référence réelle passe pour
        -- inexistante au seul motif qu'elle n'est pas le numéro principal.
        CREATE TABLE IF NOT EXISTS numeros (
            number_norm  TEXT NOT NULL,
            decision_id  TEXT NOT NULL,
            PRIMARY KEY (number_norm, decision_id)
        );

        CREATE INDEX IF NOT EXISTS idx_num  ON numeros(number_norm);
        CREATE INDEX IF NOT EXISTS idx_date ON decisions(decision_date);
        CREATE INDEX IF NOT EXISTS idx_juri ON decisions(jurisdiction);

        -- Un intervalle n'est inscrit qu'une fois entièrement moissonné :
        -- une reprise ne peut donc pas sauter une tranche à moitié faite.
        CREATE TABLE IF NOT EXISTS intervalles_faits (
            jurisdiction TEXT NOT NULL,
            date_debut   TEXT NOT NULL,
            date_fin     TEXT NOT NULL,
            recus        INTEGER,
            maj          TEXT,
            PRIMARY KEY (jurisdiction, date_debut, date_fin)
        );
    """)
    return conn


def enregistrer(conn, decisions):
    lignes, nums = [], []
    for d in decisions:
        did = d.get("id")
        if not did:
            continue
        date = d.get("decision_date") or ""
        suspecte = 1 if date and date < DATE_PLANCHER else 0
        lignes.append((
            did, d.get("number"), date, d.get("jurisdiction"),
            d.get("chamber"), d.get("location"), d.get("formation"),
            d.get("publication") if isinstance(d.get("publication"), str)
            else json.dumps(d.get("publication"), ensure_ascii=False),
            d.get("solution"), d.get("ecli"), d.get("type"),
            d.get("update_date"), suspecte,
        ))
        vus = set()
        for n in ([d.get("number")] + (d.get("numbers") or [])):
            nn = normaliser(n)
            if nn and nn not in vus:
                vus.add(nn)
                nums.append((nn, did))

    conn.executemany(
        "INSERT OR REPLACE INTO decisions VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        lignes)
    conn.executemany(
        "INSERT OR IGNORE INTO numeros VALUES (?,?)", nums)
    return len(lignes)


def moissonner_intervalle(conn, juri, debut, fin, etat):
    """Moissonne une tranche de dates, en la coupant en deux si elle déborde.

    🔑 L'API refuse de paginer au-delà de 10 000 résultats pour un même jeu de
    critères : au 11e lot elle rend `416 Range Not Satisfiable`. On ne peut donc
    pas parcourir 1,19 million de décisions d'une traite.

    Découper par tranches fixes (le mois, la semaine) obligerait à deviner une
    granularité qui serait fausse quelque part — la Cour de cassation publie
    ~850 décisions par mois, les cours d'appel bien davantage, et le rythme
    change au fil des décennies. On laisse donc l'API annoncer le volume de
    chaque tranche, et on coupe en deux tant que ça dépasse.
    """
    if conn.execute(
            "SELECT 1 FROM intervalles_faits WHERE jurisdiction=? AND "
            "date_debut=? AND date_fin=?", (juri, debut, fin)).fetchone():
        return True

    params = {"batch": 0, "batch_size": BATCH_SIZE, "jurisdiction": juri,
              "date_start": debut, "date_end": fin}
    d1 = datetime.strptime(debut, "%Y-%m-%d").date()
    d2 = datetime.strptime(fin, "%Y-%m-%d").date()

    d = appel("export", params)

    # 🔑 Un échec sur une tranche large se traite en la coupant, pas en
    # l'abandonnant. Le serveur répond `circuit_breaking_exception: Data too
    # large` quand l'intervalle demandé pèse trop lourd à assembler — l'échec
    # est donc *causé* par la taille de la tranche, et la seule information qui
    # permettrait de décider de la couper (le `total`) n'arrive jamais. Sans ce
    # rattrapage, le script renonce exactement là où il aurait dû insister.
    if d is None:
        if d1 < d2:
            journal(f"{juri} {debut}→{fin} : refus serveur, on coupe la tranche")
            milieu = d1 + (d2 - d1) / 2
            ok1 = moissonner_intervalle(conn, juri, debut, milieu.isoformat(), etat)
            suivant = (milieu + timedelta(days=1)).isoformat()
            ok2 = moissonner_intervalle(conn, juri, suivant, fin, etat)
            return ok1 and ok2
        journal(f"{juri} {debut} : refus serveur sur une seule journée")
        return False

    total = d.get("total") or 0
    if total == 0:
        conn.execute("INSERT OR REPLACE INTO intervalles_faits VALUES (?,?,?,?,?)",
                     (juri, debut, fin, 0, datetime.now(timezone.utc).isoformat()))
        conn.commit()
        return True

    if total > FENETRE_MAX:
        if d1 >= d2:
            # Une seule journée dépasse le plafond : on ne peut plus couper
            # dans le temps. Signalé plutôt que tronqué en silence.
            journal(f"⚠️ {juri} {debut} : {total} décisions sur un seul jour, "
                    f"au-delà du plafond de {FENETRE_MAX} — tranche tronquée")
        else:
            milieu = d1 + (d2 - d1) / 2
            ok1 = moissonner_intervalle(conn, juri, debut,
                                        milieu.isoformat(), etat)
            suivant = (milieu + timedelta(days=1)).isoformat()
            ok2 = moissonner_intervalle(conn, juri, suivant, fin, etat)
            return ok1 and ok2

    recus, lot = 0, 0
    while True:
        if lot:
            time.sleep(DELAI)
            d = appel("export", {**params, "batch": lot})
            if d is None:
                journal(f"{juri} {debut}→{fin} : abandon au lot {lot}")
                return False
        resultats = d.get("results") or []
        if not resultats:
            break
        recus += enregistrer(conn, resultats)
        if not d.get("next_batch"):
            break
        lot += 1

    conn.execute("INSERT OR REPLACE INTO intervalles_faits VALUES (?,?,?,?,?)",
                 (juri, debut, fin, recus, datetime.now(timezone.utc).isoformat()))
    conn.commit()

    etat["recus"] += recus
    etat["tranches"] += 1
    if etat["tranches"] % 20 == 0:
        pct = 100 * etat["recus"] / etat["total"] if etat["total"] else 0
        journal(f"{juri} : {etat['recus']:,} enregistrées ({pct:.1f} %) — "
                f"dernière tranche {debut}→{fin}".replace(",", " "))
    time.sleep(DELAI)
    return True


def moissonner(conn, juri):
    stats = appel("stats", {"jurisdiction": juri})
    total = (stats or {}).get("results", {}).get("total_decisions", 0)
    journal(f"{juri} : {total:,} décisions annoncées".replace(",", " "))

    deja = conn.execute(
        "SELECT COUNT(*) FROM intervalles_faits WHERE jurisdiction=?",
        (juri,)).fetchone()[0]
    if deja:
        journal(f"{juri} : reprise, {deja} tranches déjà faites")

    etat = {"recus": 0, "tranches": 0, "total": total}
    ok = moissonner_intervalle(conn, juri, DATE_DEBUT, DATE_FIN, etat)
    journal(f"{juri} : {etat['recus']:,} décisions sur {etat['tranches']} tranches"
            .replace(",", " "))
    return ok


# Recouvrement appliqué en mode incrémental, pour deux raisons distinctes.
#
# 1. Judilibre publie avec du retard : une décision rendue le 20 juillet peut
#    n'apparaître qu'en août. Reprendre au lendemain de la dernière date connue
#    laisserait des trous permanents.
#
# 2. 🔑 **La pagination de /export n'est pas stable.** Mesuré le 09/08/2026 sur
#    une fenêtre de cours d'appel : 6 806 lignes rendues pour 6 688 identifiants
#    distincts — 118 doublons — et une décision (`26/00276` du 23/07, indexée
#    amont la veille) purement absente du passage. Le passage suivant sur la
#    même fenêtre l'a ramenée. Signature d'un tri non déterministe côté moteur
#    de recherche : la pagination profonde répète des éléments et en saute
#    d'autres.
#
# Un unique passage ne prouve donc pas l'exhaustivité, et aucune erreur ne le
# signale. Le recouvrement est ce qui rattrape ces oublis au passage suivant —
# c'est un mécanisme de correction, pas une marge de confort.
RECOUVREMENT_JOURS = 30


def depuis_auto(conn):
    """Date de reprise : la plus récente décision connue, moins le recouvrement."""
    borne = conn.execute(
        "SELECT MIN(m) FROM (SELECT MAX(decision_date) AS m FROM decisions "
        "WHERE date_suspecte = 0 GROUP BY jurisdiction)"
    ).fetchone()[0]
    if not borne:
        return DATE_DEBUT
    depart = datetime.strptime(borne, "%Y-%m-%d").date() - timedelta(days=RECOUVREMENT_JOURS)
    return depart.isoformat()


def rafraichir(conn, depuis, jusqua):
    """Remoissonne la fenêtre récente, tranches déjà faites comprises.

    `moissonner_intervalle` saute ce qui figure dans `intervalles_faits` : sans
    cet effacement préalable, un rafraîchissement ne rapporterait jamais rien.
    """
    efface = conn.execute(
        "DELETE FROM intervalles_faits WHERE date_fin >= ? AND date_debut <= ?",
        (depuis, jusqua),
    ).rowcount
    conn.commit()
    journal(f"fenêtre {depuis} → {jusqua} : {efface} tranche(s) à refaire")

    complet = True
    for juri in JURIDICTIONS:
        etat = {"recus": 0, "tranches": 0, "total": 0}
        if not moissonner_intervalle(conn, juri, depuis, jusqua, etat):
            complet = False
        journal(f"{juri} : {etat['recus']} décisions sur {etat['tranches']} tranches")
    return complet


def main():
    incremental = "--depuis" in sys.argv
    if incremental:
        valeur = sys.argv[sys.argv.index("--depuis") + 1]

    journal(f"Index Judilibre → {DB}")
    conn = ouvrir_base()

    if incremental:
        depuis = depuis_auto(conn) if valeur == "auto" else valeur
        jusqua = datetime.now(timezone.utc).date().isoformat()
        complet = rafraichir(conn, depuis, jusqua)
    else:
        complet = True
        for juri in JURIDICTIONS:
            if not moissonner(conn, juri):
                complet = False

    n_dec = conn.execute("SELECT COUNT(*) FROM decisions").fetchone()[0]
    n_num = conn.execute("SELECT COUNT(*) FROM numeros").fetchone()[0]
    n_sus = conn.execute(
        "SELECT COUNT(*) FROM decisions WHERE date_suspecte = 1").fetchone()[0]
    conn.close()

    taille = os.path.getsize(DB) / 1e6
    journal(f"{n_dec:,} décisions, {n_num:,} numéros, {taille:.0f} Mo"
            .replace(",", " "))
    if n_sus:
        journal(f"⚠️ {n_sus} dates antérieures à {DATE_PLANCHER} (données corrompues)")

    # 🔑 Sortir en code non nul quand la moisson est partielle. Un index
    # silencieusement incomplet répondrait « cette référence n'existe pas »
    # à des arrêts bien réels — l'erreur la plus grave pour cet outil.
    if not complet:
        sys.exit("Moisson incomplète — index inutilisable en l'état.")

    # La date n'est écrite qu'en cas de succès complet : elle atteste d'une
    # moisson entière, pas d'une tentative. L'API la relaie à l'utilisateur.
    with open(MARQUEUR, "w") as f:
        f.write(datetime.now(timezone.utc).date().isoformat() + "\n")
    journal(f"marqueur de fraîcheur écrit : {MARQUEUR}")


if __name__ == "__main__":
    main()
