#!/bin/bash
# Banc des sondes — ce que `check-paths.sh` et `ecart-instance.sh` attrapent, et
# surtout ce qu'ils laissent passer en rendant vert.
#
# 🔑 **Pourquoi ce banc existe.** `AGENTS.md` demande que chaque contrôle ait été
# vu rougir, et ces deux-là n'avaient rien : ni banc, ni canari, ni cas planté.
# Leur silence ne se distinguait donc pas d'une absence de défaut. Deux faux verts
# ont été trouvés en les relisant, dont un qui retire un fichier du périmètre
# comparé — le contrôle continuait d'annoncer « rien ne diverge » sur du code
# qu'il ne regardait plus.
#
# Chaque cas suit la même forme : le défaut redouté, puis un témoin qui prouve
# que la sonde voit encore ce qu'elle doit voir. Sans le témoin, une sonde cassée
# passerait le banc en n'attrapant plus rien.
#
# Usage : bash scripts/tests/test_sondes.sh
# Sortie : 0 si toutes les propriétés tiennent. Leur nombre se compte à
#          l'exécution — l'écrire ici le ferait mentir au prochain ajout.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/../.." && pwd)"
CHECK_PATHS="$RACINE/scripts/check-paths.sh"
ECART="$RACINE/scripts/ecart-instance.sh"
for f in "$CHECK_PATHS" "$ECART"; do
    [ -r "$f" ] || { echo "sonde introuvable : $f" >&2; exit 1; }
done

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

# ── 1. check-paths.sh lancé hors d'un dépôt git ─────────────────────────────
#
# `cd "$(git rev-parse --show-toplevel)" || exit 1` ne garde rien : hors dépôt,
# la substitution est vide et `cd ""` rend 0 en bash. Les `git ls-files` rendent
# vide ensuite, les quatre blocs affichent ✓, et le script sort en 0 sans avoir
# lu un seul fichier.
# ⚠️ Le code de sortie ne mesure PAS cette propriété. Hors dépôt il vaut déjà 1,
# mais par accident : le dernier bloc échoue parce que la copie de licence qu'il
# cherche n'existe pas dans le bac. Le faux vert se lit avant, dans la sortie —
# quatre blocs annoncent ✓ après n'avoir lu aucun fichier. La propriété à tenir
# est donc « aucun ✓ quand rien n'a pu être lu », pas « le code n'est pas 0 ».
echo "▸ check-paths.sh — un lancement hors dépôt ne doit annoncer aucun succès"
texte="$( (cd "$BAC" && bash "$CHECK_PATHS") 2>&1 || true )"
faux_verts=$(printf '%s\n' "$texte" | grep -c '✓' || true)
if [ "$faux_verts" -gt 0 ]; then
    nok "annonce $faux_verts succès hors dépôt git — autant de contrôles verts sur du vide"
else
    ok "n'annonce aucun succès hors dépôt git"
fi

# Témoin : dans le dépôt réel, il doit encore tourner et rendre un verdict.
sortie=0
( cd "$RACINE" && bash "$CHECK_PATHS" >/dev/null 2>&1 ) || sortie=$?
if [ "$sortie" -le 1 ]; then
    ok "témoin : rend un verdict lisible dans le dépôt réel (code $sortie)"
else
    nok "témoin : code de sortie inattendu dans le dépôt réel ($sortie)"
fi

# ── 2. ecart-instance.sh — l'ancre de fin de motif_vers_regex ───────────────
#
# Un motif rsync ancré à la racine sans ancre de fin attrape par préfixe. Le
# motif qui vise le binaire `selfrecover_derive` attrapait donc aussi ses
# sources `.c` et `.py`, qui quittaient le périmètre comparé : leur contenu
# n'était plus jamais confronté à l'instance. Un contrôle qui rend vert en
# regardant moins est plus dangereux qu'un contrôle qui crie.
echo "▸ ecart-instance.sh — un motif de fichier ne doit pas attraper par préfixe"
if eval "$(sed -n '/^motif_vers_regex()/,/^}/p' "$ECART")" 2>/dev/null \
   && declare -F motif_vers_regex >/dev/null; then
    ok "fonction motif_vers_regex extraite du script"

    re_bin="$(motif_vers_regex '/self-security/selfrecover-luks/selfrecover_derive')"
    for source in selfrecover_derive.c selfrecover_derive.py; do
        if printf '%s\n' "self-security/selfrecover-luks/$source" | grep -qE "$re_bin"; then
            nok "le motif du binaire attrape $source — il sort du périmètre comparé"
        else
            ok "$source reste dans le périmètre comparé"
        fi
    done

    # Témoins : le motif doit toujours attraper ce qu'il vise, et les motifs de
    # répertoire doivent continuer de couvrir leur contenu. Sans eux, une ancre
    # trop serrée viderait les exclusions sans qu'un cas rougisse.
    if printf '%s\n' 'self-security/selfrecover-luks/selfrecover_derive' | grep -qE "$re_bin"; then
        ok "témoin : le motif attrape toujours le binaire qu'il vise"
    else
        nok "témoin : le motif n'attrape plus le binaire — exclusion cassée"
    fi

    re_dir="$(motif_vers_regex '/demo/lab/data/*')"
    if printf '%s\n' 'demo/lab/data/lab.db' | grep -qE "$re_dir"; then
        ok "témoin : un motif de répertoire couvre toujours son contenu"
    else
        nok "témoin : le motif de répertoire ne couvre plus son contenu"
    fi
else
    nok "extraction de motif_vers_regex impossible — le banc ne mesure rien"
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
echo "$reussites propriété(s) tiennent, $echecs en échec."
[ "$echecs" -eq 0 ] || exit 1
