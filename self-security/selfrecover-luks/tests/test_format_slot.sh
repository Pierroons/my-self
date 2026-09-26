#!/usr/bin/env bash
# `format-slot.sh` refuse-t-il vraiment de poser un keyscript sur un slot d'un autre format ?
#
# 🔑 **L'invariant que ce banc fixe.** Un keyscript ne se pose que sur un volume dont
# le format enrôlé est connu ET égal à celui qu'il produit. Un slot `raw` n'est pas
# ouvert par un keyscript `hex` : la machine démarre encore, puis ne démarre plus au
# redémarrage suivant, ou à la prochaine mise à jour du noyau.
#
# Le cas qui a motivé le contrôle est le deuxième : une machine installée avant que le
# format ne soit inscrit, keyscript en place, format réel inconnu. Deviner y est le
# seul geste interdit.
#
# Ne demande PAS root : conteneurs LUKS2 de 32 Mo sur fichier, aucune activation
# device-mapper. Même forme que test_sauvegardes.sh.
#
# Usage  : bash tests/test_format_slot.sh
# Sortie : 0 si chaque cas rend le verdict attendu, 1 sinon.

set -uo pipefail
export PATH="/usr/sbin:/sbin:$PATH"

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
FMT="${FMT:-$MODULE/format-slot.sh}"
KS_DEPOT="$MODULE/selfrecover-keyscript.sh"

[ -f "$FMT" ] || { echo "❌ script introuvable : $FMT"; exit 1; }
command -v cryptsetup >/dev/null || { echo "❌ cryptsetup absent"; exit 1; }

BANC="$(mktemp -d "${TMPDIR:-/tmp}/banc-format-slot.XXXXXX")" || { echo "❌ mktemp"; exit 1; }
trap 'rm -rf "$BANC"' EXIT
SKG="$BANC/skg"; mkdir -p "$SKG"

echec=0; total=0
verdict() {  # verdict <libellé> <ACCEPTE|REFUSE> <motif attendu|--> -- <arguments de format-slot.sh...>
  local libelle="$1" attendu="$2" motif="$3"; shift 4
  local sortie obtenu
  total=$((total + 1))
  sortie="$(bash "$FMT" "$@" 2>&1)"
  if [ $? -eq 0 ]; then obtenu=ACCEPTE; else obtenu=REFUSE; fi
  if [ "$obtenu" != "$attendu" ]; then
    printf '  ❌ %-58s %s (attendu %s)\n' "$libelle" "$obtenu" "$attendu"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4
    echec=1; return
  fi
  if [ "$motif" != "--" ] && ! printf '%s' "$sortie" | grep -qi -- "$motif"; then
    printf '  ❌ %-58s %s mais sans dire « %s »\n' "$libelle" "$obtenu" "$motif"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4
    echec=1; return
  fi
  printf '  ✅ %-58s %s\n' "$libelle" "$obtenu"
}

nouveau_volume() {
  truncate -s 32M "$1"
  printf 'phrase-de-banc' | cryptsetup luksFormat --type luks2 \
    --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "$1" - >/dev/null 2>&1 \
    || { echo "❌ luksFormat $1"; exit 1; }
}
RACINE="$BANC/racine.img"; DONNEES="$BANC/donnees.img"
nouveau_volume "$RACINE"; nouveau_volume "$DONNEES"
UUID_RACINE="$(cryptsetup luksUUID "$RACINE")"

# Les keyscripts : celui du dépôt, et deux variantes plantées. La variante raw ne
# change QUE la ligne exécutée — ses commentaires disent encore « --format hex ».
KS_RAW="$BANC/keyscript-raw.sh"
sed '$ s/--format hex/--format raw/' "$KS_DEPOT" > "$KS_RAW"
KS_SANS="$BANC/keyscript-sans-format.sh"
grep -v -- '--format' "$KS_DEPOT" > "$KS_SANS"
EN_PLACE="$SKG/selfrecover-keyscript.sh"

echo
echo "▸ contrôle : $FMT"
echo "▸ keyscript du dépôt : $(grep -oE -- '--format (hex|raw)' "$KS_DEPOT" | tail -n1)"
echo

