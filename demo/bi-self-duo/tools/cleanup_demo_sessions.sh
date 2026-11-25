#!/bin/bash
# Bi-Self Demo — Cleanup des sessions expirées.
#
# Supprime les sous-dossiers du dossier des sessions dont le meta.json a un
# expires_at passé. Ce dossier est celui que lit la démo :
# SELFRECOVER_SESSIONS_DIR, sinon le défaut de DemoSession::BASE_DIR_DEFAUT
# (lib/session_manager.php), recopié ici et comparé par tests/sanity_etat.php.
#
# À exécuter via cron toutes les 5 minutes, avec la même variable que le groupe
# d'ouvriers PHP de la démo :
#   */5 * * * * SELFRECOVER_SESSIONS_DIR=… /opt/selfrecover-demo/cleanup_demo_sessions.sh

set -u

BASE="${SELFRECOVER_SESSIONS_DIR:-/var/lib/selfjustice/demo-sessions}"
BASE="${BASE%/}"
NOW=$(date +%s)
LOG="${SELFRECOVER_CLEANUP_LOG:-/var/log/selfjustice-demo-cleanup.log}"

# Ce script efface tout sous-dossier sans meta.json : une variable mal posée
# (« / », un chemin relatif, un autre état) le ferait effacer ailleurs. Il ne
# travaille que sur un chemin absolu qui finit par /demo-sessions.
case "$BASE" in
    /*/demo-sessions) ;;
    *)
        echo "cleanup_demo_sessions : dossier refusé « $BASE » — un chemin absolu qui finit par /demo-sessions est attendu" >&2
        exit 2
        ;;
esac

if [ ! -d "$BASE" ]; then
    exit 0
fi

# Itère sur les UUID (dossiers au format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx)
for dir in "$BASE"/*; do
    [ -d "$dir" ] || continue
    base=$(basename "$dir")
    # Ignore les dossiers système (.counters, .banned_ips, etc.)
    case "$base" in
        .*) continue ;;
    esac
    meta="$dir/meta.json"
    if [ ! -f "$meta" ]; then
        # Pas de meta → probablement corrompu, on supprime
        rm -rf "$dir"
        echo "$(date -Iseconds) removed $base (no meta.json)" >> "$LOG"
        continue
    fi
    # Parse expires_at (une ligne JSON très simple, pas besoin de jq)
    expires=$(grep -oE '"expires_at"[[:space:]]*:[[:space:]]*[0-9]+' "$meta" | grep -oE '[0-9]+$' | head -1)
    if [ -z "$expires" ] || [ "$expires" -lt "$NOW" ]; then
        rm -rf "$dir"
        echo "$(date -Iseconds) purged $base (expired at $expires, now $NOW)" >> "$LOG"
    fi
done

# Nettoie les compteurs IP vieux de plus d'1h (ils stockent des timestamps)
COUNTERS="$BASE/.counters"
if [ -d "$COUNTERS" ]; then
    cutoff=$((NOW - 3600))
    for f in "$COUNTERS"/ip-*.log; do
        [ -f "$f" ] || continue
        # Garde uniquement les lignes dont le timestamp >= cutoff
        tmp=$(mktemp)
        awk -v cutoff="$cutoff" '$1 + 0 >= cutoff' "$f" > "$tmp"
        if [ -s "$tmp" ]; then
            mv "$tmp" "$f"
        else
            rm -f "$f" "$tmp"
        fi
    done
fi

exit 0
