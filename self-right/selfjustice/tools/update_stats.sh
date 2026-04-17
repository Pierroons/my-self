#!/bin/bash
# SelfJustice — Mise à jour des statistiques de la page d'accueil.
# Compte les requêtes IA dans les logs nginx et met à jour le compteur dans index.html.
#
# À lancer via cron toutes les heures :
#   0 * * * * /home/zelda/legi/update_stats.sh

set -e

LOG_FILE="/var/log/nginx/selfjustice-access.log"
LOG_FILE_OLD="/var/log/nginx/selfjustice-access.log.1"
COUNTER_FILE="/var/lib/selfjustice/counter.txt"
HTML_FILE="/var/www/selfjustice/index.html"
LEGI_DB="/home/zelda/legi/legi_selfjustice.sqlite"
LEGI_LAST_UPDATE_FILE="/var/lib/selfjustice/legi_last_update.txt"
EU_DB="/home/zelda/legi/conventionnalite.sqlite"
EU_LAST_UPDATE_FILE="/var/lib/selfjustice/eu_last_update.txt"

# Créer le dossier de stockage si besoin
sudo mkdir -p /var/lib/selfjustice
sudo chown zelda:zelda /var/lib/selfjustice 2>/dev/null || true

# ============================================================
# 1. Compter les requêtes IA dans les logs
# ============================================================

# User-Agents qui correspondent à des fetches d'IA (et non des visiteurs humains)
AI_PATTERNS='(Claude-User|Claude-Web|claudebot|anthropic|ChatGPT|GPTBot|OAI-SearchBot|MistralAI|Mistral-Bot|Google-Extended|GoogleOther|GeminiBot|PerplexityBot|Perplexity-User|Bytespider|YouBot|DuckAssistBot)'

# Compter les hits actuels dans les logs (incluant rotation)
HITS_NOW=0
if [ -f "$LOG_FILE" ]; then
    HITS_NOW=$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE" 2>/dev/null || echo 0)
fi

# Si log rotation active, ajouter aussi l'ancien log (du jour)
HITS_OLD=0
if [ -f "$LOG_FILE_OLD" ]; then
    HITS_OLD=$(sudo grep -ciE "$AI_PATTERNS" "$LOG_FILE_OLD" 2>/dev/null || echo 0)
fi

CURRENT_HITS=$((HITS_NOW + HITS_OLD))

# Lire le compteur historique (cumul depuis le début)
PREVIOUS_TOTAL=0
if [ -f "$COUNTER_FILE" ]; then
    PREVIOUS_TOTAL=$(cat "$COUNTER_FILE")
fi

# Lire le dernier hit count enregistré (pour calculer le delta avant rotation)
LAST_HITS_FILE="/var/lib/selfjustice/last_hits.txt"
LAST_HITS=0
if [ -f "$LAST_HITS_FILE" ]; then
    LAST_HITS=$(cat "$LAST_HITS_FILE")
fi

# Si CURRENT_HITS < LAST_HITS = rotation des logs → on ajoute les hits oubliés
if [ "$CURRENT_HITS" -lt "$LAST_HITS" ]; then
    # On considère que LAST_HITS représentait le total du jour précédent
    PREVIOUS_TOTAL=$((PREVIOUS_TOTAL + LAST_HITS))
fi

# Total = historique + hits du jour actuel
TOTAL_HITS=$((PREVIOUS_TOTAL + CURRENT_HITS))

# Sauvegarder pour la prochaine exécution
echo "$PREVIOUS_TOTAL" > "$COUNTER_FILE"
echo "$CURRENT_HITS" > "$LAST_HITS_FILE"

# ============================================================
# 2. Récupérer la date de dernière mise à jour LEGI
# ============================================================

LEGI_UPDATE_DATE="15 avril 2026"
LEGI_ARTICLES="488 903"

if [ -f "$LEGI_LAST_UPDATE_FILE" ]; then
    LEGI_UPDATE_DATE=$(cat "$LEGI_LAST_UPDATE_FILE")
fi

if [ -f "$LEGI_DB" ]; then
    NB_ARTICLES=$(sqlite3 "$LEGI_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo "488903")
    # Formater avec espaces fines comme séparateurs de milliers
    LEGI_ARTICLES=$(printf "%'d" "$NB_ARTICLES" | tr ',' ' ')
fi

# Base UE/CEDH
EU_UPDATE_DATE="16 avril 2026"
EU_ARTICLES="1 200"
if [ -f "$EU_LAST_UPDATE_FILE" ]; then
    EU_UPDATE_DATE=$(cat "$EU_LAST_UPDATE_FILE")
fi
if [ -f "$EU_DB" ]; then
    NB_EU=$(sqlite3 "$EU_DB" "SELECT COUNT(*) FROM articles" 2>/dev/null || echo "1200")
    EU_ARTICLES=$(printf "%'d" "$NB_EU" | tr ',' ' ')
fi

# ============================================================
# 3. Mettre à jour le HTML
# ============================================================

if [ ! -f "$HTML_FILE" ]; then
    echo "ERREUR : index.html introuvable : $HTML_FILE" >&2
    exit 1
fi

TMP_HTML="/tmp/selfjustice-stats-update.html"
sudo cp "$HTML_FILE" "$TMP_HTML"

# Remplacer les valeurs dans le HTML
# Format : <span id="header-counter">XXX</span>
sudo sed -i "s|<span id=\"header-counter\">[^<]*</span>|<span id=\"header-counter\">${TOTAL_HITS}</span>|g" "$TMP_HTML"
sudo sed -i "s|<span id=\"legi-update\">[^<]*</span>|<span id=\"legi-update\">${LEGI_UPDATE_DATE}</span>|g" "$TMP_HTML"
sudo sed -i "s|<span id=\"legi-articles\">[^<]*</span>|<span id=\"legi-articles\">${LEGI_ARTICLES}</span>|g" "$TMP_HTML"
sudo sed -i "s|<span id=\"eu-update\">[^<]*</span>|<span id=\"eu-update\">${EU_UPDATE_DATE}</span>|g" "$TMP_HTML"
sudo sed -i "s|<span id=\"eu-articles\">[^<]*</span>|<span id=\"eu-articles\">${EU_ARTICLES}</span>|g" "$TMP_HTML"

sudo cp "$TMP_HTML" "$HTML_FILE"
sudo rm -f "$TMP_HTML"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Stats mises à jour : ${TOTAL_HITS} requêtes IA, ${LEGI_ARTICLES} articles LEGI (MAJ: ${LEGI_UPDATE_DATE})"
