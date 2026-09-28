#!/usr/bin/env bash
# Le label de dérivation est-il le même à l'enrôlement, au démarrage et au secours ?
#
# 🔑 Trois scripts dérivent la clé du slot : setup-add-selfrecover-slot.sh
# (enrôlement), selfrecover-keyscript.sh (démarrage) et selfrecover-unlock.sh
# (secours). Un label changé d'un seul côté enrôle sous l'un et dérive sous
# l'autre : la machine ne s'ouvre plus au boot, et la preuve de setup-add, qui
# relit son propre label, ne le verrait pas. Le keyscript tourne dans l'initramfs
# et ne peut rien sourcer du dépôt : chaque script déclare donc le label, et ce
# banc les tient d'accord.
#
# Usage : bash tests/test_label_derivation.sh
# Sortie : 0 si les trois déclarent le même label et n'en écrivent aucun en dur.
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
MODULE="$(cd "$HERE/.." && pwd)"

echec=0
premier=""
for f in selfrecover-keyscript.sh setup-add-selfrecover-slot.sh selfrecover-unlock.sh; do
  chemin="$MODULE/$f"
  declarations=$(grep -cE '^LABEL_DERIVATION=' "$chemin")
  label=$(sed -nE 's/^LABEL_DERIVATION=([A-Za-z0-9_-]+)$/\1/p' "$chemin")
  # Un `--label` suivi d'autre chose qu'une variable est un label écrit en dur.
  en_dur=$(grep -cE -- '--label +[^"$ ]' "$chemin")
  if [ "$declarations" != 1 ] || [ -z "$label" ]; then
    printf '  ❌ %-32s %s déclaration(s) de LABEL_DERIVATION, attendu 1\n' "$f" "$declarations"
    echec=1
  elif [ "$en_dur" != 0 ]; then
    printf '  ❌ %-32s %s label(s) écrit(s) en dur après --label\n' "$f" "$en_dur"
    echec=1
  elif [ -n "$premier" ] && [ "$label" != "$premier" ]; then
    printf '  ❌ %-32s « %s », les autres « %s »\n' "$f" "$label" "$premier"
    echec=1
  else
    printf '  ✅ %-32s « %s »\n' "$f" "$label"
    premier="${premier:-$label}"
  fi
done

if [ "$echec" = 0 ]; then
  echo "✅ 3/3 — l'enrôlement, le démarrage et le secours dérivent sous le même label"
fi
exit "$echec"
