#!/bin/bash
# Banc de `ci-modules.sh` — un envoi joue-t-il les groupes qu'il touche, et eux seuls ?
#
# Deux défauts sont à craindre, et ils ne se voient pas de la même façon. Un groupe joué
# pour rien coûte du temps de CI : il se voit dans la durée. Un groupe sauté alors que
# l'envoi le touchait rend un vert qui n'a rien éprouvé : rien ne le montre. Ce banc
# garde surtout le second : chaque dépendance de la table, le « tout » d'un chemin
# inconnu, et le contrôle des jobs du workflow (`--verifier`) sur des workflows plantés.
#
# Usage : bash scripts/tests/test_ci_modules.sh
#   CI_MODULES_SOUS_TEST=<fichier> rejoue le banc contre une autre version du script,
#   pour le voir rougir sur une table plantée.
# Sortie : 0 si toutes les propriétés tiennent.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/../.." && pwd)"
SOUS_TEST="${CI_MODULES_SOUS_TEST:-$RACINE/scripts/ci-modules.sh}"
[ -r "$SOUS_TEST" ] || { echo "script introuvable : $SOUS_TEST" >&2; exit 1; }

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

# Le script lit le dépôt où il est rangé : un dépôt jetable le porte.
git -C "$BAC" init -q -b principal depot
mkdir -p "$BAC/depot/scripts" "$BAC/depot/bi-self/selfmoderate" "$BAC/depot/.github/workflows"
cp "$SOUS_TEST" "$BAC/depot/scripts/ci-modules.sh"
CI="$BAC/depot/scripts/ci-modules.sh"
git -C "$BAC/depot" -c user.name=banc -c user.email=banc@exemple.invalid add -A
git -C "$BAC/depot" -c user.name=banc -c user.email=banc@exemple.invalid commit -q -m base
BASE=$(git -C "$BAC/depot" rev-parse HEAD)

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

joues() {  # la sortie du script → les groupes à true, triés, séparés par des espaces
  grep '=true$' | cut -d= -f1 | sort | tr '\n' ' ' | sed 's/ $//'
}

egal() {  # <libellé> <attendu> <rendu>
  if [ "$3" = "$2" ]; then ok "$1"; else nok "$1 : attendu « $2 », rendu « $3 »"; fi
}

refuse() {  # <libellé> <commande>… : la commande doit sortir en échec
  local libelle="$1"; shift
  if "$@" >/dev/null 2>&1; then nok "$libelle : accepté"; else ok "$libelle"; fi
}

attendu() {  # <libellé> <groupes attendus> <fichier>… : la table range ces fichiers ainsi
  local libelle="$1" voulu="$2"; shift 2
  local rendu
  rendu=$(printf '%s\n' "$@" | bash "$CI" --fichiers 2>/dev/null | joues)
  if [ "$rendu" = "$voulu" ]; then ok "$libelle → ${voulu:-transverse seul}"
  else nok "$libelle : attendu « ${voulu:-transverse seul} », rendu « ${rendu:-transverse seul} »"; fi
}

TOUT="couplage lab selfdataguard selfrecover selfright"

echo "▸ la table"
attendu "SelfModerate" "lab" bi-self/selfmoderate/src/Moderate.php
attendu "SelfDataGuard" "couplage lab selfdataguard" self-security/selfdataguard/src/Vault.php
attendu "la démo SelfDataGuard" "couplage lab selfdataguard" demo/selfdataguard/api/_bootstrap.php
attendu "SelfRecover" "couplage lab selfrecover" bi-self/selfrecover/src/Recovery/Escalade.php
attendu "le lab" "lab" demo/lab/tests/sanity_moderate.php
attendu "Self-Right" "selfright" self-right/selfjustice/api/index.php
attendu "le README d'un module" "" bi-self/selfrecover/README.md
attendu "la doc et le journal" "" CHANGELOG.md docs/architecture/CURRENT_ARCHITECTURE.md
attendu "le transverse" "" scripts/check-paths.sh deploy/my-self/deploy.sh web/my-self.fr/index.html \
  modules.json demo/bi-self-duo/index.php self-security/selfrecover-luks/keyscript.sh .gitleaks.toml
attendu "un autre workflow" "" .github/workflows/suivi.yml
attendu "le workflow structure" "$TOUT" .github/workflows/structure.yml
attendu "l'action de préparation" "$TOUT" .github/actions/preparer-php/action.yml
attendu "la table elle-même" "$TOUT" scripts/ci-modules.sh
attendu "un chemin hors table" "$TOUT" nouveau/inconnu.php
attendu "un chemin hors table parmi d'autres" "$TOUT" bi-self/selfmoderate/src/Moderate.php LISEZ-MOI
attendu "rien de changé" ""

