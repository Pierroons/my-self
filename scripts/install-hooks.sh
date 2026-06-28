#!/usr/bin/env bash
# Active la protection OPSEC locale (hook pre-commit gitleaks).
# À lancer une fois après clonage : ./scripts/install-hooks.sh
set -euo pipefail
ROOT="$(git rev-parse --show-toplevel)"
chmod +x "$ROOT/scripts/git-hooks/pre-commit"
ln -sf ../../scripts/git-hooks/pre-commit "$ROOT/.git/hooks/pre-commit"
echo "✓ Hook pre-commit OPSEC installé (gitleaks)."
command -v gitleaks >/dev/null 2>&1 || echo "⚠ Pense à installer gitleaks : sudo apt install gitleaks"
