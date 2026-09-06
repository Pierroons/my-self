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
# Les modules Python comptent autant que les binaires. Sans ce contrôle, leur
# absence tombe en trace d'exception au milieu du banc, et il faut la lire pour
# comprendre qu'il manquait une dépendance.
for m in qrcode PIL cryptography; do
  python3 -c "import $m" 2>/dev/null || { echo "✗ module Python « $m » absent — boucle papier non mesurée"; exit 1; }
done

echec=0; n=0
lire(){ python3 "$MODULE/outils/lire_pli.py" "$@" 2>&1; }

# ── Un pli neuf, de bout en bout ─────────────────────────────────────────────
python3 "$MODULE/outils/faire_coffre.py" >/dev/null || { echo "✗ fabrication du coffre"; exit 1; }
python3 "$MODULE/outils/faire_pli.py"    >/dev/null || { echo "✗ fabrication du pli"; exit 1; }
S="$MODULE/sortie"
weasyprint "$S/pli.html" "$T/pli.pdf" 2>/dev/null || { echo "✗ rendu PDF"; exit 1; }
L1=$(cat "$MODULE/outils/secrets/code_L1.txt")

# 🔑 Le pli PDF est rendu ici, une fois. Plus bas, le contrôle des tirages mêlés
# refabrique coffre et pli — `faire_coffre.py` et `faire_pli.py` écrivent dans
# `sortie/` sans paramètre — et `sortie/` cesse alors de correspondre à ce PDF.
# Tout contrôle postérieur qui comparerait à `$S/` mesurerait deux tirages
# différents et rougirait pour la mauvaise raison. On fige donc les références.
cp "$S/coffre.selfvault" "$T/ref-coffre.selfvault"

