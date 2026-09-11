#!/usr/bin/env python3
"""SelfJustice — collecte de la jurisprudence administrative, fonds JADE (DILA).

Le Conseil d'État, les cours administratives d'appel, une sélection de tribunaux
administratifs, le Tribunal des conflits et la Cour de discipline budgétaire et
financière. **Judilibre ne sert pas cette matière** : son guichet a répondu, le
10/09/2026, `Value of the jurisdiction parameter must be in [cc,ca,tj,tcom]`.
JADE est une autre source, sans API — un dump global et des incréments
quotidiens, la même mécanique que LEGI.

    python3 build_jade_db.py --global Freemium_jade_global_*.tar.gz --db …
    python3 build_jade_db.py --diff JADE_20260909-214416.tar.gz --db …
    python3 build_jade_db.py --depuis auto --db …    # rattrape ce qui manque

🔑 **Le global seul est un piège, et il a déjà servi.** LEGI a été construit
pendant treize mois à partir d'un dump global figé au 13 juillet 2025, ses diffs
quotidiens téléchargés et jamais appliqués. La base servait honnêtement une date
qui ne bougeait pas, et personne ne l'a vu — parce qu'il n'y avait rien à voir :
pas d'erreur, pas de code de sortie, une date exacte. Le dump global de JADE
porte exactement la même date. `--global` écrit donc `dernier_diff` à la date du
dump, et `--depuis auto` refuse de s'arrêter tant qu'un incrément postérieur
reste à appliquer.

⚠️ **Le même identifiant peut apparaître deux fois dans une archive.** 40 sur
les 552 576 fichiers du global. La base en compte donc 552 536 : l'écart n'est
pas une perte, et le journal le nomme plutôt que de laisser conclure.

⚠️ **Les incréments se recouvrent.** Ils ne sont pas des deltas : celui du
5 septembre 2026 contient l'intégralité de celui du 4, et quatre incréments
consécutifs pèsent 339 décisions pour 179 distinctes. La DILA publie une fenêtre
glissante dont la profondeur n'est pas annoncée. Rattraper coûte donc plus que
le strict nécessaire — et on ne saute quand même aucun incrément : `INSERT OR
REPLACE` rend la redondance inoffensive, tandis qu'un saut fondé sur une
profondeur supposée perdrait des décisions en silence.

⚠️ **Aucun incrément ne porte de liste de suppression.** Vérifié sur cinq d'entre
eux le 10/09/2026 : ils ne contiennent que des XML de décision. Une décision
retirée du fonds par la DILA restera donc dans la base. Ce n'est pas un défaut à
corriger mais une limite de la source, et elle se dit plutôt qu'elle ne se
devine.
"""

import argparse
import datetime
import html
import os
import re
import sqlite3
import sys
import tarfile
import urllib.request
from xml.etree import ElementTree as ET

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from jade_juridictions import normaliser  # noqa: E402

BASE_DILA = os.environ.get("SELFJUSTICE_JADE_BASE",
                           "https://echanges.dila.gouv.fr/OPENDATA/JADE").rstrip("/")
AGENT = "SelfJustice-JADE/1.0"

# Une date antérieure trahit une donnée corrompue — même seuil que l'index
# Judilibre, qui porte une décision de cour d'appel datée du 24 février 0201.
# Le fonds JADE, lui, porte une décision datée de l'an 2990.
DATE_PLANCHER = "1800-01-01"

# Écrire toutes les N décisions plutôt qu'à la fin : une moisson interrompue
# garde ce qu'elle a déjà lu, et un verrou sur la base ne dure jamais longtemps.
# `judilibre-update.timer` écrit dans le même fichier le 1er et le 15.
LOT = 2000

CHAMPS = ("ID", "NUMERO", "DATE_DEC", "JURIDICTION", "FORMATION",
          "PUBLI_RECUEIL", "TYPE_REC", "SOLUTION", "NUMERO_AFFAIRE")
NOM_DIFF = re.compile(r"^JADE_(\d{8})-\d{6}\.tar\.gz$")


def journal(message: str) -> None:
    print("[%s] %s" % (datetime.datetime.now().strftime("%H:%M:%S"), message),
          flush=True)


