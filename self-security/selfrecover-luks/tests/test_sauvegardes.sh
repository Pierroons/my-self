#!/usr/bin/env bash
# `verifie-sauvegardes.sh` refuse-t-il vraiment ce qu'il prétend refuser ?
#
# 🔑 **L'invariant que ce banc fixe.** Les deux secrets irréversibles de
# SelfRecover-LUKS — l'en-tête LUKS et le sel de déploiement — doivent avoir une
# copie *utilisable*. « Un fichier existe » ne suffit pas : une copie posée sur le
# volume chiffré qu'elle sert à ouvrir est inatteignable au moment où elle sert, et
# une copie périmée d'un slot ressuscite une clé révoquée.
#
# Le 15/09/2026, une machine devenue production publique avait sa SEULE copie
# d'en-tête à l'intérieur du volume qu'elle ouvre. `INSTALL.md` §4 le proscrivait
# déjà, mot pour mot. Un avertissement qui ne déclenche rien ne vaut pas mieux qu'un
# avertissement absent : ce banc existe pour que le contrôle qui le remplace rougisse.
#
# Ne demande PAS root : conteneur LUKS2 de 32 Mo sur fichier, aucune activation
# device-mapper, aucun volume réel touché. Même forme que test_lecture_keyfile.sh.
#
# Usage  : bash tests/test_sauvegardes.sh
# Sortie : 0 si chaque cas rend le verdict attendu, 1 sinon.

set -uo pipefail
# cryptsetup vit dans /sbin, absent du PATH d'un shell non interactif.
export PATH="/usr/sbin:/sbin:$PATH"

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"
VERIF="${VERIF:-$MODULE/verifie-sauvegardes.sh}"

[ -x "$VERIF" ] || { echo "❌ script introuvable ou non exécutable : $VERIF"; exit 1; }
command -v cryptsetup >/dev/null || { echo "❌ cryptsetup absent"; exit 1; }

echec=0; total=0
verdict() {  # verdict <libellé> <ACCEPTE|REFUSE> <motif attendu|--> -- <env...>
  local libelle="$1" attendu="$2" motif="$3"; shift 3
  local sortie obtenu
  total=$((total + 1))
  sortie="$(env "$@" "$VERIF" "$DISQUE" "$SKG" 2>&1)"
  if [ $? -eq 0 ]; then obtenu=ACCEPTE; else obtenu=REFUSE; fi
  if [ "$obtenu" != "$attendu" ]; then
    printf '  ❌ %-50s %s (attendu %s)\n' "$libelle" "$obtenu" "$attendu"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4
    echec=1; return
  fi
  if [ "$motif" != "--" ] && ! printf '%s' "$sortie" | grep -qi -- "$motif"; then
    printf '  ❌ %-50s %s mais sans dire « %s »\n' "$libelle" "$obtenu" "$motif"
    printf '%s\n' "$sortie" | sed 's/^/        /' | head -4
    echec=1; return
  fi
  printf '  ✅ %-50s %s\n' "$libelle" "$obtenu"
}

# ⚠️ Le banc ne peut pas vivre sur un tmpfs : le contrôle refuse — à juste titre —
# toute copie en mémoire volatile, et TOUS les cas rendraient alors « REFUSE » pour
# la mauvaise raison. C'est exactement le motif qu'on cherche à éviter partout
# ailleurs : un banc qui rougit sans mesurer ce qu'il annonce.
BASE="${TMPDIR:-/tmp}"
if [ "$(findmnt -no FSTYPE --target "$BASE" 2>/dev/null)" = tmpfs ]; then BASE="$MODULE"; fi
BANC="$(mktemp -d -p "$BASE" banc-sauvegardes.XXXXXX)" || { echo "❌ mktemp"; exit 1; }
trap 'rm -rf "$BANC"' EXIT

echo
echo "▸ contrôle : $VERIF"
echo "▸ banc     : $BANC  (support : $(findmnt -no FSTYPE --target "$BANC" 2>/dev/null))"
echo

PHRASE='phrase-de-banc-jamais-reelle'
DISQUE="$BANC/disque.img"
SKG="$BANC/skg"; mkdir -p "$SKG"

truncate -s 32M "$DISQUE"
printf '%s' "$PHRASE" | cryptsetup luksFormat --type luks2 \
  --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "$DISQUE" - >/dev/null 2>&1 \
  || { echo "❌ luksFormat"; exit 1; }
printf 'sel-de-banc-32-caracteres-exacts\n' > "$SKG/selfrecover_salt"

cryptsetup luksHeaderBackup "$DISQUE" --header-backup-file "$BANC/entete.img" >/dev/null 2>&1
cp "$SKG/selfrecover_salt" "$BANC/sel.copie"

E="ENTETE_SAUVEGARDE=$BANC/entete.img"
S="SEL_SAUVEGARDE=$BANC/sel.copie"

