#!/bin/sh
# SelfRecover-LUKS — deverrouillage a distance, sans shell.
# Destination d'install : /etc/selfkeyguard/selfrecover-secours.sh
#
# Appele par dropbear comme `command=` de la cle autorisee au boot. Il offre les
# DEUX voies de deverrouillage et rien d'autre : la Passphrase Recover, et la
# passphrase LUKS NATIVE. C'est ce second chemin qui remplace le shell.
#
# --- pourquoi ce script existe ---
#
# Sans `command=`, la cle du dropbear d'amorcage donne un shell root busybox
# AVANT que la racine soit ouverte. Ce n'est pas une commodite, c'est un
# contournement du chiffrement : /boot n'est pas chiffre (le firmware doit le
# lire), le sel SelfRecover y voyage, et qui obtient ce shell monte /boot en
# ecriture, y depose un initrd modifie, et capture la passphrase a la saisie
# suivante. Rien ne le detecterait.
#
# Poser `command="cryptroot-unlock"` fermerait ce chemin — mais retirerait le
# filet anti-lockout que le keyscript documente : cryptroot-unlock passe par le
# keyscript, donc la passphrase NATIVE devient inatteignable a distance, et une
# machine dont le keyscript est casse n'a plus que la console physique.
#
# Ce script ferme le contournement SANS retirer le filet. Il n'y a donc pas
# d'arbitrage a trancher entre les deux, et aucune cle de secours a generer,
# sortir de la machine et ranger — une cle de secours rangee dans le meme
# coffre que les autres, ou partagee par le parc, recreerait exactement le
# defaut qu'on ferme.
#
# --- ce qu'il ne fait pas ---
#
# Il ne rend jamais de shell, n'accepte aucun argument du client (dropbear met
# la demande dans SSH_ORIGINAL_COMMAND ; on ne la lit pas), et ne monte rien.
set -eu

# Dans un initramfs Debian, crypttab est a /cryptroot/crypttab. Le chemin est
# surchargeable pour que le banc puisse l'eprouver hors initramfs.
CRYPTTAB="${SR_CRYPTTAB:-/cryptroot/crypttab}"
CRYPTSETUP="${SR_CRYPTSETUP:-cryptsetup}"
UNLOCK="${SR_UNLOCK:-cryptroot-unlock}"
ASKPASS="${SR_ASKPASS:-/lib/cryptsetup/askpass}"

echo
echo "  SelfRecover-LUKS — deverrouillage a distance"
echo

# La cible se LIT, elle ne se suppose pas : une machine peut porter plusieurs
# volumes, et le nom du mapper n'est pas devinable depuis le nom du peripherique.
# La premiere ligne non commentee de crypttab est la racine — c'est l'ordre dans
# lequel initramfs-tools la traite.
if [ ! -r "$CRYPTTAB" ]; then
  echo "  ERREUR : $CRYPTTAB illisible." >&2
  echo "  Le deverrouillage a distance ne peut pas aboutir ; il faut la console." >&2
  exit 2
fi
NAME=$(awk '!/^[[:space:]]*#/ && NF >= 2 {print $1; exit}' "$CRYPTTAB")
DEV=$(awk  '!/^[[:space:]]*#/ && NF >= 2 {print $2; exit}' "$CRYPTTAB")
if [ -z "${NAME:-}" ] || [ -z "${DEV:-}" ]; then
  echo "  ERREUR : aucune cible lisible dans $CRYPTTAB." >&2
  echo "  Le deverrouillage a distance ne peut pas aboutir ; il faut la console." >&2
  exit 2
fi

echo "  Volume : $NAME  ($DEV)"
echo
echo "    1) Passphrase RECOVER      — la voie normale"
echo "    2) Passphrase LUKS NATIVE  — si le module Recover est casse"
echo
printf '  Choix [1] : '
read -r CHOIX || CHOIX=1
[ -n "${CHOIX:-}" ] || CHOIX=1

case "$CHOIX" in
  1)
    # cryptroot-unlock parle au keyscript par le FIFO d'askpass : c'est le
    # chemin normal, celui qui derive la passphrase Recover.
    exec "$UNLOCK"
    ;;
  2)
    # La voie native NE passe PAS par le keyscript — c'est tout son interet, et
    # c'est ce que l'operateur faisait a la main dans le shell qu'on vient de
    # fermer. Le slot natif est conserve sur chaque volume par construction
    # (filet non negociable de l'installateur).
    #
    # askpass et non `read -rs` : l'option -s de read est une extension bash, et
    # ce script tourne sous dash dans l'initramfs — elle y rend « Illegal option
    # -s » et laisse la variable vide. Meme piege que le keyscript, meme parade.
    if [ -x "$ASKPASS" ]; then
      PASS=$("$ASKPASS" "Passphrase LUKS NATIVE ($NAME) : ")
    else
      printf '  Passphrase LUKS NATIVE (%s) : ' "$NAME"
      # Si stty manque, l'echo reste actif et la saisie s'affiche. Le dire : un
      # secret expose en silence est pire qu'un secret expose avec un avertissement.
      stty -echo 2>/dev/null || echo "  ATTENTION : echo non coupe, la saisie sera visible."
      read -r PASS
      stty echo 2>/dev/null
      echo
    fi
    # --key-file=- : sans lui, cryptsetup lit la phrase comme une passphrase de
    # terminal et s'arrete au premier 0x0A. printf '%s' et non echo, pour la
    # meme raison que dans le keyscript : un \n final ferait partie de la cle.
    if printf '%s' "$PASS" | "$CRYPTSETUP" open "$DEV" "$NAME" --key-file=-; then
      echo "  Volume $NAME ouvert. L'amorcage reprend."
      exit 0
    else
      # Le code de sortie de cryptsetup distingue mauvaise phrase et erreur reelle,
      # mais l'operateur a distance n'a pas la console pour le lire : le nommer ici.
      echo "  Echec : $NAME n'a pas ete ouvert (phrase incorrecte, ou volume deja ouvert)." >&2
      exit 1
    fi
    ;;
  *)
    echo "  Choix inconnu : $CHOIX" >&2
    exit 2
    ;;
esac
