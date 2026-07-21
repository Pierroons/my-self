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
MAX_ESSAIS=3

# --- Nettoyage systématique : restaure l'écho du terminal (coupé par read -s) et efface le
#     mot de la mémoire, quelle que soit la façon dont on sort (fin, erreur, Ctrl+C). ---
# shellcheck disable=SC2317  # invoquée via trap (faux positif « unreachable »)
cleanup() { stty echo 2>/dev/null || true; unset WORD 2>/dev/null || true; }
# shellcheck disable=SC2317  # invoquée via trap
on_interrupt() { cleanup; echo; echo "⏹  Annulé — aucune modification effectuée."; exit 130; }
trap cleanup EXIT
trap on_interrupt INT TERM

command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV" || { echo "$DEV n'est pas un volume LUKS"; exit 1; }
if [ -e "/dev/mapper/$MAP" ]; then echo "/dev/mapper/$MAP déjà ouvert — rien à faire."; exit 0; fi

# --- Bandeau explicite + confirmation (anti-lancement accidentel) ---
echo "=== SelfRecover — déverrouillage de SECOURS ==="
echo "Cible  : $DEV  →  /dev/mapper/$MAP"
echo "Action : ouvre ce volume chiffré à partir de ton mot de récupération."
echo "Ctrl+C à tout moment pour annuler."
echo
read -rp "Confirmer le déverrouillage de ce volume ? [o/N] " REP
case "$REP" in
  o|O|oui|OUI|Oui) ;;
  *) echo "Abandon — rien n'a été touché."; exit 0 ;;
esac

# --- Saisie du mot + dérivation, limitées à MAX_ESSAIS tentatives ---
# mot -> Argon2id(label=disk) -> key-file -> luksOpen. Le mot ne touche jamais le disque.
RUN_AS="${SUDO_USER:-$USER}"
for (( i=1; i<=MAX_ESSAIS; i++ )); do
  read -rsp "Mot de récupération SelfRecover (essai $i/$MAX_ESSAIS) : " WORD; echo
  if sudo -u "$RUN_AS" python3 "$HERE/selfrecover_derive.py" \
         --word "$WORD" --salt "$SALT" --label disk --format raw \
     | cryptsetup luksOpen "$DEV" "$MAP" --key-file=-
  then
    unset WORD
    echo "✅ $DEV déverrouillé → /dev/mapper/$MAP"
    exit 0
  fi
  unset WORD
  echo "❌ mot incorrect ou volume inaccessible (essai $i/$MAX_ESSAIS)"
  if [ "$i" -lt "$MAX_ESSAIS" ]; then sleep 1; fi
done

echo "⛔ Abandon après $MAX_ESSAIS essais infructueux."
exit 1
