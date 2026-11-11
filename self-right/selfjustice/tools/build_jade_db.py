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
    python3 build_jade_db.py --numeros --db …        # rend cherchable ce qui est en base

🔑 **Le global seul est un piège, et il a déjà servi.** LEGI a été construit
pendant treize mois à partir d'un dump global figé au 13 juillet 2025, ses diffs
quotidiens téléchargés et jamais appliqués. La base servait honnêtement une date
qui ne bougeait pas, et personne ne l'a vu — parce qu'il n'y avait rien à voir :
pas d'erreur, pas de code de sortie, une date exacte. La DILA republie le global
de JADE, mais à une cadence qu'elle n'annonce pas : celui que portait l'index le
11/11/2026 datait du 08/11. Un global absorbé seul fige donc la base à sa date.
`--global` écrit donc `dernier_diff` à la date du dump, et `--depuis auto`
refuse de s'arrêter tant qu'un incrément postérieur reste à appliquer — d'où
l'ordre que donne le refus de la garde : le global et les incréments de la
fenêtre dans un même appel.

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

⚠️ **Cette fenêtre se vide quand le global est republié, et elle ne se vide pas
par âge.** Mesuré du 08 au 11/11/2026 : un global paraît le 08 à 11:23, et le
même soir la fenêtre ne publie plus rien — l'incrément de la veille a disparu à
26 h d'âge, alors que celui du 09 vivait encore 33 h après sa parution. Une
garde qui compare la fenêtre au seul dernier incrément appliqué refuse donc une
collecte entière à chaque republication. Ce qui permet de trancher est la
dernière lecture *rattrapée* : `continuite_prouvee()`.

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
NOM_DIFF = re.compile(r"^JADE_(\d{8}-\d{6})\.tar\.gz$")
NOM_GLOBAL = re.compile(r"Freemium_jade_global_\d{8}-\d{6}\.tar\.gz")

# Les deux instants que la garde compare — celui qu'un nom d'incrément porte et
# celui d'une lecture de la fenêtre — s'écrivent dans ce format, pour que la
# comparaison reste une comparaison de chaînes comme celle de `manquants`.
MARQUE_TEMPS = "%Y%m%d-%H%M%S"

# Ce qu'une lecture de la fenêtre prouve encore, passé cet âge : rien. Le timer
# passe toutes les 24 h avec 15 min de dispersion (`jade-update.timer`), donc
# deux lectures consécutives sont espacées de 24 h 15 au plus : 25 h laisse
# 45 min de marge et refuse dès qu'un passage a été manqué.
# ⚠️ Cette borne est une prudence, pas une profondeur mesurée : la DILA ne purge
# pas par âge, et un incrément peut donc vivre plus longtemps sans que 25 h soit
# une sous-estimation à relever. Les mesures sont en tête de ce fichier.
MEMOIRE_FENETRE_H = 25

# Code de sortie quand la DILA ne répond pas : rien n'a été écrit, et le passage
# suivant reprendra. Distinct de 1 pour que le pilote ne sonne pas à chaque
# panne d'un soir (`update_jade.sh`).
DILA_INJOIGNABLE = 4


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
    # 🔑 Le même index que pose le moissonneur Judilibre, pour la même raison —
    # `/api/status` ne doit pas lire une table de 6,1 Go pour compter. Il est
    # dans les DEUX collecteurs parce que l'un ou l'autre peut créer la base :
    # posé d'un seul côté, il disparaîtrait à la première reconstruction faite
    # par l'autre, et le défaut reviendrait sans bruit. Le pourquoi complet et
    # les mesures sont dans `build_judilibre_index.py`, à `idx_couverture`.
    conn.execute("CREATE INDEX IF NOT EXISTS idx_couverture "
                 "ON decisions(date_suspecte, jurisdiction, decision_date)")
    # `/verifier` ne cherche un numéro que dans cette table : la même que pose
    # le moissonneur Judilibre, pour qu'une base créée par l'un serve l'autre.
    conn.execute("""
        CREATE TABLE IF NOT EXISTS numeros (
            number_norm  TEXT NOT NULL,
            decision_id  TEXT NOT NULL,
            PRIMARY KEY (number_norm, decision_id)
        )
    """)
    conn.execute("CREATE INDEX IF NOT EXISTS idx_num ON numeros(number_norm)")
    conn.commit()
    return conn


