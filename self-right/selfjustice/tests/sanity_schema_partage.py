#!/usr/bin/env python3
"""Les deux collecteurs écrivent dans la MÊME table `decisions`.

Chacun était éprouvé seul, sur une base que lui-même venait de créer. Ils ne
s'étaient jamais rencontrés — et le 15/09/2026 à 06:04 la moisson Judilibre est
morte au premier lot :

    sqlite3.OperationalError: table decisions has 15 columns but 13 values were supplied

Le collecteur JADE avait ajouté `texte` et `source` le 11/09 ; le moissonneur
Judilibre insérait par `VALUES (?,?,…)` sans nommer ses colonnes, donc lié au
NOMBRE de colonnes de la table. Le service a restauré l'index précédent — rien
n'a été perdu — mais la cadence du 15 a sauté, et l'API a annoncé son retard
pendant quinze jours.

⚠️ Ce banc est le seul endroit où les deux scripts touchent une même base. Le
défaut symétrique s'était déjà produit le 10/09 dans l'autre sens : la route
`decision` de l'API supposait une base MIGRÉE et rendait 500 sur une base qui ne
l'était pas. Même angle mort, deux directions — d'où les deux ordres ci-dessous.
"""

import os
import sqlite3
import sys
import tempfile

ICI = os.path.dirname(os.path.abspath(__file__))
OUTILS = os.path.join(os.path.dirname(ICI), "tools")

ECHECS = []
CLE_FACTICE = ""    # posée par main(), dans le répertoire temporaire


def controle(nom, condition, detail=""):
    if condition:
        print("  \033[32m✓\033[0m %s" % nom)
    else:
        print("  \033[31m✗\033[0m %s%s" % (nom, (" — " + detail) if detail else ""))
        ECHECS.append(nom)


DECISION_JUDILIBRE = {
    "id": "aaaa000000000001",
    "number": "23-10.001",
    "decision_date": "2026-09-15",
    "jurisdiction": "cc",
    "chamber": "soc",
    "location": None,
    "formation": "fs_b",
    "publication": "publié",
    "solution": "cassation",
    "ecli": "ECLI:FR:CCASS:2026:SO00001",
    "type": "arret",
    "update_date": "2026-09-15",
}

LIGNE_JADE = (
    "CETATEXT000000000001", "400001", "2026-09-15", "ce", None, "Paris", None,
    "publie", None, None, None, "2026-09-15", 0, "Vu la procédure suivante :", "jade",
)


def charger(nom, chemin_db):
    """Importe un collecteur avec sa base pointée sur `chemin_db`.

    Le module lit son chemin de base au CHARGEMENT : l'environnement se pose
    avant l'import, et le module se retire du cache pour que le suivant relise.

    ⚠️ La clé factice n'est pas un détail. Sans `JUDILIBRE_KEY_FILE`, le
    moissonneur cherche `~/.config/judilibre/keyid` et meurt à l'import — ce
    banc passait donc en local, où ce fichier personnel existe, et échouait sur
    un runner qui ne l'a pas. Même procédé que `sanity_refus_amont.sh`. Aucun
    appel réseau n'est fait ici : la clé n'a qu'à être lisible.
    """
    os.environ["JUDILIBRE_DB"] = chemin_db
    os.environ["JUDILIBRE_KEY_FILE"] = CLE_FACTICE
    os.environ["JUDILIBRE_MARQUEUR"] = chemin_db + ".marqueur"
    sys.path.insert(0, OUTILS)
    for m in ("build_judilibre_index", "build_jade_db", "jade_juridictions"):
        sys.modules.pop(m, None)
    return __import__(nom)


def colonnes(conn):
    return [r[1] for r in conn.execute("PRAGMA table_info(decisions)")]


# La requête de couverture, recopiée de `api/api.php:805`. C'est elle que sert
# `/api/status`, et c'est la seule des trois requêtes de l'API sur `decisions`
# qui agrège — les deux autres passent par `idx_source` et par la clé primaire.
REQUETE_COUVERTURE = (
    "SELECT jurisdiction, MIN(decision_date) AS debut, MAX(decision_date) AS fin, "
    "COUNT(*) AS total FROM decisions WHERE date_suspecte = 0 GROUP BY jurisdiction"
)


def plan(conn):
    """Ce que SQLite dit qu'il va faire, en une ligne."""
    return " ; ".join(r[3] for r in
                      conn.execute("EXPLAIN QUERY PLAN " + REQUETE_COUVERTURE))


def controle_couverture(conn, qui):
    """La couverture se calcule SANS lire la table. C'est tout ce qu'on exige.

    🔑 Ce banc ne chronomètre rien : une durée dépend de la machine, du cache
    disque et du volume, et un seuil en secondes rendrait vert sur un runner
    vide quel que soit le plan. Ce qui se vérifie ici est la PROPRIÉTÉ dont la
    vitesse découle — SQLite répond depuis l'index seul. Il le dit lui-même :
    « USING COVERING INDEX ». Sans `idx_couverture`, le même plan annonce
    « SCAN decisions USING INDEX idx_juri », c'est-à-dire un accès à la table
    par ligne ; sur l'instance, au 16/09/2026, cela faisait 1,77 M accès à
    6,1 Go et dépassait les 20 s de la sentinelle de fraîcheur dès que le cache
    était froid.
    """
    p = plan(conn)
    controle("%s : la couverture se lit dans l\u2019index, pas dans la table" % qui,
             "COVERING INDEX" in p and "idx_couverture" in p, p)


