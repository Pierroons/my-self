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
  printf 'Passphrase Recover-LUKS (%s) : ' "$NAME" >&2
  read -r PASS
fi

printf '%s' "$PASS" | "$BIN" --salt-file "$SALT" --label disk --format raw
