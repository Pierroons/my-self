#!/usr/bin/env bash
# Régénère le .docx d'un whitepaper depuis son .md.
#
# Le .docx est un dérivé : le .md est la source. Sans cette chaîne, les deux
# divergent en silence — c'est ce qui est arrivé au whitepaper SelfAct, dont le
# .docx a décrit pendant des mois une architecture (Jinja, WeasyPrint, export
# ZIP) que le code n'a jamais portée, pendant que le .md était corrigé.
#
#   scripts/whitepaper-docx.sh self-right/selfact/docs/whitepaper.md
#   scripts/whitepaper-docx.sh --tous
#
# Les métadonnées Office sont réécrites après coup : les compteurs de mots et de
# pages qu'un producteur y laisse ne correspondent à rien, et un producteur
# emprunté (« Microsoft Word ») est une affirmation fausse sur l'origine du
# fichier.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v pandoc >/dev/null || { echo "pandoc absent : sudo apt install pandoc" >&2; exit 1; }
python3 -c 'import docx' 2>/dev/null || { echo "python-docx absent : pip install --user python-docx" >&2; exit 1; }

generer() {
    local md="$1" docx="${1%.md}.docx"
    [ -f "$md" ] || { echo "introuvable : $md" >&2; return 1; }
    pandoc "$md" -o "$docx" --standalone
    python3 - "$docx" <<'PY'
import re, shutil, sys, zipfile
from pathlib import Path

docx = Path(sys.argv[1])
PRODUCTEUR = "pandoc + MySelf/scripts/whitepaper-docx.sh"

# Les compteurs sont retirés plutôt que recalculés : un chiffre qu'aucun lecteur
# ne vérifie et qu'aucune chaîne ne tient redevient faux au premier changement.
# AppVersion « 12.0000 » est le numéro de version de Word 12 que les
# producteurs recopient : il ne dit rien du fichier et affirme une origine
# fausse.
A_RETIRER = ("Words", "Characters", "CharactersWithSpaces", "Pages",
             "Paragraphs", "Lines", "TotalTime", "Template", "AppVersion")

tmp = docx.with_suffix(".docx.tmp")
with zipfile.ZipFile(docx) as src, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as dst:
    for item in src.infolist():
        data = src.read(item.filename)
        if item.filename == "docProps/app.xml":
            xml = data.decode("utf8")
            for cle in A_RETIRER:
                xml = re.sub(rf"<{cle}>.*?</{cle}>", "", xml)
            xml = re.sub(r"<Application>.*?</Application>",
                         f"<Application>{PRODUCTEUR}</Application>", xml)
            if "<Application>" not in xml:
                # pandoc n'écrit pas de balise Application, et sa racine porte un
                # préfixe de namespace variable : on ferme sur la dernière balise
                # fermante du document, quelle qu'elle soit.
                xml = re.sub(r"(</[A-Za-z:]+>)\s*$",
                             f"<Application>{PRODUCTEUR}</Application>\\1", xml.rstrip())
            data = xml.encode("utf8")
        dst.writestr(item, data)
shutil.move(tmp, docx)
print(f"  {docx}")
PY
}

if [ "${1:-}" = "--tous" ]; then
    find . -name '*.docx' -not -path './node_modules/*' | while read -r d; do
        md="${d%.docx}.md"; [ -f "$md" ] && generer "$md"
    done
else
    [ $# -eq 1 ] || { sed -n '2,14p' "$0" | sed 's/^# \?//'; exit 1; }
    generer "$1"
fi
