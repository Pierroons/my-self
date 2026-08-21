#!/bin/bash
# SelfJustice — rafraîchissement de l'index de jurisprudence Judilibre.
#
# À lancer le 1er et le 15, comme les bases LEGI et conventionnalité :
#   0 5 1,15 * * <install-dir>/update_judilibre.sh
#
# L'index répond « ce numéro de décision existe » sans appeler de service
# tiers. Une décision qui y manque ressort « introuvable » : un index en retard
# fait donc passer de vrais arrêts pour inexistants.
#
# Le mode `--depuis auto` repart trente jours avant la décision la plus récente
# connue, pas au lendemain : Judilibre publie avec du retard, une décision de
# juillet peut apparaître en août. Sans ce recouvrement, le trou laissé serait
# définitif — aucune exécution ultérieure ne repasserait dessus.

set -e

INSTALL_DIR="${SELFJUSTICE_DIR:-/opt/selfjustice}"
DB_DIR="${SELFJUSTICE_DB_DIR:-/var/lib/selfjustice/db}"

export JUDILIBRE_DB="${JUDILIBRE_DB:-$DB_DIR/judilibre_index.sqlite}"
export JUDILIBRE_MARQUEUR="${JUDILIBRE_MARQUEUR:-/var/lib/selfjustice/judilibre_last_update.txt}"
# La clé vit dans /etc, pas dans /root : l'unité systemd applique
# `ProtectHome=true`, qui rend les répertoires personnels — celui de root
# compris — invisibles au service. L'échec ne se voit qu'à la première
# exécution réelle.
export JUDILIBRE_KEY_FILE="${JUDILIBRE_KEY_FILE:-/etc/selfjustice/judilibre.key}"

# Le moissonneur est cherché à côté de ce script, pas à un chemin fixe : le
# dépôt le range dans tools/, l'installation serveur dans bin/, et coder l'un
# des deux casserait l'autre.
SCRIPT="$(dirname "$(readlink -f "$0")")/build_judilibre_index.py"
LOG_FILE="${SELFJUSTICE_LOG:-$INSTALL_DIR/update_judilibre.log}"
SAUVEGARDE="$JUDILIBRE_DB.bak"

# --- Alerte sur échec -------------------------------------------------------
#
# Sept exécutions de la sync LEGI ont échoué proprement de mai à août 2026
# sans que personne ne l'entende.
NTFY_URL="${SELFJUSTICE_NTFY_URL:-}"
NTFY_TOKEN_FILE="${SELFJUSTICE_NTFY_TOKEN_FILE:-/root/.config/selfjustice-ntfy-token}"

journal() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

alerter() {
    local titre="$1" message="$2"
    journal "ALERTE : $titre — $message"
    [ -n "$NTFY_URL" ] && [ -r "$NTFY_TOKEN_FILE" ] || return 0
    curl -fsS -m 10 --retry 2 \
        -H "Authorization: Bearer $(cat "$NTFY_TOKEN_FILE")" \
        -H "Title: $titre" \
        -H "Priority: high" -H "Tags: warning,scales" \
        -d "$message" "$NTFY_URL" > /dev/null 2>&1 || true
}

trap 'alerter "SelfJustice — index Judilibre en echec" "Arret ligne $LINENO. Index precedent restaure, voir $LOG_FILE."' ERR

journal "=== Début rafraîchissement Judilibre ==="

[ -r "$JUDILIBRE_KEY_FILE" ] || {
    alerter "SelfJustice — cle Judilibre illisible" "Fichier $JUDILIBRE_KEY_FILE absent ou sans droits."
    exit 1
}

# Sauvegarde avant écriture : le script moissonne en place, et un index
# partiellement réécrit est pire qu'un index en retard — il nierait des arrêts.
if [ -f "$JUDILIBRE_DB" ]; then
    cp "$JUDILIBRE_DB" "$SAUVEGARDE"
    journal "Sauvegarde : $SAUVEGARDE"
fi

avant=$(sqlite3 "$JUDILIBRE_DB" "SELECT COUNT(*) FROM decisions" 2>/dev/null || echo 0)

if ! python3 "$SCRIPT" --depuis auto >> "$LOG_FILE" 2>&1; then
    if [ -f "$SAUVEGARDE" ]; then
        mv "$SAUVEGARDE" "$JUDILIBRE_DB"
        journal "Moisson incomplète — index précédent restauré."
    fi
    alerter "SelfJustice — moisson Judilibre incomplete" \
            "L'index a ete restaure dans son etat precedent. Voir $LOG_FILE."
    exit 1
fi

apres=$(sqlite3 "$JUDILIBRE_DB" "SELECT COUNT(*) FROM decisions")
journal "Décisions : $avant → $apres (+$((apres - avant)))"

# Un rafraîchissement qui n'apporte rien n'est pas une réussite : soit la source
# est muette, soit la fenêtre est mal calculée. Dans les deux cas ça se signale,
# sinon l'index se fige sans que la date cesse d'avancer.
if [ "$apres" -le "$avant" ]; then
    alerter "SelfJustice — index Judilibre sans nouveaute" \
            "Aucune decision ajoutee ($avant inchange). Verifier la fenetre de reprise."
fi

rm -f "$SAUVEGARDE"

# 🔑 Ce marqueur ne dit qu'une chose : la synchronisation a réussi ce jour-là.
# Il ne se confond pas avec `judilibre_last_update.txt`, qui porte la fenêtre
# moissonnée. Le client a besoin des deux pour distinguer un cron mort d'un
# amont silencieux — sans quoi il crie au retard sur une base à jour, ce qu'il
# a fait sur LEGI jusqu'au 21/08/2026.
date +%F > "${JUDILIBRE_MARQUEUR%_last_update.txt}_last_sync.txt"

journal "=== Terminé ==="
