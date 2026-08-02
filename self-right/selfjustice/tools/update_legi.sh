#!/bin/bash
# SelfJustice — Mise à jour bimensuelle de la base LEGI.
# Télécharge les nouveaux tarballs DILA et reconstruit la SQLite.
#
# À lancer via cron le 1er et le 15 de chaque mois :
#   0 4 1,15 * * <install-dir>/update_legi.sh

set -e

# Répertoire d'installation — surchargeable pour ne pas dépendre d'un home utilisateur.
LEGI_DIR="${SELFJUSTICE_DIR:-/opt/selfjustice}"
TARBALLS_DIR="$LEGI_DIR/tarballs"
DB_FILE="$LEGI_DIR/legi_selfjustice.sqlite"
DB_BACKUP="$LEGI_DIR/legi_selfjustice.sqlite.bak"
LOG_FILE="$LEGI_DIR/update_legi.log"
LAST_UPDATE_FILE="/var/lib/selfjustice/legi_last_update.txt"

# --- Alerte sur échec -------------------------------------------------------
#
# 🔑 **Ce script échouait correctement, et personne ne l'entendait.** De mai à
# août 2026, sept exécutions se sont arrêtées sur un `ModuleNotFoundError` : le
# `set -e` faisait son travail, le code de sortie était non nul, et rien ne le
# lisait. Le cron ne remonte rien par défaut. Trois mois de silence pendant
# lesquels le module servait une base figée.
#
# Un échec doit désormais réveiller quelqu'un. Le jeton et l'URL sont
# facultatifs : sans eux le script fonctionne comme avant, il ne prévient
# simplement personne — ce qui doit rester un choix explicite, pas un défaut.
NTFY_URL="${SELFJUSTICE_NTFY_URL:-}"
NTFY_TOKEN_FILE="${SELFJUSTICE_NTFY_TOKEN_FILE:-/root/.config/selfjustice-ntfy-token}"

alerter() {
    local titre="$1" message="$2"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERTE : $titre — $message" >> "$LOG_FILE"
    [ -n "$NTFY_URL" ] && [ -r "$NTFY_TOKEN_FILE" ] || return 0
    curl -fsS -m 10 --retry 2 \
        -H "Authorization: Bearer $(cat "$NTFY_TOKEN_FILE")" \
        -H "Title: $titre" \
        -H "Priority: high" -H "Tags: warning,scales" \
        -d "$message" "$NTFY_URL" > /dev/null 2>&1 || true
}

trap 'alerter "SelfJustice — sync LEGI en echec" "Arret ligne $LINENO. Base inchangee, voir $LOG_FILE."' ERR

cd "$LEGI_DIR"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Début mise à jour LEGI ===" >> "$LOG_FILE"

# 1. Sauvegarder la base actuelle
if [ -f "$DB_FILE" ]; then
    cp "$DB_FILE" "$DB_BACKUP"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup créé : $DB_BACKUP" >> "$LOG_FILE"
fi

# 2. Télécharger les nouveaux tarballs (incrémental)
source "$LEGI_DIR/legi.py/venv/bin/activate"
python -m legi.download "$TARBALLS_DIR" >> "$LOG_FILE" 2>&1

# 3. Trouver le dernier tarball Freemium global (le plus récent)
LATEST_GLOBAL=$(ls -t "$TARBALLS_DIR"/Freemium_legi_global_*.tar.gz 2>/dev/null | head -1)

if [ -z "$LATEST_GLOBAL" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : aucun tarball global trouvé" >> "$LOG_FILE"
    exit 1
fi

# 4. Reconstruire la base avec notre script maison
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reconstruction SQLite depuis $LATEST_GLOBAL" >> "$LOG_FILE"
python3 "$LEGI_DIR/build_legi_db.py" --tarball "$LATEST_GLOBAL" --db "$DB_FILE" >> "$LOG_FILE" 2>&1

# 5. Vérifier que la base est valide
NB_ARTICLES=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo 0)