echo "▸ Les cas sains — le contrôle ne doit pas crier sans raison"
verdict "installation neuve : ni keyscript en place, ni marqueur" ACCEPTE "installation neuve" -- \
  verifier "$RACINE" "$KS_DEPOT" "$SKG"
verdict "inscrire hex sur la racine" ACCEPTE "inscrit" -- inscrire "$RACINE" hex "$SKG"
cp "$KS_DEPOT" "$EN_PLACE"
verdict "racine enrôlée hex, keyscript hex à poser" ACCEPTE "enrôlé en hex" -- \
  verifier "$RACINE" "$KS_DEPOT" "$SKG"

echo
echo "▸ Les défauts — chacun DOIT être refusé"

# LE CAS QUI COMPTE LE PLUS : slot raw, keyscript hex.
verdict "inscrire raw sur la racine" ACCEPTE "inscrit" -- inscrire "$RACINE" raw "$SKG"
verdict "racine enrôlée raw, keyscript hex à poser" REFUSE "inamorçable" -- \
  verifier "$RACINE" "$KS_DEPOT" "$SKG"
verdict "racine enrôlée raw, keyscript raw à poser (contre-témoin)" ACCEPTE "enrôlé en raw" -- \
  verifier "$RACINE" "$KS_RAW" "$SKG"

# Une seule ligne par volume : l'inscription remplace, elle n'empile pas.
bash "$FMT" inscrire "$DONNEES" hex "$SKG" >/dev/null 2>&1
bash "$FMT" inscrire "$RACINE" hex "$SKG" >/dev/null 2>&1
total=$((total + 1))
if [ "$(grep -c -- "$UUID_RACINE" "$SKG/format-slot")" = 1 ] \
   && grep -q -- "^$UUID_RACINE hex\$" "$SKG/format-slot" \
   && [ "$(wc -l < "$SKG/format-slot")" = 2 ]; then
  printf '  ✅ %-58s %s\n' "réinscrire remplace la ligne du volume, garde les autres" "ACCEPTE"
else
  printf '  ❌ %-58s\n' "réinscrire a empilé ou perdu une ligne :"; sed 's/^/        /' "$SKG/format-slot"; echec=1
fi

verdict "commentaire hex, ligne exécutée raw, racine hex" REFUSE "inamorçable" -- \
  verifier "$RACINE" "$KS_RAW" "$SKG"

# Le cas de la machine installée avant le marqueur — et sa variante trompeuse : un
# marqueur existe, mais pour le volume de DONNÉES.
: > "$SKG/format-slot"
bash "$FMT" inscrire "$DONNEES" hex "$SKG" >/dev/null 2>&1
verdict "keyscript en place, format de la racine inconnu" REFUSE "écrit nulle part" -- \
  verifier "$RACINE" "$KS_DEPOT" "$SKG"
rm -f "$SKG/format-slot"
verdict "keyscript en place, aucun marqueur du tout" REFUSE "écrit nulle part" -- \
  verifier "$RACINE" "$KS_DEPOT" "$SKG"

printf '%s hexa\n' "$UUID_RACINE" > "$SKG/format-slot"
verdict "marqueur illisible (« hexa »)" REFUSE "illisible" -- verifier "$RACINE" "$KS_DEPOT" "$SKG"
verdict "keyscript à poser sans --format" REFUSE "ne dit pas" -- verifier "$RACINE" "$KS_SANS" "$SKG"
printf 'pas un volume' > "$BANC/faux.img"
verdict "volume qui n'est pas LUKS" REFUSE "pas un volume LUKS" -- verifier "$BANC/faux.img" "$KS_DEPOT" "$SKG"
verdict "inscrire un format inconnu" REFUSE "format inconnu" -- inscrire "$RACINE" base64 "$SKG"

echo
if [ "$echec" -eq 0 ]; then
  printf '✅ %d/%d — le contrôle accepte les cas sains et refuse chaque défaut replanté.\n' "$total" "$total"
  exit 0
fi
printf '❌ des cas ont rendu le mauvais verdict (%d cas joués).\n' "$total"
exit 1
