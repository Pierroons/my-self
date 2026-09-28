#!/usr/bin/env bash
# `setup-add-selfrecover-slot.sh` prouve-t-il le slot par le binaire qui DÉMARRE ?
#
# 🔑 **L'invariant que ce banc fixe.** Un slot n'est déclaré bon que si le dérivateur
# de l'amorçage l'ouvre. L'enrôlement passe par le dérivateur Python, la preuve par le
# binaire C : deux implémentations du même calcul, qui peuvent diverger — version
# d'argon2, encodage, troncature — sans que rien ne le dise avant le redémarrage
# suivant, celui où la machine ne revient pas.
#
# Le cas qui a motivé le contrôle : jusqu'au 28/09/2026, l'enrôlement et la preuve
# appelaient tous deux le Python pendant que `selfrecover-keyscript.sh` démarrait en C.
# `INSTALL.md §6` exigeait le contrôle par le C ; l'outillage ne le faisait pas, et
# rien ne rougissait.
#
# Ne demande PAS root : conteneurs LUKS2 de 32 Mo sur fichier, aucune activation
# device-mapper. Même forme que test_format_slot.sh.
#
# Usage  : bash tests/test_preuve_binaire_boot.sh
# Sortie : 0 si chaque cas rend le verdict attendu, 1 sinon.

set -uo pipefail
export PATH="/usr/sbin:/sbin:$PATH"

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
SETUP="${SETUP:-$MODULE/setup-add-selfrecover-slot.sh}"   # surchargeable : canari de CI

command -v cryptsetup >/dev/null || { echo "❌ cryptsetup absent"; exit 1; }
python3 -c 'import argon2' 2>/dev/null || { echo "❌ python3-argon2 absent"; exit 1; }

BANC="$(mktemp -d "${TMPDIR:-/tmp}/banc-preuve-boot.XXXXXX")" || { echo "❌ mktemp"; exit 1; }
trap 'rm -rf "$BANC"' EXIT

# Le dérivateur du démarrage. Compilé par la CI dans le module ; sinon compilé ici.
# Pas de repli silencieux sur le Python : c'est précisément le chemin que ce banc
# éprouve, et le sauter rendrait un vert qui ne mesure rien.
DERIVE_C="$MODULE/selfrecover_derive"
if [ ! -x "$DERIVE_C" ]; then
  DERIVE_C="$BANC/selfrecover_derive"
  cc -O2 -Wall -o "$DERIVE_C" "$MODULE/selfrecover_derive.c" -l:libargon2.so.1 2>/dev/null \
    || { echo "❌ selfrecover_derive.c ne compile pas (libargon2-dev absent ?) — banc non exécutable"; exit 1; }
fi

# Le script sous banc est recopié à l'écart : ses deux chemins de recherche du binaire
# ($SKG puis son propre répertoire) doivent être VIDES pour que le cas « binaire
# absent » mesure ce qu'il prétend. Un binaire compilé dans le module le ferait passer
# sans rien refuser.
ATELIER="$BANC/atelier"; mkdir -p "$ATELIER"
cp "$MODULE/selfrecover_derive.py" "$MODULE/format-slot.sh" "$ATELIER/" \
  || { echo "❌ copie de l'atelier"; exit 1; }
SCRIPT="$ATELIER/setup-add-selfrecover-slot.sh"
cp "$SETUP" "$SCRIPT" || { echo "❌ script introuvable : $SETUP"; exit 1; }

SEL="0123456789abcdef0123456789abcdef"
RECOVER="passe recover du banc sept mots"
NATIF="$BANC/natif.key"; printf 'phrase-de-banc' > "$NATIF"

# Un dérivateur qui rend une clé valide par sa FORME et fausse par sa valeur : c'est
# la divergence entre deux implémentations, vue du script.
DIVERGENT="$BANC/derive_divergent"
cat > "$DIVERGENT" <<'FAUX'
#!/bin/sh
cat > /dev/null
printf '%s' '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff'
FAUX
chmod +x "$DIVERGENT"

