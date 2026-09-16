#!/usr/bin/env bash
# SelfRecover-LUKS — installateur semi-automatique.
# Déverrouillage d'un serveur chiffré (LUKS2) par UNE passphrase de récupération,
# au clavier, ou à distance au boot (dropbear, parcours serveur), + cascade des
# volumes secondaires (keyfile).
#
# ⚠️  Ce script MODIFIE le déverrouillage du disque. Lis INSTALL.md avant.
#     Filets non négociables : slot LUKS NATIF conservé sur chaque volume,
#     sauvegarde de l'initramfs avant régénération. À tester d'abord sur une
#     machine sans données critiques.  Licence : AGPL-3.0-or-later.
set -euo pipefail

# ============================ À ADAPTER ============================
ROOT_DEV="${ROOT_DEV:-}"            # OBLIGATOIRE — volume LUKS racine, ex. /dev/nvme0n1p3
ROOT_NAME="${ROOT_NAME:-}"          # nom mapper du / (auto-détecté depuis crypttab si vide)
DATA_DEV="${DATA_DEV:-}"            # optionnel — volume secondaire, ex. /dev/nvme0n1p4
DATA_NAME="${DATA_NAME:-data}"      # nom mapper du volume secondaire
DATA_MOUNT="${DATA_MOUNT:-/data}"   # point de montage du volume secondaire
NET_MODULE="${NET_MODULE:-}"        # module réseau, ex. r8169 (lspci -k | grep -A2 Ethernet)
SSH_PUBKEY="${SSH_PUBKEY:-}"        # chemin clé publique autorisée au boot — requis si dropbear
DROPBEAR_PORT="${DROPBEAR_PORT:-2222}"
DROPBEAR="${DROPBEAR:-auto}"        # auto | oui | non — SSH d'amorçage.
                                    # auto : oui si le paquet ET la clé sont là, non sinon.
                                    # Sur un poste, dropbear est un serveur SSH qui écoute
                                    # avant le déverrouillage : il n'apporte rien au clavier.
ROOTDELAY="${ROOTDELAY:-60}"
SKG="${SKG:-/etc/selfkeyguard}"
# Les deux secrets dont la perte est IRRÉVERSIBLE (§3 bis). Chemins de leurs copies,
# à donner explicitement : les chercher tout seul reviendrait à se satisfaire d'une
# copie posée sur le volume chiffré, ce qui est exactement le défaut à fermer.
ENTETE_SAUVEGARDE="${ENTETE_SAUVEGARDE:-}"   # sauvegarde de l'en-tête LUKS (§4)
SEL_SAUVEGARDE="${SEL_SAUVEGARDE:-}"         # copie de selfrecover_salt (§13)
# ===================================================================

HERE="$(cd "$(dirname "$0")" && pwd)"
say()  { printf '\n\033[1;34m=== %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
confirm() { read -rp "  → $* [o/N] " r; [ "$r" = o ] || [ "$r" = O ] || die "Annulé."; }

while [ $# -gt 0 ]; do
  case "$1" in
    --avec-dropbear) DROPBEAR=oui ;;
    --sans-dropbear) DROPBEAR=non ;;
    -h|--help) printf 'usage: %s [--avec-dropbear|--sans-dropbear]\n' "$0"; exit 0 ;;
    *) die "argument inconnu : $1" ;;
  esac
  shift
done

# ---------- 0. Vérifications ----------
say "0. Vérifications"
[ "$(id -u)" = 0 ] || die "À lancer en root."
[ -n "$ROOT_DEV" ] || die "ROOT_DEV non défini (édite l'en-tête du script)."
for f in selfrecover_derive.c selfrecover-keyscript.sh initramfs-hook-selfrecover \
         setup-add-selfrecover-slot.sh initramfs-post-update-verifie-selfrecover \
         verifie-sauvegardes.sh; do
  [ -f "$HERE/$f" ] || die "Fichier manquant dans le dépôt : $f"
