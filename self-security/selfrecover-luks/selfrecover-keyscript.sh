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

# --format hex, et NON raw. La cle brute fait 32 octets ; chacun a 1 chance sur 256
# de valoir 0x0A. Or cryptsetup lit une cle sur STDIN « up to the first newline
# character » (cryptsetup(8), Passphrase processing) alors qu'il lit un FICHIER en
# entier. Le keyscript ecrit sur stdout -> le script Debian passe --key-file=- ->
# stdin : une cle brute contenant un 0x0A serait tronquee au demarrage, alors que
# l'enrolement, qui passe par un fichier, l'aurait acceptee entiere. 11,8 % des
# cles sont dans ce cas. L'hexadecimal ne peut pas contenir 0x0A : les deux
# chemins lisent alors la meme chose.
printf '%s' "$PASS" | "$BIN" --salt-file "$SALT" --label disk --format hex
