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
# Label de dérivation : l'enrôlement, le démarrage et le secours doivent dériver
# sous le MÊME. Changé d'un seul côté, le slot ne s'ouvre plus au boot.
# Garde : tests/test_label_derivation.sh.
LABEL_DERIVATION=disk
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
#
# SELFRECOVER_TMPDIR n'existe que pour les bancs, qui n'ont pas le droit d'ecrire
# dans /run : un controle qu'on ne peut pas lancer ne garde rien. Elle s'annonce,
# parce qu'elle peut poser la cle derivee sur un disque.
TMP_BASE="${SELFRECOVER_TMPDIR:-/run}"
if [ -n "${SELFRECOVER_TMPDIR:-}" ]; then
  echo "⚠️  SELFRECOVER_TMPDIR=$SELFRECOVER_TMPDIR — la cle derivee sortira de /run." >&2
  echo "    Sur un volume reel, laisse le defaut : sur disque, l'effacement est illusoire." >&2
fi
TMP="$(mktemp -d -p "$TMP_BASE")"; chmod 700 "$TMP"; trap 'rm -rf "$TMP"; stty echo 2>/dev/null || true' EXIT

command -v cryptsetup >/dev/null || { echo "cryptsetup absent"; exit 1; }
cryptsetup isLuks "$DEV" || { echo "$DEV n'est pas un volume LUKS"; exit 1; }

# 🔑 La preuve du slot passe par le binaire C, parce que c'est LUI qui derive au
# demarrage. Prouver avec le derivateur qui vient d'enroler ne prouve que lui-meme :
# les deux implementations peuvent diverger — version d'argon2, encodage, troncature —
# et la machine ne le dirait qu'au redemarrage suivant. Resolu ICI, avant luksAddKey :
# un refus apres l'enrolement laisserait un slot sans preuve.
SKG_DIR="${SKG:-/etc/selfkeyguard}"
DERIVE_C="${SELFRECOVER_DERIVE_C:-}"
if [ -z "$DERIVE_C" ]; then
  for c in "$SKG_DIR/selfrecover_derive_c" "$HERE/selfrecover_derive"; do
    [ -x "$c" ] && { DERIVE_C="$c"; break; }
  done
fi
if [ -z "$DERIVE_C" ]; then
  if [ "${J_ACCEPTE_PREUVE_SANS_BINAIRE_BOOT:-non}" = oui ]; then
    # Une alarme sans porte de sortie se contourne en editant le script, et ce
    # contournement-la ne laisse aucune trace. Celle-ci se nomme.
    echo "⚠️  CONTROLE DESARME par J_ACCEPTE_PREUVE_SANS_BINAIRE_BOOT=oui." >&2
    echo "    Le slot sera prouve par le derivateur Python, pas par le chemin du demarrage." >&2
  else
    echo "❌ derivateur du demarrage introuvable (selfrecover_derive_c)." >&2
    echo "   Cherche dans : $SKG_DIR/selfrecover_derive_c, $HERE/selfrecover_derive" >&2
    echo "   Compile-le (install.sh, etape 1) ou nomme-le dans SELFRECOVER_DERIVE_C." >&2
    echo "   Aucun slot n'a ete ajoute." >&2
    exit 1
  fi
fi

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
# etre CELLE QUE LE KEYSCRIPT PRODUIRA au demarrage — un slot enrole en brut n'est
# pas ouvert par un keyscript qui rend de l'hex. Le format enrole est inscrit plus
# bas (format-slot.sh), et install.sh le relit avant de poser un keyscript.
FORMAT=hex
printf '%s' "$W1" | "$PY" "$HERE/selfrecover_derive.py" --stdin --salt "$SALT" --label "$LABEL_DERIVATION" --format "$FORMAT" > "$TMP/sr.key"

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

# Le succes de luksAddKey ne dit pas que la cle OUVRE le volume. On le prouve par un
# tube, comme le keyscript le fera au boot.
#
# La preuve est CROISEE : le derivateur Python a enrole, le binaire C prouve. Un
# controle qui appelle deux fois le meme derivateur s'accorde toujours avec lui-meme,
# y compris sur un \n parasite. C'est tests/test_lecture_keyfile.sh qui garde la
# lecture de la cle par cryptsetup, et tests/test_preuve_binaire_boot.sh qui garde
# celle-ci : un binaire de boot divergent doit faire echouer ce script.
printf '%s' "$SALT" > "$TMP/salt"   # --salt-file : le sel ne passe pas par argv
if [ -n "$DERIVE_C" ]; then
  PREUVE="$DERIVE_C — le chemin du demarrage"
  derive_preuve() { "$DERIVE_C" --salt-file "$TMP/salt" --label "$LABEL_DERIVATION" --format "$FORMAT"; }
else
  PREUVE="selfrecover_derive.py — le chemin du demarrage N'EST PAS eprouve"
  derive_preuve() { "$PY" "$HERE/selfrecover_derive.py" --stdin --salt "$SALT" --label "$LABEL_DERIVATION" --format "$FORMAT"; }
fi

if printf '%s' "$W1" | derive_preuve | cryptsetup open --test-passphrase --key-file=- "$DEV"; then
  echo "✅ slot SelfRecover ajouté à $DEV"
  echo "   prouvé par : $PREUVE"
  bash "$HERE/format-slot.sh" inscrire "$DEV" "$FORMAT" "$SKG_DIR" \
    || echo "⚠️  marqueur non ecrit : install.sh refusera de poser un keyscript tant qu'il manque." >&2
else
  echo "❌ le slot a ete ajoute mais n'ouvre PAS le volume par stdin." >&2
  echo "   preuve tentee par : $PREUVE" >&2
  if [ -n "$DERIVE_C" ]; then
    # Sous l'echappement, enrolement et preuve sortent du meme derivateur : leur
    # desaccord serait impossible, et l'annoncer enverrait chercher au mauvais endroit.
    echo "   L'enrolement a reussi et cette preuve echoue : les deux derivateurs divergent." >&2
  fi
  echo "   Ne branche pas le keyscript. Voir INSTALL.md §6." >&2
  exit 1
fi
cryptsetup luksDump "$DEV" | grep -E "^\s+[0-9]+: luks2" || true
