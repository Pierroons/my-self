#!/usr/bin/env bash
# Comment cryptsetup lit une clé selon le chemin emprunté — et ce qui la casse.
#
# 🔑 **L'invariant que ce banc fixe.** `--key-file=-` est une lecture de *keyfile*
# dont la source est stdin : le flux est lu **en entier**, sauts de ligne compris. La
# clause « from stdin » de cryptsetup(8) ne vise que la lecture d'une *passphrase*,
# sans `--key-file` — chemin que l'amorçage Debian n'emprunte jamais. Qui ouvre la
# page de manuel conclut spontanément l'inverse ; d'où ce banc plutôt qu'une note.
#
# Il fixe les quatre lectures, et le seul mode de défaillance : un saut de ligne
# **final** ajouté à la clé, qui casse hex comme brut. C'est la propriété que
# `printf '%s'` protège dans le keyscript et dans les deux dérivateurs — s'ils
# passaient un jour à `echo`, ce banc rougirait.
#
# Mesure commentée et historique du démenti : docs/cryptsetup-lecture-cle.md
#
# Ne demande PAS root : conteneur LUKS2 jetable sur fichier, aucune activation
# device-mapper, `--test-passphrase` seulement. Ne touche aucun volume réel.
#
# Usage  : bash tests/test_lecture_keyfile.sh
# Sortie : 0 si chaque contrôle rend le verdict attendu, 1 sinon.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
CS="${CS:-cryptsetup}"

echec=0
total=0
verdict() {  # verdict <libellé> <attendu OUVRE|ECHOUE> <commande…>
  local libelle="$1" attendu="$2"; shift 2
  local obtenu
  total=$((total + 1))
  if "$@" >/dev/null 2>&1; then obtenu=OUVRE; else obtenu=ECHOUE; fi
  if [ "$obtenu" = "$attendu" ]; then
    printf '  ✅ %-50s %s\n' "$libelle" "$obtenu"
  else
    printf '  ❌ %-50s %s (attendu %s)\n' "$libelle" "$obtenu" "$attendu"
    echec=1
  fi
}

# cryptsetup vit dans /sbin, absent du PATH d'un utilisateur ordinaire — le banc n'a
# pourtant pas besoin de root.
command -v "$CS" >/dev/null 2>&1 || for c in /sbin/cryptsetup /usr/sbin/cryptsetup; do
  [ -x "$c" ] && CS="$c" && break
done
# Un outil manquant fait ÉCHOUER, jamais passer en silence : un banc qui se saute
# tout seul rend le même vert qu'un banc qui mesure.
command -v "$CS" >/dev/null 2>&1 || { echo "❌ cryptsetup introuvable ($CS)"; exit 1; }
# DERIVE_CMD permet d'éprouver le banc lui-même, en lui donnant un dérivateur fautif.
if [ -n "${DERIVE_CMD:-}" ]; then
  # shellcheck disable=SC2206  # découpage voulu : la variable porte une ligne de commande
  DERIVE=($DERIVE_CMD)
elif [ -x "$MODULE/selfrecover_derive" ]; then
  DERIVE=("$MODULE/selfrecover_derive")
elif python3 -c 'import argon2' 2>/dev/null; then
  DERIVE=(python3 "$MODULE/selfrecover_derive.py" --stdin)
else
  echo "❌ aucun dérivateur disponible : compile selfrecover_derive.c, ou installe python3-argon2"
  exit 1
fi

echo "▸ $("$CS" --version)"
echo "▸ dérivateur : ${DERIVE[*]}"

WD="$(mktemp -d)" || exit 1
# Le répertoire contient des clés en clair : ménage garanti même en cas d'erreur.
trap 'rm -rf "$WD"' EXIT INT TERM
cd "$WD" || exit 1

# ── Le cas qui sépare les lectures ───────────────────────────────────────────
# Clé fabriquée à la main, sans passer par la dérivation : le comportement de
# cryptsetup se mesure pour lui-même, indépendamment de ce que ce module produit.
# Elle porte un 0x0A, seul octet qui tronque une lecture de passphrase — un 0x00 la
# traverse (mesuré), et aucun des deux n'arrête une lecture de keyfile.
printf '\061\062\000\064\065\066\067\070\071\012\062\063\064\065\066\067\070\071\060\061\062\063\064\065\066\067\070\071\060\061\062\063' > cle.raw
od -An -tx1 cle.raw | tr -d ' \n' > cle.hex
{ cat cle.raw; printf '\n'; }  > cle_nl.raw
{ cat cle.hex; printf '\n'; }  > cle_nl.hex
{ cat cle.raw; printf 'BRUIT'; } > cle_bruit.raw