done
command -v cryptsetup >/dev/null || die "cryptsetup absent."
cryptsetup isLuks "$ROOT_DEV" || die "$ROOT_DEV n'est pas un volume LUKS."
command -v cc >/dev/null || die "cc absent (apt install build-essential)."
# Le chemin dépend de l'architecture : `ldconfig` d'abord, chemins connus ensuite.
# ⚠️ Deux chemins amd64 codés en dur refusaient l'installation sur arm64 alors que
# la bibliothèque était là — « libargon2.so.1 absent » sur une machine qui l'avait.
# C'est le même défaut que le hook d'initramfs avait déjà rencontré, et il échoue
# du mauvais côté : un faux négatif rend le module ininstallable, et aucune
# validation sur amd64 ne peut le voir.
_ARGON2=$(ldconfig -p 2>/dev/null | awk '/libargon2\.so\.1 /{print $NF; exit}')
if [ -z "$_ARGON2" ] || [ ! -e "$_ARGON2" ]; then
  for _C in /lib/*/libargon2.so.1 /usr/lib/*/libargon2.so.1 /lib/libargon2.so.1; do
    [ -e "$_C" ] && { _ARGON2="$_C"; break; }
  done
fi
[ -n "$_ARGON2" ] && [ -e "$_ARGON2" ] \
  || die "libargon2.so.1 absent (apt install libargon2-1)."
# L'étape 4 dérive la clé du slot par python3 + le module argon2, et ni l'un ni
# l'autre n'était contrôlé ici. Sur une Debian minimale (debootstrap, image RPi
# Lite) l'installation mourait donc à l'ÉTAPE 4 — après que l'étape 2 a écrit le
# sel et l'étape 3 le keyscript : machine à moitié configurée, zéro slot ajouté.
# Mesuré sur RPi4 le 13/09 : « setup-add-selfrecover-slot.sh: ligne 51: python3 :
# commande introuvable ». Même motif que les chemins libargon2 gravés juste
# au-dessus — une dépendance supposée, qui mord tard.
# Le module s'éprouve par son import réel et non par dpkg : un `pip install` par
# utilisateur, que ce script refuse (l'étape 4 tourne en root), passerait un test
# de paquet et échouerait quand même.
_PY="${PYTHON:-python3}"
command -v "$_PY" >/dev/null \
  || die "$_PY absent (apt install python3) — l'étape 4 en dépend."
"$_PY" -c 'from argon2.low_level import hash_secret_raw' 2>/dev/null \
  || die "module python argon2 absent ou incomplet (apt install python3-argon2) — l'étape 4 en dépend."
case "$DROPBEAR" in
  oui)
    dpkg -s dropbear-initramfs >/dev/null 2>&1 || die "dropbear-initramfs absent (apt install dropbear-initramfs)."
    { [ -n "$SSH_PUBKEY" ] && [ -f "$SSH_PUBKEY" ]; } || die "SSH_PUBKEY introuvable (clé publique autorisée au boot)."
    ;;
  non) ;;
  auto)
    # Sur un poste, ni le paquet ni la clé d'amorçage n'existent : leur absence
    # vaut choix du parcours poste, et non erreur de configuration à faire mourir.
    if dpkg -s dropbear-initramfs >/dev/null 2>&1 && [ -n "$SSH_PUBKEY" ] && [ -f "$SSH_PUBKEY" ]; then
      DROPBEAR=oui
    else
      DROPBEAR=non
      warn "parcours poste : pas d'accès SSH au boot (dropbear-initramfs ou SSH_PUBKEY manquant)."
      warn "pour l'exiger sur un serveur : DROPBEAR=oui ou --avec-dropbear."
    fi
    ;;
  *) die "DROPBEAR doit valoir auto, oui ou non (reçu : $DROPBEAR)." ;;
esac
# auto-détection ROOT_NAME depuis crypttab
if [ -z "$ROOT_NAME" ]; then
  ROOT_NAME="$(awk '$0!~/^#/ && $2!~/noauto/ && $4~/x-initrd.attach|luks/ {print $1; exit}' /etc/crypttab 2>/dev/null || true)"
  [ -n "$ROOT_NAME" ] || die "ROOT_NAME introuvable — définis-le manuellement."
