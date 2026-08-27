#!/usr/bin/env python3
"""
SelfJustice — extraction de la base API depuis celle produite par legi.py.

## Pourquoi cette étape existe

`legi.py` produit une base riche : huit tables liées, l'historique des versions,
les liens entre textes, les sommaires. L'API de SelfJustice, elle, répond à une
question simple — « que dit l'article L1152-1 du code du travail ? » — et n'a
besoin que d'une table plate.

Historiquement, `build_legi_db.py` parsait le tarball directement pour produire
cette table plate. C'était plus simple, mais ça avait un coût invisible : ce
script ne lit **que le dump global**, jamais les diffs quotidiens. La base est
donc restée figée au 13 juillet 2025 pendant treize mois, en l'annonçant
honnêtement sans que personne ne s'en aperçoive.

`legi.py` applique les diffs. Ce script prend le relais et aplatit son résultat
au format qu'attend `api/api.php`.

## Correspondance des schémas

    legi.py                       SelfJustice
    articles.id                → id
    articles.num               → num
    articles.etat              → etat
    articles.date_debut        → date_debut
    articles.date_fin          → date_fin
    articles.cid               → code_id
    textes_versions.titrefull  → code_titre   (joint sur cid)
    articles.bloc_textuel      → texte        (HTML converti en texte brut)

## Périmètre

Tout le droit national consolidé que porte la base amont : les codes, mais aussi
les arrêtés, décrets, lois et ordonnances. `--natures` sert à restreindre ; il ne
restreint plus rien par défaut.

Ce défaut a longtemps valu `CODE`, et il coûtait cher sans le dire. Le règlement
de sécurité contre l'incendie — arrêté du 25 juin 1980, articles DF, GN, R — était
sur la machine et jeté ici. Une recherche « exutoire » rendait zéro, « désenfumage »
un seul renvoi, et ce vide s'affichait comme une réponse. Mesuré le 26/08/2026 :
159 602 articles en vigueur avec le filtre, 678 853 sans.

Restent dehors, parce qu'elles sont d'autres bases DILA : les conventions
collectives (KALI), les circulaires, les textes locaux.

Usage :
    python3 extract_selfjustice_db.py --source legi-full.sqlite \\
                                      --dest legi_selfjustice.sqlite
"""

import argparse
import re
import sqlite3
import sys
from html import unescape
from pathlib import Path


SCHEMA = """
CREATE TABLE IF NOT EXISTS articles (
    id TEXT PRIMARY KEY,
    num TEXT,
    etat TEXT,
    date_debut TEXT,
    date_fin TEXT,
    code_id TEXT,
    code_titre TEXT,
    nature TEXT,
    texte TEXT
)
"""

# Les colonnes sont nommées, jamais positionnelles : l'ajout de `nature` aurait
# sinon décalé `texte` d'un cran sans qu'aucune erreur ne le signale.
INSERT = """INSERT OR REPLACE INTO articles
    (id, num, etat, date_debut, date_fin, code_id, code_titre, nature, texte)
    VALUES (?,?,?,?,?,?,?,?,?)"""

INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_articles_num ON articles(num)",
    "CREATE INDEX IF NOT EXISTS idx_articles_etat ON articles(etat)",
    "CREATE INDEX IF NOT EXISTS idx_articles_code ON articles(code_id)",
    "CREATE INDEX IF NOT EXISTS idx_articles_num_etat ON articles(num, etat)",
    # L'API s'en sert pour deux questions qui n'ont de sens que depuis que la
    # base porte autre chose que des codes : nommer les codes en clair, et
    # désambiguïser un numéro d'article sans noyer l'appelant. 147 870 textes
    # portent un article « 1 » ; 111 seulement sont des codes.
    "CREATE INDEX IF NOT EXISTS idx_articles_nature ON articles(nature)",
]

