#!/usr/bin/env bash
# Les deux secrets irréversibles de SelfRecover-LUKS sont-ils réellement sauvegardés ?
#
# 🔑 **Pourquoi ce script existe.** `INSTALL.md` §4 dit depuis toujours qu'une
# sauvegarde d'en-tête rangée sur le volume chiffré ne sert à rien — « au moment où
# on en a besoin, on ne peut plus la lire ». Le 15/09/2026, une machine devenue
# production publique avait sa SEULE copie d'en-tête à l'intérieur du volume qu'elle
# sert à ouvrir. La consigne était juste ; rien ne la faisait respecter.
# Un avertissement écrit qui ne déclenche rien ne vaut pas mieux qu'un avertissement
# absent.
#
# ⚠️ **Ce qu'un script NE PEUT PAS mesurer, c'est « hors de la machine ».** Se
# contenter de « un fichier de sauvegarde existe » serait satisfait par une copie
# posée sur le volume chiffré — précisément le défaut. On aurait remplacé un
# avertissement juste par une FAUSSE ASSURANCE, ce qui est pire. Ce script vérifie
# donc seulement ce qui est mesurable ici :
#
#   1. la sauvegarde se lit, et c'est bien un en-tête LUKS ;
#   2. elle appartient à CE volume — même UUID ;
#   3. elle n'est pas périmée — même nombre de slots ;
#   4. son support ne descend pas du volume qu'elle sert à ouvrir, et n'est pas volatil.
#
# Emporter la copie hors du bâtiment reste un geste humain, que le §4 décrit.
#
# 🔑 **Il est séparé d'`install.sh` pour deux raisons.** D'abord pour être éprouvable
# hors d'une vraie machine : le banc `tests/test_sauvegardes.sh` l'exerce sur un
# conteneur LUKS de 32 Mo, sans root et sans device-mapper. Ensuite parce que le
# besoin ne s'arrête pas à l'installation — **chaque ajout de slot périme la
# sauvegarde**, et ce script se relance quand on veut, y compris depuis une tâche
# planifiée.
#
# Usage  : ENTETE_SAUVEGARDE=<fichier> SEL_SAUVEGARDE=<fichier> \
#            ./verifie-sauvegardes.sh <volume-luks> [répertoire-selfkeyguard]
# Sortie : 0 si les deux sauvegardes tiennent, 1 sinon (avec la raison).
set -uo pipefail

# cryptsetup, findmnt et lsblk vivent dans /sbin — absent du PATH d'un shell non
# interactif. Sans cette ligne, `command -v cryptsetup` rend vide sur une machine
# où le binaire existe, et le script conclurait à une dépendance manquante.
export PATH="/usr/sbin:/sbin:$PATH"

ROOT_DEV="${1:?volume LUKS attendu (ex. /dev/nvme0n1p3)}"
SKG="${2:-${SKG:-/etc/selfkeyguard}}"
ENTETE_SAUVEGARDE="${ENTETE_SAUVEGARDE:-}"
SEL_SAUVEGARDE="${SEL_SAUVEGARDE:-}"