def ouvrir_base(chemin: str) -> sqlite3.Connection:
    """La table de l'index Judilibre, augmentée de deux colonnes.

    Une base à part aurait obligé l'API à fusionner deux couvertures ; ici
    `juris_couverture()` et `juridictions_servies()` voient JADE sans qu'une
    ligne d'api.php change. La colonne `source` distingue les deux fonds, et
    `texte` reste vide pour Judilibre, dont le texte vient de l'amont à la
    demande.
    """
    conn = sqlite3.connect(chemin)
    conn.execute("PRAGMA busy_timeout = 30000")
    conn.execute("""
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
        )
    """)
    colonnes = {r[1] for r in conn.execute("PRAGMA table_info(decisions)")}
    for nom, decl in (("texte", "TEXT"), ("source", "TEXT DEFAULT 'judilibre'")):
        if nom not in colonnes:
            conn.execute("ALTER TABLE decisions ADD COLUMN %s %s" % (nom, decl))
            journal("colonne « %s » ajoutée à decisions" % nom)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS jade_etat (
            cle    TEXT PRIMARY KEY,
            valeur TEXT
        )
    """)
    conn.execute("CREATE INDEX IF NOT EXISTS idx_juri ON decisions(jurisdiction)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_source ON decisions(source)")
    conn.commit()
    return conn


def etat_lire(conn, cle, defaut=None):
    r = conn.execute("SELECT valeur FROM jade_etat WHERE cle=?", (cle,)).fetchone()
    return r[0] if r else defaut


def etat_ecrire(conn, cle, valeur):
    conn.execute("INSERT OR REPLACE INTO jade_etat VALUES (?,?)", (cle, str(valeur)))


def texte_lisible(brut: str) -> str:
    """Le contenu débarrassé de son balisage, quel qu'il soit.

    L'échantillon mesuré ne porte que `<br/>`, mais il ne couvre que l'écriture
    récente et le fonds remonte à 1873 : on retire donc TOUTE balise plutôt que
    de nommer celles qu'on a vues. Une balise inconnue laissée dans le texte
    ressortirait telle quelle chez l'utilisateur.
    """
    t = re.sub(r"<br\s*/?>", "\n", brut, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    t = html.unescape(t)
    t = re.sub(r"[ \t]+", " ", t)
    t = re.sub(r"\n{3,}", "\n\n", t)
    return t.strip()


def parser(blob: bytes):
    """Une décision JADE → le dict à écrire, ou None si elle est inexploitable."""
    try:
        racine = ET.fromstring(blob)
    except ET.ParseError:
        return None
    valeurs = {}
    for champ in CHAMPS:
        el = racine.find(".//" + champ)
        valeurs[champ] = (el.text or "").strip() if el is not None else ""
    if not valeurs["ID"]:
        return None

    juri = normaliser(valeurs["JURIDICTION"])
    if juri is None:
        return {"_refuse": valeurs["JURIDICTION"]}
    code, ville = juri

    contenu = racine.find(".//CONTENU")
    texte = ""
    if contenu is not None:
        brut = ET.tostring(contenu, encoding="unicode", method="xml")
        brut = re.sub(r"^<CONTENU[^>]*>|</CONTENU>$", "", brut.strip())
        texte = texte_lisible(brut)

    d = valeurs["DATE_DEC"]
    suspecte = 0
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", d or ""):
        suspecte = 1
    elif d < DATE_PLANCHER or d > datetime.date.today().isoformat():
        suspecte = 1

    return {
        "id": valeurs["ID"],
        "number": valeurs["NUMERO"] or valeurs["NUMERO_AFFAIRE"],
        "decision_date": d,
        "jurisdiction": code,
        "location": ville,
        "formation": valeurs["FORMATION"],
        "publication": valeurs["PUBLI_RECUEIL"],
        "solution": valeurs["SOLUTION"],
        "type": valeurs["TYPE_REC"],
        "texte": texte,
        "suspecte": suspecte,
    }


def verifier_archive(chemin: str) -> None:
    """Refuse ce qui n'est pas un tarball, avant d'essayer de l'ouvrir.

    🔑 Un miroir qui rend une page d'erreur écrit un fichier parfaitement
    valide — et parfaitement faux. Mesuré le 10/09/2026 : une page « 404 Not
    Found » de 196 octets déposée sous le nom d'un incrément, que `tarfile`
    signale par une trace Python parlant de gzip, à mille lieues de la cause.
    Un téléchargement se vérifie sur ce qu'il a ramené, pas sur le fait qu'il
    s'est terminé.
    """
    if not os.path.exists(chemin):
        sys.exit("Archive introuvable : %s" % chemin)
    with open(chemin, "rb") as f:
        entete = f.read(2)
    if entete != b"\x1f\x8b":
        taille = os.path.getsize(chemin)
        sys.exit("« %s » n'est pas une archive gzip (%d octets, commence par %r). "
                 "Un miroir qui rend une page d'erreur produit exactement ceci : "
                 "vérifier l'URL et le code HTTP avant de conclure que le fonds "
                 "est corrompu." % (os.path.basename(chemin), taille, entete))


def absorber(conn, chemin_tar: str) -> dict:
    """Parcourt un tarball et écrit ce qu'il contient. Rend le compte rendu."""
    verifier_archive(chemin_tar)
    bilan = {"lues": 0, "ecrites": 0, "illisibles": 0, "refusees": 0,
             "suspectes": 0, "sans_texte": 0, "redites": 0,
             "libelles_refuses": set()}
    # 🔑 Le fonds contient le MÊME identifiant plusieurs fois : 40 doublons sur
    # 552 576 fichiers, mesurés sur le global du 13/07/2025. `INSERT OR REPLACE`
    # les absorbe sans bruit, et la base compte alors 40 décisions de moins que
    # de fichiers lus. Un écart entre ce qu'on lit et ce qu'on écrit doit
    # s'expliquer de lui-même : sans ce compteur, il ressemblerait à une perte.
    vus = set()
    lot = []
    with tarfile.open(chemin_tar, "r:gz") as tar:
        for membre in tar:
            if not membre.isfile() or not membre.name.endswith(".xml"):
                continue
            f = tar.extractfile(membre)
            if f is None:
                continue
            bilan["lues"] += 1
            d = parser(f.read())
            if d is None:
                bilan["illisibles"] += 1
                continue
            if "_refuse" in d:
                bilan["refusees"] += 1
                bilan["libelles_refuses"].add(d["_refuse"])
                continue
            if d["id"] in vus:
                bilan["redites"] += 1
            vus.add(d["id"])
            if not d["texte"]:
                bilan["sans_texte"] += 1
            bilan["suspectes"] += d["suspecte"]
            lot.append((d["id"], d["number"], d["decision_date"], d["jurisdiction"],
                        "", d["location"], d["formation"], d["publication"],
                        d["solution"], "", d["type"],
                        datetime.date.today().isoformat(), d["suspecte"],
                        d["texte"], "jade"))
            if len(lot) >= LOT:
                bilan["ecrites"] += ecrire(conn, lot)
                lot = []
                if bilan["ecrites"] % (LOT * 25) == 0:
                    journal("  %d décisions écrites…" % bilan["ecrites"])
    if lot:
        bilan["ecrites"] += ecrire(conn, lot)
    return bilan


