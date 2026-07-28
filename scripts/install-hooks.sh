#!/usr/bin/env bash
# Active la protection OPSEC locale.
# À lancer une fois après clonage : ./scripts/install-hooks.sh
set -euo pipefail
ROOT="$(git rev-parse --show-toplevel)"

for h in pre-commit commit-msg pre-push; do
  chmod +x "$ROOT/scripts/git-hooks/$h"
  ln -sf "../../scripts/git-hooks/$h" "$ROOT/.git/hooks/$h"
done
chmod +x "$ROOT/scripts/audit-opsec.sh"

echo "✓ Hooks OPSEC installés — trois barrières, du moins cher au plus cher :"
echo "    pre-commit — fichiers indexés. Rien n'est commité : correction en 10 s."
echo "    commit-msg — le message. Aucun autre outil ne le lit, et il survit"
echo "                 à toutes les corrections de contenu."
echo "    pre-push   — l'historique complet, les orphelins, les métadonnées."
echo "                 Dernier moment où l'erreur reste réversible sans ticket."

PATTERNS="${SELFOPSEC_PATTERNS:-$HOME/.config/selfopsec/patterns.txt}"
if [ ! -f "$PATTERNS" ]; then
  echo ""
  echo "⚠ Liste de motifs absente : $PATTERNS"
  echo "  Sans elle, les hooks ne cherchent que des secrets — pas les données"
  echo "  personnelles. Un motif par ligne (noms, communes, IP internes,"
  echo "  usernames système, domaines privés). Ce fichier vit HORS du dépôt :"
  echo "  l'écrire dans le dépôt publierait exactement ce qu'il protège."
  echo ""
  echo "    mkdir -p \"$(dirname "$PATTERNS")\" && \$EDITOR \"$PATTERNS\""
fi

# ⚠️ Le paquet Debian est figé sur une version ancienne, qui n'interprète pas
# les allowlists comme les récentes : local et CI divergeraient silencieusement.
command -v gitleaks >/dev/null 2>&1 \
  || echo "⚠ gitleaks manquant — installe le binaire officiel (PAS le paquet apt) : https://github.com/gitleaks/gitleaks/releases"
command -v exiftool >/dev/null 2>&1 \
  || echo "⚠ exiftool manquant — sudo apt install libimage-exiftool-perl (métadonnées non vérifiées)"
