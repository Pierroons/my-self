#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Generateur des tables diceware PDF pour SelfRecover (reproductible, versionne).

- EN : EFF Large Wordlist (CC-BY 3.0, eff.org/dice) = liste officielle EFF.
- FR : liste ArthurPons (CC-BY 3.0). L'EFF ne publie QUE l'anglais ; cette liste FR
  en est l'equivalent communautaire. AUCUN claim "EFF FR" (qui n'existe pas).

Regenere docs/diceware-method-{en,fr}.pdf depuis data/eff_large_wordlist_{en,fr}.txt.
Usage : python3 generate_diceware_pdf.py
"""
import os
from datetime import datetime
from fpdf import FPDF
from fpdf.enums import XPos, YPos

HERE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(HERE, "..", "data")
DOC_VERSION = "0.3.0"

LANGS = {
    "en": {
        "wordfile": "eff_large_wordlist_en.txt",
        "title": "EFF Large Wordlist",
        "subtitle": "Diceware reference for cryptographic passphrases",
        "source": "Source: EFF Large Wordlist (Creative Commons BY 3.0) - https://eff.org/dice",
        "source_note": (
            "The EFF Large Wordlist is the official English diceware list published by the "
            "Electronic Frontier Foundation (2016). 7776 words, one per 5-dice roll."
        ),
        "language": "English",
        "method_title": "The Reinhold method (diceware)",
        "method": [
            "Diceware was invented by Arnold G. Reinhold in 1995 to generate passphrases with "
            "measurable, high entropy using only physical dice - no computer required.",
            "1. Get 5 six-sided dice (or roll one die 5 times).",
            "2. Roll them and read the digits left to right, e.g. 32145.",
            "3. Look up the code in the table below: 32145 -> one exact word.",
            "4. Repeat for each word. Official recommendation: 6 words minimum.",
            "5. Write the passphrase on paper, store it safely. NEVER type it into any "
            "computer to 'check' it - use the offline HTML tool shipped with SelfRecover.",
        ],
        "table_title": "Recommended number of words",
        "cols": ("Words", "Entropy", "Use case"),
        "rows": [
            ("4", "51.7 bits", "demo / low stakes"),
            ("5", "64.6 bits", "everyday accounts"),
            ("6", "77.5 bits", "RECOMMENDED"),
            ("7", "90.5 bits", "sensitive secrets"),
            ("8", "103.4 bits", "root / master key"),
        ],
        "grid_title": "Dice code -> word (11111 to 66666)",
        "footer": "SelfRecover - MySelf - AGPL-3.0-or-later",
    },
    "fr": {
        "wordfile": "eff_large_wordlist_fr.txt",
        "title": "Liste ArthurPons (équivalent français)",
        "subtitle": "Référence diceware pour passphrases cryptographiques",
        "source": "Liste source : liste ArthurPons (Creative Commons BY 3.0).",
        "source_note": (
            "IMPORTANT : l'EFF Large Wordlist n'existe QU'EN ANGLAIS. L'EFF ne publie PAS de "
            "liste française. Cette liste FR est un équivalent communautaire (liste ArthurPons) "
            "aux mêmes propriétés : 7776 mots, une par tirage de 5 dés, méthode diceware identique."
        ),
        "language": "Français",
        "method_title": "La méthode Reinhold (diceware)",
        "method": [
            "Le diceware a été inventé par Arnold G. Reinhold en 1995 pour générer des "
            "passphrases à l'entropie mesurable et élevée avec de simples dés - sans ordinateur.",
            "1. Procure-toi 5 dés à 6 faces (ou 1 dé jeté 5 fois).",
            "2. Lance-les et lis les chiffres de gauche à droite, ex. 32145.",
            "3. Cherche le code dans la table ci-dessous : 32145 -> un mot précis.",
            "4. Recommence pour chaque mot. Recommandation officielle : 6 mots minimum.",
            "5. Note la passphrase sur papier, range-la en lieu sûr. Ne la tape JAMAIS dans un "
            "ordinateur pour la 'vérifier' - utilise l'outil HTML autonome fourni avec SelfRecover.",
        ],
        "table_title": "Nombre de mots recommandé",
        "cols": ("Mots", "Entropie", "Cas d'usage"),
        "rows": [
            ("4", "51,7 bits", "démo / faible enjeu"),
            ("5", "64,6 bits", "comptes courants"),
            ("6", "77,5 bits", "RECOMMANDÉ"),
            ("7", "90,5 bits", "secrets sensibles"),
            ("8", "103,4 bits", "clé racine / master"),
        ],
        "grid_title": "Code dés -> mot (11111 à 66666)",
        "footer": "SelfRecover - MySelf - AGPL-3.0-or-later",
    },
}


def index_to_code(i: int) -> str:
    """Index 0..7775 -> code diceware 5 des (11111..66666)."""
    s = ""
    for _ in range(5):
        s = str(i % 6 + 1) + s
        i //= 6
    return s


def load_words(path: str) -> list:
    with open(path, encoding="utf-8") as f:
        words = [w.strip() for w in f if w.strip()]
    if len(words) != 7776:
        raise SystemExit(f"Wordlist invalide : {len(words)} mots au lieu de 7776 ({path})")
    return words


class DicewarePDF(FPDF):
    def __init__(self, cfg):
        super().__init__(orientation="P", unit="mm", format="A4")
        self.cfg = cfg
        self.set_auto_page_break(auto=True, margin=14)
        self.set_title(cfg["title"] + " - Diceware - SelfRecover")

    def footer(self):
        self.set_y(-12)
        self.set_font("Helvetica", "I", 7)
        self.set_text_color(120, 120, 120)
        self.cell(120, 6, self.cfg["footer"], align="L")
        self.cell(0, 6, f"p. {self.page_no()}", align="R")


def build(lang: str, cfg: dict):
    words = load_words(os.path.join(DATA, cfg["wordfile"]))
    date = datetime.now().strftime("%d/%m/%Y")
    pdf = DicewarePDF(cfg)

    # --- Page de titre + methode ---
    pdf.add_page()
    pdf.set_text_color(20, 20, 20)
    pdf.set_font("Helvetica", "", 8)
    pdf.cell(0, 5, cfg["footer"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(2)
    pdf.set_font("Helvetica", "B", 20)
    pdf.multi_cell(0, 9, cfg["title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.set_font("Helvetica", "", 11)
    pdf.set_text_color(70, 70, 70)
    pdf.multi_cell(0, 6, cfg["subtitle"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(2)

    pdf.set_font("Helvetica", "B", 9)
    pdf.set_text_color(20, 20, 20)
    pdf.multi_cell(0, 5, cfg["source"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.set_font("Helvetica", "", 9)
    pdf.set_text_color(60, 60, 60)
    pdf.multi_cell(0, 5, cfg["source_note"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(1)
    pdf.set_font("Helvetica", "", 8)
    pdf.cell(0, 5, f"Langue : {cfg['language']}  -  Version {DOC_VERSION}  -  {date}", new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(3)

    # Methode Reinhold
    pdf.set_font("Helvetica", "B", 12)
    pdf.set_text_color(20, 20, 20)
    pdf.cell(0, 7, cfg["method_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.set_font("Helvetica", "", 9.5)
    pdf.set_text_color(40, 40, 40)
    for para in cfg["method"]:
        pdf.multi_cell(0, 5, para, new_x=XPos.LMARGIN, new_y=YPos.NEXT)
        pdf.ln(1)
    pdf.ln(2)

    # Table nombre de mots
    pdf.set_font("Helvetica", "B", 11)
    pdf.cell(0, 7, cfg["table_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    widths = (30, 40, 90)
    pdf.set_font("Helvetica", "B", 9)
    pdf.set_fill_color(235, 238, 245)
    for w, h in zip(widths, cfg["cols"]):
        pdf.cell(w, 7, h, border=1, fill=True)
    pdf.ln()
    pdf.set_font("Helvetica", "", 9)
    for row in cfg["rows"]:
        bold = row[2].upper() in ("RECOMMENDED", "RECOMMANDE", "RECOMMANDÉ")
        pdf.set_font("Helvetica", "B" if bold else "", 9)
        for w, val in zip(widths, row):
            pdf.cell(w, 6, val, border=1)
        pdf.ln()

    # --- Grille code -> mot ---
    pdf.add_page()
    pdf.set_font("Helvetica", "B", 12)
    pdf.set_text_color(20, 20, 20)
    pdf.cell(0, 7, cfg["grid_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(1)

    ncols = 4
    col_w = (210 - 20) / ncols  # marges ~10mm de chaque cote
    row_h = 4.4
    top = pdf.get_y()
    usable_h = 297 - top - 16
    rows_per_col = int(usable_h // row_h)
    per_page = ncols * rows_per_col

    pdf.set_font("Courier", "", 8)
    pdf.set_text_color(30, 30, 30)
    i = 0
    n = len(words)
    while i < n:
        page_start = i
        for c in range(ncols):
            x = 10 + c * col_w
            for r in range(rows_per_col):
                idx = page_start + c * rows_per_col + r
                if idx >= n:
                    break
                pdf.set_xy(x, top + r * row_h)
                pdf.cell(col_w, row_h, f"{index_to_code(idx)}  {words[idx]}")
        i = page_start + per_page
        if i < n:
            pdf.add_page()
            pdf.set_font("Courier", "", 8)
            top = 14

    out = os.path.join(HERE, f"diceware-method-{lang}.pdf")
    pdf.output(out)
    return out, pdf.page_no()


if __name__ == "__main__":
    for lang, cfg in LANGS.items():
        out, pages = build(lang, cfg)
        print(f"{lang}: {out} ({pages} pages)")