fi
if [ "$DROPBEAR" = oui ]; then DB_RECAP="dropbear:$DROPBEAR_PORT"; else DB_RECAP="dropbear: non (clavier)"; fi
ok "racine: $ROOT_DEV (mapper: $ROOT_NAME) | secondaire: ${DATA_DEV:-aucun} | $DB_RECAP"
confirm "Ces paramètres sont corrects ?"

# ---------- 1. Compiler + déployer le binaire ----------
say "1. Compilation de selfrecover_derive (lien runtime libargon2)"
cc -O2 -Wall -o "$HERE/selfrecover_derive" "$HERE/selfrecover_derive.c" -l:libargon2.so.1
install -d -m 0755 "$SKG"
install -m 0755 "$HERE/selfrecover_derive" "$SKG/selfrecover_derive_c"
ok "binaire -> $SKG/selfrecover_derive_c"

# ---------- 2. Sel de déploiement ----------
say "2. Sel de déploiement"
if [ -s "$SKG/selfrecover_salt" ]; then
  warn "sel déjà présent — conservé (NE PAS le changer, il casserait les slots existants)."
else
  # od et non xxd : depuis Debian 13, xxd est un paquet distinct, absent d'une
  # installation minimale comme d'un bureau. od vient de coreutils.
  #
  # Écriture dans un fichier temporaire, contrôlée, puis renommée : écrit
  # directement sous son nom définitif, un sel tronqué ou vide survivrait en 0644,
  # le chmod de la ligne suivante n'étant jamais atteint. Toute dérivation lirait
  # ensuite ce sel-là et produirait des clés qui n'ouvrent rien.
  SALT_BYTES=16
  TMP_SALT="$SKG/.selfrecover_salt.nouveau"
  rm -f "$TMP_SALT"
  ( umask 077; head -c "$SALT_BYTES" /dev/urandom | od -An -tx1 | tr -d ' \n' > "$TMP_SALT" ) \
    || { rm -f "$TMP_SALT"; die "génération du sel échouée — aucun sel écrit."; }
  [ "$(wc -c < "$TMP_SALT")" -eq "$((SALT_BYTES * 2))" ] \
    || { rm -f "$TMP_SALT"; die "sel incomplet ($((SALT_BYTES * 2)) caractères hex attendus) — aucun sel écrit."; }
  chmod 0400 "$TMP_SALT"
  mv "$TMP_SALT" "$SKG/selfrecover_salt"
  ok "sel généré -> $SKG/selfrecover_salt (À SAUVEGARDER HORS-SITE)"
fi

# ---------- 3. Keyscript + hook ----------
say "3. Keyscript + hook initramfs"
install -m 0755 "$HERE/selfrecover-keyscript.sh"   "$SKG/selfrecover-keyscript.sh"
install -m 0755 "$HERE/initramfs-hook-selfrecover" /etc/initramfs-tools/hooks/selfrecover
ok "keyscript + hook (avec fix libgcc) déployés"

# ---------- 3 bis. Les deux secrets irréversibles sont-ils hors de cette machine ? ----------
say "3 bis. Sauvegardes des secrets irréversibles — contrôle bloquant"
# 🔑 Ce contrôle existe parce que la consigne ne suffisait pas. INSTALL.md §4 dit
# depuis toujours qu'une sauvegarde d'en-tête rangée sur le volume chiffré ne sert à
# rien — « au moment où on en a besoin, on ne peut plus la lire ». Le 15/09/2026, une
# machine devenue production publique avait sa SEULE copie d'en-tête à l'intérieur du
# volume qu'elle sert à ouvrir. Un avertissement écrit qui ne déclenche rien ne vaut
# pas mieux qu'un avertissement absent.
#
# ⚠️ Ce qu'un script NE PEUT PAS mesurer, c'est « hors de la machine ». Se contenter
# de « un fichier de sauvegarde existe » serait satisfait par une copie posée sur le
# volume chiffré — précisément le défaut. On aurait remplacé un avertissement juste
# par une FAUSSE ASSURANCE, ce qui est pire. Le contrôle porte donc uniquement sur ce
# qui est mesurable ici :
#   1. la sauvegarde se lit, et c'est bien un en-tête LUKS ;
#   2. elle appartient à CE volume — même UUID ;
#   3. elle n'est pas périmée — même nombre de slots ;
#   4. son support ne descend pas du volume qu'on va ouvrir, et n'est pas volatil.
# Emporter la copie hors du bâtiment reste un geste humain, que le §4 décrit.
#
# Placé ICI, juste avant l'étape 4 : c'est le premier geste irréversible, celui qui
# écrit dans l'en-tête. Refuser après laisserait la machine à moitié configurée —
# ce qu'on s'est déjà interdit sur le rootdelay.