ok()  { printf '  \033[32m✓\033[0m %s\n' "$*"; }
ko()  { printf '\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

AIDE="Pose ENTETE_SAUVEGARDE et SEL_SAUVEGARDE sur des copies hors de ce volume."

command -v cryptsetup >/dev/null || ko "cryptsetup absent."

# Le support de ce fichier est-il inutilisable comme sauvegarde ?
# Rend 0 (vrai) quand il l'est, et renseigne $MOTIF.
support_inutilisable() {
  MOTIF=""
  local sf src fs racine
  sf="$(findmnt -no SOURCE,FSTYPE --target "$1" 2>/dev/null)" || return 1
  [ -n "$sf" ] || return 1
  src="${sf%% *}"; fs="${sf##* }"
  case "$fs" in
    tmpfs|ramfs|devtmpfs)
      MOTIF="il vit en mémoire ($fs) : il disparaît au redémarrage"
      return 0 ;;
  esac
  # Réseau, overlay, ce qui n'est pas un périphérique bloc : ce n'est pas notre volume.
  case "$src" in /dev/*) ;; *) return 1 ;; esac
  racine="$(basename "$(readlink -f "$ROOT_DEV")")"
  # ⚠️ `lsblk -nso NAME` DESSINE UN ARBRE : « nvme0n1p3 », puis « └─nvme0n1 ». Sans
  # retirer ces caractères, la comparaison n'aboutit jamais — et ce contrôle rendrait
  # vert en n'ayant rien comparé. C'est la forme exacte du faux vert qu'il ferme.
  if lsblk -nso NAME "$src" 2>/dev/null | tr -d ' │└├─' | grep -qx "$racine"; then
    MOTIF="son support descend de $ROOT_DEV — le volume qu'il sert justement à ouvrir"
    return 0
  fi
  return 1
}

# ---------------------------------------------------------------- l'en-tête LUKS
[ -n "$ENTETE_SAUVEGARDE" ] || ko "ENTETE_SAUVEGARDE non défini.
   L'en-tête LUKS perdu, AUCUN slot n'ouvre plus rien — la passphrase ne sert à rien.
   Fais-la d'abord :
     cryptsetup luksHeaderBackup \"$ROOT_DEV\" --header-backup-file <fichier>
   $AIDE"
[ -f "$ENTETE_SAUVEGARDE" ] || ko "ENTETE_SAUVEGARDE introuvable : $ENTETE_SAUVEGARDE"

# ⚠️ `luksDump --header <fichier>` N'EXISTE PAS : cryptsetup 2.7.5 rend une erreur
# d'usage. La forme qui lit une sauvegarde est `luksDump <fichier>`, tout court.
# INSTALL.md §4 portait la mauvaise — l'opérateur à qui on demandait de vérifier sa
# sauvegarde voyait une erreur, et passait.
cryptsetup luksDump "$ENTETE_SAUVEGARDE" >/dev/null 2>&1 \
  || ko "$ENTETE_SAUVEGARDE ne se lit pas comme un en-tête LUKS."

U_DISQUE="$(cryptsetup luksUUID "$ROOT_DEV" 2>/dev/null)"
U_SAUVE="$(cryptsetup luksUUID "$ENTETE_SAUVEGARDE" 2>/dev/null)"
{ [ -n "$U_DISQUE" ] && [ "$U_DISQUE" = "$U_SAUVE" ]; } \
  || ko "cette sauvegarde n'est pas celle de $ROOT_DEV.
   disque : ${U_DISQUE:-?}
   copie  : ${U_SAUVE:-?}
   Restaurer un en-tête étranger détruirait l'accès au volume."

slots() { cryptsetup luksDump "$1" 2>/dev/null | grep -cE '^[[:space:]]+[0-9]+: luks2'; }
N_DISQUE="$(slots "$ROOT_DEV")"; N_SAUVE="$(slots "$ENTETE_SAUVEGARDE")"
[ "$N_DISQUE" = "$N_SAUVE" ] \
  || ko "sauvegarde PÉRIMÉE : $N_DISQUE slot(s) sur le disque, $N_SAUVE dans la copie.
   Une sauvegarde antérieure à un luksKillSlot ressuscite la clé qu'on croyait
   supprimée. Refais-la, et détruis l'ancienne."

if support_inutilisable "$ENTETE_SAUVEGARDE"; then
  ko "la sauvegarde d'en-tête ne protège rien : $MOTIF.
   $AIDE"
fi
ok "en-tête : lisible, même UUID, $N_SAUVE slot(s) concordants, hors du volume racine"

# ------------------------------------------------------------- le sel de déploiement
SEL_DISQUE="$SKG/selfrecover_salt"
[ -n "$SEL_SAUVEGARDE" ] || ko "SEL_SAUVEGARDE non défini.
   Sans le sel, la passphrase Recover ne dérive plus rien : le slot devient inouvrable
   même avec la bonne phrase. Copie $SEL_DISQUE hors de cette machine.
   $AIDE"
[ -f "$SEL_SAUVEGARDE" ] || ko "SEL_SAUVEGARDE introuvable : $SEL_SAUVEGARDE"
[ -r "$SEL_DISQUE" ] || ko "$SEL_DISQUE illisible — impossible de comparer la copie.
   « illisible » n'est pas « conforme » : ce contrôle refuse plutôt que de supposer."
cmp -s "$SEL_SAUVEGARDE" "$SEL_DISQUE" \
  || ko "la copie du sel ne correspond pas à $SEL_DISQUE.
   Une copie d'un autre déploiement ne dérivera pas la clé de celui-ci."

if support_inutilisable "$SEL_SAUVEGARDE"; then
  ko "la copie du sel ne protège rien : $MOTIF.
   $AIDE"
fi
ok "sel : identique à celui du disque, hors du volume racine"

printf '\n\033[32m✓ les deux secrets irréversibles ont une copie utilisable.\033[0m\n'
printf '  Ce script ne sait pas si elle est hors du bâtiment — ça, c'"'"'est à toi (§4, §13).\n\n'
