#!/usr/bin/env bash
# Ajoute le slot SelfRecover à un VRAI volume LUKS, en s'autorisant via le QUORUM.
#
# Enchaîne : quorum (témoins UP requis) -> master en tmpfs (/run/keyguard/master.bin)
#            -> setup-add-selfrecover-slot.sh <device> --existing-keyfile <master> (mot saisi 2×)
#            -> shred de la master.
#
# NON DESTRUCTIF : on AJOUTE un slot, aucun slot n'est retiré (le slot quorum reste = filet croisé).
#
# Config (env ou keyguard.conf) : KEYGUARD_DIR, LUKS_DEVICE, PYTHON. Voir keyguard.conf.example.
#
# Usage :
#   sudo ./add-slot-via-quorum.sh [--dry-run] [<device>]
#     --dry-run : reconstitue la master via quorum, vérifie qu'elle ouvre le device, puis shred —
#                 sans rien modifier sur le disque.
#     <device>  : à défaut, lu depuis LUKS_DEVICE (env/keyguard.conf).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PY="${PYTHON:-python3}"
KEYGUARD_DIR="${KEYGUARD_DIR:-/etc/selfkeyguard}"
SALT_FILE="$KEYGUARD_DIR/selfrecover_salt"
MASTER_KF=/run/keyguard/master.bin

DEV="${LUKS_DEVICE:-}"; DRYRUN=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRYRUN=1 ;;
    /dev/*)    DEV="$a" ;;
    *) echo "argument inconnu : $a"; exit 1 ;;
  esac
done
[ -n "$DEV" ] || { echo "préciser le device LUKS (argument ou LUKS_DEVICE)"; exit 1; }

[ "$(id -u)" -eq 0 ] || { echo "lance en sudo (cryptsetup = root)"; exit 1; }
command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV"   || { echo "$DEV n'est pas un volume LUKS"; exit 1; }
[ -f "$SALT_FILE" ]        || { echo "sel manquant : $SALT_FILE"; exit 1; }
[ -x "$HERE/setup-add-selfrecover-slot.sh" ] || { echo "setup-add-selfrecover-slot.sh introuvable à côté"; exit 1; }

echo "=== slot SelfRecover sur $DEV $( [ "$DRYRUN" = 1 ] && echo '(DRY-RUN)' ) ==="
echo "[avant] slots LUKS occupés :"
cryptsetup luksDump "$DEV" | grep -E "^\s+[0-9]+: luks2" || echo "   (aucun ?)"

if [ "$DRYRUN" = 0 ]; then
  echo
  echo "⚠  Ajout RÉEL d'un slot SelfRecover sur $DEV (non destructif, quorum requis UP)."
  read -rp "   Confirmer ? (tape OUI) : " CONF
  [ "$CONF" = "OUI" ] || { echo "annulé."; exit 0; }
fi

echo
echo "[quorum] reconstitution de la master via les témoins…"
mkdir -p /run/keyguard && chmod 700 /run/keyguard
if ! "$PY" - "$HERE" "$MASTER_KF" <<'PYEOF'
import importlib.util, sys, os, contextlib
mod_dir, out = sys.argv[1], sys.argv[2]
spec = importlib.util.spec_from_file_location("kg", os.path.join(mod_dir, "keyguard-luks-unlock.py"))
kg = importlib.util.module_from_spec(spec); spec.loader.exec_module(kg)
with contextlib.redirect_stdout(sys.stderr):   # les print(url) du quorum -> stderr, pas dans le keyfile
    key = kg.get_key_via_quorum()
with open(out, "wb") as f:                      # binaire pur, sans newline
    f.write(key)
os.chmod(out, 0o600)
PYEOF
then
  echo "❌ reconstitution KO — quorum indisponible (témoin down ?). Rien n'a été modifié sur $DEV."
  rm -f "$MASTER_KF"; exit 1
fi

SZ=$(stat -c%s "$MASTER_KF" 2>/dev/null || echo 0)
[ "$SZ" -eq 32 ] || { echo "❌ master de taille inattendue ($SZ o, attendu 32)"; shred -u "$MASTER_KF" 2>/dev/null || rm -f "$MASTER_KF"; exit 1; }
echo "      master reconstituée ($((SZ * 8)) bits) en $MASTER_KF"

if [ "$DRYRUN" = 1 ]; then
  echo "[vérif] cette master ouvre-t-elle $DEV ? (test, ne déverrouille rien)"
  if cryptsetup luksOpen --test-passphrase --key-file "$MASTER_KF" "$DEV" >/dev/null 2>&1; then
    echo "      ✅ master VALIDE pour $DEV — luksAddKey l'acceptera comme clé existante"
  else
    echo "      ⚠ master reconstituée mais ne correspond à AUCUN slot de $DEV (split ≠ ce disque ?)"
  fi
  shred -u "$MASTER_KF"
  echo
  echo "=== DRY-RUN OK : le quorum reconstitue la master valide de $DEV. Aucun slot ajouté. ==="
  echo "    Relance SANS --dry-run pour ajouter réellement le slot SelfRecover."
  exit 0
fi

echo
echo "[slot] ajout du slot SelfRecover (autorisé par la master) — saisis le mot 2×"
SELFRECOVER_SALT="$(cat "$SALT_FILE")" PYTHON="$PY" \
  "$HERE/setup-add-selfrecover-slot.sh" "$DEV" --existing-keyfile "$MASTER_KF"

shred -u "$MASTER_KF"
echo "      master effacée (shred)."

echo
echo "[après] slots LUKS occupés :"
cryptsetup luksDump "$DEV" | grep -E "^\s+[0-9]+: luks2" || true
echo
echo "=== OK : slot SelfRecover ajouté à $DEV. Slot quorum conservé. ==="
echo "    Test conseillé : reboot (quorum ouvre) + simuler quorum KO (le mot de récup ouvre)."