echo "▸ Le cas sain — le contrôle ne doit pas crier sans raison"
verdict "en-tête et sel valides, hors du volume" ACCEPTE "copie utilisable" -- "$E" "$S"

echo
echo "▸ Les défauts replantés — chacun DOIT être refusé"

verdict "ENTETE_SAUVEGARDE non défini" REFUSE "non défini" -- "ENTETE_SAUVEGARDE=" "$S"
verdict "sauvegarde d'en-tête inexistante" REFUSE "introuvable" -- "ENTETE_SAUVEGARDE=$BANC/rien.img" "$S"

printf 'ceci n est pas un en-tete LUKS' > "$BANC/faux.img"
verdict "fichier qui n'est pas un en-tête LUKS" REFUSE "ne se lit pas" -- "ENTETE_SAUVEGARDE=$BANC/faux.img" "$S"

# Un en-tête parfaitement valide… mais d'un AUTRE volume.
truncate -s 32M "$BANC/autre.img"
printf '%s' "$PHRASE" | cryptsetup luksFormat --type luks2 \
  --pbkdf pbkdf2 --pbkdf-force-iterations 1000 "$BANC/autre.img" - >/dev/null 2>&1
cryptsetup luksHeaderBackup "$BANC/autre.img" --header-backup-file "$BANC/entete-autre.img" >/dev/null 2>&1
verdict "en-tête valide mais d'un AUTRE volume" REFUSE "n'est pas celle de" -- "ENTETE_SAUVEGARDE=$BANC/entete-autre.img" "$S"

# LE CAS QUI COMPTE LE PLUS : la sauvegarde d'hier, après un ajout de slot.
printf '%s' "$PHRASE" > "$BANC/cle.actuelle"
printf '%s' 'seconde-phrase-de-banc' > "$BANC/cle.nouvelle"
cryptsetup luksAddKey "$DISQUE" "$BANC/cle.nouvelle" --key-file "$BANC/cle.actuelle" >/dev/null 2>&1 \
  || echo "  ⚠️  luksAddKey a échoué — le cas « périmée » n'est PAS éprouvé"
verdict "sauvegarde PÉRIMÉE (un slot ajouté depuis)" REFUSE "PÉRIMÉE" -- "$E" "$S"

# On la refait : le contrôle doit repasser au vert. Sans ce cas, « refuse toujours »
# passerait pour « refuse à bon escient ».
cryptsetup luksHeaderBackup "$DISQUE" --header-backup-file "$BANC/entete-neuve.img" >/dev/null 2>&1
verdict "sauvegarde refaite : le contrôle repasse au vert" ACCEPTE "copie utilisable" -- "ENTETE_SAUVEGARDE=$BANC/entete-neuve.img" "$S"
E="ENTETE_SAUVEGARDE=$BANC/entete-neuve.img"

echo
verdict "SEL_SAUVEGARDE non défini" REFUSE "non défini" -- "$E" "SEL_SAUVEGARDE="
verdict "copie du sel inexistante" REFUSE "introuvable" -- "$E" "SEL_SAUVEGARDE=$BANC/rien.txt"
printf 'un sel qui vient d ailleurs\n' > "$BANC/sel.etranger"
verdict "copie du sel d'un autre déploiement" REFUSE "ne correspond pas" -- "$E" "SEL_SAUVEGARDE=$BANC/sel.etranger"

# Le support volatil — une « sauvegarde » qui disparaît au redémarrage.
if [ -d /dev/shm ] && [ -w /dev/shm ]; then
  cp "$SKG/selfrecover_salt" /dev/shm/sel-banc-$$ 2>/dev/null
  verdict "copie du sel en mémoire volatile (/dev/shm)" REFUSE "mémoire" -- "$E" "SEL_SAUVEGARDE=/dev/shm/sel-banc-$$"
  rm -f /dev/shm/sel-banc-$$
else
  echo "  ⏭️  support volatil : NON ÉPROUVÉ ici (/dev/shm indisponible)"
fi

echo
echo "▸ Ce que ce banc N'ÉPROUVE PAS, et qui doit se savoir"
echo "  La branche « le support descend du volume racine » demande un vrai"
echo "  périphérique et un mapper : sur un conteneur LUKS en fichier, lsblk ne rend"
echo "  aucune ascendance. Elle reste donc à éprouver sur une machine réelle —"
echo "  c'est la branche que le défaut du 15/09/2026 aurait déclenchée."

echo
if [ "$echec" -eq 0 ]; then
  printf '✅ %d/%d — le contrôle accepte le cas sain et refuse chaque défaut replanté.\n' "$total" "$total"
  exit 0
fi
printf '❌ des cas ont rendu le mauvais verdict (%d cas joués).\n' "$total"
exit 1