nouveau_volume() {
  truncate -s 32M "$1"
  printf 'phrase-de-banc' | cryptsetup luksFormat --type luks2 \
    --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "$1" - >/dev/null 2>&1 \
    || { echo "❌ luksFormat $1"; exit 1; }
}
slots() { cryptsetup luksDump "$1" | grep -cE '^[[:space:]]+[0-9]+: luks2'; }

echec=0; total=0
verdict() {  # verdict <libellé> <ACCEPTE|REFUSE> <motif attendu|--> <slots attendus|--> <sortie> <code>
  local libelle="$1" attendu="$2" motif="$3" slots_attendus="$4" sortie="$5" code="$6"
  local obtenu; total=$((total + 1))
  [ "$code" -eq 0 ] && obtenu=ACCEPTE || obtenu=REFUSE
  if [ "$obtenu" != "$attendu" ]; then
    printf '  ❌ %-56s %s (attendu %s)\n' "$libelle" "$obtenu" "$attendu"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4; echec=1; return
  fi
  if [ "$motif" != "--" ] && ! printf '%s' "$sortie" | grep -qi -- "$motif"; then
    printf '  ❌ %-56s %s mais sans dire « %s »\n' "$libelle" "$obtenu" "$motif"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4; echec=1; return
  fi
  if [ "$slots_attendus" != "--" ] && [ "$SLOTS_APRES" != "$slots_attendus" ]; then
    printf '  ❌ %-56s %s mais %s slot(s), %s attendu(s)\n' \
      "$libelle" "$obtenu" "$SLOTS_APRES" "$slots_attendus"; echec=1; return
  fi
  printf '  ✅ %-56s %s\n' "$libelle" "$obtenu"
}

# lance <image> <répertoire SKG> [VAR=valeur ...] — la passphrase entre par stdin,
# deux fois, comme au clavier.
lance() {
  local img="$1" skg="$2"; shift 2
  printf '%s\n%s\n' "$RECOVER" "$RECOVER" \
    | env SELFRECOVER_SALT="$SEL" SKG="$skg" SELFRECOVER_TMPDIR="$BANC" "$@" \
        bash "$SCRIPT" "$img" --existing-keyfile "$NATIF" 2>&1
}

# Le répertoire de travail sort du poste de qui lance : une sortie de banc finit
# parfois collée dans une issue, et un chemin absolu y nomme un compte.
sans_home() { printf '%s' "${1/#$HOME/\~}"; }

echo
echo "▸ script sous banc : $(sans_home "$SETUP")"
echo "▸ dérivateur du démarrage : $(sans_home "$DERIVE_C")"
echo

echo "▸ Le vecteur de référence publié — INSTALL.md le donne, personne ne le vérifiait"
# Lu DANS le document, jamais recopié : un vecteur recopié ici et un vecteur imprimé
# là-bas divergent en silence, et c'est le lecteur qui découvre l'écart.
DOC="$MODULE/INSTALL.md"
VECTEUR="$(grep -oE '^[0-9a-f]{64}$' "$DOC" | head -n1)"
SEL_DOC="$(grep -oE "printf '[0-9a-f]+\\\\n' > /tmp/sel-test" "$DOC" | grep -oE '[0-9a-f]{8,}' | head -n1)"
MOT_DOC="$(grep -oE "printf '%s' '[^']+'" "$DOC" | head -n1 | sed "s/.*'%s' '//; s/'\$//")"
total=$((total + 1))
if [ -n "$VECTEUR" ] && [ -n "$SEL_DOC" ] && [ -n "$MOT_DOC" ]; then
  printf '  ✅ %-56s %s\n' "INSTALL.md publie encore vecteur, sel et passphrase" ACCEPTE
  printf '%s' "$SEL_DOC" > "$BANC/sel-vecteur"
  for impl in "$DERIVE_C --salt-file $BANC/sel-vecteur" \
              "python3 $MODULE/selfrecover_derive.py --stdin --salt-file $BANC/sel-vecteur"; do
    total=$((total + 1))
    nom="$(basename "${impl%% --*}")"
    obtenu="$(printf '%s' "$MOT_DOC" | $impl --label disk --format hex 2>&1)"
    if [ "$obtenu" = "$VECTEUR" ]; then
      printf '  ✅ %-56s %s\n' "$nom rend le vecteur publié" ACCEPTE
    else
      printf '  ❌ %-56s rend %s\n' "$nom rend le vecteur publié" "${obtenu:0:16}…"
      echec=1
    fi
  done