def numero_norm(numero):
    """La forme sous laquelle `/verifier` cherche un numéro.

    🔑 C'est la règle de `juris_normaliser()` dans `api/api.php` : l'API est la
    seule à lire `numeros`, c'est donc elle qui fait autorité. Un caractère
    qu'elle retire et qu'on garderait ici rendrait la décision introuvable —
    d'où l'alphabet ASCII plutôt que `str.isalnum()`, qui garde les lettres
    accentuées. `tests/sanity_schema_partage.py` compare les deux règles.
    """
    if not numero:
        return None
    return re.sub(r"[^A-Za-z0-9]", "", str(numero)).lower() or None


def remplir_numeros(conn) -> int:
    """Inscrit dans `numeros` les décisions JADE qui n'y sont pas. Rend leur nombre.

    Sans cette inscription, `decisions` porte la décision et `/verifier` la
    déclare introuvable. Idempotent : une décision déjà inscrite ne l'est pas
    deux fois.
    """
    conn.create_function("numero_norm", 1, numero_norm, deterministic=True)
    avant = conn.total_changes
    conn.execute(
        "INSERT OR IGNORE INTO numeros (number_norm, decision_id) "
        "SELECT numero_norm(number), id FROM decisions "
        "WHERE source = 'jade' AND numero_norm(number) IS NOT NULL")
    conn.commit()
    return conn.total_changes - avant


def etat_lire(conn, cle, defaut=None):
    r = conn.execute("SELECT valeur FROM jade_etat WHERE cle=?", (cle,)).fetchone()
    return r[0] if r else defaut


def etat_ecrire(conn, cle, valeur):
    conn.execute("INSERT OR REPLACE INTO jade_etat VALUES (?,?)", (cle, str(valeur)))


def horodatage(nom: str):
    """L'instant que porte le nom d'un incrément, ou None si ce n'en est pas un."""
    m = NOM_DIFF.match(nom)
    return m.group(1) if m else None


def lecture_rattrapee(conn, disponibles, global_publie):
    """La lecture qui vient d'avoir lieu peut-elle servir de preuve demain ?

    Une fenêtre **non vide** dont le dernier appliqué atteint le plus récent
    publié a été vue entière : elle vaut par elle-même.

    🔑 **Une fenêtre vide ne vaut rien par elle-même.** Elle ne dit pas ce
    qu'elle portait avant d'être purgée. Elle n'hérite du droit de servir de
    preuve que si une lecture rattrapée existe déjà, et que l'une des deux
    conditions tient : le fonds global n'a pas changé depuis elle — rien n'a
    donc été purgé —, ou elle est assez récente pour que la purge tombe entre
    deux passages consécutifs.

    ⚠️ Sans cette condition, **une seule nuit manquée la veille d'une
    republication fait perdre un incrément en silence** : la nuit suivante, la
    fenêtre vide poserait une ancre neuve sous le nouveau global, et le passage
    d'après accorderait la preuve par-dessus l'incrément jamais vu. La garde
    d'avant ce mécanisme, elle, refusait ce cas.
    """
    if disponibles:
        return etat_lire(conn, "dernier_diff", "") >= disponibles[-1]
    ancre = etat_lire(conn, "fenetre_lue_le", "")
    if not ancre:
        return False
    if global_publie and global_publie == etat_lire(conn, "global_vu", ""):
        return True
    return age_ancre(ancre) is not None


def age_ancre(ancre):
    """L'âge d'une ancre si elle est dans la mémoire de la fenêtre, sinon None."""
    try:
        age = datetime.datetime.now() - datetime.datetime.strptime(ancre, MARQUE_TEMPS)
    except ValueError:
        return None
    return age if age <= datetime.timedelta(hours=MEMOIRE_FENETRE_H) else None


