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
# Le second invariant : la borne keyfile-size de la crypttab est la longueur de la clé
# que le keyscript présente, ou elle est absente. cryptsetup lit exactement la borne ;
# une borne raw sous un keyscript hex tronque la clé. La table des longueurs de
# `format-slot.sh taille` est rejouée contre cryptsetup lui-même, pas recopiée.
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
echo "▸ La borne keyfile-size — cryptsetup lit exactement ce qu'elle dit"
# La matrice de docs/cryptsetup-lecture-cle.md §6, rejouée sur de vrais conteneurs.
# Une clé par conteneur : une ouverture ne peut pas venir du slot de l'autre format.
head -c 32 /dev/urandom > "$BANC/raw.key"
od -An -tx1 "$BANC/raw.key" | tr -d ' \n' > "$BANC/hex.key"
volume_cle() {
  truncate -s 32M "$1"
  cryptsetup luksFormat --type luks2 --pbkdf pbkdf2 --pbkdf-force-iterations 1000 -q "$1" "$2" \
    >/dev/null 2>&1 || { echo "❌ luksFormat $1"; exit 1; }
}
volume_cle "$BANC/vol-raw.img" "$BANC/raw.key"
volume_cle "$BANC/vol-hex.img" "$BANC/hex.key"
T_HEX="$(bash "$FMT" taille "$KS_DEPOT")"
T_RAW="$(bash "$FMT" taille "$KS_RAW")"

ouverture() {  # ouverture <libellé> <OUVRE|ECHOUE> <volume> <clé> [borne]
  local obtenu
  total=$((total + 1))
  # Par stdin, comme le keyscript au démarrage.
  if cryptsetup open --test-passphrase --key-file=- ${5:+--keyfile-size "$5"} "$3" < "$4" >/dev/null 2>&1
  then obtenu=OUVRE; else obtenu=ECHOUE; fi
  if [ "$obtenu" = "$2" ]; then printf '  ✅ %-58s %s\n' "$1" "$obtenu"
  else printf '  ❌ %-58s %s (attendu %s)\n' "$1" "$obtenu" "$2"; echec=1; fi
}
ouverture "clé hex, borne que format-slot.sh donne au hex ($T_HEX)" OUVRE "$BANC/vol-hex.img" "$BANC/hex.key" "$T_HEX"
ouverture "clé raw, borne que format-slot.sh donne au raw ($T_RAW)" OUVRE "$BANC/vol-raw.img" "$BANC/raw.key" "$T_RAW"
ouverture "clé hex sous la borne du raw : tronquée" ECHOUE "$BANC/vol-hex.img" "$BANC/hex.key" "$T_RAW"
ouverture "clé raw sous la borne du hex : octets manquants" ECHOUE "$BANC/vol-raw.img" "$BANC/raw.key" "$T_HEX"
ouverture "clé raw sans borne (racine équipée avant la borne)" OUVRE "$BANC/vol-raw.img" "$BANC/raw.key"
ouverture "clé hex sans borne" OUVRE "$BANC/vol-hex.img" "$BANC/hex.key"

echo
echo "▸ format-slot.sh borne — une ligne de crypttab, et elle seule"
code_rendu() {  # code_rendu <libellé> <code attendu> <motif|--> -- <arguments de format-slot.sh...>
  local libelle="$1" attendu="$2" motif="$3" texte code; shift 4
  total=$((total + 1))
  texte="$(bash "$FMT" "$@" 2>&1)"; code=$?
  if [ "$code" != "$attendu" ]; then
    printf '  ❌ %-58s code %s (attendu %s)\n' "$libelle" "$code" "$attendu"
    printf '%s\n' "$texte" | sed 's/^/        /' | head -4; echec=1; return
  fi
  if [ "$motif" != "--" ] && ! printf '%s' "$texte" | grep -qi -- "$motif"; then
    printf '  ❌ %-58s code %s mais sans dire « %s »\n' "$libelle" "$code" "$motif"; echec=1; return
  fi
  printf '  ✅ %-58s code %s\n' "$libelle" "$code"
}
constat() {  # constat <libellé> <commande...>
  local libelle="$1"; shift
  total=$((total + 1))
  if "$@"; then printf '  ✅ %-58s vrai\n' "$libelle"
  else printf '  ❌ %-58s faux\n' "$libelle"; echec=1; fi
}
borne_racine() { grep '^racine_crypt ' "$CT" | grep -o 'keyfile-size=[0-9]*'; }

CT="$BANC/crypttab"
cat > "$CT" <<'EOF'
# volume racine
racine_crypt UUID=00000000-0000-0000-0000-000000000001 none luks,discard,x-initrd.attach,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh
donnees UUID=00000000-0000-0000-0000-000000000002 /etc/keys/data.key luks,keyfile-size=4096,nofail
swap /dev/zram0 none
EOF
cp "$CT" "$BANC/crypttab.ref"

