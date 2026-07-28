#!/usr/bin/env bash
# Active la protection OPSEC locale (hook pre-commit gitleaks).
# À lancer une fois après clonage : ./scripts/install-hooks.sh
set -euo pipefail
ROOT="$(git rev-parse --show-toplevel)"
chmod +x "$ROOT/scripts/git-hooks/pre-commit"
ln -sf ../../scripts/git-hooks/pre-commit "$ROOT/.git/hooks/pre-commit"
chmod +x "$ROOT/scripts/git-hooks/pre-push"
ln -sf ../../scripts/git-hooks/pre-push "$ROOT/.git/hooks/pre-push"
echo "✓ Hooks OPSEC installés :"
echo "    pre-commit — le diff en cours (rapide)"
echo "    pre-push   — l'historique complet + métadonnées des images"
echo "                 (le pre-commit est aveugle à ce qui dort déjà dans le dépôt)"

# ⚠️ Le paquet Debian est figé sur une version ancienne, qui n'interprète pas
# les allowlists comme les récentes : local et CI divergeraient silencieusement.
command -v gitleaks >/dev/null 2>&1 \
  || echo "⚠ gitleaks manquant — installe le binaire officiel (PAS le paquet apt) : https://github.com/gitleaks/gitleaks/releases"
command -v exiftool >/dev/null 2>&1 \
  || echo "⚠ exiftool manquant — sudo apt install libimage-exiftool-perl (métadonnées d'images non vérifiées)"