echo "▸ à la demande"
egal "« tout » joue tout" "$TOUT" "$(bash "$CI" --modules tout 2>/dev/null | joues)"
egal "deux groupes nommés" "lab selfright" "$(bash "$CI" --modules lab,selfright 2>/dev/null | joues)"
refuse "un groupe inconnu est refusé" bash "$CI" --modules zorg
refuse "une demande vide est refusée" bash "$CI" --modules ""

echo "▸ le diff"
touch "$BAC/depot/bi-self/selfmoderate/Neuf.php"
git -C "$BAC/depot" -c user.name=banc -c user.email=banc@exemple.invalid add -A
git -C "$BAC/depot" -c user.name=banc -c user.email=banc@exemple.invalid commit -q -m neuf
egal "le diff d'un envoi SelfModerate joue le lab" "lab" "$(bash "$CI" --diff "$BASE" HEAD 2>/dev/null | joues)"
egal "une base nulle joue tout" "$TOUT" "$(bash "$CI" --diff 0000000000000000000000000000000000000000 HEAD 2>/dev/null | joues)"
egal "une base absente de l'historique joue tout" "$TOUT" \
  "$(bash "$CI" --diff 1234567890abcdef1234567890abcdef12345678 HEAD 2>/dev/null | joues)"

echo "▸ le contrôle des jobs du workflow"
GROUPES="selfdataguard selfrecover couplage lab selfright"
workflow() {  # un workflow minimal qui suit la table ; les cas le plantent ensuite
  {
    printf 'name: structure\non: push\njobs:\n  modules:\n    runs-on: x\n    outputs:\n'
    # shellcheck disable=SC2016  # `${{ … }}` est le texte du workflow, pas une expansion du shell
    for g in $GROUPES; do printf '      %s: ${{ steps.choix.outputs.%s }}\n' "$g" "$g"; done
    printf '  transverse:\n    runs-on: x\n'
    for j in selfdataguard-1 selfdataguard-2 selfrecover couplage couplage-php-anciens lab selfright; do
      g=$(for x in $GROUPES; do case "$j" in "$x"|"$x"-*) echo "$x";; esac; done | awk '{print length, $0}' | sort -rn | head -1 | cut -d' ' -f2)
      printf "  %s:\n    needs: modules\n    if: needs.modules.outputs.%s == 'true'\n    runs-on: x\n" "$j" "$g"
    done
    printf '  structure:\n    if: always()\n    needs:\n'
    for j in modules transverse selfdataguard-1 selfdataguard-2 selfrecover couplage couplage-php-anciens lab selfright; do
      printf '      - %s\n' "$j"
    done
    printf '    runs-on: x\n'
  } > "$1"
}
W="$BAC/wf.yml"
workflow "$W"
if bash "$CI" --verifier "$W" >/dev/null 2>&1; then ok "un workflow conforme passe"
else nok "un workflow conforme est refusé : $(bash "$CI" --verifier "$W" 2>&1 | head -2)"; fi

rougit() {  # <libellé> <sed> : la plantation doit faire rougir --verifier
  workflow "$W"; sed -i "$2" "$W"
  if bash "$CI" --verifier "$W" >/dev/null 2>&1; then nok "$1 : --verifier reste vert"; else ok "$1 rougit"; fi
}
rougit "un job oublié dans le job final" '/^      - lab$/d'
rougit "un job qui tourne sur la sortie d'un autre groupe" "s/outputs.selfrecover == 'true'/outputs.lab == 'true'/"
rougit "un job qui n'attend pas modules" '0,/^    needs: modules$/s//    needs: transverse/'
rougit "un transverse qui ne tourne pas toujours" "s/^  transverse:\$/  transverse:\n    if: false/"
rougit "un groupe sans job" '/^  selfright:$/,/^    runs-on: x$/d; /^      - selfright$/d'
rougit "une sortie de groupe manquante" '/^      couplage: /d'
rougit "un job hors de toute famille" 's/^  lab:$/  divers:/; s/^      - lab$/      - divers/'
rougit "un job final sans always()" 's/^    if: always()$/    if: success()/'

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
echo "$reussites propriété(s) tiennent, $echecs en échec."
[ "$echecs" -eq 0 ] || exit 1
