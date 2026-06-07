#!/usr/bin/env bash
# PoC : SelfRecover -> key slot LUKS, sur une IMAGE-FICHIER JETABLE (aucun vrai disque touché).
# Prérequis : sudo apt install cryptsetup   (+ argon2-cffi côté user)
# Lancer    : sudo bash test-luks-selfrecover.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
# La dérivation tourne en tant qu'utilisateur (argon2-cffi est installé côté user),
# le résultat est passé à cryptsetup qui, lui, a besoin de root.
RUN_AS="${SUDO_USER:-$USER}"
DERIVE=(sudo -u "$RUN_AS" python3 "$HERE/selfrecover_derive.py")

IMG=/tmp/luks-selfrecover-test.img
MAP=selfrecover_test
WORD="correct horse battery staple"   # mot de récupération (à garder FORT en vrai)
SALT="site-salt-example"              # sel propre au déploiement
INIT="passphrase-init-temporaire"     # slot 0 (jetable, juste pour le test)
TMP="$(mktemp -d)"

cleanup() {
  cryptsetup status "$MAP" >/dev/null 2>&1 && cryptsetup luksClose "$MAP" || true
  rm -f "$IMG"; rm -rf "$TMP"
}
trap cleanup EXIT

command -v cryptsetup >/dev/null || { echo "❌ cryptsetup absent : sudo apt install cryptsetup"; exit 1; }
"${DERIVE[@]}" --word x --salt y --label disk >/dev/null || { echo "❌ argon2-cffi indispo pour $RUN_AS"; exit 1; }

echo "1) image-fichier jetable (48 Mo)"
dd if=/dev/zero of="$IMG" bs=1M count=48 status=none

echo "2) LUKS2 format — slot 0 = passphrase init"
printf '%s' "$INIT" > "$TMP/init.key"
cryptsetup luksFormat --type luks2 --batch-mode "$IMG" "$TMP/init.key"

echo "3) dérive la clé SelfRecover (mot de récup, label=disk) + l'ajoute en slot 1"
"${DERIVE[@]}" --word "$WORD" --salt "$SALT" --label disk --format raw > "$TMP/sr.key"
cryptsetup luksAddKey "$IMG" "$TMP/sr.key" --key-file "$TMP/init.key" --key-slot 1

echo "4) ouverture AVEC la clé SelfRecover (slot 1)"
cryptsetup luksOpen "$IMG" "$MAP" --key-file "$TMP/sr.key"
echo "   ✅ volume ouvert via le slot SelfRecover"
cryptsetup luksClose "$MAP"

echo "5) secours réaliste : on REDÉRIVE le mot à la volée et on ouvre (pipe, comme au boot)"
"${DERIVE[@]}" --word "$WORD" --salt "$SALT" --label disk --format raw \
  | cryptsetup luksOpen "$IMG" "$MAP" --key-file=-
echo "   ✅ ré-ouvert en retapant le mot → le mot de récup SUFFIT à ouvrir LUKS"
cryptsetup luksClose "$MAP"

echo "6) contrôle négatif : mauvais mot = refus"
if "${DERIVE[@]}" --word "MAUVAIS mot" --salt "$SALT" --label disk --format raw \
   | cryptsetup luksOpen "$IMG" "$MAP" --key-file=- 2>/dev/null; then
  echo "   ❌ ANOMALIE : ouvert avec le mauvais mot"; cryptsetup luksClose "$MAP"; exit 1
else
  echo "   ✅ mauvais mot → refusé"
fi

echo
echo "=== slots occupés sur le volume ==="
cryptsetup luksDump "$IMG" | grep -E "^\s+[0-9]+: luks2" || true
echo "PoC terminé — image et clés détruites automatiquement (trap)."
