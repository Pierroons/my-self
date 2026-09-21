#!/usr/bin/env bash
# `selfrecover-secours.sh` rend-il vraiment les deux voies, et JAMAIS un shell ?
#
# 🔑 **L'invariant que ce banc fixe.** La clé du dropbear d'amorçage donnait un
# shell root busybox avant l'ouverture de la racine — mesuré sur les quatre
# machines chiffrées du parc le 21/09/2026, et produit par `install.sh` lui-même.
# Ce shell contourne le chiffrement : /boot n'est pas chiffré, le sel y voyage,
# et qui l'obtient dépose un initrd modifié puis capture la passphrase suivante.
#
# Le remède évident — `command="cryptroot-unlock"` — ferme ce chemin mais retire
# le filet anti-lockout : cryptroot-unlock passe par le keyscript, donc la
# passphrase NATIVE devient inatteignable à distance. Ce script existe pour tenir
# les deux, et ce banc pour que « il tient les deux » cesse d'être une intention.
#
# Aucun privilège, aucun volume réel : cryptsetup, cryptroot-unlock et askpass
# sont des leurres qui consignent ce qu'on leur a passé.
#
# Usage  : bash tests/test_secours_sans_shell.sh
# Sortie : 0 si chaque propriété tient ET si chaque canari la fait rougir, 1 sinon.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
SECOURS="${SECOURS:-$MODULE/selfrecover-secours.sh}"
VERIFIMG="${VERIFIMG:-$MODULE/verifie-initramfs.sh}"
[ -x "$SECOURS" ]  || { echo "❌ introuvable : $SECOURS"; exit 1; }
[ -x "$VERIFIMG" ] || { echo "❌ introuvable : $VERIFIMG"; exit 1; }

T=$(mktemp -d)
nettoyer() { [ -n "${T:-}" ] && [ -d "$T" ] && find "$T" -mindepth 1 -delete && rmdir "$T"; }
trap nettoyer EXIT
echec=0; total=0
verifier() {  # verifier <libellé> <attendu> <obtenu>
  total=$((total+1))
  if [ "$2" = "$3" ]; then printf '  ✅ %-56s %s\n' "$1" "$3"
  else printf '  ❌ %-56s %s (attendu %s)\n' "$1" "$3" "$2"; echec=$((echec+1)); fi
}

# ---------- les leurres ----------
mkdir -p "$T/bin"
cat > "$T/bin/faux-cryptsetup" <<'EOF'
#!/bin/sh
# consigne les arguments, et la phrase reçue TELLE QUELLE : `od -c` rend visible
# un \n final, que la comparaison d'une variable shell effacerait en silence.
printf '%s\n' "$*" > "$TRACE/cryptsetup.args"
od -c > "$TRACE/cryptsetup.stdin"
exit "${FAUX_RC:-0}"
EOF
cat > "$T/bin/faux-unlock" <<'EOF'
#!/bin/sh
echo appele > "$TRACE/unlock.appele"; exit 0
EOF
cat > "$T/bin/faux-askpass" <<'EOF'
#!/bin/sh
printf '%s' "${FAUSSE_PHRASE:-phrase-native}"
EOF
chmod +x "$T/bin/faux-cryptsetup" "$T/bin/faux-unlock" "$T/bin/faux-askpass"

crypttab() {  # crypttab <fichier> <lignes...>
  local f="$1"; shift
  printf '%s\n' "$@" > "$f"
}

SCRIPT_TESTE="$SECOURS"
lancer() {  # lancer <choix> [rc du faux cryptsetup] -> imprime le code de sortie
  TRACE="$T/trace"
  [ -d "$TRACE" ] && find "$TRACE" -mindepth 1 -delete
  mkdir -p "$TRACE"
  printf '%s\n' "$1" | env \
    SR_CRYPTTAB="$T/crypttab" SR_CRYPTSETUP="$T/bin/faux-cryptsetup" \
    SR_UNLOCK="$T/bin/faux-unlock" SR_ASKPASS="$T/bin/faux-askpass" \
    FAUX_RC="${2:-0}" TRACE="$TRACE" SSH_ORIGINAL_COMMAND="/bin/sh -i" \
    "$SCRIPT_TESTE" >"$TRACE/sortie" 2>&1
  echo $?
}
TRACE="$T/trace"

echo
echo "=== 1. Les deux voies ==="
crypttab "$T/crypttab" '# commentaire qui ne doit pas être lu' 'racine /dev/sda3 none luks'
rc=$(lancer 1)
verifier "choix 1 : appelle cryptroot-unlock"        "oui" "$([ -f "$TRACE/unlock.appele" ] && echo oui || echo non)"
verifier "choix 1 : n'appelle PAS cryptsetup"        "non" "$([ -f "$TRACE/cryptsetup.args" ] && echo oui || echo non)"
verifier "choix 1 : code de sortie"                  "0"   "$rc"

rc=$(lancer 2)
verifier "choix 2 : appelle cryptsetup"              "oui" "$([ -f "$TRACE/cryptsetup.args" ] && echo oui || echo non)"
verifier "choix 2 : n'appelle PAS cryptroot-unlock"  "non" "$([ -f "$TRACE/unlock.appele" ] && echo oui || echo non)"
verifier "choix 2 : device et mapper lus du crypttab" "open /dev/sda3 racine --key-file=-" "$(cat "$TRACE/cryptsetup.args" 2>/dev/null)"
verifier "choix 2 : code de sortie"                  "0"   "$rc"
verifier "la ligne commentée est ignorée"            "oui" \
  "$(grep -q '/dev/sda3 racine' "$TRACE/cryptsetup.args" 2>/dev/null && echo oui || echo non)"