if [ "${J_ACCEPTE_SANS_SAUVEGARDE:-non}" = oui ]; then
  # Une alarme sans porte de sortie se contourne en editant le script, et ce
  # contournement-la ne laisse aucune trace. Celle-ci se nomme et s'inscrit.
  warn "CONTROLE DESARME par J_ACCEPTE_SANS_SAUVEGARDE=oui."
  warn "Perdre l'en-tete LUKS ou le sel rend ce volume DEFINITIVEMENT inouvrable,"
  warn "y compris avec la bonne passphrase. Aucune recuperation n'existe."
  install -d -m 0755 "$SKG"
  printf '%s  install.sh  controle des sauvegardes desarme (J_ACCEPTE_SANS_SAUVEGARDE=oui)\n' \
    "$(date -Is)" >> "$SKG/renoncements.log"
  ok "choix inscrit dans $SKG/renoncements.log - trace, pas invisible"
else
  # Le controle vit dans son propre script : il est ainsi eprouvable hors d'une vraie
  # machine (tests/test_sauvegardes.sh), et relancable quand on veut - chaque ajout de
  # slot perime la sauvegarde d'en-tete.
  ENTETE_SAUVEGARDE="$ENTETE_SAUVEGARDE" SEL_SAUVEGARDE="$SEL_SAUVEGARDE" \
    bash "$HERE/verifie-sauvegardes.sh" "$ROOT_DEV" "$SKG" \
    || die "sauvegardes des secrets irreversibles : voir ci-dessus.
     Corrige, ou assume le risque par J_ACCEPTE_SANS_SAUVEGARDE=oui (le choix est journalise)."
fi

# ---------- 4. Slots recover ----------
say "4. Ajout du slot recover (autorisé par une passphrase EXISTANTE)"
confirm "Ajouter le slot recover sur la RACINE $ROOT_DEV ?"
SELFRECOVER_SALT="$(cat "$SKG/selfrecover_salt")" bash "$HERE/setup-add-selfrecover-slot.sh" "$ROOT_DEV"
if [ -n "$DATA_DEV" ] && cryptsetup isLuks "$DATA_DEV" 2>/dev/null; then
  confirm "Ajouter le MÊME slot recover sur $DATA_DEV ?"
  SELFRECOVER_SALT="$(cat "$SKG/selfrecover_salt")" bash "$HERE/setup-add-selfrecover-slot.sh" "$DATA_DEV"
fi

