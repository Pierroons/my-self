#!/usr/bin/env bash
# Lancement DÉMO local de SelfRecover (verbeux, timings courts, purge auto).
# ⚠️ NE JAMAIS utiliser tel quel en prod : ces réglages sont volontairement permissifs.
# La prod tourne SANS ces variables (secure-by-default) — voir .env.example / INSTALL-PROD.md.
set -euo pipefail
DEMO="$(cd "$(dirname "$0")" && pwd)"

export SELFRECOVER_DEBUG=true          # expose _trace dans les réponses (debug)
export SELFRECOVER_DEMO_PURGE=true     # purge auto des vieux comptes (borne la DB de test)
export SELFRECOVER_FAST_TIMINGS=true   # blocages à 30 s au lieu de 15 min / 1 h / 24 h

echo "SelfRecover DÉMO → http://127.0.0.1:8080  (DEBUG on, timings courts)"
echo "CLI SU démo :  SELFRECOVER_SU_SECRET=demo-su-secret php $DEMO/selfrecover-su <cmd>"
exec php -S 127.0.0.1:8080 -t "$DEMO" "$DEMO/router.php"
