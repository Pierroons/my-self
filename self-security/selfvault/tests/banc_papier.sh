#!/usr/bin/env bash
# Banc de la boucle papier : chiffrer → imprimer → rasteriser → relire →
# reconstituer → ouvrir.
#
# Séparé de `tests/banc.sh` parce qu'il exige des outils système que le format,
# lui, n'exige pas : poppler-utils, zbar-tools, weasyprint. `banc.sh` l'appelle
# s'ils sont là et DIT qu'il ne l'a pas lancé sinon — un contrôle sauté en
# silence ressemble à un contrôle passé.
#
# Usage : bash tests/banc_papier.sh
# Sortie : 0 si tout est conforme, 1 sinon.
set -uo pipefail
MODULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"
# Nettoyage sans effacement récursif forcé : les fichiers d'abord, puis les
# répertoires de bas en haut. Ce banc écrit des coffres et des secrets en clair.
# shellcheck disable=SC2317  # appelée par le trap EXIT
nettoyer(){ find "${T:?}" -type f -delete; find "${T:?}" -depth -type d -exec rmdir {} +; }
trap nettoyer EXIT

for o in pdftoppm pdftotext zbarimg weasyprint; do
  command -v "$o" >/dev/null || { echo "✗ « $o » absent — boucle papier non mesurée"; exit 1; }
done

echec=0; n=0
lire(){ python3 "$MODULE/outils/lire_pli.py" "$@" 2>&1; }

# ── Un pli neuf, de bout en bout ─────────────────────────────────────────────
python3 "$MODULE/outils/faire_coffre.py" >/dev/null || { echo "✗ fabrication du coffre"; exit 1; }
python3 "$MODULE/outils/faire_pli.py"    >/dev/null || { echo "✗ fabrication du pli"; exit 1; }
S="$MODULE/sortie"
weasyprint "$S/pli.html" "$T/pli.pdf" 2>/dev/null || { echo "✗ rendu PDF"; exit 1; }
L1=$(cat "$MODULE/outils/secrets/code_L1.txt")

echo "▸ La boucle complète — le contre-témoin de tout le reste"
n=$((n+1))
if lire "$T/pli.pdf" -o "$T/plein" >"$T/log" 2>&1 \
   && cmp -s "$T/plein/selfvault.html" "$MODULE/pli/selfvault.html" \
   && cmp -s "$T/plein/coffre.selfvault" "$S/coffre.selfvault"; then
  echo "  ✓ PDF rastérisé à 300 dpi → deux fichiers reconstitués octet pour octet"
else
  echo "  ✗ la boucle complète échoue : $(tail -1 "$T/log")"; echec=1
fi

n=$((n+1))
if node "$MODULE/tests/pilote_app.mjs" "$T/plein/coffre.selfvault" "$L1" >/dev/null 2>&1; then
  echo "  ✓ le coffre reconstitué s'ouvre avec le code imprimé sur le pli"
else
  echo "  ✗ le coffre reconstitué ne s'ouvre pas"; echec=1
fi

# ── Les QR codes pris comme images, dans le désordre ─────────────────────
# Le rang vit dans les données : l'ordre des pages ne doit rien changer.
mkdir -p "$T/desordre"
find "$S/qr" -name '*.png' | shuf | while read -r f; do
  cp "$f" "$T/desordre/p$RANDOM.png"
done
EA=$(python3 -c "import json;print(json.load(open('$S/pli.json'))['pieces']['A']['sha'][:32])")
EV=$(python3 -c "import json;print(json.load(open('$S/pli.json'))['pieces']['V']['sha'][:32])")

n=$((n+1))
if lire "$T/desordre" -o "$T/melange" --empreinte-app "$EA" --empreinte-coffre "$EV" >/dev/null 2>&1 \
   && cmp -s "$T/melange/coffre.selfvault" "$S/coffre.selfvault"; then
  echo "  ✓ pages mélangées — reconstitution identique"
else
  echo "  ✗ pages mélangées — la reconstitution a échoué"; echec=1
fi

# ── Les deux exemplaires ─────────────────────────────────────────────────────
# Le pli imprime chaque QR code deux fois, sur deux pages. Chaque exemplaire doit
# suffire à lui seul, sans quoi la page supplémentaire ne paie pas son prix.
mkdir -p "$T/pages"
pdftoppm -r 300 -gray -png "$T/pli.pdf" "$T/pages/p" 2>/dev/null
avecqr=$(for f in "$T"/pages/p-*.png; do
           [ "$(zbarimg --raw -q "$f" 2>/dev/null | grep -c '^PLI1|')" -gt 0 ] && echo "$f"
         done)
moitie=$(( $(echo "$avecqr" | wc -l) / 2 ))
mkdir -p "$T/ex1" "$T/ex2"
echo "$avecqr" | head -n "$moitie" | xargs -I{} cp {} "$T/ex1/"
echo "$avecqr" | tail -n "$moitie" | xargs -I{} cp {} "$T/ex2/"

for ex in ex1 ex2; do
  n=$((n+1))
  if lire "$T/$ex" -o "$T/o-$ex" --empreinte-app "$EA" --empreinte-coffre "$EV" >/dev/null 2>&1 \
     && cmp -s "$T/o-$ex/coffre.selfvault" "$S/coffre.selfvault"; then
    echo "  ✓ l'exemplaire ${ex#ex} seul suffit — l'autre peut être perdu"
  else
    echo "  ✗ l'exemplaire ${ex#ex} seul ne reconstitue pas"; echec=1
  fi