# ---------- 5. crypttab racine : keyscript ----------
say "5. Volume racine : keyscript dans /etc/crypttab"
cp -a /etc/crypttab "/etc/crypttab.bak.$(date +%s)"
# keyfile-size=64 va avec le keyscript, et n'a de sens qu'avec lui : c'est la taille
# de la clé hex qu'il produit. cryptsetup honore l'option pour un keyfile, «-» compris,
# et ne l'ignore que pour du plain dm-crypt. Elle borne la lecture, donc un \n final
# glissé un jour dans le keyscript ne changerait plus la clé présentée — sans dispenser
# du printf '%s'. Mesure : docs/cryptsetup-lecture-cle.md
if grep -q "^${ROOT_NAME}.*keyscript=" /etc/crypttab; then
  ok "keyscript déjà présent sur $ROOT_NAME"
  if grep -q "^${ROOT_NAME}.*keyfile-size=" /etc/crypttab; then
    ok "keyfile-size déjà présent sur $ROOT_NAME"
  else
    # ⚠️ Un simple avertissement laissait la borne absente sur toute machine déjà
    # équipée — précisément le public de la migration du §15, celui qui vient de
    # changer le format de sa clé. La ceinture n'était posée que sur les
    # installations neuves, c'est-à-dire là où elle sert le moins.
    # La question est posée comme partout ailleurs dans ce script : une ligne de
    # /etc/crypttab ne se modifie pas sans accord. La sauvegarde datée est déjà
    # prise plus haut, et le script se relance sans dommage.
    confirm "Ajouter keyfile-size=64 à la ligne $ROOT_NAME (borne la lecture de la clé) ?"
    sed -i "/^${ROOT_NAME}[[:space:]]/s|\$|,keyfile-size=64|" /etc/crypttab
    ok "keyfile-size=64 ajouté"
  fi
else
  confirm "Ajouter keyscript= et keyfile-size=64 à la ligne $ROOT_NAME ?"
  sed -i "/^${ROOT_NAME}[[:space:]]/s|\$|,keyscript=$SKG/selfrecover-keyscript.sh,keyfile-size=64|" /etc/crypttab
  ok "keyscript ajouté"
fi
grep "^${ROOT_NAME}" /etc/crypttab | sed 's/^/    /'

# ---------- 6. dropbear + rootdelay (parcours serveur) ----------
if [ "$DROPBEAR" != oui ]; then
  say "6. Accès distant au boot — non installé (parcours poste de travail)"
  ok "la passphrase Recover se saisit au clavier ; rien n'écoute avant le déverrouillage"
else
  say "6. Accès distant au boot (dropbear) + délai d'attente"
  IC=/etc/initramfs-tools/initramfs.conf
  # if/else et non `grep && sed || echo` : dans cette forme, le `||` se déclenche
  # aussi quand c'est le sed qui échoue, et ajoute alors une SECONDE ligne IP=dhcp.
  if grep -q '^IP=' "$IC"; then
    sed -i 's/^IP=.*/IP=dhcp/' "$IC"
  else
    echo 'IP=dhcp' >> "$IC"
  fi
  [ -n "$NET_MODULE" ] && { grep -qx "$NET_MODULE" /etc/initramfs-tools/modules || echo "$NET_MODULE" >> /etc/initramfs-tools/modules; }
  install -d -m 0755 /etc/dropbear/initramfs
  install -m 0600 "$SSH_PUBKEY" /etc/dropbear/initramfs/authorized_keys
  echo "DROPBEAR_OPTIONS=\"-p $DROPBEAR_PORT -s -j -k -I 300\"" > /etc/dropbear/initramfs/dropbear.conf
  ok "dropbear: port $DROPBEAR_PORT, clé autorisée, IP=dhcp"

  # --- rien ne disait QUELLE passphrase l'invite attend ---
  # Le keyscript pose « Passphrase Recover-LUKS (nom) : » sur /dev/console, que
  # personne ne voit sur une machine sans écran. Par dropbear, cryptroot-unlock
  # affiche sa propre invite générique « Please unlock disk … » : l'opérateur doit
  # DEVINER laquelle des deux phrases taper, et un essai raté ressemble à une
  # panne du module — alors que c'est une question d'étiquette.
  #
  # ⚠️ /usr/share/initramfs-tools/hooks/cryptroot-unlock REMPLACE le message par
  # défaut quand ce fichier existe — c'est un if/else, pas un ajout (vérifié sur
  # Debian 13 le 15/09). Ce message doit donc redire `cryptroot-unlock`, faute de
  # quoi on gagnerait une précision en perdant l'instruction.
  install -d -m 0755 /etc/initramfs-tools/etc
  cat > /etc/initramfs-tools/etc/motd <<MOTD

  SelfRecover-LUKS — ce disque attend la passphrase RECOVER.

  Pour ouvrir la racine (et les autres volumes) :  cryptroot-unlock
  À l'invite, saisis la passphrase RECOVER, pas la passphrase native du disque.

  Filet : le slot natif est conservé et ouvre toujours le volume.
    cryptsetup open $ROOT_DEV $ROOT_NAME

