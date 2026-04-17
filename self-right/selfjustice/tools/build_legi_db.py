#!/usr/bin/env python3
"""
SelfJustice — Extraction directe des articles LEGI depuis le dump tarball.
Contourne legi.py (bugué sur les dumps récents) en parsant le XML nous-mêmes.

Construit une base SQLite minimaliste avec : num, etat, code_titre.

Usage :
    python3 build_legi_db.py --tarball /path/to/Freemium_legi_global_*.tar.gz --db /path/to/legi_selfjustice.sqlite
"""

import argparse
import sqlite3
import sys
import tarfile
from pathlib import Path
from xml.etree import ElementTree as ET


def create_db(db_path: str):
    """Créer la base SQLite."""
    conn = sqlite3.connect(db_path)
    conn.execute("""
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
    """)
    conn.execute("CREATE INDEX IF NOT EXISTS idx_num ON articles(num)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_etat ON articles(etat)")
    conn.commit()
    return conn


def parse_article_xml(xml_content: bytes, filename: str = '') -> dict | None:
    """Parser un fichier XML d'article LEGI."""
    try:
        root = ET.fromstring(xml_content)
        meta = root.find('.//META_SPEC/META_ARTICLE')
        if meta is None:
            return None

        # L'ID vient du nom de fichier : LEGIARTI000006XXXXX.xml
        article_id = root.get('id') or ''
        if not article_id and filename:
            import os
            article_id = os.path.basename(filename).replace('.xml', '')

        num = meta.findtext('NUM', '')
        etat = meta.findtext('ETAT', '')
        date_debut = meta.findtext('DATE_DEBUT', '')
        date_fin = meta.findtext('DATE_FIN', '')

        # Extraire le texte : élément <CONTENU> ou <BLOC_TEXTUEL>/<CONTENU>
        contenu_elem = root.find('.//BLOC_TEXTUEL/CONTENU')
        if contenu_elem is None:
            contenu_elem = root.find('.//CONTENU')
        texte = ''
        if contenu_elem is not None:
            # Récupérer tout le texte (en préservant les retours ligne minimaux)
            texte_parts = []
            for t in contenu_elem.itertext():
                t = t.strip()
                if t:
                    texte_parts.append(t)
            texte = '\n'.join(texte_parts).strip()

        return {
            'id': article_id,
            'num': num,
            'etat': etat,
            'date_debut': date_debut,
            'date_fin': date_fin,
            'texte': texte,
        }
    except ET.ParseError:
        return None


def parse_texte_xml(xml_content: bytes) -> dict | None:
    """Parser un fichier XML de texte (code) pour obtenir le titre."""
    try:
        root = ET.fromstring(xml_content)
        titrefull = root.findtext('.//META/META_SPEC/META_TEXTE_CHRONICLE/TITRE', '')
        if not titrefull:
            titrefull = root.findtext('.//META/META_COMMUN/TITREFULL', '')
        texte_id = root.get('id', '')
        return {'id': texte_id, 'titre': titrefull}
    except ET.ParseError:
        return None


def main():
    parser = argparse.ArgumentParser(description="Extraction articles LEGI → SQLite")
    parser.add_argument("--tarball", required=True, help="Chemin vers le tarball global LEGI")
    parser.add_argument("--db", required=True, help="Chemin vers la base SQLite de sortie")
    args = parser.parse_args()

    if not Path(args.tarball).exists():
        print(f"ERREUR : tarball introuvable : {args.tarball}", file=sys.stderr)
        sys.exit(1)

    conn = create_db(args.db)
    codes = {}  # texte_id → titre
    articles_count = 0
    codes_count = 0

    print(f"Ouverture du tarball : {args.tarball}")
    print("Extraction des codes en vigueur uniquement...")

    with tarfile.open(args.tarball, 'r:gz') as tar:
        for member in tar:
            if not member.isfile():
                continue

            # Ne traiter que les codes en vigueur
            if 'code_en_vigueur' not in member.name:
                continue

            if member.name.endswith('.xml'):
                f = tar.extractfile(member)
                if f is None:
                    continue
                content = f.read()

                # Fichier de texte/version (info sur le code)
                if '/texte/version/' in member.name:
                    info = parse_texte_xml(content)
                    if info and info['titre']:
                        codes[info['id']] = info['titre']
                        codes_count += 1
                        if codes_count % 10 == 0:
                            print(f"  Codes trouvés : {codes_count}", end='\r')

                # Fichier d'article
                elif '/article/LEGI/ARTI/' in member.name:
                    art = parse_article_xml(content, member.name)
                    if art and art['num']:
                        # Trouver le code parent depuis le chemin
                        # Ex: .../LEGITEXT000006072050/article/...
                        parts = member.name.split('/')
                        code_id = ''
                        for p in parts:
                            if p.startswith('LEGITEXT'):
                                code_id = p
                                break

                        code_titre = codes.get(code_id, '')

                        conn.execute("""
                            INSERT OR REPLACE INTO articles
                            (id, num, etat, date_debut, date_fin, code_id, code_titre, texte)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        """, (
                            art['id'], art['num'], art['etat'],
                            art['date_debut'], art['date_fin'],
                            code_id, code_titre,
                            art.get('texte', '')
                        ))
                        articles_count += 1
                        if articles_count % 1000 == 0:
                            print(f"  Articles extraits : {articles_count}", end='\r')
                            conn.commit()

    conn.commit()
    conn.close()

    print(f"\nTerminé !")
    print(f"  Codes trouvés : {codes_count}")
    print(f"  Articles extraits : {articles_count}")
    print(f"  Base SQLite : {args.db}")


if __name__ == "__main__":
    main()
