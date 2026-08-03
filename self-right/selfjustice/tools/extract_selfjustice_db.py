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

Seuls les articles rattachés à un **code** sont retenus, comme le faisait
`build_legi_db.py` en ne lisant que les chemins `code_en_vigueur`. Le JORF et
les textes non codifiés sont écartés : l'API les référence par `?code=`, ils
n'auraient pas de sens ici.

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
    texte TEXT
)
"""

INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_articles_num ON articles(num)",
    "CREATE INDEX IF NOT EXISTS idx_articles_etat ON articles(etat)",
    "CREATE INDEX IF NOT EXISTS idx_articles_code ON articles(code_id)",
    "CREATE INDEX IF NOT EXISTS idx_articles_num_etat ON articles(num, etat)",
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
    return "\n".join(l.strip() for l in t.split("\n")).strip()


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--source", required=True, help="base produite par legi.py")
    p.add_argument("--dest", required=True, help="base plate pour l'API")
    p.add_argument("--natures", default="CODE",
                   help="natures de textes retenues (défaut : CODE)")
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
    placeholders = ",".join("?" * len(natures))

    # Un cid peut avoir plusieurs versions ; on retient le titre de la version
    # en vigueur, à défaut la plus récente.
    print(f"Lecture des textes de nature {natures}...")
    titres = {}
    for r in src.execute(
        f"""SELECT cid, titrefull, etat, date_debut
              FROM textes_versions
             WHERE nature IN ({placeholders})
             ORDER BY (etat = 'VIGUEUR') ASC, date_debut ASC""",
        natures,
    ):
        titres[r["cid"]] = r["titrefull"]
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
        titre = titres.get(r["cid"])
        if titre is None:
            continue  # article hors des natures retenues
        lot.append((r["id"], r["num"], r["etat"], r["date_debut"], r["date_fin"],
                    r["cid"], titre, en_texte_brut(r["bloc_textuel"])))
        if len(lot) >= 5000:
            dst.executemany("INSERT OR REPLACE INTO articles VALUES (?,?,?,?,?,?,?,?)", lot)
            n += len(lot)
            lot.clear()
            print(f"  {n} articles...", end="\r", flush=True)
    if lot:
        dst.executemany("INSERT OR REPLACE INTO articles VALUES (?,?,?,?,?,?,?,?)", lot)
        n += len(lot)

    print(f"  {n} articles extraits" + " " * 20)

    for sql in INDEXES:
        dst.execute(sql)
    dst.commit()

    vigueur = dst.execute("SELECT COUNT(*) FROM articles WHERE etat='VIGUEUR'").fetchone()[0]
    dst.close()
    src.close()

    # Garde-fou : une extraction qui rendrait une base vide ou ridicule ne doit
    # pas remplacer une base qui fonctionne.
    if n < 100000:
        print(f"ERREUR : seulement {n} articles, la base précédente est conservée",
              file=sys.stderr)
        return 1

    Path(tmp).replace(args.dest)
    print(f"Terminé : {n} articles ({vigueur} en vigueur) → {args.dest}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
