#!/usr/bin/env python3
"""
SelfJustice — Script d'audit des articles de loi cités dans index.html
Vérifie chaque article contre la base LEGI SQLite (dump officiel Légifrance).

Usage :
    python3 audit_articles.py --db /path/to/legi.sqlite --html /path/to/index.html

Sortie :
    - Articles vérifiés (en vigueur)
    - Articles modifiés (numéro changé ou article abrogé)
    - Articles introuvables (potentiellement erronés)
"""

import argparse
import re
import sqlite3
import sys
from datetime import date
from pathlib import Path

from extract_selfjustice_db import ETATS_VIVANTS


def extract_articles_from_html(html_path: str) -> list[dict]:
    """Extraire tous les articles de loi cités dans le HTML.

    Règles :
    - Ignorer les plages d'articles (ex "1641-1649") qui ne sont pas des articles uniques
      mais une désignation "articles 1641 à 1649"
    - Ignorer les mentions historiques marquées "ex-XXXX"
    - Ne pas confondre les articles R avec des numéros commençant par R
    """
    with open(html_path, encoding="utf-8") as f:
        content = f.read()

    # Retirer les mentions historiques "ex-R1334-31" du contenu avant extraction
    content_cleaned = re.sub(r"ex-[LR]?\d+[-\d]*", "", content)

    articles = []

    # Articles L (Code du travail, consommation, etc.)
    # Distinguer L1232-1 (vrai article) de ce qui serait juste un L isolé
    for match in re.finditer(r"\b(L\d+-\d+(?:-\d+)?)\b", content_cleaned):
        articles.append({"ref": match.group(1), "type": "L"})

    # Articles R (réglementaires)
    for match in re.finditer(r"\b(R\d+-\d+(?:-\d+)?)\b", content_cleaned):
        articles.append({"ref": match.group(1), "type": "R"})

    # Articles du Code civil (numéros simples, pas de plage)
    # Plage = "1641-1649" : deux nombres séparés par tiret, de tailles comparables
    # On ignore les plages et on prend les numéros uniques suivis de "Code civil"
    cc_pattern = r"art(?:icle)?\.?\s*(\d{3,4}(?:-\d+)?)\s+(?:du\s+)?Code\s+civil"
    for match in re.finditer(cc_pattern, content_cleaned, re.IGNORECASE):
        ref = match.group(1)
        # Si "NNNN-NNNN" avec chiffres comparables, c'est une plage → ignorer
        if "-" in ref:
            parts = ref.split("-")
            if len(parts[0]) == len(parts[1]) and int(parts[0]) < int(parts[1]) and int(parts[1]) < 10000:
                continue  # Plage
        articles.append({"ref": ref, "type": "CC"})

    # Articles du Code pénal (format NNN-N-N, sans R)
    # On évite d'attraper "R623-2" comme "623-2"
    cp_pattern = r"(?<![LR])(?<!\d)(\d{3}-\d+(?:-\d+)?)\s+(?:du\s+)?Code\s+pénal"
    for match in re.finditer(cp_pattern, content_cleaned, re.IGNORECASE):
        articles.append({"ref": match.group(1), "type": "CP"})

    # Dédupliquer
    seen = set()
    unique = []
    for art in articles:
        key = f"{art['type']}:{art['ref']}"
        if key not in seen:
            seen.add(key)
            unique.append(art)

    return sorted(unique, key=lambda x: (x["type"], x["ref"]))


# 🔑 La même règle que `legi_sql_applicable()` et le même ordre que
# `legi_sql_ordre_version()` dans api/api.php — recopiés, parce que Python ne peut
# pas appeler une fonction PHP, et CONFRONTÉS : tests/test_applicabilite.py fait
# juger les mêmes lignes à l'API et à cet outil, et rougit s'ils divergent. Une
# règle recopiée diverge en silence tant que rien ne la confronte à sa source.
#
# La liste des états, elle, n'est pas recopiée ici : elle vient de l'outil
# d'extraction, qui en porte l'unique copie Python.
#
# L'état LEGI nomme le dernier événement subi par une version, pas son
# applicabilité : ces trois-là seuls désignent une position dans le cycle de
# vie. Cet outil les jugeait par une cascade sur l'état — `VIGUEUR`, puis
# `VIGUEUR_DIFF`, puis la `date_debut` la plus récente —, si bien qu'un article
# en abrogation différée, applicable, sortait sous son état brut, et qu'une
# version future pouvait passer avant la version en vigueur.

STATUT_MORT = {"ABROGE": "ABROGÉ", "MODIFIE": "MODIFIÉ", "TRANSFERE": "TRANSFÉRÉ", "PERIME": "PÉRIMÉ"}