MOTD
  chmod 0644 /etc/initramfs-tools/etc/motd
  ok "message d'amorçage posé : il nomme la phrase attendue et redit cryptroot-unlock"
  # --- rootdelay : le fichier qui porte les paramètres du noyau dépend de l'amorceur ---
  #
  # Ce bloc supposait GRUB. Un RPi4 n'a ni /etc/default/grub ni update-grub :
  # l'installation mourait ICI, c'est-à-dire APRÈS l'ajout du slot (étape 4) et la
  # réécriture de crypttab (étape 5), dans une branche qui n'est pas optionnelle
  # sur une machine sans écran — et ce rootdelay est justement ce qui laisse au
  # réseau le temps de se lever, sur la seule voie d'entrée.
  #
  # Repli et non refus net : refuser laisserait la machine dans le même état à
  # moitié configuré. Ça déplace le symptôme, ça ne le soigne pas.
  ROOTDELAY_PORTEUR=""
  if [ -f /etc/default/grub ] && command -v update-grub >/dev/null; then
    cp -a /etc/default/grub "/etc/default/grub.bak.$(date +%s)"
    if grep -q 'rootdelay=' /etc/default/grub; then
      sed -i "s/rootdelay=[0-9]*/rootdelay=$ROOTDELAY/g" /etc/default/grub
    else
      sed -i "s/^GRUB_CMDLINE_LINUX_DEFAULT=\"\(.*\)\"\$/GRUB_CMDLINE_LINUX_DEFAULT=\"\1 rootdelay=$ROOTDELAY\"/" /etc/default/grub
    fi
    # `update-grub >/dev/null 2>&1 && ok` fait sortir le script sans un mot sous
    # set -e, crypttab et slots déjà modifiés. La sortie de grub est donc capturée
    # et rendue à l'écran avant d'arrêter.
    if ! GRUB_OUT="$(update-grub 2>&1)"; then
      printf '%s\n' "$GRUB_OUT" >&2
      die "update-grub a échoué — rootdelay écrit dans /etc/default/grub mais pas appliqué (repli : /etc/default/grub.bak.*)."
    fi
    ROOTDELAY_PORTEUR=/etc/default/grub
    ok "rootdelay=$ROOTDELAY appliqué (GRUB)"

  elif [ -f /boot/firmware/cmdline.txt ]; then
    # Parcours Raspberry Pi.
    # ⚠️ NE PAS écrire dans /boot/firmware/cmdline.txt : il est RÉGÉNÉRÉ par
    # raspi-firmware à chaque mise à jour de noyau et à chaque update-initramfs
    # (/etc/kernel/postinst.d/z50-raspi-firmware). Une modification à la main y
    # tient jusqu'au prochain noyau, puis disparaît — sans un mot, sur la seule
    # voie d'entrée d'une machine sans écran.
    # La source durable est documentée par le paquet lui-même, dans
    # /etc/default/raspi-firmware : « To pass extra arbitrary parameters to the
    # kernel at boot, you can specify them in /etc/default/raspi-extra-cmdline.
    # Keep in mind they should be all in a single line, no comments! »
    RXC=/etc/default/raspi-extra-cmdline
    [ -f "$RXC" ] && cp -a "$RXC" "$RXC.bak.$(date +%s)"
    if [ -f "$RXC" ] && grep -q 'rootdelay=' "$RXC"; then
      sed -i "s/rootdelay=[0-9]*/rootdelay=$ROOTDELAY/g" "$RXC"
    else
      EXTRA=""
      [ -f "$RXC" ] && EXTRA="$(tr -d '\n' < "$RXC")"
      printf '%s rootdelay=%s\n' "$EXTRA" "$ROOTDELAY" \
        | sed 's/^[[:space:]]*//' > "$RXC.nouveau"
      mv "$RXC.nouveau" "$RXC"
    fi
    ROOTDELAY_PORTEUR="$RXC"
    ok "rootdelay=$ROOTDELAY écrit dans $RXC (source durable du cmdline RPi)"
    warn "il ne sera EFFECTIF qu'après la régénération de l'étape 9 — vérifiée là-bas."

  else
    die "aucun porteur de paramètres noyau reconnu : ni /etc/default/grub avec update-grub, ni /boot/firmware/cmdline.txt.
     Pose rootdelay=$ROOTDELAY toi-même dans la ligne de commande du noyau de cet amorceur,
     puis relance ce script — il verra la valeur déjà en place.
     Sans ce délai, dropbear peut démarrer avant que le réseau soit levé : la machine
     resterait injoignable au boot, et c'est la seule voie d'entrée sans écran."
  fi