done

# ── La procédure imprimée, exécutée telle quelle ─────────────────────────────
# 🔑 Elle est EXTRAITE du pli rendu, pas recopiée ici. Une procédure imprimée sur
# un document opposable qu'on n'a jamais lancée est une affirmation, pas une
# mesure — et elle dérive du code au premier remaniement.
n=$((n+1))
mkdir -p "$T/manuel"
cp "$T"/pages/p-*.png "$T/manuel/"
python3 - "$S/pli.html" "$T/manuel/procedure.sh" <<'EXTRAIT'
import html, io, re, sys
t = io.open(sys.argv[1], encoding="utf-8").read()
m = re.search(r'<pre id="procedure">(.*?)</pre>', t, re.S)
if not m:
    sys.exit("le pli rendu ne porte pas de procédure identifiée")
io.open(sys.argv[2], "w", encoding="utf-8").write("set -e\n" + html.unescape(m.group(1)) + "\n")
EXTRAIT
if ( cd "$T/manuel" && bash procedure.sh >/dev/null 2>&1 ) \
   && cmp -s "$T/manuel/selfvault.html" "$MODULE/pli/selfvault.html" \
   && cmp -s "$T/manuel/coffre.selfvault" "$S/coffre.selfvault"; then
  echo "  ✓ la procédure imprimée sur le pli, lancée telle quelle, rend les deux fichiers"
else
  echo "  ✗ la procédure imprimée ne reproduit pas les fichiers"; echec=1
fi

echo "▸ Ce qui doit refuser, et ne rien écrire"
rouge(){ # rouge <répertoire de sortie> <fragment attendu> <intitulé> — commande dans $CMD
  n=$((n+1))
  local s c
  s=$("${CMD[@]}" 2>&1); c=$?
  if [ $c -eq 0 ]; then echo "  ✗ $3 — a RÉUSSI alors qu'il devait refuser"; echec=1
  elif [[ "$s" != *"$2"* ]]; then echo "  ✗ $3 — a refusé sans le dire : $(echo "$s" | tail -1)"; echec=1
  elif [ -e "$1/selfvault.html" ] || [ -e "$1/coffre.selfvault" ]; then
    echo "  ✗ $3 — a refusé MAIS a écrit un fichier"; echec=1
  else echo "  ✓ $3"; fi
}

# Deux QR codes effacés : le lecteur doit les nommer TOUS LES DEUX.
mkdir -p "$T/troue"
cp "$S/qr"/*.png "$T/troue/"; rm -f "$T/troue/A02.png" "$T/troue/A05.png"
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/troue" -o "$T/s1" --empreinte-app "$EA" --empreinte-coffre "$EV")
rouge "$T/s1" "A2/10, A5/10" "deux QR codes manquants, tous deux nommés"

# Une empreinte de référence fausse.
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/desordre" -o "$T/s2" --empreinte-app "00000000000000000000000000000000" --empreinte-coffre "$EV")
rouge "$T/s2" "la page 1 annonce" "empreinte de référence non concordante"

# Aucune empreinte de référence : la comparaison de la page 1 n'a pas eu lieu.
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/desordre" -o "$T/s3")
rouge "$T/s3" "AUCUNE empreinte de référence" "sans référence, le lecteur refuse au lieu de conclure"

# Le même rang absent des DEUX exemplaires : la duplication ne rattrape plus rien,
# et le lecteur doit le nommer plutôt que rendre un fichier tronqué.
mkdir -p "$T/troue2"
cp "$T"/pages/p-*.png "$T/troue2/"
echo "$avecqr" | while read -r f; do
  [ "$(zbarimg --raw -q "$f" 2>/dev/null | grep -c '^PLI1|')" -le 2 ] && rm -f "$T/troue2/$(basename "$f")"
done
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/troue2" -o "$T/s5" --empreinte-app "$EA" --empreinte-coffre "$EV")
rouge "$T/s5" "il manque" "un rang absent des deux exemplaires — nommé, rien d'écrit"

# Deux lectures divergentes d'un même rang : deux plis différents mêlés. La
# dernière lue gagnerait en silence, et rien ne dirait laquelle est la bonne.
mkdir -p "$T/melee"
cp "$S/qr"/V*.png "$T/melee/"
python3 "$MODULE/outils/faire_coffre.py" >/dev/null && python3 "$MODULE/outils/faire_pli.py" >/dev/null
cp "$S/qr"/V01.png "$T/melee/autre-V01.png"
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/melee" -o "$T/s6")
rouge "$T/s6" "ne donnent pas la même chose" "deux tirages mêlés — divergence nommée"

# Le plancher de résolution. 300 dpi passe (contre-témoin ci-dessus), 100 échoue.
mkdir -p "$T/basse"
pdftoppm -r 100 -gray -png "$T/pli.pdf" "$T/basse/p" 2>/dev/null
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/basse" -o "$T/s4" --empreinte-app "$EA" --empreinte-coffre "$EV")
rouge "$T/s4" "Pli incomplet" "numérisation à 100 dpi — refus, pas de fichier tronqué"

echo
if [ $echec -eq 0 ]; then echo "✓ Boucle papier conforme — $n contrôles."; else echo "✗ Boucle papier en échec — $n contrôles."; fi
exit $echec