# Index plein texte des articles en vigueur.
#
# Sans lui, /api/legi/search ne peut regarder que la colonne `num`, et une
# recherche par mots — « licenciement », « chanvre » — rend zéro sur des
# centaines de milliers d'articles. Le client en conclut que le droit est
# muet ; c'est l'index qui manquait.
#
# `content=articles` : l'index ne recopie pas le texte, il pointe vers la
# table. Son coût en place est une fraction de la base, pas un doublement.
#
# `remove_diacritics 2` pour que « prenom » trouve « prénom » — personne ne
# tape les accents dans une barre de recherche.
#
# Seuls les articles en vigueur : c'est ce que la recherche rend, et les
# abrogés restent consultables par leur numéro.
#
# 🔑 Et seuls ceux qui PORTENT un numéro.
#
# Un article sans numéro n'est pas adressable : `/legi/article/{ref}` cherche
# par `num`, et une ligne de résultat sans référence ne peut être ni citée ni
# rouverte. Le guichet dit pourtant « appelle `article_francais` sur la
# référence retenue » — sur une ligne vide, c'est une impasse présentée comme
# une piste.
#
# Ils sont 6 tant que la base ne porte que des codes. Elle en compte 13 194 dès
# qu'elle porte les arrêtés (mesuré le 27/08/2026) : ce sont leurs annexes, dont
# beaucoup ne font que renvoyer au fac-similé. Le contenu n'est pas perdu, il
# n'est simplement plus proposé comme une réponse qu'on pourrait citer.
FTS = [
    """CREATE VIRTUAL TABLE IF NOT EXISTS articles_fts USING fts5(
        texte, code_titre, num,
        content=articles,
        tokenize="unicode61 remove_diacritics 2"
    )""",
    """INSERT INTO articles_fts(rowid, texte, code_titre, num)
        SELECT rowid, texte, code_titre, num FROM articles
         WHERE etat = 'VIGUEUR' AND num IS NOT NULL AND num != ''""",
]

_BALISE = re.compile(r"<[^>]+>")
_ESPACES = re.compile(r"[ \t]+")
_LIGNES = re.compile(r"\n{3,}")