[ "$(wc -c < cle.raw)" = 32 ] || { echo "❌ clé de test mal formée"; exit 1; }
[ "$(wc -c < cle.hex)" = 64 ] || { echo "❌ hex de test mal formé"; exit 1; }
grep -q $'\x0a' cle.raw || { echo "❌ la clé de test ne contient pas de 0x0A : le banc ne mesurerait rien"; exit 1; }

# ── Conteneur jetable, un slot par format ────────────────────────────────────
truncate -s 32M vol.img
printf 'natif' > natif          # slot 0 : le filet, jamais retiré
FAST=(--pbkdf pbkdf2 --pbkdf-force-iterations 1000)
"$CS" luksFormat --batch-mode --type luks2 "${FAST[@]}" --key-file natif vol.img || exit 1
"$CS" luksAddKey --batch-mode "${FAST[@]}" --key-file natif vol.img cle.raw || exit 1
"$CS" luksAddKey --batch-mode "${FAST[@]}" --key-file natif vol.img cle.hex || exit 1

ouvre() { "$CS" open --test-passphrase "$@" vol.img; }
par_tube() { local f="$1"; shift; cat "$f" | ouvre --key-file=- "$@"; }

echo
echo "1) les quatre lectures, slot enrôlé en BRUT (la clé contient un 0x0A)"
verdict "fichier  --key-file"                    OUVRE  ouvre --key-file cle.raw
verdict "tube     --key-file=-"                  OUVRE  par_tube cle.raw
verdict "tube     --key-file=- + \\n final"       ECHOUE par_tube cle_nl.raw
verdict "tube     SANS --key-file (passphrase)"  ECHOUE bash -c 'cat cle.raw | "$0" open --test-passphrase vol.img' "$CS"

echo
echo "2) les mêmes, slot enrôlé en HEX"
verdict "fichier  --key-file"                    OUVRE  ouvre --key-file cle.hex
verdict "tube     --key-file=-"                  OUVRE  par_tube cle.hex
verdict "tube     --key-file=- + \\n final"       ECHOUE par_tube cle_nl.hex
verdict "tube     SANS --key-file (passphrase)"  OUVRE  bash -c 'cat cle.hex | "$0" open --test-passphrase vol.img' "$CS"

echo
echo "3) --keyfile-size est honoré pour un keyfile (32 o utiles + 5 o de bruit)"
verdict "tube     --keyfile-size 32"             OUVRE  par_tube cle_bruit.raw --keyfile-size 32
verdict "fichier  --keyfile-size 32"             OUVRE  ouvre --key-file cle_bruit.raw --keyfile-size 32

echo
echo "4) témoins négatifs — sans eux la matrice ne prouverait rien"
verdict "clé fausse par tube"                    ECHOUE bash -c 'printf nimportequoi | "$0" open --test-passphrase --key-file=- vol.img' "$CS"
verdict "troncature à 9 octets (avant le 0x0A)"  ECHOUE bash -c 'head -c 9 cle.raw | "$0" open --test-passphrase --key-file=- vol.img' "$CS"

# ── Bout en bout : le dérivateur du module, par le chemin du démarrage ───────
# Les lignes ci-dessus mesurent cryptsetup. Celles-ci mesurent CE QUE CE MODULE LUI
# DONNE — les seules qui rougiraient si un `echo` remplaçait un `printf '%s'`.
echo
echo "5) bout en bout : dérivateur → tube → cryptsetup, comme au démarrage"
SEL=0000000000000000000000000000000c
MOT='correct horse battery staple'
printf '%s' "$SEL" > sel
printf '%s' "$MOT" | "${DERIVE[@]}" --salt-file sel --label disk --format hex > derive.hex || exit 1

taille="$(wc -c < derive.hex)"
total=$((total + 1))
if [ "$taille" = 64 ]; then
  printf '  ✅ %-50s %s\n' "sortie du dérivateur : 64 o, aucun \\n final" "$taille"
else
  printf '  ❌ %-50s %s (attendu 64)\n' "sortie du dérivateur" "$taille"
  echec=1
fi

"$CS" luksAddKey --batch-mode "${FAST[@]}" --key-file natif vol.img derive.hex || exit 1
{ cat derive.hex; printf '\n'; } > derive_nl.hex
verdict "clé dérivée par tube"                   OUVRE  par_tube derive.hex
verdict "clé dérivée + \\n final"                 ECHOUE par_tube derive_nl.hex
verdict "clé dérivée + \\n, --keyfile-size 64"    OUVRE  par_tube derive_nl.hex --keyfile-size 64

echo
if [ "$echec" = 0 ]; then
  echo "✅ les $total contrôles rendent le verdict attendu"
else
  echo "❌ au moins une ligne diverge — voir docs/cryptsetup-lecture-cle.md"
fi
exit "$echec"
