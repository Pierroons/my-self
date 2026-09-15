#!/bin/sh
# SelfRecover keyscript — déverrouillage de / au boot par la Passphrase Recover-LUKS.
#
# Dérive la passphrase (Argon2id, label=disk) avec selfrecover_derive_c et écrit la CLÉ
# BRUTE (32 o) sur stdout — ce que cryptsetup attend. Appelé par crypttab (keyscript=).
#
# NB cascade : /data n'utilise PAS ce keyscript — systemd-cryptsetup gère les volumes
# non-root et IGNORE le champ keyscript. /data s'ouvre via un keyfile (/etc/keys/data.key)
# stocké sur / (chiffré) : une fois / déverrouillé par recover, systemd lit le keyfile et
# ouvre /data tout seul. Une saisie recover -> / + /data.
#
# Filet anti-lockout : la passphrase LUKS native (slot 0) reste ouvrable via
#   `cryptsetup open <dev> <name>` manuel (shell dropbear), qui NE passe PAS par ce script.
SALT=/etc/selfkeyguard/selfrecover_salt
BIN=/etc/selfkeyguard/selfrecover_derive_c
NAME="${CRYPTTAB_NAME:-disque}"

if [ -x /lib/cryptsetup/askpass ]; then
  PASS=$(/lib/cryptsetup/askpass "Passphrase Recover-LUKS ($NAME) : ")
else
  # Sans coupure de l'echo, la passphrase de recuperation s'afficherait EN CLAIR
  # sur la console d'amorcage. Ce repli sert quand askpass manque (initramfs non
  # usrmerge, image minimale).
  #
  # stty et non `read -rs` : l'option -s de read est une extension bash, et ce
  # script tourne sous /bin/sh, donc dash dans un initramfs Debian. `read -rs` y
  # rend « Illegal option -s » et laisse PASS vide. `dash -n` ne le voit pas : la
  # ligne est syntaxiquement valide, elle n'echoue qu'a l'execution.
  printf 'Passphrase Recover-LUKS (%s) : ' "$NAME" >&2
  # Si stty manque (image minimale sans son applet busybox), l'echo reste actif et
  # la passphrase s'affiche. Le dire : un secret expose en silence est pire qu'un
  # secret expose avec un avertissement.
  stty -echo 2>/dev/null || printf 'ATTENTION : echo non coupe, la saisie sera visible.\n' >&2
  read -r PASS
  stty echo 2>/dev/null
  printf '\n' >&2
fi

# --format hex, pour la PORTABILITE. Le demarrage Debian passe toujours par
# --key-file=-, qui est une lecture de keyfile : le flux est lu en entier, sauts de
# ligne compris. Une cle brute y passe donc sans dommage. Mais une cle lue en tant
# que PASSPHRASE — sans --key-file — s'arrete au premier 0x0A, et 11,8 % des cles
# brutes de 32 octets en contiennent un. L'hex survit aux deux lectures : secours
# tape a la main, cryptsetup open sans --key-file, amorceur non Debian.
# Mesure et matrice : docs/cryptsetup-lecture-cle.md
#
# printf '%s' et non echo, ici comme dans le derivateur : un \n FINAL ferait partie
# du keyfile et changerait la cle. C'est le seul mode de defaillance, et il vaut
# pour l'hex autant que pour le brut. Garde par tests/test_lecture_keyfile.sh.
printf '%s' "$PASS" | "$BIN" --salt-file "$SALT" --label disk --format hex