code_rendu "borne absente : signalée, sans écrire" 3 "aucune borne" -- borne racine_crypt "$KS_DEPOT" "$CT"
constat "… la crypttab est intacte" cmp -s "$CT" "$BANC/crypttab.ref"
code_rendu "--ecrire pose la borne du hex" 0 "absente" -- borne racine_crypt "$KS_DEPOT" "$CT" --ecrire
constat "la racine porte keyfile-size=$T_HEX, une seule fois" test "$(borne_racine)" = "keyfile-size=$T_HEX"
constat "les autres lignes n'ont pas bougé" \
  diff -q <(grep -v '^racine_crypt ' "$BANC/crypttab.ref") <(grep -v '^racine_crypt ' "$CT")
constat "la crypttab d'avant est copiée à côté" cmp -s "$BANC/crypttab.ref" "$(ls "$CT".avant-borne.* | head -1)"
code_rendu "borne conforme" 0 "keyfile-size=$T_HEX" -- borne racine_crypt "$KS_DEPOT" "$CT"

sed -i "s|^racine_crypt .*|racine_crypt UUID=00000000-0000-0000-0000-000000000001 none luks,keyfile-size=$T_RAW,discard,x-initrd.attach,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh|" "$CT"
code_rendu "borne du raw sous un keyscript hex : refus" 1 "tronquée" -- borne racine_crypt "$KS_DEPOT" "$CT"
code_rendu "--ecrire la corrige" 0 "$T_RAW → $T_HEX" -- borne racine_crypt "$KS_DEPOT" "$CT" --ecrire
constat "corrigée à sa place, sans doublon" \
  grep -q "^racine_crypt .* luks,keyfile-size=$T_HEX,discard,x-initrd.attach,keyscript=[^,]*\$" "$CT"
code_rendu "borne du hex sous un keyscript raw : refus" 1 "tronquée" -- borne racine_crypt "$KS_RAW" "$CT"
code_rendu "nom absent de la crypttab : non jugeable" 4 "aucune ligne" -- borne inconnu "$KS_DEPOT" "$CT"
code_rendu "ligne sans options : non jugeable" 4 "pas de colonne" -- borne swap "$KS_DEPOT" "$CT" --ecrire
code_rendu "keyscript sans --format : non jugeable" 4 "ne dit pas" -- borne racine_crypt "$KS_SANS" "$CT"
code_rendu "taille d'un keyscript sans --format : refus" 1 "ne dit pas" -- taille "$KS_SANS"

# Un nom à échappement octal, que crypttab et l'image gardent tels quels.
printf 'r\\040c UUID=00000000-0000-0000-0000-000000000003 none luks,keyscript=/k,keyfile-size=%s\n' "$T_HEX" >> "$CT"
code_rendu "nom à échappement octal (r\\040c) : lu tel quel" 0 "conforme\|keyfile-size=$T_HEX" -- \
  borne 'r\040c' "$KS_DEPOT" "$CT"

# --format=hex : la même chose écrite autrement.
KS_EGAL="$BANC/keyscript-egal.sh"
sed '$ s/--format hex/--format=hex/' "$KS_DEPOT" > "$KS_EGAL"
code_rendu "keyscript en --format=hex : taille $T_HEX" 0 "^$T_HEX\$" -- taille "$KS_EGAL"

# Deux bornes sur la ligne : cryptsetup lit la dernière, --ecrire les corrige toutes.
sed -i "s|^racine_crypt .*|racine_crypt UUID=00000000-0000-0000-0000-000000000001 none luks,keyfile-size=$T_HEX,keyscript=/etc/selfkeyguard/selfrecover-keyscript.sh,keyfile-size=$T_RAW|" "$CT"
code_rendu "deux bornes, la dernière raw : refus" 1 "tronquée" -- borne racine_crypt "$KS_DEPOT" "$CT"
code_rendu "--ecrire corrige les deux" 0 "→ $T_HEX" -- borne racine_crypt "$KS_DEPOT" "$CT" --ecrire
constat "plus aucune borne raw sur la ligne" \
  test "$(grep '^racine_crypt ' "$CT" | grep -o 'keyfile-size=[0-9]*' | sort -u)" = "keyfile-size=$T_HEX"

echo
if [ "$echec" -eq 0 ]; then
  printf '✅ %d/%d — le contrôle accepte les cas sains et refuse chaque défaut replanté.\n' "$total" "$total"
  exit 0
fi
printf '❌ des cas ont rendu le mauvais verdict (%d cas joués).\n' "$total"
exit 1
