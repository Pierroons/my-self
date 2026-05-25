#!/usr/bin/env bash
# selfrecover-unlock.sh — déverrouillage de SECOURS d'un volume LUKS via le mot de récupération
# SelfRecover (sans email ni tiers). Utilisé en fallback quand le déverrouillage automatique
# (quorum distribué) est indisponible.
#
# Usage :  sudo SELFRECOVER_SALT="<sel du déploiement>" ./selfrecover-unlock.sh <device> [mapping]
#   ex.    sudo SELFRECOVER_SALT="site-salt-example" ./selfrecover-unlock.sh /dev/nvme0n1p2 data
set -euo pipefail

DEV="${1:?device LUKS attendu (ex. /dev/nvme0n1p2)}"
MAP="${2:-data}"
SALT="${SELFRECOVER_SALT:?définir SELFRECOVER_SALT (sel propre au déploiement)}"
HERE="$(cd "$(dirname "$0")" && pwd)"

command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV" || { echo "$DEV n'est pas un volume LUKS"; exit 1; }
if [ -e "/dev/mapper/$MAP" ]; then echo "/dev/mapper/$MAP déjà ouvert"; exit 0; fi

# Lecture du mot SANS écho. La dérivation tourne côté user si lancé via sudo (argon2 user-side).
RUN_AS="${SUDO_USER:-$USER}"
read -rsp "Mot de récupération SelfRecover : " WORD; echo

# mot -> Argon2id(label=disk) -> key-file -> luksOpen. Le mot ne touche jamais le disque.
if sudo -u "$RUN_AS" python3 "$HERE/selfrecover_derive.py" \
       --word "$WORD" --salt "$SALT" --label disk --format raw \
   | cryptsetup luksOpen "$DEV" "$MAP" --key-file=-
then
  echo "✅ $DEV déverrouillé → /dev/mapper/$MAP"
else
  echo "❌ mot incorrect ou volume inaccessible"; exit 1
fi