if [ "$NB_ARTICLES" -lt 100000 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERREUR : base trop petite ($NB_ARTICLES articles), restauration backup" >> "$LOG_FILE"
    cp "$DB_BACKUP" "$DB_FILE"
    exit 1
fi

# 6. Marquer la date de mise à jour — date du dump DILA le plus récent
#
# 🔑 **Deux dates, et seule la seconde compte.** Que ce script tourne à l'heure
# ne dit rien de la fraîcheur du droit qu'il sert : de mai à août 2026 le cron a
# été ponctuel tous les 1er et 15 pendant que la base restait au 13 juillet 2025.
# Un contrôle portant sur la date d'exécution aurait donné son feu vert tout
# l'été. Celui qui suit porte sur le CONTENU.
sudo mkdir -p /var/lib/selfjustice
# Extraire la date depuis le nom du tarball global le plus récent
LATEST_DATE_RAW=$(ls -t "$TARBALLS_DIR"/Freemium_legi_global_*.tar.gz 2>/dev/null | head -1 | sed 's/.*global_\([0-9]\{8\}\).*/\1/')
if [ -n "$LATEST_DATE_RAW" ]; then
    YYYY=${LATEST_DATE_RAW:0:4}
    MM=${LATEST_DATE_RAW:4:2}
    DD=${LATEST_DATE_RAW:6:2}
    MONTH_NAMES=(janvier février mars avril mai juin juillet août septembre octobre novembre décembre)
    MONTH_IDX=$((10#$MM - 1))
    DATE_FR="$((10#$DD)) ${MONTH_NAMES[$MONTH_IDX]} $YYYY"
else
    DATE_FR=$(date '+%-d %B %Y' | sed 's/January/janvier/; s/February/février/; s/March/mars/; s/April/avril/; s/May/mai/; s/June/juin/; s/July/juillet/; s/August/août/; s/September/septembre/; s/October/octobre/; s/November/novembre/; s/December/décembre/')
fi
echo "$DATE_FR" | sudo tee "$LAST_UPDATE_FILE" > /dev/null

# Le contenu est-il plus vieux que la dernière échéance attendue (1er ou 15) ?
if [ -n "${LATEST_DATE_RAW:-}" ]; then
    JOUR=$(date +%-d)
    if [ "$JOUR" -ge 15 ]; then
        ECHEANCE=$(date +%Y%m15)
    else
        ECHEANCE=$(date -d "$(date +%Y-%m-01) -1 month +14 days" +%Y%m%d)
    fi
    if [ "$LATEST_DATE_RAW" -lt "$ECHEANCE" ]; then
        JOURS=$(( ( $(date -d "$(date +%Y-%m-%d)" +%s) - $(date -d "${LATEST_DATE_RAW:0:4}-${LATEST_DATE_RAW:4:2}-${LATEST_DATE_RAW:6:2}" +%s) ) / 86400 ))
        alerter "SelfJustice — base LEGI perimee" \
                "Le dump le plus recent date du $DATE_FR, soit $JOURS jours de retard. La synchronisation tourne, mais DILA ne fournit pas de dump plus frais ou les diffs ne sont pas appliques."
    fi
fi

# 7. Reconstruire la base de conventionnalité (UE + CEDH)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Reconstruction base de conventionnalité (EUR-Lex + CEDH)" >> "$LOG_FILE"
python3 "$LEGI_DIR/build_eu_db.py" --db "$LEGI_DIR/conventionnalite.sqlite" >> "$LOG_FILE" 2>&1
EU_ARTICLES=$(sqlite3 "$LEGI_DIR/conventionnalite.sqlite" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo 0)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Base de conventionnalité : $EU_ARTICLES articles" >> "$LOG_FILE"
echo "$DATE_FR" | sudo tee /var/lib/selfjustice/eu_last_update.txt > /dev/null

# 8. Lancer une mise à jour des stats pour propager à la page
"$LEGI_DIR/update_stats.sh" >> "$LOG_FILE" 2>&1

# 9. Nettoyer les vieux tarballs (garder uniquement le global le plus récent + les 30 derniers diffs)
ls -t "$TARBALLS_DIR"/LEGI_*.tar.gz 2>/dev/null | tail -n +31 | xargs -r rm -f
ls -t "$TARBALLS_DIR"/Freemium_legi_global_*.tar.gz 2>/dev/null | tail -n +2 | xargs -r rm -f

DISK_USAGE=$(du -sh "$TARBALLS_DIR" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Mise à jour réussie : $NB_ARTICLES articles, espace tarballs: $DISK_USAGE" >> "$LOG_FILE"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] === Fin mise à jour LEGI ===" >> "$LOG_FILE"
