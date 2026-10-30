#!/bin/bash
# Banc de `check-canaris.sh` — ce que devient l'arbre quand un canari ne rend
# pas ce qu'il a pris.
#
# 🔑 **Pourquoi ce banc existe.** Un canari plante un défaut dans un fichier du
# dépôt et le restaure lui-même. Coupé, arrêté sur son délai ou simplement
# oublieux, il laisse le défaut dans l'arbre de travail de quelqu'un — et un
# canari vert ne dit rien de ce qu'il a laissé.
#
# Chaque cas monte un dépôt jetable : le script sous test, un fichier suivi que
# le développeur a modifié sans le commiter, et un workflow à un seul canari. La
# propriété tenue est toujours la même — l'arbre revient tel que le développeur
# l'avait laissé, travail non commité compris, ou le script dit pourquoi il ne
# peut pas. Les témoins prouvent que le banc voit encore un rejeu sain.
#
# Usage : bash scripts/tests/test_check_canaris.sh
#   CHECK_CANARIS_SOUS_TEST=<fichier> rejoue le banc contre une autre version du
#   script — c'est ainsi qu'un cas se voit rougir, par exemple sur la version
#   d'avant la photo de l'arbre :
#   git show 4f46559:scripts/check-canaris.sh > /tmp/ancien
#   CHECK_CANARIS_SOUS_TEST=/tmp/ancien bash scripts/tests/test_check_canaris.sh
# Sortie : 0 si toutes les propriétés tiennent.

set -uo pipefail

ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(cd "$ICI/../.." && pwd)"
SOUS_TEST="${CHECK_CANARIS_SOUS_TEST:-$RACINE/scripts/check-canaris.sh}"
[ -r "$SOUS_TEST" ] || { echo "script introuvable : $SOUS_TEST" >&2; exit 1; }

BAC=$(mktemp -d)
trap 'rm -rf "$BAC"' EXIT

echecs=0 reussites=0
ok()  { echo "  ✓ $1"; reussites=$((reussites + 1)); }
nok() { echo "  ✗ $1" >&2; echecs=$((echecs + 1)); }

TRAVAIL='WHERE s.vu_le IS NULL -- en cours'

depot() {  # <nom> : un dépôt jetable, le travail non commité du développeur posé
  local d="$BAC/$1"
  mkdir -p "$d/scripts" "$d/src"
  cp "$SOUS_TEST" "$d/scripts/check-canaris.sh"
  printf 'WHERE s.vu_le IS NULL\n' > "$d/src/m.php"
  printf 'b\n' > "$d/src/b.txt"
  git -C "$d" init -q
  git -C "$d" add -A
  git -C "$d" -c user.name=banc -c user.email=banc@example.invalid -c commit.gpgsign=false \
      -c core.hooksPath=/dev/null commit -qm base
  printf '%s\n' "$TRAVAIL" > "$d/src/m.php"
  D="$d"
}

canari() {  # <dépôt> <corps du run:> — chaque corps plante par `sed -i`, sinon le volet statique le refuse
  mkdir -p "$1/.github/workflows"
  { printf 'jobs:\n  banc:\n    steps:\n      - name: Canari — banc\n        run: |\n          # @plante\n'
    printf '%s\n' "$2" | sed 's/^/          /'
  } > "$1/.github/workflows/structure.yml"
}

rejouer() {  # <dépôt> [arguments] : rejeu au premier plan, borné ; RC et SORTIE
  local d="$1"; shift
  RC=0
  SORTIE="$(cd "$d" && timeout -s KILL 40 bash scripts/check-canaris.sh "$@" 2>&1)" || RC=$?
}

en_fond() {  # <dépôt> : rejeu dans sa propre session, PID = son groupe
  ( cd "$1" && exec setsid bash scripts/check-canaris.sh > "$1.sortie" 2>&1 ) &
  PID=$!
}

attendre() {  # <fichier> : le canari a-t-il atteint son témoin ?
  local _
  for _ in $(seq 100); do [ -e "$1" ] && return 0; sleep 0.1; done
  return 1
}

coupure() {  # tue net le rejeu ET son canari, comme un arrêt de la machine
  local c
  c=$(pgrep -P "$PID" | head -n 1)
  kill -KILL -- "-$PID" 2>/dev/null
  [ -n "$c" ] && kill -KILL -- "-$c" 2>/dev/null
  wait "$PID" 2>/dev/null
}

travail_rendu() { [ "$(cat "$1/src/m.php")" = "$TRAVAIL" ]; }

# Les corps de canari s'écrivent entre apostrophes : c'est le canari qui développe
# leurs variables, pas ce banc.
# shellcheck disable=SC2016
PLANTE_ET_ATTEND='sed -i "s/IS NULL/IS NOT NULL/" src/m.php
touch "$BANC_TEMOIN"
sleep 8'