def continuite_prouvee(conn, disponibles, global_vu):
    """L'instant qui prouve que la fenêtre publiée rejoint la base, ou None.

    ⚠️ **La prémisse, à garder sous les yeux** : une republication du fonds
    global ne purge aucun incrément paru depuis le dernier passage rattrapé.
    Elle est étayée par un mois d'observation — les 24 incréments gardés au
    cache le 11/11/2026 paraissent tous entre 21:09 et 21:52, et le seul global
    observé à 11:23 — et par rien d'autre. Si la DILA se met à publier hors de
    ce créneau, un incrément pourra paraître et être purgé entre deux lectures
    sans témoin, et aucune des conditions ci-dessous ne le verra.

    🔑 **La preuve vient de la dernière lecture RATTRAPÉE**, c'est-à-dire d'un
    passage qui a lu la fenêtre entière et appliqué tout ce qu'elle portait :
    alors tout ce qui avait paru avant elle y était visible, donc est en base.
    Si le plus ancien incrément publié aujourd'hui est né après cette lecture,
    rien n'a pu paraître et disparaître entre les deux.

    ⚠️ **« Vu » ne suffit pas, il faut « appliqué ».** Ancrer la preuve sur toute
    lecture, même refusée, referme un vrai trou en quelques nuits : chaque refus
    repousserait l'ancre, et les incréments d'après finiraient par être tous nés
    après elle. La garde se désarmerait d'elle-même, exactement dans le cas
    qu'elle existe pour attraper.

    🔑 **« Né après la lecture » ne suffit pas non plus : il faut qu'aucune purge
    n'ait eu lieu depuis.** Un incrément peut paraître APRÈS cette lecture et
    disparaître AVANT la suivante — alors la fenêtre du lendemain ne porte que
    des incréments nés après l'ancre, et la seule condition de temps accorderait
    la preuve par-dessus le trou. Ce qui l'interdit est le nom du fonds global :
    sa republication est la purge mesurée, donc un global inchangé depuis la
    dernière lecture rattrapée établit que la fenêtre n'a rien perdu entre les
    deux. `MEMOIRE_FENETRE_H` ne pouvait pas y suffire : il borne l'intervalle
    entre deux lectures, pas la durée de vie d'un incrément.
    """
    lu_le = etat_lire(conn, "fenetre_lue_le", "")
    ne_le = horodatage(disponibles[0])
    if not lu_le or not ne_le or ne_le <= lu_le:
        return None
    if not global_vu or global_vu != etat_lire(conn, "global_vu", ""):
        return None
    return lu_le if age_ancre(lu_le) is not None else None


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
    conn.executemany(
        "INSERT OR IGNORE INTO numeros (number_norm, decision_id) VALUES (?,?)",
        [(n, ligne[0]) for ligne in lot if (n := numero_norm(ligne[1]))])
    conn.commit()
    return len(lot)