else
  printf '  ❌ %-56s introuvable dans INSTALL.md\n' "vecteur, sel et passphrase de référence"
  echec=1
fi

echo
echo "▸ Le cas sain — la preuve passe par le binaire du démarrage"
IMG="$BANC/nominal.img"; nouveau_volume "$IMG"
SKG="$BANC/skg-nominal"; mkdir -p "$SKG"; cp "$DERIVE_C" "$SKG/selfrecover_derive_c"
S="$(lance "$IMG" "$SKG")"; C=$?
SLOTS_APRES="$(slots "$IMG")"
verdict "binaire cohérent : le slot est ajouté et prouvé" ACCEPTE "selfrecover_derive_c" 2 "$S" "$C"
total=$((total + 1))
if printf '%s' "$S" | grep -q "chemin du demarrage"; then
  printf '  ✅ %-56s %s\n' "la preuve nomme le chemin qu'elle a emprunté" ACCEPTE
else
  printf '  ❌ %-56s le script ne dit pas par où il a prouvé\n' "la preuve nomme le chemin qu'elle a emprunté"
  echec=1
fi
total=$((total + 1))
if [ -s "$SKG/format-slot" ]; then
  printf '  ✅ %-56s %s\n' "le marqueur de format est inscrit" ACCEPTE
else
  printf '  ❌ %-56s marqueur absent\n' "le marqueur de format est inscrit"; echec=1
fi

echo
echo "▸ Les canaris — chacun doit faire rougir le contrôle"
# Canari 1 : aucun binaire de démarrage. Le refus doit tomber AVANT luksAddKey,
# sinon le volume repart avec un slot que personne n'a prouvé.
IMG="$BANC/sans-binaire.img"; nouveau_volume "$IMG"
SKG="$BANC/skg-vide"; mkdir -p "$SKG"
S="$(lance "$IMG" "$SKG")"; C=$?
SLOTS_APRES="$(slots "$IMG")"
verdict "binaire du démarrage absent : refus, aucun slot ajouté" REFUSE "introuvable" 1 "$S" "$C"

# Canari 2 : LE cas redouté. Le binaire du démarrage dérive autre chose que
# l'enrôleur. L'ancien contrôle, qui appelait deux fois le Python, rendait vert ici.
IMG="$BANC/divergent.img"; nouveau_volume "$IMG"
SKG="$BANC/skg-divergent"; mkdir -p "$SKG"; cp "$DIVERGENT" "$SKG/selfrecover_derive_c"
S="$(lance "$IMG" "$SKG")"; C=$?
SLOTS_APRES="$(slots "$IMG")"
verdict "les deux dérivateurs divergent : refus" REFUSE "divergent" 2 "$S" "$C"
total=$((total + 1))
if [ ! -s "$SKG/format-slot" ]; then
  printf '  ✅ %-56s %s\n' "divergence : aucun marqueur de format écrit" REFUSE
else
  printf '  ❌ %-56s marqueur écrit alors que la preuve a échoué\n' "divergence : aucun marqueur de format écrit"
  echec=1
fi

# Canari 3 : la porte de sortie existe, elle se nomme, et elle le dit.
IMG="$BANC/echappement.img"; nouveau_volume "$IMG"
SKG="$BANC/skg-echappement"; mkdir -p "$SKG"
S="$(lance "$IMG" "$SKG" J_ACCEPTE_PREUVE_SANS_BINAIRE_BOOT=oui)"; C=$?
SLOTS_APRES="$(slots "$IMG")"
verdict "échappement nommé : accepté, et annoncé" ACCEPTE "DESARME" 2 "$S" "$C"

echo
if [ "$echec" -eq 0 ]; then
  echo "✅ $total/$total — la preuve du slot passe par le dérivateur de l'amorçage"
else
  echo "❌ au moins un cas a rendu le mauvais verdict ($total contrôles)"
fi
exit "$echec"
