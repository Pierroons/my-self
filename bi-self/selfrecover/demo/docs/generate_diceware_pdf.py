#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Generateur des tables diceware PDF pour SelfRecover (reproductible, versionne).

Design : PRINT-FRIENDLY (fond blanc, texte noir) avec un branding MySelf leger
(sigil M', filets accent fins, titres) - negligeable a l'impression, on ne vide
pas les cartouches couleur. Liens cliquables (outil offline + projet SelfRecover).

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
DOC_VERSION = "0.4.0"

URL_TOOL = "https://bi-self.my-self.fr/selfrecover/offline/selfrecover-validator.html"
URL_SELF = "https://bi-self.my-self.fr/selfrecover"
DISP_TOOL = "bi-self.my-self.fr/selfrecover/offline/selfrecover-validator.html"
DISP_SELF = "bi-self.my-self.fr/selfrecover"

# --- Charte MySelf (RGB) : usage LEGER sur fond blanc ---
ACCENT = (58, 125, 210)      # bleu MySelf assombri pour lisibilite/impression sur blanc
ACCENT_SOFT = (232, 240, 251)  # fond tres clair (bandeau accent leger)
INK = (24, 28, 34)           # texte principal (quasi noir)
SUBINK = (92, 100, 112)      # texte secondaire
HAIR = (214, 219, 226)       # filets
ZEBRA = (248, 249, 251)      # alternance table
CODE_CLR = (36, 40, 48)      # code des : noir (impression)

LANGS = {
    "en": {
        "wordfile": "eff_large_wordlist_en.txt",
        "kicker": "SELFRECOVER  //  DICEWARE",
        "title": "Diceware Reference",
        "listname": "EFF Large Wordlist  -  7776 words",
        "note_title": "About the source",
        "note": (
            "The EFF Large Wordlist is the official English diceware list published by the "
            "Electronic Frontier Foundation (2016). 7776 words, one per 5-dice roll (CC-BY 3.0)."
        ),
        "blurb": (
            "SelfRecover is sovereign account recovery - no email, no SMS (free software, "
            "AGPL-3.0). This word table builds the passphrase that guards your recovery."
        ),
        "learn": "Learn more:",
        "language": "English",
        "method_title": "The Reinhold method (diceware)",
        "method": [
            "Diceware was invented by Arnold G. Reinhold in 1995 to generate passphrases with "
            "measurable, high entropy using physical dice - no computer required.",
            "1.  Get 5 six-sided dice (or roll one die 5 times).",
            "2.  Roll them and read the digits left to right, e.g. 32145.",
            "3.  Look up the code in the table: 32145 gives one exact word.",
            "4.  Repeat for each word. Official recommendation: 6 words minimum.",
            "5.  Write the passphrase on paper, store it safely. NEVER type it into any computer "
            "to 'check' it - use the offline tool below.",
        ],
        "tool_title": "Check a passphrase offline",
        "tool_desc": "Self-contained tool, zero network. Save it, go airgapped, verify:",
        "table_title": "Recommended number of words",
        "cols": ("Words", "Entropy", "Use case"),
        "rows": [
            ("4", "51.7 bits", "demo / low stakes"),
            ("5", "64.6 bits", "everyday accounts"),
            ("6", "77.5 bits", "RECOMMENDED"),
            ("7", "90.5 bits", "sensitive secrets"),
            ("8", "103.4 bits", "root / master key"),
        ],
        "grid_title": "Dice code  ->  word   (11111 to 66666)",
        "runhdr": "SelfRecover  //  Diceware  //  EN",
    },
    "fr": {
        "wordfile": "eff_large_wordlist_fr.txt",
        "kicker": "SELFRECOVER  //  DICEWARE",
        "title": "Reference Diceware",
        "listname": "Liste ArthurPons  -  equivalent francais  -  7776 mots",
        "note_title": "A savoir sur la source",
        "note": (
            "L'EFF Large Wordlist n'existe QU'EN ANGLAIS : l'EFF ne publie pas de liste "
            "francaise. Cette liste FR en est l'equivalent communautaire (liste ArthurPons, "
            "CC-BY 3.0), aux memes proprietes : 7776 mots, une par tirage de 5 des."
        ),
        "blurb": (
            "SelfRecover, c'est la recuperation de compte souveraine - sans email ni SMS "
            "(logiciel libre, AGPL-3.0). Cette table sert a forger la phrase secrete qui "
            "protege ta recuperation."
        ),
        "learn": "Decouvrir :",
        "language": "Francais",
        "method_title": "La methode Reinhold (diceware)",
        "method": [
            "Le diceware a ete invente par Arnold G. Reinhold en 1995 pour generer des "
            "passphrases a l'entropie mesurable et elevee avec de simples des - sans ordinateur.",
            "1.  Procure-toi 5 des a 6 faces (ou 1 de jete 5 fois).",
            "2.  Lance-les et lis les chiffres de gauche a droite, ex. 32145.",
            "3.  Cherche le code dans la table : 32145 donne un mot precis.",
            "4.  Recommence pour chaque mot. Recommandation officielle : 6 mots minimum.",
            "5.  Note la passphrase sur papier, range-la en lieu sur. Ne la tape JAMAIS dans un "
            "ordinateur pour la 'verifier' - utilise l'outil offline ci-dessous.",
        ],
        "tool_title": "Verifier une passphrase hors-ligne",
        "tool_desc": "Outil autonome, aucun reseau. Telecharge-le, coupe le reseau, verifie :",
        "table_title": "Nombre de mots recommande",
        "cols": ("Mots", "Entropie", "Cas d'usage"),
        "rows": [
            ("4", "51,7 bits", "demo / faible enjeu"),
            ("5", "64,6 bits", "comptes courants"),
            ("6", "77,5 bits", "RECOMMANDE"),
            ("7", "90,5 bits", "secrets sensibles"),
            ("8", "103,4 bits", "cle racine / master"),
        ],
        "grid_title": "Code des  ->  mot   (11111 a 66666)",
        "runhdr": "SelfRecover  //  Diceware  //  FR",
    },
}

# Variante accentuee (police Unicode) pour le FR
ACCENTS_FR = {
    "title": "Référence Diceware",
    "listname": "Liste ArthurPons  ·  équivalent français  ·  7776 mots",
    "note_title": "À savoir sur la source",
    "note": (
        "L'EFF Large Wordlist n'existe QU'EN ANGLAIS : l'EFF ne publie pas de liste "
        "française. Cette liste FR en est l'équivalent communautaire (liste ArthurPons, "
        "CC-BY 3.0), aux mêmes propriétés : 7776 mots, une par tirage de 5 dés."
    ),
    "blurb": (
        "SelfRecover, c'est la récupération de compte souveraine — sans email ni SMS "
        "(logiciel libre, AGPL-3.0). Cette table sert à forger la phrase secrète qui "
        "protège ta récupération."
    ),
    "learn": "Découvrir :",
    "language": "Français",
    "method_title": "La méthode Reinhold (diceware)",
    "method": [
        "Le diceware a été inventé par Arnold G. Reinhold en 1995 pour générer des "
        "passphrases à l'entropie mesurable et élevée avec de simples dés — sans ordinateur.",
        "1.  Procure-toi 5 dés à 6 faces (ou 1 dé jeté 5 fois).",
        "2.  Lance-les et lis les chiffres de gauche à droite, ex. 32145.",
        "3.  Cherche le code dans la table : 32145 donne un mot précis.",
        "4.  Recommence pour chaque mot. Recommandation officielle : 6 mots minimum.",
        "5.  Note la passphrase sur papier, range-la en lieu sûr. Ne la tape JAMAIS dans un "
        "ordinateur pour la « vérifier » — utilise l'outil offline ci-dessous.",
    ],
    "tool_title": "Vérifier une passphrase hors-ligne",
    "tool_desc": "Outil autonome, aucun réseau. Télécharge-le, coupe le réseau, vérifie :",
    "table_title": "Nombre de mots recommandé",
    "rows": [
        ("4", "51,7 bits", "démo / faible enjeu"),
        ("5", "64,6 bits", "comptes courants"),
        ("6", "77,5 bits", "RECOMMANDÉ"),
        ("7", "90,5 bits", "secrets sensibles"),
        ("8", "103,4 bits", "clé racine / master"),
    ],
    "grid_title": "Code dés  →  mot   (11111 à 66666)",
}


def _find_fonts():
    import fpdf as _f
    bundled = os.path.join(os.path.dirname(_f.__file__), "font")
    cands = [
        (os.path.join(bundled, "DejaVuSans.ttf"),
         os.path.join(bundled, "DejaVuSans-Bold.ttf"),
         os.path.join(bundled, "DejaVuSansMono.ttf")),
        ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf"),
    ]
    for reg, bold, mono in cands:
        if os.path.exists(reg) and os.path.exists(bold) and os.path.exists(mono):
            return reg, bold, mono
    return None


_FONTS = _find_fonts()
HAS_UNICODE = _FONTS is not None
if HAS_UNICODE:
    DEJAVU, DEJAVU_B, DEJAVU_MONO = _FONTS


def index_to_code(i: int) -> str:
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
        self.on_cover = True
        self.set_auto_page_break(auto=True, margin=16)
        if HAS_UNICODE:
            self.add_font("body", "", DEJAVU)
            self.add_font("body", "B", DEJAVU_B)
            self.add_font("mono", "", DEJAVU_MONO)
            self.body, self.mono = "body", "mono"
        else:
            self.body, self.mono = "Helvetica", "Courier"

    def draw_sigil(self, ox, oy, size, rgb):
        s = size / 200.0
        self.set_draw_color(*rgb)
        self.set_line_width(18 * s)
        pts = [(36, 158), (36, 70), (100, 138), (158, 50), (158, 158)]
        self.polyline([(ox + x * s, oy + y * s) for x, y in pts])
        self.line(ox + 158 * s, oy + 50 * s, ox + 174 * s, oy + 28 * s)
        self.set_fill_color(*rgb)
        r = 11 * s
        self.ellipse(ox + 180 * s - r, oy + 20 * s - r, 2 * r, 2 * r, "F")

    def header(self):
        if self.on_cover:
            return
        self.draw_sigil(12, 8.5, 7, ACCENT)
        self.set_xy(21, 9)
        self.set_font(self.body, "B", 8)
        self.set_text_color(*INK)
        self.cell(0, 5, self.cfg["runhdr"])
        self.set_xy(-58, 9)
        self.set_font(self.body, "", 8)
        self.set_text_color(*SUBINK)
        self.cell(46, 5, DISP_SELF, align="R", link=URL_SELF)
        self.set_draw_color(*ACCENT)
        self.set_line_width(0.4)
        self.line(12, 16.5, 198, 16.5)

    def footer(self):
        if self.on_cover:
            return
        self.set_y(-11)
        self.set_draw_color(*HAIR)
        self.set_line_width(0.2)
        self.line(12, self.get_y(), 198, self.get_y())
        self.set_y(-9)
        self.set_font(self.body, "", 7)
        self.set_text_color(*SUBINK)
        self.cell(120, 5, "SelfRecover · MySelf · AGPL-3.0-or-later")
        self.cell(0, 5, str(self.page_no()), align="R")


def callout(pdf, x, y, w, h, title, title_clr):
    """Encadre leger : filet fin + barre accent gauche (impression eco)."""
    pdf.set_draw_color(*HAIR)
    pdf.set_line_width(0.3)
    pdf.rect(x, y, w, h)
    pdf.set_fill_color(*ACCENT)
    pdf.rect(x, y, 1.4, h, "F")
    if title:
        pdf.set_xy(x + 7, y + 5)
        pdf.set_font(pdf.body, "B", 9)
        pdf.set_text_color(*title_clr)
        pdf.cell(0, 5, title)


def cover(pdf, cfg):
    pdf.on_cover = True
    pdf.set_auto_page_break(False)
    pdf.add_page()
    # sigil + wordmark
    pdf.draw_sigil(93, 34, 24, ACCENT)
    pdf.set_xy(0, 66)
    pdf.set_font(pdf.body, "B", 15)
    pdf.set_text_color(*INK)
    pdf.set_char_spacing(2.4)
    pdf.cell(210, 8, "MySelf", align="C")
    pdf.set_char_spacing(0)

    # kicker + titre + liste
    pdf.set_xy(0, 92)
    pdf.set_font(pdf.body, "B", 9)
    pdf.set_text_color(*ACCENT)
    pdf.set_char_spacing(1.8)
    pdf.cell(210, 6, cfg["kicker"], align="C")
    pdf.set_char_spacing(0)
    pdf.set_xy(0, 101)
    pdf.set_font(pdf.body, "B", 30)
    pdf.set_text_color(*INK)
    pdf.cell(210, 15, cfg["title"], align="C")
    pdf.set_xy(0, 120)
    pdf.set_font(pdf.body, "", 11)
    pdf.set_text_color(*SUBINK)
    pdf.cell(210, 6, cfg["listname"], align="C")
    pdf.set_draw_color(*ACCENT)
    pdf.set_line_width(0.6)
    pdf.line(90, 134, 120, 134)

    # encart source (honnetete)
    bx, bw = 28, 154
    callout(pdf, bx, 150, bw, 34, cfg["note_title"], ACCENT)
    pdf.set_xy(bx + 7, 158)
    pdf.set_font(pdf.body, "", 9)
    pdf.set_text_color(*INK)
    pdf.multi_cell(bw - 14, 4.8, cfg["note"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)

    # encart SelfRecover (funnel)
    callout(pdf, bx, 194, bw, 32, "SelfRecover", ACCENT)
    pdf.set_xy(bx + 7, 202)
    pdf.set_font(pdf.body, "", 9)
    pdf.set_text_color(*INK)
    pdf.multi_cell(bw - 14, 4.8, cfg["blurb"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.set_xy(bx + 7, 218)
    pdf.set_font(pdf.body, "B", 9)
    pdf.set_text_color(*SUBINK)
    pdf.cell(pdf.get_string_width(cfg["learn"]) + 2, 5, cfg["learn"])
    pdf.set_text_color(*ACCENT)
    pdf.cell(0, 5, " " + DISP_SELF, link=URL_SELF)

    # meta bas
    date = datetime.now().strftime("%d/%m/%Y")
    pdf.set_xy(0, 272)
    pdf.set_font(pdf.body, "", 8)
    pdf.set_text_color(*SUBINK)
    pdf.cell(210, 5,
             f"Version {DOC_VERSION}   ·   {cfg['language']}   ·   {date}   ·   AGPL-3.0-or-later",
             align="C")
    pdf.set_xy(0, 279)
    pdf.set_text_color(*ACCENT)
    pdf.cell(210, 5, "my-self.fr", align="C", link="https://my-self.fr")


def content(pdf, cfg, words):
    pdf.on_cover = False
    pdf.set_auto_page_break(True, margin=16)
    pdf.add_page()

    # methode
    pdf.set_y(24)
    pdf.set_font(pdf.body, "B", 15)
    pdf.set_text_color(*ACCENT)
    pdf.cell(0, 8, cfg["method_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(1)
    pdf.set_font(pdf.body, "", 9.5)
    pdf.set_text_color(*INK)
    for para in cfg["method"]:
        pdf.set_x(12)
        pdf.multi_cell(186, 5.2, para, new_x=XPos.LMARGIN, new_y=YPos.NEXT)
        pdf.ln(1.1)
    pdf.ln(2)

    # callout outil offline + lien
    ty = pdf.get_y()
    callout(pdf, 12, ty, 186, 22, cfg["tool_title"], ACCENT)
    pdf.set_xy(19, ty + 11)
    pdf.set_font(pdf.body, "", 9)
    pdf.set_text_color(*INK)
    pdf.cell(0, 5, cfg["tool_desc"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.set_xy(19, ty + 16)
    pdf.set_font(pdf.body, "B", 8.5)
    pdf.set_text_color(*ACCENT)
    pdf.cell(0, 5, DISP_TOOL, link=URL_TOOL)
    pdf.ln(10)

    # table entropie
    pdf.set_x(12)
    pdf.set_font(pdf.body, "B", 12)
    pdf.set_text_color(*ACCENT)
    pdf.cell(0, 7, cfg["table_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
    pdf.ln(1)
    widths = (26, 40, 92)
    pdf.set_x(12)
    pdf.set_font(pdf.body, "B", 9)
    pdf.set_fill_color(*ACCENT_SOFT)
    pdf.set_text_color(*ACCENT)
    for w, h in zip(widths, cfg["cols"]):
        pdf.cell(w, 7.5, "  " + h, border=0, fill=True)
    pdf.ln()
    for i, row in enumerate(cfg["rows"]):
        reco = row[2].upper() in ("RECOMMENDED", "RECOMMANDE", "RECOMMANDÉ")
        pdf.set_x(12)
        pdf.set_fill_color(*(ACCENT_SOFT if reco else (ZEBRA if i % 2 else (255, 255, 255))))
        pdf.set_font(pdf.body, "B" if reco else "", 9)
        pdf.set_text_color(*(ACCENT if reco else INK))
        for w, val in zip(widths, row):
            pdf.cell(w, 6.6, "  " + val, border=0, fill=True)
        pdf.ln()

    # --- Grille code -> mot ---
    def grid_head():
        pdf.set_y(24)
        pdf.set_font(pdf.body, "B", 13)
        pdf.set_text_color(*ACCENT)
        pdf.cell(0, 8, cfg["grid_title"], new_x=XPos.LMARGIN, new_y=YPos.NEXT)
        pdf.ln(1)
        return pdf.get_y()

    pdf.add_page()
    top = grid_head()
    ncols, left = 4, 12
    col_w = (210 - 2 * left) / ncols
    row_h = 4.3
    usable_h = 297 - top - 16
    rows_per_col = int(usable_h // row_h)
    per_page = ncols * rows_per_col

    n = len(words)
    i = 0
    while i < n:
        page_start = i
        for c in range(ncols):
            x = left + c * col_w
            for r in range(rows_per_col):
                idx = page_start + c * rows_per_col + r
                if idx >= n:
                    break
                y = top + r * row_h
                pdf.set_xy(x, y)
                pdf.set_font(pdf.mono, "", 7.6)
                pdf.set_text_color(*CODE_CLR)
                pdf.cell(11, row_h, index_to_code(idx))
                pdf.set_xy(x + 11, y)
                pdf.set_text_color(*INK)
                pdf.cell(col_w - 11, row_h, words[idx])
        i = page_start + per_page
        if i < n:
            pdf.add_page()
            top = grid_head()


def build(lang, cfg):
    if lang == "fr" and HAS_UNICODE:
        cfg = {**cfg, **ACCENTS_FR}
    words = load_words(os.path.join(DATA, cfg["wordfile"]))
    pdf = DicewarePDF(cfg)
    pdf.set_title(f"{cfg['title']} - SelfRecover - MySelf")
    pdf.set_author("MySelf - SelfRecover")
    pdf.set_creator("generate_diceware_pdf.py")
    cover(pdf, cfg)
    content(pdf, cfg, words)
    out = os.path.join(HERE, f"diceware-method-{lang}.pdf")
    pdf.output(out)
    return out, pdf.page_no()


if __name__ == "__main__":
    print("Police:", "DejaVu (Unicode)" if HAS_UNICODE else "core (Helvetica/Courier)")
    for lang, cfg in LANGS.items():
        out, pages = build(lang, cfg)
        print(f"{lang}: {out} ({pages} pages)")
