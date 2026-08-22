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
case "${1:-}" in
  --existing-keyfile) EXISTING_KF="${2:?keyfile attendu}" ;;
  "")                 ;;
  # Un argument inconnu — une faute de frappe sur --existing-keyfile, typiquement —
  # basculait en silence sur la saisie interactive : le script demandait une
  # passphrase la ou l'appelant croyait fournir un fichier-cle.
  *)                  echo "argument inconnu : $1" >&2
                      echo "usage : $0 <device> [--existing-keyfile <fichier>]" >&2
                      exit 2 ;;
esac
SALT="${SELFRECOVER_SALT:?définir SELFRECOVER_SALT (sel du déploiement)}"
# /run est un tmpfs garanti par systemd. mktemp -d seul retombe sur $TMPDIR ou
# /tmp, qui peuvent etre sur disque : la cle LUKS brute y serait ecrite en clair,
# et sur SSD l'effacement sur est illusoire (wear-leveling).
TMP="$(mktemp -d -p /run)"; chmod 700 "$TMP"; trap 'rm -rf "$TMP"; stty echo 2>/dev/null' EXIT

command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV" || { echo "$DEV n'est pas un volume LUKS"; exit 1; }

echo "Ajout d'un slot SelfRecover sur $DEV"
read -rsp "  Passphrase Recover-LUKS : " W1; echo
read -rsp "  Confirme la passphrase : " W2; echo
[ "$W1" = "$W2" ] || { echo "❌ les deux saisies diffèrent"; exit 1; }

# passphrase -> Argon2id(label=disk) -> cle du nouveau slot
# --stdin et non --word : en argv, la passphrase serait lisible dans /proc/<pid>/cmdline
# par tout processus local pendant l'execution.
# Execution en root : python3-argon2 doit etre installe a l'echelle du systeme
# (paquet Debian), et non par utilisateur via pip.
# --format hex : voir la note dans selfrecover-keyscript.sh. La cle enrolee DOIT
# etre celle que le keyscript produira au demarrage, et le demarrage passe par
# stdin, qui tronque au premier saut de ligne.
printf '%s' "$W1" | "$PY" "$HERE/selfrecover_derive.py" --stdin --salt "$SALT" --label disk --format hex > "$TMP/sr.key"

if [ -n "$EXISTING_KF" ]; then
  cryptsetup luksAddKey "$DEV" "$TMP/sr.key" --key-file "$EXISTING_KF"
else
  cat <<'PROMPT'

  ------------------------------------------------------------------
  LUKS exige une preuve que tu sais DEJA ouvrir ce volume avant
  d'y ajouter une cle. Saisis donc une passphrase DEJA ENROLEE :
  typiquement le SLOT NATIF, celui de ton gestionnaire de mots de passe.

  Ce n'est PAS la passphrase Recover que tu viens de saisir deux fois :
  celle-la n'ouvre pas encore le volume, c'est ce qu'on est en train
  de creer.
  ------------------------------------------------------------------
PROMPT
  cryptsetup luksAddKey "$DEV" "$TMP/sr.key"
fi

# Le succes de luksAddKey ne dit pas que la cle OUVRE le volume, et surtout pas
# qu'elle l'ouvrira par le chemin du demarrage — cryptsetup lit stdin jusqu'au
# premier saut de ligne, un fichier en entier. On le prouve donc par un tube,
# exactement comme le keyscript le fera au boot.
if printf '%s' "$W1" | "$PY" "$HERE/selfrecover_derive.py" --stdin --salt "$SALT" \
     --label disk --format hex \
   | cryptsetup open --test-passphrase --key-file=- "$DEV"; then
  echo "✅ slot SelfRecover ajouté à $DEV"
else
  echo "❌ le slot a ete ajoute mais n'ouvre PAS le volume par stdin." >&2
  echo "   Ne branche pas le keyscript. Voir INSTALL.md §6." >&2
  exit 1
fi
cryptsetup luksDump "$DEV" | grep -E "^\s+[0-9]+: luks2" || true
