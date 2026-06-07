#!/usr/bin/env bash
# SelfRecover-LUKS — installateur semi-automatique.
# Déverrouillage d'un serveur chiffré (LUKS2) par UNE passphrase de récupération,
# à distance au boot (dropbear) + cascade des volumes secondaires (keyfile).
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
SSH_PUBKEY="${SSH_PUBKEY:-}"        # OBLIGATOIRE — chemin clé publique autorisée au boot
DROPBEAR_PORT="${DROPBEAR_PORT:-2222}"
ROOTDELAY="${ROOTDELAY:-60}"
SKG="${SKG:-/etc/selfkeyguard}"
# ===================================================================

HERE="$(cd "$(dirname "$0")" && pwd)"
say()  { printf '\n\033[1;34m=== %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
confirm() { read -rp "  → $* [o/N] " r; [ "$r" = o ] || [ "$r" = O ] || die "Annulé."; }

# ---------- 0. Vérifications ----------
say "0. Vérifications"
[ "$(id -u)" = 0 ] || die "À lancer en root."
[ -n "$ROOT_DEV" ] || die "ROOT_DEV non défini (édite l'en-tête du script)."
[ -n "$SSH_PUBKEY" ] && [ -f "$SSH_PUBKEY" ] || die "SSH_PUBKEY introuvable (clé publique autorisée au boot)."
for f in selfrecover_derive.c selfrecover-keyscript.sh initramfs-hook-selfrecover setup-add-selfrecover-slot.sh; do
  [ -f "$HERE/$f" ] || die "Fichier manquant dans le dépôt : $f"
done
command -v cryptsetup >/dev/null || die "cryptsetup absent."
cryptsetup isLuks "$ROOT_DEV" || die "$ROOT_DEV n'est pas un volume LUKS."
command -v cc >/dev/null || die "cc absent (apt install build-essential)."
[ -f /usr/lib/x86_64-linux-gnu/libargon2.so.1 ] || [ -f /lib/x86_64-linux-gnu/libargon2.so.1 ] \
  || die "libargon2.so.1 absent (apt install libargon2-1)."
dpkg -s dropbear-initramfs >/dev/null 2>&1 || die "dropbear-initramfs absent (apt install dropbear-initramfs)."
# auto-détection ROOT_NAME depuis crypttab
if [ -z "$ROOT_NAME" ]; then
  ROOT_NAME="$(awk '$0!~/^#/ && $2!~/noauto/ && $4~/x-initrd.attach|luks/ {print $1; exit}' /etc/crypttab 2>/dev/null || true)"
  [ -n "$ROOT_NAME" ] || die "ROOT_NAME introuvable — définis-le manuellement."
fi
ok "racine: $ROOT_DEV (mapper: $ROOT_NAME) | secondaire: ${DATA_DEV:-aucun} | dropbear:$DROPBEAR_PORT"
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
  head -c 16 /dev/urandom | xxd -p > "$SKG/selfrecover_salt"
  chmod 0400 "$SKG/selfrecover_salt"
  ok "sel généré -> $SKG/selfrecover_salt (À SAUVEGARDER HORS-SITE)"
fi

# ---------- 3. Keyscript + hook ----------
say "3. Keyscript + hook initramfs"
install -m 0755 "$HERE/selfrecover-keyscript.sh"   "$SKG/selfrecover-keyscript.sh"
install -m 0755 "$HERE/initramfs-hook-selfrecover" /etc/initramfs-tools/hooks/selfrecover
ok "keyscript + hook (avec fix libgcc) déployés"

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
if grep -q "^${ROOT_NAME}.*keyscript=" /etc/crypttab; then
  ok "keyscript déjà présent sur $ROOT_NAME"
else
  confirm "Ajouter keyscript= à la ligne $ROOT_NAME ?"
  sed -i "/^${ROOT_NAME}[[:space:]]/s|\$|,keyscript=$SKG/selfrecover-keyscript.sh|" /etc/crypttab
  ok "keyscript ajouté"
fi
grep "^${ROOT_NAME}" /etc/crypttab | sed 's/^/    /'

# ---------- 6. dropbear + rootdelay ----------
say "6. Accès distant au boot (dropbear) + délai d'attente"
grep -q '^IP=' /etc/initramfs-tools/initramfs.conf \
  && sed -i 's/^IP=.*/IP=dhcp/' /etc/initramfs-tools/initramfs.conf \
  || echo 'IP=dhcp' >> /etc/initramfs-tools/initramfs.conf
[ -n "$NET_MODULE" ] && { grep -qx "$NET_MODULE" /etc/initramfs-tools/modules || echo "$NET_MODULE" >> /etc/initramfs-tools/modules; }
install -d -m 0755 /etc/dropbear/initramfs
install -m 0600 "$SSH_PUBKEY" /etc/dropbear/initramfs/authorized_keys
echo "DROPBEAR_OPTIONS=\"-p $DROPBEAR_PORT -s -j -k -I 300\"" > /etc/dropbear/initramfs/dropbear.conf
ok "dropbear: port $DROPBEAR_PORT, clé autorisée, IP=dhcp"
cp -a /etc/default/grub "/etc/default/grub.bak.$(date +%s)"
if grep -q 'rootdelay=' /etc/default/grub; then
  sed -i "s/rootdelay=[0-9]*/rootdelay=$ROOTDELAY/g" /etc/default/grub
else
  sed -i "s/^GRUB_CMDLINE_LINUX_DEFAULT=\"\(.*\)\"\$/GRUB_CMDLINE_LINUX_DEFAULT=\"\1 rootdelay=$ROOTDELAY\"/" /etc/default/grub
fi
update-grub >/dev/null 2>&1 && ok "rootdelay=$ROOTDELAY"

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

# ---------- 8. Régénérer l'initramfs (avec filet) ----------
say "8. Régénération de l'image d'amorçage"
KR="$(uname -r)"
cp -a "/boot/initrd.img-$KR" "/boot/initrd.img-$KR.bak.$(date +%s)"
ok "FILET : initrd sauvegardé (.bak.*)"
confirm "Lancer update-initramfs maintenant ?"
update-initramfs -u
say "Vérification du contenu de l'initrd"
lsinitramfs "/boot/initrd.img-$KR" | grep -E "selfrecover-keyscript|selfrecover_derive_c|libargon2|libgcc|sbin/dropbear" | sed 's/^/  /'

# ---------- 9. Récap ----------
say "TERMINÉ — à faire MAINTENANT"
cat <<EOF
  1) TESTER par redémarrage AVANT de te fier au système :
       reboot ; puis depuis un autre poste, dès que le port $DROPBEAR_PORT répond :
       ssh -p $DROPBEAR_PORT root@<IP> ; cryptroot-unlock ; (passphrase RECOVER)
     Filet si pépin : dans dropbear, 'cryptsetup open $ROOT_DEV $ROOT_NAME' (slot NATIF).

  2) SAUVEGARDER HORS-SITE (gestionnaire de mots de passe) :
       - la passphrase de récupération
       - le sel : $SKG/selfrecover_salt
       - les secrets de sauvegarde (accès dépôt + passphrase dépôt)

  3) Filets en place : slot natif conservé, initrd .bak.*, crypttab/grub/fstab .bak.*
EOF