# L'empreinte du sceau imprimée sur le pli est le seul ancrage entre un fichier et
# CE dépôt : le sceau prouve qu'un coffre n'a pas bougé, jamais d'où il vient. Une
# empreinte imprimée fausse rendrait l'ancrage inutile sans que rien ne le dise.
n=$((n+1))
SCEAU=$(python3 -c "
import json, sys; sys.path.insert(0, '$MODULE/outils')
from selfvault import empreinte_sceau
print(empreinte_sceau(json.load(open('$S/coffre.selfvault'))))")
if grep -qF "$SCEAU" "$S/pli.html"; then
  echo "  ✓ le sceau imprimé sur le pli est celui du coffre déposé"
else
  echo "  ✗ le pli imprime un sceau qui n'est pas celui du coffre : « $SCEAU » absent"; echec=1
fi

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
NA=$(python3 -c "import json;print(json.load(open('$S/pli.json'))['pieces']['A']['n'])")
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

# ── Le chemin Windows : lire les QR codes un par un, coller dans la page ─────
# L'Outil Capture d'écran de Windows décode un QR code et rend son texte. Le
# coffre n'en compte que trois : le recollage se fait donc dans le déchiffreur
# lui-même, sans installation, sans ligne de commande, et sans téléverser le
# coffre chez un tiers — ce dernier point étant rédhibitoire.
zbarimg --raw -q "$S/qr"/V*.png > "$T/coffre.lignes" 2>/dev/null

n=$((n+1))
if node "$MODULE/tests/pilote_app.mjs" "$T/coffre.lignes" "$L1" 2>&1 | grep -q '^OUVERT'; then
  echo "  ✓ les lignes des QR codes, collées dans la page, rouvrent le coffre"
else
  echo "  ✗ le recollage par collage échoue"; echec=1
fi

colle(){ # colle <fichier de lignes> <fragment attendu> <intitulé>
  n=$((n+1))
  local s
  s=$(node "$MODULE/tests/pilote_app.mjs" "$1" "$L1" 2>&1)
  if [[ "$s" == OUVERT* ]]; then echo "  ✗ $3 — s'est OUVERT alors qu'il devait refuser"; echec=1
  elif [[ "$s" != *"$2"* ]]; then echo "  ✗ $3 — a refusé sans nommer : ${s%%$'\n'*}"; echec=1
  else echo "  ✓ $3"; fi
}
head -2 "$T/coffre.lignes" > "$T/manque.lignes"
colle "$T/manque.lignes" "Il manque" "une ligne manquante — le rang est nommé"
{ cat "$T/coffre.lignes"; head -1 "$T/coffre.lignes" | sed 's/|V|1\/3|./|V|1\/3|X/'; } > "$T/div.lignes"
colle "$T/div.lignes" "ne donnent pas la même chose" "deux lectures divergentes d'un même rang"
zbarimg --raw -q "$S/qr"/A01.png > "$T/faux.lignes" 2>/dev/null
colle "$T/faux.lignes" "sont le déchiffreur, pas le coffre" "lignes du déchiffreur collées par erreur"

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
rouge "$T/s1" "A2/$NA, A5/$NA" "deux QR codes manquants, tous deux nommés"

# Une empreinte de référence fausse.
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/desordre" -o "$T/s2" --empreinte-app "00000000000000000000000000000000" --empreinte-coffre "$EV")
rouge "$T/s2" "le pli annonce" "empreinte de référence non concordante"

# Aucune empreinte de référence : la comparaison de la page 1 n'a pas eu lieu.
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/desordre" -o "$T/s3")
rouge "$T/s3" "AUCUNE empreinte de référence" "sans référence, le lecteur refuse au lieu de conclure"

# Le même rang absent des DEUX exemplaires : la duplication ne rattrape plus rien,
# et le lecteur doit le nommer plutôt que rendre un fichier tronqué.
mkdir -p "$T/troue2"
cp "$T"/pages/p-*.png "$T/troue2/"
# Les pages à retirer sont DÉSIGNÉES PAR CE QU'ELLES PORTENT, pas par leur rang
# dans le document : toute page où figure un QR code de la pièce V s'en va, dans
# les deux exemplaires. Retirer « la dernière page de chaque moitié » supposait
# que ces deux pages portent la pièce V en entier — ce qui a cessé d'être vrai
# le jour où le déchiffreur a grossi de deux QR codes.
for f in "$T"/pages/p-*.png; do
  if zbarimg --raw -q "$f" 2>/dev/null | grep -q '^PLI1|V|'; then
    rm -f "$T/troue2/$(basename "$f")"
  fi
done
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/troue2" -o "$T/s5" --empreinte-app "$EA" --empreinte-coffre "$EV")
# La pièce V a disparu du pli entier : le lecteur doit nommer la PIÈCE, pas
# énumérer ses rangs. Le cas « quelques rangs manquants » est éprouvé plus haut,
# sur des QR codes retirés un à un.
rouge "$T/s5" "entièrement absente" "une pièce absente des deux exemplaires — nommée, rien d'écrit"

# Deux lectures divergentes d'un même rang : deux plis différents mêlés. La
# dernière lue gagnerait en silence, et rien ne dirait laquelle est la bonne.
mkdir -p "$T/melee"
cp "$S/qr"/V*.png "$T/melee/"
python3 "$MODULE/outils/faire_coffre.py" >/dev/null && python3 "$MODULE/outils/faire_pli.py" >/dev/null
cp "$S/qr"/V01.png "$T/melee/autre-V01.png"
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/melee" -o "$T/s6")
rouge "$T/s6" "ne donnent pas la même chose" "deux tirages mêlés — divergence nommée"

# Le plancher de résolution, éprouvé DES DEUX CÔTÉS de la valeur publiée. Les
# README et le pli annoncent « 200 points par pouce passent, 150 échoue » : ce sont
# ces deux nombres-là qu'il faut mesurer, et pas un troisième plus confortable.
# Le banc n'éprouvait que 100 dpi — la phrase publiée ne tenait sous aucun contrôle.
n=$((n+1))
mkdir -p "$T/plancher"
pdftoppm -r 200 -gray -png "$T/pli.pdf" "$T/plancher/p" 2>/dev/null
if python3 "$MODULE/outils/lire_pli.py" "$T/plancher" -o "$T/s200" \
     --empreinte-app "$EA" --empreinte-coffre "$EV" >/dev/null 2>&1 \
   && cmp -s "$T/s200/selfvault.html" "$MODULE/pli/selfvault.html" \
   && cmp -s "$T/s200/coffre.selfvault" "$T/ref-coffre.selfvault"; then
  echo "  ✓ 200 dpi — la valeur publiée passe, octet pour octet"
else
  echo "  ✗ 200 dpi échoue alors que le pli et les README l'annoncent tenable"; echec=1
fi

mkdir -p "$T/basse"
pdftoppm -r 150 -gray -png "$T/pli.pdf" "$T/basse/p" 2>/dev/null
CMD=(python3 "$MODULE/outils/lire_pli.py" "$T/basse" -o "$T/s4" --empreinte-app "$EA" --empreinte-coffre "$EV")
rouge "$T/s4" "Pli incomplet" "150 dpi — la valeur publiée échoue, sans fichier tronqué"

echo
if [ $echec -eq 0 ]; then echo "✓ Boucle papier conforme — $n contrôles."; else echo "✗ Boucle papier en échec — $n contrôles."; fi
exit $echec