def ecrire(conn, lot) -> int:
    conn.executemany("""
        INSERT OR REPLACE INTO decisions
        (id, number, decision_date, jurisdiction, chamber, location, formation,
         publication, solution, ecli, type, update_date, date_suspecte,
         texte, source)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    """, lot)
    conn.commit()
    return len(lot)


def diffs_disponibles():
    """Les incréments publiés, du plus ancien au plus récent."""
    req = urllib.request.Request(BASE_DILA + "/", headers={"User-Agent": AGENT})
    with urllib.request.urlopen(req, timeout=90) as r:
        index = r.read().decode("utf-8", "replace")
    noms = sorted(set(re.findall(r"JADE_\d{8}-\d{6}\.tar\.gz", index)))
    return noms


def telecharger(nom: str, repertoire: str) -> str:
    cible = os.path.join(repertoire, nom)
    if os.path.exists(cible):
        return cible
    req = urllib.request.Request(BASE_DILA + "/" + nom, headers={"User-Agent": AGENT})
    with urllib.request.urlopen(req, timeout=300) as r, open(cible, "wb") as f:
        while True:
            morceau = r.read(1 << 20)
            if not morceau:
                break
            f.write(morceau)
    verifier_archive(cible)
    return cible


def rendre_compte(conn, bilan, source):
    journal("%s : %d lues · %d écrites · %d illisibles · %d refusées · "
            "%d dates suspectes · %d sans texte"
            % (source, bilan["lues"], bilan["ecrites"], bilan["illisibles"],
               bilan["refusees"], bilan["suspectes"], bilan["sans_texte"]))
    if bilan["redites"]:
        journal("  dont %d identifiant(s) vu(s) plusieurs fois dans cette archive "
                "— la base en portera d'autant moins que de fichiers lus, et "
                "c'est correct" % bilan["redites"])
    if bilan["libelles_refuses"]:
        # 🔑 Un refus se NOMME. Compter les refusées sans dire lesquelles
        # laisserait ajouter une juridiction au fonds sans que personne ne
        # sache laquelle manque — et le total resterait plausible.
        journal("⚠️ libellés de juridiction non reconnus, décisions NON écrites :")
        for lib in sorted(bilan["libelles_refuses"]):
            journal("     « %s »" % lib)


