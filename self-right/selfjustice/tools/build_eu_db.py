#!/usr/bin/env python3
"""
SelfJustice — Construction de la base de conventionnalité (droit UE + CEDH).

Télécharge les textes depuis leurs sources officielles et les stocke dans
conventionnalite.sqlite avec la même structure que legi_selfjustice.sqlite.

Sources :
  - EUR-Lex (CELEX) pour Charte UE, TUE, TFUE, règlements
  - echr.coe.int pour la CEDH et ses protocoles

Usage :
    python3 build_eu_db.py --db /path/to/conventionnalite.sqlite
"""

import argparse
import re
import sqlite3
import sys
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import URLError


SOURCES = {
    "CHARTE_UE": {
        "celex": "12016P/TXT",
        "url": "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:12016P/TXT",
        "titre": "Charte des droits fondamentaux de l'Union européenne (version consolidée 2016)",
        "date_debut": "2009-12-01",
    },
    "TUE": {
        "celex": "12016M/TXT",
        "url": "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:12016M/TXT",
        "titre": "Traité sur l'Union européenne (version consolidée)",
        "date_debut": "2009-12-01",
    },
    "TFUE": {
        "celex": "12016E/TXT",
        "url": "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:12016E/TXT",
        "titre": "Traité sur le fonctionnement de l'Union européenne (version consolidée)",
        "date_debut": "2009-12-01",
    },
    "RGPD": {
        "celex": "32016R0679",
        "url": "https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:32016R0679",
        "titre": "Règlement général sur la protection des données (RGPD)",
        "date_debut": "2018-05-25",
    },
    "CEDH": {
        "celex": None,
        "url": "https://www.echr.coe.int/documents/d/echr/convention_fra",
        "titre": "Convention européenne des droits de l'homme (CEDH)",
        "date_debut": "1953-09-03",
    },
}


HTTP_HEADERS = {
    "User-Agent": "Mozilla/5.0 (SelfJustice build; open source legal tool)",
    "Accept": "text/html,application/xml;q=0.9",
    "Accept-Language": "fr-FR,fr;q=0.9",
}


def fetch_url(url: str, timeout: int = 60, max_retries: int = 5) -> bytes:
    """Télécharger une URL avec headers appropriés et retry sur HTTP 202.

    EUR-Lex renvoie souvent HTTP 202 (Accepted) pendant la génération
    de la page. Il faut attendre et réessayer.
    """
    import time

    for attempt in range(max_retries):
        req = Request(url, headers=HTTP_HEADERS)
        try:
            with urlopen(req, timeout=timeout) as resp:
                status = resp.status
                data = resp.read()
                if status == 200 and data:
                    return data
                if status == 202 or not data:
                    wait = 2 * (attempt + 1)
                    print(f"  HTTP {status} — attente {wait}s puis retry ({attempt+1}/{max_retries})...")
                    time.sleep(wait)
                    continue
                return data
        except URLError as e:
            wait = 2 * (attempt + 1)
            print(f"  Erreur réseau : {e} — retry dans {wait}s ({attempt+1}/{max_retries})")
            time.sleep(wait)

    print(f"  ÉCHEC après {max_retries} tentatives : {url}", file=sys.stderr)
    return b""


def strip_html(html: str) -> str:
    """Retirer les tags HTML, garder le texte."""
    # Supprimer scripts et styles
    html = re.sub(r"<script[^>]*>.*?</script>", "", html, flags=re.DOTALL | re.IGNORECASE)
    html = re.sub(r"<style[^>]*>.*?</style>", "", html, flags=re.DOTALL | re.IGNORECASE)
    # Remplacer les balises de saut de ligne
    html = re.sub(r"<br\s*/?>", "\n", html, flags=re.IGNORECASE)
    html = re.sub(r"</p>", "\n\n", html, flags=re.IGNORECASE)
    html = re.sub(r"</div>", "\n", html, flags=re.IGNORECASE)
    # Retirer toutes les autres balises
    html = re.sub(r"<[^>]+>", "", html)
    # Décoder les entités HTML basiques
    html = (
        html.replace("&nbsp;", " ")
        .replace("&amp;", "&")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", '"')
        .replace("&#8217;", "'")
        .replace("&#8216;", "'")
        .replace("&#8220;", '"')
        .replace("&#8221;", '"')
        .replace("&#8211;", "-")
        .replace("&#8212;", "—")
        .replace("&#8230;", "…")
    )
    # Nettoyer les espaces
    html = re.sub(r"[ \t]+", " ", html)
    html = re.sub(r"\n{3,}", "\n\n", html)
    return html.strip()