echo
echo "=== 2. La passphrase arrive intacte (un \\n final changerait la clé) ==="
verifier "octets exacts reçus par cryptsetup" "p   h   r   a   s   e   -   n   a   t   i   v   e" \
  "$(head -1 "$TRACE/cryptsetup.stdin" 2>/dev/null | sed 's/^[0-7]*  *//;s/  *$//')"

echo
echo "=== 3. Ce qu'il REFUSE ==="
rc=$(lancer 2 1)
verifier "phrase native rejetée par cryptsetup -> 1" "1" "$rc"
rc=$(lancer 9)
verifier "choix inconnu -> 2"                        "2" "$rc"
verifier "choix inconnu : rien n'est appelé"         "non" \
  "$( { [ -f "$TRACE/unlock.appele" ] || [ -f "$TRACE/cryptsetup.args" ]; } && echo oui || echo non)"
mv "$T/crypttab" "$T/crypttab.ecarte"
rc=$(lancer 1)
verifier "crypttab absent -> 2"                      "2" "$rc"
crypttab "$T/crypttab" '# rien que des commentaires'
rc=$(lancer 1)
verifier "crypttab sans cible -> 2"                  "2" "$rc"

echo
echo "=== 4. 🔴 Il ne rend JAMAIS de shell ==="
crypttab "$T/crypttab" 'racine /dev/sda3 none luks'
verifier "aucun exec/eval vers un shell dans le script" "0" \
  "$(grep -cE '^[^#]*(exec|eval)[[:space:]]+[^|]*(/bin/)?(sh|bash|dash|busybox)([[:space:]]|$)' "$SECOURS")"
verifier "SSH_ORIGINAL_COMMAND jamais lu"            "0" \
  "$(grep -cE '^[^#]*SSH_ORIGINAL_COMMAND' "$SECOURS")"

echo
echo "=== 5. L'empreinte hors de /boot voit-elle une image altérée ? ==="
mkdir -p "$T/boot" "$T/skg"
printf 'image-legitime' > "$T/boot/initrd.img-6.1.0-test"
S=$(sha256sum < "$T/boot/initrd.img-6.1.0-test" | cut -d' ' -f1)
printf '6.1.0-test\t%s\t2026-09-21T22:00:00+02:00\n' "$S" > "$T/skg/initramfs.sha256"
env SKG="$T/skg" BOOT="$T/boot" "$VERIFIMG" >/dev/null 2>&1; verifier "image intacte -> 0" "0" "$?"
printf 'image-alteree!' > "$T/boot/initrd.img-6.1.0-test"
env SKG="$T/skg" BOOT="$T/boot" "$VERIFIMG" >/dev/null 2>&1; verifier "image altérée -> 1" "1" "$?"
mv "$T/boot/initrd.img-6.1.0-test" "$T/image.ecartee"
env SKG="$T/skg" BOOT="$T/boot" "$VERIFIMG" >/dev/null 2>&1; verifier "image absente -> 2 (jamais 0)" "2" "$?"
mv "$T/skg/initramfs.sha256" "$T/empreintes.ecartees"
env SKG="$T/skg" BOOT="$T/boot" "$VERIFIMG" >/dev/null 2>&1; verifier "aucune empreinte -> 2 (jamais 0)" "2" "$?"

echo
echo "=== 6. 🐤 Canaris — chaque propriété doit ROUGIR sur le défaut replanté ==="
# Un vert n'est une preuve que si le rouge est atteignable. On replante le défaut
# que chaque contrôle prétend attraper, et on exige qu'il cesse de passer.
canari() {  # canari <libellé> <expression sed qui replante le défaut>
  total=$((total+1))
  local c="$T/canari.sh" avant="$SCRIPT_TESTE" sorti vu=non
  sed "$2" "$SECOURS" > "$c"; chmod +x "$c"
  SCRIPT_TESTE="$c"
  crypttab "$T/crypttab" 'racine /dev/sda3 none luks'
  sorti=$(lancer 2)
  [ "$(cat "$TRACE/cryptsetup.args" 2>/dev/null)" != "open /dev/sda3 racine --key-file=-" ] && vu=oui
  [ "$(head -1 "$TRACE/cryptsetup.stdin" 2>/dev/null | sed 's/^[0-7]*  *//;s/  *$//')" \
      != "p   h   r   a   s   e   -   n   a   t   i   v   e" ] && vu=oui
  [ "$sorti" != "0" ] && vu=oui
  SCRIPT_TESTE="$avant"
  if [ "$vu" = oui ]; then printf '  ✅ %-56s rougit\n' "$1"
  else printf '  ❌ %-56s VERT (sonde morte)\n' "$1"; echec=$((echec+1)); fi
}
canari "replanté : echo au lieu de printf (\\n final sur la clé)" 's|printf .%s. "\$PASS"|echo "$PASS"|'
canari "replanté : --key-file=- retiré"                           's| --key-file=-||'
canari "replanté : device codé en dur au lieu du crypttab"        's|"\$DEV" "\$NAME"|/dev/WRONG "$NAME"|'

echo
if [ "$echec" -eq 0 ]; then
  echo "✅ $total/$total — les deux voies tiennent, aucun shell, et les canaris rougissent."
  exit 0
else
  echo "❌ $echec échec(s) sur $total"
  exit 1
fi