def check_article_in_db(db_path: str, article_ref: str, article_type: str,
                        jour: str | None = None) -> dict:
    """Vérifier si un article existe dans la base LEGI SQLite (schéma maison).

    `jour` fixe la date d'applicabilité — aujourd'hui par défaut. Un banc la
    fige : un témoin adossé au jour courant se périme à la première échéance.
    """
    jour = jour or date.today().isoformat()
    vivants = ", ".join(f"'{e}'" for e in ETATS_VIVANTS)
    applicable = (f"(etat IN ({vivants}) AND ifnull(date_debut, '') <= :jour"
                  f" AND (ifnull(date_fin, '') = '' OR date_fin > :jour))")
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    row = conn.execute(
        f"""SELECT num, etat, code_id, date_debut, date_fin, {applicable} AS applicable
              FROM articles WHERE num = :ref
             ORDER BY ifnull(applicable, 0) DESC,
                      (ifnull(date_debut, '') <= :jour) DESC,
                      CASE WHEN ifnull(date_debut, '') <= :jour THEN date_debut END DESC,
                      date_debut ASC
             LIMIT 1""",
        {"ref": article_ref, "jour": jour},
    ).fetchone()
    conn.close()

    result = {"ref": article_ref, "type": article_type, "status": "INTROUVABLE", "details": ""}
    if not row:
        return result

    etat = row["etat"]
    if row["applicable"]:
        result["status"] = "EN VIGUEUR"
        result["details"] = f"{row['num']} (depuis {row['date_debut']}) — {row['code_id']}"
    elif not etat:
        # Ni vivant ni mort : la DILA ne déclare pas d'état. L'annoncer
        # « INCONNU » ou sous son NULL affirmait plus que la source.
        result["status"] = "SANS ÉTAT"
        result["details"] = f"{row['num']} — {row['code_id']}"
    elif etat in ETATS_VIVANTS and (row["date_debut"] or "") > jour:
        result["status"] = "VIGUEUR DIFFÉRÉE"
        result["details"] = f"{row['num']} (entrée en vigueur : {row['date_debut']}) — {row['code_id']}"
    else:
        result["status"] = STATUT_MORT.get(etat, etat)
        result["details"] = f"{row['num']} (du {row['date_debut']} au {row['date_fin']}) — {row['code_id']}"
    return result


def main():
    parser = argparse.ArgumentParser(description="Audit des articles SelfJustice contre LEGI")
    parser.add_argument("--db", required=True, help="Chemin vers la base legi.sqlite")
    parser.add_argument("--html", required=True, help="Chemin vers index.html")
    parser.add_argument("--output", default=None, help="Fichier de sortie (défaut: stdout)")
    args = parser.parse_args()

    if not Path(args.db).exists():
        print(f"ERREUR : base de données introuvable : {args.db}", file=sys.stderr)
        sys.exit(1)
    if not Path(args.html).exists():
        print(f"ERREUR : fichier HTML introuvable : {args.html}", file=sys.stderr)
        sys.exit(1)

    # Extraire les articles
    articles = extract_articles_from_html(args.html)
    print(f"Articles extraits de index.html : {len(articles)}")
    print()

    # Vérifier chaque article
    results = {}
    for art in articles:
        result = check_article_in_db(args.db, art["ref"], art["type"])
        results.setdefault(result["status"], []).append(result)

    # Rapport
    output = []
    output.append("=" * 60)
    output.append("RAPPORT D'AUDIT — SelfJustice vs LEGI")
    output.append("=" * 60)
    output.append(f"Articles analysés : {len(articles)}")
    for status, items in sorted(results.items(), key=lambda x: -len(x[1])):
        output.append(f"  {status:<25} : {len(items)}")
    output.append("")

    # Statuts problématiques en premier, puis tout statut que la liste ne nomme
    # pas : un état brut (ANNULE…) était compté au résumé et jamais détaillé,
    # faute d'y figurer.
    priority = ["INTROUVABLE", "SANS ÉTAT", "ABROGÉ", "TRANSFÉRÉ", "PÉRIMÉ", "MODIFIÉ",
                "VIGUEUR DIFFÉRÉE", "EN VIGUEUR"]
    priority += [s for s in results if s not in priority]
    for status in priority:
        if status in results and results[status]:
            output.append(f"--- {status} ({len(results[status])}) ---")
            for r in results[status]:
                marker = "✓" if status == "EN VIGUEUR" else "⚠" if status in ("MODIFIÉ", "VIGUEUR DIFFÉRÉE") else "✗"
                detail = f" — {r['details']}" if r['details'] else ""
                output.append(f"  {marker} [{r['type']}] {r['ref']}{detail}")
            output.append("")

    report = "\n".join(output)

    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(report)
        print(f"Rapport écrit dans {args.output}")
    else:
        print(report)


if __name__ == "__main__":
    main()
