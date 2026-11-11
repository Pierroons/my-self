#!/usr/bin/env bash
# La ligne de clé du dropbear d'amorçage, posée et relue comme le serveur SSH la lit.
#
# 🔑 **Pourquoi ce script existe.** Une ligne de `authorized_keys` porte ses options,
# puis UNE ESPACE, puis le type de clé :
#
#     command="…/selfrecover-secours.sh",no-port-forwarding ssh-ed25519 AAAA…
#
# Une virgule à la place de l'espace fait lire le type de clé comme une option : la
# ligne ne désigne plus aucune clé, et la machine reste injoignable au démarrage, ce
# qui ne se découvre qu'au redémarrage, sur une machine souvent sans écran.
# `install.sh` et `INSTALL.md` (§8b) ont posé cette virgule jusqu'à la 0.6.2, et le
# contre-témoin d'`install.sh`, qui comptait des motifs, la laissait passer. Un seul
# fichier assemble désormais la ligne, et rien ne la remplace sans l'avoir relue.
#
#   borner <authorized_keys> <options>
#       écrit sur la sortie le fichier avec <options> devant chaque clé : une espace
#       devant une clé nue, une virgule devant des options déjà présentes. Les
#       commentaires et les lignes vides passent tels quels.
#
#   verifier <authorized_keys>
#       chaque ligne de clé est-elle lisible ? Sa forme d'abord — des options sans
#       blanc hors guillemets, une espace, un type de clé connu, la clé en base64 —,
#       puis sa lecture par `ssh-keygen` quand il est installé. Refuse un fichier sans
#       aucune clé : personne n'entrerait au démarrage.
#
# Sortie : 0 si conforme ; 1 si refusé (les lignes fautives sont nommées) ; 2 si mal appelé.
set -uo pipefail

refus() { printf '❌ %s\n' "$*" >&2; exit 1; }
usage() {
  echo "usage : $0 borner <authorized_keys> <options>" >&2
  echo "        $0 verifier <authorized_keys>" >&2
  exit 2
}

TYPES='ssh-ed25519|ssh-rsa|ssh-dss|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com'
CLE="($TYPES)[[:space:]]+[A-Za-z0-9+/]+={0,3}([[:space:]].*)?$"
CLE_NUE="^$CLE"
AVEC_OPTIONS="^([^[:space:]\"]|\"[^\"]*\")+[[:space:]]+$CLE"
VIDE_OU_COMMENTAIRE='^[[:space:]]*(#|$)'

borner() {
  local fichier="$1" options="$2" ligne
  [ -r "$fichier" ] || refus "illisible : $fichier"
  [ -n "$options" ] || refus "options vides : rien à poser."
  while IFS= read -r ligne || [ -n "$ligne" ]; do
    if [[ "$ligne" =~ $VIDE_OU_COMMENTAIRE ]]; then
      printf '%s\n' "$ligne"
    elif [[ "$ligne" =~ $CLE_NUE ]]; then
      printf '%s %s\n' "$options" "$ligne"
    else
      printf '%s,%s\n' "$options" "$ligne"
    fi
  done < "$fichier"
}

verifier() {
  local fichier="$1" ligne n=0 cles=0 fautives="" keygen="" essai
  [ -r "$fichier" ] || refus "illisible : $fichier"
  command -v ssh-keygen >/dev/null 2>&1 && keygen=oui
  essai="$(mktemp)" || refus "mktemp a échoué."
  while IFS= read -r ligne || [ -n "$ligne" ]; do
    n=$((n+1))
    [[ "$ligne" =~ $VIDE_OU_COMMENTAIRE ]] && continue
    cles=$((cles+1))
    if ! [[ "$ligne" =~ $CLE_NUE || "$ligne" =~ $AVEC_OPTIONS ]]; then
      fautives="$fautives $n"
      continue
    fi
    if [ -n "$keygen" ]; then
      printf '%s\n' "$ligne" > "$essai"
      ssh-keygen -l -f "$essai" >/dev/null 2>&1 || fautives="$fautives $n"
    fi
  done < "$fichier"
  rm -f "$essai"
  [ "$cles" -gt 0 ] || refus "aucune clé dans $fichier : personne n'entrerait au démarrage."
  [ -z "$fautives" ] || refus "ligne(s)$fautives illisible(s) dans $fichier : la clé n'ouvrirait pas, et la machine serait injoignable au démarrage."
  if [ -n "$keygen" ]; then
    printf '✓ %d clé(s) lisible(s) dans %s (forme, et lecture par ssh-keygen)\n' "$cles" "$fichier"
  else
    printf '✓ %d clé(s) de forme lisible dans %s (ssh-keygen absent : forme seule)\n' "$cles" "$fichier"
  fi
}

VERBE="${1:-}"
[ $# -ge 1 ] && shift
case "$VERBE" in
  borner)   [ $# -eq 2 ] || usage; borner "$1" "$2" ;;
  verifier) [ $# -eq 1 ] || usage; verifier "$1" ;;
  *) usage ;;
esac
