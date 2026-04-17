#!/bin/bash
# SelfJustice — Purge les uploads feedback > 30 jours.
#
# Le dossier /var/lib/selfjustice/feedback/ contient des sous-dossiers nommés
# YYYYMMDD-HHMMSS-XXXXXX/ avec le document uploadé + meta.json + comment.txt.
#
# À exécuter via cron quotidien :
#   5 4 * * * /home/zelda/legi/cleanup_feedback.sh

set -u

BASE="/var/lib/selfjustice/feedback"
DAYS=30

if [ ! -d "$BASE" ]; then
    exit 0
fi

# Supprime les sous-dossiers modifiés il y a plus de DAYS jours
find "$BASE" -mindepth 1 -maxdepth 1 -type d -mtime +$DAYS -exec rm -rf {} \; 2>/dev/null

exit 0
