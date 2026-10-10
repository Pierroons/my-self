#!/usr/bin/env bash
#
# Les portes de comptage de la CI disent-elles encore le total de leur banc ?
#
# Un banc qui gagne des cas laisse derrière lui le littéral qui le garde. La
# porte attend alors un chiffre que le banc ne rend plus, et chaque porte est
# suivie d'un `exit 1` : le job tombe sur la première, sur un banc VERT. C'est
# le défaut qu'aucune mesure de banc ne peut montrer — elle mesure le banc, pas
# ce qui le garde.
#
# 🔑 **L'appariement se fait PAR ÉTAPE**, jamais dans l'ordre d'apparition du
# fichier : les étapes qui lancent `bash` ou `node` décalent l'ordre global d'un
# cran, et un appariement global rend alors des faux rouges.
#
# Usage : scripts/check-portes.sh [chemin du workflow]
# Sortie : 0 si chaque porte dit le total de son banc, 1 sinon.

set -uo pipefail

WORKFLOW="${1:-.github/workflows/structure.yml}"
PHP="${PHP:-php}"
# Le plafond que les bancs attendent : `memory_limit = -1` en CLI laisse une
# récursion manger la machine au lieu de nommer sa ligne.
PLAFOND="${PLAFOND:-256M}"

cd "$(dirname "$0")/.." || exit 1

if [ ! -f "$WORKFLOW" ]; then
  echo "✗ workflow introuvable : $WORKFLOW"
  exit 1
fi

echo "▸ portes de comptage de $WORKFLOW"

# Un SEUL extracteur : la première ligne porte le compte des étapes écartées,
# les suivantes les paires. Deux appels auraient divergé au premier changement.
#
# Une étape va d'un `- name:` au suivant. On ne retient que celles qui lancent
# UN seul banc — php ou shell — et portent UNE seule porte chiffrée : au-delà,
# l'appariement serait une supposition, et le contrôle l'écarte en le disant.
#
# 🔑 **« Un seul banc » se compte en FICHIERS, pas en occurrences.** Une étape
# qui lance son banc puis le relance planté pour son canari le nomme deux fois ;
# compter les occurrences écarte donc les étapes dont le banc est le mieux
# gardé. L'étape écartée le dit dans le compte de sortie, avec sa raison.
#
# ⚠️ Les bancs de `selfrecover-luks` sont du shell. Ne chercher que `php` rend
# leurs portes invisibles, et une invisible ne se distingue pas d'une absente :
# le contrôle rendrait vert sur un périmètre qu'il ne dit pas.
SORTIE=$(python3 - "$WORKFLOW" <<'PY'
import re
import sys

lignes = open(sys.argv[1]).read().split('\n')
etapes, courante = [], []
for l in lignes:
    if re.match(r'\s*- name:', l):
        etapes.append(courante)
        courante = [l]
    else:
        courante.append(l)
etapes.append(courante)

MOTIF_BANC = r'\$\((php|bash) ([a-z0-9/_.-]+\.(?:php|sh))([^)|]*)'
MOTIF_PORTE = (
    r"""grep -qE ['"]([^'"]*?"""
    r"""(?:[0-9]+/[0-9]+|[0-9]+ passés|[0-9]+ contrôle)"""
    r"""[^'"]*?)['"]"""
)

apparies, ecartees, illisibles = [], 0, 0
for et in etapes:
    txt = '\n'.join(et)
    bancs = re.findall(MOTIF_BANC, txt)
    portes = re.findall(MOTIF_PORTE, txt)
    if not portes:
        continue
    if not bancs:
        # 🔑 Une porte dont le banc n'est pas reconnaissable se COMPTE. Un
        # `continue` muet la faisait disparaître des deux totaux, et le contrôle
        # annonçait un périmètre plus large que le sien — le défaut qu'il combat
        # ailleurs. Cas connus : un banc lancé en tube plutôt qu'en `$(…)`.
        illisibles += len(portes)
        continue
    # `--profil` lance le même banc plusieurs fois, et son total dépend du
    # profil : la porte le gère elle-même, hors périmètre de ce contrôle.
    fichiers = {b[1] for b in bancs}
    if len(fichiers) == 1 and len(portes) == 1 and all('--profil' not in b[2] for b in bancs):
        apparies.append((bancs[0][0], bancs[0][1], portes[0].replace('\\$', '$')))
    else:
        ecartees += 1

print(f'{ecartees}\t{illisibles}')
for interprete, banc, motif in apparies:
    print(interprete + '\t' + banc + '\t' + motif)
PY
) || { echo "✗ l'extraction des portes a échoué"; exit 1; }

