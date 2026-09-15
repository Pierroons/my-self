#!/usr/bin/env bash
# Test sur IMAGE LUKS JETABLE (aucun vrai disque touché).
# Simule le scénario réel : slot 0 = clé MASTER (ce que le quorum reconstitue),
# puis on ajoute le slot SelfRecover AUTORISÉ PAR LA MASTER (comme sur le vrai disque),
# et on vérifie que les deux clés ouvrent + qu'un mauvais mot est refusé.
#
# Lancer :  sudo PYTHON=python3 bash test-phase2-image.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PY="${PYTHON:-python3}"
IMG=/tmp/phase2-test.img; MAP=phase2test
WORD="correct horse battery staple"; SALT="test-salt-phase2"
RUN_AS="${SUDO_USER:-$USER}"
TMP="$(mktemp -d)"
cleanup(){ cryptsetup status "$MAP" >/dev/null 2>&1 && cryptsetup luksClose "$MAP" || true; rm -f "$IMG"; rm -rf "$TMP"; }
trap cleanup EXIT

# --stdin et non --word : la dérivation passe par `sudo -u`, donc la ligne de commande
# complète part dans auth.log — et donc dans les sauvegardes — pendant que tout processus
# local peut lire /proc/<pid>/cmdline le temps de l'exécution.
#
# --format hex : voir la note dans ../selfrecover-keyscript.sh. La cle enrolee doit
# etre celle que le keyscript produira.
derive(){ printf '%s' "$1" | sudo -u "$RUN_AS" "$PY" "$HERE/../selfrecover_derive.py" \
            --stdin --salt "$SALT" --label disk --format hex; }

command -v cryptsetup >/dev/null || { echo "cryptsetup absent : sudo apt install cryptsetup"; exit 1; }
derive x >/dev/null || { echo "selfrecover_derive KO (argon2 ?)"; exit 1; }

echo "1) image + slot 0 = MASTER simulée (32o aléatoires = ce que le quorum reconstitue)"
dd if=/dev/zero of="$IMG" bs=1M count=48 status=none
head -c32 /dev/urandom > "$TMP/master.key"
cryptsetup luksFormat --type luks2 --batch-mode "$IMG" "$TMP/master.key"

echo "2) ajoute le slot SelfRecover AUTORISÉ PAR LA MASTER (comme sur le vrai disque)"
derive "$WORD" > "$TMP/sr.key"
cryptsetup luksAddKey "$IMG" "$TMP/sr.key" --key-file "$TMP/master.key" --key-slot 1
cryptsetup luksDump "$IMG" | grep -E "^\s+[0-9]+: luks2"

echo "3) la MASTER ouvre (slot 0, = voie quorum) ?"
cryptsetup luksOpen "$IMG" "$MAP" --key-file "$TMP/master.key" && echo "   ✅ master OK"; cryptsetup luksClose "$MAP"

echo "4) le mot SelfRecover ouvre (slot 1, redérivé = voie fallback) ?"
derive "$WORD" | cryptsetup luksOpen "$IMG" "$MAP" --key-file=- && echo "   ✅ SelfRecover OK"; cryptsetup luksClose "$MAP"

echo "5) mauvais mot refusé ?"
if derive "MAUVAIS mot" | cryptsetup luksOpen "$IMG" "$MAP" --key-file=- 2>/dev/null; then
  echo "   ❌ ANOMALIE"; cryptsetup luksClose "$MAP"; exit 1
else
  echo "   ✅ refusé"
fi

echo
echo "=== Validé sur image : les 2 voies (master quorum + mot SelfRecover) ouvrent le même volume. ==="
echo "    → setup-add-selfrecover-slot.sh prêt pour le vrai device (autorisé par la master quorum)."
