#!/bin/bash
# L'hôte porte-t-il encore les correctifs locaux sur legi.py ?
#
# 🔑 **Pourquoi ce contrôle existe.** `legi.py` est un dépôt tiers, cloné sur
# l'instance et absent d'ici. Les correctifs qu'il porte sont appliqués à la
# main dans son arbre de travail, que rien ne versionne : un `git pull` dedans
# les efface en silence, et la mise à jour LEGI suivante s'arrête sur le premier
# article dont `CONTEXTE/TEXTE` ne déclare pas de `cid`. L'échec arrive au
# moment de la moisson — le 1er ou le 15, à 4 h du matin.
#
# Le patch de référence est `self-right/selfjustice/tools/legi_tar2sqlite.patch`.
# Il décrit ce que l'arbre doit porter EN PLUS de son commit de base ; le
# contrôle le rejoue à l'envers, à blanc. Si l'arbre a divergé — patch perdu,
# patch modifié, ou base changée sous lui — la vérification échoue.
#
# ⚠️ On ne compare pas des empreintes : une empreinte du fichier entier
# rougirait à chaque mise à jour amont légitime, y compris celles qui gardent
# les correctifs. Ce qu'on veut savoir, c'est si le DELTA est encore là.
#
# Où il tourne : sur l'hôte nommé par `LEGI_PATCH_HOST` (par SSH). Sans hôte, il
# ÉCHOUE — un contrôle qu'on ne peut pas lancer ne rend pas vert. Pour
# l'autoriser à passer son tour en connaissance de cause :
# `LEGI_PATCH_SKIP_OK=1`.
#
# Usage :
#   LEGI_PATCH_HOST=mon-serveur bash scripts/check-patch-legi.sh
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

PATCH="self-right/selfjustice/tools/legi_tar2sqlite.patch"
CLONE="${LEGI_PATCH_CLONE:-/opt/selfjustice/legi.py}"

echo "▸ Correctifs locaux sur legi.py"

if [ ! -f "$PATCH" ]; then
    echo "  ✗ patch de référence introuvable : $PATCH"
    exit 1
fi

if [ -z "${LEGI_PATCH_HOST:-}" ]; then
    if [ "${LEGI_PATCH_SKIP_OK:-}" = "1" ]; then
        echo "  ↷ aucun hôte nommé, saut assumé (LEGI_PATCH_SKIP_OK=1)"
        exit 0
    fi
    echo "  ✗ LEGI_PATCH_HOST non défini : ce contrôle ne peut pas s'exécuter."
    echo "    Nomme un hôte (LEGI_PATCH_HOST=…), ou assume le saut avec"
    echo "    LEGI_PATCH_SKIP_OK=1. Un contrôle qu'on ne peut pas lancer ne rend"
    echo "    pas vert."
    exit 1
fi

# Le patch part encodé, DANS le script — pas sur l'entrée standard, que `ssh …
# bash -s` occupe déjà. Un `cat - <<HEREDOC` ne les fait pas cohabiter : le
# heredoc devient l'entrée de `cat` et écrase ce qui arrive par le tube. Même
# piège que dans check-vhost.sh, retombé dedans en écrivant celui-ci.
recette=$(cat <<'RECETTE'
[ -d "$CLONE/.git" ] || { echo "ABSENT $CLONE"; exit 3; }
tmp=$(mktemp) || exit 4
printf '%s' "$PATCH_B64" | base64 -d > "$tmp"
cd "$CLONE" || exit 4
if git -c safe.directory="$PWD" apply --reverse --check "$tmp" 2>/dev/null; then
    echo "PORTE"
else
    echo "DIVERGE"
    git -c safe.directory="$PWD" status --porcelain -- legi/tar2sqlite.py
fi
RECETTE
)
sortie=$(printf 'PATCH_B64=%s\nCLONE=%s\n%s\n' \
    "$(base64 -w0 < "$PATCH")" "$CLONE" "$recette" \
    | ssh "$LEGI_PATCH_HOST" bash -s 2>&1)
rc=$?

case "$sortie" in
    PORTE*)
        echo "  ✓ $LEGI_PATCH_HOST:$CLONE porte les correctifs, à l'identique"
        exit 0 ;;
    ABSENT*)
        echo "  ✗ pas de clone legi.py sur $LEGI_PATCH_HOST à $CLONE"
        exit 1 ;;
    DIVERGE*)
        echo "  ✗ $LEGI_PATCH_HOST:$CLONE ne porte plus le patch de référence."
        echo "    Réappliquer :  git -C $CLONE apply $PATCH"
        printf '%s\n' "$sortie" | tail -n +2 | sed 's/^/    /'
        exit 1 ;;
    *)
        echo "  ✗ contrôle inexploitable (rc=$rc) :"
        printf '%s\n' "$sortie" | sed 's/^/    /'
        exit 1 ;;
esac