def en_texte_brut(html: str | None) -> str:
    """Convertit le bloc textuel HTML en texte lisible.

    L'API sert ce champ tel quel à une IA qui le citera. Les balises ne lui
    apportent rien et polluent le contexte ; les sauts de ligne, en revanche,
    portent la structure des alinéas et doivent survivre.
    """
    if not html:
        return ""
    t = re.sub(r"<br\s*/?>", "\n", html, flags=re.I)
    t = re.sub(r"</(p|div|li)>", "\n", t, flags=re.I)
    t = _BALISE.sub("", t)
    t = unescape(t)
    t = _ESPACES.sub(" ", t)
    t = _LIGNES.sub("\n\n", t)
    return "\n".join(ligne.strip() for ligne in t.split("\n")).strip()


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--source", required=True, help="base produite par legi.py")
    p.add_argument("--dest", required=True, help="base plate pour l'API")
    # La base servie n'est jamais `--dest` en production : `update_legi.sh`
    # écrit dans un fichier neuf puis renomme, pour qu'une extraction à demi
    # faite ne soit jamais servie. Sans cette option, la garde de non-régression
    # ci-dessous ne verrait donc rien à comparer — et ne se déclencherait
    # jamais là où elle protège quelque chose.
    p.add_argument("--reference", default=None,
                   help="base servie à comparer pour la garde de non-régression "
                        "(défaut : --dest, si elle existe)")
    p.add_argument("--natures", default="",
                   help="natures de textes retenues, séparées par des virgules "
                        "(défaut : toutes)")
    args = p.parse_args()

    if not Path(args.source).exists():
        print(f"ERREUR : source introuvable : {args.source}", file=sys.stderr)
        return 1

    src = sqlite3.connect(f"file:{args.source}?mode=ro", uri=True, timeout=60)
    src.row_factory = sqlite3.Row

    # On écrit à côté puis on remplace : une base à demi construite ne doit
    # jamais être servie. Le basculement final est un simple rename.
    tmp = args.dest + ".tmp"
    Path(tmp).unlink(missing_ok=True)
    dst = sqlite3.connect(tmp)
    dst.execute("PRAGMA journal_mode=OFF")
    dst.execute("PRAGMA synchronous=OFF")
    dst.execute(SCHEMA)

    natures = tuple(n.strip() for n in args.natures.split(",") if n.strip())
    if natures:
        filtre = "WHERE nature IN (" + ",".join("?" * len(natures)) + ")"
        libelle = "de nature " + ", ".join(natures)
    else:
        filtre = ""
        libelle = "de toutes natures"

    # Un cid peut avoir plusieurs versions ; on retient le titre de la version
    # en vigueur, à défaut la plus récente. La nature, elle, ne varie pas d'une
    # version à l'autre — elle qualifie le texte, pas son état.
    print(f"Lecture des textes {libelle}...")
    titres = {}
    for r in src.execute(
        f"""SELECT cid, titrefull, nature, etat, date_debut
              FROM textes_versions
             {filtre}
             ORDER BY (etat = 'VIGUEUR') ASC, date_debut ASC""",
        natures,
    ):
        titres[r["cid"]] = (r["titrefull"], r["nature"])
    print(f"  {len(titres)} textes retenus")

    if not titres:
        print("ERREUR : aucun texte retenu — vérifier --natures contre la base",
              file=sys.stderr)
        return 1

    print("Extraction des articles...")
    n = 0
    lot = []
    for r in src.execute(
        """SELECT id, num, etat, date_debut, date_fin, cid, bloc_textuel
             FROM articles"""
    ):
        meta = titres.get(r["cid"])
        if meta is None:
            continue  # article hors des natures retenues
        titre, nature = meta
        lot.append((r["id"], r["num"], r["etat"], r["date_debut"], r["date_fin"],
                    r["cid"], titre, nature, en_texte_brut(r["bloc_textuel"])))
        if len(lot) >= 5000:
            dst.executemany(INSERT, lot)
            n += len(lot)
            lot.clear()
            print(f"  {n} articles...", end="\r", flush=True)
    if lot:
        dst.executemany(INSERT, lot)
        n += len(lot)

    print(f"  {n} articles extraits" + " " * 20)

    for sql in INDEXES:
        dst.execute(sql)
    dst.commit()

    print("  index plein texte…", end="", flush=True)
    for sql in FTS:
        dst.execute(sql)
    dst.commit()

    # 🔑 Ce compte vient de la table source, pas de `articles_fts`.
    #
    # L'index est déclaré `content=articles` : il ne stocke pas les documents,
    # il pointe vers la table. `COUNT(*)` sur une telle table FTS5 interroge
    # donc la SOURCE, et rend le nombre total d'articles — là où l'index n'en
    # porte que les articles en vigueur. Le message annonçait 525 441 articles
    # indexés pour 159 602 réels, et 1 820 519 pour 678 853 sur la base élargie.
    # Un chiffre de contrôle qui ne mesurait pas ce qu'il nommait.
    #
    # Ce que l'INSERT du FTS a réellement inséré, c'est ceci — une seule source
    # pour les deux usages, ce message et le bilan final.
    indexes = dst.execute(
        "SELECT COUNT(*) FROM articles "
        "WHERE etat = 'VIGUEUR' AND num IS NOT NULL AND num != ''").fetchone()[0]
    vigueur = dst.execute("SELECT COUNT(*) FROM articles WHERE etat='VIGUEUR'").fetchone()[0]
    print(f" {indexes} articles indexés ({vigueur} en vigueur)")
    dst.close()
    src.close()

    # Deux garde-fous : une extraction qui rendrait une base vide ou ridicule ne
    # doit pas remplacer une base qui fonctionne.
    if n < 100000:
        print(f"ERREUR : seulement {n} articles, la base précédente est conservée",
              file=sys.stderr)
        Path(tmp).unlink(missing_ok=True)
        return 1

    # Le second est le seul qui protège vraiment depuis que le périmètre s'élargit.
    #
    # Un plancher absolu ne voit pas la régression qui compte : repasser en
    # CODE-only rendrait 525 441 articles là où la base servie en porte
    # 1 820 519 — au-dessus de tout plancher raisonnable, et pourtant les deux
    # tiers du droit disparus. La garde se mesure donc contre ce qui est servi,
    # et elle ne se déclenche que lorsqu'on s'apprête à écraser quelque chose :
    # une extraction vers un fichier neuf reste libre.
    reference = args.reference or args.dest
    if Path(reference).exists():
        anc = sqlite3.connect(f"file:{reference}?mode=ro", uri=True)
        try:
            precedent = anc.execute("SELECT COUNT(*) FROM articles").fetchone()[0]
        except sqlite3.Error:
            precedent = 0
        anc.close()
        if precedent and n < precedent * 0.8:
            print(f"ERREUR : {n} articles contre {precedent} servis "
                  f"({100 * n // precedent} %). La base précédente est conservée.",
                  file=sys.stderr)
            Path(tmp).unlink(missing_ok=True)
            return 1

    Path(tmp).replace(args.dest)
    print(f"Terminé : {n} articles ({vigueur} en vigueur) → {args.dest}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
