#!/bin/bash
# SelfAct — wrapper cron pour scraper.php.
#
# Ajout crontab (bimensuel 1er + 15 à 03:30 Europe/Paris, cohérent SelfJustice) :
#   30 3 1,15 * * /var/www/selfjustice/api/act/update_catalog.sh >> /var/log/selfact-catalog.log 2>&1

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

cd "$SCRIPT_DIR"

echo "---"
echo "[$(date -Iseconds)] SelfAct update_catalog start"

# Backup de l'actuel avant réécriture
if [ -f "$SCRIPT_DIR/data/catalog.json" ]; then
    cp "$SCRIPT_DIR/data/catalog.json" "$SCRIPT_DIR/data/catalog.json.bak"
fi

# Run scraper avec verbose pour log
"$PHP_BIN" "$SCRIPT_DIR/scraper.php" --verbose 2>&1 || {
    echo "[$(date -Iseconds)] scraper.php exited with $?"
    # Restaurer backup si échec
    if [ -f "$SCRIPT_DIR/data/catalog.json.bak" ]; then
        mv "$SCRIPT_DIR/data/catalog.json.bak" "$SCRIPT_DIR/data/catalog.json"
        echo "[$(date -Iseconds)] catalog.json restauré depuis backup"
    fi
    exit 1
}

# Nettoyer backup si succès
rm -f "$SCRIPT_DIR/data/catalog.json.bak"

# Log count
COUNT=$(grep -c '"id":' "$SCRIPT_DIR/data/catalog.json" || echo 0)
echo "[$(date -Iseconds)] SelfAct update_catalog done — $COUNT modèles"
