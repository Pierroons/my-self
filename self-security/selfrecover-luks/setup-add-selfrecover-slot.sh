#!/usr/bin/env bash
# Ajoute un slot SelfRecover à un volume LUKS (déverrouillage de secours).
# Autorise l'ajout via une clé EXISTANTE : la clé maître du quorum (--existing-keyfile)
# ou une passphrase déjà connue (prompt). Le slot quorum n'est JAMAIS retiré.
#
# Usage (vrai disque, autorisé par la master reconstituée via quorum) :
#   # 1) reconstituer la master via quorum dans un keyfile tmpfs, puis :
#   sudo SELFRECOVER_SALT="$(cat /etc/selfkeyguard/selfrecover_salt)" \
#        ./setup-add-selfrecover-slot.sh /dev/disk/by-label/cryptdata --existing-keyfile /run/keyguard/master.bin
#
# Usage (test, autorisé par une passphrase existante) :
#   sudo SELFRECOVER_SALT="..." ./setup-add-selfrecover-slot.sh /dev/xxx
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PY="${PYTHON:-python3}"
DEV="${1:?device LUKS attendu (ex. /dev/disk/by-label/cryptdata)}"; shift || true
EXISTING_KF=""
if [ "${1:-}" = "--existing-keyfile" ]; then EXISTING_KF="${2:?keyfile attendu}"; fi
SALT="${SELFRECOVER_SALT:?définir SELFRECOVER_SALT (sel du déploiement)}"
RUN_AS="${SUDO_USER:-$USER}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV" || { echo "$DEV n'est pas un volume LUKS"; exit 1; }

echo "Ajout d'un slot SelfRecover sur $DEV"
read -rsp "  Nouveau mot de récupération SelfRecover : " W1; echo
read -rsp "  Confirme le mot : " W2; echo
[ "$W1" = "$W2" ] || { echo "❌ les deux saisies diffèrent"; exit 1; }

# mot -> Argon2id(label=disk) -> clé du nouveau slot (jamais écrite ailleurs que tmpfs)
sudo -u "$RUN_AS" "$PY" "$HERE/selfrecover_derive.py" --word "$W1" --salt "$SALT" --label disk --format raw > "$TMP/sr.key"

if [ -n "$EXISTING_KF" ]; then
  cryptsetup luksAddKey "$DEV" "$TMP/sr.key" --key-file "$EXISTING_KF"
else
  echo "  → saisis une passphrase EXISTANTE pour autoriser l'ajout :"
  cryptsetup luksAddKey "$DEV" "$TMP/sr.key"
fi

echo "✅ slot SelfRecover ajouté à $DEV"
cryptsetup luksDump "$DEV" | grep -E "^\s+[0-9]+: luks2" || true