fi

# ---------- 7. Volume secondaire : keyfile (cascade) ----------
if [ -n "$DATA_DEV" ] && cryptsetup isLuks "$DATA_DEV" 2>/dev/null; then
  say "7. Volume secondaire : fichier-clé (cascade auto, post-pivot)"
  install -d -m 0700 /etc/keys
  KF="/etc/keys/${DATA_NAME}.key"
  if [ -s "$KF" ]; then warn "keyfile déjà présent — conservé"; else
    dd if=/dev/urandom of="$KF" bs=4096 count=1 status=none; chmod 0400 "$KF"
    confirm "Ajouter le fichier-clé comme slot sur $DATA_DEV (passphrase existante demandée) ?"
    cryptsetup luksAddKey "$DATA_DEV" "$KF"
    ok "keyfile -> $KF (slot ajouté)"
  fi
  DATA_UUID="$(blkid -s UUID -o value "$DATA_DEV")"
  if ! grep -q "^${DATA_NAME}[[:space:]]" /etc/crypttab; then
    echo "${DATA_NAME} UUID=${DATA_UUID} ${KF} luks,nofail" >> /etc/crypttab
  else
    sed -i "/^${DATA_NAME}[[:space:]]/c\\${DATA_NAME} UUID=${DATA_UUID} ${KF} luks,nofail" /etc/crypttab
  fi
  if grep -q "^/dev/mapper/${DATA_NAME}[[:space:]]" /etc/fstab; then
    sed -i "\|^/dev/mapper/${DATA_NAME}[[:space:]]|c\\/dev/mapper/${DATA_NAME} ${DATA_MOUNT} ext4 defaults,nofail 0 2" /etc/fstab
  else
    echo "/dev/mapper/${DATA_NAME} ${DATA_MOUNT} ext4 defaults,nofail 0 2" >> /etc/fstab
  fi
  ok "crypttab + fstab: $DATA_NAME via keyfile, nofail (non bloquant)"
fi

# ---------- 8. Garde-fou de régénération ----------
say "8. Garde-fou : contrôle des pièces de l'initramfs"
# post-update.d et non kernel/postinst.d : update-initramfs y passe à CHAQUE
# régénération, pas seulement lors d'une installation de noyau.
# Posé avant l'étape 9 : la première image générée est déjà sous contrôle.
install -d -m 0755 /etc/initramfs/post-update.d
install -m 0755 "$HERE/initramfs-post-update-verifie-selfrecover" /etc/initramfs/post-update.d/zz-verifie-selfrecover
ok "garde-fou -> /etc/initramfs/post-update.d/zz-verifie-selfrecover"