def controle_index_present(conn, qui):
    noms = {r[0] for r in conn.execute(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='decisions'")}
    controle("%s : `idx_couverture` existe" % qui, "idx_couverture" in noms,
             "index présents : %s" % ", ".join(sorted(noms)))


def sens_judilibre_dabord(tmp):
    """Judilibre crée, JADE migre, Judilibre réécrit — l'ordre du 15/09."""
    db = os.path.join(tmp, "a.sqlite")
    print("\n\033[1m▸ Judilibre crée la base, JADE la migre, Judilibre y réécrit\033[0m")

    jud = charger("build_judilibre_index", db)
    conn = jud.ouvrir_base()
    controle("la base neuve porte 13 colonnes", len(colonnes(conn)) == 13,
             "%d colonnes" % len(colonnes(conn)))
    controle_index_present(conn, "base créée par Judilibre")
    controle_couverture(conn, "base créée par Judilibre")
    conn.close()

    jade = charger("build_jade_db", db)
    conn = jade.ouvrir_base(db)
    cols = colonnes(conn)
    controle("après migration JADE, `texte` et `source` sont là",
             "texte" in cols and "source" in cols)
    controle("la table porte désormais 15 colonnes", len(cols) == 15,
             "%d colonnes" % len(cols))
    conn.close()

    # Le cœur du banc : le moissonneur écrit dans la table qu'un autre a élargie.
    jud = charger("build_judilibre_index", db)
    conn = jud.ouvrir_base()
    try:
        n = jud.enregistrer(conn, [DECISION_JUDILIBRE])
        conn.commit()
        ecrit, erreur = n == 1, ""
    except sqlite3.OperationalError as e:
        ecrit, erreur = False, str(e)
    controle("le moissonneur Judilibre écrit sur une base migrée", ecrit, erreur)

    if ecrit:
        row = conn.execute(
            "SELECT source, texte, jurisdiction FROM decisions WHERE id=?",
            (DECISION_JUDILIBRE["id"],)).fetchone()
        controle("sa décision prend `source='judilibre'` par défaut",
                 row and row[0] == "judilibre", "source=%r" % (row[0] if row else None))
        controle("son `texte` reste vide — Judilibre ne sert pas le texte",
                 row and row[1] is None)
        controle("la juridiction est intacte", row and row[2] == "cc")
        controle("son numéro est indexé sous le bon nom de colonne",
                 conn.execute("SELECT COUNT(*) FROM numeros WHERE decision_id=?",
                              (DECISION_JUDILIBRE["id"],)).fetchone()[0] == 1)
    conn.close()


def sens_jade_dabord(tmp):
    """JADE crée la base, Judilibre y écrit — l'ordre d'une instance neuve."""
    db = os.path.join(tmp, "b.sqlite")
    print("\n\033[1m▸ JADE crée la base, Judilibre y écrit ensuite\033[0m")

    jade = charger("build_jade_db", db)
    conn = jade.ouvrir_base(db)
    jade.ecrire(conn, [LIGNE_JADE])
    controle("JADE écrit sur sa propre base",
             conn.execute("SELECT COUNT(*) FROM decisions WHERE source='jade'")
                 .fetchone()[0] == 1)
    controle_index_present(conn, "base créée par JADE")
    controle_couverture(conn, "base créée par JADE")
    conn.close()

    jud = charger("build_judilibre_index", db)
    conn = jud.ouvrir_base()
    try:
        n = jud.enregistrer(conn, [DECISION_JUDILIBRE])
        conn.commit()
        ecrit, erreur = n == 1, ""
    except sqlite3.OperationalError as e:
        ecrit, erreur = False, str(e)
    controle("le moissonneur Judilibre écrit sur une base créée par JADE",
             ecrit, erreur)
    controle("les deux sources cohabitent dans la même table",
             conn.execute("SELECT COUNT(DISTINCT source) FROM decisions")
                 .fetchone()[0] == 2)
    controle("la décision JADE garde son texte intégral",
             (conn.execute("SELECT texte FROM decisions WHERE source='jade'")
                  .fetchone() or [None])[0] == "Vu la procédure suivante :")
    conn.close()


def main():
    global CLE_FACTICE
    print("\033[1mLes deux collecteurs partagent la table `decisions`\033[0m")
    with tempfile.TemporaryDirectory() as tmp:
        CLE_FACTICE = os.path.join(tmp, "keyid")
        with open(CLE_FACTICE, "w", encoding="utf-8") as f:
            f.write("factice\n")
        sens_judilibre_dabord(tmp)
        sens_jade_dabord(tmp)

    print()
    if ECHECS:
        print("\033[31m✗ %d contrôle(s) en échec : %s\033[0m"
              % (len(ECHECS), " · ".join(ECHECS)))
        return 1
    print("\033[32m✓ les deux collecteurs écrivent dans la même table, "
          "dans les deux ordres\033[0m")
    return 0


if __name__ == "__main__":
    sys.exit(main())