def main():
    p = argparse.ArgumentParser(description="Collecte JADE → SQLite")
    p.add_argument("--db", required=True)
    p.add_argument("--global", dest="glob", help="tarball du fonds global")
    p.add_argument("--diff", action="append", default=[],
                   help="tarball d'incrément (répétable)")
    p.add_argument("--depuis", help="« auto » : applique tous les incréments manquants")
    p.add_argument("--cache", default=os.environ.get("SELFJUSTICE_JADE_CACHE", "."),
                   help="où déposer les incréments téléchargés")
    a = p.parse_args()
    if not (a.glob or a.diff or a.depuis):
        p.error("rien à faire : --global, --diff ou --depuis")

    conn = ouvrir_base(a.db)
    complet = True
    refuses_total = set()

    if a.glob:
        journal("fonds global : %s" % os.path.basename(a.glob))
        b = absorber(conn, a.glob)
        rendre_compte(conn, b, "global")
        refuses_total |= b["libelles_refuses"]
        if b["refusees"] or b["illisibles"]:
            complet = False
        # La date du dump fait foi : c'est elle qui dit à `--depuis auto` où
        # reprendre. L'écrire ailleurs qu'ici rejouerait le défaut de LEGI.
        m = re.search(r"(\d{8})", os.path.basename(a.glob))
        if m:
            etat_ecrire(conn, "dernier_diff", "JADE_%s-000000.tar.gz" % m.group(1))
            conn.commit()

    a_traiter = list(a.diff)
    if a.depuis:
        if a.depuis != "auto":
            p.error("--depuis n'accepte que « auto »")
        dernier = etat_lire(conn, "dernier_diff", "")
        if not dernier:
            journal("aucun état : passer --global avant --depuis auto")
            conn.close()
            sys.exit("Rien à reprendre — le fonds global n'a pas été absorbé.")
        disponibles = diffs_disponibles()
        manquants = [n for n in disponibles if n > dernier]
        journal("%d incrément(s) publié(s), %d à appliquer depuis %s"
                % (len(disponibles), len(manquants), dernier))
        for nom in manquants:
            a_traiter.append(telecharger(nom, a.cache))

    for chemin in a_traiter:
        nom = os.path.basename(chemin)
        b = absorber(conn, chemin)
        rendre_compte(conn, b, nom)
        refuses_total |= b["libelles_refuses"]
        if b["refusees"] or b["illisibles"]:
            complet = False
        if NOM_DIFF.match(nom):
            etat_ecrire(conn, "dernier_diff", nom)
            conn.commit()

    total = conn.execute(
        "SELECT COUNT(*) FROM decisions WHERE source='jade'").fetchone()[0]
    par_juri = conn.execute(
        "SELECT jurisdiction, COUNT(*) FROM decisions WHERE source='jade' "
        "GROUP BY jurisdiction ORDER BY 2 DESC").fetchall()
    journal("base : %d décisions JADE — %s"
            % (total, " · ".join("%s %d" % (j, n) for j, n in par_juri)))
    journal("dernier incrément appliqué : %s" % etat_lire(conn, "dernier_diff", "—"))
    conn.close()

    if not complet:
        # Un fonds partiel répondrait « cette décision n'existe pas » à des
        # arrêts bien réels : l'erreur la plus grave pour cet outil.
        sys.exit("Collecte incomplète — %d libellé(s) refusé(s) ou fichiers "
                 "illisibles. Index inutilisable en l'état." % len(refuses_total))


if __name__ == "__main__":
    main()
