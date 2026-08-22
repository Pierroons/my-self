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
  # usrmerge, image minimale) — c'est justement le cas ou personne ne surveille.
  #
  # stty et non `read -rs` : l'option -s de read est une extension bash, et ce
  # script tourne sous /bin/sh, donc dash dans un initramfs Debian. `read -rs` y
  # rend « Illegal option -s » et laisse PASS vide — le repli devient inutilisable
  # dans le seul cas ou il sert. Mesure du 22/08/2026 ; `dash -n` ne le voit pas,
  # la ligne est syntaxiquement valide et n'echoue qu'a l'execution.
  printf 'Passphrase Recover-LUKS (%s) : ' "$NAME" >&2
  stty -echo 2>/dev/null
  read -r PASS
  stty echo 2>/dev/null
  printf '\n' >&2
fi

printf '%s' "$PASS" | "$BIN" --salt-file "$SALT" --label disk --format raw
