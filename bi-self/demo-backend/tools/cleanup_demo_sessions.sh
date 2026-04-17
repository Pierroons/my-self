#!/bin/bash
# Bi-Self Demo — Cleanup des sessions expirées.
#
# Supprime les sous-dossiers de /var/lib/selfjustice/demo-sessions/
# dont le meta.json a un expires_at passé.
#
# À exécuter via cron toutes les 5 minutes :
#   */5 * * * * /home/zelda/legi/cleanup_demo_sessions.sh

set -u

BASE="/var/lib/selfjustice/demo-sessions"
NOW=$(date +%s)
LOG="/var/log/selfjustice-demo-cleanup.log"

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