def fonds_publie():
    """Ce que l'index de la DILA publie : ses incréments du plus ancien au plus
    récent, et le nom de son fonds global.

    🔑 **Une fenêtre vide et un index qu'on ne sait plus lire rendent la même
    liste.** Ce qui les sépare est le fonds global, que l'index porte en
    permanence : mesuré le 11/11/2026, il nommait `Freemium_jade_global_20261108`
    à côté des incréments du moment. Un corps qui ne nomme ni l'un ni l'autre
    n'est donc pas le listage du fonds — page de courtoisie, portail captif,
    gabarit changé —, et le prendre pour une fenêtre vide ferait écrire à la
    garde une preuve de continuité sur une source qu'on n'a pas lue.

    🔑 **Le nom du global est rendu parce qu'il date la dernière purge.** Sa
    republication est le seul mécanisme de purge mesuré : `continuite_prouvee()`
    s'en sert pour savoir si la fenêtre a pu perdre un incrément sans témoin.
    """
    req = urllib.request.Request(BASE_DILA + "/", headers={"User-Agent": AGENT})
    with urllib.request.urlopen(req, timeout=90) as r:
        index = r.read().decode("utf-8", "replace")
    noms = sorted(set(re.findall(r"JADE_\d{8}-\d{6}\.tar\.gz", index)))
    globaux = sorted(set(NOM_GLOBAL.findall(index)))
    if not noms and not globaux:
        raise OSError("l'index de %s ne nomme ni incrément ni fonds global "
                      "(%d octets lus) : ce n'est pas le listage du fonds JADE"
                      % (BASE_DILA, len(index)))
    return noms, (globaux[-1] if globaux else "")


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
    p.add_argument("--numeros", action="store_true",
                   help="inscrit dans `numeros` les décisions JADE qui n'y sont pas")
    a = p.parse_args()
    if not (a.glob or a.diff or a.depuis or a.numeros):
        p.error("rien à faire : --global, --diff, --depuis ou --numeros")

    conn = ouvrir_base(a.db)
    inscrites = remplir_numeros(conn)
    journal("numéros : %d décision(s) JADE rendue(s) cherchables" % inscrites)
    if not (a.glob or a.diff or a.depuis):
        conn.close()
        return
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
    disponibles = []
    global_publie = ""
    lu_le = None
    if a.depuis:
        if a.depuis != "auto":
            p.error("--depuis n'accepte que « auto »")
        dernier = etat_lire(conn, "dernier_diff", "")
        if not dernier:
            journal("aucun état : passer --global avant --depuis auto")
            conn.close()
            sys.exit("Rien à reprendre — le fonds global n'a pas été absorbé.")
        # L'instant se prend AVANT la requête : la prémisse de la preuve est
        # « tout ce qui avait paru à cet instant était visible dans cette
        # lecture », et la requête peut attendre le délai de `fonds_publie()`.
        lu_le = datetime.datetime.now().strftime(MARQUE_TEMPS)
        try:
            disponibles, global_publie = fonds_publie()
        except OSError as e:
            journal("DILA injoignable : %s" % e)
            conn.close()
            sys.exit(DILA_INJOIGNABLE)
        manquants = [n for n in disponibles if n > dernier]
        journal("%d incrément(s) publié(s), %d à appliquer depuis %s"
                % (len(disponibles), len(manquants), dernier))
        # 🔑 **La fenêtre doit rejoindre la base.** La DILA ne garde qu'une
        # fenêtre d'incréments, de profondeur non annoncée. Si le plus ancien
        # qu'elle publie est postérieur au dernier appliqué, ce qui les séparait
        # a pu disparaître : appliquer le reste ferait avancer `dernier_diff`
        # par-dessus le trou, qui deviendrait invisible pour toujours.
        if manquants and disponibles[0] > dernier:
            preuve = continuite_prouvee(conn, disponibles, global_publie)
            if preuve:
                journal("fenêtre roulée : %s est né après la lecture rattrapée "
                        "du %s, et le fonds global n'a pas été republié depuis "
                        "(%s) — rien n'a pu disparaître entre les deux"
                        % (disponibles[0], preuve, global_publie))
            else:
                journal("trou possible : la fenêtre publiée commence à %s, après %s"
                        % (disponibles[0], dernier))
                conn.close()
                # Le remède nomme les deux temps d'un seul appel : `--global`
                # écrit `dernier_diff` à minuit du jour du dump, donc un
                # `--depuis auto` lancé après lui retomberait sur ce refus.
                sys.exit("Trou possible dans les incréments JADE : rejouer le fonds "
                         "global et les incréments de la fenêtre dans le même appel "
                         "(--global … --diff … --diff …). Rien n'a été écrit.")
        try:
            for nom in manquants:
                a_traiter.append(telecharger(nom, a.cache))
        except OSError as e:
            journal("DILA injoignable pendant le téléchargement : %s" % e)
            conn.close()
            sys.exit(DILA_INJOIGNABLE)

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

    # L'ancre de la garde, et elle ne s'écrit qu'ici : non pas « j'ai lu la
    # fenêtre » mais « j'ai lu la fenêtre et appliqué tout ce qu'elle portait ».
    # Les chemins d'échec qui précèdent sortent avant ce point — fenêtre lue puis
    # téléchargement perdu, archive corrompue, garde qui refuse : aucun n'a
    # rattrapé la fenêtre, aucun ne doit pouvoir servir de preuve demain.
    # 🔑 `complet` en fait partie, et c'est le seul échec qui arrive JUSQU'ICI :
    # une archive dont un libellé de juridiction est refusé fait avancer
    # `dernier_diff` sans écrire ses décisions. Le passage sort en échec et le
    # pilote alerte ; son ancre certifierait une fenêtre qu'il n'a pas absorbée.
    # Ce qu'une fenêtre vide peut hériter est dans `lecture_rattrapee()`.
    if a.depuis and complet and lecture_rattrapee(conn, disponibles, global_publie):
        etat_ecrire(conn, "fenetre_lue_le", lu_le)
        etat_ecrire(conn, "global_vu", global_publie)
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