def parse_articles_from_plaintext(text: str, source: str) -> list[dict]:
    """Parser le texte brut (ex : PDF converti) en articles.

    Approche : détection stricte des lignes qui commencent par 'Article X',
    avec détection des sections (Convention principale, Protocole N° X) pour
    éviter les collisions d'IDs entre protocoles qui ont chacun leurs articles.
    """
    articles = []

    # Nettoyer le texte : uniformiser les fins de ligne
    text = text.replace("\r\n", "\n").replace("\r", "\n")

    # Détecter les bornes de sections (pour CEDH et similaires)
    # Les VRAIS débuts de sections suivent toujours le pattern :
    #   "Protocole additionnel\nà la Convention de sauvegarde..." (pour P1)
    #   "Protocole n°\nX\nà la Convention de sauvegarde..." (pour P4, P6, etc.)
    # Cela distingue les sections de leur mention dans la table des matières
    # (où elles sont suivies de "....33" ou similaire).

    # Pattern multi-ligne qui cherche "Protocole [...] à la Convention de sauvegarde"
    # Le numéro de protocole peut être sur plusieurs lignes (à cause de pdftotext -raw)
    section_pattern = re.compile(
        r"Protocole\s+(?:(additionnel)|n[°\s]\s*\n?\s*(\d+))\s*\n?\s*à\s+la\s+Convention\s+de\s+sauvegarde",
        re.IGNORECASE,
    )

    section_matches = list(section_pattern.finditer(text))

    # Construire la liste des (début, fin, préfixe)
    sections = []
    # Première section = texte principal (avant le premier protocole)
    first_protocol_start = section_matches[0].start() if section_matches else len(text)
    if first_protocol_start > 0:
        sections.append((0, first_protocol_start, "MAIN"))

    for i, m in enumerate(section_matches):
        start = m.start()
        end = section_matches[i + 1].start() if i + 1 < len(section_matches) else len(text)
        if m.group(1):  # "PROTOCOLE ADDITIONNEL"
            prefix = "P1"
        else:  # "PROTOCOLE N° X"
            prefix = f"P{m.group(2)}"
        sections.append((start, end, prefix))

    # Chercher les articles dans chaque section
    article_pattern = re.compile(
        r"^\s*ARTICLE\s+(\d+(?:\s*bis|\s*ter|\s*quater)?)\s*$",
        re.IGNORECASE | re.MULTILINE,
    )

    for sec_start, sec_end, prefix in sections:
        section_text = text[sec_start:sec_end]
        matches = list(article_pattern.finditer(section_text))

        for i, match in enumerate(matches):
            num = match.group(1).strip().lower()
            num = re.sub(r"\s+", "-", num)

            art_start = match.end()
            art_end = matches[i + 1].start() if i + 1 < len(matches) else len(section_text)

            content = section_text[art_start:art_end].strip()
            if not content:
                continue

            # Le titre est souvent la première ligne non vide
            lines = [ligne.strip() for ligne in content.split("\n") if ligne.strip()]
            titre = ""
            if lines and len(lines[0]) < 150:
                titre = lines[0]
                texte = "\n".join(lines[1:]).strip()
            else:
                texte = content

            # ID composé : source + section + numéro
            art_id = f"{source}-{prefix}-{num}" if prefix != "MAIN" else f"{source}-{num}"
            # Numéro affiché : inclure le préfixe de protocole si applicable
            display_num = f"P{prefix[1:]}-{num}" if prefix.startswith("P") else num

            articles.append({
                "id": art_id,
                "source": source,
                "num": display_num,
                "titre": titre,
                "texte": texte,
            })

    return articles


