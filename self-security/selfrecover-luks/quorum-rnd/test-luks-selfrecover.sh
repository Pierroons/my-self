#!/usr/bin/env bash
# PoC : SelfRecover -> key slot LUKS, sur une IMAGE-FICHIER JETABLE (aucun vrai disque touché).
# Prérequis : sudo apt install cryptsetup   (+ argon2-cffi côté user)
# Lancer    : sudo bash test-luks-selfrecover.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# La dérivation tourne en tant qu'utilisateur (argon2-cffi est installé côté user),
# le résultat est passé à cryptsetup qui, lui, a besoin de root.
RUN_AS="${SUDO_USER:-$USER}"
DERIVE=(sudo -u "$RUN_AS" python3 "$HERE/../selfrecover_derive.py")

IMG=/tmp/luks-selfrecover-test.img
MAP=selfrecover_test
WORD="correct horse battery staple"   # mot de récupération (à garder FORT en vrai)
INIT="passphrase-init-temporaire"     # slot 0 (jetable, juste pour le test)
TMP="$(mktemp -d)"

cleanup() {
  cryptsetup status "$MAP" >/dev/null 2>&1 && cryptsetup luksClose "$MAP" || true
  rm -f "$IMG"; rm -rf "$TMP"
}
trap cleanup EXIT

# --stdin et non --word : la dérivation passe par `sudo -u`, donc la ligne de commande
# complète part dans auth.log — et donc dans les sauvegardes — pendant que tout processus
# local peut lire /proc/<pid>/cmdline le temps de l'exécution.
#
# --format hex : voir la note dans ../selfrecover-keyscript.sh. La cle enrolee doit
# etre celle que le keyscript produira.
derive() {   # $1 = sel, $2 = mot -> clé hex sur stdout
  printf '%s' "$2" | "${DERIVE[@]}" --stdin --salt "$1" --label disk --format hex
}

# Ce que le PoC établit : la chaîne passphrase → Argon2id → slot LUKS se referme. Pas
# comment cryptsetup lit une clé — cette question-là a son banc, ../tests/test_lecture_keyfile.sh.
eprouve_sel() {
  local SALT="$1" ETIQUETTE="$2"
  echo
  echo "══ sel « $SALT » — $ETIQUETTE"

  echo "1) image-fichier jetable (48 Mo)"
  dd if=/dev/zero of="$IMG" bs=1M count=48 status=none

  echo "2) LUKS2 format — slot 0 = passphrase init"
  printf '%s' "$INIT" > "$TMP/init.key"
  cryptsetup luksFormat --type luks2 --batch-mode "$IMG" "$TMP/init.key"

  echo "3) dérive la clé SelfRecover (mot de récup, label=disk) + l'ajoute en slot 1"
  derive "$SALT" "$WORD" > "$TMP/sr.key"
  cryptsetup luksAddKey "$IMG" "$TMP/sr.key" --key-file "$TMP/init.key" --key-slot 1

  echo "4) ouverture AVEC la clé SelfRecover (slot 1), lue depuis un FICHIER"
  cryptsetup luksOpen "$IMG" "$MAP" --key-file "$TMP/sr.key"
  echo "   ✅ volume ouvert via le slot SelfRecover"
  cryptsetup luksClose "$MAP"

  echo "5) secours réaliste : on REDÉRIVE le mot à la volée et on ouvre (pipe, comme au boot)"
  derive "$SALT" "$WORD" | cryptsetup luksOpen "$IMG" "$MAP" --key-file=-
  echo "   ✅ ré-ouvert en retapant le mot → le mot de récup SUFFIT à ouvrir LUKS"
  cryptsetup luksClose "$MAP"

  echo "6) contrôle négatif : mauvais mot = refus"
  if derive "$SALT" "MAUVAIS mot" | cryptsetup luksOpen "$IMG" "$MAP" --key-file=- 2>/dev/null; then
    echo "   ❌ ANOMALIE : ouvert avec le mauvais mot"; cryptsetup luksClose "$MAP"; exit 1
  else
    echo "   ✅ mauvais mot → refusé"
  fi

  echo "   === slots occupés sur le volume ==="
  cryptsetup luksDump "$IMG" | grep -E "^\s+[0-9]+: luks2" || true
  rm -f "$IMG"
}

command -v cryptsetup >/dev/null || { echo "❌ cryptsetup absent : sudo apt install cryptsetup"; exit 1; }
derive y x >/dev/null || { echo "❌ argon2-cffi indispo pour $RUN_AS"; exit 1; }

eprouve_sel "site-salt-example" "premier sel"
eprouve_sel "sel-temoin-second" "second sel"

echo
echo "PoC terminé sur 2 sels — images et clés détruites automatiquement (trap)."