# ---------- 9. Régénérer l'initramfs (avec filet) ----------
say "9. Régénération de l'image d'amorçage"
KR="$(uname -r)"
# ⚠️ Le filet ne reste PAS dans /boot. Sur un Raspberry Pi, raspi-firmware balaie
# /boot et copie les images vers la partition d'amorçage : le 13/09 il a promu une
# de ces sauvegardes .bak.* en IMAGE D'AMORÇAGE, et la machine a démarré sur un
# initrd sans aucune pièce SelfRecover. Le filet était devenu la cible — et le
# garde-fou, qui ne regardait que l'image générée, affichait « complet ».
# Le répertoire est donc hors du chemin balayé par l'amorceur.
FILETS=/root/selfrecover-filets
install -d -m 0700 "$FILETS"
cp -a "/boot/initrd.img-$KR" "$FILETS/initrd.img-$KR.bak.$(date +%s)"
ok "FILET : initrd sauvegardé dans $FILETS/ (hors du chemin de l'amorceur)"
confirm "Lancer update-initramfs maintenant ?"
update-initramfs -u

# Parcours RPi : le rootdelay se mesure À L'ARRIVÉE, dans le fichier que
# l'amorceur lit réellement — pas dans celui qu'on a écrit. C'est raspi-firmware
# qui vient de produire cmdline.txt, au cours de la régénération ci-dessus.
if [ "${ROOTDELAY_PORTEUR:-}" = /etc/default/raspi-extra-cmdline ]; then
  grep -q "rootdelay=$ROOTDELAY" /boot/firmware/cmdline.txt \
    || die "rootdelay=$ROOTDELAY est bien dans $ROOTDELAY_PORTEUR mais ABSENT de /boot/firmware/cmdline.txt après régénération.
     NE REDÉMARRE PAS : dropbear peut démarrer avant que le réseau soit levé, et
     c'est la seule voie d'entrée sur une machine sans écran."
  ok "rootdelay=$ROOTDELAY présent dans /boot/firmware/cmdline.txt (mesuré après régénération)"
fi

say "Vérification du contenu de l'initrd"
MOTIFS="selfrecover-keyscript|selfrecover_derive_c|libargon2|libgcc"
if [ "$DROPBEAR" = oui ]; then MOTIFS="$MOTIFS|sbin/dropbear"; fi
# `|| true` : sous pipefail, un grep sans correspondance ferait sortir le script
# muet juste après la régénération. Ici l'absence doit s'afficher.
PIECES="$(lsinitramfs "/boot/initrd.img-$KR" | grep -E "$MOTIFS" || true)"
if [ -n "$PIECES" ]; then
  printf '%s\n' "$PIECES" | sed 's/^/  /'
else
  warn "aucune pièce SelfRecover trouvée dans l'initrd — NE PAS redémarrer avant de comprendre."
fi

# ---------- 10. Récap ----------
say "TERMINÉ — à faire MAINTENANT"
if [ "$DROPBEAR" = oui ]; then
cat <<EOF
  1) TESTER par redémarrage AVANT de te fier au système :
       reboot ; puis depuis un autre poste, dès que le port $DROPBEAR_PORT répond :
       ssh -p $DROPBEAR_PORT root@<IP> ; cryptroot-unlock ; (passphrase RECOVER)
     Filet si pépin : dans dropbear, 'cryptsetup open $ROOT_DEV $ROOT_NAME' (slot NATIF).
EOF
else
cat <<EOF
  1) TESTER par redémarrage AVANT de te fier au système :
       reboot ; la passphrase RECOVER est demandée au clavier.
     Filet si pépin : au shell de secours de l'initramfs,
       'cryptsetup open $ROOT_DEV $ROOT_NAME' ouvre le volume par le slot NATIF.
EOF
fi
cat <<EOF

  2) SAUVEGARDER HORS-SITE (gestionnaire de mots de passe) :
       - la passphrase de récupération
       - le sel : $SKG/selfrecover_salt
       - les secrets de sauvegarde (accès dépôt + passphrase dépôt)

  3) Filets en place : slot natif conservé, initrd sauvegardé dans $FILETS/
     (hors du chemin de l'amorceur — un .bak.* laissé dans /boot peut être promu
     en image d'amorçage par raspi-firmware), crypttab/fstab/${ROOTDELAY_PORTEUR:-amorceur} .bak.*,
     garde-fou /etc/initramfs/post-update.d/zz-verifie-selfrecover à chaque régénération
EOF