# ── 1. Un canari vert qui laisse des fichiers changés ────────────────────────
#
# Le canari sort en 0 sans rien restaurer : un fichier planté, un fichier créé,
# un fichier supprimé. Un rejeu qui ne juge que le code de sortie le compte vert
# et rend l'arbre planté.
echo "▸ 1. un canari vert qui laisse trois fichiers changés"
depot residu
canari "$D" 'sed -i "s/IS NULL/IS NOT NULL/" src/m.php
echo neuf > src/neuf.txt
rm src/b.txt'
rejouer "$D"
if travail_rendu "$D" && [ -f "$D/src/b.txt" ] && [ ! -e "$D/src/neuf.txt" ]; then
    ok "les trois sont rétablis, le travail non commité revient tel quel"
else
    nok "l'arbre reste changé : m.php « $(cat "$D/src/m.php") », b.txt $([ -f "$D/src/b.txt" ] && echo présent || echo absent), neuf.txt $([ -e "$D/src/neuf.txt" ] && echo présent || echo absent)"
fi
if [ "$RC" -eq 1 ] && printf '%s\n' "$SORTIE" | grep -q 'a laissé'; then
    ok "le canari est compté rouge et ce qu'il a laissé est nommé"
else
    nok "le rejeu rend $RC sans nommer le résidu — un canari qui salit l'arbre passe pour vert"
fi

# ── 2. Un canari qui dépasse le délai ────────────────────────────────────────
#
# Le canari plante, lance un processus qui écrira plus tard, puis s'endort. À
# l'expiration, tout ce qu'il a lancé doit mourir avec lui — un banc PHP qui
# survivrait continuerait d'écrire — et l'arbre doit être rétabli.
echo "▸ 2. un canari qui dépasse le délai"
depot delai
canari "$D" 'sed -i "s/IS NULL/IS NOT NULL/" src/m.php
( sleep 3; echo tard > src/tard.txt ) &
sleep 20'
CHECK_CANARIS_DELAI=1 rejouer "$D"
sleep 4
if travail_rendu "$D"; then
    ok "le fichier planté est rétabli après le délai"
else
    nok "le fichier reste planté après le délai : « $(cat "$D/src/m.php") »"
fi
if [ ! -e "$D/src/tard.txt" ]; then
    ok "ce que le canari avait lancé est mort avec lui"
else
    nok "un processus lancé par le canari a écrit après son arrêt"
fi

# ── 3. Ctrl-C ────────────────────────────────────────────────────────────────
echo "▸ 3. Ctrl-C pendant un canari"
depot ctrlc
canari "$D" "$PLANTE_ET_ATTEND"
export BANC_TEMOIN="$BAC/temoin-3"
en_fond "$D"
attendre "$BANC_TEMOIN"
kill -INT -- "-$PID"
wait "$PID"; RC=$?
if travail_rendu "$D"; then
    ok "rendu à la version du développeur, pas à HEAD (code $RC)"
else
    nok "le fichier reste « $(cat "$D/src/m.php") » après Ctrl-C (code $RC)"
fi

# ── 4. SIGTERM adressé au script ─────────────────────────────────────────────
#
# Ce que fait `timeout`, ou un `kill <pid>` : le signal frappe le processus
# lancé, pas son groupe. Il doit atteindre le code qui sait rétablir l'arbre.
echo "▸ 4. SIGTERM adressé au script"
depot sigterm
canari "$D" "$PLANTE_ET_ATTEND"
export BANC_TEMOIN="$BAC/temoin-4"
en_fond "$D"
attendre "$BANC_TEMOIN"
kill -TERM "$PID"
wait "$PID"; RC=$?
sleep 1
if travail_rendu "$D"; then
    ok "rendu à la version du développeur (code $RC)"
else
    nok "le fichier reste « $(cat "$D/src/m.php") » après SIGTERM (code $RC)"
fi

# ── 5. Coupure nette, puis relance ───────────────────────────────────────────
#
# Contre un arrêt de la machine, rien ne s'exécute : c'est le lancement suivant
# qui doit parler. Il nomme ce qui a été laissé et ne touche à rien — le
# développeur a pu modifier ces fichiers depuis.
echo "▸ 5. coupure nette pendant un canari, puis relance"
depot coupure
canari "$D" "$PLANTE_ET_ATTEND"
export BANC_TEMOIN="$BAC/temoin-5"
en_fond "$D"
attendre "$BANC_TEMOIN"
coupure
export BANC_TEMOIN="$BAC/temoin-5-relance"
rejouer "$D"
if [ "$RC" -eq 1 ] && printf '%s\n' "$SORTIE" | grep -q 'src/m.php' \
   && printf '%s\n' "$SORTIE" | grep -q 'Canari — banc'; then
    ok "la relance refuse, nomme le fichier et le canari qui tournait"