ECARTEES=$(printf '%s\n' "$SORTIE" | head -1 | cut -f1)
ILLISIBLES=$(printf '%s\n' "$SORTIE" | head -1 | cut -f2)
PAIRES=$(printf '%s\n' "$SORTIE" | tail -n +2)

NB=$(printf '%s\n' "$PAIRES" | grep -c . || true)
if [ "$NB" = 0 ]; then
  # Zéro paire n'est pas un succès : c'est un extracteur qui ne reconnaît plus
  # la forme des portes, et il rendrait vert sur un fichier entièrement cassé.
  echo "✗ aucune porte appariée — l'extracteur ne reconnaît plus la forme du workflow"
  exit 1
fi

# ⚠️ Le périmètre s'annonce en ENTIER, y compris ce qui n'a pas pu être lu : le
# motif ne reconnaît qu'une porte chiffrée en `grep -qE`, donc ni un total porté
# par une variable (`$attendu/$attendu`), ni un `grep -qx`.
echo "▸ $NB porte(s) appariée(s) par étape · $ECARTEES écartée(s) (plusieurs bancs, ou un total par profil) · $ILLISIBLES porte(s) sans banc lisible"
echo

ROUGES=0
ABSENTS=0
while IFS=$'\t' read -r interprete banc motif; do
  [ -n "$banc" ] || continue
  if [ ! -f "$banc" ]; then
    printf '  ✗ %-50s banc introuvable\n' "$banc"
    ROUGES=$((ROUGES + 1))
    continue
  fi
  # Le plafond ne concerne que PHP : c'est son `memory_limit = -1` en CLI qui
  # laisse une récursion manger la machine au lieu de nommer sa ligne.
  if [ "$interprete" = bash ]; then
    sortie=$(timeout 300 bash "$banc" 2>&1); rc_banc=$?
  else
    sortie=$(timeout 300 "$PHP" -d memory_limit="$PLAFOND" "$banc" 2>&1); rc_banc=$?
  fi
  # 🔑 **Un prérequis absent n'est pas une porte périmée.** Les bancs shell de
  # `selfrecover-luks` sortent en 1 sur « ❌ … absent » quand `cryptsetup` ou le
  # dérivateur compilé manquent. Confondre les deux ferait dire au contrôle
  # « ta porte attend un total que ton banc ne rend plus » là où le banc n'a pas
  # pu démarrer — il enverrait corriger un littéral juste.
  if [ "$rc_banc" != 0 ] && printf '%s\n' "$sortie" | grep -qE '(absent|introuvable|command not found|No such file)'; then
    printf '  • %-50s non contrôlée — %s\n' "$banc" \
      "$(printf '%s\n' "$sortie" | grep -m1 -E '(absent|introuvable|command not found|No such file)' | cut -c1-60)"
    ABSENTS=$((ABSENTS + 1))
    continue
  fi
  if printf '%s\n' "$sortie" | grep -qE "$motif"; then
    printf '  ✓ %-50s %s\n' "$banc" "$motif"
  else
    rendu=$(printf '%s\n' "$sortie" | grep -oE '(OK|ÉCHEC) — [0-9]+/[0-9]+|✅ [0-9]+/[0-9]+|[0-9]+ passés|✅ [0-9]+ contrôle[^,]*' | tail -1)
    printf '  ✗ %-50s attend « %s » · rend « %s »\n' "$banc" "$motif" "${rendu:-rien de comptable}"
    ROUGES=$((ROUGES + 1))
  fi
done <<< "$PAIRES"

echo
# Une porte non contrôlée se DIT, dans les deux verdicts : « rendre vert sur un
# périmètre qu'on ne nomme pas » est le défaut que ce contrôle combat ailleurs.
if [ "$ABSENTS" != 0 ]; then
  echo "• $ABSENTS porte(s) non contrôlée(s) : le banc n'a pas pu démarrer (prérequis absent)."
fi
if [ "$ROUGES" = 0 ]; then
  echo "✓ les $((NB - ABSENTS)) porte(s) mesurée(s) disent le total de leur banc."
  exit 0
fi

echo "✗ $ROUGES porte(s) sur $((NB - ABSENTS)) mesurée(s) attendent un total que leur banc ne rend plus."
echo "  La CI tomberait sur la première, sur un banc vert."
exit 1