def parse_articles(html_bytes, source: str) -> list[dict]:
    """Extraire les articles depuis le HTML structuré EUR-Lex.

    EUR-Lex utilise des classes CSS spécifiques :
    - <p class="ti-art"> = titre d'article (ex: "Article 1")
    - <p class="sti-art"> = sous-titre d'article (ex: "Dignité humaine")
    - Le contenu jusqu'au prochain "ti-art" est le corps de l'article.

    On utilise lxml pour un parsing structuré. Accepte bytes ou str.
    """
    from lxml import html as lhtml

    articles = []
    # Convertir en str si bytes, retirer la déclaration XML éventuelle
    if isinstance(html_bytes, bytes):
        html_str = html_bytes.decode("utf-8", errors="replace")
    else:
        html_str = html_bytes

    # Retirer la déclaration XML qui fait planter lhtml.fromstring
    html_str = re.sub(r"^\s*<\?xml[^>]*\?>\s*", "", html_str)

    try:
        tree = lhtml.fromstring(html_str)
    except Exception as e:
        print(f"  ERREUR parsing HTML : {e}", file=sys.stderr)
        return articles

    # Collecter tous les éléments <p class="ti-art"> dans l'ordre du document
    # EUR-Lex utilise deux types de ti-art :
    #   - ceux qui contiennent "Article X" = début d'article
    #   - ceux qui contiennent un titre (ex: "Dignité humaine") = titre du dernier article
    all_ti_arts = tree.xpath('//p[contains(@class, "ti-art")]')

    # Identifier les débuts d'article (ceux qui COMMENCENT par "Article X")
    # Accepte "Article 1", "Article 1er", "Article premier", "Article 1 bis",
    # "Article 1 (ex-article 1 TCE)", etc.
    art_pattern = re.compile(
        r"^\s*Article\s+(\d+(?:\s*bis|\s*ter|\s*quater)?|premier|1er)\b",
        re.IGNORECASE,
    )
    article_starts = []
    for ti in all_ti_arts:
        title_text = ti.text_content().strip()
        m = art_pattern.match(title_text)
        if m:
            num = m.group(1).strip().lower()
            if num in ("premier", "1er"):
                num = "1"
            num = re.sub(r"\s+", "-", num)
            article_starts.append((ti, num))

    for i, (ti_start, num) in enumerate(article_starts):
        # Le prochain article = borne de fin
        next_start = article_starts[i + 1][0] if i + 1 < len(article_starts) else None

        # Collecter tous les éléments entre ti_start et next_start
        titre = ""
        contenu_parts = []
        elem = ti_start.getnext()
        titre_captured = False

        while elem is not None and elem is not next_start:
            classes = elem.get("class", "") or ""
            text = elem.text_content().strip()

            if "ti-art" in classes and not titre_captured and text:
                # Premier ti-art après le début = titre de l'article
                # (seulement si ce n'est pas un autre "Article X")
                if not art_pattern.match(text):
                    titre = text
                    titre_captured = True
            elif text:
                contenu_parts.append(text)

            elem = elem.getnext()

        texte = "\n\n".join(contenu_parts).strip()

        articles.append({
            "id": f"{source}-{num}",
            "source": source,
            "num": num,
            "titre": titre,
            "texte": texte,
        })

    return articles


def create_db(db_path: str) -> sqlite3.Connection:
    """Créer la base SQLite conventionnalité."""
    conn = sqlite3.connect(db_path)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS articles (
            id TEXT PRIMARY KEY,
            source TEXT NOT NULL,
            num TEXT NOT NULL,
            titre TEXT,
            texte TEXT,
            etat TEXT DEFAULT 'VIGUEUR',
            date_debut TEXT,
            date_fin TEXT,
            url_source TEXT
        )
    """)
    conn.execute("CREATE INDEX IF NOT EXISTS idx_source ON articles(source)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_num ON articles(num)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_source_num ON articles(source, num)")
    conn.commit()
    return conn


def process_source(conn: sqlite3.Connection, source: str, info: dict) -> int:
    """Télécharger et parser une source, retourne le nombre d'articles insérés."""
    print(f"[{source}] Téléchargement : {info['url']}")
    raw = fetch_url(info["url"])

    if not raw:
        print(f"[{source}] Téléchargement échoué — passe à la source suivante")
        return 0

    # Si c'est du PDF (CEDH), utiliser pdftotext pour convertir
    if raw[:4] == b"%PDF":
        import subprocess
        import tempfile
        print(f"[{source}] PDF détecté — conversion via pdftotext")
        with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as f:
            f.write(raw)
            pdf_path = f.name
        try:
            # -raw préserve l'ordre de lecture naturel sans essayer de conserver
            # la disposition spatiale (meilleur pour PDF 2 colonnes comme la CEDH)
            result = subprocess.run(
                ["pdftotext", "-raw", pdf_path, "-"],
                capture_output=True, text=True, timeout=30
            )
            text = result.stdout
            articles = parse_articles_from_plaintext(text, source)
        finally:
            Path(pdf_path).unlink(missing_ok=True)
    else:
        articles = parse_articles(raw, source)

    print(f"[{source}] {len(articles)} articles extraits")

    for art in articles:
        conn.execute("""
            INSERT OR REPLACE INTO articles
            (id, source, num, titre, texte, etat, date_debut, url_source)
            VALUES (?, ?, ?, ?, ?, 'VIGUEUR', ?, ?)
        """, (
            art["id"], art["source"], art["num"],
            art["titre"], art["texte"],
            info["date_debut"], info["url"],
        ))

    conn.commit()
    return len(articles)


def main():
    parser = argparse.ArgumentParser(description="Construction base conventionnalité UE+CEDH")
    parser.add_argument("--db", required=True, help="Chemin vers la base SQLite de sortie")
    parser.add_argument("--only", help="Source unique à traiter (CHARTE_UE, TUE, TFUE, RGPD, CEDH)")
    args = parser.parse_args()

    conn = create_db(args.db)
    total = 0

    sources_to_process = [args.only] if args.only else list(SOURCES.keys())

    for source in sources_to_process:
        if source not in SOURCES:
            print(f"Source inconnue : {source}", file=sys.stderr)
            continue
        n = process_source(conn, source, SOURCES[source])
        total += n

    conn.close()

    print(f"\nTerminé ! {total} articles insérés dans {args.db}")


if __name__ == "__main__":
    main()