else
    nok "la relance rend $RC sans nommer ce que la coupure a laissé"
fi
if [ ! -e "$BANC_TEMOIN" ] && grep -q 'IS NOT NULL' "$D/src/m.php"; then
    ok "rien n'est rejoué ni rétabli à sa place"
else
    nok "la relance a rejoué ou rétabli sans décision humaine"
fi
rejouer "$D" --abandonner
if [ "$RC" -eq 0 ] && [ -z "$(git -C "$D" for-each-ref refs/worktree/check-canaris/)" ] \
   && [ ! -e "$(git -C "$D" rev-parse --path-format=absolute --git-path check-canaris)/journal.json" ]; then
    ok "--abandonner lève le journal et les références"
else
    nok "--abandonner rend $RC ou laisse un état derrière lui"
fi

# ── 6. Deux rejeux dans le même arbre ────────────────────────────────────────
echo "▸ 6. un second rejeu dans le même arbre"
depot double
canari "$D" "$PLANTE_ET_ATTEND"
export BANC_TEMOIN="$BAC/temoin-6"
en_fond "$D"
attendre "$BANC_TEMOIN"
rejouer "$D"
if [ "$RC" -eq 1 ] && printf '%s\n' "$SORTIE" | grep -q 'tourne déjà'; then
    ok "le second est refusé"
else
    nok "le second rend $RC sans refuser — deux rejeux se disputent le même arbre"
fi
coupure

# ── Témoins ──────────────────────────────────────────────────────────────────
#
# Un banc dont tous les cas rougissent ne prouve rien s'il rougit aussi sur un
# rejeu sain : ces témoins-là doivent passer sur toutes les versions du script.
echo "▸ témoin — un canari qui se restaure lui-même, sur un arbre modifié"
depot sain
# shellcheck disable=SC2016
CORRECT='cp src/m.php /tmp/M.intact
sed -i "s/IS NULL/IS NOT NULL/" src/m.php
grep -q "IS NOT NULL" src/m.php
touch "$BANC_TEMOIN"
cp /tmp/M.intact src/m.php'
canari "$D" "$CORRECT"
export BANC_TEMOIN="$BAC/temoin-7"
avant=$(git -C "$D" status --porcelain)
rejouer "$D"
if [ "$RC" -eq 0 ] && [ "$(git -C "$D" status --porcelain)" = "$avant" ] \
   && [ -z "$(git -C "$D" for-each-ref refs/worktree/check-canaris/)" ]; then
    ok "vert, arbre identique, aucune référence laissée"
else
    nok "un rejeu sain rend $RC ou change l'arbre"
fi

echo "▸ témoin — une coupure avant toute plantation, puis relance"
depot propre
# Le même workflow aux deux lancements : le modifier entre les deux serait, à
# juste titre, un changement depuis la photo. La pause ne dépend que de BANC_PAUSE.
# shellcheck disable=SC2016
canari "$D" '[ -z "${BANC_PAUSE:-}" ] || { touch "$BANC_TEMOIN"; sleep 8; }
'"$CORRECT"
export BANC_TEMOIN="$BAC/temoin-8" BANC_PAUSE=1
en_fond "$D"
attendre "$BANC_TEMOIN"
coupure
export BANC_TEMOIN="$BAC/temoin-8-relance" BANC_PAUSE=
rejouer "$D"
if [ "$RC" -eq 0 ] && [ -e "$BANC_TEMOIN" ] && travail_rendu "$D"; then
    ok "rien n'avait été laissé : la relance rejoue"
else
    nok "la relance rend $RC alors que la coupure n'avait rien laissé"
fi

echo "▸ témoin — deux rejeux dans deux arbres du même dépôt"
depot arbres
canari "$D" "$CORRECT"
git -C "$D" worktree add -q --detach "$BAC/arbres-bis"
canari "$BAC/arbres-bis" "$CORRECT"
export BANC_TEMOIN="$BAC/temoin-9"
en_fond "$D"
rejouer "$BAC/arbres-bis"
wait "$PID"; rc_fond=$?
if [ "$RC" -eq 0 ] && [ "$rc_fond" -eq 0 ]; then
    ok "les deux passent, chacun dans son arbre"
else
    nok "deux arbres du même dépôt se bloquent : $rc_fond et $RC"
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
echo
echo "$reussites propriété(s) tiennent, $echecs en échec."
[ "$echecs" -eq 0 ] || exit 1
